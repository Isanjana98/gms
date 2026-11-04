<?php

namespace Tests\Feature;

use App\Models\HealthScreening;
use App\Models\Member;
use App\Services\HealthScreeningService;
use App\Support\GymSettings;
use App\Support\ParQ;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PAR-Q screening: issuing the link, signing it, and what a "yes" sets off.
 *
 * The tests that matter most here are not the happy path. They are the ones
 * about the token (it is the only thing standing between a stranger and
 * somebody's health answers) and the ones about the record hash (the whole
 * reason to call this evidence rather than a form).
 */
class HealthScreeningTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG, as the signature pad would send it. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function answers(array $yes = []): array
    {
        $answers = [];

        foreach (ParQ::ids() as $id) {
            $answers[$id] = ['answer' => in_array($id, $yes, true) ? 'yes' : 'no'];
        }

        // Anything answered yes needs its detail, as the form requires.
        foreach ($yes as $id) {
            $answers[$id]['detail'] = 'Since 2019.';
        }

        return $answers;
    }

    private function issueFor(Member $member): array
    {
        return app(HealthScreeningService::class)->issue($member, $this->admin());
    }

    /* --------------------------------------------------------- issuing */

    #[Test]
    public function issuing_a_form_stores_only_a_hash_of_the_token(): void
    {
        $member = $this->member();
        $issued = $this->issueFor($member);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued['token']);

        $screening = $issued['screening']->fresh();

        // The plain token must never be recoverable from the database.
        $this->assertSame(hash('sha256', $issued['token']), $screening->token_hash);
        $this->assertStringNotContainsString($issued['token'], json_encode($screening->toArray()));
        $this->assertSame('PENDING', $screening->status);
    }

    #[Test]
    public function issuing_again_supersedes_the_previous_link(): void
    {
        $member = $this->member();

        $first = $this->issueFor($member);
        $second = $this->issueFor($member);

        // Two live QRs for one member is how you end up with two
        // contradictory screenings and no way to say which is current.
        $this->assertSame('CANCELLED', $first['screening']->fresh()->status);
        $this->assertNull($first['screening']->fresh()->token_hash);
        $this->assertSame('PENDING', $second['screening']->fresh()->status);

        $this->get("/health/{$first['token']}")->assertNotFound();
        $this->get("/health/{$second['token']}")->assertOk();
    }

    #[Test]
    public function the_token_is_refused_once_it_has_expired(): void
    {
        $member = $this->member();
        $issued = $this->issueFor($member);

        $issued['screening']->forceFill(['token_expires_at' => now()->subMinute()])->save();

        $this->get("/health/{$issued['token']}")->assertNotFound();
    }

    #[Test]
    public function a_guessed_token_reveals_nothing_about_whether_a_member_exists(): void
    {
        $this->member(['full_name' => 'Nimal Perera']);

        $response = $this->get('/health/'.str_repeat('a', 64));

        $response->assertNotFound();
        // A 404 that named the member would turn a guessed token into a
        // membership oracle.
        $response->assertDontSee('Nimal');
    }

    /* ------------------------------------------------------ completing */

    #[Test]
    public function signing_records_the_answers_the_signature_and_the_provenance(): void
    {
        Storage::fake('local');

        $member = $this->member(['full_name' => 'Lakmal Ranatunga']);
        $issued = $this->issueFor($member);

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Lakmal Ranatunga',
            'signature' => self::PNG,
        ])->assertRedirect();

        $screening = $issued['screening']->fresh();

        $this->assertSame('COMPLETED', $screening->status);
        $this->assertSame('Lakmal Ranatunga', $screening->signed_name);
        $this->assertNotNull($screening->signed_at);
        $this->assertNotNull($screening->ip_address);
        $this->assertNotNull($screening->record_hash);
        $this->assertSame(ParQ::VERSION, $screening->questionnaire_version);

        // The signature is on the private disk, never the public one.
        Storage::disk('local')->assertExists($screening->signature_path);

        // Spent: the link cannot be used again.
        $this->assertNull($screening->token_hash);
    }

    #[Test]
    public function a_spent_link_cannot_be_submitted_twice(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        $payload = [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ];

        $this->post("/health/{$issued['token']}", $payload)->assertRedirect();

        // A double-tap on a slow connection must not produce two screenings.
        $this->post("/health/{$issued['token']}", $payload)->assertNotFound();
        $this->assertSame(1, HealthScreening::where('status', 'COMPLETED')->count());
    }

    #[Test]
    public function every_question_must_be_answered(): void
    {
        $issued = $this->issueFor($this->member());

        $answers = $this->answers();
        unset($answers['bone_joint']);

        // A blank is not a no: somebody who skipped question 5 has not told
        // us they have no joint problem.
        $this->post("/health/{$issued['token']}", [
            'answers' => $answers,
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ])->assertSessionHasErrors('answers.bone_joint.answer');

        $this->assertSame('PENDING', $issued['screening']->fresh()->status);
    }

    #[Test]
    public function the_terms_must_be_agreed_to(): void
    {
        $issued = $this->issueFor($this->member());

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ])->assertSessionHasErrors('agree');
    }

    #[Test]
    public function a_signature_that_is_not_a_png_is_refused(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        // Base64 that decodes cleanly is still not necessarily an image, and
        // this arrives from an unauthenticated form.
        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => 'data:image/png;base64,'.base64_encode('<?php echo "not a png";'),
        ])->assertSessionHasErrors('signature');

        $this->assertSame('PENDING', $issued['screening']->fresh()->status);
    }

    /* ----------------------------------------------------------- flags */

    #[Test]
    public function answering_no_to_everything_needs_no_review(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        $screening = $issued['screening']->fresh();

        $this->assertFalse($screening->has_flags);
        $this->assertSame(0, $screening->flag_count);
        $this->assertSame('NOT_REQUIRED', $screening->review_status);
    }

    #[Test]
    public function any_yes_puts_the_screening_into_the_review_queue(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(yes: ['heart_condition', 'blood_pressure_medication']),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        $screening = $issued['screening']->fresh();

        // The entire reason this feature is worth having rather than being a
        // liability: a "yes" lands somewhere a person has to answer it.
        $this->assertTrue($screening->has_flags);
        $this->assertSame(2, $screening->flag_count);
        $this->assertSame('PENDING', $screening->review_status);
    }

    #[Test]
    public function detail_given_against_a_no_is_not_stored(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        $answers = $this->answers();
        $answers['chest_pain_rest']['detail'] = 'Left over from a previous answer.';

        $this->post("/health/{$issued['token']}", [
            'answers' => $answers,
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        // Detail on a "no" is a contradiction, so it is dropped rather than
        // filed next to an answer it disagrees with.
        $this->assertNull($issued['screening']->fresh()->answers['chest_pain_rest']['detail']);
    }

    /* ------------------------------------------------------------ hash */

    #[Test]
    public function the_record_hash_detects_an_answer_being_changed_later(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());
        $service = app(HealthScreeningService::class);

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(yes: ['heart_condition']),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        $screening = $issued['screening']->fresh();
        $this->assertTrue($service->verify($screening));

        // Somebody edits the row directly, the way a careless migration or a
        // helpful administrator would.
        $answers = $screening->answers;
        $answers['heart_condition']['answer'] = false;
        $screening->forceFill(['answers' => $answers])->save();

        $this->assertFalse($service->verify($screening->fresh()));
    }

    #[Test]
    public function editing_the_gym_terms_does_not_rewrite_what_was_already_signed(): void
    {
        Storage::fake('local');

        $service = app(HealthScreeningService::class);
        $issued = $this->issueFor($this->member());

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        $signedHash = $issued['screening']->fresh()->terms_hash;

        app(GymSettings::class)->set('health.terms_body', 'Completely different terms.');
        app(GymSettings::class)->set('health.terms_version', '2.0');

        // What three hundred people already signed must not change because
        // somebody edited a settings field this morning.
        $this->assertSame($signedHash, $issued['screening']->fresh()->terms_hash);
        $this->assertNotSame($signedHash, $service->terms()['hash']);
    }

    /* ---------------------------------------------------- permissions */

    #[Test]
    public function reception_can_issue_a_form_but_not_decide_what_a_yes_means(): void
    {
        $receptionist = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $member = $this->member();

        $this->actingAs($receptionist)
            ->postJson("/api/members/{$member->id}/health-screenings")
            ->assertCreated()
            ->assertJsonStructure(['url', 'qr_svg', 'data' => ['reference']]);

        $screening = HealthScreening::first();
        $screening->forceFill(['status' => 'COMPLETED', 'has_flags' => true, 'review_status' => 'PENDING'])->save();

        // Handing somebody a QR and deciding whether a man with chest pain
        // should be on a treadmill are not the same decision.
        $this->actingAs($receptionist)
            ->postJson("/api/health-screenings/{$screening->id}/review", [
                'outcome' => 'CLEARED',
                'notes' => 'Looked fine to me.',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_trainer_can_record_the_review(): void
    {
        $trainer = $this->userWithRole(Permissions::ROLE_TRAINER);
        $member = $this->member();

        $screening = HealthScreening::create([
            'member_id' => $member->id,
            'reference' => 'PARQ-TEST-1',
            'status' => 'COMPLETED',
            'has_flags' => true,
            'flag_count' => 1,
            'review_status' => 'PENDING',
        ]);

        $this->actingAs($trainer)
            ->postJson("/api/health-screenings/{$screening->id}/review", [
                'outcome' => 'RESTRICTED',
                'notes' => 'No overhead pressing until the shoulder settles.',
            ])
            ->assertOk();

        $screening->refresh();

        $this->assertSame('RESTRICTED', $screening->review_status);
        $this->assertSame($trainer->id, $screening->reviewed_by);
        $this->assertNotNull($screening->reviewed_at);
    }

    #[Test]
    public function a_review_cannot_be_recorded_without_saying_why(): void
    {
        $screening = HealthScreening::create([
            'member_id' => $this->member()->id,
            'reference' => 'PARQ-TEST-2',
            'status' => 'COMPLETED',
            'has_flags' => true,
            'review_status' => 'PENDING',
        ]);

        // A clinical decision recorded with no reasoning is the thing that
        // looks worst a year later.
        $this->actingAs($this->admin())
            ->postJson("/api/health-screenings/{$screening->id}/review", ['outcome' => 'CLEARED'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes');
    }

    #[Test]
    public function the_queue_does_not_show_which_questions_were_flagged(): void
    {
        Storage::fake('local');

        $issued = $this->issueFor($this->member());

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(yes: ['heart_condition']),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        $response = $this->actingAs($this->admin())->getJson('/api/health-screenings');

        $response->assertOk();
        // "One flag" is what a queue needs. Which one is a click away, not
        // scrolling past on a screen at the front desk.
        $response->assertJsonPath('data.0.flag_count', 1);
        $response->assertJsonMissingPath('data.0.answers');
        $response->assertDontSee('heart condition', escape: false);
    }

    #[Test]
    public function the_member_profile_endpoint_returns_the_current_screening(): void
    {
        Storage::fake('local');

        $member = $this->member();
        $issued = $this->issueFor($member);

        $this->post("/health/{$issued['token']}", [
            'answers' => $this->answers(yes: ['bone_joint']),
            'agree' => '1',
            'signed_name' => 'Someone',
            'signature' => self::PNG,
        ]);

        // This endpoint renders the current screening in full, which reads
        // relations the list does not. Lazy loading is disabled application
        // wide, so a relation missed here is a 500 rather than a slow query -
        // which is exactly how it was found.
        $response = $this->actingAs($this->admin())
            ->getJson("/api/members/{$member->id}/health-screenings");

        $response->assertOk();
        $response->assertJsonPath('current.flag_count', 1);
        $response->assertJsonPath('current.review_status', 'PENDING');
        $response->assertJsonPath('current.record_intact', true);
        $response->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_member_token_cannot_read_health_screenings(): void
    {
        $screening = HealthScreening::create([
            'member_id' => $this->member()->id,
            'reference' => 'PARQ-TEST-3',
            'status' => 'COMPLETED',
        ]);

        // No authentication at all is the simplest form of the rule the
        // member/staff wall enforces elsewhere.
        $this->getJson("/api/health-screenings/{$screening->id}")->assertUnauthorized();
    }
}
