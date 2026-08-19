<?php

namespace App\Domain\Finance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Finance\Enums\TransactionCategoryType;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Moves money between two of the user's own financial accounts. This is
 * the ONLY correct way to record a transfer — it never posts to an
 * INCOME ledger account, so a transfer can never be miscounted as income,
 * and the transferred principal never touches an EXPENSE account either
 * (only an optional fee does). See CLAUDE.md §6.
 */
class TransferService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function recordTransfer(
        User $user,
        FinancialAccount $from,
        FinancialAccount $to,
        int $amountMinor,
        ?TransactionCategory $feeCategory = null,
        int $feeMinor = 0,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
        string $sourceType = 'manual',
        ?int $sourceId = null,
    ): Journal {
        $this->assertValidTransfer($user, $from, $to, $amountMinor, $feeCategory, $feeMinor);

        $entries = [
            [
                'ledger_account_id' => $from->ledger_account_id,
                'side' => LedgerEntrySide::CREDIT,
                'amount_minor' => $amountMinor + $feeMinor,
                'currency' => $from->currency,
            ],
            [
                'ledger_account_id' => $to->ledger_account_id,
                'side' => LedgerEntrySide::DEBIT,
                'amount_minor' => $amountMinor,
                'currency' => $from->currency,
            ],
        ];

        if ($feeMinor > 0) {
            $entries[] = [
                'ledger_account_id' => $feeCategory->ledger_account_id,
                'side' => LedgerEntrySide::DEBIT,
                'amount_minor' => $feeMinor,
                'currency' => $from->currency,
                'transaction_category_id' => $feeCategory->id,
            ];
        }

        $journal = $this->ledger->postJournal(
            user: $user,
            journalType: JournalType::TRANSFER,
            entries: $entries,
            occurredAt: $occurredAt,
            description: $description,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );

        $this->auditLogger->record(AuditAction::TRANSACTION_POSTED, $journal, [
            'journal_type' => JournalType::TRANSFER->value,
            'amount_minor' => $amountMinor,
            'fee_minor' => $feeMinor,
            'from_financial_account_id' => $from->id,
            'to_financial_account_id' => $to->id,
        ]);

        return $journal;
    }

    private function assertValidTransfer(
        User $user,
        FinancialAccount $from,
        FinancialAccount $to,
        int $amountMinor,
        ?TransactionCategory $feeCategory,
        int $feeMinor,
    ): void {
        if ($from->user_id !== $user->id || $to->user_id !== $user->id) {
            throw new InvalidArgumentException('Both accounts in a transfer must belong to this user.');
        }

        if ($from->is($to)) {
            throw new InvalidArgumentException('Cannot transfer an account to itself.');
        }

        if ($from->currency !== $to->currency) {
            throw new InvalidArgumentException('Cross-currency transfers are not supported yet.');
        }

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Transfer amount must be positive.');
        }

        if ($feeMinor < 0) {
            throw new InvalidArgumentException('Fee amount cannot be negative.');
        }

        if ($feeMinor > 0 && $feeCategory === null) {
            throw new InvalidArgumentException('A fee category is required when a fee is charged.');
        }

        if ($feeCategory !== null && $feeCategory->type !== TransactionCategoryType::EXPENSE) {
            throw new InvalidArgumentException('The fee category must be an expense category.');
        }

        if ($feeCategory !== null && $feeCategory->user_id !== null && $feeCategory->user_id !== $user->id) {
            throw new InvalidArgumentException('That fee category does not belong to this user.');
        }
    }
}
