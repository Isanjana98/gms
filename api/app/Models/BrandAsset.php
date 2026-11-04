<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** The gym's logo and sign-in imagery. */
#[Fillable([
    'type', 'path', 'original_filename', 'mime_type', 'size_bytes',
    'caption', 'sort_order', 'is_active', 'uploaded_by',
])]
class BrandAsset extends Model
{
    use Auditable;

    /** Public: the sign-in screen must load these before anyone has a token. */
    public const DISK = 'public';

    public const TYPE_LOGO = 'LOGO';

    public const TYPE_LOGIN = 'LOGIN_BACKGROUND';

    /** The banner across the top of the public website. */
    public const TYPE_HERO = 'HERO';

    /** The website's photo gallery. */
    public const TYPE_GALLERY = 'GALLERY';

    /** @return array<int, string> */
    public static function types(): array
    {
        return [self::TYPE_LOGO, self::TYPE_LOGIN, self::TYPE_HERO, self::TYPE_GALLERY];
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->orderBy('sort_order')->orderBy('id');
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function getSizeLabelAttribute(): string
    {
        $kb = $this->size_bytes / 1024;

        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : round($kb).' KB';
    }

    public function deleteFile(): void
    {
        if ($this->path && Storage::disk(self::DISK)->exists($this->path)) {
            Storage::disk(self::DISK)->delete($this->path);
        }
    }
}
