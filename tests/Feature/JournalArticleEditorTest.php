<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PostEditorApiController;
use App\Http\Controllers\Store\PageController;
use App\Models\AdminUser;
use App\Models\Post;
use App\Support\AdminCapabilities;
use Tests\Support\ArabicShop;
use Tests\Support\PostEditorRoutes;

/**
 * Content → Blog Posts → New article — the screen that lets an owner publish.
 *
 * ── THE DEFECT, ON THE SHOP ─────────────────────────────────────────────────
 *
 * The Journal's whole permalink structure was finished and served nothing an
 * owner could put there. `posts` could be filled by the WordPress import
 * (Store → Store Import / Export) and by Store → Demo Content → Demo Blog
 * Posts, and by NOTHING ELSE: Content → Blog Posts was a read-only table that
 * said so in its own subtitle, `POST /admin-api/posts` answered 405, and
 * docs/f2-operator-authored-html.md §1 records the same fact from the other
 * end — "There is no POST, PUT or PATCH anywhere in this application that
 * writes pages.content or posts.body."
 *
 * So an owner who had WRITTEN an article — the thing four rounds of SEO work
 * concluded the ranking now moves on — had nowhere to put it.
 *
 * ── AND THE ONE THAT WOULD HAVE COST AN INDEXED URL ─────────────────────────
 *
 * Articles live at the SITE ROOT, `/{slug}/`, an address shared with the whole
 * storefront, and `PageController::slugPattern()` refuses a reserved first
 * segment. An editor that accepted `about`, `wishlist`, `feed` or `concern`
 * would write a row that LOOKS published — it appears in this very list, with a
 * green Published pill — and that no request can ever reach, because the
 * storefront answers that address. Nobody would find out until Search Console
 * did, months later.
 *
 * Every test below names what it would look like on the shop, and the mutation
 * that makes it red is written beside it.
 */

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    PostEditorRoutes::wire($this->app);
});

function jaOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Journal '.$role,
        'email' => 'ja-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** @return array<string, mixed> */
function jaArticle(array $overrides = []): array
{
    return array_merge([
        'title' => 'Heartleaf, and what it actually does',
        'excerpt' => 'A calming ingredient, read honestly.',
        'body' => '<p>Heartleaf is the calming one.</p>',
        'cover' => '',
        'tag' => 'Ingredients',
        'author' => 'K-Beauty Bliss',
        'status' => 'published',
        'published_at' => null,
        'seo' => [],
        'translations' => [],
    ], $overrides);
}

/* ------------------------------------- 1. the gap: an article can be written */

it('writes an article the storefront then serves at its own address', function () {
    /*
     * THE DEFECT: there was no endpoint at all. POST /admin-api/posts answered
     * 405 and nothing else wrote posts.body, so the only articles this shop
     * could ever hold were the ones a WordPress export or the demo seeder
     * brought in.
     *
     * MUTATION: delete the `store` route from routes/post-editor-admin.php and
     * this is a 405 again, which is exactly the state it was found in.
     */
    $this->actingAs(jaOwner(), 'admin');

    $response = $this->postJson('/admin-api/post-editor-create', jaArticle());

    $response->assertStatus(201)
        ->assertJsonPath('post.slug', 'heartleaf-and-what-it-actually-does')
        ->assertJsonPath('post.status', 'published');

    $post = Post::query()->firstOrFail();

    expect($post->title)->toBe('Heartleaf, and what it actually does')
        // A published article with no date sorts last on an index ordered by
        // published_at and prints no date at all.
        ->and($post->published_at)->not->toBeNull();

    // And the shop serves it, which is the only claim that matters.
    $this->get('/'.$post->slug.'/')
        ->assertOk()
        ->assertSee('Heartleaf is the calming one.', false);
});

it('keeps a draft off the shop', function () {
    /*
     * THE DEFECT THIS GUARDS: "Save" that publishes is a screen with no way to
     * work on something. A draft has to 404 at its own address, or "draft" is
     * a label rather than a state.
     *
     * MUTATION: drop `->where('status', 'published')` from
     * Store\PageController::post() and this 200s.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['status' => 'draft']))
        ->assertStatus(201);

    $this->get('/'.Post::query()->value('slug').'/')->assertNotFound();
});

it('publishes under the shop\'s own name when the Author box is cleared', function () {
    /*
     * A REAL DEFECT, FOUND IN A BROWSER AND NOT BY THIS SUITE. `posts.author`
     * is NOT NULL with a column default, so writing null into it is a
     * SQLSTATE[23000] — and what the owner saw was that raw string printed into
     * the message strip of the editor, after writing the whole article, with
     * nothing saved. Every test above had supplied an author, so the suite was
     * green over it; only clearing the box in Chromium produced it.
     *
     * MUTATION: put `$post->author = $author === '' ? null : $author;` back and
     * this is a 500.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['author' => '']))
        ->assertStatus(201);

    expect(Post::query()->value('author'))->toBe(PostEditorApiController::DEFAULT_AUTHOR);
});

it('means by the shop\'s own name exactly what the column means', function () {
    /*
     * Two copies of one string — the constant above and the column default in
     * 0001_01_01_000000_create_kbb_schema — and the drift would be silent: an
     * article written through this editor would carry one byline and every
     * article the WordPress import wrote would carry another, on the same
     * Journal.
     *
     * MUTATION: change either string and this is red.
     */
    $schema = (string) file_get_contents(
        base_path('database/migrations/0001_01_01_000000_create_kbb_schema.php')
    );

    expect($schema)->toContain(
        "\$t->string('author')->default('".PostEditorApiController::DEFAULT_AUTHOR."')"
    );
});

/* ------------------------------------------ 2. the reserved-slug refusal ▲ */

it('refuses an article at an address the storefront already owns', function (string $slug) {
    /*
     * ▲ THE ONE THAT SILENTLY COSTS AN INDEXED URL.
     *
     * ON THE SHOP: the row is written, Content → Blog Posts shows it with a
     * green Published pill, and /about/ goes on serving the storefront's own
     * About page — so the article is a page that exists in the database, is
     * listed in the admin, and can never be opened by a reader or a crawler.
     * Nothing anywhere says why.
     *
     * The check is the ROUTER'S OWN PATTERN via PostImporter::address(), not a
     * second copy of RESERVED_SLUGS, so a lane that reserves a new segment
     * tomorrow moves this refusal with it.
     *
     * MUTATION: remove the `if ($verdict['reserved'])` branch from
     * PostEditorApiController::address() and this goes red on every one of the
     * four slugs — 201 instead of 422, and a row in `posts`.
     */
    $this->actingAs(jaOwner(), 'admin');

    $response = $this->postJson('/admin-api/post-editor-create', jaArticle(['slug' => $slug]));

    $response->assertStatus(422)->assertJsonPath('reason', 'reserved');

    expect($response->json('message'))->toContain('/'.$slug.'/')
        // Nothing was written. A refusal that leaves a row behind is not a
        // refusal, it is the defect with a warning printed over it.
        ->and(Post::query()->count())->toBe(0);

    // And the address really is the storefront's: the pattern says so.
    expect(preg_match('/^(?:'.PageController::slugPattern().')$/', $slug))->toBe(0);
})->with(['about', 'wishlist', 'feed', 'concern']);

it('answers the same refusal while he is still typing', function () {
    /*
     * THE DEFECT: told only at Save, the owner has already written the article.
     * Told at Save on a shop with no client-side check at all, he is told after
     * publishing — which is the case this whole test file exists for.
     *
     * MUTATION: make /post-editor-slug return ['ok' => true] unconditionally
     * and this is a 200.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-slug', ['title' => 'Wishlist'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'reserved')
        ->assertJsonPath('slug', 'wishlist');

    // The positive control, so the refusal above is not a function that always
    // refuses: a free address answers with the address it would publish at.
    $this->postJson('/admin-api/post-editor-slug', ['title' => 'Snail mucin, explained'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('slug', 'snail-mucin-explained');
});

it('refuses a second article at an address one already has, and suggests a free one', function () {
    /*
     * THE DEFECT: `posts.slug` is UNIQUE, so without this the save is a 500
     * (SQLSTATE 23000) on a screen the owner has just spent an hour in.
     *
     * MUTATION: delete the `$taken` branch from address() and this is a 500
     * rather than a 422.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['slug' => 'snail-mucin']))
        ->assertStatus(201);

    $this->postJson('/admin-api/post-editor-create', jaArticle(['slug' => 'snail-mucin']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'taken')
        ->assertJsonPath('suggestion', 'snail-mucin-2');

    expect(Post::query()->count())->toBe(1);
});

it('normalises a slug the route could never match rather than losing the article', function () {
    /*
     * THE DEFECT IT AVOIDS: `SPF_50_Every_Day` is not reserved — nothing else
     * owns it — but slugPattern()'s character class cannot match it either, so
     * an article stored under it is as unreachable as a reserved one. Refusing
     * it would lose the article over a shape the owner did not choose.
     *
     * MUTATION: have address() return the typed slug untouched and the created
     * article 404s at its own address on the line below.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['slug' => 'SPF_50_Every_Day']))
        ->assertStatus(201)
        ->assertJsonPath('post.slug', 'spf-50-every-day');

    $this->get('/spf-50-every-day/')->assertOk();
});

/* --------------------------------------------- 3. the address is a contract */

it('will not move an article that has already been published somewhere', function () {
    /*
     * THE DEFECT: /{slug}/ is a link Google holds and a line in a reader's
     * bookmarks. A slug field on the save form is a one-keystroke way to drop
     * an article's ranking and leave a 404 behind, with no 301 written.
     *
     * MUTATION: change the `prohibited` rule in validated() back to `nullable`
     * and this is a 200.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle())->assertStatus(201);

    $post = Post::query()->firstOrFail();

    $this->postJson('/admin-api/post-editor-save/'.$post->id, jaArticle(['slug' => 'something-else']))
        ->assertStatus(422);

    expect($post->fresh()->slug)->toBe('heartleaf-and-what-it-actually-does');
});

/* ------------------------------------------------ 4. escaping, in BOTH halves */

it('sanitises the article body, and the Arabic body with the same call', function () {
    /*
     * THE DEFECT: store/post.blade.php prints `posts.body` with {!! !!}.
     * docs/f2-operator-authored-html.md §5 DROVE this against a real browser —
     * a payload written into that column fired two dialogs on /zz-xss-post/ —
     * and its §6 Option A says that when an editor for posts is built it must
     * inherit RichText::clean() "instead of reopening the hole".
     *
     * BOTH LANGUAGES OFF ONE CONSTANT. A sanitiser applied to the English only
     * is a stored-XSS hole opened by adding the second language: the same §5
     * shows /about/ clean and /ar/about/ firing two dialogs.
     *
     * MUTATION: drop `self::RICH_FIELDS` from the TranslationInput::clean()
     * call in store() and the Arabic half of this goes red while the English
     * half stays green — which is exactly the asymmetry the document warns
     * about, made visible.
     */
    ArabicShop::on();
    $this->actingAs(jaOwner(), 'admin');

    $payload = jaArticle([
        'body' => '<p>Clean words.</p><script>alert(1)</script><img src=x onerror=alert(2)>',
        'translations' => ['ar' => [
            'title' => 'ورقة القلب',
            'body' => '<p>كلمات نظيفة.</p><script>alert(3)</script><img src=x onerror=alert(4)>',
        ]],
    ]);

    $this->postJson('/admin-api/post-editor-create', $payload)->assertStatus(201);

    $post = Post::query()->firstOrFail();

    expect($post->body)->toContain('Clean words.')
        ->and($post->body)->not->toContain('<script')
        ->and($post->body)->not->toContain('onerror');

    $arabic = $post->t('body', 'ar');

    expect($arabic)->toContain('كلمات نظيفة.')
        ->and($arabic)->not->toContain('<script')
        ->and($arabic)->not->toContain('onerror');
});

it('refuses a cover that is CSS rather than an image', function (string $cover) {
    /*
     * THE DEFECT: `posts.cover` is a CSS *background* value, and
     * store/post.blade.php prints CoverImage::background($post->cover) into a
     * style="background:…" attribute. Blade escapes it, so it cannot break out
     * of the attribute — but a value carrying a `#` comes back verbatim, so
     * `#fff;position:fixed;inset:0;z-index:99999` typed into a cover box is an
     * invisible layer over the whole page. That is a clickjacking primitive,
     * and it is the exact thing RichText refuses inline `style` for.
     *
     * MUTATION: return the trimmed value from coverUrl() instead of
     * scheme-checking it and this goes red on all three.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['cover' => $cover]))
        ->assertStatus(201);

    expect(Post::query()->value('cover'))->toBeNull();
})->with([
    '#fff;position:fixed;inset:0;z-index:99999',
    'javascript:alert(1)',
    'linear-gradient(135deg,#FFF0F4,#FCE0E8)',
]);

it('keeps a cover that really is an image, in both the shapes the picker gives', function (string $cover) {
    // The positive control for the test above: a scheme check that refused
    // everything would pass it and make the cover box useless.
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['cover' => $cover]))
        ->assertStatus(201);

    expect(Post::query()->value('cover'))->toBe($cover);
})->with(['/storage/posts/hero.jpg', 'https://example.test/hero.jpg']);

it('stores only the SEO keys this screen offers', function () {
    /*
     * THE DEFECT: Store\PageController::post() reads `canonical` and
     * `og_image` off posts.seo, and a canonical pointing at another domain is
     * the first entry on the SEO Audit screen's list of unsafe overrides.
     * `$post->seo = $request->input('seo')` would let a writing screen set
     * either of them, and `seo` is a json column so nothing downstream would
     * complain.
     *
     * MUTATION: return `$given` from seo() instead of the allowlist and the
     * canonical below is stored.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['seo' => [
        'title' => 'Heartleaf explained',
        'desc' => 'What it does.',
        'noindex' => false,
        'canonical' => 'https://kbeautyarabia.com/stolen/',
        'og_image' => 'https://evil.test/x.png',
    ]]))->assertStatus(201);

    // `noindex: false` is absent rather than stored false: PageController::post()
    // branches on !empty(), so a key that is there and falsey is a key somebody
    // later mistakes for a setting.
    expect(Post::query()->firstOrFail()->seo)
        ->toBe(['title' => 'Heartleaf explained', 'desc' => 'What it does.']);
});

it('stores one of the status select\'s own options and nothing else', function () {
    /*
     * CLAUDE.md rule 5: a select stores one of its own options or the default.
     * `status` is indexed and read by every storefront query; a free string
     * there is an article in a state nothing matches, so it is invisible on
     * the shop AND on the admin's Published/Draft filters at once.
     *
     * MUTATION: drop the `in:` rule from validated() and this is a 201.
     */
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle(['status' => 'live']))
        ->assertStatus(422);

    expect(Post::query()->count())->toBe(0);
});

/* ------------------------------------------------ 5. Arabic, from the start */

it('serves the Arabic article at /ar/{slug}/ from the same request that wrote it', function () {
    /*
     * THE POINT OF THE ARABIC BOXES: the competitor translates its blog, and
     * docs/SEO-FEATURE-MATRIX.md §2 item 2 names that as one of the three
     * places this shop loses. The Arabic is posted in the SAME request as the
     * English — translations[ar][…] — so "the Arabic is entered at the moment
     * of writing" is true rather than aspirational.
     *
     * THE SLUG IS DELIBERATELY NOT TRANSLATED. HasTranslations' header argues
     * it out: one slug per row, the language carried by the /ar prefix, so the
     * two addresses differ by four characters and hreflang joins them.
     *
     * MUTATION: drop the saveTranslations() call from store() and the Arabic
     * page renders the English headline.
     */
    ArabicShop::on();
    $this->actingAs(jaOwner(), 'admin');

    $this->postJson('/admin-api/post-editor-create', jaArticle([
        'translations' => ['ar' => [
            'title' => 'ورقة القلب، وما تفعله فعلًا',
            'excerpt' => 'مكوّن مهدّئ.',
            'body' => '<p>ورقة القلب هي المهدّئة.</p>',
        ]],
    ]))->assertStatus(201);

    $slug = Post::query()->value('slug');

    $this->get('/'.$slug.'/')->assertOk()->assertSee('Heartleaf is the calming one.', false);

    $this->get('/ar/'.$slug.'/')
        ->assertOk()
        ->assertSee('ورقة القلب هي المهدّئة.', false)
        ->assertSee('ورقة القلب، وما تفعله فعلًا', false);
});

/* ----------------------------------------------------- 6. the CollectionPage */

it('says what /skincare-guide/ is, in both languages', function () {
    /*
     * docs/SEO-MODULE-ROUND-4.md §5, the one edit that lane could not make:
     * the Journal index is a listing of articles and said NOTHING ABOUT ITSELF
     * in the graph. Every category archive in this shop publishes a
     * CollectionPage; the Journal index published none, so `type: website`
     * emitted the site-wide WebSite node and nothing for this document.
     *
     * MUTATION: put `'type' => 'website'` back in PageController::blog() and
     * both assertions below go red.
     */
    ArabicShop::on();

    $english = $this->get('/skincare-guide/')->assertOk()->getContent();

    expect($english)->toContain('"@type":"CollectionPage"')
        ->and($english)->toContain('"name":"The Glow Journal"')
        // Its own address, not the site's: a CollectionPage naming the home
        // page describes the wrong document.
        ->and($english)->toContain('/skincare-guide/');

    $arabic = $this->get('/ar/skincare-guide/')->assertOk()->getContent();

    // inLanguage is this document's own, which is what stops the Arabic index
    // inheriting the English one's claim.
    expect($arabic)->toContain('"@type":"CollectionPage"')
        ->and($arabic)->toContain('"inLanguage":"ar"');
});

/* -------------------------------------------------------- 7. the guard rail */

it('is mounted inside the admin group and reachable by nobody else', function () {
    /*
     * /api/* in this application is unauthenticated BY DESIGN, and every case
     * in ApiSecurityTest leaked in production first. An unguarded route here
     * would hand the public the ability to publish a page at the site root of
     * this shop's own domain — spam with the shop's reputation behind it.
     *
     * Read off the REGISTERED routes rather than trusting the harness:
     * RouteRegistrar::middleware() REPLACES rather than appends, so a harness
     * that chains it twice guards nothing while reading as though it does.
     *
     * MUTATION: drop 'auth:admin' from PostEditorRoutes::STACK and every
     * expectation below goes red.
     */
    $routes = PostEditorRoutes::registered();

    expect($routes)->toHaveCount(count(PostEditorRoutes::URIS));

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth:admin')->toContain('web');
    }

    // And over HTTP, with no session at all.
    $this->postJson('/admin-api/post-editor-create', jaArticle())->assertStatus(401);
    $this->getJson('/admin-api/post-editor-bootstrap')->assertStatus(401);

    expect(Post::query()->count())->toBe(0);
});

it('gives every one of its endpoints its own capability, and fails closed', function () {
    /*
     * CLAUDE.md rule 5. AdminCapabilities::for() returns null for a route it
     * has never heard of and null is owner-only — so an unmapped endpoint here
     * would work for the owner, silently refuse a manager, and nobody would
     * notice until a manager tried to write an article.
     *
     * `posts.manage` and not `content.manage`: publishing at the site root of
     * the shop is a stronger thing to hand out than the mega menu, and it must
     * be narrowable without narrowing that with it.
     *
     * MUTATION: delete the five RULES rows from AdminCapabilities and every
     * forPath() below answers null.
     */
    foreach (PostEditorRoutes::registered() as $route) {
        expect(AdminCapabilities::for($route))->toBe(
            'posts.manage',
            $route->uri().' has no capability of its own'
        );
    }

    expect(AdminCapabilities::CAPABILITIES['posts.manage'])->toBe(['owner', 'manager', 'editor'])
        // Reading the list stays where it was: an editor who may not write one
        // can still be shown what exists.
        ->and(AdminCapabilities::forPath('GET', 'admin-api/posts'))->toBe('content.manage');
});

it('lets an editor write an article and refuses a support account', function () {
    /*
     * The capability map is only worth anything if the request actually obeys
     * it. Driven over HTTP, both directions, because a rule that is never
     * enforced and a rule that refuses everybody look identical in a green run.
     *
     * MUTATION: add 'support' to the posts.manage row and the second half goes
     * red; remove 'editor' and the first half does.
     */
    $this->actingAs(jaOwner('editor'), 'admin');
    $this->postJson('/admin-api/post-editor-create', jaArticle())->assertStatus(201);

    $this->actingAs(jaOwner('support'), 'admin');
    $this->postJson('/admin-api/post-editor-create', jaArticle(['slug' => 'another-one']))
        ->assertForbidden();

    expect(Post::query()->count())->toBe(1);
});

/* ------------------------------------------------------ 8. it ships inert */

it('publishes nothing by existing', function () {
    /*
     * CLAUDE.md rule 1. Applying this package must move nothing on the shop:
     * no article appears, the Journal index is the same empty index it was, and
     * the only difference on Content → Blog Posts is two buttons.
     *
     * MUTATION: seed a demo article from anywhere in this lane's code — a
     * migration, the controller's constructor — and this goes red.
     */
    expect(Post::query()->count())->toBe(0);

    $this->get('/skincare-guide/')->assertOk();
});
