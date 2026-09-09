<?php

namespace Tests\Support;

/**
 * Builds a tiny, fully-synthetic PDF at test time rather than committing
 * a binary .pdf into the repo — this repo's .gitignore blocks *.pdf
 * entirely (protection against a real financial statement ever being
 * committed; see CLAUDE.md §7a), and generating the fixture on demand
 * keeps that rule simple and exception-free rather than needing a
 * carve-out for test fixtures.
 *
 * The generated PDF mirrors how real Safaricom statement PDFs are
 * actually structured: each table cell (receipt, timestamp, each
 * wrapped Details line, status, amount, balance) is its own separate
 * positioned text-showing command, not one line of visible text — which
 * is exactly what PdfStatementTextExtractor's row-reflow logic needs to
 * be exercised against. All data below is fabricated.
 */
class PdfFixtureBuilder
{
    /**
     * @return string path to a freshly generated PDF file under the
     *                system temp directory (callers should unlink it
     *                when done)
     */
    public static function sampleStatementPdf(): string
    {
        return self::buildPdf([
            ['text' => 'Receipt No.  Completion Time  Details  Transaction Status  Paid In  Withdrawn  Balance', 'y' => 750],
            ['text' => 'TE1TESTAB1', 'y' => 735],
            ['text' => '2026-01-05 10:15:00', 'y' => 735],
            ['text' => 'Customer Transfer to -', 'y' => 720],
            ['text' => '0700***000 JANE SAMPLE', 'y' => 705],
            ['text' => 'Completed', 'y' => 690],
            ['text' => '-100.00', 'y' => 690],
            ['text' => '400.00', 'y' => 690],
        ]);
    }

    /**
     * @param  array<int, array{text: string, y: int}>  $cells
     */
    private static function buildPdf(array $cells): string
    {
        $contentLines = [];

        foreach ($cells as $i => $cell) {
            $x = 20 + ($i * 5);
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cell['text']);
            $contentLines[] = "BT /F1 8 Tf {$x} {$cell['y']} Td ({$escaped}) Tj ET";
        }

        $stream = implode("\n", $contentLines)."\n";

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[5] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($objects as $num => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        $path = tempnam(sys_get_temp_dir(), 'lifesave-test-statement-').'.pdf';
        file_put_contents($path, $pdf);

        return $path;
    }
}
