<?php

namespace App\Domain\Achievements\DataTransferObjects;

/**
 * A read-only, computed badge — never persisted, never manually awarded.
 * Whether it's unlocked is derived fresh from existing data every time
 * (habit streaks, completed tasks/goals, purchased wishlist items), so
 * there's nothing here to keep in sync and nothing an AI or a bug could
 * "grant" that the underlying data doesn't actually support.
 */
final readonly class Achievement
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $icon,
        public bool $unlocked,
        public int $currentValue,
        public int $targetValue,
    ) {}

    public function progressPercent(): int
    {
        if ($this->targetValue <= 0) {
            return 0;
        }

        return (int) round(min($this->currentValue / $this->targetValue, 1) * 100);
    }
}
