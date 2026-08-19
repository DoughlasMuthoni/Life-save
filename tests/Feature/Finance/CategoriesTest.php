<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_an_expense_category_through_the_ui(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.categories')
            ->set('name', 'Groceries')
            ->set('type', 'expense')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transaction_categories', [
            'user_id' => $user->id,
            'name' => 'Groceries',
            'type' => 'expense',
        ]);
    }

    public function test_the_category_form_rejects_an_invalid_type(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.categories')
            ->set('name', 'Groceries')
            ->set('type', 'not-a-real-type')
            ->call('create')
            ->assertHasErrors(['type']);
    }
}
