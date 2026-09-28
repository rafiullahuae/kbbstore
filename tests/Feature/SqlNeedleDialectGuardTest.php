<?php

declare(strict_types=1);

/**
 * =============================================================================
 * A GUARD THAT MATCHES SQL BY ITS SQLITE SPELLING GUARDS NOTHING ON MYSQL
 * =============================================================================
 *
 * ── THE DEFECT, IN THE SUITE RATHER THAN ON THE SHOP ────────────────────────
 *
 * Illuminate quotes identifiers per driver. The same query is logged as
 *
 *     select "id" from "products" where "status" = ?        (sqlite)
 *     select `id` from `products` where `status` = ?        (mysql)
 *
 * The default lane is SQLite, so every matcher in this suite was written
 * against the first spelling. Under `-c phpunit-mysql.xml` — the engine the
 * shop actually runs — those needles match NOTHING, and what that costs
 * depends on which way the assertion points. A POSITIVE assertion fails
 * loudly, which is the lucky case. A NEGATIVE one, or a comparison of two
 * counts, passes VACUOUSLY.
 *
 * Three of them were found on 28 September 2026, all three green on both
 * engines and all three meaningless on one:
 *
 *   - CartVariantWasPriceTest counted `from "products"` into $one and $five and
 *     asserted `expect($five)->toBe($one)`. On MySQL both were 0, so the
 *     struck-price N+1 guard compared nothing with nothing — it would have
 *     passed with the cart reading `products` once per line.
 *   - SeoLegacyAddressesLandTest counted `from "redirects"` and asserted
 *     `toBe(0)`. On MySQL it was 0 whatever CheckRedirects did, on every one
 *     of the three warm pages.
 *   - ...and the same file's warm-up counter beside it.
 *
 * `SqlShape::portable()` already existed for exactly this — it rewrites
 * backticks to double quotes so a needle keeps its delimiters — and two files
 * already used it. Nothing made the other three adopt it. This is that thing.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────
 *
 * A test file that spells a table name the SQLite way in a matcher must show
 * it knows the other engine exists, by ONE of:
 *
 *   - calling `SqlShape::portable()` somewhere in the file (it normalises the
 *     statement before the needle ever sees it), or
 *   - carrying the backticked spelling of that same identifier, which is how
 *     ShopSearchJoinTest, OrderProductPickerTest, ApiProductIndexCostTest and
 *     ReviewImporterEmailCaseTest have always handled it.
 *
 * MUTATION, AND IT WAS RUN: revert either half of the 28 September fix — take
 * `SqlShape::portable(` back out of CartVariantWasPriceTest:522 or out of
 * SeoLegacyAddressesLandTest — and this test names that file and line. Put it
 * back and it is green.
 */


/**
 * Is this line's first non-space character opening or continuing a comment?
 *
 * Prose is not a matcher, and this suite is heavily commented: nine docblock
 * lines were reported the first time the sweep ran, and one of them — the
 * comment explaining a fix — was enough to exempt its own file.
 */
final class NeedleGuardLine
{
    public static function isComment(string $line): bool
    {
        return preg_match('/^\s*(\*|\/\/|\/\*|#)/', $line) === 1;
    }

    /** Does $needle appear on a line that is code rather than prose? */
    public static function spelledInCode(array $lines, string $needle): bool
    {
        foreach ($lines as $line) {
            if (! self::isComment($line) && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}

/** Files whose needles are not matchers, with the reason each is here. */
const NEEDLE_GUARD_EXEMPT = [
    // Defines portable() and explains the trap in prose; its "needles" are the
    // worked example in the docblock.
    'tests/Support/SqlShape.php',
    // Holds literal statements as FIXTURES for the shape inspector. They are
    // deliberately single-dialect: that is the input under test, not a needle
    // matched against a live query log.
    'tests/Feature/SqlDialectGuardTest.php',
];

it('has no matcher that spells a table the SQLite way and nothing else', function () {
    $root = dirname(__DIR__, 2);

    $files = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walk as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    expect(count($files))->toBeGreaterThan(200,
        'the sweep found almost no test files, so it is not sweeping anything');

    $offences = [];
    $checked = 0;

    foreach ($files as $path) {
        $relative = ltrim(str_replace($root, '', $path), '/');

        if (in_array($relative, NEEDLE_GUARD_EXEMPT, true)) {
            continue;
        }

        $body = (string) file_get_contents($path);
        $lines = explode("\n", $body);

        /*
         * CODE, not prose. The first cut of this test read the whole file, and
         * the comment explaining WHY a needle had been wrapped was enough to
         * exempt the file — so reverting the wrap left the guard green, which
         * is the one thing a guard may never do. Counted on non-comment lines
         * only, the mutation is red.
         */
        $knowsPortable = false;

        foreach ($lines as $line) {
            if (NeedleGuardLine::isComment($line)) {
                continue;
            }

            if (str_contains($line, 'SqlShape::portable(')) {
                $knowsPortable = true;
                break;
            }
        }

        foreach ($lines as $i => $line) {
            /*
             * Prose is not a matcher, and this suite is heavily commented. A
             * docblock line saying `fell straight from "routine" to ...`, or
             * quoting an example statement, is documentation — nine of them
             * were reported the first time this ran. Skip anything whose first
             * non-space character opens or continues a comment.
             */
            if (NeedleGuardLine::isComment($line)) {
                continue;
            }

            // A test's NAME may contain the word "from" followed by a quoted
            // word — `it('... as a different state from "blank"')` does — and
            // it is prose, not a needle.
            if (preg_match('/^\s*(it|test|describe)\s*\(/', $line) === 1) {
                continue;
            }

            if (preg_match_all('/\b(from|join|into|update)\s+"([a-z_][a-z_0-9]*)"/', $line, $m, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($m as $hit) {
                [, $keyword, $table] = $hit;
                $checked++;

                /*
                 * THE ESCAPE HATCH IS `from `products``, NOT A BARE BACKTICK.
                 *
                 * The first cut asked only whether the file mentioned the table
                 * in backticks anywhere — and CartVariantWasPriceTest's own
                 * FAILURE MESSAGE says "five variable basket lines read
                 * `products` {$five} times", which is English with a code
                 * span in it and not a MySQL needle at all. That one string
                 * exempted the file, so reverting the fix left this test green.
                 * Mirror the whole keyword-and-identifier pair instead.
                 */
                if ($knowsPortable || NeedleGuardLine::spelledInCode($lines, $keyword . ' `' . $table . '`')) {
                    continue;
                }

                $offences[] = sprintf(
                    '%s:%d matches `%s` by its SQLite spelling only — on MySQL this needle '
                    . 'matches nothing and the assertion behind it is vacuous. Wrap the statement '
                    . 'in SqlShape::portable(), or match both spellings.',
                    $relative,
                    $i + 1,
                    $table
                );
            }
        }
    }

    // Non-vacuity: if the regex ever stops finding needles, the sweep is green
    // for the wrong reason and this line says so instead.
    expect($checked)->toBeGreaterThan(10,
        'the sweep found fewer than eleven dialect-specific needles in the whole suite, '
        . 'which means the pattern has stopped matching rather than that the suite is clean');

    expect($offences)->toBe([]);
});
