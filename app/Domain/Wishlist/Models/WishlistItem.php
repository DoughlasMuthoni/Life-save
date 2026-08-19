<?php

namespace App\Domain\Wishlist\Models;

use App\Domain\Finance\Models\Journal;
use App\Domain\Goals\Models\Goal;
use App\Domain\Support\Enums\Priority;
use App\Domain\Wishlist\Enums\WishlistStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'name', 'description', 'estimated_price_minor', 'category', 'priority',
    'target_purchase_date', 'linked_goal_id', 'status', 'purchased_at', 'purchased_journal_id',
])]
class WishlistItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => WishlistStatus::class,
            'estimated_price_minor' => 'integer',
            'target_purchase_date' => 'date',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedGoal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'linked_goal_id');
    }

    public function purchasedJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'purchased_journal_id');
    }

    /**
     * Always derived from the linked goal's own allocation total — never
     * stored directly, so it can't drift from the goal it's supposed to
     * reflect. Zero when there's no linked goal.
     */
    public function amountAllocatedMinor(): int
    {
        return $this->linkedGoal?->allocatedAmountMinor() ?? 0;
    }

    public function remainingAmountMinor(): int
    {
        return max(0, $this->estimated_price_minor - $this->amountAllocatedMinor());
    }
}
