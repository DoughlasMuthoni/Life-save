<?php

namespace App\Domain\Ingestion\Models;

use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Finance\Models\Journal;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parser's proposal, awaiting human confirmation. Editable while still
 * PENDING_REVIEW or DUPLICATE (the user can correct the account/category/
 * amount before confirming); frozen the moment it reaches a final state
 * (CONFIRMED or REJECTED) — enforced below, not just documented.
 */
#[Fillable([
    'financial_message_id', 'user_id', 'transaction_type', 'financial_account_id',
    'destination_financial_account_id', 'transaction_category_id', 'fee_category_id',
    'amount_minor', 'fee_minor', 'currency', 'counterparty', 'transaction_time',
    'reported_balance_minor', 'description', 'status', 'duplicate_of_message_id', 'journal_id',
])]
class ProposedTransaction extends Model
{
    protected static function booted(): void
    {
        static::updating(function (ProposedTransaction $proposed) {
            $originalStatus = $proposed->getOriginal('status');
            $originalStatus = $originalStatus instanceof ProposedTransactionStatus
                ? $originalStatus
                : ProposedTransactionStatus::from($originalStatus);

            if ($originalStatus->isFinal()) {
                throw new ImmutableLedgerRecordException(
                    "Proposed transaction #{$proposed->id} is already {$originalStatus->value} and cannot be changed."
                );
            }
        });

        static::deleting(function () {
            throw new ImmutableLedgerRecordException('Proposed transactions are never deleted, only rejected.');
        });
    }

    protected function casts(): array
    {
        return [
            'transaction_type' => ExtractedTransactionType::class,
            'status' => ProposedTransactionStatus::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'reported_balance_minor' => 'integer',
            'transaction_time' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function financialMessage(): BelongsTo
    {
        return $this->belongsTo(FinancialMessage::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function destinationFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_financial_account_id');
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'fee_category_id');
    }

    public function duplicateOfMessage(): BelongsTo
    {
        return $this->belongsTo(FinancialMessage::class, 'duplicate_of_message_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
