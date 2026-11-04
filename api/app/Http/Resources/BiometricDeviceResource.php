<?php

namespace App\Http\Resources;

use App\Models\BiometricDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SRS 23 - Device details for the management screen.
 *
 * The device password is never included, in any form: it opens a door, and
 * an interface that displays it turns every screen-share into a key handover.
 *
 * Written to survive a partial load. Several endpoints embed a device with
 * only `id, name, code` selected - enough for a label next to an attendance
 * row - and a resource that assumes every column is present turns a cheap
 * query into a 500 the first time a real device appears in a list.
 *
 * @mixin BiometricDevice
 */
class BiometricDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'code' => $this->code,
            'name' => $this->name,
            'driver' => $this->driver,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'firmware' => $this->firmware,

            'ip_address' => $this->ip_address,
            'port' => $this->port,
            'use_https' => $this->use_https,
            'username' => $this->username,
            'has_password' => filled($this->getRawOriginal('password')),

            'mode' => $this->mode?->value,
            'mode_label' => $this->mode?->label(),
            'sync_mode' => $this->sync_mode,
            'is_active' => $this->is_active,

            'connection_status' => $this->connection_status,
            'last_error' => $this->last_error,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'consecutive_failures' => $this->consecutive_failures,

            'enrolled_users_count' => $this->whenCounted('biometricUsers'),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
