<?php

/**
 * The proof that moving thirteen modules onto one shared cast moved nothing —
 * Lane M, Phase 3's per-module settings schema.
 *
 * ── WHY A FIXTURE AND NOT ASSERTIONS ────────────────────────────────────────
 *
 * Rule 1 of the project notes is "nothing that already works may change", and
 * the thing being changed here is the code that decides what 560 live controls
 * across seventeen screens are allowed to store. Hand-written assertions cannot
 * carry that: nobody writes 4,653 of them, and the ones somebody does write are
 * the cases they already thought of, which are exactly the cases that were
 * already right.
 *
 * So the old behaviour was RECORDED before a line of it was touched. Every
 * module's own cast() was driven over an adversarial corpus for every field it
 * declares — empty strings, nulls, 300-character pastes, negative and enormous
 * numbers, "12.50" in an integer box, hex with and without a `#`, three-digit
 * hex, option values that are not options — and the answers written to
 * tests/Fixtures/module-cast-baseline.txt. This test replays the same corpus
 * against the shared cast and requires the same answers.
 *
 * 4,653 calls. 4,621 are byte-identical. The 32 that are not are the bug fix
 * below, and the second test is what stops that exemption being a blank cheque.
 *
 * ── THE DEFECT THIS LANE FOUND ──────────────────────────────────────────────
 *
 * `App\Support\Color::isValidHex()` accepts a hex WITH OR WITHOUT the leading
 * `#` — /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i. Four modules tested with it and then
 * stored `strtoupper($value)` unchanged:
 *
 *     CartPanel           accent, checkout_bg, checkout_fg
 *     MobileHeader        acct_in, acct_out, dv_colour, search_bg,
 *                         search_icon, search_ph, search_text
 *     NewsletterSettings  nl_bg_from, nl_bg_to, nl_btn_bg, nl_btn_fg,
 *                         nl_note_colour
 *     SectionDividers     colour
 *
 * Sixteen fields. A colour posted as `e23a4e` passed the check and was stored
 * as `E23A4E`, which is not a CSS colour. SectionDividers::cssVariables() emits
 * `--dv-col:E23A4E`; CartPanel writes its accent into a `background:`. The
 * browser drops the whole declaration, so the control appears to work in the
 * admin — the value saves, the screen redraws with it — and the shop does not
 * change. ProductLabels hit this, diagnosed it in a comment that is still in
 * that file ("went into style=\"background:E23A4E\" — not a colour, so the badge
 * drew with no background and its white text vanished"), and fixed its own
 * copy. The other four never got the fix, because there were four more copies
 * of the same three lines and nothing tied them together.
 *
 * MUTATIONS ACTUALLY RUN, so this file is not taking its own word for it:
 *
 *   1. CartPanel::POLICY's 'hex' set back to 'strict' — all three tests go red
 *      (the recorded-cast test, the repair test and the already-worked test),
 *      because a strict policy refuses `e23a4e` to the default instead of
 *      repairing it, which moves both the repaired call and the working one.
 *
 *   2. ModuleSchema::castColour()'s repair arm reverted to the original
 *      `strtoupper(trim($value))` with no '#' put back — two tests go red with
 *        cart_panel|accent|colour|"e23a4e" still does not store a colour: E23A4E
 *      which is the defect itself, reproduced by the test that forbids it. The
 *      third stays green, correctly: that mutation does not move a colour that
 *      already had its '#'.
 */

use App\Services\ModuleSchema;

/** Rebuild the recorded corpus against the code as it stands now. */
function mEquivRows(): array
{
    $mods = [
        'header_settings'  => App\Services\HeaderSettings::class,
        'cart_panel'       => App\Services\CartPanel::class,
        'mobile_menu'      => App\Services\MobileMenu::class,
        'product_labels'   => App\Services\ProductLabels::class,
        'cart_page'        => App\Services\CartPage::class,
        'slim_footer'      => App\Services\SlimFooter::class,
        'product_styles'   => App\Services\ProductStyles::class,
        'checkout_page'    => App\Services\CheckoutPage::class,
        'mobile_header'    => App\Services\MobileHeader::class,
        'account_panel'    => App\Services\AccountPanel::class,
        'newsletter'       => App\Services\NewsletterSettings::class,
        'section_dividers' => App\Services\SectionDividers::class,
        'security'         => App\Services\SecurityModule::class,
    ];

    // The same corpus the fixture was recorded with. Changing it invalidates
    // the fixture, which is the point: the file and this list are one artefact.
    $corpus = [
        'bool'     => [true, false, '1', '0', '', 'on', 'off', 'no', 1, 0, null],
        'text'     => ['abc', '', '  sp  ', str_repeat('x', 300), null, '<b>x</b>'],
        'textarea' => ['abc', '', null],
        'colour'   => ['#E23A4E', 'e23a4e', '#abc', 'abc', '', 'zz', null, '#e23a4e'],
        'range'    => [5, '5', 0, -10, 99999, 'abc', '', null, '3.7'],
        'money'    => [100, '100', '12.50', -5, null, '0'],
        'select'   => ['__FIRST__', 'nope', '', null],
        'tags'     => ['a,b', '', null],
        'ids'      => ['1,2', '', null, '0,-1'],
        'skin'     => ['__FIRST__', 'nope', null],
        'sections' => ['hero', '', null],
    ];

    $rows = [];

    foreach ($mods as $name => $cls) {
        $obj = app($cls);
        $ref = new ReflectionClass($cls);
        $method = $ref->getMethod('cast');
        $method->setAccessible(true);

        foreach ($ref->getConstant('SCHEMA') as $key => $def) {
            $type = is_array($def) && array_is_list($def) ? ($def[0] ?? '?') : ($def['type'] ?? '?');

            foreach ($corpus[$type] ?? [null] as $input) {
                $real = $input;

                if ($input === '__FIRST__') {
                    $opts = $def[4] ?? [];

                    if ($type === 'skin') {
                        $opts = App\Support\GridSkins::ALL;
                    }

                    $real = (string) array_key_first($opts);
                }

                try {
                    $answer = var_export($method->invoke($obj, $key, $real), true);
                } catch (\Throwable $e) {
                    $answer = 'THROW:'.get_class($e);
                }

                $rows[] = sprintf('%s|%s|%s|%s|%s', $name, $key, $type, json_encode($real), $answer);
            }
        }
    }

    return $rows;
}

/**
 * The sixteen fields whose stored colour the shared cast repairs, and the two
 * corpus inputs it repairs them for. Anything outside this set is a regression.
 *
 * @return list<string>
 */
function mAllowedRepairs(): array
{
    $fields = [
        'cart_panel' => ['accent', 'checkout_bg', 'checkout_fg'],
        'mobile_header' => ['acct_in', 'acct_out', 'dv_colour', 'search_bg', 'search_icon', 'search_ph', 'search_text'],
        'newsletter' => ['nl_bg_from', 'nl_bg_to', 'nl_btn_bg', 'nl_btn_fg', 'nl_note_colour'],
        'section_dividers' => ['colour'],
    ];

    $out = [];

    foreach ($fields as $module => $keys) {
        foreach ($keys as $key) {
            // Only the two inputs that were being stored without a '#'.
            foreach (['"e23a4e"', '"abc"'] as $input) {
                $out[] = "{$module}|{$key}|colour|{$input}";
            }
        }
    }

    return $out;
}

it('answers every recorded cast exactly as it did before the shared schema', function () {
    $recorded = file(base_path('tests/Fixtures/module-cast-baseline.txt'), FILE_IGNORE_NEW_LINES);
    $now = mEquivRows();

    expect(count($now))->toBe(count($recorded), 'the corpus changed shape; the fixture no longer describes it');

    $allowed = mAllowedRepairs();
    $moved = [];

    foreach ($recorded as $i => $before) {
        $after = $now[$i];

        if ($before === $after) {
            continue;
        }

        // module|key|type|input — the identity of the call, without the answer.
        $identity = implode('|', array_slice(explode('|', $before), 0, 4));

        if (in_array($identity, $allowed, true)) {
            continue;
        }

        $moved[] = $before.'   ==>   '.$after;
    }

    expect($moved)->toBe([], "These settings would store a different value than they did before:\n".implode("\n", $moved));
});

it('repairs a colour that was being stored as a non-colour, and only that', function () {
    /*
     * The exemption above is only safe if what it exempts is strictly an
     * improvement, so this checks both halves of every repaired call:
     * the value the module USED to store was not a colour a browser accepts,
     * and the value it stores now is.
     */
    $recorded = [];

    foreach (file(base_path('tests/Fixtures/module-cast-baseline.txt'), FILE_IGNORE_NEW_LINES) as $line) {
        $parts = explode('|', $line);
        $recorded[implode('|', array_slice($parts, 0, 4))] = $parts[4];
    }

    $now = [];

    foreach (mEquivRows() as $line) {
        $parts = explode('|', $line);
        $now[implode('|', array_slice($parts, 0, 4))] = $parts[4];
    }

    $css = '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
    $checked = 0;

    foreach (mAllowedRepairs() as $identity) {
        expect($recorded)->toHaveKey($identity);

        $before = trim($recorded[$identity], "'");
        $after = trim($now[$identity], "'");

        // It really was broken: no '#', so no browser ever drew it.
        expect(preg_match($css, $before))->toBe(0, "{$identity} was already a valid colour — do not exempt it");
        // And it really is fixed.
        expect(preg_match($css, $after))->toBe(1, "{$identity} still does not store a colour: {$after}");
        // The repair is the same digits, not a different colour.
        expect(strtoupper(ltrim($after, '#')))->toBe(strtoupper($before));

        $checked++;
    }

    expect($checked)->toBe(32, 'the repaired set changed size');
});

it('keeps a colour that already worked byte-identical', function () {
    /*
     * The half that matters most for rule 1. A shop that stored '#E23A4E' — as
     * every shop that used the colour picker did, because the picker always
     * sends a '#' — must read back the same string, on both hex policies.
     * If this fails, the fix moved a working value and has to be reverted.
     */
    foreach ([
        App\Services\CartPanel::class => ['accent', 'repair'],
        App\Services\SectionDividers::class => ['colour', 'repair'],
        App\Services\MobileHeader::class => ['dv_colour', 'repair'],
        App\Services\NewsletterSettings::class => ['nl_btn_bg', 'repair'],
        App\Services\HeaderSettings::class => ['badge_bg', 'strict'],
        App\Services\ProductStyles::class => ['cart_bg', 'strict'],
        App\Services\MobileMenu::class => ['rule_colour', 'strict'],
    ] as $class => [$key, $mode]) {
        $ref = new ReflectionClass($class);
        $method = $ref->getMethod('cast');
        $method->setAccessible(true);

        expect($ref->getConstant('POLICY')['hex'])->toBe($mode, "{$class} changed hex policy");
        expect($method->invoke(app($class), $key, '#E23A4E'))->toBe('#E23A4E', "{$class}.{$key} moved a working colour");
    }
});
