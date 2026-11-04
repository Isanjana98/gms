<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SRS 20 - One exercise line inside a workout plan. */
#[Fillable([
    'workout_plan_id', 'day_of_week', 'sort_order', 'exercise_name', 'muscle_group',
    'sets', 'repetitions', 'weight_kg', 'duration_seconds', 'rest_seconds', 'notes',
])]
class WorkoutExercise extends Model
{
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'sort_order' => 'integer',
            'sets' => 'integer',
            'weight_kg' => 'decimal:2',
            'duration_seconds' => 'integer',
            'rest_seconds' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }
}
