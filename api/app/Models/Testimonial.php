<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A member quote on the public website. */
#[Fillable(['name', 'role', 'quote', 'photo_path', 'rating', 'is_active', 'sort_order'])]
class Testimonial extends Model
{
    use Auditable, SoftDeletes;

    public const DISK = 'public';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rating' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk(self::DISK)->url($this->photo_path) : null;
    }

    public function deletePhoto(): void
    {
        if ($this->photo_path && Storage::disk(self::DISK)->exists($this->photo_path)) {
            Storage::disk(self::DISK)->delete($this->photo_path);
        }
    }
}
