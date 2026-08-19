<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\AI\DataTransferObjects\AiTool;

/**
 * The seam between domain code and whichever AI vendor is behind it. Kept
 * deliberately small — only the capabilities actually used today
 * (ingestion's deterministic-parser fallback, and the read-only financial
 * assistant). Add methods here only when a real caller needs them
 * (e.g. generateReport() lands when a phase actually needs it) — CLAUDE.md
 * §8 asks the abstraction not to leak provider specifics into domain
 * code, not to pre-build every capability speculatively.
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

    /**
     * Answer a natural-language question using only the given tools — the
     * model has no other access to data. Each tool's handler already
     * computes the real, deterministic answer; the model's job is to pick
     * the right tool(s) and narrate the result, never to calculate one
     * itself (CLAUDE.md §8).
     *
     * @param  AiTool[]  $tools
     */
    public function answerQuestion(string $question, array $tools): string;
}
