<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'telephone' => $this->telephone,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'members_count' => $this->whenCounted('members'),
            'devices_count' => $this->whenCounted('devices'),
        ];
    }
}
