<?php

declare(strict_types=1);

/**
 * Content -> HTML Blocks. (Lane BC)
 *
 * Three things are pinned here and the order is the order they matter in.
 *
 * FIRST, THAT PLACEMENT IS REAL. A screen for writing reusable snippets is
 * worth nothing if the snippet never reaches a shopper. The storefront tests
 * below fetch an actual page and an actual post over HTTP and look for the
 * block's markup in the response body -- not for a call to a renderer, for the
 * markup. They also pin the thing that made this necessary: the @shortcodes
 * directive has existed since the Phase 0 baseline and NO VIEW WAS USING IT,
 * so a shortcode written into a page was printed to the shopper verbatim.
 *
 * SECOND, THE GUARD. `content` is HTML rendered unescaped into storefront
 * pages, so an unguarded POST to /admin-api/blocks is stored XSS on every page
 * that places the block. There is no per-route authorisation in
 * Admin\BlocksApiController -- it relies entirely on being mounted inside the
 * admin-api group -- so the tests at the bottom drive every route this lane
 * registers, unauthenticated and as a signed-in customer, and expect to be
 * refused. They read the route list off the router rather than from a list
 * kept by hand, so a route added later without a guard is caught by them
 * rather than by nobody.
 *
 * THIRD, THE CONSOLE WIRING. 'htmlblocks' must be in LIVE_RENDERED, or every
 * visit to the screen fires a HEAD for kbb-admin-blocks.html -- a file this
 * repo has never shipped -- which can only 404 and is painted over a moment
 * later. That is the defect the LANE AV comment block was written about.
 */

use App\Models\AdminUser;
use App\Models\Block;
use App\Models\Customer;
use App\Models\Page;
use App\Models\Post;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HtmlBlocksAdminRoutes;

beforeEach(function () {
    HtmlBlocksAdminRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

function hbOwner()
{
    return test()->actingAs(test()->admin, 'admin');
}

function hbBlock(array $attributes = []): Block
{
    return Block::create($attributes + [
        'slug' => 'free-shipping',
        'name' => 'Free shipping note',
        'content' => '<p class="ship">Free delivery over AED 200.</p>',
        'status' => 'published',
    ]);
}

function hbPage(string $content, string $slug = 'about'): Page
{
    return Page::create([
        'slug' => $slug,
        'title' => 'About us',
        'content' => $content,
        'status' => 'published',
    ]);
}

/* ===========================================================================
 | The table
 |=========================================================================== */

it('creates a blocks table for the model that never had one', function () {
    expect(Schema::hasTable('blocks'))->toBeTrue();

    foreach (['id', 'slug', 'name', 'content', 'status', 'created_at', 'updated_at'] as $column) {
        expect([$column, Schema::hasColumn('blocks', $column)])->toBe([$column, true]);
    }
});

/* ===========================================================================
 | The shortcode — what "place" means
 |=========================================================================== */

it('renders a published block where its shortcode appears', function () {
    hbBlock();

    $out = Shortcodes::render('<div>before</div>[kbb_block slug="free-shipping"]<div>after</div>');

    expect($out)->toContain('Free delivery over AED 200.')
        ->and($out)->toContain('before')
        ->and($out)->toContain('after')
        ->and($out)->not->toContain('[kbb_block');
});

it('renders nothing at all for a draft block', function () {
    hbBlock(['status' => 'draft']);

    $out = Shortcodes::render('A[kbb_block slug="free-shipping"]B');

    // Both halves matter: the content must be gone AND the shortcode must not
    // be left sitting in the page for a shopper to read.
    expect($out)->toBe('AB');
});

it('renders nothing for a slug that does not exist', function () {
    expect(Shortcodes::render('A[kbb_block slug="nope"]B'))->toBe('AB');
});

it('renders nothing for a shortcode with no slug', function () {
    expect(Shortcodes::render('A[kbb_block]B'))->toBe('AB');
});

it('accepts single quotes around the slug, as the attribute parser does', function () {
    hbBlock();

    expect(Shortcodes::render("[kbb_block slug='free-shipping']"))
        ->toContain('Free delivery over AED 200.');
});

it('expands a block nested inside another block', function () {
    hbBlock(['slug' => 'inner', 'name' => 'Inner', 'content' => '<b>INNER</b>']);
    hbBlock(['slug' => 'outer', 'name' => 'Outer', 'content' => 'x[kbb_block slug="inner"]y']);

    expect(Shortcodes::render('[kbb_block slug="outer"]'))->toBe('x<b>INNER</b>y');
});

it('does not recurse forever on a block that places itself', function () {
    hbBlock(['slug' => 'loop', 'name' => 'Loop', 'content' => 'A[kbb_block slug="loop"]B']);

    // The assertion is that this returns at all. Before the $stack guard this
    // recursed until PHP ran out of stack and the whole PAGE 500'd — the block
    // taking the page down with it.
    expect(Shortcodes::render('[kbb_block slug="loop"]'))->toBe('AB');
});

it('does not recurse forever on a two-block cycle', function () {
    hbBlock(['slug' => 'ping', 'name' => 'Ping', 'content' => 'P[kbb_block slug="pong"]']);
    hbBlock(['slug' => 'pong', 'name' => 'Pong', 'content' => 'Q[kbb_block slug="ping"]']);

    expect(Shortcodes::render('[kbb_block slug="ping"]'))->toBe('PQ');
});

it('leaves the stack empty after a render, so the next one is not starved', function () {
    hbBlock(['slug' => 'a1', 'name' => 'A', 'content' => 'A']);

    Shortcodes::render('[kbb_block slug="a1"]');

    // If the guard leaked, a second render would find 'a1' still on the stack
    // and refuse to expand it — the block would work once per process and then
    // silently stop, which under PHP-FPM is a bug that comes and goes.
    expect(Shortcodes::render('[kbb_block slug="a1"]'))->toBe('A');
});

/* ===========================================================================
 | The storefront — the reason any of the above is worth anything
 |=========================================================================== */

it('had no view using the shortcode directive before this lane', function () {
    // The finding this lane's placement rests on, pinned so it cannot quietly
    // come back. Both storefront content views must now run their content
    // through the engine.
    $page = file_get_contents(resource_path('views/store/page.blade.php'));
    $post = file_get_contents(resource_path('views/store/post.blade.php'));

    expect($page)->toContain('@shortcodes($page->content)')
        ->and($page)->not->toContain('{!! $page->content !!}');

    expect($post)->toContain('@shortcodes($post->body');
});

it('serves a placed block to a shopper inside the page HTML', function () {
    hbBlock();
    hbPage('<h2>Hi</h2>[kbb_block slug="free-shipping"]');

    $this->get('/about')
        ->assertOk()
        ->assertSee('Free delivery over AED 200.', false)
        ->assertDontSee('[kbb_block', false);
});

it('serves a placed block to a shopper inside a post', function () {
    hbBlock();

    Post::create([
        'slug' => 'winter-routine',
        'title' => 'Winter routine',
        'body' => '<p>Words.</p>[kbb_block slug="free-shipping"]',
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->get('/winter-routine')
        ->assertOk()
        ->assertSee('Free delivery over AED 200.', false)
        ->assertDontSee('[kbb_block', false);
});

it('shows a shopper nothing where a draft block is placed', function () {
    hbBlock(['status' => 'draft']);
    hbPage('<h2>Hi</h2>[kbb_block slug="free-shipping"]');

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('Free delivery over AED 200.', false)
        ->assertDontSee('[kbb_block', false);
});

/* ===========================================================================
 | The admin API
 |=========================================================================== */

it('lists blocks with the shortcode that places each one', function () {
    hbBlock();

    $body = hbOwner()->getJson('/admin-api/blocks')->assertOk()->json();

    expect($body['blocks'][0]['shortcode'])->toBe('[kbb_block slug="free-shipping"]')
        ->and($body['blocks'][0]['status'])->toBe('published');

    // Content is deliberately not in the list payload.
    expect($body['blocks'][0])->not->toHaveKey('content');
});

it('creates a block and derives the handle from the name', function () {
    hbOwner()->postJson('/admin-api/blocks', [
        'name' => 'Payment Logos Strip',
        'status' => 'published',
        'content' => '<div>visa</div>',
    ])->assertCreated();

    expect(Block::where('slug', 'payment-logos-strip')->exists())->toBeTrue();
});

it('refuses a second block on the same handle instead of throwing', function () {
    hbBlock();

    hbOwner()->postJson('/admin-api/blocks', [
        'name' => 'Another',
        'slug' => 'free-shipping',
        'status' => 'draft',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('refuses a second block whose derived handle collides', function () {
    hbBlock();

    // The BrandsApiController lesson: a blank handle is derived, and the
    // derived value must go through the uniqueness rule too — otherwise this
    // is a QueryException, i.e. a 500 on a duplicate name.
    hbOwner()->postJson('/admin-api/blocks', [
        'name' => 'Free Shipping',
        'status' => 'draft',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('normalises a handle that is not shortcode-safe rather than refusing it', function () {
    /*
     * CHECKED, NOT ASSUMED. This test first asserted a 422 and got a 201, and
     * the code was right: validated() runs the input through Str::slug()
     * BEFORE validating, exactly as BrandsApiController::validated() does, so
     * "Not A Slug!" is stored as a usable handle instead of being bounced.
     *
     * The regex rule behind it is therefore a backstop, not the thing doing
     * the work here -- Str::slug() cannot emit a character it would reject,
     * and an input that slugs to nothing is caught by `required` first. It is
     * kept because it is what the sibling controller does and because it is
     * the rule that would catch a future path that writes `slug` without
     * going through this method.
     *
     * What matters for the owner is the LAST assertion: the response carries
     * the handle that was actually stored, so the screen can show the
     * shortcode that really works rather than the one they typed.
     */
    $body = hbOwner()->postJson('/admin-api/blocks', [
        'name' => 'Bad',
        'slug' => 'Not A Slug!',
        'status' => 'draft',
    ])->assertCreated()->json();

    expect($body['block']['slug'])->toBe('not-a-slug');
});

it('refuses a handle that cannot become one at all', function () {
    hbOwner()->postJson('/admin-api/blocks', [
        'name' => '!!!',
        'slug' => '!!!',
        'status' => 'draft',
    ])->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('refuses a status that is not one the renderer understands', function () {
    hbOwner()->postJson('/admin-api/blocks', [
        'name' => 'Bad status',
        'status' => 'live',
    ])->assertStatus(422)->assertJsonValidationErrors('status');
});

it('updates a block and the storefront shows the new HTML at once', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"]');

    $this->get('/about')->assertSee('Free delivery over AED 200.', false);

    hbOwner()->putJson("/admin-api/blocks/{$block->id}", [
        'name' => 'Free shipping note',
        'slug' => 'free-shipping',
        'status' => 'published',
        'content' => '<p class="ship">Free delivery over AED 100.</p>',
    ])->assertOk();

    // Shortcodes::block() caches for ten minutes. Without the flush() in the
    // controller the owner saves an edit, reloads, and sees the old markup —
    // the most convincing way there is to make a working screen look broken.
    $this->get('/about')
        ->assertSee('Free delivery over AED 100.', false)
        ->assertDontSee('AED 200', false);
});

it('takes a block off the storefront when it is set back to draft', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"]');

    $this->get('/about')->assertSee('Free delivery over AED 200.', false);

    hbOwner()->putJson("/admin-api/blocks/{$block->id}", [
        'name' => 'Free shipping note',
        'slug' => 'free-shipping',
        'status' => 'draft',
        'content' => '<p class="ship">Free delivery over AED 200.</p>',
    ])->assertOk();

    $this->get('/about')->assertDontSee('Free delivery over AED 200.', false);
});

it('reports which pages and posts place a block', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"]', 'about');
    hbPage("intro [kbb_block slug='free-shipping'] outro", 'delivery');
    hbPage('nothing here', 'contact-us');

    $body = hbOwner()->getJson("/admin-api/blocks/{$block->id}")->assertOk()->json();

    expect($body['used_in'])->toHaveCount(2)
        ->and(collect($body['used_in'])->pluck('type')->unique()->all())->toBe(['page']);
});

it('counts a page that places the same block twice only once', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"] and again [kbb_block slug="free-shipping"]');

    expect(hbOwner()->getJson("/admin-api/blocks/{$block->id}")->json('used_in'))->toHaveCount(1);
});

it('still sees every other block a page places after a repeated one', function () {
    // The dedupe used to `return` out of the whole page rather than skip the
    // one repeat, so a second block named after a repeat was never recorded.
    $a = hbBlock(['slug' => 'aa', 'name' => 'AA']);
    $b = hbBlock(['slug' => 'bb', 'name' => 'BB']);

    hbPage('[kbb_block slug="aa"][kbb_block slug="aa"][kbb_block slug="bb"]');

    expect(hbOwner()->getJson("/admin-api/blocks/{$a->id}")->json('used_in'))->toHaveCount(1);
    expect(hbOwner()->getJson("/admin-api/blocks/{$b->id}")->json('used_in'))->toHaveCount(1);
});

it('refuses to delete a block that pages still place', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"]');

    hbOwner()->deleteJson("/admin-api/blocks/{$block->id}")
        ->assertStatus(422)
        ->assertJsonPath('error', 'block_in_use');

    expect(Block::find($block->id))->not->toBeNull();
});

it('deletes a block in use when the operator means it', function () {
    $block = hbBlock();
    hbPage('[kbb_block slug="free-shipping"]');

    hbOwner()->deleteJson("/admin-api/blocks/{$block->id}?force=1")->assertOk();

    expect(Block::find($block->id))->toBeNull();

    // And the page that named it does not break, or print the shortcode.
    $this->get('/about')->assertOk()->assertDontSee('[kbb_block', false);
});

it('deletes an unused block without argument', function () {
    $block = hbBlock();

    hbOwner()->deleteJson("/admin-api/blocks/{$block->id}")->assertOk();

    expect(Block::find($block->id))->toBeNull();
});

/* ===========================================================================
 | The guard
 |=========================================================================== */

it('registers the routes this lane ships', function () {
    expect(HtmlBlocksAdminRoutes::registered())->not->toBeEmpty();
});

it('refuses every block route to a stranger', function () {
    foreach (HtmlBlocksAdminRoutes::paths() as [$method, $path]) {
        $response = test()->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419, 302], "{$method} {$path} was not refused");
    }
});

it('refuses every block route to a signed-in customer', function () {
    $customer = Customer::create(['name' => 'Shopper', 'email' => 'shopper@example.ae']);

    foreach (HtmlBlocksAdminRoutes::paths() as [$method, $path]) {
        $response = test()->actingAs($customer, 'customer')->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419, 302], "{$method} {$path} was not refused for a customer");
    }
});

it('keeps every block route behind the admin-api guard stack', function () {
    expect(HtmlBlocksAdminRoutes::registered())->not->toBeEmpty();

    foreach (HtmlBlocksAdminRoutes::registered() as $route) {
        // toContain() takes the expected values as varargs — a second argument
        // is another needle, not a failure message — so the route is named by
        // asserting on a labelled pair instead.
        $middleware = $route->gatherMiddleware();

        expect([$route->uri(), in_array('auth:admin', $middleware, true)])
            ->toBe([$route->uri(), true]);

        expect([$route->uri(), in_array('web', $middleware, true)])
            ->toBe([$route->uri(), true]);
    }
});

it('publishes nothing about blocks under the unauthenticated /api prefix', function () {
    // CLAUDE.md: everything under /api/* is public. A block's row is not
    // secret, but there is no reason for it to be there and every reason for
    // the write endpoints not to be — so the invariant asserted is that this
    // lane put nothing there at all.
    $apiBlockRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/') && str_contains($r->uri(), 'block'))
        ->map(fn ($r) => $r->uri())
        ->all();

    expect($apiBlockRoutes)->toBe([]);
});

/* ===========================================================================
 | The console
 |=========================================================================== */

function hbConsoleSource(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('stops probing for the standalone file the live screen replaces', function () {
    $src = hbConsoleSource();
    $at = strpos($src, 'const LIVE_RENDERED=');

    expect($at)->not->toBeFalse();

    $set = substr($src, $at, (int) strpos($src, ']', $at) - $at);

    expect($set)->toContain("'htmlblocks'");
});

it('includes the HTML Blocks screen in the console', function () {
    expect(hbConsoleSource())->toContain("@include('admin.partials.html-blocks-screen')");
});

it('renders the HTML Blocks screen in this document rather than an iframe', function () {
    $partial = (string) file_get_contents(resource_path('views/admin/partials/html-blocks-screen.blade.php'));

    // It takes over the route…
    expect($partial)->toContain('window.go = function(id)')
        ->and($partial)->toContain("var SCREEN = 'htmlblocks'");

    // …and it must not add a second sidebar row: 'htmlblocks' is already in
    // NAV, unlike the four screens whose partials do append one.
    expect($partial)->not->toContain('nav-item');
});

it('never labels a row with the data-open attribute the console already claims', function () {
    /*
     * FOUND IN CHROMIUM, not by reading. app.blade.php installs a
     * document-level click handler for the Homepage skin pickers that treats
     * ANY element carrying `data-open` as one of its own: it reads the
     * attribute, looks up [data-pop="<value>"], and calls classList.toggle()
     * on the result with no null check. Table rows using the plain attribute
     * therefore threw "Cannot read properties of null (reading 'classList')"
     * on every click -- a real pageerror at all three widths.
     *
     * This lane sidesteps it with a prefixed attribute rather than editing
     * that handler, which is in another lane's region of that file. The
     * unguarded handler is still there and is reported upward.
     */
    $partial = (string) file_get_contents(resource_path('views/admin/partials/html-blocks-screen.blade.php'));

    // The two code shapes, not the bare token: the token appears in the
    // partial's own comment explaining why it is not used.
    expect($partial)->not->toContain('data-open="')
        ->and($partial)->not->toContain('[data-open]')
        ->and($partial)->toContain('data-hb-open="')
        ->and($partial)->toContain('[data-hb-open]');
});

it('does not let a list refresh redraw an open editor', function () {
    /*
     * ALSO FOUND IN CHROMIUM. Leaving the editor calls render() and then
     * load(); at 390px the list response landed after the owner had opened
     * "New block" and typed a name, and render() rebuilt the editor from an
     * object that still held empty strings. The typed name and the handle
     * derived from it were wiped and the save came back 422 -- while the same
     * sequence at 1920 and 1280 passed, because the response happened to land
     * first. Intermittent data loss in a screen someone types into.
     *
     * Two guards, pinned structurally because they are browser behaviour that
     * Pest has no engine for: load() returns without rendering while an editor
     * is open, and any render that does happen reads the mounted fields back
     * first.
     */
    $partial = (string) file_get_contents(resource_path('views/admin/partials/html-blocks-screen.blade.php'));

    expect($partial)->toContain('if (editing) return;')
        ->and($partial)->toContain('function captureEditor()')
        ->and($partial)->toContain('captureEditor();');
});

it('serves the HTML Blocks screen to a signed-in admin', function () {
    $path = \App\Models\Setting::query()->where('key', 'admin_path')->value('value') ?: 'admin';

    hbOwner()->get('/' . ltrim($path, '/'))
        ->assertOk()
        ->assertSee('Reusable HTML blocks', false)
        ->assertSee("var SCREEN = 'htmlblocks'", false);
});
