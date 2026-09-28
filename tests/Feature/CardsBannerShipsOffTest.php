<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\HomepageSections;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;

/**
 * RULE 1, ON THE MOST VISIBLE PAGE IN THE SHOP — Lane BN.
 *
 * "Nothing that already works may change. A lane fixes or adds the thing it was
 * given and leaves the rest of the shop byte-identical."
 *
 * StorefrontEnglishUnchangedTest is the instrument for the whole storefront and
 * it stays the instrument. This file is the ARGUMENT underneath it, and it is
 * narrower and harsher in one respect that matters: that test compares the shop
 * against its old self on an EMPTY database, so it would be equally green if
 * this section drew nothing because there was nothing to draw. This one fills
 * the tables — a published set, three published cards with pictures — and
 * asserts the homepage is STILL the same bytes, because the module is off and
 * no set is chosen. That is the real shipped state of the live shop the morning
 * after the package applies.
 *
 * ── WHAT THE DEFECT WOULD LOOK LIKE ON THE SHOP ─────────────────────────────
 *
 * Two shapes, and both have shipped in this repository before:
 *
 *   1. THE EMPTY WRAPPER. The <section> written AROUND the @if instead of
 *      inside it. Every homepage on earth gains an empty element, a class
 *      attribute and a divider rule above it — a diff with no words in it,
 *      which StorefrontEnglishUnchangedTest reports and nobody can tell from a
 *      copy change. Lane IG's own notes in store/home.blade.php record paying
 *      for this at byte 51624.
 *
 *   2. THE STRAY NEWLINE. A Blade comment is replaced by the empty string and
 *      ITS TRAILING NEWLINE SURVIVES, where a line holding only a directive
 *      contributes nothing. The video rail block cost two newlines on every
 *      homepage on earth, written the readable way.
 *
 * ── WHAT EACH HALF OF THE PROOF IS FOR, HONESTLY ───────────────────────────
 *
 * The four byte-comparison cases below take their "before" from the SAME tree,
 * so on their own they prove "filling the tables changes nothing" and NOT
 * "adding this feature changed nothing". That second claim belongs to
 * StorefrontEnglishUnchangedTest, which materialises resources/views at
 * BASE_COMMIT and renders the page twice in one process — and it is the
 * instrument this lane was told to read the diff of rather than pin around.
 * Saying so here rather than letting the file imply otherwise: a test that
 * looks like it proves more than it does is the fault this repository keeps
 * finding.
 *
 * So the empty-wrapper shape is caught HERE by a property that needs no
 * "before" at all — an empty `<div class="wrap"></div>` is furniture with
 * nothing in it, and the shipped homepage has exactly none.
 *
 * MUTATION NOTES, and all three were run.
 *
 *   1. Move the `<section …>` line in store/home.blade.php outside its
 *      `@if ($bnSection !== null)`. "the homepage carries no empty content
 *      wrapper" goes RED — the page gains exactly one
 *      `<div class="wrap"></div>` where it had none. StorefrontEnglishUnchanged
 *      Test goes red with it.
 *   2. Un-glue the `@unless` from the end of the Blade comment above it — put
 *      it on its own line. Nothing in THIS file moves, because a newline is
 *      added to both sides of its comparisons; StorefrontEnglishUnchangedTest
 *      reports the homepage as changed, which is the whole reason that file
 *      exists and why this one does not pretend to do its job.
 *   3. Move 'cards_banner' after 'categories' in HomepageSections::REGISTRY.
 *      "keeps the homepage section list in the order the template draws it"
 *      goes red.
 *
 * All three were applied, observed, and put back.
 */
function bnShop(): void
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create([
        'name' => 'Ramadan',
        'slug' => 'ramadan',
        'status' => 'publish',
        'position' => 0,
    ]);

    foreach (range(1, 3) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bn-'.$i.'.webp',
            'alt' => 'Card '.$i,
            'heading' => 'Heading '.$i,
            'body' => 'One short line under it.',
            'button_label' => 'Shop now',
            'button_url' => '/shop/',
            'image_w' => 900,
            'image_h' => 1200,
            'position' => $i,
            'status' => 'publish',
        ]);
    }
}

/** The homepage's bytes, with the section in whatever state the caller set. */
function bnHome(): string
{
    return test()->get('/')->assertOk()->getContent();
}

it('draws not one byte with the module off, however full the tables are', function () {
    /*
     * AND THE SET IS CHOSEN, which is the case worth testing rather than the
     * easy one. An owner who picks a set and then switches the section off must
     * get the page he had before; the empty-tables version of this passes
     * against a feature whose gate does not work at all, because there would be
     * nothing to draw either way.
     *
     * MUTATION: delete the `enabled()` short-circuit at the top of
     * Banners::forHome() and this goes red — the row appears on a shop that
     * switched it off. Run, red, put back. (The earlier version of this case,
     * which left the setting at "None", stayed GREEN under that mutation, which
     * is why it now chooses a set.)
     */
    $bare = bnHome();

    bnShop();

    app(SettingsService::class)->setModuleSetting(
        Banners::MODULE, 'set', (string) BannerSet::query()->firstOrFail()->id
    );

    expect(app(Banners::class)->enabled())->toBeFalse()
        ->and(bnHome())->toBe($bare);
});

it('draws not one byte with the module ON but no set chosen', function () {
    $bare = bnHome();

    bnShop();

    app(SettingsService::class)->setModule('cards_banner', true);

    expect(app(Banners::class)->enabled())->toBeTrue()
        ->and(bnHome())->toBe($bare);
});

it('draws not one byte when the chosen set is a draft', function () {
    $bare = bnHome();

    bnShop();

    $set = BannerSet::query()->firstOrFail();
    $set->update(['status' => 'draft']);

    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    /*
     * A DRAFT IS THE CASE THAT LOOKS SAFE AND IS NOT. The owner picks a set,
     * publishes it, sees it, then drafts it again to work on it — and every
     * shopper on the site must go back to the page they had before. Nothing in
     * the template asks about `status`: the filter is inside the one join, which
     * is why it cannot be forgotten on one code path and remembered on another.
     */
    expect(bnHome())->toBe($bare);
});

it('draws not one byte when the chosen set has no drawable card', function () {
    $bare = bnHome();

    bnShop();

    // A card with no picture is an empty box the width of its neighbours, which
    // is worse than one card fewer. Every card here is text-only.
    BannerCard::query()->update(['image' => '']);

    $set = BannerSet::query()->firstOrFail();
    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    expect(bnHome())->toBe($bare);
});

it('DOES draw the row once the owner has turned it on and chosen a set', function () {
    /*
     * THE OTHER HALF, and it is not optional: a "ships off" test on its own is
     * satisfied by a feature that never works at all, which is precisely the
     * fault docs/FO-HOMEPAGE-INVENTORY.md catalogues and ModuleFrameworkGuardTest
     * was written for — `instagram` sat in the registry for a whole release
     * moving a row on a screen and nothing on the shop.
     */
    $bare = bnHome();

    bnShop();

    $set = BannerSet::query()->firstOrFail();
    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $html = bnHome();

    expect($html)->not->toBe($bare)
        ->and($html)->toContain('kbbn-vp')
        ->and($html)->toContain('uploads/banners/bn-1.webp')
        ->and(substr_count($html, 'class="kbbn-c'))->toBe(6); // three cards, doubled track
});

it('leaves the homepage carrying no empty content wrapper', function () {
    /*
     * THE EMPTY-WRAPPER SHAPE, caught without needing a "before".
     *
     * `<section class="sec …"><div class="wrap"></div></section>` is what this
     * section becomes if its element is written AROUND its @if instead of
     * inside it: a new element, a new class attribute and a divider rule above
     * it, on every homepage on earth, for a shop that has configured nothing.
     * Lane IG's notes in store/home.blade.php record paying for exactly this.
     *
     * The shipped homepage has NO empty content wrapper — measured, not
     * assumed — so the absence of one is a real assertion rather than a
     * tautology, and it is red under mutation 1 in this file's header.
     */
    bnShop();

    app(SettingsService::class)->setModule('cards_banner', true);

    expect(bnHome())->not->toContain('<div class="wrap"></div>');
});

it('ships the module off in the registry and the setting at None', function () {
    // The two values the package applies with. A default the owner did not ask
    // for is the one thing rule 1 allows nobody.
    expect(ModuleRegistry::REGISTRY['cards_banner'][3])->toBeFalse()
        ->and(app(Banners::class)->enabled())->toBeFalse()
        ->and(app(Banners::class)->all()['set'])->toBe('')
        ->and(app(Banners::class)->forHome())->toBeNull();
});

it('keeps the homepage section list in the order the template draws it', function () {
    /*
     * REGISTRY order IS the default order — orderIsDefault() compares
     * array_keys($all) against array_keys(REGISTRY) — so a key whose position
     * here disagrees with store/home.blade.php hands Appearance → Homepage a
     * picture of a page the shop does not draw. That is the fault settle() and
     * HomepageLayouts::summaries() were both written about.
     *
     * MUTATION: move 'cards_banner' after 'categories' in
     * HomepageSections::REGISTRY and this goes red. Run, red, put back.
     */
    $keys = array_keys(HomepageSections::REGISTRY);
    $home = (string) file_get_contents(base_path('resources/views/store/home.blade.php'));

    expect(array_slice($keys, 0, 5))->toBe(['hero', 'delivery', 'ticker', 'cards_banner', 'categories'])
        ->and(strpos($home, "hidden('cards_banner')"))->toBeLessThan((int) strpos($home, "hidden('categories')"))
        ->and(strpos($home, "hidden('cards_banner')"))->toBeGreaterThan((int) strpos($home, "hidden('ticker')"));
});
