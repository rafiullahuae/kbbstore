<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Models\Setting;
use App\Services\Import\MediaRewrite;
use App\Services\Mail\MailSettings;
use App\Services\OwnerApp\OwnerAppPath;
use App\Support\SiteHost;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is this shop ready to be served from another domain? READ-ONLY. (Lane DM)
 *
 * The owner, 6 October 2026: "I'm going to change the domain for my app [...]
 * i want zero dependency of the old domain (extrabeauty.ae) if i change the app
 * to new domain. don't assume on anything." This is the measurement behind the
 * runbook in docs/DOMAIN-MOVE-KBEAUTYBLISS.md: one command that answers, from
 * the database and the configuration and nothing else, "what still names the
 * old domain, and what will break when the new one is switched over".
 *
 * WHY IT EXISTS AS WELL AS `kbb:import-media`. MediaAudit reads the PICTURE
 * columns it knows about. A domain move breaks on any column that stores an
 * absolute address -- a menu link typed by hand, a banner's target, a redirect
 * row, a robots.txt override, a policy page that says "email us at ...". So this
 * reads EVERY text column of EVERY table, by schema, rather than a list somebody
 * has to remember to extend.
 *
 * WHAT IT CANNOT DO, AND SAYS SO: it makes no network request. DNS, the
 * certificate, Stripe, Tabby, Tamara, Meta and Search Console are all outside
 * this database; the runbook lists them as third-party steps.
 *
 * THE TWO KINDS OF HOST.
 *
 *   OLD  -- every reference is a dependency on a domain that is being left. A
 *           link or a picture address there is a RISK; an email address or a
 *           plain mention is a TODO.
 *   NEW  -- the domain being moved TO. Before the switch it still serves the old
 *           WordPress shop, so an address under /wp-content/uploads/ on it works
 *           today and 404s on the day, unless this shop already has the file at
 *           the same path under its web root (it serves wp-content/uploads from
 *           disk, on any host). That one case is a RISK; a link to any other
 *           page on it is fine, because this shop answers it after the switch.
 *
 * COST. One SELECT per table to count (every text column of that table in the
 * one statement), then a read of only the rows that matched. No writes, no
 * cache writes, no settings writes. It is an operator command, never on a page.
 */
final class DomainReadiness
{
    public const OK = 'ok';

    public const TODO = 'todo';

    public const RISK = 'risk';

    public const INFO = 'info';

    /**
     * Tables that RECORD the past. An address in them is what happened -- an
     * email already sent, a request already logged, an order already placed --
     * and changing it would change nothing anybody sees. Counted, never read.
     */
    public const HISTORY = [
        'audit_events', 'cache', 'cache_locks', 'cart_events', 'cart_recoveries', 'customer_password_reset_tokens',
        'demo_seed_log', 'domain_content_rewrites', 'domain_switch_progress', 'failed_jobs', 'import_checkpoints', 'import_history', 'import_runs', 'job_batches', 'jobs',
        'mail_deliveries', 'mail_web_copies', 'media_sideload_items', 'media_sideload_runs', 'migrations',
        'mkt_clicks', 'mkt_sends', 'not_found_log', 'old_link_rewrites', 'order_emails', 'order_items',
        'order_notes', 'orders', 'owner_app_events', 'owner_app_logins', 'password_reset_tokens', 'payment_events',
        'payments', 'push_sends', 'quiz_submissions', 'reconciliation_checkpoints', 'reconciliation_findings',
        'reconciliation_runs', 'reconciliation_sightings', 'redirect_decisions', 'refunds', 'search_terms',
        'sessions', 'update_releases', 'webp_conversions',
    ];

    /**
     * Settings that are SUPPOSED to name a host. They are the configuration
     * this class checks one by one in checks(), so counting them again as
     * "content still naming the old domain" would report the cure as the
     * disease -- `host_aliases` has to say extrabeauty.ae for the forwarding to
     * work at all.
     */
    public const CONFIG_SETTINGS = [
        SiteHost::KEY_CANONICAL, SiteHost::KEY_ALIASES, 'site_url', OwnerAppPath::HOST_SETTING,
    ];

    /** A character that may continue a host name. */
    private const HOST_CHAR = '[a-z0-9-]';

    private string $new;

    /** @var list<string> tables the last references() call left unread for want of time */
    private array $skipped = [];

    private int $tableCount = 0;

    /** @var list<string> */
    private array $old;

    /**
     * @param  string  $new  the domain the shop is moving TO, e.g. kbeautybliss.com
     * @param  list<string>  $old  the domains it is leaving; empty derives them from the configuration
     */
    public function __construct(string $new, array $old = [])
    {
        $this->new = self::bare($new);
        $old = $old === [] ? self::derivedOld($this->new) : $old;

        $this->old = array_values(array_unique(array_filter(
            array_map(self::bare(...), $old),
            fn (string $h): bool => $h !== '' && $h !== $this->new,
        )));
    }

    /** Lower-case, no scheme, no path, no port, no `www.`. */
    public static function bare(string $host): string
    {
        $host = trim($host);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $host) === 1) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        $host = SiteHost::normalise(explode('/', $host)[0]);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Every host the configuration names that is not the new one: APP_URL,
     * Site URL, the main address and the typed old addresses.
     *
     * @return list<string>
     */
    public static function derivedOld(string $new): array
    {
        $map = Setting::map();
        $out = [
            (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            (string) parse_url((string) ($map['site_url'] ?? ''), PHP_URL_HOST),
            (string) ($map[SiteHost::KEY_CANONICAL] ?? ''),
        ];

        foreach (preg_split('/[\r\n,]+/', (string) ($map[SiteHost::KEY_ALIASES] ?? '')) ?: [] as $line) {
            $out[] = (string) $line;
        }

        return array_values(array_unique(array_filter(
            array_map(self::bare(...), $out),
            fn (string $h): bool => $h !== '' && $h !== self::bare($new) && $h !== 'localhost',
        )));
    }

    /**
     * Tables the last references() call did not read because its deadline had
     * passed. Always a tail of the table list, so a later call with
     * offset = tableCount() - count(skipped()) reads exactly those.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** How many tables with a text column the last references() call saw. */
    public function tableCount(): int
    {
        return $this->tableCount;
    }

    public function newHost(): string
    {
        return $this->new;
    }

    /** @return list<string> */
    public function oldHosts(): array
    {
        return $this->old;
    }

    /* ═══════════════════════════════════════════════════════ configuration ══ */

    /**
     * One line per thing that has to agree with the new domain.
     *
     * @return list<array{level: string, what: string, detail: string, where: string}>
     */
    public function checks(): array
    {
        $out = [];
        $map = Setting::map();

        // ── APP_URL: every email link, webhook, canonical tag and redirect.
        $app = SiteUrl::configured();
        $appHost = self::bare((string) parse_url($app, PHP_URL_HOST));

        if ($app === '') {
            $out[] = $this->line(self::RISK, 'APP_URL', 'missing or unusable: emails, webhooks and canonical tags have no address', '.env');
        } elseif ($appHost === $this->new) {
            $out[] = $this->line(
                str_starts_with($app, 'https://') ? self::OK : self::RISK,
                'APP_URL',
                $app.(str_starts_with($app, 'https://') ? '' : ' -- must start with https://'),
                '.env',
            );
        } else {
            $out[] = $this->line(self::TODO, 'APP_URL', 'still '.$app.' -- change it on the day, after the new domain answers here',
                'Platform → Site address (the banner), or .env then php artisan config:clear');
        }

        if ($app !== '' && (string) parse_url($app, PHP_URL_PATH) !== '') {
            $out[] = $this->line(self::RISK, 'APP_URL path', 'carries a folder ('.parse_url($app, PHP_URL_PATH).'); a shop at a domain root has none', '.env');
        }

        $base = trim((string) config('kbb.base_path', ''), '/');
        $out[] = $base === ''
            ? $this->line(self::OK, 'KBB_BASE_PATH', 'empty', '.env')
            : $this->line(self::RISK, 'KBB_BASE_PATH', '"'.$base.'" prefixes every link; empty at a domain root', '.env');

        // ── Site URL: sitemap, canonical, Open Graph and IndexNow prefer it over APP_URL.
        $siteUrl = trim((string) ($map['site_url'] ?? ''));
        $siteHost = self::bare((string) parse_url($siteUrl, PHP_URL_HOST));
        $where = 'Store → SEO & Meta → Settings → Search appearance → Site URL (canonical base)';

        if ($siteUrl === '') {
            $out[] = $this->line(self::OK, 'Site URL', 'empty, so APP_URL is used', $where);
        } elseif ($siteHost === $this->new) {
            $out[] = $this->line(self::OK, 'Site URL', $siteUrl, $where);
        } else {
            $out[] = $this->line(self::TODO, 'Site URL', 'still '.$siteUrl.' -- sitemap and canonical tags follow it, not APP_URL', $where);
        }

        // ── Main address and the forwarding.
        $where = 'Platform → Site address';
        $canonical = SiteHost::canonical();

        if (self::bare($canonical) === $this->new && $canonical !== '') {
            $out[] = $this->line(self::OK, 'Main address', $canonical, $where);
        } elseif ($canonical === '') {
            $out[] = $this->line(self::TODO, 'Main address', 'empty -- set it to '.$this->new.' before the DNS change', $where);
        } else {
            $out[] = $this->line(self::TODO, 'Main address', 'still '.$canonical.' -- while it is, '.$this->new
                .' pointed here answers noindex (or forwards away) instead of serving the shop', $where);
        }

        if ($canonical !== '') {
            $missing = [];

            foreach ($this->old as $host) {
                foreach ([$host, 'www.'.$host] as $name) {
                    if (SiteHost::classify($name) !== SiteHost::ALIAS && SiteHost::normalise($name) !== $canonical) {
                        $missing[] = $name;
                    }
                }
            }

            $out[] = $missing === []
                ? $this->line(self::OK, 'Old addresses to forward', implode(', ', SiteHost::aliases()), $where)
                : $this->line(self::TODO, 'Old addresses to forward', 'not listed: '.implode(', ', $missing), $where);

            $out[] = SiteHost::redirectEnabled()
                ? $this->line(self::OK, 'Forward these, permanently', 'on', $where)
                : $this->line(self::TODO, 'Forward these, permanently', 'off -- switch it on once the test checklist passes', $where);
        }

        $out[] = SiteHost::visibilityIsPrivate()
            ? $this->line(self::RISK, 'Keep this install out of Google', 'ON -- the live shop answers noindex everywhere', $where)
            : $this->line(self::OK, 'Keep this install out of Google', 'off', $where);

        if (filter_var(env('KBB_NOINDEX', false), FILTER_VALIDATE_BOOL)) {
            $out[] = $this->line(self::RISK, 'KBB_NOINDEX', 'set -- every page answers X-Robots-Tag: noindex', '.env');
        }

        // ── Cookies. Host-only (empty) follows the shop to any domain.
        $sessionDomain = trim((string) config('session.domain', ''));
        $out[] = $sessionDomain === '' || self::bare(ltrim($sessionDomain, '.')) === $this->new
            ? $this->line(self::OK, 'SESSION_DOMAIN', $sessionDomain === '' ? 'empty (host-only cookies)' : $sessionDomain, '.env')
            : $this->line(self::RISK, 'SESSION_DOMAIN', $sessionDomain.' -- browsers refuse it on '.$this->new.': nobody can log in or keep a cart', '.env');

        if (app()->configurationIsCached()) {
            $out[] = $this->line(self::INFO, 'Compiled configuration', 'cached -- after any .env edit run php artisan config:clear, or the old value stays', 'SSH');
        }

        // ── The owner app's own host, when it has one.
        $ownerHost = (string) OwnerAppPath::host();

        if ($ownerHost !== '') {
            $out[] = $this->isOld($ownerHost)
                ? $this->line(self::TODO, 'Owner app host', $ownerHost.' is on the old domain -- move it to a subdomain of '.$this->new.' or clear it (phones sign in again)', 'Platform → Users & Roles → Owner app → Security → Own host')
                : $this->line(self::OK, 'Owner app host', $ownerHost, 'Platform → Users & Roles → Owner app → Security → Own host');
        }

        // ── Mail. The From domain is what SPF and DKIM are checked against.
        $from = (string) app(MailSettings::class)->fromAddress();
        $fromHost = self::bare((string) substr((string) strrchr($from, '@'), 1));

        if ($from === '') {
            $out[] = $this->line(self::RISK, 'Mail From address', 'none -- order emails cannot leave', 'Emails → Sending & delivery');
        } elseif ($this->isOld($fromHost) && $from === 'no-reply@'.$appHost) {
            // Nobody typed it: MailSettings::fromAddress() derives it from APP_URL, so it moves on the day by itself.
            $out[] = $this->line(self::TODO, 'Mail From address', $from.' is derived from APP_URL and becomes no-reply@'.$this->new
                .' on the day -- SPF/DKIM/DMARC of '.$this->new.' must authorise the sender ('.app(MailSettings::class)->transport()
                .'), or type a From you already send from', 'Emails → Sending & delivery');
        } elseif ($this->isOld($fromHost)) {
            $out[] = $this->line(self::TODO, 'Mail From address', $from.' is on the old domain', 'Emails → Sending & delivery');
        } else {
            $out[] = $this->line(self::INFO, 'Mail From address', $from.' (sent by: '.app(MailSettings::class)->transport()
                .') -- SPF/DKIM/DMARC of '.($fromHost !== '' ? $fromHost : 'that domain').' must authorise that sender', 'your DNS');
        }

        // ── A one-off migration tool that reads the WordPress shop by name.
        if (self::bare((string) config('kbb.hierarchy_source_host', '')) === $this->new) {
            $out[] = $this->line(self::INFO, 'Copy hierarchy source', $this->new.' -- after the switch that is this shop, not WordPress; '
                .'set KBB_HIERARCHY_SOURCE_HOST if a WordPress copy stays reachable elsewhere', '.env');
        }

        // ── ASSET_URL (Lane DS): every compiled script and stylesheet is built on it when set.
        $assetHost = self::bare((string) parse_url((string) config('app.asset_url', ''), PHP_URL_HOST));

        if ($assetHost !== '' && $this->isOld($assetHost)) {
            $out[] = $this->line(self::RISK, 'ASSET_URL', (string) config('app.asset_url').' -- every script and stylesheet would load from the old domain', '.env');
        }

        foreach ($this->webRootFiles() as $line) {
            $out[] = $line;
        }

        return $out;
    }

    /** Files the web server answers before the shop does. A static one wins over the shop's own. */
    public const WEB_ROOT_FILES = '/^(?:\.htaccess|[A-Za-z0-9._-]+\.(?:txt|xml|json|webmanifest|html?))$/';

    /**
     * Web-root files that name a domain being left. (Lane DS.)
     *
     * The database scan cannot see these, and they are served by Apache before
     * the application is asked: a static robots.txt or sitemap.xml left from an
     * earlier setup pointing at extrabeauty.ae would be what Google reads on
     * kbeautybliss.com, and an .htaccess rule naming the old host keeps working
     * (or breaking) after the switch. Top level only, small files only, and
     * the escaped form an .htaccess condition uses (extrabeauty\.ae) counts.
     *
     * @return list<array{level: string, what: string, detail: string, where: string}>
     */
    public function webRootFiles(?string $root = null): array
    {
        $root ??= public_path();
        $out = [];

        if ($this->old === [] || ! is_dir($root)) {
            return $out;
        }

        $alts = implode('|', array_map(fn ($h) => str_replace('\\.', '\\\\?\\.', preg_quote($h, '~')), $this->old));
        $seen = 0;

        foreach (scandir($root) ?: [] as $name) {
            $path = $root.DIRECTORY_SEPARATOR.$name;

            if (preg_match(self::WEB_ROOT_FILES, $name) !== 1 || ! is_file($path) || is_link($path) || filesize($path) > 512 * 1024 || ++$seen > 60) {
                continue;
            }

            $body = (string) @file_get_contents($path);

            if (preg_match_all('~(?<![a-z0-9-])(?:[a-z0-9-]+\\\\?\.)*(?:'.$alts.')(?![a-z0-9-])~i', $body, $m) > 0) {
                $risky = in_array(strtolower($name), ['robots.txt', 'sitemap.xml', 'sitemap_index.xml', 'manifest.json', 'site.webmanifest'], true);
                $out[] = $this->line($risky ? self::RISK : self::TODO, 'Web root file',
                    $name.' names '.strtolower(str_replace('\\', '', $m[0][0])).' '.count($m[0]).' time(s)'
                    .($risky ? ' -- the server sends this file instead of the shop\'s own' : ' -- read what that line does before the switch'),
                    'the web root folder (public_html) on the server');
            }
        }

        return $out;
    }

    /* ════════════════════════════════════════════════════════════ content ══ */

    /**
     * Every stored reference to the old domains, and every picture or video
     * address on the new domain this shop does not hold a copy of.
     *
     * @param  float|null  $deadline  microtime(true) after which no further table is started; null reads them all
     * @param  int  $offset  tables to skip from the start (a continuation)
     * @return list<array{table: string, column: string, host: string, kind: string, level: string, count: int, samples: list<string>}>
     */
    public function references(int $samples = 5, ?float $deadline = null, int $offset = 0): array
    {
        $hosts = array_values(array_unique([...$this->old, $this->new]));
        $rows = [];
        $tables = $this->textColumns();
        $this->tableCount = count($tables);
        $this->skipped = [];
        $at = 0;

        foreach ($tables as $table => $columns) {
            /*
             * THE TIME BUDGET (Lane DW). The command passes none and reads every
             * table, as before. Platform -> Domain switch runs this inside a web
             * request, so it passes a deadline: once it has passed, every table
             * still to come is named in skipped() and NOT read -- never reported
             * as clean -- and the screen offers "check the rest" from $offset.
             * Checked between tables, so one table is always read whole.
             */
            if ($at++ < $offset) {
                continue;
            }

            if ($deadline !== null && microtime(true) >= $deadline) {
                $this->skipped[] = $table;

                continue;
            }

            $counts = $this->counts($table, $columns, $hosts);

            if ($counts === null) {
                // Never a silent OK: a table that could not be read is reported.
                $rows[] = ['table' => $table, 'column' => '*', 'host' => '', 'kind' => 'unreadable',
                    'level' => self::RISK, 'count' => 0, 'samples' => []];

                continue;
            }

            foreach ($counts as [$column, $host, $matched]) {
                if ($matched === 0) {
                    continue;
                }

                if (in_array($table, self::HISTORY, true)) {
                    if ($host !== $this->new) {
                        $rows[] = ['table' => $table, 'column' => $column, 'host' => $host, 'kind' => 'history',
                            'level' => self::INFO, 'count' => $matched, 'samples' => []];
                    }

                    continue;
                }

                foreach ($this->classify($table, $column, $host, $samples) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Text-ish columns, per table. Binary, numeric and date columns cannot
     * hold an address and are not read.
     *
     * @return array<string, list<string>>
     */
    private function textColumns(): array
    {
        $out = [];

        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '' || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            try {
                $columns = Schema::getColumns($name);
            } catch (\Throwable) {
                continue;
            }

            $text = [];

            foreach ($columns as $column) {
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

                if (preg_match('/char|text|json|clob|string/', $type) === 1 && ! str_contains($type, 'binary')) {
                    $text[] = (string) $column['name'];
                }
            }

            if ($text !== []) {
                $out[$name] = $text;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * One statement per table: how many rows mention each host in each column.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $hosts
     * @return list<array{0: string, 1: string, 2: int}>|null null when the table could not be read
     */
    private function counts(string $table, array $columns, array $hosts): ?array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $select = [];
        $bindings = [];
        $keys = [];

        foreach ($columns as $c => $column) {
            foreach ($hosts as $h => $host) {
                $select[] = 'SUM(CASE WHEN '.$grammar->wrap($column).' LIKE ? THEN 1 ELSE 0 END) AS '.$grammar->wrap('m_'.$c.'_'.$h);
                $bindings[] = '%'.$host.'%';
                $keys[] = ['m_'.$c.'_'.$h, $column, $host];
            }
        }

        try {
            $row = (array) DB::table($table)->selectRaw(implode(', ', $select), $bindings)->first();
        } catch (\Throwable) {
            return null;
        }

        $out = [];

        foreach ($keys as [$alias, $column, $host]) {
            $out[] = [$column, $host, (int) ($row[$alias] ?? 0)];
        }

        return $out;
    }

    /**
     * Read the matching rows of one column and sort every mention of one host
     * into what it is.
     *
     * @return list<array{table: string, column: string, host: string, kind: string, level: string, count: int, samples: list<string>}>
     */
    private function classify(string $table, string $column, string $host, int $samples): array
    {
        $isNew = $host === $this->new;
        $key = $this->keyColumn($table);
        $buckets = [];

        $query = DB::table($table)->select(array_values(array_unique([$key, $column])))->where($column, 'like', '%'.$host.'%');

        foreach ($query->cursor() as $record) {
            $record = (array) $record;

            if ($table === 'settings' && in_array((string) ($record['key'] ?? ''), self::CONFIG_SETTINGS, true)) {
                continue;
            }

            $label = $table === 'settings' ? 'setting '.$record['key'] : $key.' '.$record[$key];

            foreach ($this->mentions((string) $record[$column], $host) as [$kind, $address]) {
                if ($isNew && $kind === 'upload' && $this->onDisk($address)) {
                    $kind = 'upload_here';
                }

                $level = $this->levelFor($kind, $isNew);

                if ($level === null) {
                    continue;
                }

                $bucket = &$buckets[$kind];
                $bucket ??= ['table' => $table, 'column' => $column, 'host' => $host, 'kind' => $kind,
                    'level' => $level, 'count' => 0, 'samples' => []];
                $bucket['count']++;

                if (count($bucket['samples']) < $samples) {
                    $bucket['samples'][] = $label.': '.$address;
                }

                unset($bucket);
            }
        }

        return array_values($buckets);
    }

    /** What one kind of mention means for this move, or null when it is not worth a line. */
    private function levelFor(string $kind, bool $isNew): ?string
    {
        if ($isNew) {
            return match ($kind) {
                'upload' => self::RISK,       // served by WordPress today, 404 on the day
                'upload_here' => self::OK,    // this shop has the file at the same path
                'link' => self::INFO,         // this shop answers the page after the switch
                default => null,              // info@kbeautybliss.com, "kbeautybliss.com" in a sentence
            };
        }

        return match ($kind) {
            'upload', 'link' => self::RISK,   // loads from, or sends shoppers to, the old domain
            'email', 'text' => self::TODO,    // not a page dependency, but it still names the old domain
            default => null,
        };
    }

    /**
     * Every mention of one host (or a subdomain of it) in a value.
     *
     * @return list<array{0: string, 1: string}> [kind, the address or the mention]
     */
    private function mentions(string $value, string $host): array
    {
        // JSON stores `https:\/\/host\/path`.
        $value = str_replace('\\/', '/', $value);
        $pattern = '~(?<!'.self::HOST_CHAR.'|\.)((?:'.self::HOST_CHAR.'+\.)*)'.preg_quote($host, '~')
            .'(?!'.self::HOST_CHAR.'|\.[a-z0-9])(/[^\s"\'<>()\[\]\\\\]*)?~i';

        if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out = [];

        foreach ($matches as $m) {
            $at = (int) $m[0][1];
            $before = substr($value, max(0, $at - 8), min(8, $at));
            $path = (string) ($m[2][0] ?? '');
            $name = strtolower($m[1][0].$host);

            if (str_ends_with($before, '@')) {
                $out[] = ['email', '…@'.$name];
            } elseif (str_ends_with($before, '//') || $path !== '') {
                $out[] = [MediaRewrite::uploadsRelativeTo(strtok($path, '?#') ?: '/') !== null ? 'upload' : 'link',
                    'https://'.$name.($path === '' ? '/' : $path)];
            } else {
                $out[] = ['text', $name];
            }
        }

        return $out;
    }

    /** Does this shop's web root already hold the file this uploads address names? */
    private function onDisk(string $address): bool
    {
        $path = rawurldecode((string) parse_url($address, PHP_URL_PATH));
        $relative = MediaRewrite::uploadsRelativeTo($path);

        if ($relative === null || str_contains($relative, '..')) {
            return false;
        }

        return is_file(public_path($relative));
    }

    private function keyColumn(string $table): string
    {
        $names = array_map(fn ($c) => (string) $c['name'], Schema::getColumns($table));

        foreach (['id', 'key'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return $names[0] ?? 'id';
    }

    private function isOld(string $host): bool
    {
        $host = self::bare($host);

        foreach ($this->old as $old) {
            if ($host === $old || str_ends_with($host, '.'.$old)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{level: string, what: string, detail: string, where: string} */
    private function line(string $level, string $what, string $detail, string $where): array
    {
        return ['level' => $level, 'what' => $what, 'detail' => $detail, 'where' => $where];
    }
}
