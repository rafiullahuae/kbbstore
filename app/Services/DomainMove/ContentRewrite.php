<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use App\Models\Setting;
use App\Support\SiteHost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform -> Domain switch -> "Old links in the shop's text". (Lane DS)
 *
 * Every absolute link to the domain being left -- https://extrabeauty.ae/...,
 * http://www.extrabeauty.ae/..., //extrabeauty.ae/..., and the JSON-escaped
 * https:\/\/extrabeauty.ae\/... a block editor stores -- rewritten to the same
 * path on the new main address, in the shop's CONTENT and nowhere else.
 *
 * ── WHAT IS CONTENT ──────────────────────────────────────────────────────
 *
 * An ALLOW-list of tables (self::TABLES), never "everything except": product
 * copy, articles, pages, blocks, menus, banners, email and marketing
 * templates, redirect targets, translations, media alt text, and settings.
 * Orders, order items, payments, payment logs, payment providers (where the
 * gateway keys live), customers, addresses, sent mail and every other record
 * of the past are not on the list, so no code path here can reach them.
 *
 * Inside the list, the columns that NAME a row (key, slug, sku, token, a
 * redirect's source path, ...) are never written, and in `settings` /
 * `module_settings` a row is skipped outright when its key is a host the
 * forwarding needs (canonical_host, host_aliases, site_url, the owner app's
 * host) or looks like anything to do with payments or security
 * (self::SECRET_KEY): those are changed by the switch's own steps, or not at
 * all.
 *
 * ── WHAT IS NOT REWRITTEN ────────────────────────────────────────────────
 *
 * An email address (info@extrabeauty.ae) -- a mailbox has to exist before
 * anything may send to it -- and the bare name in a sentence. Both stay listed
 * by the readiness check as "to do by hand". A subdomain other than www
 * (staging.extrabeauty.ae) is a different site and is left alone. A value
 * that is PHP-serialized is skipped and counted: changing a string's length
 * inside it would corrupt it.
 *
 * ── REVERSIBLE ───────────────────────────────────────────────────────────
 *
 * Every changed cell is recorded in `domain_content_rewrites`, whole value
 * before and after, under one batch id. undo() writes "before" back -- only
 * where the cell still says exactly what this wrote, so an edit made since is
 * kept and counted.
 */
final class ContentRewrite
{
    public const LEDGER = 'domain_content_rewrites';

    /** @var list<string> */
    public const TABLES = [
        'products', 'product_variants', 'product_tabs', 'posts', 'pages', 'blocks', 'categories', 'brands',
        'menus', 'menu_items', 'banner_cards', 'banner_sets', 'grid_sections', 'email_templates', 'mkt_templates',
        'redirects', 'translations', 'ugc_sections', 'ugc_videos', 'routines', 'spotted_posts', 'media',
        'attributes', 'attribute_values', 'tags', 'settings', 'module_settings',
    ];

    /** Columns that name a row rather than hold its words. Never written. */
    private const NAMING = [
        'key', 'module', 'slug', 'sku', 'token', 'locale', 'status', 'handle', 'query_var', 'mime', 'filename',
        'swatch_color', 'group', 'field', 'uuid', 'password', 'remember_token', 'email', 'wc_id',
    ];

    /** Per-table naming columns on top of the list above. */
    private const NAMING_IN = ['redirects' => ['source']];

    /** A settings key that belongs to payments or security. Never read for rewriting. */
    public const SECRET_KEY = '/secret|passw|token|api[_-]?key|private|credential|webhook|stripe|tabby|tamara|payment|'
        .'smtp|vapid|indexnow|admin_path|licen[cs]e|signature|salt|oauth|client_id|instagram/i';

    /** @var list<string> bare hosts being left */
    private array $old;

    private string $new;

    /**
     * @param  list<string>  $old  the domains being left (bare, no www)
     * @param  string  $new  the main address links are pointed at
     */
    public function __construct(array $old, string $new)
    {
        $this->new = SiteHost::normalise($new);
        $this->old = array_values(array_filter(
            array_unique(array_map(fn ($h) => DomainReadiness::bare((string) $h), $old)),
            fn (string $h) => $h !== '' && $h !== DomainReadiness::bare($this->new),
        ));
    }

    public static function forSwitch(DomainSwitch $switch): self
    {
        return new self($switch->oldHosts(), $switch->target());
    }

    /** @return list<string> */
    public function oldHosts(): array
    {
        return $this->old;
    }

    public function newHost(): string
    {
        return $this->new;
    }

    /**
     * One value, rewritten. Pure: [the new value, how many links changed].
     *
     * @param  list<string>  $old
     * @return array{0: string, 1: int}
     */
    public static function rewrite(string $value, array $old, string $new): array
    {
        if ($old === [] || $new === '') {
            return [$value, 0];
        }

        $alts = implode('|', array_map(fn ($h) => preg_quote($h, '~'), $old));
        $pattern = '~(?<![A-Za-z0-9+.\-])((?:https?:)?)((?:\\\\?/){2})(?:www\.)?(?:'.$alts.')(?![A-Za-z0-9\-]|\.[A-Za-z0-9])~i';
        $count = 0;

        $out = preg_replace_callback($pattern, function (array $m) use ($new, &$count): string {
            $count++;

            return ($m[1] !== '' ? 'https:' : '').$m[2].$new;
        }, $value);

        return [is_string($out) ? $out : $value, is_string($out) ? $count : 0];
    }

    /* ═════════════════════════════════════════════════════════ preview ══ */

    /**
     * What apply() would change, without changing it.
     *
     * @return array<string, mixed>
     */
    public function preview(int $samples = 2): array
    {
        $places = [];
        $skipped = ['serialized' => 0, 'protected_settings' => 0];

        $this->walk(function (string $table, string $keyColumn, $key, string $column, string $before, string $after, int $links) use (&$places, $samples) {
            $id = $table.'.'.$column;
            $places[$id] ??= ['table' => $table, 'column' => $column, 'label' => self::label($table, $column), 'rows' => 0, 'links' => 0, 'samples' => []];
            $places[$id]['rows']++;
            $places[$id]['links'] += $links;

            if (count($places[$id]['samples']) < $samples) {
                $places[$id]['samples'][] = ['where' => ($table === 'settings' ? 'setting ' : $keyColumn.' ').$key] + self::snippet($before, $after);
            }
        }, $skipped);

        $places = array_values($places);

        return [
            'old' => $this->old,
            'new' => $this->new,
            'rows' => array_sum(array_column($places, 'rows')),
            'links' => array_sum(array_column($places, 'links')),
            'places' => $places,
            'skipped' => $skipped,
            'last' => $this->last(),
        ];
    }

    /* ═══════════════════════════════════════════════════════════ apply ══ */

    /**
     * Rewrite, record, in one transaction.
     *
     * @return array{batch: string|null, rows: int, links: int}
     */
    public function apply(): array
    {
        $batch = (string) Str::ulid();
        $rows = 0;
        $links = 0;
        $skipped = ['serialized' => 0, 'protected_settings' => 0];

        // Read everything first, write after: never update a table while a
        // cursor over it is still open.
        $cells = [];
        $this->walk(function (string $table, string $keyColumn, $key, string $column, string $before, string $after, int $n) use (&$cells) {
            $cells[] = [$table, $keyColumn, $key, $column, $before, $after, $n];
        }, $skipped);

        DB::transaction(function () use ($batch, $cells, &$rows, &$links) {
            $now = now();
            $ledger = [];

            foreach ($cells as [$table, $keyColumn, $key, $column, $before, $after, $n]) {
                DB::table($table)->where($keyColumn, $key)->update([$column => $after]);
                $ledger[] = [
                    'batch' => $batch, 'table_name' => $table, 'key_column' => $keyColumn, 'row_key' => (string) $key,
                    'column_name' => $column, 'before' => $before, 'after' => $after, 'links' => $n, 'created_at' => $now,
                ];
                $rows++;
                $links += $n;

                if (count($ledger) >= 200) {
                    DB::table(self::LEDGER)->insert($ledger);
                    $ledger = [];
                }
            }

            if ($ledger !== []) {
                DB::table(self::LEDGER)->insert($ledger);
            }
        });

        Setting::flushMap();
        SiteHost::forget();

        return ['batch' => $rows > 0 ? $batch : null, 'rows' => $rows, 'links' => $links];
    }

    /* ════════════════════════════════════════════════════════════ undo ══ */

    /**
     * Walk the last batch back.
     *
     * @return array{batch: string|null, restored: int, kept: int}
     */
    public function undo(): array
    {
        $last = $this->last();

        if ($last === null || $last['undone']) {
            return ['batch' => null, 'restored' => 0, 'kept' => 0];
        }

        $restored = 0;
        $kept = 0;

        DB::transaction(function () use ($last, &$restored, &$kept) {
            foreach (DB::table(self::LEDGER)->where('batch', $last['batch'])->orderByDesc('id')->get() as $row) {
                if (! in_array($row->table_name, self::TABLES, true)) {
                    continue;
                }

                $current = DB::table($row->table_name)->where($row->key_column, $row->row_key)->value($row->column_name);

                if ($current !== null && (string) $current === (string) $row->after) {
                    DB::table($row->table_name)->where($row->key_column, $row->row_key)->update([$row->column_name => $row->before]);
                    $restored++;
                } else {
                    $kept++;
                }
            }

            DB::table(self::LEDGER)->where('batch', $last['batch'])->update(['undone_at' => now()]);
        });

        Setting::flushMap();
        SiteHost::forget();

        return ['batch' => $last['batch'], 'restored' => $restored, 'kept' => $kept];
    }

    /** @return array{batch: string, at: string, rows: int, links: int, undone: bool}|null */
    public function last(): ?array
    {
        if (! Schema::hasTable(self::LEDGER)) {
            return null;
        }

        $batch = DB::table(self::LEDGER)->orderByDesc('id')->value('batch');

        if ($batch === null) {
            return null;
        }

        $row = DB::table(self::LEDGER)->where('batch', $batch)
            ->selectRaw('COUNT(*) AS n, SUM(links) AS l, MIN(created_at) AS at, MAX(undone_at) AS u')->first();

        return [
            'batch' => (string) $batch,
            'at' => (string) $row->at,
            'rows' => (int) $row->n,
            'links' => (int) $row->l,
            'undone' => $row->u !== null,
        ];
    }

    /* ═════════════════════════════════════════════════════════ walking ══ */

    /**
     * Every cell that would change, handed to $each. Reads only rows that
     * mention a host being left.
     *
     * @param  callable(string, string, mixed, string, string, string, int): void  $each
     * @param  array{serialized: int, protected_settings: int}  $skipped
     */
    private function walk(callable $each, array &$skipped): void
    {
        if ($this->old === [] || $this->new === '') {
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            [$keyColumn, $columns] = $this->columns($table);

            if ($columns === []) {
                continue;
            }

            $select = array_values(array_unique([$keyColumn, ...$columns, ...(in_array($table, ['settings', 'module_settings'], true) ? ['key'] : [])]));
            $query = DB::table($table)->select($select)->where(function ($q) use ($columns) {
                foreach ($columns as $column) {
                    foreach ($this->old as $host) {
                        $q->orWhere($column, 'like', '%'.$host.'%');
                    }
                }
            })->orderBy($keyColumn);

            foreach ($query->cursor() as $record) {
                $record = (array) $record;

                if (in_array($table, ['settings', 'module_settings'], true) && self::protectedSetting((string) ($record['key'] ?? ''))) {
                    $skipped['protected_settings']++;

                    continue;
                }

                foreach ($columns as $column) {
                    $before = $record[$column] ?? null;

                    if (! is_string($before) || $before === '') {
                        continue;
                    }

                    [$after, $links] = self::rewrite($before, $this->old, $this->new);

                    if ($links === 0 || $after === $before) {
                        continue;
                    }

                    if (preg_match('/^(?:a|O|C):\d+:/', $before) === 1) {
                        $skipped['serialized']++;

                        continue;
                    }

                    $each($table, $keyColumn, $record[$keyColumn], $column, $before, $after, $links);
                }
            }
        }
    }

    /** A settings key the rewrite never touches. */
    public static function protectedSetting(string $key): bool
    {
        return in_array($key, DomainReadiness::CONFIG_SETTINGS, true) || preg_match(self::SECRET_KEY, $key) === 1;
    }

    /** @return array{0: string, 1: list<string>} the key column and the writable text columns */
    private function columns(string $table): array
    {
        $all = Schema::getColumns($table);
        $names = array_map(fn ($c) => (string) $c['name'], $all);
        $key = in_array('id', $names, true) ? 'id' : (in_array('key', $names, true) ? 'key' : ($names[0] ?? 'id'));
        $skip = [...self::NAMING, ...(self::NAMING_IN[$table] ?? []), $key];
        $text = [];

        foreach ($all as $column) {
            $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
            $name = (string) $column['name'];

            if (preg_match('/char|text|json|clob|string/', $type) === 1 && ! str_contains($type, 'binary') && ! in_array($name, $skip, true)) {
                $text[] = $name;
            }
        }

        return [$key, $text];
    }

    /** @return array{before: string, after: string} the first change, with a little context */
    private static function snippet(string $before, string $after): array
    {
        $at = 0;
        $max = min(strlen($before), strlen($after));

        while ($at < $max && $before[$at] === $after[$at]) {
            $at++;
        }

        $from = max(0, $at - 30);
        $cut = fn (string $s) => ($from > 0 ? '…' : '').mb_strcut($s, $from, 90).(strlen($s) > $from + 90 ? '…' : '');

        return ['before' => $cut($before), 'after' => $cut($after)];
    }

    private static function label(string $table, string $column): string
    {
        $what = match ($table) {
            'products', 'product_variants' => 'Products',
            'product_tabs' => 'Product tabs',
            'posts' => 'Blog articles',
            'pages' => 'Pages',
            'blocks' => 'Content blocks',
            'categories' => 'Categories',
            'brands' => 'Brands',
            'menus', 'menu_items' => 'Menus',
            'banner_cards', 'banner_sets' => 'Banners',
            'grid_sections' => 'Homepage grids',
            'email_templates' => 'Order email templates',
            'mkt_templates' => 'Marketing email templates',
            'redirects' => 'Redirects (where they send)',
            'translations' => 'Arabic and other translations',
            'ugc_sections', 'ugc_videos' => 'Shoppable videos',
            'routines' => 'Routines',
            'spotted_posts' => 'Spotted posts',
            'media' => 'Media library',
            'settings', 'module_settings' => 'Shop settings (header, footer, banners, …)',
            default => ucfirst(str_replace('_', ' ', $table)),
        };

        return $what.' · '.str_replace('_', ' ', $column);
    }
}
