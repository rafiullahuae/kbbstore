<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\BrandName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Extra Beauty" → "K-Beauty Bliss" in the shop's STORED brand text. Lane BR.
 *
 * The code has said K-Beauty Bliss for months; what the live shop shows comes
 * from the settings table and the owner's own content. This finds the old name
 * there and replaces it — the migration runs it once, Store → SEO Keywords →
 * Brand runs it again on demand, with a dry-run count first.
 *
 * ── WHERE IT LOOKS, AND THE LIST IS THE SAFETY ──────────────────────────────
 * TARGETS is an allowlist of brand-bearing columns: settings and module
 * settings, the interface-string overrides and the translations of pages,
 * articles, menus and video sections, the email and marketing templates (and
 * only UNSENT campaigns), content pages and articles, menus, banners and grid
 * headings, reusable blocks, tags, the SEO boxes of products / categories /
 * brands, and the SEO keyword sets. NOTHING ELSE IS READ. Orders, customers,
 * payments, refunds, reviews, product names and descriptions, import history
 * and mail logs are not on the list, so there is no code path that could touch
 * them — BrandRenameTest seeds "Extra Beauty" into each and proves it.
 *
 * ── WHAT IT LEAVES, EVEN INSIDE A TARGET ───────────────────────────────────
 * BrandName::OLD only matches the old name used AS A NAME: extrabeauty.ae,
 * emails, handles and legal entities ("Extra Beauty Trading LLC") are left,
 * and settings whose key is a credential, host, address or URL are skipped
 * outright. The check screen lists what was left and why.
 *
 * ── NEVER THROWS ────────────────────────────────────────────────────────────
 * Each target is its own try/catch, a missing table or column is skipped, and
 * a write that fails is counted as an error and logged. A brand rename that
 * aborted a Core Update would be the 2.60.x updater incident again.
 */
final class BrandRename
{
    /**
     * table => [primary key, columns, extra where (column => list of allowed values), lowercase keyword table?]
     *
     * @var array<string, array{0: string, 1: list<string>, 2?: array<string, list<string>>, 3?: bool}>
     */
    public const TARGETS = [
        'settings' => ['key', ['value']],
        'module_settings' => ['id', ['value']],
        'translations' => ['id', ['value'], ['group' => ['ui', 'pages', 'posts', 'menu_items', 'menus', 'ugc_sections']]],
        'email_templates' => ['id', ['subject', 'preheader', 'heading', 'body', 'blocks']],
        'mkt_templates' => ['id', ['name', 'subject', 'preheader', 'blocks']],
        'mkt_campaigns' => ['id', ['subject', 'preheader', 'from_name', 'blocks'], ['status' => ['draft', 'scheduled', 'paused']]],
        'pages' => ['id', ['title', 'content', 'doc_json', 'seo']],
        'posts' => ['id', ['title', 'excerpt', 'body', 'seo']],
        'menus' => ['id', ['name']],
        'menu_items' => ['id', ['label']],
        'banner_cards' => ['id', ['alt', 'heading', 'body', 'button_label']],
        'grid_sections' => ['id', ['heading', 'subheading']],
        'ugc_sections' => ['id', ['title', 'heading', 'subheading']],
        'blocks' => ['id', ['name', 'content']],
        'tags' => ['id', ['name']],
        'products' => ['id', ['seo', 'seo_json']],
        'categories' => ['id', ['seo']],
        'brands' => ['id', ['seo']],
        'seo_page_keywords' => ['id', ['keywords', 'layers', 'primary_kw', 'suggest'], [], true],
    ];

    /*
     * Settings that are never brand text even when they mention the old name:
     * secrets, hosts, URLs, addresses and identifiers. A host or an email here
     * is the DOMAIN, which the cutover moves, not this.
     */
    private const SKIP_KEY = '/(pass|secret|token|api_?key|private|_url$|^url|url_|host|domain|email|mail_from_address|_address$|username|verification|webhook|_dsn|_id$|^seo_kw_live$|^admin_path$)/i';

    /** @internal tests only: a target list to walk instead of TARGETS. */
    public static ?array $targets = null;

    /** At most this many rows are read per column — a ceiling, not an expectation. */
    private const CAP = 5000;

    /**
     * Count, never write.
     *
     * @return array{rows: list<array{table: string, column: string, id: string, label: string, count: int, before: string, after: string}>, left: list<array{table: string, column: string, id: string, label: string, sample: string, why: string}>, total: int, errors: list<string>}
     */
    public static function scan(): array
    {
        return self::walk(false);
    }

    /** Replace, then flush the caches the changed rows feed. Same shape as scan(). */
    public static function apply(): array
    {
        $out = self::walk(true);

        if ($out['total'] > 0) {
            self::flush();
        }

        return $out;
    }

    private static function walk(bool $write): array
    {
        $rows = [];
        $left = [];
        $errors = [];
        $total = 0;

        foreach (self::$targets ?? self::TARGETS as $table => $spec) {
            [$pk, $columns] = $spec;
            $where = $spec[2] ?? [];
            $lower = $spec[3] ?? false;

            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $have = Schema::getColumnListing($table);
                $columns = array_values(array_intersect($columns, $have));
                if ($columns === [] || ! in_array($pk, $have, true)) {
                    continue;
                }

                foreach ($columns as $col) {
                    $q = DB::table($table)->select([$pk, $col]);
                    if ($table === 'settings' || $table === 'module_settings') {
                        $q->addSelect('key');
                    }
                    foreach ($where as $wc => $allowed) {
                        if (in_array($wc, $have, true)) {
                            $q->whereIn($wc, $allowed);
                        }
                    }
                    /*
                     * A coarse, index-free prefilter; the regex is the real test.
                     * LOWER(CAST(… AS CHAR)) because MySQL compares a JSON column
                     * (products.seo, pages.seo …) with a BINARY collation, so a
                     * plain LIKE '%extra%' misses "Extra Beauty" there — measured
                     * on the MySQL suite. SQLite reads CHAR as TEXT.
                     */
                    $text = 'LOWER(CAST('.DB::getQueryGrammar()->wrap($col).' AS CHAR))';
                    $q->where(static function ($w) use ($text): void {
                        $w->whereRaw($text.' LIKE ?', ['%extra%beauty%'])
                            ->orWhereRaw($text.' LIKE ?', ['%كسترا%بيوتي%'])
                            ->orWhereRaw($text.' LIKE ?', ['%u0643%u0633%u062a%u0631%u0627%']);
                    });

                    foreach ($q->limit(self::CAP)->get() as $r) {
                        $value = $r->{$col};
                        if (! is_string($value) || $value === '') {
                            continue;
                        }
                        $id = (string) $r->{$pk};
                        $label = isset($r->key) ? (string) $r->key : $table.'#'.$id;

                        if (isset($r->key) && preg_match(self::SKIP_KEY, (string) $r->key)) {
                            // No sample: the key may hold a credential, and this list reaches the screen.
                            if (BrandName::anyCount($value) > 0) {
                                $left[] = self::leftRow($table, $col, $id, $label, '', 'an address, URL or credential — the domain moves at the cutover, not here');
                            }
                            continue;
                        }

                        [$new, $n] = BrandName::replace($value, $lower);
                        $remaining = BrandName::anyCount($new);

                        if ($n > 0) {
                            if ($write) {
                                // Only if nobody changed it since it was read. Compared as
                                // text, so a MySQL JSON column matches the text it gave us.
                                $changed = DB::table($table)->where($pk, $r->{$pk})
                                    ->whereRaw('CAST('.DB::getQueryGrammar()->wrap($col).' AS CHAR) = ?', [$value])
                                    ->update([$col => $new]);
                                if ($changed < 1) {
                                    continue; // somebody edited it in between; theirs wins
                                }
                            }
                            $total += $n;
                            $rows[] = [
                                'table' => $table, 'column' => $col, 'id' => $id, 'label' => $label, 'count' => $n,
                                'before' => self::excerpt($value), 'after' => self::excerpt($new),
                            ];
                        }

                        if ($remaining > 0) {
                            $left[] = self::leftRow($table, $col, $id, $label, $new, self::why($new));
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $table.': '.$e->getMessage();
                Log::warning('BrandRename: '.$table.' skipped', ['error' => $e->getMessage()]);
            }
        }

        if ($write) {
            $per = [];
            foreach ($rows as $r) {
                $k = $r['table'] === 'settings' ? 'settings.'.$r['label'] : $r['table'].'.'.$r['column'];
                $per[$k] = ($per[$k] ?? 0) + $r['count'];
            }
            Log::info('BrandRename: Extra Beauty → K-Beauty Bliss', ['total' => $total, 'per' => $per, 'errors' => $errors]);
        }

        return ['rows' => $rows, 'left' => array_slice($left, 0, 200), 'total' => $total, 'errors' => $errors];
    }

    private static function leftRow(string $table, string $col, string $id, string $label, string $value, string $why): array
    {
        return ['table' => $table, 'column' => $col, 'id' => $id, 'label' => $label, 'sample' => $value === '' ? '' : self::excerpt($value), 'why' => $why];
    }

    private static function why(string $value): string
    {
        if (preg_match('/extra[\s\-]?beauty\.[a-z]|[@\/]extra[\s\-_]?beauty/i', $value)) {
            return 'part of a web address, email or handle';
        }
        if (preg_match('/#extra/i', $value)) {
            return 'a hashtag';
        }
        if (preg_match('/extra[\s\-]?beauty\s+(general\s+)?(trading|llc|l\.l\.c|fze|fzco|fz|est|establishment|company|co\b|ltd|limited|inc|group)/i', $value)) {
            return 'a legal company name — change it only if your trade licence says K-Beauty Bliss';
        }

        return 'lower case inside a sentence — reads like ordinary words, so it is yours to judge';
    }

    /** ~90 characters around the first occurrence, as plain text. */
    private static function excerpt(string $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['\\n', '\\r', '\\t'], ' ', $value))));
        $at = 0;
        if (preg_match(BrandName::ANY, $text, $m, PREG_OFFSET_CAPTURE) || preg_match('/K-Beauty Bliss/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $at = max(0, mb_strlen(substr($text, 0, (int) $m[0][1])) - 40);
        }
        $cut = mb_substr($text, $at, 90);

        return ($at > 0 ? '…' : '').$cut.(mb_strlen($text) > $at + 90 ? '…' : '');
    }

    private static function flush(): void
    {
        foreach ([
            static fn () => Setting::flushMap(),
            static fn () => SettingsService::forgetMemo(),
            static fn () => TranslationStore::flush(),
            static fn () => app(\App\Services\NavigationService::class)->flush(),
            static fn () => Cache::forget('kbb.settings'),
            // A new stamp re-keys every cached page keyword set at once.
            static fn () => Setting::query()->where('key', \App\Services\Seo\Keywords\KeywordConfig::LIVE)->exists()
                ? \App\Services\Seo\Keywords\KeywordConfig::stampLive() : null,
        ] as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // A cache that cannot be cleared expires on its own TTL.
            }
        }
    }
}
