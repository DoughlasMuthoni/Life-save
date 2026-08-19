<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ReverseTransactionTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_reverse_a_transaction_from_the_transactions_page(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $journal = app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000);
        $this->assertSame(500000, $mpesa->fresh()->balanceMinor());

        Livewire::actingAs($user)
            ->test('finance.transactions')
            ->call('startReversal', $journal->id)
            ->set('reversalReason', 'Entered twice by mistake')
            ->call('confirmReversal')
            ->assertHasNoErrors();

        $this->assertSame(0, $mpesa->fresh()->balanceMinor());
        $this->assertDatabaseHas('journals', ['id' => $journal->id, 'is_reversed' => true]);
    }

    public function test_a_reversal_reason_is_required(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $journal = app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000);

        Livewire::actingAs($user)
            ->test('finance.transactions')
            ->call('startReversal', $journal->id)
            ->set('reversalReason', '')
            ->call('confirmReversal')
            ->assertHasErrors(['reversalReason']);

        $this->assertSame(500000, $mpesa->fresh()->balanceMinor());
    }

    public function test_a_user_cannot_reverse_another_users_journal(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mpesa = $this->createFinancialAccount($owner);
        $salary = $this->createIncomeCategory($owner);

        $journal = app(TransactionService::class)->recordIncome($owner, $mpesa, $salary, 500000);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)
            ->test('finance.transactions')
            ->call('startReversal', $journal->id)
            ->set('reversalReason', 'trying to mess with someone else\'s ledger')
            ->call('confirmReversal');
    }
}
