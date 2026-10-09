<?php

declare(strict_types=1);

/**
 * Lane QA — the sweep for the defect that never raises an error.
 *
 * ── WHAT THIS EXISTS FOR ────────────────────────────────────────────────────
 *
 * In one day this shop turned out to carry three features that had never once
 * worked, and not one of them logged anything anywhere:
 *
 *   routes/checkout-card.php        shipped 17 September, never required.
 *                                   POST /checkout/card/paid and
 *                                   /checkout/card/abandon answered 405 for
 *                                   twelve days, so a DECLINED payment left the
 *                                   shopper with an empty basket, the stock
 *                                   still held and a confirmable intent live at
 *                                   Stripe.
 *   routes/import-history-admin.php never required in any version. Three
 *                                   endpoints dead, and admin/app.blade.php
 *                                   rendered a link to one of them the owner
 *                                   could click and get a 404 from.
 *   routes/concern-collections.php  mounted only in round 2, months after the
 *                                   controller and the views landed.
 *
 * A file with a perfect header and no `require` is silent BY CONSTRUCTION.
 * Nothing 500s, no log line is written, the suite is green, and the only
 * symptom is a 405 or a 404 on a path a human has to think to try.
 *
 * ── WHY IT ENUMERATES RATHER THAN LISTING NAMES ─────────────────────────────
 *
 * Every existing wiring pin in this suite names ONE file — CartPanelScreenTest
 * counts its own @include, SetRoutesWiredTest counts its own require, and so on
 * for about twenty of them. That is the right assertion and the wrong shape:
 * it covers the file whose lane thought to write it, and a NEW route file that
 * nobody pins is exactly the file that goes unmounted. checkout-card.php had no
 * such test. import-history-admin.php had no such test.
 *
 * So this walks the directory. A route file added tomorrow is covered the day
 * it lands, by nobody's decision.
 *
 * ── AND IT PINS THE FINISHED STATE, NEVER AN ABSENCE ────────────────────────
 *
 * CLAUDE.md records three separate days lost to a lane asserting
 * `->not->toContain('my-routes.php')` to prove it had not wired itself up: that
 * assertion is correct in the lane's worktree and goes RED the moment the
 * integrator does the one thing the lane asked for. Every count below is
 * therefore `=== 1`. Zero is "built, never wired". Two registers everything
 * twice — for a route file Laravel keeps the LAST registration, so the require
 * order silently decides which controller serves live traffic, and for an admin
 * partial it adds the sidebar row twice and wraps window.go around its own
 * wrapper. Both are real failures and both are caught here.
 *
 * MUTATION NOTES, all four run in the lane's worktree:
 *
 *   - delete `require __DIR__.'/checkout-card.php';` from routes/web.php
 *       → "checkout-card.php is required 0 times" (this is literally the
 *         twelve-day defect above)
 *   - duplicate that same line
 *       → "checkout-card.php is required 2 times"
 *   - delete `@include('admin.partials.security-screen')` from app.blade.php
 *       → "security-screen is included 0 times"
 *   - comment the require out with `// require __DIR__.'/ugc-admin.php';`
 *       → still 0, because the count is taken from tokenised PHP and a comment
 *         describing a require is not a require. routes/web.php lines 638–651
 *         carry that exact lesson in prose.
 */

/**
 * routes/web.php and routes/api.php with every comment removed.
 *
 * Tokenised rather than regexed. routes/web.php carries a long comment block
 * around line 640 that QUOTES a require line while explaining that the file it
 * names was never actually required — a plain string search reads that prose as
 * a mount and the guard passes on the very defect it is for.
 */
function qaMountingSource(): string
{
    static $src = null;

    if ($src !== null) {
        return $src;
    }

    $out = '';

    foreach (['routes/web.php', 'routes/api.php'] as $file) {
        foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        $out .= "\n";
    }

    return $src = $out;
}

/**
 * The route files that web.php and api.php do NOT mount, with the reason.
 *
 * Not an exemption list in the sense CLAUDE.md warns about: each of these is
 * mounted somewhere else and the second assertion below proves it, rather than
 * switching the check off.
 */
const QA_MOUNTED_ELSEWHERE = [
    // The two Laravel bootstrap/app.php names itself.
    'api.php',
    'console.php',
];

it('requires every route file exactly once', function () {
    $wiring = qaMountingSource();

    $wrong = [];
    $checked = 0;

    foreach (glob(base_path('routes/*.php')) ?: [] as $file) {
        $name = basename($file);

        if ($name === 'web.php' || in_array($name, QA_MOUNTED_ELSEWHERE, true)) {
            continue;
        }

        $checked++;

        $count = preg_match_all(
            '#require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]/' . preg_quote($name, '#') . '[\'"]#',
            $wiring
        );

        // (Lane IGR) A retired file is still on disk (packages cannot delete one,
        // see Tests\Support\RetiredInstagramApi) and must be mounted ZERO times.
        $want = \Tests\Support\RetiredInstagramApi::isRetiredName($name) ? 0 : 1;

        if ($count !== $want) {
            $wrong[] = sprintf(
                '  %-34s is required %d times by routes/web.php + routes/api.php',
                $name,
                $count
            );
        }
    }

    // The check is blind if the directory ever stops being walked.
    expect($checked)->toBeGreaterThan(50, 'routes/*.php returned almost nothing, so this guard saw nothing');

    expect($wrong)->toBe(
        [],
        "a route file is mounted the wrong number of times:\n" . implode("\n", $wrong)
        . "\n\n0 is the shape this repo keeps finding — routes/checkout-card.php answered 405"
        . " for twelve days and routes/import-history-admin.php never answered at all."
        . " 2 registers every path in the file twice and Laravel keeps the LAST one,"
        . ' so the require order decides which controller serves the shop.'
    );
});

it('names routes/api.php and routes/console.php in bootstrap/app.php, since web.php does not', function () {
    /*
     * The two files the first check skips. They are mounted by
     * Application::configure()'s own arguments rather than by a require, so
     * asserting their require count would be asserting a falsehood — but
     * skipping them without checking anything is how an exemption outlives its
     * reason.
     */
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    foreach (['api.php', 'console.php'] as $name) {
        expect(substr_count($bootstrap, "/routes/{$name}'"))
            ->toBe(1, "bootstrap/app.php no longer mounts routes/{$name}");
    }
});

it('includes every admin console partial exactly once', function () {
    /*
     * The console is one 22,000-line Blade file plus 41 screen partials, and a
     * partial is reached ONLY by its @include. A partial with no include is a
     * screen the sidebar can route to and the browser never draws — which is
     * how the owner came to be told a feature had not shipped when it had.
     *
     * Blade comments are stripped first and `@@include` is ignored, because two
     * partials quote their own include line in their header to tell the
     * integrator what to add: page-editor-screen.blade.php inside a {{-- --}}
     * block and instagram-screen.blade.php as an escaped `@@include`. Counted
     * naively both read as a second mount, and the guard reports two screens
     * that are perfectly correct.
     */
    $blades = [];

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($walk as $entry) {
        if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
            $blades[] = (string) file_get_contents($entry->getPathname());
        }
    }

    $haystack = implode("\n", $blades);
    $haystack = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $haystack);
    $haystack = str_replace('@@include', '', $haystack);

    $wrong = [];
    $partials = glob(resource_path('views/admin/partials/*.blade.php')) ?: [];

    foreach ($partials as $file) {
        $name = basename($file, '.blade.php');

        $count = preg_match_all(
            "#@include\(\s*'admin\.partials\." . preg_quote($name, '#') . "'#",
            $haystack
        );

        // (Lane IGR) The retired Instagram API screen is included ZERO times.
        $want = \Tests\Support\RetiredInstagramApi::isRetiredName($name) ? 0 : 1;

        if ($count !== $want) {
            $wrong[] = sprintf('  %-34s is included %d times', $name, $count);
        }
    }

    expect(count($partials))->toBeGreaterThan(20, 'the partials directory returned almost nothing, so this guard saw nothing');

    expect($wrong)->toBe(
        [],
        "an admin screen partial is included the wrong number of times:\n" . implode("\n", $wrong)
        . "\n\n0 is a screen the sidebar routes to and the browser never draws."
        . ' 2 registers its sidebar entry twice and wraps window.go around its own wrapper.'
    );
});
