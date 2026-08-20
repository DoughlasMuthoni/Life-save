<?php

namespace Tests\Feature\Habits;

use App\Domain\Habits\Models\Habit;
use App\Domain\Habits\Models\HabitCheckIn;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HabitsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_habit_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('habits')
            ->set('name', 'Drink water')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Drink water');

        $this->assertDatabaseHas('habits', ['user_id' => $user->id, 'name' => 'Drink water']);
    }

    public function test_a_blank_habit_name_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('habits')
            ->set('name', '')
            ->call('create')
            ->assertHasErrors(['name']);
    }

    public function test_toggling_today_checks_the_habit_in(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Read']);

        Livewire::actingAs($user)->test('habits')->call('toggleToday', $habit->id);

        $this->assertDatabaseHas('habit_check_ins', [
            'habit_id' => $habit->id,
            'user_id' => $user->id,
            'date' => today()->toDateString(),
        ]);
    }

    public function test_toggling_today_twice_undoes_the_check_in(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Read']);

        $component = Livewire::actingAs($user)->test('habits');
        $component->call('toggleToday', $habit->id);
        $component->call('toggleToday', $habit->id);

        $this->assertDatabaseCount('habit_check_ins', 0);
    }

    public function test_the_streak_counts_consecutive_days_ending_today(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Read']);

        foreach ([2, 1, 0] as $daysAgo) {
            HabitCheckIn::create([
                'user_id' => $user->id,
                'habit_id' => $habit->id,
                'date' => today()->subDays($daysAgo),
            ]);
        }

        $this->assertSame(3, $habit->fresh()->load('checkIns')->currentStreak());
    }

    public function test_a_gap_breaks_the_streak(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Read']);

        HabitCheckIn::create(['user_id' => $user->id, 'habit_id' => $habit->id, 'date' => today()->subDays(5)]);
        HabitCheckIn::create(['user_id' => $user->id, 'habit_id' => $habit->id, 'date' => today()]);

        $this->assertSame(1, $habit->fresh()->load('checkIns')->currentStreak());
    }

    public function test_a_habit_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $habit = Habit::create(['user_id' => $user->id, 'name' => 'Delete me']);

        Livewire::actingAs($user)
            ->test('habits')
            ->call('delete', $habit->id)
            ->assertDontSee('Delete me');

        $this->assertDatabaseMissing('habits', ['id' => $habit->id]);
    }

    public function test_a_user_cannot_check_in_another_users_habit(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $habit = Habit::create(['user_id' => $owner->id, 'name' => 'Private']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('habits')->call('toggleToday', $habit->id);
    }
}
