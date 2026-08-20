<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Ingestion\DataTransferObjects\ParsedMessage;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Ingestion\Parsers\BankSmsParser;
use App\Domain\Ingestion\Parsers\MpesaParser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        ['extraction' => $extraction, 'aiRejected' => $aiRejected] = $this->extract($normalized, $provider, $hash);

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
            [$financialAccountId, $destinationFinancialAccountId] = $this->resolveAccountGuesses($user, $provider, $parsed, $shape);

            ProposedTransaction::create([
                'financial_message_id' => $message->id,
                'user_id' => $user->id,
                'transaction_type' => $parsed->transactionType,
                'financial_account_id' => $financialAccountId,
                'destination_financial_account_id' => $destinationFinancialAccountId,
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
    private function extract(string $normalized, MessageProvider $provider, string $hash): array
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

        $aiCandidate = $this->cachedAiParse($normalized, $hash);

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

    /**
     * Caches the AI's verdict for a given normalized message text (byte-
     * identical re-pastes are common — an accidental double-submit, or a
     * message copied twice from the phone's SMS app) so an identical
     * message never pays for a second Claude API call.
     *
     * The AI's answer is nullable ("this isn't a financial transaction"
     * is a legitimate, meaningful null), so the raw result can't be cached
     * with Cache::remember() directly — Laravel's remember() treats a
     * stored null exactly like a cache miss and would call the provider
     * again every time. Wrapping it in a single-key array keeps the cache
     * entry itself always non-null.
     *
     * AiExtractedTransaction is a `readonly` DTO — PHP's native
     * serialize()/unserialize() (what the database cache store uses)
     * cannot reconstruct readonly properties set outside the constructor
     * and silently degrades to __PHP_Incomplete_Class instead of erroring.
     * Confirmed against a real cache round-trip, not assumed. So the DTO
     * is broken down into plain, safely-serializable primitives before
     * caching and rebuilt through its constructor on a hit.
     */
    private function cachedAiParse(string $normalized, string $hash): ?AiExtractedTransaction
    {
        $cacheKey = "ai_sms_parse:{$hash}";

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached['candidate'] === null ? null : $this->hydrateCachedCandidate($cached['candidate']);
        }

        $candidate = $this->aiProvider->parseFinancialMessage($normalized);

        Cache::put($cacheKey, [
            'candidate' => $candidate === null ? null : $this->dehydrateCandidate($candidate),
        ], now()->addDay());

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrateCandidate(AiExtractedTransaction $candidate): array
    {
        return [
            'isFinancialTransaction' => $candidate->isFinancialTransaction,
            'transactionType' => $candidate->transactionType?->value,
            'amountMinor' => $candidate->amountMinor,
            'feeMinor' => $candidate->feeMinor,
            'transactionTime' => $candidate->transactionTime?->toIso8601String(),
            'externalTransactionId' => $candidate->externalTransactionId,
            'counterparty' => $candidate->counterparty,
            'reportedBalanceMinor' => $candidate->reportedBalanceMinor,
            'confidence' => $candidate->confidence,
            'uncertainFields' => $candidate->uncertainFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrateCachedCandidate(array $data): AiExtractedTransaction
    {
        return new AiExtractedTransaction(
            isFinancialTransaction: $data['isFinancialTransaction'],
            transactionType: $data['transactionType'] !== null ? ExtractedTransactionType::from($data['transactionType']) : null,
            amountMinor: $data['amountMinor'],
            feeMinor: $data['feeMinor'],
            transactionTime: $data['transactionTime'] !== null ? CarbonImmutable::parse($data['transactionTime']) : null,
            externalTransactionId: $data['externalTransactionId'],
            counterparty: $data['counterparty'],
            reportedBalanceMinor: $data['reportedBalanceMinor'],
            confidence: $data['confidence'],
            uncertainFields: $data['uncertainFields'],
        );
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

    /**
     * @return array{0: ?int, 1: ?int} [financial_account_id, destination_financial_account_id]
     */
    private function resolveAccountGuesses(User $user, MessageProvider $provider, ParsedMessage $parsed, TransactionShape $shape): array
    {
        $primaryProvider = $this->mapToFinancialAccountProvider($provider);

        // A Fuliza drawdown runs the other way round from every other
        // transfer-shaped message: the source is the Fuliza liability
        // (which increases — you owe more), the destination is the M-Pesa
        // account the borrowed funds land in. A withdrawal or a Fuliza
        // repayment both keep the detected message provider as the
        // source, only the destination differs (cash vs. paying down the
        // Fuliza liability).
        [$sourceProvider, $destinationProvider] = match ($parsed->transactionType) {
            ExtractedTransactionType::FULIZA_DRAWDOWN => [FinancialAccountProvider::FULIZA, $primaryProvider],
            ExtractedTransactionType::FULIZA_REPAYMENT => [$primaryProvider, FinancialAccountProvider::FULIZA],
            ExtractedTransactionType::WITHDRAWAL => [$primaryProvider, FinancialAccountProvider::CASH],
            default => [$primaryProvider, $shape === TransactionShape::TRANSFER ? FinancialAccountProvider::CASH : null],
        };

        return [
            $this->guessAccount($user, $sourceProvider),
            $destinationProvider !== null ? $this->guessAccount($user, $destinationProvider) : null,
        ];
    }
}
