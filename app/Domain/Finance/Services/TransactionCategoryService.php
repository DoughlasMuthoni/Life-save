<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a user-facing category together with the INCOME/EXPENSE ledger
 * account it posts to, so the two can never drift out of agreement
 * (CLAUDE.md §9).
 */
class TransactionCategoryService
{
    public function createCategory(
        User $user,
        string $name,
        TransactionCategoryType $type,
        string $currency = 'KES',
    ): TransactionCategory {
        return DB::transaction(function () use ($user, $name, $type, $currency) {
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
        });
    }
}
