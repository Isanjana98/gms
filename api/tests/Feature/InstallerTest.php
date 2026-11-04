<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Installer;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * First-run setup, and the locks that close it afterwards.
 *
 * The happy path barely matters here. What matters is that the installer
 * cannot be reached once a system exists, because an open installer is a way
 * for a stranger to drop the database and make themselves an administrator.
 */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private function lock(): string
    {
        return app(Installer::class)->lockPath();
    }

    protected function tearDown(): void
    {
        @unlink($this->lock());

        parent::tearDown();
    }

    /* ------------------------------------------------------- the page */

    #[Test]
    public function the_setup_page_renders_before_anything_exists(): void
    {
        DB::table('users')->delete();
        @unlink($this->lock());

        $response = $this->get('/install');

        $response->assertOk();
        $response->assertSee('Set up', escape: false);
        $response->assertSee('What this host provides', escape: false);
        $response->assertSee('Database', escape: false);
        $response->assertSee('Your account', escape: false);

        // Self-contained: no layout, no stylesheet, nothing that needs the
        // database or an APP_KEY, because at this point none of those exist.
        $response->assertDontSee('<link rel="stylesheet"', escape: false);
        $response->assertSee('name="robots" content="noindex', escape: false);
    }

    /* ----------------------------------------------------------- locking */

    #[Test]
    public function the_installer_is_closed_once_the_database_holds_an_installation(): void
    {
        // RefreshDatabase has migrated, and this test creates a user, so the
        // system is by definition installed - with no marker file in sight.
        $this->admin();

        @unlink($this->lock());

        $this->get('/install')->assertNotFound();
        $this->post('/install', [])->assertNotFound();
    }

    #[Test]
    public function the_installer_is_closed_by_the_marker_file_alone(): void
    {
        // The other half of the pair. A database that looks empty - restored,
        // or pointed somewhere new - must not reopen setup on a system that
        // has already been through it.
        DB::table('users')->delete();
        app(Installer::class)->lock();

        $this->get('/install')->assertNotFound();
        $this->post('/install', [])->assertNotFound();
    }

    #[Test]
    public function it_is_a_404_rather_than_an_explanation(): void
    {
        $this->admin();

        $response = $this->get('/install');

        // Answering "already installed" tells an anonymous visitor what this
        // software is and that there is an installer worth coming back for.
        $response->assertNotFound();
        $response->assertDontSee('already installed', escape: false);
        $response->assertDontSee('Set up', escape: false);
    }

    #[Test]
    public function the_lock_file_is_not_reachable_over_http(): void
    {
        $lock = app(Installer::class)->lockPath();

        // Inside storage/, never public/. A lock that could be deleted with a
        // request is not a lock.
        $this->assertStringNotContainsString(
            public_path(),
            $lock,
            'The install lock must not sit under the document root.',
        );
    }

    /* ------------------------------------------------------- the checks */

    #[Test]
    public function the_requirements_report_every_problem_at_once(): void
    {
        $checks = app(Installer::class)->requirements();

        // Somebody on shared hosting needs the whole list to take to their
        // provider, not one line at a time.
        $this->assertGreaterThan(10, count($checks));

        foreach ($checks as $check) {
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('ok', $check);
            $this->assertArrayHasKey('detail', $check);
        }

        // This machine runs the test suite, so it necessarily satisfies them.
        $this->assertTrue(app(Installer::class)->requirementsMet($checks));
    }

    /* ------------------------------------------------------ the database */

    #[Test]
    public function a_database_name_that_is_not_an_identifier_is_refused(): void
    {
        // The name cannot be a bound parameter in CREATE DATABASE, so it is
        // validated instead. This is the test that keeps that true.
        $result = app(Installer::class)->prepareDatabase([
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => 'gms`; DROP DATABASE gms_test; --',
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not allowed', $result['message']);

        // And the database the suite is using is still there.
        $this->assertTrue(Schema::hasTable('users'));
    }

    #[Test]
    public function a_connection_failure_is_explained_rather_than_dumped(): void
    {
        $result = app(Installer::class)->prepareDatabase([
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'anything',
            'username' => 'nobody',
            'password' => '',
        ]);

        $this->assertFalse($result['ok']);
        // Somebody reading this has no administrator to ask and no log to
        // open, so it has to say what to do.
        $this->assertMatchesRegularExpression(
            '/Nothing is listening|Could not connect|not accepted/',
            $result['message'],
        );
    }

    #[Test]
    public function connecting_to_an_existing_database_does_not_claim_to_have_created_it(): void
    {
        $result = app(Installer::class)->prepareDatabase([
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => config('database.connections.mysql.database'),
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['created']);
    }

    /* ------------------------------------------------- the whole thing */

    #[Test]
    public function it_builds_a_working_system_from_nothing(): void
    {
        $scratch = 'gms_install_scratch';
        $connection = config('database.connections.mysql');
        $envPath = base_path('.env');
        $originalEnv = file_get_contents($envPath);

        // Somewhere empty to install into, so the suite's own database is
        // never the thing being migrated.
        DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");

        // Both locks have to be off, or the installer correctly refuses.
        DB::table('users')->delete();
        @unlink($this->lock());

        try {
            $response = $this->post('/install', [
                'gym_name' => 'Project Kaya Fitness',
                'app_url' => 'https://projectkaya.lk',
                'db_host' => $connection['host'],
                'db_port' => $connection['port'],
                'db_database' => $scratch,
                'db_username' => $connection['username'],
                'db_password' => $connection['password'],
                'admin_name' => 'Isuru',
                'admin_email' => 'owner@projectkaya.lk',
                'admin_password' => 'a-long-enough-password',
                'admin_password_confirmation' => 'a-long-enough-password',
            ]);

            $response->assertOk();
            $response->assertSee('Project Kaya Fitness is ready', escape: false);
            $response->assertSee('owner@projectkaya.lk', escape: false);

            // The schema really was built, in the right database.
            $names = array_column(DB::select(
                'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?',
                [$scratch],
            ), 't');

            $this->assertContains('members', $names);
            $this->assertContains('health_screenings', $names);
            $this->assertContains('biometric_devices', $names);

            // An administrator who can actually sign in and do everything.
            $admin = User::where('email', 'owner@projectkaya.lk')->first();
            $this->assertNotNull($admin);
            $this->assertTrue(Hash::check('a-long-enough-password', $admin->password));
            $this->assertTrue($admin->hasRole(Permissions::ROLE_SUPER_ADMIN));

            // A branch, because nearly everything hangs off one and a system
            // without it is a series of unexplained empty screens.
            $this->assertDatabaseHas('branches', ['code' => 'MAIN', 'name' => 'Project Kaya Fitness']);

            // And it has shut behind itself.
            $this->assertTrue(app(Installer::class)->isLocked());

            $written = file_get_contents($envPath);
            $this->assertStringContainsString("DB_DATABASE={$scratch}", $written);
            $this->assertStringContainsString('APP_DEBUG=false', $written);
            $this->assertStringContainsString('APP_URL=https://projectkaya.lk', $written);
            // Generated, not left blank: it is the fingerprint reader's only
            // credential.
            $this->assertMatchesRegularExpression('/^BIOMETRIC_PUSH_TOKEN=.{40,}$/m', $written);
        } finally {
            file_put_contents($envPath, $originalEnv);

            config(['database.connections.mysql' => $connection]);
            DB::purge('mysql');
            DB::reconnect('mysql');
            DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
        }
    }

    /* ------------------------------------------------------------- .env */

    #[Test]
    public function writing_the_env_keeps_settings_it_was_not_asked_to_change(): void
    {
        $path = base_path('.env');
        $original = file_get_contents($path);

        try {
            file_put_contents($path, "APP_NAME=Old\nSOMETHING_ELSE=keep-me\n");

            app(Installer::class)->writeEnv(['APP_NAME' => 'New Gym', 'DB_DATABASE' => 'gms']);

            $written = file_get_contents($path);

            // A host may have added its own settings. Rewriting the file from
            // the example would lose them silently.
            $this->assertStringContainsString('SOMETHING_ELSE=keep-me', $written);
            $this->assertStringContainsString('APP_NAME="New Gym"', $written);
            $this->assertStringNotContainsString('APP_NAME=Old', $written);
            // A key that was not in the file is appended rather than dropped.
            $this->assertStringContainsString('DB_DATABASE=gms', $written);
        } finally {
            file_put_contents($path, $original);
        }
    }

    #[Test]
    public function a_value_with_spaces_or_hashes_is_quoted(): void
    {
        $path = base_path('.env');
        $original = file_get_contents($path);

        try {
            file_put_contents($path, "X=1\n");

            // Unquoted, .env reads everything before the # and throws the
            // rest away - which for a password is a silent lockout.
            app(Installer::class)->writeEnv(['DB_PASSWORD' => 'p a s s#word']);

            $this->assertStringContainsString('DB_PASSWORD="p a s s#word"', file_get_contents($path));
        } finally {
            file_put_contents($path, $original);
        }
    }

    /* ------------------------------------------------- the storage link */

    /**
     * A sandbox with its own public/ and storage/, so these never touch the
     * real `public/storage` the development server is using.
     */
    private function sandboxPaths(): string
    {
        $base = sys_get_temp_dir().'/gms-link-'.bin2hex(random_bytes(6));

        mkdir($base.'/public', 0775, true);
        mkdir($base.'/storage/app/public', 0775, true);

        $this->app->usePublicPath($base.'/public');
        $this->app->useStoragePath($base.'/storage');

        return $base;
    }

    #[Test]
    public function it_links_public_storage_so_uploaded_images_are_served(): void
    {
        $base = $this->sandboxPaths();

        if (! @symlink($base.'/storage/app/public', $base.'/public/probe')) {
            $this->markTestSkipped('This host does not allow symlink().');
        }

        unlink($base.'/public/probe');

        $this->assertTrue(app(Installer::class)->linkStorage());
        $this->assertDirectoryExists($base.'/public/storage');

        // The point of the link: a file written through the storage disk is
        // reachable under public/. Without it every member photograph 404s.
        file_put_contents($base.'/storage/app/public/logo.png', 'x');
        $this->assertFileExists($base.'/public/storage/logo.png');
    }

    #[Test]
    public function a_dead_link_left_by_a_copy_is_replaced_rather_than_believed(): void
    {
        $base = $this->sandboxPaths();

        // What a zip built on another machine leaves behind: a link whose
        // target does not exist here. Treating it as "already done" is how an
        // installation finishes looking successful and serves no images.
        if (! @symlink($base.'/nowhere', $base.'/public/storage')) {
            $this->markTestSkipped('This host does not allow symlink().');
        }

        $this->assertTrue(app(Installer::class)->linkStorage());

        file_put_contents($base.'/storage/app/public/logo.png', 'x');
        $this->assertFileExists($base.'/public/storage/logo.png');
    }

    #[Test]
    public function a_host_that_refuses_to_link_is_reported_rather_than_fatal(): void
    {
        $this->sandboxPaths();

        // Nowhere to put the link. Plenty of shared hosts disable symlink()
        // outright, and an installation that is otherwise complete should not
        // be thrown away over it.
        $this->app->usePublicPath(sys_get_temp_dir().'/gms-absent-'.bin2hex(random_bytes(6)).'/public');

        $this->assertFalse(app(Installer::class)->linkStorage());
    }

    #[Test]
    public function the_finished_page_says_so_when_images_will_not_display(): void
    {
        $data = [
            'gymName' => 'Kaya Fitness',
            'email' => 'owner@example.com',
            'appUrl' => 'https://example.com',
        ];

        $warned = view('install.done', $data + ['storageLinked' => false])->render();
        $quiet = view('install.done', $data + ['storageLinked' => true])->render();

        $this->assertStringContainsString('Uploaded images will not display', $warned);
        $this->assertStringNotContainsString('Uploaded images will not display', $quiet);
    }
}
