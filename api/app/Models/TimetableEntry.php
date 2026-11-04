<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class on the published timetable.
 *
 * Display only — see the migration. This is a poster on the wall, not a
 * booking system.
 */
#[Fillable([
    'branch_id', 'class_name', 'description', 'day_of_week', 'start_time', 'end_time',
    'room', 'trainer_id', 'instructor_name', 'is_active',
])]
class TimetableEntry extends Model
{
    use Auditable, BelongsToBranch;

    /** Indexed by dayOfWeekIso, so Monday is 1. */
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('day_of_week')->orderBy('start_time');
    }

    public function getDayNameAttribute(): string
    {
        return self::DAYS[$this->day_of_week] ?? '—';
    }

    /** The linked trainer's name, falling back to the free-text one. */
    public function getInstructorAttribute(): ?string
    {
        return $this->trainer?->full_name ?: $this->instructor_name;
    }

    /** "6:00 pm – 7:00 pm", which is how a timetable is read. */
    public function getTimeLabelAttribute(): string
    {
        return $this->formatTime($this->start_time).' – '.$this->formatTime($this->end_time);
    }

    private function formatTime(?string $value): string
    {
        if (! $value) {
            return '';
        }

        // Stored as TIME, so it arrives as "18:00:00" rather than a date.
        [$hour, $minute] = array_pad(explode(':', $value), 2, '00');
        $hour = (int) $hour;
        $suffix = $hour >= 12 ? 'pm' : 'am';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return $minute === '00' ? "{$display}{$suffix}" : "{$display}:{$minute}{$suffix}";
    }
}
