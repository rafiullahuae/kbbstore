<?php

declare(strict_types=1);

/*
 * Platform → Domain switch as ONE numbered installer (Lane DW2).
 *
 * The owner, 8 October 2026: "the migration steps are too confusing by not
 * mentioned from start to end as number wise, it's mixed ... step by step and
 * the system verify the changes etc. and also skip option if i will update
 * something later, like stripe live apis ... please don't assume on anything."
 *
 * What the shop looked like before: a screen numbered 1, 1b, 2 … 6, 6b, 7 … 11
 * beside a checklist numbered 1 … 6, 6a, 7 … 20, 20a, 21 … 28, so "step 7" was
 * the payments on one and the DNS on the other; checks that ran when the page
 * opened; no way to say "later"; a server address written into the code.
 *
 * The world (DNS, TLS, the old address's home page, the providers) is faked at
 * the boundary with Http::fake; nothing here reaches the network.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DomainMove\DnsLookup;
use App\Services\DomainMove\DomainSwitch;
use App\Services\DomainMove\SwitchInstaller;
use App\Services\Payments\PaymentsReadiness;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\ComingSoon;
use App\Support\SiteHost;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\DomainSwitchRoutes;

const DWI_OLD = 'https://extrabeauty.ae';
const DWI_NEW = 'https://kbeautybliss.com';

/** RFC 5737 documentation addresses: "this server" and somewhere else. */
const DWI_IP = '203.0.113.20';
const DWI_ELSEWHERE = '198.51.100.7';

function dwiOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'DWI '.$role, 'email' => 'dwi-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

/** @param  array<string, string>  $settings */
function dwiShop(array $settings = []): void
{
    config(['app.url' => DWI_OLD]);

    foreach ($settings + [
        SiteHost::KEY_CANONICAL => '', SiteHost::KEY_ALIASES => '', SiteHost::KEY_REDIRECT => '0',
        SiteHost::KEY_VISIBILITY => 'public', 'site_url' => DWI_OLD, ComingSoon::KEY_ON => '0',
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    Setting::flushMap();
    SiteHost::forget();
}

/** The shop after step 8: kbeautybliss.com is the main address and APP_URL. */
function dwiSwitched(array $extra = []): void
{
    dwiShop($extra + [SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', 'site_url' => DWI_NEW]);
    config(['app.url' => DWI_NEW]);
}

/** The records the installer asks for, all present and pointing here. */
function dwiGoodZone(): array
{
    return [
        'kbeautybliss.com' => ['A' => [DWI_IP], 'NS' => ['ns1.internet.bs.'], 'MX' => ['1 smtp.google.com.'], 'TXT' => ['"v=spf1 include:_spf.google.com ~all"']],
        'www.kbeautybliss.com' => ['CNAME' => ['kbeautybliss.com.']],
        'extrabeauty.ae' => ['A' => [DWI_IP]],
    ];
}

/**
 * The whole outside world, faked: the public resolver answers from $zone; an
 * https:// fetch of a name answers as $web says -- 'ok', 'cert' (no valid
 * certificate), 'nodns', or [status, body, headers]. Every request is logged.
 *
 * @return \ArrayObject<int, string> every URL the shop asked for
 */
function dwiWorld(array $zone, array $web = []): ArrayObject
{
    $log = new ArrayObject();

    // A fresh client: Http::fake() stubs accumulate, and the first one registered would keep answering.
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake(function (ClientRequest $r) use ($zone, $web, $log) {
        $log[] = $r->url();
        $host = (string) parse_url($r->url(), PHP_URL_HOST);

        if (str_starts_with($r->url(), DnsLookup::DOH)) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['Status' => 0, 'Answer' => array_map(fn ($v) => [
                'name' => $q['name'].'.', 'type' => DnsLookup::TYPES[$q['type']], 'TTL' => 300, 'data' => $v,
            ], $zone[$q['name']][$q['type']] ?? [])]);
        }

        $how = $web[$host] ?? 'nodns';

        return match (true) {
            $how === 'ok' => Http::response("User-agent: *\n", 200),
            $how === 'cert' => Http::failedConnection('cURL error 60: SSL: no alternative certificate subject name matches target host name'),
            is_array($how) => Http::response($how[1] ?? '', $how[0], $how[2] ?? []),
            default => Http::failedConnection('cURL error 6: Could not resolve host: '.$host),
        };
    });

    return $log;
}

function dwiStep(string $step, string $do = 'verify', string $origin = DWI_OLD, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson($origin.'/admin-api/domain-switch/step', ['step' => $step, 'do' => $do] + $extra);
}

function dwiRun(array $body, string $origin = DWI_OLD): \Illuminate\Testing\TestResponse
{
    return test()->postJson($origin.'/admin-api/domain-switch/run', $body);
}

/** One step as the screen receives it. */
function dwiRow(array $body, string $key): array
{
    $inst = $body['installer'] ?? $body['state']['installer'];

    return collect($inst['steps'])->firstWhere('key', $key);
}

function dwiGreen(string ...$steps): void
{
    foreach ($steps as $step) {
        app(SwitchInstaller::class)->write($step, ['status' => 'done', 'level' => 'green', 'verified_at' => now()]);
    }
}

beforeEach(function () {
    DomainSwitchRoutes::wire($this->app);
    \Illuminate\Support\Facades\Cache::flush();
    dwiShop();
});

/* ═════════════════════════════════════════════ 1. one list, one numbering */

it('numbers every step 1..N in one sequence, in the real order of work, with no letters', function () {
    // DEFECT: steps 1, 1b, 2 … 6, 6b, 7 on the screen. MUTATION: give a step
    // the number '6b' (or move 'cs_on' after 'dns_records') and this is red.
    $keys = array_keys(SwitchInstaller::STEPS);

    foreach ($keys as $i => $key) {
        expect(SwitchInstaller::num($key))->toBe($i + 1);
    }

    $before = fn (string $a, string $b) => expect(array_search($a, $keys, true))->toBeLessThan(array_search($b, $keys, true), "$a before $b");
    $before('start', 'name');
    $before('name', 'dns_records');        // the new name before DNS: else kbeautybliss.com is a stranger, noindexed
    $before('cs_on', 'dns_records');       // Coming Soon before anyone can reach it
    $before('dns_records', 'dns_wait');
    $before('dns_wait', 'ssl');
    $before('ssl', 'switch');
    $before('switch', 'caches');
    $before('switch', 'payments');         // providers are told the shop's own address
    $before('payments', 'tests');
    $before('tests', 'cs_off');
    $before('cs_off', 'forward');          // never forward customers into the Coming Soon page
    expect(end($keys))->toBe('done');

    // The screen draws the numbers it is given: no step number of its own, no letter.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));
    expect(preg_match_all('/\b(?:step|Step)\s+\d+[a-z]\b/', $screen))->toBe(0)
        ->and(preg_match_all("/step\\('\\d/", $screen))->toBe(0)
        ->and($screen)->not->toContain("'1b'")->not->toContain("'6b'");

    // Nor does a message on the server: "step 6b", "step 7 of this page" are gone.
    foreach (['app/Services/DomainMove/DomainSwitch.php', 'app/Http/Controllers/Admin/DomainSwitchApiController.php', 'app/Services/Payments/PaymentsReadiness.php', 'app/Services/DomainMove/SwitchInstaller.php'] as $file) {
        $src = (string) file_get_contents(base_path($file));
        expect(preg_match_all("/'[^'\\n]*\\bstep \\d+[a-z]?\\b[^'\\n]*'/", $src))->toBe(0, $file.' carries a written step number');
    }

    $this->actingAs(dwiOwner(), 'admin');
    $view = $this->getJson(DWI_OLD.'/admin-api/domain-switch')->assertOk()->json('installer');
    expect(array_column($view['steps'], 'n'))->toBe(range(1, count(SwitchInstaller::STEPS)))
        ->and($view['total'])->toBe(count(SwitchInstaller::STEPS))
        ->and($view['current'])->toBe(1);
});

it('keeps the checklist document to the same numbered steps as the screen', function () {
    // DEFECT: the checklist said "step 6a", "step 20a" while the screen said
    // 3 and 9. MUTATION: rename a title in STEPS, or swap two steps, and the
    // document no longer matches.
    $doc = (string) file_get_contents(base_path('docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md'));
    preg_match_all('/^## (\d+)\. (.+)$/m', $doc, $m, PREG_SET_ORDER);

    $docSteps = array_map(fn ($x) => [(int) $x[1], trim($x[2])], $m);
    $screenSteps = array_map(fn ($key) => [SwitchInstaller::num($key), SwitchInstaller::STEPS[$key][0]], array_keys(SwitchInstaller::STEPS));

    expect($docSteps)->toBe($screenSteps)
        // No lettered step survives anywhere in the document.
        ->and(preg_match_all('/\b\d+[a-z]\.\s/', $doc))->toBe(0)
        ->and($doc)->toContain('# Switching the shop to kbeautybliss.com: the '.count(SwitchInstaller::STEPS).' steps');

    // Every step the screen cannot skip says so in the document.
    foreach (array_keys(SwitchInstaller::STEPS) as $key) {
        $n = SwitchInstaller::num($key);
        preg_match('/^## '.$n.'\. .+?\n\n(.+?)(?=\n## |\z)/ms', $doc, $section);
        expect(str_contains($section[1] ?? '', '(cannot be skipped)'))->toBe(SwitchInstaller::critical($key), "step $n");
    }
});

/* ═════════════════════════════════════════════════════ 2. the verifiers */

it('verifies step 1: green with the server address learned, red on RISK, amber when the old address does not answer', function () {
    $this->actingAs(dwiOwner(), 'admin');

    dwiWorld(dwiGoodZone());
    $r = dwiStep('start')->assertOk()->json();
    expect($r['level'])->toBe('green')
        ->and($r['message'])->toContain('This server’s address: '.DWI_IP)
        ->and(dwiRow($r, 'start')['status'])->toBe('done')
        ->and($r['installer']['server_ips'])->toBe([DWI_IP])
        // Step 5's A record is what step 1 learned -- never a constant.
        ->and($r['dns_table'][0]['value'])->toBe(DWI_IP);

    // The first Verify stored its own words ("where extrabeauty.ae points") in
    // the progress table. The readiness check reads every table, and once read
    // them as a RISK mention of the old domain -- step 1 turning itself red.
    // MUTATION: drop 'domain_switch_progress' from DomainReadiness::HISTORY.
    dwiWorld([]);
    $r = dwiStep('start')->assertOk()->json();
    expect($r['message'])->toContain('nothing found that breaks on the switch (RISK 0)')
        ->and($r['level'])->toBe('amber')->and($r['message'])->toContain('extrabeauty.ae points nowhere')
        ->and(dwiRow($r, 'start')['status'])->toBeNull();

    Product::query()->create(['name' => 'DWI serum', 'slug' => 'dwi-serum', 'price' => 1000, 'status' => 'published',
        'description' => '<img src="https://extrabeauty.ae/wp-content/uploads/2024/01/serum.jpg">']);
    dwiWorld(dwiGoodZone());
    $r = dwiStep('start')->assertOk()->json();
    expect($r['level'])->toBe('red')->and($r['message'])->toContain('would break on the switch')
        ->and(dwiRow($r, 'start')['data']['risk'])->toBeGreaterThan(0);
});

it('verifies step 2 (the new name): red, amber, green', function () {
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();

    expect(dwiStep('name')->json('level'))->toBe('red');

    dwiShop([SiteHost::KEY_CANONICAL => 'kbeautybliss.com']);
    expect(dwiStep('name')->json())->level->toBe('amber')->message->toContain('extrabeauty.ae is not listed');

    dwiShop([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    expect(dwiStep('name')->json('level'))->toBe('green');

    Http::assertNothingSent();   // a local check
});

it('verifies step 3 (Coming Soon on): red when off, amber when it hides every address, green for the new one only', function () {
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();

    expect(dwiStep('cs_on')->json('level'))->toBe('red');

    dwiShop([ComingSoon::KEY_ON => '1', ComingSoon::KEY_SCOPE => 'all']);
    expect(dwiStep('cs_on')->json())->level->toBe('amber')->message->toContain('EVERY address');

    dwiShop([ComingSoon::KEY_ON => '1', ComingSoon::KEY_SCOPE => 'host', ComingSoon::KEY_HOST => 'kbeautybliss.com']);
    expect(dwiStep('cs_on')->json('level'))->toBe('green');
});

it('verifies step 5 (DNS records): green, red without the mail records, amber when the resolver cannot be asked', function () {
    $this->actingAs(dwiOwner(), 'admin');

    dwiWorld(dwiGoodZone());
    expect(dwiStep('dns_records')->json('level'))->toBe('green');

    $zone = dwiGoodZone();
    unset($zone['kbeautybliss.com']['MX']);
    dwiWorld($zone);
    expect(dwiStep('dns_records')->json())->level->toBe('red')->message->toContain('email to @kbeautybliss.com would stop arriving');

    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake(fn () => Http::failedConnection('cURL error 28: timed out'));
    app()->instance(DnsLookup::class, new class extends DnsLookup
    {
        protected function native(string $host, string $type): ?array
        {
            return null;
        }
    });
    expect(dwiStep('dns_records')->json('level'))->toBe('amber');
});

it('verifies step 6 (DNS) against the address the old domain resolves to: green, amber while still Hostinger, red elsewhere or with AAAA', function () {
    // DEFECT: a hard-coded server address. MUTATION: compare with a constant
    // and the "elsewhere" case below, which is right for this zone, goes red.
    $this->actingAs(dwiOwner(), 'admin');

    dwiWorld(dwiGoodZone());
    $r = dwiStep('dns_wait')->assertOk()->json();
    expect($r['level'])->toBe('green')->and($r['message'])->toContain(DWI_IP);

    $zone = dwiGoodZone();
    $zone['kbeautybliss.com']['A'] = [DomainSwitch::PREVIOUS_IP];
    dwiWorld($zone);
    expect(dwiStep('dns_wait')->json())->level->toBe('amber')->message->toContain('still spreading');

    $zone = dwiGoodZone();
    $zone['kbeautybliss.com']['A'] = [DWI_ELSEWHERE];
    dwiWorld($zone);
    expect(dwiStep('dns_wait')->json())->level->toBe('red')->message->toContain('points at '.DWI_ELSEWHERE.', not at this server ('.DWI_IP.')');

    // The same zone on a server whose own address IS that one: green, no constant in the way.
    $zone['extrabeauty.ae']['A'] = [DWI_ELSEWHERE];
    app(SwitchInstaller::class)->reset();
    dwiWorld($zone);
    expect(dwiStep('dns_wait')->json('level'))->toBe('green');

    $zone = dwiGoodZone();
    $zone['www.kbeautybliss.com'] = ['AAAA' => ['2001:db8::1'], 'CNAME' => ['kbeautybliss.com.']];
    dwiWorld($zone);
    expect(dwiStep('dns_wait')->json())->level->toBe('red')->message->toContain('AAAA');
});

it('verifies step 7 (certificate) on both new names and the old one: green, red, amber', function () {
    $this->actingAs(dwiOwner(), 'admin');
    $all = ['kbeautybliss.com' => 'ok', 'www.kbeautybliss.com' => 'ok', 'extrabeauty.ae' => 'ok', 'www.extrabeauty.ae' => 'ok'];

    $log = dwiWorld([], $all);
    expect(dwiStep('ssl')->json('level'))->toBe('green');
    expect(array_map(fn ($u) => parse_url($u, PHP_URL_HOST), (array) $log))->toEqualCanonicalizing(array_keys($all));

    dwiWorld([], ['www.kbeautybliss.com' => 'cert'] + $all);
    expect(dwiStep('ssl')->json())->level->toBe('red')->message->toContain('no valid certificate for www.kbeautybliss.com')
        ->fix->toContain('kbeautybliss.com, www.kbeautybliss.com, extrabeauty.ae, www.extrabeauty.ae');

    // The live shop's own certificate lost in a reissue is red too.
    dwiWorld([], ['extrabeauty.ae' => 'cert'] + $all);
    expect(dwiStep('ssl')->json())->level->toBe('red')->message->toContain('extrabeauty.ae lost its certificate');

    dwiWorld([], ['kbeautybliss.com' => 'nodns'] + $all);
    expect(dwiStep('ssl')->json())->level->toBe('amber')->message->toContain('cannot be found yet');
});

it('verifies step 8 (main address): red, amber, green', function () {
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();

    expect(dwiStep('switch')->json('level'))->toBe('red');

    dwiSwitched(['site_url' => DWI_OLD]);
    expect(dwiStep('switch')->json())->level->toBe('amber')->message->toContain('Site URL');

    dwiSwitched();
    expect(dwiStep('switch')->json('level'))->toBe('green');
});

it('verifies step 9 (caches) by what extrabeauty.ae serves: red for a copy from before, green when it names the new address, amber when unreachable', function () {
    $this->actingAs(dwiOwner(), 'admin');

    expect(dwiStep('caches')->json())->level->toBe('red')->message->toContain('Do step 8 first');

    dwiSwitched();
    $page = fn (string $host) => [200, '<html><head><link rel="canonical" href="https://'.$host.'/"></head></html>'];

    dwiWorld([], ['extrabeauty.ae' => $page('extrabeauty.ae')]);
    expect(dwiStep('caches')->json())->level->toBe('red')->fix->toContain('Varnish → Purge');

    dwiWorld([], ['extrabeauty.ae' => $page('kbeautybliss.com')]);
    expect(dwiStep('caches')->json('level'))->toBe('green');

    dwiWorld([], []);
    expect(dwiStep('caches')->json('level'))->toBe('amber');
});

it('verifies step 10 (old links): amber while links remain, green when none do', function () {
    $this->actingAs(dwiOwner(), 'admin');

    Product::query()->create(['name' => 'DWI cream', 'slug' => 'dwi-cream', 'price' => 1000, 'status' => 'published',
        'description' => '<a href="https://extrabeauty.ae/shop/">shop</a>']);
    expect(dwiStep('links')->json())->level->toBe('amber')->message->toContain('1 link(s)');

    Product::query()->where('slug', 'dwi-cream')->update(['description' => '<a href="https://kbeautybliss.com/shop/">shop</a>']);
    expect(dwiStep('links')->json('level'))->toBe('green');
});

it('reads the payments check as the payments step: green, amber, red', function () {
    $line = fn ($level) => ['level' => $level, 'title' => 'Webhook', 'detail' => 'd', 'fix' => 'press it'];
    $pay = fn ($level) => ['level' => $level, 'main' => 'kbeautybliss.com', 'counts' => ['green' => 1, 'amber' => (int) ($level === 'amber'), 'red' => (int) ($level === 'red')],
        'providers' => [['id' => 'stripe', 'title' => 'Card (Stripe)', 'level' => $level, 'checks' => [$line($level)]]]];

    expect(SwitchInstaller::judgePayments($pay(PaymentsReadiness::GREEN))[0])->toBe('green')
        ->and(SwitchInstaller::judgePayments($pay(PaymentsReadiness::AMBER))[0])->toBe('amber')
        ->and(SwitchInstaller::judgePayments($pay(PaymentsReadiness::RED)))->sequence(
            fn ($l) => $l->toBe('red'),
            fn ($m) => $m->toContain('Card (Stripe) · Webhook'),
            fn ($f) => $f->toBe('press it'),
            fn ($d) => $d->toBeArray(),
        );

    // And end to end: a shop with no provider set up is amber, never green.
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();
    expect(dwiStep('payments')->json('level'))->toBe('amber');
});

it('verifies steps 14 to 16: Coming Soon off, forwarding, and what Google is given', function () {
    $this->actingAs(dwiOwner(), 'admin');
    dwiSwitched([ComingSoon::KEY_ON => '1', ComingSoon::KEY_SCOPE => 'host', ComingSoon::KEY_HOST => 'kbeautybliss.com']);

    expect(dwiStep('cs_off')->json('level'))->toBe('red')
        ->and(dwiStep('google')->json())->level->toBe('red')->message->toContain('robots.txt');

    dwiSwitched();
    expect(dwiStep('cs_off')->json('level'))->toBe('green')
        ->and(dwiStep('google')->json())->level->toBe('green')->message->toContain('https://kbeautybliss.com/sitemap.xml');

    // Forwarding: off is red; on and answering 301 to the new address is green; on but a page is amber.
    expect(dwiStep('forward')->json('level'))->toBe('red');

    dwiSwitched([SiteHost::KEY_REDIRECT => '1']);
    dwiWorld([], ['extrabeauty.ae' => [301, '', ['Location' => 'https://kbeautybliss.com/']]]);
    expect(dwiStep('forward')->json('level'))->toBe('green');

    dwiWorld([], ['extrabeauty.ae' => [200, '<html></html>']]);
    expect(dwiStep('forward')->json())->level->toBe('amber')->fix->toContain('Purge');

    // The sitemap still on the old address is red.
    dwiShop();
    expect(dwiStep('google')->json('level'))->toBe('red');
});

it('says honestly that the manual steps cannot be checked, and only marks them done when asked', function () {
    $this->actingAs(dwiOwner(), 'admin');

    foreach (['cloudways', 'callbacks', 'tests', 'done'] as $key) {
        expect(SwitchInstaller::STEPS[$key][1])->toBe('manual');
        dwiStep($key)->assertStatus(422)->assertJsonPath('message', 'Step '.SwitchInstaller::num($key).' cannot be checked by the shop. Do it, then press “Mark as done”.');
        expect(dwiRow(dwiStep($key, 'done')->assertOk()->json(), $key)['status'])->toBe('done');
    }

    // A checked step is never ticked by hand.
    dwiStep('dns_wait', 'done')->assertStatus(422);
});

/* ═════════════════════════════════════════════════════════ 3. skipping */

it('remembers a skip, lists it at the end, returns to it on request, and refuses to skip a critical step', function () {
    // DEFECT: no way to say "later" (Stripe live keys). MUTATION: drop the
    // critical() check in mark() and DNS can be skipped.
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();
    dwiStep('payments')->assertOk();   // amber: no provider set up -- the reason he skips it

    $r = dwiStep('payments', 'skip')->assertOk()->json();
    expect(dwiRow($r, 'payments')['status'])->toBe('skipped')
        ->and($r['installer']['skipped'])->toHaveCount(1)
        ->and($r['installer']['skipped'][0])->toMatchArray(['n' => SwitchInstaller::num('payments'), 'key' => 'payments'])
        ->and($r['installer']['skipped'][0]['message'])->toContain('amber');

    // A later Verify that is still not green keeps it skipped.
    expect(dwiRow(dwiStep('payments')->json(), 'payments')['status'])->toBe('skipped');

    foreach (['name', 'dns_records', 'dns_wait', 'ssl', 'switch'] as $key) {
        expect(SwitchInstaller::critical($key))->toBeTrue();
        dwiStep($key, 'skip')->assertStatus(422)->assertJsonPath('message', SwitchInstaller::STEPS[$key][2]);
        expect(DB::table(SwitchInstaller::TABLE)->where('step', $key)->value('status'))->toBeNull();
    }

    $r = dwiStep('payments', 'undo')->assertOk()->json();
    expect(dwiRow($r, 'payments')['status'])->toBeNull()->and($r['installer']['skipped'])->toBe([]);

    // The screen offers no Skip button on a critical step, and says why.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));
    expect($screen)->toContain("!s.critical && s.key !== 'done'")->toContain('note(esc(s.why_no_skip))')
        ->toContain('Skipped — do later')->toContain('Return to it');
});

/* ═════════════════════════════════════════════════════════ 4. the guards */

it('refuses step 8 until DNS and the certificate verified green, unless CONFIRM is typed', function () {
    // DEFECT: the switch could be pressed while the world still reached WordPress.
    // MUTATION: delete the networkGreen() check and the first press switches.
    dwiShop([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    $dir = sys_get_temp_dir().'/kbb-dwi-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/.env', "APP_URL=".DWI_OLD."\n");
    app()->useEnvironmentPath($dir);
    $this->actingAs(dwiOwner(), 'admin');

    dwiRun(['action' => 'switch_address', 'confirm' => DWI_NEW], DWI_NEW)->assertStatus(409)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'steps 6 (DNS) and 7 (certificate)') && str_contains($m, 'CONFIRM'));
    dwiRun(['action' => 'switch_address', 'confirm' => DWI_NEW, 'override' => 'confirm please'], DWI_NEW)->assertStatus(409);
    expect(file_get_contents($dir.'/.env'))->toContain('APP_URL='.DWI_OLD);

    // DNS green alone is not enough.
    dwiGreen('dns_wait');
    dwiRun(['action' => 'switch_address', 'confirm' => DWI_NEW], DWI_NEW)->assertStatus(409);

    // Typed CONFIRM passes the gate.
    dwiRun(['action' => 'switch_address', 'confirm' => DWI_NEW, 'override' => 'CONFIRM'], DWI_NEW)->assertOk();
    expect(file_get_contents($dir.'/.env'))->toContain('APP_URL="https://kbeautybliss.com"');

    // And with both green, no word needed -- and the step verifies itself.
    file_put_contents($dir.'/.env', "APP_URL=".DWI_OLD."\n");
    dwiGreen('ssl');
    $r = dwiRun(['action' => 'switch_address', 'confirm' => DWI_NEW], DWI_NEW)->assertOk()->json();
    expect(dwiRow($r, 'switch')['status'])->toBe('done');
});

it('refuses to forward while the Coming Soon page still hides the new address, and says why', function () {
    // DEFECT: forwarding on with Coming Soon on sends every extrabeauty.ae
    // customer into the Coming Soon page. MUTATION: delete the guard in
    // forwardOn() and the redirect switch below turns on.
    dwiSwitched([ComingSoon::KEY_ON => '1', ComingSoon::KEY_SCOPE => 'host', ComingSoon::KEY_HOST => 'kbeautybliss.com']);
    $this->actingAs(dwiOwner(), 'admin');

    dwiRun(['action' => 'forward_on'])->assertStatus(409)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'Coming Soon page is still on') && str_contains($m, 'step 14'));
    Setting::flushMap();
    SiteHost::forget();
    expect(SiteHost::redirectEnabled())->toBeFalse();

    // Every address hidden is the same danger.
    dwiSwitched([ComingSoon::KEY_ON => '1', ComingSoon::KEY_SCOPE => 'all']);
    dwiRun(['action' => 'forward_on'])->assertStatus(409);

    dwiSwitched();
    dwiRun(['action' => 'forward_on'])->assertOk();
    // Idempotent: a second press says so and changes nothing.
    dwiRun(['action' => 'forward_on'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'already on'));
});

it('turns Coming Soon on for the new address only, then off, through its own screen\'s save, each press idempotent', function () {
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();

    $r = dwiRun(['action' => 'coming_soon_on'])->assertOk()->json();
    $map = Setting::map();
    expect(ComingSoon::on($map))->toBeTrue()
        ->and(ComingSoon::host($map))->toBe('kbeautybliss.com')
        ->and(ComingSoon::scope($map))->toBe('host')
        // verified at once: a local check
        ->and(dwiRow($r, 'cs_on')['level'])->toBe('green')
        // the preview link for test orders, from the Coming Soon screen's own minting
        ->and($r['state']['coming_soon_link']['url'])->toStartWith('https://kbeautybliss.com/?'.ComingSoon::QUERY.'=');

    $secret = DB::table('settings')->where('key', ComingSoon::KEY_SECRET)->value('value');
    dwiRun(['action' => 'coming_soon_on'])->assertOk()->assertJsonPath('message', 'The Coming Soon page is already on for kbeautybliss.com. Nothing changed.');
    expect(DB::table('settings')->where('key', ComingSoon::KEY_SECRET)->value('value'))->toBe($secret);

    $r = dwiRun(['action' => 'coming_soon_off'])->assertOk()->json();
    expect(ComingSoon::on(Setting::map()))->toBeFalse()->and(dwiRow($r, 'cs_off')['level'])->toBe('green');
    dwiRun(['action' => 'coming_soon_off'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'already off'));
    Http::assertNothingSent();
});

it('resets progress only with the typed confirmation, and never touches the shop', function () {
    $this->actingAs(dwiOwner(), 'admin');
    dwiShop([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    dwiGreen('start', 'dns_wait');

    test()->postJson(DWI_OLD.'/admin-api/domain-switch/step', ['do' => 'reset'])->assertStatus(422);
    expect(DB::table(SwitchInstaller::TABLE)->count())->toBe(2);

    $r = test()->postJson(DWI_OLD.'/admin-api/domain-switch/step', ['do' => 'reset', 'confirm' => 'RESET'])->assertOk()->json();
    expect(DB::table(SwitchInstaller::TABLE)->count())->toBe(0)
        ->and($r['installer']['current'])->toBe(1)
        ->and(SiteHost::canonical())->toBe('kbeautybliss.com');
});

/* ═════════════════════════════════════════════════ 5. state and the page */

it('keeps progress across a reload and on another device: done, skipped, current step, last-verified time', function () {
    // DEFECT: progress held in the page would be lost on reload and absent on
    // the phone. MUTATION: keep it in a JS variable only and the second GET reads step 1.
    $this->actingAs(dwiOwner(), 'admin');
    dwiWorld(dwiGoodZone());
    dwiStep('start')->assertOk();
    dwiShop([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae']);
    dwiStep('name')->assertOk();
    dwiStep('cs_on', 'skip')->assertOk();
    dwiStep('cloudways', 'done')->assertOk();

    // A fresh request, as from a phone: nothing in memory carries over.
    \App\Models\Setting::flushMap();
    $inst = $this->getJson(DWI_NEW.'/admin-api/domain-switch')->assertOk()->json('installer');

    expect(array_column($inst['steps'], 'status', 'key'))->toMatchArray(['start' => 'done', 'name' => 'done', 'cs_on' => 'skipped', 'cloudways' => 'done', 'dns_records' => null])
        ->and($inst['current'])->toBe(SwitchInstaller::num('dns_records'))
        ->and($inst['done'])->toBe(3)
        ->and(collect($inst['steps'])->firstWhere('key', 'start')['verified_at'])->not->toBeNull()
        ->and(collect($inst['steps'])->firstWhere('key', 'start')['level'])->toBe('green');
});

it('asks nothing of the network when the page opens; Verify is the only way out', function () {
    // DEFECT: the old screen ran the readiness check and the picture count on
    // every open. MUTATION: call a verifier from show() and assertNothingSent fails.
    $this->actingAs(dwiOwner(), 'admin');
    Http::fake();
    Http::preventStrayRequests();

    $this->getJson(DWI_OLD.'/admin-api/domain-switch')->assertOk()->assertJsonStructure(['installer' => ['steps', 'current', 'total', 'skipped', 'gate']]);
    Http::assertNothingSent();

    // The screen's load() fetches exactly one thing.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));
    preg_match('/async function load\(\) \{(.+?)\n  \}/s', $screen, $load);
    expect(substr_count($load[1], 'api('))->toBe(1)
        ->and($load[1])->toContain("api('/domain-switch')")
        ->and($screen)->not->toContain('/domain-switch/readiness')
        ->and($screen)->not->toContain('/domain-switch/pictures');
});

it('only ever fetches the shop\'s own names, whatever the request carries', function () {
    $this->actingAs(dwiOwner(), 'admin');
    $log = dwiWorld(dwiGoodZone(), ['kbeautybliss.com' => 'ok']);
    config(['app.url' => 'http://10.0.0.5']);

    foreach (['ssl', 'dns_wait', 'dns_records', 'caches'] as $key) {
        dwiStep($key, 'verify', DWI_OLD, ['host' => '169.254.169.254', 'domain' => 'internal.example'])->assertOk();
    }

    $hosts = array_unique(array_map(fn ($u) => (string) parse_url($u, PHP_URL_HOST), (array) $log));
    $names = [];

    foreach ((array) $log as $u) {
        parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
        if (isset($q['name'])) {
            $names[] = $q['name'];
        }
    }

    expect(array_diff($hosts, ['dns.google', ...app(DomainSwitch::class)->probeHosts()]))->toBe([])
        ->and(array_diff(array_unique($names), app(DomainSwitch::class)->dnsHosts()))->toBe([])
        ->and(app(DomainSwitch::class)->tlsProbe(['169.254.169.254', 'internal.example']))->toBe([]);

    dwiStep('no_such_step')->assertStatus(422);
});

/* ═════════════════════════════════════════════════════════ 6. who */

it('puts the installer endpoint behind the domain-switch capability, owner only, failing closed', function () {
    // MUTATION: route /step outside the domain-switch wildcard and the
    // capability below is null (the closed default) -- and the refuse() check
    // in step() is what still stops a custom role.
    expect(AdminCapabilities::forPath('POST', 'admin-api/domain-switch/step'))->toBe('platform.domain_switch');

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(dwiOwner($role), 'admin');
        dwiStep('payments', 'skip')->assertForbidden();
        test()->postJson(DWI_OLD.'/admin-api/domain-switch/step', ['do' => 'reset', 'confirm' => 'RESET'])->assertForbidden();
    }

    auth('admin')->logout();
    expect(dwiStep('payments', 'skip')->status())->toBeIn([401, 403, 419]);
    expect(DB::table(SwitchInstaller::TABLE)->count())->toBe(0);
});

it('refuses a custom role handed the capability: the installer reaches the main address, payments and caches', function () {
    $id = DB::table('admin_roles')->insertGetId([
        'name' => 'Domain helper', 'slug' => 'domain-helper-dwi', 'tier' => 'manager',
        'capabilities' => json_encode(['admin.access', 'platform.domain_switch']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = dwiOwner('manager');
    DB::table('admin_users')->where('id', $user->id)->update(['role_id' => $id]);
    \App\Support\AdminRoles::flush();

    $this->actingAs($user->fresh(), 'admin');
    dwiStep('payments', 'skip')->assertForbidden()->assertJsonPath('message', 'Only the owner can move the shop to another domain.');
})->skip(fn () => ! \Illuminate\Support\Facades\Schema::hasTable('admin_roles') || ! \Illuminate\Support\Facades\Schema::hasColumn('admin_users', 'role_id'), 'no custom roles table');

/* ═════════════════════════════════════════════════ 7. the shop is untouched */

it('keeps the installer out of every shop page: no read of its table, no setting of its own', function () {
    // The progress lives in a table nothing on the shop reads, and never in
    // the settings map every shop page loads.
    dwiGreen('start', 'name', 'dns_wait');
    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        if (str_contains($q->sql, SwitchInstaller::TABLE)) {
            $seen[] = $q->sql;
        }
    });

    $this->get(DWI_OLD.'/')->assertOk();
    $this->get(DWI_OLD.'/shop/')->assertOk();

    expect($seen)->toBe([])
        ->and(DB::table('settings')->where('key', 'like', '%domain_switch%')->count())->toBe(0);

    // Only the installer names the table (and its migration, outside these folders).
    $hits = [];
    foreach (['app', 'routes', 'resources/views'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_contains((string) file_get_contents((string) $f), 'domain_switch_progress')) {
                $hits[] = str_replace(base_path().'/', '', (string) $f);
            }
        }
    }
    // (DomainReadiness lists it among the records it counts but never reads.)
    expect($hits)->toEqualCanonicalizing(['app/Services/DomainMove/SwitchInstaller.php', 'app/Services/DomainMove/DomainReadiness.php']);
});
