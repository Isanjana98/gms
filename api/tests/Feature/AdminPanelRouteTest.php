<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Serving the built admin panel from /admin.
 *
 * The panel is a single-page application: /admin/members is one of its own
 * routes, not a file. Refresh that page and the server must hand back
 * index.html rather than a 404, or the panel appears broken to anybody who
 * reloads anywhere other than the dashboard.
 *
 * Normally the web server does this — Apache's FallbackResource, nginx's
 * try_files. This route is the same thing in Laravel, so the panel behaves
 * identically on hosts where that configuration is not available.
 *
 * **It does not work under `php artisan serve`**, and that is a dev-server
 * quirk rather than a defect here: PHP's built-in server finds
 * public/admin/index.html, treats /admin as a script path, and hands Laravel
 * the request with the prefix already stripped — so the framework sees
 * "members" and quite correctly cannot route it. Apache and nginx set
 * SCRIPT_NAME to index.php and pass the path intact, which is what these tests
 * reproduce.
 */
class AdminPanelRouteTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = public_path('admin');
    }

    private function deployPanel(string $html = '<!DOCTYPE html><title>Panel</title>'): void
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0o755, true);
        }

        file_put_contents($this->dir.'/index.html', $html);
    }

    private function removePanel(): void
    {
        @unlink($this->dir.'/index.html');
    }

    #[Test]
    public function a_deep_link_into_the_panel_returns_its_index(): void
    {
        $existing = is_file($this->dir.'/index.html') ? file_get_contents($this->dir.'/index.html') : null;

        $this->deployPanel('<!DOCTYPE html><title>Panel</title>');

        try {
            foreach (['/admin', '/admin/members', '/admin/reports', '/admin/members/13'] as $path) {
                $this->get($path)
                    ->assertOk()
                    ->assertSee('Panel', escape: false);
            }
        } finally {
            // Leave whatever was deployed exactly as it was found.
            $existing === null
                ? $this->removePanel()
                : file_put_contents($this->dir.'/index.html', $existing);
        }
    }

    #[Test]
    public function the_panel_is_never_cached_by_the_browser(): void
    {
        $existing = is_file($this->dir.'/index.html') ? file_get_contents($this->dir.'/index.html') : null;

        $this->deployPanel();

        try {
            // index.html names hashed asset files. Cached, a browser keeps
            // asking for the previous build's assets after a deployment and
            // the panel loads into a blank screen.
            $this->get('/admin/members')->assertHeader('Cache-Control', 'no-store, private');
        } finally {
            $existing === null
                ? $this->removePanel()
                : file_put_contents($this->dir.'/index.html', $existing);
        }
    }

    #[Test]
    public function it_is_a_plain_404_when_the_panel_has_not_been_deployed(): void
    {
        $existing = is_file($this->dir.'/index.html') ? file_get_contents($this->dir.'/index.html') : null;
        $this->removePanel();

        try {
            // Not a 500 on a missing file: an API-only installation is a
            // legitimate way to run this.
            $this->get('/admin/members')->assertNotFound();
        } finally {
            if ($existing !== null) {
                file_put_contents($this->dir.'/index.html', $existing);
            }
        }
    }

    #[Test]
    public function the_panel_route_does_not_shadow_the_api_or_the_health_form(): void
    {
        $existing = is_file($this->dir.'/index.html') ? file_get_contents($this->dir.'/index.html') : null;
        $this->deployPanel();

        try {
            // /admin must claim only its own prefix. These three share the
            // origin and each has to keep answering for itself.
            $this->getJson('/api/branding')->assertOk();
            $this->get('/health/'.str_repeat('a', 64))->assertNotFound();
            $this->getJson('/api/members')->assertUnauthorized();
        } finally {
            $existing === null
                ? $this->removePanel()
                : file_put_contents($this->dir.'/index.html', $existing);
        }
    }
}
