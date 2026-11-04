<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SRS 21 - Queues and sends notifications.
 *
 * Messages are written to the notifications table first and delivered by a
 * second pass. That separation is what makes the reminder job safe to run
 * again: queueing is deduplicated by key, and a provider outage delays
 * delivery instead of losing the message.
 *
 * IN_APP and EMAIL are delivered today. SMS and WhatsApp are queued with the
 * right recipient and body and left PENDING until a provider is configured -
 * the SRS makes both conditional on an integration that is not yet chosen.
 */
class NotificationService
{
    /**
     * SRS 21 - Queue expiry reminders for memberships lapsing soon.
     *
     * @return int number of reminders queued
     */
    public function queueExpiryReminders(): int
    {
        $days = config('gms.membership.expiry_reminder_days', [7, 3, 1]);
        $queued = 0;

        foreach ($days as $daysAhead) {
            $target = today()->addDays((int) $daysAhead)->toDateString();

            Membership::query()
                ->expiringOn($target)
                ->with('member')
                ->chunkById(200, function ($memberships) use (&$queued, $daysAhead) {
                    foreach ($memberships as $membership) {
                        if ($membership->member === null) {
                            continue;
                        }

                        $queued += $this->queueForMember(
                            $membership->member,
                            'MEMBERSHIP_EXPIRY_REMINDER',
                            'Your gym membership expires soon',
                            sprintf(
                                'Hi %s, your %s membership expires on %s. Visit reception to renew and keep your access.',
                                $membership->member->full_name,
                                $membership->plan_name,
                                $membership->end_date->format('d M Y'),
                            ),
                            // One reminder per membership per milestone, no
                            // matter how often the job runs.
                            dedupeKey: "expiry:{$membership->id}:{$daysAhead}",
                            data: [
                                'membership_id' => $membership->id,
                                'end_date' => $membership->end_date->toDateString(),
                                'days_remaining' => (int) $daysAhead,
                            ],
                        );
                    }
                });
        }

        return $queued;
    }

    /** SRS 21 - Notify members whose membership lapsed today. */
    public function queueExpiredNotices(): int
    {
        $queued = 0;

        Membership::query()
            ->where('status', MembershipStatus::EXPIRED)
            ->whereDate('end_date', today()->subDay())
            ->with('member')
            ->chunkById(200, function ($memberships) use (&$queued) {
                foreach ($memberships as $membership) {
                    if ($membership->member === null) {
                        continue;
                    }

                    $queued += $this->queueForMember(
                        $membership->member,
                        'MEMBERSHIP_EXPIRED',
                        'Your gym membership has expired',
                        sprintf(
                            'Hi %s, your %s membership expired on %s. Renew at reception to restore your access.',
                            $membership->member->full_name,
                            $membership->plan_name,
                            $membership->end_date->format('d M Y'),
                        ),
                        dedupeKey: "expired:{$membership->id}",
                        data: ['membership_id' => $membership->id],
                    );
                }
            });

        return $queued;
    }

    /** SRS 21 - Confirm a payment to the member. */
    public function queuePaymentConfirmation(Payment $payment): int
    {
        $member = $payment->member;

        if ($member === null) {
            return 0;
        }

        return $this->queueForMember(
            $member,
            'PAYMENT_CONFIRMATION',
            "Payment received - receipt {$payment->receipt_number}",
            sprintf(
                'Hi %s, we have received %s %s. Your receipt number is %s.',
                $member->full_name,
                config('gms.currency'),
                number_format((float) $payment->amount, 2),
                $payment->receipt_number,
            ),
            dedupeKey: "payment:{$payment->id}",
            data: ['payment_id' => $payment->id, 'receipt_number' => $payment->receipt_number],
        );
    }

    /**
     * Queue one message per channel the member can actually be reached on.
     *
     * @return int number of rows created
     */
    public function queueForMember(
        Member $member,
        string $type,
        string $subject,
        string $body,
        ?string $dedupeKey = null,
        array $data = [],
    ): int {
        $channels = [];

        // In-app always: it is the record reception can see even when no
        // external provider is configured.
        $channels['IN_APP'] = null;

        if (filled($member->email)) {
            $channels['EMAIL'] = $member->email;
        }

        if (filled($member->telephone)) {
            $channels['SMS'] = $member->telephone;
        }

        $created = 0;

        foreach ($channels as $channel => $recipient) {
            $attributes = [
                'branch_id' => $member->branch_id,
                'member_id' => $member->id,
                'type' => $type,
                'channel' => $channel,
                'recipient' => $recipient,
                'subject' => $subject,
                'body' => $body,
                'data' => $data,
                'status' => 'PENDING',
            ];

            /*
             * No dedupe key means "do not deduplicate", and it has to be said
             * explicitly.
             *
             * firstOrCreate(['dedupe_key' => null]) matches *any* row whose key
             * is null — so the first channel created a row and every later
             * channel found that same row and sent nothing. A member with a
             * phone and an email got one in-app notice and no message at all.
             * It stayed hidden because every caller so far passed a key; the
             * sign-in code, which must never be deduplicated, is what surfaced
             * it.
             */
            if ($dedupeKey === null) {
                Notification::create($attributes);
                $created++;

                continue;
            }

            // With a key: two schedulers racing produce one row, not two
            // messages to the member.
            $notification = Notification::query()->firstOrCreate(
                ['dedupe_key' => "{$dedupeKey}:{$channel}"],
                $attributes,
            );

            if ($notification->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Deliver everything that is due.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public function dispatchPending(int $limit = 200): array
    {
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        Notification::query()
            ->due()
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->each(function (Notification $notification) use (&$sent, &$failed, &$skipped) {
                try {
                    match ($notification->channel) {
                        // An in-app message is delivered the moment it exists.
                        'IN_APP' => $notification->markSent(),
                        'EMAIL' => $this->sendEmail($notification),
                        // No provider chosen yet (SRS 21). Left PENDING on
                        // purpose rather than marked FAILED: the message is
                        // correct and ready, and marking it failed would make
                        // an unconfigured integration look like a broken one.
                        default => $skipped++,
                    };

                    if ($notification->wasChanged('status') && $notification->status === 'SENT') {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    $notification->markFailed($e->getMessage());
                    $failed++;

                    Log::error('Notification delivery failed.', [
                        'notification_id' => $notification->id,
                        'channel' => $notification->channel,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    private function sendEmail(Notification $notification): void
    {
        if (blank($notification->recipient)) {
            $notification->markFailed('No email address on file.');

            return;
        }

        Mail::raw($notification->body, function ($message) use ($notification) {
            $message->to($notification->recipient)
                ->subject($notification->subject ?? 'Notification');
        });

        $notification->markSent();
    }
}
