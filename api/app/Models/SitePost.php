<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** An article on the public website. */
#[Fillable([
    'title', 'slug', 'excerpt', 'body', 'image_path',
    'is_published', 'published_at', 'seo_title', 'seo_description', 'author_id',
])]
class SitePost extends Model
{
    use Auditable, SoftDeletes;

    public const DISK = 'public';

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * What the public may see.
     *
     * The date check is what makes scheduling work, and it is applied here
     * rather than in the controller so no public query can forget it and
     * publish something early.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk(self::DISK)->url($this->image_path) : null;
    }

    /** A slug that is unique, readable, and never empty. */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        // A title in Sinhala or Tamil slugs to an empty string, which would
        // collide with every other such post and produce an unreachable URL.
        $base = Str::slug($title) ?: 'post-'.now()->format('Ymd-His');
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function deleteImage(): void
    {
        if ($this->image_path && Storage::disk(self::DISK)->exists($this->image_path)) {
            Storage::disk(self::DISK)->delete($this->image_path);
        }
    }
}
