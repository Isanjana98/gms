<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\NumberGenerator;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Money going out: rent, salaries, equipment, utilities. */
class ExpenseController extends Controller
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /** GET /api/expenses */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $expenses = $this->filtered($request)
            ->with(['category:id,name,code,is_recurring', 'recordedBy:id,name'])
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('payee', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")
                    ->orWhere('supplier_reference', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            }))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ExpenseResource::collection($expenses)->additional([
            'summary' => $this->summary($request),
        ]);
    }

    /** GET /api/expenses/{expense} */
    public function show(Expense $expense): ExpenseResource
    {
        return new ExpenseResource($expense->load(['category', 'recordedBy:id,name']));
    }

    /** POST /api/expenses */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSES_MANAGE), 403);

        $data = $request->validate($this->rules());

        $expense = $this->numbers->withRetry(fn () => DB::transaction(function () use ($data, $request) {
            return Expense::create([
                ...$data,
                'branch_id' => $data['branch_id'] ?? $this->defaultBranchId($request),
                'reference' => $this->numbers->expenseReference(),
                'recorded_by' => $request->user()->getKey(),
            ]);
        }));

        return (new ExpenseResource($expense->load('category')))
            ->additional(['message' => "Expense {$expense->reference} recorded."])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT /api/expenses/{expense}
     *
     * Editable, unlike a payment. An expense records something that happened
     * outside the system, so a mistyped supplier invoice is a correction, not a
     * reversal — and the audit log keeps both versions.
     */
    public function update(Request $request, Expense $expense): ExpenseResource
    {
        abort_unless($request->user()->can(Permissions::EXPENSES_MANAGE), 403);

        $expense->update($request->validate($this->rules()));

        return new ExpenseResource($expense->load('category'));
    }

    /** DELETE /api/expenses/{expense} */
    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSES_MANAGE), 403);

        $reference = $expense->reference;
        $expense->delete();

        return $this->ok("Expense {$reference} deleted.");
    }

    /**
     * Totals for the whole filtered set.
     *
     * Summed in SQL over every matching row rather than over the page, because
     * a footer that silently totals 25 of 300 rows is worse than no footer.
     *
     * @return array<string, mixed>
     */
    private function summary(Request $request): array
    {
        // Aliased total_amount, not total: Expense::getTotalAttribute() would
        // shadow an aggregate called `total` and recompute it from attributes
        // this query never selected, giving a silent 0.00.
        $row = $this->filtered($request)
            ->selectRaw('COUNT(*) AS entries, COALESCE(SUM(amount + tax_amount), 0) AS total_amount')
            ->first();

        return [
            'entries' => (int) ($row->entries ?? 0),
            'total' => round((float) ($row->total_amount ?? 0), 2),
        ];
    }

    /**
     * The filters the list and its total must agree on.
     *
     * Shared rather than written twice: a footer that totals a different set of
     * rows from the table above it is a bug nobody reports, because it still
     * looks like a number.
     */
    private function filtered(Request $request): Builder
    {
        return Expense::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when(
                $request->filled('from') || $request->filled('to'),
                fn (Builder $q) => $q->between(
                    $request->string('from')->toString() ?: '1970-01-01',
                    $request->string('to')->toString() ?: '2999-12-31',
                ),
            )
            ->when($request->integer('expense_category_id'), fn (Builder $q, $id) => $q->where('expense_category_id', $id))
            ->when($request->string('method')->toString(), fn (Builder $q, $method) => $q->where('method', $method));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            // Not in the future: an expense is something that has happened.
            // Committed spending that has not yet left the account is a
            // different thing, and pretending otherwise makes this month's
            // costs depend on next month's invoices.
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
            'payee' => ['nullable', 'string', 'max:255'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
