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
 * Simple two-leg income/expense postings: one leg against a real financial
 * account, one leg against an income/expense category. Transfers between
 * the user's own accounts are deliberately NOT handled here — see
 * TransferService, which guarantees they can never be miscounted as income
 * or expense (CLAUDE.md §6).
 */
class TransactionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function recordIncome(
        User $user,
        FinancialAccount $account,
        TransactionCategory $category,
        int $amountMinor,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
    ): Journal {
        return $this->recordSimpleTransaction(
            JournalType::INCOME,
            TransactionCategoryType::INCOME,
            $user,
            $account,
            $category,
            $amountMinor,
            $occurredAt,
            $description,
        );
    }

    public function recordExpense(
        User $user,
        FinancialAccount $account,
        TransactionCategory $category,
        int $amountMinor,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
    ): Journal {
        return $this->recordSimpleTransaction(
            JournalType::EXPENSE,
            TransactionCategoryType::EXPENSE,
            $user,
            $account,
            $category,
            $amountMinor,
            $occurredAt,
            $description,
        );
    }

    private function recordSimpleTransaction(
        JournalType $journalType,
        TransactionCategoryType $expectedCategoryType,
        User $user,
        FinancialAccount $account,
        TransactionCategory $category,
        int $amountMinor,
        ?CarbonInterface $occurredAt,
        ?string $description,
    ): Journal {
        $this->assertOwnedBy($user, $account, $category);

        if ($category->type !== $expectedCategoryType) {
            throw new InvalidArgumentException(
                "Category [{$category->name}] is a {$category->type->value} category and cannot be used for a {$journalType->value} transaction."
            );
        }

        $assetAccountId = $account->ledger_account_id;
        $categoryAccountId = $category->ledger_account_id;

        // Income: debit the asset account (money in), credit the income account.
        // Expense: debit the expense account, credit the asset account (money out).
        $assetSide = $journalType === JournalType::INCOME ? LedgerEntrySide::DEBIT : LedgerEntrySide::CREDIT;
        $categorySide = $assetSide->opposite();

        $journal = $this->ledger->postJournal(
            user: $user,
            journalType: $journalType,
            entries: [
                [
                    'ledger_account_id' => $assetAccountId,
                    'side' => $assetSide,
                    'amount_minor' => $amountMinor,
                    'currency' => $account->currency,
                ],
                [
                    'ledger_account_id' => $categoryAccountId,
                    'side' => $categorySide,
                    'amount_minor' => $amountMinor,
                    'currency' => $account->currency,
                    'transaction_category_id' => $category->id,
                ],
            ],
            occurredAt: $occurredAt,
            description: $description,
            sourceType: 'manual',
        );

        $this->auditLogger->record(AuditAction::TRANSACTION_POSTED, $journal, [
            'journal_type' => $journalType->value,
            'amount_minor' => $amountMinor,
            'financial_account_id' => $account->id,
            'transaction_category_id' => $category->id,
        ]);

        return $journal;
    }

    private function assertOwnedBy(User $user, FinancialAccount $account, TransactionCategory $category): void
    {
        if ($account->user_id !== $user->id) {
            throw new InvalidArgumentException('That financial account does not belong to this user.');
        }

        if ($category->user_id !== null && $category->user_id !== $user->id) {
            throw new InvalidArgumentException('That category does not belong to this user.');
        }
    }
}
