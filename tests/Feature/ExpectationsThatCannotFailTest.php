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
