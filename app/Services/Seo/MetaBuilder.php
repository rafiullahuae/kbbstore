<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Support\Url;

/**
 * Turns a page's SEO context plus the saved settings into the data the layout
 * prints in <head>.
 *
 * This returns *data*, not markup. Every one of these values came out of the
 * settings table, which the admin screen writes with no sanitising of its own,
 * and they all land inside HTML attributes. Building an HTML string here means
 * the layout has to print it with {!! !!} and the escaping lives in whichever
 * concatenation happened to remember it. Handing back an array lets the layout
 * use {{ }} for every single value, so a setting of `"><script>` is inert by
 * construction rather than by vigilance.
 *
 * @phpstan-type MetaTag array{attr: string, key: string, content: string}
 */
final class MetaBuilder
{
    /** Matches the admin screen's own placeholder for the title template. */
    public const DEFAULT_TITLE_TEMPLATE = '{title} {sep} {sitename}';

    public const DEFAULT_SITE_NAME = 'K-Beauty Bliss';

    public const DEFAULT_SEPARATOR = '|';

    /** Cap taken from the old renderer; Google truncates long descriptions anyway. */
    private const DESCRIPTION_LIMIT = 300;

    public function __construct(private SeoSettings $settings) {}

    /**
     * @param  array<string, mixed>  $ctx  type,title,title_is_final,description,image,url,noindex,product,article,breadcrumb
     * @return array{title: string, meta: array<int, array{attr: string, key: string, content: string}>, canonical: ?string, jsonld: array<int, array<string, mixed>>, ga: ?string}
     */
    public function build(array $ctx = []): array
    {
        $s = $this->settings;

        $siteName = $s->first(['seo_site_name', 'store_name'], self::DEFAULT_SITE_NAME);
        $separator = $s->get('seo_separator', self::DEFAULT_SEPARATOR);
        $type = $this->type($ctx);

        $title = $this->title($ctx, $type, (string) $siteName, (string) $separator);
        $description = $this->description($ctx, $type);
        $canonical = $this->canonical($ctx);
        $image = $this->image($ctx);

        $meta = [];

        if ($description !== null) {
            $meta[] = $this->name('description', $description);
        }

        $meta[] = $this->name('robots', $this->robots($ctx));

        foreach (['google_site_verification' => 'google-site-verification', 'bing_site_verification' => 'msvalidate.01'] as $key => $tag) {
            $token = $s->get($key);

            if ($token !== null) {
                $meta[] = $this->name($tag, $token);
            }
        }

        // Open Graph.
        $meta[] = $this->property('og:type', match ($type) {
            'product' => 'product',
            'article' => 'article',
            default => 'website',
        });
        $meta[] = $this->property('og:site_name', (string) $siteName);
        $meta[] = $this->property('og:title', $title);

        if ($description !== null) {
            $meta[] = $this->property('og:description', $description);
        }

        if ($canonical !== null) {
            $meta[] = $this->property('og:url', $canonical);
        }

        if ($image !== null) {
            $meta[] = $this->property('og:image', $image);
        }

        // Twitter / X.
        $meta[] = $this->name('twitter:card', $image !== null ? 'summary_large_image' : 'summary');

        $handle = $this->twitterHandle();

        if ($handle !== null) {
            $meta[] = $this->name('twitter:site', $handle);
        }

        $meta[] = $this->name('twitter:title', $title);

        if ($description !== null) {
            $meta[] = $this->name('twitter:description', $description);
        }

        if ($image !== null) {
            $meta[] = $this->name('twitter:image', $image);
        }

        return [
            'title' => $title,
            'meta' => $meta,
            'canonical' => $canonical,
            'jsonld' => $this->jsonLd($ctx, $type, (string) $siteName, $title, $description, $canonical, $image),
            'ga' => $this->analyticsId(),
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function type(array $ctx): string
    {
        $type = strtolower(trim((string) ($ctx['type'] ?? 'website')));

        return in_array($type, ['home', 'website', 'product', 'article'], true) ? $type : 'website';
    }

    // ---------------------------------------------------------------- title

    /**
     * The page title.
     *
     * The homepage gets its own setting; every other page runs the page's own
     * title through the saved template. `title_is_final` is the escape hatch
     * for a page that has already computed a complete title (a per-product SEO
     * title, say) and must not have the template applied twice.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function title(array $ctx, string $type, string $siteName, string $separator): string
    {
        $raw = $this->clean($ctx['title'] ?? null) ?? '';

        if ($type === 'home') {
            // Falls back to whatever the page itself set, which on the homepage
            // is already a full marketing title — running the template over it
            // would only append the site name a second time.
            return $this->settings->get('seo_home_title') ?? ($raw !== '' ? $raw : $siteName);
        }

        if (! empty($ctx['title_is_final'])) {
            return $raw !== '' ? $raw : $siteName;
        }

        /*
         * Pages write their own suffix into @section('title') — "Cart · K-Beauty
         * Bliss". Those views belong to other lanes and the suffix is not going
         * anywhere, so strip a trailing site name before templating; otherwise
         * every title on the site reads "Cart · K-Beauty Bliss | K-Beauty Bliss"
         * the moment a template is saved.
         */
        $bare = $this->stripSiteSuffix($raw, $siteName);

        $template = $this->settings->get('seo_title_template', self::DEFAULT_TITLE_TEMPLATE);
        $title = $this->tidy($this->fill((string) $template, $bare, $siteName, $separator), $separator);

        if ($title === '') {
            // A template of "{page}" or "{}" resolves to nothing. Never ship an
            // empty <title>: fall back to the shipped template, then to the
            // page's own title, then to the site name.
            $title = $this->tidy($this->fill(self::DEFAULT_TITLE_TEMPLATE, $bare, $siteName, $separator), $separator);
        }

        if ($title === '') {
            $title = $bare !== '' ? $bare : ($raw !== '' ? $raw : $siteName);
        }

        return $title;
    }

    /**
     * Substitute the placeholders a template may contain.
     *
     * Both spellings are accepted: the sitewide template field uses {title},
     * and the per-product SEO fields on the product editor use Yoast's
     * %%title%%. An unknown placeholder is dropped rather than printed, so a
     * typo shows as a missing word instead of a literal "{sitname}" in the
     * browser tab and in every search result.
     */
    private function fill(string $template, string $title, string $siteName, string $separator): string
    {
        $values = [
            'title' => $title,
            'sitename' => $siteName,
            'site_name' => $siteName,
            'sep' => $separator,
            'separator' => $separator,
            'page' => '',
            'primary_category' => '',
        ];

        /*
         * The last two alternatives sweep up a stray brace or %% that no
         * placeholder claimed — "{%%oops%%}" would otherwise leave "{}" on the
         * page. Only the template is scanned; a substituted value is returned
         * from the callback and never re-read, so a product genuinely named
         * "{Foo}" keeps its braces.
         */
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]*)\}|%%([a-z0-9_]*)%%|[{}]|%%/i',
            static function (array $m) use ($values): string {
                $name = strtolower(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));

                return (string) ($values[$name] ?? '');
            },
            $template
        );
    }

    /** Collapse the whitespace and dangling separators an empty placeholder leaves behind. */
    private function tidy(string $value, string $separator): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($separator !== '') {
            $quoted = preg_quote($separator, '/');
            $value = (string) preg_replace("/^(?:\\s*{$quoted})+|(?:{$quoted}\\s*)+$/u", '', $value);
        }

        return trim($value);
    }

    /** "Cart · K-Beauty Bliss" -> "Cart". Left alone when there is no suffix. */
    private function stripSiteSuffix(string $title, string $siteName): string
    {
        if ($title === '' || $siteName === '') {
            return $title;
        }

        // Any of the separators the storefront's own titles use, plus whatever
        // the shop owner configured.
        $separators = ['·', '|', '-', '–', '—', ':', '•'];
        $configured = (string) $this->settings->get('seo_separator', self::DEFAULT_SEPARATOR);

        if ($configured !== '' && ! in_array($configured, $separators, true)) {
            $separators[] = $configured;
        }

        $pattern = '/\s*(?:'.implode('|', array_map(static fn ($c) => preg_quote($c, '/'), $separators)).')\s*'
            .preg_quote($siteName, '/').'\s*$/ui';

        $stripped = trim((string) preg_replace($pattern, '', $title));

        // "K-Beauty Bliss" on its own must survive — only a suffix is removed.
        return $stripped !== '' ? $stripped : $title;
    }

    // ---------------------------------------------------------- description

    /** @param array<string, mixed> $ctx */
    private function description(array $ctx, string $type): ?string
    {
        $description = $this->clean($ctx['description'] ?? null);

        if ($description === null && $type === 'home') {
            $description = $this->settings->get('seo_home_description');
        }

        $description ??= $this->settings->get('seo_default_description');

        if ($description === null) {
            return null;
        }

        $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > self::DESCRIPTION_LIMIT) {
            $description = mb_substr($description, 0, self::DESCRIPTION_LIMIT - 3).'…';
        }

        return $description;
    }

    // ------------------------------------------------------------- the rest

    /** @param array<string, mixed> $ctx */
    private function robots(array $ctx): string
    {
        if (! empty($ctx['noindex'])) {
            return 'noindex, nofollow';
        }

        return $this->settings->oneOf('robots_index', ['index', 'noindex'], 'index')
            .', '.$this->settings->oneOf('robots_follow', ['follow', 'nofollow'], 'follow');
    }

    /** @param array<string, mixed> $ctx */
    private function canonical(array $ctx): ?string
    {
        $url = $this->clean($ctx['url'] ?? null);

        if ($url === null) {
            return $this->base();
        }

        return $this->absolute($url);
    }

    /** @param array<string, mixed> $ctx */
    private function image(array $ctx): ?string
    {
        $image = $this->clean($ctx['image'] ?? null) ?? $this->settings->get('og_default_image');

        return $image === null ? null : $this->absolute($image);
    }

    /**
     * The canonical origin: the Site URL from the SEO screen, else APP_URL.
     *
     * Returned without a trailing slash, and null when neither is configured —
     * a canonical or og:url of "" is worse than no tag at all.
     */
    private function base(): ?string
    {
        $base = $this->settings->get('site_url') ?? trim((string) config('app.url'));
        $base = rtrim(trim($base), '/');

        return $base === '' ? null : $base;
    }

    /**
     * A site path made absolute against the canonical origin.
     *
     * Url::to() is root-relative by design, which is right for an href and
     * wrong for og:url and rel=canonical — both are read by machines that do
     * not know the host. The base path is stripped off the origin before
     * Url::to() puts it back, so a subdirectory install gets it exactly once
     * (the /kbb-upgrade/kbb-upgrade/ bug Url::redirect() documents).
     */
    private function absolute(string $path): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $origin = $this->base();

        if ($origin === null) {
            return Url::to($path);
        }

        $prefix = Url::base();

        if ($prefix !== '' && str_ends_with($origin, $prefix)) {
            $origin = substr($origin, 0, -strlen($prefix));
        }

        return rtrim($origin, '/').Url::to($path);
    }

    /** Stored with or without the @, and sometimes as a full profile URL. */
    private function twitterHandle(): ?string
    {
        $handle = $this->settings->get('twitter_handle');

        if ($handle === null) {
            return null;
        }

        $handle = preg_replace('#^https?://(?:www\.)?(?:twitter|x)\.com/#i', '', $handle);
        $handle = trim((string) $handle);
        $handle = ltrim($handle, '@');
        $handle = preg_replace('/[^A-Za-z0-9_]/', '', (string) $handle);

        return ($handle === null || $handle === '') ? null : '@'.$handle;
    }

    /**
     * The Google Analytics id, only if it looks like one.
     *
     * This value is the one thing here that ends up inside a <script>, where
     * HTML escaping is the wrong defence. Validating the shape means nothing
     * but a measurement id can ever reach it.
     */
    private function analyticsId(): ?string
    {
        $id = $this->settings->get('ga');

        return ($id !== null && preg_match('/^(?:G|UA|AW|GTM|DC)-[A-Za-z0-9_-]{4,24}$/', $id) === 1) ? $id : null;
    }

    /** Trim, drop tags, and treat blank as absent. */
    private function clean(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : $value;
    }

    /** @return array{attr: string, key: string, content: string} */
    private function name(string $key, string $content): array
    {
        return ['attr' => 'name', 'key' => $key, 'content' => $content];
    }

    /** @return array{attr: string, key: string, content: string} */
    private function property(string $key, string $content): array
    {
        return ['attr' => 'property', 'key' => $key, 'content' => $content];
    }

    // ------------------------------------------------------------- JSON-LD

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<int, array<string, mixed>>
     */
    private function jsonLd(array $ctx, string $type, string $siteName, string $title, ?string $description, ?string $url, ?string $image): array
    {
        $base = $this->base();
        $nodes = [];

        // schema.org types are case-sensitive, and the admin select offers
        // exactly these four. Anything else is a hand-edited row.
        $orgTypes = ['organization' => 'Organization', 'onlinestore' => 'OnlineStore', 'store' => 'Store', 'localbusiness' => 'LocalBusiness'];

        $org = [
            '@context' => 'https://schema.org',
            '@type' => $orgTypes[$this->settings->oneOf('org_type', array_keys($orgTypes), 'organization')],
            'name' => $this->settings->first(['org_name', 'seo_site_name', 'store_name'], $siteName),
        ];

        if ($base !== null) {
            $org['url'] = $base;
        }

        $logo = $this->settings->get('org_logo');

        if ($logo !== null) {
            $org['logo'] = $this->absolute($logo);
        }

        $nodes[] = $org;

        if ($base !== null) {
            $nodes[] = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => $base,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base.'/shop?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        if ($type === 'product' && ! empty($ctx['product']) && is_array($ctx['product'])) {
            $p = $ctx['product'];
            $node = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $this->clean($p['name'] ?? null) ?? $title];

            if (! empty($p['brand'])) {
                $node['brand'] = ['@type' => 'Brand', 'name' => (string) $p['brand']];
            }

            if ($description !== null) {
                $node['description'] = $description;
            }

            if ($image !== null) {
                $node['image'] = $image;
            }

            if (! empty($p['sku'])) {
                $node['sku'] = (string) $p['sku'];
            }

            if (isset($p['price_aed'])) {
                $node['offers'] = array_filter([
                    '@type' => 'Offer',
                    'priceCurrency' => 'AED',
                    'price' => number_format((float) $p['price_aed'], 2, '.', ''),
                    'availability' => ((int) ($p['stock'] ?? 1) > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => $url,
                ], static fn ($v) => $v !== null);
            }

            if (! empty($p['rating']) && ! empty($p['reviews'])) {
                $node['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => (string) $p['rating'],
                    'reviewCount' => (int) $p['reviews'],
                ];
            }

            $nodes[] = $node;
        }

        if ($type === 'article' && ! empty($ctx['article']) && is_array($ctx['article'])) {
            $a = $ctx['article'];
            $nodes[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $this->clean($a['title'] ?? null) ?? $title,
                'image' => $image,
                'datePublished' => $a['published_at'] ?? null,
                'author' => ['@type' => 'Organization', 'name' => $siteName],
                'publisher' => ['@type' => 'Organization', 'name' => $siteName],
            ], static fn ($v) => $v !== null);
        }

        if (! empty($ctx['breadcrumb']) && is_array($ctx['breadcrumb'])) {
            $items = [];

            foreach (array_values($ctx['breadcrumb']) as $i => $crumb) {
                if (! is_array($crumb) || empty($crumb['name'])) {
                    continue;
                }

                $items[] = array_filter([
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => (string) $crumb['name'],
                    'item' => isset($crumb['url']) ? $this->absolute((string) $crumb['url']) : null,
                ], static fn ($v) => $v !== null);
            }

            if ($items !== []) {
                $nodes[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
            }
        }

        return $nodes;
    }
}
