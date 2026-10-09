<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Models\Setting;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use App\Services\OwnerApp\OwnerAppPath;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Platform -> Domain switch: what each step's state IS, worked out from the
 * shop itself every time the screen asks. (Lane DW)
 *
 * The owner, 7 October 2026: "i'm non technical, so avoid anything for me to
 * do." The runbook (docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md) had steps that
 * needed SSH or a hunt through five admin screens. This class is the read side
 * of the screen that replaces them; DomainSwitchApiController is the write side
 * and reuses the existing actions rather than copying them.
 *
 * ── NO "I CLICKED IT" FLAGS ──────────────────────────────────────────────
 *
 * A step is done when the thing it changes is true NOW: the main address
 * setting says kbeautybliss.com, APP_URL names it, the forwarding switch is on.
 * Three things cannot be read from the shop -- the world's DNS, the
 * certificate, and what a payment provider holds -- so for those the screen
 * keeps the RESULT of the last real check (what the resolver answered, what the
 * TLS handshake did, what Stripe said) with the time it was taken, in the cache
 * for a limited time. That is a measurement with a date on it, not a flag; and
 * a request that is itself being served over https on the new domain is proof
 * of both DNS and certificate, so once the owner is there, those two read done
 * with no stored value at all.
 *
 * ── WHICH NAMES MAY BE LOOKED UP ─────────────────────────────────────────
 *
 * dnsHosts() and the certificate check only ever name the domain this shop is
 * moving to (its main address, or the constant below before one is set), its
 * www twin, and the domains the configuration already names as being left.
 * Nothing in a request body becomes a name this server resolves or connects
 * to, so the screen cannot be turned into a DNS or port probe of anything else.
 */
final class DomainSwitch
{
    /** The move this screen was built for. Constants, never settings: nothing typed reaches them. */
    public const DEFAULT_NEW = 'kbeautybliss.com';

    public const DEFAULT_OLD = 'extrabeauty.ae';

    /*
     * NO SERVER ADDRESS IS WRITTEN HERE (Lane DW2). The shop cannot learn its
     * own public address from inside the server -- behind Varnish and nginx
     * SERVER_ADDR is a private one -- and a constant goes stale the day the
     * server is rebuilt. extrabeauty.ae is served by this server today, so what
     * it resolves to IS this server's public address: every check below asks
     * the world for it, and the installer keeps the answer it got
     * (SwitchInstaller::serverIps()) so step 5 can show it with a copy button.
     */

    /** Where kbeautybliss.com pointed before the move (Hostinger); the checklist's undo value. */
    public const PREVIOUS_IP = '177.202.242.149';

    /** Seconds of table scanning per readiness request; "check the rest" continues. */
    public const READINESS_SECONDS = 6.0;

    /** The removal step re-runs the whole check itself, with this much time. */
    public const REMOVE_READINESS_SECONDS = 25.0;

    public const CACHE_PREFIX = 'kbb.domain_switch.';

    /** How long a check result is shown before the screen asks for it again. */
    public const RESULT_TTL = 60 * 60 * 24 * 30;

    /** The four names on the certificate while extrabeauty.ae still forwards. */
    public static function certificateNames(string $new, string $old): string
    {
        return implode(', ', [$new, 'www.'.$new, $old, 'www.'.$old]);
    }

    /* ═══════════════════════════════════════════════════════════ names ══ */

    /**
     * The host the shop is moving to, as the main address will hold it.
     *
     * The main address once one is set (and is not the domain being left),
     * else the constant. Never the request's Host.
     */
    public function target(): string
    {
        $canonical = SiteHost::canonical();

        if ($canonical !== '' && self::isName($canonical) && DomainReadiness::bare($canonical) !== self::DEFAULT_OLD) {
            return $canonical;
        }

        return self::DEFAULT_NEW;
    }

    /** The target without `www.`, which is what DNS and the readiness check are about. */
    public function newBare(): string
    {
        return DomainReadiness::bare($this->target());
    }

    /**
     * The domains being left: everything the configuration names that is not
     * the new one, plus extrabeauty.ae, so the check keeps looking for it after
     * the old addresses have been cleared.
     *
     * @return list<string>
     */
    public function oldHosts(): array
    {
        $new = $this->newBare();
        $out = [];

        foreach ([...DomainReadiness::derivedOld($new), self::DEFAULT_OLD] as $host) {
            $host = DomainReadiness::bare($host);

            if ($host !== '' && $host !== $new && self::isName($host)) {
                $out[$host] = true;
            }
        }

        return array_keys($out);
    }

    /** The domain being left that the screen names: extrabeauty.ae, or the first configured one. */
    public function primaryOld(): string
    {
        $old = $this->oldHosts();

        return in_array(self::DEFAULT_OLD, $old, true) ? self::DEFAULT_OLD : ($old[0] ?? self::DEFAULT_OLD);
    }

    /**
     * Every name this server may look up: the new domain, its www twin, and the
     * domains being left. The SSRF guard -- see the class comment.
     *
     * @return list<string>
     */
    public function dnsHosts(): array
    {
        $new = $this->newBare();

        return array_values(array_unique([$new, 'www.'.$new, ...$this->oldHosts()]));
    }

    /** A DNS name with at least two labels: no IP literal, no localhost, no port, no path. */
    public static function isName(string $host): bool
    {
        return strlen($host) <= 253
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host);
    }

    /** The APP_URL host, normalised. */
    public function appHost(): string
    {
        return SiteHost::normalise(SiteUrl::configuredHost());
    }

    /** Is APP_URL already https://<target>? */
    public function addressSwitched(): bool
    {
        return $this->appHost() === $this->target() && str_starts_with(SiteUrl::configured(), 'https://');
    }

    /** Is this very request arriving over https on the new domain? */
    public function servedOnTarget(Request $request): bool
    {
        $host = SiteHost::normalise($request->getHost());

        // Exactly the main address: "Use this address" writes the request's own
        // host, and www.<new> would leave APP_URL disagreeing with the main address.
        return $request->isSecure() && $host === $this->target();
    }

    /** Old addresses cleared after the switch: the last step's in-shop half is done. */
    public function oldRemoved(): bool
    {
        if (SiteHost::canonical() !== $this->target() || ! $this->addressSwitched()) {
            return false;
        }

        foreach ($this->oldHosts() as $host) {
            if (SiteHost::classify($host) === SiteHost::ALIAS) {
                return false;
            }
        }

        return true;
    }

    /* ════════════════════════════════════════════════════════ readiness ══ */

    /**
     * The read-only check behind `kbb:domain-check`, inside a time budget.
     *
     * @return array<string, mixed>
     */
    public function readiness(int $offset = 0, float $seconds = self::READINESS_SECONDS): array
    {
        $started = microtime(true);
        $engine = new DomainReadiness($this->newBare(), $this->oldHosts());
        $findings = [];

        if ($offset === 0) {
            $removed = $this->oldRemoved();

            foreach ($engine->checks() as $c) {
                /*
                 * After the old address is removed (the last step's "in 2–4 weeks"
                 * button) the old addresses are gone ON PURPOSE. The engine still
                 * reads "not listed" as something to do, and its fix would be the
                 * "new name" step -- which would put them back. Said as done instead.
                 */
                if ($removed && in_array($c['what'], ['Old addresses to forward', 'Forward these, permanently'], true)) {
                    $c['level'] = DomainReadiness::OK;
                    $c['detail'] = $this->primaryOld().' was removed in step '.SwitchInstaller::num('done').'; nothing forwards it any more.';
                }

                $findings[] = $this->plainCheck($c);
            }
        }

        foreach ($engine->references(3, $started + $seconds, $offset) as $r) {
            $line = $this->plainReference($r);

            if ($line !== null) {
                $findings[] = $line;
            }
        }

        $total = $engine->tableCount();
        $done = $total - count($engine->skipped());

        return [
            'ok' => true,
            'new' => $engine->newHost(),
            'old' => $engine->oldHosts(),
            'risk' => count(array_filter($findings, fn ($f) => $f['level'] === DomainReadiness::RISK)),
            'todo' => count(array_filter($findings, fn ($f) => $f['level'] === DomainReadiness::TODO)),
            'complete' => $engine->skipped() === [],
            'offset' => $offset,
            'next_offset' => $done,
            'tables_total' => $total,
            'tables_checked' => max(0, $done - $offset),
            'seconds' => round(microtime(true) - $started, 2),
            'findings' => $findings,
        ];
    }

    /**
     * The whole check, as the removal step needs it: every table, and RISK.
     *
     * @return array{complete: bool, risk: int}
     */
    public function fullRisk(float $seconds = self::REMOVE_READINESS_SECONDS): array
    {
        $r = $this->readiness(0, $seconds);

        return ['complete' => (bool) $r['complete'], 'risk' => (int) $r['risk']];
    }

    /**
     * One configuration line, in words the owner uses, with where to fix it.
     *
     * @param  array{level: string, what: string, detail: string, where: string}  $c
     * @return array<string, mixed>
     */
    private function plainCheck(array $c): array
    {
        $go = static fn (string $key): array => ['step' => SwitchInstaller::num($key), 'label' => 'Go to step '.SwitchInstaller::num($key)];

        [$title, $fix] = match ($c['what']) {
            'APP_URL' => ['The shop’s own address (emails, payment notices, links)', $go('switch')],
            'Site URL' => ['The address Google is given (Site URL)', $go('switch')],
            'Main address' => ['The shop’s main address', $go('name')],
            'Old addresses to forward' => ['The old addresses the shop knows about', $go('name')],
            'Forward these, permanently' => ['Sending old links to the new address', $go('forward')],
            'Keep this install out of Google' => ['Hidden from Google', ['screen' => 'siteaddr', 'label' => 'Open Site address']],
            'Owner app host' => ['The owner app’s own address', ['screen' => 'ownerapp', 'label' => 'Open Owner App']],
            'Mail From address' => ['The address order emails are sent from', ['screen' => 'emails-sending', 'label' => 'Open Sending & delivery']],
            'Web root file' => ['A file on the server that names an old address', null],
            'ASSET_URL' => ['Where scripts and stylesheets load from (ASSET_URL)', null],
            default => ['A server setting ('.$c['what'].')', null],
        };

        return [
            'level' => $c['level'],
            'title' => $title,
            'detail' => $c['detail'],
            'fix' => $c['level'] === DomainReadiness::OK ? null : $fix,
            'samples' => [],
        ];
    }

    /**
     * One stored-address line, in words. Null for lines that need no reading
     * (a copy this shop already holds, a record of the past).
     *
     * @param  array{table: string, column: string, host: string, kind: string, level: string, count: int, samples: list<string>}  $r
     * @return array<string, mixed>|null
     */
    private function plainReference(array $r): ?array
    {
        if (in_array($r['level'], [DomainReadiness::OK, DomainReadiness::INFO], true)) {
            return null;
        }

        $place = self::place($r['table'], $r['column']);
        $n = $r['count'];
        $isNew = $r['host'] === $this->newBare();

        [$title, $fix] = match ($r['kind']) {
            'upload' => $isNew
                ? [$n.' picture or video address(es) in '.$place.' on '.$r['host'].' that only WordPress has today',
                    ['step' => SwitchInstaller::num('start'), 'label' => 'Fetch them (step '.SwitchInstaller::num('start').')']]
                // Lane DS: an address on the domain being left names a file on
                // THIS server (one server, two names), so nothing needs fetching:
                // the old-links step points the address at the new name, undoably.
                : [$n.' picture or video address(es) in '.$place.' still load from '.$r['host'],
                    ['step' => SwitchInstaller::num('links'), 'label' => 'Point them at the new address (step '.SwitchInstaller::num('links').')']],
            'link' => $isNew
                ? [$n.' link(s) in '.$place.' still send shoppers to '.$r['host'],
                    ['screen' => 'import', 'label' => 'Open Import → Addresses & pictures → Links to the old site']]
                : [$n.' link(s) in '.$place.' still send shoppers to '.$r['host'],
                    ['step' => SwitchInstaller::num('links'), 'label' => 'Point them at the new address (step '.SwitchInstaller::num('links').')']],
            'email' => [$n.' email address(es) in '.$place.' end in '.$r['host'], self::screenFor($r['table'])],
            'text' => [$n.' mention(s) of '.$r['host'].' in '.$place, self::screenFor($r['table'])],
            'unreadable' => ['Part of the shop could not be checked ('.$r['table'].')', ['check' => true, 'label' => 'Check again']],
            default => [$n.' '.$r['kind'].' in '.$place.' ('.$r['host'].')', null],
        };

        return [
            'level' => $r['level'],
            'title' => $title,
            'detail' => '',
            'fix' => $fix,
            'samples' => array_slice($r['samples'], 0, 3),
        ];
    }

    /** Where a table's rows are edited, in the console's own screen ids. */
    private static function screenFor(string $table): ?array
    {
        return match (true) {
            str_starts_with($table, 'product') => ['screen' => 'catalog', 'label' => 'Open Catalog'],
            in_array($table, ['posts', 'post_translations'], true) => ['screen' => 'posts', 'label' => 'Open Blog posts'],
            str_starts_with($table, 'page') => ['screen' => 'pages-store', 'label' => 'Open Pages'],
            str_starts_with($table, 'menu') => ['screen' => 'megamenu', 'label' => 'Open the menu editor'],
            $table === 'settings' => ['screen' => 'settings', 'label' => 'Open Settings'],
            default => null,
        };
    }

    /** "product descriptions", "the shop settings" -- the table as the owner knows it. */
    private static function place(string $table, string $column): string
    {
        $what = match (true) {
            $table === 'products' => 'products',
            str_starts_with($table, 'product_') => 'product details',
            $table === 'posts' => 'blog articles',
            str_starts_with($table, 'page') => 'pages',
            str_starts_with($table, 'menu') => 'the menus',
            $table === 'settings' => 'the shop settings',
            in_array($table, ['categories', 'category_translations'], true) => 'categories',
            in_array($table, ['brands', 'brand_translations'], true) => 'brands',
            str_contains($table, 'banner') => 'banners',
            $table === 'blocks' => 'content blocks',
            $table === 'redirects' => 'redirects',
            $table === 'reviews' => 'reviews',
            default => str_replace('_', ' ', $table),
        };

        return $what.' ('.$table.'.'.$column.')';
    }

    /* ═════════════════════════════════════════════════════════ pictures ══ */

    /**
     * Every picture the catalogue names, by where it is: on this server's disk,
     * a local path with no file behind it, or still on another site.
     *
     * @return array<string, mixed>
     */
    public function pictures(): array
    {
        $audit = app(MediaAudit::class);
        $summary = $audit->summarise($audit->audit());
        $plan = app(MediaSideloader::class)->plan();

        return [
            'ok' => true,
            'present' => (int) $summary['present'],
            'missing' => (int) $summary['missing'],
            'remote' => (int) $summary['remote'],
            'remaining' => (int) $plan['remaining'],
            'failed' => (int) $plan['failed'],
            'refused' => (int) $plan['refused'],
            'hosts' => array_values(array_map('strval', (array) ($plan['hosts'] ?? []))),
        ];
    }

    /* ══════════════════════════════════════════════════════════ network ══ */

    /**
     * Look up the new domain and the old one, and judge the answer.
     *
     * "This server" is what the domain being left resolves to right now, plus
     * any address an earlier check learned the same way ($known). Never a
     * constant -- see the note at the top of the class.
     *
     * @param  list<string>  $known
     * @return array<string, mixed>
     */
    public function checkDns(DnsLookup $dns, array $known = []): array
    {
        $new = $this->newBare();
        $old = $this->primaryOld();
        $queries = [[$new, 'A'], [$new, 'AAAA'], [$new, 'NS'], [$new, 'MX'], [$new, 'TXT'], ['www.'.$new, 'CNAME'], ['www.'.$new, 'A'], ['www.'.$new, 'AAAA']];

        if (in_array($old, $this->dnsHosts(), true)) {
            $queries[] = [$old, 'A'];
        }

        $hosts = $this->dnsHosts();
        $queries = array_values(array_filter($queries, fn ($q) => in_array($q[0], $hosts, true)));
        $answers = $dns->lookup($queries);

        $get = fn (string $host, string $type): array => $answers[$host.' '.$type] ?? ['ok' => false, 'records' => [], 'source' => 'none'];
        $a = $get($new, 'A');
        $aaaa = $get($new, 'AAAA');
        $oldA = $get($old, 'A');
        $expected = array_values(array_unique(array_filter([...$oldA['records'], ...array_filter($known, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)])));
        $here = $expected === [] ? 'this server' : 'this server ('.implode(', ', $expected).')';
        $www = 'www.'.$new;
        $wwwA = $get($www, 'A');
        $wwwCname = $get($www, 'CNAME')['records'];
        $wwwAaaa = $get($www, 'AAAA');

        $problems = [];
        $level = 'done';
        $worse = static function (string $now, string $to): string {
            return ['done' => 0, 'todo' => 1, 'problem' => 2][$to] > ['done' => 0, 'todo' => 1, 'problem' => 2][$now] ? $to : $now;
        };

        if (! $a['ok']) {
            $level = 'todo';
            $problems[] = 'The address book of the internet could not be asked just now. Try again in a minute.';
        } elseif ($expected === []) {
            $level = 'todo';
            $problems[] = 'The shop could not learn this server’s address: '.$old.' did not answer just now. Try again in a minute.';
        } elseif ($a['records'] === []) {
            $level = 'todo';
            $problems[] = $new.' has no A record yet. Add it at Internet.bs (step '.SwitchInstaller::num('dns_records').'), then wait a few minutes.';
        } elseif (array_diff($a['records'], $expected) !== []) {
            if (in_array(self::PREVIOUS_IP, $a['records'], true)) {
                $level = 'todo';
                $problems[] = $new.' still points at the old host ('.self::PREVIOUS_IP.'). If you already changed the A record at Internet.bs to '.implode(', ', $expected)
                    .', the change is still spreading (up to 48 hours): press Verify again later.';
            } else {
                $level = 'problem';
                $problems[] = $new.' points at '.implode(', ', $a['records']).', not at '.$here.'. Change the A record at Internet.bs to '.implode(', ', $expected).'.';
            }
        }

        if ($a['ok'] && $expected !== []) {
            // A CNAME to the bare name follows it, and the bare name is judged above.
            $wwwOk = in_array($new, $wwwCname, true)
                || ($wwwA['records'] !== [] && array_diff($wwwA['records'], $expected) === []);

            if (! $wwwOk) {
                $level = $worse($level, $wwwA['records'] === [] && $wwwCname === [] ? 'todo' : 'problem');
                $problems[] = $wwwA['records'] === [] && $wwwCname === []
                    ? $www.' has no record yet. Add the CNAME www → '.$new.' at Internet.bs.'
                    : $www.' points at '.implode(', ', $wwwA['records'] ?: $wwwCname).', not at '.$here.'. Make it a CNAME to '.$new.' at Internet.bs.';
            }
        }

        $aaaa = array_values(array_unique([...$aaaa['records'], ...$wwwAaaa['records']]));

        if ($aaaa !== []) {
            $level = 'problem';
            $problems[] = $new.' has an AAAA record ('.implode(', ', $aaaa).'). Delete it at Internet.bs: phones that use it would not reach this shop.';
        }

        $result = [
            'level' => $level,
            'checked_at' => now()->toIso8601String(),
            'host' => $new,
            'expected' => $expected,
            'records' => [
                'A' => $a['records'],
                'AAAA' => $aaaa,
                'NS' => $get($new, 'NS')['records'],
                'MX' => $get($new, 'MX')['records'],
                'TXT' => array_values(array_filter($get($new, 'TXT')['records'], fn ($t) => str_starts_with($t, 'v=spf1'))),
                'www' => $wwwCname ?: $wwwA['records'],
            ],
            'old' => ['host' => $old, 'A' => $oldA['records']],
            'source' => $a['source'],
            'problems' => $problems,
            'message' => $level === 'done'
                ? $new.' and www.'.$new.' point at '.$here.', and there is no AAAA record.'
                : implode(' ', $problems),
        ];

        $this->remember('dns', $result);

        return $result;
    }

    /**
     * What the domain being left resolves to: the address the shop is
     * reached on today, which is this server's public address.
     *
     * @return array<string, mixed>
     */
    public function checkOldDns(DnsLookup $dns): array
    {
        $old = $this->primaryOld();
        $answer = $dns->lookup([[$old, 'A']])[$old.' A'] ?? ['ok' => false, 'records' => [], 'source' => 'none'];

        $found = $answer['records'] !== [];
        $result = [
            'level' => ! $answer['ok'] || ! $found ? 'problem' : 'done',
            'checked_at' => now()->toIso8601String(),
            'host' => $old,
            'records' => $answer['records'],
            'source' => $answer['source'],
            'message' => ! $answer['ok']
                ? 'Could not look '.$old.' up just now. Try again in a minute.'
                : ($found
                    ? $old.' points at '.implode(', ', $answer['records']).': that is this server’s public address. Cloudways → Servers → your server shows the same Public IP.'
                    : $old.' points nowhere just now, so the shop cannot learn this server’s address. Read the Public IP in Cloudways → Servers → your server, and tell your developer.'),
        ];

        $this->remember('old_dns', $result);

        return $result;
    }

    /**
     * Fetch https://<new domain>/robots.txt and say what the TLS handshake did.
     *
     * Never follows a redirect (a 301 still proves the certificate) and never
     * echoes the transport's message: it carries resolved addresses and local
     * paths. The cURL error NUMBER is read to tell "no certificate for this
     * name" from "nothing answers" from "DNS not there yet".
     *
     * @return array<string, mixed>
     */
    public function checkTls(): array
    {
        $host = $this->target();
        $url = 'https://'.$host.'/robots.txt';

        try {
            $response = Http::timeout(8)->connectTimeout(4)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable $e) {
            $response = $e;
        }

        $probe = self::judgeTls($host, $response);
        $result = ['level' => $probe['level'] === 'green' ? 'done' : 'problem', 'status' => $probe['status'], 'message' => $probe['message']];

        $result += ['checked_at' => now()->toIso8601String(), 'host' => $host];
        $this->remember('tls', $result);

        return $result;
    }

    /**
     * The certificate check for several names at once, in parallel: each name's
     * https://<name>/robots.txt, no redirect followed. (Lane DW2)
     *
     * Only names this shop is moving between (probeHosts()) are ever fetched;
     * anything else is dropped before a request is made, so nothing typed or
     * sent can turn this into a probe of another host.
     *
     * @param  list<string>  $hosts
     * @return array<string, array{level: string, status: int|null, kind: string, message: string}>
     */
    public function tlsProbe(array $hosts): array
    {
        $allowed = $this->probeHosts();
        $hosts = array_values(array_unique(array_filter($hosts, fn ($h) => in_array($h, $allowed, true))));

        if ($hosts === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($hosts) {
                $out = [];

                foreach ($hosts as $host) {
                    $out[] = $pool->as($host)->timeout(8)->connectTimeout(4)
                        ->withOptions(['allow_redirects' => false])
                        ->get('https://'.$host.'/robots.txt');
                }

                return $out;
            });
        } catch (\Throwable) {
            $responses = [];
        }

        $out = [];

        foreach ($hosts as $host) {
            $out[$host] = self::judgeTls($host, $responses[$host] ?? null);
        }

        return $out;
    }

    /**
     * GET https://<the domain being left>/, no redirect followed: what a
     * shopper on an old link gets right now. (Lane DW2)
     *
     * Returns the status, where a redirect points, and the page's canonical
     * address -- the three facts the "caches" and "forwarding" checks judge.
     * Never the body, never the transport's message.
     *
     * @return array{ok: bool, status: int|null, location: string, canonical: string, host: string}
     */
    public function fetchOldHome(): array
    {
        $host = $this->primaryOld();
        $blank = ['ok' => false, 'status' => null, 'location' => '', 'canonical' => '', 'host' => $host];

        if (! in_array($host, $this->probeHosts(), true)) {
            return $blank;
        }

        try {
            $r = Http::timeout(8)->connectTimeout(4)->withOptions(['allow_redirects' => false])
                ->withHeaders(['Accept' => 'text/html'])->get('https://'.$host.'/');
        } catch (\Throwable) {
            return $blank;
        }

        $canonical = '';

        if (preg_match('/<link\b[^>]*\brel=["\']?canonical["\']?[^>]*>/i', substr($r->body(), 0, 300000), $tag) === 1
            && preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag[0], $href) === 1) {
            $canonical = html_entity_decode($href[1], ENT_QUOTES);
        }

        return [
            'ok' => true,
            'status' => $r->status(),
            'location' => (string) $r->header('Location'),
            'canonical' => $canonical,
            'host' => $host,
        ];
    }

    /** dnsHosts(), plus the www twin of each domain being left: every name a check may fetch. */
    public function probeHosts(): array
    {
        $out = $this->dnsHosts();

        foreach ($this->oldHosts() as $old) {
            if (! str_starts_with($old, 'www.')) {
                $out[] = 'www.'.$old;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * One name's certificate answer, in plain words. The cURL error NUMBER
     * tells "no certificate for this name" from "nothing answers" from "DNS
     * not there yet"; the message itself is never echoed.
     *
     * @return array{level: string, status: int|null, kind: string, message: string}
     */
    public static function judgeTls(string $host, mixed $response): array
    {
        $dns = SwitchInstaller::num('dns_wait');
        $ssl = SwitchInstaller::num('ssl');

        if ($response instanceof \Illuminate\Http\Client\Response) {
            $status = $response->status();

            return $status < 500
                ? ['level' => 'green', 'status' => $status, 'kind' => 'ok', 'message' => 'The certificate for '.$host.' is valid (the shop answered '.$status.').']
                : ['level' => 'amber', 'status' => $status, 'kind' => 'server', 'message' => 'The certificate for '.$host.' is valid, but the shop answered '.$status.'. Purge Varnish in Cloudways and check again.'];
        }

        $code = $response instanceof \Throwable && preg_match('/cURL error (\d+)/', $response->getMessage(), $m) === 1 ? (int) $m[1] : 0;

        return match (true) {
            $code === 6 => ['level' => 'amber', 'status' => null, 'kind' => 'dns', 'message' => $host.' cannot be found yet. Finish step '.$dns.' (DNS) first, then check again.'],
            in_array($code, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => ['level' => 'red', 'status' => null, 'kind' => 'cert',
                'message' => 'There is no valid certificate for '.$host.'. Do step '.$ssl.' in Cloudways (Let’s Encrypt, with every name listed), wait two minutes, then check again.'],
            in_array($code, [7, 28], true) => ['level' => 'red', 'status' => null, 'kind' => 'down',
                'message' => 'Nothing answered at '.$host.'. Check step '.SwitchInstaller::num('cloudways').' (Cloudways domain) and step '.$dns.' (DNS).'],
            default => ['level' => 'amber', 'status' => null, 'kind' => 'unknown', 'message' => 'Could not reach https://'.$host.' just now. Try again in a minute.'],
        };
    }

    /* ═══════════════════════════════════════════════════════ the state ══ */

    /**
     * Every step's status, from the shop as it is now.
     *
     * @return array<string, mixed>
     */
    public function state(Request $request): array
    {
        $target = $this->target();
        $new = $this->newBare();
        $old = $this->primaryOld();
        $canonical = SiteHost::canonical();
        $served = $this->servedOnTarget($request);
        $switched = $this->addressSwitched();
        $removed = $this->oldRemoved();
        $map = Setting::map();
        $siteUrl = trim((string) ($map['site_url'] ?? ''));
        $siteUrlOk = $siteUrl !== '' && SiteHost::normalise((string) parse_url($siteUrl, PHP_URL_HOST)) === $target
            && str_starts_with($siteUrl, 'https://');

        $listed = true;

        foreach ($this->oldHosts() as $host) {
            if (SiteHost::classify($host) !== SiteHost::ALIAS) {
                $listed = false;
            }
        }

        $dns = $this->recalled('dns');
        $oldDns = $this->recalled('old_dns');
        $tls = $this->recalled('tls');

        // A stored result about another name (the main address changed since) is not shown.
        $dns = ($dns['host'] ?? null) === $new ? $dns : null;
        $tls = ($tls['host'] ?? null) === $target ? $tls : null;

        $dnsLevel = $dns['level'] ?? ($served ? 'done' : 'todo');
        $tlsLevel = $tls['level'] ?? ($served ? 'done' : 'todo');

        // A page being served to you over https on the new name beats an older failed check.
        if ($served) {
            $tlsLevel = 'done';
            $dnsLevel = ($dns['records']['AAAA'] ?? []) !== [] ? 'problem' : 'done';
        }

        $payments = [];

        foreach (['stripe', 'tabby', 'tamara'] as $provider) {
            $last = $this->recalled('pay_'.$provider);
            $current = $last !== null && ($last['host'] ?? null) === $target;
            $payments[$provider] = [
                'level' => ! $current ? 'todo' : (($last['ok'] ?? false) ? 'done' : 'problem'),
                'message' => $current ? (string) ($last['message'] ?? '') : '',
                'at' => $current ? (string) ($last['at'] ?? '') : '',
            ];
        }

        $forwarding = SiteHost::redirectEnabled() && $canonical === $target;
        $ips = app(SwitchInstaller::class)->serverIps();

        return [
            'ok' => true,
            'target' => $target,
            'new' => $new,
            'old' => $old,
            'old_hosts' => $this->oldHosts(),
            // Learned from the world, never written down: SwitchInstaller::serverIps().
            'server_ip' => implode(', ', $ips),
            'previous_ip' => self::PREVIOUS_IP,
            'request' => [
                'host' => SiteHost::normalise($request->getHost()),
                'secure' => $request->isSecure(),
                'on_target' => $served,
            ],
            'now' => [
                'main_address' => $canonical,
                'old_addresses' => array_values(array_filter(SiteHost::aliases(), fn ($h) => DomainReadiness::bare($h) !== $new)),
                'forwarding' => SiteHost::redirectEnabled(),
                'app_url' => SiteUrl::configured(),
                'site_url' => $siteUrl,
                'owner_app_host' => (string) OwnerAppPath::host(),
            ],
            'proposed' => [
                'main_address' => $target,
                'old_addresses' => $this->oldHosts(),
                'app_url' => 'https://'.$target,
                // What "Use this address" would write -- the confirm value it must send back.
                'confirm' => $served ? SiteUrl::fromRequest($request) : null,
            ],
            'copy' => [
                'domains' => [$new, 'www.'.$new],
                'certificate' => self::certificateNames($new, $old),
                'certificate_after' => $new.', www.'.$new,
                'admin_on_target' => 'https://'.$target,
            ],
            'dns_table' => [
                ['type' => 'A', 'name' => '@', 'value' => $ips[0] ?? '', 'extra' => 'TTL 300'],
                ['type' => 'CNAME', 'name' => 'www', 'value' => $new, 'extra' => ''],
                ['type' => 'MX', 'name' => '@', 'value' => 'smtp.google.com', 'extra' => 'Priority 1'],
                ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 include:_spf.google.com ~all', 'extra' => ''],
            ],
            'checks' => ['dns' => $dns, 'old_dns' => $oldDns, 'tls' => $tls],
            'steps' => [
                'names' => $removed || ($canonical === $target && $listed) ? 'done' : 'todo',
                'cloudways' => $served || ($tls['level'] ?? null) === 'done' ? 'done' : 'yours',
                'dns' => $dnsLevel,
                'tls' => $tlsLevel,
                'switch' => $switched && $siteUrlOk ? 'done' : 'todo',
                'payments' => $payments,
                'forward' => $removed || $forwarding ? 'done' : 'todo',
                'remove' => $removed ? 'done' : 'todo',
            ],
            // Appearance -> Coming Soon page (Lane CS): one line at the top of the wizard.
            'coming_soon' => \App\Support\ComingSoon::summary(),
            'can' => [
                'switch' => $served,
                'payments' => $switched,
                'forward' => $switched && $canonical === $target,
                'remove' => $switched && $canonical === $target,
            ],
        ];
    }

    /* ═════════════════════════════════════════════════ results, dated ══ */

    /** @param  array<string, mixed>  $result */
    public function remember(string $what, array $result): void
    {
        try {
            Cache::put(self::CACHE_PREFIX.$what, $result, self::RESULT_TTL);
        } catch (\Throwable) {
            // The screen shows the result it just got either way.
        }
    }

    /** @return array<string, mixed>|null */
    public function recalled(string $what): ?array
    {
        try {
            $v = Cache::get(self::CACHE_PREFIX.$what);
        } catch (\Throwable) {
            return null;
        }

        return is_array($v) ? $v : null;
    }
}
