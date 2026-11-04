<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'member_id' => $this->member_id,
            'invoice_id' => $this->invoice_id,
            'membership_id' => $this->membership_id,
            'refund_of_payment_id' => $this->refund_of_payment_id,

            'receipt_number' => $this->receipt_number,
            'payment_type' => $this->payment_type->value,
            'payment_type_label' => $this->payment_type->label(),
            'amount' => (float) $this->amount,
            'discount' => (float) $this->discount,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'reference' => $this->reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status,
            'is_refund' => $this->is_refund,
            'notes' => $this->notes,

            'member' => new MemberSummaryResource($this->whenLoaded('member')),
            'received_by' => $this->whenLoaded('receivedBy', fn () => [
                'id' => $this->receivedBy?->id,
                'name' => $this->receivedBy?->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
