<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Models\Setting;
use App\Services\Payments\PaymentsReadiness;
use App\Support\ComingSoon;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform -> Domain switch as ONE numbered installer. (Lane DW2)
 *
 * The owner, 8 October 2026: "the migration steps are too confusing by not
 * mentioned from start to end as number wise, it's mixed. can you make me
 * somthing super simple like installer type. step by step and the system
 * verify the changes etc. and also skip option if i will update something
 * later, like stripe live apis, etc. please don't assume on anything."
 *
 * ── ONE LIST ─────────────────────────────────────────────────────────────
 *
 * STEPS is the order of work, and the ONLY place a step's number comes from:
 * num() is its position. The screen, every "go to step N" in a message and
 * docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md (pinned by a test) all read it, so a
 * number can never again be 6b on the screen and 14 in the document.
 *
 * ── WHAT IS STORED ───────────────────────────────────────────────────────
 *
 * One row per step in `domain_switch_progress`: done / skipped / to do, and
 * the last Verify's answer (green / amber / red, in words, with its fix and
 * time). A table of its own, never a setting: nothing on the shop reads it.
 *
 * ── VERIFY IS A MEASUREMENT ─────────────────────────────────────────────
 *
 * Each verifier reads the real state -- the settings, the world's DNS, a TLS
 * handshake, what Stripe holds -- and nothing else. Green marks the step done;
 * amber or red un-marks it, because the thing it checks is not true now. The
 * network is touched only here, only when Verify is pressed, only towards the
 * shop's own names (DomainSwitch::probeHosts()) and the payment APIs the
 * payments check already calls.
 */
final class SwitchInstaller
{
    public const TABLE = 'domain_switch_progress';

    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const GREEN = 'green';

    public const AMBER = 'amber';

    public const RED = 'red';

    /** Seconds of readiness scanning inside step 1's Verify; "Verify again" continues. */
    public const READINESS_SECONDS = 6.0;

    /**
     * The installer, in the order of work. key => [title, kind, why it cannot be skipped].
     *
     * kind: `verify` -- the shop can check it; `manual` -- it cannot, and the
     * step says so and offers "Mark as done". A non-null third value makes the
     * step critical: it has no "Skip for now", and the screen says why.
     */
    public const STEPS = [
        'start' => ['Before you start', 'verify', null],
        'name' => ['Tell the shop its new name', 'verify',
            'Cannot be skipped: forwarding and the switch need the shop to know its new name, and without it kbeautybliss.com is treated as a stranger and hidden from Google.'],
        'cs_on' => ['Coming Soon page ON for kbeautybliss.com', 'verify', null],
        'cloudways' => ['Cloudways: add the domain', 'manual', null],
        'dns_records' => ['Internet.bs: DNS records', 'verify',
            'Cannot be skipped: without these records nobody, not even you, can reach the shop on kbeautybliss.com.'],
        'dns_wait' => ['Wait for DNS', 'verify',
            'Cannot be skipped: until the world’s DNS points kbeautybliss.com at this server, nothing after this step can work.'],
        'ssl' => ['Cloudways: SSL certificate', 'verify',
            'Cannot be skipped: without the certificate every browser shows a security warning instead of the shop.'],
        'switch' => ['Make kbeautybliss.com the main address', 'verify',
            'Cannot be skipped: this is the switch itself — emails, payments and Google follow it.'],
        'caches' => ['Clear caches', 'verify', null],
        'links' => ['Old links in content', 'verify', null],
        'payments' => ['Payments: Stripe webhook, Apple Pay domain, Tabby, Tamara', 'verify', null],
        'callbacks' => ['Instagram and other callbacks', 'manual', null],
        'tests' => ['Test orders', 'manual', null],
        'cs_off' => ['Coming Soon page OFF', 'verify', null],
        'forward' => ['Forward the old address', 'verify', null],
        'google' => ['Google: Search Console, sitemap, IndexNow', 'verify', null],
        'done' => ['Done, and what to do in 2–4 weeks', 'manual', null],
    ];

    /**
     * The buttons on POST /domain-switch/run that belong to a step. After one
     * succeeds, a step whose check is LOCAL (no network) is verified at once,
     * so the screen shows the result of the change without another press.
     */
    public const ACTION_STEP = [
        'set_names' => 'name', 'coming_soon_on' => 'cs_on', 'switch_address' => 'switch', 'clear_caches' => 'caches',
        'rewrite_content' => 'links', 'undo_rewrite' => 'links', 'stripe' => 'payments', 'tabby' => 'payments',
        'tamara' => 'payments', 'fetch_pictures' => 'start', 'coming_soon_off' => 'cs_off', 'forward_on' => 'forward',
        'indexnow' => 'google', 'remove_old' => 'done',
    ];

    /** Verifiers that read only the shop itself: safe to run straight after an action. */
    public const LOCAL = ['name', 'cs_on', 'switch', 'links', 'cs_off', 'google'];

    public function __construct(private DomainSwitch $switch) {}

    /** The step's number: its position in STEPS, from 1. */
    public static function num(string $key): int
    {
        $i = array_search($key, array_keys(self::STEPS), true);

        return $i === false ? 0 : $i + 1;
    }

    public static function exists(string $key): bool
    {
        return isset(self::STEPS[$key]);
    }

    public static function critical(string $key): bool
    {
        return (self::STEPS[$key][2] ?? null) !== null;
    }

    /* ═════════════════════════════════════════════════════════ progress ══ */

    /** @return array<string, array<string, mixed>> every stored row, keyed by step, in ONE query */
    public function progress(): array
    {
        try {
            $rows = DB::table(self::TABLE)->get();
        } catch (\Throwable) {
            return [];   // table not there yet (package applied, migration pending): everything reads "to do"
        }

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $data = json_decode((string) ($row['data'] ?? ''), true);
            $row['data'] = is_array($data) ? $data : [];
            $out[(string) $row['step']] = $row;
        }

        return $out;
    }

    /**
     * What the screen draws: every step with its number and stored state, the
     * current one, and the summary. Reads one table; no network, ever.
     *
     * @return array<string, mixed>
     */
    public function view(?array $progress = null): array
    {
        $progress ??= $this->progress();
        $steps = [];
        $current = null;

        foreach (self::STEPS as $key => [$title, $kind, $why]) {
            $row = $progress[$key] ?? [];
            $status = in_array($row['status'] ?? null, [self::DONE, self::SKIPPED], true) ? $row['status'] : null;
            $n = self::num($key);

            if ($status === null && $current === null) {
                $current = $n;
            }

            $steps[] = [
                'n' => $n,
                'key' => $key,
                'title' => $title,
                'kind' => $kind,
                'critical' => $why !== null,
                'why_no_skip' => $why,
                'status' => $status,
                'level' => in_array($row['level'] ?? null, [self::GREEN, self::AMBER, self::RED], true) ? $row['level'] : null,
                'message' => (string) ($row['message'] ?? ''),
                'fix' => (string) ($row['fix'] ?? ''),
                'data' => $row['data'] ?? [],
                'verified_at' => self::iso($row['verified_at'] ?? null),
                'updated_at' => self::iso($row['updated_at'] ?? null),
            ];
        }

        $done = count(array_filter($steps, fn ($s) => $s['status'] === self::DONE));
        $skipped = array_values(array_map(fn ($s) => [
            'n' => $s['n'], 'key' => $s['key'], 'title' => $s['title'], 'message' => $s['message'],
        ], array_filter($steps, fn ($s) => $s['status'] === self::SKIPPED)));

        return [
            'steps' => $steps,
            'total' => count($steps),
            'current' => $current,                      // null: every step done or skipped
            'done' => $done,
            'skipped' => $skipped,
            'finished' => $current === null,
            'server_ips' => $this->serverIps($progress),
            'gate' => ['dns' => ($progress['dns_wait']['level'] ?? null) === self::GREEN, 'ssl' => ($progress['ssl']['level'] ?? null) === self::GREEN],
        ];
    }

    /**
     * This server's public address, as the last DNS check learned it from the
     * domain being left. Never a constant (DomainSwitch's class note).
     *
     * @return list<string>
     */
    public function serverIps(?array $progress = null): array
    {
        $progress ??= $this->progress();

        foreach (['dns_wait', 'start'] as $key) {
            $ips = array_values(array_filter((array) ($progress[$key]['data']['server_ips'] ?? []),
                fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false));

            if ($ips !== []) {
                return $ips;
            }
        }

        $old = $this->switch->recalled('old_dns');

        return array_values(array_filter((array) ($old['records'] ?? []), fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }

    /** Did steps 6 and 7 last verify green? Step 8's gate. */
    public function networkGreen(): bool
    {
        $gate = $this->view()['gate'];

        return $gate['dns'] && $gate['ssl'];
    }

    /** Mark as done (manual steps), Skip for now, or Return to it. @return array{ok: bool, message: string} */
    public function mark(string $key, string $as): array
    {
        if (! self::exists($key)) {
            return ['ok' => false, 'message' => 'There is no such step.'];
        }

        $n = self::num($key);

        if ($as === 'skip') {
            if (self::critical($key)) {
                return ['ok' => false, 'message' => (string) self::STEPS[$key][2]];
            }

            $this->write($key, ['status' => self::SKIPPED]);

            return ['ok' => true, 'message' => 'Step '.$n.' skipped. It is listed at the end, with a button to return to it.'];
        }

        if ($as === 'done') {
            if (self::STEPS[$key][1] !== 'manual') {
                return ['ok' => false, 'message' => 'Step '.$n.' is checked by the shop: press Verify. It is marked done when the check is green.'];
            }

            $this->write($key, ['status' => self::DONE, 'level' => null, 'message' => null, 'fix' => null]);

            return ['ok' => true, 'message' => 'Step '.$n.' marked as done.'];
        }

        // undo: back to "to do", keeping the last check's answer and its time.
        $this->write($key, ['status' => null]);

        return ['ok' => true, 'message' => 'Step '.$n.' is open again.'];
    }

    /** Forget every step's progress. The shop itself is not touched. */
    public function reset(): void
    {
        try {
            DB::table(self::TABLE)->delete();
        } catch (\Throwable) {
            // nothing stored, nothing to forget
        }
    }

    /* ═══════════════════════════════════════════════════════════ verify ══ */

    /**
     * Run one step's check and store its answer.
     *
     * @return array{level: string, message: string, fix: string, data: array<string, mixed>}
     */
    public function verify(string $key, Request $request): array
    {
        $result = match ($key) {
            'start' => $this->vStart(),
            'name' => $this->vName(),
            'cs_on' => $this->vComingSoonOn(),
            'dns_records' => $this->vDnsRecords(),
            'dns_wait' => $this->vDnsWait($request),
            'ssl' => $this->vSsl(),
            'switch' => $this->vSwitch(),
            'caches' => $this->vCaches(),
            'links' => $this->vLinks(),
            'payments' => $this->vPayments(),
            'cs_off' => $this->vComingSoonOff(),
            'forward' => $this->vForward(),
            'google' => $this->vGoogle(),
            default => null,
        };

        if ($result === null) {
            return ['level' => '', 'message' => 'Step '.self::num($key).' cannot be checked by the shop. Do it, then press “Mark as done”.', 'fix' => '', 'data' => []];
        }

        [$level, $message, $fix, $data] = $result + [2 => '', 3 => []];
        $previous = $this->progress()[$key] ?? [];
        $status = $level === self::GREEN
            ? self::DONE
            // Not true now: no longer done. A skip the owner chose stays a skip.
            : (($previous['status'] ?? null) === self::SKIPPED ? self::SKIPPED : null);

        $this->write($key, [
            'status' => $status,
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'fix' => mb_substr($fix, 0, 1000),
            'data' => $data,
            'verified_at' => now(),
        ]);

        return ['level' => $level, 'message' => $message, 'fix' => $fix, 'data' => $data];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vStart(): array
    {
        $old = $this->switch->checkOldDns(app(DnsLookup::class));
        $ready = $this->switch->readiness(0, self::READINESS_SECONDS);
        $pay = app(PaymentsReadiness::class)->run();

        $lines = [];
        $fix = '';
        $level = self::GREEN;

        if ($old['level'] === 'done') {
            $lines[] = 'This server’s address: '.implode(', ', $old['records']).' (where '.$old['host'].' points today).';
        } else {
            $level = self::AMBER;
            $lines[] = (string) $old['message'];
        }

        $risk = (int) $ready['risk'];

        if ($risk > 0) {
            $level = self::RED;
            $lines[] = 'Readiness check: '.$risk.' thing(s) would break on the switch.';
            $fix = 'Fix each RISK line below (each has a button), then press Verify again.';
        } elseif (! $ready['complete']) {
            $level = $level === self::RED ? $level : self::AMBER;
            $lines[] = 'Readiness check: '.$ready['tables_checked'].' of '.$ready['tables_total'].' parts of the shop checked, nothing found so far.';
            $fix = $fix ?: 'Press Verify again: it carries on where it stopped.';
        } else {
            $lines[] = 'Readiness check: nothing found that breaks on the switch (RISK 0).';
        }

        $counts = (array) $pay['counts'];
        $lines[] = 'Payments check: '.$counts['green'].' green, '.$counts['amber'].' amber, '.$counts['red'].' red.';

        if ($pay['level'] === PaymentsReadiness::RED) {
            $level = self::RED;
            $fix = $fix ?: 'Fix the red payment lines below, then press Verify again.';
        } elseif ($pay['level'] === PaymentsReadiness::AMBER && $level === self::GREEN) {
            // Amber at a provider before the switch is the normal state (webhooks on today's address).
            $lines[] = 'The amber payment lines are expected before the switch; step '.self::num('payments').' finishes them.';
        }

        return [$level, implode(' ', $lines), $fix, [
            'server_ips' => $old['records'] ?? [],
            'risk' => $risk,
            'todo' => (int) $ready['todo'],
            'complete' => (bool) $ready['complete'],
            'findings' => array_slice(array_values(array_filter($ready['findings'], fn ($f) => in_array($f['level'], ['risk', 'todo'], true))), 0, 12),
            'payments' => self::paySummary($pay),
        ]];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function vName(): array
    {
        $target = $this->switch->target();
        $canonical = SiteHost::canonical();

        if ($this->switch->oldRemoved()) {
            return [self::GREEN, 'The main address is '.$target.'; '.$this->switch->primaryOld().' has been removed, as the last step does.'];
        }

        if ($canonical !== $target) {
            return [self::RED, 'The shop’s main address is '.($canonical === '' ? 'not set' : $canonical).', not '.$target.'.',
                'Press “Save the new name” above. Nothing changes for shoppers.'];
        }

        $missing = array_values(array_filter($this->switch->oldHosts(), fn ($h) => SiteHost::classify($h) !== SiteHost::ALIAS));

        if ($missing !== []) {
            return [self::AMBER, 'The main address is '.$target.', but '.implode(', ', $missing).' is not listed as an old address.',
                'Press “Save the new name” again: it adds it.'];
        }

        return [self::GREEN, 'The main address is '.$target.' and the old address is '.implode(', ', $this->switch->oldHosts()).'. Forwarding is '
            .(SiteHost::redirectEnabled() ? 'on' : 'off').'.'];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function vComingSoonOn(): array
    {
        if ((self::row($this->progress(), 'cs_off')['status'] ?? null) === self::DONE) {
            return [self::GREEN, 'It was on while you set things up, and step '.self::num('cs_off').' turned it off, as planned.'];
        }

        $map = self::freshMap();
        $target = ComingSoon::bare($this->switch->target());

        if (! ComingSoon::on($map)) {
            return [self::RED, 'The Coming Soon page is OFF: anyone who reaches '.$target.' would see the shop while you set it up.',
                'Press “Turn it on for '.$target.'” above.'];
        }

        if (ComingSoon::scope($map) === ComingSoon::SCOPE_ALL) {
            return [self::AMBER, 'The Coming Soon page is ON for EVERY address, including '.$this->switch->primaryOld().', where customers shop today.',
                'Press “Turn it on for '.$target.'” above: it keeps '.$this->switch->primaryOld().' open.'];
        }

        if (! ComingSoon::hides($target, $map)) {
            return [self::RED, 'The Coming Soon page is ON for '.ComingSoon::host($map).', not for '.$target.'.', 'Press “Turn it on for '.$target.'” above.'];
        }

        return [self::GREEN, 'ON for '.$target.' (and www.'.$target.'); '.$this->switch->primaryOld().' shows the shop as always.'];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vDnsRecords(): array
    {
        $new = $this->switch->newBare();
        $answers = app(DnsLookup::class)->lookup([[$new, 'NS'], [$new, 'MX'], [$new, 'TXT'], ['www.'.$new, 'CNAME'], ['www.'.$new, 'A']]);
        $get = fn (string $h, string $t): array => $answers[$h.' '.$t] ?? ['ok' => false, 'records' => []];

        $ns = $get($new, 'NS');
        $mx = $get($new, 'MX')['records'];
        $spf = array_values(array_filter($get($new, 'TXT')['records'], fn ($t) => str_starts_with($t, 'v=spf1')));
        $www = $get('www.'.$new, 'CNAME')['records'] ?: $get('www.'.$new, 'A')['records'];
        $data = ['NS' => $ns['records'], 'MX' => $mx, 'SPF' => $spf, 'www' => $www];

        if (! $ns['ok']) {
            return [self::AMBER, 'The address book of the internet could not be asked just now.', 'Press Verify again in a minute.', $data];
        }

        $problems = [];
        $level = self::GREEN;

        if (! array_filter($mx, fn ($m) => (bool) preg_match('/(^|\s)smtp\.google\.com$/', $m))) {
            $level = self::RED;
            $problems[] = 'No MX record smtp.google.com: email to @'.$new.' would stop arriving. Add the MX row of the table.';
        }

        if (! array_filter($spf, fn ($t) => str_contains($t, 'include:_spf.google.com'))) {
            $level = self::RED;
            $problems[] = 'No SPF (TXT v=spf1 include:_spf.google.com ~all): the shop’s emails could land in spam. Add the TXT row.';
        }

        if ($www === []) {
            $level = $level === self::RED ? $level : self::AMBER;
            $problems[] = 'www.'.$new.' has no record yet. Add the CNAME row.';
        }

        $nsLine = 'Nameservers: '.($ns['records'] === [] ? 'none yet' : implode(', ', $ns['records'])).' — they should be the ones Internet.bs lists for '.$new.'.';

        return $level === self::GREEN
            ? [self::GREEN, 'Email (MX, SPF) and www records are in place. '.$nsLine, '', $data]
            : [$level, implode(' ', $problems).' '.$nsLine, 'Add the missing rows at Internet.bs → '.$new.' → DNS Management, wait a few minutes, then press Verify again.', $data];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vDnsWait(Request $request): array
    {
        $r = $this->switch->checkDns(app(DnsLookup::class), $this->serverIps());
        $data = ['server_ips' => $r['expected'], 'records' => $r['records']];

        // A page served to the owner over https on the new name proves the A record
        // when the resolver could not say (amber) -- never over a red answer.
        if ($r['level'] === 'todo' && $r['records']['AAAA'] === [] && $this->switch->servedOnTarget($request)) {
            return [self::GREEN, 'You are on https://'.$this->switch->target().' right now, so its DNS already points here.', '', $data];
        }

        return match ($r['level']) {
            'done' => [self::GREEN, (string) $r['message'], '', $data],
            'todo' => [self::AMBER, (string) $r['message'], 'Wait, then press Verify again. It takes from a few minutes to 48 hours.', $data],
            default => [self::RED, (string) $r['message'], 'Correct the record at Internet.bs → '.$this->switch->newBare().' → DNS Management, then press Verify again.', $data],
        };
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vSsl(): array
    {
        $new = $this->switch->newBare();
        $names = [$new, 'www.'.$new];
        $olds = [];

        if (! $this->switch->oldRemoved()) {
            $old = $this->switch->primaryOld();
            $olds = [$old, 'www.'.$old];
        }

        $probe = $this->switch->tlsProbe([...$names, ...$olds]);
        $data = ['hosts' => array_map(fn ($p) => ['level' => $p['level'], 'status' => $p['status']], $probe)];
        $bad = [];
        $level = self::GREEN;

        foreach ($names as $h) {
            $p = $probe[$h] ?? ['level' => self::AMBER, 'message' => 'Could not check '.$h.'.'];

            if ($p['level'] !== self::GREEN) {
                $level = $p['level'] === self::RED || $level === self::RED ? self::RED : self::AMBER;
                $bad[] = $p['message'];
            }
        }

        foreach ($olds as $h) {
            $p = $probe[$h] ?? null;

            if ($p !== null && $p['level'] === self::RED && $p['kind'] === 'cert') {
                $level = self::RED;
                $bad[] = $h.' lost its certificate: the shop customers use today shows a security warning there.';
            }
        }

        if ($level === self::GREEN) {
            return [self::GREEN, 'The certificate covers '.implode(', ', [...$names, ...$olds]).'.', '', $data];
        }

        return [$level, implode(' ', $bad), 'Cloudways → Applications → your app → SSL Certificate → Let’s Encrypt with all four names: '
            .DomainSwitch::certificateNames($new, $this->switch->primaryOld()).'. Wait two minutes, then press Verify again.', $data];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function vSwitch(): array
    {
        $target = 'https://'.$this->switch->target();
        $siteUrl = rtrim(trim((string) (self::freshMap()['site_url'] ?? '')), '/');

        if (! $this->switch->addressSwitched()) {
            return [self::RED, 'The shop still calls itself '.(SiteUrl::configured() ?: 'nothing').'.',
                'Open '.$target.' and sign in there, come back to this step and press “Make '.$this->switch->target().' the main address”.'];
        }

        if ($siteUrl !== $target) {
            return [self::AMBER, 'The shop calls itself '.$target.', but the address Google is given (Site URL) is '.($siteUrl ?: 'empty').'.',
                'Press the button again: it sets both.'];
        }

        return [self::GREEN, 'The shop calls itself '.$target.' in emails, payment notices and links, and gives Google '.$target.'. '
            .$this->switch->primaryOld().' keeps working.'];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vCaches(): array
    {
        if (! $this->switch->addressSwitched()) {
            return [self::RED, 'Do step '.self::num('switch').' first: there is nothing old to clear until the shop has switched.', '', []];
        }

        $old = $this->switch->fetchOldHome();
        $target = $this->switch->target();
        $data = ['status' => $old['status'], 'canonical_host' => SiteHost::normalise((string) parse_url($old['canonical'], PHP_URL_HOST))];
        $purge = 'Cloudways → Applications → your app → Application Settings → Varnish → Purge. Then press Verify again.';

        if (! $old['ok']) {
            return [self::AMBER, 'Could not open https://'.$old['host'].'/ just now to look.', 'Press Verify again in a minute.', $data];
        }

        $location = SiteHost::normalise((string) parse_url($old['location'], PHP_URL_HOST));

        if ($old['status'] >= 300 && $old['status'] < 400 && $location === $target) {
            return [self::GREEN, $old['host'].' already forwards to '.$target.': nothing old is being served.', '', $data];
        }

        if ($old['status'] === 200 && $data['canonical_host'] === $target) {
            return [self::GREEN, 'The pages '.$old['host'].' serves already name '.$target.': no copy from before the switch is left.', '', $data];
        }

        if ($old['status'] === 200 && $data['canonical_host'] !== '') {
            return [self::RED, 'https://'.$old['host'].'/ still serves a copy saved before the switch (it names '.$data['canonical_host'].').',
                'Press “Clear the shop’s caches” above, then '.$purge, $data];
        }

        return [self::AMBER, 'https://'.$old['host'].'/ answered '.$old['status'].', so the shop could not tell.', 'Press “Clear the shop’s caches”, then '.$purge, $data];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vLinks(): array
    {
        $preview = ContentRewrite::forSwitch($this->switch)->preview(0);
        $data = ['links' => (int) $preview['links'], 'rows' => (int) $preview['rows']];

        if ($preview['links'] === 0) {
            return [self::GREEN, 'No link in the shop’s text points at '.implode(', ', $preview['old']).'.', '', $data];
        }

        $wait = $this->switch->addressSwitched() ? '' : ' Do step '.self::num('switch').' first: until then '.$this->switch->newBare().' does not open this shop.';

        return [self::AMBER, $preview['links'].' link(s) in '.$preview['rows'].' place(s) still point at '.implode(', ', $preview['old']).'. They work, but they go the long way round.'.$wait,
            'Press “Show what would change”, then “Change these links”. Undo is offered.', $data];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vPayments(): array
    {
        return self::judgePayments(app(PaymentsReadiness::class)->run());
    }

    /**
     * The payments check's answer as the step reads it: its level, the open
     * lines in words, the first fix. Public so its three outcomes can be
     * pinned without three live providers.
     *
     * @param  array<string, mixed>  $pay  PaymentsReadiness::run()
     * @return array{0: string, 1: string, 2: string, 3: array<string, mixed>}
     */
    public static function judgePayments(array $pay): array
    {
        $summary = self::paySummary($pay);
        $counts = (array) ($pay['counts'] ?? []) + ['green' => 0, 'amber' => 0, 'red' => 0];
        $open = [];
        $fix = '';

        foreach ($summary as $p) {
            foreach ($p['checks'] as $c) {
                if ($c['level'] !== PaymentsReadiness::GREEN) {
                    $open[] = $p['title'].' · '.$c['title'].': '.$c['detail'];
                    $fix = $fix ?: (string) $c['fix'];
                }
            }
        }

        $level = match ($pay['level'] ?? null) {
            PaymentsReadiness::GREEN => self::GREEN,
            PaymentsReadiness::RED => self::RED,
            default => self::AMBER,
        };

        $message = $level === self::GREEN
            ? 'Every payment check is green: Stripe, Apple Pay, Tabby, Tamara and cash on delivery are set for '.($pay['main'] ?? 'the main address').'.'
            : $counts['red'].' red, '.$counts['amber'].' amber. '.implode(' ', array_slice($open, 0, 4));

        return [$level, $message, $fix, ['payments' => $summary]];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function vComingSoonOff(): array
    {
        $map = self::freshMap();

        if (! ComingSoon::on($map)) {
            return [self::GREEN, 'OFF — every address shows the shop.'];
        }

        return [self::RED, 'The Coming Soon page is still ON ('.(ComingSoon::scope($map) === ComingSoon::SCOPE_ALL ? 'every address' : ComingSoon::host($map)).'): visitors there do not see the shop.',
            'Press “Turn it off” above when the test orders of step '.self::num('tests').' have passed.'];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vForward(): array
    {
        $target = $this->switch->target();

        if ($this->switch->oldRemoved()) {
            return [self::GREEN, $this->switch->primaryOld().' was removed after its 2–4 weeks of forwarding.', '', []];
        }

        if (! SiteHost::redirectEnabled() || SiteHost::canonical() !== $target) {
            return [self::RED, 'Forwarding is OFF: old '.$this->switch->primaryOld().' links still open the shop there, not '.$target.'.',
                'Press “Forward '.$this->switch->primaryOld().' to '.$target.'” above.', []];
        }

        $old = $this->switch->fetchOldHome();
        $location = SiteHost::normalise((string) parse_url($old['location'], PHP_URL_HOST));
        $data = ['status' => $old['status']];

        if (! $old['ok']) {
            return [self::AMBER, 'Forwarding is ON in the shop, but https://'.$old['host'].'/ could not be opened just now to see it work.', 'Press Verify again in a minute.', $data];
        }

        if ($old['status'] >= 300 && $old['status'] < 400 && $location === $target) {
            return [self::GREEN, 'https://'.$old['host'].'/ forwards to https://'.$target.'/ ('.$old['status'].'). Leave it on for 2–4 weeks.', '', $data];
        }

        return [self::AMBER, 'Forwarding is ON in the shop, but https://'.$old['host'].'/ still answered '.$old['status'].' with a page.',
            'Cloudways → Applications → your app → Application Settings → Varnish → Purge, then press Verify again.', $data];
    }

    /** @return array{0: string, 1: string, 2: string, 3: array<string, mixed>} */
    private function vGoogle(): array
    {
        $map = self::freshMap();
        $target = $this->switch->target();
        $base = rtrim(trim((string) ($map['site_url'] ?? '')) ?: SiteUrl::configured(), '/');
        $host = SiteHost::normalise((string) parse_url($base, PHP_URL_HOST));
        $data = ['sitemap' => $base.'/sitemap.xml', 'indexnow' => $this->progress()['google']['data']['indexnow'] ?? null];
        $hand = ' Google Search Console itself cannot be checked from the shop: do its two clicks by hand.';

        if ($host !== $target || ! str_starts_with($base, 'https://')) {
            return [self::RED, 'The sitemap still gives Google '.($host ?: 'no').' addresses, not https://'.$target.'.', 'Do step '.self::num('switch').' first.', $data];
        }

        if (SiteHost::isPrivate()) {
            return [self::RED, 'Platform → Site address → Keep this install out of Google is ON: Google is told to stay away.', 'Turn it off in Platform → Site address.', $data];
        }

        if (ComingSoon::on($map) && ComingSoon::hides($target, $map)) {
            return [self::RED, 'The Coming Soon page is still on for '.$target.': robots.txt tells Google to stay away.', 'Do step '.self::num('cs_off').' first.', $data];
        }

        return [self::GREEN, 'The sitemap lists https://'.$target.'/ addresses ('.$base.'/sitemap.xml).'.$hand, '', $data];
    }

    /* ═════════════════════════════════════════════════════════ helpers ══ */

    /**
     * The payments check, kept to what the screen shows: provider, level and
     * each line's words. No key, no webhook address beyond PaymentsReadiness's
     * own masking.
     *
     * @param  array<string, mixed>  $pay
     * @return list<array<string, mixed>>
     */
    public static function paySummary(array $pay): array
    {
        return array_values(array_map(fn ($p) => [
            'id' => (string) $p['id'],
            'title' => (string) $p['title'],
            'level' => (string) $p['level'],
            'checks' => array_values(array_map(fn ($c) => [
                'level' => (string) $c['level'], 'title' => (string) $c['title'], 'detail' => (string) $c['detail'], 'fix' => (string) ($c['fix'] ?? ''),
            ], (array) $p['checks'])),
        ], (array) ($pay['providers'] ?? [])));
    }

    /** Store a step's row; `data` is merged into what is there. */
    public function write(string $key, array $fields): void
    {
        if (! self::exists($key)) {
            return;
        }

        $row = DB::table(self::TABLE)->where('step', $key)->first();
        $data = $row !== null ? (json_decode((string) ($row->data ?? ''), true) ?: []) : [];

        if (array_key_exists('data', $fields)) {
            $fields['data'] = json_encode(array_merge($data, (array) $fields['data']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $fields['updated_at'] = now();

        DB::table(self::TABLE)->updateOrInsert(['step' => $key], $fields);
    }

    /** The settings map as it is now, not as this process first read it. */
    private static function freshMap(): array
    {
        Setting::flushMap();
        SiteHost::forget();

        return Setting::map();
    }

    /** @param  array<string, array<string, mixed>>  $progress */
    private static function row(array $progress, string $key): array
    {
        return $progress[$key] ?? [];
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Is the progress table there? (A package whose migration has not run yet.) */
    public static function ready(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }
}
