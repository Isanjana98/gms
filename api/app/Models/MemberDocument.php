<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A document held against a member: agreement, ID copy, medical clearance. */
#[Fillable([
    'member_id', 'replaces_id', 'type', 'title', 'original_filename', 'path',
    'mime_type', 'size_bytes', 'signed_on', 'expires_on', 'notes', 'uploaded_by',
])]
// The storage path is an internal detail. Exposing it invites someone to try
// fetching it directly, which is exactly what the private disk prevents.
#[Hidden(['path'])]
class MemberDocument extends Model
{
    use Auditable, SoftDeletes;

    /** The disk these live on - private, never the public one. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'signed_on' => 'date',
            'expires_on' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The document this one replaced, if it is a re-signed version. */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_on')
            ->whereBetween('expires_on', [today(), today()->addDays($days)]);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isBefore(today());
    }

    public function getSizeLabelAttribute(): string
    {
        $kb = $this->size_bytes / 1024;

        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : round($kb).' KB';
    }

    /** Remove the file from disk as well as the row. */
    public function deleteFile(): void
    {
        if ($this->path && Storage::disk(self::DISK)->exists($this->path)) {
            Storage::disk(self::DISK)->delete($this->path);
        }
    }
}
