<?php

namespace Tests\Feature\Health;

use App\Domain\Health\Models\WorkoutEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkoutsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workout_can_be_logged(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.workouts')
            ->call('startCreating')
            ->set('type', 'Running')
            ->set('durationMinutes', '30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Running')
            ->assertSee('30 min');

        $this->assertDatabaseHas('workout_entries', ['user_id' => $user->id, 'type' => 'Running', 'duration_minutes' => 30]);
    }

    public function test_a_blank_type_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.workouts')
            ->call('startCreating')
            ->set('type', '')
            ->set('durationMinutes', '30')
            ->call('save')
            ->assertHasErrors(['type']);
    }

    public function test_a_workout_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $entry = WorkoutEntry::create(['user_id' => $user->id, 'performed_at' => today(), 'type' => 'Gym', 'duration_minutes' => 45]);

        Livewire::actingAs($user)->test('health.workouts')->call('delete', $entry->id);

        $this->assertDatabaseMissing('workout_entries', ['id' => $entry->id]);
    }

    public function test_a_user_cannot_edit_another_users_workout(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $entry = WorkoutEntry::create(['user_id' => $owner->id, 'performed_at' => today(), 'type' => 'Gym', 'duration_minutes' => 45]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('health.workouts')->call('startEditing', $entry->id);
    }
}
