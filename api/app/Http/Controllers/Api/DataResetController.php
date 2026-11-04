<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataResetService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Clearing operational data.
 *
 * Three separate gates, because this is the one action in the system with no
 * undo beyond a restore:
 *
 *  1. The `data.reset` permission, held only by a Super Administrator.
 *  2. The caller's own password, re-entered. A permission check only proves
 *     the session is valid, not that the person at the keyboard is the one who
 *     opened it - and reception terminals are shared.
 *  3. A typed confirmation phrase, handled by the interface.
 */
class DataResetController extends Controller
{
    /** GET /api/data-reset — what each scope would remove, right now. */
    public function preview(Request $request, DataResetService $reset): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DATA_RESET), 403);

        $scopes = collect(DataResetService::SCOPES)->map(function (array $definition, string $key) use ($reset) {
            $counts = $reset->preview($key);

            return [
                'scope' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'counts' => $counts,
                'total' => array_sum($counts),
            ];
        })->values();

        return response()->json([
            'scopes' => $scopes,
            'kept' => [
                'Staff accounts and roles',
                'Branches',
                'Settings and branding',
                'The audit log',
                'Existing backups',
            ],
        ]);
    }

    /** POST /api/data-reset */
    public function store(Request $request, DataResetService $reset): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DATA_RESET), 403);

        $data = $request->validate([
            'scope' => ['required', Rule::in(array_keys(DataResetService::SCOPES))],
            'password' => ['required', 'string'],
            // Belt and braces with the interface's typed confirmation: an
            // API client that skipped the dialog should not skip the check.
            'confirm' => ['required', 'in:RESET'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            return response()->json([
                'message' => 'That password is not correct. Nothing was deleted.',
                'errors' => ['password' => ['That password is not correct.']],
            ], 422);
        }

        try {
            $result = $reset->reset($data['scope']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rows = array_sum($result['deleted']);

        return response()->json([
            'message' => $rows === 0
                ? 'There was nothing to delete.'
                : sprintf('%s row(s) deleted. A backup was taken first: %s', number_format($rows), $result['backup']),
            'data' => $result,
        ]);
    }
}
