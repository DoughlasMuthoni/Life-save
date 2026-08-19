<?php

namespace App\Domain\Ingestion\Parsers;

use App\Domain\Finance\Support\Money;
use App\Domain\Ingestion\DataTransferObjects\ParsedMessage;
use App\Domain\Ingestion\Enums\ExtractedTransactionType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Generic best-effort parser for the common "debited/credited ... balance
 * is" shape used by most Kenyan bank SMS alerts. Bank formats vary far
 * more than M-Pesa's, so this deliberately only handles the one pattern
 * that's fairly consistent across banks (CLAUDE.md V1 scope: "basic bank
 * SMS parsing where feasible") — anything else falls through to the AI
 * fallback rather than a parser guessing at an unfamiliar bank's format.
 */
class BankSmsParser
{
    public const VERSION = '1.0';

    public function parse(string $normalizedText): ?ParsedMessage
    {
        if (! preg_match('/\b(?<direction>debited|credited)\b/i', $normalizedText, $directionMatch)) {
            return null;
        }

        if (! preg_match('/(?:KES|Ksh|KSh)\s?(?<amount>[\d,]+\.\d{2})/i', $normalizedText, $amountMatch)) {
            return null;
        }

        $date = $this->extractDate($normalizedText);

        if ($date === null) {
            return null;
        }

        $direction = strtolower($directionMatch['direction']);
        $type = $direction === 'debited' ? ExtractedTransactionType::BANK_DEBIT : ExtractedTransactionType::BANK_CREDIT;

        $balanceMinor = null;
        if (preg_match('/(?:available|current)\s+balance\s+is\s+(?:KES|Ksh|KSh)\s?(?<balance>[\d,]+\.\d{2})/i', $normalizedText, $balanceMatch)) {
            $balanceMinor = Money::toMinorUnits($balanceMatch['balance']);
        }

        $reference = null;
        if (preg_match('/\bRef(?:erence)?[:\s]+(?<ref>[A-Z0-9]{6,})/i', $normalizedText, $refMatch)) {
            $reference = strtoupper($refMatch['ref']);
        }

        return new ParsedMessage(
            transactionType: $type,
            amountMinor: Money::toMinorUnits($amountMatch['amount']),
            feeMinor: 0,
            transactionTime: $date,
            externalTransactionId: $reference,
            counterparty: null,
            reportedBalanceMinor: $balanceMinor,
        );
    }

    private function extractDate(string $text): ?CarbonImmutable
    {
        // "12-06-2025" / "12/06/2025" / "12-06-25"
        if (preg_match('/\b(?<d>\d{1,2})[\/-](?<m>\d{1,2})[\/-](?<y>\d{2,4})\b/', $text, $m)) {
            $year = strlen($m['y']) === 2 ? '20'.$m['y'] : $m['y'];

            try {
                return CarbonImmutable::createFromFormat('j/n/Y', "{$m['d']}/{$m['m']}/{$year}")->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        // ISO-ish "2025-06-12"
        if (preg_match('/\b(?<y>\d{4})-(?<m>\d{1,2})-(?<d>\d{1,2})\b/', $text, $m)) {
            try {
                return CarbonImmutable::createFromFormat('Y-n-j', "{$m['y']}-{$m['m']}-{$m['d']}")->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
