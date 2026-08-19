<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerEntrySide;
use App\Models\User;
use Carbon\CarbonInterface;
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

    /**
     * The balance as of a point in time, considering only journals whose
     * occurred_at is on or before $cutoff. Used for reconciliation: an SMS
     * reports the balance at the moment of that specific transaction, which
     * is only the same as balanceMinor() if nothing else has been posted
     * since — this lets reconciliation compare like with like even when
     * messages are confirmed out of chronological order.
     */
    public function balanceMinorAsOf(CarbonInterface $cutoff): int
    {
        $normalSide = $this->normalBalanceSide();

        $upToCutoff = fn () => $this->entries()->whereHas('journal', fn ($query) => $query->where('occurred_at', '<=', $cutoff));

        $sameSideTotal = $upToCutoff()->where('side', $normalSide->value)->sum('amount_minor');
        $oppositeSideTotal = $upToCutoff()->where('side', $normalSide->opposite()->value)->sum('amount_minor');

        return (int) $sameSideTotal - (int) $oppositeSideTotal;
    }
}
