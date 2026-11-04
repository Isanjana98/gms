<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\GymSettings;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SRS 10 / 23 - Administrator-configurable settings. */
class SettingController extends Controller
{
    public function __construct(private readonly GymSettings $settings) {}

    /** GET /api/settings */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_MANAGE), 403);

        $branchId = $request->integer('branch_id') ?: null;

        return response()->json([
            'branch_id' => $branchId,
            'settings' => Setting::query()
                ->where(function ($q) use ($branchId) {
                    $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
                })
                ->orderBy('group')
                ->orderBy('key')
                ->get()
                ->map(fn (Setting $s) => [
                    'id' => $s->id,
                    'branch_id' => $s->branch_id,
                    'group' => $s->group,
                    'key' => $s->key,
                    'value' => $s->typedValue(),
                    'type' => $s->type,
                    'label' => $s->label,
                    'description' => $s->description,
                    'is_override' => $s->branch_id !== null,
                ]),
            // The effective values in force right now, after branch overrides
            // and config defaults have been applied.
            'effective' => [
                'attendance' => [
                    'reject_invalid_membership' => $this->settings->rejectsInvalidMembership($branchId),
                    'duplicate_window_minutes' => $this->settings->int('attendance.duplicate_window_minutes', $branchId, 2),
                    'min_session_minutes' => $this->settings->int('attendance.min_session_minutes', $branchId, 5),
                    'auto_close_hour' => $this->settings->int('attendance.auto_close_hour', $branchId, 23),
                ],
                'membership' => [
                    'renew_from_expiry' => (bool) config('gms.membership.renew_from_expiry'),
                    'expiring_soon_days' => $this->settings->int('membership.expiring_soon_days', $branchId, 30),
                ],
                'currency' => config('gms.currency'),
            ],
        ]);
    }

    /**
     * PUT /api/settings
     *
     * Accepts a batch, because the settings screen saves a form rather than
     * one field at a time.
     */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_MANAGE), 403);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:100'],
            'settings.*.value' => ['present'],
            'settings.*.type' => ['nullable', 'in:string,integer,decimal,boolean,json'],
        ]);

        foreach ($data['settings'] as $setting) {
            $this->settings->set(
                $setting['key'],
                $setting['value'],
                $data['branch_id'] ?? null,
                $setting['type'] ?? $this->inferType($setting['value']),
            );
        }

        return $this->ok('Settings saved.');
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'decimal',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
