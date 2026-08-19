<?php

namespace Database\Seeders;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Services\FinancialAccountService;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter set of financial accounts for the owner — zero balance,
 * no transactions, just the account shells matching CLAUDE.md's own
 * example list (M-Pesa, M-Shwari, KCB M-Pesa, Bank Savings, Cash) so
 * there's somewhere to record a transaction against without a manual
 * setup step. Unlike a category, an account carries no fabricated
 * financial history — no balance, no journal entries — so it can't be
 * mistaken for real activity. Rename or delete freely; this is just a
 * starting point, not a claim about what accounts you actually hold.
 *
 * Idempotent: skips any account that already exists (by name) for the
 * user, same rule as TransactionCategorySeeder.
 */
class FinancialAccountSeeder extends Seeder
{
    private const ACCOUNTS = [
        ['name' => 'M-Pesa', 'provider' => FinancialAccountProvider::MPESA],
        ['name' => 'M-Shwari', 'provider' => FinancialAccountProvider::MSHWARI],
        ['name' => 'KCB M-Pesa', 'provider' => FinancialAccountProvider::KCB_MPESA],
        ['name' => 'Bank Savings', 'provider' => FinancialAccountProvider::BANK],
        ['name' => 'Cash', 'provider' => FinancialAccountProvider::CASH],
    ];

    public function run(FinancialAccountService $accounts): void
    {
        $user = User::query()->first();

        if ($user === null) {
            $this->command?->warn('No user exists yet — skipping account seeding. Run app:create-owner-account first.');

            return;
        }

        $existing = FinancialAccount::query()->where('user_id', $user->id)->pluck('name')->all();

        foreach (self::ACCOUNTS as $account) {
            if (in_array($account['name'], $existing, true)) {
                continue;
            }

            $accounts->createAccount($user, $account['name'], $account['provider']);
        }
    }
}
