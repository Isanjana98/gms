<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The gym's chart of accounts for spending. */
class ExpenseCategoryController extends Controller
{
    /** GET /api/expense-categories */
    public function index(Request $request): JsonResponse
    {
        $categories = ExpenseCategory::query()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->withCount('expenses')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (ExpenseCategory $category) => [
                'id' => $category->id,
                'code' => $category->code,
                'name' => $category->name,
                'description' => $category->description,
                'is_recurring' => $category->is_recurring,
                'is_active' => $category->is_active,
                'sort_order' => $category->sort_order,
                'expenses_count' => $category->expenses_count,
            ])->all(),
        ]);
    }

    /** POST /api/expense-categories */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSE_CATEGORIES_MANAGE), 403);

        $category = ExpenseCategory::create($request->validate($this->rules()));

        return $this->ok("Category \"{$category->name}\" created.", ['id' => $category->id], 201);
    }

    /** PUT /api/expense-categories/{expenseCategory} */
    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSE_CATEGORIES_MANAGE), 403);

        $expenseCategory->update($request->validate($this->rules($expenseCategory->id)));

        return $this->ok("Category \"{$expenseCategory->name}\" saved.");
    }

    /**
     * DELETE /api/expense-categories/{expenseCategory}
     *
     * Refuses while spending is recorded against it. Deactivating is the right
     * move for a category no longer used: deleting it would leave last year's
     * accounts with a total nobody can explain.
     */
    public function destroy(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSE_CATEGORIES_MANAGE), 403);

        $used = $expenseCategory->expenses()->count();

        if ($used > 0) {
            return response()->json([
                'message' => "{$used} expense(s) are recorded under this category. Deactivate it instead of deleting it.",
            ], 422);
        }

        $expenseCategory->delete();

        return $this->ok("Category \"{$expenseCategory->name}\" deleted.");
    }

    /** @return array<string, mixed> */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('expense_categories', 'code')->ignore($ignoreId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_recurring' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
