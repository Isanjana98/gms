<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BiometricUserResource;
use App\Models\BiometricDevice;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\EnrollmentService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** SRS 8 / 24 - Fingerprint registration and device-user mapping. */
class BiometricUserController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollment,
        private readonly BiometricSyncService $sync,
    ) {}

    /** GET /api/biometric/users */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can(Permissions::DEVICES_VIEW), 403);

        $users = BiometricUser::query()
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('enrollment_status'), fn ($q) => $q->where('enrollment_status', $request->string('enrollment_status')))
            // SRS 24 - device users that resolve to no member.
            ->when($request->boolean('unmatched'), fn ($q) => $q->unmatched())
            ->with(['member:id,full_name,membership_number,status', 'device:id,name,code'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50));

        return BiometricUserResource::collection($users);
    }

    /**
     * POST /api/biometric/users
     *
     * SRS 8 steps 2-4 - allocate the biometric user id, create the identity on
     * the reader and store the mapping. The member then places their finger on
     * the device itself; the template never comes here (SRS 26).
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ENROLLMENT_MANAGE), 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'device_id' => ['required', 'integer', 'exists:biometric_devices,id'],
            'biometric_user_id' => ['nullable', 'string', 'max:64'],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $device = BiometricDevice::findOrFail($data['device_id']);

        try {
            $mapping = $this->enrollment->enroll($member, $device, $data['biometric_user_id'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Enrolling against an id the reader was already using - the whole
        // point of being able to type one - makes that device user's earlier
        // scans this member's attendance.
        $replay = $this->recoverEarlierScans($mapping);

        $mapping->load(['member:id,full_name,membership_number', 'device:id,name,code']);

        return (new BiometricUserResource($mapping))
            ->additional([
                'replayed' => $replay['replayed'],
                'message' => $this->withRecovery(sprintf(
                    'Biometric user %s created on %s. Ask %s to enrol their fingerprint at the device, then verify.',
                    $mapping->biometric_user_id,
                    $device->name,
                    $member->full_name,
                ), $replay),
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /api/biometric/users/{biometricUser}/verify
     *
     * SRS 8 step 5 - Ask the device whether the fingerprint is really there.
     */
    public function verify(Request $request, BiometricUser $biometricUser): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ENROLLMENT_MANAGE), 403);

        try {
            $mapping = $this->enrollment->verify($biometricUser);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $verified = $mapping->enrollment_status === 'ENROLLED';

        return response()->json([
            'message' => $verified
                ? "Verified: {$mapping->fingerprint_count} fingerprint(s) enrolled on the device."
                : ($mapping->sync_error ?? 'Not verified: no fingerprint found on the device yet.'),
            'verified' => $verified,
            'data' => new BiometricUserResource($mapping->load('device:id,name,code')),
        ], $verified ? 200 : 422);
    }

    /**
     * POST /api/biometric/users/{biometricUser}/change-id
     *
     * Make the number on the reader and the number in here agree.
     *
     * `update_device` false is the ordinary case: somebody has read the id off
     * the terminal and is correcting our record to match, so nothing on the
     * hardware should move. True asks us to move the device instead, which
     * costs the member their fingerprint - the response says which happened.
     */
    public function changeId(Request $request, BiometricUser $biometricUser): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ENROLLMENT_MANAGE), 403);

        $data = $request->validate([
            // Digits only. It is what the terminal displays and what a
            // technician reads back over the phone, and the allocator
            // compares ids numerically when it hands out the next free one.
            'biometric_user_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
            'update_device' => ['nullable', 'boolean'],
        ], [
            'biometric_user_id.regex' => 'The ID must be digits only — it is the number the device itself shows.',
        ]);

        try {
            $result = $this->enrollment->changeId(
                $biometricUser,
                $data['biometric_user_id'],
                $request->boolean('update_device'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $replay = $this->recoverEarlierScans($result['mapping']);

        return $this->ok($this->withRecovery($result['message'], $replay), [
            'reenrol' => $result['reenrol'],
            'replayed' => $replay['replayed'],
            'data' => new BiometricUserResource(
                $result['mapping']->load(['member:id,full_name,membership_number', 'device:id,name,code']),
            ),
        ]);
    }

    /**
     * POST /api/biometric/users/{biometricUser}/match
     *
     * SRS 24 - Attach a device user we found but could not place to a member.
     */
    public function match(Request $request, BiometricUser $biometricUser): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ENROLLMENT_MANAGE), 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
        ]);

        $clash = BiometricUser::query()
            ->where('device_id', $biometricUser->device_id)
            ->where('member_id', $data['member_id'])
            ->whereKeyNot($biometricUser->getKey())
            ->exists();

        if ($clash) {
            return response()->json([
                'message' => 'That member is already mapped to a different user on this device.',
            ], 422);
        }

        $biometricUser->forceFill(['member_id' => $data['member_id']])->save();

        $replay = $this->recoverEarlierScans($biometricUser);

        return $this->ok($this->withRecovery('Device user matched to member.', $replay), [
            'replayed' => $replay['replayed'],
            'data' => new BiometricUserResource(
                $biometricUser->fresh()->load(['member:id,full_name,membership_number', 'device:id,name,code']),
            ),
        ]);
    }

    /**
     * Turn scans that arrived before the link existed into attendance.
     *
     * Run whenever a mapping starts pointing somewhere new - matched,
     * enrolled against an id the reader was already using, or corrected to
     * the number the terminal shows. Each of those is the moment a pile of
     * "unmatched" events becomes a member's history, and without it that
     * history silently begins on the day somebody got round to the paperwork.
     *
     * @return array{replayed: int, recorded: int, rejected: int}
     */
    private function recoverEarlierScans(BiometricUser $mapping): array
    {
        return $this->sync->replayUnmatched($mapping->fresh(['member', 'device']));
    }

    /** @param  array{replayed: int, recorded: int, rejected: int}  $replay */
    private function withRecovery(string $message, array $replay): string
    {
        if ($replay['replayed'] === 0) {
            return $message;
        }

        return $message.sprintf(
            ' %d earlier scan(s) recovered into attendance%s.',
            $replay['replayed'],
            $replay['rejected'] > 0 ? sprintf(' — %d refused on membership', $replay['rejected']) : '',
        );
    }

    /** DELETE /api/biometric/users/{biometricUser} - remove from device and retire. */
    public function destroy(Request $request, BiometricUser $biometricUser): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ENROLLMENT_MANAGE), 403);

        $this->enrollment->revoke($biometricUser);

        $message = $biometricUser->sync_status === 'FAILED'
            ? 'Deactivated here, but the device could not be reached. Remove the user from the device when it is back online.'
            : 'Biometric user removed from the device.';

        return $this->ok($message, ['data' => new BiometricUserResource($biometricUser->fresh())]);
    }
}
