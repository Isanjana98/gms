<?php

namespace App\Http\Resources;

use App\Models\BiometricUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BiometricUser */
class BiometricUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'device_id' => $this->device_id,
            'biometric_user_id' => $this->biometric_user_id,
            'device_user_name' => $this->device_user_name,

            'enrollment_status' => $this->enrollment_status,
            'fingerprint_count' => $this->fingerprint_count,
            'has_card' => $this->has_card,
            'has_face' => $this->has_face,
            'is_verified' => $this->is_verified,

            'sync_status' => $this->sync_status,
            'sync_error' => $this->sync_error,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'is_active' => $this->is_active,
            // Whether the reader will currently admit them, as last pushed.
            // Distinct from is_active, which is whether we still use this
            // mapping at all.
            'access_enabled' => $this->access_enabled,

            'member' => new MemberSummaryResource($this->whenLoaded('member')),
            'device' => new BiometricDeviceResource($this->whenLoaded('device')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
