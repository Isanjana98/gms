<?php

namespace App\Http\Resources;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Member */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'trainer_id' => $this->trainer_id,
            'membership_number' => $this->membership_number,
            'full_name' => $this->full_name,
            'nic_passport' => $this->nic_passport,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->age,
            'gender' => $this->gender,
            'address' => $this->address,
            'city' => $this->city,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_number' => $this->emergency_contact_number,
            'join_date' => $this->join_date?->toDateString(),
            'photo_url' => $this->photo_path ? url('storage/'.$this->photo_path) : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,

            'membership_expires_on' => $this->membership_expires_on?->toDateString(),
            'last_visit_at' => $this->last_visit_at?->toIso8601String(),
            'total_visits' => $this->total_visits,

            'branch' => new BranchResource($this->whenLoaded('branch')),
            'trainer' => new TrainerResource($this->whenLoaded('trainer')),
            'current_membership' => new MembershipResource($this->whenLoaded('currentMembership')),
            'memberships' => MembershipResource::collection($this->whenLoaded('memberships')),
            'biometric_users' => BiometricUserResource::collection($this->whenLoaded('biometricUsers')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
