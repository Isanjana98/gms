<?php

namespace Tests\Feature;

use App\Models\BrandAsset;
use App\Models\Lead;
use App\Models\Member;
use App\Models\Setting;
use App\Models\SitePost;
use App\Models\Testimonial;
use App\Models\TimetableEntry;
use App\Models\Trainer;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The public website and the admin screens that fill it. */
class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private function publish(): void
    {
        Setting::set('website.enabled', true, null, 'boolean');
    }

    // ------------------------------------------------------------ publishing

    #[Test]
    public function the_website_is_unpublished_until_somebody_switches_it_on(): void
    {
        // A fresh installation must not put placeholder copy on the open
        // internet under a real gym's name.
        $this->get('/')->assertNotFound()->assertSee('not been published', false);

        $this->publish();

        $this->get('/')->assertOk();
    }

    #[Test]
    public function the_unpublished_page_asks_not_to_be_indexed(): void
    {
        $this->get('/')->assertSee('noindex', false);
    }

    #[Test]
    public function every_public_page_renders(): void
    {
        $this->publish();

        foreach (['/', '/about', '/pricing', '/trainers', '/gallery', '/timetable', '/blog', '/contact'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    // --------------------------------------------------------- live content

    #[Test]
    public function the_pricing_page_reads_the_real_membership_plans(): void
    {
        // The point of the whole design: no second copy of a price to drift
        // away from what reception will actually charge.
        $this->publish();
        $this->plan(['name' => 'Platinum Annual', 'price' => 45000]);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Platinum Annual')
            ->assertSee('45,000');
    }

    #[Test]
    public function the_trainers_page_does_not_publish_staff_contact_details(): void
    {
        $this->publish();

        Trainer::create([
            'branch_id' => $this->branch->id,
            'trainer_code' => 'TR-TEST-1',
            'full_name' => 'Sunil Weerasinghe',
            'specialization' => 'Strength & Conditioning',
            'telephone' => '0771234567',
            'email' => 'sunil@example.lk',
            'status' => 'ACTIVE',
        ]);

        $response = $this->get('/trainers')->assertOk();

        $response->assertSee('Sunil Weerasinghe');
        $response->assertSee('Strength & Conditioning');
        // A trainer's number is held to run the gym, not to publish.
        $response->assertDontSee('0771234567');
        $response->assertDontSee('sunil@example.lk');
    }

    #[Test]
    public function a_section_with_nothing_in_it_is_not_shown(): void
    {
        // Better a short tidy page than three empty headings.
        $this->publish();

        $this->get('/')->assertOk()->assertDontSee('Have a look around');

        BrandAsset::create([
            'type' => BrandAsset::TYPE_GALLERY,
            'path' => 'branding/example.jpg',
            'original_filename' => 'example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'is_active' => true,
        ]);

        $this->get('/')->assertOk()->assertSee('Have a look around');
    }

    // ------------------------------------------------------------------ blog

    #[Test]
    public function an_unpublished_post_is_not_reachable_even_by_its_address(): void
    {
        $this->publish();

        $draft = SitePost::create([
            'title' => 'Secret plans',
            'slug' => 'secret-plans',
            'body' => 'Not for the public.',
            'is_published' => false,
        ]);

        $this->get('/blog/'.$draft->slug)->assertNotFound();
        $this->get('/blog')->assertOk()->assertDontSee('Secret plans');
    }

    #[Test]
    public function a_post_scheduled_for_later_stays_hidden_until_then(): void
    {
        $this->publish();

        SitePost::create([
            'title' => 'Next week announcement',
            'slug' => 'next-week',
            'body' => 'Not yet.',
            'is_published' => true,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/blog/next-week')->assertNotFound();
    }

    #[Test]
    public function slugs_are_unique_even_when_two_posts_share_a_title(): void
    {
        // Two posts on one slug would make one of them permanently unreachable.
        $first = SitePost::uniqueSlug('New equipment');
        SitePost::create(['title' => 'New equipment', 'slug' => $first, 'body' => 'x']);

        $second = SitePost::uniqueSlug('New equipment');

        $this->assertSame('new-equipment', $first);
        $this->assertSame('new-equipment-2', $second);
    }

    #[Test]
    public function a_title_with_no_latin_characters_still_gets_a_usable_slug(): void
    {
        // Str::slug() returns an empty string for Sinhala, which would collide
        // for every such post and produce an unreachable URL.
        $slug = SitePost::uniqueSlug('ශරීර සුවතාව');

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^post-\d{8}-\d{6}$/', $slug);
    }

    // -------------------------------------------------------------- enquiries

    #[Test]
    public function an_enquiry_from_the_website_becomes_a_lead(): void
    {
        $this->publish();
        $plan = $this->plan();

        $this->post('/contact', [
            'name' => 'Nimal Perera',
            'telephone' => '0771234567',
            'membership_plan_id' => $plan->id,
            'message' => 'Do you have student rates?',
        ])->assertRedirect(route('site.contact'));

        $lead = Lead::query()->firstOrFail();

        $this->assertSame('Nimal Perera', $lead->name);
        $this->assertSame('NEW', $lead->status);
        $this->assertSame($plan->id, $lead->membership_plan_id);
    }

    #[Test]
    public function an_enquiry_with_no_way_to_reply_is_refused(): void
    {
        $this->publish();

        $this->post('/contact', ['name' => 'Anonymous'])
            ->assertSessionHasErrors('telephone');

        $this->assertSame(0, Lead::query()->count());
    }

    #[Test]
    public function the_honeypot_silently_absorbs_form_spam(): void
    {
        // Answered as though it worked, so a bot learns nothing from the
        // response and does not come back with the field left blank.
        $this->publish();

        $this->post('/contact', [
            'name' => 'Spam Bot',
            'telephone' => '0000000000',
            'website' => 'http://spam.example',
        ])->assertRedirect(route('site.contact'));

        $this->assertSame(0, Lead::query()->count());
    }

    #[Test]
    public function enquiries_cannot_be_sent_while_the_website_is_offline(): void
    {
        $this->post('/contact', ['name' => 'Nimal', 'telephone' => '0771234567'])->assertNotFound();

        $this->assertSame(0, Lead::query()->count());
    }

    #[Test]
    public function reception_can_turn_an_enquiry_into_a_member(): void
    {
        $lead = Lead::create([
            'branch_id' => $this->branch->id,
            'name' => 'Kamala Silva',
            'telephone' => '0777654321',
            'status' => 'NEW',
        ]);

        $response = $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->postJson("/api/leads/{$lead->id}/convert")
            ->assertCreated();

        $this->assertDatabaseHas('members', [
            'id' => $response->json('member_id'),
            'full_name' => 'Kamala Silva',
            'telephone' => '0777654321',
        ]);

        $lead->refresh();
        $this->assertSame('CONVERTED', $lead->status);
        $this->assertSame($response->json('member_id'), $lead->converted_member_id);
    }

    #[Test]
    public function an_enquiry_cannot_be_converted_twice(): void
    {
        // Otherwise one enthusiastic click creates two member records for the
        // same person.
        $lead = Lead::create([
            'branch_id' => $this->branch->id,
            'name' => 'Kamala Silva',
            'telephone' => '0777654321',
            'status' => 'NEW',
        ]);

        $reception = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $this->actingAs($reception)->postJson("/api/leads/{$lead->id}/convert")->assertCreated();
        $this->actingAs($reception)->postJson("/api/leads/{$lead->id}/convert")->assertStatus(422);

        $this->assertSame(1, Member::query()->count());
    }

    #[Test]
    public function a_trainer_cannot_read_website_enquiries(): void
    {
        // A lead is a stranger's contact details; a trainer has no business
        // with them.
        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER))
            ->getJson('/api/leads')
            ->assertForbidden();
    }

    // ------------------------------------------------------------ admin CRUD

    #[Test]
    public function an_administrator_edits_the_website_copy(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/api/website', [
                'hero_headline' => 'Stronger every week.',
                'facilities' => [['title' => 'Free weights', 'description' => 'Racks and platforms.']],
                'show_blog' => false,
            ])
            ->assertOk();

        $this->publish();

        // The last word is wrapped for the accent colour, so the headline
        // reaches the page as markup rather than one run of text.
        $this->get('/')
            ->assertOk()
            ->assertSee('Stronger every')
            ->assertSee('<em>week.</em>', false)
            ->assertSee('Free weights');

        $this->assertSame('Stronger every week.', Setting::get('website.hero_headline'));
    }

    #[Test]
    public function the_headline_is_escaped_despite_being_rendered_as_html(): void
    {
        /*
         * headline() returns HTML so the view prints it unescaped, which makes
         * it the one place on the site where escaping is this class's job
         * rather than Blade's. A headline is edited in the admin panel, and
         * this origin carries the staff session cookie.
         */
        $this->actingAs($this->admin())
            ->putJson('/api/website', ['hero_headline' => 'Get <script>alert(1)</script> strong'])
            ->assertOk();

        $this->publish();

        $response = $this->get('/')->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    #[Test]
    public function the_map_field_only_accepts_a_url(): void
    {
        // It is rendered as an iframe on our own origin, so it must not be a
        // way to inject arbitrary markup.
        $this->actingAs($this->admin())
            ->putJson('/api/website', ['map_embed' => '"><script>alert(1)</script>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('map_embed');
    }

    #[Test]
    public function a_class_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/website/timetable', [
                'class_name' => 'Spin',
                'day_of_week' => 1,
                'start_time' => '18:00',
                'end_time' => '17:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');
    }

    #[Test]
    public function the_timetable_and_testimonials_reach_the_website(): void
    {
        $this->publish();

        TimetableEntry::create([
            'branch_id' => $this->branch->id,
            'class_name' => 'Sunrise Spin',
            'day_of_week' => 2,
            'start_time' => '06:00',
            'end_time' => '07:00',
            'instructor_name' => 'Priya',
            'is_active' => true,
        ]);

        Testimonial::create([
            'name' => 'Ruwan Fernando',
            'quote' => 'Best gym in the city.',
            'is_active' => true,
        ]);

        $this->get('/timetable')->assertOk()->assertSee('Sunrise Spin')->assertSee('Tuesday');
        $this->get('/')->assertOk()->assertSee('Best gym in the city.');
    }

    #[Test]
    public function a_hidden_timetable_entry_is_not_published(): void
    {
        $this->publish();

        TimetableEntry::create([
            'branch_id' => $this->branch->id,
            'class_name' => 'Cancelled Class',
            'day_of_week' => 3,
            'start_time' => '06:00',
            'end_time' => '07:00',
            'is_active' => false,
        ]);

        $this->get('/timetable')->assertOk()->assertDontSee('Cancelled Class');
    }

    // ----------------------------------------------------------- permissions

    #[Test]
    public function reception_cannot_edit_the_website(): void
    {
        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->getJson('/api/website')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->putJson('/api/website', ['hero_headline' => 'Hacked'])
            ->assertForbidden();
    }

    #[Test]
    public function website_images_and_branding_images_are_separate_privileges(): void
    {
        /*
         * Whoever writes the marketing copy is often not whoever runs the gym,
         * so editing the website's gallery must not carry the ability to
         * replace the logo on every receipt.
         */
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $user->givePermissionTo(Permissions::WEBSITE_MANAGE);

        $logo = BrandAsset::create([
            'type' => BrandAsset::TYPE_LOGO,
            'path' => 'branding/logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 512,
        ]);

        $gallery = BrandAsset::create([
            'type' => BrandAsset::TYPE_GALLERY,
            'path' => 'branding/floor.jpg',
            'original_filename' => 'floor.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);

        // The listing shows only what they may manage.
        $listed = $this->actingAs($user)->getJson('/api/branding/assets')->assertOk()->json('data');
        $this->assertSame([BrandAsset::TYPE_GALLERY], array_values(array_unique(array_column($listed, 'type'))));

        $this->actingAs($user)->deleteJson("/api/branding/assets/{$gallery->id}")->assertOk();
        $this->actingAs($user)->deleteJson("/api/branding/assets/{$logo->id}")->assertForbidden();

        $this->assertDatabaseHas('brand_assets', ['id' => $logo->id]);
    }
}
