<?php

namespace Tests\Feature;

use App\Models\HealthScreening;
use App\Models\Member;
use App\Models\MemberRegistration;
use App\Models\RegistrationQuestion;
use App\Support\ParQ;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-registration from the QR code at the desk.
 *
 * The tests worth having here are about the boundary, not the happy path.
 * The form is open to anyone who can see a printed code, so what matters is
 * that submitting one creates nothing a gym counts - no member, no number,
 * no access - until a person with the right permission says so.
 */
class MemberRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG, as the signature pad would send it. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array<string, mixed> */
    private function payload(array $overrides = [], array $yes = []): array
    {
        $answers = [];

        foreach (ParQ::ids() as $id) {
            $answers[$id] = ['answer' => in_array($id, $yes, true) ? 'yes' : 'no'];
        }

        foreach ($yes as $id) {
            $answers[$id]['detail'] = 'Since 2019.';
        }

        return [
            'full_name' => 'Nimal Perera',
            'telephone' => '0771234567',
            'email' => 'nimal@example.com',
            'answers' => $answers,
            'signed_name' => 'Nimal Perera',
            'signature' => self::PNG,
            'agree' => '1',
            ...$overrides,
        ];
    }

    private function url(string $suffix = ''): string
    {
        return "/join/{$this->branch->code}{$suffix}";
    }

    /* ------------------------------------------------------ the public form */

    #[Test]
    public function the_form_opens_for_a_real_branch_code(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('Join')
            ->assertSee('signature-canvas', false);
    }

    #[Test]
    public function an_unknown_branch_code_is_not_a_form(): void
    {
        // A printed code that no longer matches a branch is a poster to
        // replace, not a reason to register somebody into a default gym.
        $this->get('/join/NOPE')->assertNotFound();
    }

    #[Test]
    public function submitting_creates_a_pending_registration_and_no_member(): void
    {
        Storage::fake('local');

        $this->post($this->url(), $this->payload())
            ->assertRedirect($this->url('/done'));

        $registration = MemberRegistration::sole();

        $this->assertSame('Nimal Perera', $registration->full_name);
        $this->assertSame('PENDING', $registration->status);
        $this->assertSame($this->branch->id, $registration->branch_id);
        $this->assertNull($registration->member_id);
        $this->assertNotNull($registration->signed_at);

        // The point of the whole design: nothing the gym counts exists yet.
        $this->assertSame(0, Member::count());
        $this->assertSame(0, HealthScreening::count());
    }

    #[Test]
    public function the_signature_is_written_to_the_private_disk(): void
    {
        Storage::fake('local');

        $this->post($this->url(), $this->payload());

        $registration = MemberRegistration::sole();

        $this->assertNotNull($registration->signature_path);
        Storage::disk('local')->assertExists($registration->signature_path);

        // Never the public disk: a signature on a guessable URL is a
        // signature anybody can take a copy of.
        $this->assertStringStartsWith('registrations/', $registration->signature_path);
    }

    #[Test]
    public function every_health_question_must_be_answered(): void
    {
        $payload = $this->payload();
        unset($payload['answers'][ParQ::ids()[2]]);

        $this->post($this->url(), $payload)
            ->assertSessionHasErrors('answers.'.ParQ::ids()[2].'.answer');

        $this->assertSame(0, MemberRegistration::count());
    }

    #[Test]
    public function the_terms_must_be_agreed_to(): void
    {
        $this->post($this->url(), $this->payload(['agree' => null]))
            ->assertSessionHasErrors('agree');

        $this->assertSame(0, MemberRegistration::count());
    }

    #[Test]
    public function a_signature_that_is_not_a_png_is_refused(): void
    {
        $this->post($this->url(), $this->payload(['signature' => 'data:image/png;base64,bm90YXBuZw==']))
            ->assertSessionHasErrors('signature');

        $this->assertSame(0, MemberRegistration::count());
    }

    #[Test]
    public function yes_answers_are_counted_and_their_detail_kept(): void
    {
        Storage::fake('local');

        $flagged = [ParQ::ids()[0], ParQ::ids()[3]];

        $this->post($this->url(), $this->payload(yes: $flagged));

        $registration = MemberRegistration::sole();

        $this->assertSame(2, $registration->parq_flag_count);
        $this->assertTrue($registration->hasHealthFlags());
        $this->assertSame('Since 2019.', $registration->parq_answers[$flagged[0]]['detail']);

        // Detail on a "no" is noise at best and a contradiction at worst.
        $this->assertNull($registration->parq_answers[ParQ::ids()[1]]['detail']);
    }

    #[Test]
    public function the_honeypot_drops_the_submission_without_saying_so(): void
    {
        // Answered with success on purpose: telling a bot which check it
        // failed is telling it how to pass next time.
        $this->post($this->url(), $this->payload(['website' => 'http://spam.example']))
            ->assertRedirect($this->url('/done'));

        $this->assertSame(0, MemberRegistration::count());
    }

    /* ----------------------------------------------------------- reviewing */

    private function pending(array $yes = []): MemberRegistration
    {
        Storage::fake('local');
        $this->post($this->url(), $this->payload(yes: $yes));

        return MemberRegistration::sole();
    }

    #[Test]
    public function approving_creates_the_member_and_carries_the_parq_across(): void
    {
        $registration = $this->pending(yes: [ParQ::ids()[0]]);

        $response = $this->actingAs($this->admin())
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertOk();

        $member = Member::sole();

        $this->assertSame('Nimal Perera', $member->full_name);
        $this->assertNotEmpty($member->membership_number);
        $response->assertJsonPath('member.id', $member->id);

        $registration->refresh();
        $this->assertSame('APPROVED', $registration->status);
        $this->assertSame($member->id, $registration->member_id);

        /*
         * The screening is the part most likely to be forgotten. Somebody
         * answered seven medical questions and signed them; if approval only
         * copied the name across, the member would appear with no screening
         * on file and the signature would be stranded.
         */
        $screening = HealthScreening::sole();
        $this->assertSame($member->id, $screening->member_id);
        $this->assertSame('COMPLETED', $screening->status);
        $this->assertSame(1, $screening->flag_count);
        $this->assertTrue($screening->has_flags);
        // A yes has to reach somebody, which is the whole reason it is asked.
        $this->assertSame('PENDING', $screening->review_status);
        $this->assertNotNull($screening->signature_path);
    }

    #[Test]
    public function a_clear_parq_needs_no_review(): void
    {
        $registration = $this->pending();

        $this->actingAs($this->admin())
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertOk();

        $this->assertSame('NOT_REQUIRED', HealthScreening::sole()->review_status);
    }

    #[Test]
    public function the_same_registration_cannot_be_approved_twice(): void
    {
        $registration = $this->pending();

        $this->actingAs($this->admin())
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertOk();

        // Without this guard a double click is a duplicate member.
        $this->actingAs($this->admin())
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertStatus(409);

        $this->assertSame(1, Member::count());
    }

    #[Test]
    public function rejecting_records_who_and_why_and_creates_no_member(): void
    {
        $registration = $this->pending();

        $this->actingAs($this->admin())
            ->postJson("/api/member-registrations/{$registration->id}/reject", ['notes' => 'Duplicate.'])
            ->assertOk();

        $registration->refresh();

        $this->assertSame('REJECTED', $registration->status);
        $this->assertSame('Duplicate.', $registration->review_notes);
        $this->assertNotNull($registration->reviewed_at);
        $this->assertSame(0, Member::count());

        // The signature survives rejection: "what did this person sign?"
        // outlives the decision not to accept them.
        $this->assertNotNull($registration->signature_path);
    }

    /* --------------------------------------------------------- permissions */

    #[Test]
    public function reading_registrations_needs_the_view_permission(): void
    {
        $registration = $this->pending();
        $trainer = $this->userWithRole(Permissions::ROLE_TRAINER);

        $this->actingAs($trainer)->getJson('/api/member-registrations')->assertForbidden();
        $this->actingAs($trainer)->getJson("/api/member-registrations/{$registration->id}")->assertForbidden();
    }

    #[Test]
    public function approving_needs_the_review_permission(): void
    {
        $registration = $this->pending();

        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER))
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertForbidden();

        $this->assertSame(0, Member::count());
    }

    #[Test]
    public function reception_can_review_because_they_are_the_ones_standing_there(): void
    {
        $registration = $this->pending();

        $this->actingAs($this->userWithRole(Permissions::ROLE_RECEPTIONIST))
            ->postJson("/api/member-registrations/{$registration->id}/approve")
            ->assertOk();

        $this->assertSame(1, Member::count());
    }

    #[Test]
    public function the_signature_is_not_served_without_a_session(): void
    {
        $registration = $this->pending();

        $this->getJson("/api/member-registrations/{$registration->id}/signature")
            ->assertUnauthorized();
    }

    /* ------------------------------------------- the gym's own questions */

    private function question(array $attributes = []): RegistrationQuestion
    {
        return RegistrationQuestion::create([
            'key' => 'injuries',
            'label' => 'Any current injuries?',
            'type' => 'BOOLEAN',
            'is_required' => false,
            'sort_order' => 1,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    #[Test]
    public function the_gyms_own_questions_appear_on_the_form(): void
    {
        $this->question(['key' => 'goal', 'label' => 'What is your main goal?', 'type' => 'TEXT']);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('What is your main goal?')
            // The standard seven are still there and still first.
            ->assertSee('heart condition', false);
    }

    #[Test]
    public function a_retired_question_is_no_longer_asked(): void
    {
        $this->question(['label' => 'Old question', 'is_active' => false]);

        $this->get($this->url())->assertOk()->assertDontSee('Old question');
    }

    #[Test]
    public function an_answer_keeps_the_wording_it_was_given_under(): void
    {
        Storage::fake('local');

        $question = $this->question(['key' => 'goal', 'label' => 'What is your main goal?', 'type' => 'TEXT']);

        $this->post($this->url(), $this->payload(['custom' => ['goal' => 'Lose weight']]));

        /*
         * The point of the whole design. Reword the question afterwards and
         * the stored answer must still say what was actually asked - the
         * failure the PAR-Q avoids by keeping its wording in code, and which
         * these questions are exposed to precisely because they are editable.
         */
        $question->update(['label' => 'Something completely different']);

        $stored = MemberRegistration::sole()->custom_answers;

        $this->assertSame('goal', $stored[0]['key']);
        $this->assertSame('What is your main goal?', $stored[0]['label']);
        $this->assertSame('Lose weight', $stored[0]['answer']);
    }

    #[Test]
    public function a_required_question_must_be_answered(): void
    {
        $this->question(['key' => 'goal', 'label' => 'Goal?', 'type' => 'TEXT', 'is_required' => true]);

        $this->post($this->url(), $this->payload())
            ->assertSessionHasErrors('custom.goal');

        $this->assertSame(0, MemberRegistration::count());
    }

    #[Test]
    public function a_choice_question_only_accepts_its_own_options(): void
    {
        $this->question([
            'key' => 'heard',
            'label' => 'How did you hear about us?',
            'type' => 'CHOICE',
            'options' => ['Facebook', 'A friend'],
        ]);

        $this->post($this->url(), $this->payload(['custom' => ['heard' => 'Billboard']]))
            ->assertSessionHasErrors('custom.heard');
    }

    #[Test]
    public function detail_is_kept_for_a_yes_and_dropped_for_a_no(): void
    {
        Storage::fake('local');

        $this->question();

        $this->post($this->url(), $this->payload([
            'custom' => ['injuries' => 'yes', 'injuries_detail' => 'Left knee.'],
        ]));

        $stored = MemberRegistration::sole()->custom_answers;
        $this->assertTrue($stored[0]['answer']);
        $this->assertSame('Left knee.', $stored[0]['detail']);

        MemberRegistration::query()->forceDelete();

        $this->post($this->url(), $this->payload([
            'custom' => ['injuries' => 'no', 'injuries_detail' => 'ignore me'],
        ]));

        $stored = MemberRegistration::sole()->custom_answers;
        $this->assertFalse($stored[0]['answer']);
        $this->assertArrayNotHasKey('detail', $stored[0]);
    }

    #[Test]
    public function an_unanswered_optional_question_is_not_stored_at_all(): void
    {
        Storage::fake('local');

        $this->question(['key' => 'goal', 'label' => 'Goal?', 'type' => 'TEXT']);

        $this->post($this->url(), $this->payload());

        // Padding the record with questions somebody declined to answer
        // would make it look as though they answered them.
        $this->assertNull(MemberRegistration::sole()->custom_answers);
    }

    #[Test]
    public function managing_questions_needs_settings_manage(): void
    {
        $reception = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        // Reception reviews registrations but does not configure the form.
        $this->actingAs($reception)->getJson('/api/registration-questions')->assertOk();
        $this->actingAs($reception)
            ->postJson('/api/registration-questions', ['label' => 'Nope', 'type' => 'TEXT'])
            ->assertForbidden();
    }

    #[Test]
    public function a_key_is_derived_once_and_survives_a_rename(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/registration-questions', ['label' => 'What is your main goal?', 'type' => 'TEXT'])
            ->assertCreated();

        $id = $response->json('data.id');
        $key = $response->json('data.key');

        $this->assertSame('what_is_your_main_goal', $key);

        $this->actingAs($this->admin())
            ->putJson("/api/registration-questions/{$id}", ['label' => 'Renamed', 'type' => 'TEXT'])
            ->assertOk();

        // Answers are filed under the key, so renaming must not move them.
        $this->assertSame($key, RegistrationQuestion::find($id)->key);
    }

    #[Test]
    public function a_choice_question_needs_at_least_two_options(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/registration-questions', [
                'label' => 'Pick one', 'type' => 'CHOICE', 'options' => ['Only one'],
            ])
            ->assertStatus(422);
    }

    /* ---------------------------------------------------------------- the QR */

    #[Test]
    public function the_qr_points_at_this_branch_and_carries_no_secret(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/member-registrations/qr')
            ->assertOk();

        $url = $response->json('url');

        $this->assertStringEndsWith("/join/{$this->branch->code}", $url);
        $this->assertStringContainsString('<svg', $response->json('qr_svg'));

        // It is printed on a wall and must keep working, so there is nothing
        // in it that can expire or be spent.
        $this->assertStringNotContainsString('token', $url);
    }
}
