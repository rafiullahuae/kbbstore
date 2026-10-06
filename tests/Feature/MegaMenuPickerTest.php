<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Services\NavigationService;
use App\Support\AdminCapabilities;
use App\Support\MenuTargets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MegaMenuPickerRoutes;

/**
 * Store → Mega Menu → Add items.                                      Lane MX
 *
 * The owner: "in the mega menu, i need a full list of categories, brands,
 * pages etc etc. same like in wordpress, to directly click and add to specific
 * menu / columns. so i will avoid to paste the manual links mostely" — and
 * "i need a horizontal scroll bar to drag to go horizontally, the mouse
 * gestures something gives back page on mac. so i need a dedicated bar at the
 * bottom".
 *
 * Before this, the only way to put a category in the header was to type its
 * address into the Link box by hand — and a typo, or a category renamed later,
 * was a 404 in the header of every page.
 */
beforeEach(function () {
    MegaMenuPickerRoutes::wire($this->app);
});

function mxAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'MX '.$role, 'email' => 'mx-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** n categories (every third one nested under the one before), n brands, n published posts, one draft post. */
function mxCatalogue(int $n): void
{
    $prev = null;
    for ($i = 1; $i <= $n; $i++) {
        $slug = 'mx-cat-'.$i.'-'.Str::random(4);
        $nested = $i % 3 === 0 && $prev !== null;
        $prev = Category::create([
            'slug' => $slug, 'name' => 'MX Cat '.$i,
            'parent_id' => $nested ? $prev->id : null,
            'path' => $nested ? $prev->path.'/'.$slug : $slug, 'depth' => $nested ? 1 : 0,
        ]);
        Brand::create(['slug' => 'mx-brand-'.$i.'-'.Str::random(4), 'name' => 'MX Brand '.$i]);
        Post::create(['slug' => 'mx-post-'.$i.'-'.Str::random(4), 'title' => 'MX Post '.$i, 'status' => 'published', 'published_at' => now()]);
    }
    Post::create(['slug' => 'mx-draft-'.Str::random(4), 'title' => 'MX Draft post', 'status' => 'draft']);
}

/** A menu: "Skincare" (top) with two columns, "Face" holding one link; and "Sale" (top, no children). */
function mxMenu(): array
{
    $menu = Menu::create(['name' => 'MX menu', 'slug' => 'mx-'.Str::random(6), 'show_desktop' => true]);
    Menu::where('id', '!=', $menu->id)->update(['show_desktop' => false]);
    $skin = MenuItem::create(['menu_id' => $menu->id, 'label' => 'Skincare', 'url' => '/collections/skincare/', 'position' => 0]);
    $face = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $skin->id, 'label' => 'Face', 'position' => 0]);
    $body = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $skin->id, 'label' => 'Body', 'position' => 1]);
    $link = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $face->id, 'label' => 'Toners', 'url' => '/collections/toners/', 'position' => 0]);
    $sale = MenuItem::create(['menu_id' => $menu->id, 'label' => 'Sale', 'url' => '/super-sale/', 'position' => 1]);
    app(NavigationService::class)->flush();

    return compact('menu', 'skin', 'face', 'body', 'link', 'sale');
}

/** The header's tree, flattened to label => url. */
function mxHeader(): array
{
    app(NavigationService::class)->flush();
    $out = [];
    (function ($items) use (&$out, &$walk) {
        $walk = function ($items) use (&$out, &$walk) {
            foreach ($items as $i) {
                $out[$i['label']] = $i['url'];
                $walk($i['children'] ?? []);
            }
        };
        $walk($items);
    })(app(NavigationService::class)->menu('primary'));

    return $out;
}

/* ---------------- the list: one request, everything, nothing private ---------------- */

it('lists every category (with its parent), brand, published post, routed page and collection in one response', function () {
    /*
     * MUTATION NOTE: drop `->where('status', 'published')` from catalogue()'s
     * posts and "MX Draft post" is in the list — red; return the Category model
     * instead of the projection and the key check names `slug` — red.
     */
    mxCatalogue(5);
    Page::query()->updateOrCreate(['slug' => 'about'], ['title' => 'About &amp; us', 'status' => 'published']);
    Page::query()->updateOrCreate(['slug' => 'faqs'], ['title' => 'FAQs', 'status' => 'draft']);
    Page::query()->updateOrCreate(['slug' => 'mx-unrouted'], ['title' => 'Nowhere', 'status' => 'published']);

    $g = $this->actingAs(mxAdmin(), 'admin')->getJson('/admin-api/mega-menu/sources')->assertOk()->json('groups');

    expect(count($g['categories']))->toBe(Category::count())
        ->and(count($g['brands']))->toBe(Brand::count())
        ->and(count($g['posts']))->toBe(Post::where('status', 'published')->count())
        ->and(collect($g['posts'])->pluck('name')->all())->not->toContain('MX Draft post');

    foreach (['categories' => ['id', 'name', 'url', 'parent'], 'brands' => ['id', 'name', 'url'], 'pages' => ['id', 'name', 'url'], 'posts' => ['id', 'name', 'url'], 'collections' => ['id', 'name', 'url']] as $group => $keys) {
        foreach ($g[$group] as $row) {
            expect(array_keys($row))->toBe($keys, "{$group} carries more than the panel needs");
        }
    }

    $nested = Category::whereNotNull('parent_id')->where('name', 'like', 'MX Cat%')->first();
    $row = collect($g['categories'])->firstWhere('id', $nested->id);
    expect($row['parent'])->toBe($nested->parent_id)
        ->and($row['url'])->toBe('/collections/'.$nested->path.'/');

    // A draft page, and a published page the router serves at no address, are not offered.
    $pages = collect($g['pages']);
    expect($pages->firstWhere('url', '/about/')['name'] ?? null)->toBe('About & us')
        ->and($pages->pluck('url')->all())->not->toContain('/faqs/')
        ->and($pages->pluck('name')->all())->not->toContain('Nowhere');

    // Brands A–Z, collections from the shop's own listing routes.
    $names = collect($g['brands'])->pluck('name')->all();
    $sorted = $names;
    usort($sorted, fn ($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)));
    expect($names)->toBe($sorted)
        ->and(collect($g['collections'])->pluck('url')->all())->toContain('/super-sale/', '/new-in/', '/best-sellers/', '/everything-under-54-aed/');
});

it('costs the same number of queries with 3 rows of everything as with 40', function () {
    /*
     * "a page's cost stays FLAT as the catalogue grows" (CLAUDE.md).
     * MUTATION NOTE: resolve each category's address with Category::buildPath()
     * (one query per parent) or each page's with a find() and the 40-row count
     * is higher — red.
     */
    $admin = mxAdmin();
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'admin')->getJson('/admin-api/mega-menu/sources')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    mxCatalogue(3);
    $count();   // warm: settings and the role map are cached after the first request
    $three = $count();
    mxCatalogue(37);
    $forty = $count();

    expect($forty)->toBe($three)->and($three)->toBeLessThan(12);
});

/* ---------------- adding: the right row, in the right column ---------------- */

it('files a picked category into the chosen column with its canonical address and a reference', function () {
    /*
     * MUTATION NOTE: write `'url' => $item['url']` (the browser's word) in
     * pick() and the request below, which sends no url, stores NULL — red; drop
     * target_type/target_id from the row and the reference assertions are red.
     */
    $m = mxMenu();
    $parent = Category::create(['slug' => 'mx-skin', 'name' => 'Skin', 'path' => 'mx-skin']);
    $toner = Category::create(['slug' => 'mx-toners', 'name' => 'Hydrating Toners', 'parent_id' => $parent->id, 'path' => null]);
    $brand = Brand::create(['slug' => 'mx-anua', 'name' => 'Anua']);

    $r = $this->actingAs(mxAdmin(), 'admin')->postJson('/admin-api/mega-menu/pick', [
        'menu_id' => $m['menu']->id,
        'parent_id' => $m['face']->id,
        'items' => [['type' => 'category', 'id' => $toner->id], ['type' => 'brand', 'id' => $brand->id], ['type' => 'collection', 'id' => 'super-sale']],
    ])->assertOk();

    $rows = MenuItem::where('parent_id', $m['face']->id)->orderBy('position')->get();
    expect($rows->pluck('label')->all())->toBe(['Toners', 'Hydrating Toners', 'Anua', 'Super Sale'])
        ->and($rows->pluck('position')->map(fn ($p) => (int) $p)->all())->toBe([0, 1, 2, 3])
        // Path walked through the parent, since the row's own `path` is empty.
        ->and($rows[1]->url)->toBe('/collections/mx-skin/mx-toners/')
        ->and([$rows[1]->target_type, (int) $rows[1]->target_id])->toBe(['category', $toner->id])
        ->and($rows[2]->url)->toBe('/brands/mx-anua/')
        ->and([$rows[2]->target_type, (int) $rows[2]->target_id])->toBe(['brand', $brand->id])
        ->and([$rows[3]->url, $rows[3]->target_type])->toBe(['/super-sale/', null])
        ->and(collect($r->json('items'))->pluck('id')->all())->toBe($rows->slice(1)->pluck('id')->values()->all());

    // And the header draws them, in that column.
    $header = mxHeader();
    expect($header['Hydrating Toners'])->toBe('/collections/mx-skin/mx-toners/')
        ->and($header['Anua'])->toBe('/brands/mx-anua/');
});

it('keeps a picked link pointing at its category after the slug is renamed, and only a picked one', function () {
    /*
     * What a reference is for: the owner renames a category, the header follows.
     * MUTATION NOTE: pass [] for $live in NavigationService::menu() and the
     * header still says /collections/mx-old/ — red. Drop the source_post_id
     * condition from MenuTargets::isPicked() and the IMPORTED row is rewritten
     * — red on the last expectation.
     */
    $m = mxMenu();
    $cat = Category::create(['slug' => 'mx-old', 'name' => 'Old name', 'path' => 'mx-old']);
    $this->actingAs(mxAdmin(), 'admin')->postJson('/admin-api/mega-menu/pick', [
        'menu_id' => $m['menu']->id, 'parent_id' => $m['body']->id, 'items' => [['type' => 'category', 'id' => $cat->id]],
    ])->assertOk();
    MenuItem::create(['menu_id' => $m['menu']->id, 'parent_id' => $m['body']->id, 'label' => 'Imported', 'url' => '/collections/mx-old/',
        'target_type' => 'category', 'target_id' => $cat->id, 'source_post_id' => 990001, 'position' => 9]);

    $cat->update(['slug' => 'mx-new', 'path' => 'mx-new']);

    $header = mxHeader();
    expect($header['Old name'])->toBe('/collections/mx-new/')
        ->and($header['Imported'])->toBe('/collections/mx-old/');
});

it('makes a picked row a plain link once the owner types a different address into it', function () {
    // MUTATION NOTE: delete the $reference clearing in update() and the
    // header shows the category's address, not the one typed — red.
    $m = mxMenu();
    $cat = Category::create(['slug' => 'mx-ref', 'name' => 'Ref', 'path' => 'mx-ref']);
    $admin = mxAdmin();
    $id = $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu/pick', [
        'menu_id' => $m['menu']->id, 'parent_id' => $m['body']->id, 'items' => [['type' => 'category', 'id' => $cat->id]],
    ])->json('items.0.id');

    // Saving the edit form unchanged keeps the reference...
    $this->actingAs($admin, 'admin')->postJson("/admin-api/mega-menu/{$id}", ['label' => 'Ref', 'url' => '/collections/mx-ref/'])->assertOk();
    expect(MenuItem::find($id)->target_type)->toBe('category');

    // ...a typed address replaces it.
    $this->actingAs($admin, 'admin')->postJson("/admin-api/mega-menu/{$id}", ['label' => 'Ref', 'url' => '/collections/mx-ref/?sort=new'])->assertOk();
    $cat->update(['slug' => 'mx-ref2', 'path' => 'mx-ref2']);
    expect(MenuItem::find($id)->target_type)->toBeNull()
        ->and(mxHeader()['Ref'])->toBe('/collections/mx-ref/?sort=new');
});

it('adds new top-level items, and refuses a link as a parent and a column from another menu', function () {
    // MUTATION NOTE: delete the depth check in pick() and the request under a
    // LINK is a 200 that files rows nothing ever draws — red.
    $m = mxMenu();
    $other = mxMenu();
    $admin = mxAdmin();
    $post = fn (?int $parent) => $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu/pick', [
        'menu_id' => $m['menu']->id, 'parent_id' => $parent, 'items' => [['type' => 'collection', 'id' => 'new-in']],
    ]);

    $post($m['link']->id)->assertStatus(422);
    $post($other['face']->id)->assertStatus(422);
    $post(null)->assertOk();

    $top = MenuItem::where('menu_id', $m['menu']->id)->whereNull('parent_id')->orderBy('position')->pluck('label')->all();
    expect($top)->toBe(['Skincare', 'Sale', 'New In']);
});

it('refuses a draft page, a draft post, a deleted id and an unknown listing, and writes nothing', function () {
    // MUTATION NOTE: drop the status filter from MenuTargets::resolve('article')
    // and the draft post is filed — red.
    $m = mxMenu();
    $draftPost = Post::create(['slug' => 'mx-d-'.Str::random(4), 'title' => 'Draft', 'status' => 'draft']);
    $draftPage = Page::query()->updateOrCreate(['slug' => 'delivery'], ['title' => 'Delivery', 'status' => 'draft']);
    $admin = mxAdmin();
    $before = MenuItem::count();

    foreach ([['type' => 'article', 'id' => $draftPost->id], ['type' => 'page', 'id' => $draftPage->id],
        ['type' => 'category', 'id' => 99999999], ['type' => 'collection', 'id' => 'not-a-listing'], ['type' => 'product', 'id' => 1]] as $item) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu/pick', [
            'menu_id' => $m['menu']->id, 'parent_id' => $m['face']->id, 'items' => [['type' => 'collection', 'id' => 'new-in'], $item],
        ])->assertStatus(422);
    }

    expect(MenuItem::count())->toBe($before);
});

/* ---------------- the custom link, checked on the server ---------------- */

it('takes a custom link only as a path on this shop or an https address', function () {
    /*
     * The panel checks first to say so sooner; the server decides.
     * MUTATION NOTE: replace the customUrlAllowed() call in pick() with `true`
     * and `javascript:alert(1)` is stored — red.
     */
    $m = mxMenu();
    $admin = mxAdmin();
    $post = fn (string $url) => $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu/pick', [
        'menu_id' => $m['menu']->id, 'parent_id' => $m['face']->id, 'items' => [['type' => 'custom', 'label' => 'X', 'url' => $url]],
    ]);

    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', 'java&#x09;script:alert(1)', 'data:text/html,hi',
        '//evil.test/x', '/\\evil.test', 'http://plain.test/', 'mailto:a@b.c', 'gift-cards', 'https://'] as $bad) {
        $post($bad)->assertStatus(422);
    }
    expect(MenuItem::where('label', 'X')->count())->toBe(0);

    $post('/gift-cards/')->assertOk();
    $post('https://instagram.com/kbeautybliss')->assertOk();
    expect(MenuItem::where('label', 'X')->pluck('url')->all())->toBe(['/gift-cards/', 'https://instagram.com/kbeautybliss']);
});

it('refuses a javascript: address typed into the ordinary add and edit forms too', function () {
    // Before: saved, then drawn as the homepage by SafeUrl, so the owner was
    // never told. MUTATION NOTE: delete linkAllowed() from store() — red.
    $m = mxMenu();
    $admin = mxAdmin();
    $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu', ['menu_id' => $m['menu']->id, 'label' => 'Bad', 'url' => 'javascript:alert(1)'])->assertStatus(422);
    $this->actingAs($admin, 'admin')->postJson("/admin-api/mega-menu/{$m['link']->id}", ['label' => 'Toners', 'url' => 'javascript:alert(1)'])->assertStatus(422);
    expect($m['link']->fresh()->url)->toBe('/collections/toners/');

    // What was allowed stays allowed.
    foreach (['/collections/x/', 'https://a.test/', 'http://a.test/', 'mailto:a@b.c', 'tel:+971500000000', ''] as $ok) {
        $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu', ['menu_id' => $m['menu']->id, 'label' => 'Ok', 'url' => $ok])->assertOk();
    }
});

/* ---------------- who may ---------------- */

it('sits behind the menu editor\'s own capability and refuses everyone else', function () {
    // MUTATION NOTE: move the routes out of admin-api/mega-menu/** (say to
    // admin-api/menu-sources) and forPath() answers null — red; and a role
    // without content.manage would be let in.
    foreach (['GET' => 'admin-api/mega-menu/sources', 'POST' => 'admin-api/mega-menu/pick'] as $verb => $path) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('content.manage');
    }

    $m = mxMenu();
    $body = ['menu_id' => $m['menu']->id, 'parent_id' => $m['face']->id, 'items' => [['type' => 'collection', 'id' => 'new-in']]];

    $this->getJson('/admin-api/mega-menu/sources')->assertStatus(401);
    expect($this->postJson('/admin-api/mega-menu/pick', $body)->status())->toBeIn([401, 403, 419]);

    $support = mxAdmin('support');
    $this->actingAs($support, 'admin')->getJson('/admin-api/mega-menu/sources')->assertStatus(403);
    $this->actingAs($support, 'admin')->postJson('/admin-api/mega-menu/pick', $body)->assertStatus(403);
    expect(MenuItem::where('parent_id', $m['face']->id)->count())->toBe(1);
});

/* ---------------- the screen: one script, measured nothing, a bar at the bottom ---------------- */

function mxNode(string $body): array
{
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('node is not on this machine');
    }

    $js = "const vm = require('node:vm'), fs = require('node:fs');\n"
        ."const ctx = { self: {} };\n"
        ."vm.runInNewContext(fs.readFileSync(".json_encode(resource_path('js/kbb/admin/menu-picker.js')).", 'utf8'), ctx);\n"
        ."vm.runInNewContext(fs.readFileSync(".json_encode(resource_path('js/kbb/admin/menu-order.js')).", 'utf8'), ctx);\n"
        ."const P = ctx.self.KBBMenuPicker, M = ctx.self.KBBMenuOrder;\n"
        ."(async () => {\n".$body."\n})().then(o => process.stdout.write(JSON.stringify(o)), e => { process.stdout.write('ERR ' + e.stack); });\n";

    $file = tempnam(sys_get_temp_dir(), 'kbbmx').'.cjs';
    file_put_contents($file, $js);
    $raw = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    $out = json_decode($raw, true);
    expect($out)->toBeArray('node could not run the probe: '.$raw);

    return $out;
}

it('lists categories as a tree, searches in the browser, marks what is added, and sends ids rather than addresses', function () {
    /*
     * MUTATION NOTE: return the categories unsorted from categoryRows() and
     * "Toners" is no longer under "Skincare" — red; make pickBody() send the
     * row's url and the body expectation is red; drop esc() from rowHtml() and
     * the <img> survives — red.
     */
    $out = mxNode(<<<'JS'
const cats = [{id: 3, name: 'Toners', url: '/collections/skincare/toners/', parent: 1}, {id: 1, name: 'Skincare', url: '/collections/skincare/', parent: null},
  {id: 2, name: 'Hair', url: '/collections/hair/', parent: null}, {id: 4, name: 'Orphan', url: '/collections/o/', parent: 77}];
const rows = P.categoryRows(cats);
const tree = [{id: 10, label: 'Skincare', url: '/collections/skincare/', children: [{id: 11, label: 'Face', children: [{id: 12, label: 'T', url: '/collections/skincare/toners/', children: []}]}]}, {id: 20, label: 'Sale', url: null, children: []}];
return {
  order: rows.map(r => r.name + ':' + r.depth),
  search: ['toner', 'HAIR', 'nothing-like-this', ''].map(q => rows.filter(r => P.matches(r, q)).map(r => r.name)),
  accents: P.matches({name: 'Crème', url: ''}, 'creme'),
  added: Object.keys(P.addedUrls(tree)).sort(),
  targets: P.targets(tree),
  parents: [P.parentFor('10', '11'), P.parentFor('10', '0'), P.parentFor('top', '11')],
  body: P.pickBody(5, 11, 'categories', ['3', '1']),
  coll: P.pickBody(5, 11, 'collections', ['super-sale']).items,
  custom: ['/gift-cards/', 'https://a.test/x', 'javascript:alert(1)', '//evil.test', 'http://a.test', '/\\evil', 'java&#x09;script:x', ' /x'].map(P.customUrlOk),
  escaped: P.rowHtml('brands', {id: 1, name: '<img src=x onerror=alert(1)>', url: '/x"y/', depth: 0}, {}),
  tick: P.rowHtml('categories', {id: 3, name: 'Toners', url: '/collections/skincare/toners/', depth: 1}, P.addedUrls(tree)),
};
JS);

    expect($out['order'])->toBe(['Skincare:0', 'Toners:1', 'Hair:0', 'Orphan:0'])
        ->and($out['search'])->toBe([['Toners'], ['Hair'], [], ['Skincare', 'Toners', 'Hair', 'Orphan']])
        ->and($out['accents'])->toBeTrue()
        ->and($out['added'])->toBe(['/collections/skincare/', '/collections/skincare/toners/'])
        ->and($out['targets'])->toBe([['id' => 10, 'label' => 'Skincare', 'columns' => [['id' => 11, 'label' => 'Face']]], ['id' => 20, 'label' => 'Sale', 'columns' => []]])
        ->and($out['parents'])->toBe([11, 10, null])
        ->and($out['body'])->toBe(['menu_id' => 5, 'parent_id' => 11, 'items' => [['type' => 'category', 'id' => 3], ['type' => 'category', 'id' => 1]]])
        ->and($out['coll'])->toBe([['type' => 'collection', 'id' => 'super-sale']])
        ->and($out['custom'])->toBe([true, true, false, false, false, false, false, true])
        ->and($out['escaped'])->not->toContain('<img')->toContain('&lt;img')->toContain('/x&quot;y/')
        ->and($out['tick'])->toContain('class="mx-row mx-on"')->toContain('style="--mx-d:1"');
});

it('draws the bottom scrollbar exactly once per board, with ‹ › and a focusable track', function () {
    /*
     * MUTATION NOTE: drop `+ sbarHtml()` from boardHtml() and the count is 0 —
     * red; render it twice and it is 2 — red.
     */
    $out = mxNode("const b = M.boardHtml([{id: 1, label: 'A', children: []}, {id: 2, label: 'B', children: []}]); return {b, empty: M.boardHtml([])};");

    foreach ([$out['b'], $out['empty']] as $html) {
        expect(substr_count($html, 'data-mo-sbar'))->toBe(1)
            ->and(substr_count($html, 'data-mo-strack tabindex="0"'))->toBe(1)
            ->and(substr_count($html, 'data-mo-sstep="-1"'))->toBe(1)
            ->and(substr_count($html, 'data-mo-sstep="1"'))->toBe(1);
    }
    // After the board, so it sits under the columns.
    expect(strpos($out['b'], 'data-mo-sbar'))->toBeGreaterThan(strpos($out['b'], 'data-mo-board'));
});

it('keeps the bar on screen and the Mac back-swipe off, in CSS', function () {
    /*
     * "the mouse gestures something gives back page on mac". A horizontal
     * swipe that reaches an end is Back unless something contains it.
     * MUTATION NOTE: delete the `html:has(.mo-board)…{overscroll-behavior-x:none}`
     * rule or the strack's `overscroll-behavior-x:contain` — red; turn the bar
     * from sticky to static — red.
     */
    $css = (string) file_get_contents(resource_path('views/admin/partials/menu-order.blade.php'));

    expect($css)->toContain('html:has(.mo-board),body:has(.mo-board),.content:has(.mo-board){overscroll-behavior-x:none}')
        ->and($css)->toMatch('/\.mo-board\{[^}]*overscroll-behavior-x:contain/')
        ->and($css)->toMatch('/\.mo-strack\{[^}]*overflow-x:scroll;[^}]*overscroll-behavior-x:contain/')
        ->and($css)->toMatch('/\.mo-sbar\{[^}]*position:sticky;bottom:0;/')
        // The track's width is CSS from the column count, not a measurement.
        ->and($css)->toContain('.mo-sspan{height:1px;width:calc(var(--mo-n,0) * 164px - 8px - 2 * (var(--mo-sbw) + var(--mo-sbg)))}')
        // ...which only holds while a column is 156px and the gap 8px.
        ->and($css)->toContain('.mo-col{flex:0 0 156px;width:156px;')
        ->and($css)->toContain('.mo-cols{display:flex;align-items:flex-start;gap:8px;');
});

it('ships both modules in the one script tag, measures nothing and runs no timer', function () {
    /*
     * CLAUDE.md rule 4: no JavaScript that measures layout. The bar mirrors
     * scrollLeft through scroll events and takes its width from CSS.
     * MUTATION NOTE: add `board.scrollWidth` to either file — red.
     */
    $html = view('admin.partials.menu-order')->render();
    expect(substr_count($html, '<script>'))->toBe(1)
        ->and(substr_count($html, 'root.KBBMenuPicker = factory();'))->toBe(1)
        ->and(substr_count($html, 'root.KBBMenuOrder = factory();'))->toBe(1)
        ->and(strpos($html, 'root.KBBMenuPicker'))->toBeLessThan(strpos($html, 'root.KBBMenuOrder'));

    foreach (['js/kbb/admin/menu-picker.js', 'js/kbb/admin/menu-order.js'] as $f) {
        $src = (string) file_get_contents(resource_path($f));
        foreach (['getBoundingClientRect', 'getClientRects', 'offsetTop', 'offsetLeft', 'offsetWidth', 'offsetHeight', 'offsetParent',
            'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'elementFromPoint', 'elementsFromPoint',
            'ResizeObserver', 'IntersectionObserver', 'setInterval', 'setTimeout', 'requestAnimationFrame', 'innerWidth'] as $api) {
            expect(str_contains($src, $api))->toBeFalse("{$f} uses {$api}");
        }
        expect(stripos($src, '</script'))->toBeFalse();
    }

    // One request for the list, made once: the module caches it for the visit.
    $picker = (string) file_get_contents(resource_path('js/kbb/admin/menu-picker.js'));
    expect(substr_count($picker, "board.api('/sources'"))->toBe(1)
        ->and($picker)->toContain('if (cache || loading) return loading;')
        ->and(substr_count($picker, "board.api('/pick'"))->toBe(1);
});
