<?php

namespace App\Http\Resources;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The few member fields a list row or a nested reference needs.
 *
 * Attendance and payment lists embed the member on every row; sending the full
 * profile there would multiply the payload for fields nothing displays.
 *
 * @mixin Member
 */
class MemberSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'membership_number' => $this->membership_number,
            'full_name' => $this->full_name,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'status' => $this->status?->value,
            'photo_url' => $this->photo_path ? url('storage/'.$this->photo_path) : null,
            'membership_expires_on' => $this->membership_expires_on?->toDateString(),
        ];
    }
}
