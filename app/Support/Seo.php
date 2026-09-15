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

        // og:product price/availability. These are what Meta's catalogue and
        // Pinterest's rich pins read — og:type=product was already being
        // emitted with none of the properties that make it mean anything. Only
        // ever the same values the Offer above carries, so the two cannot
        // disagree.
        if ($type === 'product' && !empty($ctx['product'])) {
            $p = $ctx['product'];
            $priceString = self::priceString($p);

            if ($priceString !== null) {
                $out[] = '<meta property="product:price:amount" content="' . $e($priceString) . '">';
                $out[] = '<meta property="product:price:currency" content="'
                    . $e(SeoSettings::firstFilled($p['currency'] ?? null, 'AED')) . '">';
            }

            // Open Graph has its own small vocabulary here and it is not the
            // schema.org one, so the two are mapped explicitly rather than by
            // decamelising the schema URL — "BackOrder" is not an og value.
            $out[] = '<meta property="product:availability" content="'
                . $e(match (self::availability($p)) {
                    'https://schema.org/OutOfStock' => 'out of stock',
                    'https://schema.org/BackOrder' => 'available for order',
                    default => 'in stock',
                }) . '">';
        }

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
            $currency = SeoSettings::firstFilled($p['currency'] ?? null, 'AED');
            $node = [
                '@context' => 'https://schema.org', '@type' => 'Product',
                'name' => $p['name'] ?? $title,
            ];
            if (!empty($p['brand']))       $node['brand'] = ['@type' => 'Brand', 'name' => $p['brand']];
            if ($desc)                     $node['description'] = $desc;

            // image: Google takes several per product and picks per layout,
            // preferring a set that covers 1:1, 4:3 and 16:9. The gallery is
            // already on the page; emitting only the featured shot threw the
            // rest away. Absolute, de-duplicated, order preserved, and still a
            // bare string when there is genuinely only one -- both forms are
            // valid and the single-image page should not gain an array.
            $images = [];
            foreach ((array) ($p['images'] ?? []) as $candidate) {
                $abs = self::absolute(is_string($candidate) ? $candidate : null, $base);

                if ($abs !== null && !in_array($abs, $images, true)) {
                    $images[] = $abs;
                }
            }
            if ($images === [] && $image) {
                $images[] = $image;
            }
            if ($images !== []) {
                $node['image'] = count($images) === 1 ? $images[0] : $images;
            }

            if (!empty($p['sku']))         $node['sku'] = $p['sku'];
            // Recommended, and cheap: it disambiguates the product from the
            // page when the two are ever cited separately.
            if ($url)                      $node['url'] = $url;

            // The price arrives as an exact decimal string built from the
            // integer minor units (see Money::decimalString). price_aed is the
            // older float-valued key, still accepted for the Schema Inspector
            // and any caller that has not been moved across.
            $priceString = self::priceString($p);

            if ($priceString !== null) {
                $offer = [
                    '@type' => 'Offer', 'priceCurrency' => $currency,
                    'price' => $priceString,
                    'availability' => self::availability($p),
                    'url' => $url,
                ];

                // itemCondition is a merchant-listing requirement and is not a
                // shipping or returns term, so it is not behind the merchant
                // gate: the store sells new retail stock and the setting says
                // so, defaulting to NewCondition.
                $offer['itemCondition'] = 'https://schema.org/' . SeoSettings::from($s, 'merchant_condition');

                // priceValidUntil is emitted ONLY when a real sale window says
                // when this price stops applying, and only while that date is
                // still ahead.
                //
                // It used to fall back to date('+1 year'), which is a claim the
                // store cannot make: nothing guarantees the price holds for a
                // year. The other half of the same bug was worse -- the date
                // was taken from sale_ends_at whether or not the sale was still
                // running, so a finished sale published a priceValidUntil in
                // the PAST, and Google reads an elapsed priceValidUntil as an
                // expired offer and drops the price from the rich result
                // entirely. A missing recommended field costs a warning; a
                // stale one costs the result.
                $validUntil = self::futureDate($p['sale_ends_at'] ?? null);

                if ($validUntil !== null) {
                    $offer['priceValidUntil'] = $validUntil;
                }

                // Merchant listing: shipping and returns on the Offer itself —
                // what unlocks price + star ratings showing directly in Google,
                // and eligibility for AI Shopping surfaces. Off by default
                // (enable_merchant), since shipping/return terms entered wrong
                // is worse than not shown at all — an admin has to deliberately
                // confirm these are accurate before they go out to search
                // engines.
                if (($s['enable_merchant'] ?? '') === '1') {
                    $country = SeoSettings::from($s, 'merchant_ship_country');

                    // Both settings are major units typed into a step="0.01"
                    // box (AdminController::SETTING_RULES calls them 'aed'), so
                    // they are converted to minor units once and every
                    // comparison and the emitted string are integer work from
                    // there. The old line compared two floats and then cast the
                    // cost straight to a string, which published "0" for free
                    // shipping and "12.5" for AED 12.50.
                    $shipMinor = Money::fromMajor($s['merchant_ship_cost'] ?? 0);
                    $freeOverMinor = Money::fromMajor($s['merchant_ship_free_over'] ?? 0);
                    $priceMinor = self::priceMinor($p);

                    if ($freeOverMinor > 0 && $priceMinor !== null && $priceMinor >= $freeOverMinor) {
                        $shipMinor = 0;
                    }

                    $offer['shippingDetails'] = [
                        '@type' => 'OfferShippingDetails',
                        'shippingRate' => [
                            '@type' => 'MonetaryAmount',
                            'value' => Money::decimalString($shipMinor),
                            'currency' => $currency,
                        ],
                        'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
                    ];

                    $returnDays = (int) ($s['merchant_return_days'] ?? 0);
                    if ($returnDays > 0) {
                        $policy = [
                            '@type' => 'MerchantReturnPolicy',
                            'applicableCountry' => $country,
                            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                            'merchantReturnDays' => $returnDays,
                        ];

                        // returnMethod and returnFees were hardcoded to
                        // ReturnByMail and FreeReturn. Neither is something
                        // this code can know, and both are promises to a
                        // shopper: a store that charges for returns and
                        // publishes FreeReturn has misrepresented its terms in
                        // a Google surface. They are emitted only when an admin
                        // has actually stated them.
                        $method = SeoSettings::from($s, 'merchant_return_method', '');
                        $fees = SeoSettings::from($s, 'merchant_return_fees', '');

                        if ($method !== '') $policy['returnMethod'] = 'https://schema.org/' . $method;
                        if ($fees !== '')   $policy['returnFees'] = 'https://schema.org/' . $fees;

                        $offer['hasMerchantReturnPolicy'] = $policy;
                    }
                }

                $node['offers'] = $offer;
            }

            // AggregateRating only where real, approved reviews exist. Both
            // halves are checked: a rating with no reviews behind it, or a
            // review count with no rating, is a star rating Google can show
            // against a page that does not display one — the textbook trigger
            // for a structured-data manual action.
            $rating = (float) ($p['rating'] ?? 0);
            $reviews = (int) ($p['reviews'] ?? 0);

            if ($rating > 0 && $reviews > 0) {
                $node['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => (string) $rating,
                    'reviewCount' => $reviews,
                    // Stated rather than left to the default, so the scale is
                    // unambiguous to any consumer that does not assume 1–5.
                    'bestRating' => '5',
                    'worstRating' => '1',
                ];
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
        //
        // `item` is absolutised here rather than trusted from the caller. Every
        // trail on the storefront is built as site_url . $model->url(), and
        // site_url is read through Setting::map(), which memoises in a
        // process-level static -- so a page rendered before that map was first
        // filled produced a trail of root-relative items ("/shop/") inside an
        // otherwise absolute document. Google resolves `item` as an identifier,
        // not against <base>, and reports a relative one as invalid. The same
        // canonical() that fixes og:url and rel=canonical fixes it here, which
        // also collapses the doubled base path under KBB_BASE_PATH.
        if (!empty($ctx['breadcrumb']) && is_array($ctx['breadcrumb'])) {
            $items = [];
            foreach ($ctx['breadcrumb'] as $i => $bc) {
                $item = self::canonical($bc['url'] ?? null, $base);
                $entry = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $bc['name']];

                if ($item !== null) {
                    $entry['item'] = $item;
                }

                $items[] = $entry;
            }
            $nodes[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }

        return $nodes;
    }

    /**
     * The offer price as an exact decimal string, or null when there is none.
     *
     * `price` is the exact string the caller already built from integer minor
     * units and is used as-is. `price_aed` is the older float-valued key: it is
     * still accepted so the Schema Inspector and any caller not yet moved
     * across keep working, but it is routed back through the integer path
     * rather than through number_format() on a float.
     */
    private static function priceString(array $p): ?string
    {
        if (isset($p['price']) && is_string($p['price']) && trim($p['price']) !== '') {
            return trim($p['price']);
        }

        $minor = self::priceMinor($p);

        return $minor === null ? null : Money::decimalString($minor);
    }

    /** The offer price in minor units, or null. */
    private static function priceMinor(array $p): ?int
    {
        if (isset($p['price_minor']) && is_numeric($p['price_minor'])) {
            return (int) $p['price_minor'];
        }

        if (isset($p['price']) && is_numeric(str_replace(',', '', (string) $p['price']))) {
            return Money::fromMajor(str_replace(',', '', (string) $p['price']));
        }

        if (isset($p['price_aed']) && is_numeric($p['price_aed'])) {
            return Money::fromMajor($p['price_aed']);
        }

        return null;
    }

    /**
     * The schema.org availability URL for this product.
     *
     * stock_status is the column the storefront actually branches on and it has
     * three values, not two. The old expression read a synthesised `stock` flag
     * and collapsed 'onbackorder' into OutOfStock, which tells a shopper in a
     * search result that something they can order today cannot be bought.
     */
    private static function availability(array $p): string
    {
        $status = isset($p['stock_status']) ? (string) $p['stock_status'] : null;

        if ($status === null) {
            return (($p['stock'] ?? 1) > 0)
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock';
        }

        return match ($status) {
            'outofstock' => 'https://schema.org/OutOfStock',
            'onbackorder' => 'https://schema.org/BackOrder',
            default => 'https://schema.org/InStock',
        };
    }

    /**
     * A Y-m-d date, but only if it is genuinely today or later.
     *
     * A date already in the past is not a weaker version of this field, it is
     * an active harm: Google treats an elapsed priceValidUntil as an expired
     * offer. Anything unparseable or historic yields null and the field is
     * simply not emitted.
     */
    private static function futureDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $stamp = strtotime($value);

        if ($stamp === false) {
            return null;
        }

        return date('Y-m-d', $stamp) >= date('Y-m-d') ? date('Y-m-d', $stamp) : null;
    }
}
