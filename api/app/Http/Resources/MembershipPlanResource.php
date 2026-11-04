<?php

namespace App\Http\Resources;

use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MembershipPlan */
class MembershipPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'duration_label' => $this->duration_label,
            'price' => (float) $this->price,
            'default_discount' => (float) $this->default_discount,
            'registration_fee' => (float) $this->registration_fee,
            'grace_days' => $this->grace_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'active_memberships_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
