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

/*
 * ── ONE SCREEN'S RECORDED PAYLOAD MOVED ON PURPOSE ────────────────── Lane PG2 ──
 *
 * `product-styles` in tests/Fixtures/module-screen-payloads.json, and three
 * values in it:
 *
 *     skins                     28 entries -> 32; the showcase family is four
 *                               new selectable card templates
 *     grid_skin.default         "classic" -> "showcase"
 *     grid_skin.value           "classic" -> "showcase"
 *
 * The second and third are the owner's own words — "keep this design by default
 * from backend" — which is CLAUDE.md rule 1's one exception, and the first is
 * what a picker looks like when four options are added to it. NOTHING ELSE in
 * the file moved: the field count is the same, every other screen is untouched,
 * and `grid_skin`'s label, help and type are as they were. Read before it was
 * advanced, and advanced for that change and nothing else.
 *
 * ── AND TWO MORE FIELDS ON THE SAME SCREEN ─────────────────────── Lane CARD ──
 *
 *     show_brand.default / .value      true -> false
 *     show_category.default / .value   true -> false
 *     show_brand.help / show_category.help   "" -> one sentence each
 *
 * The owner again, in as many words: "i want to hide the brand name, category
 * name by default. only name, rating (if any), pricing and cart buttons." What
 * moved is the shipped value of two controls that were already on this screen —
 * they are still there, still switchable, and their key, type and label are
 * untouched. The help text is new because a default that surprises somebody
 * should say why on the screen itself.
 *
 * NOTHING ELSE in the file moved: the field count is the same, the other five
 * "what the card shows" toggles still read true, and every other screen is
 * untouched. Read field by field before it was advanced.
 *
 * ── AND THE SAME SCREEN AGAIN, 2 OCTOBER ──────────────────────────── Lane PR ──
 *
 *     tabs[0].description                    "Card shape and corners." -> names
 *                                            the hover switch it now carries
 *     tabs[0].fields[3]                      NEW: hover_phone, default false
 *     show_discount / show_new .default/.value   true -> false, help says why
 *     tabs[2]                                NEW: "Spacing & type", 21 fields,
 *                                            every default today's measured value
 *
 * The owner asked for the two pills off and for hover off on phones in as many
 * words, and for the spacing and type controls. Written by script into the
 * `product-styles` entry ONLY — the dumped payload, leaf by leaf, with that
 * entry re-serialised in the file's own format — and the diff read before it
 * was committed: 7 lines out, every one of them one of the leaves above.
 */
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

    /*
     * ── 30 SEPTEMBER, FIVE EDITS TO THE FIXTURE, EACH ONE READ OFF THE DIFF ─
     *                                                              (Lane SEC)
     * None of them regenerated: the live payload was dumped, diffed against the
     * recorded one leaf by leaf, and exactly these five leaves were written.
     * Nothing else in the file was touched, and the field COUNT did not move.
     *
     *   header.tabs[6].description       Appearance → Header → Flag bar. The
     *                                    strip is on for desktop now and, on
     *                                    the home page, sits under the banner
     *                                    rather than above the header. The
     *                                    sentence said the opposite of both.
     *   header.tabs[6].fields[1]         `fb_desktop`: help, default and value,
     *                                    false => true. The owner asked for it
     *                                    in as many words -- "apply this on
     *                                    desktop and mobile both" -- which is
     *                                    rule 1's one exception. FlagBarTest
     *                                    carries the quotation.
     *   dividers.sections[]              Appearance → Section dividers' picker
     *                                    is built from
     *                                    HomepageSections::REGISTRY, whose
     *                                    first key is now `cards_banner`: the
     *                                    picture banner is the homepage's
     *                                    banner and is drawn above the hero.
     *                                    A REORDER AND A RENAME, not an
     *                                    addition -- the same row, moved, with
     *                                    "Cards banner" now reading "Banners"
     *                                    because the treatment it ships is
     *                                    pictures.
     *   security.tabs[2].fields[1].help  and
     *   mail.fields[6].help              Two sentences that told the owner he
     *                                    was on shared hosting. He is on
     *                                    Cloudways, with a shell. Corrected in
     *                                    the premise sweep; no control, value
     *                                    or default moved with them.
     *
     * WHAT IS NOT HERE AND WAS EXPECTED TO BE: a new `settings.header_settings`
     * row. The migration that turns the strip on writes that row ONLY when it
     * already exists, because the schema default above covers a shop that has
     * never opened the screen -- so a fresh install's settings table is
     * untouched and SeoBackOfficePayloadTest's fixture needed no edit at all.
     */
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
    //
    // 559 AFTER LANE BG: one control, `fb_text_desktop`, on Appearance →
    // Header → Flag bar. "UAE's Authentic K-Beauty Store — remove this from
    // the desktop version." `fb_text` is one string shared by both widths, so
    // a switch is the only thing that can take the line off desktop without
    // taking it off phones as well.
    //
    // ADVANCED ADDITIVELY, and the diff of the fixture is the proof: ELEVEN
    // LINES INSERTED AND ONE CHANGED. The one is the tab's own description,
    // which now says the wording is on phones only; the eleven are the new
    // field object, written in the key ORDER the recorded ones use and placed
    // at index 3, directly after the `fb_text` it qualifies — tabs and fields
    // are compared BY POSITION here, so appending it would have reported
    // `fb_flags` as having become `fb_text_desktop` and every field after it as
    // moved. Every control already recorded still carries the key, type, label,
    // help, value, default and options it carried.
    //
    // 565 AFTER LANE PI-B: 559 + 6, the six controls of Appearance → Header →
    // Breadcrumbs, a new EIGHTH tab appended after Flag bar (it is last in
    // HeaderSettings::TABS, so appending is the position the screen sends).
    // Two switches ("Show it on phones" / "on desktop", both false — "by
    // default keep it off") and four 0–48px spacing sliders at the product
    // page's own 18 / 0. And three leaves on the Flag bar tab, written off the
    // diff, not regenerated: the tab's description, and `fb_mobile` and
    // `fb_desktop` — help, default and value, true => false — because the
    // owner said "Turn off the top countries bar entirely for now". No other
    // recorded control moved.
    //
    // 566 IN THE SAME LANE, a round later: Appearance → Cart panel → Behaviour
    // → "When something is added" (`add_feedback`: Animated tick / Text pill /
    // None, shipping `tick` because the owner asked for the tick). INSERTED at
    // index 1, directly after `open_on_add`, which is where SCHEMA and TABS put
    // it — fields are compared by position, so appending it would have read as
    // `added_note` becoming `add_feedback`. Fourteen lines inserted, none
    // changed.
    //
    // 588 AFTER LANE PR: 566 + 22 on Appearance → Product styles — `hover_phone`
    // appended to Layout (last in that tab's TABS list, so appending IS its
    // position) and the twenty-one Spacing & type controls, a NEW tab inserted
    // at index 2 between Card content and Colour, which is where TABS puts it.
    // Tabs are compared by position too, so Colour and Sticky are now compared
    // at 3 and 4 against the same recorded objects. Changed leaves: the Layout
    // description and show_discount / show_new's help, default and value — the
    // owner asked for both pills off. See the Lane PR note in the header.
    //
    // 592 IN 2.60.348: + 4 brand-line switches the owner asked for ("give option
    // to hide un-hide the brands names. on desktop and mobile seperate options
    // ... same on checkout rows"): ci_brand_on / ci_brand_on_m on the Cart
    // page's two row tabs and d_row_brand / m_row_brand on the Checkout page's,
    // each inserted at its TABS position. Forty lines inserted, none changed.
    expect($compared)->toBe(592, 'the number of controls drawn changed');

    foreach (['review-settings', 'review-badges', 'cache'] as $flat) {
        expect($expected[$flat]['settings'] ?? null)
            ->toBeArray()
            ->not->toBeEmpty("the recorded {$flat} settings map is empty — the outright comparison above would pass by doing nothing");
    }
});
