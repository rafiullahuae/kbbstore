<?php

declare(strict_types=1);

/**
 * Lane PH -- THE PAGE HEADER IN THE BRAND PAGE'S DESIGN.
 *
 * The owner, 9 October: "Also i want the same header style, which we used for
 * categories and brands page. need the same for normal pages too. except
 * homepage, we must should have control to display header or normal site
 * banner, only that will be difference from other headers."
 *
 * So every CONTENT PAGE (About, Contact, Delivery, Returns, Terms, FAQ,
 * Privacy -- store/page.blade.php) and the Journal's index draw the brand
 * page's Panel header by default: the same partial (store/partials/brand-panel),
 * the same stylesheet, the same controls under `pg_*`. A page with no picture
 * gets the same no-picture look a brand and a category get. The switch -- the
 * one difference -- is "Brand-page design / Normal page banner", shop-wide in
 * Appearance -> Site layout -> Page header (brand design) and per page in
 * Pages -> User pages -> Edit page -> Page header. "Normal page banner" is the
 * page exactly as it was. The homepage, cart, checkout, account, search and
 * the articles are not touched.
 *
 * Each `it` says the defect it would catch, and its MUTATION line names the
 * change that turns it red.
 */

use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\BrandPanel;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnglishRenderWalk;

/** The release this lane branched from: its views are "the page as it was". */
// 2.60.446 (838ac564), not .445: .446 moved the footer strip icon to the Support
// headset, which every content page prints and this lane did not touch.
const PBH_BASE = '838ac564';

const PBH_PICS = ['own', 'banner'];

function pbhLayout(array $values): void
{
    app(SiteLayout::class)->save($values);
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function pbhHtml(string $path): string
{
    Setting::flushMap();
    SettingsService::forgetMemo();

    return (string) test()->get($path)->assertOk()->getContent();
}

function pbhPage(string $slug = 'about'): Page
{
    return Page::query()->where('slug', $slug)->firstOrFail();
}

/** The header's wrapper on a content page, or ''. */
function pbhPanel(string $html): string
{
    return preg_match('#<div class="kbb-cbw">\n<div class="brw-phw" data-kbb-brand-header>.*?</section>\n</div>\n</div>#s', $html, $m) === 1 ? $m[0] : '';
}

function pbhAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PBH '.$role, 'email' => 'pbh-'.$role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role,
    ]);
}

/** What the page editor posts, with this lane's block on top. */
function pbhSave(Page $page, mixed $header, string $role = 'owner')
{
    test()->actingAs(pbhAdmin($role), 'admin');

    return test()->postJson('/admin-api/page-editor-save/'.$page->id, [
        'title' => \App\Support\PageTitle::decoded($page->title),
        'content' => (string) $page->content,
        'status' => 'published',
        'seo' => [],
        'translations' => [],
        'header_layout' => $header,
    ]);
}

beforeEach(function () {
    foreach (PBH_PICS as $name) {
        $path = public_path('uploads/pbh-test/'.$name.'.jpg');
        @mkdir(\dirname($path), 0775, true);
        $im = imagecreatetruecolor(1200, 300);
        imagejpeg($im, $path, 60);
        imagedestroy($im);
    }
});

afterEach(function () {
    foreach (PBH_PICS as $name) {
        @unlink(public_path('uploads/pbh-test/'.$name.'.jpg'));
    }
    @rmdir(public_path('uploads/pbh-test'));
});

/* ═══════════════════════ 1. the default: the brand design ═══════════════════════ */

it('draws the brand page header on every content page by default, with the no-picture look', function () {
    /*
     * The owner asked for it, so it ships ON: a page with no picture draws the
     * brand page's no-picture Panel (`brw-ph--noimg`), the page's title as its
     * one <h1>, and the brand header's stylesheet in the <head>.
     *
     * MUTATION: ship `pg_hero` at 'banner' (or return null from forPage()) and
     * every page here is red -- the old `<h1>` in .policy comes back.
     */
    foreach (['about' => 'About Us', 'contact-us' => 'Contact Us', 'privacy-policy' => 'Privacy Policy'] as $slug => $title) {
        $html = pbhHtml('/'.$slug.'/');
        $panel = pbhPanel($html);

        expect($panel)->toContain('class="brw-ph brw-ph--frost brw-ph--pill-capsule brw-ph--logo-circle brw-ph--pos-center brw-ph--noimg"')
            ->and($panel)->toContain('<h1 class="brw-ph__name" id="brw-ph-title">'.$title.'</h1>')
            ->and($panel)->not->toContain('<img')
            ->and($panel)->not->toContain('brw-logo')
            ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*kbb-brand-header[^"]*\.css"#')
            ->and($html)->toContain('<style>'.BrandPanel::CATEGORY_CSS.BrandPanel::PAGE_CSS.'</style>')
            ->and($html)->not->toContain('<h1>'.$title.'</h1>')
            ->and(substr_count($html, '<h1'))->toBe(1);
    }
});

it('draws the page\'s own Header picture: srcset, reserved height, the LCP hint', function () {
    /*
     * Every picture through ImageVariants and the on-server check, exactly as
     * a category's: a <picture>, width/height for the ratio (the box's height
     * is the stylesheet's min-height, so nothing moves), fetchpriority=high.
     *
     * MUTATION: drop the image candidates from forPage() and this is the
     * no-picture look instead.
     */
    pbhPage()->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg', 'sub' => 'Chosen with care']])->save();

    $panel = pbhPanel(pbhHtml('/about/'));

    expect($panel)->not->toContain('brw-ph--noimg')
        ->and($panel)->toContain('<picture class="brw-ph__pic">')
        ->and($panel)->toContain('src="/uploads/pbh-test/own.jpg"')
        ->and($panel)->toContain('width="'.\App\Support\TitleHeader::IMG_WIDTH.'" height="'.\App\Support\TitleHeader::IMG_HEIGHT.'"')
        ->and($panel)->toContain('fetchpriority="high"')
        ->and($panel)->toContain('<div class="brw-ph__desc brw-desc">Chosen with care</div>');
});

it('picks up the picture the page\'s normal banner would have shown, and draws that banner no longer', function () {
    /*
     * The category rule: "if there's banner, then the banner should be picked
     * auto by new design". A page with a Pages -> Page banners picture gets it
     * as the header's background, and the strip itself is not drawn as well.
     *
     * MUTATION: pass null for the banner in PageController::show() and the
     * first expectation is red; draw `pageTop` regardless and the last is.
     */
    $service = app(\App\Services\PageBanners::class);
    $all = $service->all();
    $all['banners'][] = ['img_d' => '/uploads/pbh-test/banner.jpg'] + \App\Services\PageBanners::blank('pbh', 'PBH');
    $all['assign']['page:about'] = 'pbh';
    expect($service->save($all))->toBe([]);
    $service->forget();

    $html = pbhHtml('/about/');

    expect(pbhPanel($html))->toContain('src="/uploads/pbh-test/banner.jpg"')
        ->and($html)->not->toContain('class="kbb-pb"');

    // Its own Header picture wins over the banner's.
    pbhPage()->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg']])->save();
    expect(pbhPanel(pbhHtml('/about/')))->toContain('src="/uploads/pbh-test/own.jpg"')->not->toContain('banner.jpg');
});

it('takes the banner picture before the page header picture, and its own before both (unit)', function () {
    /*
     * The order BrandPanel::forPage() reads its candidates in, without going
     * through the Page banners screen's own storage.
     *
     * MUTATION: swap the candidates in forPage() and the first line is red.
     */
    $layout = app(SiteLayout::class)->all();
    $page = pbhPage();

    $fromBanner = BrandPanel::forPage($page, $layout, ['d' => '/uploads/pbh-test/banner.jpg', 'm' => ''], ['d' => '/uploads/pbh-test/own.jpg', 'm' => '']);
    expect($fromBanner['image'])->toBe('/uploads/pbh-test/banner.jpg');

    $fromHeader = BrandPanel::forPage($page, $layout, null, ['d' => '/uploads/pbh-test/own.jpg', 'm' => '']);
    expect($fromHeader['image'])->toBe('/uploads/pbh-test/own.jpg');

    $page->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg']]);
    expect(BrandPanel::forPage($page, $layout, ['d' => '/uploads/pbh-test/banner.jpg', 'm' => ''])['image'])->toBe('/uploads/pbh-test/own.jpg');
});

it('never draws a broken header: a picture that is not on this server gets the no-picture look', function () {
    /*
     * A picture deleted from the server, never copied, or still on the old
     * domain: the no-picture look, never an <img> on a 404.
     *
     * MUTATION: drop the onServer() test in pagePanel() and this is red.
     */
    foreach (['/uploads/pbh-test/never-copied.jpg', 'https://kbeautybliss.com/wp-content/uploads/2024/01/about.jpg'] as $missing) {
        pbhPage()->forceFill(['header_layout' => ['image' => $missing]])->save();
        $html = pbhHtml('/about/');

        expect(pbhPanel($html))->toContain('brw-ph--noimg')->not->toContain('<img')
            ->and($html)->not->toContain('never-copied.jpg')
            ->and($html)->not->toContain('kbeautybliss.com/wp-content');
    }
});

it('keeps exactly one <h1>: the header carries it, the article does not repeat it', function () {
    /*
     * MUTATION: drop `&& empty($pagePanel)` from page.blade.php's article <h1>
     * and the count is 2.
     */
    pbhPage()->forceFill(['header_layout' => ['title' => 'Who we are']])->save();

    $html = pbhHtml('/about/');

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(pbhPanel($html))->toContain('id="brw-ph-title">Who we are</h1>');

    pbhLayout(['pg_hero' => 'banner']);
    pbhPage()->forceFill(['header_layout' => null])->save();
    expect(substr_count(pbhHtml('/about/'), '<h1'))->toBe(1);
});

/* ════════════════════ 2. the switch: Normal page banner ════════════════════ */

it('puts every page back exactly as it was under "Normal page banner", byte for byte', function () {
    /*
     * The owner's one difference: the normal site banner instead. Shop-wide,
     * "Normal page banner (as before)" renders each content page byte for byte
     * as the views of the release this lane branched from render it.
     *
     * MUTATION: print anything in page.blade.php outside the `$pagePanel`
     * guards (a newline, the stylesheet) and this is red.
     */
    $this->travelTo(\Carbon\Carbon::parse('2026-06-15 09:30:00'));
    pbhLayout(['pg_hero' => 'banner', 'pg_blog' => 'pages']);
    /*
     * The site LOGO is not this test's subject; the page header is. Since
     * 2.60.451 the header logo ships as the lotus lockup (Lane LG2, the
     * owner's choice), which the 838ac564 views cannot print. "Text only (as
     * before)" is the old wordmark byte for byte by design (LogoLockupTest and
     * EnglishRenderWalk pin that), so it is set here and the comparison stays
     * whole: every other byte of all four pages is still compared.
     */
    /*
     * ▲ 2.60.460 (Lane QK12): the phone menu icon's default moved to `lines`,
     * as the owner asked, and the 838ac564 views have no such icon. The
     * previous default, `tiles`, is set here so the button renders as those
     * views drew it; Qk12HeaderAndMenuTest owns the new icon and the flash.
     */
    app(\App\Services\HeaderSettings::class)->save(['logo_style' => 'text', 'menu_icon' => 'tiles']);
    // ▲ 2.60.460: and the flash beside Super Sale is new markup the same views never drew.
    app(\App\Services\MobileMenu::class)->save(['sale_flash' => false]);
    /*
     * ▲ 2.60.466 (Lane AN): with the footer's colour drift on, the big name
     * carries an empty `.kft-nm` copy the compositor moves, which the 838ac564
     * views never drew. Drift off prints the name as those views did (and
     * drops `kft-motion` from BOTH renders alike), so every other byte of all
     * four pages is still compared; ShopAnimationsCompositorOnlyTest and
     * SiteFooterTest own the copy.
     */
    app(\App\Services\SiteFooter::class)->save(['site_motion' => false]);
    /*
     * ▲ 2.60.457 (Lane TS): the trust strip above the footer ships ON, as the
     * owner asked, and the 838ac564 views have no such row. It is not part of
     * the brand header this test is about, so it is switched off here and the
     * rest of all four pages is still compared byte for byte; TrustStripTest
     * owns where the strip appears.
     */
    app(\App\Services\SettingsService::class)->set('ts_foot', 'off');
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();

    $current = config('view.paths');
    /*
     * /contact-us/ left this list at 2.60.449: Lane CT rebuilt that page on
     * the owner's brief (its own inline <style>, the cards and the inquiry
     * form, all behind page.blade.php's `$contactHub`, which is null on every
     * other page), so it no longer matches the 838ac564 views and is not meant
     * to. The four pages left still render through the same page.blade.php,
     * so printing anything outside the `$pagePanel` guards stays red here;
     * the contact page's own header is covered by "lets one page choose".
     */
    $paths = ['/about/', '/privacy-policy/', '/terms-and-conditions/', '/blog/'];
    $render = function () use ($paths): array {
        $out = [];
        foreach ($paths as $path) {
            test()->flushSession();
            $out[$path] = EnglishRenderWalk::mask(pbhHtml($path));
        }

        return $out;
    };

    try {
        EnglishRenderWalk::useViewPath(EnglishRenderWalk::baseViews(PBH_BASE));
        $before = $render();
        EnglishRenderWalk::useViewPath($current[0]);
        $after = $render();
    } finally {
        EnglishRenderWalk::useViewPath($current[0]);
    }

    foreach ($paths as $path) {
        expect(strlen($before[$path]))->toBeGreaterThan(3000)
            ->and($after[$path])->toBe($before[$path], $path.' '.EnglishRenderWalk::firstDifference($before[$path], $after[$path]));
    }
});

it('lets one page choose for itself, both ways', function () {
    /*
     * Pages -> User pages -> Edit page -> Page header -> Header: an explicit
     * choice wins over the shop's, in either direction; blank follows it.
     *
     * MUTATION: read only `pg_hero` in forPage() (drop pageHero()) and both
     * halves are red.
     */
    pbhPage('contact-us')->forceFill(['header_layout' => ['hero' => 'banner']])->save();

    expect(pbhPanel(pbhHtml('/contact-us/')))->toBe('')
        ->and(pbhHtml('/contact-us/'))->toContain('<h1>Contact Us</h1>')
        ->and(pbhPanel(pbhHtml('/about/')))->not->toBe('');

    pbhLayout(['pg_hero' => 'banner']);
    pbhPage('about')->forceFill(['header_layout' => ['hero' => 'brand']])->save();

    expect(pbhPanel(pbhHtml('/about/')))->toContain('brw-ph__name')
        ->and(pbhPanel(pbhHtml('/privacy-policy/')))->toBe('');
});

/* ═══════════════════════ 3. what is NOT a content page ═══════════════════════ */

it('leaves the homepage, cart, checkout, account, an article and search alone, whatever the switch says', function () {
    /*
     * The homepage keeps its own slider; the functional pages and the
     * articles are not content pages. Each renders the same bytes with the
     * switch on either side, and with no brand header in them.
     *
     * MUTATION: push page-panel-head from the layout (or draw the header from
     * a shared partial) and these are red.
     */
    Post::query()->create(['slug' => 'pbh-article', 'title' => 'A routine', 'excerpt' => 'Four steps.', 'body' => '<p>Cleanse.</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
    $this->travelTo(\Carbon\Carbon::parse('2026-06-15 09:30:00'));

    $paths = ['/', '/cart/', '/my-account/', '/blog/pbh-article/', '/shop/?s=serum'];
    $render = function () use ($paths): array {
        $out = [];
        foreach ($paths as $path) {
            test()->flushSession();
            $response = test()->get($path);
            $out[$path] = $response->getStatusCode().' '.EnglishRenderWalk::mask((string) $response->getContent());
        }

        return $out;
    };

    $on = $render();
    pbhLayout(['pg_hero' => 'banner', 'pg_blog' => 'own']);
    $off = $render();

    foreach ($paths as $path) {
        expect($on[$path])->toBe($off[$path], $path)
            ->and($on[$path])->not->toContain('brw-phw')
            ->and($on[$path])->not->toContain(BrandPanel::PAGE_CSS);
    }

    // The checkout with an empty bag is a redirect either way -- not a page that grew a header.
    expect(test()->get('/checkout/')->getStatusCode())->toBeIn([200, 301, 302]);
});

/* ═════════════════════════════ 4. the Journal ═════════════════════════════ */

it('draws the Journal index in the brand design by default, and its own header on request', function () {
    /*
     * "The Journal (/blog/) header": Same as the pages (default), Brand-page
     * design, or the Journal's own. Its heading stays the page's one <h1>,
     * and the tag chips keep their place under the header.
     *
     * MUTATION: ignore `pg_blog` in forJournal() and the third block is red.
     */
    $html = pbhHtml('/blog/');
    expect($html)->toContain('<div class="wrap kbb-cbw">')
        ->and($html)->toContain('id="brw-ph-title">Skincare tips &amp; the K-beauty edit</h1>')
        ->and($html)->toContain('<div class="chips" id="chips"></div>')
        ->and($html)->not->toContain('<section class="hero">')
        ->and(substr_count($html, '<h1'))->toBe(1);

    pbhLayout(['pg_hero' => 'banner']);
    expect(pbhHtml('/blog/'))->toContain('<section class="hero">')->not->toContain('brw-phw');

    pbhLayout(['pg_hero' => 'panel', 'pg_blog' => 'own']);
    expect(pbhHtml('/blog/'))->toContain('<section class="hero">')->not->toContain('brw-phw');

    pbhLayout(['pg_hero' => 'banner', 'pg_blog' => 'panel']);
    expect(pbhHtml('/blog/'))->toContain('brw-phw')->not->toContain('<section class="hero">');
});

/* ═════════════════════════════ 5. speed ═════════════════════════════ */

it('costs no query: the same count with the header, without it, and with forty pages', function () {
    /*
     * Off the page row already loaded and the settings the request already
     * read; the picture's shape is read from the file, never the database.
     * And the cost stays flat as the shop's pages grow.
     *
     * MUTATION: look the page up again in forPage() or read a setting with
     * Setting::query() and `$with` is one more.
     */
    pbhPage()->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg']])->save();

    $count = static function (string $path): int {
        pbhHtml($path);
        Setting::flushMap();
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        test()->get($path)->assertOk();
        DB::getEventDispatcher()?->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };

    $with = $count('/about/');
    $without = $count('/contact-us/');
    pbhLayout(['pg_hero' => 'banner']);
    $asBefore = $count('/about/');
    pbhLayout(['pg_hero' => 'panel']);

    foreach (range(1, 40) as $i) {
        Page::query()->create(['slug' => 'pbh-extra-'.$i, 'title' => 'Extra '.$i, 'content' => '<p>x</p>', 'status' => 'published']);
    }
    $forty = $count('/about/');

    expect($with)->toBe($asBefore)
        ->and($with)->toBe($without)
        ->and($forty)->toBe($with);
});

/* ═══════════════════════ 6. the editor, and security ═══════════════════════ */

it('saves a page\'s own header from the page editor, and hands the screen what it shows', function () {
    /*
     * MUTATION: drop the header_layout write from save() and the stored row is
     * null; drop it from projection() and the screen reloads empty.
     */
    $page = pbhPage();

    $out = pbhSave($page, ['hero' => 'brand', 'image' => '/uploads/pbh-test/own.jpg', 'title' => '  Who   we are ', 'sub' => 'Since 2019'])
        ->assertOk()->json('page');

    expect($page->fresh()->header_layout)->toBe(['hero' => 'brand', 'image' => '/uploads/pbh-test/own.jpg', 'title' => 'Who we are', 'sub' => 'Since 2019'])
        ->and($out['header_layout'])->toBe(['hero' => 'brand', 'image' => '/uploads/pbh-test/own.jpg', 'title' => 'Who we are', 'sub' => 'Since 2019'])
        ->and($out['header_shop'])->toBe('brand');

    // Every field blank: "follow the shop", stored as NULL.
    pbhSave($page, ['hero' => '', 'image' => '', 'title' => '', 'sub' => ''])->assertOk();
    expect($page->fresh()->header_layout)->toBeNull();
});

it('refuses an unsafe picture, an unknown key and a choice that is not on the list, with a 422', function () {
    /*
     * The picture goes through TitleHeader::safeImage(), the category Banner
     * picture's own check: an uploaded path or an http(s) address. Anything
     * else is refused -- not dropped -- and nothing is written.
     *
     * MUTATION: drop the safeImage() refusal in headerLayout() and the first
     * four are 200s that store nothing (or store the address).
     */
    $page = pbhPage();
    $page->forceFill(['header_layout' => ['sub' => 'kept']])->save();

    foreach (['javascript:alert(1)', '//evil.test/x.jpg', '/uploads/../../.env', '/uploads/a.jpg" onerror="alert(1)'] as $bad) {
        pbhSave($page, ['image' => $bad])->assertStatus(422)->assertJsonValidationErrors(['header_layout.image']);
    }

    pbhSave($page, ['hero' => 'carousel'])->assertStatus(422)->assertJsonValidationErrors(['header_layout.hero']);
    pbhSave($page, ['colour' => 'red'])->assertStatus(422)->assertJsonValidationErrors(['header_layout']);
    pbhSave($page, ['title' => str_repeat('x', BrandPanel::PAGE_TITLE_MAX + 1)])->assertStatus(422);

    expect($page->fresh()->header_layout)->toBe(['sub' => 'kept']);
});

it('keeps an account without pages.manage out, closed', function () {
    /*
     * The page editor's own capability; no new endpoint.
     *
     * MUTATION: map page-editor-save to a broader capability and this is a 200.
     */
    $page = pbhPage();

    pbhSave($page, ['hero' => 'banner'], 'support')->assertForbidden();
    expect($page->fresh()->header_layout)->toBeNull();
});

it('escapes the title and subtitle, and prints no tag the owner typed', function () {
    /*
     * Both are one line of plain words, printed with {{ }}.
     *
     * MUTATION: print the heading with {!! !!} in brand-panel and the first
     * expectation is red.
     */
    pbhPage()->forceFill(['header_layout' => ['title' => 'Us & "them" <b>bold</b>', 'sub' => '<script>alert(1)</script> & co']])->save();

    $panel = pbhPanel(pbhHtml('/about/'));

    expect($panel)->toContain('id="brw-ph-title">Us &amp; &quot;them&quot; bold</h1>')
        ->and($panel)->toContain('>alert(1) &amp; co</div>')
        ->and($panel)->not->toContain('<script>')
        ->and($panel)->not->toContain('<b>');
});

it('keeps the Header picture out of the Media Library\'s delete list, and moves it with a WebP conversion', function () {
    /*
     * The header is drawn on that file, so the library must count it as used.
     *
     * MUTATION: drop pageHeaderPictures() from MediaUsage::index() and the
     * first is red; drop header_layout from WebpReferences and the second is.
     */
    pbhPage()->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg']])->save();

    $hits = \App\Support\MediaUsage::verify(\App\Support\MediaUsage::index(), 'own.jpg', 'uploads/pbh-test/own.jpg');

    expect(collect($hits)->pluck('field')->all())->toContain('Page header picture')
        ->and(\App\Services\Media\WebpReferences::COLUMNS['pages'][1])->toContain('header_layout');
});

/* ═══════════════════════ 7. the controls, one list ═══════════════════════ */

it('answers every Page header control under its own name, with the category\'s bounds and shipped values', function () {
    /*
     * The Panel's controls again, under `pg_`, minus the five logo ones (a
     * page has no logo). Same bounds and defaults as the category's, so the
     * two cannot drift; the switch first.
     *
     * MUTATION: ship a `pg_` default different from its `catb_` twin and the
     * loop is red; move pg_hero off the top and the first line is.
     */
    expect(SiteLayout::PGBANNER_KEYS[0])->toBe('pg_hero')
        ->and(SiteLayout::SCHEMA['pg_hero'][2])->toBe('panel')
        ->and(SiteLayout::TABS['pagebanner'][2])->toBe(SiteLayout::PGBANNER_KEYS);

    $logo = ['logo', 'logo_show', 'logo_show_m', 'logo_size', 'logo_size_m'];
    $n = 0;

    foreach (BrandPanel::CHOICES + BrandPanel::RANGES as $key => $spec) {
        $pg = BrandPanel::settingKey($spec[0], BrandPanel::PAGE_PREFIX);
        $catb = BrandPanel::settingKey($spec[0], true);

        if (in_array($key, $logo, true)) {
            expect(isset(SiteLayout::SCHEMA[$pg]))->toBeFalse($pg);

            continue;
        }

        expect(SiteLayout::SCHEMA[$pg][2])->toBe(SiteLayout::SCHEMA[$catb][2], $pg)
            ->and(SiteLayout::SCHEMA[$pg][4] ?? null)->toBe(SiteLayout::SCHEMA[$catb][4] ?? null, $pg)
            ->and(in_array($pg, SiteLayout::PGBANNER_KEYS, true))->toBeTrue($pg);
        $n++;
    }

    expect(count(SiteLayout::PGBANNER_KEYS))->toBe($n + 2);

    // And a page's header follows them: a 300px laptop height prints.
    pbhLayout(['pg_banner_h' => 300]);
    expect(pbhPanel(pbhHtml('/about/')))->toContain('--brw-ph-h:300px');
});

it('leaves the brand and category headers alone, whatever the page header is set to', function () {
    /*
     * MUTATION: read `pg_` keys in forBrand()/forCategory() (a wrong prefix in
     * settingKey()) and the brand's height is 300 here.
     */
    pbhLayout(['pg_banner_h' => 300, 'pg_panel_style' => 'brand']);

    $brand = \App\Models\Brand::query()->create(['name' => 'PBH Brand', 'slug' => 'pbh-brand']);
    $html = pbhHtml('/brands/pbh-brand/');

    expect($html)->toContain('brw-ph--frost')->not->toContain('--brw-ph-h:300px');
});

it('lists a Header picture in the Media Library grid without breaking it', function () {
    /*
     * A page's Header picture is recorded under the SITE kind, the way the
     * share image is. The grid joins each recorded kind back to its owner's
     * name; a kind with no owning table must be skipped there, not looked up.
     * The delete guard (the index above) is what keeps the file; the grid only
     * has to keep drawing.
     *
     * MUTATION: drop the kind guard in MediaLibraryApiController::usageFor()
     * and this is a 500 ("Undefined array key "site"").
     */
    \Tests\Support\MediaLibraryRoutes::wire(app());
    test()->actingAs(pbhAdmin(), 'admin');

    $media = \App\Models\Media::create(['filename' => 'own.jpg', 'path' => 'uploads/pbh-test/own.jpg', 'mime' => 'image/jpeg', 'size' => 1024, 'alt' => '']);
    pbhPage()->forceFill(['header_layout' => ['image' => '/uploads/pbh-test/own.jpg']])->save();
    \App\Support\MediaUsageWriter::syncMedia([$media]);

    expect(DB::table('media_usages')->where('media_id', $media->id)->value('owner_type'))->toBe('site');

    $tile = collect(test()->getJson('/admin-api/media')->assertOk()->json('items'))->firstWhere('filename', 'own.jpg');

    expect($tile)->not->toBeNull()
        ->and($tile['used'])->toBeTrue()
        ->and($tile['used_types'])->toBe(['site']);
});
