<?php

namespace App\Http\Resources;

use App\Models\Trainer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Trainer */
class TrainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'user_id' => $this->user_id,
            'trainer_code' => $this->trainer_code,
            'full_name' => $this->full_name,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'specialization' => $this->specialization,
            'joining_date' => $this->joining_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'members_count' => $this->whenCounted('members'),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
