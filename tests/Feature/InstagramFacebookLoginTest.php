<?php

declare(strict_types=1);

use App\Mail\OwnerAppSecurityAlert;
use App\Models\AdminUser;
use App\Models\InstagramPost;
use App\Models\Setting;
use App\Services\Instagram\FacebookConnect;
use App\Services\Instagram\FacebookGraphClient;
use App\Services\Instagram\InstagramAuth;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramFeed;
use App\Services\InstagramSettings;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\Shortcodes;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InstagramAdminRoutes;

/**
 * Content → Instagram → "Connect with Facebook" (Lane IG2).
 *
 * The owner's Meta app offers "API setup with Facebook login" and NOT "API setup
 * with Instagram login", so the module gained a second route: Facebook Login for
 * Business → code → long-lived user token → /me/accounts → the Page whose
 * instagram_business_account is set → that Page's token → the IG user id. The
 * feed, the pictures and the shop section are the existing ones, fed through the
 * InstagramSource seam.
 *
 * EGRESS TO META IS BLOCKED FROM THIS CONTAINER, so every Graph answer below is
 * Http::fake()d in the shape Meta documents. Values are readable words, never
 * anything shaped like a real credential.
 */
const FB_APP = '1234567890123456';
const FB_SECRET = 'notarealsecretnotarealsecret0000';
const FB_CONFIG = '9876543210';
const FB_IG = '17841400000000001';
const FB_IG_TWO = '17841400000000002';

function fbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Fb '.$role,
        'email' => 'fb-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** A 1x1 PNG: storeImage() decides what a file is from its bytes. */
function fbPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AARAADAP8A/wH0lH8AAAAASUVORK5CYII=');
}

function fbMedia(): array
{
    return [
        ['id' => '9001', 'caption' => 'First post', 'media_type' => 'IMAGE', 'like_count' => 120, 'comments_count' => 4,
            'permalink' => 'https://www.instagram.com/p/AAAA1111/', 'timestamp' => '2026-09-01T10:00:00+0000',
            'media_url' => 'https://scontent.cdninstagram.com/one.jpg'],
        ['id' => '9002', 'caption' => 'A reel', 'media_type' => 'VIDEO', 'like_count' => 33, 'comments_count' => 2,
            'permalink' => 'https://www.instagram.com/reel/BBBB2222/', 'timestamp' => '2026-08-20T10:00:00+0000',
            'media_url' => 'https://scontent.cdninstagram.com/two.mp4',
            'thumbnail_url' => 'https://scontent.cdninstagram.com/two.jpg'],
    ];
}

/** The top-level names in a Graph `fields` list, nested braces skipped. */
function fbFields(string $fields): array
{
    return array_map('trim', explode(',', (string) preg_replace('/\{[^}]*\}/', '', $fields)));
}

function fbProfile(string $id = FB_IG): array
{
    return [
        'id' => $id, 'username' => 'kbeauty.bliss', 'name' => 'K-Beauty Bliss',
        'profile_picture_url' => 'https://scontent.cdninstagram.com/avatar.jpg',
        'followers_count' => 12345, 'media_count' => 148,
    ];
}

/**
 * Graph, as Meta answers it. `$pages` is /me/accounts' data; `$profileError`
 * makes the account read fail with Meta's error body.
 */
function fbFake(array $pages, ?array $profileError = null): void
{
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();

    Http::fake(function (HttpRequest $r) use ($pages, $profileError) {
        $parts = parse_url($r->url());
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $q);

        if ($host !== 'graph.facebook.com') {
            return Http::response(fbPng(), 200, ['Content-Type' => 'image/png']);
        }

        $v = '/'.FacebookGraphClient::VERSION;

        return match (true) {
            $path === $v.'/oauth/access_token' && isset($q['fb_exchange_token'])
                => Http::response(['access_token' => 'fb-long-user-token-words', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            $path === $v.'/oauth/access_token'
                => Http::response(['access_token' => 'fb-short-user-token-words', 'token_type' => 'bearer', 'expires_in' => 3600]),
            $path === $v.'/me/accounts' => Http::response(['data' => $pages]),
            $path === $v.'/debug_token'
                => Http::response(['data' => ['app_id' => FB_APP, 'type' => 'PAGE', 'is_valid' => true, 'expires_at' => 0]]),
            // Only the fields asked for, as Graph answers: a field dropped from
            // the request is a field missing from the row.
            str_ends_with($path, '/media') => Http::response(['data' => array_map(
                fn (array $row) => array_intersect_key($row, array_flip(fbFields((string) ($q['fields'] ?? '')))),
                fbMedia(),
            )]),
            str_ends_with($path, '/insights') => Http::response(['data' => []]),
            $profileError !== null => Http::response(['error' => $profileError], 400),
            default => Http::response(fbProfile(basename($path))),
        };
    });
}

function fbPage(string $id, string $name, string $token, ?string $ig, string $username = 'kbeauty.bliss'): array
{
    return array_filter([
        'id' => $id, 'name' => $name, 'access_token' => $token,
        'instagram_business_account' => $ig === null ? null : ['id' => $ig, 'username' => $username],
    ], fn ($v) => $v !== null);
}

/**
 * The section's markup. Its <style> and <script> blocks are printed once per
 * request (the first render carries them, later ones do not), so they are cut out of the
 * comparison; everything a shopper sees of the posts is what remains.
 */
function fbSection(): string
{
    InstagramFeed::flush();

    return trim((string) preg_replace(
        ['#<style id="kbb-ig-style">.*?</style>#s', '#<script id="kbb-ig-script">.*?</script>#s'],
        '',
        Shortcodes::render('[kbb_instagram]'),
    ));
}

function fbAppSaved(?string $config = FB_CONFIG): void
{
    InstagramCredentials::saveFacebookApp(FB_APP, FB_SECRET, $config);
}

/** Start the handshake as the browser does and return the state it minted. */
function fbStart($test): string
{
    $test->get('/admin-api/instagram/start?via=facebook')->assertRedirect();

    return (string) session(InstagramAuth::STATE_SESSION_KEY)['value'];
}

beforeEach(function () {
    @mkdir(\App\Services\Instagram\IgPath::directory(), 0775, true);
});

/* ───────────────────────────── the full chain ───────────────────────────── */

it('runs the whole chain from the code to the Page token to the IG id, and fetches the posts', function () {
    /*
     * The defect this guards: the Facebook route storing the wrong token. The
     * USER token works for /me/accounts and fails for the IG account's media on
     * the shop's next refresh, a week later, with nobody watching — the owner
     * would see "Connected" and an empty section. So the assertion is on WHICH
     * token is stored (the Page's, from /me/accounts), WHICH id (the
     * instagram_business_account's), and that the user token is NOT at rest.
     *
     * MUTATION NOTE. In FacebookConnect::finish() pass $userToken-shaped data —
     * e.g. change `$page['page_token']` to `$page['page_id']` in
     * saveFacebookConnection() — and the token expectation is red. Drop the
     * fb_exchange_token step in callback() and the "sent" expectation is red.
     */
    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    fbFake([fbPage('5550001', 'KBB Shop', 'fb-page-token-words', FB_IG)]);
    $this->actingAs(fbAdmin(), 'admin');

    $go = $this->get('/admin-api/instagram/start?via=facebook');
    $to = (string) $go->headers->get('Location');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $dialog);

    // The Facebook Login for Business dialog, with config_id and NOT scope.
    expect(str_starts_with($to, 'https://www.facebook.com/'.FacebookGraphClient::VERSION.'/dialog/oauth?'))->toBeTrue()
        ->and($dialog['config_id'] ?? null)->toBe(FB_CONFIG)
        ->and(array_key_exists('scope', $dialog))->toBeFalse()
        ->and($dialog['redirect_uri'])->toBe(InstagramAuth::redirectUri())
        ->and($dialog['client_id'])->toBe(FB_APP);

    $state = (string) session(InstagramAuth::STATE_SESSION_KEY)['value'];

    $this->get('/admin-api/instagram/callback?code=a-code&state='.$state)->assertRedirect();

    expect(InstagramCredentials::via())->toBe('facebook')
        ->and(InstagramCredentials::token())->toBe('fb-page-token-words')
        ->and(InstagramCredentials::userId())->toBe(FB_IG)
        ->and(InstagramCredentials::pageName())->toBe('KBB Shop')
        ->and(InstagramCredentials::expiresAt())->toBeNull()
        ->and(InstagramPost::query()->count())->toBe(2)
        ->and((int) InstagramPost::query()->where('remote_id', '9001')->value('like_count'))->toBe(120);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/access_token') && str_contains($r->url(), 'code=a-code'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'fb_exchange_token=fb-short-user-token-words'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/me/accounts') && str_contains($r->url(), 'access_token=fb-long-user-token-words'));
    // The account is read with the PAGE token, signed with appsecret_proof.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/'.FB_IG.'/media')
        && str_contains($r->url(), 'access_token=fb-page-token-words')
        && str_contains($r->url(), 'appsecret_proof='.hash_hmac('sha256', 'fb-page-token-words', FB_SECRET)));
    // Never the Instagram-login host.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'instagram.com/oauth') || str_contains($r->url(), 'graph.instagram.com'));

    // The user token is not at rest anywhere in settings, encrypted or not.
    foreach (Setting::query()->pluck('value') as $value) {
        $plain = $value;
        try {
            $plain = Crypt::decryptString((string) $value);
        } catch (\Throwable) {
        }
        expect(str_contains((string) $plain, 'fb-long-user-token-words'))->toBeFalse();
    }
});

it('sends scope instead of config_id when no configuration id is saved', function () {
    fbAppSaved(null);

    $url = InstagramAuth::facebookAuthorizeUrl()['url'];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    expect($q['scope'])->toBe(FacebookGraphClient::SCOPE)
        ->and(array_key_exists('config_id', $q))->toBeFalse();
});

/* ───────────────────────────────── the picker ───────────────────────────── */

it('offers a picker when several Pages have an Instagram account, and stores the one chosen', function () {
    /*
     * The defect: with two linked Pages the first one wins silently, and the
     * owner's shop shows the wrong brand's Instagram. And the second defect a
     * picker invites: trusting a page_id from the browser — so pick() asks Meta
     * again and takes the token Meta returned for that id.
     *
     * MUTATION NOTE. Change `count($linked) === 1` in FacebookConnect::decide()
     * to `count($linked) >= 1` and the "nothing stored yet" expectation is red.
     * Drop the hash_equals() match in pick() (take $pages['pages'][0]) and the
     * stored token is the first Page's, red.
     */
    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    fbFake([
        fbPage('5550001', 'KBB Shop', 'fb-page-token-one', FB_IG, 'kbeauty.bliss'),
        fbPage('5550002', 'KBB Outlet', 'fb-page-token-two', FB_IG_TWO, 'kbb.outlet'),
        fbPage('5550003', 'Unlinked Page', 'fb-page-token-three', null),
    ]);
    $this->actingAs(fbAdmin(), 'admin');

    $state = fbStart($this);
    $this->get('/admin-api/instagram/callback?code=a-code&state='.$state)->assertRedirect();

    expect(InstagramCredentials::hasToken())->toBeFalse();

    $raw = $this->getJson('/admin-api/instagram')->assertOk();
    $pending = $raw->json('connection.facebook.pending');

    expect($pending['state'])->toBe('pick')
        ->and(collect($pending['candidates'])->pluck('ig_username')->all())->toBe(['kbeauty.bliss', 'kbb.outlet'])
        // Names only: no Page token, no user token on the wire.
        ->and(str_contains($raw->getContent(), 'fb-page-token'))->toBeFalse()
        ->and(str_contains($raw->getContent(), 'fb-long-user-token-words'))->toBeFalse();

    // An id Meta did not return for this login is refused.
    $this->postJson('/admin-api/instagram/fb/pick', ['page_id' => '5550009'])->assertStatus(422);
    // The Page with no Instagram account cannot be chosen either.
    $this->postJson('/admin-api/instagram/fb/pick', ['page_id' => '5550003'])->assertStatus(422);

    $this->postJson('/admin-api/instagram/fb/pick', ['page_id' => '5550002'])
        ->assertOk()->assertJsonPath('state', 'connected');

    expect(InstagramCredentials::token())->toBe('fb-page-token-two')
        ->and(InstagramCredentials::userId())->toBe(FB_IG_TWO)
        ->and(InstagramCredentials::pageName())->toBe('KBB Outlet')
        ->and(session()->has(FacebookConnect::PENDING_KEY))->toBeFalse();
});

/* ─────────────────────────────── not linked ─────────────────────────────── */

it('says plainly when no Page has the Instagram account linked, and Check again connects once it is', function () {
    /*
     * The defect: a login that returns Pages but no instagram_business_account
     * used to look like success with nothing in it. It must say what to do, in
     * the Instagram app, and let him retry without logging in again.
     *
     * MUTATION NOTE. Make decide() return ['ok' => false] for an empty $linked and
     * the pending.state expectation is red (no pending is kept, so Check again has
     * nothing to check). Remove the "is not linked to a Facebook Page" sentence
     * from instagram-screen.blade.php and the screen expectation is red.
     */
    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    fbFake([fbPage('5550001', 'KBB Shop', 'fb-page-token-one', null)]);
    $this->actingAs(fbAdmin(), 'admin');

    $state = fbStart($this);
    $this->get('/admin-api/instagram/callback?code=a-code&state='.$state)->assertRedirect();

    $pending = $this->getJson('/admin-api/instagram')->json('connection.facebook.pending');

    expect($pending['state'])->toBe('unlinked')
        ->and($pending['pages'])->toBe(['KBB Shop'])
        ->and(InstagramCredentials::hasToken())->toBeFalse();

    $screen = file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php'));
    expect($screen)->toContain('is not linked to a Facebook Page</b> — \'')
        ->and($screen)->toContain('Instagram app → Settings → Accounts Center → connect your Page')
        ->and($screen)->toContain('data-igs-fbcheck');

    // Still not linked: Check again answers the same, without a new login.
    $this->postJson('/admin-api/instagram/fb/check')->assertOk()->assertJsonPath('state', 'unlinked');

    // He links it in the Instagram app; Check again now connects.
    fbFake([fbPage('5550001', 'KBB Shop', 'fb-page-token-one', FB_IG)]);
    $this->postJson('/admin-api/instagram/fb/check')->assertOk()->assertJsonPath('state', 'connected');

    expect(InstagramCredentials::token())->toBe('fb-page-token-one');
});

/* ─────────────────────────────────── state ──────────────────────────────── */

it('refuses a callback whose state does not match, before any call to Facebook', function () {
    /*
     * The callback is a GET that stores a token, so its CSRF defence is the
     * single-use state. A forged callback must exchange nothing and store
     * nothing — and a state from a minute ago must not be usable twice.
     *
     * MUTATION NOTE. Replace hash_equals() in InstagramAuth::consume() with
     * `true` and the first refusal is red: the forged code is exchanged.
     */
    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    fbFake([fbPage('5550001', 'KBB Shop', 'fb-page-token-one', FB_IG)]);
    $this->actingAs(fbAdmin(), 'admin');

    $state = fbStart($this);

    $this->get('/admin-api/instagram/callback?code=a-code&state=not-'.$state)->assertRedirect();
    Http::assertNothingSent();
    expect(InstagramCredentials::hasToken())->toBeFalse();

    // Spent by the refusal: the right state no longer works either.
    $this->get('/admin-api/instagram/callback?code=a-code&state='.$state)->assertRedirect();
    Http::assertNothingSent();

    // And an expired one is refused.
    $state = fbStart($this);
    session()->put(InstagramAuth::STATE_SESSION_KEY, [
        'value' => $state, 'issued_at' => time() - InstagramAuth::STATE_TTL_SECONDS - 5, 'via' => 'facebook',
    ]);
    $this->get('/admin-api/instagram/callback?code=a-code&state='.$state)->assertRedirect();
    Http::assertNothingSent();
    expect(InstagramCredentials::hasToken())->toBeFalse();
});

/* ─────────────────────────────── expiry ─────────────────────────────────── */

it('turns a 190 from Facebook into the Reconnect warning and one email', function () {
    /*
     * The defect: a token Meta stopped accepting (password changed, app removed)
     * looked like "Instagram could not be reached" and the shop quietly stopped
     * getting new posts. Error 190 must flag the connection invalid, tell the
     * owner once by email, and clear when he reconnects.
     *
     * MUTATION NOTE. Drop 190 from FacebookGraphClient::TOKEN_DEAD_CODES and the
     * reason expectation ('expired') is red. Remove the `notified` early return
     * in InstagramCredentials::markInvalid() and the "one email" expectation is
     * red (two refreshes, two emails).
     */
    Mail::fake();
    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    InstagramCredentials::saveFacebookConnection('fb-page-token-one', FB_IG, '5550001', 'KBB Shop');
    fbFake([], ['message' => 'Error validating access token: The session has been invalidated.', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460]);
    $owner = fbAdmin();
    $this->actingAs($owner, 'admin');

    $this->postJson('/admin-api/instagram/refresh')->assertStatus(422)
        ->assertJsonPath('reason', 'expired')
        ->assertJsonPath('connection.invalid', true);
    $this->postJson('/admin-api/instagram/refresh')->assertStatus(422);

    Mail::assertSent(OwnerAppSecurityAlert::class, 1);
    Mail::assertSent(OwnerAppSecurityAlert::class, fn ($m) => $m->hasTo($owner->email)
        && $m->subjectLine === 'Instagram needs reconnecting');

    $screen = file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php'));
    expect($screen)->toContain('data-igs-invalid')->and($screen)->toContain('>Reconnect</a>');

    // Reconnecting clears it.
    InstagramCredentials::saveFacebookConnection('fb-page-token-new', FB_IG, '5550001', 'KBB Shop');
    expect(InstagramCredentials::invalid())->toBeNull();
});

it('never calls the Instagram refresh endpoint with a Facebook Page token', function () {
    /*
     * MUTATION NOTE. Delete the viaFacebook() guard at the top of
     * InstagramSync::refreshTokenIfDue() and this is red: a Page token with a
     * near expiry is sent to graph.instagram.com/refresh_access_token.
     */
    fbAppSaved();
    InstagramCredentials::saveFacebookConnection('fb-page-token-one', FB_IG, '5550001', 'KBB Shop', time() + 3 * 86400);
    fbFake([]);

    app(InstagramSync::class)->refreshTokenIfDue();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.instagram.com'));
});

/* ─────────────────────────── same feed, either route ─────────────────────── */

it('renders the shop section identically whichever route fed it', function () {
    /*
     * "Do not fork the feed": the rows and the pictures written from
     * graph.facebook.com must be the rows and the pictures written from
     * graph.instagram.com, so the section is byte-identical. The rows are
     * scrambled between the two fetches, so a Facebook fetch that wrote nothing
     * (or wrote different fields) cannot pass by leaving the first fetch's rows
     * in place.
     *
     * MUTATION NOTE. In FacebookGraphClient::media() drop `like_count` from the
     * field list and the rows differ (the scrambled 0 stays). Make
     * InstagramSync::source() always return $this->client and the Facebook run
     * fails, red.
     */
    $service = app(SettingsService::class);
    $service->setModule(InstagramSettings::MODULE, true);
    SettingsService::forgetMemo();
    Setting::flushMap();
    Cache::flush();

    // 1. Instagram-login route.
    InstagramCredentials::saveApp(FB_APP, FB_SECRET);
    InstagramCredentials::saveToken('ig-long-token-words', 60 * 86400, FB_IG);

    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::fake([
        'graph.instagram.com/*/me/media*' => Http::response(['data' => fbMedia()]),
        'graph.instagram.com/*/me*' => Http::response(fbProfile() + ['account_type' => 'BUSINESS']),
        '*' => Http::response(fbPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    expect(app(InstagramSync::class)->run()['ok'])->toBeTrue();
    $fromInstagram = fbSection();
    $rows = fn () => InstagramPost::query()->orderBy('remote_id')->get(['remote_id', 'media_type', 'permalink', 'shortcode', 'caption', 'local_path', 'width', 'height', 'like_count', 'comments_count', 'posted_at'])->toArray();
    $rowsFromInstagram = $rows();

    // Scramble what the first fetch wrote.
    InstagramPost::query()->update(['like_count' => 0, 'comments_count' => 0, 'caption' => 'stale', 'permalink' => null]);
    expect(fbSection())->not->toBe($fromInstagram);

    // 2. Facebook-login route, same account.
    fbAppSaved();
    InstagramCredentials::saveFacebookConnection('fb-page-token-one', FB_IG, '5550001', 'KBB Shop');
    fbFake([]);

    expect(app(InstagramSync::class)->run()['ok'])->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/'.FacebookGraphClient::VERSION.'/'.FB_IG.'/media'));

    expect($fromInstagram)->toContain('First post')
        ->and(fbSection())->toBe($fromInstagram)
        // The rows behind it too, counts included — the section does not draw
        // every column, the Spotted page and the admin preview read the rest.
        ->and($rows())->toBe($rowsFromInstagram);
});

/* ─────────────────────────────── capability ─────────────────────────────── */

it('puts every Facebook endpoint behind instagram.manage and fails closed', function () {
    /*
     * MUTATION NOTE. Move the three fb routes out of routes/instagram-admin.php to
     * a group without auth:admin and the 401s are red. Map
     * `POST admin-api/instagram/**` to instagram.view in AdminCapabilities::RULES
     * and the editor's 403s are red.
     */
    InstagramAdminRoutes::wire($this->app);

    foreach (['fb/app', 'fb/check', 'fb/pick'] as $path) {
        expect(AdminCapabilities::forPath('POST', 'admin-api/instagram/'.$path))->toBe('instagram.manage');
        $this->postJson('/admin-api/instagram/'.$path)->assertStatus(401);
    }

    // An editor holds instagram.view only.
    $this->actingAs(fbAdmin('editor'), 'admin');

    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => FB_APP, 'app_secret' => FB_SECRET])->assertStatus(403);
    $this->postJson('/admin-api/instagram/fb/check')->assertStatus(403);
    $this->postJson('/admin-api/instagram/fb/pick', ['page_id' => '5550001'])->assertStatus(403);
    $this->get('/admin-api/instagram/start?via=facebook')->assertStatus(403);

    expect(InstagramCredentials::hasFbAppId())->toBeFalse()
        ->and(session()->has(InstagramAuth::STATE_SESSION_KEY))->toBeFalse();
});

it('validates the Facebook app fields before saving them', function () {
    InstagramAdminRoutes::wire($this->app);
    $this->actingAs(fbAdmin(), 'admin');

    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => 'abc', 'app_secret' => FB_SECRET])->assertStatus(422);
    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => FB_APP, 'app_secret' => 'short'])->assertStatus(422);
    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => FB_APP, 'app_secret' => ''])->assertStatus(422);
    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => FB_APP, 'app_secret' => FB_SECRET, 'config_id' => 'x"><b>'])->assertStatus(422);

    $this->postJson('/admin-api/instagram/fb/app', ['app_id' => FB_APP, 'app_secret' => FB_SECRET, 'config_id' => FB_CONFIG])
        ->assertOk()
        ->assertJsonPath('connection.facebook.secret_saved', true)
        ->assertJsonPath('connection.facebook.config_id', FB_CONFIG);

    expect(InstagramCredentials::fbSecret())->toBe(FB_SECRET)
        // Encrypted at rest.
        ->and(Setting::query()->find(InstagramCredentials::FB_SECRET_KEY)->value)->not->toContain(FB_SECRET);
});

/* ─────────────────────────── no token anywhere it shows ─────────────────── */

it('never puts a token or the secret in the payload, the redirect or the log', function () {
    /*
     * Meta echoes a good deal back in error bodies. Here /me/accounts refuses
     * with a message that QUOTES the user token, the way an "Invalid OAuth access
     * token" message can; that sentence travels to the screen as ig_detail in the
     * callback's redirect URL — which lands in browser history and server access
     * logs — so it must arrive redacted.
     *
     * MUTATION NOTE. Remove the `$secrets` argument from the pages() request()
     * call in FacebookGraphClient and the Location expectation is red: the long
     * user token is in the redirect URL.
     */
    $log = storage_path('ig-logs/fb-test-'.getmypid().'.log');
    @unlink($log);
    config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);

    InstagramAdminRoutes::wire($this->app);
    fbAppSaved();
    $this->actingAs(fbAdmin(), 'admin');

    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::fake(function (HttpRequest $r) {
        if (str_contains($r->url(), '/me/accounts')) {
            return Http::response(['error' => [
                'message' => 'Invalid token fb-long-user-token-words for app '.FB_SECRET, 'code' => 100,
            ]], 400);
        }

        return str_contains($r->url(), 'fb_exchange_token')
            ? Http::response(['access_token' => 'fb-long-user-token-words'])
            : Http::response(['access_token' => 'fb-short-user-token-words']);
    });

    $state = fbStart($this);
    $back = $this->get('/admin-api/instagram/callback?code=a-code&state='.$state);
    $location = urldecode((string) $back->headers->get('Location'));

    expect($location)->toContain('[redacted]')
        ->and($location)->not->toContain('fb-long-user-token-words')
        ->and($location)->not->toContain(FB_SECRET);

    // A connected shop's payload carries no token and no secret.
    InstagramCredentials::saveFacebookConnection('fb-page-token-one', FB_IG, '5550001', 'KBB Shop');
    $payload = $this->getJson('/admin-api/instagram')->assertOk()->getContent();

    foreach (['fb-page-token-one', 'fb-long-user-token-words', 'fb-short-user-token-words', FB_SECRET] as $secret) {
        expect(str_contains($payload, $secret))->toBeFalse($secret.' in the payload')
            ->and(str_contains((string) @file_get_contents($log), $secret))->toBeFalse($secret.' in the log');
    }

    @unlink($log);
});
