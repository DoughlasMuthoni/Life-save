<?php

namespace App\Domain\Wishlist\Enums;

enum WishlistStatus: string
{
    case CONSIDERING = 'considering';
    case SAVING = 'saving';
    case READY = 'ready';
    case PURCHASED = 'purchased';
    case CANCELLED = 'cancelled';
}
