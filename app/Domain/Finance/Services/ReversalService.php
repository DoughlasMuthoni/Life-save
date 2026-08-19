<?php

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only sanctioned way to correct a posted journal: post a new REVERSAL
 * journal whose entries mirror the original with every side flipped, then
 * flag the original as reversed. The original's entries are never touched
 * (CLAUDE.md §6 — reversal, not silent overwrite).
 */
class ReversalService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reverseJournal(User $user, Journal $journal, string $reason): Journal
    {
        if ($journal->user_id !== $user->id) {
            throw new InvalidArgumentException('That journal does not belong to this user.');
        }

        if ($journal->is_reversed) {
            throw new RuntimeException("Journal #{$journal->id} has already been reversed.");
        }

        $journal->loadMissing('entries');

        if ($journal->entries->isEmpty()) {
            throw new RuntimeException("Journal #{$journal->id} has no entries to reverse.");
        }

        return DB::transaction(function () use ($user, $journal, $reason) {
            $reversalEntries = $journal->entries->map(fn ($entry) => [
                'ledger_account_id' => $entry->ledger_account_id,
                'side' => $entry->side->opposite(),
                'amount_minor' => $entry->amount_minor,
                'currency' => $entry->currency,
                'transaction_category_id' => $entry->transaction_category_id,
            ])->all();

            $reversalJournal = $this->ledger->postJournal(
                user: $user,
                journalType: JournalType::REVERSAL,
                entries: $reversalEntries,
                description: $reason,
                sourceType: 'reversal_of',
                sourceId: $journal->id,
            );

            $journal->update([
                'is_reversed' => true,
                'reversed_journal_id' => $reversalJournal->id,
            ]);

            $this->auditLogger->record(AuditAction::TRANSACTION_REVERSED, $journal, [
                'reversal_journal_id' => $reversalJournal->id,
                'reason' => $reason,
            ]);

            return $reversalJournal;
        });
    }
}
