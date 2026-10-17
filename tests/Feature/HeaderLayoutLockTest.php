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
    // Lane HLB: the header language switch's markup and its drawn Arabic words.
    'app/Support/LangSwitch.php',
    'app/Support/LangSwitchGlyphs.php',
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

/*
 * ▲ THE SECOND OPENING, Lane HLB (17 October 2026). The owner approved the
 *   header language switch in the round-3 live preview -- "ok proceed to the
 *   patch, but with super care without distubing anything else" -- and moved
 *   the phone's wishlist heart into the cart panel ("in mobile the wishlist
 *   will be move to the cart panel beside browsed tab"). These thirteen rules,
 *   and nothing else, are new. Like the Arabic opening above they are taken OUT
 *   of the fingerprint, which therefore stays the 12 October pin byte for byte
 *   -- the proof that no existing header or logo rule moved -- and are pinned
 *   word for word by their own case below, which also requires every selector
 *   to name a class the header prints ONLY with the feature on: .lsw (the
 *   switch; printed only while Arabic is on), .has-lswd (the computer pill is
 *   printed) or .ib-wcp (the heart has moved to the cart panel). A shop with
 *   neither matches none of them. The sweeps CLAUDE.md names were run
 *   (tools/hlb-sweep.cjs, docs/lane-hlb/sweep.json).
 */
const HLL_HLB_RULES = [
    'kbb.css||header .hact .lsw{display:none}',
    'kbb.css|@media (min-width:901px)|header .hact .lsw-d{display:flex;align-items:center;align-self:center;height:30px;margin-inline-end:10px;border:1px solid #e9dfe3;border-radius:999px;overflow:hidden;font-size:12px;font-weight:700;white-space:nowrap;flex:none}',
    'kbb.css|@media (min-width:901px)|header .hact .lsw-d>*{display:flex;align-items:center;justify-content:center;height:100%;min-width:44px;padding:0 14px;color:inherit;text-decoration:none}',
    'kbb.css|@media (min-width:901px)|header .hact .lsw-d>span{background:var(--pink,#e0567b);color:#fff}',
    'kbb.css|@media (min-width:901px)|header .hact .lsw-d>a:hover{background:#fbeff3}',
    'kbb.css|@media (min-width:901px)|header .hact .lsw-d .lsw-g{display:block;width:auto;height:13px}',
    'kbb.css|@media (min-width:901px)|header .hin.has-lswd>.sbox{margin-inline-end:0}',
    'kbb.css|@media (min-width:901px)|header .hin.has-lswd>.hinfo{margin-inline-start:-10px}',
    'kbb.css|@media (max-width:900px)|header .hact .ib-wcp{display:none}',
    'kbb.css|@media (max-width:900px)|header .hact .lsw-m.ib{display:grid;place-items:center;overflow:visible;color:inherit;text-decoration:none;margin-inline-start:8px}',
    'kbb.css|@media (max-width:900px)|header .hact .lsw-m~.ib-acct{margin-inline-end:-8px}',
    'kbb.css|@media (max-width:900px)|header .hact .lsw-m>span{display:flex;align-items:center;justify-content:center;height:21px;box-sizing:border-box;padding:0 6px;border:1.5px solid currentColor;border-radius:999px;font-weight:700;font-size:10px;line-height:1;white-space:nowrap}',
    'kbb.css|@media (max-width:900px)|header .hact .lsw-m .lsw-g{display:block;width:auto;height:9px}',
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
    // Lane HLB: one key, `lang_switch` (Icons tab, last; Off/Phone/Computer/Both,
    // ships `both` -- the owner's). Nothing else in the file moved.
    'app/Services/HeaderSettings.php' => '4d051c5e3991e240a08d67157b719dcab5fb6f413e06935f26e263b50210604f',
    'app/Services/MobileHeader.php' => 'b2caa3c7afdd24ad1080b2488c4804b9174a0785cd950de7837e21465aa13a6d',
    // Lane HLB: the switch printed at the start of .hact (before the account
    // icon), `has-lswd` on .hin while the computer pill is printed, `ib-wcp` on
    // the heart while the phone capsule takes its slot and the cart panel has
    // the heart tab. All three print nothing
    // with the feature off.
    'resources/views/partials/header.blade.php' => '77ee35c96403f3b1bd70123d7b2acdd058c2361107ac146a570728ebfca51bed',
    'resources/views/partials/logo-lockup.blade.php' => 'cb0b59b02911aa1862ec8108a68920203e0ec152c4f37edecf67712d8af9f2fb',
    // Lane HLB: `lines` drawn from the right on a mirrored page, its own path.
    // English prints the same path as before.
    'resources/views/partials/menu-icon.blade.php' => '394cf53811814392826b11a134f71eafdfb4ef459a5b52f8070b7041fe4ac629',
    'app/Support/LangSwitch.php' => '27f3f6ef96d11d009a684c89d9b505cf0af25a11f57b03ab854a05e48de4380d',
    'app/Support/LangSwitchGlyphs.php' => '4eb5f5497a006823428bcd22c6dc64c6f57b91ea4c18daba586bfb37eea044f5',
];

function hllActual(): array
{
    $lines = array_values(array_filter(explode("\n", hllCssRules()), static fn (string $l): bool => ! in_array($l, HLL_AR_RULES, true) && ! in_array($l, HLL_HLB_RULES, true)));
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

/*
 * Lane HLB's opening is exactly the thirteen rules above, each present once,
 * each scoped to a class only the feature prints. Mutation: write the phone
 * rule as `header .hact .ib-acct{margin-inline-end:-8px}` (the preview's
 * spelling, which pulls the account icon on EVERY shop, with or without
 * Arabic) and this is red -- the rule is missing from the list and the
 * fingerprint case goes red with it.
 */
it('opens the lock for the language switch and the moved heart only, with rules a shop without them cannot match', function () {
    $lines = explode("\n", hllCssRules());
    $feature = '/\.(lsw|lsw-d|lsw-m|has-lswd|ib-wcp)(?![\w-])/';

    foreach (HLL_HLB_RULES as $rule) {
        expect(count(array_keys($lines, $rule, true)))->toBe(1, "missing or doubled: {$rule}");

        [, , $css] = explode('|', $rule, 3);
        foreach (explode(',', substr($css, 0, (int) strpos($css, '{'))) as $sel) {
            expect((bool) preg_match($feature, $sel))->toBeTrue("not scoped to the feature: {$sel}");
        }
    }

    // And no rule outside the list names the feature's classes.
    $ours = array_filter($lines, static fn (string $l): bool => (bool) preg_match($feature, $l));
    expect(array_values(array_diff($ours, HLL_HLB_RULES)))->toBe([]);
});
