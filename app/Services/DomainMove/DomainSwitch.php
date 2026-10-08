<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Models\Setting;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaSideloader;
use App\Services\Instagram\InstagramAuth;
use App\Services\OwnerApp\OwnerAppPath;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * The Cloudways server's public address, as extrabeauty.ae resolves today
     * (docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md step 5). The shop cannot learn
     * its own public address reliably from inside the server -- behind
     * Varnish and nginx SERVER_ADDR is a private one -- so the screen shows
     * this, asks the owner to compare it with Cloudways, and also accepts
     * whatever the old domain resolves to right now.
     */
    public const SERVER_IP = '134.209.147.13';

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

    /** Old addresses cleared after the switch: step 11's in-shop half is done. */
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
                 * After step 11 the old addresses are gone ON PURPOSE. The engine
                 * still reads "not listed" as something to do, and its fix would be
                 * step 2 -- which would put them back. Said as done instead.
                 */
                if ($removed && in_array($c['what'], ['Old addresses to forward', 'Forward these, permanently'], true)) {
                    $c['level'] = DomainReadiness::OK;
                    $c['detail'] = $this->primaryOld().' was removed in step 11; nothing forwards it any more.';
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
        [$title, $fix] = match ($c['what']) {
            'APP_URL' => ['The shop’s own address (emails, payment notices, links)', ['step' => 6, 'label' => 'Go to step 6']],
            'Site URL' => ['The address Google is given (Site URL)', ['step' => 6, 'label' => 'Go to step 6']],
            'Main address' => ['The shop’s main address', ['step' => 2, 'label' => 'Go to step 2']],
            'Old addresses to forward' => ['The old addresses the shop knows about', ['step' => 2, 'label' => 'Go to step 2']],
            'Forward these, permanently' => ['Sending old links to the new address', ['step' => 10, 'label' => 'Go to step 10']],
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
                    ['step' => 8, 'label' => 'Fetch them (step 8)']]
                // Lane DS: an address on the domain being left names a file on
                // THIS server (one server, two names), so nothing needs fetching:
                // step 6b points the address at the new name, undoably.
                : [$n.' picture or video address(es) in '.$place.' still load from '.$r['host'],
                    ['step' => '6b', 'label' => 'Point them at the new address (step 6b)']],
            'link' => $isNew
                ? [$n.' link(s) in '.$place.' still send shoppers to '.$r['host'],
                    ['screen' => 'import', 'label' => 'Open Import → Addresses & pictures → Links to the old site']]
                : [$n.' link(s) in '.$place.' still send shoppers to '.$r['host'],
                    ['step' => '6b', 'label' => 'Point them at the new address (step 6b)']],
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
     * @return array<string, mixed>
     */
    public function checkDns(DnsLookup $dns): array
    {
        $new = $this->newBare();
        $old = $this->primaryOld();
        $queries = [[$new, 'A'], [$new, 'AAAA'], [$new, 'NS'], [$new, 'MX'], [$new, 'TXT'], ['www.'.$new, 'CNAME'], ['www.'.$new, 'A']];

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
        $expected = array_values(array_unique([self::SERVER_IP, ...$oldA['records']]));

        $problems = [];
        $level = 'done';

        if (! $a['ok']) {
            $level = 'problem';
            $problems[] = 'The address book of the internet could not be asked just now. Try again in a minute.';
        } elseif ($a['records'] === []) {
            $level = 'todo';
            $problems[] = $new.' has no A record yet. Add it at Internet.bs (the first row of the table above), then wait a few minutes.';
        } elseif (array_diff($a['records'], $expected) !== []) {
            $level = 'problem';
            $problems[] = in_array(self::PREVIOUS_IP, $a['records'], true)
                ? $new.' still points at the old host ('.self::PREVIOUS_IP.'). Change the A record at Internet.bs to '.self::SERVER_IP.'. It can take up to 48 hours to spread.'
                : $new.' points at '.implode(', ', $a['records']).', not at this server ('.self::SERVER_IP.'). Change the A record at Internet.bs.';
        }

        if ($aaaa['records'] !== []) {
            $level = 'problem';
            $problems[] = $new.' has an AAAA record ('.implode(', ', $aaaa['records']).'). Delete it at Internet.bs: phones that use it would not reach this shop.';
        }

        $result = [
            'level' => $level,
            'checked_at' => now()->toIso8601String(),
            'host' => $new,
            'expected' => $expected,
            'records' => [
                'A' => $a['records'],
                'AAAA' => $aaaa['records'],
                'NS' => $get($new, 'NS')['records'],
                'MX' => $get($new, 'MX')['records'],
                'TXT' => array_values(array_filter($get($new, 'TXT')['records'], fn ($t) => str_starts_with($t, 'v=spf1'))),
                'www' => $get('www.'.$new, 'CNAME')['records'] ?: $get('www.'.$new, 'A')['records'],
            ],
            'old' => ['host' => $old, 'A' => $oldA['records']],
            'source' => $a['source'],
            'problems' => $problems,
            'message' => $level === 'done'
                ? $new.' points at this server and has no AAAA record.'
                : implode(' ', $problems),
        ];

        $this->remember('dns', $result);

        return $result;
    }

    /**
     * Step 3's check: what the domain being left resolves to, which is the
     * address the shop is reached on today.
     *
     * @return array<string, mixed>
     */
    public function checkOldDns(DnsLookup $dns): array
    {
        $old = $this->primaryOld();
        $answer = $dns->lookup([[$old, 'A']])[$old.' A'] ?? ['ok' => false, 'records' => [], 'source' => 'none'];

        $matches = in_array(self::SERVER_IP, $answer['records'], true);
        $result = [
            'level' => ! $answer['ok'] ? 'problem' : ($matches ? 'done' : 'problem'),
            'checked_at' => now()->toIso8601String(),
            'host' => $old,
            'records' => $answer['records'],
            'source' => $answer['source'],
            'message' => ! $answer['ok']
                ? 'Could not look '.$old.' up just now. Try again in a minute.'
                : ($matches
                    ? $old.' points at '.self::SERVER_IP.'. Check that Cloudways → Servers → your server shows the same Public IP.'
                    : $old.' points at '.($answer['records'] === [] ? 'nothing' : implode(', ', $answer['records']))
                        .', not '.self::SERVER_IP.'. Use the Public IP Cloudways shows in the DNS records below, and tell your developer.'),
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

            $status = $response->status();
            $result = [
                'level' => $status < 500 ? 'done' : 'problem',
                'status' => $status,
                'message' => $status < 500
                    ? 'The certificate for '.$host.' is valid (the shop answered '.$status.').'
                    : 'The certificate is valid, but '.$host.' answered '.$status.'. Purge Varnish in Cloudways and check again.',
            ];
        } catch (ConnectionException $e) {
            $code = preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 0;

            $result = [
                'level' => 'problem',
                'status' => null,
                'message' => match (true) {
                    $code === 6 => $host.' cannot be found yet. Finish step 4 (DNS) first, then check again.',
                    in_array($code, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => 'There is no valid certificate for '.$host.' yet. Do step 5 in Cloudways (Let’s Encrypt), wait two minutes, then check again.',
                    in_array($code, [7, 28], true) => 'Nothing answered at '.$host.'. Check step 3 (Cloudways domain) and step 4 (DNS).',
                    default => 'Could not reach https://'.$host.'. Check steps 3, 4 and 5, then try again.',
                },
            ];
        } catch (\Throwable) {
            $result = ['level' => 'problem', 'status' => null, 'message' => 'Could not reach https://'.$host.'. Try again in a minute.'];
        }

        $result += ['checked_at' => now()->toIso8601String(), 'host' => $host];
        $this->remember('tls', $result);

        return $result;
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

        return [
            'ok' => true,
            'target' => $target,
            'new' => $new,
            'old' => $old,
            'old_hosts' => $this->oldHosts(),
            'server_ip' => self::SERVER_IP,
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
                'instagram' => 'https://'.$target.InstagramAuth::CALLBACK_PATH,
                'admin_on_target' => 'https://'.$target,
            ],
            'dns_table' => [
                ['type' => 'A', 'name' => '@', 'value' => self::SERVER_IP, 'extra' => 'TTL 300'],
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
