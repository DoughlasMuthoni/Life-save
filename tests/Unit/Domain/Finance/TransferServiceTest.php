<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Finance\Services\TransferService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private TransferService $transfers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transfers = app(TransferService::class);
    }

    public function test_a_transfer_moves_money_without_a_fee(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)
            ->recordIncome($user, $mpesa, $salary, 5000000);

        $journal = $this->transfers->recordTransfer($user, $mpesa, $mshwari, 2000000);

        $this->assertSame(JournalType::TRANSFER, $journal->journal_type);
        $this->assertSame(3000000, $mpesa->balanceMinor());
        $this->assertSame(2000000, $mshwari->balanceMinor());
    }

    public function test_a_transfer_fee_is_posted_as_a_separate_expense_leg(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);
        $fees = $this->createExpenseCategory($user, 'Transaction Fees');

        app(TransactionService::class)
            ->recordIncome($user, $mpesa, $salary, 5000000);

        $journal = $this->transfers->recordTransfer(
            user: $user,
            from: $mpesa,
            to: $mshwari,
            amountMinor: 1000000,
            feeCategory: $fees,
            feeMinor: 1500,
        );

        // M-Pesa loses amount + fee; M-Shwari receives only the net amount.
        $this->assertSame(5000000 - 1000000 - 1500, $mpesa->balanceMinor());
        $this->assertSame(1000000, $mshwari->balanceMinor());
        $this->assertSame(1500, $fees->ledgerAccount->balanceMinor());

        $this->assertCount(3, $journal->entries);
    }

    public function test_a_transfer_never_posts_to_an_income_ledger_account(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa');
        $mshwari = $this->createFinancialAccount($user, 'M-Shwari', FinancialAccountProvider::MSHWARI);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)
            ->recordIncome($user, $mpesa, $salary, 5000000);

        $journal = $this->transfers->recordTransfer($user, $mpesa, $mshwari, 1000000);

        $touchedTypes = LedgerEntry::whereIn('id', $journal->entries->pluck('id'))
            ->with('ledgerAccount')
            ->get()
            ->pluck('ledgerAccount.type')
            ->unique();

        $this->assertFalse($touchedTypes->contains(LedgerAccountType::INCOME));
        $this->assertFalse($touchedTypes->contains(LedgerAccountType::EXPENSE));
    }

    public function test_transferring_an_account_to_itself_is_rejected(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);

        $this->expectException(InvalidArgumentException::class);

        $this->transfers->recordTransfer($user, $mpesa, $mpesa, 1000);
    }

    public function test_transferring_another_users_account_is_rejected(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mpesa = $this->createFinancialAccount($owner, 'M-Pesa');
        $mshwari = $this->createFinancialAccount($owner, 'M-Shwari', FinancialAccountProvider::MSHWARI);

        $this->expectException(InvalidArgumentException::class);

        $this->transfers->recordTransfer($intruder, $mpesa, $mshwari, 1000);
    }
}
