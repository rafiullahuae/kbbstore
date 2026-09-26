<?php

declare(strict_types=1);

use App\Http\Controllers\Api\UgcController;
use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Models\UgcVideoLike;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The two PUBLIC shoppable-video endpoints, and the one of them that WRITES.
 *
 * `/api/*` is unauthenticated on this shop and CLAUDE.md names it: every endpoint
 * there is public, and tests/Feature/ApiSecurityTest.php exists because each of
 * its cases leaked in production. A like is therefore a row anybody on the
 * internet can create, and the feed is a document anybody can read.
 */
function likeShop(bool $module = true, bool $likes = true): UgcVideo
{
    $settings = app(SettingsService::class);
    $settings->setModule('shoppable_video', $module);
    $settings->setModuleSetting('shoppable_video', 'likes_on', $likes);
    SettingsService::forgetMemo();
    Cache::flush();

    $brand = Brand::query()->firstOrCreate(['slug' => 'like-brand'], ['name' => 'Like Brand']);

    $product = Product::query()->create([
        'slug' => 'like-p-'.uniqid(),
        'name' => 'Like Product',
        'brand_id' => $brand->id,
        'price' => 5000,
        'status' => 'publish',
        'stock_status' => 'instock',
        'rating' => 4.2,
        'review_count' => 9,
        'type' => 'simple',
        /*
         * Never on the wire: the three columns ApiSecurityTest exists for. `wc_id`
         * is UNIQUE, so it is derived rather than literal — likeShop() is called
         * twice in one case below and a literal 4242 made the second call a
         * constraint violation rather than a test failure.
         */
        'wc_id' => random_int(100000, 999999),
        'sku' => 'SECRET-SKU',
        'total_sales' => 777,
    ]);

    $video = UgcVideo::query()->updateOrCreate(['slug' => 'likeable'], [
        'title' => 'Likeable clip',
        'status' => 'publish',
        'rights_status' => 'granted',
        'rights_evidence' => 'DM from @creator on 3 March, screenshot in Drive',
        'file_path' => '/uploads/ugc/clip-like.mp4',
        'poster_path' => '/uploads/ugc/poster-like.webp',
        'width' => 360,
        'height' => 640,
        'creator_handle' => '@creator',
        'published_at' => now()->subDay(),
    ]);

    /*
     * updateOrCreate / syncWithoutDetaching throughout, because the last case calls
     * this helper TWICE in one test — once with the module off and once with it on —
     * and `create()` made the second call a unique-constraint violation on
     * ugc_videos.slug rather than a test result.
     */
    $video->products()->syncWithoutDetaching([$product->id => ['position' => 0]]);

    $section = UgcSection::query()->updateOrCreate(['handle' => 'likeable'], [
        'title' => 'Likeable',
        'status' => 'publish',
    ]);

    $section->videos()->syncWithoutDetaching([$video->id => ['position' => 0]]);

    return $video;
}

/**
 * The one-per-browser token, sent back the way a browser sends it.
 *
 * ▲ withUnencryptedCookie() AND NOT withCookie(), AND THE DIFFERENCE IS NOT A
 * TEST DETAIL — it is what these endpoints being in the api group means.
 *
 * Laravel's withCookie() ENCRYPTS the value on the way out, because it assumes
 * the route is in the web group where EncryptCookies will decrypt it again.
 * routes/ugc.php is required from routes/api.php, which carries no EncryptCookies
 * at all — so the controller received ciphertext, UgcVideoLike::looksMinted()
 * correctly refused it, minted a fresh token, and every request counted as a first
 * like. The ledger held two rows for one browser and `already` was never true.
 *
 * A real browser does the right thing here: the response sets the cookie
 * unencrypted (nothing encrypts it), the browser sends it back byte for byte, and
 * the controller reads it. Both halves are on the same side of EncryptCookies,
 * which is one of the two reasons routes/ugc.php's header gives for the api group.
 * Split across the two, every like would look like a first like — silently.
 *
 * ▲ AND withCredentials(), WHICHOUT WHICH postJson() SENDS NO COOKIES AT ALL.
 * MakesHttpRequests::prepareCookiesForJsonRequest() returns `[]` unless
 * withCredentials() has been called — mirroring fetch(), which is a same-origin
 * XHR and not a navigation. Without it the server saw an EMPTY cookie bag, minted
 * a fresh token on every request, and the ledger held one row per press. The
 * shipped script sets `credentials: 'same-origin'` explicitly, so this is the same
 * request a browser makes rather than a test convenience.
 */
function likeAs(string $token): \Illuminate\Testing\TestResponse
{
    return test()->withCredentials()
        ->withUnencryptedCookie(UgcVideoLike::COOKIE, $token)
        ->postJson('/api/ugc/likeable/like');
}

it('counts one like per browser, however many times the button is pressed', function () {
    /*
     * THE UNIQUE INDEX IS THE RULE AND THE INSERT IS HOW IT IS APPLIED.
     *
     * A read-then-write guard in PHP is a race that two taps in the same second
     * both win — which on a tap target in a rail is not hypothetical — so
     * `(ugc_video_id, token_hash)` is unique in the schema and a constraint
     * violation is caught and answered "already". Same answer from the shopper's
     * side, and the only one that cannot double-count.
     *
     * MUTATION NOTE. Replace the try/catch in UgcController::like() with an
     * `if (! exists()) { create(); increment(); }` and this case still passes —
     * which is why the case below it exists and hits the table directly. Drop the
     * unique index from the migration and the second press counts: red at 2.
     * RUN: red.
     */
    $video = likeShop();

    $first = $this->postJson('/api/ugc/likeable/like');

    $first->assertOk()->assertJson(['ok' => true, 'likes' => 1, 'already' => false]);

    // The cookie the response minted, sent back the way a browser would.
    $token = $first->getCookie(UgcVideoLike::COOKIE, false)?->getValue();

    expect($token)->not->toBeNull()
        ->and(UgcVideoLike::looksMinted($token))->toBeTrue();

    $second = likeAs((string) $token);

    $second->assertOk()->assertJson(['ok' => true, 'likes' => 1, 'already' => true]);

    expect((int) $video->fresh()->likes)->toBe(1)
        ->and(UgcVideoLike::query()->count())->toBe(1);
});

it('cannot be double-counted by two requests racing, because the database refuses', function () {
    /*
     * The half a PHP guard cannot cover. Two inserts with the same pair, as the
     * two halves of a race would arrive.
     *
     * MUTATION NOTE. Remove the ->unique(['ugc_video_id','token_hash']) line from
     * 2027_02_02_000001_create_ugc_likes.php and this is red: the second insert
     * succeeds and the ledger holds two rows for one browser. RUN: red.
     */
    $video = likeShop();
    $hash = UgcVideoLike::hash(UgcVideoLike::mint());

    UgcVideoLike::query()->create(['ugc_video_id' => $video->id, 'token_hash' => $hash]);

    expect(fn () => UgcVideoLike::query()->create(['ugc_video_id' => $video->id, 'token_hash' => $hash]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('stores nothing about the person who liked it', function () {
    /*
     * Two columns and that is the whole table. No address, no user agent, no
     * customer id, no referrer — each omission is a decision, listed in
     * App\Models\UgcVideoLike's docblock. The rate limit lives in the RateLimiter's
     * own expiring cache and is never written down.
     *
     * MUTATION NOTE. Add `'ip' => $request->ip()` to the create() in
     * UgcController::like() and this is red on the column list. RUN: red — and the
     * migration would have to grow the column too, which is the point: the schema
     * is where "we do not keep this" is enforced.
     */
    likeShop();

    $this->postJson('/api/ugc/likeable/like')->assertOk();

    $row = UgcVideoLike::query()->firstOrFail();
    $columns = array_keys($row->getAttributes());

    sort($columns);

    expect($columns)->toBe(['created_at', 'id', 'token_hash', 'ugc_video_id', 'updated_at']);

    // And the token is HASHED, not kept: a readable dump of this table is not a
    // list of usable cookies.
    expect($row->token_hash)->toHaveLength(64)
        ->and($row->token_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('refuses a cookie that is not the shape this shop mints', function () {
    /*
     * The cookie is attacker-controlled. Hashing four kilobytes of junk and writing
     * a row for it is work somebody else chose for us, so a value that is not 32
     * hex characters is REPLACED with a fresh token rather than trusted.
     *
     * MUTATION NOTE. Delete the looksMinted() branch in UgcController::like() and
     * this is red: the junk value is hashed and the response sets no cookie, so the
     * caller can spend one like per junk string it invents — which is the per-IP
     * ceiling's problem rather than the index's. RUN: red.
     */
    likeShop();

    $response = likeAs(str_repeat('z', 4096));

    $response->assertOk();

    $minted = $response->getCookie(UgcVideoLike::COOKIE, false)?->getValue();

    expect(UgcVideoLike::looksMinted($minted))->toBeTrue()
        ->and($minted)->not->toBe(str_repeat('z', 4096));
});

it('stops a patient caller at the per-address ceiling', function () {
    /*
     * The cookie is the caller's to throw away, so it is a convenience and not a
     * limit — exactly what Store\ReviewController says about its own vote cookie.
     * This is the limit.
     *
     * MUTATION NOTE. Remove the RateLimiter::hit() call and this is red: the
     * ceiling is never reached. RUN: red.
     */
    likeShop();

    RateLimiter::clear('ugc-like:127.0.0.1');

    for ($i = 0; $i < UgcController::MAX_LIKES_PER_HOUR; $i++) {
        RateLimiter::hit('ugc-like:127.0.0.1', 3600);
    }

    $this->postJson('/api/ugc/likeable/like')->assertStatus(429);
});

it('charges the allowance only when it actually writes', function () {
    /*
     * A shopper re-tapping a clip they already liked must not spend their
     * allowance, or forty taps on one heart locks them out of every other.
     * Store\ReviewController::helpful() makes the same point in its own comment.
     *
     * MUTATION NOTE. Move RateLimiter::hit() above the try/catch and this is red at
     * 2 against 1. RUN: red.
     */
    likeShop();
    RateLimiter::clear('ugc-like:127.0.0.1');

    $first = $this->postJson('/api/ugc/likeable/like');
    $token = (string) $first->getCookie(UgcVideoLike::COOKIE, false)?->getValue();

    likeAs($token)->assertOk();
    likeAs($token)->assertOk();

    expect(RateLimiter::attempts('ugc-like:127.0.0.1'))->toBe(1);
});

it('is the same 404 for a draft clip, a refused one and a slug nobody issued', function () {
    /*
     * Api\QuizController::expertRequest already paid for this lesson (CLAUDE.md,
     * Known gaps): an endpoint that answers differently for "exists but you may
     * not" and "does not exist" is a directory of the rows you may not have. Here
     * that directory would list clips whose creator has REFUSED permission, which
     * is the worst row in the table to advertise.
     *
     * Byte for byte, because they are the same return statement.
     *
     * MUTATION NOTE. Change `UgcVideo::published()->where('slug', ...)` to
     * `->where('slug', ...)` plus a separate status check with its own 403, and
     * this is red on the body comparison. RUN: red.
     */
    likeShop();

    UgcVideo::query()->create([
        'slug' => 'draft-clip',
        'title' => 'Draft',
        'status' => 'draft',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-d.mp4',
        'poster_path' => '/uploads/ugc/poster-d.webp',
    ]);

    UgcVideo::query()->create([
        'slug' => 'refused-clip',
        'title' => 'Refused',
        'status' => 'publish',
        'rights_status' => 'refused',
        'file_path' => '/uploads/ugc/clip-r.mp4',
        'poster_path' => '/uploads/ugc/poster-r.webp',
    ]);

    $bodies = [];

    foreach (['draft-clip', 'refused-clip', 'never-existed'] as $slug) {
        $response = $this->postJson('/api/ugc/'.$slug.'/like');
        $response->assertStatus(404);
        $bodies[] = $response->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1);
});

it('404s both endpoints while the module is off, and while likes are off', function () {
    /*
     * RULE 1 AS A REACHABLE SURFACE. Applying this package must add nothing a
     * stranger can reach, and the module ships OFF. Two switches, both failing
     * closed: the module, and the likes control on Appearance → Video rail.
     *
     * MUTATION NOTE. Remove the `! ($config['likes_on'] ?? false)` clause and the
     * second block is red: a shop with the module on but likes off accepts public
     * writes. RUN: red.
     */
    likeShop(module: false);

    $this->postJson('/api/ugc/likeable/like')->assertStatus(404);
    $this->getJson('/api/ugc/likeable')->assertStatus(404);

    likeShop(module: true, likes: false);

    $this->postJson('/api/ugc/likeable/like')->assertStatus(404);
    // The FEED is not gated on likes_on — only on the module — so it answers.
    $this->getJson('/api/ugc/likeable')->assertOk();
});

it('publishes an allowlist and never a model', function () {
    /*
     * §7, and the pattern is SettingController::PUBLIC_KEYS: an explicit list
     * iterated over, so a column added next year is invisible by DEFAULT rather
     * than public by default. The products inside go through the EXISTING
     * Product::toApi(), not a second copy of it.
     *
     * MUTATION NOTE. Replace the map in UgcController::section() with
     * `$videos->toArray()` and this is red with rights_evidence, status and the
     * filesystem timestamps in it. RUN: red.
     */
    likeShop();

    $body = $this->getJson('/api/ugc/likeable')->assertOk()->json();

    expect(array_keys($body))->toBe(['section', 'videos'])
        ->and(array_keys($body['section']))->toBe(['handle', 'heading', 'subheading'])
        ->and(array_keys($body['videos'][0]))->toBe([
            'slug', 'title', 'caption', 'poster', 'src', 'teaser', 'width', 'height',
            'duration_ms', 'creator_handle', 'creator_url', 'source_url', 'platform',
            'published_at', 'likes', 'products',
        ]);

    $json = $this->getJson('/api/ugc/likeable')->getContent();

    foreach (['rights_evidence', 'rights_status', 'rights_granted_at', 'DM from',
        'SECRET-SKU', '777', 'position', 'created_at', 'updated_at'] as $forbidden) {
        /*
         * str_contains() AND NOT ->not->toContain($needle, $message).
         * Pest's toContain() IS VARIADIC, so the second argument is read as a
         * SECOND NEEDLE rather than as a failure message — and
         * ExpectationsThatCannotFailTest exists to catch exactly that, because an
         * expectation written the other way cannot fail. It caught this line.
         */
        expect(str_contains($json, $forbidden))->toBeFalse("the public feed leaks {$forbidden}");
    }

    /*
     * The operator's own label for the section is NOT published either. `title` is
     * his filing system — "Homepage hero rail" — and `heading` is what a shopper
     * reads. A public feed has no business with the first.
     */
    expect($json)->not->toContain('Likeable"');
});
