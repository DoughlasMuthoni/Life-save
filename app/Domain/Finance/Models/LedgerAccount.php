<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'type', 'currency', 'is_active'])]
class LedgerAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'is_active' => 'boolean',
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

    public function normalBalanceSide(): LedgerEntrySide
    {
        return $this->type->normalBalanceSide();
    }

    /**
     * The current balance, in minor units, derived entirely from posted
     * ledger entries. This is the only correct way to know an account's
     * balance (CLAUDE.md §6/§9) — there is deliberately no cached `balance`
     * column on this model to accidentally treat as authoritative.
     */
    public function balanceMinor(): int
    {
        $normalSide = $this->normalBalanceSide();

        $sameSideTotal = $this->entries()->where('side', $normalSide->value)->sum('amount_minor');
        $oppositeSideTotal = $this->entries()->where('side', $normalSide->opposite()->value)->sum('amount_minor');

        return (int) $sameSideTotal - (int) $oppositeSideTotal;
    }
}
