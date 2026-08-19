<?php

namespace App\Domain\Support\Enums;

/**
 * Shared low/medium/high priority, used by both Goal and WishlistItem —
 * a genuinely identical concept in both places, so it lives once here
 * rather than being duplicated per domain.
 */
enum Priority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}
