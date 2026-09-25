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

    /* ═══════════════════════════════════════════════════════════════════════
     * LANE M4 · the MailSettings corpus, and why it is a THIRD instrument
     *
     * Rounds 1 and 3 recorded a pure function: `cast($key, $raw)` and
     * `normalise($key, $value)` both answer from their arguments alone, so a
     * fixture could be produced with no database and no container.
     *
     * MailSettings answers nothing that way. `save()` WRITES — to `settings`
     * through SettingsService for fifteen keys and to the encrypted
     * `mail_credentials` row for the sixteenth — and `all()` reads back through
     * a cache. The behaviour this round is migrating is therefore the ROUND
     * TRIP, not a cast, and the only honest way to record it is to perform it:
     * plant, save, read back, and record what the three separate readers
     * (`all()`, the raw settings row, and the credential store's presence flag)
     * each say afterwards.
     *
     * THE THREE READERS ARE RECORDED SEPARATELY ON PURPOSE. Round 2's named
     * blocker is that they DISAGREE — `all()` hands back the transport LABEL
     * where the row holds the key — and a fixture that recorded only one of
     * them could not see the disagreement move. `raw` is read straight off the
     * table, past every cache, so "the stored bytes did not change" is a
     * measurement rather than an inference; that is the assertion the whole
     * round rests on, because a migration that rewrote a stored transport key
     * is the shape that stops a shop sending.
     *
     * THE PASSWORD IS RECORDED AS A PRESENCE FLAG AND NEVER AS A VALUE. The
     * fixture is a tracked file in a public repository. `secret` fields record
     * `has:true` / `has:false`, which is exactly the fact the screen is allowed
     * to know, and the canary test in MailSecretSurfaceTest proves the fixture
     * itself carries no password.
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * What is posted at each type of box on Store → Mail.
     *
     * The text arm is round 3's, plus the three inputs that only matter here: a
     * well-formed address, a malformed one (two fields refuse it), and a string
     * past every cap this screen declares.
     *
     * @return array<string, list<mixed>>
     */
    public static function mailCorpus(): array
    {
        return [
            'choice' => ['', null, 'nonsense', ' ', 0, '__KEYS__', '__LABELS__', 'SMTP', ' smtp '],
            'text' => [
                'abc', '', '  sp  ', null, '0', "line\nbreak",
                '<b>x</b>', '<script>alert(1)</script>',
                'ops@example.com', ' ops@example.com ', 'not-an-address', 'a@b',
                str_repeat('x', 300), str_repeat('y', 600),
            ],
            /*
             * The write states a `secret` has, and they are FOUR, not two:
             * absent (never posted — covered by the payload-shaped tests, not
             * here), blank (the box renders empty and blank means unchanged),
             * the literal "-" (forget it), and a real value. The padded " - "
             * is here because trim() runs before the comparison and a future
             * cast that trims differently would move it.
             */
            'secret' => ['s3cret-one', '', '   ', '-', ' - ', '--', null, 'x', str_repeat('p', 300)],
        ];
    }

    /**
     * One line per recorded call:
     *   key|type|json(input)|all=<var_export>|raw=<var_export>|has=<bool>
     *
     * `$save`, `$readAll`, `$readRaw` and `$hasSecret` are passed in rather than
     * resolved here so this file depends on no container: the test that calls it
     * owns the app, and this owns the corpus and the line format.
     *
     * @param  callable(array<string,mixed>):void  $save     reset, then save this payload
     * @param  callable():array<string,mixed>      $readAll  MailSettings::all()
     * @param  callable(string):mixed              $readRaw  the settings row, past every cache
     * @param  callable():bool                     $hasSecret
     * @param  array<string, array<int|string, mixed>>  $schema
     * @return list<string>
     */
    public static function mailRows(array $schema, callable $save, callable $readAll, callable $readRaw, callable $hasSecret, array $choices = []): array
    {
        $corpus = self::mailCorpus();
        $rows = [];

        foreach ($schema as $key => $def) {
            /*
             * BOTH SHAPES, for round 3's reason. This is recorded against the
             * parent revision, where MailSettings carries a positional
             * `[type, label, help]` list — note that `help` is in slot 2 here
             * and in slot 3 of every other schema in this app, which is exactly
             * why the constant has to be converted rather than handed to
             * ModuleSchema::field() positionally — and replayed against this
             * one, where it carries the associative form.
             */
            $type = array_is_list($def) ? (string) ($def[0] ?? '?') : (string) ($def['type'] ?? '?');
            $type = match ($type) {
                'select' => 'choice',
                'textarea' => 'text',
                default => $type,
            };

            $inputs = [];

            foreach ($corpus[$type] ?? [null] as $input) {
                if ($input === '__KEYS__' || $input === '__LABELS__') {
                    $set = $choices[$key] ?? [];
                    foreach (($input === '__KEYS__' ? array_keys($set) : array_values($set)) as $one) {
                        $inputs[] = (string) $one;
                    }

                    continue;
                }

                $inputs[] = $input;
            }

            foreach ($inputs as $input) {
                $save([$key => $input]);

                $all = $readAll();

                $rows[] = sprintf(
                    '%s|%s|%s|all=%s|raw=%s|has=%s',
                    $key,
                    $type,
                    self::mailInput($type, $input),
                    strtr(var_export($all[$key] ?? '__ABSENT__', true), ["\n" => '\n', "\r" => '\r']),
                    strtr(var_export($readRaw($key), true), ["\n" => '\n', "\r" => '\r']),
                    $hasSecret() ? 'true' : 'false',
                );
            }
        }

        return $rows;
    }

    /**
     * How an input is written into the fixture line.
     *
     * ── A SECRET'S INPUT IS DESCRIBED, NOT QUOTED ───────────────────────────
     *
     * This fixture is a tracked file in a public repository, and the first
     * version of this recorder wrote `json_encode($input)` for every type — so
     * the password corpus went into git verbatim. They were invented strings
     * and nothing was disclosed, but the shape is a recorder that commits
     * whatever it is driven with, and the day somebody re-records it against a
     * value copied off a real screen the fix is rewriting history.
     *
     * MailSecretSurfaceTest is what found it, by scanning the fixture for the
     * corpus's own password inputs — a test written to check that this could
     * not happen, run against a file where it had.
     *
     * The label has to keep one call distinguishable from another, because the
     * identity of a recorded line is key|type|input and two lines sharing one
     * would compare against each other. Length plus a short digest does that
     * without carrying a byte of the value: `-` and `x` are both one character
     * and get different digests.
     */
    private static function mailInput(string $type, mixed $input): string
    {
        if ($type !== 'secret') {
            return (string) json_encode($input);
        }

        if ($input === null) {
            return '<secret:null>';
        }

        $value = (string) $input;

        return sprintf('<secret:len=%d:%s>', mb_strlen($value), substr(sha1($value), 0, 8));
    }
}
