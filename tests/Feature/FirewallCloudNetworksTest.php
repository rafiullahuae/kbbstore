<?php

declare(strict_types=1);

/**
 * Store → Security → Firewall → Cloud networks.                   (Lane FWN)
 *
 * The owner, fighting "visitors" from Singapore, the US, Hong Kong and Brazil
 * (95% desktop) that Live activity showed were Alibaba Cloud and Tencent Cloud
 * servers rotating addresses: offered "a ready list of the big cloud
 * companies' ranges (Alibaba, Tencent and similar), which no real shopper
 * uses", he said "yes please".
 *
 * Every case names the defect it would catch on the shop and the mutation
 * that turns it red. Addresses are the ones from the owner's own Live
 * activity (47.84.129.63 Alibaba SG, 43.173.182.215 Tencent SG) or read from
 * the bundled list itself, so the cases test the list that ships.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartService;
use App\Services\Security\CloudNetworks;
use App\Services\Security\CountryDb;
use App\Services\Security\FirewallConfig;
use App\Services\Security\FirewallLog;
use App\Services\Security\GoodBots;
use App\Services\Security\IpBlockList;
use App\Support\AdminCapabilities;
use App\Support\IpRange;
use Illuminate\Support\Facades\DB;

const FWN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
const FWN_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const FWN_ALIBABA = '47.84.129.63';     // Alibaba Cloud SG, from the owner's Live activity
const FWN_TENCENT = '43.173.182.215';   // Tencent Cloud SG, likewise
const FWN_AE = '94.200.10.20';          // the fixture's UAE: a shopper

beforeEach(function () {
    $dir = storage_path('framework/testing/fwn-'.getmypid());
    @mkdir($dir, 0775, true);
    $csv = $dir.'/countries.csv';
    file_put_contents($csv, implode("\n", [
        '43.172.0.0,43.173.255.255,SG',
        '47.84.0.0,47.84.255.255,SG',
        '94.200.0.0,94.200.255.255,AE',
        '2a00:1450::,2a00:1450:ffff:ffff:ffff:ffff:ffff:ffff,US',
    ])."\n");
    CountryDb::build([$csv], $dir.'/country.bin', 20261001);
    CountryDb::usePath($dir.'/country.bin');
    GoodBots::useRangesPath($dir.'/bot-ranges.php');
    $this->fwnDir = $dir;
});

afterEach(function () {
    CountryDb::forget();
    GoodBots::forget();
    CloudNetworks::forget();
    foreach (glob($this->fwnDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->fwnDir);
});

function fwnProduct(): Product
{
    return Product::create(['slug' => 'fwn-'.uniqid(), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 5900, 'stock_status' => 'instock']);
}

function fwnVisitor(string $ip, string $ua = FWN_UA, array $server = [])
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

    return test()->withCredentials()
        ->withServerVariables(['REMOTE_ADDR' => $ip] + $server)
        ->withHeaders(['User-Agent' => $ua])
        ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, 'fwn-'.uniqid());
}

function fwnAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => ucfirst($role).' FWN', 'email' => $role.'-fwn-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role]);
}

/** An address inside the first IPv4 range the bundled list holds for $provider. */
function fwnAddressOf(string $provider): string
{
    $cidr = CloudNetworks::verify(resource_path(CloudNetworks::BUNDLED))['providers'][$provider]['cidrs'][0];
    $net = (string) hex2bin(IpRange::parse($cidr)['network']);

    return inet_ntop(substr($net, 0, 3).chr(ord($net[3]) | 7));
}

/** An ipverse-shaped body. */
function fwnBody(string ...$cidrs): string
{
    return "# AS0 (TEST)\n# Test holder\n#\n".implode("\n", $cidrs)."\n";
}

it('refuses Alibaba, Tencent and Huawei on every shop page with scope "site", and only cart, checkout and forms with "commerce"', function () {
    /*
     * DEFECT: the owner's Analytics fills with Alibaba / Tencent "visitors"
     * because the providers he asked to block are not refused — or, with the
     * scope at "Cart, checkout and forms", a cloud address cannot even read a
     * product page the way a blocked country can. MUTATION: drop the cloud arm
     * from Firewall::before() and every 403 below is 200; drop `$fw['scope'] ===
     * 'site' ||` from it and the homepage is 200 with scope "site".
     */
    $p = fwnProduct();
    $huawei = fwnAddressOf('huawei');
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);
    expect(IpBlockList::compiled()['fw']['cloud'])->toBe('alibaba,tencent,huawei');

    foreach ([FWN_ALIBABA, FWN_TENCENT, $huawei] as $ip) {
        fwnVisitor($ip)->get('/')->assertStatus(403)
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('Reference: FN', false);
        fwnVisitor($ip)->get('/product/'.$p->slug.'/')->assertStatus(403);
    }

    // A shopper in the UAE is untouched.
    fwnVisitor(FWN_AE)->get('/')->assertOk();
    fwnVisitor(FWN_AE)->get('/product/'.$p->slug.'/')->assertOk();

    FirewallConfig::save(['scope' => 'commerce']);

    foreach ([FWN_ALIBABA, FWN_TENCENT, $huawei] as $ip) {
        fwnVisitor($ip)->get('/product/'.$p->slug.'/')->assertOk();
        fwnVisitor($ip)->get('/cart')->assertStatus(403);
        fwnVisitor($ip)->postJson('/api/cart/add', ['product_id' => $p->id])->assertStatus(403)->assertJsonPath('blocked', true);
    }

    fwnVisitor(FWN_AE)->postJson('/api/cart/add', ['product_id' => $p->id])->assertOk();
});

it('lets DigitalOcean through while it is on Allow, refuses it on Block, and lets it through again the moment it is switched back', function () {
    /*
     * DEFECT: a provider offered OFF is refused anyway, or switching one off
     * in an emergency waits for a cache to expire. MUTATION: make
     * FirewallConfig::cloudDefaults() return 'block' for every provider and
     * the first visit is 403; drop the CloudNetworks::compileFor() call from
     * IpBlockList::rebuild() and the switch-on is not enforced (the stale
     * file fails open).
     */
    $do = fwnAddressOf('digitalocean');
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);

    fwnVisitor($do)->get('/')->assertOk();

    expect(FirewallConfig::setClouds(['digitalocean' => 'block']))->toBe(1);
    fwnVisitor($do)->get('/')->assertStatus(403)->assertSee('Reference: FN', false);

    FirewallConfig::setClouds(['digitalocean' => 'allow', 'alibaba' => 'allow']);
    fwnVisitor($do)->get('/')->assertOk();
    fwnVisitor(FWN_ALIBABA)->get('/')->assertOk();
    expect(IpBlockList::compiled()['fw']['cloud'])->toBe('tencent,huawei');

    // And the shell's emergency off stops it with everything else.
    FirewallConfig::setClouds(['alibaba' => 'block']);
    fwnVisitor(FWN_ALIBABA)->get('/')->assertStatus(403);
    $this->artisan('kbb:firewall', ['action' => 'off'])->assertSuccessful();
    fwnVisitor(FWN_ALIBABA)->get('/')->assertOk();
});

it('never refuses a verified Googlebot inside a listed cloud range', function () {
    /*
     * DEFECT: Google crawls from an address that also sits in a blocked
     * provider's list and the shop drops out of search. MUTATION: move the
     * cloud arm above the good-bot check in Firewall::before() and the
     * Googlebot below is 403.
     */
    GoodBots::refresh(fn (string $url): ?string => str_contains($url, 'common-crawlers')
        ? json_encode(['prefixes' => [['ipv4Prefix' => '47.84.129.0/24']]])
        : null);
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);

    fwnVisitor(FWN_ALIBABA, FWN_GOOGLEBOT)->get('/')->assertOk();
    fwnVisitor(FWN_ALIBABA, FWN_GOOGLEBOT)->get('/cart')->assertOk();
    // Same address, a browser's name: refused, which proves the range is listed.
    fwnVisitor(FWN_ALIBABA)->get('/')->assertStatus(403);
});

it('never refuses an address on the owner\'s Always allow list, nor the server\'s own address', function () {
    /*
     * DEFECT: the owner's own monitoring or a partner's server on Alibaba is
     * shut out with no way to let it in; or a shop hosted on a listed cloud
     * refuses its own loopback calls. MUTATION: drop the `allow` entries from
     * FirewallConfig::compile()'s skip map and the first case is 403; drop
     * the SERVER_ADDR comparison in Firewall::before() and the second is.
     */
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);
    fwnVisitor(FWN_ALIBABA)->get('/')->assertStatus(403);

    expect(FirewallConfig::allow('47.84.129.0/24', 'Partner on Alibaba')['ok'])->toBeTrue();
    fwnVisitor(FWN_ALIBABA)->get('/')->assertOk();

    fwnVisitor(FWN_TENCENT, FWN_UA, ['SERVER_ADDR' => FWN_TENCENT])->get('/')->assertOk();
    fwnVisitor(FWN_TENCENT)->get('/')->assertStatus(403);
});

it('never refuses webhooks, payment returns or the admin from a blocked cloud', function () {
    /*
     * DEFECT: Stripe, Tabby or Tamara (or a customer back from paying) on an
     * address a provider list covers is refused and an order is lost.
     * MUTATION: move the cloud arm above `self::exempt($route)` in
     * Firewall::before() and /checkout/success is 403.
     */
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'site']);

    expect(fwnVisitor(FWN_ALIBABA)->get('/checkout/success')->status())->not->toBe(403);
    expect(fwnVisitor(FWN_ALIBABA)->get('/checkout/pending')->status())->not->toBe(403);
    $paid = fwnVisitor(FWN_ALIBABA)->postJson('/checkout/card/paid', []);
    expect($paid->status())->not->toBe(403)->and((string) $paid->getContent())->not->toContain('"blocked":true');
    fwnVisitor(FWN_ALIBABA)->get(route('admin.login'))->assertOk();
    $hook = fwnVisitor(FWN_ALIBABA)->postJson('/api/payments/webhook/stripe/not-a-secret', []);
    expect($hook->status())->not->toBe(403)->and((string) $hook->getContent())->not->toContain('"blocked":true');
    fwnVisitor(FWN_ALIBABA)->get('/robots.txt')->assertOk();

    fwnVisitor(FWN_ALIBABA)->get('/')->assertStatus(403);
});

it('logs a cloud network in Monitor without refusing it, named by provider, with its country, address and range', function () {
    /*
     * DEFECT: Monitor quietly enforces the new rule, or Live activity cannot
     * say which provider a refusal came from. MUTATION: replace `if
     * ($enforce)` with `if (true)` in the cloud arm and the page is 403;
     * drop the net_ labels from FirewallLog::reasons() and bucketRows() drops
     * the rows (the report is empty).
     */
    FirewallConfig::save(['mode' => 'monitor', 'scope' => 'site']);

    fwnVisitor(FWN_ALIBABA)->get('/')->assertOk();
    fwnVisitor(FWN_ALIBABA)->get('/')->assertOk();
    fwnVisitor(FWN_TENCENT)->get('/')->assertOk();

    $r = FirewallLog::report();
    $reasons = collect($r['reasons'])->keyBy('reason');
    expect($r['refused'])->toBe(0)
        ->and($reasons['net_alibaba']['logged'])->toBe(2)
        ->and($reasons['net_alibaba']['label'])->toBe('Cloud network (Alibaba Cloud)')
        ->and($reasons['net_tencent']['label'])->toBe('Cloud network (Tencent Cloud)')
        ->and(collect($r['countries'])->firstWhere('country', 'SG')['hits'])->toBe(3)
        ->and(collect($r['ips'])->firstWhere('ip', FWN_ALIBABA)['reasons'])->toBe(['net_alibaba'])
        ->and(collect($r['nets'])->firstWhere('net', '47.84.129.0/24')['hits'])->toBe(2);
});

it('keeps the previous list when a download fails, comes back empty or shrinks by more than half', function () {
    /*
     * DEFECT: one bad night at the data source empties the list (every bot
     * walks back in) or replaces it with garbage. MUTATION: drop the `$error
     * !== null` arm in CloudNetworks::refresh() and the failed provider's
     * ranges are written as none; drop the half-size check and the shrunken
     * list replaces the good one.
     */
    CloudNetworks::useSourcePath($this->fwnDir.'/cloud.txt', false);
    $good = function (string $url): ?string {
        if (str_contains($url, 'stat.ripe.net')) {
            return null;
        }
        preg_match('#/as/(\d+)/ipv(\d)#', $url, $m);

        $o = $m[1] % 200;

        return $m[2] === '4'
            ? fwnBody("47.$o.0.0/16", "8.$o.0.0/20", "5.$o.0.0/16", "31.$o.0.0/16", "37.$o.0.0/16", '10.0.0.0/8', '0.0.0.0/0', 'junk')
            : fwnBody('2400:'.dechex((int) $m[1] % 60000).'::/32');
    };

    $report = CloudNetworks::refresh($good);
    expect(collect($report)->every(fn ($r) => $r['ok']))->toBeTrue()
        ->and($report['alibaba']['ranges'])->toBe(24); // 4 ASNs x 6 clean ranges (the private /8, the /0 and the junk dropped)
    $before = file_get_contents($this->fwnDir.'/cloud.txt');

    // Everything fails: nothing is written.
    $report = CloudNetworks::refresh(fn () => null);
    expect(collect($report)->every(fn ($r) => ! $r['ok']))->toBeTrue()
        ->and(file_get_contents($this->fwnDir.'/cloud.txt'))->toBe($before)
        ->and(CloudNetworks::about()['providers']['alibaba']['ranges'])->toBe(24);

    // One provider fails (Tencent's first ASN answers garbage), another
    // shrinks to one range: both keep theirs, the rest refresh.
    $report = CloudNetworks::refresh(function (string $url) use ($good): ?string {
        if (str_contains($url, '/132203/')) {
            return '<html>rate limited</html>';
        }
        if (str_contains($url, '/24940/')) {
            return str_contains($url, 'ipv4') ? fwnBody('5.9.0.0/16') : null;
        }

        return $good($url);
    });
    expect($report['tencent']['ok'])->toBeFalse()
        ->and($report['hetzner']['ok'])->toBeFalse()
        ->and($report['alibaba']['ok'])->toBeTrue();
    $about = CloudNetworks::about()['providers'];
    expect($about['tencent']['ranges'])->toBe(18)->and($about['hetzner']['ranges'])->toBe(6);

    // RIPEstat answers when ipverse does not.
    $report = CloudNetworks::refresh(function (string $url) use ($good): ?string {
        if (str_contains($url, 'raw.githubusercontent.com') && str_contains($url, '/16276/')) {
            return null;
        }
        if (str_contains($url, 'stat.ripe.net') && str_contains($url, 'AS16276')) {
            return json_encode(['data' => ['prefixes' => [['prefix' => '51.68.0.0/16'], ['prefix' => '51.75.0.0/16'], ['prefix' => '2001:41d0::/32'], ['prefix' => '192.168.0.0/16']]]]);
        }

        return $good($url);
    });
    expect($report['ovh']['ok'])->toBeTrue()->and($report['ovh']['ranges'])->toBe(3);
});

it('ships a bundled list that loads, verifies, holds the owner\'s addresses and none that must never be blocked', function () {
    /*
     * DEFECT: the package is applied on a server that cannot download, and
     * the "yes please" blocks nothing — or the list carries a private range,
     * Cloudflare or the proxy and shuts the shop. MUTATION: change one
     * character of resources/firewall/cloud-networks.txt and verify() fails;
     * remove IpRange::protectedRange() from CloudNetworks::clean() and a
     * tampered list with 10.0.0.0/8 loads.
     */
    $v = CloudNetworks::verify(resource_path(CloudNetworks::BUNDLED));
    expect($v['ok'])->toBeTrue();

    $counts = array_map(fn ($p) => count($p['cidrs']), $v['providers']);
    expect($counts['alibaba'])->toBeGreaterThan(500)
        ->and($counts['tencent'])->toBeGreaterThan(150)
        ->and($counts['huawei'])->toBeGreaterThan(200)
        ->and(array_keys($counts))->toEqualCanonicalizing(array_keys(CloudNetworks::PROVIDERS));

    foreach ($v['providers'] as $key => $p) {
        foreach ($p['cidrs'] as $cidr) {
            expect(IpRange::protectedRange($cidr))->toBeNull();
        }
        // Every ASN in the file is one the provider is declared with.
        expect(array_diff(array_keys($p['asns']), CloudNetworks::PROVIDERS[$key][1]))->toBe([]);
    }

    // No AWS, Google or Microsoft network is offered at all.
    foreach ([16509, 14618, 15169, 396982, 8075] as $asn) {
        foreach (CloudNetworks::PROVIDERS as $p) {
            expect(in_array($asn, $p[1], true))->toBeFalse();
        }
    }

    // A tampered copy is refused whole; an honest re-sign of a private range too.
    $copy = $this->fwnDir.'/tampered.txt';
    file_put_contents($copy, str_replace('alibaba AS45102 8.208.0.0/16', 'alibaba AS45102 8.208.0.0/15', (string) file_get_contents(resource_path(CloudNetworks::BUNDLED))));
    expect(CloudNetworks::verify($copy)['ok'])->toBeFalse();
    file_put_contents($copy, CloudNetworks::serialise(['alibaba' => ['lines' => [[45102, '10.0.0.0/8']]]], '2026-10-19'));
    expect(CloudNetworks::verify($copy)['ok'])->toBeFalse();
    file_put_contents($copy, CloudNetworks::serialise(['alibaba' => ['lines' => [[45102, '8.208.0.0/16']]]], '2026-10-19'));
    expect(CloudNetworks::verify($copy)['ok'])->toBeTrue();

    // And it is what the request path uses when nothing was downloaded.
    expect(CloudNetworks::about()['from'])->toBe('bundled');
    CloudNetworks::compileFor('alibaba,tencent,huawei', true);
    expect(CloudNetworks::match((string) IpRange::pack(FWN_ALIBABA), 'alibaba,tencent,huawei'))->toBe('alibaba')
        ->and(CloudNetworks::match((string) IpRange::pack(FWN_TENCENT), 'alibaba,tencent,huawei'))->toBe('tencent')
        ->and(CloudNetworks::match((string) IpRange::pack('2400:3200::1'), 'alibaba,tencent,huawei'))->toBe('alibaba')
        ->and(CloudNetworks::match((string) IpRange::pack(FWN_AE), 'alibaba,tencent,huawei'))->toBeNull()
        ->and(CloudNetworks::match((string) IpRange::pack('8.8.8.8'), 'alibaba,tencent,huawei'))->toBeNull()
        // Built for another set: fails open rather than refusing from a stale list.
        ->and(CloudNetworks::match((string) IpRange::pack(FWN_ALIBABA), 'tencent'))->toBeNull();
});

it('costs a shop page no query and a few microseconds, however many ranges are on Block', function () {
    /*
     * DEFECT: the new rule reads the list from the database, the settings or
     * the network on every request, or parses the text file per request.
     * MUTATION: make CloudNetworks::match() call load() (the text parser)
     * and the per-lookup time below goes from microseconds to milliseconds;
     * add a DB read to it and the query counts differ.
     */
    $p = fwnProduct();
    $page = '/product/'.$p->slug.'/';
    FirewallConfig::save(['mode' => 'enforce', 'scope' => 'commerce']);

    $count = function (string $ip) use ($page): int {
        fwnVisitor($ip)->get($page); // warm
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        fwnVisitor($ip)->get($page)->assertOk();
        app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $n;
    };

    $none = $count(FWN_AE);
    FirewallConfig::setClouds(array_fill_keys(array_keys(CloudNetworks::PROVIDERS), 'block'));
    expect($count(FWN_AE))->toBe($none)->and($count(FWN_ALIBABA))->toBe($none);

    // Every provider on Block: thousands of ranges, one binary search.
    $for = IpBlockList::compiled()['fw']['cloud'];
    $bins = array_map(fn ($ip) => (string) IpRange::pack($ip), [FWN_ALIBABA, FWN_TENCENT, FWN_AE, '2400:3200::1', '2a00:1450::1']);
    CloudNetworks::match($bins[0], $for);
    $t = hrtime(true);
    for ($i = 0; $i < 2000; $i++) {
        CloudNetworks::match($bins[$i % 5], $for);
    }
    $perLookupUs = (hrtime(true) - $t) / 2000 / 1000;
    expect($perLookupUs)->toBeLessThan(50.0);
});

it('draws the Cloud networks tab, saves Block and Allow, refuses any other value, and is the owner\'s alone', function () {
    /*
     * DEFECT: the switches are missing, a forged value ("maybe", a provider
     * that is not on the list) is stored and read back as something else, or
     * a support account unblocks Alibaba. MUTATION: drop the ACTIONS check in
     * FirewallConfig::setClouds() and "maybe" is saved (200); remove the
     * firewall rules from AdminCapabilities and the support POST is 200.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/security/firewall/clouds'))->toBe('firewall.manage');

    $support = fwnAdmin('support');
    test()->actingAs($support, 'admin')->postJson('/admin-api/security/firewall/clouds', ['clouds' => ['alibaba' => 'allow']])->assertForbidden();
    app('auth')->forgetGuards();

    $owner = fwnAdmin();
    $r = test()->actingAs($owner, 'admin')->getJson('/admin-api/security/firewall')->assertOk();
    $rows = collect($r->json('clouds'))->keyBy('key');
    expect($rows->keys()->all())->toBe(array_keys(CloudNetworks::PROVIDERS))
        ->and($rows['alibaba']['action'])->toBe('block')
        ->and($rows['tencent']['action'])->toBe('block')
        ->and($rows['huawei']['action'])->toBe('block')
        ->and($rows['digitalocean']['action'])->toBe('allow')
        ->and($rows['alibaba']['ranges'])->toBeGreaterThan(500)
        ->and($rows['alibaba']['asns'])->toContain('AS45102')
        ->and($r->json('clouds_not_offered'))->toContain('AWS')
        ->and($r->json('data.clouds.from'))->toBe('bundled')
        ->and($r->json('data.clouds.ok'))->toBeTrue();

    foreach ([['alibaba' => 'maybe'], ['aws' => 'block'], ['alibaba' => ['block']], [], 'block'] as $bad) {
        test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall/clouds', ['clouds' => $bad])->assertStatus(422);
    }
    // Nothing of a refused save is applied, not even its valid half.
    test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall/clouds', ['clouds' => ['ovh' => 'block', 'alibaba' => 'maybe']])->assertStatus(422);
    expect(FirewallConfig::all()['clouds']['ovh'])->toBe('allow')
        ->and(DB::table('firewall_rules')->where('kind', 'cloud')->count())->toBe(0);

    test()->actingAs($owner, 'admin')->postJson('/admin-api/security/firewall/clouds', ['clouds' => ['alibaba' => 'allow', 'ovh' => 'block']])
        ->assertOk()->assertJsonPath('ok', true);
    expect(FirewallConfig::all()['clouds']['alibaba'])->toBe('allow')
        ->and(IpBlockList::compiled()['fw']['cloud'])->toBe('tencent,huawei,ovh');

    // A row damaged in the table reads as the shipped default, never as Block.
    DB::table('firewall_rules')->where('kind', 'cloud')->where('subject', 'ovh')->update(['value' => '<script>']);
    expect(FirewallConfig::all()['clouds']['ovh'])->toBe('allow');

    // The screen: the tab, the switches, the Data-tab button — once each.
    $blade = (string) file_get_contents(resource_path('views/admin/partials/firewall-screen.blade.php'));
    expect(substr_count($blade, "['clouds', 'Cloud networks']"))->toBe(1)
        ->and(substr_count($blade, "data-fwl-cloud=\"' + esc(c.key)"))->toBe(1)
        ->and(substr_count($blade, 'data-fwl-data="clouds"'))->toBe(1)
        ->and(substr_count($blade, "api('/clouds'"))->toBe(1);
    $routes = (string) file_get_contents(base_path('routes/security-admin.php'));
    expect(substr_count($routes, "Route::post('/security/firewall/clouds'"))->toBe(1);
});

it('reports and refreshes from the shell', function () {
    /*
     * DEFECT: the owner (or the next lane) has no way to see from SSH what is
     * blocked, or `kbb:firewall cloud refresh` is not wired. MUTATION: drop
     * the 'cloud' arm of FirewallCommand::handle() and both calls fail.
     */
    $this->artisan('kbb:firewall', ['action' => 'cloud'])
        ->expectsOutputToContain('Alibaba Cloud')
        ->assertSuccessful();

    CloudNetworks::useSourcePath($this->fwnDir.'/cloud-cli.txt', false);
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response("# x\n203.0.113.0/24\n", 200)]);
    // Only documentation space comes back: nothing usable, nothing written, exit non-zero.
    $this->artisan('kbb:firewall', ['action' => 'cloud', 'target' => 'refresh'])->assertFailed();
    expect(is_file($this->fwnDir.'/cloud-cli.txt'))->toBeFalse();
});
