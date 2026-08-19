<?php

namespace App\Domain\Wishlist\Services;

use App\Domain\Finance\Models\Journal;
use App\Domain\Goals\Models\Goal;
use App\Domain\Support\Enums\Priority;
use App\Domain\Wishlist\Enums\WishlistStatus;
use App\Domain\Wishlist\Models\WishlistItem;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class WishlistService
{
    public function createItem(
        User $user,
        string $name,
        int $estimatedPriceMinor,
        Priority $priority = Priority::MEDIUM,
        ?string $category = null,
        ?string $description = null,
        ?CarbonInterface $targetPurchaseDate = null,
        ?Goal $linkedGoal = null,
    ): WishlistItem {
        if ($linkedGoal !== null && $linkedGoal->user_id !== $user->id) {
            throw new InvalidArgumentException('That goal does not belong to this user.');
        }

        return WishlistItem::create([
            'user_id' => $user->id,
            'name' => $name,
            'description' => $description,
            'estimated_price_minor' => $estimatedPriceMinor,
            'category' => $category,
            'priority' => $priority,
            'target_purchase_date' => $targetPurchaseDate,
            'linked_goal_id' => $linkedGoal?->id,
            'status' => WishlistStatus::CONSIDERING,
        ]);
    }

    public function setStatus(WishlistItem $item, WishlistStatus $status): WishlistItem
    {
        if ($status === WishlistStatus::PURCHASED) {
            throw new InvalidArgumentException('Use markPurchased() to move an item to purchased.');
        }

        $item->update(['status' => $status]);

        return $item;
    }

    public function markPurchased(WishlistItem $item, ?Journal $journal = null): WishlistItem
    {
        if ($journal !== null && $journal->user_id !== $item->user_id) {
            throw new InvalidArgumentException('That transaction does not belong to this user.');
        }

        $item->update([
            'status' => WishlistStatus::PURCHASED,
            'purchased_at' => now(),
            'purchased_journal_id' => $journal?->id,
        ]);

        return $item;
    }
}
