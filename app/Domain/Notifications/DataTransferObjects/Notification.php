<?php

namespace App\Domain\Notifications\DataTransferObjects;

/**
 * A single bell-dropdown entry. Never persisted, never has a read/unread
 * flag — it's a pure, live derivation from whatever the underlying data
 * looks like right now (same "derive, don't store" principle the ledger
 * balances follow). That also means an item disappears on its own the
 * moment the thing it describes is no longer true — nothing to mark as
 * read, nothing to clean up.
 */
final readonly class Notification
{
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
        public string $color,
        public string $url,
    ) {}
}
