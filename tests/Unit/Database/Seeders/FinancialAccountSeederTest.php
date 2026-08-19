<?php

namespace Tests\Unit\Database\Seeders;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Models\User;
use Database\Seeders\FinancialAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_five_zero_balance_accounts_for_the_owner(): void
    {
        $user = User::factory()->create();

        $this->seed(FinancialAccountSeeder::class);

        $this->assertSame(5, FinancialAccount::where('user_id', $user->id)->count());

        $mpesa = FinancialAccount::where('user_id', $user->id)->where('name', 'M-Pesa')->first();
        $this->assertNotNull($mpesa);
        $this->assertSame(FinancialAccountProvider::MPESA, $mpesa->provider);
        $this->assertSame(0, $mpesa->balanceMinor());
    }

    public function test_it_does_not_create_duplicates_when_run_twice(): void
    {
        $user = User::factory()->create();

        $this->seed(FinancialAccountSeeder::class);
        $this->seed(FinancialAccountSeeder::class);

        $this->assertSame(5, FinancialAccount::where('user_id', $user->id)->count());
    }

    public function test_it_does_nothing_when_no_user_exists_yet(): void
    {
        $this->seed(FinancialAccountSeeder::class);

        $this->assertDatabaseCount('financial_accounts', 0);
    }
}
