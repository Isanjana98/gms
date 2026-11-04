<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\DeviceDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SRS 13 - Endpoint for device-initiated event notifications.
 *
 * This is the only route a device talks to, and a fingerprint reader cannot
 * hold a Sanctum token, so it authenticates with a shared secret compared in
 * constant time. It is deliberately the thinnest possible surface: identify
 * the device, hand the payload to that device's driver, and let the same
 * ingestion path as the scheduled pull do the rest - including the duplicate
 * suppression, so an event arriving by push and again by pull is stored once.
 *
 * Push is treated as an optimisation, never as the only delivery route: a
 * device configured for PUSH is still polled unless its sync_mode says
 * otherwise, because a notification that silently stops being sent is
 * indistinguishable from a quiet gym.
 */
class BiometricPushController extends Controller
{
    public function __construct(
        private readonly BiometricSyncService $sync,
        private readonly DeviceDriverFactory $drivers,
    ) {}

    /** POST /api/biometric/push/{deviceCode} */
    public function __invoke(Request $request, string $deviceCode): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            Log::warning('Rejected biometric push with an invalid token.', [
                'device_code' => $deviceCode,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $device = BiometricDevice::query()
            ->where('code', $deviceCode)
            ->active()
            ->first();

        if ($device === null) {
            return response()->json(['message' => 'Unknown device.'], 404);
        }

        $payload = $this->payload($request);

        if ($payload === []) {
            return response()->json(['message' => 'Empty payload.'], 422);
        }

        try {
            $event = $this->drivers->for($device)->parsePushEvent($payload);
        } catch (\Throwable $e) {
            Log::error('Failed to parse a pushed biometric event.', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not parse the event.'], 422);
        }

        // Heartbeats and door-state messages arrive here too. Acknowledging
        // them with 200 matters: a device that receives an error may retry
        // the same non-event indefinitely.
        if ($event === null) {
            $device->markOnline();

            return response()->json(['message' => 'Acknowledged.']);
        }

        $status = $this->sync->ingest($device, $event, 'PUSH');

        $device->forceFill([
            'last_sync_at' => now(),
            'last_event_at' => $device->last_event_at === null || $event->occurredAt->greaterThan($device->last_event_at)
                ? $event->occurredAt
                : $device->last_event_at,
        ])->save();

        $device->markOnline();

        return response()->json([
            'message' => 'Event received.',
            'result' => $status->value,
        ]);
    }

    /**
     * Constant-time comparison of the shared secret.
     *
     * A plain === leaks the secret one byte at a time to anyone willing to
     * measure response times.
     */
    private function tokenIsValid(Request $request): bool
    {
        $expected = (string) config('gms.biometric.push_token');

        // An unset token would otherwise make every request match an empty
        // string and leave the endpoint wide open.
        if ($expected === '' || $expected === 'change-me-in-production') {
            return false;
        }

        $provided = (string) ($request->header('X-GMS-Token')
            ?? $request->query('token')
            ?? '');

        return hash_equals($expected, $provided);
    }

    /**
     * Hikvision posts either JSON or multipart with a JSON part, depending on
     * firmware and whether a snapshot is attached.
     *
     * @return array<mixed>
     */
    private function payload(Request $request): array
    {
        $json = $request->json()->all();

        if ($json !== []) {
            return $json;
        }

        // Multipart: the event rides in an "event_log" field alongside the
        // image parts, which we neither want nor store.
        foreach (['event_log', 'EventNotificationAlert', 'AccessControllerEvent'] as $field) {
            $value = $request->input($field);

            if (is_array($value)) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $all = $request->all();

        return is_array($all) ? $all : [];
    }
}
