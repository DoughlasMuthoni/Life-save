<?php

namespace Tests\Support;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;

/**
 * Test double bound in place of ClaudeProvider for the entire test suite
 * (see TestCase::setUp()) — automated tests must never make real network
 * calls to the Anthropic API. Defaults to "no opinion" (null) for parsing
 * and a canned string for the assistant, matching how a real provider
 * fails gracefully; individual tests swap in whatever behavior they need.
 *
 * Deliberately does NOT simulate Claude's own tool-picking logic — tests
 * that need to prove a specific tool's data reaches the answer should call
 * that tool's handler directly (see FinancialAssistantServiceTest), not
 * try to fake what the model would have chosen to call.
 */
class FakeAIProvider implements AIProviderInterface
{
    public function __construct(
        private readonly ?AiExtractedTransaction $parseResponse = null,
        private readonly string $answerQuestionResponse = 'This is a fake AI response.',
    ) {}

    public function parseFinancialMessage(string $normalizedText): ?AiExtractedTransaction
    {
        return $this->parseResponse;
    }

    public function answerQuestion(string $question, array $tools): string
    {
        return $this->answerQuestionResponse;
    }
}
