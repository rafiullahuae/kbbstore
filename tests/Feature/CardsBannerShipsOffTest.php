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
 * asserts the homepage is STILL the same bytes for every state in which the
 * section is not supposed to draw.
 *
 * ▲ THE SHIPPED STATE IN THAT LIST HAS CHANGED, AND THE FILE'S NAME IS NOW
 *   HALF RIGHT.                                                     (Lane SEC)
 *
 * The module shipped OFF, and "off with the tables full" was the state of the
 * live shop the morning after the package applied. The owner has since asked
 * for the banner to BE the homepage's banner — "the banner i need to change to
 * simple image banners, not cards ... apply this on desktop and mobile both" —
 * and for it to be applied rather than offered. So the module ships ON with no
 * set chosen, which draws exactly the same nothing for a different reason, and
 * the four "not one byte" cases below now set the state they are testing
 * instead of inheriting it. The name is left alone: renaming a test file loses
 * its history for a word, and the gates it covers are the same four gates.
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
        /*
         * ▲ `kind` IS NAMED NOW, AND IT HAS TO BE.                  (Lane SEC)
         * BannerSet::$attributes ships a new set as a picture slider, so a seed
         * that left this out stopped seeding the thing this file is named after
         * — every "not one byte" case below went on passing, and the one case
         * that renders the row went red looking for `kbbn-c` in a slider. The
         * cards treatment is still selectable per set, which is what this seed
         * now says out loud.
         */
        'kind' => 'cards',
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
    /*
     * ▲ THE MODULE IS SWITCHED OFF HERE NOW, WHERE IT USED TO BE OFF ALREADY.
     *                                                              (Lane SEC)
     * It shipped off and this case relied on that. It ships ON — the owner
     * asked for the banner to be the homepage's banner rather than a switch to
     * find — so the case switches it off itself. WHAT IS ASSERTED IS UNCHANGED
     * and it is the thing that can still regress: the gate works, so an owner
     * who turns the section off gets back exactly the page he had.
     *
     * The mutation note below still holds word for word, and was re-run.
     */
    $bare = bnHome();

    bnShop();

    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', false);
    $settings->setModuleSetting(
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

it('gives the hero no divider mark while the banner above it draws nothing', function () {
    /*
     * ── THE DEFECT, AND WHAT IT LOOKED LIKE ON THE SHOP ─────────────────────
     *
     * A tick rule directly under the header on every phone homepage of every
     * shop that had not uploaded a banner picture. One element, no words, on
     * the most visible page there is.
     *
     * HOW IT GOT THERE. `cards_banner` became the FIRST key in
     * HomepageSections::REGISTRY this round, because the picture banner is the
     * homepage's banner now and is drawn above the hero. Dividers ship ON
     * (SectionDividers: style `ticks`, scope `all`, `first` off, desktop off),
     * and HomepageSections::frameClass() suppresses the mark above "the first
     * section" — which it read as array_key_first(), the first key in the
     * ORDER. `cards_banner` is the one section in the registry whose <section>
     * is inside its own @if, so on a shop with no picture the first key is not
     * in the document at all and the hero, which IS, counted as second.
     *
     * THE FIX is firstDrawnKey(): the first key that will be in the page, with
     * `cards_banner` assumed absent until store/home.blade.php says it is
     * there. Written that way round on purpose — HomepageSections::draws()
     * carries the reason, which is that StorefrontEnglishUnchangedTest renders
     * BASE_COMMIT's Blade against the working tree's PHP.
     *
     * MUTATION, run: change firstDrawnKey() back to array_key_first($all) and
     * the first expectation is red with `class="sec dv"`; the second stays
     * green, which is what makes them two assertions and not one.
     *
     * AND WHY IT IS HERE RATHER THAN LEFT TO THE WALK.
     * StorefrontEnglishUnchangedTest did not report it: its database leaves
     * Appearance → Section dividers alone and its seed renders with the marks
     * off, so `dv` appeared on neither side. The dividers are turned ON here,
     * explicitly, which is the whole reason this case can see it.
     */
    $dividers = app(\App\Services\SectionDividers::class);
    $dividers->save(['style' => 'ticks', 'scope' => 'all', 'first' => false, 'desktop' => true]);
    SettingsService::forgetMemo();

    expect($dividers->classFor('hero'))->toBe('dv', 'the dividers are off, so this case proves nothing');

    // 1. NO PICTURES: the hero is the first section in the document and carries
    //    no mark, exactly as it did before the banner moved above it.
    bnShop();
    $bare = bnHome();

    expect($bare)->toContain('<section class="sec " style="padding-top:14px">')
        ->and($bare)->not->toContain('<section class="sec dv" style="padding-top:14px">');

    // 2. PICTURES: the banner IS the first section, so it carries no mark and
    //    the hero — now genuinely second — carries one. A divider between two
    //    sections is what the setting asks for.
    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) BannerSet::query()->firstOrFail()->id);

    $withBanner = bnHome();

    /*
     * ▲ THE BANNER'S SECTION GAINED `kbb-secw-bleed` — Lane BG, and the pin is
     * advanced rather than loosened to a substring.
     *
     * The owner asked for the picture banner to run full width, so
     * HomepageSections::WIDTH_DEFAULTS ships `cards_banner` at `bleed` and
     * frameClass() writes the class. What this case asserts is UNCHANGED and is
     * still the whole of it: the banner is the first section that draws, so it
     * carries NO `dv` mark and the hero — now genuinely second — carries one.
     * The class attribute is matched whole on purpose, because a `toContain`
     * on `'class="sec'` alone would pass on a page with the mark back.
     */
    expect($withBanner)->toContain('<section class="sec dv" style="padding-top:14px">')
        ->and($withBanner)->toContain('<section class="sec kbb-secw-bleed" style="padding-top:8px">')
        ->and($withBanner)->not->toContain('<section class="sec kbb-secw-bleed dv"');
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

it('ships the module ON with no set chosen, so it is armed and drawing nothing', function () {
    /*
     * ▲ THIS CASE SAID "off in the registry and the setting at None", AND THE
     *   FIRST HALF IS NOW THE OPPOSITE ON INSTRUCTION.              (Lane SEC)
     *
     * Its old comment: "The two values the package applies with. A default the
     * owner did not ask for is the one thing rule 1 allows nobody." He asked
     * for this one — "the banner i need to change to simple image banners ...
     * apply this on desktop and mobile both", and then "i want to apply such
     * things directly to the site to save time" — which is rule 1's one
     * exception and, under the reversed rule, the ordinary case.
     *
     * ── AND THE SECOND HALF IS WHY TURNING IT ON MOVES NO PIXEL ─────────────
     *
     * The setting is still at None on a fresh install, and `forHome()` returns
     * null while it is. So the module being on buys the owner one thing only:
     * he picks a set and sees it, instead of picking a set, seeing nothing, and
     * hunting for a switch. THAT is the whole claim, and the third assertion is
     * what keeps it honest — an "on by default" that also drew something by
     * default would be this lane changing the front page of a live shop on a
     * guess about what is in its tables.
     *
     * The migration `banner_ships_as_image_slider` does choose a set, but only
     * when there is exactly one that would draw. That is asserted in
     * BannerShipsAsImageSliderTest, against real rows, rather than here.
     *
     * MUTATION: put the registry's fourth element back to `false` and the
     * first expectation is red while the other two stay green — which is the
     * point of asserting the toggle and the emptiness separately.
     */
    expect(ModuleRegistry::REGISTRY['cards_banner'][3])->toBeTrue()
        ->and(app(Banners::class)->enabled())->toBeTrue()
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
     * ▲ THE EXPECTED SEQUENCE MOVED, AND THE PROPERTY DID NOT.      (Lane SEC)
     * It read ['hero', 'delivery', 'ticker', 'cards_banner', 'categories'].
     * The picture banner is the homepage's banner now and is drawn above the
     * hero band, so the registry's first key is `cards_banner` and the third
     * assertion below is inverted: the banner's @unless comes BEFORE the hero
     * band's, not after the ticker's. What is asserted is unchanged — that this
     * list and the template agree — which is the only thing that keeps
     * Appearance → Homepage showing the page the shop actually draws.
     *
     * MUTATION: move 'cards_banner' after 'categories' in
     * HomepageSections::REGISTRY and this goes red. Run, red, put back.
     */
    $keys = array_keys(HomepageSections::REGISTRY);
    $home = (string) file_get_contents(base_path('resources/views/store/home.blade.php'));

    expect(array_slice($keys, 0, 5))->toBe(['cards_banner', 'hero', 'delivery', 'ticker', 'categories'])
        ->and(strpos($home, "hidden('cards_banner')"))->toBeLessThan((int) strpos($home, "hidden('categories')"))
        ->and(strpos($home, "hidden('cards_banner')"))->toBeLessThan((int) strpos($home, "bandHidden('hero')"));

    /*
     * AND SIGNATURE IS array_keys(REGISTRY), exactly. Its own claim is "every
     * section on, in the order the site uses today", and
     * HomepageSections::orderIsDefault() compares the applied sequence against
     * the registry's keys — so a preset that disagrees makes "put the shipped
     * order back" put back something else. Asserted here rather than left to
     * HomepageSectionOrderTest's indirect version, which reports it as a
     * stylesheet that would not go away.
     */
    expect(\App\Services\HomepageLayouts::LAYOUTS['signature']['sections'])->toBe($keys);
});
