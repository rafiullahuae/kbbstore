<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\InstagramPost;
use App\Models\Setting;
use App\Services\Instagram\InstagramAuth;
use App\Services\Instagram\InstagramClient;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramFeed;
use App\Services\InstagramSettings;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstagramAdminRoutes;

/**
 * Content → Instagram — the connection, the fetch, the section and the shortcode.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md is the design and §0 of it is the honest
 * caveat this whole file is written under: EGRESS IS BLOCKED IN THIS CONTAINER, so
 * not one assertion below reached a live Meta endpoint. Every call is Http::fake()d
 * against the response shapes Meta documents, which proves this shop handles those
 * shapes correctly and proves NOTHING about whether Meta still sends them. §7 lists
 * what the owner must do himself for the real thing to work.
 *
 * That is a real limit and it is worth being exact about what the fakes DO buy:
 * every branch of the flow — the refusals, the malformed answers, the personal
 * account, the omitted count, the expired token — is a shape this code has to get
 * right whatever Meta's current field list is, and each one below is a branch that
 * would otherwise be first exercised on the owner's live shop.
 */
function igAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Ig '.$role,
        'email' => 'ig-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** Turn the module on (or off) and clear everything that caches it. */
function igModule(bool $on = true, array $settings = []): void
{
    $service = app(SettingsService::class);
    $service->setModule(InstagramSettings::MODULE, $on);

    foreach ($settings as $key => $value) {
        $service->setModuleSetting(InstagramSettings::MODULE, $key, $value);
    }

    SettingsService::forgetMemo();
    Setting::flushMap();
    Cache::flush();
}

/**
 * A stored post with a picture that really exists on disk.
 *
 * THE FILE IS WRITTEN, not just the row. InstagramFeed drops a tile whose path
 * fails IgPath::stored(), and a test whose fixtures were paths alone would pass
 * with tiles that the shop cannot draw — which is the exact failure this feature
 * has to be able to report ("posts fetched, no pictures stored").
 */
function igPost(array $attributes = []): InstagramPost
{
    $id = $attributes['remote_id'] ?? ('ig-'.uniqid());
    $name = sha1((string) $id).'.jpg';

    @mkdir(\App\Services\Instagram\IgPath::directory(), 0775, true);
    file_put_contents(\App\Services\Instagram\IgPath::directory().'/'.$name, 'not-really-a-jpeg');

    return InstagramPost::query()->create(array_merge([
        'remote_id' => $id,
        'media_type' => 'IMAGE',
        'permalink' => 'https://www.instagram.com/p/ABC'.substr(sha1((string) $id), 0, 8).'/',
        'shortcode' => 'ABC'.substr(sha1((string) $id), 0, 8),
        'caption' => 'A caption',
        'local_path' => '/'.\App\Services\Instagram\IgPath::ROOT.$name,
        'width' => 640,
        'height' => 640,
        'like_count' => 120,
        'comments_count' => 4,
        'posted_at' => now()->subMinutes(random_int(1, 10000)),
        'seen_at' => now(),
    ], $attributes));
}

/** App id + secret saved, and a token with 60 days on it. */
function igConnected(): void
{
    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');
    InstagramCredentials::saveToken('a-very-long-lived-token', 60 * 86400, '17841400000000000');
}

/* ─────────────────────────────── the capability map ───────────────────────── */

it('maps every new endpoint to a capability, with the writes above the reads', function () {
    /*
     * AdminCapabilities::RULES is FIRST-MATCH-WINS, and the two entries that matter
     * most here are GETs THAT WRITE: `GET admin-api/instagram/start` mints an OAuth
     * state, and `GET admin-api/instagram/callback` stores a sixty-day access token.
     * Both have to be GETs — Instagram navigates the owner's browser to the callback
     * and a browser cannot be made to POST there — so both are named individually
     * ABOVE the general `GET admin-api/instagram/**` rule.
     *
     * MUTATION NOTE. Move the two `GET admin-api/instagram` lines in
     * AdminCapabilities::RULES above the six write lines and this is red: /start and
     * /callback both resolve to `instagram.view`, which would let a read-only
     * support account authorise this shop against an Instagram account. RUN: red on
     * the first two expectations.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/instagram/start'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/instagram/callback'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/instagram'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/instagram/app'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/instagram/refresh'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/instagram'))->toBe('instagram.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/instagram'))->toBe('instagram.view');
});

it('gives the read capability to an editor and the write capability to nobody below manager', function () {
    /*
     * The split is SHARPER here than for the video library and that is the whole
     * argument for a second capability: `instagram.view` reads a username, a
     * follower count and how many days are left on the connection. `instagram.manage`
     * starts an OAuth handshake, stores an encrypted app secret, and can disconnect
     * the shop from the account. The first is something to give a support account.
     *
     * MUTATION NOTE. Add 'editor' to `instagram.manage` in AdminCapabilities::RULES
     * and the third expectation is red. RUN: red.
     */
    expect(AdminCapabilities::roleCan('editor', 'instagram.view'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('manager', 'instagram.manage'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('owner', 'instagram.manage'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('editor', 'instagram.manage'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('support', 'instagram.manage'))->toBeFalse();
});

it('refuses a signed-out caller on every one of them', function (string $method, string $url) {
    /*
     * Not a nicety. POST /instagram/app takes an app SECRET, /start mints an OAuth
     * state into the admin session, /callback stores a sixty-day token, and DELETE
     * can disconnect the shop. `/api/*` is unauthenticated on this shop (CLAUDE.md)
     * and NOT ONE of these could live there.
     *
     * MUTATION NOTE. Move the Route:: declarations in routes/instagram-admin.php
     * outside the admin-api group — which is what requiring that file at the top
     * level of routes/web.php would do — and every one of these is red with a 200 or
     * a 302. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);

    $verb = ['GET' => 'getJson', 'POST' => 'postJson', 'DELETE' => 'deleteJson'][$method];

    $this->{$verb}($url)->assertStatus(401);
})->with([
    ['GET', '/admin-api/instagram'],
    ['POST', '/admin-api/instagram'],
    ['POST', '/admin-api/instagram/app'],
    ['POST', '/admin-api/instagram/refresh'],
    ['DELETE', '/admin-api/instagram'],
]);

it('refuses a signed-out browser on the two GETs that write, without starting anything', function () {
    /*
     * The two OAuth legs are ordinary browser navigations rather than JSON calls, so
     * they are checked the way a browser reaches them — and the thing being asserted
     * is not only the refusal but that NOTHING HAPPENED: no state was minted into a
     * session by an unauthenticated hit on /start.
     */
    InstagramAdminRoutes::wire($this->app);

    /*
     * ▲ PIN ADVANCED (Lane SEC, round 2): this asserted assertRedirect() on both legs.
     *
     * An admin-guarded address that does NOT carry the secret admin path --
     * every `admin-api/...` one -- used to answer a browser with
     * `302 Location: .../<admin_path>/login`, which handed the secret to anyone
     * who typed a fixed, guessable prefix. It answers a plain 404 now. Still a
     * refusal, and one that does not admit the endpoint is there. A request
     * that expects JSON still answers 401, unchanged.
     * tests/Feature/AdminPathNeverLeaksTest.php sweeps all 398.
     */
    $this->get('/admin-api/instagram/start')->assertNotFound();
    $this->get('/admin-api/instagram/callback?code=x&state=y')->assertNotFound();

    expect(session()->has(InstagramAuth::STATE_SESSION_KEY))->toBeFalse();
});

/* ──────────────────────────────── the OAuth state ────────────────────────── */

it('refuses a callback whose state is empty on both sides', function () {
    /*
     * ── THE ONE THAT WOULD HAVE SHIPPED ─────────────────────────────────────
     *
     * `hash_equals('', '')` is TRUE. A callback carrying no state at all, arriving
     * in a session that has none stored, passes a naive comparison — and that is the
     * whole attack, because an unauthorised callback has no state to send and a
     * session it did not start has none stored.
     *
     * MUTATION NOTE. Delete the `$expected === '' || $given === '' ||` clause from
     * InstagramAuth::consume() and this case is red: consume() returns ok. RUN: red.
     */
    $request = \Illuminate\Http\Request::create('/x');
    $request->setLaravelSession(app('session.store'));

    expect(InstagramAuth::consume($request, '')['ok'])->toBeFalse();
});

it('spends the state by looking at it, so a replayed callback cannot reuse it', function () {
    /*
     * consume() calls pull(), which REMOVES the value — so the state is spent by
     * being looked at and every refusal has already consumed it. That is what stops
     * a replayed callback URL getting a second code exchange out of one state.
     *
     * MUTATION NOTE. Change the `pull()` in InstagramAuth::consume() to `get()` and
     * the second expectation is red: the same state matches twice. RUN: red.
     */
    $request = \Illuminate\Http\Request::create('/x');
    $request->setLaravelSession(app('session.store'));

    InstagramAuth::remember($request, 'a-state-of-forty-characters-or-so-here');

    expect(InstagramAuth::consume($request, 'a-state-of-forty-characters-or-so-here')['ok'])->toBeTrue()
        ->and(InstagramAuth::consume($request, 'a-state-of-forty-characters-or-so-here')['ok'])->toBeFalse();
});

it('refuses a state that has been sitting in a session too long', function () {
    $request = \Illuminate\Http\Request::create('/x');
    $request->setLaravelSession(app('session.store'));

    // Written by hand rather than by remember(), because the thing being tested is
    // what happens to an OLD one and remember() can only make a new one.
    $request->session()->put(InstagramAuth::STATE_SESSION_KEY, [
        'value' => 'an-old-state-nobody-finished-using-here',
        'issued_at' => time() - (InstagramAuth::STATE_TTL_SECONDS + 60),
    ]);

    expect(InstagramAuth::consume($request, 'an-old-state-nobody-finished-using-here')['ok'])->toBeFalse();
});

it('builds the redirect URI from this shop and not from the admin path', function () {
    /*
     * Two properties in one line, and both are decisions docs/IG-PROFILE.md §7
     * item 4 costs: the URI is a FIXED PATH, so the owner's Meta app configuration
     * never has to change; and it does NOT contain the secret admin path, which is
     * a setting he can edit and which this shop treats as semi-secret.
     *
     * MUTATION NOTE. Build CALLBACK_PATH from Setting `admin_path` and the second
     * expectation is red the moment that setting is anything but empty. RUN: red.
     */
    Setting::query()->updateOrCreate(['key' => 'admin_path'], ['value' => 'secret-door', 'autoload' => true]);
    Setting::flushMap();

    $uri = InstagramAuth::redirectUri();

    expect(str_ends_with($uri, '/admin-api/instagram/callback'))->toBeTrue()
        ->and(str_contains($uri, 'secret-door'))->toBeFalse();
});

it('will not build an authorisation URL before the app id and secret are saved', function () {
    expect(InstagramAuth::authorizeUrl()['ok'])->toBeFalse();

    igConnected();

    $answer = InstagramAuth::authorizeUrl();

    expect($answer['ok'])->toBeTrue()
        ->and(str_starts_with((string) $answer['url'], InstagramClient::AUTHORIZE_URL.'?'))->toBeTrue()
        // The scope asked for is the read-only one and nothing else. A write scope
        // on a read feature is a scope to be sorry about — docs/IG-PROFILE.md §4.
        ->and(str_contains((string) $answer['url'], 'scope=instagram_business_basic'))->toBeTrue()
        ->and(str_contains((string) $answer['url'], 'instagram_business_content_publish'))->toBeFalse()
        ->and(strlen((string) $answer['state']))->toBe(40);
});

/* ───────────────────────────── credentials at rest ───────────────────────── */

it('stores the secret and the token encrypted, and not in the settings snapshot', function () {
    /*
     * Three properties, and the third is the one a reader would not think to check.
     *
     * 1. the ciphertext in the row is not the plaintext;
     * 2. the getter still returns the plaintext, so the encryption is not a
     *    write-only hole;
     * 3. `autoload` is FALSE on both rows. `autoload` is what puts a row into the
     *    snapshot SettingsService::all() builds and hands to anything that asks for
     *    the settings map — which is a lot of places, some of which log what they
     *    were given.
     *
     * MUTATION NOTE. Set `'autoload' => true` in InstagramCredentials::put() and the
     * last two expectations are red: both credentials appear in Setting::map().
     * RUN: red on both.
     */
    igConnected();

    $secretRow = Setting::query()->find(InstagramCredentials::SECRET_KEY);
    $tokenRow = Setting::query()->find(InstagramCredentials::TOKEN_KEY);

    expect($secretRow->value)->not->toBe('abcdef0123456789abcdef0123456789')
        ->and($tokenRow->value)->not->toBe('a-very-long-lived-token')
        ->and(InstagramCredentials::secret())->toBe('abcdef0123456789abcdef0123456789')
        ->and(InstagramCredentials::token())->toBe('a-very-long-lived-token');

    /*
     * ── AND IT IS SettingsService::all() THAT autoload GOVERNS, NOT Setting::map() ─
     *
     * Found by running this: the first draft asserted against `Setting::map()` and
     * was RED, because that method reads EVERY row — `Setting::query()->get(...)`
     * with no `where('autoload', true)` — so the ciphertext is in it whatever
     * `autoload` says. The two snapshots are genuinely different things and only one
     * of them is what `autoload` is for:
     *
     *   SettingsService::all()  `where('autoload', true)`. The map handed to
     *                           anything that asks for "the settings", which is a
     *                           lot of places and some of them log what they were
     *                           given. THIS is the one a credential must stay out
     *                           of, and it does.
     *   Setting::map()          every row, used by readers that want one key by
     *                           name. The ciphertext is in it, and that is
     *                           acceptable precisely because it is ciphertext —
     *                           which is the reason the encryption is not
     *                           decoration on top of the autoload flag but the
     *                           actual protection.
     *
     * InstagramCredentials::put()'s own docblock names SettingsService::all() for
     * this reason; the assertion now matches the claim rather than a stronger one
     * nothing in this app makes.
     */
    Setting::flushMap();
    Cache::forget('kbb.settings');
    SettingsService::forgetMemo();

    $autoloaded = app(SettingsService::class)->all();

    expect(array_key_exists(InstagramCredentials::SECRET_KEY, $autoloaded))->toBeFalse()
        ->and(array_key_exists(InstagramCredentials::TOKEN_KEY, $autoloaded))->toBeFalse()
        // ...and what IS in the all-rows snapshot is the ciphertext, never the plain
        // value. Asserted so the paragraph above is a checked claim.
        ->and(in_array('abcdef0123456789abcdef0123456789', array_values(Setting::map()), true))->toBeFalse()
        ->and(in_array('a-very-long-lived-token', array_values(Setting::map()), true))->toBeFalse();
});

it('reads a credential as “nothing configured” rather than throwing when it cannot be decrypted', function () {
    /*
     * An APP_KEY rotation makes every ciphertext in the database unreadable. Throwing
     * would take out every screen that asks whether Instagram is connected —
     * INCLUDING THE ONE THE OWNER HAS TO USE TO FIX IT. Degrading to null greys the
     * buttons and leaves the shop showing the posts it already has.
     *
     * MUTATION NOTE. Remove the try/catch from InstagramCredentials::plain() and
     * this case is red with a DecryptException. RUN: red.
     */
    Setting::query()->updateOrCreate(
        ['key' => InstagramCredentials::TOKEN_KEY],
        ['value' => 'this-is-not-a-ciphertext', 'autoload' => false],
    );
    Setting::flushMap();

    expect(InstagramCredentials::token())->toBeNull()
        ->and(InstagramCredentials::hasToken())->toBeFalse();
});

it('keeps the stored secret when the box is submitted empty', function () {
    /*
     * The screen draws the secret box EMPTY with a placeholder, because asterisks
     * disclose the length. An empty box therefore already means "unchanged", so it
     * cannot also mean "delete" — forgetAll() is the explicit way to remove one.
     *
     * MUTATION NOTE. Drop the `if ($secret !== '')` guard in
     * InstagramCredentials::saveApp() and this is red: correcting a typo in the app
     * id wipes the secret. RUN: red.
     */
    igConnected();

    InstagramCredentials::saveApp('9999999999999999', null);

    expect(InstagramCredentials::appId())->toBe('9999999999999999')
        ->and(InstagramCredentials::secret())->toBe('abcdef0123456789abcdef0123456789');
});

it('clamps a nonsense expiry rather than trusting it', function () {
    /*
     * A remote server answering `expires_in: 0` would otherwise mark a perfectly
     * good token as expired the instant it arrived; one answering a year would let
     * it lapse unrefreshed, and a lapsed token can only be repaired by the full
     * authorisation again.
     */
    InstagramCredentials::saveToken('t', 0);
    expect(InstagramCredentials::daysLeft())->toBeGreaterThanOrEqual(0)
        ->and(InstagramCredentials::expired())->toBeFalse();

    InstagramCredentials::saveToken('t', 365 * 86400);
    expect(InstagramCredentials::daysLeft())->toBeLessThanOrEqual(90);
});

/* ─────────────────────────────────── the fetch ───────────────────────────── */

/**
 * The two Graph answers a happy fetch gets, as Meta documents their shape.
 *
 * ── Http::fake() MERGES, AND THAT MADE A TEST PASS AGAINST NOTHING ──────────
 *
 * `Http::fake()` does not REPLACE the stubs already registered — it merges into
 * them, and the FIRST matching stub wins. So a test that fakes one answer, runs,
 * then fakes a different answer for the same URL and runs again gets THE FIRST
 * ANSWER BOTH TIMES, silently.
 *
 * That is not hypothetical here. `it writes a null for a count Instagram did not
 * send` is built on exactly that shape — fetch with a count, fetch again without
 * one — and it was passing while the second fetch still saw `like_count: 120`. It
 * was GREEN under a mutation that replaced the whole `array_key_exists` guard with
 * `??`, which is the defect the case exists to catch, and that is how it was
 * found: the test asserted the right thing about a scenario it never reached.
 *
 * `Http::clearResolvedInstances()` drops the faked factory out of the container so
 * the next `Http::fake()` starts from an empty stub list. Every caller here goes
 * through this function, so the trap cannot come back one test at a time.
 */
function igFakeGraph(array $media, array $profile = []): void
{
    /*
     * See the docblock. BOTH lines are needed and the second is the one that does
     * the work: the client factory is a container SINGLETON, so clearing the facade's
     * own resolved instance hands back the very same factory with the very same
     * stubs still on it. Forgetting the binding is what makes the next fake start
     * from an empty list.
     */
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();

    Http::fake([
        'graph.instagram.com/*/me/media*' => Http::response(['data' => $media]),
        'graph.instagram.com/*/me*' => Http::response(array_merge([
            'id' => '17841400000000000',
            'username' => 'kbeauty.bliss',
            'name' => 'K-Beauty Bliss',
            'account_type' => 'BUSINESS',
            'profile_picture_url' => 'https://scontent.cdninstagram.com/avatar.jpg?token=expires-soon',
            'followers_count' => 12345,
            'media_count' => 148,
        ], $profile)),
        'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-token', 'expires_in' => 5184000]),
        'graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'fresher', 'expires_in' => 5184000]),
        'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short', 'user_id' => '17841400000000000']),
        // Every image download. A real 1x1 PNG, because storeImage() decides what a
        // file is from getimagesizefromstring() on the BYTES rather than from the
        // URL — so a fake returning 'x' would be refused, correctly, and the test
        // would be asserting the refusal path while claiming to assert the happy one.
        '*' => Http::response(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AARAADAP8A/wH0lH8AAAAASUVORK5CYII='
        ), 200, ['Content-Type' => 'image/png']),
    ]);
}

it('writes a null for a count Instagram did not send, and leaves an old one alone', function () {
    /*
     * ── docs/UGC-ENGAGEMENT.md'S RULE, APPLIED TO A WRITE ───────────────────
     *
     * Instagram OMITS `like_count` entirely when the post's owner has hidden likes
     * on it. A `?? null` would then overwrite yesterday's real figure with "we do
     * not know", on every refresh, for a reason that has nothing to do with the
     * number having changed — and the shop would print nothing under a reel it had
     * a true count for an hour ago.
     *
     * So: `array_key_exists` and not `??`. A key that is ABSENT leaves the column
     * as it was; a key that is present and null writes null.
     *
     * MUTATION NOTE. Change the two `array_key_exists(...)` guards in
     * InstagramSync::run() to `$row['like_count'] ?? null` and this is red: the
     * second fetch nulls the 120 likes the first one stored. RUN: red.
     */
    igConnected();
    igFakeGraph([[
        'id' => 'm1', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/AAAA1111/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
        'like_count' => 120, 'comments_count' => 3,
    ]]);

    app(InstagramSync::class)->run();

    expect(InstagramPost::query()->where('remote_id', 'm1')->value('like_count'))->toBe(120);

    // The same post, refreshed, with the count no longer offered.
    igFakeGraph([[
        'id' => 'm1', 'media_type' => 'IMAGE', 'permalink' => 'https://www.instagram.com/p/AAAA1111/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
        'comments_count' => 3,
    ]]);

    app(InstagramSync::class)->run();

    expect(InstagramPost::query()->where('remote_id', 'm1')->value('like_count'))->toBe(120);
});

it('tells a personal account apart from a refusal, and says what to do about it', function () {
    /*
     * A personal account returns nothing useful from any of these endpoints at any
     * price (docs/IG-PROFILE.md §1), and the SYMPTOM is an empty grid behind a
     * successful fetch — which reads exactly like a bug in this shop. The account
     * type is therefore checked and named, with a remedy that takes two minutes in
     * the phone app.
     *
     * MUTATION NOTE. Delete the `if ($type === 'PERSONAL')` arm from
     * InstagramSync::run() and this is red: ok is true and the reason is absent.
     * RUN: red.
     */
    igConnected();
    igFakeGraph([], ['account_type' => 'PERSONAL']);

    $result = app(InstagramSync::class)->run();

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toBe('not_professional')
        ->and(str_contains(strtolower($result['detail']), 'professional'))->toBeTrue();
});

it('stores the sixty-day token and not the one-hour one', function () {
    /*
     * ── THE ORDERING THAT MATTERS MOST IN THIS FEATURE ──────────────────────
     *
     * Meta's flow is two exchanges: the code becomes a ONE-HOUR token, and that
     * becomes a SIXTY-DAY one. Storing the first on the way past leaves a shop that
     * reports itself connected and stops working an hour later with no explanation.
     *
     * ── AND THIS CASE IS HONEST ABOUT WHAT IT CAN SEE ──────────────────────
     *
     * It was called "never stores the short-lived token, even for a moment" and
     * that name was a claim the assertion cannot make: it reads the END state, so a
     * connect() that stored the short token and then overwrote it a line later
     * would pass. RUN as a mutation — saveToken(short) inserted before the
     * exchange — and it was GREEN, correctly, because the final value really is the
     * long one. Renamed to what it checks rather than strengthened, because the
     * transient write is not the defect: the defect is a shop that reports itself
     * connected and stops working an hour later, and that is exactly the end state.
     *
     * MUTATION NOTE. Delete the exchangeForLongLived() call and store
     * $short['data']['access_token'] instead, and this is red: the stored token is
     * 'short'. RUN: red.
     */
    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');
    igFakeGraph([]);

    app(InstagramSync::class)->connect('an-authorisation-code');

    expect(InstagramCredentials::token())->toBe('long-token');
});

it('keeps the connection when the first fetch fails, rather than sending him round again', function () {
    /*
     * If the token exchange succeeded THE SHOP IS CONNECTED, and throwing the token
     * away because the first media call was unlucky — a rate limit, a timeout —
     * sends the owner back through the whole authorisation for something the Refresh
     * button fixes.
     *
     * MUTATION NOTE. Make connect() return ok=false when run() fails and this is
     * red: hasToken() is still true but the screen would draw "Configure now" and
     * the owner would reconnect for nothing. RUN: red on the first expectation.
     */
    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');

    Http::fake([
        'api.instagram.com/oauth/access_token' => Http::response(['access_token' => 'short', 'user_id' => '1']),
        'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-token', 'expires_in' => 5184000]),
        // The profile call is the one that fails.
        'graph.instagram.com/*' => Http::response(['error' => ['message' => 'Application request limit reached']], 429),
    ]);

    $answer = app(InstagramSync::class)->connect('code');

    expect($answer['ok'])->toBeTrue()
        ->and(InstagramCredentials::hasToken())->toBeTrue()
        ->and(str_contains($answer['message'], 'Refresh posts'))->toBeTrue();
});

it('refuses a permalink that is not on instagram.com', function () {
    /*
     * The permalink becomes an `href` on the shop. UgcPath::link() answers "is this
     * a safe URL", which a `https://evil.example/p/x/` passes — so the HOST is
     * checked as well, in InstagramSync, because a link this shop presents as an
     * Instagram post has to be one.
     *
     * MUTATION NOTE. Remove the host check from InstagramSync::permalink() and this
     * is red: the row stores the attacker's URL and the tile links to it. RUN: red.
     */
    igConnected();
    igFakeGraph([[
        'id' => 'evil', 'media_type' => 'IMAGE', 'permalink' => 'https://evil.example/p/AAAA1111/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
    ]]);

    app(InstagramSync::class)->run();

    expect(InstagramPost::query()->where('remote_id', 'evil')->value('permalink'))->toBeNull();
});

it('names the file from a hash and not from anything Meta sent', function () {
    /*
     * A remote id is never a filename. sha1() of it is forty hex characters and
     * cannot contain a slash, a dot or a NUL, whatever Meta decides an id looks like
     * next year.
     *
     * MUTATION NOTE. Make IgPath::fileName() return the remote id itself and this is
     * red — and on a real traversal id it would be worse than red.
     */
    igConnected();
    igFakeGraph([[
        'id' => '../../../etc/passwd', 'media_type' => 'IMAGE',
        'permalink' => 'https://www.instagram.com/p/AAAA2222/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/a.jpg',
    ]]);

    app(InstagramSync::class)->run();

    $path = (string) InstagramPost::query()->where('remote_id', '../../../etc/passwd')->value('local_path');

    expect(str_contains($path, '..'))->toBeFalse()
        ->and(\App\Services\Instagram\IgPath::stored($path))->toBe($path);
});

/* ─────────────────────────── the section on the shop ─────────────────────── */

it('renders nothing at all with the module off, and makes no query doing it', function () {
    /*
     * ── RULE 1, AND RULE 4'S "A FEATURE THAT SHIPS OFF MUST COST NOTHING" ───
     *
     * This is the state every shop is in the day the package applies. The shortcode
     * returns the empty string, so a page that already carries [kbb_instagram] is
     * byte-identical — and it gets there WITHOUT A QUERY, because
     * Shortcodes::instagram() reads the module switch (a warmed snapshot) before it
     * resolves InstagramFeed at all.
     *
     * MUTATION NOTE, AND IT IS NOT THE OBVIOUS ONE. Removing the `enabled()` guard
     * from Shortcodes::instagram() alone is GREEN — RUN, and it is — because
     * InstagramFeed::section() checks the switch too and returns before any query.
     * That is the second lock doing its job, and it is why the shortcode's own
     * guard is described in its docblock as the CHEAP one rather than the load-
     * bearing one. The mutation that makes this red is removing BOTH: take the
     * `enabled()` check out of InstagramFeed::section() as well and the count is 1.
     * RUN: green on the first alone, red on the pair.
     */
    igModule(false);
    igPost();

    /*
     * ── THE SNAPSHOT IS WARMED FIRST, AND THAT IS NOT CHEATING ──────────────
     *
     * `moduleEnabled()` reads ONE cached snapshot of the whole module_toggles
     * table, and building it costs one SELECT. On the shop that SELECT has already
     * happened long before this section is reached: the homepage reads the module
     * switches to decide what to draw, and every storefront page reads them for the
     * header. Counting a cold cache here would be measuring the snapshot's first
     * build and calling it this feature's cost.
     *
     * What is being asserted is the thing that IS this feature's cost: with the
     * module off it adds NOTHING — no posts query, no module_settings read, no
     * profile read. Zero, not "one fewer than when it is on".
     */
    app(InstagramSettings::class)->enabled();

    $queries = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$queries) { $queries++; });

    expect(Shortcodes::render('[kbb_instagram]'))->toBe('');
    expect($queries)->toBe(0);
});

it('renders nothing when the module is on but nothing has been fetched', function () {
    /*
     * The second inert state, and it is the one an owner reaches by turning the
     * switch on before connecting. A shortcode that cannot resolve must not leave
     * "[kbb_instagram]" in a published page for a shopper to read, and must not print
     * an error either — the storefront is not where a configuration problem is
     * reported. Content → Instagram is, and it says which step is outstanding.
     */
    igModule(true);

    expect(Shortcodes::render('[kbb_instagram]'))->toBe('');
});

it('draws no tile for a post whose picture never downloaded', function () {
    /*
     * A tile with no poster is a hole in the page at first paint — the rule UgcRail
     * applies to a clip with no poster, for the same reason. Applied as a SCOPE
     * rather than a filter afterwards, so the LIMIT counts rows that will be drawn:
     * filtering afterwards is how a "9 posts" setting renders six.
     *
     * ── MUTATION NOTE, AND WHAT RUNNING IT ACTUALLY SHOWED ─────────────────
     *
     * Removing `->drawable()` alone is GREEN. Removing the `->filter()` alone is
     * GREEN TOO. Both were run and both were green, and the honest reading is that
     * EITHER guard is sufficient for this case on its own — the scope stops the row
     * being selected, and the filter stops a selected row being drawn. Only
     * removing both turns this red. RUN: green, green, red.
     *
     * That is defence in depth rather than a weak test, and the two are kept because
     * they fail differently on a question this case does NOT ask: the scope is what
     * makes the LIMIT count rows that will be drawn, so without it a "9 posts"
     * setting renders six the week two thumbnails fail — a defect no assertion about
     * two rows can see. The filter is what catches a row whose stored path fails
     * IgPath::stored() on the way out, which the scope cannot know about because the
     * column is not null.
     */
    igModule(true);
    igPost(['remote_id' => 'has-picture']);
    InstagramPost::query()->create([
        'remote_id' => 'no-picture',
        'media_type' => 'IMAGE',
        'permalink' => 'https://www.instagram.com/p/BBBB1111/',
        'shortcode' => 'BBBB1111',
        'local_path' => null,
        'posted_at' => now(),
    ]);

    $html = Shortcodes::render('[kbb_instagram]');

    expect(substr_count($html, '<img'))->toBe(1)
        ->and(str_contains($html, 'BBBB1111'))->toBeFalse();
});

it('escapes everything Instagram sent, in every one of the five layouts', function () {
    /*
     * ── THE ASSERTION THAT MATTERS MOST ON THIS FEATURE ─────────────────────
     *
     * Every string on that section is REMOTE USER INPUT: the caption is whatever was
     * typed into Instagram, and so is the username. Run over all five layouts,
     * because "one of the five layouts is the one nobody reviews" is the reason
     * InstagramSettings keeps the tile markup identical across them — and this is
     * the assertion that proves it stayed identical.
     *
     * MUTATION NOTE. Change `{{ $tile['caption'] }}` in instagram/section.blade.php
     * to `{!! $tile['caption'] !!}` and this is red in all five. RUN: red x5.
     */
    $nasty = '</script><img src=x onerror=alert(1)>';

    foreach (array_keys(InstagramSettings::LAYOUTS) as $layout) {
        igModule(true, ['layout' => $layout, 'caption' => true, 'profile_style' => 'card']);
        InstagramPost::query()->delete();
        igPost(['remote_id' => 'x-'.$layout, 'caption' => $nasty]);

        app(InstagramSettings::class)->saveProfile([
            'username' => 'kbeauty.bliss',
            'name' => $nasty,
            'avatar' => null,
            'followers' => 10,
            'posts' => 2,
        ]);

        InstagramFeed::flush();
        Cache::flush();

        $html = Shortcodes::render('[kbb_instagram]');

        expect($html)->not->toBe('');

        /*
         * ── ASSERTED ON THE DANGEROUS SHAPE, NOT ON THE WORDS ───────────────
         *
         * The first draft of this case also asserted that 'onerror=alert' was
         * absent, and it was RED against correctly escaped output: html escaping
         * touches < > & " ', and leaves `onerror=alert(1)` sitting in the page as
         * the harmless text inside `&lt;img src=x onerror=alert(1)&gt;`. An
         * assertion that a safe page fails is worse than no assertion, because the
         * way to green it is to weaken the thing it was guarding.
         *
         * So what is checked is the only thing that matters: that the angle
         * brackets did not survive as markup. `</script><img` is the payload that
         * would break out of the script block the assets file emits, and it is the
         * reason the caption is that particular string.
         */
        expect(str_contains($html, '</script><img'))->toBeFalse("layout {$layout} printed the caption raw");
        expect(str_contains($html, '<img src=x'))->toBeFalse("layout {$layout} printed the caption raw");
        expect(str_contains($html, '&lt;img src=x'))->toBeTrue("layout {$layout} did not escape the caption");
        // The class is read from the MARKUP and not from the stylesheet beside it —
        // `.is-grid` is a selector in that stylesheet, so a bare 'is-grid' would be
        // found whichever layout was chosen. The trailing quote is what makes this
        // an attribute value.
        expect(str_contains($html, 'igp-t is-'.$layout.'"'))->toBeTrue("layout {$layout} did not reach the markup");
    }
});

it('draws no follow link at all when the stored username is not a real handle', function () {
    /*
     * The Follow button's href is built from the USERNAME, so the username is
     * matched against Instagram's own handle alphabet first — and a value that fails
     * produces NO ELEMENT rather than a link to something assembled out of it. Rule
     * 5: anything printed unescaped is a constant, and the only way to keep that true
     * of a URL with a variable in it is to make the variable unable to carry anything
     * else.
     *
     * MUTATION NOTE. Drop the preg_match in InstagramFeed::profileBox() and this is
     * red: the href carries the injected path. RUN: red.
     */
    igModule(true, ['profile_style' => 'card']);
    igPost();

    app(InstagramSettings::class)->saveProfile([
        'username' => 'not a handle/../evil',
        'name' => 'Whoever',
        'avatar' => null,
        'followers' => 1,
        'posts' => 1,
    ]);

    InstagramFeed::flush();
    Cache::flush();

    $html = Shortcodes::render('[kbb_instagram]');

    /*
     * `class="igp-pf"` and not `igp-pf`: the stylesheet the section emits carries
     * `.igp-pf{...}` as a selector, so the bare class name is present on every page
     * that draws this section whether the button is there or not. The first draft
     * asserted the bare name and was red against correct output — an assertion
     * reading the stylesheet while claiming to read the markup.
     */
    expect(str_contains($html, 'evil'))->toBeFalse()
        ->and(str_contains($html, 'class="igp-pf"'))->toBeFalse();
});

it('builds the embed URL from the shortcode characters and never from the permalink', function () {
    /*
     * The embed URL becomes an iframe `src`. It is assembled from `shortcode` only
     * after that has matched InstagramPost::SHORTCODE_RE, so not one byte of a remote
     * string reaches that attribute. A shortcode that fails the pattern produces NO
     * embed, and the tile falls back to the permalink — which is the shipped
     * behaviour anyway.
     *
     * MUTATION NOTE. Remove the SHORTCODE_RE check from InstagramPost::toTile() and
     * this is red: the embed URL carries the quote and closes the attribute. RUN: red.
     */
    $bad = igPost(['remote_id' => 'bad-code', 'shortcode' => 'ab"cd onload=x']);
    $good = igPost(['remote_id' => 'good-code', 'shortcode' => 'AbCd_1-2']);

    expect($bad->toTile()['embed'])->toBeNull()
        ->and($good->toTile()['embed'])->toBe('https://www.instagram.com/p/AbCd_1-2/embed/captioned/');
});

it('draws a real zero and draws nothing at all for a count it does not have', function () {
    /*
     * ── docs/UGC-ENGAGEMENT.md'S RULE, WHICH THIS PROJECT HAS ALREADY PAID FOR ─
     *
     * "A NUMBER THIS SHOP CANNOT VERIFY IS NOT PRINTED. Not zero. Not a dash where a
     * figure should be. The element is not drawn at all." And pointed the other way:
     * "A reel posted an hour ago genuinely has zero comments. Hiding an honest zero
     * is the same defect pointed the other way."
     *
     * So this asserts BOTH directions in one case, because a truthiness test passes
     * one of them and fails the other and a reader cannot tell which by looking.
     *
     * MUTATION NOTE. Change the two `!== null` tests in instagram/section.blade.php
     * to truthy tests and the honest zero disappears — red on the third expectation.
     * Change InstagramPost::toTile()'s `likes` to `(int) $this->like_count` and the
     * unknown count becomes "0" — red on the second. RUN: red on each.
     */
    igModule(true, ['counts' => true]);

    igPost(['remote_id' => 'unknown-counts', 'like_count' => null, 'comments_count' => null,
        'posted_at' => now()->subDay()]);
    igPost(['remote_id' => 'honest-zero', 'like_count' => 0, 'comments_count' => 0,
        'posted_at' => now()]);

    $html = Shortcodes::render('[kbb_instagram]');

    /*
     * Counted on the ATTRIBUTE and not on the class name: the stylesheet emitted
     * beside the markup carries `.igp-m`, `.igp-m span` and `.igp-m svg` as
     * selectors, so a substr_count of the bare name reads 4 for one element. The
     * first draft of this case did exactly that and was red at 4 against 1.
     *
     * Two tiles, and exactly ONE metrics element between them — the post with the
     * honest zero has one and the post with no numbers at all has none.
     */
    expect(substr_count($html, 'class="igp-c'))->toBe(2)
        ->and(substr_count($html, 'class="igp-m"'))->toBe(1)
        /*
         * BOTH numbers, and that is not belt-and-braces — it is the whole case.
         *
         * The first draft asserted only that "0" appeared somewhere in the one
         * metrics element, and went GREEN under the mutation it names below:
         * changing the LIKES test to a truthy one hides the like count, but the
         * comment count is still 0 and still drawn, so a "0" was still present and
         * the element still existed. The assertion could not tell which of the two
         * numbers it was looking at.
         *
         * Two `igp-sr` labels is one like figure AND one comment figure — the
         * screen-reader word that follows each number — so this counts them.
         */
        ->and(substr_count($html, '<span class="igp-sr">'))->toBe(2)
        // The honest zero, printed as a zero. `>0<` is the number's own text node.
        /*
         * The honest zero. Written as the number followed by the screen-reader span
         * rather than as '>0<': the template puts the heart SVG, a newline and the
         * template's own indentation between the '>' and the figure, so '>0<' is
         * absent from correct output — which the first draft of this line asserted
         * and was red for. This matches what the page really contains.
         */
        ->and(str_contains($html, '0<span class="igp-sr">'))->toBeTrue();
});

it('ships every appearance setting at a value that draws Instagram’s own grid', function () {
    /*
     * Rule 1: a NEW setting ships at the value the page already has, so applying the
     * package moves nothing until somebody moves a slider. There is no previous page
     * here — the section is new — so the standard is Instagram's own grid, which is
     * what the owner asked for ("a nice grid type instagram section").
     *
     * `tap` is the one worth reading twice: it ships at `permalink` and NOT at
     * `embed`, even though `embed` is the "playable on our site" the owner asked
     * for, because the embed is an iframe on www.instagram.com and
     * ContentSecurityPolicy's frame-src lists only Stripe. The policy is report-only
     * today, so the embed works right now and files a violation — and shipping a
     * default that the round which ENFORCES the policy silently kills is worse than
     * shipping the floor and saying how to raise it. docs/IG-PROFILE.md §3 has the
     * exact one-line diff.
     */
    expect(app(InstagramSettings::class)->all())->toBe([
        'layout' => 'grid',
        'profile_style' => 'card',
        'posts' => 9,
        'gap' => 8,
        'radius' => 10,
        'heading' => 'Follow us on Instagram',
        'counts' => true,
        'caption' => false,
        'play_badge' => true,
        'tap' => 'permalink',
    ]);
});

it('drops a shortcode attribute that is not one of the screen’s own options', function () {
    /*
     * Rule 5's "a select stores one of its own options or the default", applied to a
     * shortcode ATTRIBUTE — which is a place a value arrives from outside just as
     * much as a POST body is, because an author types it.
     *
     * ── MUTATION NOTE, AND THE THIRD LOCK NOBODY COUNTED ───────────────────
     *
     * Dropping the `isset(InstagramSettings::LAYOUTS[...])` guard in
     * Shortcodes::instagram() alone is GREEN: instagram/section.blade.php re-checks
     * the layout against the same constant and falls back to `grid`. Dropping BOTH
     * was green too on the first run — and that was this assertion's fault rather
     * than the code's, because it read `is-grid`, a string the emitted STYLESHEET
     * contains as a selector whatever the layout is. Read off the class attribute
     * instead, both-guards-removed is red. RUN: green on either alone, red on both.
     *
     * Worth noting what is still true with all of it gone: Blade's `{{ }}` escapes
     * the value, so the injected markup is inert even then. Three locks on one
     * value, and the case asserts against the outermost two because escaping is not
     * a validation strategy — a value that is merely escaped is still a class
     * attribute this shop never meant to emit.
     */
    igModule(true);
    igPost();

    $html = Shortcodes::render('[kbb_instagram layout="\"><script>alert(1)</script>" limit="900"]');

    /*
     * READ OFF THE CLASS ATTRIBUTE, not off the word. `is-grid` on its own is in the
     * stylesheet this section emits as the selector `.igp-t.is-grid`, so the first
     * draft of this line was true whatever the layout resolved to — which is how it
     * stayed GREEN with BOTH allowlists removed. The attribute's exact value is the
     * thing that can actually be wrong.
     */
    expect(str_contains($html, '<script>alert'))->toBeFalse()
        ->and(str_contains($html, 'class="igp-t is-grid"'))->toBeTrue();
});

it('emits its stylesheet and its script exactly once for two sections on one page', function () {
    /*
     * ── AND @once DOES NOT DO THIS ──────────────────────────────────────────
     *
     * Blade's @once is scoped to a RENDER CYCLE, and every section on a page is its
     * own view(...)->render() call from Shortcodes — so the counter is back at zero
     * by the time the second one starts and the directive fires again. Two sections
     * on one page shipped the CSS twice and, worse, the SCRIPT twice; that script
     * registers a delegated document click listener, so a second copy opens the
     * lightbox twice on one tap.
     *
     * MUTATION NOTE. Replace the container flag in Shortcodes::instagram() with
     * Blade's @once inside instagram/section.blade.php and this is red with 2 and 2.
     * RUN: red.
     */
    igModule(true);
    igPost();

    $html = Shortcodes::render('[kbb_instagram] and then [kbb_instagram layout="strip"]');

    expect(substr_count($html, 'id="kbb-ig-style"'))->toBe(1)
        ->and(substr_count($html, 'id="kbb-ig-script"'))->toBe(1)
        // ...and both sections really did render.
        ->and(substr_count($html, 'class="igp"'))->toBe(2);
});

/* ─────────────────────────────── the admin screen ────────────────────────── */

it('returns no credential on the screen’s own payload', function () {
    /*
     * ── THE LEAK THAT WOULD MATTER MOST ─────────────────────────────────────
     *
     * The app secret and the sixty-day token must never reach the browser, a log or
     * an error. This asserts it on the payload BY VALUE rather than by key name,
     * because a key rename is exactly how a leak survives a test that reads keys.
     *
     * MUTATION NOTE. Add `'secret' => InstagramCredentials::secret()` to
     * InstagramController::connection() and this is red. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    igConnected();
    $this->actingAs(igAdmin(), 'admin');

    $raw = $this->getJson('/admin-api/instagram')->assertOk()->getContent();

    expect(str_contains($raw, 'abcdef0123456789abcdef0123456789'))->toBeFalse()
        ->and(str_contains($raw, 'a-very-long-lived-token'))->toBeFalse()
        // ...and it does say whether one is stored, which is what the screen needs.
        ->and($this->getJson('/admin-api/instagram')->json('connection.secret_saved'))->toBeTrue()
        ->and($this->getJson('/admin-api/instagram')->json('connection.connected'))->toBeTrue();
});

it('never puts an Instagram credential on the public settings endpoint', function () {
    /*
     * `/api/*` is UNAUTHENTICATED (CLAUDE.md), and SettingController::PUBLIC_KEYS is
     * a strict allowlist — so a new key cannot leak by being forgotten. This asserts
     * it anyway, by name, in the shape BilingualFoundationTest already uses for the
     * translation key: the allowlist is the mechanism, and a test naming these five
     * keys is what stops somebody "fixing" the screen by adding one to it.
     *
     * MUTATION NOTE. Add 'instagram_app_secret' to PUBLIC_KEYS and this is red.
     * RUN: red.
     */
    igConnected();
    Setting::flushMap();

    $body = $this->getJson('/api/settings')->assertOk()->json();

    foreach (InstagramCredentials::ALL_KEYS as $key) {
        expect(array_key_exists($key, $body))->toBeFalse("{$key} is on the public settings endpoint");
    }
});

it('refuses an app id or secret that could not be one, before anything leaves the server', function () {
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igAdmin(), 'admin');

    $this->postJson('/admin-api/instagram/app', ['app_id' => 'not-a-number', 'app_secret' => str_repeat('a', 32)])
        ->assertStatus(422);

    $this->postJson('/admin-api/instagram/app', ['app_id' => '1234567890123456', 'app_secret' => 'short'])
        ->assertStatus(422);

    // The first save genuinely needs a secret; an empty box cannot mean "keep" when
    // there is nothing to keep.
    $this->postJson('/admin-api/instagram/app', ['app_id' => '1234567890123456', 'app_secret' => ''])
        ->assertStatus(422);

    $this->postJson('/admin-api/instagram/app', [
        'app_id' => '1234567890123456', 'app_secret' => str_repeat('a', 32),
    ])->assertOk();

    expect(InstagramCredentials::hasSecret())->toBeTrue();
});

it('keeps the posts when the shop is disconnected, and only clears them when asked', function () {
    /*
     * Disconnecting revokes ACCESS; clearing throws away CONTENT. Rolling them into
     * one button is how an owner who wanted to change Meta apps finds his homepage
     * section blank — so they are two acts with two sentences, and this is the
     * assertion that keeps them apart.
     *
     * MUTATION NOTE. Make InstagramController::disconnect() always call
     * forgetEverything() and the second expectation is red. RUN: red.
     */
    InstagramAdminRoutes::wire($this->app);
    igConnected();
    igPost();
    $this->actingAs(igAdmin(), 'admin');

    $this->deleteJson('/admin-api/instagram')->assertOk();

    expect(InstagramCredentials::hasToken())->toBeFalse()
        ->and(InstagramPost::query()->count())->toBe(1)
        // ...and the app registration survives, so reconnecting is one press.
        ->and(InstagramCredentials::hasAppId())->toBeTrue();

    $this->deleteJson('/admin-api/instagram?posts=1')->assertOk();

    expect(InstagramPost::query()->count())->toBe(0);
});

it('reports the six setup steps from what is saved, and marks the two it cannot see', function () {
    /*
     * A checklist a user ticks himself is a checklist that says "done" about the step
     * he skipped. The four observable steps are derived; the two this server cannot
     * see — the Meta dashboard's redirect URI and a tester invitation on a phone —
     * are marked unobservable rather than drawn as an empty tick that implies we
     * looked.
     */
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(igAdmin(), 'admin');

    $steps = $this->getJson('/admin-api/instagram')->assertOk()->json('connection.steps');

    expect(count($steps))->toBe(6);

    $byKey = collect($steps)->keyBy('key');

    expect($byKey['app']['done'])->toBeFalse()
        ->and($byKey['connect']['done'])->toBeFalse()
        ->and($byKey['redirect']['observable'])->toBeFalse()
        ->and($byKey['tester']['observable'])->toBeFalse();

    igConnected();

    $after = collect($this->getJson('/admin-api/instagram')->json('connection.steps'))->keyBy('key');

    expect($after['app']['done'])->toBeTrue()
        ->and($after['keys']['done'])->toBeTrue()
        ->and($after['connect']['done'])->toBeTrue();
});
