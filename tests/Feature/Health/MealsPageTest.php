<?php

namespace Tests\Feature\Health;

use App\Domain\Health\Enums\MealType;
use App\Domain\Health\Models\MealEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MealsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_meal_can_be_logged(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.meals')
            ->call('startCreating')
            ->set('mealType', 'lunch')
            ->set('description', 'Grilled chicken and rice')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Lunch')
            ->assertSee('Grilled chicken and rice');

        $this->assertDatabaseHas('meal_entries', [
            'user_id' => $user->id,
            'meal_type' => MealType::LUNCH->value,
            'description' => 'Grilled chicken and rice',
        ]);
    }

    public function test_a_meal_type_is_optional(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.meals')
            ->call('startCreating')
            ->set('description', 'A snack')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('meal_entries', ['user_id' => $user->id, 'meal_type' => null]);
    }

    public function test_a_blank_description_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.meals')
            ->call('startCreating')
            ->set('description', '')
            ->call('save')
            ->assertHasErrors(['description']);
    }

    public function test_a_meal_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $entry = MealEntry::create(['user_id' => $user->id, 'eaten_at' => now(), 'description' => 'Delete me']);

        Livewire::actingAs($user)->test('health.meals')->call('delete', $entry->id);

        $this->assertDatabaseMissing('meal_entries', ['id' => $entry->id]);
    }

    public function test_a_user_cannot_edit_another_users_meal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $entry = MealEntry::create(['user_id' => $owner->id, 'eaten_at' => now(), 'description' => 'Private']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('health.meals')->call('startEditing', $entry->id);
    }
}
