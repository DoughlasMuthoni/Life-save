<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;

/**
 * The seam between domain code and whichever AI vendor is behind it. Kept
 * deliberately small — only the capability actually used today
 * (ingestion's deterministic-parser fallback). Add methods here only when
 * a real caller needs them (e.g. categorizeTransaction() / answerQuestion()
 * / generateReport() land with the AI assistant and reporting phases) —
 * CLAUDE.md §8 asks the abstraction not to leak provider specifics into
 * domain code, not to pre-build every capability speculatively.
 */
interface AIProviderInterface
{
    /**
     * Attempt to extract a transaction from a financial SMS a deterministic
     * parser couldn't handle. Returning null means "I don't have an
     * opinion" — the caller must still independently validate whatever
     * comes back before trusting it.
     */
    public function parseFinancialMessage(string $normalizedText): ?AiExtractedTransaction;
}
