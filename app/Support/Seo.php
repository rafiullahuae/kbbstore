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
     * The @type a row in a CollectionPage's ItemList is allowed to name.
     *
     * An allowlist and not a passthrough. The value is written straight into a
     * JSON-LD document that search engines read as this shop's own claim about
     * itself, and this repository's standing rule for anything reaching a
     * public document is a named list rather than a filter -- Product::toApi()
     * and SettingController::PUBLIC_KEYS are the same decision on the API side.
     *
     * `Product` is every product listing on the shop. `Brand` is the A-Z
     * directory at /korean-skincare-brands/, which lists brands.
     *
     * @var list<string>
     */
    public const COLLECTION_ITEM_TYPES = ['Product', 'Brand'];

    /**
     * What the shop is willing to SAY about returns, as schema.org names it.
     *
     * ══════════════════════════════════════════════════════════════════════
     * "AT THE MOMENT WE DON'T OFFER RETURNS" WAS NOT EXPRESSIBLE
     * ══════════════════════════════════════════════════════════════════════
     *
     * The merchant block below gated the whole `MerchantReturnPolicy` on
     * `merchant_return_days > 0` and could emit exactly one category,
     * `MerchantReturnFiniteReturnWindow`. Measured on a rendered product page
     * with `enable_merchant` on and the days box at 0 — and again at blank,
     * which `AdminController::SETTING_RULES` documents as the same value:
     *
     *     shippingDetails            published
     *     hasMerchantReturnPolicy    ABSENT
     *
     * So a shop that takes no returns published its shipping terms and said
     * nothing whatever about returns, and "we do not accept returns" and "we
     * have not told you" left the same markup. They are different statements:
     * schema.org has `MerchantReturnNotPermitted` for the first one, and a
     * stated policy is worth more in a merchant listing than an absent one.
     *
     * ── WHY A CATEGORY AND NOT A SENTINEL IN THE DAYS BOX ───────────────
     *
     * "Do you take returns" and "for how many days" are two different
     * questions, and one integer was carrying both. A `-1` would have made the
     * bound on that box (`[0, 3650]`) a lie and put the answer to a yes/no
     * question inside a number the owner reads as a window.
     *
     * ── BLANK IS "NOT STATED", AND IT IS TODAY'S BEHAVIOUR EXACTLY ──────────
     *
     * The absent row, the empty string and any value not on this list all mean
     * not stated, and not stated is the shipped default: the days box alone
     * decides, exactly as it did before this constant existed. Applying the
     * package moves no markup on any shop. That is the same rule
     * `BusinessAddress` follows for the postal address, and it matters more
     * here than usual — publishing "no returns" for a shop that has said
     * nothing would be the identical error to the hardcoded `FreeReturn` this
     * block already had to take back out.
     *
     * ── THE TWO THAT ARE HERE, AND THE TWO THAT ARE NOT ─────────────────
     *
     * schema.org defines four categories. These are the two this shop can
     * truthfully choose between from the settings it has. `MerchantReturn
     * UnlimitedWindow` is a promise no line in this application supports, and
     * `MerchantReturnUnspecifiedReturnWindow` is a returns policy with no terms
     * in it — Google asks for a `merchantReturnLink` alongside it, which is a
     * page this shop does not have. Neither is invented here; a later round
     * with a policy page behind it can add one.
     *
     * @var list<string>
     */
    public const RETURN_CATEGORIES = [
        'MerchantReturnNotPermitted',
        'MerchantReturnFiniteReturnWindow',
    ];

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
        /*
         * A private install overrides the page's own answer, and overrides the
         * shop's robots_index setting, because on a staging copy that setting
         * is whatever production's was. The header CanonicalHost sets is the
         * instruction that covers images and XML too; this is the same
         * instruction where a human reading View Source will find it.
         */
        $robots = (!empty($ctx['noindex']) || \App\Support\SiteHost::isPrivate())
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
            /*
             * A CANDIDATE home title, for the live preview above the box.
             *
             * Lane S7 needed to show what Google would print for a title the
             * owner is typing and has not saved, and this branch read
             * seo_home_title out of the settings table first -- so the preview
             * could only ever show the SAVED value. It worked around that by
             * reaching the `title_is_final` arm through `title_token`, which is
             * two paragraphs of justification where one key will do.
             *
             * INERT EVERYWHERE TODAY: array_key_exists, not a truthiness test,
             * so only a caller that deliberately passes the key takes this path,
             * and nothing on the storefront passes it. An empty string passed
             * deliberately still means "no title", which falls through to the
             * same place it always did.
             */
            $homeTitle = array_key_exists('home_title', $ctx)
                ? trim((string) $ctx['home_title'])
                : SeoSettings::from($s, 'seo_home_title', '');
            $title = TitleTemplate::render(
                $homeTitle !== '' ? $homeTitle : ($rawTitle !== '' ? $rawTitle : $siteName),
                $tokens,
                $sep
            );
        } elseif (!empty($ctx['title_is_final']) && $rawTitle !== '') {
            /*
             * `%%title%%` INSIDE A FINAL TITLE, which is the one token this
             * branch could not resolve and the one every real Yoast export
             * carries.
             *
             * Yoast's default product title template is
             * `%%title%% %%sep%% %%sitename%%`, so it is what most of an
             * imported catalogue holds in `products.seo.title`. This branch
             * used to pass `sep`, `sitename` and `page` and NOT `title` —
             * and TitleTemplate::render() deletes a token it is not given.
             * The default template therefore rendered as the site name alone:
             * every imported product published `<title>K-Beauty Bliss</title>`,
             * the same six words on all 671 pages, while the import reported
             * every row successfully imported. It is silent on the shop
             * because the page itself renders perfectly.
             *
             * It could not simply be added to the array either. In this branch
             * $rawTitle IS the template, so `'title' => $rawTitle` substitutes
             * the template into itself. `%%title%%` in Yoast means the POST
             * title — here the product's own name — which is a different
             * string from the SEO title being rendered, and only the caller
             * knows it. So the caller supplies it under `title_token`.
             *
             * ABSENT, THE BEHAVIOUR IS EXACTLY WHAT IT WAS: the token is
             * deleted. App\Support\ProductSeo::metaTitle() passes the same
             * value the product page does, because the editor's preview
             * promises to show the bytes the page will publish.
             *
             * FOUR CALLERS PASS IT, one per overridable thing, and each names
             * the row `%%title%%` stands for on its own page:
             *
             *   Store\ProductController::show()   the product's name
             *   Store\BrandController::seoCtx()   the brand's name
             *   Store\ShopController::index()     the category's name
             *   Store\PageController::post()      the article's headline
             *
             * The other three were added after the product fix, on the same
             * argument and against the same branch. Nothing IMPORTS a Yoast
             * template into `brands.seo`, `categories.seo` or `posts.seo` — the
             * only way one gets there is an operator typing it into the SEO box
             * on that screen, which is precisely why it had to be fixed: a box
             * that silently deletes what you type into it is worse than a box
             * that refuses it. Every one of those four sets the key ONLY
             * alongside `title_is_final`, so a thing with an empty SEO title
             * cannot move.
             */
            $finalTokens = ['sep' => $sep, 'sitename' => $siteName, 'page' => ''];

            $titleToken = trim((string) ($ctx['title_token'] ?? ''));

            if ($titleToken !== '') {
                $finalTokens['title'] = $titleToken;
            }

            $title = TitleTemplate::render($rawTitle, $finalTokens, $sep);
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

        $alternates = self::readerAlternatePaths($path);

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
    /**
     * Every language's address for this page, each spelled as that language's
     * readers see it.
     *
     * ── WHY Locale::alternatePaths() ALONE IS NOT ENOUGH ONCE SLUGS DIFFER ──
     *
     * `Locale::alternatePaths()` takes a PATH and no row, and derives every
     * language's address by swapping the prefix on that one path. While the shop
     * has one slug per row that is exactly right and every cluster is reciprocal
     * — ArabicSlugPolicyTest pins it, SeoBilingualTest measures it — and it is
     * what this method returns, byte for byte, in the shipped `shared` policy.
     *
     * The moment a row carries a second slug it is not enough, and the failure
     * is the expensive kind: the English page would advertise `hreflang="ar"` at
     * the ENGLISH slug under /ar, and the Arabic page would advertise
     * `hreflang="en"` at the ARABIC slug. Google requires an hreflang cluster to
     * be reciprocal and drops the whole cluster when it is not, so both
     * languages would lose their alternate at once — on every page of the shop,
     * and in the sitemap, which says the same thing.
     *
     * So the translation is applied PER LANGUAGE rather than to the path: each
     * code gets the address a reader of that language would be served, from
     * LocaleSlugs, with its own segment on top. English is untouched because
     * toDisplayPath() answers null for the default locale.
     *
     * PUBLIC, AND THAT IS A REQUEST TO ONE OTHER FILE. `SeoFilesController` builds
     * the sitemap's `<xhtml:link>` alternates and llms.txt's language links from
     * `Locale::alternatePaths()` directly rather than through this class, so with
     * the translated policy on the SITEMAP would advertise the shared address
     * under /ar while the PAGE canonicalises to the Arabic one — an hreflang
     * cluster that disagrees with itself, which Google resolves by dropping it.
     * That file belongs to another lane, so the fix is exposed here rather than
     * made there: swapping `Locale::alternatePaths($path)` for
     * `Seo::readerAlternatePaths($path)` at its two call sites is the whole of it,
     * and it is byte-identical in the shipped `shared` policy. Until it is done,
     * the policy must not be switched on — docs/SEO-ARABIC-SLUGS.md §7.3.
     *
     * @return array<string, string> locale => path, base path NOT applied
     */
    public static function readerAlternatePaths(string $path): array
    {
        $alternates = Locale::alternatePaths($path);

        if ($alternates === [] || ! \App\Support\LocaleSlugs::translating()) {
            return $alternates;
        }

        /*
         * BACK TO THE CANONICAL SPELLING FIRST, AND THIS IS THE STEP THAT IS
         * EASY TO MISS.
         *
         * $path here is the page's own canonical, which localise() has ALREADY
         * put through displayPath() — so on an Arabic page it is the Arabic slug.
         * Translating that per language without undoing it first would hand the
         * English alternate an Arabic slug, which is the same broken cluster one
         * layer along. The locale is taken from the path rather than from
         * Locale::current() for the same reason: this method is also reached for
         * a page whose canonical points somewhere other than the request.
         */
        [$pathLocale, $bare] = Locale::splitPath($path);
        $pathLocale = $pathLocale ?? Locale::DEFAULT;
        $bare = \App\Support\LocaleSlugs::toCanonicalPath($bare, $pathLocale) ?? $bare;

        $out = [];

        foreach (array_keys($alternates) as $code) {
            $out[$code] = Locale::withSegment(self::displayPath($bare, $code), $code);
        }

        return $out;
    }

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

        /*
         * THE LOCALE SEGMENT COMES OFF BEFORE THE SLUG IS TRANSLATED.
         *
         * $path here may already carry /ar — every caller builds its canonical
         * through Url::to(), which adds it. displayPath() matches on the address
         * shape ('/product/…'), so handing it '/ar/product/…' matches nothing and
         * the Arabic page would declare the SHARED slug canonical while serving
         * the translated one: a page telling Google to index a different page,
         * which is worse than either consistent answer. withSegment() is
         * idempotent, so splitting first and putting it back is byte-identical in
         * the shared policy.
         */
        [, $bare] = Locale::splitPath($path);

        return rtrim($base, '/') . $prefix . Locale::withSegment(self::displayPath($bare)) . $suffix;
    }

    /**
     * The path as a READER of this language sees it.
     *
     * Identity while `seo_arabic_slugs` says `shared`, which is the shipped
     * value and today's behaviour — so this method changes no canonical and no
     * hreflang on any shop until somebody switches the policy and fills in an
     * Arabic address.
     *
     * With the policy on it is the other half of `ResolveLocaleSlugs`: that
     * class turns the address a reader typed into the one the router serves, and
     * this turns the one the router served back into the address the reader
     * should see. Both halves are needed or the shop would answer at the Arabic
     * address and declare the English one canonical, which is a page telling
     * Google to index a different page — the worst of the three states.
     *
     * It is HERE, in localise(), because localise() is the one place a locale
     * segment reaches a canonical, and canonical() is what alternateLinks()
     * reads. One choke point, so the canonical and its own self-referencing
     * hreflang cannot disagree about what page this is.
     */
    private static function displayPath(string $path, ?string $locale = null): string
    {
        return \App\Support\LocaleSlugs::toDisplayPath($path, $locale ?? Locale::current()) ?? $path;
    }

    private static function canonicalAbsolute(?string $url, string $base): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return $base !== '' ? $base . '/' : null;
        }

        $basePath = $base === '' ? '' : rtrim((string) parse_url($base, PHP_URL_PATH), '/');

        /*
         * A scheme this shop will not publish. The per-row canonical override is
         * a setting-shaped value that becomes an href, an og:url and a node
         * identifier, so the scheme is checked here rather than trusted -- the
         * same rule SeoAudit::canonicalIsSafe() already applies when it REPORTS
         * one. Anything that is not http, https or protocol-relative falls
         * through to the path branch below, which produces an address on this
         * site instead of the operator's string.
         *
         * Integrator, from Lane S6 docs/SEO-ROUND-5-VERIFICATION.md section 5.2.
         * Narrow the regex back and a product whose canonical override reads
         * javascript:alert(1) publishes that string in <link rel=canonical>,
         * og:url, Product.url and every hreflang href.
         */
        if (preg_match('#^(//|https?://)#i', $url) === 1) {
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

        /*
         * WHERE THE SHOP IS -- address, telephone, and on a Place type the
         * coordinates and the opening hours.
         *
         * MERGED INTO THE ORGANIZATION NODE, NOT EMITTED AS A SECOND ONE.
         * The obvious shape -- a `LocalBusiness` node beside the `Organization`
         * node -- would put two nodes on every page claiming to be the same
         * business, which is the duplicate-schema defect docs/SEO-COMPETITIVE.md
         * identifies as the classic Shopify review-app failure. It is also
         * simply wrong: `Store` and `LocalBusiness` are SUBTYPES of
         * Organization, so the address belongs on the node that already names
         * the business, whose @type the owner has already chosen.
         *
         * App\Support\BusinessAddress decides what may be said -- see its
         * class comment for why a half-filled address is worse than none, and
         * why `geo` and `openingHoursSpecification` are gated on the type being
         * a Place. A shop that has filled none of it in gets exactly the node
         * it got before this existed, which is rule 1 of this project.
         *
         * The values are settings, so they are the same XSS vector `org_name`
         * is, and they are safe by the same mechanism: encodeJsonLd()'s HEX
         * flags, pinned by SeoRenderTest. Nothing here is printed unescaped.
         */
        $org += BusinessAddress::organizationFragment($s, (string) $org['@type']);

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
                /*
                 * THE LANGUAGES THIS SITE IS PUBLISHED IN, not the language of
                 * the page the node happens to be sitting on.
                 *
                 * `WebSite` describes the SITE, and this shop is one site in
                 * two languages — so a node that said "ar" on an Arabic page
                 * and "en" on an English one would be two contradictory claims
                 * about one thing, made from different pages, with the same
                 * `url`. The document's own language is stated on the node that
                 * describes the DOCUMENT (Article and CollectionPage below) and
                 * in `<html lang>`, which is where a consumer looks for it.
                 *
                 * A bare string while only one language is live, an array when
                 * both are — both are valid, and the single-language shop must
                 * not gain an array it did not have. Locale::enabledCodes()
                 * returns ['en'] until the owner switches Arabic on, so this is
                 * `"inLanguage":"en"` on the shop as it stands today.
                 */
                'inLanguage' => count($live = Locale::enabledCodes()) === 1 ? $live[0] : $live,
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    /*
                     * THE READER'S OWN SHOP, AND THIS IS THE ONE THING IN THIS
                     * NODE THAT FOLLOWS THE PAGE RATHER THAN THE SITE.
                     *
                     * This was `$base . '/shop/?s='` — the English shop, on
                     * every page in both languages. Google renders the sitelinks
                     * searchbox under the result for the page it crawled, so an
                     * Arabic reader who found the Arabic page and typed into
                     * that box was sent to the English catalogue. Measured
                     * before this change: the urlTemplate on /ar/shop/ was
                     * byte-for-byte the one on /shop/.
                     *
                     * A search action is something a PERSON does from THIS
                     * page, which is why it is localised where `url` and
                     * `inLanguage` above are not. Locale::withSegment() adds
                     * the segment and adds nothing in English, so this is
                     * unchanged on the shop as it stands today.
                     */
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $base . Locale::withSegment('/shop/') . '?s={search_term_string}',
                    ],
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

            /*
             * GTIN -- THE IDENTIFIER THE PLAN RECORDED AS BLOCKED.
             *
             * Phase 12 closed the structured-data item with "Still open: GTIN
             * and variant-level offers -- genuinely blocked, no GTIN/barcode
             * column exists anywhere in the schema". That was true when it was
             * written and is not true now: `products.gtin` was added by
             * 2026_10_05_000000_add_product_editor_columns, the product editor
             * collects it, and ProductEditorApiController refuses a value
             * App\Support\Gtin::isValid() rejects.
             *
             * VALIDATED AGAIN HERE, which is not the same check twice over.
             * The admin form is one of three ways a value reaches this column
             * -- the other two are the WooCommerce import and a hand-edited
             * row -- and neither of those passes through that controller. A
             * GTIN is the field Google MATCHES PRODUCTS ON: a wrong one does
             * not degrade the listing, it attaches this shop's price and stock
             * to somebody else's product. The check digit exists precisely to
             * catch the mistyped and transposed digits a human makes copying
             * fourteen numbers off a box, so a value that fails it is not
             * published at all. Absent is a missing recommended field; wrong is
             * a misattributed product.
             *
             * `gtin`, not `gtin13`. Google's current guidance is the
             * length-agnostic property, which lets one field carry an EAN-13, a
             * UPC-A and an ITF-14 without the writer having to classify the
             * number -- and Gtin::normalise() has already established which of
             * the four lengths it is by accepting it at all.
             */
            $gtin = Gtin::normalise(is_string($p['gtin'] ?? null) ? $p['gtin'] : null);

            if ($gtin !== null && Gtin::isValid($gtin)) {
                $node['gtin'] = $gtin;
            }
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

                    /*
                     * ═══════════════════════════════════════════════════════
                     * SHIPPING AND RETURNS ARE TWO ANSWERS, NOT ONE — Lane S8
                     * ═══════════════════════════════════════════════════════
                     *
                     * THE DEFECT, and it was a promise Google shows a shopper.
                     * These two blocks used to be one: turning `enable_merchant`
                     * on published `shippingDetails` unconditionally, with the
                     * rate read as `Money::fromMajor($s['merchant_ship_cost'] ??
                     * 0)`. `merchant_ship_cost` ships blank, SeoSettings::map()
                     * drops blanks, so `?? 0` fired and every product page went
                     * out saying:
                     *
                     *     "shippingRate":{"value":"0.00","currency":"AED"}
                     *
                     * — free delivery to the whole UAE, on a shop that has never
                     * stated a delivery rate. Driven and read back off a real
                     * request before this was changed, not reasoned about.
                     *
                     * AND THE OWNER WAS ABOUT TO WALK STRAIGHT INTO IT. He has
                     * told us "at the moment we don't offer returns", which is a
                     * fact this application can publish (Lane S5 built the
                     * category for it) and the ONLY way to publish it was to
                     * turn this flag on. So the one switch that let him state
                     * the thing he knows also stated a thing he does not: his
                     * shipping terms are genuinely unknown and nobody may guess
                     * them.
                     *
                     * THE FIX IS THE DISCRIMINATOR THAT WAS ALREADY THERE.
                     * SeoSettings::map() drops a BLANK and keeps a '0' -- its own
                     * docblock says so and names merchant_ship_cost as the
                     * example. So "the owner typed 0 because delivery is free"
                     * and "nobody has said" are distinguishable, and the `?? 0`
                     * was throwing that distinction away. A stated 0 still
                     * publishes free shipping, which is a real and useful answer;
                     * an unstated rate publishes no shippingDetails at all.
                     *
                     * A MISSING shippingDetails COSTS A WARNING. A WRONG ONE
                     * COSTS THE SHOPPER. Google treats absent shipping as not
                     * provided and may show its own estimate or nothing; it
                     * treats a stated 0.00 as a commitment and prints it. Same
                     * asymmetry as `priceValidUntil` above -- a missing
                     * recommended field costs a warning, a stale one costs the
                     * result.
                     */
                    $shipping = self::shippingDetails($s, $p, $country, $currency);

                    if ($shipping !== null) {
                        $offer['shippingDetails'] = $shipping;
                    }

                    $policy = self::returnPolicy($s, $country);

                    if ($policy !== null) {
                        $offer['hasMerchantReturnPolicy'] = $policy;
                    }
                }

                /*
                 * VARIANT-LEVEL OFFERS -- the other half of the plan's
                 * "genuinely blocked" line, and it was never blocked at all.
                 *
                 * `product_variants` has carried `price`, `sale_price`,
                 * `stock_status` and `sku` since the ORIGINAL schema migration.
                 * The blocker recorded in Phase 12 is the GTIN one; variant
                 * offers were listed beside it and inherited the same verdict
                 * without the schema being checked a second time.
                 *
                 * What the page actually shows is the test that matters here,
                 * and store/product.blade.php shows a price PER OPTION: every
                 * `.variant` row prints its own `Money::format($vsale)`, struck
                 * through against its own regular price. The single Offer this
                 * block builds publishes the PARENT row's price, so a product
                 * whose 30ml is 89 and whose 100ml is 210 told Google it costs
                 * one number while showing a shopper two. A range is what the
                 * page states, so a range is what the document should state.
                 */
                $node['offers'] = self::aggregateOffer($p, $offer, $currency) ?? $offer;
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
                'url' => $url,
                'datePublished' => $a['published_at'] ?? null,
                'dateModified' => $a['updated_at'] ?? null,
                /*
                 * THE LANGUAGE THIS DOCUMENT IS WRITTEN IN.
                 *
                 * An Article IS a CreativeWork, so `inLanguage` is a property
                 * it really has — see the note on Product below for the nodes
                 * that do NOT get one. It matches `<html lang>` exactly rather
                 * than being spelled out as a regional tag: inventing `ar-AE`
                 * would be a guess about an audience nothing in this shop
                 * records, and two different answers to "what language is this
                 * page" in one document is worse than a less precise one.
                 */
                'inLanguage' => Locale::current(),
                'author' => ['@type' => 'Organization', 'name' => $siteName],
                'publisher' => ['@type' => 'Organization', 'name' => $siteName],
            ]);
        }


        /*
         * CollectionPage + ItemList — category archives, brand landing pages
         * and the four curated listings.
         *
         * These pages published `@type` nothing at all: the sitewide
         * Organization and WebSite nodes, a BreadcrumbList, and that was the
         * whole document. A listing that says nothing about itself is read as
         * an ordinary web page, so the highest-intent surface on the shop --
         * the one "korean sunscreen uae" lands on -- had no machine-readable
         * statement that it is a list of products, and no statement of WHICH
         * products.
         *
         * ── WHY THE LIST IS THIS PAGE'S ROWS AND NOT THE CATEGORY'S ─────────
         *
         * `position` is the item's place in the WHOLE listing, not its place on
         * this page: page two of Serums carries positions 25..48. Restarting at
         * 1 on every page would state that page two is the same list as page
         * one, which is the thing the self-referencing canonical on a paginated
         * archive exists to deny -- and denying it in the <head> while
         * asserting it in the JSON-LD is worse than doing neither. The caller
         * passes `offset`, which is the count of rows before the first one on
         * this page; ShopController and CollectionController both already know
         * it because both already compute the page window.
         *
         * `numberOfItems` is the number of entries in THIS list, which is what
         * schema.org's definition says it is -- the count of itemListElement --
         * and not the category's total. The total is what `url` plus the
         * breadcrumb already carry.
         *
         * ── WHY THE CALLER DECIDES WHETHER THERE IS A LIST AT ALL ──────────
         *
         * A filtered or sorted view of an archive canonicalises to the CLEAN
         * archive URL (Facets::canonicalUrl), which is a different document
         * from the one being rendered. Attaching this page's twenty-four rows
         * to that canonical would describe the wrong document -- the same class
         * of defect as a canonical naming the wrong address, which is the one
         * this project has already paid for twice. So `collection.items` is
         * passed only when the canonical is self-referencing; when it is not,
         * the CollectionPage node still stands (the canonical IS a collection
         * page) and carries no list.
         *
         * ── PRICES ──────────────────────────────────────────────────────────
         *
         * Through priceString(), which is the same integer-fils path the
         * Product node above uses. Nothing here touches a raw column: a 126 AED
         * serum whose `price` column holds 12600 publishes "126.00", and
         * CollectionSchemaTest mutates the builder to hand over the raw minor
         * units and watches this go red.
         */
        if (($ctx['type'] ?? '') === 'collection') {
            $c = is_array($ctx['collection'] ?? null) ? $ctx['collection'] : [];

            /*
             * `name` is the LISTING's name -- "Serums", "Best Sellers", the
             * brand -- and falls back to the page title. The two differ by the
             * site name, which seo_title_template appends to every <title> on
             * the site: a CollectionPage called "Serums · K-Beauty Bliss" names
             * the shop twice in one node, once here and once in the
             * Organization beside it. Article resolves the same fork the same
             * way ($a['title'] ?? $title).
             */
            $name = isset($c['name']) && trim((string) $c['name']) !== ''
                ? trim((string) $c['name'])
                : $title;

            $node = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $name,
                'description' => $desc ?: null,
                'url' => $url,
                // A CollectionPage is a WebPage is a CreativeWork, and `url`
                // above is this page's own localised address — so the language
                // stated here is this document's, exactly as on Article.
                'inLanguage' => Locale::current(),
            ], static fn ($v) => $v !== null);

            $rows = is_array($c['items'] ?? null) ? array_values($c['items']) : [];

            if ($rows !== []) {
                $offset = max(0, (int) ($c['offset'] ?? 0));
                $elements = [];

                foreach ($rows as $i => $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $itemUrl = self::canonical($row['url'] ?? null, $base);

                    /*
                     * WHAT THE ROW IS. `Product` unless the caller names
                     * something else, because every caller but one lists
                     * products and a default of anything else would make the
                     * common case say the wrong thing loudly.
                     *
                     * The one exception is the A-Z brand directory
                     * (App\Support\BrandDirectorySchema), which lists BRANDS.
                     * Publishing ninety-three Brand landing pages as `Product`
                     * would put ninety-three products into Google's index that
                     * have no price, no availability and no SKU -- a product
                     * node missing every required field is not a weaker rich
                     * result, it is an invalid one, and it would be invalid
                     * ninety-three times on the shop's own brand index.
                     *
                     * Allowlisted rather than taken verbatim: this string is
                     * published into a JSON-LD document, and a row that reached
                     * here from anything less trusted than a builder in this
                     * namespace must not be able to name an arbitrary type.
                     */
                    $rowType = is_string($row['schema_type'] ?? null)
                        && in_array($row['schema_type'], self::COLLECTION_ITEM_TYPES, true)
                            ? $row['schema_type']
                            : 'Product';

                    $item = array_filter([
                        '@type' => $rowType,
                        'name' => isset($row['name']) ? (string) $row['name'] : null,
                        'url' => $itemUrl,
                        'image' => self::absolute($row['image'] ?? null, $base),
                        'sku' => isset($row['sku']) && (string) $row['sku'] !== '' ? (string) $row['sku'] : null,
                        // schema.org puts `logo` on Organization and on Brand,
                        // and nowhere else this branch emits. A product row
                        // never carries the key, so the guard is the key's
                        // absence rather than a type test.
                        'logo' => self::absolute($row['logo'] ?? null, $base),
                    ], static fn ($v) => $v !== null && $v !== '');

                    if (! empty($row['brand'])) {
                        $item['brand'] = ['@type' => 'Brand', 'name' => (string) $row['brand']];
                    }

                    /*
                     * NO OFFER ON A ROW THAT IS NOT A PRODUCT, and this is the
                     * half that is easy to leave out. priceString() reads
                     * `price`/`price_minor`, a brand row carries neither, and
                     * the result today would be null anyway -- so the guard
                     * looks redundant and is not. `schema.org/Brand` has no
                     * `offers` property at all, so the day any caller hands a
                     * non-product row something price-shaped, the absence of
                     * this test is what publishes an invalid node instead of
                     * ignoring the field.
                     */
                    $price = $rowType === 'Product' ? self::priceString($row) : null;

                    if ($price !== null) {
                        $item['offers'] = array_filter([
                            '@type' => 'Offer',
                            'price' => $price,
                            'priceCurrency' => SeoSettings::firstFilled($row['currency'] ?? null, 'AED'),
                            'availability' => self::availability($row),
                            'url' => $itemUrl,
                        ], static fn ($v) => $v !== null);
                    }

                    $elements[] = [
                        '@type' => 'ListItem',
                        'position' => $offset + $i + 1,
                        'item' => $item,
                    ];
                }

                if ($elements !== []) {
                    $node['mainEntity'] = [
                        '@type' => 'ItemList',
                        'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
                        'numberOfItems' => count($elements),
                        'itemListElement' => $elements,
                    ];
                }
            }

            $nodes[] = $node;
        }

        /*
         * FAQPage, from the questions a content page already answers.
         *
         * Off unless Store -> SEO & Meta -> "FAQ markup on content pages" is on,
         * and null unless the page is genuinely written as questions -- see
         * Services\Seo\FaqSchema for why the question mark is the whole
         * selection rule, and why no page slug is named anywhere. Merged into
         * THIS graph rather than emitted by a second class, for the reason
         * SEO-BUILD-PLAN item 3 refuses a SeoGraph: two emitters is two
         * Organization nodes on every page.
         */
        if (($ctx['type'] ?? '') === 'page'
            && \App\Services\Seo\FaqSchema::enabled($s)
            && ($faq = \App\Services\Seo\FaqSchema::node((string) ($ctx['page_body'] ?? ''), $url)) !== null) {
            $nodes[] = $faq;
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
     * A variable product's offers as an AggregateOffer, or null to keep the
     * single Offer the caller already built.
     *
     * ── WHEN IT RETURNS null, WHICH IS MOST OF THE TIME ────────────────────
     *
     * Fewer than two priced variants, or every variant priced the same. Both
     * are cases where the single Offer above is already the whole truth, and
     * an AggregateOffer with `lowPrice` equal to `highPrice` states a range
     * that is not one. A simple product therefore emits a document byte-
     * identical to the one it emitted before this method existed, which is the
     * property SeoVariantOfferTest pins first.
     *
     * ── THE ARITHMETIC IS DONE ON THE MINOR UNITS, AND ONLY THERE ──────────
     *
     * `price_minor` is the integer AED × 100; `price` is the decimal string
     * built from it. min() and max() run on the INTEGERS and the result is
     * formatted once by Money::decimalString().
     *
     * AND THE OBVIOUS REASON FOR THAT IS NOT TRUE, which is worth writing down
     * because the first draft of this method claimed it and a mutation proved
     * it wrong. Taking min() of the decimal STRINGS does not sort "12.00" below
     * "9.00": PHP compares two numeric strings numerically, so min(['9.00',
     * '12.00']) is '9.00' and the naive version is correct today. The mutation
     * that swaps the integers for the strings stays green, and is recorded in
     * ProductIdentifierAndVariantOfferTest as one that does not go red.
     *
     * The integers are still right, for a reason that survives being checked:
     * the string version is correct only while Money::decimalString() emits a
     * bare separator-free decimal AND PHP's numeric-string juggling holds. Give
     * the formatter a thousands separator -- min(['1,299.00', '890.00']) is
     * '1,299.00', because a string with a comma in it is not numeric and PHP
     * falls back to comparing it character by character -- and a product
     * ranging from 890 to 1,299 publishes a lowPrice ABOVE its highPrice.
     * Google reads highPrice < lowPrice as an invalid offer and drops the price
     * from the result entirely. Integer arithmetic depends on none of that.
     *
     * The mutation that IS red is formatting the range from the minor units
     * without Money::decimalString() -- `(string) min($minors)` publishes 8900
     * for an AED 89 option, which is the fils bug this project has already
     * shipped once and caught in 2.60.36.
     *
     * A variant with no `price_minor` aborts the whole aggregate rather than
     * being skipped: a range computed over some of the options is a range the
     * page does not show, and the single parent Offer is a true statement
     * where a partial range is not.
     *
     * ── WHERE THE MERCHANT FIELDS GO ───────────────────────────────────────
     *
     * The AggregateOffer keeps everything the single Offer carried EXCEPT the
     * price pair: itemCondition, priceValidUntil, shippingDetails and
     * hasMerchantReturnPolicy describe the shop's terms and are the same for
     * every option, so repeating them per variant would say the same thing ten
     * times in one document.
     *
     * `priceSpecification` is the exception and moves DOWN into each child
     * offer, because it is the only one of them that names a price. Left on
     * the aggregate it would carry the parent row's single figure beside a
     * lowPrice and a highPrice that disagree with it -- a VAT statement about
     * a price the document no longer claims.
     *
     * @param  array<string, mixed>  $p        the product context
     * @param  array<string, mixed>  $offer    the single Offer already built
     * @return array<string, mixed>|null
     */
    private static function aggregateOffer(array $p, array $offer, string $currency): ?array
    {
        $variants = is_array($p['variants'] ?? null) ? array_values($p['variants']) : [];

        if (count($variants) < 2) {
            return null;
        }

        $rows = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                return null;
            }

            $price = self::priceString($variant);
            $minor = self::priceMinor($variant);

            if ($price === null || $minor === null) {
                return null;
            }

            $rows[] = ['price' => $price, 'minor' => $minor, 'variant' => $variant];
        }

        $minors = array_column($rows, 'minor');

        // One price across every option is not a range. The parent Offer
        // already publishes that number and publishes it with less ceremony.
        if (count(array_unique($minors, SORT_NUMERIC)) < 2) {
            return null;
        }

        $vatIncluded = self::vatIncludedSitewide();
        $children = [];

        foreach ($rows as $row) {
            $child = array_filter([
                '@type' => 'Offer',
                'priceCurrency' => $currency,
                'price' => $row['price'],
                // The OPTION's own stock status, which is the whole reason a
                // per-variant offer is worth publishing: the page tags a
                // sold-out size "Sold out" and greys it, and a document that
                // says every size is in stock contradicts what is on screen.
                'availability' => self::availability($row['variant']),
                'sku' => isset($row['variant']['sku']) && (string) $row['variant']['sku'] !== ''
                    ? (string) $row['variant']['sku']
                    : null,
                // Every option is bought on the one product page; there is no
                // per-variant URL on this storefront, so naming the product's
                // is the honest answer rather than inventing a fragment.
                'url' => $offer['url'] ?? null,
                'itemCondition' => $offer['itemCondition'] ?? null,
            ], static fn ($v) => $v !== null);

            if ($vatIncluded !== null) {
                $child['priceSpecification'] = [
                    '@type' => 'UnitPriceSpecification',
                    'price' => $row['price'],
                    'priceCurrency' => $currency,
                    'valueAddedTaxIncluded' => $vatIncluded,
                ];
            }

            $children[] = $child;
        }

        $aggregate = $offer;

        // The parent's single figure and its VAT statement are what the range
        // replaces. unset() rather than rebuilding the array from scratch, so
        // a merchant field added to $offer later is carried here without this
        // method having to be remembered.
        unset($aggregate['price'], $aggregate['priceSpecification']);

        $aggregate['@type'] = 'AggregateOffer';
        $aggregate['lowPrice'] = Money::decimalString(min($minors));
        $aggregate['highPrice'] = Money::decimalString(max($minors));
        $aggregate['offerCount'] = count($children);
        $aggregate['offers'] = $children;

        return $aggregate;
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

    /**
     * `OfferShippingDetails`, or null when nobody has stated a delivery rate.
     *
     * ── THE ONE QUESTION THIS ASKS ──────────────────────────────────────
     *
     * Has an admin ENTERED a shipping cost? Not "is the cost zero" -- zero is a
     * legitimate, useful answer and it publishes. The question is whether the
     * value exists at all, and `SeoSettings::map()` answers it: it removes every
     * blank value and keeps '0', which is documented in its own header with
     * `merchant_ship_cost` named as the example. So `array_key_exists()` on the
     * cleaned map is the exact test, and it is the one the old code skipped by
     * writing `?? 0`.
     *
     * `merchant_ship_free_over` is deliberately NOT part of the test. Its
     * documented meaning is "0 means delivery is never free", so blank and 0 say
     * the same thing there and neither is a promise on its own -- it only ever
     * reduces a rate the owner has already stated.
     *
     * The conversion to minor units and the threshold comparison are unchanged
     * and stay integer work throughout: the old line compared two floats and
     * then cast the cost straight to a string, which published "0" for free
     * shipping and "12.5" for AED 12.50.
     *
     * @param  array<string, mixed>  $s  the settings map, blanks already dropped
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>|null
     */
    private static function shippingDetails(array $s, array $p, string $country, string $currency): ?array
    {
        if (! array_key_exists('merchant_ship_cost', $s) || trim((string) $s['merchant_ship_cost']) === '') {
            return null;
        }

        $shipMinor = Money::fromMajor($s['merchant_ship_cost']);
        $freeOverMinor = Money::fromMajor($s['merchant_ship_free_over'] ?? 0);
        $priceMinor = self::priceMinor($p);

        if ($freeOverMinor > 0 && $priceMinor !== null && $priceMinor >= $freeOverMinor) {
            $shipMinor = 0;
        }

        return [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => Money::decimalString($shipMinor),
                'currency' => $currency,
            ],
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
        ];
    }

    /**
     * The `hasMerchantReturnPolicy` node, or null when the shop has stated
     * nothing this application is willing to publish on its behalf.
     *
     * ── THE THREE ANSWERS, AND WHICH SETTING CARRIES EACH ───────────────
     *
     *   NOT STATED — `merchant_returns` blank, absent, or not one of
     *   RETURN_CATEGORIES. The days box alone decides, which is byte for byte
     *   what this block did before the select existed: a window above zero
     *   publishes a finite window, and zero or blank publishes nothing. Every
     *   shop that has not touched the new control is in this branch.
     *
     *   NO RETURNS — `MerchantReturnNotPermitted`. The days box is not read at
     *   all, because a window is not a thing a shop that refuses returns has;
     *   emitting `merchantReturnDays` beside this category would be markup that
     *   contradicts itself. `returnMethod` and `returnFees` are suppressed for
     *   the same reason — the method by which a refused return travels, and the
     *   fee for it, are not statements anybody can make. This is the answer the
     *   owner gave in his own words: "at the moment we don't offer returns."
     *
     *   A WINDOW — `MerchantReturnFiniteReturnWindow`, which still needs the
     *   number. Chosen with the box at zero it publishes NOTHING rather than
     *   guessing a length, because the whole point of separating the two
     *   questions is that neither answer may be invented from the other.
     *
     * `applicableCountry` and the two optional terms are unchanged and come
     * from settings an admin typed, never from this code. See the block this
     * was lifted out of for the incident behind that.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>|null
     */
    private static function returnPolicy(array $s, string $country): ?array
    {
        $stated = SeoSettings::from($s, 'merchant_returns', '');
        $category = in_array($stated, self::RETURN_CATEGORIES, true) ? $stated : '';
        $returnDays = (int) ($s['merchant_return_days'] ?? 0);

        if ($category === 'MerchantReturnNotPermitted') {
            return [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => $country,
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
            ];
        }

        if ($returnDays <= 0) {
            return null;
        }

        $policy = [
            '@type' => 'MerchantReturnPolicy',
            'applicableCountry' => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => $returnDays,
        ];

        // returnMethod and returnFees were hardcoded to ReturnByMail and
        // FreeReturn. Neither is something this code can know, and both are
        // promises to a shopper: a store that charges for returns and publishes
        // FreeReturn has misrepresented its terms in a Google surface. They are
        // emitted only when an admin has actually stated them.
        $method = SeoSettings::from($s, 'merchant_return_method', '');
        $fees = SeoSettings::from($s, 'merchant_return_fees', '');

        if ($method !== '') {
            $policy['returnMethod'] = 'https://schema.org/' . $method;
        }

        if ($fees !== '') {
            $policy['returnFees'] = 'https://schema.org/' . $fees;
        }

        return $policy;
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
     * three values, not two. `stock_status` is read directly rather than a
     * synthesised `stock` flag, because the flag cannot tell the three apart.
     *
     * ── WHY 'onbackorder' IS PUBLISHED AS OutOfStock — OWNER'S DECISION ──────
     *
     * This method used to answer BackOrder here, on a reasonable argument that
     * is written down so nobody re-derives it: telling a shopper in a search
     * result that something they can order today cannot be bought loses a sale.
     *
     * The trouble is that nothing else in this shop agrees with it. On one
     * back-ordered product, in one document, the head said
     * `product:availability = available for order` and schema.org said
     * `BackOrder`, while the gallery carried a grey "Sold out" badge, the stock
     * line read "Sold out — check back soon", the buy button was disabled, the
     * card offered "View product" instead of Add to cart, and
     * POST /api/cart/add answered 422 "That product is sold out."
     *
     * So the head invited a purchase the entire rest of the application
     * refuses. That is not a missed sale, it is a PAID one: a Merchant Center
     * or Meta feed reading BackOrder runs ads, the shopper clicks, and the
     * landing page shows a greyed-out button. Google suspends items for exactly
     * that mismatch between feed availability and landing-page availability.
     *
     * Two coherent ways out: make back-order genuinely sellable (a pre-order
     * flow, an expected ship date on the page and in the confirmation email,
     * and a stock-return path), or say sold out and mean it. The owner was
     * shown both, with the costs, and chose the second. What he accepted along
     * with it, stated here so it is not a surprise later: 'onbackorder' now
     * means the same thing to a shopper as 'outofstock', and the only things
     * that still tell them apart are the admin label and the back-in-stock
     * alert form.
     *
     * The Open Graph branch above maps this value to 'out of stock', so both
     * machine-facing claims move together.
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
            'outofstock', 'onbackorder' => 'https://schema.org/OutOfStock',
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
