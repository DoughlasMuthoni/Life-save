<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Services\FinancialAccountService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_account_defaults_to_asset_type(): void
    {
        $user = User::factory()->create();

        $account = app(FinancialAccountService::class)->createAccount(
            user: $user,
            name: 'M-Pesa',
            provider: FinancialAccountProvider::MPESA,
        );

        $this->assertSame(LedgerAccountType::ASSET, $account->ledgerAccount->type);
    }

    public function test_a_liability_account_can_be_created_explicitly(): void
    {
        $user = User::factory()->create();

        $account = app(FinancialAccountService::class)->createAccount(
            user: $user,
            name: 'Fuliza',
            provider: FinancialAccountProvider::FULIZA,
            type: LedgerAccountType::LIABILITY,
        );

        $this->assertSame(LedgerAccountType::LIABILITY, $account->ledgerAccount->type);
        $this->assertSame(0, $account->balanceMinor());
    }
}
