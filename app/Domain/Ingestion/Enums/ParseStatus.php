<?php

namespace App\Domain\Ingestion\Enums;

/**
 * The outcome of trying to parse a financial_message. This describes
 * parsing only — the separate review workflow (confirm/reject/duplicate)
 * lives on ProposedTransaction via ProposedTransactionStatus.
 */
enum ParseStatus: string
{
    case PARSED = 'parsed';
    case NEEDS_REVIEW = 'needs_review';
}
