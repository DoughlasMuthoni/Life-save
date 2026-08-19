<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Finance\Exceptions\UnbalancedJournalException;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Services\LedgerService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    public function test_a_balanced_journal_posts_successfully(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $journal = $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 500000],
        ]);

        $this->assertDatabaseHas('journals', ['id' => $journal->id, 'journal_type' => JournalType::INCOME->value]);
        $this->assertCount(2, $journal->entries);
        $this->assertSame(500000, $asset->fresh()->balanceMinor());
    }

    public function test_an_unbalanced_journal_is_rejected_and_nothing_is_written(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $this->expectException(UnbalancedJournalException::class);

        try {
            $this->ledger->postJournal($user, JournalType::INCOME, [
                ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
                ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 400000],
            ]);
        } finally {
            $this->assertDatabaseCount('journals', 0);
            $this->assertDatabaseCount('ledger_entries', 0);
        }
    }

    public function test_a_journal_needs_at_least_two_entries(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);

        $this->expectException(InvalidArgumentException::class);

        $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
        ]);
    }

    public function test_entry_amounts_must_be_positive(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $this->expectException(InvalidArgumentException::class);

        $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 0],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 0],
        ]);
    }

    public function test_posted_journals_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $journal = $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 500000],
        ]);

        $this->expectException(ImmutableLedgerRecordException::class);

        $journal->description = 'sneaky edit';
        $journal->save();
    }

    public function test_posted_journals_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $journal = $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 500000],
        ]);

        $this->expectException(ImmutableLedgerRecordException::class);

        $journal->delete();
    }

    public function test_ledger_entries_cannot_be_edited_or_deleted(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);

        $journal = $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 500000],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 500000],
        ]);

        $entry = $journal->entries->first();

        try {
            $entry->amount_minor = 1;
            $entry->save();
            $this->fail('Expected ImmutableLedgerRecordException on update.');
        } catch (ImmutableLedgerRecordException) {
            // expected
        }

        $this->expectException(ImmutableLedgerRecordException::class);
        $entry->delete();
    }

    public function test_account_balance_is_derived_from_postings_not_a_stored_column(): void
    {
        $user = User::factory()->create();
        $asset = LedgerAccount::create(['user_id' => $user->id, 'name' => 'M-Pesa', 'type' => LedgerAccountType::ASSET]);
        $income = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Salary', 'type' => LedgerAccountType::INCOME]);
        $expense = LedgerAccount::create(['user_id' => $user->id, 'name' => 'Groceries', 'type' => LedgerAccountType::EXPENSE]);

        $this->ledger->postJournal($user, JournalType::INCOME, [
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 1000000],
            ['ledger_account_id' => $income->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 1000000],
        ]);

        $this->ledger->postJournal($user, JournalType::EXPENSE, [
            ['ledger_account_id' => $expense->id, 'side' => LedgerEntrySide::DEBIT, 'amount_minor' => 235000],
            ['ledger_account_id' => $asset->id, 'side' => LedgerEntrySide::CREDIT, 'amount_minor' => 235000],
        ]);

        $this->assertFalse($this->columnExists('ledger_accounts', 'balance'));
        $this->assertSame(765000, $asset->fresh()->balanceMinor());
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}
