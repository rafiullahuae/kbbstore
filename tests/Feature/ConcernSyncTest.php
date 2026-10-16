<?php

declare(strict_types=1);

/**
 * Catalog -> Shop by Concern -> Sync. (Lane SC)
 *
 * What each defect would look like in the shop:
 *  - an editor or support account spending the owner's Claude key, or adding
 *    products to seven storefront collections (capability not enforced);
 *  - Sync listing products already in a collection (the owner re-files them);
 *  - Apply taking a product OUT of a collection, re-ordering a hand-ordered
 *    one, or listing a product twice;
 *  - a made-up slug from Claude ("hair-loss") reaching category_product, or
 *    Claude "answering" for a product id it was never asked about;
 *  - garbage JSON from the API turning Sync into a 500 instead of falling
 *    back to the rules;
 *  - the saved key coming back to the browser.
 *
 * MUTATIONS, RUN: drop the whereNotExists in ConcernSync::unassignedQuery --
 * "lists only concern-less" is red; drop the `isset($allowed[...])` check in
 * ClaudeConcernSuggester::parse -- "only asked ids" is red; remove the two
 * RULES rows in AdminCapabilities -- the manager case is red (an unmapped
 * path is owner-only).
 */

use App\Models\AdminUser;
use App\Services\ConcernSync\ClaudeConcernSuggester;
use App\Services\ConcernSync\ConcernRules;
use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConcernSyncAdminRoutes;

beforeEach(function () {
    ConcernSyncAdminRoutes::wire(app());
    Http::preventStrayRequests();
});

function csyAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'SC '.$role, 'email' => 'sc-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function csyCat(string $slug, string $name): int
{
    return (int) DB::table('categories')->insertGetId(['slug' => $slug, 'name' => $name, 'path' => $slug, 'created_at' => now(), 'updated_at' => now()]);
}

function csyProd(string $slug, array $extra = []): int
{
    return (int) DB::table('products')->insertGetId($extra + ['slug' => $slug, 'name' => $slug, 'type' => 'simple',
        'status' => 'publish', 'price' => 5000, 'stock_status' => 'instock', 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string,int> slug => category id */
function csyConcerns(): array
{
    $out = [];
    foreach (ConcernSync::CONCERNS as $slug => $name) {
        $out[$slug] = csyCat($slug, $name);
    }

    return $out;
}

function csyClaude(array $products): array
{
    return ['id' => 'msg_x', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode(['products' => $products])]]];
}

it('refuses roles without concerns.sync, and an anonymous request', function () {
    csyConcerns();

    $this->getJson('/admin-api/concern-sync')->assertStatus(401);

    foreach (['editor', 'support'] as $role) {
        $this->actingAs(csyAdmin($role), 'admin');
        $this->getJson('/admin-api/concern-sync')->assertForbidden();
        $this->postJson('/admin-api/concern-sync/scan', [])->assertForbidden();
        $this->postJson('/admin-api/concern-sync/apply', ['links' => [['product_id' => 1, 'concerns' => ['pigmentation']]]])->assertForbidden();
        $this->postJson('/admin-api/concern-sync/settings', ['model' => 'claude-sonnet-5-5'])->assertForbidden();
    }

    $this->actingAs(csyAdmin('manager'), 'admin');
    $this->getJson('/admin-api/concern-sync')->assertOk();
});

it('lists only published products in no concern, with rule-based suggestions when no key is saved', function () {
    $c = csyConcerns();
    $in = csyProd('already-filed', ['name' => 'Niacinamide Serum']);
    DB::table('category_product')->insert(['category_id' => $c['pigmentation'], 'product_id' => $in]);
    $retinol = csyProd('new-retinol', ['name' => 'Retinal Serum', 'short_description' => '<p>With <b>retinol</b> and 5% niacinamide.</p>']);
    $shampoo = csyProd('new-shampoo', ['name' => 'Rosemary Peptide Shampoo', 'short_description' => 'peptide for the scalp']);
    csyProd('a-draft', ['status' => 'draft', 'name' => 'Draft retinol']);

    $this->actingAs(csyAdmin(), 'admin');
    $j = $this->postJson('/admin-api/concern-sync/scan', [])->assertOk()->json();

    $ids = array_column($j['products'], 'id');
    expect($ids)->toContain($retinol)->toContain($shampoo)->not->toContain($in)
        ->and($j['engine'])->toBe('rules')
        ->and($j['note'])->toContain('rule-based');

    $byId = array_column($j['products'], null, 'id');
    $slugs = array_column($byId[$retinol]['suggestions'], 'slug');
    expect($slugs)->toContain('anti-aging')->toContain('pigmentation')
        ->and($byId[$retinol]['source'])->toBe('rules')
        // Hair is not a skin concern: no suggestion at all.
        ->and($byId[$shampoo]['suggestions'])->toBe([]);

    Http::assertNothingSent();
});

it('adds only the ticked memberships, never removes or reorders, never duplicates', function () {
    $c = csyConcerns();
    $p = csyProd('p1');
    $q = csyProd('q1');
    $theirs = csyProd('owner-ordered');
    DB::table('category_product')->insert(['category_id' => $c['anti-aging'], 'product_id' => $theirs, 'category_position' => 3]);
    DB::table('category_product')->insert(['category_id' => $c['pigmentation'], 'product_id' => $p, 'category_position' => 1]);

    $this->actingAs(csyAdmin(), 'admin');
    $j = $this->postJson('/admin-api/concern-sync/apply', ['links' => [
        ['product_id' => $p, 'concerns' => ['anti-aging', 'pigmentation']],
        ['product_id' => $q, 'concerns' => ['hydration-glow']],
    ]])->assertOk()->json();

    expect($j['added'])->toBe(2)->and($j['already'])->toBe(1);

    $link = fn ($cat, $prod) => DB::table('category_product')->where(['category_id' => $cat, 'product_id' => $prod]);
    expect($link($c['anti-aging'], $p)->count())->toBe(1)
        ->and($link($c['anti-aging'], $p)->value('category_position'))->toBeNull()
        ->and($link($c['pigmentation'], $p)->value('category_position'))->toBe(1)
        ->and($link($c['anti-aging'], $theirs)->value('category_position'))->toBe(3)
        ->and($link($c['hydration-glow'], $q)->count())->toBe(1)
        ->and($link($c['pore-care'], $q)->count())->toBe(0);

    $total = DB::table('category_product')->count();
    $this->postJson('/admin-api/concern-sync/apply', ['links' => [['product_id' => $p, 'concerns' => ['anti-aging']]]])->assertOk()->assertJson(['added' => 0]);
    expect(DB::table('category_product')->count())->toBe($total);
});

it('refuses a slug that is not one of the shop\'s concerns, and a non-concern category', function () {
    $c = csyConcerns();
    $patches = csyCat('acne-patches', 'Acne Patches');
    $p = csyProd('p1');
    $this->actingAs(csyAdmin(), 'admin');

    foreach (['hair-loss', 'acne-patches', '', 'pigmentation" OR 1=1'] as $bad) {
        $this->postJson('/admin-api/concern-sync/apply', ['links' => [['product_id' => $p, 'concerns' => [$bad]]]])->assertStatus(422);
    }

    expect(DB::table('category_product')->where('product_id', $p)->count())->toBe(0)
        ->and(DB::table('category_product')->where('category_id', $patches)->count())->toBe(0);
});

it('uses Claude when a key is saved, keeps only known slugs and asked ids, and never echoes the key', function () {
    csyConcerns();
    $a = csyProd('a', ['name' => 'Azelaic Serum']);
    $b = csyProd('b', ['name' => 'Plain Cleansing Oil']);
    ConcernSyncCredentials::saveKey('sk-ant-test-123');
    ConcernSyncCredentials::saveModel('claude-haiku-5-5');

    Http::fake(['api.anthropic.com/*' => Http::response(csyClaude([
        ['id' => $a, 'concerns' => [['slug' => 'acne-blemish-care', 'reason' => '<b>10% azelaic</b> acid for blemishes'], ['slug' => 'hair-loss', 'reason' => 'x'], ['slug' => 'acne-blemish-care', 'reason' => 'dup']]],
        ['id' => 999999, 'concerns' => [['slug' => 'pigmentation', 'reason' => 'never asked']]],
        ['id' => $b, 'concerns' => []],
    ]))]);

    $this->actingAs(csyAdmin(), 'admin');
    $show = $this->getJson('/admin-api/concern-sync')->assertOk();
    expect($show->getContent())->not->toContain('sk-ant-test-123')->and($show->json('has_key'))->toBeTrue()->and($show->json('model'))->toBe('claude-haiku-5-5');

    $j = $this->postJson('/admin-api/concern-sync/scan', [])->assertOk()->json();
    expect(json_encode($j))->not->toContain('sk-ant-test-123')->not->toContain('never asked')
        ->and($j['engine'])->toBe('claude');

    $byId = array_column($j['products'], null, 'id');
    expect($byId[$a]['suggestions'])->toBe([['slug' => 'acne-blemish-care', 'reason' => '10% azelaic acid for blemishes']])
        ->and($byId[$a]['source'])->toBe('claude')
        ->and($byId[$b]['suggestions'])->toBe([])
        ->and(array_keys($byId))->not->toContain(999999);

    Http::assertSent(function ($req) {
        $body = $req->data();

        return $req->url() === 'https://api.anthropic.com/v1/messages'
            && $req->header('x-api-key') === ['sk-ant-test-123']
            && $req->header('anthropic-version') === ['2023-06-01']
            && $body['model'] === 'claude-haiku-5-5'
            && $body['output_config']['format']['type'] === 'json_schema'
            && ! array_key_exists('temperature', $body) && ! array_key_exists('thinking', $body)
            && ! str_contains(json_encode($body), '5000'); // no price
    });
});

it('falls back to the rules when Claude answers garbage, an error, or nothing usable', function () {
    csyConcerns();
    $p = csyProd('retinol-cream', ['name' => 'Retinol Cream']);
    ConcernSyncCredentials::saveKey('sk-ant-test-123');
    $this->actingAs(csyAdmin(), 'admin');

    foreach ([
        Http::response(['content' => [['type' => 'text', 'text' => 'Sure! Here are {not json']]]),
        Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
        Http::response(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"products":[']]]),
        Http::response('<html>bad gateway</html>', 502),
    ] as $answer) {
        Http::fake(['api.anthropic.com/*' => $answer]);
        $j = $this->postJson('/admin-api/concern-sync/scan', [])->assertOk()->json();
        $row = collect($j['products'])->firstWhere('id', $p);
        expect($j['note'])->toContain('Claude could not answer')
            ->and($row['source'])->toBe('rules')
            ->and(array_column($row['suggestions'], 'slug'))->toContain('anti-aging');
    }
});

it('saves the key encrypted and can clear it', function () {
    csyConcerns();
    $this->actingAs(csyAdmin(), 'admin');

    $this->postJson('/admin-api/concern-sync/settings', ['model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-abc_DEF-1'])->assertOk()->assertJson(['has_key' => true, 'model' => 'claude-opus-5-5']);
    $raw = DB::table('settings')->where('key', 'concern_sync_api_key')->value('value');
    expect($raw)->not->toContain('sk-ant-abc')->and(ConcernSyncCredentials::read()['key'])->toBe('sk-ant-abc_DEF-1');

    $this->postJson('/admin-api/concern-sync/settings', ['model' => 'gpt-4'])->assertStatus(422);
    $this->postJson('/admin-api/concern-sync/settings', ['model' => 'claude-opus-5-5', 'api_key' => 'x" onmouseover="1'])->assertStatus(422);

    $this->postJson('/admin-api/concern-sync/settings', ['model' => 'claude-sonnet-5-5', 'clear_key' => true])->assertOk()->assertJson(['has_key' => false]);
    expect(ConcernSyncCredentials::read()['key'])->toBeNull();

    // And the general settings screen never carries it.
    ConcernSyncCredentials::saveKey('sk-ant-zzz');
    $this->getJson('/admin-api/settings')->assertOk()->assertJsonMissingPath('settings.concern_sync_api_key');
});

it('costs the same number of queries for 3 products as for 40', function () {
    csyConcerns();
    $this->actingAs(csyAdmin(), 'admin');
    $brand = DB::table('brands')->insertGetId(['name' => 'B', 'slug' => 'b', 'created_at' => now(), 'updated_at' => now()]);
    $other = csyCat('serums-'.uniqid(), 'Serums');

    $make = function (int $n) use ($brand, $other) {
        for ($i = 0; $i < $n; $i++) {
            $id = csyProd('p-'.uniqid(), ['brand_id' => $brand, 'name' => 'Peptide cream '.$i]);
            DB::table('category_product')->insert(['category_id' => $other, 'product_id' => $id]);
        }
    };

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson('/admin-api/concern-sync/scan', ['engine' => 'rules'])->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $make(3);
    $count(); // warm: the first request of a test also reads the admin's role and session once
    $small = $count();
    $make(37);
    $big = $count();

    expect($big)->toBe($small);
});

it('rules: suggests by ingredient, skips non-face products, and never names a concern the shop lacks', function () {
    $concerns = ['pigmentation' => ['id' => 1, 'name' => 'Pigmentation'], 'acne-blemish-care' => ['id' => 2, 'name' => 'Acne']];

    $s = ConcernRules::suggest(['name' => 'Clear Toner', 'short' => 'With 2% salicylic acid and tranexamic acid.', 'ingredients' => '', 'description' => ''], $concerns);
    expect(array_column($s, 'slug'))->toBe(['acne-blemish-care', 'pigmentation'])
        ->and($s[0]['reason'])->toContain('salicylic acid');

    // Retinol is anti-aging, which this shop does not have here: nothing invented.
    expect(ConcernRules::suggest(['name' => 'Night Cream', 'short' => 'retinol', 'ingredients' => '', 'description' => ''], $concerns))->toBe([])
        ->and(ConcernRules::suggest(['name' => 'Niacinamide Body Lotion', 'short' => 'niacinamide', 'ingredients' => '', 'description' => ''], $concerns))->toBe([]);
});

it('parses defensively: every slug a known concern, every id one that was asked', function () {
    $concerns = ['pigmentation' => ['id' => 1, 'name' => 'Pigmentation']];
    $res = new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(csyClaude([
        ['id' => 5, 'concerns' => [['slug' => 'pigmentation', 'reason' => 'arbutin']]],
        ['id' => '6', 'concerns' => [['slug' => 'pigmentation', 'reason' => 'string id']]],
        ['id' => 7, 'concerns' => 'nope'],
        ['id' => 8, 'concerns' => [['slug' => 'pigmentation', 'reason' => 'not asked']]],
    ]))));

    [$out, $why] = ClaudeConcernSuggester::parse($res, [5, 6, 7], $concerns);
    expect($why)->toBeNull()
        ->and($out[5])->toBe([['slug' => 'pigmentation', 'reason' => 'arbutin']])
        ->and($out[6])->toBe([])
        ->and($out[7])->toBe([])
        ->and($out)->not->toHaveKey(8);
});

it('ships the screen as its own partial, wired once, with the late-answer guard and the secret field', function () {
    $partial = file_get_contents(resource_path('views/admin/partials/concern-sync-screen.blade.php'));

    expect($partial)->toContain('function ours()')
        ->toContain('autocomplete="new-password"')
        ->toContain("label: 'Shop by Concern'")   // a literal: AdminNavAndIdsTest reads the sidebar label from source
        ->not->toContain('setInterval')
        ->not->toContain('getBoundingClientRect');

    // The route file names the throttle bucket and every path the capability covers.
    $routes = file_get_contents(base_path('routes/concern-sync-admin.php'));
    expect($routes)->toContain("throttle:10,1,concern-sync");
    expect(\App\Support\AdminCapabilities::forPath('POST', 'admin-api/concern-sync/apply'))->toBe('concerns.sync')
        ->and(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/concern-sync'))->toBe('concerns.sync');

    // Never mounted twice once the integrator adds the require / include.
    expect(substr_count(file_get_contents(base_path('routes/web.php')), "require __DIR__.'/concern-sync-admin.php';"))->toBeLessThanOrEqual(1)
        ->and(substr_count(file_get_contents(resource_path('views/admin/app.blade.php')), "@include('admin.partials.concern-sync-screen')"))->toBeLessThanOrEqual(1);
});
