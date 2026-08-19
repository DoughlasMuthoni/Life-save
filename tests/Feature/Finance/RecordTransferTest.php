<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class RecordTransferTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_user_can_record_a_transfer_with_a_fee_through_the_ui(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $fees = $this->createExpenseCategory($user, 'Transaction Fees');

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 5000000);

        Livewire::actingAs($user)
            ->test('finance.record-transfer')
            ->set('fromAccountId', (string) $mpesa->id)
            ->set('toAccountId', (string) $mshwari->id)
            ->set('amount', '10000.00')
            ->set('hasFee', true)
            ->set('feeCategoryId', (string) $fees->id)
            ->set('feeAmount', '15.00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('finance.transactions'));

        $this->assertSame(5000000 - 1000000 - 1500, $mpesa->fresh()->balanceMinor());
        $this->assertSame(1000000, $mshwari->fresh()->balanceMinor());
    }

    public function test_transferring_to_the_same_account_is_rejected(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);

        Livewire::actingAs($user)
            ->test('finance.record-transfer')
            ->set('fromAccountId', (string) $mpesa->id)
            ->set('toAccountId', (string) $mpesa->id)
            ->set('amount', '1000.00')
            ->call('save')
            ->assertHasErrors(['fromAccountId']);
    }
}
