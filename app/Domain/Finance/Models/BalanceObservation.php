<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\ReconciliationStatus;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot comparison of what an SMS claimed a balance was against what
 * the ledger independently calculates. The comparison fields are
 * immutable once written; only the resolution fields may change, when the
 * user reviews a mismatch (CLAUDE.md §"Balance Reconciliation").
 */
#[Fillable([
    'user_id', 'financial_account_id', 'financial_message_id', 'observed_balance_minor',
    'calculated_balance_minor', 'difference_minor', 'observed_at', 'reconciliation_status',
    'resolved_at', 'resolution_note',
])]
class BalanceObservation extends Model
{
    public $timestamps = false;

    private const MUTABLE_AFTER_CREATE = ['reconciliation_status', 'resolved_at', 'resolution_note'];

    protected static function booted(): void
    {
        static::creating(function (BalanceObservation $observation) {
            $observation->created_at ??= now();
        });

        static::updating(function (BalanceObservation $observation) {
            $changed = array_keys($observation->getDirty());
            $disallowed = array_diff($changed, self::MUTABLE_AFTER_CREATE);

            if ($disallowed !== []) {
                throw new ImmutableLedgerRecordException(
                    'Balance observation #'.$observation->id.' is immutable except for its resolution; cannot change: '.implode(', ', $disallowed)
                );
            }
        });

        static::deleting(function () {
            throw new ImmutableLedgerRecordException('Balance observations cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'reconciliation_status' => ReconciliationStatus::class,
            'observed_balance_minor' => 'integer',
            'calculated_balance_minor' => 'integer',
            'difference_minor' => 'integer',
            'observed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function financialMessage(): BelongsTo
    {
        return $this->belongsTo(FinancialMessage::class);
    }
}
