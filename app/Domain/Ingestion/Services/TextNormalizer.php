<?php

namespace App\Domain\Ingestion\Services;

/**
 * Normalizes pasted SMS text for parsing and hashing. Never touches
 * raw_text itself — normalized_text is a derived, disposable copy
 * (CLAUDE.md §7: the original raw text must be preserved exactly).
 */
class TextNormalizer
{
    public function normalize(string $rawText): string
    {
        $text = trim($rawText);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Collapse runs of horizontal whitespace, but keep line breaks —
        // some messages are only unambiguous with them (e.g. mockup-style
        // pastes with a leading timestamp line).
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);

        return trim($text);
    }

    /**
     * Splits a textarea's worth of pasted content into individual messages.
     * The UI documents "separate multiple messages with a blank line", so a
     * blank line is the split point — checked against the raw text, before
     * normalize() collapses blank lines away.
     */
    public function splitMessages(string $rawText): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($rawText));
        $chunks = preg_split('/\n\s*\n/', $text);

        return array_values(array_filter(array_map('trim', $chunks), fn ($chunk) => $chunk !== ''));
    }
}
