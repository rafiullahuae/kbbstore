<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\UgcVideo;
use App\Support\AdminCapabilities;
use Tests\Support\UgcAdminRoutes;

/**
 * Content → Shoppable video: the endpoints, the capabilities and the screen.
 *
 * routes/ugc-admin.php is required from routes/web.php by the INTEGRATOR — no
 * lane may edit that file — so the route file is declared and left unwired, and
 * Tests\Support\UgcAdminRoutes mounts it here from the real file with the real
 * middleware stack. That is deliberate rather than convenient: it means this
 * suite exercises the file the integrator will require, including its ordering
 * and its names, and a typo in it fails here rather than after a package is
 * applied.
 */
function ugcAdminUser(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'UGC '.$role,
        'email' => 'ugc-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function ugcProduct(string $name): Product
{
    return Product::create([
        'slug' => 'ugc-'.\Illuminate\Support\Str::slug($name).'-'.uniqid(),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12300,
        'stock_status' => 'instock',
    ]);
}

/** The minimum a save needs, so each case can vary one field. */
function ugcPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Layla tries the snail essence',
        'caption' => '',
        'status' => 'draft',
        'source_platform' => 'instagram',
        'source_url' => 'https://www.instagram.com/p/Cabc123/',
        'creator_handle' => '@layla.skin',
        'creator_url' => 'https://www.instagram.com/layla.skin/',
        'rights_status' => 'pending',
        'rights_evidence' => '',
        'locale' => null,
        'position' => 0,
        'published_at' => null,
    ], $overrides);
}

beforeEach(function () {
    UgcAdminRoutes::wire($this->app);
});

/* ═══════════════════════════════════════════════════ the capabilities ═══ */

it('maps every route in the file, and maps none of them to the owner-only default', function () {
    /*
     * A route the map has never heard of resolves to null, and
     * EnforceAdminCapability turns null into 403 for everyone but the owner.
     * That is the right default and a terrible thing to rely on: "owner-only
     * because somebody decided so" and "owner-only because nobody mapped it"
     * are the same 403 and a very different piece of evidence.
     *
     * AdminCapabilityMapTest asserts this for every route the ROUTER carries,
     * and cannot see this file until the integrator wires it. This is the same
     * assertion, a round early.
     *
     * MUTATION NOTE. Delete the six ugc-videos lines from
     * AdminCapabilities::RULES and every route here reports null. RUN.
     */
    $routes = UgcAdminRoutes::registered();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            expect(AdminCapabilities::forPath($method, $route->uri()))
                ->not->toBeNull($method.' '.$route->uri().' is not in the capability map');
        }
    }
});

it('puts the upload behind ugc.manage and not behind the read capability', function () {
    /*
     * THE ORDERING DEFECT THIS FILE EXISTS FOR. RULES is first-match-wins, and
     * a `['GET', 'admin-api/ugc-videos/**', 'ugc.view']` line placed ABOVE the
     * write lines would not match a POST — but a `['*', ...]` read rule would,
     * and that is the shape of the quiz-leads and coupons/manage mistakes
     * AdminCapabilities names. POST .../media moves up to 64 MB into the web
     * root; it is not a read.
     *
     * MUTATION NOTE. Move the two GET lines above the POST/PUT/DELETE ones in
     * RULES and this is still green (a GET rule cannot match a POST) — but
     * change either GET line's method to '*' and it goes red immediately, which
     * is the mistake that is actually made. RUN: red.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/ugc-videos/7/media'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/ugc-videos/7/derive'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/ugc-videos/7/products'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/ugc-videos/7'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/ugc-videos/7'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/ugc-videos'))->toBe('ugc.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-videos'))->toBe('ugc.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-videos/7'))->toBe('ugc.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/ugc-videos/products'))->toBe('ugc.view');
});

it('does not hand the video library out with any existing capability', function () {
    /*
     * The whole point of per-capability gating: granting somebody the video
     * library must not grant them the media library, the mega menu, the
     * homepage or anything else content.manage reaches — and narrowing
     * content.manage one day must not narrow this with it, silently, from
     * another file.
     */
    expect(AdminCapabilities::CAPABILITIES)->toHaveKey('ugc.view')
        ->and(AdminCapabilities::CAPABILITIES)->toHaveKey('ugc.manage');

    foreach (UgcAdminRoutes::registered() as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            expect(AdminCapabilities::forPath($method, $route->uri()))
                ->toStartWith('ugc.');
        }
    }
});

it('refuses a support account, which holds neither capability', function () {
    /*
     * `support` answers customers: it reads orders, adds notes and moderates
     * reviews. It has no business replacing the file a shop's front page plays.
     *
     * MUTATION NOTE. Add 'support' to either ugc.* row in CAPABILITIES and this
     * is red. RUN.
     */
    $this->actingAs(ugcAdminUser('support'), 'admin');

    $this->getJson('/admin-api/ugc-videos')->assertForbidden();
    $this->postJson('/admin-api/ugc-videos', ugcPayload())->assertForbidden();
});

it('lets an editor run the library', function () {
    // Storefront content, so the same three roles content.manage carries —
    // declared here rather than inherited, which is the difference.
    $this->actingAs(ugcAdminUser('editor'), 'admin');

    $this->getJson('/admin-api/ugc-videos')->assertOk();
});

it('refuses everything to a visitor who is not signed in at all', function () {
    foreach (['/admin-api/ugc-videos', '/admin-api/ugc-videos/products'] as $path) {
        $status = $this->getJson($path)->getStatusCode();

        expect($status)->toBeGreaterThanOrEqual(401)
            ->and($status)->toBeLessThan(500);
    }
});

/* ═══════════════════════════════════════════════════════ the endpoints ══ */

it('creates a clip and reports what this server can do for it', function () {
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->postJson('/admin-api/ugc-videos', ugcPayload())->assertStatus(201);

    $body = $this->getJson('/admin-api/ugc-videos')->assertOk()->json();

    expect($body['videos'])->toHaveCount(1)
        ->and($body['videos'][0]['title'])->toBe('Layla tries the snail essence')
        ->and($body['videos'][0]['media_state'])->toBe('none')
        /*
         * §8 question 4 is unanswered, so the screen asks rather than assumes.
         * This key is what lets it tell the owner whether he uploads one file
         * or three BEFORE he uploads anything.
         */
        ->and($body['transcoder'])->toHaveKey('available')
        ->and($body['limits']['clip_mb'])->toBe(64);
});

it('refuses to publish a clip that is not publishable, and says why', function () {
    /*
     * 422 with the reasons named, not a silent downgrade to draft: an owner who
     * pressed Publish and got a draft would press it again.
     *
     * MUTATION NOTE. Delete the canPublish() branch from
     * UgcVideoController::write() and this is green — a clip with no file, no
     * poster and no permission is published. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $body = $this->postJson('/admin-api/ugc-videos', ugcPayload(['status' => 'publish']))
        ->assertStatus(422)
        ->json();

    expect($body['blockers'])->toBeArray()
        ->and(implode(' ', $body['blockers']))->toContain('permission')
        ->and(UgcVideo::count())->toBe(0);
});

it('stores the default rather than a hand-rolled value for every select', function () {
    /*
     * A select stores one of its own options or the default —
     * SecurityModule::cast()'s rule, so a POST of something else never reaches
     * a match() somewhere later. Rule::in() refuses with the ordinary 422
     * rather than storing quietly, which is the stronger half of the same rule.
     *
     * MUTATION NOTE. Replace any Rule::in() in write() with ['string'] and the
     * matching case goes green with the forged value in the column. RUN: 4
     * turned green, one per field.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    foreach ([
        'status' => 'deleted',
        'rights_status' => 'definitely-granted',
        'source_platform' => 'javascript',
        'locale' => 'xx',
    ] as $field => $forged) {
        $this->postJson('/admin-api/ugc-videos', ugcPayload([$field => $forged]))
            ->assertStatus(422);
    }

    expect(UgcVideo::count())->toBe(0);
});

it('drops a creator link that is not a web address, and keeps the rest of the row', function () {
    /*
     * §7: an admin-supplied URL is a URL that ends up in an attribute. It is
     * stored as null rather than refusing the whole save, because an operator
     * pasting a bad link should not lose the caption they typed beside it.
     *
     * MUTATION NOTE. Assign $validated['creator_url'] straight to the column
     * instead of passing it through UgcPath::link() and this is red — with a
     * javascript: URL in a column that becomes an href. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->postJson('/admin-api/ugc-videos', ugcPayload([
        'creator_url' => 'jav&#x09;ascript:alert(1)',
        'source_url' => '//evil.test/steal',
    ]))->assertStatus(201);

    $row = UgcVideo::first();

    expect($row->creator_url)->toBeNull()
        ->and($row->source_url)->toBeNull()
        ->and($row->creator_handle)->toBe('@layla.skin');
});

it('never lets a request write a media path', function () {
    /*
     * file_path, teaser_path and poster_path are written only by UgcMedia from
     * a file this server has just checked and named. There is no field for them
     * on the save, which is what makes a forged POST unable to point a
     * <video src> anywhere.
     *
     * MUTATION NOTE. Add 'file_path' to write()'s rules and assign it, and this
     * is red with an off-site URL in the column. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->postJson('/admin-api/ugc-videos', ugcPayload([
        'file_path' => 'https://evil.test/x.mp4',
        'poster_path' => '/etc/passwd',
        'teaser_path' => '../../.env',
    ]))->assertStatus(201);

    $row = UgcVideo::first();

    expect($row->file_path)->toBeNull()
        ->and($row->poster_path)->toBeNull()
        ->and($row->teaser_path)->toBeNull();
});

it('records when permission was granted, and forgets it when it is withdrawn', function () {
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->postJson('/admin-api/ugc-videos', ugcPayload(['rights_status' => 'granted']))
        ->assertStatus(201);

    $row = UgcVideo::first();
    expect($row->rights_granted_at)->not->toBeNull();

    $this->putJson('/admin-api/ugc-videos/'.$row->id, ugcPayload(['rights_status' => 'refused']))
        ->assertOk();

    // A granted-at on a clip whose permission was withdrawn is stale evidence,
    // and stale evidence is worse than none.
    expect($row->fresh()->rights_granted_at)->toBeNull();
});

it('tags several products on one clip and keeps the order they were dragged into', function () {
    /*
     * REQUIREMENT ONE, through the real endpoint. The list is sent WHOLE rather
     * than one add at a time, because the order is part of the data.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $video = UgcVideo::create(['slug' => 'tag-'.uniqid(), 'title' => 'Tag']);

    $cream = ugcProduct('Cream');
    $toner = ugcProduct('Toner');
    $essence = ugcProduct('Essence');

    $body = $this->postJson('/admin-api/ugc-videos/'.$video->id.'/products', [
        'products' => [
            ['id' => $essence->id],
            ['id' => $cream->id, 'at_ms' => 3200],
            ['id' => $toner->id],
        ],
    ])->assertOk()->json();

    expect(array_column($body['video']['products'], 'name'))->toBe(['Essence', 'Cream', 'Toner'])
        ->and($body['video']['products'][1]['at_ms'])->toBe(3200)
        ->and($body['video']['products'][0]['at_ms'])->toBeNull();
});

it('takes the last position when the same product is sent twice', function () {
    /*
     * The pivot's unique index would refuse the second row with a constraint
     * violation — a 500 the operator cannot act on — and the thing they
     * actually did was click Add twice on a laggy phone.
     *
     * MUTATION NOTE. Build the sync array with []= instead of keying it by
     * product id and this is a 500 rather than a 200. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $video = UgcVideo::create(['slug' => 'dup-'.uniqid(), 'title' => 'Dup']);
    $p = ugcProduct('Twice');

    $body = $this->postJson('/admin-api/ugc-videos/'.$video->id.'/products', [
        'products' => [['id' => $p->id], ['id' => $p->id]],
    ])->assertOk()->json();

    expect($body['video']['products'])->toHaveCount(1);
});

it('refuses a product id that is not a product', function () {
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $video = UgcVideo::create(['slug' => 'bad-'.uniqid(), 'title' => 'Bad']);

    $this->postJson('/admin-api/ugc-videos/'.$video->id.'/products', [
        'products' => [['id' => 99999999]],
    ])->assertStatus(422);
});

it('answers 404 rather than 500 for an id that is not a number', function () {
    /*
     * The route puts no numeric constraint on {id} and the controller declares
     * strict_types, so an int parameter would turn /ugc-videos/abc into a
     * TypeError and a 500 where a 404 was meant — the fault
     * ReviewController::helpful() names in its own docblock.
     *
     * MUTATION NOTE. Type find()'s parameter as int and this is a 500. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->getJson('/admin-api/ugc-videos/abc')->assertStatus(404);
    $this->getJson('/admin-api/ugc-videos/999999')->assertStatus(404);
});

it('reaches the product search rather than reading it as an id', function () {
    /*
     * ROUTE ORDER. {id} carries no constraint, so /ugc-videos/products WOULD be
     * read as an id if it were registered second — it would answer the 404 that
     * find() returns for the row with id 0, and the picker would silently never
     * find anything.
     *
     * MUTATION NOTE. Move the /ugc-videos/products line below /ugc-videos/{id}
     * in routes/ugc-admin.php and this is a 404. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    // A token no seeded catalogue row can carry: this database is migrated with
    // the shop's own demo content in it, so a real product name would match
    // rows this case did not create and measure the seed rather than the route.
    $token = 'Zqx'.strtoupper(substr(uniqid(), -6));

    ugcProduct('Anua Heartleaf Toner '.$token);
    ugcProduct('Beauty of Joseon Serum');

    $body = $this->getJson('/admin-api/ugc-videos/products?q='.$token)->assertOk()->json();

    expect($body['products'])->toHaveCount(1)
        ->and($body['products'][0]['name'])->toBe('Anua Heartleaf Toner '.$token)
        /*
         * An explicit list of three keys. `products` carries wc_id, sku and
         * total_sales — the three columns ApiSecurityTest exists because of —
         * and a picker needs a name and an id.
         */
        ->and(array_keys($body['products'][0]))->toBe(['id', 'name', 'brand']);
});

it('does not offer a draft product to be tagged', function () {
    /*
     * A draft or scheduled product has no page, so tagging one would put a card
     * in a player that links to a 404. ReviewController makes the same argument
     * about its own product_id rule.
     *
     * MUTATION NOTE. Drop ->visible() from products() and this is red. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $token = 'Zqx'.strtoupper(substr(uniqid(), -6));

    Product::create(['slug' => 'draft-'.uniqid(), 'name' => 'Secret Launch Toner '.$token,
        'status' => 'draft', 'is_visible' => true, 'price' => 1000, 'stock_status' => 'instock']);

    $body = $this->getJson('/admin-api/ugc-videos/products?q='.$token)->assertOk()->json();

    expect($body['products'])->toBe([]);
});

it('treats a wildcard typed into the search as text, not as a pattern', function () {
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    ugcProduct('Anua Heartleaf Toner');

    // A bare % is a LIKE wildcard that would match the whole catalogue. Escaped
    // before it reaches the query, it matches the literal character, which no
    // product name carries.
    $body = $this->getJson('/admin-api/ugc-videos/products?q=%')->assertOk()->json();

    expect($body['products'])->toBe([]);
});

it('deletes a clip and its files together', function () {
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $dir = public_path(\App\Services\UgcMedia::DIR);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $name = 'clip-20270110-000000-deletemetst.mp4';
    file_put_contents($dir.'/'.$name, 'x');

    $video = UgcVideo::create([
        'slug' => 'del-'.uniqid(), 'title' => 'Del',
        'file_path' => '/uploads/ugc/'.$name,
    ]);

    $this->deleteJson('/admin-api/ugc-videos/'.$video->id)->assertOk();

    /*
     * The file goes with the row. An orphan under /uploads/ugc/ is a video
     * still being served from this shop's own domain after the owner deleted
     * it, which is exactly what a creator who withdrew permission asked to
     * stop.
     *
     * MUTATION NOTE. Remove the media->forget() loop from destroy() and this is
     * red. RUN.
     */
    expect(is_file($dir.'/'.$name))->toBeFalse()
        ->and(UgcVideo::count())->toBe(0);
});

it('will not delete a file outside its own directory, whatever the row says', function () {
    /*
     * A column is only ever as trustworthy as everything that has ever written
     * to it, so UgcMedia::forget() re-checks the SHAPE of the path before
     * unlinking rather than trusting the row.
     *
     * MUTATION NOTE. Drop the UgcPath::stored() call from forget() and this is
     * red — with the file gone. RUN.
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $victim = storage_path('app/ugc-must-not-be-deleted.txt');
    file_put_contents($victim, 'keep me');

    /*
     * THREE levels up, not two, and that is the point: public_path() is
     * <base>/public, so ../../ from /uploads/ugc/ lands back inside public/ and
     * deletes nothing whatever the guard does. This traversal genuinely reaches
     * <base>/storage/app, so the file survives only because UgcPath::stored()
     * refused the shape. The first version of this case used two and passed
     * against the mutation, which is what the mutation run is for.
     */
    $video = UgcVideo::create([
        'slug' => 'esc-'.uniqid(), 'title' => 'Escape',
        'file_path' => '/uploads/ugc/../../../storage/app/ugc-must-not-be-deleted.txt',
    ]);

    $this->deleteJson('/admin-api/ugc-videos/'.$video->id)->assertOk();

    expect(is_file($victim))->toBeTrue();

    @unlink($victim);
});

it('stores the Arabic title beside the English one', function () {
    /*
     * §5 — Arabic from the start, through the ordinary translations path every
     * other editor in this console uses rather than a second one.
     *
     * MUTATION NOTE. Remove the saveTranslations() call from write() and this
     * is red: the Arabic box saves and nothing reads it back, which is this
     * repo's signature defect (`single_name` saved and nothing read it).
     */
    $this->actingAs(ugcAdminUser('owner'), 'admin');

    $this->postJson('/admin-api/ugc-videos', ugcPayload([
        'translations' => ['ar' => ['title' => 'ليلى تجرب إسنس الحلزون']],
    ]))->assertStatus(201);

    $row = UgcVideo::first();

    expect($row->t('title', 'ar'))->toBe('ليلى تجرب إسنس الحلزون')
        // ...and the English is untouched, which is what "beside" means.
        ->and($row->title)->toBe('Layla tries the snail essence');
});

/* ══════════════════════════════════════════════════════════ the screen ══ */

it('is included from nowhere, so the integrator has one line to add', function () {
    /*
     * This lane may not edit resources/views/admin/app.blade.php. The screen is
     * therefore a partial that registers its own sidebar entry and wraps
     * window.go, exactly as the nine partials beside it do, so ONE @include is
     * the whole of the change to that file. This asserts the partial exists and
     * that nothing in this lane's diff has quietly wired it up.
     */
    $partial = resource_path('views/admin/partials/ugc-library-screen.blade.php');

    expect(is_file($partial))->toBeTrue();

    $app = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($app)->not->toContain('ugc-library-screen');
});

it('escapes every operator string it prints, and prefixes every class it invents', function () {
    /*
     * app.blade.php binds delegated listeners to `document` itself, each
     * claiming a BARE attribute name, so a click on any element carrying one is
     * handled by that listener whichever screen it belongs to. Every data-
     * attribute here is prefixed data-ugs-, and every class ugs-.
     *
     * And every interpolation of an operator string goes through esc(): a title
     * and a creator handle are settings, and CLAUDE.md rule 5 says anything
     * printed unescaped is a constant, never a setting.
     */
    $source = file_get_contents(resource_path('views/admin/partials/ugc-library-screen.blade.php'));

    // No bare data- attribute that another screen's listener could claim.
    preg_match_all('/data-(?!ugs-)([a-z-]+)=/', $source, $matches);

    $foreign = array_values(array_filter(array_unique($matches[1]), fn ($a) => ! in_array($a, [
        // Two attributes this screen READS off the console's own markup rather
        // than inventing: the sidebar group wrapper, and KBBArabic's
        // placeholder. Neither is written by this file.
        'sec',
        'ph',
    ], true)));

    expect($foreign)->toBe([]);

    /*
     * Every class this file STYLES is ugs-prefixed.
     *
     * Read out of the stylesheet rather than out of the markup, because that is
     * where the invariant actually bites: a rule named `.card` here would
     * restyle every other screen in this console. The markup builds several
     * class attributes by concatenation, so parsing it for class names reads
     * JavaScript fragments as though they were CSS identifiers.
     */
    $style = substr($source, strpos($source, '<style>'), strpos($source, '</style>') - strpos($source, '<style>'));

    preg_match_all('/\.([A-Za-z][A-Za-z0-9_-]*)/', $style, $selectors);

    $leaked = array_values(array_unique(array_filter(
        $selectors[1],
        fn (string $c) => ! str_starts_with($c, 'ugs-')
            // The three state modifiers, which only ever appear compounded onto
            // a ugs- class (`.ugs-pill.is-live`) and never on their own.
            && ! str_starts_with($c, 'is-')
    )));

    expect($leaked)->toBe([], 'these class rules are not ugs-prefixed and would restyle other screens');
});
