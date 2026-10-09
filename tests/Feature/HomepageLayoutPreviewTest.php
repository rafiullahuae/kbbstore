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
    /*
     * ADVANCED BY LANE BN. `cards_banner` joined Conversion's own sequence
     * directly after the delivery strip and this preview is the first eight
     * keys of it, so `recommended` falls off the end of the wire-frame. The
     * CLAIM this case exists for is untouched and is the first three entries:
     * the strip is drawn SECOND, where the preset's stored sequence asks for it
     * third, because settle() puts a nested section back behind its host.
     */
    /*
     * ADVANCED BY LANE PF. Conversion now places Under AED 54 straight after
     * the flash sale (both price-led) and its old Best sellers rail is off —
     * the new one, `bestselling`, took its place further down — so the first
     * eight visible keys gain `under54` and lose `bestsellers` off the end.
     * The first three, which are this case's claim, do not move.
     */
    /*
     * ▲ ADVANCED BY LANE HC. Every preset gains the Top strip first and the
     * Countries strip under the banner; the claim — the delivery strip right
     * behind the hero, ahead of the ticker — is unchanged, one place along.
     */
    expect(fwPreviewKeys('conversion'))->toBe([
        'topstrip', 'hero', 'delivery', 'ticker', 'cards_banner', 'countries', 'flash', 'under54',
    ]);

    expect(array_slice(fwPreviewKeys('boutique'), 0, 4))->toBe(['topstrip', 'hero', 'delivery', 'categories']);
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
    /*
     * ADVANCED BY LANE IG, from 17/16/16/12, and the diff is exactly two sections
     * per preset except Boutique. `videos` and `instagram` joined REGISTRY and all
     * four presets; three switch them on and Boutique lists them in `off` beside
     * `spotted`, for the reason written at that preset. So three counts rise by two
     * and the fourth does not move — which is the check, rather than a number that
     * was updated until it passed.
     *
     * ADVANCED AGAIN BY LANE BN, from 19/18/18/12, and the diff is exactly ONE
     * section for three presets and none for the fourth. `cards_banner` joined
     * REGISTRY and all four presets; Signature, Conversion and Editorial switch
     * it on, and Boutique lists it in `off` beside `videos`, `instagram` and
     * `spotted`, for the reason written at that preset — a row of cards that
     * scrolls itself is one of the busiest bands on offer and that preset's
     * case is restraint. So three counts rise by one and the fourth does not
     * move, which is the check rather than four numbers updated until they
     * passed. The `off` comparison in the loop below is what pins the fourth.
     */
    /*
     * ADVANCED BY ROW 55 (Lane HA), from 20/19/19/12, by exactly FOUR in every
     * preset: `bestselling`, `trending`, `under54` and `feature` joined
     * REGISTRY. Signature lists them at their registry positions (its claim is
     * the shipped order); the other three do not mention them, and payloadFor()
     * keeps a section a preset does not mention at its default — ON — placed
     * last. No preset's `off` list moved, which the loop below still pins.
     */
    /*
     * MOVED BY LANE PF, from 24/23/23/16, and every number is accounted for:
     *
     *   signature  24 -> 11  `off` is HomepageSections::OFF_BY_DEFAULT (13
     *                        sections): Signature is the owner's row-55 page —
     *                        the banner, the hero as its fallback, and the
     *                        nine — instead of "every section on".
     *   conversion 23 -> 20  routine, quiz (unfinished, owner 3 Oct) and the
     *   editorial  23 -> 20  old best-sellers rail (the new one replaces it)
     *                        join `off`: three fewer each.
     *   boutique   16 -> 12  the same three, plus Under AED 54, off beside
     *                        the flash sale it already turned off.
     *
     * The `off` comparison in the loop below is unchanged and still pins that
     * the SET is exactly what each preset's `off` says.
     */
    // ▲ Lane HC: +2 each — the Top strip and the Countries strip join every
    // preset, phones only, so each preset draws two more sections on a phone.
    // ▲ Lane IGE: +1 for signature, conversion and editorial — the Instagram
    // embeds row ships on (it draws nothing until a post is pasted); Boutique
    // lists it OFF beside `instagram`, for that preset's own "calmer" argument.
    // ▲ Lane IGR: -1 for conversion and editorial — the `instagram` row (the
    // retired Instagram API module) left the registry and every preset; it was
    // ON in those two. Signature already had it off-by-default, Boutique off.
    $expected = ['signature' => 14, 'conversion' => 22, 'editorial' => 22, 'boutique' => 14];

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
