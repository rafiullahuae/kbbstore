<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CategoryHeaderApiController;
use App\Models\AdminUser;
use App\Models\Category;
use App\Services\CategoryHeaders;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\TitleHeader;
use Illuminate\Support\Facades\DB;
use Tests\Support\CategoryHeaderRoutes;
use Tests\Support\StorefrontAdminRoutes;

/**
 * The category page's "Edit header" panel and its custom header area. (Lane CH)
 *
 * The owner: "for categories we will have category name, description. and
 * background image, but everything can be controlled from the front-end with
 * edit button. and including header area size, spacings etc. AND in the same
 * edit panel, we will have option to choose this page with custom header area,
 * so in that tab we will see the same features as we have done for super sale
 * page."
 *
 * Each case names the defect it would have caught and the mutation that turns
 * it red.
 */

/* ---------------------------------------------------------------- helpers */

function chAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CH '.$role,
        'email' => "ch-{$role}@example.test",
        'password' => 'password-long-enough',
        'role' => $role,
    ]);
}

function chCategory(array $over = []): Category
{
    $c = Category::query()->create(array_merge([
        'name' => 'Sunscreens',
        'slug' => 'ch-sunscreens',
        'parent_id' => null,
        'description' => '<p>Light Korean sunscreens for every day.</p>',
        'header_image' => 'https://example.test/sun.jpg',
    ], $over));
    $c->forceFill(['path' => $c->slug, 'depth' => 0])->save();

    return $c->fresh();
}

function chPage(): string
{
    return (string) test()->get('/collections/ch-sunscreens/')->assertOk()->getContent();
}

function chSave(Category $c, array $body): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin-api/category-header/'.$c->id, $body);
}

beforeEach(function () {
    StorefrontAdminRoutes::wire($this->app);
    CategoryHeaderRoutes::wire($this->app);
});

/* ----------------------------------------------------------- who sees it */

it('hands the panel to owner, manager and editor on a category page only, and refuses support', function () {
    /*
     * MUTATION: drop 'editor' from categoryheader.manage in AdminCapabilities
     * and the editor's `categoryheader` is null -- red. Leave the map line out
     * of AdminCapabilities and every role but the owner is refused (fails closed).
     */
    $c = chCategory();

    foreach (['owner', 'manager', 'editor'] as $role) {
        $this->actingAs(chAdmin($role), 'admin');
        $r = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/collections/ch-sunscreens/'))->assertOk();

        expect($r->json('categoryheader.id'))->toBe($c->id, $role)
            ->and($r->json('categoryheader.endpoints.save'))->toEndWith('/admin-api/category-header/'.$c->id);

        $home = $this->getJson('/admin-api/storefront/context?path=%2F')->assertOk();
        expect($home->json('categoryheader'))->toBeNull();
    }

    $this->actingAs(chAdmin('support'), 'admin');
    chSave($c, ['mode' => 'title', 'fields' => ['header_title' => 'Nope']])->assertForbidden();
    expect($c->fresh()->header_title)->toBeNull();

    expect(AdminCapabilities::forPath('POST', 'admin-api/category-header/{id}'))->toBe('categoryheader.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/category-header/{id}/preview'))->toBe('categoryheader.manage')
        ->and(AdminCapabilities::CAPABILITIES['categoryheader.manage'])->toBe(['owner', 'manager', 'editor']);
});

it('answers the context with an allowlist, never a model', function () {
    /*
     * The context is admin-only, but it is still built key by key: a model
     * dump would carry `header_source` and `seo`.
     * MUTATION: return $category->toArray() as `categoryheader` -> red.
     */
    $c = chCategory(['header_source' => 'secret-term-meta-key']);
    $this->actingAs(chAdmin(), 'admin');

    $ctx = $this->getJson('/admin-api/storefront/context?path='.rawurlencode('/collections/ch-sunscreens/'))->assertOk()->json('categoryheader');

    expect(array_keys($ctx))->toBe(['id', 'key', 'name', 'mode', 'legacy_banner', 'fields', 'custom', 'placeholders', 'limits', 'spec', 'pageheader', 'sample', 'endpoints', 'upload', 'css',
        // Lane CB: the category banner's sheet and its rules, for a preview
        // that draws it (both constants or a same-origin path).
        'panel_css', 'panel_inline', 'console'])
        ->and(array_keys($ctx['fields']))->toBe(['header_title', 'header_description', 'header_image', 'header_style'])
        ->and(json_encode($ctx))->not->toContain('secret-term-meta-key');
});

/* ------------------------------------------------------- the title header */

it('saves name, description, pictures, sizes and spacing to that category only, and the page draws them', function () {
    /*
     * The panel's whole first tab, through the save and onto the page.
     * MUTATION: drop PANEL_NUMBERS from TitleHeader::style()'s loop and the
     * --kbb-th-mtd / -pyd / -mw assertions are red; drop the <source> branch
     * from the component and the phone picture is not on the page.
     */
    $c = chCategory();
    $other = chCategory(['slug' => 'ch-toners', 'name' => 'Toners']);
    $this->actingAs(chAdmin(), 'admin');

    chSave($c, ['mode' => 'title', 'fields' => [
        'header_title' => 'Sunscreens for the UAE sun',
        'header_description' => 'No white cast',
        'header_image' => '/uploads/categories/sun.jpg',
        'header_style' => [
            'h_desktop' => 360, 'mt_desktop' => 20, 'mb_phone' => 30, 'py_desktop' => 48, 'px_phone' => 12, 'maxw' => 900,
            'align_desktop' => 'center', 'focus_desktop' => 'left', 'img_phone' => '/uploads/categories/sun-phone.jpg',
        ],
    ]])->assertOk()->assertJson(['ok' => true, 'mode' => 'title']);

    $html = chPage();

    expect($html)->toContain('Sunscreens for the UAE sun')
        ->and($html)->toContain('No white cast')
        ->and($html)->toContain('--kbb-th-hd:360px')
        ->and($html)->toContain('--kbb-th-mtd:20px')
        ->and($html)->toContain('--kbb-th-mb:30px')
        ->and($html)->toContain('--kbb-th-pyd:48px')
        ->and($html)->toContain('--kbb-th-px:12px')
        ->and($html)->toContain('--kbb-th-mw:900px')
        ->and($html)->toContain('kbb-th--fxd-left')
        ->and($html)->toContain('<source media="(max-width: 899.98px)" srcset="/uploads/categories/sun-phone.jpg">')
        ->and($html)->toContain('src="/uploads/categories/sun.jpg"');

    expect($other->fresh()->header_style)->toBeNull()
        ->and($other->fresh()->header_title)->toBeNull();
});

it('stores the description as escaped plain text, so markup typed in is shown, never run', function () {
    /*
     * The defect this guards: a description typed on the page landing in a
     * column the page prints through the HTML allowlist, where "<b>" became
     * bold and "<a href>" a link. MUTATION: store $plain instead of e($plain)
     * and the page carries a real <b> element -- red.
     */
    $c = chCategory();
    $this->actingAs(chAdmin(), 'admin');

    chSave($c, ['mode' => 'title', 'fields' => ['header_description' => '<b>Bold</b> & <a href="https://x.test">link</a>']])->assertOk();

    $html = chPage();
    expect($html)->toContain('&lt;b&gt;Bold&lt;/b&gt; &amp; &lt;a href=')
        ->and($html)->not->toContain('<b>Bold</b>');

    // And the panel reads it back as the words typed.
    expect(CategoryHeaderApiController::fields($c->fresh())['header_description'])->toBe('<b>Bold</b> & <a href="https://x.test">link</a>');
});

it('refuses what Catalog → Categories refuses, and what the panel does not own, writing nothing', function () {
    /*
     * MUTATION: drop the Rule::in on header_style.focus_desktop in
     * TitleHeaderInput -> the "diagonal" case answers 200; drop the
     * img_phone check in TitleHeaderInput::clean() -> the data: case answers 200.
     */
    $c = chCategory();
    $this->actingAs(chAdmin(), 'admin');

    $cases = [
        ['header_style' => ['focus_desktop' => 'diagonal']],
        ['header_style' => ['align_phone' => 'justify']],
        ['header_style' => ['img_phone' => 'data:image/png;base64,AAAA']],
        ['header_style' => ['img_phone' => 'javascript:alert(1)']],
        ['header_image' => 'javascript:alert(1)'],
        ['header_title' => str_repeat('x', 161)],
        ['header_description' => str_repeat('x', 1001)],
        ['header_style' => ['bg' => '#000000', 'mode' => 'custom']],   // not a key the panel draws
        ['name' => 'Renamed'],
        ['slug' => 'moved'],
    ];

    foreach ($cases as $fields) {
        chSave($c, ['mode' => 'title', 'fields' => $fields])->assertStatus(422);
    }
    chSave($c, ['mode' => 'title', 'parent_id' => 7])->assertStatus(422);
    chSave($c, ['mode' => 'sideways'])->assertStatus(422);

    $fresh = $c->fresh();
    expect($fresh->name)->toBe('Sunscreens')
        ->and($fresh->slug)->toBe('ch-sunscreens')
        ->and($fresh->header_style)->toBeNull()
        ->and($fresh->header_title)->toBeNull();
});

it('clamps every number to its own slider and merges onto the stored look', function () {
    /*
     * MUTATION: replace the merge in validatedFields() with the panel's keys
     * alone and the box colour set in Catalog → Categories ('bg') is wiped -- red.
     */
    $c = chCategory(['header_style' => ['bg' => '#112233', 'align_desktop' => 'end']]);
    $this->actingAs(chAdmin(), 'admin');

    chSave($c, ['mode' => 'title', 'fields' => ['header_style' => ['mt_desktop' => 999, 'px_phone' => 0, 'align_desktop' => null]]])->assertOk();

    $style = TitleHeader::sanitizeStyle($c->fresh()->header_style);
    expect($style['mt_desktop'])->toBe(80)            // cat_header_mt_desktop's max
        ->and($style['px_phone'])->toBe(0)
        ->and($style['bg'])->toBe('#112233')
        ->and($style)->not->toHaveKey('align_desktop');
});

it('keeps the panel\'s settings when Catalog → Categories saves the same category', function () {
    /*
     * The defect: that screen sends a whole header_style built from its own
     * boxes, and it has no box for the spacing, the phone picture or the
     * custom area switch -- so one save there silently undid the panel.
     * MUTATION: remove the PANEL_KEYS carry-over in CategoriesApiController
     * and mt_desktop, img_phone and mode are gone -- red.
     */
    $c = chCategory(['header_style' => ['mt_desktop' => 20, 'img_phone' => '/uploads/p.jpg', 'mode' => 'custom', 'align_desktop' => 'end']]);
    $this->actingAs(chAdmin(), 'admin');

    $this->putJson('/admin-api/categories/'.$c->id, [
        'name' => 'Sunscreens', 'slug' => 'ch-sunscreens', 'parent_id' => null,
        'header_style' => ['align_desktop' => 'center', 'h_desktop' => 320],
    ])->assertOk();

    $style = TitleHeader::sanitizeStyle($c->fresh()->header_style);
    expect($style['align_desktop'])->toBe('center')
        ->and($style['h_desktop'])->toBe(320)
        ->and($style['mt_desktop'])->toBe(20)
        ->and($style['img_phone'])->toBe('/uploads/p.jpg')
        ->and($style['mode'])->toBe('custom');
});

it('previews without writing anything', function () {
    /* MUTATION: call $category->save() in preview() -> the title is stored, red. */
    $c = chCategory();
    $this->actingAs(chAdmin(), 'admin');

    $r = $this->postJson('/admin-api/category-header/'.$c->id.'/preview', ['fields' => ['header_title' => 'Only a preview', 'header_style' => ['h_desktop' => 400]]])->assertOk();

    expect($r->json('html'))->toContain('Only a preview')
        ->and($r->json('html'))->toContain('--kbb-th-hd:400px')
        ->and($c->fresh()->header_title)->toBeNull()
        ->and($c->fresh()->header_style)->toBeNull();
});

/* ------------------------------------------------- the custom header area */

function chCustom(array $over = []): array
{
    $bag = \App\Services\PageHeaders::blank();
    $bag['d']['button'] = false;
    $bag['img'] = '/uploads/categories/area.jpg';
    $banner = \App\Services\PageBanners::blank('ignored', 'Sun');
    $banner['img_d'] = '/uploads/categories/banner.jpg';
    $banner['items'] = [['en' => 'Reef friendly', 'ar' => '', 'dev' => 'both']];

    return array_replace(['header' => $bag, 'banner' => $banner, 'banner_on' => true, 'banner_at' => 'above'], $over);
}

it('switches this category to the Super Sale-style header, and back, losing nothing', function () {
    /*
     * MUTATION: in ShopController drop `$titleHeader = null` for a custom
     * category and the page carries both headers (two <h1>) -- red. Make the
     * title mode delete the CategoryHeaders entry and the second switch to
     * custom comes back without its banner -- red.
     */
    $c = chCategory(['header_title' => 'Sun care']);
    $other = chCategory(['slug' => 'ch-toners', 'name' => 'Toners']);
    $this->actingAs(chAdmin(), 'admin');

    chSave($c, ['mode' => 'custom', 'custom' => chCustom()])->assertOk()->assertJson(['mode' => 'custom']);

    $html = chPage();
    expect($html)->toContain('data-kbb-ch="'.$c->id.'"')
        ->and($html)->toContain('data-kbb-ph="category:'.$c->id.'"')
        ->and($html)->toContain('<style id="kbb-ph-css">')
        ->and($html)->toContain('src="/uploads/categories/area.jpg"')
        ->and($html)->toContain('src="/uploads/categories/banner.jpg"')
        ->and($html)->toContain('<span>Reef friendly</span>')
        ->and($html)->toContain('Sun care')
        ->and($html)->not->toContain('data-kbb-title-header')
        ->and(substr_count($html, '<h1'))->toBe(1);

    // The other category is untouched.
    expect((string) $this->get('/collections/ch-toners/')->getContent())->not->toContain('data-kbb-ch=');

    // Back to the title header: the custom look is kept.
    chSave($c, ['mode' => 'title'])->assertOk();
    $html = chPage();
    expect($html)->toContain('data-kbb-title-header')
        ->and($html)->not->toContain('data-kbb-ch=');

    app(CategoryHeaders::class)->forget();
    $kept = app(CategoryHeaders::class)->entryFor($c->id);
    expect($kept['own'])->toBeTrue()
        ->and($kept['banner']['img_d'])->toBe('/uploads/categories/banner.jpg')
        ->and($kept['header']['d']['button'])->toBeFalse();

    // And on again, from the switch alone.
    chSave($c, ['mode' => 'custom'])->assertOk();
    expect(chPage())->toContain('src="/uploads/categories/banner.jpg"');
});

it('refuses a bad custom area whole, and does not switch the page', function () {
    /*
     * PageHeaders::bag() and PageBanners::sanitize() decide, strictly.
     * MUTATION: pass $strict = false to CategoryHeaders::clean() in save()
     * and these answer 200 -- red.
     */
    $c = chCategory();
    $this->actingAs(chAdmin(), 'admin');

    $bad = [
        chCustom(['header' => ['d' => ['align' => 'diagonal']]]),
        chCustom(['header' => ['d' => ['img_h' => 99999]]]),
        chCustom(['header' => ['img' => 'javascript:alert(1)']]),
        chCustom(['banner' => ['link' => 'javascript:alert(1)'] + chCustom()['banner']]),
        chCustom(['banner' => ['bg' => 'red'] + chCustom()['banner']]),
        chCustom(['banner_at' => 'sideways']),
    ];

    foreach ($bad as $custom) {
        chSave($c, ['mode' => 'custom', 'custom' => $custom])->assertStatus(422);
    }

    expect(CategoryHeaderApiController::modeOf($c->fresh()))->toBe('title')
        ->and(app(SettingsService::class)->get(CategoryHeaders::KEY))->toBeNull();
});

it('escapes every word the area prints', function () {
    /* MUTATION: print the strip item with {!! !!} in partials/page-banner -> red. */
    $c = chCategory(['header_title' => '<script>alert(1)</script>']);
    $this->actingAs(chAdmin(), 'admin');

    $custom = chCustom();
    $custom['banner']['items'] = [['en' => '<img src=x onerror=alert(2)>', 'ar' => '', 'dev' => 'both']];
    chSave($c, ['mode' => 'custom', 'custom' => $custom])->assertOk();

    $html = chPage();
    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('<img src=x onerror');
});

it('costs a shopper no query: a custom category page runs exactly as many as a title one', function () {
    /*
     * The area is read from the category row the page already loaded and from
     * a setting every request has already loaded. MUTATION: have
     * CategoryHeaders::stored() read Setting::query()->where('key', ...) and
     * the custom page runs one more -- red.
     */
    $c = chCategory();
    $this->actingAs(chAdmin(), 'admin');
    chSave($c, ['mode' => 'custom', 'custom' => chCustom()])->assertOk();
    auth('admin')->logout();

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        chPage();

        return $n;
    };

    expect(chPage())->toContain('data-kbb-ch=');
    $custom = $count();

    $c->refresh()->forceFill(['header_style' => null])->save();
    expect(chPage())->not->toContain('data-kbb-ch=');
    $title = $count();

    expect($custom)->toBe($title)->and($custom)->toBeGreaterThan(0);
});

it('changes nothing on a category nobody edited: no area, no new style property, no picture element', function () {
    /*
     * The shipped default. MUTATION: default `mode` to custom in
     * forCategory(), or print the <picture> without a phone picture -> red.
     */
    chCategory();

    $html = chPage();
    expect($html)->toContain('data-kbb-title-header')
        ->and($html)->not->toContain('data-kbb-ch=')
        ->and($html)->not->toContain('kbb-th__pic')
        ->and($html)->not->toContain('kbb-th--fxd-')
        ->and(app(SettingsService::class)->get(CategoryHeaders::KEY))->toBeNull();
});

/* ------------------------------------------------------- the editor chunk */

it('ships the editor as its own chunk that a shopper never fetches, and that measures nothing', function () {
    /*
     * MUTATION: import category-header-editor.js statically at the top of
     * storefront-admin.js -> the dynamic-import assertion is red. Use
     * getBoundingClientRect anywhere in the module -> red.
     */
    $admin = (string) file_get_contents(resource_path('js/kbb/admin/storefront-admin.js'));
    $editor = (string) file_get_contents(resource_path('js/kbb/admin/category-header-editor.js'));

    expect(substr_count($admin, "import('./category-header-editor.js')"))->toBe(1)
        ->and($admin)->not->toMatch('/^import .*category-header-editor/m');

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollHeight', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'setInterval'] as $banned) {
        expect($editor)->not->toContain($banned);
    }

    chCategory();
    expect(chPage())->not->toContain('category-header-editor');

    // The built chunk exists and is not an entry a page links.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $chunk = collect($manifest)->first(fn ($e) => str_contains((string) ($e['src'] ?? ''), 'category-header-editor.js'));
    expect($chunk)->not->toBeNull()
        ->and($chunk['isEntry'] ?? false)->toBeFalse()
        ->and(is_file(public_path('build/'.$chunk['file'])))->toBeTrue();
});

it('opens on the title header alone: no custom-area tab unless the category already uses one', function () {
    /*
     * The owner, 5 October: "FOR CATEGORIES pages ... backgroudn image,
     * centeralized title and description text. that's it."
     * DEFECT THIS CATCHES: the "Custom header area" tab back in front of him
     * on every category. MUTATION: delete the early return in drawTabs() -> red.
     */
    $js = file_get_contents(resource_path('js/kbb/admin/category-header-editor.js'));

    expect($js)->toContain("if (ctx.mode !== 'custom' && st.tab !== 'custom') { tabsHost.replaceChildren(); return; }");
});
