<?php

namespace App\Domain\Ingestion\Parsers;

use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\DataTransferObjects\ParsedMessage;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;

/**
 * Deterministic parser for one row of a pasted M-Pesa statement (the
 * "Detailed Statement" table from Safaricom's official statement export —
 * copy-pasted text only, never a file upload; CLAUDE.md §7/§19/§20
 * prohibit document/PDF upload for financial data, full stop).
 *
 * A statement row looks nothing like an SMS: "Receipt No. | Completion
 * Time | Details | Status | Paid In | Withdrawn | Balance", with the
 * Details column often wrapping onto several continuation lines below
 * the row that has the actual numbers. splitRows() groups a raw pasted
 * statement block into one text chunk per row; parse() then classifies
 * one row's Details text against the shapes this first pass covers.
 *
 * Deliberately scoped to the common, high-volume shapes only (as agreed
 * with the user) — reversals ("Send Money Reversal via API", "Pay
 * Merchant Reversal From") and the Fuliza-flavored variants that show up
 * in statement text ("Merchant Payment Fuliza M-Pesa", "OD Loan
 * Repayment... Fuliza II Overdraw", etc.) are NOT handled here and
 * deliberately fall through to AI fallback / needs-review, same as any
 * other unrecognized message.
 */
class MpesaStatementParser
{
    public const VERSION = '1.0';

    /**
     * Groups a raw pasted statement block into one text chunk per
     * transaction row (the row's first line plus any wrapped Details
     * continuation lines), skipping headers/footers/page-break noise
     * that a multi-page PDF-to-text paste inevitably carries along.
     *
     * @return string[]
     */
    public function splitRows(string $rawText): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($rawText));
        $lines = explode("\n", $text);

        $rows = [];
        $current = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^[A-Z0-9]{8,12}\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $trimmed)) {
                if ($current !== []) {
                    $rows[] = implode("\n", $current);
                }
                $current = [$line];

                continue;
            }

            if ($current === [] || $trimmed === '' || $this->looksLikePageNoise($trimmed)) {
                continue;
            }

            $current[] = $line;
        }

        if ($current !== []) {
            $rows[] = implode("\n", $current);
        }

        return $rows;
    }

    public function parse(string $normalizedRowText): ?ParsedMessage
    {
        $lines = explode("\n", $normalizedRowText);
        $firstLine = array_shift($lines);

        if (! preg_match(
            '/^(?<receipt>[A-Z0-9]{8,12})\s+(?<date>\d{4}-\d{2}-\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<detailsStart>.*?)\s+Completed\s+(?<amount>-?[\d,]+\.\d{2})\s+(?<balance>[\d,]+\.\d{2})\s*$/i',
            trim($firstLine),
            $m
        )) {
            return null;
        }

        $details = trim($m['detailsStart'].' '.implode(' ', array_map('trim', $lines)));
        $details = preg_replace('/\s+/', ' ', $details);

        $isWithdrawal = str_starts_with($m['amount'], '-');
        $amountMinor = Money::toMinorUnits(ltrim($m['amount'], '-'));

        $classified = $this->classify($details, $isWithdrawal);

        if ($classified === null) {
            return null;
        }

        [$type, $counterparty] = $classified;

        $transactionTime = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$m['date']} {$m['time']}");

        // A "...Charge" row shares its parent transaction's receipt
        // number in real statements — using it verbatim as
        // externalTransactionId would make duplicate detection think the
        // fee row is a duplicate of its own parent. Suffixed distinctly
        // instead; still traceable back to the same receipt, still
        // caught if the exact same fee row is genuinely re-pasted.
        $externalId = $type === ExtractedTransactionType::STATEMENT_FEE
            ? $m['receipt'].'-FEE'
            : $m['receipt'];

        return new ParsedMessage(
            transactionType: $type,
            amountMinor: $amountMinor,
            feeMinor: 0,
            transactionTime: $transactionTime,
            externalTransactionId: $externalId,
            counterparty: $counterparty,
            reportedBalanceMinor: Money::toMinorUnits($m['balance']),
        );
    }

    /**
     * @return array{0: ExtractedTransactionType, 1: ?string}|null
     */
    private function classify(string $details, bool $isWithdrawal): ?array
    {
        if (preg_match('/Charge$/i', $details)) {
            return [ExtractedTransactionType::STATEMENT_FEE, null];
        }

        if (preg_match('/^Customer Bundle Purchase\b\s*(?:to|with)?\s*[-:]?\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::BUNDLE_PURCHASE, $this->cleanCounterparty($m[1]) ?: 'Airtime/Bundles'];
        }

        if (preg_match('/^(?:Customer Withdrawal At Agent|Customer Withdraw)\b\s*[-:]?\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::WITHDRAWAL, $this->cleanCounterparty($m[1]) ?: 'Agent'];
        }

        if (preg_match('/^M-Shwari Withdraw\b/i', $details)) {
            return [ExtractedTransactionType::MSHWARI_TRANSFER_IN, 'M-Shwari'];
        }

        if (preg_match('/^M-Shwari Deposit\b/i', $details)) {
            return [ExtractedTransactionType::MSHWARI_TRANSFER_OUT, 'M-Shwari'];
        }

        if (preg_match('/^Customer Transfer to\b\s*[-:]?\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::SEND_MONEY, $this->cleanCounterparty($m[1])];
        }

        if (preg_match('/^Funds received from\b\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::RECEIVE_MONEY, $this->cleanCounterparty($m[1])];
        }

        if (preg_match('/^(?:Customer Payment to Small Business to|Merchant Payment to|Merchant Payment Online to)\b\s*[-:]?\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::BUY_GOODS, $this->cleanCounterparty($m[1])];
        }

        if (preg_match('/^(?:Pay Bill Online to|Pay Bill to)\b\s*[-:]?\s*(.*)$/i', $details, $m)) {
            return [ExtractedTransactionType::PAYBILL, $this->cleanCounterparty($m[1])];
        }

        // Rows that got this far didn't match any shape this first pass
        // covers (reversals, Fuliza-flavored variants, agent deposits,
        // etc.) — fall through to AI fallback / needs-review rather than
        // guessing, same as any other unrecognized message.
        return null;
    }

    private function cleanCounterparty(string $text): ?string
    {
        $text = trim($text, " \t-:");
        $text = preg_replace('/\s+/', ' ', $text);

        return $text !== '' ? $text : null;
    }

    private function looksLikePageNoise(string $trimmed): bool
    {
        return (bool) preg_match(
            '/^(Page \d+ of \d+|Receipt No\.|TRANSACTION TYPE|Disclaimer:|Statement Verification|For self-help|M-PESA STATEMENT|SUMMARY|DETAILED STATEMENT|TOTAL:|Customer Name:|Mobile Number:|Email Address:|Statement Period:|Request Date:)/i',
            $trimmed
        );
    }
}
