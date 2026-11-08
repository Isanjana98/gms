<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationQuestion;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The gym's own questions on the joining form.
 *
 * These are configuration, so they sit behind SETTINGS_MANAGE rather than a
 * permission of their own. The seven PAR-Q questions are not here and are not
 * editable: see App\Support\ParQ for why.
 */
class RegistrationQuestionController extends Controller
{
    /** GET /api/registration-questions */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REGISTRATIONS_VIEW), 403);

        return response()->json([
            'data' => RegistrationQuestion::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    /** POST /api/registration-questions */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_MANAGE), 403);

        $data = $this->validated($request);

        /*
         * The key is derived once, from the label, and never changes again.
         * Answers are filed under it, so renaming it later would orphan
         * every answer already given - which is exactly the kind of silent
         * history-rewriting this whole feature is built to avoid.
         */
        $data['key'] = $this->uniqueKey($data['label'], $data['branch_id'] ?? null);

        return response()->json([
            'data' => RegistrationQuestion::create($data),
            'message' => 'Question added.',
        ], 201);
    }

    /** PUT /api/registration-questions/{question} */
    public function update(Request $request, RegistrationQuestion $question): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_MANAGE), 403);

        $data = $this->validated($request, $question);

        // Deliberately not touching `key` or `branch_id`: both decide where
        // existing answers live.
        $question->update($data);

        return response()->json([
            'data' => $question->fresh(),
            'message' => 'Question updated. Answers already given keep the wording they were given under.',
        ]);
    }

    /**
     * DELETE /api/registration-questions/{question}
     *
     * Soft, and the model is retired rather than removed from the form's
     * history. A question a hundred people have answered should stop being
     * asked without the answers losing their meaning.
     */
    public function destroy(Request $request, RegistrationQuestion $question): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_MANAGE), 403);

        $question->forceFill(['is_active' => false])->save();
        $question->delete();

        return response()->json(['message' => 'Question removed from the form.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RegistrationQuestion $existing = null): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'label' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(RegistrationQuestion::TYPES)],
            'options' => ['nullable', 'array', 'max:20'],
            'options.*' => ['required', 'string', 'max:100'],
            'is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A choice with nothing to choose from is a dead end on the form.
        if ($data['type'] === 'CHOICE' && count($data['options'] ?? []) < 2) {
            abort(422, 'A multiple-choice question needs at least two options.');
        }

        if ($data['type'] !== 'CHOICE') {
            $data['options'] = null;
        }

        unset($data['branch_id']);

        if ($existing === null) {
            $data['branch_id'] = $request->integer('branch_id') ?: null;
        }

        return $data;
    }

    private function uniqueKey(string $label, ?int $branchId): string
    {
        $base = Str::of($label)->slug('_')->limit(40, '')->value() ?: 'question';
        $key = $base;
        $n = 2;

        while (RegistrationQuestion::withTrashed()
            ->where('branch_id', $branchId)
            ->where('key', $key)
            ->exists()
        ) {
            // withTrashed, because a retired question still owns its key:
            // reusing it would file new answers under the old one's name.
            $key = Str::limit($base, 44, '')."_{$n}";
            $n++;
        }

        return $key;
    }
}
