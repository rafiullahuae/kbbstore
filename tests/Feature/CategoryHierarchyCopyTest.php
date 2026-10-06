<?php

declare(strict_types=1);

/*
 * Catalog → Categories → "Copy hierarchy from kbeautybliss.com". (Lane CH)
 *
 * The owner: "i got all the categories, but parent and sub categories are
 * seperated. i want the exact hirarchy which i have at kbeautybliss.com" --
 * and, asked about addresses, KEEP SHORT URLS: no category address may change.
 *
 * What the shop looked like before this: every category at the top level, and
 * no way to give them parents short of dragging 150 rows one by one -- and
 * each drag MOVED the address (/collections/toners/ became
 * /collections/skincare/toners/), because `path` was rebuilt from the parents.
 */

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\CategoryHierarchy\HierarchySource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function chhAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CH '.$role,
        'email' => 'ch-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** A flat shop, the way the import left it: four categories, no parents. */
function chhFlatShop(): array
{
    $out = [];
    foreach (['ch-skincare' => 'CH Skincare', 'ch-toners' => 'CH Toners', 'ch-serums' => 'CH Serums', 'ch-only-here' => 'CH Only Here', 'ch-essence-local' => 'CH Essences'] as $slug => $name) {
        $out[$slug] = Category::create(['slug' => $slug, 'name' => $name, 'path' => $slug, 'depth' => 0]);
    }
    // What the backfill migration does to every flat row on the live shop.
    DB::table('categories')->whereIn('slug', array_keys($out))->update(['short_url' => true]);

    return $out;
}

/** kbeautybliss.com's tree, as /wp-json/wp/v2/product_cat answers it. */
function chhSourceList(): array
{
    return [
        ['id' => 10, 'name' => 'CH Skincare', 'slug' => 'ch-skincare', 'parent' => 0],
        ['id' => 11, 'name' => 'CH Toners', 'slug' => 'ch-toners', 'parent' => 10],
        ['id' => 12, 'name' => 'CH Serums', 'slug' => 'ch-serums', 'parent' => 10],
        // Slug differs from the local one; the name is exact and unique.
        ['id' => 13, 'name' => 'CH Essences', 'slug' => 'ch-essences', 'parent' => 12],
        ['id' => 14, 'name' => 'CH Body &amp; Bath', 'slug' => 'ch-body', 'parent' => 0],
    ];
}

function chhFakeSite(?array $list = null): void
{
    Http::fake([
        'https://kbeautybliss.com/wp-json/wp/v2/product_cat*' => Http::response($list ?? chhSourceList(), 200, ['X-WP-TotalPages' => '1']),
        '*' => Http::response('unexpected', 500),
    ]);
}

/** A later Http::fake() adds stubs BEHIND the earlier ones; start clean. */
function chhResetHttp(): void
{
    Http::swap(new \Illuminate\Http\Client\Factory());
}

beforeEach(function () {
    config(['kbb.hierarchy_source_host' => 'kbeautybliss.com']);
});

/* -------------------------------------------------------------- dry run */

it('dry-runs: counts, the resulting tree, and writes nothing', function () {
    chhFlatShop();
    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    $before = DB::table('categories')->orderBy('id')->get()->toJson();

    $d = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertOk()->json();

    // toners + serums under skincare; essences (matched by NAME) under serums.
    expect($d['counts']['get_parent'])->toBe(3)
        ->and($d['counts']['already_right'])->toBe(1)                     // skincare
        ->and($d['counts']['matched_by_name'])->toBe(1)
        ->and($d['counts']['not_here'])->toBe(1)                          // ch-body
        ->and($d['not_here'][0]['name'])->toBe('CH Body & Bath')          // entity decoded
        ->and(collect($d['not_on_source'])->pluck('slug'))->toContain('ch-only-here')
        ->and($d['token'])->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($d)->not->toHaveKey('changes');

    $tree = collect($d['tree'])->keyBy('slug');
    expect($tree['ch-toners']['depth'])->toBe(1)
        ->and($tree['ch-essence-local']['depth'])->toBe(2)
        ->and($tree['ch-toners']['state'])->toBe('moves')
        ->and($tree['ch-only-here']['state'])->toBe('unmatched');

    // Dry run: not one byte of the table moved.
    expect(DB::table('categories')->orderBy('id')->get()->toJson())->toBe($before);
});

/* ---------------------------------------------------------------- apply */

it('applies: parents, depth, the address frozen, caches evicted -- and is idempotent', function () {
    $c = chhFlatShop();
    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    \Illuminate\Support\Facades\Cache::put('kbb.shop.cats', 'stale', 900);

    $urls = collect($c)->map(fn ($cat) => $cat->fresh()->url())->all();
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $r = $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertOk()->json();

    expect($r['moved'])->toBe(3)->and($r['redirects'])->toBe(0);

    $toners = $c['ch-toners']->fresh();
    $ess = $c['ch-essence-local']->fresh();
    expect($toners->parent_id)->toBe($c['ch-skincare']->id)
        ->and($toners->depth)->toBe(1)
        ->and($ess->parent_id)->toBe($c['ch-serums']->id)
        ->and($ess->depth)->toBe(2)
        // `path` is the ADDRESS and it did not move.
        ->and($toners->path)->toBe('ch-toners')
        ->and($ess->path)->toBe('ch-essence-local')
        ->and($c['ch-only-here']->fresh()->parent_id)->toBeNull()
        ->and(\Illuminate\Support\Facades\Cache::get('kbb.shop.cats'))->toBeNull();

    foreach ($c as $slug => $cat) {
        expect($cat->fresh()->url())->toBe($urls[$slug]);
    }

    // Idempotent: the same list again finds everything right and writes nothing.
    $snapshot = DB::table('categories')->orderBy('id')->get()->toJson();
    $token2 = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $again = $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token2])->assertOk()->json();
    expect($again['moved'])->toBe(0)
        ->and($again['counts']['already_right'])->toBe(4)
        ->and(DB::table('categories')->orderBy('id')->get()->toJson())->toBe($snapshot);
});

it('keeps every short address: the old URL answers 200 and the nested form 301s to it', function () {
    // MUTATION: drop the short_url branch in CategoryTree::walk() and the
    // path becomes ch-skincare/ch-toners -- /collections/ch-toners/ then 301s
    // AWAY from itself and the url() assertion goes red.
    $c = chhFlatShop();
    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertOk();

    expect($c['ch-toners']->fresh()->url())->toEndWith('/collections/ch-toners/');

    $this->get('/collections/ch-toners/')->assertOk();

    $nested = $this->get('/collections/ch-skincare/ch-toners/');
    $nested->assertStatus(301);
    expect($nested->headers->get('Location'))->toEndWith('/collections/ch-toners/');

    // The retired WooCommerce form lands in one hop on the short address too.
    $legacy = $this->get('/product-category/ch-skincare/ch-toners/');
    expect($legacy->status())->toBe(301)
        ->and($legacy->headers->get('Location'))->toEndWith('/collections/ch-toners/');
});

it('shows the parents in the category page breadcrumb, and a top-level page is unchanged', function () {
    $c = chhFlatShop();
    $p = Product::create(['slug' => 'ch-prod', 'name' => 'CH Prod', 'price' => 1000, 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple']);
    DB::table('category_product')->insert([
        ['category_id' => $c['ch-toners']->id, 'product_id' => $p->id],
        ['category_id' => $c['ch-skincare']->id, 'product_id' => $p->id],
    ]);

    $flat = $this->get('/collections/ch-toners/')->assertOk()->getContent();
    expect($flat)->toContain('<div class="crumb"><b>Home</b> / Category</div>');

    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertOk();

    $html = $this->get('/collections/ch-toners/')->assertOk()->getContent();
    expect($html)->toMatch('#<div class="crumb"><b>Home</b> / <a href="[^"]*/collections/ch-skincare/">CH Skincare</a> / CH Toners</div>#')
        // BreadcrumbList JSON-LD: Home, Shop, CH Skincare, CH Toners.
        ->and($html)->toMatch('#"position":\s*3,\s*"name":\s*"CH Skincare"#');

    // The parent itself is still top level: its crumb is byte-identical.
    expect($this->get('/collections/ch-skincare/')->getContent())->toContain('<div class="crumb"><b>Home</b> / Category</div>');
});

it('never touches products, slugs, positions or the per-category order (Lane SO)', function () {
    $c = chhFlatShop();
    DB::table('categories')->where('id', $c['ch-toners']->id)->update(['position' => 7]);
    $p = Product::create(['slug' => 'ch-p1', 'name' => 'CH P1', 'price' => 1000, 'status' => 'publish',
        'is_visible' => true, 'stock_status' => 'instock', 'type' => 'simple', 'category_id' => $c['ch-toners']->id]);
    DB::table('category_product')->insert(['category_id' => $c['ch-toners']->id, 'product_id' => $p->id, 'category_position' => 42]);

    $pivot = DB::table('category_product')->orderBy('category_id')->get()->toJson();
    $products = DB::table('products')->orderBy('id')->get()->toJson();
    $slugsAndPositions = DB::table('categories')->orderBy('id')->get(['id', 'slug', 'position', 'name'])->toJson();

    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertOk();

    expect(DB::table('category_product')->orderBy('category_id')->get()->toJson())->toBe($pivot)
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($products)
        ->and(DB::table('categories')->orderBy('id')->get(['id', 'slug', 'position', 'name'])->toJson())->toBe($slugsAndPositions);
});

/* ------------------------------------------------------------ conflicts */

it('refuses a change that would close a loop, and a parent this shop does not have', function () {
    // Here: ch-a sits under ch-b. The source says ch-b goes under ch-a, and
    // does not list ch-a's own parent -- so ch-a stays under ch-b and the move
    // would make a loop. MUTATION: delete the findCycle() pass and the apply
    // writes ch-a <-> ch-b, which every tree walker then has to survive.
    $b = Category::create(['slug' => 'ch-b', 'name' => 'CH B', 'path' => 'ch-b']);
    $a = Category::create(['slug' => 'ch-a', 'name' => 'CH A', 'parent_id' => $b->id, 'path' => 'ch-b/ch-a', 'depth' => 1]);
    $x = Category::create(['slug' => 'ch-x', 'name' => 'CH X', 'path' => 'ch-x']);

    chhFakeSite([
        ['id' => 1, 'name' => 'CH B', 'slug' => 'ch-b', 'parent' => 2],
        ['id' => 3, 'name' => 'CH X', 'slug' => 'ch-x', 'parent' => 99],     // parent not in the list
        ['id' => 5, 'name' => 'Elsewhere', 'slug' => 'ch-elsewhere', 'parent' => 0],
        ['id' => 6, 'name' => 'CH X child', 'slug' => 'ch-x-kid', 'parent' => 5],
        ['id' => 2, 'name' => 'CH A', 'slug' => 'ch-a', 'parent' => 1],
    ]);
    // ch-a's source parent is ch-b: already right. ch-b under ch-a: a loop.
    $this->actingAs(chhAdmin(), 'admin');
    $d = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertOk()->json();

    $texts = collect($d['conflicts'])->pluck('text', 'slug');
    expect($texts['ch-b'])->toContain('loop')
        ->and($texts['ch-x'])->toContain('not in the source')
        ->and($d['counts']['get_parent'])->toBe(0);

    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $d['token']])->assertOk();
    expect($b->fresh()->parent_id)->toBeNull()
        ->and($a->fresh()->parent_id)->toBe($b->id)
        ->and($x->fresh()->parent_id)->toBeNull();
});

it('records a 301 for a category that was already nested (legacy address) and moves', function () {
    $old = Category::create(['slug' => 'ch-old-parent', 'name' => 'CH Old Parent', 'path' => 'ch-old-parent']);
    $new = Category::create(['slug' => 'ch-new-parent', 'name' => 'CH New Parent', 'path' => 'ch-new-parent']);
    $kid = Category::create(['slug' => 'ch-kid', 'name' => 'CH Kid', 'parent_id' => $old->id, 'path' => 'ch-old-parent/ch-kid', 'depth' => 1]);

    chhFakeSite([
        ['id' => 1, 'name' => 'CH New Parent', 'slug' => 'ch-new-parent', 'parent' => 0],
        ['id' => 2, 'name' => 'CH Kid', 'slug' => 'ch-kid', 'parent' => 1],
    ]);
    $this->actingAs(chhAdmin(), 'admin');
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');
    $r = $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertOk()->json();

    expect($kid->fresh()->path)->toBe('ch-new-parent/ch-kid')
        ->and($r['redirects'])->toBe(1);
    $this->get('/collections/ch-old-parent/ch-kid/')->assertStatus(301);
});

/* ---------------------------------------------------------------- fetch */

it('follows WordPress pagination', function () {
    chhFlatShop();
    $list = chhSourceList();
    Http::fake([
        'https://kbeautybliss.com/wp-json/wp/v2/product_cat*' => Http::sequence()
            ->push(array_slice($list, 0, 3), 200, ['X-WP-TotalPages' => '2'])
            ->push(array_slice($list, 3), 200, ['X-WP-TotalPages' => '2']),
    ]);
    $this->actingAs(chhAdmin(), 'admin');

    $d = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertOk()->json();

    expect($d['counts']['total_source'])->toBe(5)->and($d['counts']['get_parent'])->toBe(3);
    Http::assertSentCount(2);
    Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://kbeautybliss.com/wp-json/wp/v2/product_cat')
        && str_contains($req->url(), 'per_page=100') && str_contains($req->url(), '_fields=id%2Cname%2Cslug%2Cparent'));
});

it('says so plainly when the site times out, and offers the file instead', function () {
    chhFlatShop();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
    $this->actingAs(chhAdmin(), 'admin');

    $r = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertStatus(422)->json();
    expect($r['error'])->toContain('could not reach kbeautybliss.com')
        ->and($r['error'])->not->toContain('cURL');
});

it('refuses an answer that is not a JSON category list, then tries the Store API', function () {
    chhFlatShop();
    Http::fake([
        'https://kbeautybliss.com/wp-json/wp/v2/*' => Http::response('<html>Cloudflare challenge</html>', 200),
        'https://kbeautybliss.com/wp-json/wc/store/v1/products/categories*' => Http::response('<html>no</html>', 200),
    ]);
    $this->actingAs(chhAdmin(), 'admin');
    $r = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertStatus(422)->json();
    expect($r['error'])->toContain('not JSON');

    chhResetHttp();
    Http::fake([
        'https://kbeautybliss.com/wp-json/wp/v2/*' => Http::response(['code' => 'rest_no_route'], 404),
        'https://kbeautybliss.com/wp-json/wc/store/v1/products/categories*' => Http::response(chhSourceList(), 200),
    ]);
    $d = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertOk()->json();
    expect($d['counts']['get_parent'])->toBe(3)->and($d['source'])->toContain('/wc/store/v1/');
});

it('fetches only from the configured host, over https, and follows a redirect only to its www twin', function () {
    expect(HierarchySource::host())->toBe('kbeautybliss.com');
    config(['kbb.hierarchy_source_host' => 'http://169.254.169.254/latest']);
    expect(HierarchySource::host())->toBe('kbeautybliss.com');
    config(['kbb.hierarchy_source_host' => 'kbeautybliss.com']);

    chhFlatShop();
    $this->actingAs(chhAdmin(), 'admin');

    Http::fake([
        'https://kbeautybliss.com/*' => Http::response('', 301, ['Location' => 'https://evil.example/wp-json/wp/v2/product_cat']),
        '*' => Http::response(chhSourceList(), 200),
    ]);
    $r = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertStatus(422)->json();
    expect($r['error'])->toContain('will not follow');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example'));

    chhResetHttp();
    Http::fake([
        'https://kbeautybliss.com/*' => Http::response('', 301, ['Location' => 'https://www.kbeautybliss.com/wp-json/wp/v2/product_cat']),
        'https://www.kbeautybliss.com/*' => Http::response(chhSourceList(), 200),
    ]);
    $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertOk();

    // The request body cannot name a URL: there is no such parameter.
    chhResetHttp();
    Http::fake(['*' => Http::response(chhSourceList(), 200)]);
    $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site', 'url' => 'https://evil.example/'])->assertOk();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'evil.example'));
});

/* --------------------------------------------------------------- upload */

it('takes the JSON saved from the browser, several pages at once', function () {
    $c = chhFlatShop();
    Http::fake();
    $this->actingAs(chhAdmin(), 'admin');
    $list = chhSourceList();

    $d = $this->post('/admin-api/categories/hierarchy/preview', ['file' => [
        UploadedFile::fake()->createWithContent('page1.json', json_encode(array_slice($list, 0, 2))),
        UploadedFile::fake()->createWithContent('page2.json', json_encode(array_slice($list, 2))),
    ]], ['Accept' => 'application/json'])->assertOk()->json();

    expect($d['counts']['get_parent'])->toBe(3)->and($d['source'])->toContain('page1.json');
    Http::assertNothingSent();

    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $d['token']])->assertOk();
    expect($c['ch-toners']->fresh()->parent_id)->toBe($c['ch-skincare']->id);
});

it('takes a categories CSV whose parent is a term id or a slug', function () {
    $c = chhFlatShop();
    $this->actingAs(chhAdmin(), 'admin');

    $csv = "term_id,name,slug,parent,description\n10,CH Skincare,ch-skincare,0,\"two\nlines\"\n11,CH Toners,ch-toners,10,\n12,CH Serums,ch-serums,ch-skincare,\n";
    $d = $this->post('/admin-api/categories/hierarchy/preview', [
        'file' => UploadedFile::fake()->createWithContent('cats.csv', $csv),
    ], ['Accept' => 'application/json'])->assertOk()->json();

    expect($d['counts']['get_parent'])->toBe(2);
    $tree = collect($d['tree'])->keyBy('slug');
    expect($tree['ch-serums']['depth'])->toBe(1);
});

it('refuses a file of the wrong type, an oversized one, and junk', function () {
    chhFlatShop();
    $this->actingAs(chhAdmin(), 'admin');

    $this->post('/admin-api/categories/hierarchy/preview', ['file' => UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('ok', false);
    $this->post('/admin-api/categories/hierarchy/preview', ['file' => UploadedFile::fake()->create('big.json', 3000)], ['Accept' => 'application/json'])
        ->assertStatus(422);
    $this->post('/admin-api/categories/hierarchy/preview', ['file' => UploadedFile::fake()->createWithContent('j.json', '{"not":"a list"}')], ['Accept' => 'application/json'])
        ->assertStatus(422);
    // Hostile values are re-typed, not trusted: a string id, an array name.
    $d = $this->post('/admin-api/categories/hierarchy/preview', ['file' => UploadedFile::fake()->createWithContent('j.json', json_encode([
        ['id' => 'DROP TABLE', 'slug' => 'ch-toners', 'name' => 'x', 'parent' => 1],
        ['id' => 7, 'slug' => ['x'], 'name' => 'y', 'parent' => 0],
        ['id' => 8, 'slug' => 'ch-toners', 'name' => ['z'], 'parent' => '-3'],
    ]))], ['Accept' => 'application/json'])->assertOk()->json();
    expect($d['counts']['total_source'])->toBe(1)->and($d['counts']['already_right'])->toBe(1);
});

/* ------------------------------------------------------------- security */

it('refuses an account without catalog.manage, and an anonymous caller, before fetching anything', function () {
    chhFlatShop();
    Http::fake();

    $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertStatus(401);

    $this->actingAs(chhAdmin('support'), 'admin');
    $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->assertStatus(403);
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => str_repeat('a', 40)])->assertStatus(403);

    Http::assertNothingSent();
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/categories/hierarchy/preview'))->toBe('catalog.manage')
        ->and(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/categories/hierarchy/apply'))->toBe('catalog.manage');
});

it('applies only a dry run the same admin made', function () {
    $c = chhFlatShop();
    chhFakeSite();
    $this->actingAs(chhAdmin(), 'admin');
    $token = $this->postJson('/admin-api/categories/hierarchy/preview', ['source' => 'site'])->json('token');

    $this->actingAs(chhAdmin('manager'), 'admin');
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => $token])->assertStatus(422);
    $this->postJson('/admin-api/categories/hierarchy/apply', ['token' => 'nope'])->assertStatus(422);
    expect($c['ch-toners']->fresh()->parent_id)->toBeNull();
});

/* ----------------------------------------------- the rule, everywhere */

it('gives a new category made on the Categories screen a short address under any parent', function () {
    $c = chhFlatShop();
    $this->actingAs(chhAdmin(), 'admin');

    $made = $this->postJson('/admin-api/categories', ['name' => 'CH Mists', 'slug' => 'ch-mists', 'parent_id' => $c['ch-toners']->id])
        ->assertStatus(201)->json('category');

    expect($made['path'])->toBe('ch-mists')->and($made['depth'])->toBe(1);
    $this->get('/collections/ch-mists/')->assertOk();
});

it('keeps a legacy nested tree composing exactly as before', function () {
    // short_url = 0 everywhere: the old rule, which 21 test files and every
    // shop that already nests rely on. A short ancestor ends the prefix.
    $root = Category::create(['slug' => 'ch-r', 'name' => 'R']);
    $mid = Category::create(['slug' => 'ch-m', 'name' => 'M', 'parent_id' => $root->id]);
    $leaf = Category::create(['slug' => 'ch-l', 'name' => 'L', 'parent_id' => $mid->id]);
    \App\Support\CategoryTree::resync();
    expect($leaf->fresh()->path)->toBe('ch-r/ch-m/ch-l')->and($leaf->fresh()->depth)->toBe(2);

    DB::table('categories')->where('id', $mid->id)->update(['short_url' => true]);
    \App\Support\CategoryTree::resync();
    expect($mid->fresh()->path)->toBe('ch-m')
        ->and($leaf->fresh()->path)->toBe('ch-m/ch-l')
        ->and($leaf->fresh()->depth)->toBe(2)
        ->and($leaf->fresh()->buildPath())->toBe('ch-m/ch-l');
});
