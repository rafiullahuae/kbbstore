<?php

declare(strict_types=1);

/**
 * Phase 15 — the ↑/↓ arrows on Appearance → Homepage, which never moved
 * anything. (Lane FR)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * Every one of the seventeen rows on that screen has had a pair of arrows since
 * it shipped. HomepageSections::save() wrote an `order`, ::all() sorted by it,
 * all five HomepageLayouts presets set it, and the screen reported
 * "Saved N sections — live now". store/home.blade.php rendered the seventeen in
 * TEMPLATE order and read `order` nowhere, and `.kbb-home` was not a flex or
 * grid container, so there was no CSS ordering either. The reproduction is in
 * docs/FO-HOMEPAGE-INVENTORY.md §3: after saving ['newsletter','trust',
 * 'reviews'] first, the service agreed and the page did not move a byte.
 *
 * ── WHAT IS ASSERTED, AND WHY IT IS ASSERTED THIS WAY ───────────────────────
 *
 * The order is applied with CSS `order` on a flex column, so the DOM sequence
 * is UNCHANGED by design and a byte-offset assertion — the very measurement
 * that exposed the bug — would now be measuring the wrong thing. What decides
 * the page is the pairing of two artefacts: the class on each section's wrapper
 * and the rule in the emitted <style>. Both are read out of the RENDERED HTML
 * below, and the pairing is checked rather than either half alone, because
 * either half on its own is inert.
 *
 * The visual result was also measured in Chromium at 1280px and at 390px —
 * getBoundingClientRect().top for all fifteen movable sections, in the saved
 * order, both widths. That belongs in the lane report and not in this file:
 * this suite has no browser.
 *
 * ── EVERY TEST BELOW WAS RUN AGAINST THE UNFIXED TREE ───────────────────────
 *
 * The mutations are listed in docs/FR-HOMEPAGE-ORDER.md with what each one
 * caught. §1 is the one that passes before AND after and says so: it is the
 * promise that a shop which has not touched the screen renders the identical
 * page.
 */

use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\SettingsService;

/** The section list, saved in this order, everything not named keeping its place. */
function frSaveOrder(array $first): void
{
    $keys = array_merge($first, array_values(array_filter(
        array_keys(HomepageSections::REGISTRY),
        fn ($k) => ! in_array($k, $first, true)
    )));

    $payload = [];
    $order = 0;

    foreach ($keys as $key) {
        $payload[$key] = [
            'desktop' => true,
            'mobile' => true,
            'order' => $order++,
            'skin' => HomepageSections::REGISTRY[$key][3],
        ];
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();
}

function frHome(): string
{
    SettingsService::forgetMemo();

    return test()->get('/')->getContent();
}

/** The <style> element this feature emits, or '' when it emitted none. */
function frOrderStyle(string $html): string
{
    $at = strpos($html, '<style>.kbb-home{');

    if ($at === false) {
        return '';
    }

    return substr($html, $at, strpos($html, '</style>', $at) + 8 - $at);
}

/**
 * The opening tag of the wrapper carrying this section's ordering class.
 *
 * Read out of the document rather than off classFor(), because classFor()
 * returning the right string and the template printing it in the right place
 * are two separate things and only the second one is the page.
 */
function frTagFor(string $html, string $key): string
{
    $sections = app(HomepageSections::class)->all();

    // Past the <style> element, which names every one of these classes too, and
    // on a word boundary, because `kbb-ord-1` is a prefix of `kbb-ord-10` and a
    // substring search would answer for the wrong section.
    $body = substr($html, strpos($html, '<body'));

    if (preg_match('/\bkbb-ord-' . $sections[$key]['order'] . '\b/', $body, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }

    $at = $m[0][1];
    $open = strrpos(substr($body, 0, $at), '<');

    return substr($body, $open, strpos($body, '>', $at) + 1 - $open);
}

/* ──────────────────────────────────── §1 a shop that has not touched it */

it('emits nothing at all while the order is the one the template is written in', function () {
    $sections = app(HomepageSections::class);

    expect($sections->orderIsDefault())->toBeTrue();
    expect($sections->orderStyle())->toBe('');

    $html = frHome();

    // Not one extra byte: no style element, and no ordering class on any of the
    // seventeen. This is the whole "off means off" promise, and it is why the
    // feature is gated on orderIsDefault() rather than always emitted.
    expect($html)->not->toContain('kbb-ord-');
    expect($html)->not->toContain('<style>.kbb-home{');
    expect($html)->not->toContain('display:flex;flex-direction:column');

    foreach (array_keys(HomepageSections::REGISTRY) as $key) {
        expect($sections->classFor($key))->not->toContain('kbb-ord-');
    }
});

it('goes on emitting nothing when only the switches have been changed', function () {
    $payload = [];
    $order = 0;

    foreach (HomepageSections::REGISTRY as $key => $meta) {
        $payload[$key] = [
            'desktop' => $key !== 'quiz',
            'mobile' => true,
            'order' => $order++,
            'skin' => $meta[3],
        ];
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    $html = frHome();

    // The switch took effect …
    expect($html)->toContain('d-off');
    // … and the ordering machinery stayed out of the page entirely.
    expect($html)->not->toContain('kbb-ord-');
    expect($html)->not->toContain('<style>.kbb-home{');
});

it('emits nothing again once the Signature preset is re-applied', function () {
    frSaveOrder(['newsletter', 'trust', 'reviews']);

    expect(frHome())->toContain('kbb-ord-');

    app(HomepageLayouts::class)->apply('signature', app(HomepageSections::class));
    SettingsService::forgetMemo();

    // Signature IS the registry order, so "put it back" has to reach the same
    // page the shop shipped with rather than an equivalent-looking one.
    expect(app(HomepageSections::class)->orderIsDefault())->toBeTrue();
    expect(frHome())->not->toContain('kbb-ord-');
});

/* ─────────────────────────────────────────── §2 the arrows move the page */

it('turns a saved order into a flex column and one rule per movable section', function () {
    frSaveOrder(['newsletter', 'trust', 'reviews']);

    $html = frHome();
    $style = frOrderStyle($html);

    expect($style)->not->toBe('');
    expect($style)->toContain('.kbb-home{display:flex;flex-direction:column}');

    // Fifteen rules, one per section that is a child of .kbb-home. Counted, so
    // a rule quietly lost or doubled is a failure and not a shrug.
    expect(substr_count($style, '{order:'))->toBe(15);
});

it('pairs the class on the section with the rule in the style, for the whole order', function () {
    frSaveOrder(['newsletter', 'trust', 'reviews', 'blog']);

    $html = frHome();
    $style = frOrderStyle($html);
    $sections = app(HomepageSections::class)->all();

    // The service's answer first — this much was already true before the fix.
    expect(array_slice(array_keys($sections), 0, 4))->toBe(['newsletter', 'trust', 'reviews', 'blog']);

    $paired = 0;

    foreach ($sections as $key => $row) {
        if (! $row['movable']) {
            continue;
        }

        $n = $row['order'];

        // The rule exists for all fifteen. Two of them — the journal rail and
        // the review wall — are additionally gated on there being an article or
        // a review to show, so on a fixture without either they carry a rule
        // and draw nothing. An unused rule costs the page nothing and dropping
        // it would mean orderStyle() having to know what every section's own
        // @if decides, which is the coupling this design exists to avoid.
        expect($style)->toContain('.kbb-home>.kbb-ord-' . $n . '{order:' . $n . '}');

        $tag = frTagFor($html, $key);

        if ($tag === '') {
            continue;
        }

        // … and where the section IS drawn, the class is on it. A class with no
        // rule and a rule with no class are both silent no-ops, which is the
        // failure mode this whole lane exists to stop shipping.
        expect($tag)->toStartWith('<section class="sec ');
        expect($tag)->toMatch('/\bkbb-ord-' . $n . '\b/');

        $paired++;
    }

    // Counted, so the loop above cannot pass by finding nothing to check.
    expect($paired)->toBe(13);
});

it('gives the hero a later position than the newsletter once it has been moved down', function () {
    frSaveOrder(['newsletter', 'trust', 'reviews']);

    $sections = app(HomepageSections::class)->all();

    // The reproduction in FO's §3, the right way up. The hero is still FIRST in
    // the document — that is the design, not a leftover — so what has to be
    // asserted is the ordering value that the browser lays the page out by.
    expect($sections['newsletter']['order'])->toBe(0);
    expect($sections['hero']['order'])->toBeGreaterThan($sections['newsletter']['order']);

    $html = frHome();

    expect(strpos($html, 'id="slider"'))->toBeLessThan(strpos($html, 'class="nl"'));
    expect(frTagFor($html, 'newsletter'))->toMatch('/\bkbb-ord-0\b/');
    expect(frOrderStyle($html))->toContain('.kbb-home>.kbb-ord-0{order:0}');
});

it('keeps the visibility and divider classes it already carried', function () {
    $payload = [];
    $order = 0;

    foreach (['newsletter', 'trust', 'reviews'] as $key) {
        $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => null];
    }

    foreach (HomepageSections::REGISTRY as $key => $meta) {
        if (! isset($payload[$key])) {
            $payload[$key] = [
                'desktop' => $key !== 'quiz',
                'mobile' => true,
                'order' => $order++,
                'skin' => $meta[3],
            ];
        }
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    // classFor() gained a third responsibility; the two it already had have to
    // survive it, and on the same element.
    $class = app(HomepageSections::class)->classFor('quiz');

    expect($class)->toContain('kbb-ord-');
    expect($class)->toContain('d-off');
});

/* ──────────────────────────── §3 the two rows the arrows cannot move */

it('marks the delivery strip and the ticker as not movable, and says why', function () {
    $sections = app(HomepageSections::class)->all();

    foreach (['delivery', 'ticker'] as $key) {
        expect($sections[$key]['movable'])->toBeFalse();
        expect($sections[$key]['nested_in'])->toBe('hero');
        expect($sections[$key]['note'])->toBe(HomepageSections::NESTED_NOTE);
    }

    // And nothing else is marked that way, or the screen would take arrows off
    // rows that work.
    foreach ($sections as $key => $row) {
        if (in_array($key, ['delivery', 'ticker'], true)) {
            continue;
        }

        expect($row['movable'])->toBeTrue();
        expect($row['nested_in'])->toBeNull();
        expect($row['note'])->toBeNull();
    }

    // The sentence is a real sentence and not a placeholder: the console prints
    // it verbatim on the row in place of the arrows.
    expect(HomepageSections::NESTED_NOTE)->toContain('hero band');
    expect(HomepageSections::NESTED_NOTE)->toContain('cannot be placed elsewhere');

    // AND THE CLAUSE ABOUT THE SWITCHES IS GONE, WHICH IS A PASS AND NOT A
    // REGRESSION — Lane FW.
    //
    // This assertion used to read `toContain('switched off for')`. The note
    // ended "and is hidden on any device the hero itself is switched off for",
    // because the band was one <section> carrying the hero's own visibility
    // classes and turning the hero off for a device hid these two on that
    // device with their own switches still on. That was measured here at
    // 1280px — the band computed display:none while .delivery inside it
    // computed flex — and this lane required the screen to say so rather than
    // offer a switch it silently overrode.
    //
    // The band now takes bandClassFor(), the union of the three rows, and the
    // slider takes the hero's own; the switches decide their own rows.
    // tests/Feature/HomepageHeroBandVisibilityTest.php holds that whole story.
    // A note that still described the override would be the same defect this
    // constant exists to prevent, pointing the other way, so the sentence is
    // asserted ABSENT rather than simply no longer asserted present.
    expect(str_contains(HomepageSections::NESTED_NOTE, 'switched off for'))->toBeFalse(
        'The note still describes an override the hero band no longer applies.'
    );
});

it('never emits an ordering class or rule for a section CSS order cannot move', function () {
    frSaveOrder(['ticker', 'delivery', 'newsletter']);

    $html = frHome();
    $style = frOrderStyle($html);
    $sections = app(HomepageSections::class);

    foreach (['delivery', 'ticker'] as $key) {
        // `order` on a non-flex-child is accepted by every browser and does
        // nothing. Emitting one would be this project's own defect class —
        // a declaration that looks like a control and is not.
        expect($sections->classFor($key))->not->toContain('kbb-ord-');
    }

    $nested = $sections->all();

    foreach (['delivery', 'ticker'] as $key) {
        expect($style)->not->toContain('.kbb-home>.kbb-ord-' . $nested[$key]['order'] . '{');
    }

    // The delivery strip is still inside the hero's own <section>, which is the
    // fact the note on the row states.
    $hero = strpos($html, 'id="slider"');
    $heroEnd = strpos($html, '</section>', $hero);

    expect(strpos($html, 'class="delivery'))->toBeGreaterThan($hero);
    expect(strpos($html, 'class="delivery'))->toBeLessThan($heroEnd);
});

it('refuses to show a nested section anywhere the page will not draw it', function () {
    // The screen posts back whatever sequence the arrows made, and a sequence
    // that puts the ticker first is a position the hero's markup has no way of
    // producing. Rather than accepting it, sorting by it and painting a list
    // the shopper will never see, all() settles the row back behind its host —
    // so the rows the owner is shown ARE the order that renders.
    frSaveOrder(['ticker', 'newsletter', 'delivery', 'trust']);

    $keys = array_keys(app(HomepageSections::class)->all());

    expect(array_slice($keys, 0, 4))->toBe(['newsletter', 'trust', 'hero', 'delivery']);
    expect($keys[4])->toBe('ticker');

    // Renumbered to the effective position, so the console posting this list
    // straight back is a no-op rather than a second, different order.
    $sections = app(HomepageSections::class)->all();

    expect(array_column($sections, 'order'))->toBe(range(0, count($sections) - 1));

    frSaveOrder($keys);

    expect(array_keys(app(HomepageSections::class)->all()))->toBe($keys);
});

it('normalises the presets that ordered the ticker somewhere the hero cannot put it', function () {
    // Conversion asks for hero, ticker, delivery; Editorial and Boutique put
    // the ticker seventeenth. All three were promises the template could not
    // keep, and were kept silently until now.
    foreach (['conversion', 'editorial', 'boutique'] as $preset) {
        app(HomepageLayouts::class)->apply($preset, app(HomepageSections::class));
        SettingsService::forgetMemo();

        $keys = array_keys(app(HomepageSections::class)->all());
        $hero = array_search('hero', $keys, true);

        expect(array_slice($keys, $hero, 3))->toBe(['hero', 'delivery', 'ticker']);
    }
});

/* ───────────────────────────────────────────── §4 what else reads the order */

it('lets the divider setting mean the section that is now at the top', function () {
    app(\App\Services\SettingsService::class)->set('divider_style', 'ticks');
    app(\App\Services\SettingsService::class)->set('divider_first', false);
    app(\App\Services\SettingsService::class)->set('divider_desktop', true);
    SettingsService::forgetMemo();

    frSaveOrder(['newsletter', 'trust']);

    $sections = app(HomepageSections::class);

    // "Also above the first section: off" names a POSITION, so once the hero is
    // no longer at the top it is the newsletter that must lose the mark and the
    // hero that must gain one. Reading array_key_first(REGISTRY) here would
    // leave the mark suppressed on a section in the middle of the page and
    // draw one above the first thing the shopper sees.
    expect($sections->classFor('newsletter'))->not->toContain('dv');
    expect($sections->classFor('hero'))->toContain('dv');
});

/* ─────────────────────────────────── §5 the console block stays appliable */

it('still finds both console anchors verbatim in the admin script', function () {
    // resources/views/admin/app.blade.php is owned by another lane, so this
    // lane's two edits to paintHomepage() ship as anchor → replacement blocks
    // in docs/FR-HOMEPAGE-ORDER.md. An anchor is a quotation of a file several
    // lanes edit at once, and a quotation rots. This fails the moment one of
    // them stops matching, which is a loud stale block instead of a silent one.
    $doc = file_get_contents(base_path('docs/FR-HOMEPAGE-ORDER.md'));
    $script = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($doc)->toContain('<!-- ANCHOR-1 -->');
    expect($doc)->toContain('<!-- ANCHOR-2 -->');

    foreach ([1, 2] as $n) {
        $at = strpos($doc, '<!-- ANCHOR-' . $n . ' -->');
        $open = strpos($doc, "```", $at);
        $start = strpos($doc, "\n", $open) + 1;
        $anchor = substr($doc, $start, strpos($doc, "\n```", $start) - $start);

        expect($anchor)->not->toBe('');

        // The replacement sits in the next fenced block after the anchor's.
        $rOpen = strpos($doc, '```', strpos($doc, "\n```", $start) + 4);
        $rStart = strpos($doc, "\n", $rOpen) + 1;

        /*
         * INTEGRATOR: the anchor is CONSUMED once the block is applied, which
         * is the normal end of a block's life — this guard was written while it
         * was still pending and would then fail for the one reason that is not
         * a problem. It now accepts either state and still catches the one it
         * exists for: a block that matches NEITHER is stale, and stale is what
         * silently ships nothing.
         */
        $replacement = substr($doc, $rStart, strpos($doc, "\n```", $rStart) - $rStart);

        $pending = str_contains($script, $anchor);
        $applied = $replacement !== '' && str_contains($script, $replacement);

        expect($pending || $applied)->toBeTrue(
            'console block '.$n.' matches the admin script neither as its anchor nor as its replacement — it has gone stale'
        );
    }
});

