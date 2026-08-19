<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Services\AiExtractionValidator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiExtractionValidatorTest extends TestCase
{
    private AiExtractionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AiExtractionValidator;
    }

    private function extraction(array $overrides = []): AiExtractedTransaction
    {
        return new AiExtractedTransaction(
            isFinancialTransaction: true,
            transactionType: $overrides['transactionType'] ?? ExtractedTransactionType::SEND_MONEY,
            amountMinor: $overrides['amountMinor'] ?? 150000,
            feeMinor: $overrides['feeMinor'] ?? 0,
            transactionTime: $overrides['transactionTime'] ?? CarbonImmutable::create(2025, 5, 31, 13, 41),
            externalTransactionId: $overrides['externalTransactionId'] ?? 'QGH7XI9K2L',
            counterparty: $overrides['counterparty'] ?? 'JOHN MWANGI (0712345678)',
            reportedBalanceMinor: $overrides['reportedBalanceMinor'] ?? 345000,
            confidence: $overrides['confidence'] ?? 0.95,
        );
    }

    public function test_a_correct_extraction_verifies_every_field(): void
    {
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $result = $this->validator->verify($this->extraction(), $text);

        $this->assertTrue($result['amount_verified']);
        $this->assertTrue($result['counterparty_verified']);
        $this->assertTrue($result['balance_verified']);
        $this->assertTrue($result['transaction_id_verified']);
        $this->assertTrue($result['date_verified']);
        $this->assertTrue($this->validator->passesMinimumBar($result));
    }

    public function test_a_fabricated_amount_fails_verification(): void
    {
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $result = $this->validator->verify($this->extraction(['amountMinor' => 999999]), $text);

        $this->assertFalse($result['amount_verified']);
        $this->assertFalse($this->validator->passesMinimumBar($result));
    }

    public function test_a_fabricated_counterparty_does_not_block_the_minimum_bar(): void
    {
        // Counterparty is a secondary field: flagged as unverified, but not
        // a hard block — CLAUDE.md asks for per-field validation states,
        // not an all-or-nothing gate.
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $result = $this->validator->verify($this->extraction(['counterparty' => 'SOMEONE ELSE ENTIRELY']), $text);

        $this->assertFalse($result['counterparty_verified']);
        $this->assertTrue($this->validator->passesMinimumBar($result));
    }

    public function test_a_wrong_date_fails_the_minimum_bar(): void
    {
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $result = $this->validator->verify($this->extraction(['transactionTime' => CarbonImmutable::create(2025, 7, 4)]), $text);

        $this->assertFalse($result['date_verified']);
        $this->assertFalse($this->validator->passesMinimumBar($result));
    }

    public function test_no_fee_is_trivially_verified(): void
    {
        $text = 'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. New M-PESA balance is Ksh3,450.00.';

        $result = $this->validator->verify($this->extraction(['feeMinor' => 0]), $text);

        $this->assertTrue($result['fee_verified']);
    }
}
