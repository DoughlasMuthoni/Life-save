<?php

namespace App\Domain\Goals\Models;

use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Goals\Enums\AllocationEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocate/release event. Immutable once written — same reasoning as
 * ledger entries: a correction is a new opposing event, never an edit
 * (CLAUDE.md §SAVINGS: "create an allocation history rather than merely
 * storing a number").
 */
#[Fillable(['user_id', 'goal_id', 'financial_account_id', 'event_type', 'amount_minor', 'note'])]
class GoalAllocationEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (GoalAllocationEvent $event) {
            $event->created_at ??= now();
        });

        static::updating(function () {
            throw new ImmutableLedgerRecordException('Goal allocation events cannot be modified after creation.');
        });

        static::deleting(function () {
            throw new ImmutableLedgerRecordException('Goal allocation events cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'event_type' => AllocationEventType::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
