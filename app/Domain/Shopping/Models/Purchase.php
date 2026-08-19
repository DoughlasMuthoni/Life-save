<?php

namespace App\Domain\Shopping\Models;

use App\Domain\Finance\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'merchant_id', 'journal_id', 'total_amount_minor', 'purchased_at', 'notes'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return [
            'total_amount_minor' => 'integer',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function itemsTotalMinor(): int
    {
        return $this->items->sum(fn (PurchaseItem $item) => $item->lineTotalMinor());
    }

    /**
     * Whether the itemized lines add up to the total actually paid. Purely
     * informational — a purchase with no items, or with a total that
     * legitimately differs (tip, delivery fee), is not an error.
     */
    public function itemsReconcileWithTotal(): bool
    {
        if ($this->items->isEmpty()) {
            return true;
        }

        return $this->itemsTotalMinor() === $this->total_amount_minor;
    }
}
