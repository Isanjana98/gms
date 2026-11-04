<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime configuration, editable by an administrator (SRS 10, 23).
 *
 * Reads are cached because the attendance path consults settings on every
 * fingerprint scan, and a busy gym at 6pm should not turn each scan into a
 * database round trip for a value that changes twice a year.
 */
#[Fillable([
    'branch_id', 'group', 'key', 'value', 'type', 'label', 'description', 'is_public',
])]
class Setting extends Model
{
    private const CACHE_KEY = 'gms.settings';

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Resolve a setting, preferring the branch override over the global value.
     */
    public static function get(string $key, mixed $default = null, ?int $branchId = null): mixed
    {
        $all = static::map();

        return $all["{$branchId}:{$key}"]
            ?? $all[":{$key}"]
            ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $branchId = null, string $type = 'string'): self
    {
        $stored = match (true) {
            \is_array($value) => json_encode($value),
            \is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        $setting = static::updateOrCreate(
            ['branch_id' => $branchId, 'key' => $key],
            ['value' => $stored, 'type' => $type],
        );

        static::flush();

        return $setting;
    }

    /**
     * All settings as a flat map keyed "branchId:key" with values already cast.
     *
     * @return array<string, mixed>
     */
    public static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            return static::query()->get()->mapWithKeys(fn (self $s) => [
                "{$s->branch_id}:{$s->key}" => $s->typedValue(),
            ])->all();
        });
    }

    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'decimal' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
