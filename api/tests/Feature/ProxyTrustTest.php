<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Services\HealthScreeningService;
use App\Support\ParQ;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the application believes about a request that arrived through a proxy.
 *
 * Every failure here is silent. Nothing errors, nothing logs: the signature on
 * a health form simply records the wrong address, the session cookie quietly
 * loses its Secure flag, and the rate limiter puts the whole internet in one
 * bucket. They are only visible if something asserts them.
 */
class ProxyTrustTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Headers a Cloudflare tunnel adds on its way to the local web server. */
    private const FORWARDED = [
        'X-Forwarded-For' => '203.94.77.12',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'projectkaya.lk',
    ];

    private function answers(): array
    {
        return collect(ParQ::ids())
            ->mapWithKeys(fn (string $id) => [$id => ['answer' => 'no']])
            ->all();
    }

    #[Test]
    public function the_members_own_address_is_recorded_not_the_proxys(): void
    {
        Storage::fake('local');

        $member = Member::query()->firstOr(fn () => $this->member());
        $issued = app(HealthScreeningService::class)->issue($member, $this->admin());

        // Laravel's test client reports 127.0.0.1 as the connecting address,
        // which is exactly what a tunnel agent on this machine looks like.
        $this->withHeaders(self::FORWARDED)->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ])->assertRedirect();

        $screening = $issued['screening']->fresh();

        // The whole point: this is evidence of who signed and from where.
        // Recording the proxy's address would look like real provenance while
        // being worth nothing.
        $this->assertSame('203.94.77.12', $screening->ip_address);
    }

    #[Test]
    public function a_forwarded_https_request_is_seen_as_secure(): void
    {
        // Decides the session cookie's Secure flag and its __Host- prefix. A
        // proxy terminates TLS and forwards plain HTTP, so without trust the
        // application believes every request is insecure and drops both.
        $this->withHeaders(self::FORWARDED)->get('/up');

        $this->assertTrue(request()->isSecure());
        $this->assertSame('https', request()->getScheme());
    }

    #[Test]
    public function only_the_loopback_hop_is_trusted_by_default(): void
    {
        // The server is reachable directly on the gym LAN as well as through
        // the tunnel, so '*' would let anyone on that LAN set
        // X-Forwarded-For and write a false address onto a signed health
        // form. A tunnel agent runs on this machine and connects over
        // loopback, so loopback is the only hop that needs trusting.
        //
        // Asserted on the configuration rather than by simulating an
        // untrusted request: the middleware re-applies the trusted list on
        // every request, so a test cannot take it away part-way through. The
        // configuration is the thing that would actually be got wrong.
        $this->get('/up');

        $trusted = Request::getTrustedProxies();

        $this->assertNotContains('*', $trusted, 'Trusting every hop lets a LAN client forge the client IP.');
        $this->assertNotEmpty($trusted);

        foreach ($trusted as $proxy) {
            $this->assertContains(
                $proxy,
                ['127.0.0.1', '::1'],
                "Unexpected trusted proxy [{$proxy}]. Widen TRUSTED_PROXIES deliberately, not by accident.",
            );
        }
    }
}
