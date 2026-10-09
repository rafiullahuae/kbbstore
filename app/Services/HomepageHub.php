<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\GridSection;
use App\Models\Post;
use App\Models\Product;
use App\Support\HomeSections as HS;
use App\Support\HomeSources;
use App\Support\ProductSource;

/**
 * Appearance → Homepage content → All sections (Lane HC).
 *
 * The owner: "please bring every section edit on hompage content, so we will
 * get everything edit for homepage on one page. each section will have Edit
 * content button to open for detailed popup".
 *
 * THIS CLASS OWNS NO SETTING. It reads the homepage in the order the shop
 * draws it (HomepageSections::all(), which is the order AND the on/off state
 * Appearance → Homepage writes) and says, for each section, where its
 * controls already live:
 *
 *   content  a tab of HomepageContent::SCHEMA — fields sent here, saved by
 *            POST /admin-api/homepage/content, the endpoint the tabs use;
 *   remote   another screen's schema endpoint (Banners, Spotted, Video rail,
 *            Instagram) — the editor GETs its tabs and POSTs its `settings`.
 *            `tabs` names the ones to show, or `skip` the ones not to, so a
 *            tab another lane adds to that screen's TABS (Spotted's, for one)
 *            appears here with no change to this class;
 *   grid     a Grid section — GET/PUT /admin-api/grid-sections/{id};
 *   hero     the slide repeater on this same screen's Hero slider tab;
 *   none     nothing of its own to edit (it is derived), with the sentence
 *            that says where it comes from.
 *
 * Every section also carries HomepageSections' own row controls (Laptop,
 * Phone, card design, background, width), saved by POST /admin-api/homepage
 * exactly as Appearance → Homepage saves them. So no value on this page has a
 * second writer: two screens, one endpoint per value.
 *
 * COST IS FLAT IN THE CATALOGUE. Brands and categories are listed whole (they
 * are the vocabulary of the source picker and do not grow with products);
 * products are read ONLY for the ids a manual list already holds, one
 * whereIn. The 5,000-product option list the Homepage content tabs carry is
 * not sent: the picker searches instead (products()).
 */
final class HomepageHub
{
    /** section key => editor descriptor. Absent and not a grid: `none`. */
    public const EDITORS = [
        'cards_banner' => ['kind' => 'remote', 'get' => 'banners', 'save' => 'banners', 'tabs' => ['homepage'], 'also' => ['banners', 'Appearance → Banners'], 'note' => 'The pictures themselves are built as banner sets on Appearance → Banners.'],
        'topstrip' => ['kind' => 'content', 'tab' => 'topstrip'],
        'countries' => ['kind' => 'remote', 'get' => 'header', 'save' => 'header', 'tabs' => ['flagbar'], 'also' => ['header', 'Appearance → Header → Flag bar'], 'note' => 'The same strip as Appearance → Header → Flag bar: one set of words, colours and sizes. On the homepage, its Laptop and Phone switches above decide; on other pages, the Flag bar’s own two switches do.'],
        'hero' => ['kind' => 'hero', 'note' => 'The slides — words, colours, links — are edited on the Hero slider tab of this page.'],
        'delivery' => ['kind' => 'none', 'note' => 'Its words come from the delivery zones and the free-delivery threshold.', 'also' => ['shipping', 'Store → Delivery & Shipping']],
        'ticker' => ['kind' => 'content', 'tab' => 'copy'],
        'categories' => ['kind' => 'none', 'note' => 'The ten categories with the most products, chosen automatically. Names and pictures are edited on the categories themselves.'],
        'bundles' => ['kind' => 'content', 'tab' => 'bundles', 'source' => 'hb'],
        'bestselling' => ['kind' => 'content', 'tab' => 'bestselling', 'source' => 'bs'],
        'recommended' => ['kind' => 'content', 'tab' => 'recommended', 'source' => 'rc'],
        'routine' => ['kind' => 'none', 'note' => 'Each step shows the best seller of its category (oil cleanser, toner, serum…), chosen automatically.', 'also' => ['routines', 'Catalog → Build my routine']],
        'quiz' => ['kind' => 'none', 'note' => 'The two-minute skin quiz. Its questions are fixed; turn it on or off for each device here.'],
        'brands' => ['kind' => 'content', 'tab' => 'brands'],
        'spotted' => ['kind' => 'remote', 'get' => 'spotted', 'save' => 'spotted/settings', 'skip' => ['page'], 'also' => ['spotted', 'Appearance → #KBeautyBliss Spotted'], 'note' => 'Which posts appear is ticked per post on Appearance → #KBeautyBliss Spotted.'],
        'videos' => ['kind' => 'remote', 'get' => 'ugc-appearance', 'save' => 'ugc-appearance', 'tabs' => ['home'], 'also' => ['ugcstyle', 'Appearance → Video rail'], 'note' => 'The clips are uploaded and grouped under Content → Shoppable video.'],
        // (Lane IGE) Its posts, card style and layout are one screen of their own.
        'igembeds' => ['kind' => 'none', 'note' => 'The posts, the card style and the layout are set on Content → Instagram embeds.', 'also' => ['igembeds', 'Content → Instagram embeds']],
        'trending' => ['kind' => 'content', 'tab' => 'trending', 'source' => 'tr'],
        'bestsellers' => ['kind' => 'content', 'tab' => 'bestsellers', 'source' => 'bsl'],
        'flash' => ['kind' => 'content', 'tab' => 'flash', 'source' => 'fl'],
        'blog' => ['kind' => 'content', 'tab' => 'blog'],
        'under54' => ['kind' => 'content', 'tab' => 'under54', 'source' => 'u54'],
        'feature' => ['kind' => 'content', 'tab' => 'feature'],
        'about' => ['kind' => 'content', 'tab' => 'about'],
        'reviews' => ['kind' => 'none', 'note' => 'The score and the four newest approved reviews, read from the reviews themselves.', 'also' => ['rev-settings', 'Reviews → Review Settings']],
        'trust' => ['kind' => 'none', 'note' => 'The three claims are the shop’s own, shared with checkout and the product page.', 'also' => ['store-settings', 'Store → Business Details']],
        'newsletter' => ['kind' => 'none', 'note' => 'The sign-up box. Its offer and wording are the newsletter module’s.', 'also' => ['newsletter', 'Marketing → Newsletter']],
    ];

    /**
     * The four groups a section's fields are dealt into, in tab order. A fifth,
     * `type`, is the Fonts & size tab; the editor draws it LAST, after Show &
     * frame, together with SectionType's controls (Lane FS).
     */
    public const GROUPS = ['content' => 'Content', 'products' => 'Products', 'layout' => 'Layout', 'style' => 'Style'];

    public function __construct(
        private HomepageSections $sections,
        private SettingsService $settings,
    ) {}

    /**
     * Which tab of the editor a field belongs on, from its key alone — so a
     * field a later lane adds to a section's schema lands somewhere without
     * this class changing. Order matters: the first match wins.
     */
    public static function group(string $key): string
    {
        return match (true) {
            // Lane BS: `_imgs` — a picture per picked row — sits with its picks.
            (bool) preg_match('/_(src|source|brand|cat|brands|cats|picks|imgs|sort|stock|limit|max|count_[dm])$/', $key) => 'products',
            // Lane FS: spacing and text size are the Fonts & size tab's, so a
            // section's OWN space-above / space-below / under-the-heading keys
            // move there instead of being offered twice (SectionType::OWNED).
            (bool) preg_match('/_(size|pt_[dm]|pb_[dm]|hg_[dm]|pad_[dm]|head_gap_[dm]|btn_gap_[dm])$/', $key) => 'type',
            (bool) preg_match('/_(bg|ink|h)$/', $key) => 'style',
            (bool) preg_match('/_(cols_[dm]|layout_[dm]|per_[dm]|arrows_[dm]|auto|align|btn_[dm]|th_m|clamp_[dm]|count)$/', $key) => 'layout',
            default => 'content',
        };
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $rows = array_values($this->sections->all());

        $flat = ModuleSchema::read($this->settings, 'homepage_content', HomepageContent::SCHEMA);
        $flat['about_text'] = app(HomepageContent::class)->aboutText();

        $tabs = [];

        foreach (ModuleSchema::tabs(HomepageContent::SCHEMA, HomepageContent::TABS, $flat) as $tab) {
            $tabs[$tab['key']] = $tab;
        }

        $grids = $this->grids($rows);
        $type = \App\Support\SectionType::read($this->settings->all());
        $vocab = $this->vocab($flat);
        $productIds = [];

        foreach (HomepageContent::SCHEMA as $key => $f) {
            if ($f['type'] === 'ids' && ($f['options']['of'] ?? '') === 'products') {
                array_push($productIds, ...ProductSource::ids($flat[$key] ?? '', ProductSource::CAP_PICKS));
            }
        }

        foreach ($grids as $g) {
            array_push($productIds, ...array_map('intval', (array) ($g->manual_ids ?? [])));
        }

        $out = [];

        foreach ($rows as $row) {
            $key = $row['key'];
            $gridId = GridSection::idFromKey($key);
            $editor = $gridId !== null
                ? ['kind' => 'grid', 'id' => $gridId, 'also' => ['gridsections', 'Appearance → Grid sections']]
                : (self::EDITORS[$key] ?? ['kind' => 'none']);

            $item = $row + [
                'editor' => $editor,
                'section_tabs' => HomepageSections::sectionTabs($key, $row),
                // Lane FS: the Fonts & size tab's own controls ([] for the hero,
                // whose slides are edited on the Hero slider tab).
                'type_fields' => \App\Support\SectionType::fields($key, $type[$key] ?? [], $flat),
            ];

            if ($editor['kind'] === 'content' && isset($tabs[$editor['tab']])) {
                $item['fields'] = array_map(
                    fn (array $f) => $f + ['group' => self::group((string) $f['key'])],
                    $tabs[$editor['tab']]['fields']
                );
                $item['tab_label'] = $tabs[$editor['tab']]['label'];
            }

            if (isset($editor['source'])) {
                $item['source'] = self::sourceKeys($editor['source']);
            }

            $item['summary'] = $this->summary($key, $editor, $flat, $vocab, $gridId !== null ? ($grids[$gridId] ?? null) : null);

            $out[] = $item;
        }

        return [
            'ok' => true,
            'sections' => $out,
            'skins' => collect(\App\Support\GridSkins::ALL)->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values()->all(),
            'sorts' => ProductSource::SORTS,
            'groups' => self::GROUPS,
            'vocab' => $vocab,
            'products' => self::cards(array_values(array_unique($productIds))),
        ];
    }

    /**
     * The setting keys one product section's source picker reads and writes.
     * Row 55 rails (`bs`, `tr`, `u54`) keep their old single brand/category
     * and two counts; the four older rows have `src` and one `limit`.
     *
     * @return array<string, mixed>
     */
    public static function sourceKeys(string $p): array
    {
        $legacy = in_array($p, ['hb', 'rc', 'bsl', 'fl'], true);
        $k = fn (string $s) => "home_{$p}_{$s}";

        return $legacy
            ? ['style' => 'legacy', 'source' => $k('src'), 'brands' => $k('brands'), 'cats' => $k('cats'), 'sort' => $k('sort'), 'stock' => $k('stock'), 'picks' => $k('picks'), 'limit' => $k('limit')]
            : ['style' => 'rail', 'source' => $k('source'), 'brand' => $k('brand'), 'cat' => $k('cat'), 'brands' => $k('brands'), 'cats' => $k('cats'), 'sort' => $k('sort'), 'stock' => $k('stock'), 'picks' => $k('picks'), 'limit_d' => $k('count_d'), 'limit_m' => $k('count_m')];
    }

    /**
     * id/name/brand/image/price for the given product ids, in that order —
     * the ONLY product shape this screen ever receives. An allowlist, not the
     * model: `products` carries wc_id, sku and total_sales.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public static function cards(array $ids, bool $visibleOnly = false): array
    {
        if ($ids === []) {
            return [];
        }

        $q = Product::query()->select(['id', 'name', 'brand_id', 'image', 'price', 'sale_price'])
            ->with('brand:id,name')->whereIn('id', $ids);

        if ($visibleOnly) {
            $q->visible();
        }

        $rows = $q->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $rows->get($id))->filter()
            ->map(fn (Product $p) => self::card($p))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function card(Product $p): array
    {
        $price = (int) $p->price;
        $sale = (int) $p->sale_price;

        return [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'brand' => (string) ($p->brand?->name ?? ''),
            'image' => \App\Support\SafeUrl::src((string) ($p->image ?? '')),
            'price' => $sale > 0 && $sale < $price ? $sale : $price,
        ];
    }

    /** @return array<int, GridSection> */
    private function grids(array $rows): array
    {
        $ids = array_values(array_filter(array_map(fn ($r) => GridSection::idFromKey($r['key']), $rows)));

        return $ids === [] ? [] : GridSection::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /** @return array<string, list<array{id: int, name: string}>> */
    private function vocab(array $flat): array
    {
        $rows = static fn ($q, string $label = 'name') => $q->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->{$label}])->values()->all();

        $picked = ProductSource::ids($flat['home_bl_picks'] ?? '', 24);

        // The newest 150 articles, plus any picked one older than that. Two
        // statements, not `IN (subquery … LIMIT)`: MySQL refuses LIMIT inside
        // an IN subquery (error 1235) where SQLite allows it — caught by the
        // MySQL run, green on SQLite.
        $posts = $rows(Post::query()->select('id', 'title')->where('status', 'published')
            ->latest('published_at')->orderByDesc('id')->limit(150), 'title');
        $missing = array_values(array_diff($picked, array_column($posts, 'id')));

        if ($missing !== []) {
            $posts = array_merge($posts, $rows(Post::query()->select('id', 'title')->whereIn('id', $missing), 'title'));
        }

        return [
            'brands' => $rows(Brand::query()->select('id', 'name')->orderBy('name')->orderBy('id')),
            'categories' => $rows(Category::query()->select('id', 'name')->orderBy('name')->orderBy('id')),
            'posts' => $posts,
        ];
    }

    /**
     * One line: what this section shows right now.
     * e.g. "Best selling · Toners + Serums · 8 laptop / 6 phone".
     */
    private function summary(string $key, array $editor, array $c, array $vocab, ?GridSection $grid): string
    {
        $name = static function (array $list, array $ids): string {
            $by = array_column($list, 'name', 'id');
            $names = array_values(array_filter(array_map(fn ($id) => $by[$id] ?? null, $ids)));

            return count($names) > 3 ? implode(' + ', array_slice($names, 0, 3)).' +'.(count($names) - 3) : implode(' + ', $names);
        };

        $describe = function (string $source, array $q, array $picks, int $brand, int $cat, string $count) use ($vocab, $name): string {
            $parts = [];

            if ($source === 'manual') {
                $parts[] = 'Hand-picked · '.count($picks).' product'.(count($picks) === 1 ? '' : 's');
            } elseif ($source === 'query') {
                $parts[] = ProductSource::SORTS[$q['sort']];
                $b = $name($vocab['brands'], $q['brands']);
                $ct = $name($vocab['categories'], $q['cats']);
                $parts[] = $b !== '' ? 'Brands: '.$b : 'All brands';
                $parts[] = $ct !== '' ? 'Categories: '.$ct : 'All categories';

                if ($q['stock']) {
                    $parts[] = 'In stock only';
                }
            } elseif ($source === 'auto') {
                $parts[] = 'As shipped';
            } else {
                $label = (string) (GridSection::SOURCES[$source] ?? $source);
                $parts[] = trim(explode('—', $label)[0]);

                if ($source === 'brand') {
                    $parts[] = $name($vocab['brands'], [$brand]) ?: 'no brand chosen';
                }

                if ($source === 'category') {
                    $parts[] = $name($vocab['categories'], [$cat]) ?: 'no category chosen';
                }
            }

            $parts[] = $count;

            return implode(' · ', array_filter($parts, fn ($p) => $p !== ''));
        };

        if ($grid !== null) {
            $count = $grid->count === $grid->mobile_count ? $grid->count.' products' : $grid->count.' laptop / '.$grid->mobile_count.' phone';
            $line = $describe((string) $grid->source, ProductSource::clean($grid->source_query ?? []), array_map('intval', (array) ($grid->manual_ids ?? [])),
                (int) $grid->source_brand_id, (int) $grid->source_category_id, $count);

            return ($grid->status === 'publish' ? '' : 'Draft — draws nothing · ').$line;
        }

        if (isset($editor['source'])) {
            $s = self::sourceKeys($editor['source']);

            if ($s['style'] === 'legacy') {
                $section = array_search($editor['source'], array_column(HomeSources::RAILS, 0, null), true);
                $r = HomeSources::read($c, (string) array_keys(HomeSources::RAILS)[$section]);

                return $describe($r['source'], $r['query'], $r['picks'], 0, 0, $r['limit'].' products');
            }

            $section = array_search($editor['source'], HS::RAILS, true);
            $r = HS::rail($c, (string) $section);
            $count = $r['count_d'] === $r['count_m'] ? $r['count_d'].' products' : $r['count_d'].' laptop / '.$r['count_m'].' phone';

            return ($r['title'] !== '' ? '“'.$r['title'].'” · ' : '').$describe($r['source'], $r['query'], $r['picks'], $r['brand'], $r['category'], $count);
        }

        return match ($key) {
            'ticker' => trim((string) ($c['home_ticker'] ?? '')) !== '' ? 'Chip: “'.trim((string) $c['home_ticker']).'”' : 'No promo chip — the delivery chips still scroll',
            'brands' => (function () use ($c, $vocab, $name) {
                $b = HS::brands($c);
                $picked = $name($vocab['brands'], $b['picks']);

                return $picked !== '' ? 'Brands: '.$picked : 'The brands with the most products · '.$b['fetch'].' shown';
            })(),
            'blog' => (function () use ($c, $vocab, $name) {
                $bl = HS::blog($c);

                return $bl['source'] === 'manual' && $bl['picks'] !== [] ? 'Articles: '.$name($vocab['posts'], $bl['picks']) : 'The three newest journal articles';
            })(),
            'about' => 'About us · '.mb_strimwidth(preg_replace('/\s+/u', ' ', (string) ($c['about_text'] ?? '')), 0, 70, '…'),
            'feature' => 'Two photo panels: “'.trim((string) ($c['home_ft_l_title'] ?? '')).'” and “'.trim((string) ($c['home_ft_r_title'] ?? '')).'”',
            'hero' => count(app(HomepageContent::class)->editable()).' slides',
            default => (string) (HomepageSections::registry()[$key][1] ?? ''),
        };
    }
}
