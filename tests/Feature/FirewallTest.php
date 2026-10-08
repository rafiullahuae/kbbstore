<?php

declare(strict_types=1);

/**
 * Store → Security → Firewall.                                     (Lane FW)
 *
 * The owner: "bots from china, russia, singapore, hongkong ... each time they
 * change the ips and come and attach multiple times in a single second ... i
 * need super super secure, and fully reliable and don't harm to real visitors
 * at all ... always allow the google and other friendly bots!"
 *
 * Every case names the defect it would catch on the shop and the mutation that
 * turns it red. Addresses are documentation ranges (RFC 5737 / 3849) wherever
 * the case allows; the country fixture maps them to the countries each case
 * needs.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Security\CountryDb;
use App\Services\Security\Firewall;
use App\Services\Security\FirewallConfig;
use App\Services\Security\FirewallLog;
use App\Services\Security\FirewallStore;
use App\Services\Security\GoodBots;
use App\Services\Security\IpBlockList;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;

const FW_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
const FW_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const FW_CN = '203.0.113.9';      // the fixture's China
const FW_AE = '94.200.10.20';     // the fixture's UAE
const FW_SG = '198.51.100.7';     // the fixture's Singapore

beforeEach(function () {
    $dir = storage_path('framework/testing/fw-'.getmypid());
    @mkdir($dir, 0775, true);
    $csv = $dir.'/countries.csv';
    file_put_contents($csv, implode("\n", [
        '1.0.0.0,1.0.0.255,AU',
        '36.110.0.0,36.110.255.255,CN',
        '66.249.64.0,66.249.95.255,US',
        '94.200.0.0,94.200.255.255,AE',
        '95.24.0.0,95.24.255.255,RU',
        '198.51.100.0,198.51.100.255,SG',
        '203.0.113.0,203.0.113.255,CN',
        '2001:db8::,2001:db8:ffff:ffff:ffff:ffff:ffff:ffff,CN',
        '2a00:1450::,2a00:1450:ffff:ffff:ffff:ffff:ffff:ffff,US',
    ])."\n");
    CountryDb::build([$csv], $dir.'/country.bin', 20261001);
    CountryDb::usePath($dir.'/country.bin');
    GoodBots::useRangesPath($dir.'/bot-ranges.php');
    $this->fwDir = $dir;
});

afterEach(function () {
    CountryDb::forget();
    GoodBots::forget();
    foreach (glob($this->fwDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->fwDir);
});

function fwProduct(): Product
{
    return Product::create(['slug' => 'fw-'.uniqid(), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 5900, 'stock_status' => 'instock']);
}

/** A visitor: their address, their browser, a fresh client. */
function fwVisitor(string $ip, string $ua = FW_UA, array $headers = [], array $cookies = [])
{
    app()->forgetScopedInstances();

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'cart') || str_contains($route->uri(), 'checkout')) {
            $route->controller = null;
        }
    }

    test()->flushHeaders();
    test()->flushSession();
    app('auth')->forgetGuards();

    $t = test()->withCredentials()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => $ua] + $headers)
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, 'fw-'.uniqid());

    foreach ($cookies as $k => $v) {
        $t = $t->withUnencryptedCookie($k, $v);
    }

    return $t;
}

function fwAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => ucfirst($role).' FW', 'email' => $role.'-fw-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role]);
}

function fwProof(\Illuminate\Testing\TestResponse $r): ?string
{
    foreach ($r->headers->getCookies() as $c) {
        if ($c->getName() === Firewall::COOKIE) {
            return (string) $c->getValue();
        }
    }

    return null;
}

/** Fire $n page requests from $ip, all inside one 10-second window. */
function fwBurst(string $ip, int $n, string $path): void
{
    for ($i = 0; $i < $n; $i++) {
        fwVisitor($ip)->get($path);
    }
}

it('ships in Monitor mode, with China, Russia, Singapore and Hong Kong on Protect and every bot family on', function () {
    /*
     * DEFECT: the first install refuses traffic before the owner has watched
     * a single day of it, or a country he did not name is restricted.
     * MUTATION: change SCHEMA['mode'][2] to 'enforce', or add 'IN' to
     * COUNTRY_DEFAULTS.
     */
    $fw = IpBlockList::compiled()['fw'];

    expect($fw['mode'])->toBe('monitor')
        ->and($fw['countries'])->toBe(['CN' => 'protect', 'HK' => 'protect', 'RU' => 'protect', 'SG' => 'protect'])
        ->and($fw['scope'])->toBe('commerce');

    foreach (array_keys(GoodBots::FAMILIES) as $family) {
        expect($fw['botre'])->toContain('(?<'.$family.'>');
    }
});

it('allows a verified Googlebot everywhere, uncounted, and refuses a fake one', function () {
    /*
     * DEFECT: Googlebot or AdsBot is rate-limited or refused and the shop
     * drops out of search / its ads are disapproved — or a scraper calling
     * itself Googlebot walks straight past every rule. MUTATION: make
     * GoodBots::verify() return null for range hits and the real Googlebot is
     * banned by the flood below; make the fake_bot arm in Firewall::before()
     * fall through and the fake is answered 200.
     */
    $p = fwProduct();
    GoodBots::refresh(fn (string $url): ?string => str_contains($url, 'common-crawlers')
        ? json_encode(['creationTime' => '2026-10-01', 'prefixes' => [['ipv4Prefix' => '66.249.64.0/19'], ['ipv6Prefix' => '2001:4860:4801::/48']]])
        : null);
    FirewallConfig::save(['mode' => 'enforce', 'ip_10s' => 20, 'scope' => 'site']);

    // The real one, from Google's published range: 60 requests in one window, never counted.
    for ($i = 0; $i < 60; $i++) {
        fwVisitor('66.249.66.1', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')->assertOk();
    }
    fwVisitor('66.249.66.1', 'Mozilla/5.0 (compatible; AdsBot-Google; +http://www.google.com/adsbot.html)')->get('/cart')->assertOk();
    expect(Firewall::bans())->toBe([]);

    // A fake: same name, an address Google does not publish. The first visit
    // is let through while its DNS is checked AFTER the response …
    GoodBots::useResolver(fn (string $name, int $type) => $type === DNS_PTR
        ? [['target' => 'scraper.example.net']]
        : [['ip' => '203.0.113.77']]);
    fwVisitor('198.51.100.77', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')->assertOk();

    // … and every visit after that is refused, logged as a fake bot.
    fwVisitor('198.51.100.77', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')
        ->assertStatus(403)->assertHeader('Cache-Control', 'no-store, private')->assertSee('Reference: FF', false);
    expect(collect(FirewallLog::report()['reasons'])->firstWhere('reason', 'fake_bot')['refused'] ?? 0)->toBeGreaterThan(0);

    // DNS that says yes (forward-confirmed) makes a Googlebot outside the
    // downloaded ranges real — a range file a day old cannot brand it fake.
    GoodBots::useResolver(fn (string $name, int $type) => $type === DNS_PTR
        ? [['target' => 'crawl-198-51-100-78.googlebot.com']]
        : [['ip' => '198.51.100.78']]);
    fwVisitor('198.51.100.78', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')->assertOk();
    fwVisitor('198.51.100.78', FW_GOOGLEBOT)->get('/cart')->assertOk();

    // A resolver failure caches nothing: the claim stays "pending", not fake.
    GoodBots::useResolver(fn () => false);
    fwVisitor('198.51.100.79', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')->assertOk();
    fwVisitor('198.51.100.79', FW_GOOGLEBOT)->get('/product/'.$p->slug.'/')->assertOk();
});

it('bans a flood within seconds, and the ban ends by itself', function () {
    /*
     * DEFECT: a bot hammering the cart many times a second is never stopped —
     * or it is stopped for ever, and the owner has to find and lift every ban.
     * MUTATION: drop the ban() call in Firewall::after() and /cart stays 200;
     * give ban() a TTL of 0 (forever) and it is still 429 after 11 minutes.
     */
    $p = fwProduct();
    $this->travelTo(now()->startOfMinute()->addSeconds(1));
    FirewallConfig::save(['mode' => 'enforce', 'ip_10s' => 20]);

    fwBurst(FW_AE, 21, '/product/'.$p->slug.'/');

    fwVisitor(FW_AE)->get('/cart')->assertStatus(429)->assertHeader('Retry-After')->assertSee('Reference: FB', false);
    fwVisitor(FW_AE)->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(429)->assertJson(['blocked' => true]);
    // Scope "cart, checkout and forms": reading the shop still works.
    fwVisitor(FW_AE)->get('/product/'.$p->slug.'/')->assertOk();
    // A neighbour on the same carrier range is not banned with it.
    fwVisitor('94.200.10.21')->get('/cart')->assertOk();

    $bans = Firewall::bans();
    expect($bans)->toHaveCount(1)->and($bans[0]['who'])->toBe(FW_AE)->and($bans[0]['why'])->toBe('10s');

    // IPv6 the same way, and its /64 neighbour is not caught with it.
    fwBurst('2a00:1450:4001:82a::5', 21, '/product/'.$p->slug.'/');
    fwVisitor('2a00:1450:4001:82a::5')->get('/cart')->assertStatus(429);
    fwVisitor('2a00:1450:4001:82a::6')->get('/cart')->assertOk();
    expect(Firewall::unban((string) Firewall::subjectFor('2a00:1450:4001:82a::5')))->toBeTrue();

    $this->travel(11)->minutes();
    fwVisitor(FW_AE)->get('/cart')->assertOk();

    // A repeat offender's second ban is twice as long.
    fwBurst(FW_AE, 21, '/product/'.$p->slug.'/');
    $this->travel(11)->minutes();
    fwVisitor(FW_AE)->get('/cart')->assertStatus(429);
    $this->travel(10)->minutes();
    fwVisitor(FW_AE)->get('/cart')->assertOk();

    // Unban from the screen's endpoint lifts it at once.
    fwBurst(FW_AE, 21, '/product/'.$p->slug.'/');
    fwVisitor(FW_AE)->get('/cart')->assertStatus(429);
    expect(Firewall::unban((string) Firewall::subjectFor(FW_AE)))->toBeTrue()
        ->and(Firewall::subjectFor(FW_AE))->toBe($bans[0]['subject']);
    fwVisitor(FW_AE)->get('/cart')->assertOk();
});

it('bans a range that rotates addresses, even across a minute boundary', function () {
    /*
     * DEFECT: "each time they change the ips" — 20 addresses in one /24, each
     * well under the per-address limit, together far over the range's; and a
     * fixed one-minute window lets them send nearly twice the limit by
     * straddling a minute (measured on the preview: 500 requests in 50 s from
     * a Russian /24 went unbanned that way). MUTATION: make estimate() return
     * $r['n60'] only and the range is never banned.
     */
    $p = fwProduct();
    $this->travelTo(now()->startOfMinute()->addSeconds(50));
    FirewallConfig::save(['mode' => 'enforce', 'net_60s' => 120]);

    $wave = function () use ($p): void {
        for ($a = 1; $a <= 20; $a++) {
            for ($i = 0; $i < 5; $i++) {
                fwVisitor('94.201.7.'.$a)->get('/product/'.$p->slug.'/');
            }
        }
    };

    $wave();                        // 100 at :50
    fwVisitor('94.201.7.99')->get('/cart')->assertOk();
    $this->travel(15)->seconds();   // :05 of the next minute
    $wave();                        // 100 more: 100 + 100 × 55/60 > 120

    fwVisitor('94.201.7.200')->get('/cart')->assertStatus(429);   // a fresh address, same /24
    fwVisitor('94.202.7.1')->get('/cart')->assertOk();            // the next /24 is not caught
    expect(collect(Firewall::bans())->pluck('who')->all())->toContain('94.201.7.0/24');
});

it('never bans a fast shopper browsing with hover-prefetch, even from a Protect country', function () {
    /*
     * DEFECT: InstantNav prefetches a page on every hover, and a shopper who
     * skims a category gets banned from the checkout — from an ad click, in a
     * Protect country. MEASURED in Chromium (tools/fw451-human.cjs, two
     * minutes of skimming at 1280 and 390, every request the app would
     * handle logged by tools/fw451-router.php): the busiest 10 s held 9 page
     * and API requests and 19 prefetches; the busiest minute, 26 page and API
     * requests. Replayed here at TWICE that, for a whole minute: 18 pages and
     * 38 prefetches every 10 s, 108 pages in the minute — under the Protect
     * limits (half of 60/10 s, 240/60 s and 80 prefetches/10 s).
     * MUTATION: count prefetches with the pages (drop the isSpeculative() arm
     * of after()) and the Protect visitor is banned within the first 10 s.
     */
    $p = fwProduct();
    $this->travelTo(now()->startOfMinute()->addSeconds(1));
    FirewallConfig::save(['mode' => 'enforce']);
    $page = '/product/'.$p->slug.'/';

    foreach ([FW_AE, FW_CN] as $ip) {
        for ($block = 0; $block < 6; $block++) {
            for ($k = 0; $k < 38; $k++) {
                if ($k < 18) {
                    fwVisitor($ip)->get($page)->assertOk();
                }
                fwVisitor($ip, FW_UA, ['Sec-Purpose' => 'prefetch'])->get($page)->assertOk();
            }
            $this->travel(10)->seconds();
        }

        fwVisitor($ip)->get('/cart')->assertOk();
    }

    expect(Firewall::bans())->toBe([]);

    // And the prefetch counter is a counter: a client that only "prefetches"
    // at bot speed is still banned.
    for ($i = 0; $i < 90; $i++) {
        fwVisitor('94.200.10.30', FW_UA, ['Sec-Purpose' => 'prefetch'])->get($page);
    }
    fwVisitor('94.200.10.30')->get('/cart')->assertStatus(429);
});

it('looks countries up correctly at range edges, in gaps, for IPv4, IPv6 and mapped IPv4', function () {
    /*
     * DEFECT: an off-by-one at a range edge puts a UAE customer in China, or
     * a gap between ranges inherits the country before it. MUTATION: drop
     * the "- 1" from $lo in CountryDb::lookup() and the first address of every
     * range reads as the range before it; skip the gap row in emit() and
     * 1.0.1.0 reads AU.
     */
    $at = fn (string $ip): string => CountryDb::lookup((string) \App\Support\IpRange::pack($ip));

    expect($at('0.0.0.0'))->toBe('')
        ->and($at('1.0.0.0'))->toBe('AU')
        ->and($at('1.0.0.255'))->toBe('AU')
        ->and($at('1.0.1.0'))->toBe('')            // the gap after AU
        ->and($at('36.109.255.255'))->toBe('')
        ->and($at('36.110.0.0'))->toBe('CN')
        ->and($at('36.110.255.255'))->toBe('CN')
        ->and($at('36.111.0.0'))->toBe('')
        ->and($at('94.200.0.0'))->toBe('AE')
        ->and($at('94.200.255.255'))->toBe('AE')
        ->and($at('95.24.128.1'))->toBe('RU')
        ->and($at('203.0.113.255'))->toBe('CN')
        ->and($at('203.0.114.0'))->toBe('')
        ->and($at('255.255.255.255'))->toBe('')
        ->and($at('::ffff:94.200.1.1'))->toBe('AE')  // IPv4 on a dual-stack socket
        ->and($at('2001:db7:ffff::1'))->toBe('')
        ->and($at('2001:db8::'))->toBe('CN')
        ->and($at('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'))->toBe('CN')
        ->and($at('2001:db9::'))->toBe('')
        ->and($at('2a00:1450:4001:82a::200e'))->toBe('US')
        ->and($at('ffff::1'))->toBe('');

    // The installed file is integrity-checked: a truncated copy is refused
    // (lookups answer "unknown", never a wrong country), a damaged byte fails
    // the checksum, and the converter refuses unsorted input.
    $path = $this->fwDir.'/country.bin';
    expect(CountryDb::verify($path)['ok'])->toBeTrue();

    $bad = $this->fwDir.'/bad.bin';
    file_put_contents($bad, substr((string) file_get_contents($path), 0, -3));
    CountryDb::usePath($bad);
    expect($at('94.200.0.1'))->toBe('')->and(CountryDb::verify($bad)['ok'])->toBeFalse();

    $bytes = (string) file_get_contents($path);
    $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
    file_put_contents($bad, $bytes);
    expect(CountryDb::verify($bad))->toMatchArray(['ok' => false]);

    file_put_contents($this->fwDir.'/unsorted.csv', "94.200.0.0,94.200.255.255,AE\n1.0.0.0,1.0.0.255,AU\n");
    expect(fn () => CountryDb::build([$this->fwDir.'/unsorted.csv'], $this->fwDir.'/u.bin', 20261001))
        ->toThrow(RuntimeException::class);
});

it('Protect: refuses a bot that posts to the cart without loading a page, and lets a browser through unnoticed', function () {
    /*
     * DEFECT: a script posts add-to-cart / COD orders from fresh Chinese
     * addresses many times a second, never loading a page. Or the other way:
     * a real shopper in Singapore cannot add to cart. MUTATION: drop the
     * proofAge() check in before() and the bot's POST is 200; stop
     * attachProof() in after() and the browser's POST is 403.
     */
    $p = fwProduct();
    FirewallConfig::save(['mode' => 'enforce']);

    // The bot: straight to the POST.
    fwVisitor(FW_CN)->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403)->assertJson(['blocked' => true]);

    // The browser: opens the page (from an ad, with a gclid), gets the proof
    // as a Set-Cookie on the page it asked for, sends it back with the add.
    $page = fwVisitor(FW_SG)->get('/product/'.$p->slug.'/?gclid=Cj0KCQjw-test')->assertOk();
    $proof = fwProof($page);
    expect($proof)->not->toBeNull();
    $cookie = collect($page->headers->getCookies())->first(fn ($c) => $c->getName() === Firewall::COOKIE);
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');

    fwVisitor(FW_SG, FW_UA, [], [Firewall::COOKIE => $proof])
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    // Bound to the range it was issued to: replayed from elsewhere it fails —
    // and the refusal carries a fresh proof, so a shopper whose phone changed
    // network succeeds on the next tap.
    $r = fwVisitor('203.0.113.200', FW_UA, [], [Firewall::COOKIE => $proof])
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    $fresh = fwProof($r);
    expect($fresh)->not->toBeNull()->not->toBe($proof);
    fwVisitor('203.0.113.200', FW_UA, [], [Firewall::COOKIE => $fresh])
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    // Forged and expired proofs fail.
    fwVisitor(FW_SG, FW_UA, [], [Firewall::COOKIE => dechex(time()).'.'.str_repeat('A', 22)])
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);
    $this->travel(13)->hours();
    fwVisitor(FW_SG, FW_UA, [], [Firewall::COOKIE => $proof])
        ->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403);

    // An Allow country is untouched: no proof needed, none handed out, and
    // the page is byte-for-byte what it was with the firewall off.
    $on = fwVisitor(FW_AE)->get('/product/'.$p->slug.'/')->assertOk();
    expect(fwProof($on))->toBeNull();
    fwVisitor(FW_AE)->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    FirewallConfig::save(['mode' => 'off']);
    $off = fwVisitor(FW_AE)->get('/product/'.$p->slug.'/')->assertOk();
    $strip = fn (string $html): string => (string) preg_replace('/(csrf-token" content="|name="_token" value="|"csrf":")[^"]+/', '$1', $html);
    expect($strip((string) $on->getContent()))->toBe($strip((string) $off->getContent()));

    // A GET is never refused by Protect, however the visitor arrives.
    FirewallConfig::save(['mode' => 'enforce']);
    fwVisitor(FW_CN)->get('/product/'.$p->slug.'/')->assertOk();
    fwVisitor(FW_CN)->get('/cart')->assertOk();
});

it('refuses nothing in Monitor mode, and logs what it would have refused', function () {
    /*
     * DEFECT: "monitor" quietly enforces, and the owner's first day of
     * watching turns away real customers. MUTATION: replace `$enforce &&` with
     * `true &&` in the ban arm of before() and the flooded /cart is 429.
     */
    $p = fwProduct();
    $this->travelTo(now()->startOfMinute()->addSeconds(1));
    FirewallConfig::save(['ip_10s' => 20]);
    FirewallConfig::setCountries(['RU' => 'block']);
    expect(IpBlockList::compiled()['fw']['mode'])->toBe('monitor');

    fwBurst(FW_AE, 25, '/product/'.$p->slug.'/');
    fwVisitor(FW_AE)->get('/cart')->assertOk();
    fwVisitor(FW_CN)->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    fwVisitor('95.24.1.1')->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();

    $report = FirewallLog::report();
    $reasons = collect($report['reasons'])->keyBy('reason');
    expect($report['refused'])->toBe(0)
        ->and($reasons['flood']['logged'])->toBe(1)
        ->and($reasons['no_proof']['logged'])->toBe(1)
        ->and($reasons['country']['logged'])->toBe(1)
        ->and(Firewall::bans()[0]['monitor'])->toBeTrue();
});

it('never refuses the admin, webhooks, payment returns, .well-known, robots or the sitemap', function () {
    /*
     * DEFECT: a banned range (or a blocked country, scope "whole storefront")
     * catches the owner at the admin login, a gateway's "paid" webhook, a
     * customer coming back from Tabby, Apple Pay's domain check or Googlebot's
     * robots.txt. MUTATION: drop the NEVER pattern from Firewall::exempt() and
     * robots.txt answers 403.
     */
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);
    FirewallConfig::setCountries(['CN' => 'block']);

    fwVisitor(FW_CN)->get('/robots.txt')->assertOk();
    expect(fwVisitor(FW_CN)->get('/sitemap.xml')->status())->not->toBe(403);
    expect(fwVisitor(FW_CN)->get('/.well-known/apple-developer-merchantid-domain-association')->status())->not->toBe(403);
    expect(fwVisitor(FW_CN)->get('/checkout/success')->status())->not->toBe(403);
    expect(fwVisitor(FW_CN)->get('/checkout/pending')->status())->not->toBe(403);
    fwVisitor(FW_CN)->get(route('admin.login'))->assertOk();
    $hook = fwVisitor(FW_CN)->postJson('/api/payments/webhook/stripe/not-a-secret', []);
    expect($hook->status())->not->toBe(403)->and((string) $hook->getContent())->not->toContain('"blocked":true');

    // …while the shop itself is refused, which proves the rule is live.
    fwVisitor(FW_CN)->get('/')->assertStatus(403)->assertSee('Reference: FC', false);
});

it('lets the always-allow list through every rule, and validates what is typed into it', function () {
    /*
     * DEFECT: the owner's office shares a range with a bot farm and is banned
     * from his own shop — or "always allow 0.0.0.0/0" opens it to everyone.
     * MUTATION: drop `allow` from FirewallConfig::compile()'s skip map and
     * the office is 403; drop the MIN_PREFIX check and the /8 is accepted.
     */
    $p = fwProduct();
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site', 'ip_10s' => 20]);
    FirewallConfig::setCountries(['CN' => 'block']);
    fwVisitor(FW_CN)->get('/')->assertStatus(403);

    expect(FirewallConfig::allow('203.0.113.0/24', 'Office')['ok'])->toBeTrue();
    fwVisitor(FW_CN)->get('/')->assertOk();
    fwBurst(FW_CN, 40, '/product/'.$p->slug.'/');
    fwVisitor(FW_CN)->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
    expect(Firewall::bans())->toBe([]);

    expect(FirewallConfig::allow('not an address', null)['ok'])->toBeFalse()
        ->and(FirewallConfig::allow('10.0.0.0/8', null)['ok'])->toBeFalse()
        ->and(FirewallConfig::allow('2001:db8::/16', null)['ok'])->toBeFalse()
        ->and(FirewallConfig::allow('<script>', null)['ok'])->toBeFalse()
        ->and(FirewallConfig::allow('2001:db8:1::/48', '<b>x</b>')['ok'])->toBeTrue();

    FirewallConfig::disallow('203.0.113.0/24');
    fwVisitor(FW_CN)->get('/')->assertStatus(403);
});

it('gives every non-owner role a 403 on every Firewall endpoint, and the owner the screen', function () {
    /*
     * DEFECT: a support account switches the firewall off or unbans a bot
     * farm. MUTATION: move the firewall rules below 'admin-api/security/**'
     * in AdminCapabilities and they resolve to security.view.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/security/firewall'))->toBe('firewall.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/security/firewall/live'))->toBe('firewall.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/security/firewall'))->toBe('firewall.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/security/firewall/unban'))->toBe('firewall.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/security/firewall/data'))->toBe('firewall.manage');

    $posts = ['', '/countries', '/bots', '/allow', '/allow/remove', '/unban', '/data'];

    foreach (['manager', 'support', 'editor'] as $role) {
        $admin = fwAdmin($role);
        test()->actingAs($admin, 'admin')->getJson('/admin-api/security/firewall')->assertForbidden();
        test()->actingAs($admin, 'admin')->getJson('/admin-api/security/firewall/live')->assertForbidden();
        foreach ($posts as $path) {
            test()->actingAs($admin, 'admin')->postJson('/admin-api/security/firewall'.$path, ['values' => ['mode' => 'off']])->assertForbidden();
        }
    }

    expect(IpBlockList::compiled()['fw']['mode'])->toBe('monitor');
    app('auth')->forgetGuards();
    $owner = fwAdmin();

    $r = test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => FW_AE])->getJson('/admin-api/security/firewall')->assertOk();
    expect($r->json('fields.0.value'))->toBe('monitor')
        ->and(collect($r->json('countries'))->firstWhere('code', 'CN')['action'])->toBe('protect')
        ->and($r->json('my_ip'))->toBe(FW_AE)
        ->and($r->json('data.attribution.text'))->toBe('IP Geolocation by DB-IP');

    test()->actingAs($owner, 'admin')->withServerVariables(['REMOTE_ADDR' => FW_AE])
        ->postJson('/admin-api/security/firewall/allow', ['mine' => true])->assertOk()->assertJsonPath('cidr', FW_AE.'/32');
    test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall/allow', ['target' => '0.0.0.0/0'])->assertStatus(422);
    test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall', ['values' => ['mode' => 'enforce', 'scope' => 'everything', 'ip_10s' => 1]])->assertOk();

    $fw = IpBlockList::compiled()['fw'];
    expect($fw['mode'])->toBe('enforce')->and($fw['scope'])->toBe('commerce')->and($fw['ip_10s'])->toBe(20);

    // Off from the shell, whatever the screen says — and the marker file it
    // drops stops the firewall even where PHP-FPM's OPcache still holds the
    // old compiled "enforce" (simulated here by writing enforce back without
    // touching the marker). MUTATION: drop Firewall::killed() from BlockGate
    // and the flood below is banned.
    $this->artisan('kbb:firewall', ['action' => 'off'])->assertSuccessful();
    expect(IpBlockList::compiled()['fw']['mode'])->toBe('off')->and(Firewall::killed())->toBeTrue();
    $stale = IpBlockList::compiled();
    $stale['fw']['mode'] = 'enforce';
    $stale['fw']['ip_10s'] = 20;
    file_put_contents(IpBlockList::path(), '<?php return '.var_export($stale, true).';');
    IpBlockList::forget();
    $p = fwProduct();
    fwBurst(FW_AE, 25, '/product/'.$p->slug.'/');
    fwVisitor(FW_AE)->get('/cart')->assertOk();

    $this->artisan('kbb:firewall', ['action' => 'monitor'])->assertSuccessful();
    expect(Firewall::killed())->toBeFalse();
});

it('adds no database query to a shop page, in any mode', function () {
    /*
     * DEFECT: the firewall reads its rules, writes a log row or counts a
     * request in the database on every page — the slowest thing it could do
     * on the busiest path. MUTATION: replace FirewallLog::hit()'s bump() with
     * DB::table('firewall_log')->insert() and the Watch count goes up by one.
     */
    $p = fwProduct();
    $page = '/product/'.$p->slug.'/';
    $count = function (string $ip, array $cookies = []) use ($page): int {
        fwVisitor($ip)->get($page); // warm
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        fwVisitor($ip, FW_UA, [], $cookies)->get($page)->assertOk();
        app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };

    FirewallConfig::save(['mode' => 'off']);
    $off = $count(FW_SG);

    FirewallConfig::setCountries(['SG' => 'watch']);
    FirewallConfig::save(['mode' => 'monitor']);
    $monitor = $count(FW_SG);

    FirewallConfig::setCountries(['SG' => 'protect']);
    FirewallConfig::save(['mode' => 'enforce']);
    $enforce = $count(FW_SG);

    expect($monitor)->toBe($off)->and($enforce)->toBe($off);
});

it('reads the client address the way the app trusts proxies: a header cannot change it', function () {
    /*
     * DEFECT: a banned bot sends X-Forwarded-For: <Google's address> (or the
     * owner's) and walks through. MUTATION: trust proxies '*' in
     * bootstrap/app.php and the first request below is 200.
     */
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);
    FirewallConfig::setCountries(['CN' => 'block']);
    FirewallConfig::allow(FW_AE, 'office');

    foreach (['X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'CF-Connecting-IP', 'True-Client-IP', 'X-Client-IP', 'Forwarded'] as $h) {
        $value = $h === 'Forwarded' ? 'for='.FW_AE : FW_AE;
        fwVisitor(FW_CN, FW_UA, [$h => $value])->get('/')->assertStatus(403);
    }

    fwVisitor(FW_AE)->get('/')->assertOk();
});

it('fails open when its counter table cannot be written', function () {
    /*
     * DEFECT: a full disk or a permissions slip under storage/ turns every
     * shop page into a 500. MUTATION: remove the try/catch and the null
     * returns in FirewallCounters::update().
     */
    $p = fwProduct();
    FirewallConfig::save(['mode' => 'enforce', 'ip_10s' => 20]);
    $blocker = $this->fwDir.'/not-a-directory';
    file_put_contents($blocker, 'x');
    \App\Services\Security\FirewallCounters::useDir($blocker.'/table');

    for ($i = 0; $i < 25; $i++) {
        fwVisitor(FW_AE)->get('/product/'.$p->slug.'/')->assertOk();
    }
    fwVisitor(FW_AE)->get('/cart')->assertOk();
    expect(Firewall::bans())->toBe([]);
});

it('keeps the counter table at a fixed size, however many addresses come', function () {
    /*
     * DEFECT: one counter file per visitor per window (what Laravel's file
     * cache does with a windowed key) fills the disk during a botnet flood.
     * MUTATION: key the record by window as well as address, and the file
     * count grows past 256.
     */
    $p = fwProduct();
    FirewallConfig::save(['mode' => 'monitor']);
    for ($i = 1; $i <= 300; $i++) {
        fwVisitor('94.200.'.intdiv($i, 250).'.'.($i % 250 + 1))->get('/cart');
    }
    $files = glob(\App\Services\Security\FirewallCounters::dir().'/*') ?: [];
    expect(count($files))->toBeLessThanOrEqual(256);
    foreach ($files as $f) {
        expect(filesize($f))->toBeLessThanOrEqual(64 * 4 * 64);
    }
});

it('is wired exactly once: the routes from security-admin.php, the screen from the Security partial', function () {
    /*
     * DEFECT: built and never mounted (zero), or the sidebar row and the
     * window.go wrapper registered twice (two).
     */
    $sec = (string) file_get_contents(base_path('routes/security-admin.php'));
    $partial = (string) file_get_contents(resource_path('views/admin/partials/security-screen.blade.php'));

    expect(substr_count($sec, "require __DIR__.'/firewall-admin.php';"))->toBe(1)
        ->and(substr_count($partial, "@include('admin.partials.firewall-screen')"))->toBe(1)
        ->and(collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => $r->uri() === 'admin-api/security/firewall')->count())->toBe(2);

    $screen = (string) file_get_contents(resource_path('views/admin/partials/firewall-screen.blade.php'));
    expect($screen)->not->toContain('setInterval')->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth');
});
