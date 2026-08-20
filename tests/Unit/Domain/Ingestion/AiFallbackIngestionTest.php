<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Parsers\MpesaParser;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAIProvider;
use Tests\TestCase;

class AiFallbackIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function bindAi(?AiExtractedTransaction $response): FakeAIProvider
    {
        $fake = new FakeAIProvider($response);
        $this->app->instance(AIProviderInterface::class, $fake);

        return $fake;
    }

    public function test_a_verified_ai_extraction_becomes_a_proposed_transaction(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Not M-Pesa, not a recognizable bank alert shape — deterministic
        // parsers won't touch this, so it must go through the AI fallback.
        $raw = 'Umetuma KES 1,500.00 kwa JOHN MWANGI tarehe 31/5/25 saa 1:41 PM. Salio jipya la M-PESA ni KES 3,450.00.';

        $this->bindAi(new AiExtractedTransaction(
            isFinancialTransaction: true,
            transactionType: ExtractedTransactionType::SEND_MONEY,
            amountMinor: 150000,
            feeMinor: 0,
            transactionTime: CarbonImmutable::create(2025, 5, 31, 13, 41),
            externalTransactionId: null,
            counterparty: 'JOHN MWANGI',
            reportedBalanceMinor: 345000,
            confidence: 0.9,
        ));

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        $this->assertSame(ParseStatus::PARSED, $message->parse_status);
        $this->assertSame(90, $message->confidence);
        $this->assertSame(AIProviderInterface::class, $message->parser_type);

        $proposed = $message->proposedTransaction;
        $this->assertNotNull($proposed);
        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $proposed->status);
        $this->assertSame(150000, $proposed->amount_minor);
        $this->assertIsArray($proposed->field_verification);
        $this->assertTrue($proposed->field_verification['amount_verified']);

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => AuditAction::AI_PARSE_ACCEPTED->value,
        ]);
    }

    public function test_an_ai_extraction_that_fails_verification_is_rejected_not_trusted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $raw = 'Umetuma KES 1,500.00 kwa JOHN MWANGI tarehe 31/5/25 saa 1:41 PM. Salio jipya la M-PESA ni KES 3,450.00.';

        // Amount doesn't match anything in the source text — a hallucinated
        // extraction the backend must catch independently.
        $this->bindAi(new AiExtractedTransaction(
            isFinancialTransaction: true,
            transactionType: ExtractedTransactionType::SEND_MONEY,
            amountMinor: 999999999,
            feeMinor: 0,
            transactionTime: CarbonImmutable::create(2025, 5, 31),
            externalTransactionId: null,
            counterparty: 'JOHN MWANGI',
            reportedBalanceMinor: null,
            confidence: 0.9,
        ));

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        $this->assertSame(ParseStatus::NEEDS_REVIEW, $message->parse_status);
        $this->assertNull($message->proposedTransaction);

        $this->assertDatabaseHas('audit_events', [
            'user_id' => $user->id,
            'action' => AuditAction::AI_PARSE_REJECTED->value,
        ]);
    }

    public function test_the_ai_declining_a_non_transaction_message_results_in_needs_review_without_a_rejection_audit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->bindAi(null);

        $message = app(FinancialMessageIngestionService::class)->ingest($user, 'Hey, are we still on for lunch tomorrow?');

        $this->assertSame(ParseStatus::NEEDS_REVIEW, $message->parse_status);
        $this->assertDatabaseMissing('audit_events', [
            'user_id' => $user->id,
            'action' => AuditAction::AI_PARSE_REJECTED->value,
        ]);
    }

    public function test_deterministic_parsing_never_calls_the_ai_provider(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // If the AI fallback were invoked here, FakeAIProvider(null) would
        // make this message NEEDS_REVIEW instead of PARSED.
        $this->bindAi(null);

        $raw = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $message = app(FinancialMessageIngestionService::class)->ingest($user, $raw);

        $this->assertSame(ParseStatus::PARSED, $message->parse_status);
        $this->assertSame(MpesaParser::class, $message->parser_type);
    }

    public function test_pasting_the_identical_message_twice_only_calls_the_ai_provider_once(): void
    {
        // Force the real 'database' cache store rather than the test
        // suite's default 'array' store — array caching never serializes
        // at all, so it can't catch a value that fails to round-trip
        // through PHP's actual serialize()/unserialize() (see
        // FinancialMessageIngestionService::cachedAiParse() — this
        // exact bug was caught by testing against the database store).
        config(['cache.default' => 'database']);

        $user = User::factory()->create();
        $this->actingAs($user);

        $raw = 'Umetuma KES 1,500.00 kwa JOHN MWANGI tarehe 31/5/25 saa 1:41 PM. Salio jipya la M-PESA ni KES 3,450.00.';

        $fake = $this->bindAi(new AiExtractedTransaction(
            isFinancialTransaction: true,
            transactionType: ExtractedTransactionType::SEND_MONEY,
            amountMinor: 150000,
            feeMinor: 0,
            transactionTime: CarbonImmutable::create(2025, 5, 31, 13, 41),
            externalTransactionId: null,
            counterparty: 'JOHN MWANGI',
            reportedBalanceMinor: 345000,
            confidence: 0.9,
        ));

        $service = app(FinancialMessageIngestionService::class);
        $first = $service->ingest($user, $raw);
        $second = $service->ingest($user, $raw.' ');

        $this->assertSame(1, $fake->parseCallCount);
        $this->assertSame($first->proposedTransaction->amount_minor, $second->proposedTransaction->amount_minor);
        $this->assertSame($first->proposedTransaction->counterparty, $second->proposedTransaction->counterparty);
    }

    public function test_pasting_the_identical_non_financial_message_twice_only_calls_the_ai_provider_once(): void
    {
        config(['cache.default' => 'database']);

        $user = User::factory()->create();
        $this->actingAs($user);

        // A null response ("this isn't a financial transaction") must be
        // cached too — Laravel's Cache::remember() treats a stored null
        // exactly like a cache miss, so this specifically guards against
        // that gotcha silently defeating the cache for every declined
        // message.
        $fake = $this->bindAi(null);

        $raw = 'Hey, are we still on for lunch tomorrow?';

        $service = app(FinancialMessageIngestionService::class);
        $service->ingest($user, $raw);
        $service->ingest($user, $raw);

        $this->assertSame(1, $fake->parseCallCount);
    }
}
