<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Builds the <head> SEO block (title, meta, canonical, Open Graph, Twitter,
 * verification, and JSON-LD structured data) for a storefront page.
 * Values come from the store_settings the admin SEO module writes.
 */
class Seo
{
    /** @param array $ctx type,title,description,image,url,noindex,product,article,breadcrumb */
    public static function render(array $ctx = []): string
    {
        $s = Setting::map();
        $siteName = $s['seo_site_name'] ?? ($s['store_name'] ?? 'K-Beauty Bliss');
        $sep      = $s['seo_separator'] ?? '|';
        $base     = rtrim($s['site_url'] ?? config('app.url') ?? '', '/');

        $type = $ctx['type'] ?? 'website';
        $isHome = $type === 'home';

        // Title (templated). Home uses its own title; others use "{title} | {sitename}".
        $rawTitle = $ctx['title'] ?? '';
        if ($isHome) {
            $title = $s['seo_home_title'] ?? ($rawTitle ?: $siteName);
        } elseif (!empty($ctx['title_is_final'])) {
            $title = $rawTitle ?: $siteName;             // per-product SEO title, used verbatim
        } else {
            $tpl = $s['seo_title_template'] ?? "{title} {sep} {sitename}";
            $title = $rawTitle
                ? strtr($tpl, ['{title}' => $rawTitle, '{sep}' => $sep, '{sitename}' => $siteName])
                : $siteName;
        }

        $desc = $ctx['description']
            ?? ($isHome ? ($s['seo_home_description'] ?? null) : null)
            ?? ($s['seo_default_description'] ?? null)
            ?? '';
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) $desc)));
        if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 297) . '…';

        $url    = $ctx['url'] ?? ($base ?: null);
        $image  = self::absolute($ctx['image'] ?? ($s['og_default_image'] ?? null), $base);
        $robots = !empty($ctx['noindex'])
            ? 'noindex, nofollow'
            : (($s['robots_index'] ?? 'index') . ', ' . ($s['robots_follow'] ?? 'follow'));

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
            $out[] = '<script type="application/ld+json">' . json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
        }

        // Optional analytics / pixel (only if configured)
        if (!empty($s['ga'])) {
            $ga = $e($s['ga']);
            $out[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga . '"></script>';
            $out[] = "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $ga . "');</script>";
        }

        return "\n" . implode("\n", $out) . "\n";
    }

    /**
     * Thin public wrapper around the same JSON-LD builder every real page
     * already uses — the Schema Inspector needs to show an admin exactly
     * what would actually be sent for a given page, not a reimplementation
     * of the logic that could quietly drift from what jsonLd() itself does.
     */
    public static function inspect(array $ctx): array
    {
        $s = Setting::map();
        $siteName = $s['seo_site_name'] ?? ($s['store_name'] ?? 'K-Beauty Bliss');
        $base = rtrim($s['site_url'] ?? config('app.url') ?? '', '/');
        $title = $ctx['title'] ?? $siteName;
        $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($ctx['description'] ?? ''))));
        $url = $ctx['url'] ?? ($base ?: null);
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
        $org = ['@context' => 'https://schema.org', '@type' => $s['org_type'] ?? 'Organization', 'name' => $s['org_name'] ?? $siteName];
        if ($base) $org['url'] = $base;
        if (!empty($s['org_logo'])) $org['logo'] = $s['org_logo'];

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
