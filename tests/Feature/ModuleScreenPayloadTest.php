<?php

/**
 * What the module settings screens receive, pinned — Lane M.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * Nine controllers each carried a byte-identical copy of the same fifteen-line
 * loop turning a module's SCHEMA and TABS constants into the JSON its screen
 * draws from. Phase 3's open item is to have that loop exist once —
 * ModuleSchema::tabs() — so a module can ship a setting and the screen draws it
 * without the renderer being edited.
 *
 * Replacing nine copies of a loop with one call is only safe if what comes out
 * is what came out before, and "I read both and they look the same" is how the
 * nine copies drifted in the first place. So the payload of every module screen
 * was captured from the endpoints BEFORE the migration —
 * tests/Fixtures/module-screen-payloads.json, 482 fields across 14 screens —
 * and this requires the endpoints to still produce it.
 *
 * ── THE ONE PERMITTED DIFFERENCE ────────────────────────────────────────────
 *
 * ModuleSchema::fields() emits `name` beside `key`, holding the same string;
 * the three modules migrated before this lane already received it and the nine
 * did not. It is additive and nothing reads it on these screens (every field
 * renderer in resources/views/admin/app.blade.php addresses `f.key`), so it is
 * allowed here and nothing else is.
 *
 * ── WHAT THIS CAUGHT ────────────────────────────────────────────────────────
 *
 * Not hypothetical. The first version of the migration passed each module's
 * validation `overrides()` into the render call as well, which is how
 * ProductStyles' card style and SectionDividers' section picker get checked
 * against option sets that live in other registries. That put both sets into
 * the payload's `options` key, where they had always been null — and the
 * divider registry's rows are `[label, description, bool, skin]`, not the
 * `value => label` shape a select needs, so the screen would have drawn a
 * picker out of nested arrays. This test failed on exactly those two fields and
 * nothing else, which is what sent the overrides back to the cast where they
 * belong.
 *
 * MUTATION ACTUALLY RUN: passing `SectionDividers::overrides()` back into the
 * ModuleSchema::tabs() call in SectionDividersApiController fails this with
 *   dividers.sections.options: None -> {'hero': [...], ...}
 */

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/** Every module settings endpoint, as the console asks for it. */
function mScreenUrls(): array
{
    return [
        'cart-panel', 'account-panel', 'header', 'mobile-header', 'mobile-menu',
        'newsletter', 'product-labels', 'product-styles', 'dividers',
        'slim-footer', 'cart-page', 'checkout-page', 'pay-ship-rules',
        'marketing-pixels',
        /*
         * Lane M2. Of the five screens that round touched, Store → Security was
         * the only one whose payload nothing here pinned, so its before-state
         * was recorded off the parent revision and added in the same shape as
         * the other fourteen.
         *
         * ITS FIXTURE ENTRY CARRIES `tabs` AND NOTHING ELSE, deliberately. The
         * endpoint also answers `report`, which holds the integrity check's
         * `ran_at` — a wall-clock timestamp that differs between any two runs.
         * The loop below compares every non-`tabs` key OUTRIGHT, so recording
         * it would have made this test fail on the clock rather than on a
         * change, which is the kind of red that gets a test deleted.
         */
        'security',
        /*
         * Lane M3. The three App\Support\*Settings screens, whose payloads
         * round 2 named as the other half of the blocker — "migrating them
         * rewrites three constants and moves three screens' payloads".
         *
         * THEY HAVE NO `tabs` KEY, and that is not an omission. None of the
         * three draws its controls out of a schema payload: Review Settings and
         * the two badge screens draw their own controls in their own partials
         * and receive a FLAT `settings` map, and Cache receives a reading of the
         * shop rather than a form. So the loop below compares their recorded
         * keys OUTRIGHT — the whole settings map, the whole option lists, the
         * whole theme table — which is a stricter comparison than the tab walk,
         * not a weaker one: a single moved default fails it.
         *
         * Their fixture entries carry the DETERMINISTIC keys only, the way
         * Security's carries `tabs` and not `report`. What is left out, and why:
         * review-badges' `sample` is the most-reviewed product read out of the
         * database; cache's `live` is a sub-request through the kernel,
         * `compiled` is a directory listing, `store` is the configured cache
         * driver and `assets` holds a URL built from the build manifest. Every
         * one of those differs between two runs of the same code, and recording
         * it would make this test fail on the machine rather than on a change.
         */
        'review-settings',
        'review-badges',
        'cache',
        /*
         * Lane M4. Store → Mail, which round 3 §5 named as the last screen on
         * its own hand-written schema and declined to take, because
         * `MailSettings::all()` hands the `<select>` a display LABEL where every
         * other reader on this schema hands back the stored key — so migrating
         * the reader moves what the dropdown is given, on the one screen whose
         * failure mode is a shop that stops sending order email.
         *
         * IT HAS NO `tabs` KEY EITHER, for the same reason the three above do
         * not: it draws its own four bands from MAIL_SECTIONS in
         * resources/views/admin/app.blade.php and receives a FLAT `fields` list.
         * So every recorded key is compared OUTRIGHT — the whole field list,
         * every `value`, every `has_value`, every `options` array, in order —
         * which is stricter than the tab walk, not weaker. One moved label, one
         * reordered option, one `value` that came back as a key instead of a
         * sentence, and this fails.
         *
         * Its before-state was recorded off the parent revision, on a store
         * that has saved nothing, so `last_test` is null and `configured`,
         * `missing` and `transport` are the shipped answers. Nothing in this
         * entry is a wall clock or a machine reading.
         */
        'mail',
    ];
}

it('sends every module screen the payload it sent before the shared schema', function () {
    $owner = AdminUser::create([
        'name' => 'Payload Owner',
        'email' => 'payload-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $expected = json_decode(file_get_contents(base_path('tests/Fixtures/module-screen-payloads.json')), true);

    expect($expected)->toBeArray()->not->toBeEmpty();

    $moved = [];
    $compared = 0;

    foreach (mScreenUrls() as $url) {
        $response = test()->getJson('/admin-api/'.$url);

        expect($response->status())->toBe(200, "/admin-api/{$url} did not answer");

        $now = $response->json();
        $was = $expected[$url] ?? null;

        expect($was)->not->toBeNull("no recorded payload for {$url}");

        // Everything beside the controls — the previews' own data, the module
        // flag, the currency — has to match outright.
        foreach ($was as $key => $value) {
            if ($key === 'tabs') {
                continue;
            }

            if ($value !== ($now[$key] ?? null)) {
                $moved[] = "{$url}.{$key} (outside tabs) changed";
            }
        }

        if (! isset($was['tabs'])) {
            continue;
        }

        expect(count($now['tabs'] ?? []))->toBe(count($was['tabs']), "{$url}: tab count changed");

        foreach ($was['tabs'] as $i => $tab) {
            $nowTab = $now['tabs'][$i];

            foreach (['key', 'label', 'description'] as $k) {
                if (($tab[$k] ?? null) !== ($nowTab[$k] ?? null)) {
                    $moved[] = "{$url}.tab{$i}.{$k} changed";
                }
            }

            expect(count($nowTab['fields']))->toBe(count($tab['fields']), "{$url}.{$tab['key']}: field count changed");

            foreach ($tab['fields'] as $j => $field) {
                $nowField = $nowTab['fields'][$j];
                $compared++;

                // `name` is the one addition this migration is allowed to make.
                $added = array_diff(array_keys($nowField), array_keys($field), ['name']);
                $lost = array_diff(array_keys($field), array_keys($nowField));

                foreach ($lost as $k) {
                    $moved[] = "{$url}.{$field['key']}: lost “{$k}”";
                }

                foreach ($added as $k) {
                    $moved[] = "{$url}.{$field['key']}: unexpected new key “{$k}”";
                }

                foreach ($field as $k => $value) {
                    if ($value !== ($nowField[$k] ?? null)) {
                        $moved[] = "{$url}.{$field['key']}.{$k}: ".json_encode($value).' => '.json_encode($nowField[$k] ?? null);
                    }
                }
            }
        }
    }

    expect($moved)->toBe([], "These module screens would now draw something different:\n".implode("\n", $moved));

    // A guard on the guard: if the fixture or the URL list is emptied, the loop
    // above passes by doing nothing. It compared 482 fields when written.
    // 482 when written; Store → Security's 18 controls joined in Lane M2.
    //
    // STILL 500 AFTER LANE M3, and that is the right number rather than a
    // stale one: the three screens it added carry no `tabs`, so they are
    // compared key by key above and contribute no tab-walked control. The
    // count below would not notice them going missing, so a second guard does:
    //
    // 525 AFTER LANE CP, which is 500 plus the twenty-five controls Appearance →
    // Cart panel gained when it was split into Desktop and Mobile tabs. The
    // fixture's cart-panel entry was advanced in the same commit, and it was
    // advanced ADDITIVELY: no control that existed before is gone, and every
    // surviving one carries the same key, type, value, default and options it
    // carried. What moved is which tab each one sits on (`size`/`density` became
    // `desktop`/`mobile`), two labels that had a device suffix which the tab now
    // says for them, and two help sentences that were empty. All of it was read
    // off the diff rather than regenerated on trust.
    //
    // 520 AFTER LANE AD, which is 525 less the five controls removed from
    // Appearance → Product styles. They are removals and nothing else: the
    // fixture entry was edited by CUTTING those five field objects out of it,
    // byte for byte, so every surviving control still carries the key, type,
    // label, help, value, default and options it carried before. The only other
    // edit in that file is the Layout tab's description, which named columns and
    // spacing that the tab no longer has.
    //
    // ── 29 SEPTEMBER, THREE ADVANCES, ALL ADDITIVE OR EXPLAINED ──────────
    //
    // `header` went 6 tabs to 7: Lane FB's Flag bar, 11 fields. The six
    // recorded tabs were carried across byte for byte and the seventh appended;
    // nothing already recorded was regenerated.
    //
    // `cart-page` went 7 tabs to 9: Lane CR's "Product rows · spacing and size"
    // and "Product rows · phone". ▲ THE TABS ARE COMPARED BY POSITION, not by
    // key — see the `$was['tabs'] as $i` loop below — and the two new ones sit
    // at indexes 2 and 3, not at the end. Appending them read as "cart-page.rec:
    // field count changed, 13 against 12", which is the wrong tab entirely and
    // sent the first attempt at this hunting a field that had not moved. The
    // fixture is now in the ORDER the screen sends, with every recorded tab
    // still carrying its own recorded bytes.
    //
    // And `cart-page.rows` changed its label and description, which is the one
    // non-additive edit here and is deliberate:
    //
    //   was: "Product rows" / "One height drives the whole line. Everything in
    //        it is worked out from that number."
    //   now: "Product rows · the squeezed layout" / "ONLY on the Squeezed
    //        layout — these four are custom properties that the classic cart
    //        page does not read. …"
    //
    // Lane CR found that those four sliders read `--cpg-*` properties whose only
    // readers live in the squeezed stylesheet, so on the CLASSIC cart page they
    // save, report success and move nothing — which is why the owner believed
    // the screen already had row controls and asked for them again. The rename
    // is the screen telling the truth about itself; the real controls are the
    // two new tabs. No field moved, and the old strings are above so the change
    // is visible rather than silent.
    //
    // Each of the five moved nothing on the shop and each was a SECOND ANSWER to
    // a question another screen already owns — the column count belongs to
    // Appearance → Site layout, the gap is set per grid on purpose, and the
    // button's words are in Content → Translations. The reasoning is recorded in
    // full in ProductStyles::SCHEMA, and ProductStylesReachTheShopTest is the
    // measurement it rests on.
    //
    // STILL 520 after Appearance → Mobile menu lost "Menu icon", and the reason
    // is the same one the Lane M3 note above gives: mobile-menu's payload
    // carries `fields` and `groups` rather than `tabs`, so its controls are
    // compared OUTRIGHT, key by key, in the loop above and contribute nothing
    // to this walk. Its field object and its name in the panel group were both
    // cut from the fixture, byte for byte, and the outright comparison is what
    // holds them.
    //
    // (Why it went: the seven options are CSS class names — `ico-spin`,
    // `ico-arrow` and the rest — whose rules are real and still in kbb.css, but
    // MobileMenu::bodyClass() has never emitted one of them onto anything, so
    // all seven did the same nothing. The menu icon is chosen on Appearance →
    // Header → Icon now, which reaches the element and offers a family, a
    // speed, a size and three colours besides.)
    // 558 AFTER LANES FB AND CR, 29 September, and the arithmetic is the whole
    // check: 520 + 11 + 13 + 14 = 558. 11 is Appearance → Header → Flag bar;
    // 13 and 14 are Appearance → Cart page → "Product rows · spacing and size"
    // and "Product rows · phone". Every one of the 520 already recorded is still
    // compared and still answers what it answered — this walk counts controls,
    // and a control that CHANGED would have failed the key-by-key comparison
    // above long before reaching this line.
    expect($compared)->toBe(558, 'the number of controls drawn changed');

    foreach (['review-settings', 'review-badges', 'cache'] as $flat) {
        expect($expected[$flat]['settings'] ?? null)
            ->toBeArray()
            ->not->toBeEmpty("the recorded {$flat} settings map is empty — the outright comparison above would pass by doing nothing");
    }
});
