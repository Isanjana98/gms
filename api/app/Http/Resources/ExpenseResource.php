<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'expense_category_id' => $this->expense_category_id,

            'reference' => $this->reference,
            'expense_date' => $this->expense_date?->toDateString(),
            'amount' => (float) $this->amount,
            'tax_amount' => (float) $this->tax_amount,
            'total' => $this->total,

            // Null-safe because list endpoints select a subset of columns, and
            // a partially hydrated model leaves the enum cast holding null.
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),

            'payee' => $this->payee,
            'supplier_reference' => $this->supplier_reference,
            'description' => $this->description,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'code' => $this->category?->code,
                'is_recurring' => $this->category?->is_recurring,
            ]),

            'recorded_by' => $this->whenLoaded('recordedBy', fn () => [
                'id' => $this->recordedBy?->id,
                'name' => $this->recordedBy?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
