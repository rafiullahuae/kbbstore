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

        /*
         * hreflang survives the engine switch, and that is deliberate.
         *
         * Store -> Modules -> SEO Engine governs what this shop CHOOSES to say
         * about itself — the title template, the descriptions, the cards, the
         * structured data. Which languages a document exists in is not a
         * choice, it is a fact about the URL space, and dropping the tag would
         * not make the Arabic pages go away: it would leave two indexable
         * copies of every page with nothing relating them, which is the
         * duplicate-content state hreflang exists to prevent. The layout
         * emitted it outside the engine's gate before this moved here, so this
         * is also the behaviour that was already shipping.
         */
        $base = rtrim(SeoSettings::firstFilled($s['site_url'] ?? null, (string) config('app.url')), '/');
        $links = self::alternateLinks(self::canonical($ctx['url'] ?? null, $base), $base, $ctx);

        return "\n" . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n"
            . ($links === [] ? '' : implode("\n", $links) . "\n");
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

        // Title. Home has its own field; everything else goes through the
        // configured template. A page that has already produced its final
        // title (a per-product SEO title, a blog post override) passes
        // title_is_final and is only cleaned of placeholders, not re-templated.
        $tokens = self::tokens($ctx, $sep, $siteName);
        $title = self::titleOf($ctx, $s, $siteName, $sep, $tokens);

        $desc = self::describe($ctx, $tokens, $sep);

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

        /*
         * Webmaster verification.
         *
         * FOUR boxes, not two. Store -> SEO & Meta -> "Verification & tracking"
         * collects Google, Bing, Pinterest and Baidu, and all four keys are on
         * AdminController::SETTING_RULES, so all four have always saved. Only
         * the first two were ever printed, which made the other two a screen
         * writing keys nothing read: "Saved" on screen, a row in the settings
         * table, no tag in the <head>, and a verification that fails at the
         * other end with nothing here to explain it.
         *
         * The tag names are each service's own and are not interchangeable:
         * Pinterest reads `p:domain_verify`, Baidu reads
         * `baidu-site-verification`. tests/Feature/SeoVerificationTagsTest.php
         * drives the list off SETTING_RULES, so a fifth engine added to that
         * constant cannot ship without its tag.
         */
        if (!empty($s['google_site_verification']))    $out[] = '<meta name="google-site-verification" content="' . $e($s['google_site_verification']) . '">';
        if (!empty($s['bing_site_verification']))      $out[] = '<meta name="msvalidate.01" content="' . $e($s['bing_site_verification']) . '">';
        if (!empty($s['pinterest_site_verification'])) $out[] = '<meta name="p:domain_verify" content="' . $e($s['pinterest_site_verification']) . '">';
        if (!empty($s['baidu_site_verification']))     $out[] = '<meta name="baidu-site-verification" content="' . $e($s['baidu_site_verification']) . '">';

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

        // hreflang. See alternateLinks() for why it is emitted from here and
        // not from the layout, and why it is built from $url rather than from
        // the request path.
        foreach (self::alternateLinks($url, $base, $ctx) as $link) {
            $out[] = $link;
        }

        /*
         * ANALYTICS USED TO BE EMITTED HERE, and that is why the storefront
         * loaded Google's tag twice.
         *
         * This block read the SEO setting `ga`. Fifty lines further down the
         * same <head>, layouts/store.blade.php called MarketingPixels::
         * baseTags(), which emitted the same two tags from a DIFFERENT setting
         * (marketing_pixels.ga4_id). Two admin boxes for one measurement ID,
         * two loaders, and two gtag('config', …) calls — so two page_view hits
         * per page view on any shop that had filled in both.
         *
         * It also meant that switching the SEO Engine module off switched
         * analytics off with it, on the pages whose only loader was this one,
         * which is not what either switch says it does.
         *
         * App\Services\Analytics is now the only thing that emits an analytics
         * tag anywhere in this application, it resolves ONE id per network,
         * and it emits once per request whoever asks. This class is back to
         * describing the page to a search engine, which is all it claims to
         * do. The shape check this file used to apply to the ID lives on as
         * Analytics::validId(), which is the only caller that needs it.
         */
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
     * An absolute canonical URL.
     *
     * The layout hands in Url::to(...), which is root-relative by design --
     * correct for an href, wrong for rel=canonical and og:url, which search
     * engines and every share scraper require in absolute form. The base path
     * is not added twice if site_url already carries it (the production host
     * serves the app from /kbb-upgrade, and APP_URL includes it).
     */
    /**
     * The placeholder values a page's title AND its description are rendered
     * with — one array, which is the whole reason this is a method.
     *
     * `{sitename}` IS DROPPED WHEN THE PAGE TITLE ALREADY CARRIES THE SITE
     * NAME. That rule was written for the title ("Cart · K-Beauty Bliss" must
     * not become "Cart · K-Beauty Bliss | K-Beauty Bliss") and it was applied
     * by mutating the shared $tokens array in the middle of render(), so it
     * reached the description too: a `seo_default_description` containing
     * {sitename} resolves it on the home page and blanks it on a product page,
     * because a product page's title ends in the site name and the home page's
     * template supplies it separately.
     *
     * That is what the pages emit today and it is NOT changed here. It is moved
     * somewhere a second caller can reproduce it, which is the point: the
     * admin's snippet preview has to predict the same string, and it could not
     * predict a rule that existed only as a mid-method assignment.
     *
     * Home pages and pages that carry a final title of their own keep the token,
     * because neither goes through the title template that would double it.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, string>
     */
    private static function tokens(array $ctx, string $sep, string $siteName): array
    {
        $rawTitle = trim((string) ($ctx['title'] ?? ''));

        $tokens = ['title' => $rawTitle, 'sep' => $sep, 'sitename' => $siteName, 'page' => ''];

        if (($ctx['type'] ?? null) !== 'home'
            && empty($ctx['title_is_final'])
            && $rawTitle !== ''
            && $siteName !== ''
            && mb_stripos($rawTitle, $siteName) !== false
        ) {
            $tokens['sitename'] = '';
        }

        return $tokens;
    }

    /**
     * The `<title>` this page will emit, resolved through the configured
     * template — the title half of what describe() answers for the description.
     *
     * ── WHY THIS EXISTS, AND WHY IT IS AN EXTRACTION RATHER THAN A SECOND COPY
     *
     * describe() was pulled out of render() because the admin's snippet preview
     * had no way to ask what description a page publishes, and so invented one.
     * The title had exactly the same shape of bug and it was still live: the
     * product editor's preview drew the operator's raw "Page title" box, or the
     * bare product name when that box was empty. Neither is what the page
     * emits. A page's title goes through `seo_title_template` — "{title} {sep}
     * {sitename}" on this store — so an operator typing a 58-character title
     * was shown "58 / 60, good" under a `<title>` that Google actually receives
     * at 58 + " | K-Beauty Bliss" = 75 characters and truncates.
     *
     * Three rules live in here and none of them is reconstructible from
     * outside: home pages use their own field, a title the page has already
     * finalised (a per-product SEO title, a post override) is only cleaned of
     * placeholders and never re-templated, and a raw title that ALREADY carries
     * the site name blanks {sitename} so the brand is not printed twice — that
     * last one via self::tokens(), which is shared with the description.
     *
     * render() calls this to build the tag. App\Support\ProductSeo::metaTitle()
     * calls it so the editor can be handed the same bytes.
     *
     * @param  array<string, mixed>  $ctx  the same context render() is given
     */
    public static function titleFor(array $ctx): string
    {
        $s = SeoSettings::map();
        $siteName = SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        );
        $sep = SeoSettings::from($s, 'seo_separator');

        return self::titleOf($ctx, $s, $siteName, $sep, self::tokens($ctx, $sep, $siteName));
    }

    /**
     * The shared body, given the settings render() has already read.
     *
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $s
     * @param  array<string, string>  $tokens
     */
    private static function titleOf(array $ctx, array $s, string $siteName, string $sep, array $tokens): string
    {
        $rawTitle = trim((string) ($ctx['title'] ?? ''));

        if (($ctx['type'] ?? 'website') === 'home') {
            $homeTitle = SeoSettings::from($s, 'seo_home_title', '');
            $title = TitleTemplate::render(
                $homeTitle !== '' ? $homeTitle : ($rawTitle !== '' ? $rawTitle : $siteName),
                $tokens,
                $sep
            );
        } elseif (!empty($ctx['title_is_final']) && $rawTitle !== '') {
            $title = TitleTemplate::render($rawTitle, ['sep' => $sep, 'sitename' => $siteName, 'page' => ''], $sep);
        } else {
            // The "already carries the brand" rule that used to live here is
            // now in self::tokens(), because it reaches the DESCRIPTION too:
            // $tokens is the same array both are rendered with, so blanking
            // {sitename} for the title silently blanked it for the description
            // as well. That coupling is preserved exactly -- it is what the
            // pages emit today -- but it is now written down in one place
            // where an outside caller can reproduce it. See describe().
            $title = TitleTemplate::render(SeoSettings::from($s, 'seo_title_template'), $tokens, $sep);
        }

        // A blank <title> is never acceptable: it is what an untouched
        // template field or an empty site name used to produce.
        if ($title === '') {
            $title = $siteName !== '' ? $siteName : 'K-Beauty Bliss';
        }

        return $title;
    }

    /**
     * The description this page will emit, resolved and cleaned — or '' when
     * the page emits no `<meta name="description">` at all.
     *
     * ── WHY THIS IS A METHOD AND NOT FOUR LINES INSIDE render() ─────────────
     *
     * It was four lines inside render(), and that made "what description does
     * this page actually publish?" a question only a full page render could
     * answer. The admin's per-product snippet preview needed exactly that
     * answer and, having no way to ask, INVENTED ONE: it fell back to
     * "Shop {name} by {brand} at {site} — authentic Korean skincare, fast UAE
     * delivery." — a sentence this storefront has never emitted, promising a
     * delivery speed for one country on behalf of a shop that serves the whole
     * Gulf on different terms per country (App\Support\DeliveryLine). The owner
     * was being shown a preview of a page that does not exist.
     *
     * So there is one implementation and both sides call it. render() calls it
     * to build the tag; App\Support\ProductSeo::metaDescription() calls it so
     * Admin\AdminController::getProduct() can hand the editor the real thing.
     *
     * '' IS A REAL ANSWER, NOT A MISSING ONE. render() emits no description tag
     * for an empty string, so an empty return here means the page publishes no
     * description — which a snippet preview should show as an empty description
     * rather than paper over with a sentence of its own.
     *
     * @param  array<string, mixed>  $ctx  the same context render() is given
     * @param  array<string, string>|null  $tokens  placeholder values; derived
     *   from $ctx when omitted, which is how an outside caller uses this
     */
    public static function describe(array $ctx, ?array $tokens = null, ?string $sep = null): string
    {
        $s = SeoSettings::map();
        $sep ??= SeoSettings::from($s, 'seo_separator');

        $tokens ??= self::tokens($ctx, $sep, SeoSettings::firstFilled(
            $s['seo_site_name'] ?? null,
            $s['store_name'] ?? null,
            (string) config('app.name'),
            'K-Beauty Bliss'
        ));

        $desc = $ctx['description']
            ?? ((($ctx['type'] ?? null) === 'home') ? ($s['seo_home_description'] ?? null) : null)
            ?? ($s['seo_default_description'] ?? null)
            ?? '';
        $desc = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $desc)));
        // Placeholders reach the description too — the same fields accept them
        // and a literal {sitename} in a search result is as wrong as in a tab.
        $desc = TitleTemplate::render($desc, $tokens, $sep);

        if (mb_strlen($desc) > 300) {
            $desc = mb_substr($desc, 0, 297) . '…';
        }

        return $desc;
    }

    /**
     * The canonical URL for the page being rendered, IN THE LANGUAGE IT IS
     * BEING RENDERED IN.
     *
     * ── WHY THE LOCALE IS APPLIED HERE AND NOT AT THE CALL SITES ────────────
     *
     * Nine places build a `url` for $ctx and hand it to render(): the product
     * page, the post page, the Journal index, /reviews, /skin-quiz, /app, the
     * four curated collections, the brand pages and /shop. Most of them build
     * it as `$siteBase . '/some/literal/path/'` — a string, not a link — so
     * Url::to() never sees it and the language prefix was never added. Fetched
     * with Arabic switched on, /ar/shop/, /ar/new-in/, /ar/skincare-guide/,
     * /ar/reviews/ and /ar/skin-quiz/ each published
     * `<link rel="canonical" href="https://…/shop/">` — the ENGLISH address.
     * A canonical pointing at another language is not a weak signal, it is an
     * instruction: it tells Google the Arabic page is a duplicate of the
     * English one and must not be indexed, which would have left the entire
     * Arabic storefront out of the index while every hreflang on the site
     * advertised it.
     *
     * Two of those nine already did it correctly (BrandController goes through
     * Url::to(), Product::url() does too), which is exactly the failure mode a
     * choke point exists to stop: the rule was known, written down, and applied
     * in two places out of nine. Fixing the seven call sites leaves the eighth
     * — the one a later lane writes — still wrong. Fixing it here cannot be
     * forgotten by a page that does not exist yet.
     *
     * ── AND IT FIXES THE `seo` JSON OVERRIDE FOR FREE ───────────────────────
     *
     * ProductController and PageController both honour a per-row
     * `seo.canonical`, which an admin types once, in English, for both
     * languages. Passed straight through, that override dragged the Arabic
     * product page onto the English canonical no matter what the rest of this
     * class did. It arrives here like any other URL and is localised like any
     * other URL — so the Arabic page canonicalises to the Arabic address OF
     * THE PAGE THE OWNER POINTED AT, which is what the override means.
     *
     * A canonical on ANOTHER HOST is left exactly as it is: a syndication
     * canonical names a document this shop does not serve, and /ar/ in front of
     * somebody else's URL is a 404.
     */
    private static function canonical(?string $url, string $base): ?string
    {
        return self::localise(self::canonicalAbsolute($url, $base), $base);
    }

    /**
     * <link rel="alternate" hreflang="…"> for every language this page exists
     * in, plus x-default.
     *
     * ── WHY IT IS BUILT FROM THE CANONICAL AND NOT FROM THE REQUEST PATH ────
     *
     * Google's stated requirement is that an hreflang URL must be the canonical
     * form of the page it names. Built from request()->getPathInfo(), as the
     * layout built it, the two could differ and on three page types they did:
     *
     *   - /new-in/?page=2 canonicalises WITH the query (page 2 is its own
     *     document) and the alternates were emitted without it, so the Arabic
     *     alternate of page two was page one;
     *   - a post carrying a `seo.canonical` override canonicalises to another
     *     address entirely, and the alternates still named this one;
     *   - /cart vs /cart/ — the canonical normalises the trailing slash back on
     *     whatever the request carried, and an alternate that disagrees is an
     *     alternate pointing at a redirect, which drops the whole cluster.
     *
     * Reading the canonical instead makes those three true by construction
     * rather than by three separate corrections: whatever the page declares
     * itself to be, its alternates are the other languages OF THAT, and the
     * self-referencing alternate is the canonical string itself.
     *
     * ── WHY IT IS HERE AND NOT IN layouts/store.blade.php ──────────────────
     *
     * Four storefront pages do not use that layout — /skin-quiz, /skincare-guide,
     * an article, and /app each carry their own <html> document and call
     * render() for their <head>. Fetched with Arabic on, all four served an
     * Arabic URL with no hreflang on it at all, so /ar/skin-quiz/ and
     * /skin-quiz/ were two unrelated pages as far as a crawler was concerned.
     * Every page that describes itself to a search engine comes through this
     * method; the layout is only one of its callers.
     *
     * ── BOTH DIRECTIONS, ALWAYS ────────────────────────────────────────────
     *
     * Including this page's own address. A page that lists its alternates
     * without listing itself is a page Google treats as unrelated to them, and
     * the pair reads as duplicate content rather than as two languages of one
     * document. Locale::alternatePaths() returns the whole set, this page
     * included, and returns [] while there is only one language live — so with
     * Arabic off nothing at all is emitted, which is what the shop does today.
     *
     * x-default points at the default language, which is where a reader whose
     * browser asks for neither should land.
     *
     * ── AND NOT AT ALL ON A DOCUMENT THAT SAYS noindex ABOUT ITSELF ────────
     *
     * An hreflang set is a claim that these URLs are alternates of one another
     * and should each be indexed for their own audience. A document whose
     * robots tag says "noindex, nofollow" is saying the opposite about itself
     * in the same <head>, and Google resolves the pair by dropping the cluster
     * rather than honouring half of it — so leaving the tags on costs the OTHER
     * language its alternate too. Not hypothetical: `brands.seo` and
     * `categories.seo` carry a noindex the owner sets per brand and per
     * category, and with Arabic enabled a brand marked noindex published three
     * alternates advertising itself.
     *
     * `noindex_editorial`, NOT `noindex`, AND THAT IS THE WHOLE CARE HERE.
     * layouts/store.blade.php also sets noindex from
     * Indexability::isPrivate() — the cart, the checkout, the account area, the
     * wishlist and order tracking. Those are per-visitor pages excluded for a
     * reason that has nothing to do with what document they are, and the
     * bilingual foundation emits their alternates deliberately so an Arabic
     * shopper's wishlist links to the English one. Only an editorial "do not
     * index this document" retracts the cluster, and the layout is what knows
     * which kind its noindex is.
     *
     * A caller that renders its own <head> — /skin-quiz, the Journal, an
     * article, /app — sets only `noindex`, and for those it is always the
     * editorial kind, which is why that is the fallback.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<string>
     */
    private static function alternateLinks(?string $url, string $base, array $ctx = []): array
    {
        if (! empty($ctx['noindex_editorial'] ?? ($ctx['noindex'] ?? false))) {
            return [];
        }

        $split = self::splitOwnUrl($url, $base);

        if ($split === null) {
            return [];
        }

        [$prefix, $path, $suffix] = $split;

        $alternates = Locale::alternatePaths($path);

        if ($alternates === []) {
            return [];
        }

        $root = rtrim($base, '/');
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        /*
         * THE SAME TRAILING SLASH THE CANONICAL CARRIES.
         *
         * Every storefront route in this shop is declared with one and the
         * canonical puts it back whether or not the request had it. An hreflang
         * naming /ar/my-wishlist while that page canonicalises to
         * /ar/my-wishlist/ is an hreflang pointing at a redirect, and the
         * cluster is dropped rather than half-honoured.
         */
        $href = static function (string $localePath) use ($root, $prefix, $suffix): string {
            if ($localePath !== '/' && ! str_ends_with($localePath, '/')) {
                $localePath .= '/';
            }

            return $root . $prefix . $localePath . $suffix;
        };

        $out = [];

        foreach ($alternates as $code => $localePath) {
            $out[] = '<link rel="alternate" hreflang="' . $e($code) . '" href="' . $e($href($localePath)) . '">';
        }

        $default = $alternates[Locale::DEFAULT] ?? null;

        if ($default !== null) {
            $out[] = '<link rel="alternate" hreflang="x-default" href="' . $e($href($default)) . '">';
        }

        return $out;
    }

    /**
     * Split an absolute URL of OUR OWN into [deployment prefix, path, suffix].
     *
     * Returns null for anything that is not under $base — another host, a
     * protocol-relative CDN URL, a syndication canonical. Nothing in this file
     * may put a locale segment on one of those.
     *
     * The deployment prefix is separated from the path because the two compose
     * in exactly one order: KBB_BASE_PATH is where the application is MOUNTED
     * and the locale is a fact about the PAGE, so it is /kbb-upgrade/ar/shop/
     * and never /ar/kbb-upgrade/shop/. site_url normally already carries the
     * base path (APP_URL does on the production host), in which case it is part
     * of $base and $prefix comes back ''; when it does not, Url::base() finds
     * it on the front of the path and it is held aside here. Both spellings
     * therefore produce the same address, which is the point — the shop has
     * shipped with site_url written both ways.
     *
     * The suffix is the query string and fragment, kept out of the way of the
     * path surgery and put back untouched. /new-in/?page=2 has an Arabic twin
     * and it is /ar/new-in/?page=2, not /ar/new-in/.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private static function splitOwnUrl(?string $url, string $base): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        $root = rtrim($base, '/');

        if ($root === '' || ! str_starts_with($url, $root)) {
            return null;
        }

        $rest = substr($url, strlen($root));

        if ($rest === '') {
            $rest = '/';
        }

        // https://kbeautybliss.com vs https://kbeautybliss.com.evil.test/ —
        // str_starts_with() alone would accept the second.
        if ($rest[0] !== '/') {
            return null;
        }

        $cut = strcspn($rest, '?#');
        $path = substr($rest, 0, $cut);
        $suffix = substr($rest, $cut);

        $prefix = '';
        $basePath = Url::base();

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $prefix = $basePath;
            $path = substr($path, strlen($basePath));
            $path = $path === '' ? '/' : $path;
        }

        return [$prefix, $path, $suffix];
    }

    /**
     * Put the current language's segment onto one of our own absolute URLs.
     *
     * Locale::withSegment() is idempotent and returns the path untouched for a
     * path that may not carry a prefix at all (/wp-content/…, the admin, any
     * segment with a dot in it), so this is safe to apply to every URL this
     * class emits rather than to a list of the ones that need it.
     *
     * In English — and that is every request this shop serves until the owner
     * turns Arabic on — Locale::segment() is '' and this returns its argument
     * unchanged, before any path is split. The English shop is byte-for-byte
     * the shop it is today.
     */
    private static function localise(?string $url, string $base): ?string
    {
        if ($url === null || Locale::segment() === '') {
            return $url;
        }

        $split = self::splitOwnUrl($url, $base);

        if ($split === null) {
            return $url;
        }

        [$prefix, $path, $suffix] = $split;

        return rtrim($base, '/') . $prefix . Locale::withSegment($path) . $suffix;
    }

    private static function canonicalAbsolute(?string $url, string $base): ?string
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
                /*
                 * THE SITELINKS SEARCHBOX HAS TO SEARCH THIS SHOP.
                 *
                 * This advertised `/shop?q={search_term_string}`, and nothing
                 * in this application has ever read `q`. The storefront's own
                 * search box is `<form method="get" action="/shop/">` with
                 * `<input name="s">` (partials/header.blade.php) and
                 * ShopController reads `$request->query('s')` in all four
                 * places it asks. So a visitor who typed into the search box
                 * Google renders under the shop's own result was sent to a URL
                 * that ignores what they typed and serves the entire catalogue
                 * — the search term silently dropped between Google and the
                 * shelf.
                 *
                 * The page wins: the parameter here is the one the page
                 * actually filters on, and the path is the slashed form /shop/
                 * canonicalises to, for the same reason the sitemap's entry
                 * carries the slash — advertising a URL the site then
                 * redirects is the defect, not a tidy-up.
                 */
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base . '/shop/?s={search_term_string}'],
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

                /*
                 * IS THE PUBLISHED PRICE THE PRICE THE BUYER PAYS?
                 *
                 * `price` above is the bare shelf price — the same figure the
                 * product page prints, which is why it was right to publish it
                 * and why it stays. What was missing is the one thing that
                 * makes the figure mean something: whether tax is already
                 * inside it. Under an EXCLUSIVE rule it is not. The shopper
                 * chooses this shop out of a result showing AED 100, reaches
                 * the checkout and is charged AED 105, and neither the result
                 * nor the markup said so anywhere.
                 *
                 * `valueAddedTaxIncluded` on a UnitPriceSpecification is the
                 * field schema.org and Google provide for exactly this, so no
                 * new number has to be invented and, deliberately, none is:
                 * the price and currency here are the SAME two values the
                 * Offer already carries, so the two cannot disagree — the
                 * og:product block above is built on the same rule.
                 *
                 * NO TAX ARITHMETIC HAPPENS HERE. App\Support\TaxRule is the
                 * single authority on what a rate does and this only asks it
                 * the one question it already answers for every money path,
                 * addsToTotal(). See vatIncludedSitewide() for why the answer
                 * can be absent.
                 */
                $vatIncluded = self::vatIncludedSitewide();

                if ($vatIncluded !== null) {
                    $offer['priceSpecification'] = [
                        '@type' => 'UnitPriceSpecification',
                        'price' => $priceString,
                        'priceCurrency' => $currency,
                        'valueAddedTaxIncluded' => $vatIncluded,
                    ];
                }

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
     * Does the published price already contain every tax the buyer will pay,
     * for EVERY destination this shop serves — or is there no single answer?
     *
     * A STRUCTURED-DATA OFFER IS ONE DOCUMENT PER URL, exactly as a meta
     * description is, and the same rule follows from it: a claim that is true
     * of one country and false of another cannot be published sitewide. That
     * rule is why Store\ShopController::seoDescription() carries no delivery
     * window and why the description this lane rewrote carries none either.
     * Tax is the same shape of question — App\Support\VatDisplay resolves a
     * rule PER COUNTRY, and the price beside it is one number.
     *
     * So this answers with a boolean only when the answer is the same
     * everywhere, and with null — the field is then not emitted at all — when
     * the shop's own configuration gives two answers. Absent is a missing
     * recommended field; a wrong one is a price in a search result that the
     * checkout then exceeds, which is the failure Google penalises and the
     * shopper actually feels.
     *
     *   VAT switched off      null. There is no tax to be inside or outside
     *                         the price, and saying "tax is included" about a
     *                         tax that does not exist is not an improvement.
     *   tax_mode = display    true, the shipped default. Nothing is added to
     *                         any total in this mode — the line is printed and
     *                         charged to nobody — so the shelf price IS the
     *                         price paid, wherever the shopper is.
     *   tax_mode = live       the destinations are asked. Every rule that does
     *                         not add to the total (inclusive, flat) says the
     *                         price is final; `exclusive` says it is not. One
     *                         answer shared by the default rule and every
     *                         per-country rule is published; a disagreement is
     *                         null.
     *
     * Read-only throughout: no rate is computed and no basis is chosen here.
     */
    private static function vatIncludedSitewide(): ?bool
    {
        $vat = app(\App\Support\VatDisplay::class);

        if (! $vat->enabled()) {
            return null;
        }

        if (! $vat->live()) {
            return true;
        }

        $answers = [! $vat->defaultRule()->addsToTotal()];

        foreach (array_keys($vat->countryRates()) as $country) {
            $answers[] = ! $vat->ruleFor($country)->addsToTotal();
        }

        $answers = array_unique($answers, SORT_REGULAR);

        return count($answers) === 1 ? (bool) reset($answers) : null;
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
