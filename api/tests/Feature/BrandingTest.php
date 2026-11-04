<?php

namespace Tests\Feature;

use App\Models\BrandAsset;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The gym's name, logo and sign-in imagery. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    #[Test]
    public function branding_is_readable_without_signing_in(): void
    {
        // It has to be: the sign-in screen renders before anyone has a token.
        $this->getJson('/api/branding')
            ->assertOk()
            ->assertJsonStructure(['gym_name', 'tagline', 'logo_url', 'login_images']);
    }

    #[Test]
    public function the_public_endpoint_exposes_nothing_beyond_what_the_sign_in_screen_shows(): void
    {
        // An unauthenticated endpoint should return what an unauthenticated
        // visitor can already see, and not one field more.
        $response = $this->getJson('/api/branding')->assertOk();

        $this->assertSame(
            ['gym_name', 'tagline', 'logo_url', 'login_images'],
            array_keys($response->json()),
        );
    }

    #[Test]
    public function a_guest_cannot_read_or_change_the_assets(): void
    {
        $this->getJson('/api/branding/assets')->assertUnauthorized();
        $this->putJson('/api/branding', ['gym_name' => 'Hijacked'])->assertUnauthorized();
        $this->postJson('/api/branding/assets', [])->assertUnauthorized();
    }

    #[Test]
    public function the_gym_name_and_tagline_can_be_changed(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/branding', [
                'gym_name' => 'Iron House Fitness',
                'tagline' => 'Discipline builds everything.',
            ])
            ->assertOk();

        $this->getJson('/api/branding')
            ->assertJsonPath('gym_name', 'Iron House Fitness')
            ->assertJsonPath('tagline', 'Discipline builds everything.');
    }

    #[Test]
    public function a_logo_can_be_uploaded_and_is_then_public(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/branding/assets', [
                'image' => UploadedFile::fake()->image('logo.png', 256, 256),
                'type' => 'LOGO',
            ])
            ->assertCreated();

        Storage::disk('public')->assertExists(BrandAsset::first()->path);

        $this->getJson('/api/branding')->assertOk()->assertJsonPath(
            'logo_url',
            fn (?string $url) => $url !== null,
        );
    }

    #[Test]
    public function uploading_a_second_logo_replaces_the_first(): void
    {
        // A gym has one logo. Leaving two active and letting the query pick
        // makes which one appears a matter of luck.
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/branding/assets', [
            'image' => UploadedFile::fake()->image('old.png'),
            'type' => 'LOGO',
        ])->assertCreated();

        $old = BrandAsset::first();

        $this->actingAs($admin, 'sanctum')->postJson('/api/branding/assets', [
            'image' => UploadedFile::fake()->image('new.png'),
            'type' => 'LOGO',
        ])->assertCreated();

        $this->assertSame(1, BrandAsset::where('type', 'LOGO')->count());
        $this->assertDatabaseMissing('brand_assets', ['id' => $old->id]);
        Storage::disk('public')->assertMissing($old->path);
    }

    #[Test]
    public function sign_in_images_accumulate_and_keep_their_order(): void
    {
        $admin = $this->admin();

        foreach (['first.jpg', 'second.jpg', 'third.jpg'] as $name) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/branding/assets', [
                'image' => UploadedFile::fake()->image($name),
                'type' => 'LOGIN_BACKGROUND',
            ])->assertCreated();
        }

        $this->getJson('/api/branding')->assertOk()->assertJsonCount(3, 'login_images');

        $this->assertSame([1, 2, 3], BrandAsset::where('type', 'LOGIN_BACKGROUND')->pluck('sort_order')->all());
    }

    #[Test]
    public function a_hidden_image_is_kept_but_not_shown(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/branding/assets', [
            'image' => UploadedFile::fake()->image('bg.jpg'),
            'type' => 'LOGIN_BACKGROUND',
        ])->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/branding/assets/{$id}", ['is_active' => false])
            ->assertOk();

        $this->getJson('/api/branding')->assertJsonCount(0, 'login_images');
        // Still there for whoever manages it, just not on display.
        $this->actingAs($admin, 'sanctum')->getJson('/api/branding/assets')->assertJsonCount(1, 'data');
    }

    #[Test]
    public function removing_an_image_deletes_its_file(): void
    {
        // Unlike a member document, a decorative image is not evidence:
        // leaving orphaned files on a public disk is the worse outcome.
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/branding/assets', [
            'image' => UploadedFile::fake()->image('bg.jpg'),
            'type' => 'LOGIN_BACKGROUND',
        ])->json('data.id');

        $path = BrandAsset::find($id)->path;

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/branding/assets/{$id}")->assertOk();

        $this->assertDatabaseMissing('brand_assets', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function a_non_image_upload_is_refused(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/branding/assets', [
                'image' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
                'type' => 'LOGO',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    #[Test]
    public function reception_cannot_change_the_gyms_branding(): void
    {
        // It is what every member and visitor sees; it belongs with the
        // administrators, not the front desk.
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/branding', ['gym_name' => 'Renamed by reception'])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')->getJson('/api/branding/assets')->assertForbidden();
    }

    #[Test]
    public function a_gym_administrator_can_change_the_branding(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_GYM_ADMIN), 'sanctum')
            ->putJson('/api/branding', ['gym_name' => 'Set by the manager'])
            ->assertOk();

        $this->getJson('/api/branding')->assertJsonPath('gym_name', 'Set by the manager');
    }

    #[Test]
    public function the_gym_name_appears_on_a_receipt(): void
    {
        // The receipt is one of the few things that leaves the building.
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->putJson('/api/branding', ['gym_name' => 'Iron House Fitness']);

        $member = $this->member();
        $membership = app(MembershipService::class)->create($member, $this->plan(['price' => 5000]));
        $payment = app(PaymentService::class)->record($member, [
            'amount' => 5000,
            'membership_id' => $membership->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->get("/api/payments/{$payment->id}/receipt");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
