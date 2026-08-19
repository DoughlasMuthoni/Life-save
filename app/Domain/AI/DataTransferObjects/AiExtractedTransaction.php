<?php

namespace App\Domain\AI\DataTransferObjects;

use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;

/**
 * What the AI provider claims it found in a financial SMS. This is a
 * PROPOSAL only — nothing downstream trusts it until
 * AiExtractionValidator independently checks each field against the raw
 * source text (CLAUDE.md §8: schema-valid JSON is not automatically
 * correct).
 */
final readonly class AiExtractedTransaction
{
    /**
     * @param  string[]  $uncertainFields  Field names the model itself flagged as low-confidence.
     */
    public function __construct(
        public bool $isFinancialTransaction,
        public ?ExtractedTransactionType $transactionType,
        public ?int $amountMinor,
        public int $feeMinor,
        public ?CarbonImmutable $transactionTime,
        public ?string $externalTransactionId,
        public ?string $counterparty,
        public ?int $reportedBalanceMinor,
        public float $confidence,
        public array $uncertainFields = [],
    ) {}
}
