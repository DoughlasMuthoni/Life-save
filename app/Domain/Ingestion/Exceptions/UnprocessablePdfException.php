<?php

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/**
 * Thrown by PdfStatementTextExtractor when a PDF can't be turned into
 * usable statement text (CLAUDE.md §7a). `$userMessage` is written to be
 * shown directly to the user — it never contains file paths or internal
 * details.
 */
class UnprocessablePdfException extends RuntimeException
{
    private function __construct(public readonly string $userMessage)
    {
        parent::__construct($userMessage);
    }

    public static function notAPdf(): self
    {
        return new self("This doesn't look like a valid PDF file. Please upload the original M-Pesa statement PDF.");
    }

    public static function encrypted(): self
    {
        return new self('This PDF is password-protected. Please upload an unlocked copy (open it, enter the password once, then save/export an unencrypted copy) and try again.');
    }

    public static function noExtractableText(): self
    {
        return new self('No text could be extracted from this PDF — it may be a scanned image rather than a text-based export. Statement PDF upload only supports PDFs with a real text layer; try pasting the statement text instead.');
    }
}
