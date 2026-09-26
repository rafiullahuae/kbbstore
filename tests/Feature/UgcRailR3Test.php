<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\Ugc\Tile;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;

/**
 * The rail IS R3, and the rating bar is the one deliberate change to it.
 *
 * The owner chose "R3 — Card overlaid on the poster is final" and then said
 * "make sure you get 100% design the proposed design, i need exactly to match
 * including every single thing." So the acceptance test for this round is not
 * "it looks right": docs/UGC-RAIL-R3.md carries an element-by-element comparison
 * of the rendered rail against the rendered preview in one browser at 390 and
 * 1280, and it came back with EIGHT deltas at each width, every one of them
 * accounted for and none of them unintended.
 *
 * This file pins the things that comparison cannot pin because they are invisible
 * when they break — the ones a screenshot passes and a shopper pays for.
 */
function r3Shop(bool $on = true): void
{
    app(SettingsService::class)->setModule('shoppable_video', $on);
    SettingsService::forgetMemo();
    Cache::flush();
}

function r3Product(string $slug, string $brandName, string $name, int $price, ?int $sale, float $rating, int $reviews): Product
{
    $brand = Brand::query()->firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brandName)], ['name' => $brandName]);

    return Product::query()->create([
        'slug' => $slug,
        'name' => $name,
        'brand_id' => $brand->id,
        'price' => $price,
        'sale_price' => $sale,
        'status' => 'publish',
        'stock_status' => 'instock',
        'rating' => $rating,
        'review_count' => $reviews,
        'type' => 'simple',
    ]);
}

/**
 * The RENDERED MARKUP only, with the stylesheet and the script cut off the front.
 *
 * This helper exists because leaving it out cost two wrong assertions. The
 * shortcode's output is `<style>…</style><script>…</script><section>…</section>`,
 * and both of the first two mention every class the third draws — so
 * `not->toContain('ugcr-rate')` is false for a rail that draws no rating bar, and
 * `strpos($html, '<div class="ugcr-bd">')` finds the one inside the player's
 * JavaScript card builder rather than the one in the tile. A test that asserts
 * against a page's own stylesheet is a test that cannot fail.
 */
function r3Markup(string $html): string
{
    $at = strrpos($html, '<section class="kbb-ugc');

    return $at === false ? '' : substr($html, $at);
}

function r3Rail(array $products, array $video = [], array $section = []): string
{
    $row = UgcVideo::query()->create(array_merge([
        'slug' => 'r3-'.uniqid(),
        'title' => 'Glass skin in 6 steps',
        'caption' => 'Glass skin in 6 steps',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-r3.mp4',
        'teaser_path' => '/uploads/ugc/teaser-r3.mp4',
        'poster_path' => '/uploads/ugc/poster-r3.webp',
        'width' => 360,
        'height' => 640,
        'creator_handle' => '@layla.skin',
        'creator_url' => 'https://www.instagram.com/layla.skin/',
        'source_platform' => 'upload',
    ], $video));

    $pos = 0;

    foreach ($products as $p) {
        $row->products()->attach($p->id, ['position' => $pos++]);
    }

    $handle = $section['handle'] ?? 'r3-'.substr(md5((string) $row->id), 0, 8);

    $sec = UgcSection::query()->create(array_merge([
        'handle' => $handle,
        'title' => 'R3',
        'status' => 'publish',
    ], $section, ['handle' => $handle]));

    $sec->videos()->attach($row->id, ['position' => 0]);

    return Shortcodes::render('[kbb_videos section="'.$handle.'"]');
}

it('draws the rating inside the product box and not on the poster', function () {
    /*
     * THE ONE DELIBERATE CHANGE TO R3, and it has two halves that have to be
     * checked separately because each can regress without the other.
     *
     * R3 as previewed puts a black pill with five amber stars ON THE POSTER, inside
     * `.over`, above the creator handle. The owner marked that up on a screenshot
     * with arrows pointing down into the white product box: "inside the product
     * box, i need a thin minimal type rating bar as marked attached."
     *
     * MUTATION NOTE. Move the .ugcr-rate block in resources/views/ugc/rail.blade.php
     * out of .ugcr-bd and into .ugcr-over (where R3 has it) and this is red on the
     * second expectation: the bar is then a descendant of the overlay rather than
     * of the card. RUN: red.
     */
    r3Shop();

    $html = r3Rail([r3Product('r3-snail', 'COSRX', 'Advanced Snail 96 Mucin Power Essence', 11000, 7900, 4.8, 1284)]);

    $markup = r3Markup($html);

    expect($markup)->toContain('ugcr-rate');

    // Inside the card's brand line...
    $bd = substr($markup, strpos($markup, '<div class="ugcr-bd">'));
    $bd = substr($bd, 0, strpos($bd, '<a class="ugcr-nm"'));
    expect($bd)->toContain('ugcr-rate');

    // ...and NOT in the poster overlay, which is where R3 had it.
    $over = substr($markup, strpos($markup, '<div class="ugcr-over">'));
    $over = substr($over, 0, strpos($over, '<div class="ugcr-card">'));
    expect($over)->not->toContain('ugcr-rate');
});

it('draws no bar at all for a product that has never been reviewed', function () {
    /*
     * THE CASE THE PREVIOUS ROUND ESTABLISHED AND THIS ONE MUST NOT LOSE.
     *
     * An empty five-star row reads as "rated badly" and a zero reads as "rated
     * zero", and a product can be three years old and simply unreviewed. The shop
     * already draws this line: partials/home/grid.blade.php gates its New badge on
     * `! $p->review_count`.
     *
     * MUTATION NOTE. Change the gate in rail.blade.php from
     * `$p['reviews'] > 0` to `$p['rating'] >= 0` — which is true for every product
     * — and this is red: an unreviewed product renders `0.0 ★ (0)`. RUN: red, and
     * the rendered string was exactly `<bdi>0.0</bdi>`.
     */
    r3Shop();

    $html = r3Rail([r3Product('r3-new', 'Isntree', 'Hyaluronic Acid Watery Sun Gel', 9500, null, 0.0, 0)]);

    $markup = r3Markup($html);

    expect($markup)->toContain('ugcr-card')            // the card is there
        ->and($markup)->not->toContain('ugcr-rate')    // the bar is not
        ->and($markup)->not->toContain('ugcr-sc')      // nor the score
        ->and($markup)->not->toContain('ugcr-star');   // nor the glyph

    /*
     * AND THE PLAYER'S CARD DRAWS NONE EITHER. The tile and the opened player are
     * two different renderers of the same rule, and only one of them is Blade —
     * the player's card is built in the browser from the inline JSON beside the
     * tile. So the JSON carries `reviews: 0` and the builder gates on it; without
     * that the tile would be right and the player, one tap later, would print
     * "0.0 ★ (0)".
     *
     * `rating: "0.0"` IS in that JSON and that is correct: it is the raw figure,
     * and `reviews` is what decides whether anything is drawn from it. Which is
     * exactly why this asserts on the DRAWN classes above rather than on the
     * string "0.0" — the first draft did the latter and failed against the
     * player's own payload.
     */
    expect($markup)->toContain('"reviews":0');
});

it('rounds the score to the same number of stars a shop card would', function () {
    /*
     * A tile and a shop card must never disagree about how many stars a 4.6 gets.
     * partials/home/grid.blade.php uses `(int) round((float) $p->rating)` and
     * App\Services\Ugc\Tile computes `stars` the same way.
     *
     * MUTATION NOTE. Change Tile::product()'s `round()` to `floor()` and this is
     * red at 4 against 5 for the 4.6. RUN: red.
     */
    $p = r3Product('r3-round', 'Anua', 'Heartleaf 77% Soothing Toner', 13200, 9200, 4.6, 843);

    $tile = Tile::fromVideo(tap(UgcVideo::query()->create([
        'slug' => 'r3-round-clip',
        'title' => 'x',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-round.mp4',
        'poster_path' => '/uploads/ugc/poster-round.webp',
        'width' => 360,
        'height' => 640,
    ]), fn ($v) => $v->products()->attach($p->id, ['position' => 0]))->load('products'), 'en');

    expect($tile['products'][0]['stars'])->toBe(5)
        ->and($tile['products'][0]['reviews'])->toBe(843)
        // The same arithmetic the shop card does, spelled out rather than trusted.
        ->and((int) round((float) $p->rating))->toBe($tile['products'][0]['stars']);
});

it('reserves the tile box from the stored dimensions, in lowest terms', function () {
    /*
     * §2 budgets layout shift at ZERO and two tests in this repo forbid the
     * element-measuring APIs by name, so the box comes from the columns. Reduced by
     * the gcd so an ordinary 360x640 clip prints `9 / 16` — which is what R3
     * prints, and what made this a delta on every tile of the comparison until it
     * was reduced.
     *
     * MUTATION NOTE. Make Tile::gcd() return 1 and this is red: the tile renders
     * `aspect-ratio:360 / 640`. Lays out identically, reports differently, and the
     * comparison against R3 goes from 8 deltas to 9. RUN: red.
     */
    r3Shop();

    $html = r3Rail([r3Product('r3-box', 'COSRX', 'Low pH Good Morning Gel Cleanser', 6500, 4500, 4.6, 843)]);

    expect(r3Markup($html))->toContain('aspect-ratio:9 / 16')
        ->and(r3Markup($html))->not->toContain('aspect-ratio:360 / 640');

    // A clip that is NOT 9:16 still reserves its own true box rather than being
    // forced into one.
    $square = r3Rail(
        [r3Product('r3-square', 'Anua', 'Peach Niacinamide 30% Serum', 11800, null, 4.4, 96)],
        ['width' => 1080, 'height' => 1350]
    );

    expect(r3Markup($square))->toContain('aspect-ratio:4 / 5');
});

it('mounts nothing that could autoplay in the wrong place', function () {
    /*
     * INVISIBLE WHEN IT BREAKS, WHICH IS WHY IT IS PINNED. A <video> created
     * without `muted` autoplays nowhere; one without `playsinline` is taken full
     * screen by iOS Safari instead of playing in the tile, which is worse than not
     * playing; and a `preload` that is not `none` turns a rail of posters into a
     * rail of clips. All three look perfect in a desktop screenshot.
     *
     * The property AND the attribute in each case: the property is what the
     * element honours, the attribute is what older WebKit reads.
     *
     * MUTATION NOTE. Delete `v.muted = true;` from the mount() in
     * resources/views/ugc/assets.blade.php and this is red. Delete
     * `v.preload = 'none'` and it is red. RUN: both.
     */
    $js = (string) file_get_contents(resource_path('views/ugc/assets.blade.php'));

    /*
     * SCOPED TO mount(), AND THE MUTATION RUN IS WHY. Deleting `v.muted = true`
     * from mount() left this case GREEN, because the string also appears in
     * open()'s retry-muted branch — so the assertion passed off a line about a
     * completely different element while every tile in the rail had silently
     * stopped autoplaying. A whole-file toContain() on a file with two functions in
     * it is an assertion about the file, not about the thing.
     */
    $at = strpos($js, 'function mount(');

    expect($at)->not->toBeFalse('mount() is not in assets.blade.php any more');

    $mount = substr($js, (int) $at, (int) (strpos($js, 'function playTeaser(') - $at));

    expect($mount)->toContain('v.muted = true')
        ->and($mount)->toContain("v.setAttribute('muted', '')")
        ->and($mount)->toContain('v.playsInline = true')
        ->and($mount)->toContain("v.setAttribute('playsinline', '')")
        ->and($mount)->toContain("v.setAttribute('webkit-playsinline', '')")
        ->and($mount)->toContain("v.preload = 'none'")
        // The play() promise is caught in every one of the three places it is
        // called. An uncaught rejection here is a console error on every tile.
        ->and(substr_count($js, 'catch(function () {})'))->toBeGreaterThanOrEqual(3);
});

it('gives the decoder back rather than merely pausing', function () {
    /*
     * A paused <video> keeps its decoded stream RESIDENT. Scrolling a long page of
     * rails would accumulate them and the page would die a third of the way down.
     * Dropping the src and calling load() is what actually frees it —
     * docs/UGC-VIDEO-PLAN.md §2 measured this.
     *
     * MUTATION NOTE. Reduce unmount() in assets.blade.php to `v.pause()` and this
     * is red on both the removeAttribute and the load(). RUN: red.
     */
    $js = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    expect($js)->toContain("v.removeAttribute('src')")
        ->and($js)->toContain('v.load()');
});

it('measures no layout in script, anywhere in the rail', function () {
    /*
     * Rule 4, and it is a rule with two tests behind it already
     * (CheckoutFloatingBarGateTest and CartPageSqueezeTest name these APIs). This
     * project sizes with calc(), aspect-ratio, scroll-snap and container queries
     * for a reason, and a rail is the most tempting place in the shop to reach for
     * a rect.
     *
     * getBoundingClientRect IS allowed in ONE place and it is not in the shipped
     * file: the harness that produced docs/UGC-RAIL-R3.md uses it, in
     * docs/ugc-rail-shots, to MEASURE the design rather than to lay it out.
     *
     * MUTATION NOTE. Add `el.getBoundingClientRect()` anywhere in
     * assets.blade.php and this is red. RUN: red.
     */
    foreach (['assets', 'rail', 'likes'] as $partial) {
        $src = (string) file_get_contents(resource_path('views/ugc/'.$partial.'.blade.php'));

        /*
         * COMMENTS STRIPPED FIRST — the Blade comment, the CSS/JS block comment and
         * the line comment — and this is the second time in this file that mattered.
         * Those partials EXPLAIN the rule in prose, naming the forbidden APIs, so a
         * scan of the raw text matches the explanation and fails a file that is
         * correct. A source-scanning assertion has to read the code.
         *
         * ▲ AND THIS CASE ONLY STARTED WORKING WHEN THE EXPECTATION WAS FIXED.
         * Written `->not->toContain($needle, $message)` it could not fail at all
         * (toContain is variadic), so it passed for its whole life over a file whose
         * comments name getBoundingClientRect twice. ExpectationsThatCannotFailTest
         * found that, and the very first honest run of this case went red on those
         * two comments — which is how the strip below came to exist.
         */
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
        $src = (string) preg_replace('#^\s*//.*$#m', '', $src);

        foreach (['getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
            'clientHeight', 'clientWidth', 'scrollY', 'requestAnimationFrame',
            "addEventListener('scroll'", "addEventListener('resize'"] as $forbidden) {
            /*
             * str_contains(), because Pest's toContain() is VARIADIC: a second
             * argument is another needle, not a message, and the expectation then
             * cannot fail. ExpectationsThatCannotFailTest caught this line.
             */
            expect(str_contains($src, $forbidden))->toBeFalse("ugc/{$partial}.blade.php uses {$forbidden}");
        }
    }
});

it('keeps the reset below the components it is meant to sit under', function () {
    /*
     * THE BUG THE ELEMENT-BY-ELEMENT RUN ACTUALLY FOUND, and it is the reason that
     * run exists rather than a screenshot.
     *
     * The first version of assets.blade.php wrote the scoped reset as
     * `.kbb-ugc button{...;padding:0}`. That is specificity (0,1,1) and it BEATS
     * `.ugcr-add{padding:5px 8px}` at (0,1,0) — so the ADD button shipped with
     * padding 0, background transparent, colour #2A2228 on #2A2228, font-size 16
     * and weight 400. An invisible label on a transparent pill, on every tile.
     *
     * The preview gets it right by accident, because its reset is a bare `*` and a
     * bare `button` at (0,0,0) and (0,0,1). SCOPING A RESET TO A CLASS IS WHAT
     * SILENTLY RAISES IT ABOVE THE COMPONENTS IT IS MEANT TO SIT UNDER. :where()
     * contributes zero specificity, which puts it back.
     *
     * MUTATION NOTE. Change `.kbb-ugc :where(button)` back to `.kbb-ugc button`
     * and this is red. RUN: red. And the picture at docs/ugc-rail-shots is what it
     * looked like.
     */
    $css = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    expect($css)->toContain('.kbb-ugc :where(button)')
        ->and($css)->toContain('.kbb-ugc :where(*){margin:0;padding:0}')
        // `font-family:inherit`, never `font:inherit`: the latter also sets
        // font-size and line-height, which put the 42px play disc's own box at
        // 16px/24px where R3's is the UA default 13.333px/normal.
        // A DECLARATION, not the word. The comment above the reset in that file
        // explains why `font:inherit` is wrong, so a bare toContain() search finds
        // the explanation and passes for the wrong reason.
        ->and(preg_match('/[{;]\s*font:\s*inherit/', (string) preg_replace('#/\*.*?\*/#s', '', $css)))->toBe(0);

    /*
     * And no un-:where()d element reset that could outrank a component rule.
     *
     * THE COMMENTS ARE STRIPPED FIRST, and that is not fussiness: the comment
     * above the reset quotes `.kbb-ugc button{padding:0}` as the thing that went
     * wrong, so the regexp matched the explanation and the case failed against a
     * file that was correct. A source-scanning assertion has to read the code.
     */
    $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    expect(preg_match('/\.kbb-ugc\s+(button|img|video|\*)\s*\{/', $declarations))->toBe(0);
});

it('keeps the rating bar out of the card’s height, in CSS rather than by hope', function () {
    /*
     * "It must not push the box taller" was a requirement. The first version put
     * the score at 9px with line-height 1.5 — a 13.5px line box in a row whose
     * brand sets 12px — and the card grew by exactly 1.5px, which the comparison
     * reported as `rating cost delta: 1.5`. line-height:1 on the bar puts the
     * score's box at 9px, the brand's 12px decides the row, and the measured delta
     * is 0 at both widths.
     *
     * MUTATION NOTE. Change `.ugcr-rate{...line-height:1...}` to line-height:1.5
     * and the measured delta goes back to 1.5px. This case is red on the source
     * pin; docs/ugc-rail-shots/r3-compare.json is red on the measurement. RUN:
     * both.
     */
    $css = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    expect($css)->toMatch('/\.ugcr-rate\{[^}]*line-height:1;/')
        // The count is what goes on a narrow tile, answered by a container query
        // rather than by a script that measures.
        ->and($css)->toContain('@container (max-width:170px){ .ugcr-rate .ugcr-rc{display:none} }')
        ->and($css)->toContain('container-type:inline-size');
});

it('renders nothing at all while the module is off', function () {
    /*
     * RULE 1. A page that already carries the shortcode must be byte-identical on
     * the day this package applies, because the module ships off. Not an empty
     * section element, not a stylesheet, not a script — the empty string.
     *
     * MUTATION NOTE. Remove the enabled() check from Shortcodes::videos() and this
     * is red with the whole rail in it. RUN: red.
     */
    r3Shop(false);

    $html = r3Rail([r3Product('r3-off', 'COSRX', 'Advanced Snail 96 Mucin Power Essence', 11000, 7900, 4.8, 1284)]);

    expect($html)->toBe('');
});
