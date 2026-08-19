<?php

namespace Tests\Support;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;

/**
 * Test double bound in place of ClaudeProvider for the entire test suite
 * (see TestCase::setUp()) — automated tests must never make real network
 * calls to the Anthropic API. Defaults to "no opinion" (null), matching
 * how a real provider fails gracefully; individual tests that want to
 * exercise the AI fallback path swap in a canned response.
 */
class FakeAIProvider implements AIProviderInterface
{
    public function __construct(private readonly ?AiExtractedTransaction $response = null) {}

    public function parseFinancialMessage(string $normalizedText): ?AiExtractedTransaction
    {
        return $this->response;
    }
}
