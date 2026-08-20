<?php

namespace Tests\Feature\Health;

use App\Domain\Health\Models\WeightEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WeightPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_weight_entry_can_be_recorded(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.weight')
            ->call('startCreating')
            ->set('weightKg', '72.50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('72.50 kg');

        $this->assertDatabaseHas('weight_entries', ['user_id' => $user->id, 'weight_kg' => 72.50]);
    }

    public function test_a_non_numeric_weight_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('health.weight')
            ->call('startCreating')
            ->set('weightKg', 'not a number')
            ->call('save')
            ->assertHasErrors(['weightKg']);
    }

    public function test_the_change_since_the_previous_entry_is_shown(): void
    {
        $user = User::factory()->create();
        WeightEntry::create(['user_id' => $user->id, 'recorded_at' => today()->subDay(), 'weight_kg' => 75.00]);
        WeightEntry::create(['user_id' => $user->id, 'recorded_at' => today(), 'weight_kg' => 74.00]);

        Livewire::actingAs($user)
            ->test('health.weight')
            ->assertSee('-1')
            ->assertSee('kg');
    }

    public function test_an_entry_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $entry = WeightEntry::create(['user_id' => $user->id, 'recorded_at' => today(), 'weight_kg' => 70.00]);

        Livewire::actingAs($user)->test('health.weight')->call('delete', $entry->id);

        $this->assertDatabaseMissing('weight_entries', ['id' => $entry->id]);
    }

    public function test_a_user_cannot_edit_another_users_entry(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $entry = WeightEntry::create(['user_id' => $owner->id, 'recorded_at' => today(), 'weight_kg' => 70.00]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('health.weight')->call('startEditing', $entry->id);
    }
}
