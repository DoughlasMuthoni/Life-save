<?php

namespace Tests\Feature\Finance;

use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Enums\ParseStatus;
use App\Domain\Ingestion\Enums\ProposedTransactionStatus;
use App\Domain\Ingestion\Parsers\MpesaStatementParser;
use App\Domain\Ingestion\Services\FinancialMessageIngestionService;
use App\Domain\Ingestion\Services\ProposedTransactionConfirmationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\FakeAIProvider;
use Tests\Support\PdfFixtureBuilder;
use Tests\TestCase;

class StatementImportTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    private function sampleStatementText(): string
    {
        return <<<'TXT'
            Receipt No.        Completion Time                  Details               Transaction Status     Paid In           Withdrawn              Balance
            AB1JK5E6YT           2026-09-07 16:44:41   Customer Transfer to -            Completed                                      -1,800.00                  685.61
                                                       0114***703 MERCY GICHARU
            AB1JK5E6YT           2026-09-07 16:44:41   Customer Transfer of Funds        Completed                                         -33.00                  652.61
                                                       Charge
            AB1895GAZY           2026-09-07 11:23:37   Funds received from -             Completed                        70.00                                  1,199.61
            TXT;
    }

    public function test_ingesting_a_statement_batch_via_the_service_produces_proposals_for_each_row(): void
    {
        $user = User::factory()->create();

        $messages = app(FinancialMessageIngestionService::class)->ingestStatementBatch($user, $this->sampleStatementText());

        $this->assertCount(3, $messages);
        $this->assertTrue($messages->every(fn ($m) => $m->parse_status === ParseStatus::PARSED));

        $types = $messages->map(fn ($m) => $m->proposedTransaction->transaction_type)->all();
        $this->assertContains(ExtractedTransactionType::SEND_MONEY, $types);
        $this->assertContains(ExtractedTransactionType::STATEMENT_FEE, $types);
        $this->assertContains(ExtractedTransactionType::RECEIVE_MONEY, $types);
    }

    public function test_the_parent_row_and_its_fee_row_are_not_flagged_as_duplicates_of_each_other(): void
    {
        $user = User::factory()->create();

        $messages = app(FinancialMessageIngestionService::class)->ingestStatementBatch($user, $this->sampleStatementText());

        $statuses = $messages->map(fn ($m) => $m->proposedTransaction->status)->all();

        $this->assertTrue(collect($statuses)->every(fn ($s) => $s === ProposedTransactionStatus::PENDING_REVIEW));
    }

    public function test_pasting_the_exact_same_statement_twice_flags_the_second_batch_as_duplicates(): void
    {
        $user = User::factory()->create();
        $ingestion = app(FinancialMessageIngestionService::class);

        $ingestion->ingestStatementBatch($user, $this->sampleStatementText());
        $second = $ingestion->ingestStatementBatch($user, $this->sampleStatementText());

        $this->assertTrue($second->every(
            fn ($m) => $m->proposedTransaction->status === ProposedTransactionStatus::DUPLICATE
        ));
    }

    public function test_an_unrecognized_row_never_calls_the_ai_provider(): void
    {
        $user = User::factory()->create();
        $fake = new FakeAIProvider;
        $this->app->instance(AIProviderInterface::class, $fake);

        $textWithAReversal = $this->sampleStatementText()."\n".
            'AB1JK4YAAA           2026-09-05 10:00:00   Send Money Reversal via API       Completed                        500.00                                  9,000.00';

        app(FinancialMessageIngestionService::class)->ingestStatementBatch($user, $textWithAReversal);

        // Deliberate: bulk statement import skips AI fallback entirely
        // (potentially hundreds of rows in one paste) — the reversal row
        // should end up NEEDS_REVIEW, not an AI-derived proposal.
        $this->assertSame(0, $fake->parseCallCount);
    }

    public function test_an_unrecognized_row_is_stored_as_needs_review_not_silently_dropped(): void
    {
        $user = User::factory()->create();

        $textWithAReversal = "Receipt No.\n".
            'AB1JK4YAAA           2026-09-05 10:00:00   Send Money Reversal via API       Completed                        500.00                                  9,000.00';

        $messages = app(FinancialMessageIngestionService::class)->ingestStatementBatch($user, $textWithAReversal);

        $this->assertCount(1, $messages);
        $this->assertSame(ParseStatus::NEEDS_REVIEW, $messages->first()->parse_status);
        $this->assertNull($messages->first()->proposedTransaction);
    }

    public function test_confirming_a_statement_derived_transfer_posts_correctly_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $mpesa = $this->createFinancialAccount($user);
        $expense = $this->createExpenseCategory($user, 'Transfers');

        $messages = app(FinancialMessageIngestionService::class)->ingestStatementBatch(
            $user,
            "Receipt No.\nAB1JK5E6YT           2026-09-07 16:44:41   Customer Transfer to -            Completed                                      -1,800.00                  685.61\n                                                       0114***703 MERCY GICHARU"
        );

        $proposal = $messages->first()->proposedTransaction;

        app(ProposedTransactionConfirmationService::class)->confirm($user, $proposal, [
            'financial_account_id' => $mpesa->id,
            'transaction_category_id' => $expense->id,
        ]);

        $this->assertSame(-180000, $mpesa->fresh()->balanceMinor());
    }

    public function test_the_import_page_shows_a_summary_after_importing(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementText', $this->sampleStatementText())
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('Rows found')
            ->assertSee('3');
    }

    public function test_pasting_unrecognizable_text_is_rejected_with_a_helpful_message(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementText', 'This is not a statement at all, just some random text.')
            ->call('import')
            ->assertHasErrors(['statementText']);
    }

    public function test_the_statement_parser_is_correctly_recorded_as_the_source_parser(): void
    {
        $user = User::factory()->create();

        $messages = app(FinancialMessageIngestionService::class)->ingestStatementBatch($user, $this->sampleStatementText());

        $this->assertTrue($messages->every(fn ($m) => $m->parser_type === MpesaStatementParser::class));
        $this->assertTrue($messages->every(fn ($m) => $m->parser_version === MpesaStatementParser::VERSION));
    }

    private function samplePdfUpload(string $originalName = 'statement.pdf'): UploadedFile
    {
        $path = PdfFixtureBuilder::sampleStatementPdf();

        try {
            return UploadedFile::fake()->createWithContent($originalName, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_uploading_a_statement_pdf_imports_it_via_the_same_pipeline_as_paste(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementFile', $this->samplePdfUpload())
            ->call('import')
            ->assertHasNoErrors()
            ->assertSee('Rows found')
            ->assertSee('1');

        // CLAUDE.md §7a: parser_type must be recorded exactly as it is
        // for a paste — the PDF is only a different way of getting text
        // into the identical MpesaStatementParser / ingest pipeline.
        $this->assertDatabaseHas('financial_messages', [
            'user_id' => $user->id,
            'parser_type' => MpesaStatementParser::class,
        ]);
    }

    public function test_uploading_a_statement_pdf_records_an_audit_event_without_logging_extracted_text(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementFile', $this->samplePdfUpload('my-mpesa-statement.pdf'))
            ->call('import')
            ->assertHasNoErrors();

        $event = AuditEvent::where('action', AuditAction::STATEMENT_PDF_IMPORTED)->first();

        $this->assertNotNull($event);
        $this->assertSame('my-mpesa-statement.pdf', $event->data['filename']);
        $this->assertSame(1, $event->data['rows_found']);
        // The extracted statement text (receipt numbers, names, amounts)
        // must never end up in the audit trail — only counts/metadata.
        $this->assertStringNotContainsString('JANE SAMPLE', json_encode($event->data));
    }

    public function test_uploading_a_non_pdf_file_is_rejected_with_a_helpful_message(): void
    {
        $user = User::factory()->create();

        $file = UploadedFile::fake()->createWithContent('fake.pdf', 'just some text, not a real pdf');

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementFile', $file)
            ->call('import')
            ->assertHasErrors(['statementFile']);
    }

    public function test_neither_pasting_nor_uploading_anything_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('finance.statement-import')
            ->set('statementText', '')
            ->call('import')
            ->assertHasErrors(['statementText']);
    }
}
