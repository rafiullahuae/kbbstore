<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Locale;
use App\Support\StoreTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalog -> Labels: the owner's own badges ("Best Seller", "New Arrival",
 * "Ramadan Pick" ...) on the products he chooses.          (Lane LBL, 15 Nov)
 *
 * Not to be confused with App\Services\ProductLabels, the AUTOMATIC module
 * (sold out / sale / new / bestseller, Growth & Marketing -> Product Labels),
 * which is unchanged and keeps its screen.
 *
 * ── THE SHOP READS ONE CACHED MAP, AT MOST ONCE PER REQUEST ───────────────
 *
 * The owner, approving the previews: "make sure no compromise on the speed".
 * So a shop page never runs a query for labels:
 *
 *   - `custom_labels_on` (an autoloaded SETTING) says whether any label is
 *     on at all, and which build of the map is current. The settings map is
 *     already read once per request (SettingsRequestMemo), so a shop with no
 *     label pays nothing -- not even a cache read -- and its HTML is byte for
 *     byte what it was (StorefrontEnglishUnchangedTest).
 *   - With labels, ONE cache entry `kbb.labels.v1` holds product id => label
 *     ids (priority order) and the label styles. It is read once per request
 *     and kept on the request (request attributes), never per card.
 *   - The map is WRITTEN, not deleted, when a label is saved, so no shopper
 *     builds it. A product or category save rebuilds it after the response
 *     (terminating), and an entry older than STALE is rebuilt after the
 *     response of the request that noticed -- that is what picks up raw
 *     `category_product` writers (New Arrivals, Shop by Concern) that fire no
 *     model event. Only an emptied cache (an update's cache:clear) makes one
 *     request build it inline: 3-4 queries, once.
 *
 * Date windows and the two-per-card cap are worked out in PHP from data
 * already loaded. Dates are store-time days (StoreTime), inclusive.
 */
final class CustomLabels
{
    public const CACHE_KEY = 'kbb.labels.v1';

    /** Autoloaded setting: 0/absent = no label is on; otherwise the map's build number. */
    public const FLAG = 'custom_labels_on';

    /** An entry older than this is rebuilt after the response that sees it. */
    public const STALE = 900;

    public const MAX_PER_CARD = 2;

    public const SHAPES = ['A', 'B', 'C', 'D'];

    public const PLACES = ['cards', 'page', 'both'];

    public const MODES = ['products', 'categories', 'both'];

    public const TEXT_MAX = 22;

    private const MEMO = 'kbb.custom_labels';

    private const SEEN = 'kbb.custom_labels.seen';

    // ── reading (the shop) ───────────────────────────────────────────────

    /** The build number from the settings map the request already holds; 0 = no labels. */
    public static function flag(): int
    {
        try {
            $all = app(SettingsService::class)->all();
        } catch (\Throwable) {
            return 0;
        }

        return max(0, (int) ($all[self::FLAG] ?? 0));
    }

    /**
     * This request's view of the map: the entry plus which labels are inside
     * their date window today. Null when no label is on.
     *
     * @return array{d: array, live: array<int, true>}|null
     */
    public static function current(): ?array
    {
        $memoOn = SettingsService::requestMemoActive();
        $req = $memoOn ? request() : null;

        if ($req !== null && $req->attributes->has(self::MEMO)) {
            return $req->attributes->get(self::MEMO);
        }

        $ver = self::flag();
        $out = null;

        if ($ver > 0) {
            $d = null;

            try {
                $d = Cache::get(self::CACHE_KEY);
            } catch (\Throwable) {
            }

            if (! is_array($d) || ($d['v'] ?? null) !== $ver || ! isset($d['l'], $d['m'])) {
                $d = self::build($ver);
                self::put($d);
            } elseif (time() - (int) ($d['at'] ?? 0) > self::STALE) {
                self::rebuildAfterResponse();
            }

            $today = self::today();
            $live = [];

            foreach ($d['l'] as $id => $l) {
                if (($l['s'] === null || $l['s'] <= $today) && ($l['e'] === null || $l['e'] >= $today)) {
                    $live[(int) $id] = true;
                }
            }

            $out = $live === [] ? null : ['d' => $d, 'live' => $live];
        }

        if ($req !== null) {
            $req->attributes->set(self::MEMO, $out);
        }

        return $out;
    }

    /**
     * The labels one product shows in one place ('cards' or 'page'): on,
     * inside their window, by priority, at most two. Each carries its text in
     * this request's language (Arabic falls back to English when empty).
     *
     * @return list<array{id: int, text: string, sh: string, bg: string, fg: string, sb: string, sf: string, sd: string}>
     */
    public static function forProduct(int $productId, string $where = 'cards'): array
    {
        $cur = self::current();

        if ($cur === null) {
            return [];
        }

        self::seen();
        $ids = $cur['d']['m'][$productId] ?? null;

        if ($ids === null) {
            return [];
        }

        $ar = ! Locale::isDefault();
        $out = [];

        foreach ($ids as $id) {
            $l = $cur['d']['l'][$id] ?? null;

            if ($l === null || ! isset($cur['live'][$id]) || ($l['pl'] !== 'both' && $l['pl'] !== $where)) {
                continue;
            }

            $out[] = ['id' => (int) $id, 'text' => $ar && $l['ta'] !== '' ? $l['ta'] : $l['t']] + $l;

            if (count($out) >= self::MAX_PER_CARD) {
                break;
            }
        }

        return $out;
    }

    /** The ids a card shows now, for CardFragments' signature ('' = none). */
    public static function cardKey(int $productId): string
    {
        return implode('.', array_column(self::forProduct($productId, 'cards'), 'id'));
    }

    /** Marks that this request drew a card or a product page while labels are on. */
    private static function seen(): void
    {
        if (SettingsService::requestMemoActive()) {
            request()->attributes->set(self::SEEN, true);
        }
    }

    /**
     * The badge stylesheet, for the head of a page that drew labels; '' otherwise.
     * Printed by App\Providers\CustomLabelsServiceProvider's layout composer.
     */
    public static function headStyle(): string
    {
        if (! SettingsService::requestMemoActive() || ! request()->attributes->get(self::SEEN)) {
            return '';
        }

        return '<style id="kbb-plb">'.self::CSS.'</style>';
    }

    /**
     * The shapes. Colours are inline (validated #hex), so this never changes.
     * No var(), no newer CSS function, no measuring: a stack of flex boxes
     * that keeps clear of the opposite corner with a calc() max-width.
     */
    public const CSS = '.plb-s{position:absolute;top:9px;inset-inline-start:9px;display:flex;flex-direction:column;align-items:flex-start;gap:5px;z-index:3;max-width:calc(100% - 56px);pointer-events:none}'
        .'.plb-s.plb-e{max-width:calc(100% - 78px)}.plb-s.plb-r{top:38px}.plb-s.plb-as{top:46px}.plb-s.plb-r.plb-as{top:72px}'
        .'.plb{display:inline-flex;align-items:center;gap:5px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.35;box-sizing:border-box}'
        .'.plb-A,.plb-B,.plb-C{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}'
        .'.plb-A{padding:4px 9px;border-radius:20px;box-shadow:0 2px 8px rgba(0,0,0,.18)}'
        .'.plb-B{padding:5px 18px 5px 10px;margin-inline-start:-9px;clip-path:polygon(0 0,100% 0,calc(100% - 7px) 50%,100% 100%,0 100%)}'
        .'[dir=rtl] .plb-B{padding:5px 10px 5px 18px;clip-path:polygon(0 0,100% 0,100% 100%,0 100%,7px 50%)}'
        .'.plb-C{font-size:9px;padding:3px 8px;border-radius:5px}'
        .'.plb-D{font-size:10.5px;font-weight:600;padding:3px 9px 3px 7px;border-radius:7px;border:1px solid}'
        .'.plb-D::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}'
        .'.plb-sash{position:absolute;top:15px;left:-34px;width:130px;transform:rotate(-45deg);text-align:center;font-size:9px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:4px 18px;z-index:3;box-shadow:0 2px 6px rgba(0,0,0,.18);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;box-sizing:border-box}'
        .'[dir=rtl] .plb-sash{left:auto;right:-34px;transform:rotate(45deg)}'
        .'.gmain .plb-s{top:14px;inset-inline-start:14px;gap:6px;max-width:calc(100% - 76px)}.gmain .plb-s.plb-as{top:58px}'
        .'.gmain .plb-A,.gmain .plb-B,.gmain .plb-C{font-size:11px;padding:5px 11px}.gmain .plb-B{padding:6px 22px 6px 14px;margin-inline-start:-14px}'
        .'.gmain .plb-D{font-size:12px;padding:4px 10px 4px 8px}.gmain .plb-sash{font-size:10.5px;top:22px;left:-38px;width:160px;padding:5px 22px}'
        .'[dir=rtl] .gmain .plb-sash{left:auto;right:-38px}.gmain .plb-s .lbl{position:static}'
        .'.kbb-card-thumb>.lbl.plb-x{left:auto;right:auto;inset-inline-start:auto;inset-inline-end:9px}'
        .'.plb-t{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 10px}.plb-t .plb{margin:0;clip-path:none;border-radius:20px;padding:4px 10px}.plb-t .plb-D{border-radius:7px}';

    /**
     * The photo-corner stack: a C first label is the corner sash and the rest
     * stack under it.
     *
     * @param  list<array>  $labels  from forProduct()
     * @param  string  $extra  'plb-e' (a pill in the other corner), 'plb-r' (a rank above)
     * @param  string  $tail  already-escaped markup to append inside the stack (the gallery's automatic badge)
     */
    public static function stackHtml(array $labels, string $extra = '', string $tail = ''): string
    {
        if ($labels === [] && $tail === '') {
            return '';
        }

        $h = '';
        $cls = 'plb-s'.($extra !== '' ? ' '.$extra : '');

        if ($labels !== [] && $labels[0]['sh'] === 'C') {
            $h .= '<span class="plb-sash" style="background:'.$labels[0]['bg'].';color:'.$labels[0]['fg'].'">'.e($labels[0]['text']).'</span>';
            $labels = array_slice($labels, 1);
            $cls .= ' plb-as';
        }

        $inner = '';

        foreach ($labels as $l) {
            $inner .= self::badge($l);
        }

        return $inner === '' && $tail === '' ? $h : $h.'<span class="'.$cls.'">'.$inner.$tail.'</span>';
    }

    /** The chips beside the product page's title. */
    public static function chipsHtml(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $h = '';

        foreach ($labels as $l) {
            $h .= self::badge($l);
        }

        return '<div class="plb-t">'.$h.'</div>';
    }

    private static function badge(array $l): string
    {
        $style = $l['sh'] === 'D'
            ? 'background:'.$l['sb'].';color:'.$l['sf'].';border-color:'.$l['sd']
            : 'background:'.$l['bg'].';color:'.$l['fg'];

        return '<span class="plb plb-'.$l['sh'].'" style="'.$style.'">'.e($l['text']).'</span>';
    }

    // ── building ─────────────────────────────────────────────────────────

    /** Today in store time, Y-m-d. */
    private static function today(): string
    {
        try {
            return StoreTime::now()->format('Y-m-d');
        } catch (\Throwable) {
            return now()->format('Y-m-d');
        }
    }

    /**
     * Every label's rules resolved to product ids.
     *
     * @param  bool  $activeOnly  the shop's map (true) or the admin's counts (false)
     * @return array{labels: array<int, object>, sets: array<int, array<int, true>>, cats: bool}
     */
    public static function resolve(bool $activeOnly = true): array
    {
        $q = DB::table('product_labels')->orderBy('priority')->orderByDesc('id');

        if ($activeOnly) {
            $q->where('active', true);
        }

        $labels = [];

        foreach ($q->get() as $row) {
            $labels[(int) $row->id] = $row;
        }

        if ($labels === []) {
            return ['labels' => [], 'sets' => [], 'cats' => false];
        }

        $rules = [];
        $catIds = [];
        $withChildren = false;

        foreach (DB::table('product_label_targets')->whereIn('label_id', array_keys($labels))->get(['label_id', 'kind', 'target_id', 'with_children']) as $r) {
            $lid = (int) $r->label_id;
            $rules[$lid][$r->kind][(int) $r->target_id] = (bool) $r->with_children;

            if ($r->kind === 'category' && ($labels[$lid]->mode ?? 'both') !== 'products') {
                $catIds[(int) $r->target_id] = true;
                $withChildren = $withChildren || (bool) $r->with_children;
            }
        }

        // Descendants from parent_id, walked in PHP from one read of the tree.
        $children = [];

        if ($withChildren) {
            foreach (DB::table('categories')->whereNotNull('parent_id')->get(['id', 'parent_id']) as $c) {
                $children[(int) $c->parent_id][] = (int) $c->id;
            }
        }

        $expand = static function (int $root, bool $deep) use ($children): array {
            $out = [$root => true];

            if (! $deep) {
                return $out;
            }

            $todo = [$root];

            while ($todo !== []) {
                foreach ($children[array_pop($todo)] ?? [] as $kid) {
                    if (! isset($out[$kid])) {
                        $out[$kid] = true;
                        $todo[] = $kid;
                    }
                }
            }

            return $out;
        };

        $need = [];
        $catSets = [];

        foreach ($rules as $lid => $r) {
            if (($labels[$lid]->mode ?? 'both') === 'products') {
                continue;
            }

            foreach ($r['category'] ?? [] as $cid => $deep) {
                $catSets[$lid][$cid] = $expand($cid, $deep);
                $need += $catSets[$lid][$cid];
            }
        }

        $inCat = [];

        foreach (array_chunk(array_keys($need), 800) as $chunk) {
            foreach (DB::table('category_product')->whereIn('category_id', $chunk)->get(['product_id', 'category_id']) as $cp) {
                $inCat[(int) $cp->category_id][(int) $cp->product_id] = true;
            }
        }

        $sets = [];

        foreach ($labels as $lid => $row) {
            $set = [];
            $mode = $row->mode ?? 'both';

            if ($mode !== 'categories') {
                $set += $rules[$lid]['product'] ?? [];
            }

            foreach ($catSets[$lid] ?? [] as $cats) {
                foreach ($cats as $cid => $_) {
                    $set += $inCat[$cid] ?? [];
                }
            }

            foreach ($rules[$lid]['exclude'] ?? [] as $pid => $_) {
                unset($set[$pid]);
            }

            $sets[$lid] = array_fill_keys(array_keys($set), true);
        }

        return ['labels' => $labels, 'sets' => $sets, 'cats' => $catIds !== []];
    }

    /** The shop's map, from the active labels. */
    public static function build(int $ver): array
    {
        $r = self::resolve(true);
        $l = [];
        $m = [];

        foreach ($r['labels'] as $id => $row) {
            $bg = self::hex((string) $row->bg, '#C13E63');
            $fg = self::hex((string) $row->fg, '#FFFFFF');
            $l[$id] = [
                't' => (string) $row->text,
                'ta' => (string) ($row->text_ar ?? ''),
                'bg' => $bg,
                'fg' => $fg,
                'sh' => in_array($row->shape, self::SHAPES, true) ? $row->shape : 'A',
                'pl' => in_array($row->placement, self::PLACES, true) ? $row->placement : 'both',
                's' => $row->starts_on ? substr((string) $row->starts_on, 0, 10) : null,
                'e' => $row->ends_on ? substr((string) $row->ends_on, 0, 10) : null,
                // Soft tag (D): precomputed tints, so the stylesheet needs no color-mix().
                'sb' => self::mix($bg, .88),
                'sf' => self::darken($bg, .18),
                'sd' => self::mix($bg, .6),
            ];

            foreach ($r['sets'][$id] ?? [] as $pid => $_) {
                $m[$pid][] = $id;
            }
        }

        return ['v' => $ver, 'at' => time(), 'l' => $l, 'm' => $m, 'c' => $r['cats']];
    }

    /**
     * After a label is saved, toggled, reordered or deleted: a new build
     * number in the settings (which also renews every cached card, whose
     * signature hashes the settings) and the new map WRITTEN to the cache.
     */
    public static function saved(): void
    {
        $settings = app(SettingsService::class);
        $any = DB::table('product_labels')->where('active', true)->exists();
        $ver = $any ? max(time(), self::flag() + 1) : 0;

        $settings->set(self::FLAG, $ver);

        if ($any) {
            self::put(self::build($ver));
        } else {
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (\Throwable) {
            }
        }

        self::forgetMemo();
    }

    /** Rewrites the map under the current build number (product/category change). */
    public static function rebuildMap(): void
    {
        $ver = self::flag();

        if ($ver > 0) {
            self::put(self::build($ver));
            self::forgetMemo();
        }
    }

    /**
     * A product or category changed: rebuild the map once, after the response.
     * Free when no label is on.
     */
    public static function markDirty(): void
    {
        /*
         * No settings read HERE: a model event that loaded the settings map
         * warmed it for whatever ran next, and moved the query counts other
         * tests pin (SearchSpellingTest). rebuildMap() asks the flag after the
         * response, from the map the request already read, and returns at
         * once when no label is on.
         */
        try {
            self::rebuildAfterResponse();
        } catch (\Throwable) {
            // A label map must never stop a product from saving.
        }
    }

    private static function rebuildAfterResponse(): void
    {
        $app = app();

        if ($app->bound(self::MEMO.'.dirty')) {
            return;
        }

        /*
         * ONE-SHOT. Application::terminate() never clears its callbacks, so
         * in a long-lived process (a queue worker, a test) every callback
         * registered so far runs again at every later terminate. Without the
         * `done` latch each of them would rebuild the map on every request
         * after the first product save -- invisible under PHP-FPM, where each
         * request is a new process, and a query budget broken everywhere else.
         */
        $job = new \stdClass;
        $job->done = false;
        $app->instance(self::MEMO.'.dirty', true);
        $app->terminating(static function () use ($app, $job): void {
            if ($job->done) {
                return;
            }

            $job->done = true;
            $app->forgetInstance(self::MEMO.'.dirty');

            try {
                self::rebuildMap();
            } catch (\Throwable) {
            }
        });
    }

    private static function put(array $d): void
    {
        try {
            Cache::forever(self::CACHE_KEY, $d);
        } catch (\Throwable) {
            // A cache that will not write costs a rebuild next time, never a page.
        }
    }

    private static function forgetMemo(): void
    {
        try {
            request()->attributes->remove(self::MEMO);
        } catch (\Throwable) {
        }
    }

    // ── values ───────────────────────────────────────────────────────────

    /** A #RRGGBB colour, upper case, or the fallback. #RGB is expanded. */
    public static function hex(string $v, string $fallback): string
    {
        $v = trim($v);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $v, $m)) {
            return '#'.strtoupper($m[1]);
        }

        if (preg_match('/^#?([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $v, $m)) {
            return '#'.strtoupper($m[1].$m[1].$m[2].$m[2].$m[3].$m[3]);
        }

        return $fallback;
    }

    /** Blend towards white by $t (0..1). */
    public static function mix(string $hex, float $t): string
    {
        $n = hexdec(substr($hex, 1));
        $f = static fn (int $c): int => (int) round($c + (255 - $c) * $t);

        return sprintf('#%02X%02X%02X', $f(($n >> 16) & 255), $f(($n >> 8) & 255), $f($n & 255));
    }

    /** Darken by $t (0..1). */
    public static function darken(string $hex, float $t): string
    {
        $n = hexdec(substr($hex, 1));
        $f = static fn (int $c): int => (int) round($c * (1 - $t));

        return sprintf('#%02X%02X%02X', $f(($n >> 16) & 255), $f(($n >> 8) & 255), $f($n & 255));
    }
}
