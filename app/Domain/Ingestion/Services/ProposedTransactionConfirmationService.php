<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Services\TransactionService;
use App\Domain\Finance\Services\TransferService;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a reviewed ProposedTransaction into a real posted journal. This is
 * the only path from ingestion into the ledger — it always goes back
 * through TransactionService/TransferService, never posts directly, so
 * every invariant those enforce (balanced postings, transfer vs.
 * expense/income, ownership) still applies to SMS-derived transactions
 * exactly as it does to manual ones (CLAUDE.md §6).
 */
class ProposedTransactionConfirmationService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransferService $transfers,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  Fields to correct before posting (e.g. financial_account_id, transaction_category_id, amount_minor) — this is the "Edit" in Confirm/Edit/Reject.
     */
    public function confirm(User $user, ProposedTransaction $proposed, array $overrides = []): Journal
    {
        $this->assertOwnedByAndEditable($user, $proposed);

        return DB::transaction(function () use ($user, $proposed, $overrides) {
            if ($overrides !== []) {
                $proposed->fill($overrides);
                $proposed->save();
            }

            $account = $proposed->financialAccount;

            if ($account === null) {
                throw new InvalidArgumentException('Select which financial account this transaction affects.');
            }

            $journal = match ($proposed->transaction_type->shape()) {
                TransactionShape::INCOME => $this->confirmIncome($user, $proposed, $account),
                TransactionShape::EXPENSE => $this->confirmExpense($user, $proposed, $account),
                TransactionShape::TRANSFER => $this->confirmTransfer($user, $proposed, $account),
            };

            $proposed->status = ProposedTransactionStatus::CONFIRMED;
            $proposed->journal_id = $journal->id;
            $proposed->save();

            return $journal;
        });
    }

    public function reject(User $user, ProposedTransaction $proposed): void
    {
        $this->assertOwnedByAndEditable($user, $proposed);

        $proposed->status = ProposedTransactionStatus::REJECTED;
        $proposed->save();

        $this->auditLogger->record(AuditAction::PROPOSED_TRANSACTION_REJECTED, $proposed, [
            'transaction_type' => $proposed->transaction_type->value,
        ]);
    }

    private function confirmIncome(User $user, ProposedTransaction $proposed, $account): Journal
    {
        $category = $proposed->transactionCategory;

        if ($category === null) {
            throw new InvalidArgumentException('Select an income category.');
        }

        return $this->transactions->recordIncome(
            user: $user,
            account: $account,
            category: $category,
            amountMinor: $proposed->amount_minor,
            occurredAt: $proposed->transaction_time,
            description: $proposed->description ?: $proposed->counterparty,
            sourceType: 'financial_message',
            sourceId: $proposed->financial_message_id,
        );
    }

    private function confirmExpense(User $user, ProposedTransaction $proposed, $account): Journal
    {
        $category = $proposed->transactionCategory;

        if ($category === null) {
            throw new InvalidArgumentException('Select an expense category.');
        }

        return $this->transactions->recordExpense(
            user: $user,
            account: $account,
            category: $category,
            amountMinor: $proposed->amount_minor,
            occurredAt: $proposed->transaction_time,
            description: $proposed->description ?: $proposed->counterparty,
            feeCategory: $proposed->feeCategory,
            feeMinor: $proposed->fee_minor,
            sourceType: 'financial_message',
            sourceId: $proposed->financial_message_id,
        );
    }

    private function confirmTransfer(User $user, ProposedTransaction $proposed, $account): Journal
    {
        $destination = $proposed->destinationFinancialAccount;

        if ($destination === null) {
            throw new InvalidArgumentException('Select the destination account for this transfer (e.g. Cash).');
        }

        return $this->transfers->recordTransfer(
            user: $user,
            from: $account,
            to: $destination,
            amountMinor: $proposed->amount_minor,
            feeCategory: $proposed->feeCategory,
            feeMinor: $proposed->fee_minor,
            occurredAt: $proposed->transaction_time,
            description: $proposed->description ?: $proposed->counterparty,
            sourceType: 'financial_message',
            sourceId: $proposed->financial_message_id,
        );
    }

    private function assertOwnedByAndEditable(User $user, ProposedTransaction $proposed): void
    {
        if ($proposed->user_id !== $user->id) {
            throw new InvalidArgumentException('That proposed transaction does not belong to this user.');
        }

        if ($proposed->status->isFinal()) {
            throw new RuntimeException("Proposed transaction #{$proposed->id} is already {$proposed->status->value}.");
        }
    }
}
