<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\JournalType;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One financial event. Immutable once created except for the narrow
 * reversal-bookkeeping fields — see the `updating` guard below, which is
 * the code-level enforcement of CLAUDE.md §6 ("posted financial entries are
 * immutable"), not just a convention documented in prose.
 */
#[Fillable(['user_id', 'journal_type', 'description', 'occurred_at', 'source_type', 'source_id', 'is_reversed', 'reversed_journal_id'])]
class Journal extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * Fields the ORM is allowed to change after the initial insert.
     * Everything else about a posted journal is frozen for good.
     */
    private const MUTABLE_AFTER_CREATE = ['is_reversed', 'reversed_journal_id'];

    protected static function booted(): void
    {
        static::creating(function (Journal $journal) {
            $journal->created_at ??= now();
        });

        static::updating(function (Journal $journal) {
            $changed = array_keys($journal->getDirty());
            $disallowed = array_diff($changed, self::MUTABLE_AFTER_CREATE);

            if ($disallowed !== []) {
                throw new ImmutableLedgerRecordException(
                    'Journal #'.$journal->id.' is posted and immutable; cannot change: '.implode(', ', $disallowed)
                );
            }
        });

        static::deleting(function (Journal $journal) {
            throw new ImmutableLedgerRecordException('Posted journals cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'journal_type' => JournalType::class,
            'occurred_at' => 'datetime',
            'is_reversed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reversedJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversed_journal_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(Journal::class, 'reversed_journal_id');
    }
}
