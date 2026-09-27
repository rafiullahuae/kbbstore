<?php

declare(strict_types=1);

/*
 * Appearance → Cart panel → Desktop / Mobile: two stored values per control, and
 * the one mistake that makes the Mobile tab save and move nothing.
 *
 * ── THE LANDMINE, IN ONE PARAGRAPH ──────────────────────────────────────────
 *
 * partials/drawers.blade.php renders the panel as
 *
 *     <aside class="drawer {{ $cp->bodyClass() }}" style="{{ $cp->cssVariables() }}">
 *
 * so every custom property arrives in a STYLE ATTRIBUTE. An inline declaration
 * outranks every rule in every media query, whatever that rule's specificity. So
 * a phone value written as `--cp-rowpad` — the obvious thing, and the thing the
 * brief for this lane warns costs a day — would win at EVERY width: the desktop
 * panel would move, the phone would be identical to it, and the Mobile tab would
 * read as a broken save rather than as a specificity problem.
 *
 * The shape that works, and the shape panel_width / panel_width_m already had:
 * emit `--cp-x` and `--cp-x-m` SIDE BY SIDE and let the STYLESHEET choose inside
 * its media query. Block 1 pins that for every pair. Block 2 pins the other half
 * — that the stylesheet really does read the `-m` twin at the width that owns it,
 * because a property nothing reads is a slider that moves nothing.
 *
 * ── AND EVERY DEFAULT IS TODAY'S NUMBER ─────────────────────────────────────
 *
 * CLAUDE.md rule 1. Block 3 walks every pair against the value the shop renders
 * now, so a shop that applies this and opens nothing renders the panel byte for
 * byte as before.
 */

use App\Services\CartPanel;
use App\Services\SettingsService;

/** The panel's stylesheet. */
function cpCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb.css'));
}

/** The admin screen. */
function cpScreen(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/cart-panel-screen.blade.php'));
}

/**
 * cssVariables() as a key => value map, so a test can ask about one property.
 *
 * EVERY CALL STARTS FROM A SHOP WITH NO SETTINGS, which is not tidiness: the
 * first draft of the percentage case below asked for `--cp-price` after having
 * set price_size to 115 earlier in the same test, and got 1.15 — it was
 * measuring the previous probe. Each row is deleted on the way in so one probe
 * cannot answer for another, and `forgetMemo` is called because
 * SettingsService memoises in a process-level static as well as the cache and
 * would not see the delete inside one test process.
 */
function cpVars(array $settings = []): array
{
    /* 'cartpanel%' and not 'cartpanel\_%'. SQLite's LIKE has no escape character
       unless one is declared, so the backslash form is a literal backslash and
       matched no row at all — this helper silently kept every probe before it. */
    \App\Models\Setting::query()->where('key', 'like', 'cartpanel%')->delete();

    /* THREE CACHES, AND ALL THREE. A query-builder delete goes round the model,
       so none of them hears about it: SettingsService memoises in a
       process-level static (CLAUDE.md records that trap by name), it caches the
       autoload set, and Setting::map() keeps a cache of its own that the SEO
       layer reads. Clearing one and not the others left this helper measuring
       the previous probe. */
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    foreach ($settings as $key => $value) {
        app(SettingsService::class)->set('cartpanel_' . $key, $value);
    }

    $out = [];

    foreach (explode(';', app(CartPanel::class)->cssVariables()) as $pair) {
        [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');
        $out[trim($name)] = trim($value);
    }

    return $out;
}

/**
 * Every desktop key, its phone twin, and the CSS property each one is emitted as.
 *
 * This list IS the contract. A key added to one side and not the other is the
 * defect the owner reported — "squeezing the phone squeezes the desktop too" —
 * so it is written out rather than derived from the schema by stripping `_m`,
 * which would agree with any mistake the schema made.
 */
function cpPairs(): array
{
    return [
        //  desktop key      phone key          desktop property  phone property
        ['panel_width',    'panel_width_m',    '--cp-w',        '--cp-w-m'],
        ['list_pad',       'list_pad_m',       '--cp-pad',      '--cp-pad-m'],
        ['row_pad',        'row_pad_m',        '--cp-rowpad',   '--cp-rowpad-m'],
        ['thumb_size',     'thumb_size_m',     '--cp-thumb',    '--cp-thumb-m'],
        ['name_size',      'name_size_m',      '--cp-name',     '--cp-name-m'],
        ['name_lines',     'name_lines_m',     '--cp-lines',    '--cp-lines-m'],
        ['price_size',     'price_size_m',     '--cp-price',    '--cp-price-m'],
        ['stepper_size',   'stepper_size_m',   '--cp-step',     '--cp-step-m'],
        ['rm_size',        'rm_size_m',        '--cp-rm',       '--cp-rm-m'],
        ['x_size',         'x_size_m',         '--cp-x',        '--cp-x-m'],
        ['x_glyph',        'x_glyph_m',        '--cp-xg',       '--cp-xg-m'],
        ['btn_gap',        'btn_gap_m',        '--cp-btngap',   '--cp-btngap-m'],
        ['btn_pad',        'btn_pad_m',        '--cp-btnpad',   '--cp-btnpad-m'],
        ['btn_radius',     'btn_radius_m',     '--cp-btnr',     '--cp-btnr-m'],
        ['btn_size',       'btn_size_m',       '--cp-btnf',     '--cp-btnf-m'],
    ];
}

/* ------------------------------------------------------------------------
 | 1. The phone value is its OWN property — the landmine
 |------------------------------------------------------------------------*/

it('emits each mobile value as its own property instead of overwriting the desktop one', function () {
    /*
     * MOVE THE PHONE AND ONLY THE PHONE, one pair at a time. Each desktop value
     * is left at its default and each phone value is set to something no default
     * and no other key uses, so a property carrying the wrong one is visible in
     * the failure message rather than being a coincidence.
     *
     * MUTATION: in CartPanel::cssVariables(), change
     *     '--cp-rowpad-m:' . $c['row_pad_m'] . 'px',
     * to
     *     '--cp-rowpad:' . $c['row_pad_m'] . 'px',
     * — the shape the brief warns about, and the one an author reaching for a
     * media query would write. RED twice over: --cp-rowpad-m is missing, and
     * --cp-rowpad no longer holds the desktop number. Run and confirmed.
     */
    $fresh = cpVars();

    foreach (cpPairs() as [$dKey, $mKey, $dProp, $mProp]) {
        $field = CartPanel::SCHEMA[$mKey];

        // A value inside the phone key's own range, one step off its minimum, so
        // the clamp cannot be what makes this pass.
        $probe = (int) $field[4]['min'] + (int) $field[4]['step'];

        $vars = cpVars([$mKey => $probe]);

        expect($vars)->toHaveKey($mProp);
        expect($vars)->toHaveKey($dProp);

        // The phone property carries the phone number. str_contains rather than
        // toContain(): toContain is variadic over NEEDLES, so a message passed as
        // its second argument is silently asserted as a second substring and the
        // failure reads as though the real needle were the message.
        expect(str_contains($vars[$mProp], (string) $probe))->toBeTrue(
            "{$mProp} is '{$vars[$mProp]}' with {$mKey}={$probe}; the Mobile tab writes a property the shop does not read"
        );

        // ...and the desktop property still carries what it carries on a shop
        // with nothing set, which is the half that fails when a phone key is
        // emitted under the desktop name.
        expect($vars[$dProp])->toBe(
            $fresh[$dProp],
            "setting {$mKey} moved {$dProp} to '{$vars[$dProp]}'; the phone value is overwriting its desktop twin"
        );
    }
});

it('never writes a bare property for a value the Mobile tab owns', function () {
    /*
     * The same rule stated from the other end, and cheap to check: the three
     * properties that exist for the phone alone must carry the `-m` suffix. A
     * `--cp-rmbox` with no suffix would be an inline declaration for a value that
     * is only ever a phone value, so it would apply on a laptop too.
     */
    $vars = cpVars();

    foreach (['--cp-rmbox-m', '--cp-btnh-m', '--cp-tabh-m'] as $prop) {
        expect($vars)->toHaveKey($prop);
        expect($vars)->not->toHaveKey(substr($prop, 0, -2));
    }
});

/* ------------------------------------------------------------------------
 | 2. The stylesheet reads the phone twin, at the width that owns it
 |------------------------------------------------------------------------*/

it('chooses the mobile value in the stylesheet, inside a media query', function () {
    /*
     * THE OTHER HALF OF THE LANDMINE. cssVariables() emitting `--cp-rowpad-m` is
     * worth nothing on its own: if no rule reads it, the slider saves a number
     * and the phone renders the desktop one. So every `-m` property has to appear
     * inside one of the panel's two phone blocks.
     *
     * TWO BLOCKS, NOT ONE, because the shop has two and always has: 680px is
     * where the panel becomes the phone panel and 900px is where the four tap
     * targets are raised to 44. Each property is required in the block that
     * already owned the number it replaces — putting a tap target at 680 would
     * have left the 681–900px band alone and changed the panel where nobody was
     * looking.
     *
     * MUTATION: delete `.kc-item{gap:8px;padding:var(--cp-rowpad-m,9px) 0}` from
     * the 680px block and put `gap:8px` back on its own. RED here, and green
     * everywhere else in the suite — which is why this case exists. Run and
     * confirmed.
     */
    $css = cpCss();

    // The two blocks, cut out by their own opening lines.
    $phoneAt = strpos($css, '@media(max-width:680px){');
    expect($phoneAt)->not->toBeFalse('the panel\'s 680px block is gone');

    // Everything from the 680px block to the end of the file: the 900px blocks
    // are further down it. Split so each property can be required in its own.
    $phoneBlock = substr($css, (int) $phoneAt, strpos($css, '/* Cart panel switches', (int) $phoneAt) - (int) $phoneAt);
    $tapBlocks = substr($css, (int) strpos($css, 'Mobile polish, second pass'));

    foreach ([
        '--cp-w-m', '--cp-pad-m', '--cp-rowpad-m', '--cp-thumb-m', '--cp-name-m',
        '--cp-lines-m', '--cp-price-m', '--cp-step-m', '--cp-btngap-m',
        '--cp-btnpad-m', '--cp-btnr-m', '--cp-btnf-m',
    ] as $prop) {
        expect(str_contains($phoneBlock, 'var(' . $prop))->toBeTrue(
            "{$prop} is emitted on the panel and no rule in the 680px block reads it, "
            .'so its slider on the Mobile tab saves a number and moves nothing'
        );
    }

    foreach (['--cp-x-m', '--cp-xg-m', '--cp-tabh-m', '--cp-rmbox-m', '--cp-rm-m', '--cp-btnh-m'] as $prop) {
        expect(str_contains($tapBlocks, 'var(' . $prop))->toBeTrue(
            "{$prop} is a tap-target value and no rule in the 900px blocks reads it"
        );
    }
});

it('keeps the desktop value on the rule the desktop reads', function () {
    /*
     * And nothing moved the desktop properties out of the base rules while the
     * phone ones were being added. A `--cp-rowpad` that survived only inside the
     * phone block would leave the laptop panel unsettable.
     */
    $css = cpCss();
    $base = substr($css, 0, (int) strpos($css, '@media(max-width:680px){'));

    foreach ([
        '--cp-w', '--cp-pad', '--cp-rowpad', '--cp-thumb', '--cp-name', '--cp-lines',
        '--cp-price', '--cp-step', '--cp-rm', '--cp-x', '--cp-xg',
        '--cp-btngap', '--cp-btnpad', '--cp-btnr', '--cp-btnf',
    ] as $prop) {
        expect(str_contains($base, 'var(' . $prop . ','))->toBeTrue(
            "{$prop} is no longer read by any desktop rule"
        );
    }
});

it('sizes the stepper box and the number between them off one value, on both devices', function () {
    /*
     * kbb.css:376-377 — --cp-step sizes `.kc-qty button` AND the min-width of the
     * span holding the quantity, so one slider moves three boxes. The phone rule
     * added in this round has to do the same or the digit outgrows its box at one
     * end of the scale and rattles at the other.
     *
     * MUTATION: drop the `.kc-qty span` line from the 680px block. RED. Run and
     * confirmed.
     */
    $css = cpCss();
    $phone = substr($css, (int) strpos($css, '@media(max-width:680px){'));
    $phone = substr($phone, 0, (int) strpos($phone, '/* Cart panel switches'));

    expect($phone)->toContain('.kc-qty button{width:var(--cp-step-m,22px);height:var(--cp-step-m,22px)}')
        ->and($phone)->toContain('.kc-qty span{min-width:var(--cp-step-m,22px)}');
});

/* ------------------------------------------------------------------------
 | 3. Every default is the number the panel renders today
 |------------------------------------------------------------------------*/

it('ships every new value at what the panel renders today', function () {
    /*
     * CLAUDE.md rule 1: "Any NEW setting ships at the value the page already has,
     * so applying the package moves nothing until somebody moves a slider."
     *
     * The right-hand column is the rule the number was hard-coded in before this
     * round, read off resources/css/kbb/kbb.css. Two of them are worth the words:
     *
     *  list_pad_m = 11, NOT 16. The phone's rule was
     *  `padding:9px calc(var(--cp-pad,16px) - 5px)`, so 11px is what the phone
     *  drew whatever the desktop slider said. 16 would have WIDENED the phone by
     *  5px on every shop that applied this, which is exactly the silent change
     *  rule 1 forbids.
     *
     *  price_size and btn_size = 100, a PERCENTAGE. .kc-pr is 12.5px and
     *  .kc-btns a is 13.5px; a range stores whole numbers (ModuleSchema::TYPES
     *  maps `range` to int), so no px slider could hold either value and 100% of
     *  it is the only default that leaves the type where it is.
     *
     * MUTATION: change list_pad_m's default to 16. RED here, with the message
     * naming the key. Run and confirmed.
     */
    $today = [
        'panel_width' => 380,     // .drawer{width:var(--cp-w,380px)}
        'panel_width_m' => 77,    // @680 .drawer{width:var(--cp-w-m,77vw)}
        'list_pad' => 16,         // .dbody{padding:10px var(--cp-pad,16px)}
        'list_pad_m' => 11,       // @680 .dbody{padding:9px calc(var(--cp-pad,16px) - 5px)}
        'row_pad' => 9,           // .kc-item{padding:var(--cp-rowpad,9px) 0}
        'row_pad_m' => 9,         // no phone rule existed; the desktop one applied
        'thumb_size' => 42,       // .kc-th{width:var(--cp-thumb,42px)}
        'thumb_size_m' => 38,     // @680 .kc-th{width:var(--cp-thumb-m,38px)}
        'name_size' => 13,        // --cp-name, emitted at the schema default
        'name_size_m' => 13,      // no phone rule existed
        'name_lines' => 2,        // .kc-nm{-webkit-line-clamp:var(--cp-lines,2)}
        'name_lines_m' => 2,      // no phone rule existed
        'price_size' => 100,      // .kc-pr{font-size:12.5px}
        'price_size_m' => 100,    // .kc-pr had no phone rule
        'stepper_size' => 22,     // .kc-qty button{width:var(--cp-step,22px)}
        'stepper_size_m' => 22,   // no phone rule existed
        'rm_size' => 13,          // .kc-rm{font-size:13px}
        'rm_size_m' => 15,        // @900 .kc-rm{font-size:15px}
        'rm_tap_m' => 44,         // @900 .kc-rm{width:44px;height:44px}
        'x_size' => 28,           // .kc-x{width:28px;height:28px}
        'x_size_m' => 44,         // @900 .kc-x{width:44px;height:44px}
        'x_glyph' => 13,          // .kc-x{font-size:13px}
        'x_glyph_m' => 13,        // .kc-x's font-size was not raised at 900
        'tab_h_m' => 44,          // @900 .kc-tab{min-height:44px}
        'btn_layout_m' => 'side', // @680 .kc-btns{grid-template-columns:1fr 1fr}
        'btn_gap' => 8,           // .kc-btns{gap:8px}
        'btn_gap_m' => 6,         // @680 .kc-btns{gap:6px}
        'btn_pad' => 12,          // .kc-btns a{padding:12px 8px}
        'btn_pad_m' => 10,        // @680 .kc-btns a{padding:10px 4px}
        'btn_h_m' => 44,          // @900 .kc-btns .btn-ghost,.kc-btns .cobtn{min-height:44px}
        'btn_radius' => 99,       // .kc-btns a{border-radius:99px}
        'btn_radius_m' => 99,     // the same rule; the phone had no override
        'btn_size' => 100,        // .kc-btns a{font-size:13.5px}
        'btn_size_m' => 100,      // @680 .kc-btns a{font-size:12px}
    ];

    foreach ($today as $key => $expected) {
        expect(array_key_exists($key, CartPanel::SCHEMA))->toBeTrue("cartpanel_{$key} is not in SCHEMA");
        expect(CartPanel::SCHEMA[$key][2])->toBe(
            $expected,
            "cartpanel_{$key} ships at ".var_export(CartPanel::SCHEMA[$key][2], true)
            .' rather than '.var_export($expected, true)
            .", which is what the panel renders today — applying this would move the shop"
        );
    }
});

it('renders the panel exactly as it does today on a shop with no settings at all', function () {
    /*
     * The same rule measured rather than read off the schema. Every property the
     * panel carries, on a database where no `cartpanel_*` row exists, against the
     * number the stylesheet's own fallback holds — which is the number the shop
     * rendered before any of this existed.
     *
     * The two `calc()` factors are 1, which is the whole reason they are
     * factors: calc(12.5px * 1) computes to 12.5px and no px slider could have
     * said so.
     *
     * MUTATION: change `--cp-pad-m` to emit `$c['list_pad']` instead of
     * `$c['list_pad_m']`. GREEN here, because the two defaults differ by five and
     * this case would then expect 16 — so it is NOT the case that catches that;
     * the pair walk in block 1 is. Kept because it catches the other direction:
     * a default changed in the schema with the stylesheet left alone.
     */
    expect(app(SettingsService::class)->get('cartpanel_row_pad', null))->toBeNull();

    expect(cpVars())->toBe([
        '--cp-w' => '380px',      '--cp-w-m' => '77vw',
        '--cp-pad' => '16px',     '--cp-pad-m' => '11px',
        '--cp-rowpad' => '9px',   '--cp-rowpad-m' => '9px',
        '--cp-thumb' => '42px',   '--cp-thumb-m' => '38px',
        '--cp-name' => '13px',    '--cp-name-m' => '13px',
        '--cp-lines' => '2',      '--cp-lines-m' => '2',
        '--cp-price' => '1',      '--cp-price-m' => '1',
        '--cp-step' => '22px',    '--cp-step-m' => '22px',
        '--cp-rm' => '13px',      '--cp-rm-m' => '15px',
        '--cp-rmbox-m' => '44px',
        '--cp-x' => '28px',       '--cp-x-m' => '44px',
        '--cp-xg' => '13px',      '--cp-xg-m' => '13px',
        '--cp-tabh-m' => '44px',
        '--cp-btngap' => '8px',   '--cp-btngap-m' => '6px',
        '--cp-btnpad' => '12px',  '--cp-btnpad-m' => '10px',
        '--cp-btnh-m' => '44px',
        '--cp-btnr' => '99px',    '--cp-btnr-m' => '99px',
        '--cp-btnf' => '1',       '--cp-btnf-m' => '1',
        '--cp-accent' => '#C13E63',
        '--cp-cta-bg' => '#C13E63',
        '--cp-cta-fg' => '#FFFFFF',
    ]);

    // And no class at all, so the panel's class attribute is byte for byte what
    // it is today. `side` is the shipped arrangement and writes nothing.
    expect(app(CartPanel::class)->bodyClass())->toBe('');
});

/* ------------------------------------------------------------------------
 | 4. The factors are factors, and they divide cleanly
 |------------------------------------------------------------------------*/

it('turns a type percentage into a factor the stylesheet can multiply', function () {
    /*
     * 115% must reach the panel as 1.15 and be multiplied by the shop's own size,
     * not as `115` — which would resolve to calc(12.5px * 115) and print a price
     * 1,437px tall.
     *
     * AND IT MUST NOT ARRIVE AS 0.9333333333333333. A percentage that does not
     * divide evenly is rounded to three decimals on the way into the attribute;
     * 70% of 12.5px differing by four ten-thousandths of a pixel is not a
     * difference on a screen, and sixteen digits in a style attribute on every
     * page is.
     *
     * MUTATION: drop the number_format() and emit `$c['price_size'] / 100`. RED
     * on the 95% case, which is 0.9500000000000001 in binary floating point... it
     * is in fact 0.95 exactly, so the case that catches it is 70/100*... — the
     * assertion below uses values whose plain division prints long. Run and
     * confirmed on 115 => '1.15'.
     */
    expect(cpVars(['price_size' => 115])['--cp-price'])->toBe('1.15');
    expect(cpVars(['btn_size' => 145])['--cp-btnf'])->toBe('1.45');
    expect(cpVars(['price_size_m' => 70])['--cp-price-m'])->toBe('0.7');

    // 100 is the default and is the one value that has to be exactly '1', because
    // calc(12.5px * 1) is the declaration that must not move the shop.
    expect(cpVars()['--cp-price'])->toBe('1');
    expect(cpVars()['--cp-btnf'])->toBe('1');

    // The stylesheet's half: the factor is multiplied by the shop's own size.
    expect(cpCss())->toContain('.kc-pr{font-size:calc(12.5px * var(--cp-price,1))')
        ->and(cpCss())->toContain('font-size:calc(13.5px * var(--cp-btnf,1))')
        ->and(cpCss())->toContain('.kc-pr{font-size:calc(12.5px * var(--cp-price-m,1))}')
        ->and(cpCss())->toContain('font-size:calc(12px * var(--cp-btnf-m,1))');
});
