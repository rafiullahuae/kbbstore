<?php

declare(strict_types=1);

use App\Models\Block;
use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\UgcSettings;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;

/**
 * [kbb_videos section="..."] — "can insert anywhere in the site, products and
 * pages etc via short code."
 *
 * ── IT EXTENDS THE EXISTING SYSTEM AND DOES NOT INVENT ONE ─────────────────
 *
 * App\Support\Shortcodes already registers [kbb_products] and [kbb_block] with
 * its own attribute parser, it is already expanded from page bodies, post bodies
 * and HTML blocks through the `@shortcodes` directive
 * (AppServiceProvider::boot()), and [kbb_products] is already rendered AS A VIEW
 * in one place. So the new tag is a third arm on that class, in the same idiom,
 * and everything downstream of it — nesting inside a block, caching, flushing —
 * comes for free and is pinned below.
 */
function scShop(bool $on = true): void
{
    app(SettingsService::class)->setModule('shoppable_video', $on);
    SettingsService::forgetMemo();
    Cache::flush();
}

function scSection(array $attributes = [], bool $withVideo = true): UgcSection
{
    $section = UgcSection::query()->create(array_merge([
        'handle' => 'sc-'.substr(md5(uniqid()), 0, 8),
        'title' => 'SC',
        'status' => 'publish',
    ], $attributes));

    if (! $withVideo) {
        return $section;
    }

    $brand = Brand::query()->firstOrCreate(['slug' => 'sc-brand'], ['name' => 'SC Brand']);

    $product = Product::query()->create([
        'slug' => 'sc-p-'.uniqid(),
        'name' => 'SC Product',
        'brand_id' => $brand->id,
        'price' => 9900,
        'status' => 'publish',
        'stock_status' => 'instock',
        'rating' => 4.5,
        'review_count' => 20,
        'type' => 'simple',
    ]);

    $video = UgcVideo::query()->create([
        'slug' => 'sc-v-'.uniqid(),
        'title' => 'SC clip',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-sc.mp4',
        'poster_path' => '/uploads/ugc/poster-sc.webp',
        'width' => 360,
        'height' => 640,
        'creator_handle' => '@sc',
    ]);

    $video->products()->attach($product->id, ['position' => 0]);
    $section->videos()->attach($video->id, ['position' => 0]);

    return $section;
}

it('renders a rail from a page, a post or a block alike', function () {
    /*
     * "ANYWHERE IN THE SITE" is not a claim about this method — it is a claim about
     * where Shortcodes::render() is already called from, and the strongest version
     * of it is the NESTED case: an HTML block is content the owner reuses on many
     * pages, and blocks are expanded BEFORE the other tags precisely so a tag
     * inside one is not emitted as literal text.
     *
     * MUTATION NOTE. Move the [kbb_videos] pass in Shortcodes::render() ABOVE the
     * [kbb_block] pass and this is red on the nested case: the block has not been
     * expanded yet when the video pass runs, so the page ships the literal string
     * "[kbb_videos section=...]" for a shopper to read. RUN: red.
     */
    scShop();
    $section = scSection();

    $direct = Shortcodes::render('<p>before</p>'.$section->shortcode().'<p>after</p>');

    expect($direct)->toContain('ugcr-rail')
        ->and($direct)->toContain('<p>before</p>')
        ->and($direct)->toContain('<p>after</p>')
        ->and($direct)->not->toContain('[kbb_videos');

    Block::query()->create([
        'slug' => 'sc-block',
        'name' => 'SC block',
        // 'published', not 'publish' — Block::scopePublished() spells it that way
        // and DRAFT IS THE OFF SWITCH for a block, so the wrong word here is a
        // block that renders nothing and a test that passes for the wrong reason.
        'status' => 'published',
        'content' => '<div class="promo">'.$section->shortcode().'</div>',
    ]);

    $nested = Shortcodes::render('[kbb_block slug="sc-block"]');

    expect($nested)->toContain('ugcr-rail')
        ->and($nested)->not->toContain('[kbb_videos');

    /*
     * ▲ AND THE ORDER PINNED AT THE SOURCE, because the first mutation of it was
     * badly built and came back green: it ADDED a videos pass above the block pass
     * and left the original one below, so the nested case was still expanded by the
     * second pass. The behavioural case above only fails if the videos pass is the
     * LAST one, which is what this asserts.
     *
     * MUTATION NOTE. Genuinely MOVE the videos pass above the block pass — delete
     * the one at the end — and the behavioural case above is red with the literal
     * "[kbb_videos section=..." in the output of a published page. RUN: red.
     */
    $source = (string) file_get_contents(app_path('Support/Shortcodes.php'));
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    $block = strpos($source, "'/\\[kbb_block");
    $products = strpos($source, "'/\\[kbb_products");
    $videos = strpos($source, "'/\\[kbb_videos");

    expect($block)->not->toBeFalse()->and($products)->not->toBeFalse()->and($videos)->not->toBeFalse();

    // Blocks first (a block may contain either of the others), then products, then
    // video rails. One pass each, in that order.
    expect($block < $products)->toBeTrue('the block pass must run before the products pass')
        ->and($products < $videos)->toBeTrue('the video pass must run last');
});

it('says nothing at all in every case where there is nothing to draw', function (string $label, callable $make) {
    /*
     * THE SAME RULE Shortcodes::block() FOLLOWS, and its docblock gives the
     * reason: a shortcode that cannot resolve must not leave
     * "[kbb_videos section=...]" in the middle of a published page for a shopper to
     * read, and must not print an error either — the storefront is not where an
     * authoring mistake is reported. Content → Shoppable video → Sections is, and
     * it shows every section's status beside its shortcode.
     *
     * MUTATION NOTE. Make Shortcodes::videos() return the matched text when it
     * cannot resolve — the naive "leave it alone" behaviour — and every one of
     * these five is red with the raw tag in the output. RUN: red on all five.
     */
    scShop();

    $handle = $make();

    expect(Shortcodes::render('[kbb_videos section="'.$handle.'"]'))->toBe('');
})->with([
    ['no such handle', fn () => 'nothing-here'],
    ['a draft section', fn () => scSection(['status' => 'draft'])->handle],
    ['a section with no clips in it', fn () => scSection([], false)->handle],
    ['a section restricted to the other storefront', fn () => scSection(['locale' => 'ar'])->handle],
    /*
     * A handle the shortcode parser CAN carry but UgcSection::HANDLE_RE refuses:
     * uppercase and an underscore. (A handle carrying a quote or a `]` is refused
     * one layer earlier, by the attribute regexp itself, which is the reason
     * HANDLE_RE is this narrow — see UgcSection's docblock.)
     */
    ['a handle that could not be one', fn () => 'NOT_A_Handle'],
]);

it('drops a clip that is not publishable rather than the whole rail', function () {
    /*
     * The behaviour the owner actually wants while he is chasing permissions: the
     * rail works with the clips he has cleared, and a clip whose creator has not
     * said yes simply is not in it. Rights fail closed at the CLIP, not at the
     * section.
     *
     * MUTATION NOTE. Drop `->published()` from the videos() call in
     * UgcRail::build() and this is red with 2 tiles: the draft clip appears on the
     * shop, and so would a clip whose rights_status is 'refused' — which is a
     * copyright problem, not a cosmetic one. RUN: red.
     */
    scShop();
    $section = scSection();

    $hidden = UgcVideo::query()->create([
        'slug' => 'sc-refused',
        'title' => 'Refused clip',
        'status' => 'publish',
        'rights_status' => 'refused',
        'file_path' => '/uploads/ugc/clip-refused.mp4',
        'poster_path' => '/uploads/ugc/poster-refused.webp',
        'width' => 360,
        'height' => 640,
    ]);

    $section->videos()->attach($hidden->id, ['position' => 1]);
    Cache::flush();

    $html = Shortcodes::render($section->shortcode());

    /*
     * COUNTED ON data-ugcr-slug AND NOT data-ugcr-tile, because the script in the
     * same output selects on `[data-ugcr-tile]` three times — so counting that
     * attribute counts the stylesheet's own selectors and the first draft of this
     * line read 4 for a rail of one.
     */
    expect(substr_count($html, 'data-ugcr-slug='))->toBe(1)
        ->and($html)->not->toContain('Refused clip');
});

it('resolves nothing at all when the module is off', function () {
    /*
     * ▲ WRITTEN BECAUSE A MUTATION CAME BACK GREEN. Removing the enabled() check
     * from Shortcodes::videos() changed NOTHING observable: UgcRail::section()
     * checks the same switch one layer down, so the rail still returned [] and the
     * shortcode still returned ''. The two checks are redundant BY DESIGN — the one
     * in the shortcode is the cheap one, and its whole job is to not resolve two
     * services out of the container to be told no.
     *
     * "Redundant" is not "untested", though: the cheap lock has an observable
     * effect and this is it. With the module off the UgcRail service is never
     * resolved at all.
     *
     * MUTATION NOTE. Delete the enabled() check from Shortcodes::videos() and this
     * is red — UgcRail is resolved, and on a page with four rails it is resolved
     * four times. RUN: red. (The rail still renders nothing, which is why the other
     * cases in this file could not tell.)
     */
    scShop(false);
    $section = scSection();

    // A fresh container view: nothing has asked for the rail service yet.
    app()->forgetInstance(\App\Services\UgcRail::class);

    expect(Shortcodes::render($section->shortcode()))->toBe('')
        ->and(app()->resolved(\App\Services\UgcRail::class))->toBeFalse();
});

it('takes limit and columns only from their own vocabularies', function () {
    /*
     * A shortcode attribute is a value arriving from outside just as much as a POST
     * body is — it is typed into a page body by hand. Rule 5's "a select stores one
     * of its own options or the default" applies to it.
     *
     * MUTATION NOTE. Pass $a['columns'] straight through in Shortcodes::videos()
     * without the isset(UgcSettings::COLUMNS[...]) check and this is red: the
     * section element ships `--ugc-w:158px` from cssVariables()' default arm, but
     * the class attribute and any future consumer of the raw string would carry
     * `"><script>`. RUN: red on the third expectation.
     */
    scShop();
    $section = scSection();

    // A real option is honoured.
    $two = Shortcodes::render('[kbb_videos section="'.$section->handle.'" columns="two"]');
    expect($two)->toContain('--ugc-w:calc((100% - 12px) / 2)');

    // A limit is clamped to the section's own ceiling rather than trusted.
    $over = Shortcodes::render('[kbb_videos section="'.$section->handle.'" limit="9999"]');
    expect($over)->toContain('ugcr-rail');

    // Anything else falls back to the setting, and nothing of it reaches the page.
    $junk = Shortcodes::render('[kbb_videos section="'.$section->handle.'" columns="1;background:url(x)"]');

    expect($junk)->toContain('--ugc-w:158px')
        ->and($junk)->not->toContain('background:url')
        ->and($junk)->not->toContain('1;background');

    expect(array_keys(UgcSettings::COLUMNS))->toContain('peek', 'one', 'one_peek', 'two', 'two_three', 'three', 'grid_two');

    /*
     * ▲ AND THE INVARIANT, PINNED INSTEAD OF ONE LOCK — because TWO mutations of
     * this came back green and the reason is worth writing down.
     *
     * There are THREE locks between a shortcode attribute and the style attribute
     * it would land in:
     *
     *   1. Shortcodes::videos() drops a `columns` that is not a key of
     *      UgcSettings::COLUMNS.
     *   2. cssVariables() checks it again against the same set.
     *   3. cssVariables()'s `match` has a DEFAULT ARM that ignores the value
     *      entirely and returns the tile width.
     *
     * Removing 1 changed nothing (2 caught it). Removing 2 changed nothing either
     * (3 caught it). That is not over-engineering for a value that ends up inside a
     * `style` attribute — but it does mean no single lock can be tested by removing
     * it, so testing them one at a time is testing nothing.
     *
     * So this asserts the INVARIANT the three of them exist to hold: whatever
     * arrives, the output is one of a fixed set of strings, and it contains nothing
     * that came from the input. That fails if any arm of the match is ever written
     * to interpolate the value.
     *
     * MUTATION NOTE. Change the `default` arm of that match to
     * `$cols.'px'` — the shape somebody reaches for when adding a numeric option —
     * and this is red on every one of the six inputs. RUN: red.
     */
    $base = ['cols' => 'peek', 'tile_w' => 158, 'gap' => 12, 'radius' => 16];

    foreach (['1;background:url(x)', '"><script>', '../../etc', 'peek}', '999', ''] as $junk) {
        $vars = UgcSettings::cssVariables($base, $junk);

        expect($vars)->toMatch('/^--ugc-w:(?:158px|100%|auto|calc\(\(100% - [0-9.]+px\) \/ [0-9.]+\));--ugc-dw:206px;--ugc-gap:12px;--ugc-r:16px$/')
            ->and(str_contains($vars, $junk === '' ? '\0never\0' : $junk))->toBeFalse(
                "cssVariables() let “{$junk}” through into a style attribute"
            );
    }
});

it('emits one stylesheet and one script however many rails a page carries', function () {
    /*
     * @once is Blade's own per-render guard. Two rails on one page must not ship
     * the CSS twice — and must not ship the SCRIPT twice, which matters more: the
     * script registers a delegated document click listener and an
     * IntersectionObserver, so a second copy opens the player twice on one tap and
     * observes every tile twice.
     *
     * MUTATION NOTE. Remove the @once/@endonce wrapper from
     * resources/views/ugc/assets.blade.php and this is red at 2 for both. RUN: red.
     */
    scShop();
    $a = scSection();
    $b = scSection();

    $html = Shortcodes::render($a->shortcode().'<hr>'.$b->shortcode());

    expect(substr_count($html, 'id="kbb-ugc-style"'))->toBe(1)
        ->and(substr_count($html, 'IntersectionObserver('))->toBe(1)
        // ...but two rails.
        ->and(substr_count($html, 'class="kbb-ugc ugcr-sec'))->toBe(2);
});

it('is required from routes/api.php exactly once', function () {
    /*
     * PINNED AS THE FINISHED STATE, which is the shape CLAUDE.md asks for: ZERO is
     * the "built, never wired up" fault this repository keeps finding, and TWO
     * registers the same two routes twice, which makes route() ambiguous by name.
     *
     * routes/api.php and not routes/web.php, and the reasons are in
     * routes/ugc.php's own header: a like is posted by a script on a page that may
     * be cached, so a session-minted CSRF token would be stale for the first
     * shopper served it; and the one-per-browser cookie is written and read by this
     * one endpoint, so it has to sit on one side of EncryptCookies or every like
     * looks like a first like.
     *
     * MUTATION NOTE. Delete the require line and this is red at 0, and every case
     * in UgcLikeApiTest 404s with "route not found" rather than with the
     * controller's own refusal. RUN: red.
     */
    $api = file_get_contents(base_path('routes/api.php'));

    expect(substr_count($api, "require __DIR__.'/ugc.php';"))->toBe(
        1,
        'routes/ugc.php is required '.substr_count($api, "require __DIR__.'/ugc.php';").' times from routes/api.php; it must be exactly one'
    );

    // And the two routes it declares are actually registered and dispatchable.
    expect(route('api.ugc.section', ['section' => 'x']))->toContain('/api/ugc/x')
        ->and(route('api.ugc.like', ['slug' => 'y']))->toContain('/api/ugc/y/like');
});

it('is required from routes/web.php exactly once, for the admin half', function () {
    /*
     * The admin routes went into routes/ugc-admin.php, which the integrator ALREADY
     * wired in the previous round — so this round's nine admin endpoints needed no
     * wiring at all. That is the reason they were added to that file rather than a
     * new one, and this pins that the require is still there exactly once.
     */
    $web = file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/ugc-admin.php';"))->toBe(1);

    expect(route('admin.ugc.sections'))->toContain('admin-api/ugc-sections')
        ->and(route('admin.ugc.appearance'))->toContain('admin-api/ugc-appearance');
});
