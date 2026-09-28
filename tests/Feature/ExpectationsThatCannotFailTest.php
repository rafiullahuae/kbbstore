<?php

declare(strict_types=1);

/**
 * THE SUITE'S GUARD ON ITS OWN GUARDS.
 *
 * A test that cannot fail is worse than no test, because it is counted as
 * coverage. This file walks every file under tests/ looking for the one shape
 * that has produced eleven of them on this project so far, and proves the trap
 * is real rather than asserting that it is.
 *
 * THE TRAP. Pest's toContain() is VARIADIC — `toContain(mixed ...$needles)` —
 * so what reads as a failure message is a SECOND NEEDLE:
 *
 *     expect($body)->not->toContain('sk_live_CANARY', '/api/settings');
 *
 * The positive expectation is "the body contains BOTH 'sk_live_CANARY' AND
 * '/api/settings'". It fails as soon as either is missing, and `not` passes on
 * that failure — so the guard passes over a body that is serving the canary.
 * The test below measures exactly that instead of describing it.
 *
 * toContainEqual() has the same signature and the same hole.
 *
 * ── AND toHaveKey() HAS A DIFFERENT SIGNATURE WITH THE SAME OUTCOME — S8 ────
 *
 * `toHaveKey(string $key, mixed $value = null)`. The second argument is an
 * EXPECTED VALUE, so:
 *
 *     expect($vars)->not->toHaveKey('--mh-logo', 'an untouched header moves');
 *
 * asserts "$vars does NOT have --mh-logo set to the string 'an untouched header
 * moves'", which is true whatever $vars contains. **It cannot fail.** That is
 * not a hypothetical: it is MobileHeaderControlsTest, where a block claiming
 * "not one size property is emitted" was vacuous for its whole life while being
 * counted as coverage, and it is on record twice more in
 * docs/SEO-ARABIC-PARITY.md.
 *
 * The sweep below did not cover it, because toHaveKey is not variadic and the
 * original sweep was written about variadics. The distinction does not matter to
 * the defect -- both shapes take a message and silently reinterpret it -- so
 * there is now a second sweep for it, and the repair is the same:
 *
 *     expect(array_key_exists($key, $array))->toBeFalse($message);
 *
 * THE POSITIVE FORM IS LEFT ALONE AND THAT IS DELIBERATE.
 * `expect($a)->toHaveKey('k', 3)` is legitimate Pest: it asserts the key exists
 * AND holds 3. Written by mistake with a message it FAILS LOUDLY rather than
 * passing vacuously, so it is a bug that reports itself and needs no sweep.
 * Only the negated form is silent, which is why only the negated form is swept.
 *
 * THE REPAIR is mechanical and reads no worse:
 *
 *     expect(str_contains($haystack, $needle))->toBeFalse($message);
 *     expect(in_array($needle, $haystack, true))->toBeFalse($message);
 *
 * WHAT THIS SWEEP FOUND when it was written: ten live occurrences, across ten
 * files owned by eight different lanes. Two of them were watching live
 * secrets — a mail token in the delivery log, and gateway credentials in a
 * page's <head>. Every one was proved vacuous by injecting its own canary into
 * its own haystack and watching it stay green, and proved to bite afterwards
 * by doing it again. One more expectation, `expect(true)->toBeTrue()` in
 * CashOnDeliveryTest, stated its conclusion rather than asking anything and
 * was replaced with Http::assertNothingSent().
 *
 * WHY token_get_all() AND NOT A REGEX. A regex cannot count arguments across a
 * nested call — `->not->toContain(Money::plain($a, 0), $message)` has three
 * commas and two arguments — and cannot tell a comma inside a string from a
 * comma between arguments. It also must not read the sample in this file's own
 * docblock as a finding. Tokenising costs nothing and answers both.
 *
 * WHY NOT nikic/php-parser. It is in vendor/, but only as a transitive
 * dependency of the framework. A guard that stops running when an unrelated
 * package drops it is the same failure this file exists to prevent.
 */

/** Every PHP file in the suite. */
function suiteFiles(): array
{
    $out = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

/**
 * Every `->not-> <matcher> (` call in one file, with the number of arguments
 * it was given.
 *
 * @return array<int, array{line:int, matcher:string, args:int}>
 */
function negatedMatcherCalls(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));

    $found = [];
    $count = count($tokens);

    for ($i = 0; $i + 4 < $count; $i++) {
        // -> not -> matcher (
        if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR)) {
            continue;
        }
        if (! (is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING && $tokens[$i + 1][1] === 'not')) {
            continue;
        }
        if (! (is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_OBJECT_OPERATOR)) {
            continue;
        }
        if (! (is_array($tokens[$i + 3]) && $tokens[$i + 3][0] === T_STRING)) {
            continue;
        }
        if ($tokens[$i + 4] !== '(') {
            continue;
        }

        $matcher = $tokens[$i + 3][1];
        $line = $tokens[$i + 3][2];

        // Count TOP-LEVEL commas from the opening bracket to its partner.
        $depth = 0;
        $args = 0;
        $sawAnything = false;

        for ($j = $i + 4; $j < $count; $j++) {
            $t = $tokens[$j];
            $text = is_array($t) ? $t[1] : $t;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            $sawAnything = true;

            if ($depth === 1 && $text === ',') {
                $args++;
            }
        }

        $found[] = ['line' => $line, 'matcher' => $matcher, 'args' => $sawAnything ? $args + 1 : 0];
    }

    return $found;
}

it('proves the trap it is guarding against, rather than describing it', function () {
    // The positive expectation cannot succeed, so `not` succeeds — over a
    // haystack that is serving the needle. This is the whole bug, in one line.
    $raw = '{"probe":"sk_live_CANARY"}';

    expect($raw)->not->toContain('sk_live_CANARY', '/api/settings');

    // …while the honest form of the same question goes red. Asked through a
    // closure so this test can assert the failure instead of suffering it.
    expect(fn () => expect(str_contains($raw, 'sk_live_CANARY'))->toBeFalse('the canary leaked'))
        ->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);

    // And the one-needle form of the original is honest too.
    expect(fn () => expect($raw)->not->toContain('sk_live_CANARY'))
        ->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);
});

it('has a suite to walk and a tokeniser that can see into it', function () {
    // Guards the sweep below: a broken walker would report nothing and pass.
    $files = suiteFiles();

    expect(count($files))->toBeGreaterThan(300);

    $calls = negatedMatcherCalls(file_get_contents(__FILE__));

    // This file's own proof above is the sample. If the tokeniser cannot find
    // that, it cannot find anything.
    $samples = array_filter($calls, fn ($c) => $c['matcher'] === 'toContain' && $c['args'] === 2);
    expect($samples)->toHaveCount(1);

    $single = array_filter($calls, fn ($c) => $c['matcher'] === 'toContain' && $c['args'] === 1);
    expect($single)->not->toBeEmpty();
});

it('proves the toHaveKey trap too, rather than describing it', function () {
    /*
     * The same demonstration as above, for the other shape. The array HAS the
     * key -- so the honest assertion must go red -- and the message form stays
     * green, because the key does not hold the message as its value.
     */
    $vars = ['--mh-logo' => '22px'];

    expect($vars)->not->toHaveKey('--mh-logo', 'an untouched shop\'s header moves');

    // The honest form of the same question, which does go red.
    expect(fn () => expect(array_key_exists('--mh-logo', $vars))->toBeFalse('the header moved'))
        ->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);

    // And so does the one-argument form of the original.
    expect(fn () => expect($vars)->not->toHaveKey('--mh-logo'))
        ->toThrow(\PHPUnit\Framework\ExpectationFailedException::class);
});

it('gives no negated toHaveKey a failure message, anywhere in the suite', function () {
    /*
     * THE SECOND SWEEP — Lane S8. `toHaveKey`'s second argument is an expected
     * VALUE, so `->not->toHaveKey($key, $message)` asserts something that is
     * true whatever the array holds and cannot fail. The variadic sweep below
     * does not see it, because toHaveKey is not variadic.
     *
     * MUTATION NOTE (run, red): change any `expect(array_key_exists($k, $a))
     * ->toBeFalse($msg)` in the suite back to `expect($a)->not->toHaveKey($k,
     * $msg)` and this names the file and the line.
     */
    $offenders = [];
    $checked = 0;

    foreach (suiteFiles() as $path) {
        // This file's own proof above holds the sample on purpose.
        if ($path === __FILE__) {
            continue;
        }

        foreach (negatedMatcherCalls(file_get_contents($path)) as $call) {
            if ($call['matcher'] !== 'toHaveKey') {
                continue;
            }

            $checked++;

            if ($call['args'] > 1) {
                $offenders[] = sprintf(
                    '%s:%d  ->not->toHaveKey() with %d arguments — the second is an expected VALUE, '
                    . 'not a message, so this expectation cannot fail. Use '
                    . 'expect(array_key_exists($key, $array))->toBeFalse($message).',
                    str_replace(base_path() . '/', '', $path),
                    $call['line'],
                    $call['args']
                );
            }
        }
    }

    /*
     * No floor on $checked here, unlike the variadic sweep. A suite with zero
     * negated toHaveKey calls left is the GOAL of this sweep rather than a sign
     * it is broken -- every one of them reads better as array_key_exists -- so a
     * `toBeGreaterThan` would turn success into failure. The tokeniser is proved
     * by the test above it, which is where that guard belongs.
     */
    expect($checked)->toBeGreaterThanOrEqual(0);
    expect($offenders)->toBe([], "\n" . implode("\n", $offenders) . "\n");
});

it('gives no variadic matcher a failure message, anywhere in the suite', function () {
    // toContain() and toContainEqual() are the only variadic matchers Pest
    // has; both are checked, and a third would be added here.
    $variadic = ['toContain', 'toContainEqual'];

    $offenders = [];
    $checked = 0;

    foreach (suiteFiles() as $path) {
        // This file's docblock and its own proof hold the sample on purpose.
        if ($path === __FILE__) {
            continue;
        }

        foreach (negatedMatcherCalls(file_get_contents($path)) as $call) {
            if (! in_array($call['matcher'], $variadic, true)) {
                continue;
            }

            $checked++;

            if ($call['args'] > 1) {
                $offenders[] = sprintf(
                    '%s:%d  ->not->%s() with %d arguments — the second is a NEEDLE, not a message, '
                    . 'so this expectation cannot fail. Use '
                    . 'expect(str_contains($haystack, $needle))->toBeFalse($message).',
                    str_replace(base_path() . '/', '', $path),
                    $call['line'],
                    $call['matcher'],
                    $call['args']
                );
            }
        }
    }

    expect($checked)->toBeGreaterThan(50);
    expect($offenders)->toBe([], "\n" . implode("\n", $offenders) . "\n");
});

/**
 * The POSITIVE half of the same hole, and its calls are parsed here because the
 * walker above only follows `->not->`.
 *
 * Returns every `->toContain(...)` / `->toContainEqual(...)` that is NOT
 * negated, with the source text of its LAST top-level argument.
 *
 * @return list<array{line:int, matcher:string, args:int, last:string}>
 */
function positiveContainCalls(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));

    $found = [];
    $count = count($tokens);

    for ($i = 0; $i + 2 < $count; $i++) {
        if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_OBJECT_OPERATOR)) {
            continue;
        }
        if (! (is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING)) {
            continue;
        }

        $matcher = $tokens[$i + 1][1];

        if (! in_array($matcher, ['toContain', 'toContainEqual'], true) || $tokens[$i + 2] !== '(') {
            continue;
        }

        // Negated calls belong to the walker above; skip `->not->toContain(`.
        if ($i >= 2 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_STRING
            && $tokens[$i - 1][1] === 'not') {
            continue;
        }

        $depth = 0;
        $args = 0;
        $sawAnything = false;
        $last = '';

        for ($j = $i + 2; $j < $count; $j++) {
            $t = $tokens[$j];
            $text = is_array($t) ? $t[1] : $t;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                if ($depth > 1) { $last .= $text; }

                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                $last .= $text;

                continue;
            }

            $sawAnything = true;

            if ($depth === 1 && $text === ',') {
                $args++;
                $last = '';

                continue;
            }

            $last .= $text;
        }

        $found[] = [
            'line' => $tokens[$i + 1][2],
            'matcher' => $matcher,
            'args' => $sawAnything ? $args + 1 : 0,
            'last' => $last,
        ];
    }

    return $found;
}

it('finds a message that was handed to toContain as a second needle', function () {
    /*
     * ── THE OTHER HALF OF THE TRAP, AND IT BIT THREE TIMES IN ONE DAY ───────
     *
     * The sweep above catches `->not->toContain($needle, $message)`, which can
     * never fail. The POSITIVE form is the mirror image: `toContain()` is
     * variadic, so a message passed as the second argument becomes a SECOND
     * NEEDLE and the assertion looks for the sentence itself inside the
     * haystack. It therefore ALWAYS fails — loudly, and blaming exactly the
     * thing that has just been fixed.
     *
     * On 28 September 2026, in one day:
     *
     *   - GeWpExporterTest's new plugin-version pin went red with
     *     "CHANGELOG.md does not say what 1.5.0 changed" reported as a missing
     *     substring, against a changelog that said it.
     *   - SetRoutesWiredTest's deep-link pin stayed red AFTER the wiring was
     *     done, telling the integrator to add a TITLES entry that was already
     *     there.
     *   - and UgcLikeApiTest's own comment had already written the trap down,
     *     which is how both were recognised rather than debugged.
     *
     * A wiring pin that fails after you wire it is worse than no pin: it trains
     * people to distrust the message. Hence this.
     *
     * THE RULE, and it is deliberately narrow. A real multi-needle call passes
     * TOKENS — `toContain('Cart', 'Checkout')`. A message is PROSE. So: two or
     * more arguments, and the last one a plain string literal holding a space
     * and at least 25 characters. Anything built from a variable, a function
     * call or an interpolation is left alone, because that is a needle
     * somebody computed.
     *
     * MUTATION NOTE, RUN: put the message back as a second argument in
     * SetRoutesWiredTest and this names that file and line.
     */
    $offences = [];
    $checked = 0;

    foreach (suiteFiles() as $path) {
        $relative = ltrim(str_replace(base_path(), '', $path), '/');

        // This file demonstrates the trap on purpose, above.
        if ($relative === 'tests/Feature/ExpectationsThatCannotFailTest.php') {
            continue;
        }

        foreach (positiveContainCalls((string) file_get_contents($path)) as $call) {
            if ($call['args'] < 2) {
                continue;
            }

            $checked++;
            $last = $call['last'];

            // A plain single-quoted or double-quoted literal, nothing else.
            if (preg_match('/^([\'"])(.*)\\1$/s', $last, $m) !== 1) {
                continue;
            }

            $text = $m[2];

            if (strlen($text) < 25 || ! str_contains($text, ' ')) {
                continue;
            }

            $offences[] = sprintf(
                '%s:%d passes prose as the last argument to %s(), which is variadic — '
                . 'that sentence is being searched for as a NEEDLE, so the assertion can only fail. '
                . 'Use expect(str_contains($haystack, $needle))->toBeTrue($message).',
                $relative,
                $call['line'],
                $call['matcher']
            );
        }
    }

    expect($checked)->toBeGreaterThan(0,
        'the parser found no multi-argument toContain() anywhere, so it is not looking at anything');

    expect($offences)->toBe([]);
});
