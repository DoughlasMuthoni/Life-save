<?php

namespace Tests\Unit\Database\Seeders;

use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Models\User;
use Database\Seeders\TransactionCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_income_and_expense_categories_for_the_owner(): void
    {
        $user = User::factory()->create();

        $this->seed(TransactionCategorySeeder::class);

        $this->assertTrue(
            TransactionCategory::where('user_id', $user->id)->where('type', TransactionCategoryType::INCOME)->where('name', 'Salary')->exists()
        );
        $this->assertTrue(
            TransactionCategory::where('user_id', $user->id)->where('type', TransactionCategoryType::EXPENSE)->where('name', 'Groceries')->exists()
        );
    }

    public function test_it_does_not_create_duplicates_when_run_twice(): void
    {
        $user = User::factory()->create();

        $this->seed(TransactionCategorySeeder::class);
        $countAfterFirstRun = TransactionCategory::where('user_id', $user->id)->count();

        $this->seed(TransactionCategorySeeder::class);
        $countAfterSecondRun = TransactionCategory::where('user_id', $user->id)->count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
    }

    public function test_renaming_a_seeded_category_leaves_it_alone_on_reseed(): void
    {
        // Idempotency here is by name, not by "was this originally
        // seeded" — a simple, defensible rule for a one-shot reference
        // seeder. Renaming a category means the next reseed no longer
        // recognizes it as already present and seeds a fresh one under
        // the original name; it does NOT touch or duplicate the renamed
        // category itself.
        $user = User::factory()->create();

        $this->seed(TransactionCategorySeeder::class);

        $groceries = TransactionCategory::where('user_id', $user->id)->where('name', 'Groceries')->first();
        $groceries->update(['name' => 'Weekly Shop']);

        $this->seed(TransactionCategorySeeder::class);

        $this->assertDatabaseHas('transaction_categories', ['id' => $groceries->id, 'name' => 'Weekly Shop']);
        $this->assertSame(
            1,
            TransactionCategory::where('user_id', $user->id)->where('name', 'Weekly Shop')->count()
        );
    }

    public function test_it_does_nothing_when_no_user_exists_yet(): void
    {
        $this->seed(TransactionCategorySeeder::class);

        $this->assertDatabaseCount('transaction_categories', 0);
    }
}
