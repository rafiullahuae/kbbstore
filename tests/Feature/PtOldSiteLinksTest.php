<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE PT — internal links in imported copy that still open the OLD shop
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE DEFECT, AS THE OWNER SAW IT ON extrabeauty.ae (2 October 2026): "Internal
 * links in the end of anua foam and other cleansers -- on all articles and
 * products/sets descriptions, internal links are going to still old site." The
 * Anua foam's description ends "Get premium Face Cleansers at unbeatable prices
 * only at K-Beauty Bliss", and "Face Cleansers" was
 * <a href="https://kbeautybliss.com/pt-face-washes/"> -- a click left the new shop
 * for a site about to be switched off. Every product, set and article written on
 * WordPress carries links like it, and the import copied them verbatim.
 *
 * App\Services\Import\OldSiteLinks re-points them -- at the end of every import
 * and from Store -> Store Import / Export -> Addresses & pictures -> Links to
 * the old site -- and records what it did so Undo can put it back.
 *
 * MUTATIONS, RUN (each red, then restored):
 *   - drop `www.` from OldSiteLinks::hosts(): red, the article's
 *     http://www.kbeautybliss.com/product/serum-4021/ stays on the old host
 *     after the import (`it re-points the links the export carries...`);
 *   - remove the uploads-file early return in resolve(): red, the anchor to
 *     .../wp-content/uploads/guide.pdf is rewritten to a local path whose file
 *     is not here (`it leaves a link to an uploaded FILE to the picture pass`);
 *   - Url::raw() -> plain path in resolve(): red under KBB_BASE_PATH
 *     (`it carries the base path`);
 *   - the replay() call removed from ProductImporter: red, a second import of
 *     the unchanged fixture reports product 4023 `updated`
 *     (`it is idempotent across a second import`);
 *   - the `$queues` consumption in restore() replaced by a plain map: red, two
 *     old links that landed on one address come back as the same one;
 *   - the `data.old_links` rule moved below the urls-media/** wildcard: red,
 *     forPath() answers data.import (`it has a capability of its own`) -- the
 *     403s stay green either way, because data.import is owner-only too today,
 *     which is exactly why the map is pinned and not only the refusal.
 */

use App\Models\AdminUser;
use App\Models\Block;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\Entities\ContentBlockImporter;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\OldSiteLinks;
use App\Support\AdminCapabilities;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Tests\Support\UrlsMediaAdminRoutes;

function ptLinkShop(): void
{
    $skin = Category::query()->create(['name' => 'Skincare', 'slug' => 'pt-skin', 'parent_id' => null]);
    $skin->forceFill(['path' => 'pt-skin', 'depth' => 0])->save();

    $wash = Category::query()->create(['name' => 'Face Washes', 'slug' => 'pt-face-washes', 'parent_id' => $skin->id]);
    $wash->forceFill(['path' => 'pt-skin/pt-face-washes', 'depth' => 1])->save();

    Brand::create(['name' => 'Anua', 'slug' => 'pt-anua']);

    Product::query()->create(['name' => 'Anua Heartleaf Foam', 'slug' => 'pt-anua-foam', 'price' => 5000, 'status' => 'publish']);

    Post::create(['slug' => 'pt-double-cleansing', 'title' => 'Double cleansing', 'status' => 'published']);

    Page::query()->create(['slug' => 'pt-about-us', 'title' => 'About', 'content' => '<p>x</p>', 'status' => 'published']);

    Redirect::query()->create(['source' => '/pt-old-sale/', 'target' => '/super-sale/', 'enabled' => true]);
}

function ptOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PT '.$role,
        'email' => 'pt-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/* ═════════════════════════════════════════════════════════ where it goes ═══ */

it('sends each kind of old address to this shop\'s address for the same thing', function () {
    ptLinkShop();

    $links = new OldSiteLinks;

    $cases = [
        // The owner's own case: a flat root category on the old shop.
        'https://kbeautybliss.com/pt-face-washes/' => '/collections/pt-skin/pt-face-washes/',
        'https://kbeautybliss.com/product-category/pt-skin/pt-face-washes/' => '/collections/pt-skin/pt-face-washes/',
        'https://kbeautybliss.com/pt-skin/pt-face-washes/' => '/collections/pt-skin/pt-face-washes/',
        'https://kbeautybliss.com/product/pt-anua-foam/' => '/product/pt-anua-foam/',
        'http://www.kbeautybliss.com/product/pt-anua-foam/?ref=desc#how' => '/product/pt-anua-foam/?ref=desc#how',
        '//kbeautybliss.com/brand/pt-anua/' => '/brands/pt-anua/',
        'https://kbeautybliss.com/pa_brands/pt-anua/' => '/brands/pt-anua/',
        'https://kbeautybliss.com/shop/?filter_brands=pt-anua' => '/brands/pt-anua/',
        'https://kbeautybliss.com/shop/?filter_brands=pt-anua,cosrx' => '/shop/?filter_brands=pt-anua,cosrx',
        'https://kbeautybliss.com/pt-double-cleansing/' => '/blog/pt-double-cleansing/',
        'https://kbeautybliss.com/skincare-guide/pt-double-cleansing/' => '/blog/pt-double-cleansing/',
        'https://kbeautybliss.com/2021/05/pt-double-cleansing/' => '/blog/pt-double-cleansing/',
        'https://kbeautybliss.com/pt-about-us/' => '/pt-about-us/',
        'https://kbeautybliss.com/pt-old-sale/' => '/super-sale/',
        'https://kbeautybliss.com/' => '/',
        // Nothing here answers it: the same path on THIS shop, never the old host.
        'https://kbeautybliss.com/Some-Retired-Page/' => '/Some-Retired-Page/',
        'https://KBEAUTYBLISS.COM/product/gone-forever/' => '/product/gone-forever/',
    ];

    $got = [];

    foreach ($cases as $from => $expected) {
        $got[$from] = $links->resolve($from);
    }

    expect($got)->toBe($cases);

    // Not ours: another host, a mail link, a relative link, an uploaded FILE.
    expect($links->resolve('https://www.instagram.com/kbeautybliss/'))->toBeNull()
        ->and($links->resolve('https://kbeautybliss.com.evil.test/product/pt-anua-foam/'))->toBeNull()
        ->and($links->resolve('mailto:info@kbeautybliss.com'))->toBeNull()
        ->and($links->resolve('/collections/pt-skin/'))->toBeNull()
        ->and($links->resolve('javascript:alert(1)//kbeautybliss.com'))->toBeNull()
        ->and($links->resolve('https://kbeautybliss.com/wp-content/uploads/2021/05/guide.pdf'))->toBeNull();
});

it('carries the base path when the shop is mounted under one', function () {
    ptLinkShop();
    config(['kbb.base_path' => '/kbb-upgrade']);
    Url::forgetBase();

    expect((new OldSiteLinks)->resolve('https://kbeautybliss.com/pt-face-washes/'))
        ->toBe('/kbb-upgrade/collections/pt-skin/pt-face-washes/');

    config(['kbb.base_path' => '']);
    Url::forgetBase();

    expect((new OldSiteLinks)->resolve('https://kbeautybliss.com/pt-face-washes/'))
        ->toBe('/collections/pt-skin/pt-face-washes/');
});

/* ══════════════════════════════════════════════════════════ the rewrite ═══ */

it('changes only the address inside the link -- the words, rel, target and every other byte stay', function () {
    ptLinkShop();

    $before = "<p>Foam that lifts sunscreen.</p>\n<p>Get premium <a class=\"x\" href=\"https://kbeautybliss.com/pt-face-washes/\" target=\"_blank\" rel=\"noopener\"><strong>Face Cleansers</strong></a>"
        ." at unbeatable prices only at K-Beauty Bliss &amp; نظافة</p>\n"
        .'<a href=\'https://www.instagram.com/x/\'>us</a> <img src="https://kbeautybliss.com/wp-content/uploads/a.jpg">';

    $product = Product::query()->where('slug', 'pt-anua-foam')->firstOrFail();
    $product->forceFill(['description' => $before])->save();

    $done = (new OldSiteLinks)->apply();

    expect($done)->toMatchArray(['documents' => 1, 'links' => 1]);

    $after = (string) $product->fresh()->description;

    expect($after)->toBe(str_replace(
        'href="https://kbeautybliss.com/pt-face-washes/"',
        'href="/collections/pt-skin/pt-face-washes/"',
        $before,
    ));
});

it('leaves a link to an uploaded FILE to the picture pass', function () {
    ptLinkShop();

    $html = '<a href="https://kbeautybliss.com/wp-content/uploads/2021/05/guide.pdf">the guide</a>';
    Product::query()->where('slug', 'pt-anua-foam')->update(['description' => $html]);

    expect((new OldSiteLinks)->propose())->toBe([])
        ->and((new OldSiteLinks)->apply()['links'])->toBe(0)
        ->and(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toBe($html);
});

it('reads every kind of imported copy: products, sets, their tabs, articles, blocks, categories and brands', function () {
    ptLinkShop();

    $link = '<a href="https://kbeautybliss.com/pt-face-washes/">cleansers</a>';

    Product::query()->where('slug', 'pt-anua-foam')->update(['description' => $link, 'short_description' => $link]);
    Post::query()->where('slug', 'pt-double-cleansing')->update(['body' => $link]);
    Category::query()->where('slug', 'pt-skin')->update(['description' => $link]);
    Brand::query()->where('slug', 'pt-anua')->update(['description' => $link]);
    $block = Block::query()->create(['slug' => 'pt-block', 'name' => 'Ingredients', 'content' => $link, 'status' => 'published']);
    \App\Models\ProductTab::query()->forceCreate([
        'product_id' => Product::query()->where('slug', 'pt-anua-foam')->value('id'),
        'title' => 'How to use', 'body' => $link, 'position' => 900, 'is_enabled' => true, 'import_key' => 'wc:1',
    ]);

    $tables = collect((new OldSiteLinks)->propose())->map(fn ($p) => $p['table'].'.'.$p['field'])->sort()->values()->all();

    expect($tables)->toBe([
        'blocks.content', 'brands.description', 'categories.description',
        'posts.body', 'product_tabs.body', 'products.description', 'products.short_description',
    ]);

    (new OldSiteLinks)->apply();

    $left = [];

    foreach (OldSiteLinks::DOCUMENTS as [, $table, $field]) {
        foreach (DB::table($table)->pluck($field) as $value) {
            if (is_string($value) && str_contains($value, 'kbeautybliss.com')) {
                $left[] = $table.'.'.$field;
            }
        }
    }

    expect($left)->toBe([]);
    expect($block->fresh()->content)->toBe('<a href="/collections/pt-skin/pt-face-washes/">cleansers</a>');
});

it('keeps a content block reading as un-edited, so the next import still refreshes it', function () {
    ptLinkShop();

    $html = '<p>See <a href="https://kbeautybliss.com/pt-face-washes/">cleansers</a></p>';
    $block = Block::query()->create(['slug' => 'pt-b', 'name' => 'Tip', 'content' => $html, 'status' => 'published']);
    $block->forceFill(['wc_id' => 18190, 'source_hash' => ContentBlockImporter::hash('Tip', 'published', $html)])->save();

    (new OldSiteLinks)->apply();

    $block->refresh();

    // The block's own edit detector: a fingerprint that no longer matches
    // reads as "the owner edited this", and the import would freeze it.
    expect(hash_equals((string) $block->source_hash, ContentBlockImporter::hash('Tip', 'published', (string) $block->content)))->toBeTrue();
});

/* ══════════════════════════════════════════════════════════════ undo ═══ */

it('records what it changed and puts it back -- except a link somebody edited since', function () {
    ptLinkShop();

    $html = '<a href="https://kbeautybliss.com/pt-face-washes/">one</a> <a href="http://kbeautybliss.com/pt-face-washes">two</a> '
        .'<a href="https://kbeautybliss.com/product/pt-anua-foam/">three</a>';
    Product::query()->where('slug', 'pt-anua-foam')->update(['description' => $html]);

    $done = (new OldSiteLinks)->apply();

    expect($done['links'])->toBe(3)
        ->and(OldSiteLinks::applied())->toBe(['documents' => 1, 'links' => 3]);

    $rewritten = (string) Product::query()->where('slug', 'pt-anua-foam')->value('description');

    // Two DIFFERENT old links landed on one address. Hand-edit the product
    // link, then Undo.
    $edited = str_replace('href="/product/pt-anua-foam/"', 'href="/product/pt-anua-foam/?edited=1"', $rewritten);
    Product::query()->where('slug', 'pt-anua-foam')->update(['description' => $edited]);

    $undo = (new OldSiteLinks)->restore();

    expect($undo)->toBe(['documents' => 1, 'links' => 2, 'kept' => 1]);

    // Each of the two comes back as ITSELF, in order; the edited one is kept.
    expect(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toBe(
        '<a href="https://kbeautybliss.com/pt-face-washes/">one</a> <a href="http://kbeautybliss.com/pt-face-washes">two</a> '
        .'<a href="/product/pt-anua-foam/?edited=1">three</a>'
    );

    expect(OldSiteLinks::applied())->toBe(['documents' => 0, 'links' => 0]);
});

/* ═════════════════════════════════════════════════════════════ the import ═══ */

function ptImportFixture(array $overrides = []): App\Services\Import\ImportReport
{
    $dir = base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    return (new ImportRunner)->run(new ImportOptions(...array_merge([
        'directory' => $dir,
        'sourceTimezone' => $manifest['source']['timezone'],
        'adoptBySlug' => true,
    ], $overrides)));
}

it('re-points the links the export carries, by itself, at the end of the import', function () {
    $report = ptImportFixture();

    // Product 4023's copy ends the way the Anua foam's does. The rel and
    // target are what the import's sanitizer gave it when it was still an
    // outside link (RichText::clean) -- kept, as the brief says: only the
    // address changes.
    expect(Product::query()->where('wc_id', 4023)->value('description'))->toBe(
        'Two sizes. Get premium <a href="/collections/skincare/face-cleansers/" rel="noopener noreferrer" target="_blank">Face Cleansers</a> at unbeatable prices only at K-Beauty Bliss'
    );

    // The article's link was http://www. -- both spellings are the old shop.
    expect((string) Post::query()->where('source_post_id', 7001)->value('body'))
        ->toContain('<a href="/product/serum-4021/" target="_blank" rel="noopener noreferrer">the ginseng serum</a>');

    // A category description, linking to a category imported AFTER it.
    expect(Category::query()->where('source_term_id', 22)->value('description'))->toBe(
        '<p>Gentle cleansers for every skin. Follow with a <a href="/collections/toners/" rel="noopener noreferrer" target="_blank">toner</a>.</p>'
    );

    // And the import said so.
    $notes = collect(ImportRunner::entityNames())
        ->flatMap(fn ($e) => array_keys($report->for($e)->notes()))
        ->filter(fn ($n) => str_starts_with((string) $n, 'Links:'))
        ->values()->all();

    expect($notes)->not->toBe([]);

    // THE FINISH LINE, ASKED OF THE DATABASE: no <a href> to a page on the old
    // shop is left in any document this reads. (A picture still on the old
    // host is the picture pass's business and is not counted here.)
    expect((new OldSiteLinks)->propose())->toBe([]);
});

it('is idempotent across a second import of the same export', function () {
    ptImportFixture();

    $second = ptImportFixture(['runKey' => 'second', 'restart' => true]);

    foreach (['categories', 'brands', 'products', 'posts'] as $entity) {
        expect($second->for($entity)->updated)->toBe(0, $entity.' churned on an unchanged export');
    }

    expect(Product::query()->where('wc_id', 4023)->value('description'))
        ->toContain('href="/collections/skincare/face-cleansers/"');
});

/* ═════════════════════════════════════════════════════════════ the screen ═══ */

it('previews, fixes and undoes from Addresses & pictures', function () {
    UrlsMediaAdminRoutes::wire($this->app);
    ptLinkShop();

    Product::query()->where('slug', 'pt-anua-foam')->update([
        'description' => '<p>Get premium <a href="https://kbeautybliss.com/pt-face-washes/">Face Cleansers</a> here</p>',
    ]);

    $owner = ptOwner();

    $preview = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/urls-media/old-links', ['action' => 'preview'])
        ->assertOk()->json();

    expect($preview['summary']['documents'])->toBe(1)
        ->and($preview['summary']['links'])->toBe(1)
        ->and($preview['summary']['samples'][0])->toBe([
            'where' => 'Product description — Anua Heartleaf Foam',
            'text' => 'Face Cleansers',
            'from' => 'https://kbeautybliss.com/pt-face-washes/',
            'to' => '/collections/pt-skin/pt-face-washes/',
        ]);

    // A preview writes nothing.
    expect(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toContain('kbeautybliss.com');

    $fix = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/urls-media/old-links', ['action' => 'apply'])
        ->assertOk()->json();

    expect($fix['links'])->toBe(1)
        ->and($fix['remaining']['links'])->toBe(0)
        ->and(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toContain('href="/collections/pt-skin/pt-face-washes/"');

    $undo = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/urls-media/old-links', ['action' => 'restore'])
        ->assertOk()->json();

    expect($undo['links'])->toBe(1)
        ->and(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toContain('href="https://kbeautybliss.com/pt-face-washes/"');

    // Only the three verbs.
    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/urls-media/old-links', ['action' => 'drop-everything'])
        ->assertStatus(422);
});

it('refuses everyone but the owner, and nobody signed in at all', function () {
    UrlsMediaAdminRoutes::wire($this->app);
    ptLinkShop();

    $html = '<a href="https://kbeautybliss.com/pt-face-washes/">x</a>';
    Product::query()->where('slug', 'pt-anua-foam')->update(['description' => $html]);

    $this->postJson('/admin-api/urls-media/old-links', ['action' => 'apply'])->assertStatus(401);

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(ptOwner($role), 'admin')
            ->postJson('/admin-api/urls-media/old-links', ['action' => 'apply'])
            ->assertStatus(403);
    }

    expect(Product::query()->where('slug', 'pt-anua-foam')->value('description'))->toBe($html);
});

it('has a capability of its own, owner-only, named above the urls-media wildcard', function () {
    expect(AdminCapabilities::forPath('POST', 'admin-api/urls-media/old-links'))->toBe('data.old_links')
        ->and(AdminCapabilities::CAPABILITIES['data.old_links'])->toBe(['owner'])
        // The rest of the screen is untouched.
        ->and(AdminCapabilities::forPath('POST', 'admin-api/urls-media/media'))->toBe('data.import');

    // Wired exactly once, in the file routes/web.php already requires.
    $routes = (string) file_get_contents(base_path('routes/urls-media-admin.php'));
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($routes, "Route::post('/urls-media/old-links'"))->toBe(1)
        ->and(substr_count($web, "require __DIR__.'/urls-media-admin.php';"))->toBe(1);
});

it('draws the row on Addresses & pictures exactly once', function () {
    $admin = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($admin, '+ptOldLinksRow()'))->toBe(1)
        ->and(substr_count($admin, 'function ptOldLinksRow()'))->toBe(1)
        ->and(substr_count($admin, "'/urls-media/old-links'"))->toBe(2);
});
