<?php

declare(strict_types=1);

/*
 * THE HEADER AND LOGO ARE FROZEN -- the owner, 12 October 2026: "it's fixed.
 * lock the layout from such issues happening, so no patch will disturbed such
 * code / setting". See CLAUDE.md, "The header and logo are frozen".
 *
 * 2.60.492 and 2.60.493 fixed two live faults: the lotus filling the screen on
 * older phones and in-app browsers, and the search field squeezing into the
 * logo's row on large or zoomed-out iPhones (a shrinking logo and a flicker
 * while resizing). This file pins the finished state: every stylesheet rule
 * that styles the header or the logo, and the files that draw them and hold
 * their settings. Any change -- even one meant well -- turns it red.
 *
 * GOING RED IS NOT A PIN TO ADVANCE. A lane whose brief is the header or the
 * logo runs the checks CLAUDE.md names (the 320-1024 sweep, the older-browser
 * and no-stylesheet sweeps, pictures at 390/600/1280), says so in its report,
 * and only then prints the new fingerprints with:
 *     KBB_HEADER_LOCK_PRINT=1 vendor/bin/pest tests/Feature/HeaderLayoutLockTest.php
 */

/** Every rule in the shop stylesheets whose selector touches the header or the logo, with the at-rules it sits in. */
function hllCssRules(): string
{
    $touch = '/(^|[\s,>+~(])(header\b|\.sbox\b|\.hin\b|\.hact\b|\.logo\b|\.lgx|\.kbbmi\b|\.ib\b|\.search-in\b|\.mnav-h\b)/';
    $out = [];

    foreach (glob(resource_path('css/kbb/*.css')) as $file) {
        $css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
        $stack = [];
        preg_match_all('/([^{};]*)([{};])/', $css, $m, PREG_SET_ORDER);
        $selector = null;
        $body = '';

        foreach ($m as [, $pre, $tok]) {
            $pre = trim(preg_replace('/\s+/', ' ', $pre));
            if ($tok === '{') {
                if (str_starts_with($pre, '@')) {
                    $stack[] = $pre;
                } else {
                    $selector = $pre;
                    $body = '';
                }
            } elseif ($tok === ';') {
                $body .= $pre.';';
            } else { // '}'
                if ($selector !== null) {
                    $body .= $pre;
                    if (preg_match($touch, $selector)) {
                        $out[] = basename($file).'|'.implode('|', $stack).'|'.$selector.'{'.$body.'}';
                    }
                    $selector = null;
                } else {
                    array_pop($stack);
                }
            }
        }
    }

    return implode("\n", $out);
}

/** The files that draw the header and the logo and hold their settings. */
const HLL_FILES = [
    'app/Support/LogoLockup.php',
    'app/Services/HeaderSettings.php',
    'app/Services/MobileHeader.php',
    'resources/views/partials/header.blade.php',
    'resources/views/partials/logo-lockup.blade.php',
    'resources/views/partials/menu-icon.blade.php',
];

/*
 * ▲ THE ONE OPENING, round 3 of Lane AR (the owner, asking for exactly this:
 *   "the logo icon should be on the right side for arabic version"). These
 *   five rules, and nothing else, are new; every one is scoped to a mirrored
 *   page ([dir="rtl"]) or to the Arabic wordmark class only /ar prints
 *   (.lgx-ar), so no English page matches any of them. They are taken OUT of
 *   the fingerprint below, which is therefore still the 12 October pin
 *   byte for byte -- the proof that no other header or logo rule moved -- and
 *   pinned word for word by their own case. The sweeps CLAUDE.md names were
 *   run on English and /ar (docs/lane-ar-shots/round3/sweep-*.json).
 */
const HLL_AR_RULES = [
    'kbb.css||[dir="rtl"] .logo.lgx{direction:rtl}',
    'kbb.css||[dir="rtl"] .lgx .lgx-lt{direction:ltr}',
    'kbb.css||.logo.lgx.lgx-ar{font-family:Outfit,"Cairo","Geeza Pro","Noto Sans Arabic",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
    'kbb.css||.logo.lgx.lgx-ar .lgx-w,.logo.lgx.lgx-ar .lgx-g{letter-spacing:0}',
    'kbb.css||header .hin>.logo.lgx.lgx-ar .lgx-w,.mnav-h>.logo.lgx.lgx-ar .lgx-w,.co-head .in>.logo.lgx.lgx-ar .lgx-w,header .hin>.logo.lgx.lgx-ar .lgx-g,.mnav-h>.logo.lgx.lgx-ar .lgx-g,.co-head .in>.logo.lgx.lgx-ar .lgx-g{letter-spacing:0}',
    /*
     * ▲ Lane ARF (the owner, 15 October: "also in the header logo, the end is
     *   cut little bit"). The pink «بليس» is painted with background-clip:text,
     *   which paints only inside the em's box, and the Arabic س runs past it to
     *   the left and below: its tail was not drawn. Room for the paint, given
     *   back by an equal negative margin, so the margin box -- the word, the
     *   logo box and every cap -- is unchanged. /ar only (.lgx-ar). The sweep
     *   (tools/arr-header-sweep.cjs, English and /ar, modern / old / blocked,
     *   320-1024 and 1280) reads the logo, lotus and word boxes identical
     *   before and after at every width; tools/arf-logo.cjs reads 0 px of ink
     *   lost after, against 18-217 before.
     */
    'kbb.css||.logo.lgx.lgx-ar .lgx-w em{padding:.25em;margin:-.25em}',
];

/** The pinned fingerprints (sha256). Change only as the header's comment says. */
const HLL_PINS = [
    'css' => 'ac36cf2d7f924bc64241794c7a61f9bc129744c555b7c46631992513a1076329',
    'app/Support/LogoLockup.php' => 'e4b30adf7ef6c4b1c18591be129294b1c4cb2dda3dd2970fef812946ca4f9aa0',
    // Lane AR round 3: lockup() prints the Arabic wordmark on /ar (only while the
    // wordmark is the shipped one), all() the Arabic placeholder and
    // trendingWords() the same trending words in Arabic, on /ar.
    // English returns exactly what it returned (StorefrontEnglishUnchangedTest).
    // Lane ARF (the owner: "i need the full translation by default"): all() also
    // gives the WhatsApp label its Arabic on /ar while it is still the shipped
    // "24/7 support" -- the placeholder's rule, one more key. Nothing else in
    // the file moved; English is byte-identical.
    'app/Services/HeaderSettings.php' => '472c0932abc8c4ded78f70d362ee8c3712069c77a8816185408e0a9fde480090',
    'app/Services/MobileHeader.php' => 'b2caa3c7afdd24ad1080b2488c4804b9174a0785cd950de7837e21465aa13a6d',
    'resources/views/partials/header.blade.php' => 'cb7479ececf96c453fcc4ede51e8f3757267b540f280dfc764026313bf371224',
    'resources/views/partials/logo-lockup.blade.php' => 'cb0b59b02911aa1862ec8108a68920203e0ec152c4f37edecf67712d8af9f2fb',
    'resources/views/partials/menu-icon.blade.php' => '0fdf319d6ab2b4aefffae57d25398df3ab999bfd80a198cd78fb02859402c32e',
];

function hllActual(): array
{
    $lines = array_values(array_filter(explode("\n", hllCssRules()), static fn (string $l): bool => ! in_array($l, HLL_AR_RULES, true)));
    $now = ['css' => hash('sha256', implode("\n", $lines))];
    foreach (HLL_FILES as $f) {
        $now[$f] = hash_file('sha256', base_path($f));
    }

    return $now;
}

it('keeps every header and logo rule, and the files that draw them, exactly as fixed on 12 October', function () {
    $now = hllActual();

    if (getenv('KBB_HEADER_LOCK_PRINT')) {
        fwrite(STDERR, var_export($now, true)."\n");
    }

    foreach (HLL_PINS as $what => $pin) {
        expect($now[$what])->toBe($pin, "THE HEADER IS FROZEN (CLAUDE.md): {$what} changed. Revert it, or -- only if the brief is the header -- run the sweeps CLAUDE.md names, report them, then re-pin.");
    }
});

it('still sees the rules it is guarding (the lock cannot pass by matching nothing)', function () {
    $rules = hllCssRules();

    expect(substr_count($rules, "\n"))->toBeGreaterThan(100)
        ->and($rules)->toContain('.logo.lgx>.lgx-i{')
        ->and($rules)->toContain('max-width:59px;max-height:44px')
        ->and($rules)->toContain('header .wrap .sbox{')
        ->and($rules)->toContain('flex:0 0 100%');
});

/*
 * The opening is exactly the rules above (five from Lane AR, one from Lane ARF), each present once, each scoped
 * to the Arabic page. Mutation: drop [dir="rtl"] from the first rule (the lotus
 * would move on English too) and this is red; add any other header rule and
 * the fingerprint case is.
 */
it('opens the lock for the Arabic logo side only, with rules no English page can match', function () {
    $lines = explode("\n", hllCssRules());

    foreach (HLL_AR_RULES as $rule) {
        expect(count(array_keys($lines, $rule, true)))->toBe(1, "missing or doubled: {$rule}");

        [, , $css] = explode('|', $rule, 3);
        $selectors = substr($css, 0, (int) strpos($css, '{'));
        foreach (explode(',', $selectors) as $sel) {
            expect(str_contains($sel, '[dir="rtl"]') || str_contains($sel, '.lgx-ar'))->toBeTrue("not Arabic-scoped: {$sel}");
        }
    }
});
