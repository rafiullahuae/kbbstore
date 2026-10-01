<?php

declare(strict_types=1);

use App\Services\HeaderSettings;
use Illuminate\Support\Facades\View;

/**
 * THE COUNTRIES STRIP MOVED UNDER THE BANNER, ON THE HOME PAGE ONLY.
 *                                                                  Lane SEC
 *
 * The owner, with a red arrow drawn on a screenshot of his own phone running
 * from the strip at the very top of the page down to below the hero: "The top
 * countries bar, i need under banner ... apply this on desktop and mobile
 * both."
 *
 * ── WHY THIS FILE HAD TO BE WRITTEN, AND IT IS THE WHOLE REASON ─────────────
 *
 * StorefrontEnglishUnchangedTest CANNOT SEE THIS CHANGE. Not "did not" — the
 * walk is structurally blind to it, and I only believed it after mutating the
 * partial to prove the walk renders the strip at all.
 *
 * EnglishRenderWalk::approvedInsertions() carries a rule called "the flag bar
 * above the header (Lane FB)" whose pattern is
 *
 *     #<div class="kfb [^>]*>\s*<div class="kfb-in">.*?</div>\s*</div>\n#s
 *
 * applied to the AFTER side with `hits => 31`. It CUTS THE STRIP OUT, wherever
 * it sits, on each of the 31 pages that draw one, and it consumes the trailing
 * newline with it. So the strip travelling 21 kilobytes down the home page
 * removes the same bytes from the same document and leaves the same remainder:
 * the count is still 31, nothing else on the page moved, and that test is
 * GREEN. Measured on the tree this file was written against — `class="kfb` at
 * byte 8274 before the move and 29827 after it, and three passes green either
 * way.
 *
 * That is not a fault in the walk. Its rule was written narrow on purpose and
 * cutting an element is what lets the other thirty-nine pages be compared byte
 * for byte. But it means the walk's green says nothing at all about WHERE the
 * strip is, and the position is the entire deliverable. So the position is
 * pinned here, on both sides of the claim:
 *
 *   - on the home page the strip comes AFTER the header, and exactly once
 *   - on every other page it comes BEFORE the header, and exactly once
 *
 * ── ONCE, NOT "NOT ZERO" ────────────────────────────────────────────────────
 *
 * CLAUDE.md: zero is the "built, never wired up" shape this repository keeps
 * finding, and two is the one that registers a thing twice. Two is the live
 * risk here and not a hypothetical: the claim is made by
 * `@section('flagbar-placed')` in the child and read by `View::hasSection()`
 * in the layout, and if the layout's condition were dropped while the child's
 * include stayed, every home page on the shop would carry TWO countries
 * strips. `substr_count()` is what catches that; `toContain()` would not.
 *
 * MUTATIONS, all four run:
 *   1. Drop `&& ! View::hasSection('flagbar-placed')` from layouts/store —
 *      the home page has two strips; the first case is red on the count.
 *   2. Drop `@section('flagbar-placed', '1')` from store/home — same failure,
 *      from the other end.
 *   3. Drop the include at the bottom of the home page's banner block — the
 *      home page has none at all; red on the count, at zero.
 *   4. Move the home page's include back above the banner — the count is 1 and
 *      the ORDER case is red, which is the assertion the count cannot make.
 */

/**
 * Where the strip's OUTER element sits in a page, and how many times.
 *
 * ── THE TRAILING SPACE IN THE NEEDLE IS LOAD-BEARING ────────────────────────
 *
 * `<div class="kfb` counts TWO per strip and not one, because the strip is two
 * nested elements and the inner one is `<div class="kfb-in">` — which has the
 * shorter needle as a prefix. Written that way every count below read 2 for a
 * page with one strip, and a lane that had expected 2 would have pinned a
 * number that says nothing: it stays 2 whether the page draws one strip or
 * whether the inner div were deleted and a second outer one added.
 *
 * This is the ambiguous-needle class Lane PLC catalogued, found the expensive
 * way in the first run of this file. `<div class="kfb ` — with the space that
 * separates the class from `flagBarClass()`'s own — matches the outer element
 * and nothing else, which is why EnglishRenderWalk's own rule for this element
 * is written `<div class="kfb [^>]*>`.
 */
function fbubFind(string $path): array
{
    $html = test()->get($path)->assertOk()->getContent();

    return [
        'strip' => strpos($html, '<div class="kfb '),
        'header' => strpos($html, '<header'),
        'count' => substr_count($html, '<div class="kfb '),
        'html' => $html,
    ];
}

/*
 * The strip ships OFF since Lane PI-B ("Turn off the top countries bar
 * entirely for now" — FlagBarTest's first case pins that). Where it is drawn
 * when the owner switches it back on is still this file's whole subject, so
 * every case here starts with it on.
 */
beforeEach(function () {
    app(HeaderSettings::class)->save(['fb_mobile' => true, 'fb_desktop' => true]);
});

it('draws the strip under the banner on the home page, exactly once', function () {
    /*
     * `<header` AND NOT THE BANNER ITSELF, and the difference is the point.
     * The banner section is conditional twice over — the owner can hide it on
     * Appearance → Homepage, and Banners::forHome() answers null while no set
     * is chosen — so on a database with no banner pictures, which is this
     * suite's database and also a real shop's, there IS no banner element to be
     * under. What can be asserted on every shop is the half that is the actual
     * complaint: the strip is no longer the first thing on the page, above the
     * header. Where it sits relative to a banner that exists is asserted in the
     * next case, with a banner seeded.
     */
    $home = fbubFind('/');

    expect($home['count'])->toBe(1, 'the home page draws the countries strip '
        .$home['count'].' times; one claim, one include, one strip');

    expect($home['strip'])->not->toBeFalse('no countries strip on the home page at all');
    expect($home['header'])->not->toBeFalse('no <header> on the home page, so this case proves nothing');

    expect($home['strip'])->toBeGreaterThan(
        (int) $home['header'],
        'the countries strip is still above the header on the home page'
    );
});

it('leaves the strip above the header on every other page', function () {
    /*
     * THE HALF THAT IS RULE 1. "A lane fixes or adds the thing it was given and
     * leaves the rest of the shop byte-identical." The owner's arrow was drawn
     * on his home page and only the home page has a banner, so the shop, the
     * cart and a product page must be exactly as they were — which is a claim
     * the walk above CAN see, and this is the cheap direct version of it.
     */
    foreach (['/shop/', '/cart'] as $path) {
        $page = fbubFind($path);

        expect($page['count'])->toBe(1, $path.' draws the countries strip '.$page['count'].' times');
        expect($page['strip'])->not->toBeFalse('no countries strip on '.$path);
        expect($page['header'])->not->toBeFalse('no <header> on '.$path);

        expect((int) $page['strip'])->toBeLessThan(
            (int) $page['header'],
            $path.' moved its countries strip below the header; only the home page places its own'
        );
    }
});

it('places the claim and the include exactly once each in the templates', function () {
    /*
     * THE WIRING, pinned in its FINISHED state rather than as an absence.
     *
     * Three counts and each is 1. The layout's condition, the home page's
     * claim, and the home page's own include. Zero on any of the three is the
     * feature half-applied — a package that shipped one template and not the
     * other is the shape the round's clear_caches migration exists for, and
     * two compiled Blades cached separately is exactly how that happens.
     */
    $layout = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));
    $home = (string) file_get_contents(resource_path('views/store/home.blade.php'));

    expect(substr_count($layout, "View::hasSection('flagbar-placed')"))->toBe(1)
        ->and(substr_count($layout, "@include('partials.flag-bar')"))->toBe(1)
        ->and(substr_count($home, "@section('flagbar-placed', '1')"))->toBe(1)
        ->and(substr_count($home, "@include('partials.flag-bar')"))->toBe(1);
});

it('still hides the strip on both widths when the owner switches it off', function () {
    /*
     * AND THE SWITCH STILL WORKS FROM ITS NEW PLACE, which is not free: the
     * home page's include has its own `flagBarOn()` guard and a second guard is
     * a second thing to forget. With both switches off the home page carries no
     * strip and neither does the shop.
     *
     * MUTATION: drop the `@if` around the home page's include and this is red
     * at 1 rather than 0, while every other case in this file stays green.
     */
    app(HeaderSettings::class)->save(['fb_mobile' => false, 'fb_desktop' => false]);

    expect(app(HeaderSettings::class)->flagBarOn())->toBeFalse();

    expect(fbubFind('/')['count'])->toBe(0, 'the home page drew a strip the owner switched off');
    expect(fbubFind('/shop/')['count'])->toBe(0, 'the shop drew a strip the owner switched off');
});

it('draws the strip under a real banner when there is one', function () {
    /*
     * THE OWNER'S OWN SENTENCE, with a banner on the page to be under. The
     * other cases above use `<header>` as the landmark because this suite's
     * database has no banner pictures; this one seeds them, so the assertion is
     * the literal claim: the strip comes after the banner's own frame.
     */
    $set = \App\Models\BannerSet::create([
        'name' => 'Under', 'slug' => 'fbub-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    foreach (range(1, 2) as $i) {
        \App\Models\BannerCard::create([
            'banner_set_id' => $set->id, 'image' => 'uploads/banners/fbub-'.$i.'.webp',
            'alt' => 'A'.$i, 'position' => $i, 'status' => 'publish',
        ]);
    }

    $settings = app(\App\Services\SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

    $home = fbubFind('/');

    /*
     * str_contains AND NOT ->toContain($needle, $message). Pest's `toContain`
     * is VARIADIC: a second argument is a second needle, not a message, so the
     * readable form silently asserts the sentence is in the page and fails on a
     * correct one. Paid for in an earlier round of this lane; the repository's
     * one-line form for a message on a substring is this.
     */
    expect(str_contains($home['html'], 'kbbs-vp'))
        ->toBeTrue('the banner did not draw, so this case proves nothing');

    $banner = strpos($home['html'], 'kbbs-vp');

    expect($home['count'])->toBe(1)
        ->and((int) $banner)->toBeLessThan(
            (int) $home['strip'],
            'the countries strip is drawn ABOVE the banner it was asked to sit under'
        );
});
