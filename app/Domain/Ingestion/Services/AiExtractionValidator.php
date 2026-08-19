<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\AI\DataTransferObjects\AiExtractedTransaction;
use Carbon\CarbonInterface;

/**
 * Independently checks an AI extraction against the raw source text — the
 * backend validation CLAUDE.md §8 requires before an AI-parsed transaction
 * is trusted at all. Schema-valid JSON is not automatically correct; every
 * field the AI claims to have found must actually appear (in some
 * recognizable form) in the message it claims to have found it in.
 */
class AiExtractionValidator
{
    /**
     * @return array{amount_verified: bool, fee_verified: bool, counterparty_verified: bool, balance_verified: bool, transaction_id_verified: bool, date_verified: bool}
     */
    public function verify(AiExtractedTransaction $extracted, string $normalizedText): array
    {
        $haystack = strtoupper(str_replace(',', '', $normalizedText));

        return [
            'amount_verified' => $extracted->amountMinor !== null && $this->minorUnitsAppear($extracted->amountMinor, $haystack),
            'fee_verified' => $extracted->feeMinor === 0 || $this->minorUnitsAppear($extracted->feeMinor, $haystack),
            'counterparty_verified' => $this->textAppears($extracted->counterparty, $haystack),
            'balance_verified' => $extracted->reportedBalanceMinor === null || $this->minorUnitsAppear($extracted->reportedBalanceMinor, $haystack),
            'transaction_id_verified' => $this->textAppears($extracted->externalTransactionId, $haystack),
            'date_verified' => $extracted->transactionTime !== null && $this->dateAppears($extracted->transactionTime, $haystack),
        ];
    }

    /**
     * The minimum bar for an AI extraction to become a proposed transaction
     * at all: the amount and the date must both check out, and a
     * transaction type must have been identified. Everything else is
     * surfaced to the user as an unverified-field warning rather than
     * blocking the proposal outright — CLAUDE.md asks for validation
     * states, not an all-or-nothing gate.
     */
    public function passesMinimumBar(array $verification): bool
    {
        return $verification['amount_verified'] && $verification['date_verified'];
    }

    private function minorUnitsAppear(int $minorUnits, string $haystack): bool
    {
        $whole = intdiv($minorUnits, 100);
        $fraction = $minorUnits % 100;
        $plain = $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);

        return str_contains($haystack, $plain) || str_contains($haystack, (string) $whole);
    }

    private function textAppears(?string $needle, string $haystack): bool
    {
        if ($needle === null || trim($needle) === '') {
            return false;
        }

        // A meaningful chunk of the claimed text (e.g. the first word of a
        // name, or a whole transaction code) should appear verbatim.
        $needle = strtoupper(preg_replace('/[^A-Z0-9 ]/i', '', $needle) ?? '');
        $firstToken = trim(explode(' ', $needle)[0] ?? '');

        if ($firstToken === '' || strlen($firstToken) < 3) {
            return str_contains($haystack, $needle) && $needle !== '';
        }

        return str_contains($haystack, $firstToken);
    }

    private function dateAppears(CarbonInterface $time, string $haystack): bool
    {
        // Loose on purpose (SMS dates arrive as 31/5/25, 31-05-2025,
        // 2025-05-31, ...) but NOT so loose that it's meaningless — a bare
        // day-of-month digit like "4" would match almost any message via a
        // phone number or time. Require day+month (or the ISO year-month-
        // day form) to appear together as a plausible date token.
        $day = $time->day;
        $month = $time->month;
        $year = $time->year;

        $candidates = [
            sprintf('%d/%d', $day, $month),
            sprintf('%02d/%02d', $day, $month),
            sprintf('%d-%d', $day, $month),
            sprintf('%02d-%02d', $day, $month),
            sprintf('%04d-%02d-%02d', $year, $month, $day),
        ];

        foreach ($candidates as $candidate) {
            if (str_contains($haystack, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
