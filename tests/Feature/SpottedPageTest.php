<?php

declare(strict_types=1);

/**
 * /kbeautybliss-spotted/ and its admin endpoints.                   (Lane HB)
 *
 * THE OWNER (master plan row 55, item 4): "a button to a new page
 * /kbeautybliss-spotted with a manually-selected Instagram grid", and the SEO
 * list for the same row: one H1, a crawlable <a href> on every card, alt and
 * width/height on every picture, BreadcrumbList on /kbeautybliss-spotted.
 * Controls: Appearance → #KBeautyBliss Spotted (spotted.manage).
 *
 * The routes are registered by Tests\Support\SpottedRoutes, the same way
 * UgcAdminRoutes does it, so these are green before AND after the integrator
 * requires routes/spotted.php and routes/spotted-admin.php from routes/web.php.
 *
 * The defects these pin, as each would look on the shop:
 *   · the page served the site's generic title and no canonical — Google sees a
 *     duplicate of the homepage's head;
 *   · no BreadcrumbList, which the row asks for by name;
 *   · an editor the owner gave the screen to, refused with a 403 — or worse, a
 *     support account allowed to change what every shopper sees;
 *   · a javascript: picture or a look-alike Instagram host accepted at the door.
 *
 * MUTATION NOTES, RUN:
 *   · drop 'breadcrumb' from SpottedController's seoCtx → RED (case 1).
 *   · remove the two admin-api/spotted lines from AdminCapabilities::RULES →
 *     RED (case 4): the route falls to owner-only and the editor gets 403.
 *   · remove the host check in SpottedApiController::clean() (store the raw
 *     ig_url) → RED (case 5).
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\SpottedPost;
use App\Services\SpottedSettings;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;
use Tests\Support\SpottedRoutes;

function sppAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => ucfirst($role), 'email' => 'spp-'.$role.'-'.Str::random(6).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function sppPost(array $over = []): SpottedPost
{
    static $n = 0;
    $n++;

    return SpottedPost::create($over + [
        'image' => '/uploads/spotted/g'.$n.'.jpg', 'ig_url' => 'https://www.instagram.com/p/Grid'.$n.'/',
        'handle' => 'lina.skin', 'caption' => 'Anua Toner '.$n, 'sort' => $n, 'on_home' => true, 'on_page' => true,
    ]);
}

beforeEach(function () {
    SpottedRoutes::wire($this->app);
    SpottedSettings::flush();
});

it('serves the page with one H1, its own title, description, canonical and a BreadcrumbList', function () {
    sppPost();

    $html = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1>#KBeautyBliss Spotted</h1>')
        ->and($html)->toContain('<title>#KBeautyBliss Spotted — K-Beauty Routines from Our UAE Community</title>')
        ->and($html)->toContain('<meta name="description" content="Real Korean skincare routines, unboxings and results shared by K-Beauty Bliss customers')
        ->and($html)->toMatch('#<link rel="canonical" href="[^"]*/kbeautybliss-spotted/">#');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $crumbs = collect($m[1])->map(fn ($j) => json_decode($j, true))->flatMap(fn ($n) => isset($n['@type']) ? [$n] : ($n['@graph'] ?? $n))
        ->firstWhere('@type', 'BreadcrumbList');

    expect($crumbs)->not->toBeNull()
        ->and($crumbs['itemListElement'])->toHaveCount(2)
        ->and($crumbs['itemListElement'][1]['name'])->toBe('#KBeautyBliss Spotted')
        ->and($crumbs['itemListElement'][1]['item'] ?? $crumbs['itemListElement'][1]['@id'] ?? '')->toEndWith('/kbeautybliss-spotted/');
});

it('lists only the posts ticked for the page, each a real link with alt text and a size', function () {
    $p = Product::create([
        'slug' => 'spp-serum', 'name' => 'Spotted Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock',
    ]);
    sppPost(['caption' => 'On the grid']);
    sppPost(['caption' => 'Homepage only', 'on_page' => false]);
    sppPost(['caption' => 'Shop it', 'product_id' => $p->id, 'link_to' => 'product', 'image_alt' => 'Spotted Serum on a vanity']);

    $html = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();

    expect(substr_count($html, '<li class="spt-cell">'))->toBe(2)
        ->and($html)->not->toContain('Homepage only')
        ->and($html)->toMatch('#<a class="spt-card" href="https://www\.instagram\.com/p/Grid\d+/" target="_blank" rel="noopener">#')
        ->and($html)->toContain('<a class="spt-card" href="'.$p->url().'">')
        ->and($html)->toContain('alt="Spotted Serum on a vanity" width="400" height="500"')
        ->and($html)->toContain('alt="@lina.skin — On the grid" width="400" height="500"')
        // The first pictures of a grid are not held back behind the lazy loader.
        ->and($html)->not->toMatch('#alt="@lina.skin — On the grid" width="400" height="500" loading="lazy"#');
});

it('says so plainly when there is nothing to show yet, and the owner\'s wording replaces the shipped one', function () {
    $html = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();
    expect($html)->toContain('<p class="spt-empty">New posts are on their way — check back soon.</p>');

    app(SpottedSettings::class)->save(['page_h1' => 'Seen on you', 'seo_title' => 'Seen on you | KBB', 'page_cols_d' => '9']);
    \App\Services\SettingsService::forgetMemo();
    sppPost();

    $html = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();
    expect($html)->toContain('<h1>Seen on you</h1>')
        ->toContain('<title>Seen on you | KBB</title>')
        ->toContain('style="--spt-cols-d:4;--spt-cols-m:2"');
});

it('maps every admin endpoint to its own capability, and that capability fails closed', function () {
    foreach ([['GET', 'admin-api/spotted'], ['POST', 'admin-api/spotted/settings'], ['POST', 'admin-api/spotted/posts'],
        ['POST', 'admin-api/spotted/posts/{id}'], ['DELETE', 'admin-api/spotted/posts/{id}'], ['POST', 'admin-api/spotted/order'],
        ['GET', 'admin-api/spotted/products']] as [$method, $uri]) {
        expect(AdminCapabilities::forPath($method, $uri))->toBe('spotted.manage');
    }

    expect(AdminCapabilities::roleCan('editor', 'spotted.manage'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('support', 'spotted.manage'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('editor', 'spotted.nonsense'))->toBeFalse();

    // Not signed in: refused.
    $this->getJson('/admin-api/spotted')->assertStatus(401);

    // Support: refused. Editor: allowed.
    $this->actingAs(sppAdmin('support'), 'admin')->getJson('/admin-api/spotted')->assertForbidden();
    $this->actingAs(sppAdmin('editor'), 'admin')->getJson('/admin-api/spotted')->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('tabs.0.key', 'home');
});

it('refuses a look-alike Instagram host, a javascript picture and an invented heart count at the door', function () {
    $owner = sppAdmin('owner');
    $ok = ['image' => '/uploads/a.jpg', 'handle' => '@sara.glows', 'ig_url' => 'https://www.instagram.com/p/Ok1/', 'caption' => 'Fine'];

    foreach ([
        ['ig_url' => 'https://instagram.com.evil.test/p/x/'],
        ['ig_url' => 'javascript:alert(1)'],
        ['image' => 'javascript:alert(1)'],
        ['image' => '//evil.test/a.jpg'],
        ['handle' => '<b>me</b>'],
        ['likes' => '1.2k'],
        ['likes' => '-4'],
        ['ig_url' => '', 'product_id' => null],
    ] as $bad) {
        $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/posts', $bad + $ok)->assertStatus(422);
    }

    expect(SpottedPost::count())->toBe(0);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/posts', $ok + ['likes' => ''])
        ->assertOk()->assertJsonPath('post.handle', 'sara.glows')->assertJsonPath('post.likes', null)
        ->assertJsonPath('post.drawable', true);

    $id = SpottedPost::query()->value('id');
    $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/posts/'.$id, $ok + ['likes' => '860', 'on_home' => false])
        ->assertOk()->assertJsonPath('post.likes', 860)->assertJsonPath('post.on_home', false);

    $second = sppPost();
    $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/order', ['ids' => [$second->id, $id]])->assertOk();
    expect(SpottedPost::query()->ordered()->pluck('id')->all())->toBe([$second->id, $id]);

    $this->actingAs($owner, 'admin')->deleteJson('/admin-api/spotted/posts/'.$id)->assertOk();
    expect(SpottedPost::query()->whereKey($id)->exists())->toBeFalse();
});

it('hands the product picker an allowlist and never a model', function () {
    Product::create([
        'slug' => 'spp-cream', 'name' => 'Zzqx Snail Cream', 'status' => 'publish', 'sku' => 'SECRET-SKU',
        'is_visible' => true, 'price' => 100, 'stock_status' => 'instock', 'wc_id' => 777, 'total_sales' => 55,
    ]);

    $body = $this->actingAs(sppAdmin('owner'), 'admin')->getJson('/admin-api/spotted/products?q=zzqx')->assertOk()->json();

    expect($body['products'])->toHaveCount(1)
        ->and(array_keys($body['products'][0]))->toBe(['id', 'name', 'slug', 'image', 'status'])
        ->and(json_encode($body))->not->toContain('SECRET-SKU')->not->toContain('777');
});

it('is never wired twice: each require and the screen include appear at most once', function () {
    // CLAUDE.md: pin the finished state rather than the absence of the wiring.
    // Twice registers a sidebar entry twice and wraps window.go around itself.
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($web, "require __DIR__.'/spotted.php';"))->toBeLessThanOrEqual(1)
        ->and(substr_count($web, "require __DIR__.'/spotted-admin.php';"))->toBeLessThanOrEqual(1)
        ->and(substr_count($app, "@include('admin.partials.spotted-screen')"))->toBeLessThanOrEqual(1);
});
