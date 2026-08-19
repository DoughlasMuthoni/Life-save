<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Finance\Exceptions\UnbalancedJournalException;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place a Journal + its LedgerEntry postings get written. Every
 * other finance service (TransactionService, TransferService,
 * ReversalService) builds a set of entries and calls postJournal() rather
 * than writing to `journals`/`ledger_entries` directly — this is the
 * chokepoint that guarantees every posted journal is balanced
 * (CLAUDE.md §6).
 */
class LedgerService
{
    /**
     * @param  array<int, array{ledger_account_id: int, side: LedgerEntrySide, amount_minor: int, currency?: string, transaction_category_id?: int|null}>  $entries
     */
    public function postJournal(
        User $user,
        JournalType $journalType,
        array $entries,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): Journal {
        $entries = $this->normalizeEntries($entries);

        $this->assertBalanced($entries);

        return DB::transaction(function () use ($user, $journalType, $entries, $occurredAt, $description, $sourceType, $sourceId) {
            $journal = Journal::create([
                'user_id' => $user->id,
                'journal_type' => $journalType,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            foreach ($entries as $entry) {
                LedgerEntry::create([
                    'journal_id' => $journal->id,
                    'ledger_account_id' => $entry['ledger_account_id'],
                    'transaction_category_id' => $entry['transaction_category_id'] ?? null,
                    'side' => $entry['side'],
                    'amount_minor' => $entry['amount_minor'],
                    'currency' => $entry['currency'],
                ]);
            }

            return $journal->load('entries');
        });
    }

    /**
     * @param  array<int, array{ledger_account_id: int, side: LedgerEntrySide, amount_minor: int, currency?: string, transaction_category_id?: int|null}>  $entries
     * @return array<int, array{ledger_account_id: int, side: LedgerEntrySide, amount_minor: int, currency: string, transaction_category_id: int|null}>
     */
    private function normalizeEntries(array $entries): array
    {
        if (count($entries) < 2) {
            throw new InvalidArgumentException('A journal requires at least two ledger entries.');
        }

        return array_map(function (array $entry) {
            if (! isset($entry['amount_minor']) || $entry['amount_minor'] <= 0) {
                throw new InvalidArgumentException('Every ledger entry amount must be a positive integer of minor units.');
            }

            return [
                'ledger_account_id' => $entry['ledger_account_id'],
                'side' => $entry['side'],
                'amount_minor' => (int) $entry['amount_minor'],
                'currency' => $entry['currency'] ?? 'KES',
                'transaction_category_id' => $entry['transaction_category_id'] ?? null,
            ];
        }, $entries);
    }

    /**
     * Debits must equal credits, independently, for every currency present
     * in the journal. Throws before anything is written to the database.
     *
     * @param  array<int, array{side: LedgerEntrySide, amount_minor: int, currency: string}>  $entries
     */
    private function assertBalanced(array $entries): void
    {
        $totalsByCurrency = [];

        foreach ($entries as $entry) {
            $currency = $entry['currency'];
            $totalsByCurrency[$currency] ??= [LedgerEntrySide::DEBIT->value => 0, LedgerEntrySide::CREDIT->value => 0];
            $totalsByCurrency[$currency][$entry['side']->value] += $entry['amount_minor'];
        }

        foreach ($totalsByCurrency as $currency => $totals) {
            if ($totals[LedgerEntrySide::DEBIT->value] !== $totals[LedgerEntrySide::CREDIT->value]) {
                throw new UnbalancedJournalException(
                    "Journal does not balance for {$currency}: debits={$totals[LedgerEntrySide::DEBIT->value]}, credits={$totals[LedgerEntrySide::CREDIT->value]}."
                );
            }
        }
    }
}
