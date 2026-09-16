<?php

declare(strict_types=1);

/**
 * Two admin screens given the Coupons treatment, and what must stay true of
 * them: Business Details, and the Settings tab of SEO & Meta.
 *
 * WHAT THE OWNER ASKED FOR. The Coupons screen was "completely messy — fields
 * just thrown in the page, no grouping, no headings", and the rebuild of it
 * (resources/views/admin/partials/coupon-editor-screen.blade.php) is the
 * standard. Three things make that standard: a band with a heading and one
 * line saying when you would touch it; paired fields that share a row and a
 * width; and help that is one short line rather than a paragraph under every
 * box. These assertions pin the first two, because those are the ones a later
 * edit can undo without anybody noticing.
 *
 * Analytics was the third and is deliberately absent: lane customers-analytics
 * rebuilt that screen to the same standard, and it is theirs to pin.
 *
 * WHY THESE ARE STRUCTURAL. Pest has no layout engine, so the measurements are
 * in the commit, not here. Taken in Chromium against the real markup, before
 * and after, with the admin's own measure — #content, never documentElement,
 * because body is overflow-x:hidden and the document is never wider than the
 * viewport however broken a screen is:
 *
 *   Business Details 390px   currency select clipped to "AED — UAE Dirha"
 *                            .g2 is `1fr 1fr` with no breakpoint anywhere
 *   SEO & Meta       390px   the same two 145px columns
 *
 * Neither overflowed. They squeezed — which is exactly why the repo's overflow
 * walk never saw them, and why a measurement alone would not have caught it
 * either.
 */
function laneCdConsole(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** The body of one render function, from its declaration to the next marker. */
function laneCdSlice(string $from, string $to): string
{
    $src = laneCdConsole();

    $start = strpos($src, $from);
    expect($start)->not->toBeFalse("cannot find `{$from}` — this check is blind");

    $end = strpos($src, $to, (int) $start);
    expect($end)->not->toBeFalse("cannot find `{$to}` after `{$from}` — this check is blind");

    return substr($src, (int) $start, (int) $end - (int) $start);
}

function laneCdStoreSettings(): string
{
    return laneCdSlice('async function renderStoreSettings(){', "document.getElementById('set_save_biz').onclick");
}

function laneCdSeoSettings(): string
{
    return laneCdSlice('async function renderSeoSettings(){', "document.getElementById('set_save_seo').onclick");
}

/**
 * THE BEHAVIOUR GUARD, and the reason this file exists at all.
 *
 * Both settings screens are one form with one Save, and the handler builds its
 * payload by reading ids out of the document with sval(). A redesign that drops
 * a field does not break: the id is simply absent, sval() returns '', and the
 * setting is silently overwritten with nothing on the next save. Nothing else
 * in this suite would see that — the request still succeeds, with a value the
 * owner never typed.
 */
it('still draws every field the Business Details save reads', function () {
    $markup = laneCdStoreSettings();
    $handler = laneCdSlice("document.getElementById('set_save_biz').onclick", 'function seoSel(');

    expect(preg_match_all("/sval\('([a-z0-9_]+)'\)/", $handler, $m))->toBeGreaterThan(5);

    foreach (array_unique($m[1]) as $id) {
        // set_currency is drawn by the shared curSelect() helper rather than
        // spelled out here, so the call is what has to be present.
        $drawn = $id === 'set_currency'
            ? str_contains($markup, 'curSelect()')
            : str_contains($markup, "'{$id}'");

        expect($drawn)->toBeTrue(
            "the Business Details save posts {$id}, but nothing on the screen draws it any more — "
            . 'sval() will return an empty string and overwrite the stored value with nothing.'
        );
    }
});

it('still draws every field the SEO settings save reads', function () {
    $markup = laneCdSeoSettings();
    $handler = laneCdSlice("document.getElementById('set_save_seo').onclick", 'Redirects created automatically');

    expect(preg_match_all("/sval\('([a-z0-9_]+)'\)/", $handler, $m))->toBeGreaterThan(25);

    foreach (array_unique($m[1]) as $id) {
        expect(str_contains($markup, "'{$id}'"))->toBeTrue(
            "the SEO save posts {$id}, but nothing on the screen draws it any more — "
            . 'sval() will return an empty string and overwrite the stored value with nothing.'
        );
    }

    // The four ticks are read by class rather than by value, so they are
    // checked the same way they are read.
    foreach (['seo_merchant_cbx', 'seo_indexnow_cbx', 'seo_llms_cbx', 'seo_crawlclean_cbx'] as $id) {
        expect(str_contains($markup, "'{$id}'"))->toBeTrue(
            "the SEO save reads {$id}'s class, but nothing on the screen draws it any more."
        );
    }
});

/**
 * Every band names itself AND says when you would touch it. A heading on its
 * own is what these screens already had — seven cards each headed by a bare
 * bold word — and it is not what was asked for.
 */
it('gives every band on both screens a one-line description', function () {
    $src = laneCdConsole();

    foreach (['bdSec' => 3, 'smSec' => 7] as $fn => $least) {
        // The helper itself has to emit the description slot.
        expect(preg_match('/function '.$fn.'\(title,description,body\)\{/', $src) === 1)
            ->toBeTrue("{$fn}() is gone or no longer takes a description — this check is blind.");

        preg_match_all('/function '.$fn.'\(title,description,body\)\{(.{0,400}?)\n  \}/s', $src, $body);
        expect($body[1][0] ?? '')->toContain('-sec-d');

        /*
         * And every call has to fill it. A call site is
         * `fn('Title',<newline> 'description…'`. The description is matched as
         * a whole string literal where it is one, and as its first two
         * characters where it is not — one band's description is built by a
         * ternary rather than written out, and a rule that only understood
         * literals would quietly stop checking that band.
         */
        preg_match_all(
            '/(?<![A-Za-z])'.$fn.'\(\s*\'((?:[^\'\\\\]|\\\\.)*)\',\s*(\'(?:[^\'\\\\]|\\\\.)*\'|..)/s',
            $src,
            $calls
        );

        $found = count($calls[0]);
        expect($found)->toBeGreaterThanOrEqual($least, "{$fn} is called {$found} times, expected at least {$least}");

        foreach ($calls[1] as $i => $title) {
            $description = $calls[2][$i];

            if (str_starts_with($description, "'") && str_ends_with($description, "'") && strlen($description) > 1) {
                // A written-out description is a sentence, not a word, and
                // certainly not '' — which is what an edit that drops one
                // leaves behind.
                expect(strlen($description) - 2)->toBeGreaterThan(
                    30,
                    "{$fn}('{$title}', …) has no real description — the band says what it is but not when the owner would touch it."
                );

                continue;
            }

            expect($description)->not->toBe("''", "{$fn}('{$title}', …) passes an empty description.");
        }
    }
});

/**
 * The grids. This is the 390px defect, and the only structural rule that
 * prevents it coming back.
 *
 * repeat(auto-fit, minmax(240px, 1fr)) is NOT enough: a track cannot go below
 * 240px, so two of them plus the gap demand more than a 390px phone has and the
 * row overflows rather than stacking. minmax(min(230px,100%), 1fr) lets a track
 * give way on a narrow screen and keeps the floor everywhere else.
 */
it('lets every field grid on both screens give way on a phone', function () {
    $css = laneCdConsole();

    foreach (['bd-grid', 'sm-grid'] as $cls) {
        /*
         * preg_match into toBeTrue rather than toMatch with a message: on a
         * 900KB subject, toMatch's failure prints the whole file before the
         * sentence that explains it, and the sentence is the only part anybody
         * can act on.
         */
        $ok = preg_match(
            '/\.'.preg_quote($cls, '/').'\{[^}]*grid-template-columns:repeat\(auto-fit,minmax\(min\(\d+px,\s*100%\),\s*1fr\)\)/',
            $css
        ) === 1;

        expect($ok)->toBeTrue(
            ".{$cls} no longer uses an auto-fit grid with a min() floor, so its tracks cannot give way on a 390px screen."
        );
    }
});

it('keeps .g2 and fixed column counts off both screens', function () {
    /*
     * .g2 is `display:grid;grid-template-columns:1fr 1fr` with no media query
     * anywhere in the console, and Analytics set repeat(4,1fr) in an inline
     * style attribute — which no media query can reach at all. Both are how
     * these screens came to draw four 80px columns on a phone.
     */
    foreach ([
        'Business Details' => laneCdStoreSettings(),
        'SEO settings' => laneCdSeoSettings(),
    ] as $name => $markup) {
        expect(str_contains($markup, 'class="g2"'))->toBeFalse(
            "{$name} is using .g2 again, which is `1fr 1fr` with no breakpoint — it will draw two 145px columns on a phone."
        );

        expect(preg_match('/grid-template-columns:\s*repeat\(\d/', $markup))->toBe(
            0,
            "{$name} pins a fixed number of grid columns in its markup, where no media query can reach it."
        );
    }
});

/**
 * A grid or flex item's default min-width is `auto` — "at least as wide as my
 * content". A <select> whose widest option is 240px therefore sets a floor on
 * its track that no media query can reach. Every container gets min-width:0
 * and passes it to its children; this is the pairing the Coupons screen was
 * rebuilt around and it is asserted here for the same reason.
 */
it('lets every wrapper on both screens shrink below its content', function () {
    $css = laneCdConsole();

    foreach (['bd-wrap', 'bd-sec', 'bd-grid', 'bd-field', 'sm-wrap', 'sm-sec', 'sm-grid', 'sm-field'] as $cls) {
        $q = preg_quote($cls, '/');

        expect(preg_match('/\.'.$q.'\{[^}]*min-width:0/', $css) === 1)
            ->toBeTrue(".{$cls} itself can no longer shrink below its content.");

        expect(preg_match('/\.'.$q.'\s*>\s*\*\{[^}]*min-width:0/', $css) === 1)
            ->toBeTrue(".{$cls}'s children can no longer shrink, which is the half that actually bites.");
    }
});

/**
 * Help under a box is one line. Anything longer belongs in the band
 * description or in a single note at the foot of the band — otherwise the eye
 * has to cross a paragraph before it finds the next label, which is the shape
 * of the complaint these three screens were rebuilt to answer.
 */
it('holds help text to a readable measure on both screens', function () {
    $css = laneCdConsole();

    foreach (['bd-help' => '62ch', 'bd-sec-d' => '78ch', 'sm-help' => '62ch', 'sm-sec-d' => '78ch'] as $cls => $measure) {
        $ok = preg_match('/\.'.preg_quote($cls, '/').'\{[^}]*max-width:'.preg_quote($measure, '/').'/', $css) === 1;

        expect($ok)->toBeTrue(
            ".{$cls} lost its {$measure} cap, so on a wide console it spreads into a full-width paragraph."
        );
    }
});
