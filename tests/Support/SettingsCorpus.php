<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\CacheSettings;
use App\Support\ReviewBadgeSettings;
use App\Support\ReviewSettings;

/**
 * The adversarial corpus for the three `App\Support\*Settings` classes, and the
 * driver that turns it into one recorded line per call.
 *
 * ── WHY THIS IS A SECOND CORPUS AND NOT A ROW IN THE FIRST ──────────────────
 *
 * Lane M recorded 4,653 calls of thirteen modules' own `cast($key, $raw)` into
 * tests/Fixtures/module-cast-baseline.txt. These three classes do not have a
 * `cast()`: they have a **static** `normalise($key, $value)` over a positional
 * `[type, default, min, max]` schema whose types are `enum`/`int` rather than
 * `select`/`range`. Reflecting `cast` over them finds nothing, so they were
 * outside that instrument entirely — which is exactly why round 2 could name
 * them as a blocker and go no further.
 *
 * So they get their own recording, in the same shape and read by the same kind
 * of test: driven over every input the first corpus uses for the equivalent
 * type, **plus the inputs that only matter here**:
 *
 *   'null'   the literal four-letter string. This is the whole third boolean
 *            dialect: these three fold it to FALSE and ModuleSchema::castBool()
 *            answers TRUE. The first corpus does not contain it, so the first
 *            fixture could never have caught the difference — a gap this round
 *            closes rather than works around.
 *   'NULL'   the same word from a case-insensitive source. Both classes lower
 *            before comparing; a fold that matched only lower case would be a
 *            silent narrowing.
 *   'true'   the other half of a JSON round trip, which nothing folds and which
 *            is true under every dialect. Recorded so that stays true.
 *   '  off ' padded, because these three trim and one of the dialects does not
 *            reach its word list without the trim.
 *
 * Every answer is recorded with var_export(), so `true` and `'1'` and `1` are
 * three distinct lines rather than one.
 */
final class SettingsCorpus
{
    /** @return array<string, list<mixed>> type => inputs */
    public static function corpus(): array
    {
        return [
            // The eleven the first corpus uses, plus the five that only matter
            // for a dialect that reads words.
            'bool' => [
                true, false, '1', '0', '', 'on', 'off', 'no', 1, 0, null,
                'null', 'NULL', 'true', 'false', 'FALSE', '  off ', 'yes', '2', 0.0, '0.0',
            ],
            'int' => [5, '5', 0, -10, 99999, 'abc', '', null, '3.7', true, false, '007', ' 12 ', -1, 1000000],
            'enum' => ['__FIRST__', '__LAST__', 'nope', '', null, 0, 'NEWEST'],
            'text' => ['abc', '', '  sp  ', str_repeat('x', 300), null, '<b>x</b>', '<script>alert(1)</script>', '0', "line\nbreak"],
            'colour' => ['#E23A4E', 'e23a4e', '#abc', 'abc', '', 'zz', null, '#e23a4e', '#ABC', ' #E23A4E ', 'red;position:fixed', '#E23A4E;x', '#1234567'],
        ];
    }

    /** The three classes, and the schema each one declares. */
    public static function classes(): array
    {
        return [
            'review_settings' => [ReviewSettings::class, ReviewSettings::SCHEMA, ReviewSettings::SORTS],
            'cache_settings' => [CacheSettings::class, CacheSettings::SCHEMA, []],
            'review_badge' => [ReviewBadgeSettings::class, ReviewBadgeSettings::SCHEMA, ReviewBadgeSettings::STYLES],
        ];
    }

    /**
     * One line per call: module|key|type|json(input)|var_export(answer).
     *
     * @return list<string>
     */
    public static function rows(): array
    {
        $corpus = self::corpus();
        $rows = [];

        foreach (self::classes() as $name => [$class, $schema, $options]) {
            foreach ($schema as $key => $def) {
                /*
                 * BOTH SHAPES, ON PURPOSE. This corpus is recorded against the
                 * PARENT revision, where these three classes carry their own
                 * `[type, default, min, max]` list, and replayed against this
                 * one, where they carry ModuleSchema's associative form. A
                 * driver that could only read one of the two could not compare
                 * them, which is the whole job.
                 *
                 * The type is written to the line in its CANONICAL spelling, so
                 * `enum` becoming `select` renames nothing in the fixture: the
                 * identity of a recorded call is module|key|type|input, and a
                 * rename there would read as 347 calls moving.
                 */
                $type = array_is_list($def) ? (string) ($def[0] ?? '?') : (string) ($def['type'] ?? '?');
                $type = match ($type) {
                    'select' => 'enum',
                    'range' => 'int',
                    'textarea' => 'text',
                    default => $type,
                };

                foreach ($corpus[$type] ?? [null] as $input) {
                    $real = $input;

                    if ($input === '__FIRST__') {
                        $real = (string) array_key_first($options);
                    }

                    if ($input === '__LAST__') {
                        $real = (string) array_key_last($options);
                    }

                    try {
                        $answer = var_export($class::normalise((string) $key, $real), true);
                    } catch (\Throwable $e) {
                        $answer = 'THROW:'.get_class($e);
                    }

                    /*
                     * The answer's own newlines escaped, because the fixture is
                     * ONE LINE PER CALL and a stored value may legally contain
                     * a line break — `sr_empty_text` is free text. Recorded
                     * unescaped, "line\nbreak" wrote two file lines, every row
                     * after it compared against its neighbour, and 239 of 347
                     * calls read as moved when two had. A fixture format that
                     * the data can break is a fixture that reports a shift as a
                     * regression.
                     */
                    $rows[] = sprintf(
                        '%s|%s|%s|%s|%s',
                        $name, $key, $type, json_encode($real), strtr($answer, ["\n" => '\n', "\r" => '\r'])
                    );
                }
            }
        }

        return $rows;
    }
}
