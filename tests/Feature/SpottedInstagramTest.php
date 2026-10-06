<?php

declare(strict_types=1);

/**
 * #KBeautyBliss Spotted from OUR Instagram.                     (Lane SG, 2.60.417)
 *
 * THE OWNER, 6 October 2026: "The instagram spotted page is completely wrong. i
 * want a function to fetch our instagram all posts / videos, and to choose from
 * the list which one need to be shown on the page … display the cover image auto
 * from instaram, and display all vitals like likes, comments, shares icons with
 * counts. and our profile @kbeauty.bliss … on click it will go the original
 * posts on our instagram." And: "if the post is video, it should play on our
 * website directly by embed from the instagram."
 *
 * WHAT EACH DEFECT WOULD LOOK LIKE ON THE SHOP, and the case that catches it:
 *
 *   · the picker lists only the newest 25 posts, because the sync read one page
 *     and stopped ("all posts" was the whole request)              → paging
 *   · the sync follows `paging.next` — a URL Meta hands back WITH THE ACCESS
 *     TOKEN IN IT — so a tampered body could send the token anywhere  → paging
 *   · every refresh downloads every picture again (1,000 files a press)
 *                                                                → no re-download
 *   · a card prints "0 shares" (or an invented figure) for a post Meta gave no
 *     number for, or before the owner reconnected for insights      → shares
 *   · two hundred refused insights calls per refresh without the permission
 *                                                                → shares
 *   · the page shows posts nobody ticked, or in the wrong order     → selection
 *   · a card links somewhere that is not instagram.com, or prints a caption raw
 *                                                                → markup/permalink
 *   · a reel's iframe is in the markup, so every visitor loads instagram.com on
 *     page load                                                    → facade
 *   · the switch says "Videos play on our page: off" and they still do → switch
 *   · an editor can't reach the picker, or a support login can     → capability
 *   · the page's query count grows with the number of picked posts → flat
 *   · the page goes blank when nothing is ticked yet               → fallback
 *
 * Nothing here reaches Meta: every Graph call is Http::fake()d (see
 * InstagramProfileTest's note on why the fakes are reset per call).
 */

use App\Models\AdminUser;
use App\Models\InstagramPost;
use App\Models\SpottedPost;
use App\Services\Instagram\IgPath;
use App\Services\Instagram\InstagramCredentials;
use App\Services\Instagram\InstagramSync;
use App\Services\InstagramSettings;
use App\Services\Security\ContentSecurityPolicy;
use App\Services\SettingsService;
use App\Services\SpottedInstagram;
use App\Services\SpottedSettings;
use App\Support\AdminCapabilities;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\SpottedRoutes;

const SGI_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AARAADAP8A/wH0lH8AAAAASUVORK5CYII=';

function sgiAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => ucfirst($role), 'email' => 'sgi-'.$role.'-'.Str::random(6).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function sgiPost(array $over = []): InstagramPost
{
    static $n = 0;
    $n++;
    $id = $over['remote_id'] ?? ('1790000000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT));
    $name = sha1($id).'.jpg';
    @mkdir(IgPath::directory(), 0775, true);
    file_put_contents(IgPath::directory().'/'.$name, 'x');

    return InstagramPost::query()->create($over + [
        'remote_id' => $id, 'media_type' => 'IMAGE',
        'permalink' => 'https://www.instagram.com/p/Sgi'.$n.'Code/', 'shortcode' => 'Sgi'.$n.'Code',
        'caption' => 'Post number '.$n, 'local_path' => '/'.IgPath::ROOT.$name,
        'like_count' => 100 + $n, 'comments_count' => $n, 'posted_at' => now()->subDays($n), 'seen_at' => now(),
    ]);
}

function sgiConnected(): void
{
    InstagramCredentials::saveApp('1234567890123456', 'abcdef0123456789abcdef0123456789');
    InstagramCredentials::saveToken('a-very-long-lived-token', 60 * 86400, '17841400000000000');
}

/** Fresh fakes every call: Http::fake() MERGES into stubs already registered. */
function sgiFake(array $stubs): void
{
    app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstances();
    Http::fake($stubs + [
        'graph.instagram.com/*/me?*' => Http::response(['id' => '17841400000000000', 'username' => 'kbeauty.bliss', 'account_type' => 'BUSINESS', 'media_count' => 3]),
        'graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'fresher', 'expires_in' => 5184000]),
        '*' => Http::response(base64_decode(SGI_PNG), 200, ['Content-Type' => 'image/png']),
    ]);
}

function sgiMedia(string $id, string $type = 'IMAGE', array $over = []): array
{
    return $over + [
        'id' => $id, 'media_type' => $type, 'permalink' => 'https://www.instagram.com/'.($type === 'VIDEO' ? 'reel' : 'p').'/C'.$id.'/',
        'timestamp' => '2026-09-01T10:00:00+0000', 'media_url' => 'https://scontent.cdninstagram.com/'.$id.'.jpg',
        'thumbnail_url' => 'https://scontent.cdninstagram.com/'.$id.'-t.jpg', 'like_count' => 10, 'comments_count' => 1,
    ];
}

function sgiPage(): string
{
    SettingsService::forgetMemo();
    SpottedInstagram::flush();
    SpottedSettings::flush();

    return (string) test()->get('/kbeautybliss-spotted/')->assertOk()->getContent();
}

beforeEach(function () {
    SpottedRoutes::wire($this->app);
    SpottedSettings::flush();
    SpottedInstagram::flush();
});

afterEach(function () {
    // Every picture a test wrote into the web root's uploads/instagram/ goes.
    try {
        foreach (InstagramPost::query()->pluck('local_path') as $p) {
            $f = IgPath::absolute($p);
            if ($f !== null) {
                @unlink($f);
            }
        }
    } catch (\Throwable) {
    }
});

/* ───────────────────────────── fetching every post ─────────────────────────── */

it('follows the media pages to the end, by cursor, and never requests the next URL Meta sent', function () {
    /*
     * DEFECT: one Graph page and stop — the picker shows the newest posts only and
     * "all our posts" is not what the owner gets. And following `paging.next`
     * verbatim would send our access token to whatever host the body named.
     *
     * MUTATION NOTE. Replace the do/while in InstagramSync::run() with a single
     * media() call → RED: 2 posts stored, not 3. Make media() request
     * $paging['next'] instead of rebuilding with `after` → RED on the evil host.
     */
    sgiConnected();
    sgiFake([
        'graph.instagram.com/*/me/media*' => Http::sequence()
            ->push(['data' => [sgiMedia('1001'), sgiMedia('1002', 'VIDEO')],
                'paging' => ['cursors' => ['after' => 'QVFIcursor2'], 'next' => 'https://evil.example/steal?access_token=a-very-long-lived-token']])
            ->push(['data' => [sgiMedia('1003', 'CAROUSEL_ALBUM', ['children' => ['data' => [['media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/c.jpg']]]])],
                'paging' => ['cursors' => ['after' => 'QVFIcursor3']]]),
    ]);

    $result = app(InstagramSync::class)->run();

    expect($result['ok'])->toBeTrue()
        ->and($result['pages'])->toBe(2)
        ->and(InstagramPost::query()->pluck('remote_id')->sort()->values()->all())->toBe(['1001', '1002', '1003']);

    $media = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/me/media'))->values();
    expect($media)->toHaveCount(2)
        ->and($media[1][0]->url())->toContain('after=QVFIcursor2')
        ->and($media[0][0]->url())->toContain('limit=100');

    // Not one request to the host the body named.
    expect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example')))->toHaveCount(0);

    // Carousel's cover is its first child; a video's is thumbnail_url.
    expect(Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '1002-t.jpg')))->toHaveCount(1)
        ->and(Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/c.jpg')))->toHaveCount(1);
});

it('does not download a picture it already holds', function () {
    /*
     * DEFECT: a thousand identical downloads per press — the rate limit the shop
     * shares with every later refresh, spent for nothing.
     *
     * MUTATION NOTE. Make `$have` always false in InstagramSync::run() → RED:
     * the second run downloads the picture again.
     */
    sgiConnected();
    $stubs = ['graph.instagram.com/*/me/media*' => Http::response(['data' => [sgiMedia('2001')]])];
    sgiFake($stubs);
    app(InstagramSync::class)->run();
    $path = InstagramPost::query()->where('remote_id', '2001')->value('local_path');
    expect(IgPath::absolute($path))->toBeFile();

    sgiFake($stubs);
    $second = app(InstagramSync::class)->run();

    expect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'cdninstagram')))->toHaveCount(0)
        ->and($second['pictures'])->toBe(1)
        ->and($second['pending'])->toBe(0);
});

/* ───────────────────────────────── shares ──────────────────────────────────── */

it('reads shares for the ticked posts only, a video with views, and stores them', function () {
    /*
     * DEFECT: insights fetched for all 1,000 posts (1,000 calls), or not at all.
     *
     * MUTATION NOTE. Drop the whereNotNull('spotted_sort') from
     * selectedInsights() → RED: three insights calls, not two.
     */
    sgiConnected();
    $photo = sgiPost(['remote_id' => '3001', 'spotted_sort' => 1]);
    $reel = sgiPost(['remote_id' => '3002', 'media_type' => 'VIDEO', 'spotted_sort' => 2]);
    $other = sgiPost(['remote_id' => '3003']);

    sgiFake([
        'graph.instagram.com/*/3001/insights*' => Http::response(['data' => [['name' => 'shares', 'period' => 'lifetime', 'values' => [['value' => 1240]]]]]),
        // The newer answer shape, total_value — read too.
        'graph.instagram.com/*/3002/insights*' => Http::response(['data' => [
            ['name' => 'shares', 'total_value' => ['value' => 87]], ['name' => 'views', 'total_value' => ['value' => 45200]],
        ]]),
    ]);

    $out = app(InstagramSync::class)->selectedInsights('tok', microtime(true) + 10);

    $calls = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/insights'))->values();
    expect($out['done'])->toBe(2)
        ->and($calls)->toHaveCount(2)
        ->and(urldecode($calls[0][0]->url()))->toContain('metric=shares&')
        ->and(urldecode($calls[1][0]->url()))->toContain('metric=shares,views')
        ->and($photo->fresh()->share_count)->toBe(1240)
        ->and($reel->fresh()->share_count)->toBe(87)
        ->and($reel->fresh()->view_count)->toBe(45200)
        ->and($other->fresh()->share_count)->toBeNull();
});

it('hides the share count when Meta gives none or refuses, and stops asking after the first refusal', function () {
    /*
     * DEFECT: "0 shares" printed under a post whose shares nobody knows (no
     * insights permission until the owner reconnects), and 200 refused calls.
     *
     * MUTATION NOTE. Change the card's `@if ($card['shares'] !== null)` to
     * always draw → RED (a share icon with an empty count). Remove the
     * `if ($note !== '') continue;` → RED: two insights calls.
     */
    sgiConnected();
    sgiPost(['remote_id' => '4001', 'spotted_sort' => 1, 'caption' => 'First']);
    sgiPost(['remote_id' => '4002', 'spotted_sort' => 2, 'caption' => 'Second']);

    sgiFake(['graph.instagram.com/*/insights*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 400)]);

    $out = app(InstagramSync::class)->selectedInsights('tok', microtime(true) + 10);

    expect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/insights')))->toHaveCount(1)
        ->and($out['note'])->toContain('Reconnect')
        ->and(InstagramPost::query()->whereNotNull('share_count')->count())->toBe(0);

    $html = sgiPage();
    expect(substr_count($html, 'class="sig-card"'))->toBe(2)
        ->and($html)->not->toContain(' '.__('store.spotted.shares').'<')
        ->and(preg_match_all('#\d+<span class="sig-sr"> likes</span>#', $html))->toBe(2);

    // An answer with no value at all is "no value" too: null, not 0.
    sgiFake(['graph.instagram.com/*/insights*' => Http::response(['data' => [['name' => 'shares', 'values' => []]]])]);
    app(InstagramSync::class)->selectedInsights('tok', microtime(true) + 10);
    expect(InstagramPost::query()->whereNotNull('share_count')->count())->toBe(0)
        ->and(InstagramPost::query()->whereNotNull('insights_at')->count())->toBe(2);
});

it('asks Instagram for the insights permission when it connects', function () {
    // MUTATION NOTE. Put SCOPE back to 'instagram_business_basic' → RED.
    expect(explode(',', \App\Services\Instagram\InstagramClient::SCOPE))
        ->toContain('instagram_business_basic')
        ->toContain('instagram_business_manage_insights');
});

/* ───────────────────────────── selection and order ─────────────────────────── */

it('draws exactly the ticked posts in the chosen order, and saves the order the picker sends', function () {
    /*
     * DEFECT: the page shows every synced post, or the newest first regardless of
     * the order the owner arranged.
     *
     * MUTATION NOTE. Order build() by posted_at instead of spotted_sort → RED.
     */
    $a = sgiPost(['caption' => 'Alpha post']);
    $b = sgiPost(['caption' => 'Bravo post']);
    $c = sgiPost(['caption' => 'Charlie post']);
    $owner = sgiAdmin('owner');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/instagram', ['ids' => [$c->id, $a->id, 999999]])
        ->assertOk()->assertJsonPath('selected', 2);

    $html = sgiPage();
    expect(substr_count($html, 'class="sig-card"'))->toBe(2)
        ->and(strpos($html, 'Charlie post'))->toBeLessThan(strpos($html, 'Alpha post'))
        ->and($html)->not->toContain('Bravo post');

    // Re-ordered and narrowed in one save; the old ticks are cleared.
    $this->actingAs($owner, 'admin')->postJson('/admin-api/spotted/instagram', ['ids' => [$b->id]])->assertOk();
    expect(InstagramPost::query()->whereNotNull('spotted_sort')->pluck('id')->all())->toBe([$b->id]);
});

it('falls back to the manual posts while nothing is ticked, and Manual is one switch away', function () {
    // DEFECT: a blank page the day the package applies, before anything is ticked.
    SpottedPost::create(['image' => '/uploads/spotted/m.jpg', 'ig_url' => 'https://www.instagram.com/p/Manual1/',
        'handle' => 'lina.skin', 'caption' => 'Manual one', 'sort' => 1, 'on_home' => true, 'on_page' => true]);
    sgiPost(['caption' => 'From IG']);

    expect(sgiPage())->toContain('Manual one')->not->toContain('class="sig-card"');

    InstagramPost::query()->update(['spotted_sort' => 1]);
    expect(sgiPage())->toContain('From IG')->not->toContain('Manual one');

    app(SpottedSettings::class)->save(['page_source' => 'manual']);
    expect(sgiPage())->toContain('Manual one')->not->toContain('From IG');
});

it('ships with Instagram as the source, videos playing here, and card style C (the owner\'s pick)', function () {
    // The owner asked for these; Manual / off / B–D stay one choice away.
    $page = app(SpottedSettings::class)->page();
    expect($page['source'])->toBe('instagram')->and($page['play'])->toBeTrue()->and($page['card'])->toBe('c');

    // A hand-made POST of a value that is not an option stores the default.
    app(SpottedSettings::class)->save(['page_card' => 'z"><script>', 'page_source' => 'evil']);
    SettingsService::forgetMemo();
    $page = app(SpottedSettings::class)->page();
    expect($page['card'])->toBe('c')->and($page['source'])->toBe('instagram');
});

/* ─────────────────────────────── the card ──────────────────────────────────── */

it('draws the cover, the profile, the counts in compact form, an escaped caption and a link to the post', function () {
    /*
     * MUTATION NOTE. Print the caption with {!! !!} → RED on the <script>.
     * Drop target/rel from the anchor → RED.
     */
    app(InstagramSettings::class)->saveProfile(['username' => 'kbeauty.bliss', 'avatar' => '/'.IgPath::ROOT.'avatar.jpg']);
    sgiPost(['caption' => "Glow <script>alert(1)</script>\n\n#kbeautybliss", 'like_count' => 15620, 'comments_count' => 1320,
        'share_count' => 0, 'spotted_sort' => 1, 'permalink' => 'https://www.instagram.com/p/GlowCode1/']);

    $html = sgiPage();

    expect($html)->toContain('<a class="sig-card" href="https://www.instagram.com/p/GlowCode1/" target="_blank" rel="noopener">')
        ->toContain('<b dir="ltr">@kbeauty.bliss</b>')
        ->toContain('<img src="/'.IgPath::ROOT.'avatar.jpg" alt="" width="28" height="28"')
        ->toContain('15.6K<span class="sig-sr"> likes</span>')
        ->toContain('1.3K<span class="sig-sr"> comments</span>')
        // A real 0 from Meta IS a number and is drawn; only null is hidden.
        ->toContain('0<span class="sig-sr"> shares</span>')
        ->toContain('Glow &lt;script&gt;alert(1)&lt;/script&gt; #kbeautybliss')
        ->not->toContain('<script>alert(1)')
        ->toContain('width="400" height="500"')
        ->toContain('class="sig-grid sig-c"');
});

it('formats counts the way Instagram does, rounding down', function () {
    foreach ([[null, null], [0, '0'], [999, '999'], [1000, '1K'], [1299, '1.2K'], [12345, '12.3K'], [100000, '100K'],
        [999999, '999K'], [1250000, '1.2M']] as [$in, $out]) {
        expect(SpottedInstagram::compact($in))->toBe($out);
    }
});

it('drops a card whose link is not an Instagram post, and rebuilds the embed from the shortcode alone', function () {
    /*
     * DEFECT: a card that sends a shopper to a look-alike host, or an iframe URL
     * carrying whatever the stored string carried.
     *
     * MUTATION NOTE. Remove the host check from SpottedInstagram::permalink()
     * → RED on the first two.
     */
    foreach ([
        'https://instagram.com.evil.test/p/AbC123/', 'https://evil.test/p/AbC123/', 'http://www.instagram.com/p/AbC123/',
        'javascript:alert(1)//www.instagram.com/p/AbC123/', 'https://www.instagram.com/p/Ab"C/', 'https://www.instagram.com/p/AbC/../../x/',
        'https://user@www.instagram.com/p/AbC123/', 'https://www.instagram.com/stories/kbeauty.bliss/1/',
    ] as $bad) {
        expect(SpottedInstagram::permalink($bad))->toBeNull();
    }

    expect(SpottedInstagram::permalink('https://instagram.com/reel/AbC_1-2/?igsh=xyz#x'))
        ->toBe(['url' => 'https://www.instagram.com/reel/AbC_1-2/', 'kind' => 'reel', 'code' => 'AbC_1-2'])
        ->and(SpottedInstagram::embed(['kind' => 'reel', 'code' => 'AbC_1-2']))->toBe('https://www.instagram.com/reel/AbC_1-2/embed/')
        ->and(SpottedInstagram::embed(['kind' => 'reel', 'code' => 'a/../b']))->toBeNull()
        ->and(SpottedInstagram::embed(['kind' => 'javascript', 'code' => 'abc']))->toBeNull();

    sgiPost(['caption' => 'Good one', 'spotted_sort' => 1]);
    sgiPost(['caption' => 'Evil one', 'spotted_sort' => 2, 'permalink' => 'https://evil.test/p/AbC123/']);
    $html = sgiPage();
    expect($html)->toContain('Good one')->not->toContain('Evil one');
});

it('loads nothing from instagram.com until a reel is tapped, and links instead when play is off', function () {
    /*
     * DEFECT: an <iframe src="https://www.instagram.com/…"> in the markup is a
     * third-party request on every page view (the speed rule), and a switch that
     * says off but still plays.
     *
     * MUTATION NOTE. Render the iframe in the partial → RED (src in markup).
     * Drop `($play ?? true) &&` from the card → RED in the second half.
     */
    sgiPost(['media_type' => 'VIDEO', 'spotted_sort' => 1, 'permalink' => 'https://www.instagram.com/reel/ReelCode1/', 'view_count' => 45200]);
    sgiPost(['spotted_sort' => 2, 'caption' => 'A photo']);

    $html = sgiPage();
    expect($html)->toContain('data-sig-embed="https://www.instagram.com/reel/ReelCode1/embed/"')
        ->and(preg_match('#<iframe[^>]+instagram#', $html))->toBe(0)
        ->and(preg_match('#src="https://www\.instagram\.com#', $html))->toBe(0)
        ->and(substr_count($html, 'data-sig-embed='))->toBe(1)
        ->and($html)->toContain('id="sigm" hidden role="dialog" aria-modal="true"')
        ->and($html)->toContain('<span>45.2K</span><span class="sig-sr"> views</span>')
        // The script checks the URL shape before it builds a frame.
        ->and($html)->toContain('www\\.instagram\\.com\\/(p|reel)\\/[A-Za-z0-9_-]{1,64}\\/embed\\/$');

    app(SpottedSettings::class)->save(['page_play' => false]);
    $off = sgiPage();
    expect($off)->not->toContain('data-sig-embed')
        ->and($off)->not->toContain('id="sigm"')
        ->and($off)->toContain('<a class="sig-card" href="https://www.instagram.com/reel/ReelCode1/" target="_blank" rel="noopener">');
});

it('lets the player through the content policy by one exact host', function () {
    // MUTATION NOTE. Remove the line → RED; write https://*.instagram.com → RED.
    expect(ContentSecurityPolicy::DIRECTIVES['frame-src'])->toContain('https://www.instagram.com');
    foreach (ContentSecurityPolicy::DIRECTIVES['frame-src'] as $src) {
        expect($src)->not->toContain('*');
    }
});

it('draws each card style from the same markup, and prints only the chosen style\'s rules', function () {
    /*
     * The owner: "super light". Four styles must not mean four styles' CSS on
     * every view.
     *
     * MUTATION NOTE. Replace the @if/@elseif chain with all four blocks → RED on
     * the not->toContain.
     */
    sgiPost(['spotted_sort' => 1]);
    foreach (['a', 'b', 'c', 'd'] as $s) {
        app(SpottedSettings::class)->save(['page_card' => $s]);
        $html = sgiPage();
        expect($html)->toContain('class="sig-grid sig-'.$s.'"')
            ->and($html)->toContain('.kbb-home .sig-'.$s.' ');
        foreach (array_diff(['a', 'b', 'c', 'd'], [$s]) as $other) {
            expect($html)->not->toContain('.kbb-home .sig-'.$other.' ');
        }
    }
});

/* ─────────────────────────── the admin endpoints ───────────────────────────── */

it('maps the picker to its own capability, above the screen, and fails closed', function () {
    /*
     * MUTATION NOTE. Move the spotted/instagram RULES line below spotted/** →
     * RED (resolves to spotted.manage).
     */
    foreach (['GET', 'POST'] as $m) {
        expect(AdminCapabilities::forPath($m, 'admin-api/spotted/instagram'))->toBe('spotted.instagram');
    }
    expect(AdminCapabilities::roleCan('editor', 'spotted.instagram'))->toBeTrue()
        ->and(AdminCapabilities::roleCan('support', 'spotted.instagram'))->toBeFalse();

    $this->getJson('/admin-api/spotted/instagram')->assertStatus(401);
    $this->postJson('/admin-api/spotted/instagram', ['ids' => []])->assertStatus(401);
    $this->actingAs(sgiAdmin('support'), 'admin')->getJson('/admin-api/spotted/instagram')->assertForbidden();
    $this->actingAs(sgiAdmin('support'), 'admin')->postJson('/admin-api/spotted/instagram', ['ids' => []])->assertForbidden();
    $this->actingAs(sgiAdmin('editor'), 'admin')->getJson('/admin-api/spotted/instagram')->assertOk();
});

it('hands the picker an allowlist: no token, no remote id', function () {
    sgiConnected();
    sgiPost(['remote_id' => '17999999999999999', 'spotted_sort' => 1, 'share_count' => 12]);

    $res = $this->actingAs(sgiAdmin('owner'), 'admin')->getJson('/admin-api/spotted/instagram')->assertOk();
    $raw = $res->getContent();

    expect($raw)->not->toContain('17999999999999999')
        ->and($raw)->not->toContain('a-very-long-lived-token')
        ->and(array_keys($res->json('posts.0')))->toBe(['id', 'type', 'thumb', 'caption', 'likes', 'comments', 'shares', 'views', 'date', 'url', 'drawable', 'sort'])
        ->and($res->json('posts.0.shares'))->toBe(12)
        ->and($res->json('account.connected'))->toBeTrue();
});

it('reads shares for newly ticked posts when the selection is saved', function () {
    sgiConnected();
    $p = sgiPost(['remote_id' => '5001']);
    sgiFake(['graph.instagram.com/*/5001/insights*' => Http::response(['data' => [['name' => 'shares', 'values' => [['value' => 9]]]]])]);

    $this->actingAs(sgiAdmin('owner'), 'admin')->postJson('/admin-api/spotted/instagram', ['ids' => [$p->id]])->assertOk();

    expect($p->fresh()->share_count)->toBe(9);

    // Saving the same selection again asks for nothing new.
    sgiFake([]);
    $this->actingAs(sgiAdmin('owner'), 'admin')->postJson('/admin-api/spotted/instagram', ['ids' => [$p->id]])->assertOk();
    expect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '/insights')))->toHaveCount(0);
});

it('schedules the daily sync, and the command is a quiet no-op when not connected', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kbb:instagram-sync'));
    expect($events)->toHaveCount(1);

    $this->artisan('kbb:instagram-sync --unattended')->assertExitCode(0);
});

/* ────────────────────────────── speed ──────────────────────────────────────── */

it('costs the same queries with 3 picked posts as with 40', function () {
    /*
     * DEFECT: an N+1 — a query per card for its profile, its counts or its
     * picture — so the page slows as the owner picks more.
     *
     * ONE counter, registered ONCE (InstagramProfileTest records how a listener
     * per pass inflated a slope that was not there).
     *
     * MUTATION NOTE. Read InstagramSettings::profile() inside card() → RED.
     */
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    $measure = function () use (&$count): int {
        SettingsService::forgetMemo();
        SpottedInstagram::flush();
        SpottedSettings::flush();
        $count = 0;
        $this->get('/kbeautybliss-spotted/')->assertOk();

        return $count;
    };

    for ($i = 0; $i < 3; $i++) {
        sgiPost(['spotted_sort' => $i + 1]);
    }
    $measure(); // warm everything that is not ours
    $three = $measure();

    for ($i = 3; $i < 40; $i++) {
        sgiPost(['spotted_sort' => $i + 1]);
    }
    $forty = $measure();

    expect(substr_count((string) $this->get('/kbeautybliss-spotted/')->getContent(), 'class="sig-card"'))->toBe(40)
        ->and($forty)->toBe($three);
});
