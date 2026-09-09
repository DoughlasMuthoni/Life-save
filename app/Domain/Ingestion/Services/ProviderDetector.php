<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\Ingestion\Enums\MessageProvider;

/**
 * Best-effort labeling of which provider a pasted message looks like it's
 * from. Only MPESA has a working parser in this phase — the others are
 * detected purely so the UI can say "M-Shwari parsing isn't built yet"
 * instead of a generic "unknown", not because anything acts on them yet.
 */
class ProviderDetector
{
    public function detect(string $normalizedText): MessageProvider
    {
        $text = strtoupper($normalizedText);

        return match (true) {
            // Checked first, deliberately: a statement row like "M-Shwari
            // Withdraw ... Completed 2,000.00 2,485.61" contains the
            // literal text "M-SHWARI" and would otherwise get classified
            // as MessageProvider::MSHWARI below — which has no
            // statement-row parser wired to it — before ever being
            // recognized as the M-Pesa statement row it actually is. A
            // genuine M-Shwari/KCB SMS never starts with a receipt-code-
            // plus-timestamp, so this can't misclassify real SMS text.
            $this->looksLikeMpesaStatementRow($normalizedText) => MessageProvider::MPESA,
            str_contains($text, 'M-SHWARI') => MessageProvider::MSHWARI,
            str_contains($text, 'KCB M-PESA'), str_contains($text, 'KCB-MPESA') => MessageProvider::KCB_MPESA,
            str_contains($text, 'M-PESA') => MessageProvider::MPESA,
            preg_match('/\b(debited|credited|account balance)\b/i', $normalizedText) === 1 => MessageProvider::BANK,
            default => MessageProvider::UNKNOWN,
        };
    }

    /**
     * A single row from a pasted M-Pesa statement (not an SMS) doesn't
     * necessarily contain the literal string "M-PESA" — e.g. "Customer
     * Transfer to - 0114***703 MERCY GICHARU Completed -1,800.00 685.61"
     * — but it does reliably start with a Safaricom receipt code followed
     * by an ISO-ish completion timestamp, which no SMS text looks like.
     */
    private function looksLikeMpesaStatementRow(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{8,12}\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', trim($text));
    }
}
