<?php

namespace Database\Seeders;

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Services\TransactionCategoryService;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter set of income/expense categories for the owner account.
 * Reference data only (CLAUDE.md: seeders never seed user credentials or
 * fabricated financial history) — no accounts, transactions, or balances
 * are created here, just the category labels a new user would otherwise
 * have to type in one at a time before they can record anything.
 *
 * Idempotent: skips any category that already exists (by name + type) for
 * the user, so running this again after the user has customized their
 * categories won't create duplicates.
 */
class TransactionCategorySeeder extends Seeder
{
    private const INCOME_CATEGORIES = [
        'Salary',
        'Business Income',
        'Gifts',
        'Other Income',
    ];

    private const EXPENSE_CATEGORIES = [
        'Groceries',
        'Transport',
        'Eating Out',
        'Shopping',
        'Bills & Utilities',
        'Rent',
        'Airtime & Data',
        'Health',
        'Education',
        'Entertainment',
        'Transaction Fees',
        'Other Expense',
    ];

    public function run(TransactionCategoryService $categories): void
    {
        $user = User::query()->first();

        if ($user === null) {
            $this->command?->warn('No user exists yet — skipping category seeding. Run app:create-owner-account first.');

            return;
        }

        $this->seed($categories, $user, TransactionCategoryType::INCOME, self::INCOME_CATEGORIES);
        $this->seed($categories, $user, TransactionCategoryType::EXPENSE, self::EXPENSE_CATEGORIES);
    }

    /**
     * @param  string[]  $names
     */
    private function seed(TransactionCategoryService $categories, User $user, TransactionCategoryType $type, array $names): void
    {
        $existing = TransactionCategory::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->pluck('name')
            ->all();

        foreach ($names as $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $categories->createCategory($user, $name, $type);
        }
    }
}
