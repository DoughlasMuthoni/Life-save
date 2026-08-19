<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Models\FinancialAccount;
use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Models\FinancialMessage;
use App\Domain\Ingestion\Models\ProposedTransaction;
use App\Domain\Ingestion\Parsers\MpesaParser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The orchestrator for CLAUDE.md §7's pipeline:
 *
 *   raw text -> storage -> normalization -> provider detection ->
 *   deterministic parser -> structured extraction -> duplicate detection
 *   -> proposed transaction
 *
 * There is no AI fallback yet (that's Phase 4) — a message no deterministic
 * parser understands is stored and flagged NEEDS_REVIEW, and deliberately
 * does NOT get a ProposedTransaction. Turning an unrecognized message into
 * a transaction is exactly the "don't invent missing financial information"
 * line this system won't cross without either a working parser or a human.
 */
class FinancialMessageIngestionService
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly ProviderDetector $providerDetector,
        private readonly MpesaParser $mpesaParser,
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

        $parsed = $provider === MessageProvider::MPESA ? $this->mpesaParser->parse($normalized) : null;

        // Must run BEFORE the message is inserted — otherwise the hash
        // lookup below would match the row we're about to create and every
        // message would appear to duplicate itself.
        $duplicateOf = $parsed !== null
            ? $this->duplicates->findDuplicate($user, $provider, $parsed->externalTransactionId, $hash)
            : null;

        return DB::transaction(function () use ($user, $rawText, $normalized, $hash, $provider, $parsed, $duplicateOf) {
            $message = FinancialMessage::create([
                'user_id' => $user->id,
                'raw_text' => $rawText,
                'normalized_text' => $normalized,
                'message_hash' => $hash,
                'provider' => $provider,
                'parser_type' => $parsed !== null ? MpesaParser::class : null,
                'parser_version' => $parsed !== null ? MpesaParser::VERSION : null,
                'parse_status' => $parsed !== null ? ParseStatus::PARSED : ParseStatus::NEEDS_REVIEW,
                'confidence' => $parsed !== null ? 100 : 0,
                'external_transaction_id' => $parsed?->externalTransactionId,
            ]);

            if ($parsed === null) {
                return $message;
            }

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
                'status' => $duplicateOf !== null ? ProposedTransactionStatus::DUPLICATE : ProposedTransactionStatus::PENDING_REVIEW,
                'duplicate_of_message_id' => $duplicateOf?->id,
            ]);

            $this->auditLogger->record(AuditAction::SMS_PARSED, $message, [
                'provider' => $provider->value,
                'transaction_type' => $parsed->transactionType->value,
            ]);

            if ($duplicateOf !== null) {
                $this->auditLogger->record(AuditAction::SMS_DUPLICATE_DETECTED, $message, [
                    'duplicate_of_message_id' => $duplicateOf->id,
                ]);
            }

            return $message;
        });
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
