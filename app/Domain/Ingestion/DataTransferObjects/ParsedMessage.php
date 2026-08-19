<?php

namespace App\Domain\Ingestion\DataTransferObjects;

use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;

/**
 * What a deterministic parser extracted from one SMS. Purely a data
 * carrier — parsers build this, FinancialMessageIngestionService turns it
 * into a ProposedTransaction. Never trusted as-is: every field still goes
 * through the ingestion service's own sanity checks before anything is
 * written (CLAUDE.md §7 — validate against source, don't just trust
 * schema-valid output).
 */
final readonly class ParsedMessage
{
    public function __construct(
        public ExtractedTransactionType $transactionType,
        public int $amountMinor,
        public int $feeMinor,
        public CarbonImmutable $transactionTime,
        public ?string $externalTransactionId = null,
        public ?string $counterparty = null,
        public ?int $reportedBalanceMinor = null,
    ) {}
}
