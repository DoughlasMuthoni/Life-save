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
            str_contains($text, 'M-SHWARI') => MessageProvider::MSHWARI,
            str_contains($text, 'KCB M-PESA'), str_contains($text, 'KCB-MPESA') => MessageProvider::KCB_MPESA,
            str_contains($text, 'M-PESA') => MessageProvider::MPESA,
            preg_match('/\b(debited|credited|account balance)\b/i', $normalizedText) === 1 => MessageProvider::BANK,
            default => MessageProvider::UNKNOWN,
        };
    }
}
