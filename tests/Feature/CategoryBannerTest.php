<?php

declare(strict_types=1);

/**
 * Lane CB -- THE CATEGORY BANNER: the brand page's Panel header on a category.
 *
 * The owner, 8 October: "ALSO i need the categories banners, exact same like
 * brand banners. with exact same controls and everything. just use the same
 * thing on the categories pages."
 *
 * And, on the question of a category that already has a picture: "yes if
 * there's banner, then the banner should be picked auto by new design".
 *
 * So a category WITH a picture -- its own Banner picture (Category header ->
 * Banner layout), else the one its old header showed: the Catalog banner's,
 * else its header picture -- draws store/partials/brand-panel, resolved by BrandPanel::forCategory() from
 * the same code as a brand, with the brand page's controls again under
 * Appearance -> Site layout -> Category banner (`catb_*`) and per category in
 * `categories.header_layout`. A category WITHOUT a picture keeps today's page
 * byte for byte: the brand's no-picture Panel (a ground in the brand's shades)
 * is not turned on for categories -- that is the owner's decision to make.
 *
 * Each `it` says the defect it would catch, and its MUTATION line names the
 * change that turns it red.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\BrandPanel;
use App\Support\ImageVariants;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

function cbLayout(array $values): void
{
    app(SiteLayout::class)->save($values);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function cbCategory(string $slug, array $over = []): Category
{
    $c = Category::query()->create(array_merge([
        'name' => 'Cleansers '.$slug, 'slug' => $slug, 'parent_id' => null,
        'description' => '<p>Gentle cleansers for every skin type.</p>',
    ], $over));
    $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();

    foreach (range(1, 3) as $i) {
        $p = Product::create([
            'slug' => $slug.'-p'.$i, 'name' => 'CB Product '.$slug.' '.$i, 'price' => 1000 + $i,
            'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
        ]);
        $c->products()->attach($p->id);
    }

    return $c;
}

function cbPage(string $path): string
{
    Setting::flushMap();
    SettingsService::forgetMemo();

    return (string) test()->get($path)->assertOk()->getContent();
}

/** The category banner's <section>, or ''. */
function cbSection(string $html): string
{
    return preg_match('#<div class="wrap kbb-cbw">.*?</section>\s*</div>\s*</div>#s', $html, $m) === 1 ? $m[0] : '';
}

/** A real picture on disk, so the img-cache copies can exist. */
function cbPicture(string $rel): void
{
    $path = public_path($rel);
    @mkdir(\dirname($path), 0775, true);
    $im = imagecreatetruecolor(1920, 600);
    for ($y = 0; $y < 600; $y += 4) {
        imagefilledrectangle($im, 0, $y, 1920, $y + 3, (int) imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 3) % 255));
    }
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

/*
 * The pictures these cases name are REAL files: the banner draws only a
 * picture that is on this server (BrandPanel::onServer), so a made-up path
 * is the "as before" case now, which has cases of its own below.
 */
const CB_PICS = ['c', 'mine', 'old', 'tall', 'wide', 'x', 'sq'];

beforeEach(function () {
    foreach (CB_PICS as $name) {
        $path = public_path('uploads/cb-test/'.$name.'.jpg');
        @mkdir(\dirname($path), 0775, true);
        $im = imagecreatetruecolor($name === 'tall' ? 300 : 800, $name === 'tall' ? 600 : ($name === 'sq' ? 800 : 250));
        imagejpeg($im, $path, 60);
        imagedestroy($im);
    }
});

afterEach(function () {
    foreach (CB_PICS as $name) {
        @unlink(public_path('uploads/cb-test/'.$name.'.jpg'));
    }
    @rmdir(public_path('uploads/cb-test'));
});

function cbOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CB '.$role, 'email' => 'cb-'.$role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role,
    ]);
}

/* ═══════════════════════ 1. a category with a picture ═══════════════════════ */

it('draws the brand page banner on a category with a picture: srcset, reserved height, the LCP hint', function () {
    /*
     * THE REQUEST ITSELF. Before this lane the category drew its title header
     * (kbb-th) over the picture; the owner asked for the brand page's banner.
     *
     * MUTATION: return null at the top of BrandPanel::forCategory() and every
     * expectation here is red (the title header draws instead). Drop the
     * srcset from category-panel-picture and the srcset line is red -- the
     * bare 2400px original on a 390px phone is what CLAUDE.md's picture rule
     * forbids. Drop `--brw-ph-h` / the min-height and the height line is red.
     * Hand it the plain `min(100vw, 2400px)` again and the sizes line is red:
     * a 1920 x 600 picture covering the 165px phone banner is drawn 528px
     * wide, and the phone took the 400w copy and upscaled it.
     */
    if (! ImageVariants::available()) {
        test()->markTestSkipped('no GD: the srcset half needs it');
    }

    $rel = 'uploads/categories/cb-'.uniqid().'.jpg';
    cbPicture($rel);
    ImageVariants::generate('/'.$rel);

    try {
        cbCategory('cb-pic', ['header_image' => '/'.$rel]);
        $html = cbPage('/collections/cb-pic/');
        $section = cbSection($html);

        expect($section)->not->toBe('', 'no category banner on a category with a picture')
            ->and($html)->not->toContain('data-kbb-title-header')
            ->and(substr_count($html, '<h1'))->toBe(1)
            ->and($section)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">Cleansers cb-pic</h1>')
            ->and($section)->toContain('<div class="brw-phw" data-kbb-brand-header>')
            ->and($section)->toMatch('#<section class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center[ "]#')
            // The height is reserved by the stylesheet's min-height from these two.
            ->and($section)->toContain('--brw-ph-h:270px')
            ->and($section)->toContain('--brw-ph-hm:165px')
            ->and($section)->toContain('width="1600" height="500" decoding="async" fetchpriority="high"')
            ->and($section)->toMatch('#<img class="brw-ph__img" src="/'.preg_quote($rel, '#').'" srcset="/img-cache/200/'.preg_quote($rel, '#').' 200w, [^"]+ 1920w" sizes="\(max-width: 599px\) max\(100vw, 528px\), min\(max\(100vw, 864px\), 2400px\)"#')
            // The description, under the name, through the title header's own rules.
            ->and($section)->toContain('Gentle cleansers for every skin type.')
            // The brand page's own sheet, in the head; the title header's is not loaded.
            ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*kbb-brand-header[^"]*\.css"#')
            ->and($html)->not->toMatch('#kbb-title-header[^"]*\.css#')
            // The banner is the LCP: the first product card is no longer eager.
            ->and($html)->not->toContain('fetchpriority="high" loading="eager"');
    } finally {
        ImageVariants::forget('/'.$rel);
        @unlink(public_path($rel));
    }
});

it('takes the Catalog banner picture first, and does not draw that banner as well', function () {
    /*
     * The brand rule (BR4): the owner's own banner is the picture he chose.
     * Drawn as the panel's background, with ONE <h1>, not a second header.
     *
     * MUTATION: pass `$banner` (not null) to the view under the panel in
     * ShopController and `kbb-banner` is printed too -- two headers.
     */
    cbCategory('cb-ban', [
        'header_image' => '/uploads/cb-test/old.jpg',
        'banner' => ['enabled' => true, 'style' => 'full', 'image' => '/uploads/cb-test/mine.jpg', 'heading' => 'Mine'],
    ]);

    $html = cbPage('/collections/cb-ban/');

    expect(cbSection($html))->toContain('src="/uploads/cb-test/mine.jpg"')
        ->and($html)->not->toContain('class="kbb-banner')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('gives phones the category\'s own phone picture', function () {
    /*
     * Catalog -> Categories' "Edit header" panel already keeps a phone picture
     * (`header_style.img_phone`); the banner offers it under 900px.
     *
     * MUTATION: drop the <source> from category-panel-picture and this is red.
     */
    cbCategory('cb-phone', ['header_image' => '/uploads/cb-test/wide.jpg', 'header_style' => ['img_phone' => '/uploads/cb-test/tall.jpg']]);

    expect(cbSection(cbPage('/collections/cb-phone/')))
        ->toContain('<source media="(max-width: 899.98px)" srcset="/uploads/cb-test/tall.jpg">');
});

/* ═══════════════════ 2. a category without one, unchanged ═══════════════════ */

it('leaves a category with no picture byte for byte as it was', function () {
    /*
     * "A category with no banner image set must look exactly like today."
     * Today is the page under "Category header -- as before", which is the
     * code path this lane did not touch -- so the two renders must be equal
     * to the byte, for the light box, the plain title and the Catalog tint.
     *
     * MUTATION: let forCategory() draw the brand's no-picture Panel (return
     * draw() with a null image instead of null) and all three are red.
     */
    cbCategory('cb-box');
    cbCategory('cb-tint', ['banner' => ['enabled' => true, 'style' => 'tint', 'heading' => 'Tinted']]);

    foreach (['/collections/cb-box/', '/collections/cb-tint/'] as $path) {
        cbLayout(['catb_hero' => 'panel']);
        $now = cbPage($path);
        cbLayout(['catb_hero' => 'header']);
        $before = cbPage($path);

        expect(preg_replace('#name="csrf-token" content="[^"]+"|value="[A-Za-z0-9]{40}"#', '', $now))
            ->toBe(preg_replace('#name="csrf-token" content="[^"]+"|value="[A-Za-z0-9]{40}"#', '', $before), $path.' changed with no picture')
            ->and($now)->not->toContain('kbb-cbw')
            ->and($now)->not->toContain('kbb-brand-header');
    }

    // And the light box is what that page is.
    cbLayout(['catb_hero' => 'panel']);
    expect(cbPage('/collections/cb-box/'))->toContain('data-kbb-title-header');
});

it('puts the title header back for every category under "Category header -- as before"', function () {
    /* MUTATION: ignore `catb_hero` in ShopController and this is red. */
    cbCategory('cb-back', ['header_image' => '/uploads/cb-test/x.jpg']);
    cbLayout(['catb_hero' => 'header']);

    $html = cbPage('/collections/cb-back/');

    expect($html)->toContain('data-kbb-title-header')->and($html)->not->toContain('kbb-cbw');
});

/* ══════════════ 2b. which picture, and never a broken banner ══════════════ */

/** The page with the csrf token taken out, so two renders can be compared. */
function cbBare(string $html): string
{
    return (string) preg_replace('#name="csrf-token" content="[^"]+"|value="[A-Za-z0-9]{40}"#', '', $html);
}

it('picks up the picture the old header showed, by itself', function () {
    /*
     * The owner: "yes if there's banner, then the banner should be picked
     * auto by new design". An imported category carries its old banner in
     * `header_image` and nothing in the new Banner layout; it gets the banner
     * at the shipped settings, with nobody opening the category.
     *
     * MUTATION: drop oldPicture() from forCategory()'s candidates and this is
     * red -- the title header draws, as it did before the owner said yes.
     */
    cbCategory('cb-auto', ['header_image' => '/uploads/cb-test/old.jpg']);

    $html = cbPage('/collections/cb-auto/');

    expect(cbSection($html))->toContain('<img class="brw-ph__img" src="/uploads/cb-test/old.jpg"')
        ->and($html)->not->toContain('data-kbb-title-header');
});

it('takes the category\'s own Banner picture over the old one', function () {
    /*
     * The new field wins: a Banner picture set in Category header -> Banner
     * layout is the banner, whatever the import left in `header_image`.
     *
     * MUTATION: swap the two candidates in forCategory() and this is red.
     */
    cbCategory('cb-mine', ['header_image' => '/uploads/cb-test/old.jpg', 'header_layout' => ['image' => '/uploads/cb-test/mine.jpg']]);

    $section = cbSection(cbPage('/collections/cb-mine/'));

    expect($section)->toContain('src="/uploads/cb-test/mine.jpg"')->and($section)->not->toContain('old.jpg');
});

it('never draws a broken banner: a picture not on this server keeps the old header', function () {
    /*
     * An imported `header_image` can be a file the media copy never brought
     * across, or an address on the OLD WooCommerce domain. The banner draws
     * only a picture ImageVariants can open under this web root; anything
     * else leaves the page byte for byte as it was under "Category header --
     * as before" -- the old title header, which drew it the same way before.
     * A Banner picture that is missing falls through to the old one.
     *
     * MUTATION: drop the onServer() test in forCategory() and all three are
     * red (a banner on a 404, or on another site's file).
     */
    cbCategory('cb-gone', ['header_image' => '/uploads/cb-test/never-copied.jpg']);
    cbCategory('cb-far', ['header_image' => 'https://kbeautybliss.com/wp-content/uploads/2024/01/banner.jpg']);

    foreach (['/collections/cb-gone/', '/collections/cb-far/'] as $path) {
        cbLayout(['catb_hero' => 'panel']);
        $now = cbPage($path);
        cbLayout(['catb_hero' => 'header']);
        $before = cbPage($path);

        expect(cbBare($now))->toBe(cbBare($before), $path.' drew something new for a picture it cannot show')
            ->and($now)->not->toContain('kbb-cbw');
    }

    cbLayout(['catb_hero' => 'panel']);
    cbCategory('cb-miss', ['header_image' => '/uploads/cb-test/old.jpg', 'header_layout' => ['image' => '/uploads/cb-test/deleted.jpg']]);
    expect(cbSection(cbPage('/collections/cb-miss/')))->toContain('src="/uploads/cb-test/old.jpg"');
});

it('uses the square category picture only where the old header did', function () {
    /*
     * "Whatever the old title header showed": the category's own square
     * picture was the old header's picture only with "When no banner was
     * imported, use the category picture" on, which ships Off. So a category
     * with nothing but that picture is unchanged at the shipped settings,
     * and becomes a banner exactly when the old header would have shown it.
     *
     * MUTATION: read `image` in oldPicture() without the switch and the
     * first half is red.
     */
    cbCategory('cb-sq', ['image' => '/uploads/cb-test/sq.jpg']);

    cbLayout(['catb_hero' => 'panel']);
    $now = cbPage('/collections/cb-sq/');
    cbLayout(['catb_hero' => 'header']);
    expect(cbBare($now))->toBe(cbBare(cbPage('/collections/cb-sq/')))->and($now)->not->toContain('kbb-cbw');

    cbLayout(['catb_hero' => 'panel', 'cat_header_fallback' => true]);
    expect(cbSection(cbPage('/collections/cb-sq/')))->toContain('src="/uploads/cb-test/sq.jpg"');
});

it('keeps the Banner picture out of the Media Library\'s delete list', function () {
    /*
     * The banner is drawn on that file, so the library must count it as used.
     *
     * MUTATION: drop the header_layout read from MediaUsage::collect() and
     * this is red -- the library would offer to delete a live banner.
     */
    $cat = cbCategory('cb-media', ['header_layout' => ['image' => '/uploads/cb-test/mine.jpg']]);
    $row = Category::query()->find($cat->id);

    $fields = collect(\App\Support\MediaUsage::indexForOwner('category', $row))->flatten(1)->pluck('field')->all();

    expect($fields)->toContain('Category banner picture');
});

/* ═════════════════════════ 3. the brand page, unchanged ═════════════════════════ */

it('leaves the brand page alone, whatever the category banner is set to', function () {
    /*
     * The brand resolver was generalised (BrandPanel::draw), so the brand
     * page is pinned against itself across every category control, and its
     * Panel to the exact class and style BR4 shipped.
     *
     * MUTATION: read the category prefix for a brand in BrandPanel::shop()
     * (pass `true`) and the second render differs.
     */
    $brand = Brand::create(['name' => 'Anua', 'slug' => 'cb-anua', 'header_image' => '/uploads/brands/a.jpg', 'description' => 'Heartleaf.']);
    Product::create(['slug' => 'cb-anua-p', 'name' => 'Anua Toner', 'brand_id' => $brand->id, 'price' => 1000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);

    $before = cbPage('/brands/cb-anua/');
    cbLayout(['catb_banner_h' => 400, 'catb_panel_style' => 'brand', 'catb_name_fs' => 40, 'catb_hero' => 'header', 'catb_space_top' => 0]);
    $after = cbPage('/brands/cb-anua/');

    $strip = static fn (string $h): string => (string) preg_replace('#name="csrf-token" content="[^"]+"|value="[A-Za-z0-9]{40}"#', '', $h);

    expect($strip($after))->toBe($strip($before))
        ->and($after)->toContain('<section class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center" style="--brw-ph-w:100%;--brw-ph-h:270px;--brw-ph-hm:165px;--brw-ph-cw:60%;--brw-ph-dk:#431a25;--brw-ph-lt:#fceef2" aria-labelledby="brw-ph-title">')
        ->and($after)->toContain('<img class="brw-ph__img" src="/uploads/brands/a.jpg" alt="" width="1600" height="500" decoding="async" fetchpriority="high">')
        ->and($after)->not->toContain('kbb-cbw');
});

/* ═══════════════════════════ 4. every control works ═══════════════════════════ */

it('answers every Category banner control, each under its own name', function () {
    /*
     * Every brand Panel control has a category twin, and each one reaches the
     * category's header -- a size as its custom property, a choice as its class.
     *
     * MUTATION: drop one `catb_` entry from SiteLayout::SCHEMA and its line
     * is red (all() never hands it over); map settingKey() back to `brand_`
     * and every line is red.
     */
    $cat = cbCategory('cb-ctl', ['header_image' => '/uploads/cb-test/c.jpg']);
    $layout = app(SiteLayout::class);

    foreach (BrandPanel::RANGES as $key => [$brandSetting, $min, $max, $property, $unit]) {
        $setting = BrandPanel::settingKey($brandSetting, true);
        expect(SiteLayout::SCHEMA)->toHaveKey($setting)
            ->and(SiteLayout::SCHEMA[$setting][4]['min'])->toBe($min)
            ->and(SiteLayout::SCHEMA[$setting][4]['max'])->toBe($max)
            ->and(SiteLayout::SCHEMA[$setting][2])->toBe(SiteLayout::SCHEMA[$brandSetting][2]);

        $value = (int) SiteLayout::SCHEMA[$setting][2] === $max ? $min : $max;
        $panel = BrandPanel::forCategory($cat->fresh(), [$setting => $value] + $layout->all(), 'C');
        expect($panel['style'])->toContain($property.':'.$value.$unit);
        // The brand page's own setting moves nothing here.
        $brandOnly = BrandPanel::forCategory($cat->fresh(), [$brandSetting => $value] + $layout->all(), 'C');
        expect($brandOnly['style'])->not->toContain($property.':'.$value.$unit);
    }

    foreach (BrandPanel::CHOICES as $key => [$brandSetting, $allowed]) {
        $setting = BrandPanel::settingKey($brandSetting, true);
        $last = end($allowed);
        $panel = BrandPanel::forCategory($cat->fresh(), [$setting => in_array($key, BrandPanel::SWITCHES, true) ? true : $last] + $layout->all(), 'C');
        $class = isset(BrandPanel::QUIET_CHOICES[$key]) ? 'brw-ph--'.BrandPanel::QUIET_CHOICES[$key].'-'.$last : 'brw-ph--'.($key === 'panel' ? '' : ($key === 'position' ? 'pos-' : $key.'-')).$last;
        expect($panel['class'])->toContain($class);
    }

    // And end to end, through the screen's own save and a real page.
    cbLayout(['catb_banner_h' => 410, 'catb_pill_at' => 'top-right']);
    $section = cbSection(cbPage('/collections/cb-ctl/'));
    expect($section)->toContain('--brw-ph-h:410px')->and($section)->toContain('brw-ph--at-top-right');

    // Every one of them on the Category banner tab, next to Brand page.
    expect(SiteLayout::TABS['catbanner'][0])->toBe('Category banner')
        ->and(array_keys(SiteLayout::TABS))->toContain('catbanner')
        ->and(array_search('catbanner', array_keys(SiteLayout::TABS), true))->toBe(array_search('brandpage', array_keys(SiteLayout::TABS), true) + 1)
        ->and(count(SiteLayout::CATBANNER_KEYS))->toBe(count(BrandPanel::RANGES) + count(BrandPanel::CHOICES) + 1)
        ->and(SiteLayout::SCHEMA['catb_hero'][2])->toBe('panel');
});

it('lets one category override the shop, as a brand can', function () {
    /* MUTATION: drop `sanitize(header_layout) +` in forCategory and this is red. */
    $cat = cbCategory('cb-own', ['header_image' => '/uploads/cb-test/c.jpg', 'header_layout' => ['height' => 350, 'panel' => 'brand', 'width' => 999, 'evil' => '"><script>']]);

    $section = cbSection(cbPage('/collections/cb-own/'));

    expect($section)->toContain('--brw-ph-h:350px')
        ->and($section)->toContain('brw-ph--brand')
        // Clamped to its range, and an unknown key dropped.
        ->and($section)->toContain('--brw-ph-w:100%')
        ->and($section)->not->toContain('<script>');
});

/* ═══════════════════ 5. the per-category fields: saved and escaped ═══════════════════ */

it('saves a category\'s banner layout from Catalog -> Categories, and refuses what is not on its lists', function () {
    /*
     * MUTATION: drop layoutRules() from CategoriesApiController and the 422s
     * are 200s; drop headerLayout() and nothing is stored.
     */
    test()->actingAs(cbOwner(), 'admin');
    $cat = cbCategory('cb-save', ['header_image' => '/uploads/cb-test/c.jpg']);
    $base = ['name' => $cat->name, 'slug' => $cat->slug];

    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['height' => 300, 'pill' => 'rect', 'name_m' => '']])->assertOk();
    expect($cat->fresh()->header_layout)->toBe(['pill' => 'rect', 'height' => 300]);

    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['pill' => 'oval']])->assertStatus(422);
    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['height' => 9999]])->assertStatus(422);
    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['onclick' => 'x']])->assertStatus(422);
    expect($cat->fresh()->header_layout)->toBe(['pill' => 'rect', 'height' => 300]);

    // A save that does not carry the key (the Catalog tab) leaves it alone.
    test()->putJson('/admin-api/categories/'.$cat->id, $base)->assertOk();
    expect($cat->fresh()->header_layout)->toBe(['pill' => 'rect', 'height' => 300]);

    // The Banner picture: an uploaded path is kept; a script address is
    // refused with the field named, not saved as "no banner". MUTATION: drop
    // the categoryImage() check in headerLayout() and the 422 is a 200.
    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['image' => '/uploads/cb-test/mine.jpg']])->assertOk();
    expect($cat->fresh()->header_layout)->toBe(['image' => '/uploads/cb-test/mine.jpg']);
    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => ['image' => 'javascript:alert(1)']])
        ->assertStatus(422)->assertJsonValidationErrors(['header_layout.image']);
    expect($cat->fresh()->header_layout)->toBe(['image' => '/uploads/cb-test/mine.jpg']);

    // All blank is "follow the shop": NULL.
    test()->putJson('/admin-api/categories/'.$cat->id, $base + ['header_layout' => []])->assertOk();
    expect($cat->fresh()->header_layout)->toBeNull();

    // The screen gets the field back to show it.
    $row = collect(test()->getJson('/admin-api/categories')->assertOk()->json('categories') ?? test()->getJson('/admin-api/categories')->json())
        ->firstWhere('id', $cat->id);
    expect($row)->toHaveKey('header_layout');
});

it('keeps a support account out of the per-category layout, closed', function () {
    /* The write is catalog.manage, the capability every category edit holds. */
    test()->actingAs(cbOwner('support'), 'admin');
    $cat = cbCategory('cb-closed');

    test()->putJson('/admin-api/categories/'.$cat->id, ['name' => $cat->name, 'slug' => $cat->slug, 'header_layout' => ['height' => 300]])
        ->assertForbidden();
    expect($cat->fresh()->header_layout)->toBeNull();
});

it('escapes the name, cleans the description and refuses a script address', function () {
    /*
     * The name is {{ }}; the description is RichText's allowlist; the picture
     * is TitleHeader::safeImage() -- a `javascript:` picture is no picture, so
     * no banner at all.
     *
     * MUTATION: print the heading with {!! !!} in brand-panel and the first
     * line is red (`&` and `"` arrive raw).
     */
    cbCategory('cb-xss', [
        'name' => 'Toners & "Peels" <img src=x onerror=alert(1)>',
        'header_image' => '/uploads/cb-test/c.jpg',
        'description' => '<p>Soft</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
    ]);
    cbCategory('cb-js', ['header_image' => 'javascript:alert(1)']);

    $section = cbSection(cbPage('/collections/cb-xss/'));

    // Tags out (TitleHeader's plain-text rule for a heading), the rest escaped.
    expect($section)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">Toners &amp; &quot;Peels&quot;</h1>')
        ->and($section)->not->toContain('onerror')
        ->and($section)->not->toContain('<script>')
        ->and($section)->not->toContain('javascript:')
        ->and($section)->toContain('<p>Soft</p>');

    expect(cbPage('/collections/cb-js/'))->not->toContain('kbb-cbw')->not->toContain('javascript:alert');
});

/* ═══════════════════════════ 6. the query count ═══════════════════════════ */

it('costs no query: the same count with the banner, without it, and with forty products', function () {
    /*
     * Off the category row already loaded and the settings map the request
     * already read. CLAUDE.md: a category page is frozen.
     *
     * MUTATION: look the category up again inside forCategory() (or read a
     * setting with Setting::query()) and the banner page is one more.
     */
    cbCategory('cb-q1', ['header_image' => '/uploads/cb-test/c.jpg']);
    cbCategory('cb-q2');

    $count = static function (string $path): int {
        cbPage($path); // warm, as the budget test does
        Setting::flushMap();
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->get($path)->assertOk();

        return $n;
    };

    $with = $count('/collections/cb-q1/');
    $without = $count('/collections/cb-q2/');
    cbLayout(['catb_hero' => 'header']);
    $asBefore = $count('/collections/cb-q1/');

    expect($with)->toBe($asBefore)->and($with)->toBe($without);
});

/* ═══════════════════════════════ 7. Arabic ═══════════════════════════════ */

it('draws the Arabic page in Arabic, mirrored by the page\'s own direction', function () {
    /*
     * A category's Arabic name and description are its translations (Catalog
     * -> Categories -> Edit -> Arabic). The custom English title is not
     * shown on the Arabic page, as on the title header.
     *
     * MUTATION: print `$category->name` instead of the panel's heading and
     * the Arabic page shows English.
     */
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    $cat = cbCategory('cb-ar', ['header_image' => '/uploads/cb-test/c.jpg', 'header_title' => 'English Only Title']);
    $cat->saveTranslations(['ar' => ['name' => 'منظفات', 'description' => '<p>منظفات لطيفة لكل أنواع البشرة.</p>']]);
    \App\Services\Translation\TranslationStore::flush();

    $html = cbPage('/ar/collections/cb-ar/');
    $section = cbSection($html);

    expect($html)->toContain('dir="rtl"')
        ->and($section)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">منظفات</h1>')
        ->and($section)->toContain('منظفات لطيفة لكل أنواع البشرة.')
        ->and($section)->not->toContain('English Only Title');

    expect(cbSection(cbPage('/collections/cb-ar/')))->toContain('>English Only Title</h1>');
});

/* ═══════════════════ 8. the shop's own editors show what the page shows ═══════════════════ */

it('previews the category banner in the category "Edit header" panel', function () {
    /*
     * The page draws the banner, so the panel's live preview and its swap
     * after Save draw it too -- in the same wrapper the page uses, which is
     * what category-header-editor.js looks for. A category with no picture
     * keeps the title header there as on its page.
     *
     * MUTATION: drop the panel branch from CategoryHeaderApiController::
     * rendered() and the first line is red (the preview would show the old
     * title header over a page that no longer has one).
     */
    $with = cbCategory('cb-che', ['header_image' => '/uploads/cb-test/c.jpg']);
    $without = cbCategory('cb-che2');

    expect(\App\Http\Controllers\Admin\CategoryHeaderApiController::rendered($with)['html'])
        ->toStartWith('<div class="wrap kbb-cbw"><div class="brw-phw" data-kbb-brand-header>')
        ->and(\App\Http\Controllers\Admin\CategoryHeaderApiController::rendered($without)['html'])
        ->toContain('data-kbb-title-header');
});
