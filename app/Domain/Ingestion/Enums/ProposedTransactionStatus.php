<?php

namespace App\Domain\Ingestion\Enums;

enum ProposedTransactionStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case DUPLICATE = 'duplicate';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';

    public function isFinal(): bool
    {
        return $this === self::CONFIRMED || $this === self::REJECTED;
    }
}
