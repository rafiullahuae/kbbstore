<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Post;
use App\Services\Seo\SeoSettings;
use App\Support\ProductSeo;
use App\Support\Seo;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "What will Google see for this page?" — asked from the box the owner is
 * typing into, answered by the renderer the page itself uses.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS ENDPOINT EXISTS AND NOT A PIECE OF JAVASCRIPT
 * ---------------------------------------------------------------------------
 *
 * The category, brand and article editors each collect an SEO title and an SEO
 * description and show the operator NO CONSEQUENCE: a 255-character box and a
 * 500-character box, and whatever is typed disappears into a JSON column. That
 * is the whole reason these fields get filled in badly — nobody can see what
 * they produce, and what they produce is not what is in the box.
 *
 * It is not the box, because four rules sit between the two and none of them is
 * reconstructible from the browser:
 *
 *   - An EMPTY title box does not publish an empty title. It publishes the
 *     row's own name run through `seo_title_template`, which on this store
 *     appends " | K-Beauty Bliss" — so a blank box is a 40-character tag and a
 *     58-character box is a 75-character tag that Google truncates.
 *   - A FILLED title box is the WHOLE title and the site name is NOT appended
 *     (`title_is_final`). The same 58 characters therefore mean two different
 *     lengths depending on a rule that is invisible on the screen.
 *   - A `%%title%%` or `{sitename}` token inside a typed title is substituted
 *     server-side by App\Support\TitleTemplate, and a token it is not handed is
 *     DELETED — the defect that published the site name alone on 671 product
 *     pages.
 *   - An empty description box publishes the row's own description, then
 *     `seo_default_description`, then nothing at all — and "nothing at all" is
 *     a real answer a preview has to be able to show.
 *
 * Re-implementing those four in the console would be a FIFTH dialect of the
 * same rules, which is the mistake this project has paid for repeatedly — see
 * docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md §1 on the third hex dialect nobody
 * had named. So the console sends what is in the boxes and this asks
 * App\Support\Seo, the class every storefront page renders its own head with.
 *
 * SeoRowPreviewTest does not take that claim on trust: for each kind it saves
 * the override, FETCHES THE REAL PAGE, pulls `<title>` and
 * `<meta name="description">` out of the response, and requires this endpoint
 * to answer the same bytes. A preview that agreed with a second implementation
 * would still be wrong; one that agrees with the page cannot be.
 *
 * ---------------------------------------------------------------------------
 * WHY IT WRITES NOTHING, AND HOW THAT IS ENFORCED RATHER THAN INTENDED
 * ---------------------------------------------------------------------------
 *
 * The point of the preview is to be seen BEFORE Save. So the candidate values
 * arrive in the request body and the stored row is only ever READ — for the
 * fallbacks an empty box falls through to, and for the `%%title%%` token, which
 * is the row's own name. Nothing here calls save(), update() or set(), and
 * SeoRowPreviewTest asserts the stored `seo` blob is byte-identical after a
 * preview call, on a row that already had one.
 *
 * ---------------------------------------------------------------------------
 * RULE 5 — EVERY VALUE HERE IS OPERATOR-SUPPLIED AND HEADED FOR GOOGLE
 * ---------------------------------------------------------------------------
 *
 * `kind` stores one of its own options or is REFUSED — never defaulted. A
 * defaulted kind would preview the wrong page's rules and say nothing about it,
 * which is a preview that lies rather than one that fails.
 *
 * Nothing is echoed back raw. The two candidate strings are returned only after
 * passing through Seo::titleFor()/Seo::describe(), and the console prints the
 * result with textContent rather than innerHTML — a preview of a title IS
 * operator-supplied content being drawn in the admin, which is the class of
 * value that put `<img src=x onerror=…>` on the storefront homepage this week
 * (docs/M-PHASE3-SETTINGS-SCHEMA-ROUND-3.md §4).
 *
 * THE URL LINE IS RETURNED AS TEXT AND DRAWN AS TEXT, never as an `href`. The
 * project notes require a URL from a setting to be scheme-checked before it
 * becomes a link; the cheaper and stricter answer is for it never to become
 * one. `site_url` is an operator setting and the grey address line in a Google
 * result is not a link on the real thing either.
 *
 * ---------------------------------------------------------------------------
 * NO CAP ON WHAT ARRIVES, AND THAT IS DELIBERATE
 * ---------------------------------------------------------------------------
 *
 * `max:` on the two candidate strings would make an over-long title come back
 * as a REFUSAL rather than as a preview reading "78 / 60 — too long", which is
 * precisely the answer the owner opened the box to get. The bound that matters
 * is the one on the box (the editors cap at 255 and 500) and the one on the
 * column; this reads a string, renders it and stores nothing, so a long one
 * costs one render. `LIMIT` is a sanity ceiling on the request body, well above
 * any box on any of these screens, so the endpoint cannot be used to hand a
 * megabyte to the template engine.
 */
class SeoPreviewApiController extends Controller
{
    /**
     * The page kinds this can preview.
     *
     * One per screen that has an SEO title and description box, and no more. A
     * kind is added here in the same commit as the screen that asks for it, so
     * the list cannot name a preview nothing draws.
     */
    public const KINDS = ['home', 'category', 'brand', 'article'];

    /**
     * The sanity ceiling on a candidate string — see the class note.
     *
     * Twice the longest box on any of these screens (the category description
     * caps at 500), rounded up. It is not a content rule and no value the
     * screens can produce comes near it.
     */
    public const LIMIT = 4000;

    /**
     * What Google truncates at, which is what the counters are measured
     * against.
     *
     * The same two numbers the product editor has counted against since it was
     * built (resources/views/admin/partials/product-editor-screen.blade.php,
     * `count('seo-title', emitted.title, 50, 60)`). They are quoted from there
     * rather than picked again, so the four screens cannot disagree about what
     * "too long" means.
     */
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 155;

    public function show(Request $request): JsonResponse
    {
        $kind = $request->input('kind');

        /*
         * REFUSED, not defaulted. Each kind carries different rules about
         * whether the site name is appended, so guessing one would draw a
         * plausible preview of a page that does not exist.
         */
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unknown page kind.',
            ], 422);
        }

        $title = $this->candidate($request->input('title'));
        $description = $this->candidate($request->input('description'));

        $row = $this->row($kind, $request->input('id'));

        /*
         * THE NAME AND THE FALLBACK DESCRIPTION ON THE FORM WIN OVER THE STORED
         * ONES, and that is the difference between a preview and a report.
         *
         * A row's own name is what an empty SEO title box publishes, and its own
         * description (the category description, the brand description, the
         * article's excerpt) is what an empty SEO description box publishes. Both
         * are boxes ON THE SAME FORM, being edited in the same sitting. Reading
         * them out of the table would show the operator the result of the save he
         * made last week while he renames the category in front of him — which is
         * the one thing this preview exists not to do.
         *
         * Blank is not "use the form's value": an empty Name box means the
         * operator has cleared it and is mid-sentence, and the stored name is
         * still the better answer until he types the new one.
         */
        if ($row !== null) {
            $candidateName = $this->candidate($request->input('name'));
            $candidateFallback = $this->candidate($request->input('fallback'));

            if ($candidateName !== '') {
                $row['name'] = $candidateName;
            }

            if ($candidateFallback !== '') {
                $row['description'] = $candidateFallback;
            }
        }

        if ($row === null && $kind !== 'home') {
            /*
             * A row that is being CREATED has no id yet, and that is the moment
             * the boxes matter most. So a missing row is not an error: the
             * preview falls back to the name the console sends, which is what
             * the creating form has in its own Name box.
             */
            $row = [
                'name' => $this->candidate($request->input('name')),
                'description' => $this->candidate($request->input('fallback')),
                'path' => $this->pathFromSlug($kind, $this->candidate($request->input('slug'))),
                'stored' => [],
            ];
        }

        $ctx = $this->context($kind, $title, $description, $row ?? []);

        /*
         * THE FILLED BOXES ARE RESOLVED BY App\Support\Seo. THE EMPTY ONES ARE
         * READ OFF THE PAGE.
         *
         * A filled box is a string this endpoint owns: it goes through the real
         * title engine and the answer is exact.
         *
         * An EMPTY box is a different question, and reproducing its answer here
         * was tried and abandoned for a reason worth writing down. What an empty
         * title box publishes is the page's OWN `@section('title')` run through
         * `seo_title_template` — and that section is a different expression in
         * every template: `__('store.shop.page_title', ...)` for a category
         * archive, the brand's bare name for a brand, a literal sentence on the
         * homepage. What an empty DESCRIPTION box publishes is worse:
         * ShopController::seoDescription() builds a sentence out of the category
         * name AND ITS LIVE PRODUCT COUNT.
         *
         * Four expressions in four templates plus a generator that counts rows
         * is a FIFTH DIALECT of this shop's own head, which is the mistake the
         * settings rounds paid for four times. So the empty case is answered by
         * ASKING THE PAGE: one internal sub-request to the row's own URL, and
         * `<title>` and `<meta name="description">` read out of the response.
         * That cannot drift from the page, because it IS the page.
         *
         * Measured, not assumed: the first version of this file reproduced the
         * rules, and SeoRowPreviewTest caught it against the real category
         * archive — the page published "Toners Probe · K-Beauty Bliss | KBB" and
         * the preview said "KBB".
         *
         * COST. One render, memoised for PUBLISHED_TTL seconds against the row,
         * and only when a box is actually empty. A row with both boxes filled
         * costs no sub-request at all. Staleness is invisible: the value is a
         * FALLBACK the operator is in the middle of replacing, and saving
         * repaints the editor.
         */
        $published = ($title === '' || $description === '')
            ? $this->published($kind, $row ?? [])
            : ['title' => null, 'description' => null];

        $emittedTitle = $title !== ''
            ? Seo::titleFor($ctx)
            : ($published['title'] ?? Seo::titleFor($ctx));

        $emittedDescription = $description !== ''
            ? Seo::describe($ctx)
            : ($published['description'] ?? Seo::describe($ctx));

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'url' => $this->displayUrl($kind, $row ?? []),
            'title' => $emittedTitle,
            'description' => $emittedDescription,
            /*
             * Whether the page could be read at all. A row being CREATED has no
             * page, so an empty box there has no published answer and the screen
             * says so rather than drawing a guess — "the shop will write this
             * once the category exists" is the true sentence.
             */
            'published_known' => ($title !== '' || $published['title'] !== null)
                && ($description !== '' || $published['description'] !== null),
            /*
             * Measured here rather than in the browser, because mb_strlen and
             * String.prototype.length disagree on anything outside the BMP and
             * an emoji in a title is a real thing an owner types. Google counts
             * characters, not UTF-16 code units.
             */
            'title_length' => mb_strlen($emittedTitle),
            'description_length' => mb_strlen($emittedDescription),
            'title_max' => self::TITLE_MAX,
            'description_max' => self::DESCRIPTION_MAX,
            /*
             * Whether the boxes are doing anything at all, which is the second
             * question a preview answers: "this is your wording" and "this is
             * what the shop wrote for you" look identical in a snippet and are
             * completely different facts about the page.
             */
            'title_is_yours' => $title !== '',
            'description_is_yours' => $description !== '',
        ]);
    }

    /**
     * How long a read of the row's own page is kept.
     *
     * Short on purpose. The value is a FALLBACK the operator is in the middle of
     * replacing, so it is only ever shown for a box he has not filled in — and
     * saving repaints the editor, which is a new mount and a new read. Thirty
     * seconds bounds a typist to two page renders a minute per row while still
     * being shorter than any sitting at this screen.
     */
    public const PUBLISHED_TTL = 30;

    /**
     * What the row's own page publishes right now: `<title>` and
     * `<meta name="description">`, read out of a real response.
     *
     * ── WHY A SUB-REQUEST AND NOT A FIFTH COPY OF THE RULES ────────────────
     *
     * See the long note in show(). The short version: an empty SEO box falls
     * back to the page's own `@section('title')`, which is a different
     * expression in each of four templates, and to a generated sentence that
     * counts products. Reproducing those here is the kind of parallel
     * implementation this project has repeatedly found drifting; asking the page
     * cannot drift, because it is the page.
     *
     * There is precedent in this console: Admin\CacheApiController's `live` key
     * is a sub-request through the kernel for the same reason — the only honest
     * answer to "what does the shop really send" is what the shop really sends.
     *
     * ── WHAT IT DOES NOT DO ────────────────────────────────────────────────
     *
     * It never leaves this application. The request is built with
     * Request::create() and handed to this app's own kernel, so there is no
     * outbound HTTP, no host to resolve and no operator-supplied URL involved:
     * the path comes from the row, not from the request body. Anything other
     * than a 200 answers null and the caller falls back to the engine, so a
     * draft article, a 404 or a redirect degrades to a weaker preview rather
     * than to an error.
     *
     * @param  array<string, mixed>  $row
     * @return array{title: string|null, description: string|null}
     */
    private function published(string $kind, array $row): array
    {
        $path = $kind === 'home' ? Url::to('/') : (string) ($row['path'] ?? '');

        // A row being created has no page. That is a state, not a failure.
        if ($path === '' || $path === '/' && $kind !== 'home') {
            return ['title' => null, 'description' => null];
        }

        $key = 'kbb.s7.seo-preview.'.$kind.'.'.md5($path);

        $answer = Cache::remember($key, self::PUBLISHED_TTL, function () use ($path): array {
            try {
                $request = Request::create($path, 'GET');
                $response = app()->handle($request);

                if ($response->getStatusCode() !== 200) {
                    return ['title' => null, 'description' => null];
                }

                $html = (string) $response->getContent();
            } catch (\Throwable) {
                /*
                 * A preview is never worth a 500 on the screen that draws it.
                 * The caller falls back to the title engine, which is a weaker
                 * answer and still an answer.
                 */
                return ['title' => null, 'description' => null];
            }

            return [
                'title' => self::tagText($html, '#<title>(.*?)</title>#si'),
                'description' => self::tagText($html, '#<meta\s+name="description"\s+content="(.*?)"#si'),
            ];
        });

        return is_array($answer) ? $answer + ['title' => null, 'description' => null] : ['title' => null, 'description' => null];
    }

    /**
     * One captured group out of a rendered page, with entities decoded.
     *
     * Decoded because App\Support\Seo writes the tag with htmlspecialchars() and
     * the console prints this with textContent — so leaving it encoded would
     * show the operator `Anua &amp; Co` where Google shows `Anua & Co`. Decoding
     * is not a weakening: the value is printed as TEXT at the other end and
     * never as markup.
     */
    private static function tagText(string $html, string $pattern): ?string
    {
        if (preg_match($pattern, $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** A candidate string off the request: trimmed, bounded, never a fallback. */
    private function candidate(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim(mb_substr($value, 0, self::LIMIT));
    }

    /**
     * The stored row behind this preview, reduced to the four things the
     * renderer needs — never the model.
     *
     * The same rule Admin\SeoAuditApiController writes down for its samples and
     * that CLAUDE.md records for /api/*: an allowlist that is BUILT cannot leak
     * a column added next year. `products` is not among the kinds here, but
     * `brands` and `categories` both carry ids, positions and counts that an
     * SEO preview has no business handing to the browser.
     *
     * @return array{name: string, description: string, path: string, stored: array<string, mixed>}|null
     */
    private function row(string $kind, mixed $id): ?array
    {
        if (! is_numeric($id)) {
            return null;
        }

        $id = (int) $id;

        if ($kind === 'category') {
            $category = Category::query()->find($id);

            return $category === null ? null : [
                'name' => (string) $category->t('name'),
                'description' => (string) ($category->t('description') ?? ''),
                'path' => $category->url(),
                'stored' => ProductSeo::normalise($category->seo) ?? [],
            ];
        }

        if ($kind === 'brand') {
            $brand = Brand::query()->find($id);

            return $brand === null ? null : [
                'name' => (string) $brand->t('name'),
                'description' => (string) ($brand->t('description') ?? ''),
                'path' => Url::to('/korean-skincare-brands/'.$brand->slug.'/'),
                'stored' => ProductSeo::normalise($brand->seo) ?? [],
            ];
        }

        if ($kind === 'article') {
            $post = Post::query()->find($id);

            return $post === null ? null : [
                'name' => (string) $post->t('title'),
                'description' => (string) ($post->t('excerpt') ?? ''),
                'path' => '/'.$post->slug.'/',
                'stored' => is_array($post->seo) ? $post->seo : [],
            ];
        }

        return null;
    }

    /**
     * The `$ctx` the real page hands App\Support\Seo, with the candidate values
     * standing in for the stored ones.
     *
     * ── WHY EACH ARM IS SHAPED THE WAY IT IS ────────────────────────────────
     *
     * Every line below is quoted from the storefront controller that renders
     * that kind of page, and the comment names it. That is the only defensible
     * way to write this: the rules are NOT the same across the four, and a
     * single shared shape would be a fifth dialect wearing a tidy jumper.
     *
     *   category   Store\ShopController::index(), $seoCtx
     *   brand      Store\BrandController::seoCtx()
     *   article    Store\PageController::post()
     *   home       Store\HomeController, via Seo::titleOf()'s `home` branch
     *
     * SeoRowPreviewTest is what keeps them quoted rather than remembered: it
     * fetches the real page for each kind and compares.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function context(string $kind, string $title, string $description, array $row): array
    {
        $name = (string) ($row['name'] ?? '');
        $stored = is_array($row['stored'] ?? null) ? $row['stored'] : [];

        if ($kind === 'home') {
            /*
             * THE `home` BRANCH CANNOT BE REACHED WITH A CANDIDATE, so it is
             * reached the one other way that answers the same bytes.
             *
             * Seo::titleOf()'s `home` arm reads `seo_home_title` OUT OF THE
             * SETTINGS TABLE and falls back to $ctx['title'] only when that row
             * is blank. So on a shop that has already saved a home title,
             * passing the candidate as $ctx['title'] is ignored and this preview
             * would draw the SAVED value while the owner typed a new one — which
             * is the worst failure available to a preview, because it looks like
             * it is working. Measured, not reasoned: the first version of this
             * file did exactly that and SeoRowPreviewTest caught it.
             *
             * `title_is_final` + `title_token` reaches the other arm, which
             * renders the given string through TitleTemplate with
             * ['sep', 'sitename', 'page' => '', 'title'] — the SAME four tokens
             * Seo::tokens() builds for a home context, because the
             * "already carries the site name" rule that blanks `sitename` is
             * skipped for `type === 'home'` and skipped again whenever
             * `title_is_final` is set. The two arms therefore answer the same
             * bytes for the same input, and SeoRowPreviewTest pins that against
             * the real homepage rather than leaving it as an argument here.
             *
             * `type` is `website` AND NOT `home`, which also settles the
             * description. Seo::describe() reads `seo_home_description` only for
             * a home context — and an empty box means the owner is about to
             * store a blank, after which the homepage falls through to
             * `seo_default_description`. `website` is that answer. An empty box
             * is handled before this is reached anyway, by reading the live page.
             */
            $ctx = [
                'type' => 'website',
                'url' => $this->siteBase().Url::to('/'),
                'title' => $title,
                'title_is_final' => true,
                'title_token' => $title,
            ];

            /*
             * Passed explicitly so it WINS over `seo_home_description`, for the
             * same reason the title does. OMITTED when the box is empty — `??`
             * does not skip an empty string, so setting it to '' would publish
             * no description at all instead of falling through the real chain.
             */
            if ($description !== '') {
                $ctx['description'] = $description;
            }

            return $ctx;
        }

        // Store\PageController::post() — `type: article`, and it passes `title`
        // and `title_token` on EVERY article, with `title_is_final` only when
        // there is an override. So an article with no override still reaches the
        // template arm carrying its own headline, which is why the fallback here
        // is the row's name rather than nothing.
        if ($kind === 'article') {
            $ctx = [
                'type' => 'article',
                'url' => $this->siteBase().($row['path'] ?? '/'),
                'title' => $title !== '' ? $title : $name,
                'title_is_final' => $title !== '',
                'title_token' => $name,
            ];

            $ctx['description'] = $description !== ''
                ? $description
                : (string) ($stored['desc'] ?? $row['description'] ?? '');

            return $ctx;
        }

        /*
         * Store\ShopController::index() and Store\BrandController::seoCtx() —
         * `type: collection`, and both leave `title` OUT unless there is an
         * override, so an un-overridden archive renders through
         * `seo_title_template` with the page's own `@section('title')`. The two
         * are identical in every respect this preview reads, which is why they
         * share an arm: BrandController's own note says the shape is
         * "deliberately identical to `products.seo` so there is one shape in
         * this app for the SEO overrides of a thing".
         */
        $ctx = [
            'type' => 'collection',
            'url' => $this->siteBase().($row['path'] ?? '/'),
        ];

        if ($title !== '') {
            $ctx['title'] = $title;
            $ctx['title_is_final'] = true;
            $ctx['title_token'] = $name;
        } elseif ($name !== '') {
            /*
             * ONLY REACHED FOR A ROW THAT HAS NO PAGE — one being created, or
             * one whose page did not answer 200 (a draft). An existing row's
             * empty box is answered by reading the live page, which is exact;
             * this is the weaker answer for the case where there is nothing to
             * read.
             *
             * `title_is_final` is deliberately NOT set, so the name goes through
             * `seo_title_template` exactly as the real archive's own section
             * title does. What this cannot know is the wording the template
             * around it adds ("Toners · K-Beauty Bliss" rather than "Toners"),
             * which lives in the page's own template. So the response reports
             * `published_known: false` and the screen says the exact title
             * appears once the row is saved, rather than presenting this as the
             * finished answer.
             */
            $ctx['title'] = $name;
        }

        $resolved = $description !== ''
            ? $description
            : (string) ($stored['desc'] ?? $row['description'] ?? '');

        if ($resolved !== '') {
            $ctx['description'] = $resolved;
        }

        return $ctx;
    }

    /**
     * The grey address line of the snippet.
     *
     * Drawn as TEXT by the console, never as an href — see the class note. It
     * is built from `site_url` plus the row's own path, which is the same pair
     * every canonical on this shop is built from, so an owner who has not set
     * `site_url` sees the bare path rather than a link to nowhere.
     *
     * @param  array<string, mixed>  $row
     */
    private function displayUrl(string $kind, array $row): string
    {
        $path = $kind === 'home' ? Url::to('/') : (string) ($row['path'] ?? '/');

        return $this->siteBase().$path;
    }

    /**
     * The address a row BEING CREATED will live at, from the slug box on the
     * form.
     *
     * Only the grey line of the snippet depends on this, and it is drawn as
     * text. The slug is reduced to the characters a slug may hold before it is
     * put in a path — not for safety (nothing here becomes a link, and the
     * console prints it with textContent) but for honesty: the address that
     * appears is the address the row will get, and showing "Sun Care & SPF"
     * inside a URL would be a preview of something the shop never serves.
     *
     * A category being created shows /product-category/<slug>/ without its
     * ancestors, because the parent is a select on the same form and resolving
     * the whole path would mean reading the tree here. The one thing that would
     * be worse than a short path is a wrong one, so the note beside the box
     * ("Set once. It cannot be changed after the product exists.") is where the
     * full answer lives, and this shows the segment the operator is typing.
     */
    private function pathFromSlug(string $kind, string $slug): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9\-]+/', '-', $slug) ?? '');
        $slug = trim($slug, '-');

        if ($slug === '') {
            return '/';
        }

        return match ($kind) {
            'category' => Url::to('/product-category/'.$slug.'/'),
            'brand' => Url::to('/korean-skincare-brands/'.$slug.'/'),
            'article' => '/'.$slug.'/',
            default => '/',
        };
    }

    /** `site_url` with no trailing slash, exactly as the storefront reads it. */
    private function siteBase(): string
    {
        return rtrim(SeoSettings::get('site_url', ''), '/');
    }
}
