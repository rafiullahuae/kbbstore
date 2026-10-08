<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * THE SEARCH-RESULT SHARE IMAGE IS TAKEN FROM THE MAIN IMAGE. (Lane SP2)
 *
 * The owner:
 *
 *   "ON THE product edit page and set edit page, the seo image must be taken
 *    auto from the main image automatically when i upload the main image of the
 *    product or set, and manually also i can change that seo image."
 *
 * Both are the same screen — Catalog → Product editor, panels **Images** and
 * **Search appearance** — because a set is a product type there.
 *
 * ── WHAT THIS DOES AND DOES NOT CHANGE ────────────────────────────────────
 *
 * Store\ProductController::show() has always read `$override['og_image'] ??
 * $product->image`, so the HEAD of a product with no share image already
 * carried the main image. Filling the column therefore publishes NOTHING NEW —
 * the case below renders the page before and after and compares the tags — and
 * what it buys is that the value is visible, editable, and correct after the
 * main image is swapped, which the fallback could not give the operator because
 * he could not see it at all.
 *
 * ── AND THE HALF THAT WOULD BE A BUG ──────────────────────────────────────
 *
 * A share image the operator chose must never be overwritten. That is the
 * difference between a helpful default and a screen that fights its user, and
 * it is the reason the rule is "empty, or equal to the main image being
 * replaced" rather than "fill it whenever we have a main image".
 */
beforeEach(function () {
    $this->actingAs(AdminUser::create([
        'name' => 'Share owner',
        'email' => 'sog-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
});

function sogCreate(array $overrides = []): int
{
    return test()->postJson('/admin-api/product-editor-create', array_merge([
        'name' => 'Heartleaf Toner',
        'slug' => 'sog-'.Str::lower(Str::random(10)),
        'status' => 'publish',
        'price_aed' => '99',
        'image' => '/media/products/toner-front.jpg',
    ], $overrides))->assertCreated()->json('product.id');
}

function sogSeo(int $id): array
{
    return (array) (Product::find($id)->seo ?? []);
}

/** The editor's source, with every comment stripped first. */
function sogEditorCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/* ═══════════════════════════════════════════════════ filling it in ═══ */

it('fills the share image from the main image when a product is created', function () {
    /*
     * ON CREATE, where there is no row yet — the half an "update the SEO row
     * afterwards" design would have missed, because there is nothing to update
     * until the insert has happened.
     *
     * MUTATION NOTE. Delete the followMainImageIntoSeo() call from apply() and
     * this is red: `seo` is null and the operator sees an empty Share image box
     * on a product that has a photograph. RUN.
     */
    $id = sogCreate();

    expect(sogSeo($id)['og_image'] ?? null)->toBe('/media/products/toner-front.jpg');
});

it('moves an automatic share image when the main image is replaced', function () {
    /*
     * The share image was filled from the main image, so it FOLLOWS the main
     * image. A product re-photographed a month later must not go on sharing the
     * old shot on Facebook and WhatsApp for ever.
     *
     * MUTATION NOTE. Compare only against the NEW main image — drop `$og !==
     * $was` from followMainImageIntoSeo() — and this is red: the automatic
     * value looks hand-picked the instant the main image changes, so the
     * automatic behaviour would fire exactly once in a product's life. RUN.
     */
    $id = sogCreate();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'image' => '/media/products/toner-2026.jpg',
    ])->assertOk();

    expect(sogSeo($id)['og_image'] ?? null)->toBe('/media/products/toner-2026.jpg');
});

/* ══════════════════════════════════ and never over the top of a choice ═══ */

it('never overwrites a share image the operator chose by hand', function () {
    /*
     * ▲ THE ONE THAT MATTERS MOST. A picked share image is usually a DIFFERENT
     *   picture on purpose — a lifestyle shot, a banner with words on it, a
     *   square crop — and replacing it with the packshot because somebody
     *   swapped the main photograph is the screen overruling its operator.
     *
     * MUTATION NOTE. Remove the early return from followMainImageIntoSeo() and
     * this is red: the hand-picked share card is silently replaced by the new
     * packshot. RUN.
     */
    $id = sogCreate();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'seo' => ['og_image' => '/media/social/toner-share-card.jpg'],
    ])->assertOk();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'image' => '/media/products/toner-2026.jpg',
        'seo' => ['og_image' => '/media/social/toner-share-card.jpg'],
    ])->assertOk();

    expect(Product::find($id)->image)->toBe('/media/products/toner-2026.jpg')
        ->and(sogSeo($id)['og_image'] ?? null)->toBe('/media/social/toner-share-card.jpg');
});

it('goes back to automatic when the box is cleared', function () {
    /*
     * The way back. There is no flag to unset, so clearing the box IS the
     * instruction — ProductSeo::normalise() filters '' out of the stored array,
     * the value is then empty, and the rule fills it from the main image again.
     * The screen's "Use the main image" button is the same instruction with the
     * typing done for the operator.
     */
    $id = sogCreate();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'seo' => ['og_image' => '/media/social/toner-share-card.jpg'],
    ])->assertOk();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'seo' => ['og_image' => ''],
    ])->assertOk();

    expect(sogSeo($id)['og_image'] ?? null)->toBe('/media/products/toner-front.jpg');
});

it('drops an automatic share image with the main image, and keeps a chosen one', function () {
    /*
     * Removing the main image. An automatic value is pointing at a picture this
     * product no longer has, so it goes with it and the page falls back to the
     * sitewide default share image — which is what it published before either
     * was set. A hand-picked one is about the share card, not about the main
     * image, and stays.
     */
    $auto = sogCreate();
    $hand = sogCreate();

    test()->postJson('/admin-api/product-editor-save/'.$hand, [
        'seo' => ['og_image' => '/media/social/toner-share-card.jpg'],
    ])->assertOk();

    foreach ([$auto, $hand] as $id) {
        test()->postJson('/admin-api/product-editor-save/'.$id, ['image' => null])->assertOk();
    }

    expect(sogSeo($auto)['og_image'] ?? null)->toBeNull()
        ->and(sogSeo($hand)['og_image'] ?? null)->toBe('/media/social/toner-share-card.jpg');
});

/* ══════════════════════════════════════════ and the page does not move ═══ */

it('publishes exactly the same head it published before the column was filled', function () {
    /*
     * CLAUDE.md's first rule, measured rather than asserted. The storefront's
     * fallback was ALREADY the main image, so writing it into `seo.og_image`
     * must change the emitted tags by not one byte — og:image, twitter:image
     * and the Product node's `image` alike, all absolute, all through
     * App\Support\Seo::absolute() exactly as the fallback was.
     *
     * That is also the answer to "does an auto-filled value satisfy the rules
     * og:image already applies": it is the same string the same function
     * already made absolute, so it cannot satisfy them any less.
     *
     * MUTATION NOTE. Write a bare filename instead of the stored path —
     * `basename($now)` in followMainImageIntoSeo() — and this is red: the tags
     * point at https://host/toner-front.jpg, which is a 404, and Facebook shows
     * no picture at all. RUN.
     */
    $product = Product::create([
        'slug' => 'sog-render-'.Str::lower(Str::random(8)),
        'name' => 'Heartleaf Toner',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'image' => '/media/products/toner-front.jpg',
    ]);

    $tags = static function (string $html): array {
        preg_match_all('#<meta[^>]+(?:og:image|twitter:image)[^>]*>#', $html, $m);

        return $m[0];
    };

    $before = $tags((string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent());

    expect($before)->not->toBeEmpty('nothing to compare — the page emitted no image tag at all');

    test()->postJson('/admin-api/product-editor-save/'.$product->id, [
        'name' => 'Heartleaf Toner',
    ])->assertOk();

    expect(sogSeo($product->id)['og_image'] ?? null)->toBe('/media/products/toner-front.jpg');

    $after = $tags((string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent());

    expect($after)->toBe($before, 'Filling the column must publish nothing new.');
});

/* ═══════════════════════════════════════════════════════ the screen ═══ */

it('says on the screen which of the two states the share image is in', function () {
    /*
     * A picture that appeared by itself is indistinguishable from one somebody
     * chose unless the screen says so — and the operator cannot otherwise know
     * whether changing the main image will move it.
     *
     * Asserted against the source WITH COMMENTS STRIPPED, because this screen
     * explains itself in prose and a scan of the raw text would match the
     * explanation and pass a screen that does not have the control in it.
     */
    $code = sogEditorCode();

    expect(str_contains($code, 'Automatic — taken from the main image.'))
        ->toBeTrue('the automatic state is not named on the screen');

    expect(str_contains($code, 'Chosen by hand.'))
        ->toBeTrue('the hand-picked state is not named on the screen');

    expect(str_contains($code, 'id="peo-ogauto"'))
        ->toBeTrue('there is no way back to automatic');

    /* Defined once, drawn once in the panel, and redrawn once while the box is
       being typed into -- three, and the third is what keeps the line honest
       between renders. Zero would be a control nobody can see; four would mean
       the line is in the markup twice and the "Use the main image" button with
       it. */
    expect(substr_count($code, 'ogStateView()'))
        ->toBe(3, 'the state line is defined once, drawn once, and refreshed once');

    expect(substr_count($code, "id=\"peo-ogstatehost\""))
        ->toBe(1, 'the host the live refresh writes into is not there exactly once');
});

it('writes the main image through one function, so the share image follows every control', function () {
    /*
     * THE DEFECT THIS ASSERTION IS ABOUT. Three controls set the main image —
     * the Media Library, an upload and Remove — and the follow rule has to be
     * on all three or it is on none of them. It was three separate
     * `model.image = …` assignments, which is exactly how a rule ends up
     * applied on two paths out of three and the bug report reads "it works when
     * I upload but not when I choose".
     *
     * MUTATION NOTE. Put `model.image = urls[0];` back in the Media Library
     * handler and this is red at 4 occurrences. RUN.
     */
    $code = sogEditorCode();

    expect(substr_count($code, 'model.image = '))
        ->toBe(1, 'model.image is written outside setMainImage()')
        ->and(substr_count($code, 'setMainImage('))
        // 5 with Lane RPL: the fourth control is Replace (click the main image,
        // or its Replace button -> the Media Library in replace mode), and it
        // goes through setMainImage() like the other three.
        ->toBe(5, 'one definition and the four controls that set a main image');
});
