<?php

namespace App\Domain\Ingestion\Models;

use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A raw pasted SMS, preserved exactly as evidence. Fully immutable once
 * created — same reasoning as the ledger tables (CLAUDE.md §7: "the
 * original raw_text must be preserved exactly").
 */
#[Fillable(['user_id', 'raw_text', 'normalized_text', 'message_hash', 'provider', 'parser_type', 'parser_version', 'parse_status', 'confidence', 'external_transaction_id'])]
class FinancialMessage extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (FinancialMessage $message) {
            $message->created_at ??= now();
        });

        static::updating(function () {
            throw new ImmutableLedgerRecordException('Financial messages are immutable evidence and cannot be modified.');
        });

        static::deleting(function () {
            throw new ImmutableLedgerRecordException('Financial messages cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'provider' => MessageProvider::class,
            'parse_status' => ParseStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proposedTransaction(): HasOne
    {
        return $this->hasOne(ProposedTransaction::class);
    }
}
