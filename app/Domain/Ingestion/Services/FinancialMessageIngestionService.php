<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Ingestion\DataTransferObjects\ParsedMessage;
use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Ingestion\Parsers\BankSmsParser;
use App\Domain\Ingestion\Parsers\MpesaParser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The orchestrator for CLAUDE.md §7's pipeline:
 *
 *   raw text -> storage -> normalization -> provider detection ->
 *   deterministic parser -> AI fallback (only if deterministic fails) ->
 *   structured extraction -> validation against source text ->
 *   duplicate detection -> proposed transaction
 *
 * A message nothing can parse (deterministically or via AI) is stored and
 * flagged NEEDS_REVIEW with no ProposedTransaction — turning an
 * unrecognized message into a transaction is exactly the "don't invent
 * missing financial information" line this system won't cross.
 */
class FinancialMessageIngestionService
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly ProviderDetector $providerDetector,
        private readonly MpesaParser $mpesaParser,
        private readonly BankSmsParser $bankSmsParser,
        private readonly AIProviderInterface $aiProvider,
        private readonly AiExtractionValidator $aiValidator,
        private readonly DuplicateDetectionService $duplicates,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, FinancialMessage>
     */
    public function ingestBatch(User $user, string $pastedText): Collection
    {
        return collect($this->normalizer->splitMessages($pastedText))
            ->map(fn (string $rawText) => $this->ingest($user, $rawText));
    }

    public function ingest(User $user, string $rawText): FinancialMessage
    {
        $normalized = $this->normalizer->normalize($rawText);
        $hash = hash('sha256', $normalized);
        $provider = $this->providerDetector->detect($normalized);

        ['extraction' => $extraction, 'aiRejected' => $aiRejected] = $this->extract($normalized, $provider);

        // Must run BEFORE the message is inserted — otherwise the hash
        // lookup below would match the row we're about to create and every
        // message would appear to duplicate itself.
        $duplicateOf = $extraction !== null
            ? $this->duplicates->findDuplicate($user, $provider, $extraction['parsed']->externalTransactionId, $hash)
            : null;

        return DB::transaction(function () use ($user, $rawText, $normalized, $hash, $provider, $extraction, $aiRejected, $duplicateOf) {
            $message = FinancialMessage::create([
                'user_id' => $user->id,
                'raw_text' => $rawText,
                'normalized_text' => $normalized,
                'message_hash' => $hash,
                'provider' => $provider,
                'parser_type' => $extraction['parserType'] ?? null,
                'parser_version' => $extraction['parserVersion'] ?? null,
                'parse_status' => $extraction !== null ? ParseStatus::PARSED : ParseStatus::NEEDS_REVIEW,
                'confidence' => $extraction['confidence'] ?? 0,
                'external_transaction_id' => $extraction['parsed']->externalTransactionId ?? null,
            ]);

            if ($aiRejected) {
                $this->auditLogger->record(AuditAction::AI_PARSE_REJECTED, $message);
            }

            if ($extraction === null) {
                return $message;
            }

            /** @var ParsedMessage $parsed */
            $parsed = $extraction['parsed'];
            $shape = $parsed->transactionType->shape();

            ProposedTransaction::create([
                'financial_message_id' => $message->id,
                'user_id' => $user->id,
                'transaction_type' => $parsed->transactionType,
                'financial_account_id' => $this->guessAccount($user, $this->mapToFinancialAccountProvider($provider)),
                'destination_financial_account_id' => $shape === TransactionShape::TRANSFER
                    ? $this->guessAccount($user, FinancialAccountProvider::CASH)
                    : null,
                'amount_minor' => $parsed->amountMinor,
                'fee_minor' => $parsed->feeMinor,
                'currency' => 'KES',
                'counterparty' => $parsed->counterparty,
                'transaction_time' => $parsed->transactionTime,
                'reported_balance_minor' => $parsed->reportedBalanceMinor,
                'field_verification' => $extraction['fieldVerification'] ?? null,
                'status' => $duplicateOf !== null ? ProposedTransactionStatus::DUPLICATE : ProposedTransactionStatus::PENDING_REVIEW,
                'duplicate_of_message_id' => $duplicateOf?->id,
            ]);

            $this->auditLogger->record(AuditAction::SMS_PARSED, $message, [
                'provider' => $provider->value,
                'transaction_type' => $parsed->transactionType->value,
                'parser_type' => $extraction['parserType'],
            ]);

            if ($extraction['parserType'] === AIProviderInterface::class) {
                $this->auditLogger->record(AuditAction::AI_PARSE_ACCEPTED, $message, [
                    'field_verification' => $extraction['fieldVerification'],
                ]);
            }

            if ($duplicateOf !== null) {
                $this->auditLogger->record(AuditAction::SMS_DUPLICATE_DETECTED, $message, [
                    'duplicate_of_message_id' => $duplicateOf->id,
                ]);
            }

            return $message;
        });
    }

    /**
     * @return array{extraction: array{parsed: ParsedMessage, parserType: string, parserVersion: string, confidence: int, fieldVerification: ?array}|null, aiRejected: bool}
     */
    private function extract(string $normalized, MessageProvider $provider): array
    {
        $deterministic = match ($provider) {
            MessageProvider::MPESA => $this->mpesaParser->parse($normalized),
            MessageProvider::BANK => $this->bankSmsParser->parse($normalized),
            // M-Shwari and KCB M-Pesa don't have dedicated deterministic
            // parsers yet — they fall through to the AI fallback below.
            default => null,
        };

        if ($deterministic !== null) {
            return [
                'extraction' => [
                    'parsed' => $deterministic,
                    'parserType' => $provider === MessageProvider::MPESA ? MpesaParser::class : BankSmsParser::class,
                    'parserVersion' => $provider === MessageProvider::MPESA ? MpesaParser::VERSION : BankSmsParser::VERSION,
                    'confidence' => 100,
                    'fieldVerification' => null,
                ],
                'aiRejected' => false,
            ];
        }

        $aiCandidate = $this->aiProvider->parseFinancialMessage($normalized);

        if ($aiCandidate === null) {
            // No opinion from the AI at all (network/provider failure, or it
            // legitimately isn't a financial transaction) — not a rejection.
            return ['extraction' => null, 'aiRejected' => false];
        }

        if ($aiCandidate->transactionType === null || $aiCandidate->amountMinor === null || $aiCandidate->transactionTime === null) {
            return ['extraction' => null, 'aiRejected' => true];
        }

        $verification = $this->aiValidator->verify($aiCandidate, $normalized);

        if (! $this->aiValidator->passesMinimumBar($verification)) {
            return ['extraction' => null, 'aiRejected' => true];
        }

        return [
            'extraction' => [
                'parsed' => new ParsedMessage(
                    transactionType: $aiCandidate->transactionType,
                    amountMinor: $aiCandidate->amountMinor,
                    feeMinor: $aiCandidate->feeMinor,
                    transactionTime: $aiCandidate->transactionTime,
                    externalTransactionId: $aiCandidate->externalTransactionId,
                    counterparty: $aiCandidate->counterparty,
                    reportedBalanceMinor: $aiCandidate->reportedBalanceMinor,
                ),
                'parserType' => AIProviderInterface::class,
                'parserVersion' => config('services.anthropic.model'),
                'confidence' => (int) round($aiCandidate->confidence * 100),
                'fieldVerification' => $verification,
            ],
            'aiRejected' => false,
        ];
    }

    private function mapToFinancialAccountProvider(MessageProvider $provider): ?FinancialAccountProvider
    {
        return match ($provider) {
            MessageProvider::MPESA => FinancialAccountProvider::MPESA,
            MessageProvider::MSHWARI => FinancialAccountProvider::MSHWARI,
            MessageProvider::KCB_MPESA => FinancialAccountProvider::KCB_MPESA,
            MessageProvider::BANK => FinancialAccountProvider::BANK,
            MessageProvider::UNKNOWN => null,
        };
    }

    /**
     * A convenience default, nothing more: if the user has exactly one
     * account for this provider, pre-fill it. Otherwise leave it null —
     * the user picks during confirmation. Never guess when it's ambiguous.
     */
    private function guessAccount(User $user, ?FinancialAccountProvider $provider): ?int
    {
        if ($provider === null) {
            return null;
        }

        $accounts = FinancialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->get();

        return $accounts->count() === 1 ? $accounts->first()->id : null;
    }
}
