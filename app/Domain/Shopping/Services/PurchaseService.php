<?php

namespace App\Domain\Shopping\Services;

use App\Domain\Finance\Models\Journal;
use App\Domain\Shopping\Models\Merchant;
use App\Domain\Shopping\Models\Purchase;
use App\Domain\Shopping\Models\PurchaseItem;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * "What was bought" — kept deliberately separate from TransactionService,
 * which answers "how was it paid" (CLAUDE.md §SHOPPING). Linking a
 * purchase to a journal never changes the journal or its amount; it's a
 * cross-reference, nothing more.
 */
class PurchaseService
{
    public function createPurchase(
        User $user,
        int $totalAmountMinor,
        CarbonInterface $purchasedAt,
        ?Merchant $merchant = null,
        ?Journal $journal = null,
        ?string $notes = null,
    ): Purchase {
        if ($totalAmountMinor <= 0) {
            throw new InvalidArgumentException('Purchase total must be positive.');
        }

        if ($merchant !== null && $merchant->user_id !== $user->id) {
            throw new InvalidArgumentException('That merchant does not belong to this user.');
        }

        if ($journal !== null) {
            $this->assertJournalIsLinkable($user, $journal);
        }

        return Purchase::create([
            'user_id' => $user->id,
            'merchant_id' => $merchant?->id,
            'journal_id' => $journal?->id,
            'total_amount_minor' => $totalAmountMinor,
            'purchased_at' => $purchasedAt,
            'notes' => $notes,
        ]);
    }

    public function addItem(
        Purchase $purchase,
        string $name,
        int $quantity,
        int $unitPriceMinor,
        ?string $category = null,
    ): PurchaseItem {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        if ($unitPriceMinor <= 0) {
            throw new InvalidArgumentException('Unit price must be positive.');
        }

        return PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'category' => $category,
        ]);
    }

    public function linkToJournal(User $user, Purchase $purchase, Journal $journal): Purchase
    {
        if ($purchase->user_id !== $user->id) {
            throw new InvalidArgumentException('That purchase does not belong to this user.');
        }

        $this->assertJournalIsLinkable($user, $journal, excludingPurchase: $purchase);

        $purchase->update(['journal_id' => $journal->id]);

        return $purchase;
    }

    private function assertJournalIsLinkable(User $user, Journal $journal, ?Purchase $excludingPurchase = null): void
    {
        if ($journal->user_id !== $user->id) {
            throw new InvalidArgumentException('That transaction does not belong to this user.');
        }

        $alreadyLinked = Purchase::query()
            ->where('journal_id', $journal->id)
            ->when($excludingPurchase, fn ($query) => $query->where('id', '!=', $excludingPurchase->id))
            ->exists();

        if ($alreadyLinked) {
            throw new InvalidArgumentException('That transaction is already linked to another purchase.');
        }
    }
}
