<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Opening hours: one parser, read by the admin's validator and by the JSON-LD
 * emitter.
 *
 * ── WHY ONE PARSER AND NOT TWO ──────────────────────────────────────────────
 *
 * The standing failure on the settings screen is a control that saves a value
 * no reader can use: AdminController::SETTING_RULES carries the warning in as
 * many words, and this project has already shipped a field that saved nothing
 * while the endpoint answered "ok". Opening hours are the shape most likely to
 * repeat it, because they are the one business fact with a SYNTAX -- so the
 * box that accepts the text and the code that turns it into
 * `openingHoursSpecification` are the same function. If parse() refuses it,
 * the owner is told at the moment he types it; if parse() accepts it, the
 * emitter is reading the identical structure the validator approved. The two
 * cannot drift, because there is only one of them.
 *
 * ── THE FORMAT ──────────────────────────────────────────────────────────────
 *
 * One rule per line, which is what a person writes on a shop door:
 *
 *     Mon-Sat 10:00-22:00
 *     Sun 12:00-20:00
 *
 * Day tokens are the three-letter English abbreviations, alone
 * ("Sun"), in a range ("Mon-Thu") or in a comma list ("Mon,Wed,Fri"), and the
 * three forms combine ("Mon-Wed,Sat"). Case is not significant.
 *
 * A DAY THAT IS NOT LISTED IS CLOSED. That is schema.org's own rule for
 * `openingHoursSpecification` -- the specification enumerates when the place is
 * open, and silence about Friday means shut on Friday -- so there is no
 * "closed" keyword to learn and no way to say the same thing two ways.
 *
 * ── WHAT IS DELIBERATELY NOT REFUSED ────────────────────────────────────────
 *
 * `closes` EARLIER THAN `opens` IS LEGAL and means past midnight: "Thu
 * 20:00-02:00" is a Thursday evening that ends on Friday morning, which is an
 * ordinary thing for a shop in this city to do and exactly how schema.org
 * expects it to be written. Refusing it would be inventing a limit the
 * consumer does not have -- the mistake SETTING_RULES' own header warns
 * against. The one time-pair refused is opens == closes, which describes a
 * zero-length day and is always a typo.
 */
final class OpeningHours
{
    /** Canonical day names, Monday first, as schema.org spells them. */
    public const DAYS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * Parse the owner's text.
     *
     * Returns the error as a SENTENCE naming the offending line, because the
     * caller that reports it is a person looking at a textarea. A refusal is
     * always better than a coerced value here, for the reason checkSetting()
     * gives: the alternative is storing something that reads back as nothing.
     *
     * @return array{ok:bool, error:?string, spec:list<array<string,mixed>>}
     */
    public static function parse(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return ['ok' => true, 'error' => null, 'spec' => []];
        }

        // \R matches every line ending, so a value pasted from Windows or from
        // a Mac text editor is not one unparseable line.
        $lines = preg_split('/\R/u', $text) ?: [];

        // opens|closes => list of canonical day names, so two lines that name
        // the same window collapse into one specification below.
        $byWindow = [];

        // Which line first claimed a day. A day in two windows is not a
        // clever overlap, it is the owner editing one line and forgetting the
        // other, and schema.org would publish both as if the shop were open
        // twice.
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z,\- ]+?)\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $line, $m) !== 1) {
                return self::fail("“{$line}” is not a line like “Mon-Sat 10:00-22:00”.");
            }

            [, $dayPart, $oh, $om, $ch, $cm] = $m;

            $days = self::days($dayPart);

            if ($days === null) {
                return self::fail("“{$line}” names a day this does not recognise. Use Mon, Tue, Wed, Thu, Fri, Sat or Sun.");
            }

            $opens = self::time((int) $oh, (int) $om);
            $closes = self::time((int) $ch, (int) $cm);

            if ($opens === null || $closes === null) {
                return self::fail("“{$line}” has a time outside 00:00–23:59.");
            }

            if ($opens === $closes) {
                return self::fail("“{$line}” opens and closes at the same time.");
            }

            foreach ($days as $day) {
                if (isset($seen[$day])) {
                    return self::fail("{$day} is on more than one line. Each day can have one set of hours.");
                }

                $seen[$day] = true;
                $byWindow[$opens . '|' . $closes][] = $day;
            }
        }

        $spec = [];

        foreach ($byWindow as $window => $days) {
            [$opens, $closes] = explode('|', $window);

            $spec[] = [
                '@type' => 'OpeningHoursSpecification',
                // A single day stays a bare string rather than becoming a
                // one-element array: both are valid, and the simpler form is
                // what a shop open only on Sunday should read as.
                'dayOfWeek' => count($days) === 1 ? $days[0] : $days,
                'opens' => $opens,
                'closes' => $closes,
            ];
        }

        return ['ok' => true, 'error' => null, 'spec' => $spec];
    }

    /**
     * The specification, or null when there is nothing to say.
     *
     * Null and not an empty array, so the caller's `!== null` test is the whole
     * decision and an empty `openingHoursSpecification: []` -- which asserts a
     * shop that is never open -- cannot be emitted by accident.
     *
     * UNPARSEABLE TEXT EMITS NOTHING. It cannot normally be stored, because the
     * same parse() refuses it at the settings screen, but a row written before
     * this rule existed, or by a direct database edit, must not become a
     * half-read set of hours on every page of the site.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function spec(string $text): ?array
    {
        $parsed = self::parse($text);

        if (! $parsed['ok'] || $parsed['spec'] === []) {
            return null;
        }

        return $parsed['spec'];
    }

    /**
     * "Mon-Wed,Sat" => the canonical names, in week order.
     *
     * Week order rather than the order they were typed, so "Sat,Mon" and
     * "Mon,Sat" produce the same document. Returns null on any token that is
     * not a day, which the caller turns into the message naming the line.
     *
     * @return list<string>|null
     */
    private static function days(string $part): ?array
    {
        $out = [];

        foreach (explode(',', $part) as $token) {
            $token = strtolower(trim($token));

            if ($token === '') {
                return null;
            }

            if (str_contains($token, '-')) {
                $ends = array_map('trim', explode('-', $token));

                if (count($ends) !== 2) {
                    return null;
                }

                $from = array_search($ends[0], array_keys(self::DAYS), true);
                $to = array_search($ends[1], array_keys(self::DAYS), true);

                if ($from === false || $to === false) {
                    return null;
                }

                $keys = array_keys(self::DAYS);

                /*
                 * A range that wraps the week is a range, not a mistake:
                 * "Sat-Sun" is the weekend and "Fri-Mon" is a long one. Walking
                 * forward modulo seven reads both the same way round a shop
                 * door does, where "Thu-Tue" means everything but Wednesday.
                 */
                $steps = (($to - $from) + 7) % 7;

                for ($i = 0; $i <= $steps; $i++) {
                    $out[] = self::DAYS[$keys[($from + $i) % 7]];
                }

                continue;
            }

            if (! isset(self::DAYS[$token])) {
                return null;
            }

            $out[] = self::DAYS[$token];
        }

        // Week order, de-duplicated. array_values so the list stays a list --
        // a JSON object where an array belongs is the classic way a "list"
        // reaches a consumer as {"0":"Monday"}.
        $order = array_values(self::DAYS);

        $out = array_values(array_unique($out));

        usort($out, static fn (string $a, string $b): int => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $out === [] ? null : $out;
    }

    /** "9:05" => "09:05"; anything off the clock => null. */
    private static function time(int $hour, int $minute): ?string
    {
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /** @return array{ok:bool, error:?string, spec:list<array<string,mixed>>} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message, 'spec' => []];
    }
}
