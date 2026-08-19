<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Services\ReversalService;
use App\Domain\Finance\Services\TransactionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ReversalServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private ReversalService $reversals;

    private TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reversals = app(ReversalService::class);
        $this->transactions = app(TransactionService::class);
    }

    public function test_reversing_a_journal_nets_the_account_balance_back_to_zero(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $original = $this->transactions->recordIncome($user, $mpesa, $salary, 500000);
        $this->assertSame(500000, $mpesa->balanceMinor());

        $reversal = $this->reversals->reverseJournal($user, $original, 'Entered twice by mistake');

        $this->assertSame(JournalType::REVERSAL, $reversal->journal_type);
        $this->assertSame(0, $mpesa->balanceMinor());
    }

    public function test_the_original_journal_is_flagged_as_reversed_but_not_deleted_or_edited(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $original = $this->transactions->recordIncome($user, $mpesa, $salary, 500000);
        $this->reversals->reverseJournal($user, $original, 'oops');

        $original->refresh();

        $this->assertTrue($original->is_reversed);
        $this->assertNotNull($original->reversed_journal_id);
        $this->assertDatabaseHas('journals', ['id' => $original->id]);
        $this->assertDatabaseHas('ledger_entries', ['journal_id' => $original->id]);
    }

    public function test_a_journal_cannot_be_reversed_twice(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $salary = $this->createIncomeCategory($user);

        $original = $this->transactions->recordIncome($user, $mpesa, $salary, 500000);
        $this->reversals->reverseJournal($user, $original, 'first reversal');

        $this->expectException(RuntimeException::class);

        $this->reversals->reverseJournal($user, $original->fresh(), 'second reversal attempt');
    }
}
