<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Services\MembershipService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 21 - Expiry reminders and the outbound queue. */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function memberExpiringIn(int $days, array $attributes = []): void
    {
        $member = $this->member($attributes);

        // A one-month term starting today ends on day 29, so start it far
        // enough back that it lapses exactly `days` from now.
        app(MembershipService::class)->create($member, $this->plan(), [
            'start_date' => today()->subMonthNoOverflow()->addDays($days + 1)->toDateString(),
            'activate' => true,
        ]);
    }

    #[Test]
    public function reminders_are_queued_for_the_configured_milestones(): void
    {
        config(['gms.membership.expiry_reminder_days' => [7, 3, 1]]);

        $this->memberExpiringIn(7, ['full_name' => 'Week away', 'email' => 'week@test.local']);
        $this->memberExpiringIn(3, ['full_name' => 'Three days', 'email' => 'three@test.local']);
        $this->memberExpiringIn(5, ['full_name' => 'Not a milestone', 'email' => 'five@test.local']);

        $queued = app(NotificationService::class)->queueExpiryReminders();

        $this->assertGreaterThan(0, $queued);
        $this->assertDatabaseHas('notifications', ['type' => 'MEMBERSHIP_EXPIRY_REMINDER', 'channel' => 'EMAIL']);

        // The member five days out is not at a milestone and must be left alone.
        $this->assertSame(
            0,
            Notification::query()->where('recipient', 'five@test.local')->count(),
        );
    }

    #[Test]
    public function running_the_reminder_job_twice_does_not_message_the_member_twice(): void
    {
        // The job runs daily and may be re-run by hand; a member should not
        // receive the same "expires in 3 days" message repeatedly.
        config(['gms.membership.expiry_reminder_days' => [3]]);

        $this->memberExpiringIn(3, ['email' => 'once@test.local']);

        $service = app(NotificationService::class);
        $first = $service->queueExpiryReminders();
        $second = $service->queueExpiryReminders();

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    #[Test]
    public function a_member_is_messaged_on_every_channel_they_can_be_reached_on(): void
    {
        config(['gms.membership.expiry_reminder_days' => [3]]);

        $this->memberExpiringIn(3, ['email' => 'both@test.local', 'telephone' => '0771234567']);

        app(NotificationService::class)->queueExpiryReminders();

        $channels = Notification::query()->pluck('channel')->sort()->values()->all();

        $this->assertSame(['EMAIL', 'IN_APP', 'SMS'], $channels);
    }

    #[Test]
    public function email_is_delivered_and_sms_waits_for_a_provider(): void
    {
        // SMS and WhatsApp are conditional on an integration the SRS does not
        // choose. They must stay PENDING, not be marked FAILED - an
        // unconfigured channel is not a broken one.
        Mail::fake();

        config(['gms.membership.expiry_reminder_days' => [3]]);
        $this->memberExpiringIn(3, ['email' => 'send@test.local', 'telephone' => '0771234567']);

        $service = app(NotificationService::class);
        $service->queueExpiryReminders();

        $result = $service->dispatchPending();

        $email = Notification::where('channel', 'EMAIL')->first();

        $this->assertSame('SENT', $email->status);
        $this->assertNotNull($email->sent_at);
        $this->assertSame('SENT', Notification::where('channel', 'IN_APP')->first()->status);
        $this->assertSame('PENDING', Notification::where('channel', 'SMS')->first()->status);
        $this->assertSame(1, $result['skipped']);
    }

    #[Test]
    public function a_delivered_message_is_not_sent_again_on_the_next_run(): void
    {
        // dispatchPending() only picks up PENDING rows, so a second run must
        // leave an already-sent message untouched - no second attempt, and no
        // second email to the member.
        Mail::fake();

        config(['gms.membership.expiry_reminder_days' => [3]]);
        $this->memberExpiringIn(3, ['email' => 'once-only@test.local']);

        $service = app(NotificationService::class);
        $service->queueExpiryReminders();
        $service->dispatchPending();

        $second = $service->dispatchPending();

        $email = Notification::where('channel', 'EMAIL')->first();

        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $email->attempts);
    }
}
