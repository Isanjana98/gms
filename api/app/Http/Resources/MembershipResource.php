<?php

namespace App\Http\Resources;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Membership */
class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'member_id' => $this->member_id,
            'membership_plan_id' => $this->membership_plan_id,
            'renewed_from_id' => $this->renewed_from_id,
            'reference' => $this->reference,
            'plan_name' => $this->plan_name,

            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'grace_days' => $this->grace_days,
            'access_until' => $this->access_until->toDateString(),
            'days_remaining' => $this->days_remaining,
            'is_expired' => $this->is_expired,

            'price' => (float) $this->price,
            'discount' => (float) $this->discount,
            'registration_fee' => (float) $this->registration_fee,
            'final_amount' => (float) $this->final_amount,
            'paid_amount' => (float) $this->paid_amount,
            'balance' => $this->balance,

            'payment_status' => $this->payment_status->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'activated_at' => $this->activated_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,

            'member' => new MemberSummaryResource($this->whenLoaded('member')),
            'plan' => new MembershipPlanResource($this->whenLoaded('plan')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
