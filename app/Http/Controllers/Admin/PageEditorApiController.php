<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\BrandPanel;
use App\Support\PageTitle;
use App\Support\RichText;
use App\Support\RoutedPages;
use App\Support\TitleHeader;
use App\Support\TranslationInput;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pages → User pages → Edit — the content page editor, and with it the per-row SEO
 * fields on the fifth and last table that carries them. (Lane S9)
 *
 * ── WHAT WAS MISSING, MEASURED AGAINST THE ROUTER ───────────────────────────
 *
 * `pages` carries the same `{title, desc, og_image, canonical, noindex}` column
 * that `products`, `categories`, `brands` and `posts` carry. Lane S6 made the
 * storefront READ it (Store\PageController::show() builds a `$seoCtx` from it)
 * and made the sitemap honour its `noindex`. `Support\SeoAudit::scanPages()`
 * scans content pages and raises findings against those very fields.
 *
 * And NOTHING COULD WRITE THEM. Driven against the route collection before this
 * file existed: zero non-GET routes whose URI mentions `pages`.
 * Admin\PagesApiController had exactly two methods, `store()` and `user()`, both
 * GET, both listing, and `user()` did not return the `seo` column at all — so no
 * screen could have rendered a form over it. The console said so in its own
 * words: the New page button called
 * `toast('Page editor arrives with the CMS in Phase 11')`.
 *
 * The consequence is not cosmetic: a finding the SEO Audit screen raises against
 * a content page was not actionable anywhere in this software. The owner could
 * be told his `/faqs/` page has a duplicate description and had no box to fix it
 * in. `docs/SEO-PREVIEWS.html` carried that row as the one genuinely absent item
 * in the SEO programme.
 *
 * ── THIS EDITOR EDITS. IT DOES NOT CREATE AND IT DOES NOT DELETE ────────────
 *
 * That is the single most important sentence in this file, it is a finding
 * rather than a preference, and the finding is in the router:
 *
 *   * The seven content pages are SEVEN LITERAL ROUTES in routes/web.php, each
 *     carrying `->defaults('slug', 'about')` and friends. That default is where
 *     PageController::show() gets the slug it looks up.
 *   * The site-root catch-all that looks as though it would serve the rest —
 *     `/{slug}/` in routes/kbb-brands-blog.php — reaches
 *     `PageController::post()`, which queries `Post` and NOTHING ELSE.
 *
 * So a page created at any other slug is a row no request can reach. It would
 * appear in this editor's own list with a green Published pill and 404 for every
 * reader and every crawler, and the first anyone would hear of it is Search
 * Console, months later. That is exactly the failure
 * routes/post-editor-admin.php's header describes for a reserved article slug,
 * and writing it deliberately would be worse than leaving the button greyed out.
 *
 * NOT HYPOTHETICAL. Store → Demo Content → Demo Pages already does it: it writes
 * four rows slugged `about-us-demo`, `shipping-delivery-demo`,
 * `returns-exchanges-demo` and `faq-demo`, published, under a comment reading
 * "the whole point of this demo content is to actually be visible so the page
 * layout can be previewed". All four 404. This editor is the first screen in the
 * console that SAYS SO — a row with no route is drawn with "No address on the
 * shop" instead of a link — and ContentPageEditorTest pins it.
 *
 * WHAT CREATING A PAGE WOULD ACTUALLY TAKE, so the next lane does not have to
 * re-derive it: a route that serves an arbitrary `pages` slug. It cannot simply
 * be appended, because the router matches the first registered route and the
 * posts catch-all already matches every single root segment — a page catch-all
 * would have to be registered BEFORE it and would then change precedence for
 * every root-level address on the shop, articles included. That is the Phase 11
 * CMS decision, it belongs in routes/web.php, which a lane may not edit, and it
 * is not smuggled in under an SEO round.
 *
 * DELETE, for the same reason in reverse: the slug is a hard-coded route and the
 * footer links to it. Deleting the row makes `/about/` a 404 at an address the
 * shop advertises and the sitemap has submitted. Taking a page down is
 * `status: draft`, which this editor offers, and which leaves the row and its
 * address recoverable — the rule PostEditorApiController states for an article.
 *
 * THE SLUG IS NOT A FIELD, and here that is stronger than it is for an article.
 * Changing `pages.about` to `pages.about-us` would make BOTH addresses 404: the
 * route still looks up `about` and finds nothing, and `/about-us/` has no route.
 * The page would simply vanish. save() therefore refuses a slug with
 * `prohibited` and the screen prints the address read-only.
 *
 * ── ESCAPING: THE COLUMN IS HTML, BECAUSE THE VIEW SAYS IT IS ───────────────
 *
 * resources/views/store/page.blade.php prints BOTH columns unescaped:
 *
 *     <h1>{!! $page->t('title') !!}</h1>
 *     <div class="policy-body">{!! BodyHeadings::demoteH1(Shortcodes::render($page->t('content'))) !!}</div>
 *
 * and the seeders agree with it — `seed_policy_pages` stores the literal
 * `Terms &amp; Conditions`. So `title` and `content` are HTML columns, not text
 * columns, and an editor that typed straight into them would be a stored-XSS
 * surface on the public storefront the moment a second, lesser-privileged admin
 * account exists. `docs/f2-operator-authored-html.md` §5 measured three dialogs
 * firing on `/about/` from exactly this shape, and this repo has already had one
 * live XSS on the homepage ticker.
 *
 * The rule is NOT widened and NOT reinvented. §6 Option A of that document says
 * in as many words that "when an editor for pages or posts is eventually built,
 * it inherits the rule instead of reopening the hole", and the rule is
 * `RichText::clean()` — the same call PostEditorApiController makes on
 * `posts.body` and the product editor makes on four description columns. So:
 *
 *   `content`  RichText::clean(), in BOTH languages, off one constant
 *              (RICH_FIELDS) so the two cannot drift. Measured on this shop's
 *              own corpus in f2 §6: over all seven populated rows it strips no
 *              tag and no attribute; it changes eight named entities into the
 *              characters they already rendered as, 34 bytes in total. Nothing
 *              is migrated — the rule runs on the way IN, so a row nobody
 *              re-saves is byte-identical, which is rule 1.
 *
 *   `title`    NOT rich, and not raw either. It is a one-line plain-text field
 *              stored in an HTML column, so it is DECODED for the box and
 *              RE-ENCODED on save (PageTitle::stored()). `Terms &amp; Conditions`
 *              round-trips to itself byte-for-byte; a typed `Shipping &
 *              Delivery` stores as `Shipping &amp; Delivery`, which is what the
 *              seeders already store; and a typed `<script>` stores as
 *              `&lt;script&gt;` and is printed as visible text. There is no
 *              input on this screen that can put a tag in an h1.
 *
 * `doc_json`, `css` and `template` are NOT offered. They are the page-builder
 * columns, they are null on every shipped row, and `css` in particular is a
 * stylesheet printed into a page — a clickjacking primitive of exactly the kind
 * PostEditorApiController refuses a `style` cover for. A screen that cannot
 * write them cannot be the way one gets written.
 *
 * ── THE SEO BAG: ALL FIVE KEYS, AND TWO OF THEM SCHEME-CHECKED ──────────────
 *
 * PostEditorApiController offers `title`, `desc` and `noindex` and deliberately
 * withholds `canonical` and `og_image`, arguing there is "no reason to open a
 * second door to them from a writing screen". That argument does not transfer,
 * and the difference is worth stating rather than quietly diverging:
 *
 *   * `SeoAudit::scanPages()` ALREADY raises `bad_canonical` against
 *     `pages.seo.canonical`. A finding that no screen can clear is a finding the
 *     owner cannot act on — which is the whole defect this lane closes. Offering
 *     the field is what makes the audit screen honest.
 *   * `Store\PageController::show()` reads all five keys. An editor that wrote
 *     three of them would leave two that only a database client can change, on
 *     the one table where that was already the complaint.
 *
 * Both are narrowed HERE as well as at render time: `http://`, `https://` or a
 * rooted path, and anything else is REFUSED with the reason rather than saved.
 * That does not undo `Support\Seo::canonicalAbsolute()`'s own narrowing to
 * http/https/protocol-relative — it is a second, earlier refusal, so the value
 * never reaches the column at all.
 *
 * An override that is not set is ABSENT rather than empty, because show()
 * branches on `trim(...) !== ''`: a key that is there and blank is a key
 * somebody later mistakes for a setting, and PageSeoOverridesTest's second case
 * exists for precisely that shape.
 */
class PageEditorApiController extends Controller
{
    /**
     * The columns the storefront prints with {!! !!} and this editor therefore
     * sanitises — in BOTH languages, off this one constant.
     *
     * `title` is not here. It is printed unescaped too, and it is handled by
     * PageTitle::stored() instead, because a title is one line of text and an allowlist
     * that let `<strong>` into an `<h1>` would also be the allowlist that let it
     * into `<title>` through strip_tags(). See the class header.
     *
     * @var list<string>
     */
    public const RICH_FIELDS = ['content'];

    /** The only values `status` may hold. A select stores one of its own options. */
    public const STATUSES = ['published', 'draft'];

    /**
     * The keys `pages.seo` may carry from this screen — an allowlist, not the
     * request. Exactly the five Store\PageController::show() reads.
     *
     * @var list<string>
     */
    public const SEO_KEYS = ['title', 'desc', 'og_image', 'canonical', 'noindex'];

    /**
     * The widest `title` this editor will store, AFTER encoding.
     *
     * `pages.title` is a `string` column — VARCHAR(255). The box is bounded at
     * 200 typed characters, and encoding EXPANDS: 200 ampersands become 1,000
     * bytes. Without this check the refusal would be a bare SQLSTATE[22001]
     * printed into the message strip after the owner had written the whole page,
     * which is the shape of defect JournalArticleEditorTest's empty-author case
     * was found as.
     */
    public const TITLE_STORED_MAX = 255;

    /**
     * Everything the screen needs before it draws a form.
     *
     * `routed` is the whole reason this endpoint carries more than a status
     * list: the screen must be able to say which rows the shop actually serves,
     * and the answer comes from the router rather than from a copy of the seven
     * slugs. See App\Support\RoutedPages.
     */
    public function bootstrap(): JsonResponse
    {
        $routed = RoutedPages::paths();

        return response()->json([
            'statuses' => self::STATUSES,
            'translations' => (new Page)->translationsForEditor(),
            'routed' => $routed,
            'routed_count' => count($routed),
            'site_base' => rtrim(Url::base(), '/'),
            /*
             * Stated by the server rather than assumed by the screen, so the
             * button the screen draws and the endpoint that would answer it
             * cannot disagree. There is no create route and no delete route; see
             * the class header for why.
             */
            'can_create' => false,
            'can_delete' => false,
        ]);
    }

    /**
     * The list this screen draws.
     *
     * NOT a second copy of Admin\PagesApiController::user() — that one is the
     * projection app.blade.php's Store-pages/User-pages split already binds to,
     * it is mapped to `content.manage` (reading which pages exist is not writing
     * one, exactly as GET /admin-api/posts is not), and it is left byte-identical
     * by this lane.
     *
     * This one answers the two questions an EDITOR has to ask and that one does
     * not: is the row served at an address at all, and does it carry an SEO
     * override. `seo` itself is not returned here — only whether one exists —
     * because a table does not need it and a projection that carries less cannot
     * leak more.
     */
    public function index(): JsonResponse
    {
        $routed = RoutedPages::paths();

        $rows = Page::query()
            ->select('id', 'slug', 'title', 'status', 'seo', 'updated_at')
            ->orderBy('title')
            ->get()
            ->map(function (Page $page) use ($routed): array {
                $path = $routed[$page->slug] ?? null;

                return [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    /*
                     * Decoded, because the column is HTML and the console prints
                     * this cell as text. `Terms &amp; Conditions` in a table
                     * cell is the kind of thing that makes an owner open a
                     * database client.
                     */
                    'title' => PageTitle::decoded($page->title),
                    'status' => $page->status,
                    'routed' => $path !== null,
                    'path' => $path,
                    'url' => $path === null ? null : Url::to($path),
                    'seo_set' => self::seoIsSet($page->seo),
                    'updated' => $page->updated_at?->diffForHumans(),
                ];
            });

        return response()->json([
            'pages' => $rows,
            'routed_count' => count($routed),
        ]);
    }

    /** One page, in the shape the form binds to. */
    public function show(int $id): JsonResponse
    {
        $page = Page::query()->findOrFail($id);

        return response()->json(['page' => $this->projection($page)]);
    }

    /**
     * Save one page. No slug, no create, no delete — see the class header.
     */
    public function save(Request $request, int $id): JsonResponse
    {
        $page = Page::query()->findOrFail($id);

        $data = $this->validated($request);

        $title = PageTitle::stored((string) $data['title']);

        if ($title === '') {
            return response()->json([
                'ok' => false,
                'message' => 'A page needs a title: it is the heading on the page and the '
                    .'<title> a search engine prints.',
            ], 422);
        }

        if (mb_strlen($title) > self::TITLE_STORED_MAX) {
            return response()->json([
                'ok' => false,
                'message' => 'That title is too long once the characters that have to be escaped '
                    .'are written out (&, < and >). Shorten it a little.',
            ], 422);
        }

        foreach (['canonical', 'og_image'] as $key) {
            $given = trim((string) (($data['seo'] ?? [])[$key] ?? ''));

            if ($given !== '' && self::safeUrl($given) === null) {
                return response()->json([
                    'ok' => false,
                    'message' => $key === 'canonical'
                        ? 'A canonical has to be an address this shop can publish: https://…, '
                            .'http://… or one starting with / for a page on this site.'
                        : 'A social image has to be an image address: https://…, http://… or one '
                            .'starting with / for a file in this shop’s media library.',
                ], 422);
            }
        }

        // Lane PH: the page's own header choices, refused (422) rather than
        // dropped when a key, a choice or the picture is not one it may hold.
        $header = $request->exists('header_layout') ? $this->headerLayout($request) : false;

        $this->fill($page, $data, $title);

        // Never written before its migration has run: a package's files land
        // before its database step, and an UPDATE naming a missing column fails
        // the whole save. (A non-empty header was already refused above.)
        if ($header !== false && BrandPanel::columnReady('pages')) {
            $page->header_layout = $header;
        }

        $page->save();

        $page->saveTranslations($this->translations($data['translations'] ?? []));

        return response()->json([
            'ok' => true,
            'page' => $this->projection($page->refresh()),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            /*
             * A content page keeps the slug its ROUTE names. Handed the rule
             * rather than left out, so a slug posted at a page is a 422 that
             * says why instead of a field silently dropped — and the message
             * names the real consequence, which is worse here than for an
             * article: both addresses would 404.
             */
            'slug' => ['prohibited'],
            'content' => ['nullable', 'string', 'max:200000'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:200'],
            'seo.desc' => ['nullable', 'string', 'max:400'],
            'seo.og_image' => ['nullable', 'string', 'max:500'],
            'seo.canonical' => ['nullable', 'string', 'max:500'],
            'seo.noindex' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'array'],
            // Lane PH: Page header -- each key checked here, then again by
            // headerLayout(), which refuses a key that is not one of these.
            'header_layout' => ['sometimes', 'nullable', 'array'],
            'header_layout.hero' => ['nullable', 'string', 'in:'.implode(',', BrandPanel::PAGE_HEROES)],
            'header_layout.image' => ['nullable', 'string', 'max:2048'],
            'header_layout.title' => ['nullable', 'string', 'max:'.BrandPanel::PAGE_TITLE_MAX],
            'header_layout.sub' => ['nullable', 'string', 'max:'.BrandPanel::PAGE_SUB_MAX],
        ], [
            'slug.prohibited' => 'A content page is served by a route that names its slug, so '
                .'changing the slug would take the page off the shop at both addresses — the old '
                .'one would no longer find the row and the new one has no route. Ask for a '
                .'redirect at Store → SEO & Meta → Redirects instead.',
        ]);
    }

    /**
     * Write the named columns. Never $page->fill($request->all()).
     *
     * `doc_json`, `css` and `template` are not touched, in either direction: a
     * page that has them keeps them, and this screen cannot be the way one gets
     * them. See the class header.
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Page $page, array $data, string $title): void
    {
        $page->title = $title;

        foreach (self::RICH_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $clean = RichText::clean((string) ($data[$field] ?? ''));

                $page->{$field} = RichText::isBlank($clean) ? null : $clean;
            }
        }

        /*
         * The select can only store one of its own options: anything else is
         * already a 422 from the `in:` rule, and this re-reads the same constant
         * rather than trusting that it was.
         */
        $page->status = in_array($data['status'], self::STATUSES, true)
            ? $data['status']
            : 'draft';

        if (array_key_exists('seo', $data)) {
            $page->seo = $this->seo(is_array($data['seo']) ? $data['seo'] : []);
        }
    }

    /**
     * Lane PH: `pages.header_layout`, rebuilt from BrandPanel::PAGE_KEYS --
     * the page's own Header choice, Header picture, title and subtitle. An
     * unknown key is refused, not dropped; so is a picture that is not an
     * uploaded path or an http(s) address (TitleHeader::safeImage, the
     * category Banner picture's own check), so a typo is never saved as "no
     * picture". Blank fields are left out; nothing left is NULL, "follow the
     * shop". Read off the INPUT, which the rules above have checked key by key.
     *
     * @return array<string, string>|null
     */
    private function headerLayout(Request $request): ?array
    {
        $raw = $request->input('header_layout');
        $raw = is_array($raw) ? $raw : [];
        $unknown = array_diff(array_keys($raw), BrandPanel::PAGE_KEYS);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['header_layout' => 'The page header has no setting called "'.mb_substr((string) reset($unknown), 0, 40).'".']);
        }

        $clean = [];
        $hero = BrandPanel::pageHero(['hero' => $raw['hero'] ?? null]);

        if ($hero !== null) {
            $clean['hero'] = $hero;
        }

        $picture = trim((string) ($raw['image'] ?? ''));

        if ($picture !== '') {
            $safe = TitleHeader::safeImage($picture);

            if ($safe === null) {
                throw ValidationException::withMessages(['header_layout.image' => 'The header picture must be an uploaded file (/uploads/…) or an http(s) address.']);
            }

            $clean['image'] = $safe;
        }

        foreach (['title' => BrandPanel::PAGE_TITLE_MAX, 'sub' => BrandPanel::PAGE_SUB_MAX] as $key => $max) {
            // One line of plain words: printed escaped, and kept as typed.
            $value = trim((string) preg_replace('/\s+/u', ' ', (string) ($raw[$key] ?? '')));

            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, $max);
            }
        }

        if ($clean !== [] && ! BrandPanel::columnReady('pages')) {
            throw ValidationException::withMessages(['header_layout' => 'The page header needs this update\'s database step. Run the update again from Store → Core Updates.']);
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * `pages.seo`, rebuilt from an allowlist.
     *
     * An override that is not set is ABSENT, never an empty string — show()
     * guards on `trim(...) !== ''` so a blank key is "no override" by a longer
     * route, and PageSeoOverridesTest's second case exists because the panel
     * posts every field on every save.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, mixed>|null
     */
    private function seo(array $given): ?array
    {
        $out = [];

        foreach (self::SEO_KEYS as $key) {
            if (! array_key_exists($key, $given)) {
                continue;
            }

            if ($key === 'noindex') {
                if ((bool) $given[$key]) {
                    $out[$key] = true;
                }

                continue;
            }

            $value = trim((string) ($given[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            // The two that become an href and an og:image. save() has already
            // refused an unsafe one with a reason; this is the line that makes
            // the refusal structural rather than procedural.
            if ($key === 'canonical' || $key === 'og_image') {
                $value = self::safeUrl($value) ?? '';

                if ($value === '') {
                    continue;
                }
            }

            $out[$key] = $value;
        }

        return $out === [] ? null : $out;
    }

    /**
     * The Arabic bag, under exactly the rules the English half just went
     * through.
     *
     * `content` through TranslationInput::clean()'s RichText::clean, and `title`
     * through the SAME PageTitle::stored() the English title used — an asymmetry here
     * would be a stored-XSS hole opened by the act of adding the second
     * language, which is the defect docs/f2-operator-authored-html.md §5
     * measured firing on /ar/about/ with the English left clean.
     *
     * @param  array<mixed>  $given
     * @return array<string, array<string, string|null>>
     */
    private function translations(array $given): array
    {
        $clean = TranslationInput::clean($given, self::RICH_FIELDS);

        foreach ($clean as $locale => $fields) {
            if (array_key_exists('title', $fields) && $fields['title'] !== null) {
                $clean[$locale]['title'] = PageTitle::stored((string) $fields['title']);
            }
        }

        return $clean;
    }

    /**
     * A URL this editor is allowed to store: http, https, or a rooted path.
     *
     * `//host/path` is REFUSED although Support\Seo::canonicalAbsolute() accepts
     * it, and that is deliberate rather than a disagreement: a protocol-relative
     * canonical is a value nobody types on purpose and a `//evil.test/` typed
     * into a box that says "address on this site" is the mistake the box should
     * catch. Seo's acceptance is about rows that ALREADY hold one, from the
     * importer; this is about what a new one may be.
     *
     * `mailto:` is not on the list: neither of these two becomes a link a
     * shopper clicks — one is a canonical and one is an og:image.
     */
    public static function safeUrl(string $given): ?string
    {
        $value = trim($given);

        if ($value === '') {
            return null;
        }

        // This shop's own media library and its own pages: /storage/…, /faqs/.
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    /** Does this row carry an override at all? Used by the list, not the form. */
    private static function seoIsSet(mixed $seo): bool
    {
        if (is_string($seo)) {
            $seo = json_decode($seo, true);
        }

        if (! is_array($seo)) {
            return false;
        }

        foreach (self::SEO_KEYS as $key) {
            if ($key === 'noindex') {
                if (! empty($seo[$key])) {
                    return true;
                }

                continue;
            }

            if (trim((string) ($seo[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Page $page): array
    {
        $path = RoutedPages::pathFor($page->slug);

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => PageTitle::decoded($page->title),
            'content' => $page->content,
            'status' => $page->status,
            /*
             * Null when the storefront serves this row at no address at all, and
             * the screen draws that as a refusal rather than as a link to
             * nowhere. See App\Support\RoutedPages.
             */
            'routed' => $path !== null,
            'path' => $path,
            'url' => $path === null ? null : Url::to($path),
            'seo' => is_array($page->seo) ? $page->seo : [],
            // Lane PH: Page header -- the page's own choices, and the shop's
            // switch the "Shop setting" option follows, so the screen can say
            // which header that is today.
            'header_layout' => BrandPanel::pageOwn($page->getAttribute('header_layout')),
            'header_shop' => (app(\App\Services\SiteLayout::class)->only(['pg_hero'])['pg_hero'] ?? 'panel') === 'banner' ? 'banner' : 'brand',
            'translations' => self::decodedTranslations($page->translationsForEditor()),
        ];
    }

    /**
     * The Arabic prefill, with the title cell decoded the way the English title
     * is.
     *
     * The Arabic title is printed by the SAME `{!! !!}` in store/page.blade.php,
     * so it is stored encoded by save() — and a box that showed
     * `الشروط &amp; الأحكام` while the English box beside it showed
     * `Terms & Conditions` would be one screen disagreeing with itself about
     * what its own column holds.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $bag
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function decodedTranslations(array $bag): array
    {
        foreach ($bag as $locale => $fields) {
            if (isset($fields['title']) && is_array($fields['title'])) {
                $bag[$locale]['title']['value'] = PageTitle::decoded(
                    (string) ($fields['title']['value'] ?? '')
                );
            }
        }

        return $bag;
    }
}
