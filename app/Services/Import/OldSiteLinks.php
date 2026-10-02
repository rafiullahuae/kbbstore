<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Block;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\Entities\ContentBlockImporter;
use App\Support\CategoryPath;
use App\Support\Url;
use App\Support\UrlScheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Links inside imported copy that still point at the OLD site, pointed at this
 * shop's address for the same thing. (Lane PT)
 *
 * THE DEFECT, AS THE OWNER SAW IT (2 October 2026): "Internal links in the end
 * of anua foam and other cleansers -- on all articles and products/sets
 * descriptions, internal links are going to still old site." The Anua foam's
 * description ends "Get premium Face Cleansers at unbeatable prices only at
 * K-Beauty Bliss", and "Face Cleansers" is
 * `<a href="https://kbeautybliss.com/face-washes/">`. Every product, set and
 * article written on WordPress carries links like it, the export copies the
 * copy verbatim, and a shopper who clicks one leaves extrabeauty.ae for a site
 * that is about to be switched off.
 *
 * =============================================================================
 * WHAT IS REWRITTEN, AND WHAT IS NOT
 * =============================================================================
 *
 * Only the `href` of an `<a>` whose host is the old shop (kbeautybliss.com or
 * www., http or https, or protocol-relative). Its link text, its rel, its
 * target, every other attribute and every other byte of the document are
 * untouched: the edit is SURGICAL -- the same `tags()`/`attributeOf()`
 * DocumentMediaRewrite edits pictures with (via its `anchors()`), the value is
 * spliced in place and the document is never re-serialised. Its header says
 * why that matters for an Arabic article.
 *
 * NOT A LINK TO A FILE UNDER wp-content/uploads. That is a picture's business
 * and DocumentMediaRewrite already owns it -- it re-points the anchor only
 * once the file is on this server, which is the rule that keeps a working link
 * from becoming a broken one. Taking it here would undo that rule.
 *
 * =============================================================================
 * WHERE A LINK GOES -- the first answer wins
 * =============================================================================
 *
 *   /product/{slug}/                    a product with that slug -> its page
 *   /product-category/…/{leaf}/         a category -> /collections/{path}/
 *   /{a}/{b}/…/{leaf}/ at the root      a category whose slug is the leaf
 *                                       (the old shop served categories flat at
 *                                       the root: LegacyCategoryUrls)
 *   /brand/ /brands/ /pa_brands/ /korean-skincare-brands/ {slug}/
 *   /shop/?filter_brands={one slug}     a brand -> /brands/{slug}/
 *   /blog/ /skincare-guide/ {slug}/, or /{slug}/ at the root
 *                                       an article -> /blog/{slug}/
 *   /{slug}/                            a page -> /{slug}/
 *   anything with a row in `redirects`  that row's target (the "Old addresses"
 *                                       map writes them; the owner approves)
 *   anything else                       THE SAME PATH ON THIS SHOP. The
 *                                       redirect layer answers it like any
 *                                       other old address; the old host is
 *                                       never left in the link.
 *
 * Every answer goes out through Url::raw(), so a shop mounted under a base path
 * (KBB_BASE_PATH, `/kbb-upgrade` on the old staging box, empty on the live
 * shop) gets links that carry it, exactly as a picture path does. A query
 * string and a fragment are kept, except the `filter_brands` a brand page
 * replaces.
 *
 * =============================================================================
 * REVERSIBLE, WHICH A PICTURE RE-POINT GETS FOR FREE AND THIS DOES NOT
 * =============================================================================
 *
 * `old_link_rewrites` records, per document, every {from, to} in document
 * order. restore() walks them back -- only where the link still says exactly
 * what this wrote, so a link somebody has edited since is left alone and
 * counted as kept. See the migration for why a ledger is needed here when
 * MediaRewrite needs none.
 *
 * AND THE LEDGER IS WHAT KEEPS THE IMPORT IDEMPOTENT. A second import writes
 * the export's copy -- old links and all -- back over the row, and the row
 * would read as "updated" on every pass. replay() applies the recorded map to
 * the incoming copy first, so an unchanged export compares equal to what this
 * left behind and the importer's own dirty check says "unchanged".
 */
final class OldSiteLinks
{
    /** The old shop. `www.` is derived, not listed -- see hosts(). */
    public const OLD_HOSTS = ['kbeautybliss.com'];

    /**
     * The documents this reads, and the label each row is shown under.
     *
     * @var list<array{0: class-string, 1: string, 2: string}>
     */
    public const DOCUMENTS = [
        [Product::class, 'products', 'description'],
        [Product::class, 'products', 'short_description'],
        [Post::class, 'posts', 'body'],
        [Block::class, 'blocks', 'content'],
        [Category::class, 'categories', 'description'],
        [Brand::class, 'brands', 'description'],
    ];

    /** Bases the old shop published a brand under. */
    private const BRAND_BASES = ['brand', 'brands', 'pa_brands', 'korean-skincare-brands'];

    /** Bases the old shop (or this one) published an article under. */
    private const BLOG_BASES = ['blog', 'skincare-guide'];

    /** @var array<string, string>|null slug => category path */
    private ?array $categories = null;

    /** @var array<string, true>|null */
    private ?array $products = null;

    /** @var array<string, true>|null */
    private ?array $brands = null;

    /** @var array<string, true>|null */
    private ?array $posts = null;

    /** @var array<string, true>|null */
    private ?array $pages = null;

    /** @var array<string, string>|null source path => target */
    private ?array $redirects = null;

    /** @var array<string, string|null> slug => path from CategoryPath::resolve(), memoised */
    private array $movedCategories = [];

    /** @var array<string, array<string, array<string, string>>> table => "id:field" => from => to */
    private array $ledger = [];

    /** @return list<string> the old shop's hosts, apex and www. */
    public static function hosts(): array
    {
        $out = [];

        foreach (self::OLD_HOSTS as $host) {
            $host = strtolower($host);
            $out[] = $host;
            $out[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
        }

        return array_values(array_unique($out));
    }

    /** Is this a link into the old shop, as opposed to anything else? */
    public static function isOldSite(string $url): bool
    {
        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($scheme, ['http', 'https'], true) && in_array($host, self::hosts(), true);
    }

    /**
     * This shop's address for one old-site link, or null when it is not one
     * this class re-points (another host, or a file under uploads).
     */
    public function resolve(string $url): ?string
    {
        if (! self::isOldSite($url)) {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?? '');

        if ($path === '') {
            $path = '/';
        }

        // A FILE under uploads is a picture's business -- DocumentMediaRewrite
        // re-points it once the file is here. The same test that class uses.
        if (MediaRewrite::uploadsRelativeTo($path) !== null && preg_match('/\.[A-Za-z0-9]{1,8}$/', $path) === 1) {
            return null;
        }

        [$target, $query] = $this->target($path, $query);

        return Url::raw($target)
            .($query === '' ? '' : '?'.$query)
            .($fragment === '' ? '' : '#'.$fragment);
    }

    /**
     * @return array{0: string, 1: string} a root-relative path with no base path, and the query to keep
     */
    private function target(string $path, string $query): array
    {
        $decoded = mb_strtolower(rawurldecode($path));
        $segments = array_values(array_filter(explode('/', trim($decoded, '/')), static fn (string $s): bool => $s !== ''));
        $count = count($segments);
        $leaf = $count > 0 ? $segments[$count - 1] : '';
        $first = $segments[0] ?? '';

        if ($count === 0) {
            return ['/', $query];
        }

        // /shop/?filter_brands=cosrx -- the old brand listing, one brand.
        if ($first === 'shop' && $count === 1) {
            parse_str($query, $params);
            $brand = is_string($params['filter_brands'] ?? null) ? mb_strtolower(trim($params['filter_brands'])) : '';

            if ($brand !== '' && ! str_contains($brand, ',') && isset($this->brands()[$brand])) {
                unset($params['filter_brands']);

                return [UrlScheme::brand($brand), http_build_query($params)];
            }

            return [UrlScheme::shop(), $query];
        }

        if ($first === 'product' && $count >= 2 && isset($this->products()[$leaf])) {
            return [UrlScheme::product($leaf), $query];
        }

        if (in_array($first, ['product-category', 'collections'], true) && $count >= 2) {
            $category = $this->category($leaf);

            if ($category !== null) {
                return [UrlScheme::collection($category), $query];
            }
        }

        if (in_array($first, self::BRAND_BASES, true) && $count === 2 && isset($this->brands()[$leaf])) {
            return [UrlScheme::brand($leaf), $query];
        }

        if (in_array($first, self::BLOG_BASES, true) && $count === 2 && isset($this->posts()[$leaf])) {
            return [UrlScheme::article($leaf), $query];
        }

        // AT THE ROOT. The old shop served categories, articles and pages flat
        // at /{slug}/. An article wins its own slug (the rule
        // LegacyCategoryUrls::landingPath() keeps for the same namespace).
        if ($count === 1 && isset($this->posts()[$leaf])) {
            return [UrlScheme::article($leaf), $query];
        }

        if (! in_array($first, ['product', 'product-category', 'collections', 'shop', ...self::BRAND_BASES, ...self::BLOG_BASES], true)) {
            $category = $this->category($leaf);

            if ($category !== null) {
                return [UrlScheme::collection($category), $query];
            }

            // A dated permalink, /2021/05/{slug}/, still names an article.
            if (isset($this->posts()[$leaf])) {
                return [UrlScheme::article($leaf), $query];
            }

            if ($count === 1 && isset($this->pages()[$leaf])) {
                return ['/'.$leaf.'/', $query];
            }
        }

        // A row the "Old addresses" map wrote, or one the owner wrote himself.
        $redirect = $this->redirects()[self::normalisePath($decoded)] ?? null;

        if ($redirect !== null) {
            return [$redirect, $query];
        }

        // Nothing here answers it: the same path on THIS shop, which the
        // redirect layer then handles like any other old address. The path is
        // kept as the old site spelled it, not lower-cased.
        return ['/'.ltrim($path, '/'), $query];
    }

    /** The category path for a slug, or null. Moved categories via CategoryPath. */
    private function category(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        $known = $this->categories()[$slug] ?? null;

        if ($known !== null) {
            return $known;
        }

        if (! array_key_exists($slug, $this->movedCategories)) {
            $verdict = CategoryPath::resolve($slug);

            $this->movedCategories[$slug] = match ($verdict['status']) {
                'ok' => isset($verdict['category']) ? CategoryPath::canonicalPath($verdict['category']) : null,
                'redirect' => isset($verdict['to_path']) && $verdict['to_path'] !== '' ? (string) $verdict['to_path'] : null,
                default => null,
            };
        }

        return $this->movedCategories[$slug];
    }

    /** @return array<string, string> */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];

            foreach (Category::query()->select(['id', 'slug', 'path'])->cursor() as $category) {
                $slug = mb_strtolower((string) $category->slug);

                if ($slug !== '' && ! isset($this->categories[$slug])) {
                    $this->categories[$slug] = trim((string) ($category->path ?: $category->slug), '/');
                }
            }
        }

        return $this->categories;
    }

    /** @return array<string, true> */
    private function products(): array
    {
        return $this->products ??= self::slugSet(Product::query()->pluck('slug')->all());
    }

    /** @return array<string, true> */
    private function brands(): array
    {
        return $this->brands ??= self::slugSet(Brand::query()->pluck('slug')->all());
    }

    /** @return array<string, true> */
    private function posts(): array
    {
        return $this->posts ??= self::slugSet(Post::query()->pluck('slug')->all());
    }

    /** @return array<string, true> */
    private function pages(): array
    {
        return $this->pages ??= self::slugSet(Page::query()->pluck('slug')->all());
    }

    /** @return array<string, string> */
    private function redirects(): array
    {
        if ($this->redirects === null) {
            $this->redirects = [];

            foreach (Redirect::query()->where('enabled', true)->get(['source', 'target']) as $row) {
                $target = trim((string) $row->target);

                // A relative target only: an absolute one may name the old
                // host itself, and the whole point is to leave it.
                if ($target === '' || ! str_starts_with($target, '/') || str_starts_with($target, '//')) {
                    continue;
                }

                $this->redirects[self::normalisePath(mb_strtolower((string) $row->source))] = $target;
            }
        }

        return $this->redirects;
    }

    private static function normalisePath(string $path): string
    {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? $path);

        return '/'.trim($path, '/').($path === '/' || trim($path, '/') === '' ? '' : '/');
    }

    /**
     * @param  list<mixed>  $slugs
     * @return array<string, true>
     */
    private static function slugSet(array $slugs): array
    {
        $out = [];

        foreach ($slugs as $slug) {
            $slug = mb_strtolower(trim((string) $slug));

            if ($slug !== '') {
                $out[$slug] = true;
            }
        }

        return $out;
    }

    /* ───────────────────────────────────────────────────────── documents ── */

    /**
     * The changes one document needs, in document order.
     *
     * @return list<array{from: string, to: string, at: int, length: int, quote: string, text: string}>
     */
    public function changesIn(string $html): array
    {
        $out = [];

        foreach (DocumentMediaRewrite::anchors($html) as $anchor) {
            $to = $this->resolve($anchor['url']);

            if ($to === null || $to === $anchor['url']) {
                continue;
            }

            $out[] = [
                'from' => $anchor['url'],
                'to' => $to,
                'at' => $anchor['at'],
                'length' => $anchor['length'],
                'quote' => $anchor['quote'],
                'text' => self::linkText($html, $anchor['at']),
            ];
        }

        return $out;
    }

    /** Splice new values into a document, back to front so offsets hold. */
    private static function splice(string $html, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        foreach ($edits as $edit) {
            $written = htmlspecialchars($edit['to'], ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

            // An unquoted value is quoted when the new one needs it -- the
            // DocumentMediaRewrite::replace() rule, for the same reason.
            if ($edit['quote'] === '' && preg_match('/[\s>"\'=`]/', $written) === 1) {
                $written = '"'.$written.'"';
            }

            $html = substr($html, 0, $edit['at']).$written.substr($html, $edit['at'] + $edit['length']);
        }

        return $html;
    }

    /** The words a reader clicks, for the preview. Text only, trimmed. */
    private static function linkText(string $html, int $at): string
    {
        $open = strpos($html, '>', $at);
        $close = $open === false ? false : stripos($html, '</a', $open);

        if ($open === false || $close === false) {
            return '';
        }

        $text = html_entity_decode(strip_tags(substr($html, $open + 1, $close - $open - 1)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), 80);
    }

    /**
     * Every document that still links to the old shop, and what would change.
     *
     * @return list<array{table: string, id: int, field: string, label: string, changes: list<array{from: string, to: string, text: string}>}>
     */
    public function propose(): array
    {
        $out = [];

        foreach ($this->documents() as $document) {
            $changes = $this->changesIn($document['html']);

            if ($changes === []) {
                continue;
            }

            $out[] = [
                'table' => $document['table'],
                'id' => $document['id'],
                'field' => $document['field'],
                'label' => $document['label'],
                'changes' => array_map(
                    static fn (array $c): array => ['from' => $c['from'], 'to' => $c['to'], 'text' => $c['text']],
                    $changes,
                ),
            ];
        }

        return $out;
    }

    /**
     * What the screen shows: how many documents, how many links, and a sample.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return array{documents: int, links: int, by_table: array<string, int>, samples: list<array<string, string>>}
     */
    public static function summarise(array $proposals, int $samples = 12): array
    {
        $links = 0;
        $byTable = [];
        $out = [];

        foreach ($proposals as $proposal) {
            $links += count($proposal['changes']);
            $byTable[$proposal['table']] = ($byTable[$proposal['table']] ?? 0) + 1;

            foreach ($proposal['changes'] as $change) {
                if (count($out) < $samples) {
                    $out[] = [
                        'where' => self::where($proposal['table'], $proposal['field']).' — '.$proposal['label'],
                        'text' => $change['text'],
                        'from' => $change['from'],
                        'to' => $change['to'],
                    ];
                }
            }
        }

        return ['documents' => count($proposals), 'links' => $links, 'by_table' => $byTable, 'samples' => $out];
    }

    private static function where(string $table, string $field): string
    {
        return match ($table.'.'.$field) {
            'products.description' => 'Product description',
            'products.short_description' => 'Product short description',
            'posts.body' => 'Article',
            'blocks.content' => 'HTML block',
            'categories.description' => 'Category description',
            'brands.description' => 'Brand description',
            default => $table.'.'.$field,
        };
    }

    /**
     * Rewrite every old-site link, recording each change. Returns what it did.
     *
     * One transaction: a half-applied run is copy with some links here and
     * some there and a ledger that only knows about some of them.
     *
     * @return array{documents: int, links: int, batch: string}
     */
    public function apply(): array
    {
        $batch = now()->format('YmdHis').'-'.Str::lower(Str::random(6));
        $documents = 0;
        $links = 0;

        DB::transaction(function () use ($batch, &$documents, &$links): void {
            foreach ($this->documents() as $document) {
                $changes = $this->changesIn($document['html']);

                if ($changes === []) {
                    continue;
                }

                /** @var \Illuminate\Database\Eloquent\Model|null $row */
                $row = $document['model']::query()->find($document['id']);

                if ($row === null) {
                    continue;
                }

                $before = (string) ($row->{$document['field']} ?? '');

                if ($before !== $document['html']) {
                    continue;
                }

                $after = self::splice($before, $changes);

                // A content block keeps a fingerprint of what the last import
                // wrote so an owner's edit is never overwritten. This rewrite
                // is not an owner's edit: move the fingerprint with it -- but
                // only when it matched, so a block he HAS edited stays edited.
                if ($row instanceof Block && $row->source_hash !== null
                    && hash_equals($row->source_hash, ContentBlockImporter::hash((string) $row->name, (string) $row->status, $before))) {
                    $row->source_hash = ContentBlockImporter::hash((string) $row->name, (string) $row->status, $after);
                }

                $row->{$document['field']} = $after;
                $row->save();

                DB::table('old_link_rewrites')->insert([
                    'batch' => $batch,
                    'table' => $document['table'],
                    'row_id' => $document['id'],
                    'field' => $document['field'],
                    'changes' => json_encode(array_map(
                        static fn (array $c): array => ['from' => $c['from'], 'to' => $c['to']],
                        $changes,
                    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $documents++;
                $links += count($changes);
            }
        });

        $this->ledger = [];

        return ['documents' => $documents, 'links' => $links, 'batch' => $batch];
    }

    /**
     * Put the old-site links back, newest run first.
     *
     * A link is restored only where it still says exactly what this wrote; one
     * edited since is counted as kept and left alone.
     *
     * @return array{documents: int, links: int, kept: int}
     */
    public function restore(): array
    {
        $documents = 0;
        $links = 0;
        $kept = 0;

        DB::transaction(function () use (&$documents, &$links, &$kept): void {
            $entries = DB::table('old_link_rewrites')->whereNull('restored_at')->orderByDesc('id')->get();

            foreach ($entries as $entry) {
                $model = self::modelFor((string) $entry->table, (string) $entry->field);
                $row = $model === null ? null : $model::query()->find((int) $entry->row_id);
                $changes = json_decode((string) $entry->changes, true);

                DB::table('old_link_rewrites')->where('id', $entry->id)->update(['restored_at' => now(), 'updated_at' => now()]);

                if ($row === null || ! is_array($changes)) {
                    $kept += is_array($changes) ? count($changes) : 0;

                    continue;
                }

                $field = (string) $entry->field;
                $html = (string) ($row->{$field} ?? '');

                // One queue per written value, consumed in document order, so
                // two different old links that landed on one new address each
                // get their own back.
                $queues = [];

                foreach ($changes as $change) {
                    $queues[(string) $change['to']][] = (string) $change['from'];
                }

                $edits = [];

                foreach (DocumentMediaRewrite::anchors($html) as $anchor) {
                    $from = isset($queues[$anchor['url']]) ? array_shift($queues[$anchor['url']]) : null;

                    if ($from === null) {
                        continue;
                    }

                    $edits[] = ['at' => $anchor['at'], 'length' => $anchor['length'], 'quote' => $anchor['quote'], 'to' => $from];
                }

                foreach ($queues as $left) {
                    $kept += count($left);
                }

                if ($edits === []) {
                    continue;
                }

                $after = self::splice($html, $edits);

                if ($row instanceof Block && $row->source_hash !== null
                    && hash_equals($row->source_hash, ContentBlockImporter::hash((string) $row->name, (string) $row->status, $html))) {
                    $row->source_hash = ContentBlockImporter::hash((string) $row->name, (string) $row->status, $after);
                }

                $row->{$field} = $after;
                $row->save();

                $documents++;
                $links += count($edits);
            }
        });

        $this->ledger = [];

        return ['documents' => $documents, 'links' => $links, 'kept' => $kept];
    }

    /** What the ledger says is still applied: documents and links. */
    public static function applied(): array
    {
        $rows = DB::table('old_link_rewrites')->whereNull('restored_at')->get(['changes']);
        $links = 0;

        foreach ($rows as $row) {
            $changes = json_decode((string) $row->changes, true);
            $links += is_array($changes) ? count($changes) : 0;
        }

        return ['documents' => $rows->count(), 'links' => $links];
    }

    /**
     * The export's copy, with the links this class already re-pointed on this
     * row re-pointed the same way -- so a re-import of an unchanged export
     * writes back exactly what is stored, and reads as unchanged.
     *
     * Every unrestored record for the document, oldest first and later winning,
     * so a link re-pointed on an earlier run is not forgotten by a later one.
     * One query per TABLE per instance, not per row.
     */
    public function replay(string $table, mixed $id, string $field, ?string $html): ?string
    {
        if ($html === null || $html === '' || $id === null || ! str_contains($html, '<a')) {
            return $html;
        }

        if (! isset($this->ledger[$table])) {
            $this->ledger[$table] = [];

            try {
                $rows = DB::table('old_link_rewrites')
                    ->where('table', $table)
                    ->whereNull('restored_at')
                    ->orderBy('id')
                    ->get(['row_id', 'field', 'changes']);
            } catch (\Throwable) {
                // The table arrives with this package; before it is migrated
                // there is nothing to replay.
                $rows = [];
            }

            foreach ($rows as $row) {
                foreach ((array) json_decode((string) $row->changes, true) as $change) {
                    if (is_array($change) && isset($change['from'], $change['to'])) {
                        $this->ledger[$table][$row->row_id.':'.$row->field][(string) $change['from']] = (string) $change['to'];
                    }
                }
            }
        }

        $map = $this->ledger[$table][$id.':'.$field] ?? [];

        if ($map === []) {
            return $html;
        }

        $edits = [];

        foreach (DocumentMediaRewrite::anchors($html) as $anchor) {
            if (isset($map[$anchor['url']])) {
                $edits[] = ['at' => $anchor['at'], 'length' => $anchor['length'], 'quote' => $anchor['quote'], 'to' => $map[$anchor['url']]];
            }
        }

        return $edits === [] ? $html : self::splice($html, $edits);
    }

    /** @return class-string|null */
    private static function modelFor(string $table, string $field): ?string
    {
        foreach (self::DOCUMENTS as [$model, $t, $f]) {
            if ($t === $table && $f === $field) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Every document that names the old host at all, one row at a time.
     *
     * A LIKE on the host first, so a catalogue of three thousand descriptions
     * is not hydrated to find the forty that link out; cursor() and three
     * columns, for DocumentMediaRewrite::documents()' reason.
     *
     * @return iterable<int, array{model: class-string, table: string, id: int, field: string, label: string, html: string}>
     */
    private function documents(): iterable
    {
        foreach (self::DOCUMENTS as [$model, $table, $field]) {
            $label = match ($table) {
                'products', 'categories', 'brands' => 'name',
                'posts' => 'title',
                default => 'name',
            };

            $query = $model::query()->select(array_values(array_unique(['id', $field, $label])));

            $query->where(function ($q) use ($field): void {
                foreach (self::OLD_HOSTS as $host) {
                    $q->orWhere($field, 'like', '%'.$host.'%');
                }
            });

            foreach ($query->cursor() as $row) {
                $html = $row->getRawOriginal($field);

                if (! is_string($html) || $html === '') {
                    continue;
                }

                yield [
                    'model' => $model,
                    'table' => $table,
                    'id' => (int) $row->id,
                    'field' => $field,
                    'label' => (string) ($row->getRawOriginal($label) ?? ''),
                    'html' => $html,
                ];
            }
        }
    }
}
