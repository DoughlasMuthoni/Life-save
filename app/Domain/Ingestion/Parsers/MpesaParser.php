<?php

namespace App\Domain\Ingestion\Parsers;

use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\DataTransferObjects\ParsedMessage;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;

/**
 * Deterministic parser for standard Safaricom M-Pesa confirmation SMS.
 * Handles the five transaction shapes CLAUDE.md's V1 scope calls for:
 * send money, receive money, buy goods (till), paybill, and withdrawal.
 *
 * Anything that doesn't match one of these patterns returns null — it is
 * NOT this parser's job to guess. An unrecognized M-Pesa-looking message
 * is left for a human (or, later, the AI fallback in Phase 4) rather than
 * silently becoming a financial record (CLAUDE.md §7).
 */
class MpesaParser
{
    public const VERSION = '1.0';

    public function parse(string $normalizedText): ?ParsedMessage
    {
        return $this->parsePaybill($normalizedText)
            ?? $this->parseBuyGoods($normalizedText)
            ?? $this->parseSendMoney($normalizedText)
            ?? $this->parseReceiveMoney($normalizedText)
            ?? $this->parseWithdrawal($normalizedText)
            ?? $this->parseFuliza($normalizedText);
    }

    private function parseSendMoney(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/Ksh(?<amount>[\d,]+\.\d{2})\s+sent to\s+(?<name>[A-Za-z .\'-]+?)\s+(?<phone>0\d{9})\s+on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s?[AP]M)\.?\s*New M-PESA balance is Ksh(?<balance>[\d,]+\.\d{2})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::SEND_MONEY,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: $this->extractFeeMinor($text),
            transactionTime: $this->parseDateTime($m['date'], $m['time']),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: trim($m['name']).' ('.$m['phone'].')',
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    private function parseReceiveMoney(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/You have received\s+Ksh(?<amount>[\d,]+\.\d{2})\s+from\s+(?<name>[A-Za-z .\'-]+?)\s+(?<phone>0\d{9})\s+on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s?[AP]M)\.?\s*New M-PESA balance is Ksh(?<balance>[\d,]+\.\d{2})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::RECEIVE_MONEY,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: 0,
            transactionTime: $this->parseDateTime($m['date'], $m['time']),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: trim($m['name']).' ('.$m['phone'].')',
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    private function parsePaybill(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/Ksh(?<amount>[\d,]+\.\d{2})\s+paid to\s+(?<merchant>[A-Za-z0-9 .\'&-]+?)\s+for account\s+(?<account>[A-Za-z0-9]+)\s+on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s?[AP]M)\.?\s*New M-PESA balance is Ksh(?<balance>[\d,]+\.\d{2})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::PAYBILL,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: $this->extractFeeMinor($text),
            transactionTime: $this->parseDateTime($m['date'], $m['time']),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: trim($m['merchant']).' (Acc: '.$m['account'].')',
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    private function parseBuyGoods(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/Ksh(?<amount>[\d,]+\.\d{2})\s+paid to\s+(?<merchant>[A-Za-z0-9 .\'&-]+?)\.?\s+on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s?[AP]M)\.?\s*New M-PESA balance is Ksh(?<balance>[\d,]+\.\d{2})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::BUY_GOODS,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: $this->extractFeeMinor($text),
            transactionTime: $this->parseDateTime($m['date'], $m['time']),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: trim($m['merchant']),
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    private function parseWithdrawal(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/Ksh(?<amount>[\d,]+\.\d{2})\s+withdrawn from\s+(?<agent>[A-Za-z0-9 .\'&-]+?)\s+on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s?[AP]M)\.?\s*New M-PESA balance is Ksh(?<balance>[\d,]+\.\d{2})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::WITHDRAWAL,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: $this->extractFeeMinor($text),
            transactionTime: $this->parseDateTime($m['date'], $m['time']),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: trim($m['agent']),
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    /**
     * A Fuliza drawdown notification. Unlike every other pattern this
     * parser recognizes, the SMS carries no transaction date/time — only
     * a future repayment due date — so transactionTime falls back to
     * "now" (when the message is parsed). In practice that's fine, since
     * Fuliza SMS are pasted in shortly after arriving, but it's a real
     * gap worth knowing about rather than a silent guess dressed up as
     * fact. The due date is folded into `counterparty` (there's no
     * dedicated field for it) so it's still visible on the proposal card
     * and in the transaction list.
     */
    private function parseFuliza(string $text): ?ParsedMessage
    {
        if (! preg_match(
            '/Fuliza M-PESA amount is\s+Ksh\s*(?<amount>[\d,]+\.\d{2})\.\s*Access Fee charged\s+Ksh\s*(?<fee>[\d,]+\.\d{2})\.\s*Total Fuliza M-PESA outstanding amount is\s+Ksh\s*(?<outstanding>[\d,]+\.\d{2})\s+due on\s+(?<duedate>\d{1,2}\/\d{1,2}\/\d{2,4})/i',
            $text,
            $m
        )) {
            return null;
        }

        return new ParsedMessage(
            transactionType: ExtractedTransactionType::FULIZA_DRAWDOWN,
            amountMinor: Money::toMinorUnits($m['amount']),
            feeMinor: Money::toMinorUnits($m['fee']),
            transactionTime: CarbonImmutable::now(),
            externalTransactionId: $this->extractTransactionCode($text),
            counterparty: 'Fuliza (due '.$m['duedate'].')',
            reportedBalanceMinor: Money::toMinorUnits($m['outstanding']),
        );
    }

    private function extractFeeMinor(string $text): int
    {
        if (preg_match('/Transaction cost,?\s*Ksh(?<fee>[\d,]+\.\d{2})/i', $text, $m)) {
            return Money::toMinorUnits($m['fee']);
        }

        return 0;
    }

    private function extractTransactionCode(string $text): ?string
    {
        if (preg_match('/^(?<code>[A-Z0-9]{8,12})\s+Confirmed\./', $text, $m)) {
            return $m['code'];
        }

        return null;
    }

    private function parseDateTime(string $date, string $time): CarbonImmutable
    {
        $date = str_replace(['\\', ' '], ['/', ''], $date);
        $time = strtoupper(str_replace(' ', '', $time));

        $dateParts = explode('/', $date);
        $year = $dateParts[2];

        if (strlen($year) === 2) {
            $dateParts[2] = '20'.$year;
        }

        $normalizedDate = implode('/', $dateParts);

        return CarbonImmutable::createFromFormat('j/n/Y g:iA', "{$normalizedDate} {$time}");
    }
}
