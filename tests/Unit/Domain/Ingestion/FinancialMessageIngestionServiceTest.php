<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Finance\Enums\FinancialAccountProvider;
use App\Domain\Finance\Exceptions\ImmutableLedgerRecordException;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Enums\TransactionShape;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class FinancialMessageIngestionServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private FinancialMessageIngestionService $ingestion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestion = app(FinancialMessageIngestionService::class);
    }

    private function sendMoneySms(string $code = 'QGH7XI9K2L'): string
    {
        return "{$code} Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. ".
            'New M-PESA balance is Ksh3,450.00. Transaction cost, Ksh0.00.';
    }

    public function test_a_recognized_message_produces_a_pending_proposed_transaction(): void
    {
        $user = User::factory()->create();

        $message = $this->ingestion->ingest($user, $this->sendMoneySms());

        $this->assertSame(ParseStatus::PARSED, $message->parse_status);
        $this->assertSame(MessageProvider::MPESA, $message->provider);
        $this->assertSame('QGH7XI9K2L', $message->external_transaction_id);
        $this->assertSame($this->sendMoneySms(), $message->raw_text);

        $proposed = $message->proposedTransaction;
        $this->assertNotNull($proposed);
        $this->assertSame(ExtractedTransactionType::SEND_MONEY, $proposed->transaction_type);
        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $proposed->status);
        $this->assertSame(150000, $proposed->amount_minor);
    }

    public function test_the_source_mpesa_account_is_prefilled_when_the_user_has_exactly_one(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user, 'M-Pesa', FinancialAccountProvider::MPESA);

        $message = $this->ingestion->ingest($user, $this->sendMoneySms());

        $this->assertSame($mpesa->id, $message->proposedTransaction->financial_account_id);
    }

    public function test_the_source_account_is_left_blank_when_ambiguous(): void
    {
        $user = User::factory()->create();
        $this->createFinancialAccount($user, 'M-Pesa Personal', FinancialAccountProvider::MPESA);
        $this->createFinancialAccount($user, 'M-Pesa Business', FinancialAccountProvider::MPESA);

        $message = $this->ingestion->ingest($user, $this->sendMoneySms());

        $this->assertNull($message->proposedTransaction->financial_account_id);
    }

    public function test_an_unrecognized_message_is_flagged_for_review_without_a_proposed_transaction(): void
    {
        $user = User::factory()->create();

        $message = $this->ingestion->ingest($user, 'Your loan of Ksh5,000.00 has been approved.');

        $this->assertSame(ParseStatus::NEEDS_REVIEW, $message->parse_status);
        $this->assertNull($message->proposedTransaction);
    }

    public function test_pasting_the_exact_same_message_twice_flags_the_second_as_a_duplicate(): void
    {
        $user = User::factory()->create();
        $raw = $this->sendMoneySms();

        $first = $this->ingestion->ingest($user, $raw);
        $second = $this->ingestion->ingest($user, $raw);

        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $first->proposedTransaction->status);
        $this->assertSame(ProposedTransactionStatus::DUPLICATE, $second->proposedTransaction->status);
        $this->assertSame($first->id, $second->proposedTransaction->duplicate_of_message_id);

        // Both messages are stored — evidence is never dropped.
        $this->assertDatabaseCount('financial_messages', 2);
    }

    public function test_the_same_transaction_code_with_different_wording_is_still_flagged_a_duplicate(): void
    {
        $user = User::factory()->create();

        $first = $this->ingestion->ingest($user, $this->sendMoneySms('QGH7SAMECODE'));
        $second = $this->ingestion->ingest(
            $user,
            'QGH7SAMECODE Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM.   New M-PESA balance is Ksh3,450.00.'
        );

        $this->assertSame(ProposedTransactionStatus::DUPLICATE, $second->proposedTransaction->status);
        $this->assertSame($first->id, $second->proposedTransaction->duplicate_of_message_id);
    }

    public function test_a_different_transaction_is_not_flagged_as_a_duplicate(): void
    {
        $user = User::factory()->create();

        $this->ingestion->ingest($user, $this->sendMoneySms('QGH7CODEONE'));
        $second = $this->ingestion->ingest($user, $this->sendMoneySms('QGH7CODETWO'));

        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $second->proposedTransaction->status);
    }

    public function test_duplicate_detection_is_scoped_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $raw = $this->sendMoneySms('QGH7SHARED01');

        $this->ingestion->ingest($owner, $raw);
        $second = $this->ingestion->ingest($other, $raw);

        $this->assertSame(ProposedTransactionStatus::PENDING_REVIEW, $second->proposedTransaction->status);
    }

    public function test_a_withdrawal_is_extracted_as_a_transfer_shaped_proposal(): void
    {
        $user = User::factory()->create();
        $raw = 'QGH7WD00001 Confirmed. Ksh2,000.00 withdrawn from 123456 - AGENT NAME on 30/5/25 at 6:45 PM. '.
            'New M-PESA balance is Ksh1,800.00. Transaction cost, Ksh28.00.';

        $message = $this->ingestion->ingest($user, $raw);

        $this->assertSame(ExtractedTransactionType::WITHDRAWAL, $message->proposedTransaction->transaction_type);
        $this->assertSame(TransactionShape::TRANSFER, $message->proposedTransaction->transaction_type->shape());
    }

    public function test_pasting_multiple_messages_at_once_ingests_each_one(): void
    {
        $user = User::factory()->create();

        $batch = $this->sendMoneySms('QGH7BATCH01')."\n\n".
            "QGH7BATCH02 Confirmed. Ksh450.00 paid to QUICKMART JUJA. on 31/5/25 at 3:22 PM. New M-PESA balance is Ksh3,000.00.\n\n".
            'Your loan of Ksh5,000.00 has been approved.';

        $messages = $this->ingestion->ingestBatch($user, $batch);

        $this->assertCount(3, $messages);
        $this->assertSame(ParseStatus::PARSED, $messages[0]->parse_status);
        $this->assertSame(ParseStatus::PARSED, $messages[1]->parse_status);
        $this->assertSame(ParseStatus::NEEDS_REVIEW, $messages[2]->parse_status);
    }

    public function test_financial_messages_are_immutable(): void
    {
        $user = User::factory()->create();
        $message = $this->ingestion->ingest($user, $this->sendMoneySms());

        $this->expectException(ImmutableLedgerRecordException::class);

        $message->raw_text = 'tampered';
        $message->save();
    }
}
