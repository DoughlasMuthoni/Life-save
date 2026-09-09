<?php

namespace Tests\Unit\Domain\Ingestion;

use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use App\Domain\Ingestion\Parsers\MpesaStatementParser;
use App\Domain\Ingestion\Services\TextNormalizer;
use Tests\TestCase;

class MpesaStatementParserTest extends TestCase
{
    private MpesaStatementParser $parser;

    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MpesaStatementParser;
        $this->normalizer = new TextNormalizer;
    }

    private function parseRow(string $rawRow)
    {
        return $this->parser->parse($this->normalizer->normalize($rawRow));
    }

    public function test_it_splits_a_multi_row_statement_into_one_chunk_per_row(): void
    {
        $text = <<<'TXT'
            Receipt No.        Completion Time                  Details               Transaction Status     Paid In           Withdrawn              Balance
            AB1JK5FYNQ           2026-09-07 22:47:17   Customer Bundle Purchase to       Completed                                         -20.00                  527.61
                                                       244441SAFARICOM POSTPAID
                                                       BUNDLES by - 254707***913
            AB1JK5F09M           2026-09-07 19:05:22   Customer Payment to Small         Completed                                         -45.00                  547.61
                                                       Business to - 0726***312
                                                       Florence Nyabuto
            TXT;

        $rows = $this->parser->splitRows($text);

        $this->assertCount(2, $rows);
        $this->assertStringStartsWith('AB1JK5FYNQ', $rows[0]);
        $this->assertStringContainsString('254707***913', $rows[0]);
        $this->assertStringStartsWith('AB1JK5F09M', $rows[1]);
        $this->assertStringContainsString('Florence Nyabuto', $rows[1]);
    }

    public function test_it_skips_page_headers_and_footers_between_rows(): void
    {
        $text = <<<'TXT'
            Page 1 of 31
            M-PESA STATEMENT
            Customer Name:                                  Jane Doe
            AB1JK5FYNQ           2026-09-07 22:47:17   Customer Bundle Purchase to       Completed                                         -20.00                  527.61
                                                       244441SAFARICOM POSTPAID BUNDLES

            Page 2 of 31
            Receipt No.        Completion Time                  Details
            AB1JK5F09M           2026-09-07 19:05:22   Merchant Payment to 6621093 -     Completed                                        -150.00                1,179.61
                                                       TERESIA ROBI
            TXT;

        $rows = $this->parser->splitRows($text);

        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('Page 1 of 31', $rows[0]);
        $this->assertStringNotContainsString('Customer Name:', $rows[0]);
    }

    public function test_it_parses_a_customer_transfer_row(): void
    {
        $result = $this->parseRow(
            "AB1JK5E6YT           2026-09-07 16:44:41   Customer Transfer to -            Completed                                      -1,800.00                  685.61\n".
            '                                           0114***703 MERCY GICHARU'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::SEND_MONEY, $result->transactionType);
        $this->assertSame(180000, $result->amountMinor);
        $this->assertSame(68561, $result->reportedBalanceMinor);
        $this->assertSame('AB1JK5E6YT', $result->externalTransactionId);
        $this->assertStringContainsString('MERCY GICHARU', $result->counterparty);
        $this->assertSame('2026-09-07 16:44:41', $result->transactionTime->format('Y-m-d H:i:s'));
    }

    public function test_it_parses_a_funds_received_row_as_income(): void
    {
        $result = $this->parseRow(
            'AB1895GAZY           2026-09-07 11:23:37   Funds received from -             Completed                        70.00                                  1,199.61'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::RECEIVE_MONEY, $result->transactionType);
        $this->assertSame(7000, $result->amountMinor);
        $this->assertSame(119961, $result->reportedBalanceMinor);
    }

    public function test_it_parses_a_merchant_payment_row_as_buy_goods(): void
    {
        $result = $this->parseRow(
            "AB1JK5B3LU           2026-09-06 20:38:59   Merchant Payment to 6621093 -     Completed                                        -150.00                1,179.61\n".
            '                                           TERESIA ROBI'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::BUY_GOODS, $result->transactionType);
        $this->assertSame(15000, $result->amountMinor);
        $this->assertStringContainsString('TERESIA ROBI', $result->counterparty);
    }

    public function test_it_parses_a_pay_bill_row(): void
    {
        $result = $this->parseRow(
            "AB1JK5ESPR           2026-09-07 18:42:53   Pay Bill Online to 247247 -       Completed                                         -20.00                  612.61\n".
            '                                           Equity Paybill Account Acc.'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::PAYBILL, $result->transactionType);
        $this->assertSame(2000, $result->amountMinor);
    }

    public function test_it_parses_a_withdrawal_row_as_transfer_shape(): void
    {
        $result = $this->parseRow(
            'AB1JK5XXXX           2026-09-07 12:00:00   Customer Withdrawal At Agent -     Completed                                       -500.00                1,000.00'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::WITHDRAWAL, $result->transactionType);
        $this->assertSame(50000, $result->amountMinor);
    }

    public function test_it_parses_a_bundle_purchase_row(): void
    {
        $result = $this->parseRow(
            "AB1JK5FYNQ           2026-09-07 22:47:17   Customer Bundle Purchase to       Completed                                         -20.00                  527.61\n".
            '                                           244441SAFARICOM POSTPAID BUNDLES'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::BUNDLE_PURCHASE, $result->transactionType);
        $this->assertSame(2000, $result->amountMinor);
    }

    public function test_it_parses_mshwari_withdraw_as_transfer_in(): void
    {
        $result = $this->parseRow(
            'AB1JK5E2FQ           2026-09-07 16:11:46   M-Shwari Withdraw                 Completed                   2,000.00                                    2,485.61'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::MSHWARI_TRANSFER_IN, $result->transactionType);
        $this->assertSame(200000, $result->amountMinor);
    }

    public function test_it_parses_mshwari_deposit_as_transfer_out(): void
    {
        $result = $this->parseRow(
            'AB1JK576D3         2026-09-05 20:56:49   M-Shwari Deposit                  Completed                                        -5,000.00            5,896.61'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::MSHWARI_TRANSFER_OUT, $result->transactionType);
        $this->assertSame(500000, $result->amountMinor);
    }

    public function test_a_charge_row_is_parsed_as_a_statement_fee_with_a_suffixed_id(): void
    {
        $result = $this->parseRow(
            'AB1JK5E6YT           2026-09-07 16:44:41   Customer Transfer of Funds        Completed                                         -33.00                  652.61'."\n".
            '                                           Charge'
        );

        $this->assertNotNull($result);
        $this->assertSame(ExtractedTransactionType::STATEMENT_FEE, $result->transactionType);
        $this->assertSame(3300, $result->amountMinor);
        // Suffixed distinctly from its parent transaction's plain
        // "AB1JK5E6YT" so duplicate detection (provider + external id)
        // never confuses a fee row for a duplicate of its own parent.
        $this->assertSame('AB1JK5E6YT-FEE', $result->externalTransactionId);
    }

    public function test_a_reversal_row_is_not_recognized_this_pass(): void
    {
        $result = $this->parseRow(
            'AB1JK4YAAA           2026-09-05 10:00:00   Send Money Reversal via API       Completed                        500.00                                  9,000.00'
        );

        $this->assertNull($result);
    }

    public function test_a_fuliza_flavored_row_is_not_recognized_this_pass(): void
    {
        $result = $this->parseRow(
            'AB1JK4YBBB           2026-09-04 18:23:07   Merchant Payment Fuliza M-Pesa Completed                                              -23.00                 0.00'
        );

        $this->assertNull($result);
    }

    public function test_a_non_completed_row_is_not_parsed(): void
    {
        $result = $this->parseRow(
            'AB1JK4YCCC           2026-09-04 18:23:07   Customer Transfer to - 0114***703 X    Failed                                         -23.00                 0.00'
        );

        $this->assertNull($result);
    }
}
