<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single debit or credit posting. Fully immutable — once written, a
 * ledger entry is never updated or deleted by application code, full stop
 * (CLAUDE.md §6). Correcting a mistake means posting a new journal via
 * ReversalService, never touching this row.
 */
#[Fillable(['journal_id', 'ledger_account_id', 'transaction_category_id', 'side', 'amount_minor', 'currency'])]
class LedgerEntry extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry) {
            $entry->created_at ??= now();
        });

        static::updating(function () {
            throw new ImmutableLedgerRecordException('Ledger entries cannot be modified after posting.');
        });

        static::deleting(function () {
            throw new ImmutableLedgerRecordException('Ledger entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'side' => LedgerEntrySide::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class);
    }
}
