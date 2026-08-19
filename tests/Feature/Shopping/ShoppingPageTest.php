<?php

namespace Tests\Feature\Shopping;

use App\Domain\Finance\Services\TransactionService;
use App\Domain\Shopping\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ShoppingPageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_log_a_purchase_with_a_new_merchant(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('shopping')
            ->set('merchantName', 'Quickmart Juja')
            ->set('totalAmount', '4350.00')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('purchases', ['user_id' => $user->id, 'total_amount_minor' => 435000]);
        $this->assertDatabaseHas('merchants', ['user_id' => $user->id, 'name' => 'Quickmart Juja']);
    }

    public function test_a_user_can_link_a_purchase_to_an_expense_transaction(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $groceries = $this->createExpenseCategory($user);
        $journal = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 435000, description: 'Quickmart');

        Livewire::actingAs($user)
            ->test('shopping')
            ->set('totalAmount', '4350.00')
            ->set('journalId', (string) $journal->id)
            ->call('create')
            ->assertHasNoErrors();

        $purchase = Purchase::where('user_id', $user->id)->first();
        $this->assertSame($journal->id, $purchase->journal_id);
    }

    public function test_an_already_linked_journal_cannot_be_selected_again(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $groceries = $this->createExpenseCategory($user);
        $journal = app(TransactionService::class)->recordExpense($user, $mpesa, $groceries, 435000);

        Livewire::actingAs($user)
            ->test('shopping')
            ->set('totalAmount', '4350.00')
            ->set('journalId', (string) $journal->id)
            ->call('create');

        // The second time around, that journal no longer appears as a
        // selectable option (it's already linked).
        Livewire::actingAs($user)
            ->test('shopping')
            ->assertDontSee('Quickmart');
    }

    public function test_a_user_can_add_items_to_a_purchase(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('shopping')
            ->set('totalAmount', '4350.00')
            ->call('create');

        $purchase = Purchase::where('user_id', $user->id)->first();

        $component
            ->call('startAddingItem', $purchase->id)
            ->set('itemName', 'Milk 500ml')
            ->set('itemQuantity', '2')
            ->set('itemUnitPrice', '80.00')
            ->call('confirmAddItem')
            ->assertHasNoErrors()
            ->assertSee('Milk 500ml');

        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $purchase->id,
            'name' => 'Milk 500ml',
            'quantity' => 2,
            'unit_price_minor' => 8000,
        ]);
    }
}
