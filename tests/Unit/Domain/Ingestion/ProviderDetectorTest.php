<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Ingestion\Enums\MessageProvider;
use App\Domain\Ingestion\Services\ProviderDetector;
use Tests\TestCase;

class ProviderDetectorTest extends TestCase
{
    private ProviderDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new ProviderDetector;
    }

    public function test_a_regular_mpesa_sms_is_detected_as_mpesa(): void
    {
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $this->assertSame(MessageProvider::MPESA, $this->detector->detect($text));
    }

    public function test_a_regular_mshwari_sms_is_still_detected_as_mshwari(): void
    {
        $text = 'You have successfully deposited Ksh1,000.00 to your M-Shwari account.';

        $this->assertSame(MessageProvider::MSHWARI, $this->detector->detect($text));
    }

    public function test_a_regular_kcb_mpesa_sms_is_still_detected_as_kcb_mpesa(): void
    {
        $text = 'Confirmed. You have received Ksh500.00 into your KCB M-PESA account.';

        $this->assertSame(MessageProvider::KCB_MPESA, $this->detector->detect($text));
    }

    /**
     * The bug this guards against: an M-Pesa statement row for an
     * M-Shwari transfer literally contains the text "M-Shwari" ("M-Shwari
     * Withdraw ... Completed 2,000.00 2,485.61"), which — before this was
     * fixed — got classified as MessageProvider::MSHWARI (no statement
     * parser wired to it) rather than MPESA, so the row silently never
     * reached MpesaStatementParser at all.
     */
    public function test_an_mshwari_statement_row_is_detected_as_mpesa_not_mshwari(): void
    {
        $text = 'AB1JK5E2FQ 2026-09-07 16:11:46 M-Shwari Withdraw Completed 2,000.00 2,485.61';

        $this->assertSame(MessageProvider::MPESA, $this->detector->detect($text));
    }

    public function test_a_plain_statement_row_with_no_provider_keyword_is_detected_as_mpesa(): void
    {
        $text = 'AB1JK5E6YT 2026-09-07 16:44:41 Customer Transfer to - 0114***703 MERCY GICHARU Completed -1,800.00 685.61';

        $this->assertSame(MessageProvider::MPESA, $this->detector->detect($text));
    }

    public function test_a_bank_sms_is_detected_as_bank(): void
    {
        $text = 'Your account has been debited KES 2,000.00. Available balance is KES 15,000.00.';

        $this->assertSame(MessageProvider::BANK, $this->detector->detect($text));
    }

    public function test_unrelated_text_is_unknown(): void
    {
        $this->assertSame(MessageProvider::UNKNOWN, $this->detector->detect('Hey, are we still on for lunch tomorrow?'));
    }
}
