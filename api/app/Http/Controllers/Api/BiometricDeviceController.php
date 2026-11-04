<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceMode;
use App\Http\Controllers\Controller;
use App\Http\Resources\BiometricDeviceResource;
use App\Models\BiometricDevice;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\DeviceDriverFactory;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** SRS 23 / 29 - Biometric device management. */
class BiometricDeviceController extends Controller
{
    public function __construct(
        private readonly BiometricSyncService $sync,
        private readonly DeviceDriverFactory $drivers,
    ) {}

    /** GET /api/biometric/devices */
    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = BiometricDevice::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->filled('status'), fn ($q) => $q->where('connection_status', $request->string('status')))
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->withCount(['biometricUsers' => fn ($q) => $q->whereNotNull('member_id')->where('is_active', true)])
            ->with('branch:id,name,code')
            ->orderBy('name')
            ->get();

        return BiometricDeviceResource::collection($devices);
    }

    /**
     * Wording somebody at a counter can act on. Laravel's default for a regex
     * rule is "format is invalid", which says nothing about what to type.
     */
    private const MESSAGES = [
        'code.regex' => 'The code can only contain letters, numbers, dots, dashes and underscores — for example ENT-01. It is a short label, not a URL.',
    ];

    /** POST /api/biometric/devices */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_MANAGE), 403);

        $data = $request->validate($this->rules(), self::MESSAGES);

        $device = BiometricDevice::create([
            ...$data,
            'branch_id' => $data['branch_id'] ?? $this->defaultBranchId($request),
        ]);

        return (new BiometricDeviceResource($device))
            ->additional(['message' => "Device \"{$device->name}\" added. Test the connection to confirm it is reachable."])
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/biometric/devices/{device} */
    public function show(BiometricDevice $device): BiometricDeviceResource
    {
        $device->loadCount('biometricUsers')->load('branch:id,name,code');

        return new BiometricDeviceResource($device);
    }

    /** PUT /api/biometric/devices/{device} */
    public function update(Request $request, BiometricDevice $device): BiometricDeviceResource
    {
        abort_unless($request->user()->can(Permissions::DEVICES_MANAGE), 403);

        $data = $request->validate($this->rules($device->id, updating: true), self::MESSAGES);

        // An empty password field means "leave it alone", not "clear it" -
        // the interface cannot show the current one to re-submit it.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $device->update($data);

        return new BiometricDeviceResource($device->fresh());
    }

    /** DELETE /api/biometric/devices/{device} */
    public function destroy(Request $request, BiometricDevice $device): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_MANAGE), 403);

        $device->delete();

        return $this->ok("Device \"{$device->name}\" removed.");
    }

    /**
     * POST /api/biometric/devices/{device}/test
     *
     * SRS 23 - Test connection. Always answers, including when the device is
     * unreachable: "cannot connect" is the useful answer, not a 500.
     */
    public function test(Request $request, BiometricDevice $device): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_VIEW), 403);

        $log = $this->sync->testConnection($device);
        $device->refresh();

        return response()->json([
            'message' => $log->message ?? 'Connection test complete.',
            'reachable' => $log->status === 'SUCCESS',
            'device' => new BiometricDeviceResource($device),
        ], $log->status === 'SUCCESS' ? 200 : 422);
    }

    /** GET /api/biometric/drivers - which vendors this build supports (SRS 35). */
    public function drivers(): JsonResponse
    {
        return response()->json(['data' => $this->drivers->available()]);
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            /*
             * URL-safe, because the code becomes a path segment in the push
             * endpoint: /api/biometric/push/{code}.
             *
             * Without this a slash is accepted and the resulting push URL is
             * quietly unroutable - which is exactly what happened the first
             * time somebody set a device up, having read the hint "used in the
             * device's push URL" as an instruction to paste the URL.
             */
            'code' => [
                $required,
                'string',
                'max:30',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('biometric_devices', 'code')->ignore($ignoreId)->whereNull('deleted_at'),
            ],
            'name' => [$required, 'string', 'max:255'],
            'driver' => ['nullable', Rule::in($this->drivers->available())],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'ip_address' => [$required, 'string', 'max:45'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'use_https' => ['nullable', 'boolean'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', Rule::in(DeviceMode::values())],
            'sync_mode' => ['nullable', Rule::in(['PULL', 'PUSH', 'BOTH'])],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
