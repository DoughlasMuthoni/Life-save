<?php

namespace App\Domain\Ingestion\Services;

use App\Domain\Ingestion\Exceptions\UnprocessablePdfException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts the text layer from an uploaded M-Pesa statement PDF
 * (CLAUDE.md §7a). Text-layer extraction only — never OCR, never a
 * shelled-out binary (the production host has exec() disabled). The
 * returned text is handed to the exact same MpesaStatementParser /
 * ingestStatementBatch() pipeline that paste-in-text already uses; this
 * class's only job is turning a PDF into that same raw text.
 *
 * Real Safaricom statement PDFs position each table cell (receipt code,
 * timestamp, each wrapped Details line, status, amount, balance) as its
 * own separate text-showing command — smalot/pdfparser's getTextArray()
 * returns one array element per such command, not per visual line the
 * way `pdftotext -layout` did, and its own gap-based space heuristic
 * isn't reliable enough on this document to reconstruct row spacing.
 * Rather than depend on layout/whitespace heuristics at all, every
 * extracted cell is flattened into one space-joined stream per page and
 * then reflowed structurally: a receipt code followed by a timestamp
 * starts a row, and everything up to the row's amount+balance pair
 * (however many cells that spans) becomes that row's Details text. This
 * works regardless of how many cells a given PDF happened to split a row
 * into, including a row rendered as a single text-showing command.
 */
class PdfStatementTextExtractor
{
    private const ROW_PATTERN = '/(?<receipt>[A-Z0-9]{8,12})\s+(?<date>\d{4}-\d{2}-\d{2})\s+(?<time>\d{2}:\d{2}:\d{2})\s+(?<rest>.*?)\s+(?<amount>-?[\d,]+\.\d{2})\s+(?<balance>[\d,]+\.\d{2})(?=\s|$)/';

    public function extractText(string $filePath): string
    {
        try {
            $document = (new Parser)->parseFile($filePath);
        } catch (Throwable $e) {
            throw self::looksLikeEncryptionError($e->getMessage())
                ? UnprocessablePdfException::encrypted()
                : UnprocessablePdfException::notAPdf();
        }

        $cells = [];

        foreach ($document->getPages() as $page) {
            foreach ($page->getTextArray() as $cell) {
                $cell = trim(preg_replace('/\s+/', ' ', (string) $cell));

                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
        }

        $flattened = implode(' ', $cells);

        if ($flattened === '') {
            throw UnprocessablePdfException::noExtractableText();
        }

        $rows = $this->reflowStatementRows($flattened);

        // No row-shaped sequence found at all — this may be a real PDF
        // with real text that just isn't a statement export. Return the
        // flattened text anyway rather than claiming "no text": the
        // existing ingestStatementBatch() pipeline already produces a
        // specific, helpful "no statement rows were recognized" error
        // for exactly this case.
        return $rows === [] ? $flattened : implode("\n", $rows);
    }

    /**
     * @return string[] one reconstructed line per detected row, in the
     *                  same "receipt date time details... Completed
     *                  amount balance" shape MpesaStatementParser
     *                  already expects from a paste.
     */
    private function reflowStatementRows(string $flattened): array
    {
        preg_match_all(self::ROW_PATTERN, $flattened, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $m) => "{$m['receipt']} {$m['date']} {$m['time']} {$m['rest']} {$m['amount']} {$m['balance']}",
            $matches
        );
    }

    /**
     * smalot/pdfparser has no dedicated exception type for encryption —
     * it throws a generic \Exception with a message like "Secured pdf
     * file are currently not supported." whenever the trailer carries an
     * /Encrypt entry. Exposed as a pure, standalone function so this
     * mapping can be tested directly without needing to hand-craft an
     * actual encrypted PDF fixture.
     */
    public static function looksLikeEncryptionError(string $exceptionMessage): bool
    {
        return str_contains($exceptionMessage, 'ecured pdf') || str_contains($exceptionMessage, 'ncrypt');
    }
}
