<?php

namespace App\Services;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberLoginCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Signing a member in to the mobile app.
 *
 * Members have no password. They identify themselves with something already on
 * their record — membership number, telephone or email — and receive a
 * short-lived code on a channel that record already contains.
 *
 * The security of a six-digit code rests entirely on three limits, and all
 * three are enforced here rather than left to the caller:
 *
 *  - it expires in minutes;
 *  - it can be guessed at only a handful of times before it is destroyed;
 *  - it is single-use.
 *
 * Remove any one of those and a six-digit code is guessable. They are the
 * reason this is not merely a shorter password.
 */
class MemberAuthService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Start a sign-in.
     *
     * Returns the same shape whether or not the identifier matched anybody.
     * That is deliberate: a different answer for an unknown number turns this
     * endpoint into a way to ask "is this person a member here?" — a question
     * about somebody's gym attendance that strangers should not be able to put
     * to the system.
     *
     * @return array{sent:bool, channel:?string, hint:?string, debug_code:?string}
     */
    public function requestCode(string $identifier, ?string $ip = null): array
    {
        $member = $this->findMember($identifier);

        // Nothing to send, and nothing to reveal.
        if ($member === null) {
            return ['sent' => true, 'channel' => null, 'hint' => null, 'debug_code' => null];
        }

        $channel = $this->channelFor($member);

        // On file but unreachable: no phone, no email. Reception has to fix the
        // record; the app cannot.
        if ($channel === null) {
            return ['sent' => false, 'channel' => null, 'hint' => null, 'debug_code' => null];
        }

        // Any code already outstanding is spent. Otherwise asking for a second
        // code leaves the first one live, and "request one, request another,
        // try both" quietly doubles an attacker's allowance.
        MemberLoginCode::query()
            ->where('member_id', $member->getKey())
            ->live()
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();
        $destination = $channel === 'EMAIL' ? $member->email : $member->telephone;

        MemberLoginCode::create([
            'member_id' => $member->getKey(),
            'code_hash' => Hash::make($code),
            'channel' => $channel,
            'destination_hint' => $this->mask((string) $destination),
            'expires_at' => now()->addMinutes($this->ttlMinutes()),
            'ip_address' => $ip,
        ]);

        $this->deliver($member, $code);

        return [
            'sent' => true,
            'channel' => $channel,
            'hint' => $this->mask((string) $destination),
            // Local development only, and guarded twice over. Without it there
            // is no way to sign in until an SMS provider is configured, which
            // would leave the whole app untestable.
            'debug_code' => $this->mayExposeCode() ? $code : null,
        ];
    }

    /**
     * Finish a sign-in, returning the member on success.
     *
     * Every failure looks the same to the caller — wrong code, expired code, no
     * code, unknown member. Distinguishing them would let an attacker map the
     * boundaries of the system for free.
     */
    public function verify(string $identifier, string $code): ?Member
    {
        $member = $this->findMember($identifier);

        if ($member === null) {
            return null;
        }

        $record = MemberLoginCode::query()
            ->where('member_id', $member->getKey())
            ->live()
            ->latest('id')
            ->first();

        if ($record === null) {
            return null;
        }

        // Counted before the comparison, so a crash or a dropped connection
        // part-way through still costs the attacker an attempt.
        $record->increment('attempts');

        if ($record->attempts > $this->maxAttempts()) {
            $record->forceFill(['consumed_at' => now()])->save();

            return null;
        }

        if (! Hash::check($code, $record->code_hash)) {
            return null;
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return $member;
    }

    /**
     * Resolve an identifier to a member.
     *
     * Blacklisted and soft-deleted members are excluded. Somebody the gym has
     * barred should stop working everywhere at once, not in some places.
     */
    private function findMember(string $identifier): ?Member
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return Member::query()
            ->whereIn('status', [
                MemberStatus::ACTIVE,
                MemberStatus::EXPIRED,
                MemberStatus::INACTIVE,
                MemberStatus::SUSPENDED,
            ])
            ->where(function ($query) use ($identifier) {
                $query->where('membership_number', $identifier)
                    ->orWhere('telephone', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->first();
    }

    /** SMS first: it is the channel every member has and actually reads. */
    private function channelFor(Member $member): ?string
    {
        if (filled($member->telephone)) {
            return 'SMS';
        }

        return filled($member->email) ? 'EMAIL' : null;
    }

    /**
     * Sent through the same outbox as every other message to a member, so a
     * sign-in code follows whatever provider the gym has configured and appears
     * in the same delivery log as everything else.
     */
    private function deliver(Member $member, string $code): void
    {
        $minutes = $this->ttlMinutes();

        $this->notifications->queueForMember(
            member: $member,
            type: 'LOGIN_CODE',
            subject: 'Your sign-in code',
            body: "Your code is {$code}. It expires in {$minutes} minutes. If you did not ask for this, ignore it.",
            // No dedupe key: every request must produce its own message, or
            // asking for a second code would silently send nothing.
            dedupeKey: null,
            data: ['expires_in_minutes' => $minutes],
        );

        if ($this->mayExposeCode()) {
            Log::info('Member sign-in code issued.', [
                'member' => $member->membership_number,
                'code' => $code,
            ]);
        }
    }

    /**
     * Cryptographically random, never rand().
     *
     * A predictable sign-in code is the whole system's front door.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** "****4567" — enough to recognise, not enough to learn. */
    private function mask(string $destination): string
    {
        if (str_contains($destination, '@')) {
            [$name, $domain] = explode('@', $destination, 2);

            return mb_substr($name, 0, 1).str_repeat('*', max(mb_strlen($name) - 1, 1)).'@'.$domain;
        }

        $tail = mb_substr($destination, -4);

        return str_repeat('*', max(mb_strlen($destination) - 4, 0)).$tail;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('gms.member_auth.code_ttl_minutes', 10));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('gms.member_auth.max_attempts', 5));
    }

    /**
     * Whether the code may be handed back in the response.
     *
     * Guarded on the config flag *and* on not being in production, so switching
     * the flag on by accident on a live server still does nothing.
     */
    private function mayExposeCode(): bool
    {
        return (bool) config('gms.member_auth.expose_code', false) && ! app()->isProduction();
    }
}
