<?php

declare(strict_types=1);

namespace App\Services\Import\Sources;

use App\Services\Import\RowRejected;

/**
 * A WooCommerce CSV export, read one row at a time.
 *
 * DELIBERATELY BORING, because the interesting failures in CSV import are all
 * about being lenient in the wrong place:
 *
 *  - A UTF-8 BOM. Excel and the WooCommerce exporter both write one. Left in
 *    place it becomes part of the FIRST HEADER NAME, so `id` arrives as
 *    "\u{feff}id", every lookup of `id` misses, and every row is rejected for a
 *    missing id that is plainly there in the file. Stripped here, once.
 *  - Header case and spacing. Woo's own product exporter writes "Regular
 *    price" and "In stock?"; a SQL export writes `regular_price`. Headers are
 *    folded to lowercase with runs of non-alphanumerics collapsed to `_`, so
 *    both arrive as `regular_price` and the importers can name one key. The
 *    ORIGINAL header is kept too, so a column whose real name matters can still
 *    be addressed.
 *  - A short or long row. A row with fewer cells than the header is a
 *    structural problem — usually an unescaped quote earlier in the file that
 *    has swallowed a line break — and the cells after the break are silently
 *    shifted into the wrong columns. Padding it quietly is how a phone number
 *    ends up in the country column. It is a rejection, raised as RowRejected so
 *    it lands in the report with its line number like any other refusal.
 *
 * Memory: fgetcsv one line at a time, never file() or str_getcsv on the whole
 * file. A full order export from a store with five years of history is hundreds
 * of megabytes and this has to run inside a shared host's memory_limit.
 *
 * The empty `escape` argument on both fgetcsv calls is deliberate and is not
 * boilerplate. PHP's historical default of `\` is a proprietary extension that
 * RFC 4180 does not have and no spreadsheet writes: with it, a product
 * description ending in a backslash before the closing quote escapes that
 * quote, the parser runs on into the next line, and the row comes back
 * misaligned. Passing '' selects standards-conforming parsing, and it is also
 * the default PHP 8.4 moves to — without it this line is a deprecation notice
 * on every row of a 400,000-row import.
 */
final class CsvRowSource implements RowSource
{
    private ?string $fingerprint = null;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
    ) {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new \RuntimeException('Cannot read '.$this->path);
        }
    }

    public function rows(): iterable
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open '.$this->path);
        }

        try {
            $header = fgetcsv($handle, 0, $this->delimiter, '"', '');

            if ($header === false || $header === [null]) {
                return;
            }

            $header = $this->normaliseHeader($header);
            $width = count($header);

            // Line 1 is the header, so the first data row is line 2 — which is
            // the number the owner's spreadsheet will show them.
            $line = 1;

            while (($cells = fgetcsv($handle, 0, $this->delimiter, '"', '')) !== false) {
                $line++;

                // fgetcsv yields [null] for a blank line. A trailing newline at
                // the end of the file is normal and is not a defect.
                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) !== $width) {
                    // Thrown rather than returned so it travels the same path
                    // as a validation refusal and appears in the same report.
                    throw RowRejected::because(
                        'line '.$line.' has '.count($cells).' cells but the header has '.$width
                        .' — the file is misaligned, most likely an unescaped quote earlier in it'
                    );
                }

                yield $line => array_combine($header, array_map(
                    static fn ($cell): string => is_string($cell) ? trim($cell) : '',
                    $cells,
                ));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * sha256 of the file's bytes.
     *
     * Content, not mtime or size: a re-export that happens to be the same
     * length is the case that would corrupt a resume, and hashing a few hundred
     * megabytes once at the start of a run that will take an hour costs
     * nothing worth counting.
     */
    public function fingerprint(): string
    {
        return $this->fingerprint ??= (string) hash_file('sha256', $this->path);
    }

    public function describe(): string
    {
        return $this->path;
    }

    /**
     * @param  list<string|null>  $header
     * @return list<string>
     */
    private function normaliseHeader(array $header): array
    {
        $out = [];

        foreach ($header as $i => $name) {
            $name = (string) $name;

            if ($i === 0) {
                // The BOM, which is only ever on the first cell of the first line.
                $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;
            }

            $key = mb_strtolower(trim($name));
            $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
            $key = trim($key, '_');

            if ($key === '') {
                $key = 'column_'.$i;
            }

            // Woo product exports repeat "Attribute N name" style headers and a
            // hand-built export can repeat a column outright. array_combine
            // would silently keep only the last; numbering the duplicates keeps
            // both addressable and keeps the row width honest.
            $base = $key;
            $n = 2;

            while (in_array($key, $out, true)) {
                $key = $base.'_'.$n++;
            }

            $out[] = $key;
        }

        return $out;
    }
}
