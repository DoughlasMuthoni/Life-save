<?php

namespace Tests\Support;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\TransactionCategory;
use App\Models\User;

/**
 * Shared fixture builders for finance domain tests, keeping each test's
 * setup to "one asset account, one category" rather than re-deriving the
 * chart of accounts every time.
 */
trait CreatesFinanceFixtures
{
    protected function createFinancialAccount(
        User $user,
        string $name = 'M-Pesa',
        FinancialAccountProvider $provider = FinancialAccountProvider::MPESA,
        string $currency = 'KES',
    ): FinancialAccount {
        $ledgerAccount = LedgerAccount::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => LedgerAccountType::ASSET,
            'currency' => $currency,
        ]);

        return FinancialAccount::create([
            'user_id' => $user->id,
            'ledger_account_id' => $ledgerAccount->id,
            'name' => $name,
            'provider' => $provider,
            'currency' => $currency,
        ]);
    }

    protected function createCategory(
        User $user,
        string $name,
        TransactionCategoryType $type,
        string $currency = 'KES',
    ): TransactionCategory {
        $ledgerAccount = LedgerAccount::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type->ledgerAccountType(),
            'currency' => $currency,
        ]);

        return TransactionCategory::create([
            'user_id' => $user->id,
            'ledger_account_id' => $ledgerAccount->id,
            'name' => $name,
            'type' => $type,
        ]);
    }

    protected function createIncomeCategory(User $user, string $name = 'Salary'): TransactionCategory
    {
        return $this->createCategory($user, $name, TransactionCategoryType::INCOME);
    }

    protected function createExpenseCategory(User $user, string $name = 'Groceries'): TransactionCategory
    {
        return $this->createCategory($user, $name, TransactionCategoryType::EXPENSE);
    }
}
