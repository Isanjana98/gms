<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PDOException;

/**
 * First-run setup, from a browser.
 *
 * Uploading a zip and visiting a page is how most people expect to install a
 * PHP application, and the alternative here is a shell they may not have.
 *
 * The thing to be careful about is not the happy path. **An installer that
 * stays reachable after it has run is a way for a stranger to drop the
 * database and make themselves an administrator.** So there are two locks,
 * not one:
 *
 *  - a marker file written on success, and
 *  - the state of the database itself.
 *
 * Either one being present refuses the installer. The file alone would be a
 * single `rm` away from an open door; the database check alone would let a
 * half-finished install be resumed by anybody. Together they mean the window
 * is the few minutes between uploading the files and finishing setup — which
 * is why the documentation says to install immediately, and why the last step
 * tells you to delete the installer outright if the site is public.
 */
class Installer
{
    /** Outside public/, so the lock cannot be removed over HTTP. */
    private const LOCK = 'installed.lock';

    public function lockPath(): string
    {
        return storage_path('app/'.self::LOCK);
    }

    /* --------------------------------------------------------- the locks */

    public function isInstalled(): bool
    {
        return $this->isLocked() || $this->databaseLooksInstalled();
    }

    public function isLocked(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * Whether the configured database already holds an installation.
     *
     * Deliberately forgiving about failure: before setup there is usually no
     * database to connect to at all, and that is the normal case rather than
     * an error.
     */
    public function databaseLooksInstalled(): bool
    {
        try {
            return DB::table('users')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function lock(): void
    {
        file_put_contents(
            $this->lockPath(),
            'Installed '.now()->toIso8601String()."\n".
            "Delete this file only to run the installer again, and only on a system nobody else can reach.\n",
        );
    }

    /* -------------------------------------------------------- the checks */

    /**
     * What the host must provide, and whether it does.
     *
     * Reported all at once rather than failing at the first problem: somebody
     * on shared hosting needs the whole list to take to their provider, not
     * one line at a time.
     *
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    public function requirements(): array
    {
        $checks = [[
            'label' => 'PHP 8.2 or newer',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'detail' => 'Running '.PHP_VERSION,
        ]];

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd', 'zip', 'curl', 'intl', 'bcmath'] as $extension) {
            $checks[] = [
                'label' => "PHP extension: {$extension}",
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'Loaded' : 'Not loaded — ask your host to enable it',
            ];
        }

        foreach ([
            'storage' => storage_path(),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $label => $path) {
            $checks[] = [
                'label' => "Writable: {$label}",
                'ok' => is_writable($path),
                'detail' => is_writable($path) ? 'Yes' : 'Not writable — set it to 775',
            ];
        }

        // Written during setup. If .env already exists it is edited in place,
        // so what matters is whichever of the two will be touched.
        $envWritable = is_file(base_path('.env'))
            ? is_writable(base_path('.env'))
            : is_writable(base_path());

        $checks[] = [
            'label' => 'Writable: .env',
            'ok' => $envWritable,
            'detail' => $envWritable ? 'Yes' : 'The application cannot save its settings',
        ];

        return $checks;
    }

    /** @param array<int, array{ok: bool}> $requirements */
    public function requirementsMet(array $requirements): bool
    {
        return collect($requirements)->every(fn (array $check) => $check['ok']);
    }

    /* ------------------------------------------------------ the database */

    /**
     * Connect with the details given, creating the database if it is missing
     * and we are allowed to.
     *
     * Most shared hosting gives the application a user that can use a database
     * but not create one — that is done in the control panel. So a failure to
     * create is reported as the ordinary situation it is, with the one
     * instruction that resolves it.
     *
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, message: string, created: bool}
     */
    public function prepareDatabase(array $config): array
    {
        $dsn = sprintf('mysql:host=%s;port=%d', $config['host'], (int) $config['port']);

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            return [
                'ok' => false,
                'created' => false,
                'message' => $this->friendlyConnectionError($e, $config),
            ];
        }

        $database = $config['database'];

        if (! $this->isSafeIdentifier($database)) {
            return [
                'ok' => false,
                'created' => false,
                'message' => 'That database name contains characters that are not allowed. Use letters, numbers and underscores.',
            ];
        }

        $exists = (bool) $pdo
            ->query('SHOW DATABASES LIKE '.$pdo->quote($database))
            ->fetchColumn();

        if ($exists) {
            return ['ok' => true, 'created' => false, 'message' => 'Connected to the existing database.'];
        }

        try {
            // The name is validated above rather than bound, because an
            // identifier cannot be a bound parameter.
            $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            return ['ok' => true, 'created' => true, 'message' => "Created the database {$database}."];
        } catch (PDOException $e) {
            return [
                'ok' => false,
                'created' => false,
                'message' => "The database {$database} does not exist, and this user may not create one. "
                    ."Create it in your hosting control panel first, then come back. ({$e->getMessage()})",
            ];
        }
    }

    /* ----------------------------------------------------------- the env */

    /**
     * Write the settings to .env, keeping anything already there.
     *
     * Edited in place rather than rewritten from the example, so a host that
     * has added its own settings — a mail transport, a cache prefix — does not
     * silently lose them.
     *
     * @param  array<string, string>  $values
     */
    public function writeEnv(array $values): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            copy(base_path('.env.example'), $path);
        }

        $contents = file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote($value);

            $contents = preg_match("/^{$key}=.*$/m", $contents)
                ? preg_replace("/^{$key}=.*$/m", $line, $contents, 1)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    public function generateKey(): string
    {
        $key = 'base64:'.base64_encode(random_bytes(32));

        $this->writeEnv(['APP_KEY' => $key]);

        return $key;
    }

    /* --------------------------------------------------------- the build */

    /**
     * Point the running process at the new database.
     *
     * .env is read once, at boot. Without this the migration below would run
     * against whatever connection the installer itself started with — which is
     * either nothing or, worse, somebody else's database.
     *
     * @param  array<string, mixed>  $config
     */
    public function useDatabase(array $config): void
    {
        config([
            'database.connections.mysql.host' => $config['host'],
            'database.connections.mysql.port' => (int) $config['port'],
            'database.connections.mysql.database' => $config['database'],
            'database.connections.mysql.username' => $config['username'],
            'database.connections.mysql.password' => $config['password'] ?? '',
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    public function migrate(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    public function seedRoles(): void
    {
        Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
    }

    /**
     * The gym's first branch and its first administrator.
     *
     * One branch is created because almost everything else hangs off one, and
     * a system with no branch presents as a long series of unexplained empty
     * screens.
     */
    public function createGym(string $gymName, string $adminName, string $email, string $password): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            ['name' => $gymName, 'is_active' => true, 'is_default' => true],
        );

        $user = User::query()->create([
            'branch_id' => $branch->id,
            'name' => $adminName,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $user->assignRole(Permissions::ROLE_SUPER_ADMIN);

        return $user;
    }

    public function pushToken(): string
    {
        return Str::random(48);
    }

    /* ----------------------------------------------------------- helpers */

    private function isSafeIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
    }

    private function quote(string $value): string
    {
        // Anything with whitespace or a character the parser treats specially
        // has to be quoted, or .env reads half of it.
        return preg_match('/[\s#"\'=]/', $value) ? '"'.addcslashes($value, '"\\').'"' : $value;
    }

    /** @param array<string, mixed> $config */
    private function friendlyConnectionError(PDOException $e, array $config): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Access denied') => 'The database username or password was not accepted.',
            str_contains($message, 'actively refused'),
            str_contains($message, 'Connection refused') => "Nothing is listening on {$config['host']}:{$config['port']}. "
                .'On shared hosting the host is usually "localhost".',
            str_contains($message, 'getaddrinfo'),
            str_contains($message, 'Unknown MySQL server host') => "Could not find the database server \"{$config['host']}\".",
            default => 'Could not connect: '.$message,
        };
    }
}
