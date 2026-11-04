<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Attendance */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'member_id' => $this->member_id,
            'membership_id' => $this->membership_id,
            'biometric_user_id' => $this->biometric_user_id,

            'attendance_date' => $this->attendance_date->toDateString(),
            'check_in_time' => $this->check_in_time?->toIso8601String(),
            'check_out_time' => $this->check_out_time?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,

            'attendance_type' => $this->attendance_type->value,
            'verification_type' => $this->verification_type->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'membership_valid' => $this->membership_valid,
            'validation_message' => $this->validation_message,
            'notes' => $this->notes,

            'member' => new MemberSummaryResource($this->whenLoaded('member')),
            'check_in_device' => new BiometricDeviceResource($this->whenLoaded('checkInDevice')),
            'check_out_device' => new BiometricDeviceResource($this->whenLoaded('checkOutDevice')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
