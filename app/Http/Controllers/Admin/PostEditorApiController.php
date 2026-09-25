<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\PageController;
use App\Models\Post;
use App\Services\Import\Entities\PostImporter;
use App\Support\RichText;
use App\Support\TranslationInput;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Content → Blog Posts → New article / Edit — the half of the Journal that was
 * missing.
 *
 * ── WHAT WAS ACTUALLY MISSING, MEASURED RATHER THAN READ ────────────────────
 *
 * KBB-Master-Plan Phase 9 says "`posts` is empty and nothing in the repo can
 * fill it". Half of that is now stale, and it was checked by clicking rather
 * than by reading the plan:
 *
 *   * `PostImporter` exists (Lane GJ) and is wired into `ImportRunner`, so a
 *     WordPress export DOES fill `posts` — that is Store → Store Import /
 *     Export.
 *   * Store → Demo Content → Demo Blog Posts writes four sample articles.
 *     Driven here: POST /admin-api/demo-content/posts/import answered
 *     {"ok":true,"count":4} and /skincare-guide/ listed all four.
 *
 * What was NOT stale: an owner who has WRITTEN an article — not exported one
 * from WordPress — had nowhere to put it. Content → Blog Posts was a read-only
 * table by its own comment, `POST /admin-api/posts` answered **405**, and
 * docs/f2-operator-authored-html.md §1 recorded the same thing from the other
 * end: "There is no POST, PUT or PATCH anywhere in this application that writes
 * `pages.content` or `posts.body`."
 *
 * This controller is that endpoint, and nothing else about the Journal moves.
 *
 * ── THE SLUG IS THE WHOLE REASON THIS IS NOT A THIN CRUD CONTROLLER ─────────
 *
 * An article is served from the SITE ROOT, `/{slug}/`, which is an address it
 * shares with the entire storefront. `PageController::slugPattern()` puts a
 * negative lookahead over `RESERVED_SLUGS` in front of the catch-all, so an
 * article slugged `about`, `wishlist`, `feed` or `concern` is a row no request
 * can ever reach. Saved quietly it would look published on this screen, appear
 * in the admin list, and 404 for every reader and every crawler — and the first
 * anyone would hear of it is Search Console, months later.
 *
 * So the refusal happens AT THE MOMENT HE TYPES IT: slug() answers the same
 * verdict live, and create() refuses the write. Both ask
 * `PostImporter::address()`, which asks `PageController::slugPattern()` — THE
 * ROUTER'S OWN PATTERN, not a second copy of the list. A lane that adds a route
 * and a reserved slug tomorrow moves this editor with it for free, and it folds
 * in the configurable admin path (KBB_ADMIN_PATH) that a static list could not.
 *
 * That is also why there is no second sanitiser and no second slug helper in
 * this file. Every rule here is the one the importer already applies to the
 * same column.
 *
 * ── THE SLUG IS SET ONCE, AT CREATE ─────────────────────────────────────────
 *
 * Exactly the rule `ProductEditorApiController` states for `products.slug`, and
 * for the same reason: `/{slug}/` is a live URL contract — a link Google holds.
 * save() does not accept a slug. Moving an article's address is Store → SEO &
 * Meta → Redirects, which is the screen that exists for it and which writes the
 * 301 that keeps the ranking.
 *
 * ── ARABIC IS ENTERED IN THE SAME REQUEST, NOT RETROFITTED ──────────────────
 *
 * `Post::$translatable` is already `['title', 'excerpt', 'body']` and
 * `TranslationEstimate::CONTENT` already lists `Post::class`, so the Arabic
 * half needed no schema and no new plumbing — only a screen. The boxes post
 * `translations[ar][<field>]` in the SAME request as the English, through
 * `TranslationInput::clean()` and `saveTranslations()`, which is the contract
 * docs/BILINGUAL-PLAN.md sets and which the product editor already follows.
 *
 * THE SLUG IS DELIBERATELY NOT TRANSLATED, and that is not an omission of this
 * lane. `App\Support\HasTranslations`' header argues it out: one slug per row
 * with the language carried by the `/ar` prefix, so `/{slug}/` and
 * `/ar/{slug}/` are the same article at two addresses that differ by four
 * characters, which is what hreflang is for. Driven against a running server
 * with Arabic switched on: `/ar/centella-vs-cica-what-calms-skin-demo/` → 200.
 *
 * ── ESCAPING: ONE SANITISER, THE ONE THAT IS ALREADY THERE ──────────────────
 *
 * `posts.body` is printed with `{!! !!}` (store/post.blade.php). It goes
 * through `RichText::clean()`, and the Arabic half goes through the SAME call
 * via `TranslationInput::clean($…, self::RICH_FIELDS)` — a sanitiser applied to
 * one language only is a stored-XSS hole opened by adding the second.
 *
 * docs/f2-operator-authored-html.md measured the cost on this shop's real
 * corpus before this was chosen: over all seven populated rows `RichText::
 * clean()` strips no tag and no attribute and changes eight named HTML entities
 * into the characters they already rendered as. The four capabilities it does
 * cost — inline `style`, `id`, `<iframe>`, media/form tags — are named in §4 of
 * that document, and its §6 Option A says in as many words that "when an editor
 * for pages or posts is eventually built, it inherits the rule instead of
 * reopening the hole". This is that editor, and it does.
 *
 * `excerpt` is NOT in RICH_FIELDS, because it is not rich: the index prints it
 * `{{ }}` and the article prints `e($post->t('excerpt'))`. It is stored as
 * typed and escaped at both ends.
 *
 * ── `cover` IS A URL HERE, THOUGH THE COLUMN IS WIDER ───────────────────────
 *
 * `posts.cover` is a CSS *background* value — see `App\Support\CoverImage` —
 * and store/post.blade.php prints `CoverImage::background($post->cover)` into a
 * `style="background:…"` attribute. Blade escapes it, so it cannot break the
 * attribute; it CAN still be CSS. A value carrying a `#` is returned verbatim,
 * so `#fff;position:fixed;inset:0;z-index:99999` typed into a cover box would
 * be an invisible layer over the whole page — a clickjacking primitive, which
 * is the exact thing `RichText` refuses inline `style` for.
 *
 * So THIS EDITOR writes an image URL or nothing: scheme-checked http/https, or
 * a rooted path, and anything else is refused with the reason. The column keeps
 * accepting whatever the importer and the demo seeder already put in it —
 * nothing is migrated and no existing row changes — but the one new way to
 * write it cannot author a `style` payload.
 */
class PostEditorApiController extends Controller
{
    /**
     * The fields printed with {!! !!} on the storefront, and therefore the
     * fields sanitised — in BOTH languages, off this one constant, so the two
     * cannot drift the way they could if the Arabic list were typed out again.
     *
     * @var list<string>
     */
    public const RICH_FIELDS = ['body'];

    /** The only values `status` may hold. A select stores one of its own options. */
    public const STATUSES = ['published', 'draft'];

    /** The keys `posts.seo` may carry from this screen. An allowlist, not the request. */
    public const SEO_KEYS = ['title', 'desc', 'noindex'];

    /**
     * What an empty Author box means.
     *
     * `posts.author` is NOT NULL with this as its column default
     * (0001_01_01_000000_create_kbb_schema), so "no author" is not an option
     * the schema offers — an empty box means the shop's own name, the way it
     * already does for every article the WordPress import and the demo seeder
     * wrote.
     *
     * FOUND IN A BROWSER, NOT IN A TEST. Clearing the Author box and pressing
     * Save answered a bare SQLSTATE[23000] — "NOT NULL constraint failed:
     * posts.author" — printed into the message strip on the editor, after the
     * owner had written the whole article. Every test had supplied an author,
     * so the suite was green over it. JournalArticleEditorTest pins the empty
     * box now, and pins this constant against the migration's own default so
     * the two cannot drift.
     */
    public const DEFAULT_AUTHOR = 'K-Beauty Bliss';

    /**
     * Everything the screen needs to draw a blank "New article" form.
     *
     * `translations` is the EMPTY shape `translationsForEditor()` produces for
     * an unsaved model, and the screen asks it rather than carrying its own
     * list of translatable columns — which is what makes a field added to
     * `Post::$translatable` grow an Arabic box here for free, and what stops
     * this screen offering a box the server would silently drop.
     */
    public function bootstrap(): JsonResponse
    {
        return response()->json([
            'statuses' => self::STATUSES,
            'translations' => (new Post)->translationsForEditor(),
            'journal_url' => Url::to('/skincare-guide/'),
            'site_base' => rtrim(Url::base(), '/'),
        ]);
    }

    /**
     * One article, in the shape the editor binds to.
     *
     * Not `PostsApiController::index`'s projection: that one is a table and
     * deliberately carries no body. This carries everything the form edits and
     * nothing it does not.
     */
    public function show(int $id): JsonResponse
    {
        $post = Post::query()->findOrFail($id);

        return response()->json(['post' => $this->projection($post)]);
    }

    /**
     * The address a title or a typed slug would actually be published at —
     * asked live, as he types, and answered by the router's own pattern.
     *
     * A GET-shaped question over POST because the screen sends the title, which
     * can be long; it writes nothing.
     */
    public function slug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required_without:slug', 'nullable', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200'],
            'id' => ['nullable', 'integer'],
        ]);

        $verdict = $this->address(
            (string) ($data['slug'] ?? ''),
            (string) ($data['title'] ?? ''),
            isset($data['id']) ? (int) $data['id'] : null,
        );

        return response()->json($verdict, $verdict['ok'] ? 200 : 422);
    }

    /**
     * Create one article.
     *
     * Nothing about this is a mass assignment: every column written below is
     * named, and `seo` is rebuilt from an allowlist rather than taken from the
     * request.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, creating: true);

        $verdict = $this->address(
            (string) ($data['slug'] ?? ''),
            (string) $data['title'],
            null,
        );

        if (! $verdict['ok']) {
            return response()->json($verdict, 422);
        }

        $post = new Post;
        $post->slug = $verdict['slug'];

        $this->fill($post, $data);
        $post->save();

        $post->saveTranslations(TranslationInput::clean(
            $data['translations'] ?? [],
            self::RICH_FIELDS,
        ));

        return response()->json([
            'ok' => true,
            'created' => true,
            'post' => $this->projection($post->refresh()),
        ], 201);
    }

    /**
     * Save an existing article. No slug — see the header.
     */
    public function save(Request $request, int $id): JsonResponse
    {
        $post = Post::query()->findOrFail($id);

        $data = $this->validated($request, creating: false);

        $this->fill($post, $data);
        $post->save();

        $post->saveTranslations(TranslationInput::clean(
            $data['translations'] ?? [],
            self::RICH_FIELDS,
        ));

        return response()->json([
            'ok' => true,
            'created' => false,
            'post' => $this->projection($post->refresh()),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            // Create only. save() is handed the rule too, so a slug posted at
            // an existing article is a 422 that says why rather than a field
            // silently dropped.
            'slug' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:200000'],
            'cover' => ['nullable', 'string', 'max:500'],
            'tag' => ['nullable', 'string', 'max:60'],
            'author' => ['nullable', 'string', 'max:100'],
            'status' => ['required', 'string', 'in:'.implode(',', self::STATUSES)],
            'published_at' => ['nullable', 'date'],
            'seo' => ['nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:200'],
            'seo.desc' => ['nullable', 'string', 'max:400'],
            'seo.noindex' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'array'],
        ], [
            'slug.prohibited' => 'An article keeps the address it was published at. '
                .'To move it, add a redirect at Store → SEO & Meta → Redirects.',
        ]);
    }

    /**
     * Write the named columns. Never $post->fill($request->all()).
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Post $post, array $data): void
    {
        $post->title = trim((string) $data['title']);

        // Plain text, printed escaped at both ends. Stored as typed.
        if (array_key_exists('excerpt', $data)) {
            $excerpt = trim((string) ($data['excerpt'] ?? ''));
            $post->excerpt = $excerpt === '' ? null : $excerpt;
        }

        // Sanitised without exception — the storefront prints it with {!! !!}.
        foreach (self::RICH_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $clean = RichText::clean((string) ($data[$field] ?? ''));

                $post->{$field} = RichText::isBlank($clean) ? null : $clean;
            }
        }

        if (array_key_exists('cover', $data)) {
            $post->cover = $this->coverUrl($data['cover']);
        }

        // `tag` is nullable and an empty tag is a real state: the card simply
        // carries no pill.
        if (array_key_exists('tag', $data)) {
            $tag = trim((string) ($data['tag'] ?? ''));
            $post->tag = $tag === '' ? null : $tag;
        }

        // `author` is not. See DEFAULT_AUTHOR.
        if (array_key_exists('author', $data)) {
            $author = trim((string) ($data['author'] ?? ''));
            $post->author = $author === '' ? self::DEFAULT_AUTHOR : $author;
        }

        /*
         * The select can only store one of its own options: anything else is
         * already a 422 from the `in:` rule above, and this line re-reads the
         * same constant rather than trusting that it was.
         */
        $post->status = in_array($data['status'], self::STATUSES, true)
            ? $data['status']
            : 'draft';

        /*
         * A published article with no date sorts last on an index ordered by
         * `published_at` and prints no date at all. `now()` at the moment of
         * publishing is what the owner means; a draft keeps whatever it had.
         */
        if (array_key_exists('published_at', $data) && $data['published_at'] !== null) {
            $post->published_at = Carbon::parse((string) $data['published_at']);
        } elseif ($post->status === 'published' && $post->published_at === null) {
            $post->published_at = Carbon::now();
        }

        if (array_key_exists('seo', $data)) {
            $post->seo = $this->seo(is_array($data['seo']) ? $data['seo'] : []);
        }
    }

    /**
     * `posts.seo`, rebuilt from an allowlist.
     *
     * Store\PageController::post() reads `title`, `desc`, `canonical`,
     * `og_image` and `noindex` off this bag. This screen offers the first two
     * and the last; `canonical` and `og_image` are NOT offered, because a
     * canonical pointing at another domain and an `og_image` pointing anywhere
     * at all are the two entries on Store → SEO & Meta → SEO Audit's list of
     * unsafe overrides, and there is no reason to open a second door to them
     * from a writing screen.
     *
     * An override that is not set is ABSENT rather than empty, because
     * `post()` branches on `!empty(...)`: an empty string would read as "no
     * override", which is the same outcome by a longer route, and a key that is
     * there and blank is a key somebody later mistakes for a setting.
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

            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * A cover the editor is allowed to write: an image URL, or nothing.
     *
     * See the class header for why this is narrower than the column. `mailto:`
     * is not on the list `RichText` uses for an href, because this one becomes
     * an `<img src>` and a background, never a link.
     */
    private function coverUrl(mixed $given): ?string
    {
        $value = trim((string) ($given ?? ''));

        if ($value === '') {
            return null;
        }

        // A rooted path — /storage/posts/hero.jpg — is this shop's own media
        // library, which is where the picker's URLs come from.
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    /**
     * The verdict on one address: normalise it, then ask the router.
     *
     * `PostImporter::address()` is the SAME call the WordPress import makes on
     * the same column — it decodes a percent-encoded slug, normalises a slug
     * the route's character class cannot match, and answers `reserved` off
     * `PageController::slugPattern()`. A second copy of that logic here is a
     * second copy to go stale, and the two disagreeing would mean the import
     * and the editor publish articles at different addresses.
     *
     * @return array<string, mixed>
     */
    private function address(string $given, string $title, ?int $ignoreId): array
    {
        $title = trim($title);
        $verdict = PostImporter::address($given === '' ? null : $given, $title);

        $slug = $verdict['slug'];

        if ($slug === null) {
            return [
                'ok' => false,
                'slug' => null,
                'reason' => 'shape',
                'message' => 'That title has no letters or digits in it, so there is no address to '
                    .'publish it at. Type a slug by hand.',
            ];
        }

        if ($verdict['reserved']) {
            return [
                'ok' => false,
                'slug' => $slug,
                'reason' => 'reserved',
                'reserved_segment' => $slug,
                'message' => '“/'.$slug.'/” already belongs to the shop, so an article published '
                    .'there could never be opened — the storefront answers that address. Choose a '
                    .'different slug.',
            ];
        }

        $taken = Post::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            return [
                'ok' => false,
                'slug' => $slug,
                'reason' => 'taken',
                'suggestion' => $this->uniqueSlug($slug),
                'message' => 'Another article is already published at “/'.$slug.'/”.',
            ];
        }

        return [
            'ok' => true,
            'slug' => $slug,
            'adjusted' => (bool) $verdict['normalised'],
            'url' => Url::to('/'.$slug.'/'),
            'path' => '/'.$slug.'/',
        ];
    }

    /**
     * `slug-2`, `slug-3`, … — and each candidate is put back through the same
     * reserved check, because a suffix can walk a slug off the reserved list
     * but cannot walk it onto a free one.
     */
    private function uniqueSlug(string $slug): string
    {
        $pattern = '/^(?:'.PageController::slugPattern().')$/';

        for ($n = 2; $n < 200; $n++) {
            $candidate = $slug.'-'.$n;

            if (preg_match($pattern, $candidate) === 1
                && ! Post::query()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $slug.'-'.Str::lower(Str::random(6));
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Post $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'cover' => $post->cover,
            'tag' => $post->tag,
            'author' => $post->author,
            'status' => $post->status,
            'published_at' => optional($post->published_at)->format('Y-m-d\TH:i'),
            'seo' => is_array($post->seo) ? $post->seo : [],
            'url' => Url::to('/'.$post->slug.'/'),
            'path' => '/'.$post->slug.'/',
            'translations' => $post->translationsForEditor(),
        ];
    }
}
