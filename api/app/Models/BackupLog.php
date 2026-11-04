<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** SRS 30 - Record of a database backup run. */
#[Fillable([
    'type', 'filename', 'path', 'size_bytes', 'status', 'message',
    'started_at', 'finished_at', 'triggered_by',
])]
class BackupLog extends Model
{
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'SUCCESS');
    }

    public function getSizeMbAttribute(): ?float
    {
        return $this->size_bytes ? round($this->size_bytes / 1048576, 2) : null;
    }
}
