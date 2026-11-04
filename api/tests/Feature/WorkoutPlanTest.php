<?php

namespace Tests\Feature;

use App\Models\Trainer;
use App\Models\WorkoutExercise;
use App\Models\WorkoutPlan;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SRS 20 - Workout plans. */
class WorkoutPlanTest extends TestCase
{
    use RefreshDatabase;

    private function trainer(): Trainer
    {
        return Trainer::create([
            'branch_id' => $this->branch->id,
            'trainer_code' => 'TR-TEST-1',
            'full_name' => 'Test Trainer',
            'status' => 'ACTIVE',
        ]);
    }

    #[Test]
    public function a_plan_and_its_exercises_are_created_together(): void
    {
        $member = $this->member();
        $trainer = $this->trainer();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $member->id,
                'trainer_id' => $trainer->id,
                'title' => 'Beginner strength',
                'goal' => 'STRENGTH',
                'status' => 'ACTIVE',
                'exercises' => [
                    ['exercise_name' => 'Squat', 'muscle_group' => 'Legs', 'sets' => 3, 'repetitions' => '8-10', 'weight_kg' => 40, 'day_of_week' => 1],
                    ['exercise_name' => 'Bench press', 'muscle_group' => 'Chest', 'sets' => 3, 'repetitions' => '8', 'day_of_week' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Beginner strength')
            ->assertJsonCount(2, 'data.exercises');

        $this->assertSame(1, WorkoutPlan::count());
        $this->assertSame(2, WorkoutExercise::count());
    }

    #[Test]
    public function updating_the_exercises_replaces_the_previous_set(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $plan = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $member->id,
                'title' => 'Original',
                'exercises' => [
                    ['exercise_name' => 'Squat'],
                    ['exercise_name' => 'Deadlift'],
                    ['exercise_name' => 'Row'],
                ],
            ])
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/workout-plans/{$plan}", [
                'exercises' => [['exercise_name' => 'Overhead press']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.exercises');

        $this->assertSame(1, WorkoutExercise::count());
        $this->assertSame('Overhead press', WorkoutExercise::first()->exercise_name);
    }

    #[Test]
    public function deleting_a_plan_hides_it_without_destroying_its_exercises(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $plan = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $member->id,
                'title' => 'To be deleted',
                'exercises' => [['exercise_name' => 'Squat']],
            ])
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/workout-plans/{$plan}")->assertOk();

        // Soft-deleted, so the programme disappears from the trainer's list
        // but the exercises stay attached. The database cascade only fires on
        // a hard delete, which is what makes restoring a plan give back a
        // complete plan rather than an empty shell.
        $this->assertSoftDeleted('workout_plans', ['id' => $plan]);
        $this->assertSame(1, WorkoutExercise::where('workout_plan_id', $plan)->count());

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/workout-plans')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_trainer_can_write_plans_but_cannot_reach_payments(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_TRAINER);
        $member = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $member->id,
                'title' => 'Trainer-authored plan',
                'exercises' => [['exercise_name' => 'Plank', 'duration_seconds' => 60]],
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')->getJson('/api/payments')->assertForbidden();
    }

    #[Test]
    public function a_trainer_sees_only_their_own_members_schedules(): void
    {
        // A trainer holds workouts.manage, so "can they manage?" cannot stand
        // in for "may they see everyone?" - that check let every trainer read
        // the whole gym's programmes.
        $user = $this->userWithRole(Permissions::ROLE_TRAINER);
        $trainer = Trainer::create([
            'branch_id' => $this->branch->id,
            'trainer_code' => 'TR-OWN-1',
            'full_name' => 'Own Trainer',
            'user_id' => $user->id,
            'status' => 'ACTIVE',
        ]);

        $mine = $this->member(['full_name' => 'Assigned to me', 'trainer_id' => $trainer->id]);
        $theirs = $this->member(['full_name' => 'Someone else\'s']);

        WorkoutPlan::create(['member_id' => $mine->id, 'trainer_id' => $trainer->id, 'title' => 'Mine', 'status' => 'ACTIVE']);
        WorkoutPlan::create(['member_id' => $theirs->id, 'title' => 'Not mine', 'status' => 'ACTIVE']);

        $this->actingAs($user->refresh(), 'sanctum')
            ->getJson('/api/workout-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    #[Test]
    public function a_trainer_cannot_open_another_trainers_members_schedule(): void
    {
        // Restricting only the list is not a restriction: member ids are
        // sequential and the detail endpoint would hand it over anyway.
        $user = $this->userWithRole(Permissions::ROLE_TRAINER);
        Trainer::create([
            'branch_id' => $this->branch->id,
            'trainer_code' => 'TR-OWN-2',
            'full_name' => 'Own Trainer',
            'user_id' => $user->id,
            'status' => 'ACTIVE',
        ]);

        $stranger = $this->member(['full_name' => 'Not their member']);

        $this->actingAs($user->refresh(), 'sanctum')
            ->getJson("/api/members/{$stranger->id}/schedule")
            ->assertForbidden();
    }

    #[Test]
    public function a_trainer_account_with_no_trainer_record_sees_nothing_rather_than_everything(): void
    {
        // The dangerous failure mode: the scoping used to key off "does this
        // user have a trainer record", so an unlinked Trainer-role account
        // skipped the filter entirely and read every member's programme.
        $user = $this->userWithRole(Permissions::ROLE_TRAINER);
        $this->assertNull($user->trainer, 'This test needs an unlinked trainer account.');

        $member = $this->member();
        WorkoutPlan::create(['member_id' => $member->id, 'title' => 'Someone else\'s', 'status' => 'ACTIVE']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/workout-plans')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/members/{$member->id}/schedule")
            ->assertForbidden();
    }

    #[Test]
    public function reception_can_see_and_write_every_members_schedule(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $member = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $member->id,
                'title' => 'Front-desk written',
                'exercises' => [['exercise_name' => 'Treadmill', 'day_of_week' => 2, 'duration_seconds' => 1200]],
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/members/{$member->id}/schedule")
            ->assertOk()
            ->assertJsonPath('plan.title', 'Front-desk written');
    }

    #[Test]
    public function the_schedule_endpoint_returns_a_full_week_grouped_by_day(): void
    {
        // Seven days always come back, including the empty ones - a rest day
        // is information, and the grid needs a cell for it.
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/workout-plans', [
            'member_id' => $member->id,
            'title' => 'Three-day split',
            'status' => 'ACTIVE',
            'exercises' => [
                ['exercise_name' => 'Squat', 'muscle_group' => 'Legs', 'day_of_week' => 1, 'sets' => 3, 'repetitions' => '8-10'],
                ['exercise_name' => 'Bench press', 'muscle_group' => 'Chest', 'day_of_week' => 3, 'sets' => 3],
                ['exercise_name' => 'Deadlift', 'muscle_group' => 'Back', 'day_of_week' => 5, 'sets' => 3],
                ['exercise_name' => 'Warm-up walk', 'duration_seconds' => 300],
            ],
        ])->assertCreated();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/members/{$member->id}/schedule")
            ->assertOk();

        $response->assertJsonCount(7, 'days');
        $response->assertJsonPath('days.0.label', 'Monday');
        $response->assertJsonCount(1, 'days.0.exercises');
        // Tuesday is a rest day and must still be present.
        $response->assertJsonCount(0, 'days.1.exercises');
        $response->assertJsonCount(1, 'days.2.exercises');
        // An exercise with no day applies to every session.
        $response->assertJsonCount(1, 'every_session');
    }

    #[Test]
    public function a_member_with_no_schedule_returns_an_empty_week_rather_than_an_error(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/members/{$member->id}/schedule")
            ->assertOk()
            ->assertJsonPath('plan', null)
            ->assertJsonCount(7, 'days');
    }

    #[Test]
    public function an_exercise_must_have_a_name(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/workout-plans', [
                'member_id' => $this->member()->id,
                'title' => 'Incomplete',
                'exercises' => [['sets' => 3]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exercises.0.exercise_name');
    }
}
