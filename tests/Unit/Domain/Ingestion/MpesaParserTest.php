<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Parsers\MpesaParser;
use App\Domain\Ingestion\Services\TextNormalizer;
use Tests\TestCase;

class MpesaParserTest extends TestCase
{
    private MpesaParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MpesaParser;
    }

    private function normalize(string $text): string
    {
        return (new TextNormalizer)->normalize($text);
    }

    public function test_it_parses_a_send_money_message(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2L Confirmed. Ksh1,500.00 sent to JOHN MWANGI 0712345678 on 31/5/25 at 1:41 PM. '.
            'New M-PESA balance is Ksh3,450.00. Transaction cost, Ksh0.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::SEND_MONEY, $result->transactionType);
        $this->assertSame(150000, $result->amountMinor);
        $this->assertSame(0, $result->feeMinor);
        $this->assertSame('QGH7XI9K2L', $result->externalTransactionId);
        $this->assertSame('JOHN MWANGI (0712345678)', $result->counterparty);
        $this->assertSame(345000, $result->reportedBalanceMinor);
        $this->assertSame('2025-05-31 13:41:00', $result->transactionTime->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_a_send_money_message_with_a_fee(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2M Confirmed. Ksh500.00 sent to JANE DOE 0722111222 on 2/6/25 at 9:05 AM. '.
            'New M-PESA balance is Ksh1,000.00. Transaction cost, Ksh11.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertSame(50000, $result->amountMinor);
        $this->assertSame(1100, $result->feeMinor);
    }

    public function test_it_parses_a_receive_money_message(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2N Confirmed. You have received Ksh2,400.00 from MARY WANJIKU 0718765432 on 31/5/25 at 5:07 PM. '.
            'New M-PESA balance is Ksh5,400.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertSame(ExtractedTransactionType::RECEIVE_MONEY, $result->transactionType);
        $this->assertSame(240000, $result->amountMinor);
        $this->assertSame(0, $result->feeMinor);
        $this->assertSame('MARY WANJIKU (0718765432)', $result->counterparty);
    }

    public function test_it_parses_a_buy_goods_message(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2P Confirmed. Ksh450.00 paid to QUICKMART JUJA. on 31/5/25 at 3:22 PM. '.
            'New M-PESA balance is Ksh3,000.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertSame(ExtractedTransactionType::BUY_GOODS, $result->transactionType);
        $this->assertSame(45000, $result->amountMinor);
        $this->assertSame('QUICKMART JUJA', $result->counterparty);
    }

    public function test_it_parses_a_paybill_message_and_does_not_confuse_it_with_buy_goods(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2Q Confirmed. Ksh1,200.00 paid to KPLC PREPAID for account 987654321 on 31/5/25 at 7:15 PM. '.
            'New M-PESA balance is Ksh4,200.00. Transaction cost, Ksh22.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertSame(ExtractedTransactionType::PAYBILL, $result->transactionType);
        $this->assertSame(120000, $result->amountMinor);
        $this->assertSame(2200, $result->feeMinor);
        $this->assertSame('KPLC PREPAID (Acc: 987654321)', $result->counterparty);
    }

    public function test_it_parses_a_withdrawal_message(): void
    {
        $text = $this->normalize(
            'QGH7XI9K2R Confirmed. Ksh2,000.00 withdrawn from 123456 - AGENT NAME on 30/5/25 at 6:45 PM. '.
            'New M-PESA balance is Ksh1,800.00. Transaction cost, Ksh28.00.'
        );

        $result = $this->parser->parse($text);

        $this->assertSame(ExtractedTransactionType::WITHDRAWAL, $result->transactionType);
        $this->assertSame(200000, $result->amountMinor);
        $this->assertSame(2800, $result->feeMinor);
    }

    public function test_it_returns_null_for_an_unrecognized_message(): void
    {
        $text = $this->normalize('Your loan of Ksh5,000.00 has been approved. Visit the app for details.');

        $this->assertNull($this->parser->parse($text));
    }

    public function test_it_returns_null_for_unrelated_text(): void
    {
        $this->assertNull($this->parser->parse('Hey, are we still on for lunch tomorrow?'));
    }
}
