<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Ingestion\Exceptions\UnprocessablePdfException;
use App\Domain\Ingestion\Services\PdfStatementTextExtractor;
use Tests\Support\PdfFixtureBuilder;
use Tests\TestCase;

class PdfStatementTextExtractorTest extends TestCase
{
    private PdfStatementTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new PdfStatementTextExtractor;
    }

    public function test_it_extracts_text_from_a_valid_pdf(): void
    {
        $path = PdfFixtureBuilder::sampleStatementPdf();

        try {
            $text = $this->extractor->extractText($path);
        } finally {
            unlink($path);
        }

        // The fixture PDF splits one row across several separate
        // text-showing commands (receipt, timestamp, two wrapped
        // Details lines, status, amount, balance) — mirroring real
        // Safaricom statement PDFs — to exercise the row-reflow logic,
        // not just raw text concatenation. The header row must not
        // survive reflow (it doesn't start with a receipt+timestamp).
        $this->assertStringNotContainsString('Receipt No.', $text);
        $this->assertSame(
            'TE1TESTAB1 2026-01-05 10:15:00 Customer Transfer to - 0700***000 JANE SAMPLE Completed -100.00 400.00',
            trim($text)
        );
    }

    public function test_it_rejects_a_file_that_is_not_a_pdf_at_all(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-pdf');
        file_put_contents($path, "This is just plain text, not a PDF.\n");

        try {
            $this->expectException(UnprocessablePdfException::class);
            $this->extractor->extractText($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_a_pdf_with_no_extractable_text(): void
    {
        // A structurally valid PDF whose one page has no content stream
        // at all — the same outcome as a scanned-image-only PDF from
        // this library's point of view (no text layer to pull from).
        $pdf = <<<'PDF'
            %PDF-1.4
            1 0 obj
            << /Type /Catalog /Pages 2 0 R >>
            endobj
            2 0 obj
            << /Type /Pages /Kids [3 0 R] /Count 1 >>
            endobj
            3 0 obj
            << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>
            endobj
            trailer
            << /Size 4 /Root 1 0 R >>
            %%EOF
            PDF;

        $path = tempnam(sys_get_temp_dir(), 'empty-pdf');
        file_put_contents($path, $pdf);

        try {
            $this->expectException(UnprocessablePdfException::class);
            $this->extractor->extractText($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * smalot/pdfparser has no dedicated encryption exception — it throws
     * a generic \Exception with a message like "Secured pdf file are
     * currently not supported." This tests the mapping logic directly
     * rather than needing a hand-crafted encrypted PDF fixture.
     */
    public function test_it_recognizes_the_libraries_encryption_error_message(): void
    {
        $this->assertTrue(PdfStatementTextExtractor::looksLikeEncryptionError(
            'Secured pdf file are currently not supported.'
        ));
        $this->assertTrue(PdfStatementTextExtractor::looksLikeEncryptionError(
            'Something about an Encrypted document.'
        ));
        $this->assertFalse(PdfStatementTextExtractor::looksLikeEncryptionError(
            'Object list not found. Possible secured file.'
        ));
    }

    public function test_the_encrypted_exception_carries_a_helpful_user_message(): void
    {
        $exception = UnprocessablePdfException::encrypted();

        $this->assertStringContainsString('password-protected', $exception->userMessage);
        $this->assertStringContainsString('unlocked copy', $exception->userMessage);
    }
}
