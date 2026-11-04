<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\Installer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The setup page.
 *
 * One screen and one submission rather than a wizard, because a wizard needs
 * somewhere to keep its state between steps and there is nowhere yet: the
 * session driver is the database, and the database is the thing being set up.
 * Re-rendering the same form with its errors avoids needing a session at all.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    /** GET /install */
    public function show(): View
    {
        $requirements = $this->installer->requirements();

        return view('install.index', [
            'requirements' => $requirements,
            'ready' => $this->installer->requirementsMet($requirements),
            'values' => $this->defaults(),
            'errors' => [],
            'failure' => null,
        ]);
    }

    /** POST /install */
    public function run(Request $request): View
    {
        $requirements = $this->installer->requirements();

        if (! $this->installer->requirementsMet($requirements)) {
            return $this->back($requirements, $request, failure: 'The host is still missing something above.');
        }

        $validator = validator($request->all(), [
            'gym_name' => ['required', 'string', 'max:255'],
            'app_url' => ['required', 'url'],

            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            // Eight is the floor everywhere else in this application; the
            // account being made here is the one that can do everything.
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->back($requirements, $request, errors: $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $database = [
            'host' => $data['db_host'],
            'port' => $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => $data['db_password'] ?? '',
        ];

        $prepared = $this->installer->prepareDatabase($database);

        if (! $prepared['ok']) {
            return $this->back($requirements, $request, failure: $prepared['message']);
        }

        try {
            $this->installer->writeEnv([
                'APP_NAME' => $data['gym_name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => rtrim($data['app_url'], '/'),
                'APP_TIMEZONE' => 'Asia/Colombo',

                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => $database['database'],
                'DB_USERNAME' => $database['username'],
                'DB_PASSWORD' => $database['password'],

                // Generated rather than asked for: it is a shared secret for
                // the fingerprint reader, and a person inventing one at a
                // setup screen invents a weak one.
                'BIOMETRIC_PUSH_TOKEN' => $this->installer->pushToken(),
            ]);

            $this->installer->generateKey();
            $this->installer->useDatabase($database);
            $this->installer->migrate();
            $this->installer->seedRoles();

            $this->installer->createGym(
                $data['gym_name'],
                $data['admin_name'],
                $data['admin_email'],
                $data['admin_password'],
            );

            $this->installer->lock();
        } catch (\Throwable $e) {
            Log::error('Installation failed.', ['error' => $e->getMessage()]);

            // The message is shown because at this point there is no
            // administrator to read a log file, and no way in to find one.
            return $this->back($requirements, $request, failure: 'Setup failed: '.$e->getMessage());
        }

        return view('install.done', [
            'gymName' => $data['gym_name'],
            'email' => $data['admin_email'],
            'appUrl' => rtrim($data['app_url'], '/'),
        ]);
    }

    /**
     * @param  array<int, array{label: string, ok: bool, detail: string}>  $requirements
     * @param  array<string, array<int, string>>  $errors
     */
    private function back(array $requirements, Request $request, array $errors = [], ?string $failure = null): View
    {
        return view('install.index', [
            'requirements' => $requirements,
            'ready' => $this->installer->requirementsMet($requirements),
            // The password is never echoed back into the form.
            'values' => array_merge($this->defaults(), $request->except([
                'admin_password', 'admin_password_confirmation', 'db_password',
            ])),
            'errors' => $errors,
            'failure' => $failure,
        ]);
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        return [
            'gym_name' => '',
            'app_url' => request()->getSchemeAndHttpHost(),
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => '',
            'db_username' => '',
            'admin_name' => '',
            'admin_email' => '',
        ];
    }
}
