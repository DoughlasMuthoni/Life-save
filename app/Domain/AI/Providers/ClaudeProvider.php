<?php

namespace App\Domain\AI\Providers;

use Anthropic\Client;
use Anthropic\Lib\Tools\BetaRunnableTool;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\AI\DataTransferObjects\AiTool;
use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Claude-backed implementation of AIProviderInterface. Uses a strict JSON
 * schema (output_config.format) rather than free-text parsing — the model
 * cannot return anything except the declared shape. That still doesn't
 * make its content trustworthy (CLAUDE.md §8): amounts come back as
 * decimal strings and are converted via the same Money::toMinorUnits()
 * every other amount in this app goes through, not the model's own
 * arithmetic, and the caller (FinancialMessageIngestionService) is
 * responsible for validating every field against the source text before
 * treating this as anything more than a proposal.
 */
class ClaudeProvider implements AIProviderInterface
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'is_financial_transaction' => ['type' => 'boolean'],
            // NOTE: 'enum' combined with a nullable `type: [string, null]`
            // is rejected by the API ("Enum value ... does not match
            // declared type") even though it's valid JSON Schema — so
            // "not applicable" is its own enum member instead of null.
            'transaction_type' => [
                'type' => 'string',
                'enum' => ['SEND_MONEY', 'RECEIVE_MONEY', 'BUY_GOODS', 'PAYBILL', 'WITHDRAWAL', 'BANK_DEBIT', 'BANK_CREDIT', 'NONE'],
            ],
            'transaction_id' => ['type' => ['string', 'null']],
            'amount' => ['type' => ['string', 'null'], 'description' => 'Decimal amount, e.g. "1500.00". No currency symbol, no thousands separators.'],
            'fee' => ['type' => 'string', 'description' => 'Decimal fee amount, e.g. "0.00" if none.'],
            'counterparty' => ['type' => ['string', 'null']],
            'transaction_time' => ['type' => ['string', 'null'], 'description' => 'ISO 8601, e.g. 2025-05-31T13:41:00'],
            'reported_balance' => ['type' => ['string', 'null'], 'description' => 'Decimal balance stated in the message, if any.'],
            'confidence' => ['type' => 'number', 'description' => '0.0 to 1.0'],
            'uncertain_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'is_financial_transaction', 'transaction_type', 'transaction_id', 'amount', 'fee',
            'counterparty', 'transaction_time', 'reported_balance', 'confidence', 'uncertain_fields',
        ],
        'additionalProperties' => false,
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You extract structured data from a single SMS text message sent by a Kenyan
        mobile money or bank provider (M-Pesa, M-Shwari, KCB M-Pesa, or a bank).

        Rules:
        - Only use information literally present in the message. Never guess or
          invent an amount, date, name, or balance that isn't stated.
        - If the message is not a financial transaction confirmation (e.g. it's a
          promotion, a loan offer, an unrelated text), set
          is_financial_transaction to false, transaction_type to "NONE", and
          leave the other fields null.
        - transaction_type must be one of: SEND_MONEY (sending money to a person),
          RECEIVE_MONEY (receiving money from a person), BUY_GOODS (paying a
          till/merchant), PAYBILL (paying a paybill/account number), WITHDRAWAL
          (cash withdrawal from an agent or ATM), BANK_DEBIT (a bank account
          debit not covered by the above), BANK_CREDIT (a bank account credit
          not covered by the above), or NONE if it doesn't clearly match any of
          those (and in that case also set is_financial_transaction to false).
        - amount, fee, and reported_balance are plain decimal strings with no
          currency symbol and no thousands separators (e.g. "1500.00", not
          "Ksh1,500.00" or "1,500").
        - List in uncertain_fields the name of any field you were not confident
          about, even if you still filled it in.
        - confidence is your own overall confidence in this extraction, 0.0 to 1.0.
        PROMPT;

    private const ASSISTANT_SYSTEM_PROMPT = <<<'PROMPT'
        You are a read-only personal finance assistant. You have no access to
        the user's financial data except through the tools provided to you —
        you cannot see, guess, or invent financial figures.

        Rules:
        - Always call a tool to get real data before answering any question
          that involves a financial figure. Never compute a sum, average, or
          percentage yourself — always use the number a tool already
          calculated for you.
        - If no available tool can answer the question, say so plainly
          rather than guessing or approximating.
        - Keep answers concise and concrete, using the actual figures the
          tools returned. State amounts clearly (they are in Kenyan
          Shillings unless the tool result says otherwise).
        - You cannot change the user's data in any way. If asked to record,
          edit, or delete anything, explain that you can only view and
          explain data, and suggest which screen in the app to use instead.
        PROMPT;

    public function __construct(private readonly Client $client, private readonly string $model) {}

    public function answerQuestion(string $question, array $tools): string
    {
        $runnableTools = array_map(
            fn (AiTool $tool) => new BetaRunnableTool(
                definition: [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'inputSchema' => $tool->parameters,
                ],
                run: fn (array $input) => json_encode(($tool->handler)($input)),
            ),
            $tools,
        );

        try {
            $runner = $this->client->beta->messages->toolRunner(
                model: $this->model,
                maxTokens: 2048,
                messages: [['role' => 'user', 'content' => $question]],
                tools: $runnableTools,
                extraParams: ['system' => self::ASSISTANT_SYSTEM_PROMPT],
            );

            $finalText = '';

            foreach ($runner as $message) {
                foreach ($message->content as $block) {
                    if ($block->type === 'text') {
                        $finalText = $block->text;
                    }
                }
            }

            return $finalText !== '' ? $finalText : "I wasn't able to come up with an answer to that.";
        } catch (Throwable $e) {
            Log::warning('ClaudeProvider: answerQuestion request failed', ['error' => $e->getMessage()]);

            return 'Something went wrong answering that question. Please try again in a moment.';
        }
    }

    public function parseFinancialMessage(string $normalizedText): ?AiExtractedTransaction
    {
        try {
            $message = $this->client->messages->create(
                model: $this->model,
                maxTokens: 1024,
                system: self::SYSTEM_PROMPT,
                messages: [
                    ['role' => 'user', 'content' => $normalizedText],
                ],
                outputConfig: [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => self::SCHEMA,
                    ],
                ],
            );
        } catch (Throwable $e) {
            // A failed AI call is a fallback failure, not an application
            // error — the message simply stays NEEDS_REVIEW. Never let an
            // AI provider outage break SMS ingestion.
            Log::warning('ClaudeProvider: parseFinancialMessage request failed', ['error' => $e->getMessage()]);

            return null;
        }

        $json = null;

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }

        if (! is_array($json) || ($json['is_financial_transaction'] ?? false) !== true) {
            return null;
        }

        return $this->toDto($json);
    }

    private function toDto(array $json): ?AiExtractedTransaction
    {
        $transactionType = is_string($json['transaction_type'] ?? null)
            ? ExtractedTransactionType::tryFrom(strtolower($json['transaction_type']))
            : null;

        if ($transactionType === null) {
            return null;
        }

        $amountMinor = $this->parseDecimal($json['amount'] ?? null);
        $feeMinor = $this->parseDecimal($json['fee'] ?? null) ?? 0;
        $reportedBalanceMinor = $this->parseDecimal($json['reported_balance'] ?? null);

        if ($amountMinor === null) {
            return null;
        }

        $transactionTime = null;
        if (is_string($json['transaction_time'] ?? null)) {
            try {
                $transactionTime = CarbonImmutable::parse($json['transaction_time']);
            } catch (Throwable) {
                $transactionTime = null;
            }
        }

        return new AiExtractedTransaction(
            isFinancialTransaction: true,
            transactionType: $transactionType,
            amountMinor: $amountMinor,
            feeMinor: $feeMinor,
            transactionTime: $transactionTime,
            externalTransactionId: is_string($json['transaction_id'] ?? null) ? $json['transaction_id'] : null,
            counterparty: is_string($json['counterparty'] ?? null) ? $json['counterparty'] : null,
            reportedBalanceMinor: $reportedBalanceMinor,
            confidence: is_numeric($json['confidence'] ?? null) ? (float) $json['confidence'] : 0.0,
            uncertainFields: is_array($json['uncertain_fields'] ?? null) ? array_values(array_filter($json['uncertain_fields'], 'is_string')) : [],
        );
    }

    private function parseDecimal(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Money::toMinorUnits($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
