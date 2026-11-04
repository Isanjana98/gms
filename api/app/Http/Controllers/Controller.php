<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The branch a request should operate on.
     *
     * A user tied to a branch is confined to it - passing ?branch_id for
     * another branch must not widen their view. Only a user permitted to see
     * all branches may choose, and choosing nothing means all of them.
     */
    protected function branchScope(Request $request): ?int
    {
        $user = $request->user();

        if ($user && ! $user->isMultiBranch()) {
            return $user->branch_id;
        }

        $requested = $request->integer('branch_id');

        return $requested > 0 ? $requested : null;
    }

    /**
     * The branch a new record belongs to when the caller did not name one.
     *
     * Falls through to the default branch because a Super Administrator is
     * deliberately not tied to any branch (SRS 22) - without this, the one
     * account that can do everything cannot register a member.
     */
    protected function defaultBranchId(Request $request): ?int
    {
        $explicit = $request->integer('branch_id');

        if ($explicit > 0) {
            return $explicit;
        }

        if ($request->user()?->branch_id) {
            return $request->user()->branch_id;
        }

        $default = Branch::default();

        abort_if(
            $default === null,
            422,
            'No branch exists yet. Create a branch before adding records.',
        );

        return $default->getKey();
    }

    protected function ok(string $message, array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, ...$extra], $status);
    }

    /** Clamp a caller-supplied page size so one request cannot ask for everything. */
    protected function perPage(Request $request, int $default = 25, int $max = 200): int
    {
        return min(max($request->integer('per_page') ?: $default, 1), $max);
    }
}
