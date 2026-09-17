<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CollectionController;
use App\Services\Translation\InterfaceStrings;
use App\Support\RepeatPurchase;
use Tests\Support\ArabicShop;

/**
 * Lane FB — the four curated listings, which had an English heading and an
 * English sentence under it on an Arabic page.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * /new-in/, /best-sellers/, /super-sale/ and /everything-under-54-aed/ are the
 * header links. Their wording is not in a Blade file, so Lane EU's conversion
 * of 94 templates never reached it: seven strings sitting in
 * Store\CollectionController::COLLECTIONS and two more in
 * App\Support\RepeatPurchase, rendered into an otherwise Arabic page as the
 * <h1> and the paragraph beneath it.
 *
 * ── WHY THE CONSTANTS ARE STILL THERE ───────────────────────────────────────
 *
 * COLLECTIONS carries the SELECTION MODE in the same row as the wording, and a
 * const cannot call __() — the same pair of reasons Facets::SORTS keeps its
 * constant. The mode ('newest', 'popular', 'on_sale', 'budget') is what show()
 * switches on and it is untouched, so nothing that decides which products
 * appear can be moved by a translation.
 *
 * The drift guard at the bottom is the one that matters over time: two English
 * sources fail silently and in one direction, leaving an Arabic translation of
 * a sentence that is no longer on the page.
 */

/** The four listings, as [url, title key, intro key]. */
function fbCollections(): array
{
    return [
        ['/new-in/', 'store.collection.title_new_in', 'store.collection.intro_new_in'],
        ['/super-sale/', 'store.collection.title_super_sale', 'store.collection.intro_super_sale'],
        ['/everything-under-54-aed/', 'store.collection.title_under_54', 'store.collection.intro_under_54'],
    ];
}

it('says each listing\'s heading and its sentence in the shopper\'s language', function () {
    ArabicShop::on();

    foreach (fbCollections() as [$url, $titleKey, $introKey]) {
        ArabicShop::string($titleKey, 'عنوان-' . $titleKey);
        ArabicShop::string($introKey, 'وصف-' . $introKey);
    }

    foreach (fbCollections() as [$url, $titleKey, $introKey]) {
        $html = $this->get('/ar' . $url)->assertOk()->getContent();

        /*
         * THE HEADING IS READ OUT OF THE <h1>, not looked for anywhere on the
         * page. Three of these four listings are also links in the header menu,
         * and a menu item is a `menu_items` row rather than an interface string
         * — it reaches Arabic through the translations table against its own
         * id, which is a different lane's mechanism and not this one's to
         * assert on. An unscoped `not->toContain` would fail on the nav and
         * tell us nothing about the heading.
         */
        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $h1);

        expect($h1)->not->toBeEmpty('the listing rendered no <h1> to read the heading out of');

        expect($h1[1])
            ->toContain('عنوان-' . $titleKey)
            ->and($h1[1])->not->toContain((string) InterfaceStrings::english($titleKey));

        // The sentence underneath is this controller's alone and appears
        // nowhere else, so it is asserted against the whole document.
        expect($html)
            ->toContain('وصف-' . $introKey)
            ->and($html)->not->toContain((string) InterfaceStrings::english($introKey));
    }
});

it('says the best-sellers sentence in the shopper\'s language and still chooses it by measurement', function () {
    /*
     * /best-sellers/ is the one listing whose sentence is a MEASUREMENT rather
     * than a fixed line: RepeatPurchase only lets the page claim customers keep
     * coming back if the order history says some of them did. Translating it
     * must not move that condition — on a shop with no repeat purchases, which
     * is the state of this one in a fresh test database, it is still the
     * units-sold wording that is rendered, now in Arabic.
     */
    ArabicShop::on();

    ArabicShop::string('store.collection.title_best_sellers', 'الأكثر مبيعاً');
    ArabicShop::string('store.collection.intro_best_sellers_by_units', 'حسب عدد القطع المباعة.');
    ArabicShop::string('store.collection.intro_best_sellers_measured', 'ما يعود إليه عملاؤنا.');

    expect(RepeatPurchase::any())->toBeFalse('this shop has no repeat purchases, so the measured sentence must not be the one chosen');

    $html = $this->get('/ar/best-sellers/')->assertOk()->getContent();

    expect($html)
        ->toContain('الأكثر مبيعاً')
        ->toContain('حسب عدد القطع المباعة.')
        // The claim the measurement does not support is still not made — in
        // either language.
        ->and($html)->not->toContain('ما يعود إليه عملاؤنا.')
        ->and($html)->not->toContain(RepeatPurchase::INTRO_MEASURED)
        ->and($html)->not->toContain(RepeatPurchase::INTRO_BY_UNITS);
});

it('keeps an English source in the strings table for every word the constants carry', function () {
    /*
     * THE DRIFT GUARD. COLLECTIONS and RepeatPurchase remain the English source
     * and the place the argument for each sentence is written down;
     * InterfaceStrings holds what is actually rendered.
     */
    $reflected = new ReflectionClass(CollectionController::class);
    $collections = $reflected->getConstant('COLLECTIONS');

    $expected = [
        'new-in' => ['store.collection.title_new_in', 'store.collection.intro_new_in'],
        'best-sellers' => ['store.collection.title_best_sellers', null],
        'super-sale' => ['store.collection.title_super_sale', 'store.collection.intro_super_sale'],
        'under-54' => ['store.collection.title_under_54', 'store.collection.intro_under_54'],
    ];

    // Every row in the constant is covered, so adding a fifth listing without a
    // key fails here rather than shipping one English heading.
    expect(array_keys($collections))->toBe(array_keys($expected));

    $missing = [];

    foreach ($expected as $key => [$titleKey, $introKey]) {
        [$title, $intro] = $collections[$key];

        if (InterfaceStrings::english($titleKey) !== $title) {
            $missing[$titleKey] = $title;
        }

        if ($introKey !== null && InterfaceStrings::english($introKey) !== $intro) {
            $missing[$introKey] = $intro;
        }
    }

    // /best-sellers/ carries '' in the constant on purpose: its sentence is
    // RepeatPurchase's, and both of ITS wordings are keyed too.
    expect($collections['best-sellers'][1])->toBe('');

    if (InterfaceStrings::english('store.collection.intro_best_sellers_measured') !== RepeatPurchase::INTRO_MEASURED) {
        $missing['store.collection.intro_best_sellers_measured'] = RepeatPurchase::INTRO_MEASURED;
    }

    if (InterfaceStrings::english('store.collection.intro_best_sellers_by_units') !== RepeatPurchase::INTRO_BY_UNITS) {
        $missing['store.collection.intro_best_sellers_by_units'] = RepeatPurchase::INTRO_BY_UNITS;
    }

    expect($missing)->toBe([], sprintf(
        "A collection's English source and its strings-table entry have stopped agreeing:\n\n%s\n\n"
        . 'The constant is the English source; the strings table is what renders. '
        . 'They have to say the same thing, or the Arabic on the page is a translation '
        . 'of a sentence that is no longer in the code.',
        implode("\n", array_map(
            fn (string $k, string $v): string => "  {$k} => \"{$v}\"",
            array_keys($missing),
            $missing
        ))
    ));
});

it('leaves the price band the budget listing names exactly where it found it', function () {
    /*
     * 'Everything under AED 54' is the one collection title with a figure in
     * it, and the figure is coupled to the 5400-fil ceiling in show()'s
     * 'budget' arm. Money display is being changed under this lane by another,
     * so the rule this lane worked to was: key the sentence, move no figure.
     * This fails if the title's number and the query's ceiling ever stop
     * describing the same band.
     */
    $reflected = new ReflectionClass(CollectionController::class);
    $collections = $reflected->getConstant('COLLECTIONS');

    expect($collections['under-54'][0])->toBe('Everything under AED 54')
        ->and(InterfaceStrings::english('store.collection.title_under_54'))->toBe('Everything under AED 54');

    $source = file_get_contents((string) $reflected->getFileName());

    // 54 dirhams is 5400 fils, and the ceiling is still written as an integer
    // in the query rather than derived from anything this lane touched.
    expect($source)->toContain('COALESCE(NULLIF(sale_price, 0), price) <= ?')
        ->and($source)->toContain('[5400]');
});
