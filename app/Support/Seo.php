<?php

namespace App\Support;

use App\Services\Seo\SeoSettings;
use App\Services\Seo\TitleTemplate;

/**
 * Builds the <head> SEO block (title, meta, canonical, Open Graph, Twitter,
 * verification, and JSON-LD structured data) for a storefront page.
 * Values come from the store_settings the admin SEO module writes.
 *
 * Settings are read through SeoSettings::map() rather than Setting::map():
 * the SEO screen saves '' for every field an admin never filled in, and this
 * file is built on `?? default`, which '' defeats. See that class.
 */
class Seo
{
    /**
     * Is the SEO Engine module switched on?
     *
     * Store → Modules → SEO → SEO Engine. Until this check existed the whole
     * engine ran unconditionally: the registry advertised `seo_engine` as
     * `live`, meaning "something on the storefront reads moduleEnabled() for
     * this key", and nothing did. The switch saved and was never read — the
     * same fault as `single_name` before 2.53.0.
     *
     * The check lives here rather than in the layout so no caller can route
     * around it. Seo::inspect() is deliberately NOT gated: the admin's Schema
     * Inspector exists to show what the engine would emit, and a tool that
     * goes blank when the thing it inspects is off is useless for deciding
     * whether to turn it on.
     *
     * Where the switch stops, deliberately: sitemap.xml, robots.txt and
     * llms.txt (SeoFilesController) each already have their own on/off field on
     * the SEO screen — `sitemap_enabled`, `llms_enabled`. Two switches for one
     * thing is how a control ends up half working, which is the reason the
     * registry has an `elsewhere` status at all, so those keep the single
     * switch they already had. Redirects and the 404 log are not gated either:
     * a redirect is a promise made to a URL somebody already published, and
     * silently dropping it because an SEO toggle moved would break live links.
     */
    private static function engineOn(): bool
    {
        return app(\App\Services\SettingsService::class)->moduleEnabled('seo_engine', true);
    }

    /**
     * What the <head> was before the engine was ever connected to a page: a
     * bare <title> and nothing else. That is the honest "off" state — the
     * layout's own comment records it as the exact previous behaviour.
     *
     * No template, no separator, no site-name append: those are engine
     * features. A page that has no title of its own still gets the store
     * name, because a blank <title> is never an acceptable output.
     */
    private static function bareHead(array $ctx): string
    {
        $s = SeoSettings::map();
        $title = trim((string) ($ctx['title'] ?? ''));

        if ($title === '') {
            $title = SeoSettings::firstFilled(
                $s['store_name'] ?? null,
                (string) config('app.name'),
                'K-Beauty Bliss'
            );
        }

        return "\n" . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n";
    }

    /** @param array $ctx type,title,description,image,url,noindex,product,article,breadcrumb */
    public static function render(array $ctx = []): string
    {
        if (! self::engineOn()) {
            return self::bareHead($ctx);
        }

        $s = SeoSettings::map();
        $siteName = SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        );
        $sep      = SeoSettings::from($s, 'seo_separator');
        $base     = rtrim(SeoSettings::firstFilled($s['site_url'] ?? null, (string) config('app.url')), '/');

        $type = $ctx['type'] ?? 'website';
        $isHome = $type === 'home';

        // Title. Home has its own field; everything else goes through the
        // configured template. A page that has already produced its final
        // title (a per-product SEO title, a blog post override) passes
        // title_is_final and is only cleaned of placeholders, not re-templated.
        $rawTitle = trim((string) ($ctx['title'] ?? ''));
        $tokens = ['title' => $rawTitle, 'sep' => $sep, 'sitename' => $siteName, 'page' => ''];

        if ($isHome) {
            $homeTitle = SeoSettings::from($s, 'seo_home_title', '');
            $title = TitleTemplate::render(
                $homeTitle !== '' ? $homeTitle : ($rawTitle !== '' ? $rawTitle : $siteName),
                $tokens,
                $sep
            );
        } elseif (!empty($ctx['title_is_final']) && $rawTitle !== '') {
            $title = TitleTemplate::render($rawTitle, ['sep' => $sep, 'sitename' => $siteName, 'page' => ''], $sep);
        } else {
            $tpl = SeoSettings::from($s, 'seo_title_template');

            // A page title that already carries the brand ("Cart · K-Beauty
            // Bliss") must not get it a second time. Dropping the token rather
            // than the template keeps the separator cleanup in one place.
            if ($rawTitle !== '' && $siteName !== '' && mb_stripos($rawTitle, $siteName) !== false) {
                $tokens['sitename'] = '';
            }

            $title = TitleTemplate::render($tpl, $tokens, $sep);
        }

        // A blank <title> is never acceptable: it is what an untouched
        // template field or an empty site name used to produce.
        if ($title === '') {
            $title = $siteName !== '' ? $siteName : 'K-Beauty Bliss';
        }

        $desc = $ctx['description']
            ?? ($isHome ? ($s['seo_home_description'] ?? null) : null)
            ?? ($s['seo_default_description'] ?? null)
            ?? '';
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) $desc)));
        // Placeholders reach the description too — the same fields accept them
        // and a literal {sitename} in a search result is as wrong as in a tab.
        $desc = TitleTemplate::render($desc, $tokens, $sep);
        if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 297) . '…';

        $url    = self::canonical($ctx['url'] ?? null, $base);
        $image  = self::absolute($ctx['image'] ?? ($s['og_default_image'] ?? null), $base);
        $robots = !empty($ctx['noindex'])
            ? 'noindex, nofollow'
            : (SeoSettings::from($s, 'robots_index') . ', ' . SeoSettings::from($s, 'robots_follow'));

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $out = [];
        $out[] = '<title>' . $e($title) . '</title>';
        if ($desc)  $out[] = '<meta name="description" content="' . $e($desc) . '">';
        $out[] = '<meta name="robots" content="' . $e($robots) . '">';
        if ($url)   $out[] = '<link rel="canonical" href="' . $e($url) . '">';

        // Webmaster verification
        if (!empty($s['google_site_verification'])) $out[] = '<meta name="google-site-verification" content="' . $e($s['google_site_verification']) . '">';
        if (!empty($s['bing_site_verification']))   $out[] = '<meta name="msvalidate.01" content="' . $e($s['bing_site_verification']) . '">';

        // Open Graph
        $out[] = '<meta property="og:type" content="' . ($type === 'product' ? 'product' : ($type === 'article' ? 'article' : 'website')) . '">';
        $out[] = '<meta property="og:site_name" content="' . $e($siteName) . '">';
        $out[] = '<meta property="og:title" content="' . $e($title) . '">';
        if ($desc)  $out[] = '<meta property="og:description" content="' . $e($desc) . '">';
        if ($url)   $out[] = '<meta property="og:url" content="' . $e($url) . '">';
        if ($image) $out[] = '<meta property="og:image" content="' . $e($image) . '">';

        // Twitter card
        $out[] = '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">';
        if (!empty($s['twitter_handle'])) $out[] = '<meta name="twitter:site" content="' . $e($s['twitter_handle']) . '">';
        $out[] = '<meta name="twitter:title" content="' . $e($title) . '">';
        if ($desc)  $out[] = '<meta name="twitter:description" content="' . $e($desc) . '">';
        if ($image) $out[] = '<meta name="twitter:image" content="' . $e($image) . '">';

        // JSON-LD structured data
        foreach (self::jsonLd($ctx, $s, $siteName, $base, $title, $desc, $url, $image) as $node) {
            $json = self::encodeJsonLd($node);

            if ($json !== null) {
                $out[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        // Optional analytics (only if configured, and only if it is actually
        // a measurement ID).
        $ga = self::measurementId($s['ga'] ?? null);
        if ($ga !== null) {
            $out[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga . '"></script>';
            $out[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $ga . "');</script>";
        }

        return "\n" . implode("\n", $out) . "\n";
    }

    /**
     * JSON-LD, encoded so it cannot close the block it sits in.
     *
     * This carried JSON_UNESCAPED_SLASHES, which switches off the `\/`
     * escaping that is the only thing keeping a "</script>" inside a string
     * from ending the <script> element. An org_name of
     * "</script><script>alert(1)</script>" -- an ordinary text field on the
     * SEO screen -- therefore executed on every page of the site. The HEX
     * flags escape <, >, &, ' and " as \uXXXX, which is valid JSON, reads
     * identically to every consumer, and leaves nothing that HTML can parse
     * as markup.
     */
    private static function encodeJsonLd(array $node): ?string
    {
        $json = json_encode(
            $node,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return $json === false ? null : $json;
    }

    /**
     * An analytics measurement ID, or null.
     *
     * This value is interpolated into a script body, where it sits inside a
     * JavaScript string literal rather than in markup -- htmlspecialchars()
     * does nothing about a quote-and-semicolon there, because the browser
     * HTML-decodes the script's contents before the JS parser ever sees them.
     * The defence has to be the value's own shape, so anything that is not a
     * Google measurement/property ID is simply not emitted.
     */
    private static function measurementId(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $ok = preg_match('/^(?:G-[A-Z0-9]{4,24}|GT-[A-Z0-9]{4,24}|AW-[0-9]{6,20}|UA-[0-9]{4,12}-[0-9]{1,4})$/', $value) === 1;

        return $ok ? $value : null;
    }

    /**
     * An absolute canonical URL.
     *
     * The layout hands in Url::to(...), which is root-relative by design --
     * correct for an href, wrong for rel=canonical and og:url, which search
     * engines and every share scraper require in absolute form. The base path
     * is not added twice if site_url already carries it (the production host
     * serves the app from /kbb-upgrade, and APP_URL includes it).
     */
    private static function canonical(?string $url, string $base): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $base !== '' ? $base . '/' : null;
        }

        $basePath = $base === '' ? '' : rtrim((string) parse_url($base, PHP_URL_PATH), '/');

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $url) === 1) {
            // Several callers build their own absolute URL as
            // site_url . $model->url(), and $model->url() already carries the
            // base path — so under KBB_BASE_PATH they produce
            // https://host/kbb-upgrade/kbb-upgrade/product/x/. Collapse the
            // one duplicate rather than publish a canonical that 404s.
            if ($basePath !== '' && str_starts_with($url, rtrim($base, '/') . $basePath . '/')) {
                return rtrim($base, '/') . substr($url, strlen(rtrim($base, '/') . $basePath));
            }

            return $url;
        }

        if ($base === '') {
            return $url;
        }

        $path = '/' . ltrim($url, '/');

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
            $path = $path === '' ? '/' : $path;
        }

        return rtrim($base, '/') . $path;
    }

    /**
     * Thin public wrapper around the same JSON-LD builder every real page
     * already uses — the Schema Inspector needs to show an admin exactly
     * what would actually be sent for a given page, not a reimplementation
     * of the logic that could quietly drift from what jsonLd() itself does.
     */
    public static function inspect(array $ctx): array
    {
        $s = SeoSettings::map();
        $siteName = SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        );
        $base = rtrim(SeoSettings::firstFilled($s['site_url'] ?? null, (string) config('app.url')), '/');
        $title = trim((string) ($ctx['title'] ?? '')) ?: $siteName;
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($ctx['description'] ?? ''))));
        $url = self::canonical($ctx['url'] ?? null, $base);
        $image = self::absolute($ctx['image'] ?? ($s['og_default_image'] ?? null), $base);

        return self::jsonLd($ctx, $s, $siteName, $base, $title, $desc, $url, $image);
    }

    /**
     * An absolute URL, or null.
     *
     * Url::media() returns a root-relative path -- correct for an <img> on the
     * page, and invalid everywhere this file puts it. og:image, twitter:image
     * and schema.org's image all require a full URL: Facebook and Twitter drop
     * a relative one silently, so every share of a product showed no picture,
     * and Google reports it as an invalid image field on the Product.
     *
     * Left alone if it already carries a scheme or is protocol-relative, so a
     * CDN or an absolute og_default_image still works.
     */
    private static function absolute(?string $path, string $base): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path) === 1) {
            return $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private static function jsonLd(array $ctx, array $s, string $siteName, string $base, string $title, string $desc, ?string $url, ?string $image): array
    {
        $nodes = [];

        // Organization + WebSite (sitewide)
        $org = [
            '@context' => 'https://schema.org',
            '@type' => SeoSettings::from($s, 'org_type'),
            'name' => SeoSettings::from($s, 'org_name', $siteName),
        ];
        if ($base) $org['url'] = $base;
        // Absolute for the same reason og:image is: Google rejects a relative
        // logo on an Organization outright.
        $logo = self::absolute($s['org_logo'] ?? null, $base);
        if ($logo !== null) $org['logo'] = $logo;

        // sameAs: official social profiles, confirming to Google these
        // really are the same business — helps Knowledge Panel and brand
        // search results. Only genuinely-filled-in ones are included.
        $sameAs = array_values(array_filter([
            $s['social_facebook'] ?? null,
            $s['social_instagram'] ?? null,
            $s['social_tiktok'] ?? null,
            $s['social_pinterest'] ?? null,
            $s['social_linkedin'] ?? null,
            $s['social_youtube'] ?? null,
        ]));
        if (!empty($sameAs)) $org['sameAs'] = $sameAs;

        $nodes[] = $org;

        if ($base) {
            $nodes[] = [
                '@context' => 'https://schema.org', '@type' => 'WebSite',
                'name' => $siteName, 'url' => $base,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base . '/shop?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        }

        // Product schema
        if (($ctx['type'] ?? '') === 'product' && !empty($ctx['product'])) {
            $p = $ctx['product'];
            $node = [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => $p['name'] ?? $title,
            ];
            if (!empty($p['brand']))       $node['brand'] = ['@type' => 'Brand', 'name' => $p['brand']];
            if ($desc)                     $node['description'] = $desc;
            if ($image)                    $node['image'] = $image;
            if (!empty($p['sku']))         $node['sku'] = $p['sku'];
            if (isset($p['price_aed'])) {
                $offer = [
                    '@type' => 'Offer', 'priceCurrency' => 'AED',
                    'price' => number_format((float) $p['price_aed'], 2, '.', ''),
                    'availability' => (($p['stock'] ?? 1) > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => $url,
                ];

                // Google reports a missing priceValidUntil on every Offer. A
                // live sale gives a real date; otherwise a rolling year ahead,
                // which is what the field is for -- a statement that the price
                // is not stale, not a commitment to a date.
                $offer['priceValidUntil'] = ! empty($p['sale_ends_at'])
                    ? substr((string) $p['sale_ends_at'], 0, 10)
                    : date('Y-m-d', strtotime('+1 year'));

                // Merchant listing: brand/GTIN/condition/shipping/returns on
                // the Offer itself — what actually unlocks price + star
                // ratings showing directly in Google, and eligibility for
                // AI Shopping surfaces. Off by default (enable_merchant),
                // since shipping/return terms entered wrong is worse than
                // not shown at all — an admin has to deliberately confirm
                // these are accurate before they go out to search engines.
                if (($s['enable_merchant'] ?? '') === '1') {
                    $offer['itemCondition'] = 'https://schema.org/' . ($s['merchant_condition'] ?? 'NewCondition');

                    $shipCost = (float) ($s['merchant_ship_cost'] ?? 0);
                    $freeOver = (float) ($s['merchant_ship_free_over'] ?? 0);
                    $actualShipCost = ($freeOver > 0 && (float) $p['price_aed'] >= $freeOver) ? 0 : $shipCost;

                    $offer['shippingDetails'] = [
                        '@type' => 'OfferShippingDetails',
                        'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => (string) $actualShipCost, 'currency' => 'AED'],
                        'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $s['merchant_ship_country'] ?? 'AE'],
                    ];

                    $returnDays = (int) ($s['merchant_return_days'] ?? 0);
                    if ($returnDays > 0) {
                        $offer['hasMerchantReturnPolicy'] = [
                            '@type' => 'MerchantReturnPolicy',
                            'applicableCountry' => $s['merchant_ship_country'] ?? 'AE',
                            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                            'merchantReturnDays' => $returnDays,
                            'returnMethod' => 'https://schema.org/ReturnByMail',
                            'returnFees' => 'https://schema.org/FreeReturn',
                        ];
                    }
                }

                $node['offers'] = $offer;
            }
            if (!empty($p['rating']) && !empty($p['reviews'])) {
                $node['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string) $p['rating'], 'reviewCount' => (int) $p['reviews']];
            }
            $nodes[] = $node;
        }

        // Article schema (blog posts)
        if (($ctx['type'] ?? '') === 'article' && !empty($ctx['article'])) {
            $a = $ctx['article'];
            $nodes[] = array_filter([
                '@context' => 'https://schema.org', '@type' => 'Article',
                'headline' => $a['title'] ?? $title,
                'image' => $image ?: null,
                'datePublished' => $a['published_at'] ?? null,
                'author' => ['@type' => 'Organization', 'name' => $siteName],
                'publisher' => ['@type' => 'Organization', 'name' => $siteName],
            ]);
        }

        // Breadcrumbs
        if (!empty($ctx['breadcrumb']) && is_array($ctx['breadcrumb'])) {
            $items = [];
            foreach ($ctx['breadcrumb'] as $i => $bc) {
                $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $bc['name'], 'item' => $bc['url']];
            }
            $nodes[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        return $nodes;
    }
}
