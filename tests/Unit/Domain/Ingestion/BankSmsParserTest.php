<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Parsers\BankSmsParser;
use App\Domain\Ingestion\Services\TextNormalizer;
use Tests\TestCase;

class BankSmsParserTest extends TestCase
{
    private BankSmsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BankSmsParser;
    }

    private function normalize(string $text): string
    {
        return (new TextNormalizer)->normalize($text);
    }

    public function test_it_parses_a_debit_alert(): void
    {
        $text = $this->normalize(
            'Your account XXXX1234 has been debited with KES 5,000.00 on 12-06-2025. Available balance is KES 45,000.00. Ref: BNK2025061212345'
        );

        $result = $this->parser->parse($text);

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::BANK_DEBIT, $result->transactionType);
        $this->assertSame(500000, $result->amountMinor);
        $this->assertSame(4500000, $result->reportedBalanceMinor);
        $this->assertSame('BNK2025061212345', $result->externalTransactionId);
        $this->assertSame('2025-06-12', $result->transactionTime->format('Y-m-d'));
    }

    public function test_it_parses_a_credit_alert(): void
    {
        $text = $this->normalize('Your account has been credited with KES 10,000.00 on 2025-06-15. Available balance is KES 55,000.00.');

        $result = $this->parser->parse($text);

        $this->assertSame(ExtractedTransactionType::BANK_CREDIT, $result->transactionType);
        $this->assertSame(1000000, $result->amountMinor);
        $this->assertSame('2025-06-15', $result->transactionTime->format('Y-m-d'));
    }

    public function test_it_returns_null_without_a_debit_or_credit_keyword(): void
    {
        $this->assertNull($this->parser->parse($this->normalize('Your statement for June is ready. KES 5,000.00 is your closing balance.')));
    }

    public function test_it_returns_null_without_a_parseable_date(): void
    {
        $this->assertNull($this->parser->parse($this->normalize('Your account has been debited with KES 5,000.00. Available balance is KES 45,000.00.')));
    }
}
