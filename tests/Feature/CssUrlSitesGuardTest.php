<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * NO SIXTEENTH SITE: every storefront CSS url() goes through App\Support\CssUrl.
 *
 * ── WHAT IT GUARDS ──────────────────────────────────────────────────────────
 *
 * Thirteen places in the storefront pasted an image address between `url('` and
 * `')` inside a `style=` attribute and escaped it with e(), the HTML escaper.
 * A browser HTML-decodes a style attribute BEFORE the CSS parser reads it, so
 * `&#39;` arrives at CSS as `'` and closes the url. CssUrl::value() answers the
 * CSS question instead, and its class docblock records what it refuses and what
 * it merely escapes.
 *
 * The fix is only worth as much as the thing that stops the fourteenth site
 * being written the old way, which is this file. The WordPress import is about
 * to write thousands of these addresses from a database this shop did not
 * author, so a new tile, rail or drawer is likely and the old spelling is the
 * obvious one.
 *
 * ── WHY IT COMPILES THE BLADE INSTEAD OF GREPPING IT ────────────────────────
 *
 * A COMMENT EXPLAINING THE FIX MUST NOT SATISFY THE CHECK THE FIX EXISTS TO
 * PASS. That has happened three times in two days on this project, so it is
 * measured here rather than avoided by care: `it refuses to read a comment as
 * the fix` below plants a file whose ONLY mention of CssUrl is in a Blade
 * comment and a PHP comment and proves it is still reported.
 *
 * Stripping comments by regex is what invites that bug back — `{{-- --}}` is
 * easy, `//` inside an @php block is not, and `//` cannot be stripped blindly
 * because every `https://` in the file contains one. So the source is handed to
 * the real Blade compiler (which removes Blade comments as part of its job) and
 * the result is tokenised, with T_COMMENT and T_DOC_COMMENT blanked. That is
 * the same reasoning ExpectationsThatCannotFailTest records for using
 * token_get_all() rather than a regex, and it costs nothing.
 *
 * ── WHAT COUNTS AS GOING THROUGH THE HELPER ─────────────────────────────────
 *
 * Either the interpolation inside the url() calls CssUrl:: itself, or every
 * variable it names ends in `Css` AND is assigned from CssUrl::value() in the
 * same file. Naming a variable `$fooCss` is therefore not enough on its own —
 * the assignment has to be there, in code, with its comments already gone.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 * Run as `vendor/bin/pest tests/Feature/CssUrlSitesGuardTest.php
 * tests/Feature/CssUrlInjectionTest.php`, 17 cases green to begin with.
 *
 * 1. Revert store/product.blade.php's variant swatch to
 *    `url('{{ $v->image }}')`. RUN: 4 failed, the first of them 'every
 *    storefront CSS url() goes through the helper', naming that file and the
 *    chunk `'<?php echo e($v->image); ?>`. The other three are the rendered
 *    cases in CssUrlInjectionTest.
 * 2. Change `e($imgCss)` back to `e($img)` in
 *    partials/checkout/summary-items.blade.php, leaving the assignment above it
 *    in place. RUN: 1 failed — the sweep, naming that file. A variable called
 *    something else is not guarded however tidy the file around it looks.
 * 3. Change cssUrlPattern() to `/\bnosuchthing\(([^)]*)/i` so it matches
 *    nothing. RUN: 5 failed — 'the sweep is looking at something' (0 dynamic
 *    sites and 0 files against the floors of 13 and 10) and the four fixture
 *    cases below. A regex that silently stops matching cannot read as a clean
 *    sweep.
 * 4. In cssUrlScanSource(), stop blanking T_COMMENT. RUN: 1 failed — 'it
 *    refuses to read a comment as the fix', which is the trap this file is
 *    shaped around and the one this repo walked into three times in two days.
 */

/**
 * One Blade file's source with every comment gone.
 *
 * Blade comments are removed by the compiler; PHP comments are removed by
 * tokenising what the compiler produced. Each comment becomes a single space so
 * that two identifiers either side of one cannot be glued into a third.
 */
function cssUrlScanSource(string $blade): string
{
    $compiled = Blade::compileString($blade);

    $out = '';

    foreach (token_get_all($compiled) as $token) {
        if (is_array($token)) {
            $out .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/**
 * Every CSS `url(` in one comment-free file, split into the three kinds.
 *
 * `static`  — no interpolation at all (the two inline data: SVG icons).
 * `guarded` — interpolated, and the interpolation goes through CssUrl.
 * `open`    — interpolated, and it does not. One of these fails the build.
 *
 * @return array{static: list<string>, guarded: list<string>, open: list<string>}
 */
function cssUrlScan(string $blade): array
{
    $source = cssUrlScanSource($blade);

    $found = ['static' => [], 'guarded' => [], 'open' => []];

    /*
     * The chunk is everything from `url(` to the first `)`. For an interpolated
     * site that stops inside the expression — `url('" . e($imgCss` — which is
     * all this needs: it carries the `$` that makes the site dynamic and the
     * names that say whether it was guarded. For a static data: URI it runs to
     * the closing paren and carries no `$` at all.
     */
    preg_match_all(cssUrlPattern(), $source, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $chunk = $m[1];

        if (! str_contains($chunk, '$')) {
            $found['static'][] = $m[0];

            continue;
        }

        $found[cssUrlChunkIsGuarded($chunk, $source) ? 'guarded' : 'open'][] = $m[0];
    }

    return $found;
}

/**
 * A CSS `url(` and nothing else.
 *
 * `\burl\(` alone also matches PHP: `Facets::url('cat', $c->slug)` and
 * `$sf->url($sfC['l1_url'])` are method calls that build an href, have nothing
 * to do with CSS, and were reported as findings the first time this ran. So a
 * `url` reached through `->` or `::`, or glued to an identifier
 * (`parse_url(`), is not a CSS url(). `background:url(` survives that, because
 * the two characters before its `url` are `d:` rather than `::`.
 */
function cssUrlPattern(): string
{
    return '/(?<!->)(?<!::)(?<![A-Za-z0-9_\\\\])url\(([^)]*)/i';
}

/** Does this interpolated url() chunk get its value from the helper? */
function cssUrlChunkIsGuarded(string $chunk, string $source): bool
{
    if (str_contains($chunk, 'CssUrl::')) {
        return true;
    }

    preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $chunk, $vars);

    if ($vars[1] === []) {
        return false;
    }

    foreach ($vars[1] as $name) {
        if (! str_ends_with($name, 'Css')) {
            return false;
        }

        // The assignment has to be real code in this same file. The comments
        // are already gone, so a docblock promising it cannot stand in for it.
        if (preg_match('/\$'.preg_quote($name, '/').'\s*=\s*[^;]*CssUrl::value\s*\(/', $source) !== 1) {
            return false;
        }
    }

    return true;
}

/**
 * Every storefront Blade file.
 *
 * resources/views/admin/** is left out on purpose: those screens are another
 * lane's and answer a different threat model — an operator who is already
 * signed in with a capability, not an anonymous shopper reading a catalogue
 * row the WordPress import wrote.
 *
 * @return list<string>
 */
function cssUrlStorefrontBlades(): array
{
    $root = resource_path('views');
    $admin = $root.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR;

    $out = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (str_starts_with($file->getPathname(), $admin)) {
            continue;
        }

        $out[] = $file->getPathname();
    }

    sort($out);

    return $out;
}

/* ─────────────────────────────── the sweep ───────────────────────────────── */

it('every storefront CSS url() goes through the helper', function () {
    $open = [];

    foreach (cssUrlStorefrontBlades() as $path) {
        foreach (cssUrlScan((string) file_get_contents($path))['open'] as $chunk) {
            $open[] = str_replace(base_path().'/', '', $path).'  →  '.trim($chunk);
        }
    }

    expect($open === [])->toBeTrue(
        "A CSS url() is built from an interpolated value that does not go through\n"
        ."App\\Support\\CssUrl::value(). e() is the HTML escaper and the HTML parser\n"
        ."decodes its output before CSS reads the attribute, so a quote in the\n"
        ."address closes the url( and the rest is CSS this shop did not write.\n\n"
        .implode("\n", $open)
    );
});

it('the sweep is looking at something', function () {
    /*
     * NON-VACUITY. A regex that stops matching — a Blade syntax change, a
     * refactor that moves the declarations into a component — would otherwise
     * report a clean sweep over nothing at all. The floors are the counts the
     * day this was written, so they can only be tripped by sites LEAVING:
     * 13 interpolated sites across 10 files, and 4 static ones (the inline
     * data: SVG icons in address-sheet and instagram/assets, plus `new
     * URL(location.href)` on /shop and welcome.blade.php's url('/dashboard'),
     * neither of which is CSS and neither of which interpolates anything).
     */
    $dynamic = 0;
    $static = 0;
    $files = [];

    foreach (cssUrlStorefrontBlades() as $path) {
        $found = cssUrlScan((string) file_get_contents($path));
        $n = count($found['guarded']) + count($found['open']);

        if ($n > 0) {
            $files[] = $path;
        }

        $dynamic += $n;
        $static += count($found['static']);
    }

    expect($dynamic)->toBeGreaterThanOrEqual(13)
        ->and(count($files))->toBeGreaterThanOrEqual(10)
        ->and($static)->toBeGreaterThanOrEqual(2);
});

it('reports a url() built the old way', function () {
    // The exact shape this lane replaced, and the one a new screen would reach
    // for: e() around the address and literal quotes around that.
    $blade = '<span style="{{ $img ? "background-image:url(\'" . e($img) . "\')" : "" }}"></span>';

    expect(cssUrlScan($blade)['open'])->toHaveCount(1);
});

it('accepts a url() that goes through the helper', function () {
    $direct = '<span style="background-image:url(\'{{ \App\Support\CssUrl::value($img) }}\')"></span>';
    $viaVar = '@php $imgCss = \App\Support\CssUrl::value($img); @endphp'
        .'<span style="{{ $imgCss !== "" ? "background-image:url(\'" . e($imgCss) . "\')" : "" }}"></span>';

    expect(cssUrlScan($direct)['open'])->toBeEmpty()
        ->and(cssUrlScan($direct)['guarded'])->toHaveCount(1)
        ->and(cssUrlScan($viaVar)['open'])->toBeEmpty()
        ->and(cssUrlScan($viaVar)['guarded'])->toHaveCount(1);
});

it('refuses to read a comment as the fix', function () {
    /*
     * THE TRAP THIS REPO HAS WALKED INTO THREE TIMES IN TWO DAYS. Both comments
     * below say CssUrl::value(, in both comment syntaxes a Blade file has, and
     * neither is code. The site must still be reported.
     */
    $blade = '{{-- safe: the address came from CssUrl::value($img) upstream --}}'
        .'@php /* $imgCss = \App\Support\CssUrl::value($img); */ @endphp'
        .'@php // CssUrl::value() is applied by the controller'."\n".'@endphp'
        .'<span style="{{ "background-image:url(\'" . e($imgCss) . "\')" }}"></span>';

    expect(cssUrlScan($blade)['open'])->toHaveCount(1);
});

it('does not mistake an inline data: icon for a dynamic site', function () {
    $blade = '<style>.tick{background-image:url("data:image/svg+xml;utf8,'
        .'<svg xmlns=\'http://www.w3.org/2000/svg\'><path d=\'m4 12 5 5 10-11\'/></svg>");}</style>';

    expect(cssUrlScan($blade)['static'])->toHaveCount(1)
        ->and(cssUrlScan($blade)['open'])->toBeEmpty()
        ->and(cssUrlScan($blade)['guarded'])->toBeEmpty();
});
