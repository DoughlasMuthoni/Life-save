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
 * Simple income/expense postings: one leg against a real financial
 * account, one leg against an income/expense category, and — for expenses
 * only — an optional third leg for a transaction fee (e.g. a paybill's
 * "Transaction cost"). Transfers between the user's own accounts are
 * deliberately NOT handled here — see TransferService, which guarantees
 * they can never be miscounted as income or expense (CLAUDE.md §6).
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
        string $sourceType = 'manual',
        ?int $sourceId = null,
    ): Journal {
        $this->assertOwnedBy($user, $account, $category);
        $this->assertCategoryType($category, TransactionCategoryType::INCOME, JournalType::INCOME);

        $journal = $this->ledger->postJournal(
            user: $user,
            journalType: JournalType::INCOME,
            entries: [
                [
                    'ledger_account_id' => $account->ledger_account_id,
                    'side' => LedgerEntrySide::DEBIT,
                    'amount_minor' => $amountMinor,
                    'currency' => $account->currency,
                ],
                [
                    'ledger_account_id' => $category->ledger_account_id,
                    'side' => LedgerEntrySide::CREDIT,
                    'amount_minor' => $amountMinor,
                    'currency' => $account->currency,
                    'transaction_category_id' => $category->id,
                ],
            ],
            occurredAt: $occurredAt,
            description: $description,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );

        $this->auditLogger->record(AuditAction::TRANSACTION_POSTED, $journal, [
            'journal_type' => JournalType::INCOME->value,
            'amount_minor' => $amountMinor,
            'financial_account_id' => $account->id,
            'transaction_category_id' => $category->id,
        ]);

        return $journal;
    }

    public function recordExpense(
        User $user,
        FinancialAccount $account,
        TransactionCategory $category,
        int $amountMinor,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
        ?TransactionCategory $feeCategory = null,
        int $feeMinor = 0,
        string $sourceType = 'manual',
        ?int $sourceId = null,
    ): Journal {
        $this->assertOwnedBy($user, $account, $category);
        $this->assertCategoryType($category, TransactionCategoryType::EXPENSE, JournalType::EXPENSE);

        if ($feeMinor < 0) {
            throw new InvalidArgumentException('Fee amount cannot be negative.');
        }

        if ($feeMinor > 0 && $feeCategory === null) {
            throw new InvalidArgumentException('A fee category is required when a fee is charged.');
        }

        if ($feeCategory !== null) {
            $this->assertCategoryType($feeCategory, TransactionCategoryType::EXPENSE, JournalType::EXPENSE, 'Fee category');
            if ($feeCategory->user_id !== null && $feeCategory->user_id !== $user->id) {
                throw new InvalidArgumentException('That fee category does not belong to this user.');
            }
        }

        $entries = [
            [
                'ledger_account_id' => $category->ledger_account_id,
                'side' => LedgerEntrySide::DEBIT,
                'amount_minor' => $amountMinor,
                'currency' => $account->currency,
                'transaction_category_id' => $category->id,
            ],
            [
                'ledger_account_id' => $account->ledger_account_id,
                'side' => LedgerEntrySide::CREDIT,
                'amount_minor' => $amountMinor + $feeMinor,
                'currency' => $account->currency,
            ],
        ];

        if ($feeMinor > 0) {
            $entries[] = [
                'ledger_account_id' => $feeCategory->ledger_account_id,
                'side' => LedgerEntrySide::DEBIT,
                'amount_minor' => $feeMinor,
                'currency' => $account->currency,
                'transaction_category_id' => $feeCategory->id,
            ];
        }

        $journal = $this->ledger->postJournal(
            user: $user,
            journalType: JournalType::EXPENSE,
            entries: $entries,
            occurredAt: $occurredAt,
            description: $description,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );

        $this->auditLogger->record(AuditAction::TRANSACTION_POSTED, $journal, [
            'journal_type' => JournalType::EXPENSE->value,
            'amount_minor' => $amountMinor,
            'fee_minor' => $feeMinor,
            'financial_account_id' => $account->id,
            'transaction_category_id' => $category->id,
        ]);

        return $journal;
    }

    private function assertCategoryType(
        TransactionCategory $category,
        TransactionCategoryType $expected,
        JournalType $journalType,
        string $label = 'Category',
    ): void {
        if ($category->type !== $expected) {
            throw new InvalidArgumentException(
                "{$label} [{$category->name}] is a {$category->type->value} category and cannot be used for a {$journalType->value} transaction."
            );
        }
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
