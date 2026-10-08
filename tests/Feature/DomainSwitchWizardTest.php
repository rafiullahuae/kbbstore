<?php

declare(strict_types=1);

/*
 * Platform → Domain switch (Lane DW). The owner, 7 October 2026: "i'm non
 * technical, so avoid anything for me to do." Every in-shop step of
 * docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md is a button on one screen; these are
 * the server halves of those buttons, each with the refusal that keeps it from
 * doing harm, and the network checks with the world faked at the boundary
 * (Http::fake for the resolver and the TLS fetch, a stand-in for
 * dns_get_record) -- nothing here reaches the network.
 */

use App\Http\Controllers\Admin\TabbyWebhookController;
use App\Http\Controllers\Admin\TamaraAdminController;
use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DomainMove\DnsLookup;
use App\Services\DomainMove\DomainReadiness;
use App\Services\DomainMove\DomainSwitch;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\Payments\GatewayCredentials;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSwitchRoutes;

const DW_OLD = 'https://extrabeauty.ae';
const DW_NEW = 'https://kbeautybliss.com';

function dwOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'DW '.$role, 'email' => 'dw-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** The shop as it is today: served on extrabeauty.ae, nothing configured for the move. */
function dwToday(array $settings = []): void
{
    config(['app.url' => DW_OLD]);

    foreach ($settings + [
        SiteHost::KEY_CANONICAL => '', SiteHost::KEY_ALIASES => '', SiteHost::KEY_REDIRECT => '0',
        SiteHost::KEY_VISIBILITY => 'public', 'site_url' => DW_OLD,
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    Setting::flushMap();
    SiteHost::forget();
}

/** A .env of this test's own: the address switch writes one, and it must never be the worktree's. */
function dwSandboxEnv(string $appUrl = DW_OLD): string
{
    $dir = sys_get_temp_dir().'/kbb-dw-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/.env', "APP_KEY=base64:AAAA\nAPP_URL={$appUrl}\nDB_PASSWORD=\"keep me\"\n");
    app()->useEnvironmentPath($dir);

    return $dir.'/.env';
}

/**
 * The world's DNS, faked at the boundary: name => type => records. Every
 * question the shop asks is recorded, so the SSRF guard can be checked.
 */
function dwDns(array $zone): void
{
    Http::fake(function (ClientRequest $r) use ($zone) {
        if (! str_starts_with($r->url(), DnsLookup::DOH)) {
            return Http::response('unexpected '.$r->url(), 599);
        }

        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $records = $zone[$q['name']][$q['type']] ?? [];

        return Http::response(['Status' => 0, 'Answer' => array_map(fn ($v) => [
            'name' => $q['name'].'.', 'type' => DnsLookup::TYPES[$q['type']], 'TTL' => 300, 'data' => $v,
        ], $records)]);
    });
}

/** The records the checklist asks for, all present. */
function dwGoodZone(): array
{
    return [
        'kbeautybliss.com' => [
            'A' => [DomainSwitch::SERVER_IP], 'NS' => ['ns1.internet.bs.', 'ns2.internet.bs.'],
            'MX' => ['1 smtp.google.com.'], 'TXT' => ['"v=spf1 include:_spf.google.com ~all"'],
        ],
        'www.kbeautybliss.com' => ['CNAME' => ['kbeautybliss.com.']],
        'extrabeauty.ae' => ['A' => [DomainSwitch::SERVER_IP]],
    ];
}

function dwRun(array $body, string $origin = DW_OLD): \Illuminate\Testing\TestResponse
{
    return test()->postJson($origin.'/admin-api/domain-switch/run', $body);
}

beforeEach(function () {
    DomainSwitchRoutes::wire($this->app);
    Cache::flush();
    dwToday();
});

/* ═════════════════════════════════════════════════════ 1. who may open it */

it('maps every domain-switch endpoint to its own owner-only capability', function () {
    // DEFECT: a screen that writes APP_URL reachable by a manager. MUTATION:
    // give 'platform.domain_switch' => ['owner', 'manager'] and this is red.
    foreach ([['GET', 'admin-api/domain-switch'], ['GET', 'admin-api/domain-switch/readiness'],
        ['GET', 'admin-api/domain-switch/pictures'], ['POST', 'admin-api/domain-switch/run']] as [$m, $uri]) {
        expect(AdminCapabilities::forPath($m, $uri))->toBe('platform.domain_switch');
    }

    expect(AdminCapabilities::CAPABILITIES['platform.domain_switch'])->toBe(['owner']);

    foreach (['manager', 'support', 'editor'] as $role) {
        expect(AdminCapabilities::roleCan($role, 'platform.domain_switch'))->toBeFalse($role);
    }
});

it('refuses a manager, a support account and a signed-out visitor, and changes nothing for them', function () {
    // DEFECT: the button that writes the main address answering a non-owner.
    // MUTATION: delete the capability rule and the closed default still holds;
    // delete the refuse() call too and a custom role (next case) gets in.
    foreach (['manager', 'support'] as $role) {
        $this->actingAs(dwOwner($role), 'admin');
        $this->getJson(DW_OLD.'/admin-api/domain-switch')->assertForbidden();
        $this->getJson(DW_OLD.'/admin-api/domain-switch/readiness')->assertForbidden();
        dwRun(['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->assertForbidden();
    }

    auth('admin')->logout();
    expect($this->postJson(DW_OLD.'/admin-api/domain-switch/run', ['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->status())
        ->toBeIn([401, 403, 419]);

    Setting::flushMap();
    expect(SiteHost::canonical())->toBe('');
});

it('refuses a custom role that was handed the capability, because the buttons reach six other capabilities', function () {
    // DEFECT: one tick on a custom role becoming a side door to APP_URL, the
    // payment keys and the owner app address. MUTATION: make refuse() return
    // null and this role saves the main address.
    $id = DB::table('admin_roles')->insertGetId([
        'name' => 'Domain helper', 'slug' => 'domain-helper', 'tier' => 'manager',
        'capabilities' => json_encode(['admin.access', 'platform.domain_switch']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = dwOwner('manager');
    DB::table('admin_users')->where('id', $user->id)->update(['role_id' => $id]);
    \App\Support\AdminRoles::flush();

    $this->actingAs($user->fresh(), 'admin');
    dwRun(['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->assertForbidden()
        ->assertJsonPath('message', 'Only the owner can move the shop to another domain.');

    Setting::flushMap();
    expect(SiteHost::canonical())->toBe('');
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('admin_roles') || ! \Illuminate\Support\Facades\Schema::hasColumn('admin_users', 'role_id'), 'no custom roles table');

it('rejects an action that is not one of the buttons', function () {
    $this->actingAs(dwOwner(), 'admin');
    dwRun(['action' => 'drop_tables'])->assertStatus(422);
});

/* ═══════════════════════════════════════════════════ 2. state, from the shop */

it('reads the shop as it is today: everything to do, the switch locked until the new address answers', function () {
    $this->actingAs(dwOwner(), 'admin');
    $s = $this->getJson(DW_OLD.'/admin-api/domain-switch')->assertOk()->json();

    expect($s['target'])->toBe('kbeautybliss.com')
        ->and($s['old'])->toBe('extrabeauty.ae')
        ->and($s['steps']['names'])->toBe('todo')
        ->and($s['steps']['switch'])->toBe('todo')
        ->and($s['steps']['dns'])->toBe('todo')
        ->and($s['can']['switch'])->toBeFalse()
        ->and($s['can']['payments'])->toBeFalse()
        ->and($s['copy']['certificate'])->toBe('kbeautybliss.com, www.kbeautybliss.com, extrabeauty.ae, www.extrabeauty.ae')
        ->and($s['copy']['instagram'])->toBe('https://kbeautybliss.com/admin-api/instagram/callback')
        ->and($s['dns_table'][0])->toBe(['type' => 'A', 'name' => '@', 'value' => '134.209.147.13', 'extra' => 'TTL 300'])
        ->and(collect($s['dns_table'])->pluck('type')->all())->not->toContain('AAAA');
});

it('proves DNS and the certificate from the request itself once the owner is on the new address over https', function () {
    // No stored flag: being served this page over https on kbeautybliss.com IS the proof.
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $this->actingAs(dwOwner(), 'admin');

    $s = $this->getJson(DW_NEW.'/admin-api/domain-switch')->assertOk()->json();
    expect($s['steps']['dns'])->toBe('done')->and($s['steps']['tls'])->toBe('done')
        ->and($s['steps']['cloudways'])->toBe('done')->and($s['can']['switch'])->toBeTrue()
        ->and($s['proposed']['confirm'])->toBe(DW_NEW);

    // The www twin is not the main address: switching there would write
    // APP_URL=https://www.kbeautybliss.com beside a main address without www.
    expect($this->getJson('https://www.kbeautybliss.com/admin-api/domain-switch')->json('can.switch'))->toBeFalse();

    // Plain http on the same name proves nothing about the certificate.
    $s = $this->getJson('http://kbeautybliss.com/admin-api/domain-switch')->assertOk()->json();
    expect($s['steps']['tls'])->toBe('todo')->and($s['can']['switch'])->toBeFalse();
});

/* ═════════════════════════════════════════════════════════ 3. readiness */

it('runs the domain check in the request, finds a picture still on extrabeauty.ae, and points at the fix', function () {
    Product::query()->create(['name' => 'DW serum', 'slug' => 'dw-serum', 'price' => 1000, 'status' => 'published',
        'description' => '<img src="https://extrabeauty.ae/wp-content/uploads/2024/01/serum.jpg">']);
    $this->actingAs(dwOwner(), 'admin');

    $r = $this->getJson(DW_OLD.'/admin-api/domain-switch/readiness')->assertOk()->json();

    expect($r['complete'])->toBeTrue()
        ->and($r['risk'])->toBeGreaterThan(0)
        ->and($r['tables_checked'])->toBe($r['tables_total'])
        ->and($r['new'])->toBe('kbeautybliss.com')
        ->and($r['old'])->toContain('extrabeauty.ae');

    $picture = collect($r['findings'])->first(fn ($f) => str_contains($f['title'], 'still load from extrabeauty.ae'));
    expect($picture)->not->toBeNull()
        ->and($picture['level'])->toBe('risk')
        // Lane DS: an extrabeauty.ae address names a file on THIS server; step 6b
        // re-points it (Fetch, step 8, is for files only WordPress holds).
        ->and($picture['fix'])->toBe(['step' => '6b', 'label' => 'Point them at the new address (step 6b)'])
        ->and($picture['samples'][0])->toContain('https://extrabeauty.ae/wp-content/uploads/2024/01/serum.jpg');

    // The configuration half, in words, with the step that fixes each line.
    $main = collect($r['findings'])->firstWhere('title', 'The shop’s main address');
    expect($main['level'])->toBe('todo')->and($main['fix']['step'])->toBe(2);
});

it('stops at its time budget, says which part is unread, and finishes from where it stopped', function () {
    // DEFECT: a scan of every text column of every table inside a web request
    // with no bound. MUTATION: drop the deadline check in references() and the
    // zero-second run below reads every table and reports complete.
    $engine = new DomainReadiness('kbeautybliss.com', ['extrabeauty.ae']);

    DB::enableQueryLog();
    $refs = $engine->references(3, microtime(true) - 1);
    $counted = collect(DB::getQueryLog())->filter(fn ($q) => str_contains((string) $q['query'], 'SUM(CASE'))->count();
    DB::disableQueryLog();

    expect($refs)->toBe([])
        ->and($engine->tableCount())->toBeGreaterThan(10)
        ->and($engine->skipped())->toHaveCount($engine->tableCount());

    // Not one table was counted -- only the schema was listed.
    expect($counted)->toBe(0);

    // Through the screen: a zero budget is incomplete; a continuation finishes.
    $this->actingAs(dwOwner(), 'admin');
    $first = app(DomainSwitch::class)->readiness(0, 0.0);
    expect($first['complete'])->toBeFalse()->and($first['next_offset'])->toBe(0);

    $rest = $this->getJson(DW_OLD.'/admin-api/domain-switch/readiness?offset=5')->assertOk()->json();
    expect($rest['complete'])->toBeTrue()
        ->and($rest['tables_checked'])->toBe($rest['tables_total'] - 5)
        // A continuation does not repeat the configuration lines.
        ->and(collect($rest['findings'])->firstWhere('title', 'The shop’s main address'))->toBeNull();
});

it('reads the whole SQLite shop well inside its budget', function () {
    $started = microtime(true);
    $r = app(DomainSwitch::class)->readiness();

    expect($r['complete'])->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(DomainSwitch::READINESS_SECONDS);
});

/* ═════════════════════════════════════════════ 4. step 2: tell it the name */

it('sets main address, old addresses and forwarding off in one press, and a second press changes nothing', function () {
    $this->actingAs(dwOwner(), 'admin');

    $first = dwRun(['action' => 'set_names', 'domain' => 'https://kbeautybliss.com/'])->assertOk()->json();
    expect($first['ok'])->toBeTrue()->and($first['message'])->toContain('extrabeauty.ae works exactly as before')
        ->and($first['state']['steps']['names'])->toBe('done');

    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::canonical())->toBe('kbeautybliss.com')
        ->and(SiteHost::aliases())->toContain('extrabeauty.ae')->toContain('www.extrabeauty.ae')
        ->and(SiteHost::redirectEnabled())->toBeFalse()
        ->and(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::ALIAS);

    $before = Setting::query()->whereIn('key', [SiteHost::KEY_CANONICAL, SiteHost::KEY_ALIASES, SiteHost::KEY_REDIRECT])->pluck('value', 'key')->all();
    dwRun(['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->assertOk();
    expect(Setting::query()->whereIn('key', array_keys($before))->pluck('value', 'key')->all())->toBe($before);

    // Audit-logged like every other admin act.
    expect(DB::table('audit_events')->where('subject', 'domain_switch.set_names')->count())->toBeGreaterThan(0);
});

it('refuses the address being left, an IP and a sentence, and saves nothing', function () {
    $this->actingAs(dwOwner(), 'admin');

    foreach (['extrabeauty.ae', 'www.extrabeauty.ae', '127.0.0.1', 'my new shop', ''] as $typed) {
        dwRun(['action' => 'set_names', 'domain' => $typed])->assertStatus(422)->assertJsonPath('ok', false);
    }

    Setting::flushMap();
    expect(SiteHost::canonical())->toBe('');
});

it('does not switch forwarding back off when step 2 is pressed again after step 10', function () {
    // DEFECT: "pressing any button twice is harmless" -- a re-press of the
    // first save would silently stop forwarding the old domain. MUTATION:
    // always pass redirect=false from setNames() and this is red.
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '1']);
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->assertOk();

    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::redirectEnabled())->toBeTrue();
});

/* ═════════════════════════════════════════════════════════ 5. DNS checks */

it('reports DNS done when the A record is this server and there is no AAAA, and remembers it across a reload', function () {
    dwDns(dwGoodZone());
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_dns'])->assertOk()->json();
    expect($r['level'])->toBe('done')
        ->and($r['records']['A'])->toBe(['134.209.147.13'])
        ->and($r['records']['MX'])->toBe(['1 smtp.google.com'])
        ->and($r['records']['TXT'])->toBe(['v=spf1 include:_spf.google.com ~all'])
        ->and($r['records']['www'])->toBe(['kbeautybliss.com'])
        ->and($r['state']['steps']['dns'])->toBe('done');

    // A reload shows the same, without asking again.
    Http::fake();
    expect($this->getJson(DW_OLD.'/admin-api/domain-switch')->json('steps.dns'))->toBe('done');
    Http::assertNothingSent();
});

it('says in plain words that kbeautybliss.com still points at Hostinger', function () {
    $zone = dwGoodZone();
    $zone['kbeautybliss.com']['A'] = [DomainSwitch::PREVIOUS_IP];
    dwDns($zone);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_dns'])->assertOk()->json();
    expect($r['level'])->toBe('problem')
        ->and($r['message'])->toContain('still points at the old host (177.202.242.149)')
        ->and($r['message'])->toContain('134.209.147.13')
        ->and($r['state']['steps']['dns'])->toBe('problem');
});

it('flags an AAAA record even when the A record is right', function () {
    $zone = dwGoodZone();
    $zone['kbeautybliss.com']['AAAA'] = ['2606:4700::6810:84e5'];
    dwDns($zone);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_dns'])->assertOk()->json();
    expect($r['level'])->toBe('problem')->and($r['message'])->toContain('has an AAAA record (2606:4700::6810:84e5). Delete it');
});

it('says "not yet" for a name with no A record, and "could not ask" when nothing answers, never done', function () {
    dwDns([]);
    $this->actingAs(dwOwner(), 'admin');
    expect(dwRun(['action' => 'check_dns'])->json('level'))->toBe('todo');

    // The public resolver unreachable AND the server's own failing: a problem, not a pass.
    Http::fake(fn () => Http::failedConnection('cURL error 28: timed out'));
    app()->instance(DnsLookup::class, new class extends DnsLookup
    {
        protected function native(string $host, string $type): ?array
        {
            return null;
        }
    });
    $r = dwRun(['action' => 'check_dns'])->json();
    expect($r['level'])->toBe('problem')->and($r['message'])->toContain('could not be asked');
});

it('falls back to the server resolver when the public one cannot be reached', function () {
    Http::fake(fn () => Http::failedConnection('cURL error 7: refused'));
    app()->instance(DnsLookup::class, new class extends DnsLookup
    {
        protected function native(string $host, string $type): ?array
        {
            return $host === 'kbeautybliss.com' && $type === 'A' ? [DomainSwitch::SERVER_IP] : [];
        }
    });
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_dns'])->json();
    expect($r['level'])->toBe('done')->and($r['source'])->toBe('server');
});

it('only ever looks up the configured domains, whatever the request carries (no DNS probing)', function () {
    // DEFECT: a check button that resolves a name from the request body turns
    // the shop into a DNS probe of anything. MUTATION: build the queries from
    // $request->input('domain') and the hosts below appear in the log.
    $asked = [];
    Http::fake(function (ClientRequest $r) use (&$asked) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $asked[] = $q['name'] ?? $r->url();

        return Http::response(['Status' => 0, 'Answer' => []]);
    });

    // Even an APP_URL on an IP literal does not become a name to look up.
    config(['app.url' => 'http://10.0.0.5']);
    $this->actingAs(dwOwner(), 'admin');
    dwRun(['action' => 'check_dns', 'domain' => 'internal.corp.example', 'host' => '169.254.169.254'])->assertOk();
    dwRun(['action' => 'check_old_dns', 'domain' => 'internal.corp.example'])->assertOk();

    $allowed = app(DomainSwitch::class)->dnsHosts();
    expect($asked)->not->toBeEmpty()
        ->and(array_diff(array_unique($asked), $allowed))->toBe([])
        ->and($allowed)->not->toContain('10.0.0.5')->not->toContain('internal.corp.example');
});

it('reads where extrabeauty.ae points for step 3', function () {
    dwDns(dwGoodZone());
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_old_dns'])->assertOk()->json();
    expect($r['level'])->toBe('done')->and($r['message'])->toContain('extrabeauty.ae points at 134.209.147.13');
});

/* ═════════════════════════════════════════════════════ 6. the certificate */

it('reports the certificate valid when https://kbeautybliss.com/robots.txt answers, and fetches nothing else', function () {
    Http::fake(['kbeautybliss.com/robots.txt' => Http::response("User-agent: *\n", 200)]);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'check_tls', 'domain' => 'evil.example'])->assertOk()->json();
    expect($r['level'])->toBe('done')->and($r['message'])->toContain('valid');

    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $q) => $q->url() === 'https://kbeautybliss.com/robots.txt');
});

it('tells a missing certificate from a domain that does not resolve yet, in plain words', function () {
    $this->actingAs(dwOwner(), 'admin');

    $errors = ['cURL error 60: SSL: no alternative certificate subject name matches target host name',
        'cURL error 6: Could not resolve host: kbeautybliss.com', 'cURL error 7: Failed to connect'];
    Http::fake(function () use (&$errors) {
        return Http::failedConnection(array_shift($errors));
    });
    $r = dwRun(['action' => 'check_tls'])->json();
    expect($r['level'])->toBe('problem')->and($r['message'])->toContain('no valid certificate for kbeautybliss.com yet')
        ->and($r['message'])->not->toContain('cURL');

    expect(dwRun(['action' => 'check_tls'])->json('message'))->toContain('cannot be found yet');
    expect(dwRun(['action' => 'check_tls'])->json('message'))->toContain('Nothing answered at kbeautybliss.com');
});

/* ═════════════════════════════════════════ 7. step 6: switch the address */

it('refuses to switch the address from extrabeauty.ae, and touches nothing', function () {
    // The rule "Use this address" has always had: only on the address being adopted.
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $env = dwSandboxEnv();
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'switch_address', 'confirm' => DW_NEW])->assertStatus(409)->assertJsonPath('ok', false);

    expect(file_get_contents($env))->toContain('APP_URL='.DW_OLD)
        ->and(Setting::query()->where('key', 'site_url')->value('value'))->toBe(DW_OLD);
});

it('switches APP_URL, Site URL and clears caches on kbeautybliss.com, and a second press is harmless', function () {
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $env = dwSandboxEnv();
    Cache::put('dw-probe', 'stale', 600);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'switch_address', 'confirm' => DW_NEW], DW_NEW)->assertOk()->json();

    expect($r['ok'])->toBeTrue()->and($r['changed'])->toBeTrue()->and($r['caches_cleared'])->toBeTrue()
        ->and(file_get_contents($env))->toContain('APP_URL="https://kbeautybliss.com"')
        ->and(file_get_contents($env))->toContain('DB_PASSWORD="keep me"')
        ->and(Setting::query()->where('key', 'site_url')->value('value'))->toBe(DW_NEW)
        ->and(Cache::get('dw-probe'))->toBeNull()
        ->and($r['state']['steps']['switch'])->toBe('done')
        ->and($r['state']['can']['payments'])->toBeTrue();

    $again = dwRun(['action' => 'switch_address', 'confirm' => DW_NEW], DW_NEW)->assertOk()->json();
    expect($again['changed'])->toBeFalse()->and($again['state']['steps']['switch'])->toBe('done');
});

it('refuses a stale confirmation (a tab left open since before the DNS change)', function () {
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    $env = dwSandboxEnv();
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'switch_address', 'confirm' => DW_OLD], DW_NEW)->assertStatus(409);
    expect(file_get_contents($env))->toContain('APP_URL='.DW_OLD)
        ->and(Setting::query()->where('key', 'site_url')->value('value'))->toBe(DW_OLD);
});

/* ═════════════════════════════════════════════════════════ 8. payments */

it('will not tell a payment provider anything before the shop uses the new address', function () {
    Http::fake();
    $this->actingAs(dwOwner(), 'admin');

    foreach (['stripe', 'tabby', 'tamara'] as $p) {
        dwRun(['action' => $p])->assertStatus(409)->assertJsonPath('ok', false);
    }

    Http::assertNothingSent();
});

it('sets up the Stripe webhook through the existing action and removes the extrabeauty.ae one', function () {
    config(['app.url' => DW_NEW]);
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', 'site_url' => DW_NEW]);
    config(['app.url' => DW_NEW]);
    PaymentProvider::query()->updateOrCreate(['id' => 'stripe'], ['title' => 'Card', 'enabled' => true, 'mode' => 'test', 'position' => 0,
        'config' => ['publishable_key_test' => 'pk_test_dw', 'secret_key_test' => 'sk_test_dw', 'webhook_secret' => 'whsec-stripe-dw0123456789abcdefABCDEF']]);
    app(GatewayCredentials::class)->forget();

    $ours = app(\App\Services\Payments\StripeConnect::class)->webhookUrl();
    Http::fake([
        'api.stripe.com/v1/webhook_endpoints?limit=100' => Http::response(['data' => [
            ['id' => 'we_old', 'url' => str_replace(DW_NEW, DW_OLD, (string) $ours), 'enabled_events' => \App\Services\Payments\StripeConnect::EVENTS],
        ]]),
        'api.stripe.com/v1/webhook_endpoints/*' => Http::response(['id' => 'we_old', 'deleted' => true]),
        'api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_new', 'url' => $ours, 'secret' => 'whsec_new']),
        'api.stripe.com/v1/account' => Http::response(['id' => 'acct_1']),
    ]);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'stripe'])->assertOk()->json();
    expect($r['message'])->toContain('Stripe will now send payment notices to kbeautybliss.com')
        ->and($r['message'])->toContain('1 old webhook(s) removed')
        ->and($r['state']['steps']['payments']['stripe']['level'])->toBe('done');

    Http::assertSent(fn (ClientRequest $q) => $q->method() === 'DELETE' && str_ends_with($q->url(), '/we_old'));
});

it('passes a provider refusal through in plain words, as a problem, with no 500', function () {
    config(['app.url' => DW_NEW]);
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    config(['app.url' => DW_NEW]);
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::fake();
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'stripe'])->assertStatus(422)->json();
    expect($r['message'])->toStartWith('Stripe: There is no')
        ->and($r['state']['steps']['payments']['stripe']['level'])->toBe('problem');

    $t = dwRun(['action' => 'tamara'])->assertStatus(422)->json();
    expect($t['message'])->toContain('Tamara has no API token stored yet');

    expect(dwRun(['action' => 'tabby'])->status())->toBe(422);
    Http::assertNothingSent();
});

it('removes then registers Tamara, in that order, and says which half failed', function () {
    config(['app.url' => DW_NEW]);
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    config(['app.url' => DW_NEW]);

    $calls = new ArrayObject();
    $stub = new class($calls) extends TamaraAdminController
    {
        public bool $registerFails = false;

        public function __construct(private ArrayObject $calls) {}

        public function unregisterWebhook(): \Illuminate\Http\JsonResponse
        {
            $this->calls[] = 'remove';

            return response()->json(['ok' => true]);
        }

        public function registerWebhook(): \Illuminate\Http\JsonResponse
        {
            $this->calls[] = 'register';

            return $this->registerFails
                ? response()->json(['ok' => false, 'message' => 'Tamara refused the request. Nothing was changed.'], 502)
                : response()->json(['ok' => true, 'created' => true]);
        }
    };
    app()->instance(TamaraAdminController::class, $stub);
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'tamara'])->assertOk();
    expect($calls->getArrayCopy())->toBe(['remove', 'register']);

    $stub->registerFails = true;
    $r = dwRun(['action' => 'tamara'])->assertStatus(422)->json();
    expect($r['message'])->toContain('registering again failed')->and($r['message'])->toContain('Press this button again');
});

it('reports Tabby per country through its own allowlisted report', function () {
    config(['app.url' => DW_NEW]);
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    config(['app.url' => DW_NEW]);
    app()->instance(TabbyWebhookController::class, new class extends TabbyWebhookController
    {
        public function __construct() {}

        public function sync(): \Illuminate\Http\JsonResponse
        {
            return response()->json(['ok' => true, 'countries' => [['country' => 'AE', 'state' => 'registered', 'message' => 'Registered.']]]);
        }
    });
    $this->actingAs(dwOwner(), 'admin');

    expect(dwRun(['action' => 'tabby'])->assertOk()->json('message'))->toContain('AE: Registered.');
});

it('does not call Tabby done when a country refused, although its controller answers 200', function () {
    // DEFECT: TabbyWebhookController answers 200/ok whenever the call ran; a
    // wizard reading only `ok` showed ✓ over "Tabby refused to register this
    // shop". MUTATION: drop $failed from the condition in tabby() and this is 200.
    config(['app.url' => DW_NEW]);
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    config(['app.url' => DW_NEW]);
    app()->instance(TabbyWebhookController::class, new class extends TabbyWebhookController
    {
        public function __construct() {}

        public function sync(): \Illuminate\Http\JsonResponse
        {
            return response()->json(['ok' => true, 'countries' => [
                ['country' => 'AE', 'state' => 'failed', 'message' => 'Tabby refused to register this shop.'],
                ['country' => 'SA', 'state' => 'not_authorised', 'message' => 'This account is not authorised for this country.'],
            ]]);
        }
    });
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'tabby'])->assertStatus(422)->json();
    expect($r['message'])->toContain('AE: Tabby refused to register this shop.')
        ->and($r['state']['steps']['payments']['tabby']['level'])->toBe('problem');
});

/* ═══════════════════════════════════════════════════════════ 9. pictures */

it('counts pictures by where they are and fetches a bounded batch from WordPress with the importer\'s fetcher', function () {
    foreach (['wp-content', 'uploads'] as $root) {
        File::deleteDirectory(public_path($root));
    }

    Product::query()->create(['name' => 'DW toner', 'slug' => 'dw-toner', 'price' => 1000, 'status' => 'published',
        'image' => 'https://kbeautybliss.com/wp-content/uploads/2024/02/toner.jpg']);
    app()->instance(MediaSideloader::class, new MediaSideloader(new MediaAudit, null, fn (string $h) => ['93.184.216.34']));
    Http::fake(['kbeautybliss.com/wp-content/uploads/*' => Http::response("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01".str_repeat('*', 64)."\xFF\xD9", 200, ['Content-Type' => 'image/jpeg'])]);
    $this->actingAs(dwOwner(), 'admin');

    $before = $this->getJson(DW_OLD.'/admin-api/domain-switch/pictures')->assertOk()->json();
    expect($before['remote'])->toBe(1)->and($before['remaining'])->toBe(1);

    $r = dwRun(['action' => 'fetch_pictures'])->assertOk()->json();
    expect($r['message'])->toContain('Fetched 1 picture(s)')
        ->and($r['pictures']['remote'])->toBe(0)
        ->and(is_file(public_path('wp-content/uploads/2024/02/toner.jpg')))->toBeTrue();

    // Pressed again: nothing left, nothing fetched, no error.
    expect(dwRun(['action' => 'fetch_pictures'])->assertOk()->json('message'))->toContain('Fetched 0 picture(s)');

    foreach (['wp-content', 'uploads'] as $root) {
        File::deleteDirectory(public_path($root));
    }
});

/* ══════════════════════════════════════════ 10. forwarding, and the end */

it('switches forwarding on only after the address has moved', function () {
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'forward_on'])->assertStatus(409);
    Setting::flushMap();
    expect(SiteHost::redirectEnabled())->toBeFalse();

    config(['app.url' => DW_NEW]);
    $r = dwRun(['action' => 'forward_on'])->assertOk()->json();
    expect($r['state']['steps']['forward'])->toBe('done');
    dwRun(['action' => 'forward_on'])->assertOk();

    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::redirectEnabled())->toBeTrue()->and(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::ALIAS);
});

it('refuses to remove extrabeauty.ae while the check still finds RISK, and removes nothing', function () {
    // DEFECT: the old domain removed while a picture still loads from it.
    // MUTATION: skip the fullRisk() check in removeOld() and the alias goes.
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '1', 'site_url' => DW_NEW]);
    config(['app.url' => DW_NEW]);
    Product::query()->create(['name' => 'DW cream', 'slug' => 'dw-cream', 'price' => 1000, 'status' => 'published',
        'description' => '<a href="https://extrabeauty.ae/shop/cream/">old</a>']);
    $this->actingAs(dwOwner(), 'admin');

    $r = dwRun(['action' => 'remove_old', 'confirm' => 'REMOVE'])->assertStatus(409)->json();
    expect($r['message'])->toContain('Nothing was removed')->and($r['message'])->toContain('problem(s)');

    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::ALIAS);
});

it('refuses without the typed confirmation, and before the address has moved', function () {
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $this->actingAs(dwOwner(), 'admin');

    dwRun(['action' => 'remove_old'])->assertStatus(422);
    dwRun(['action' => 'remove_old', 'confirm' => 'REMOVE'])->assertStatus(409);

    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::classify('extrabeauty.ae'))->toBe(SiteHost::ALIAS);
});

it('removes the old addresses and an owner-app host on extrabeauty.ae when RISK is 0, and a second press is harmless', function () {
    dwToday([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '1', 'site_url' => DW_NEW]);
    config(['app.url' => DW_NEW]);
    OwnerAppPath::setHost('owner.extrabeauty.ae');
    $this->actingAs(dwOwner(), 'admin');

    expect(app(DomainSwitch::class)->fullRisk()['risk'])->toBe(0);

    $r = dwRun(['action' => 'remove_old', 'confirm' => 'REMOVE'])->assertOk()->json();
    expect($r['message'])->toContain('no longer knows extrabeauty.ae')->and($r['message'])->toContain('owner.extrabeauty.ae')
        ->and($r['state']['steps']['remove'])->toBe('done')
        ->and($r['state']['steps']['names'])->toBe('done');

    Setting::flushMap();
    SiteHost::forget();
    OwnerAppPath::forgetMemo();
    expect(SiteHost::classify('extrabeauty.ae'))->not->toBe(SiteHost::ALIAS)
        ->and(SiteHost::canonical())->toBe('kbeautybliss.com')
        ->and(OwnerAppPath::host())->toBeNull();

    dwRun(['action' => 'remove_old', 'confirm' => 'REMOVE'])->assertOk();

    // Afterwards the check calls the old addresses done, not "go to step 2",
    // and step 2 pressed again does not list the removed domain again.
    // MUTATION: drop the oldRemoved() guard in setNames() and the alias is back.
    $r = app(DomainSwitch::class)->readiness();
    expect(collect($r['findings'])->firstWhere('title', 'The old addresses the shop knows about')['level'])->toBe('ok')
        ->and($r['todo'])->toBe(0);

    dwRun(['action' => 'set_names', 'domain' => 'kbeautybliss.com'])->assertOk()->assertJsonPath('message', 'Nothing to do: kbeautybliss.com is the main address and extrabeauty.ae was removed in step 11.');
    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::classify('extrabeauty.ae'))->not->toBe(SiteHost::ALIAS);
});
