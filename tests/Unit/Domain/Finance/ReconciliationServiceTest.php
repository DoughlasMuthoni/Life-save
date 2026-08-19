<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Finance\Services\ReconciliationService;
use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private ReconciliationService $reconciliation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciliation = app(ReconciliationService::class);
    }

    public function test_a_matching_observation_is_flagged_matched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000, Carbon::parse('2025-05-01'));

        $observation = $this->reconciliation->recordObservation($user, $mpesa, 500000, Carbon::parse('2025-05-01'));

        $this->assertSame(ReconciliationStatus::MATCHED, $observation->reconciliation_status);
        $this->assertSame(0, $observation->difference_minor);
    }

    public function test_a_mismatched_observation_is_flagged_and_audited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        app(TransactionService::class)->recordIncome($user, $mpesa, $salary, 500000, Carbon::parse('2025-05-01'));

        // SMS claims a higher balance than the ledger can account for —
        // e.g. a missing transaction.
        $observation = $this->reconciliation->recordObservation($user, $mpesa, 800000, Carbon::parse('2025-05-01'));

        $this->assertSame(ReconciliationStatus::MISMATCHED, $observation->reconciliation_status);
        $this->assertSame(300000, $observation->difference_minor);

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => 'reconciliation.mismatch_detected',
        ]);
    }

    public function test_resolving_a_mismatch_records_a_note_and_timestamp(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);

        $observation = $this->reconciliation->recordObservation($user, $mpesa, 100000, Carbon::parse('2025-05-01'));
        $this->assertSame(ReconciliationStatus::MISMATCHED, $observation->reconciliation_status);

        $resolved = $this->reconciliation->resolve($user, $observation, 'Found the missing expense, added it manually.');

        $this->assertSame(ReconciliationStatus::RESOLVED, $resolved->reconciliation_status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame('Found the missing expense, added it manually.', $resolved->resolution_note);
    }

    public function test_a_matched_observation_cannot_be_resolved(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);

        $observation = $this->reconciliation->recordObservation($user, $mpesa, 0, Carbon::parse('2025-05-01'));
        $this->assertSame(ReconciliationStatus::MATCHED, $observation->reconciliation_status);

        $this->expectException(RuntimeException::class);

        $this->reconciliation->resolve($user, $observation, 'n/a');
    }

    public function test_the_snapshot_fields_are_immutable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $mpesa = $this->createFinancialAccount($user);

        $observation = $this->reconciliation->recordObservation($user, $mpesa, 100000, Carbon::parse('2025-05-01'));

        $this->expectException(ImmutableLedgerRecordException::class);

        $observation->observed_balance_minor = 1;
        $observation->save();
    }

    public function test_a_user_cannot_record_an_observation_for_another_users_account(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $mpesa = $this->createFinancialAccount($owner);

        $this->expectException(InvalidArgumentException::class);

        $this->reconciliation->recordObservation($intruder, $mpesa, 100000, Carbon::parse('2025-05-01'));
    }
}
