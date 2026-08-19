<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\ProposedTransactionConfirmationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ConfirmationReconciliationTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_confirming_a_transaction_with_a_reported_balance_creates_a_matching_observation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user, 'Gifts');

        $raw = 'QGH7RC00001 Confirmed. You have received Ksh2,400.00 from MARY WANJIKU 0718765432 on 31/5/25 at 5:07 PM. '.
            'New M-PESA balance is Ksh2,400.00.';

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        app(ProposedTransactionConfirmationService::class)->confirm($user, $message->proposedTransaction, [
            'transaction_category_id' => $salary->id,
        ]);

        $this->assertDatabaseHas('balance_observations', [
            'user_id' => $user->id,
            'financial_account_id' => $mpesa->id,
            'observed_balance_minor' => 240000,
            'calculated_balance_minor' => 240000,
            'reconciliation_status' => ReconciliationStatus::MATCHED->value,
        ]);
    }

    public function test_confirming_a_transaction_with_a_wrong_reported_balance_flags_a_mismatch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user, 'Gifts');

        // A prior, never-recorded transaction means the SMS's claimed
        // balance (5,000) doesn't match what the ledger can account for
        // after just this one income posting (2,400) — exactly the
        // "missing transaction" scenario reconciliation exists to catch.
        $raw = 'QGH7RC00002 Confirmed. You have received Ksh2,400.00 from MARY WANJIKU 0718765432 on 31/5/25 at 5:07 PM. '.
            'New M-PESA balance is Ksh5,000.00.';

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        app(ProposedTransactionConfirmationService::class)->confirm($user, $message->proposedTransaction, [
            'transaction_category_id' => $salary->id,
        ]);

        $this->assertDatabaseHas('balance_observations', [
            'user_id' => $user->id,
            'financial_account_id' => $mpesa->id,
            'observed_balance_minor' => 500000,
            'calculated_balance_minor' => 240000,
            'difference_minor' => 260000,
            'reconciliation_status' => ReconciliationStatus::MISMATCHED->value,
        ]);
    }

    public function test_confirming_a_manual_transaction_with_no_reported_balance_creates_no_observation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000);

        $this->assertDatabaseCount('balance_observations', 0);
    }
}
