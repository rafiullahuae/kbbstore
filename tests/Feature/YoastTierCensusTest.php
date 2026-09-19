<?php

declare(strict_types=1);

/**
 * Which Yoast tier was in use, answered by the export.
 *
 * Phase 12 leaves the Yoast importer at "[~]" with one thing outstanding and
 * calls it "an owner question, not a developer one": which tier was in use —
 * free, Premium, or Premium plus the WooCommerce SEO add-on — because that
 * decides whether the export carries product-schema data at all.
 *
 * It is answerable from the file. Each tier writes post meta the others do not,
 * and the export is a list of exactly those keys. These cases pin the three
 * answers and the two traps that kept the third one invisible.
 *
 * ── WHY THIS FILE DOES NOT FETCH A PAGE ────────────────────────────────────
 *
 * The rest of this lane asserts on rendered <head>s because the thing under
 * test is a document a crawler reads. This one is not: it is a decision about a
 * CSV the owner has not handed over yet, and there is no page anywhere that
 * shows it. The fixtures below are therefore rows, spelled the three ways real
 * exporters spell them, and the assertions are on the sentences the run would
 * print.
 */

use App\Support\YoastSeo;
use App\Support\YoastTiers;

/** A row as a `wp db export` of postmeta pivoted per post spells it. */
function ytFullPrefix(array $extra = []): array
{
    return array_merge([
        'id' => '4211',
        '_yoast_wpseo_title' => 'Dokdo Toner %%sep%% %%sitename%%',
        '_yoast_wpseo_metadesc' => 'A gentle toner.',
        '_yoast_wpseo_focuskw' => 'dokdo toner',
        '_yoast_wpseo_linkdex' => '71',
    ], $extra);
}

it('reports a free-tier export as free, and says so positively', function () {
    $verdict = YoastTiers::verdict(YoastTiers::present(ytFullPrefix()));

    expect($verdict['free'])->toBeTrue();
    expect($verdict['premium'])->toBeFalse();
    expect($verdict['woocommerce'])->toBeFalse();
    expect($verdict['summary'])->toContain('only fields the FREE tier writes');
});

it('reports Premium from a key only the Premium editor writes', function () {
    /*
     * THE TIER TELL. `_yoast_wpseo_focuskeywords` — the related-keyphrase list —
     * has no free-tier writer, so one row carrying it settles the question
     * whatever anybody remembers about the subscription.
     *
     * MUTATION: move '_yoast_wpseo_focuskeywords' out of PREMIUM_PROOF. Red.
     */
    $verdict = YoastTiers::verdict(YoastTiers::present(ytFullPrefix([
        '_yoast_wpseo_focuskeywords' => '[{"keyword":"snail mucin","score":"good"}]',
    ])));

    expect($verdict['premium'])->toBeTrue();
    expect($verdict['summary'])->toContain('Premium was in use');
});

it('treats a Premium redirect as a hint and refuses to call it proof', function () {
    /*
     * `_yoast_wpseo_redirect` is REGISTERED by the free plugin — it is in the
     * `advanced` group of Yoast's own class-wpseo-meta.php — and merely WRITTEN
     * by Premium's redirect manager. A verdict that announced Premium on the
     * strength of a key the free plugin also declares is the confident wrong
     * answer this whole class exists to replace.
     *
     * MUTATION: add '_yoast_wpseo_redirect' to PREMIUM_PROOF. Red on the first
     * expectation.
     */
    $verdict = YoastTiers::verdict(YoastTiers::present(ytFullPrefix([
        '_yoast_wpseo_redirect' => '/old-toner/',
    ])));

    expect($verdict['premium'])->toBeFalse();
    expect($verdict['premium_hint'])->toBeTrue();
    expect($verdict['summary'])->toContain('HINTS at Premium without proving it');
});

it('finds the WooCommerce add-on by a key that does not look like Yoast', function () {
    /*
     * THE FIRST TRAP, AND THE REASON THIS WAS NEVER FOUND. The add-on's
     * identifier key is `wpseo_global_identifier_values`: no leading
     * underscore, no `yoast`. YoastSeo::looksLikeYoast() tests for the
     * substring `yoast_wpseo`, so the column does not look like Yoast to the
     * importer, and YoastSeo::UNMAPPED does not list it, so skipped() cannot
     * report it either.
     *
     * Asserted against YoastSeo's own two methods rather than described, so the
     * gap is a fact in the suite and not a claim in a docblock.
     */
    $cells = ytFullPrefix([
        'wpseo_global_identifier_values' => '{"gtin13":"4006381333931","mpn":"RL-100"}',
    ]);

    $verdict = YoastTiers::verdict(YoastTiers::present($cells));

    expect($verdict['woocommerce'])->toBeTrue();
    expect($verdict['gtin_source'])->toBeTrue();
    expect($verdict['summary'])->toContain('AND IT CARRIES BARCODES');

    // And it says which way: the product map lands in products.gtin since Lane
    // GQ. A summary that still read "nothing imports them yet" would be the
    // false alarm this class exists to avoid.
    expect($verdict['summary'])->toContain('imported into products.gtin');

    // What the importer sees today, and does not see.
    expect(YoastSeo::skipped($cells))->not->toContain('wpseo_global_identifier_values');

    // A file of nothing but the id and the identifiers is not even recognised
    // as a Yoast export, so it would be rejected row by row.
    expect(YoastSeo::looksLikeYoast([
        'id' => '4211',
        'wpseo_global_identifier_values' => '{"gtin13":"4006381333931"}',
    ]))->toBeFalse();
});

it('unpicks a barcode out of both encodings, and validates it', function () {
    /*
     * THE SECOND TRAP: the value is a MAP, not a string. WordPress stores it
     * serialized; a CSV exporter emits either PHP's serialization or JSON. So
     * even a reader that found the column would get
     * `a:1:{s:6:"gtin13";s:13:"...";}` rather than a barcode.
     *
     * MUTATION: drop the unserialize() fallback. Red on the second case.
     */
    $json = ['wpseo_global_identifier_values' => '{"gtin13":"4006381333931"}'];
    $php = ['wpseo_global_identifier_values' => serialize(['gtin13' => '4006381333931'])];

    expect(YoastTiers::gtinFrom($json))->toBe('4006381333931');
    expect(YoastTiers::gtinFrom($php))->toBe('4006381333931');
});

it('returns nothing rather than a barcode it cannot vouch for', function () {
    /*
     * A wrong GTIN is worse than none: Google matches products on it, so a
     * transposed pair of digits attaches this shop's price and stock to
     * somebody else's product. Four ways of having no answer, and all four must
     * produce the same nothing.
     *
     * MUTATION: drop the Gtin::isValid() half of gtinFrom(). Red on the first
     * case — the number is thirteen digits and passes normalise().
     */
    // Same as the good one but for its final character: the exact mistake the
    // check digit exists to catch.
    expect(YoastTiers::gtinFrom(['wpseo_global_identifier_values' => '{"gtin13":"4006381333930"}']))->toBeNull();

    /*
     * An MPN is not a GTIN. It has no check digit and no issuing authority, and
     * putting one in products.gtin publishes a confident wrong identifier
     * against a field Google matches on.
     *
     * TWO FIXTURES, because the first one alone does not bite. "RL-100ML" is
     * refused by Gtin::normalise() for having letters in it, so a reader that
     * happily looked at `mpn` would still come back null and look correct —
     * adding 'mpn' to IDENTIFIER_KEYS as a mutation leaves it green. A
     * manufacturer part number is often all digits, and one that happens to be
     * thirteen of them with an agreeing check digit is indistinguishable from a
     * barcode by shape alone. The only thing that keeps it out is the key not
     * being read, so the second fixture is the one that proves it.
     *
     * MUTATION: add 'mpn' to YoastTiers::IDENTIFIER_KEYS. Red on the second.
     */
    expect(YoastTiers::gtinFrom(['wpseo_global_identifier_values' => '{"mpn":"RL-100ML"}']))->toBeNull();
    expect(YoastTiers::gtinFrom(['wpseo_global_identifier_values' => '{"mpn":"4006381333931"}']))->toBeNull();

    // Not a map at all.
    expect(YoastTiers::gtinFrom(['wpseo_global_identifier_values' => 'yes']))->toBeNull();

    // Absent.
    expect(YoastTiers::gtinFrom(ytFullPrefix()))->toBeNull();
});

it('reads the three spellings a real export uses for a prefixed key', function () {
    /*
     * WordPress exporters disagree about the prefix: a postmeta dump keeps
     * `_yoast_wpseo_metadesc`, WP All Export writes `yoast_wpseo_metadesc`, and
     * some write the bare `metadesc`. YoastSeo already accepts all three and
     * this table has to match it or the census reports zero rows against a file
     * the importer reads perfectly.
     *
     * MUTATION: return only [$meta] from spellings(). Red on the second and
     * third.
     */
    foreach (['_yoast_wpseo_metadesc', 'yoast_wpseo_metadesc', 'metadesc'] as $column) {
        expect(YoastTiers::present(['id' => '1', $column => 'A toner.']))
            ->toContain('_yoast_wpseo_metadesc');
    }
});

it('does not invent a prefix for the add-on keys', function () {
    /*
     * The other half of the same rule. Stripping `_yoast_wpseo_` off
     * `wpseo_global_identifier_values` would invent a column called
     * `global_identifier_values` that no exporter writes, and matching on it
     * would be matching on nothing.
     *
     * MUTATION: apply the prefix-stripping to every key in spellings(). This
     * case stays green — the invented spelling matches nothing real — which is
     * recorded because it looks as though it should catch it. What the mutation
     * actually costs is a FALSE POSITIVE on a file that happens to carry a
     * column of that name, which no fixture can honestly supply.
     */
    expect(YoastTiers::present(['id' => '1', 'global_identifier_values' => '{"gtin13":"4006381333931"}']))
        ->toBe([]);
});

it('says out loud that a blank column is not a value', function () {
    /*
     * Yoast writes '' for a product whose SEO tab was never opened, which is
     * most rows in a real export. Counting those as present would report every
     * key on every row and say nothing at all.
     *
     * MUTATION: drop the `$value !== ''` test in YoastTiers::value(). Red.
     */
    expect(YoastTiers::present(['id' => '1', '_yoast_wpseo_metadesc' => '  ']))->toBe([]);
});

it('names a wpseo column it has never heard of instead of passing over it', function () {
    /*
     * "We did not import it" and "we never heard of it" are the same bytes on
     * disk afterwards, and only one of them is a decision. A key this table
     * cannot attribute is reported as unattributed.
     *
     * MUTATION: return [] from unrecognised(). Red.
     */
    $cells = ytFullPrefix(['_yoast_wpseo_some_future_field' => 'x']);

    expect(YoastTiers::unrecognised($cells))->toBe(['_yoast_wpseo_some_future_field']);
    expect(YoastTiers::line('_yoast_wpseo_some_future_field'))->toContain('unrecognised');

    // And a blank one is not reported, for the same reason a blank mapped
    // column is not: it is what an untouched product looks like.
    expect(YoastTiers::unrecognised(ytFullPrefix(['_yoast_wpseo_some_future_field' => ''])))->toBe([]);
});

it('gives every key a line naming its tier and what happens to it', function () {
    /*
     * The line is the census. EntityReport counts notes by their text, so one
     * note per present key per row comes out of a run as "N × <this line>" —
     * the key, the tier, the disposition and the number of rows carrying it,
     * with no new report channel and no run-level state.
     *
     * The three dispositions have to be distinguishable at a glance, and the
     * third is the one worth having: a key with somewhere to go that nothing
     * reads.
     */
    expect(YoastTiers::line('_yoast_wpseo_metadesc'))
        ->toContain('Yoast SEO (free)')
        ->toContain('imported');

    expect(YoastTiers::line('_yoast_wpseo_focuskeywords'))
        ->toContain('Yoast SEO PREMIUM')
        ->toContain('no home for it');

    /*
     * The third disposition -- "somewhere to go and nothing reads it" -- was
     * true of this key until Lane GQ's census wired SeoImporter to
     * gtinFrom(). It is now the per-VARIATION map that has somewhere to go and
     * nothing to carry it, so that is the key asserted on: the distinction is
     * the point of the three dispositions and it has to be asserted wherever it
     * is currently true, not wherever it used to be.
     */
    expect(YoastTiers::line('wpseo_global_identifier_values'))
        ->toContain('Yoast WooCommerce SEO ADD-ON')
        ->toContain('imported');

    expect(YoastTiers::line('wpseo_variation_global_identifiers_values'))
        ->toContain('Yoast WooCommerce SEO ADD-ON')
        ->toContain('THERE IS SOMEWHERE FOR IT TO GO');
});

it('answers the question even when the export carries nothing', function () {
    /*
     * "No Yoast data at all" is a usable answer to "does the export carry
     * product-schema data" and "probably free" is not. An empty verdict has to
     * say the first thing.
     */
    expect(YoastTiers::verdict([])['summary'])
        ->toContain('no Yoast key of any tier carried a value');
});

it('carries every key the importer already knows about', function () {
    /*
     * The two tables must not drift. YoastSeo::MAPPED and ::UNMAPPED are what
     * the importer acts on; this one is what the report is built from, and a
     * key in the first that is missing from the second is a row the run imports
     * or drops without a word about it.
     *
     * The reverse is deliberately NOT asserted: this table is WIDER on purpose.
     * It names the Premium and add-on keys the importer has never had a line
     * for, and three free-tier scores (inclusive_language_score,
     * seo_title_score, meta_description_score) that YoastSeo::UNMAPPED does not
     * list either — so an export carrying them is today neither imported nor
     * reported.
     */
    $known = array_keys(YoastTiers::KEYS);
    $acted = [...array_keys(YoastSeo::MAPPED), ...YoastSeo::UNMAPPED];

    /*
     * array_diff and not a toContain() per key, and that is not a style
     * preference. Pest's toContain() is VARIADIC, so the failure message this
     * loop first carried was read as a SECOND NEEDLE — the assertion became
     * "contains both the key and the sentence", which is a different question
     * and, under `not`, one that cannot fail. ExpectationsThatCannotFailTest
     * sweeps the suite for exactly that shape every run; this file tripped it
     * on the first execution and the repair is the mechanical one that file
     * prescribes. The diff also reports EVERY missing key at once rather than
     * stopping at the first.
     */
    $missing = array_values(array_diff($acted, $known));

    expect($missing)->toBe([], 'Keys the importer acts on with no line in YoastTiers::KEYS: '.implode(', ', $missing));

    // And the three that prove the table is wider.
    expect(YoastSeo::UNMAPPED)->not->toContain('_yoast_wpseo_seo_title_score');
    expect($known)->toContain('_yoast_wpseo_seo_title_score');
});
