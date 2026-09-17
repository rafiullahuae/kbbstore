<?php

declare(strict_types=1);

/**
 * Phase 15 — the Layouts wire-frame drew a page the preset does not produce.
 * (Lane FW)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * Appearance → Homepage shows four Layouts cards, each with a small wire-frame
 * of bars in the order that preset lays the page out in. The bars come from
 * `HomepageLayouts::summaries()['order']`, which read `$layout['sections']` —
 * the sequence the preset ASKS for.
 *
 * Two of the four ask for something the hero's markup cannot do. Conversion
 * orders `hero, ticker, delivery`; Boutique puts the delivery strip tenth. Both
 * of those rows are drawn inside the hero's own `<section>` and render wherever
 * it renders, so HomepageSections::all() settles them back behind their host on
 * every read. The preset applied cleanly and the picture beside it was of a
 * page that has never existed — the same "the screen said one order and the
 * shop rendered another" fault that settling exists to end, one screen along.
 *
 * Lane FR found this and named it rather than fixing it, on the grounds that it
 * belonged to whoever owns the console. It turned out not to need the console
 * at all: the wire-frame draws whatever the endpoint sends, and the endpoint is
 * PHP. Nothing in resources/views/admin/app.blade.php changes.
 *
 * ── EVERY TEST BELOW WAS RUN AGAINST THE UNFIXED TREE ───────────────────────
 *
 * The mutations are listed in docs/FW-APPEARANCE-LEFTOVERS.md.
 */

use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\SettingsService;

/** The preview sequence for one preset, as keys rather than labels. */
function fwPreviewKeys(string $preset): array
{
    $labels = [];

    foreach (app(HomepageLayouts::class)->summaries() as $summary) {
        if ($summary['key'] === $preset) {
            $labels = $summary['order'];
        }
    }

    $byLabel = [];

    foreach (HomepageSections::REGISTRY as $key => $meta) {
        $byLabel[$meta[0]] = $key;
    }

    return array_map(fn ($label) => $byLabel[$label] ?? $label, $labels);
}

/** What applying that preset really leaves on the page, in order. */
function fwAppliedKeys(string $preset): array
{
    app(HomepageLayouts::class)->apply($preset, app(HomepageSections::class));
    SettingsService::forgetMemo();

    $rows = app(HomepageSections::class)->all();

    return array_values(array_keys(array_filter(
        $rows,
        fn ($row) => $row['desktop'] || $row['mobile']
    )));
}

it('previews every preset in the order applying it actually produces', function () {
    foreach (array_keys(HomepageLayouts::LAYOUTS) as $preset) {
        $preview = fwPreviewKeys($preset);
        $applied = fwAppliedKeys($preset);

        // The card shows the first eight only, so the applied list is cut to
        // the same length rather than the preview being padded.
        expect($preview)->toBe(array_slice($applied, 0, count($preview)), $preset . ' previews an order it does not produce');
    }
});

it('puts the delivery strip second in the two presets that asked for it elsewhere', function () {
    // Named individually as well as swept above, because the sweep would stay
    // green if BOTH sides broke the same way — it compares two computed lists.
    // These two are the concrete wrong pictures the screen was drawing.
    expect(fwPreviewKeys('conversion'))->toBe([
        'hero', 'delivery', 'ticker', 'flash', 'bundles', 'categories', 'bestsellers', 'recommended',
    ]);

    expect(array_slice(fwPreviewKeys('boutique'), 0, 3))->toBe(['hero', 'delivery', 'categories']);
});

it('leaves the preset definitions alone — only the picture of them changed', function () {
    // The stored sequences are NOT rewritten. A preset is allowed to ask for
    // something the page settles; rewriting LAYOUTS to match would be this lane
    // editing four design decisions to make one preview easier.
    expect(HomepageLayouts::LAYOUTS['conversion']['sections'][1])->toBe('ticker');
    expect(HomepageLayouts::LAYOUTS['conversion']['sections'][2])->toBe('delivery');
});

it('counts the same sections it always did, and names the same ones off', function () {
    // The SET is decided by `off`, which this change does not touch. Pinned so
    // that a reordering fix cannot quietly become a visibility one.
    $expected = ['signature' => 17, 'conversion' => 16, 'editorial' => 16, 'boutique' => 12];

    foreach (app(HomepageLayouts::class)->summaries() as $summary) {
        expect($summary['count'])->toBe($expected[$summary['key']], $summary['key'] . ' changed its section count');
        expect($summary['off'])->toBe(array_map(
            fn ($s) => HomepageSections::REGISTRY[$s][0],
            HomepageLayouts::LAYOUTS[$summary['key']]['off'],
        ));
    }
});

it('answers the same question for a bare list of keys as it does for saved rows', function () {
    // settleKeys() is the piece settle() was split around. Two callers now
    // depend on them agreeing, so that is asserted rather than assumed.
    $keys = ['ticker', 'newsletter', 'hero', 'delivery', 'trust'];

    expect(HomepageSections::settleKeys($keys))->toBe(['newsletter', 'hero', 'delivery', 'ticker', 'trust']);

    $payload = [];
    $order = 0;

    foreach (array_merge($keys, array_values(array_diff(array_keys(HomepageSections::REGISTRY), $keys))) as $key) {
        $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => HomepageSections::REGISTRY[$key][3]];
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();

    expect(array_slice(array_keys(app(HomepageSections::class)->all()), 0, 5))
        ->toBe(['newsletter', 'hero', 'delivery', 'ticker', 'trust']);
});
