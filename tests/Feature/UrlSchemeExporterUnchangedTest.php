<?php

declare(strict_types=1);

use App\Services\Import\RedirectMap;

/**
 * The exporter plugin needed no change for the address scheme, and this is the
 * check rather than the claim.
 *
 * ── WHY IT IS WORTH A TEST AND NOT A SENTENCE IN A REPORT ───────────────────
 *
 * Touching the plugin is expensive: `GeWpExporterTest` fails if the plugin
 * header and `KBB_EXPORTER_VERSION` disagree, or if `CHANGELOG.md` does not
 * account for the number, so an edit made "for tidiness" is a version bump and a
 * changelog entry. The honest answer is therefore worth proving.
 *
 * ── WHY IT NEEDS NOTHING ────────────────────────────────────────────────────
 *
 * The scheme changed where this shop SERVES things. It changed nothing about
 * what the old site served, and a permalink export is a record of the second.
 * `permalinks.csv` already carries every address, per row, as
 * `get_permalink()`/`get_term_link()` really answered it — which means every
 * rewrite rule and every filter the live site had installed — and
 * `manifest.json` already carries the two settings that produced them,
 * `permalink_structure` and `woocommerce_permalinks`.
 *
 * Both halves are asserted below against the reference export, because "the
 * exporter already covers it" is exactly the kind of claim that is true of the
 * code and false of the file it writes.
 */
it('already exports the old address of every kind of thing the scheme moved', function () {
    $rows = array_map('str_getcsv', array_filter(explode(
        "\n",
        (string) file_get_contents(base_path('tests/Fixtures/kbb-export/permalinks.csv')),
    )));

    $header = array_shift($rows);

    expect($header)->toBe(['type', 'wc_id', 'slug', 'permalink', 'status', 'source', 'note']);

    $byType = [];

    foreach ($rows as $row) {
        $byType[$row[0]][] = ['permalink' => $row[3], 'source' => $row[5]];
    }

    /*
     * ONE ROW PER KIND OF ADDRESS THE SCHEME MOVED, with the address the old
     * site served in it. `RedirectMap::currentPathFor()` reads `type` and
     * `wc_id` and nothing else, so these four labels are the whole interface.
     */
    foreach (['product', 'category', 'post', 'page'] as $type) {
        // expect(...)->toHaveKey($k, $v) reads its second argument as the
        // expected VALUE, not as a message — the same variadic trap
        // toContain() carries. Asserted as a bool so the message is a message.
        expect(array_key_exists($type, $byType))
            ->toBeTrue("permalinks.csv carries no {$type} rows at all");
        expect($byType[$type][0]['permalink'])->not->toBe('', "the {$type} row carries no address");
    }

    // The product row is the one the scheme's central claim rests on: the old
    // site served /product/{slug}/, which is what this shop serves.
    expect($byType['product'][0]['permalink'])->toBe('https://kbeautybliss.com/product/serum-4021/');

    // And the category row is the flat root form, which is the finding
    // LegacyCategoryUrls was written for.
    expect($byType['category'][0]['permalink'])->toBe('https://kbeautybliss.com/skincare/');

    /*
     * `source` says whether WordPress answered or whether the exporter had to
     * derive the address from the permalink settings. `wp` is the one worth
     * trusting, and it is what the rows the map acts on carry.
     */
    expect($byType['product'][0]['source'])->toBe('wp');
    expect($byType['category'][0]['source'])->toBe('wp');
});

it('already exports the setting the product-base finding rests on', function () {
    /*
     * The scheme leaves /product/{slug}/ alone. That is only safe because the
     * base is CHECKED, and the thing that makes it checkable is already in the
     * export — no new column, no new stage, no version bump.
     *
     * MUTATION NOTE. Delete `woocommerce_permalinks` from the fixture manifest
     * and this is red; that is the same shape of change that would be needed in
     * the plugin if it did not already write it.
     */
    $manifest = json_decode(
        (string) file_get_contents(base_path('tests/Fixtures/kbb-export/manifest.json')),
        true,
    );

    expect($manifest['source']['woocommerce_permalinks'])->toHaveKey('product_base');
    expect($manifest['source']['woocommerce_permalinks'])->toHaveKey('category_base');
    expect($manifest['source'])->toHaveKey('permalink_structure');
});

it('reads a brand archive out of the export rather than inventing its base', function () {
    /*
     * The one place the scheme could have been tempted into a plugin change: it
     * gave this shop a brand PAGE, so a brand permalink now has somewhere real
     * to land. What it did NOT do is teach the exporter to emit a brand
     * archive base, because the exporter already records what the taxonomy
     * really answered — including "nothing", which the reference export says
     * for `pa_brands` in as many words.
     *
     * An empty permalink never reaches fromPermalinks() at all (the loop skips
     * it), so a shop whose brand taxonomy had no public archive produces no
     * brand rows and no questions — which is the answer, not a gap.
     */
    $rows = array_map('str_getcsv', array_filter(explode(
        "\n",
        (string) file_get_contents(base_path('tests/Fixtures/kbb-export/permalinks.csv')),
    )));

    array_shift($rows);

    $brands = array_values(array_filter($rows, static fn (array $r): bool => $r[0] === 'brand'));

    expect($brands)->not->toBeEmpty('permalinks.csv carries no brand rows');
    expect($brands[0][3])->toBe('', 'the reference export claims a brand archive address it never served');
    expect($brands[0][6])->toContain('no public archive');

    // And the map says nothing at all about it, rather than asking a question
    // about an address that was never served.
    $proposals = (new RedirectMap)->propose([
        ['type' => 'brand', 'wc_id' => 501, 'permalink' => ''],
    ]);

    foreach ($proposals as $proposal) {
        expect($proposal['rule'])->not->toBe('permalink', 'an empty permalink produced a proposal');
    }
});
