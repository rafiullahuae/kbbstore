<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Http\Controllers\Store\PageController;
use App\Models\Post;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use App\Support\BodyHeadings;
use App\Support\RichText;
use Illuminate\Support\Str;

/**
 * The Journal, out of `posts.csv` and into `posts`.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Lane GA established, by fetching against a running server, that the whole
 * permalink structure for the Journal is finished and serving nothing:
 * `/skincare-guide/` renders an index with no cards, every `/{slug}/` 404s, and
 * the homepage rail draws stand-in cards linking addresses that 404. The reason
 * is not the routing and not the views. It is that `posts` is empty and there
 * was NO WAY TO PUT ANYTHING IN IT: no seeder, no importer, and an admin Blog
 * Posts screen that says in its own comment that it is read-only. See
 * docs/GA-SKINCARE-GUIDE.md §6.
 *
 * `posts.csv` is one of the seven files docs/WP-EXPORT-CONTRACT.md marks as a
 * **gap** — written by Lane GE's plugin, opened by nothing. This is the entity
 * that opens it.
 *
 * ── THE HAZARD THAT SHAPES THE WHOLE CLASS: ARTICLES LIVE AT THE SITE ROOT ──
 *
 * The owner settled this in Phase 9 and 2.60.109 records it: an article is
 * served from `/{slug}/`, with no prefix, exactly as the live WordPress site
 * serves it. That address is shared with the entire storefront, so
 * PageController::slugPattern() puts a negative lookahead over
 * RESERVED_SLUGS in front of the catch-all — `/cart`, `/checkout`,
 * `/wishlist`, `/about`, `/feed` and forty more belong to the shop.
 *
 * So a live article whose slug is a reserved first segment is **an indexed URL
 * this application can never serve**, whatever this importer does with the row.
 * Writing it anyway would put a row in the database that no request can ever
 * reach — the worst outcome available, because the report would say "created"
 * and the owner would have no way to find out otherwise until Search Console
 * did. So the row is REFUSED, by name, with the reserved segment quoted and the
 * remedy spelled out; and the same fact is written into the discard list, which
 * is the channel Phase 13 says the owner APPROVES rather than merely reads.
 *
 * That follows SlugGuard's own precedent for the same class of problem: "two
 * real things want one slug and only one can have it. Always refused. Which one
 * keeps it is a content decision and the importer has no basis for making it."
 *
 * THE CHECK IS THE ROUTE'S OWN PATTERN, not a copy of the list. slugPattern()
 * is what the router is actually given, it already folds in the configurable
 * admin path (KBB_ADMIN_PATH), and a lane that adds a route and a reserved slug
 * tomorrow moves this importer with it for free. A second copy of the list here
 * would be a second copy to go stale.
 *
 * ── A SLUG THAT IS MERELY THE WRONG SHAPE IS A DIFFERENT DECISION ───────────
 *
 * `My_Post`, `Cleansing-101`, or the percent-encoded `%d8%a7%d9%84…` that
 * WordPress writes for a non-Latin title are not reserved — nothing else owns
 * them — but slugPattern()'s character class cannot match them either, so they
 * are equally unservable. Refusing those would lose the article for a reason
 * the owner cannot act on (the shape is WordPress's, not a collision), so they
 * are NORMALISED and the change is reported as an ADJUSTMENT with the before
 * and the after: the article is published at an address that works, and the
 * owner is told the old address will need a redirect row. Imported, and not
 * what the export said, is the definition of `adjusted`.
 *
 * ── WHAT THIS DOES NOT IMPORT, AND WHY IT SAYS SO ROW BY ROW ────────────────
 *
 * `posts.csv` carries the blog AND the pages AND any other post type the shop
 * has — one file with a `type` column, by the exporter's own design. This
 * entity writes ARTICLES ONLY. A WordPress `page` is refused with its title and
 * its content length named in the discard list, because importing pages is a
 * decision and not a mapping: this application already ships its own /about/,
 * /delivery/, /faqs/, /privacy-policy/ and /terms-and-conditions/, their copy
 * is byte-pinned by tests/Feature/StorefrontEnglishUnchangedTest, and deciding
 * which of two /about/ pages the shop serves is the owner's call, not this
 * importer's. Refused rather than skipped: a skipped row moves no tally, and
 * the runner records a row that moves no tally as UNACCOUNTED — which is the
 * alarm reserved for a row that vanished, and it must not be spent on a row
 * this importer deliberately declined.
 *
 * ── IDEMPOTENCE ─────────────────────────────────────────────────────────────
 *
 * Matched on `posts.source_post_id`, which is unique and which is the
 * WordPress post id unchanged. Every write goes through ImportContext::apply(),
 * so a second pass over an unchanged export reports `unchanged` for every row
 * from Eloquent's own dirty check against the database rather than from this
 * class deciding it did nothing.
 */
final class PostImporter extends EntityImporter
{
    /**
     * The one post type this entity writes.
     *
     * A denylist would be wrong here in a way it is right in the exporter. The
     * exporter's job is to carry everything off the old site, so it denies what
     * it knows another file holds and takes the rest. This entity's job is to
     * write rows into `posts`, which is the Journal and nothing else, so it
     * takes what it knows how to serve and NAMES the rest.
     */
    public const ARTICLE_TYPE = 'post';

    /** WordPress statuses that mean "the public can read this". */
    private const PUBLISHED = ['publish', 'published'];

    public function name(): string
    {
        return 'posts';
    }

    public function conventionalFile(): string
    {
        return 'posts.csv';
    }

    /**
     * Articles that came from WordPress.
     *
     * On `source_post_id` rather than on the table, for the reason the base
     * class gives: anything an admin or a test writes by hand has no external
     * id, and counting it would read as an import that produced rows the file
     * did not supply.
     */
    public function countImported(): ?int
    {
        return Post::query()->whereNotNull('source_post_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $id = $row->requireId('id', 'id', 'post_id', 'ID');
        $report = $context->report->for($this->name());

        $type = mb_strtolower($row->text('type', 'post_type') ?? self::ARTICLE_TYPE);
        $title = $row->text('title', 'post_title');
        $rawBody = $row->text('content', 'post_content', 'body');

        if ($type !== self::ARTICLE_TYPE) {
            $report->discarded(
                'a WordPress post type this shop has no screen for -- posts.csv carries the whole site and '
                .'this entity writes the Journal only, so these rows are in the export and will not be in '
                .'the database, and nothing else in this report mentions them',
                $row->line,
                $this->identify($row),
                $type,
                ($title ?? '(untitled)').' -- '.mb_strlen((string) $rawBody).' characters of content',
            );

            throw RowRejected::because(
                "post type '".$type."' is not an article — this entity writes the Journal (`posts`) only. "
                .'A WordPress page is not imported because this shop already ships its own /about/, '
                .'/delivery/, /faqs/, /privacy-policy/ and /terms-and-conditions/ pages, and which of the '
                .'two the shop serves is a decision rather than a mapping. The row is named in the '
                .'discard list with its title and its size.'
            );
        }

        if ($title === null) {
            throw RowRejected::because(
                'title is required and this row has none — `posts.title` is NOT NULL and an article with '
                .'no title has nothing for the index card, the <h1> or the <title> tag'
            );
        }

        $slug = $this->settleSlug($row, $title, $report);

        $post = Post::query()->where('source_post_id', $id)->first() ?? new Post;

        if (! $post->exists) {
            /*
             * `posts.slug` is unique. SlugGuard is the one place in this
             * importer that decides what happens when the slug a row wants is
             * already taken, and it distinguishes the two cases that must not
             * be conflated: another WordPress post holds it (refused — a
             * content decision), or a row with no WordPress origin holds it
             * (refused by default, adopted with --adopt-by-slug and reported
             * by name). Reusing it rather than re-deciding it here is the point.
             */
            $adopt = SlugGuard::resolve(
                Post::query(),
                'posts',
                'source_post_id',
                $slug,
                $id,
                $context,
                $this->name(),
            );

            if ($adopt !== null) {
                $post = Post::query()->findOrFail($adopt);
            }
        }

        $status = mb_strtolower($row->text('status', 'post_status') ?? 'publish');

        /*
         * `draft` IN AND `draft` OUT IS NOT AN ADJUSTMENT, it is a faithful
         * import, and reporting it as one would print "status: draft -> draft"
         * in the list the owner is asked to read. An adjustment list with a
         * no-op in it is a list that gets skimmed.
         */
        if (! in_array($status, self::PUBLISHED, true) && $status !== 'draft') {
            /*
             * Everything that is not `publish` is imported as a draft, and that
             * includes `future`. `PageController::blog()` filters on
             * status = 'published' and does NOT filter on the date, so a
             * scheduled post imported as published would be on the index the
             * moment the import finished — publishing the owner's unfinished
             * work on their behalf. A draft keeps the row, keeps the slug
             * reserved against a later article stealing it, and serves nothing.
             */
            $report->adjusted(
                "a WordPress status this shop does not serve -- imported as a draft, so the article is in "
                .'the shop and is not on /skincare-guide/ until it is published',
                $row->line,
                $this->identify($row),
                'status',
                $status,
                'draft',
            );
        }

        $body = $this->cleanBodyReported($rawBody, 'content', $row, $report);

        $outcome = $context->apply($post, [
            'source_post_id' => $id,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $this->cleanBodyReported($row->text('excerpt', 'post_excerpt'), 'excerpt', $row, $report),
            'body' => $body,
            'cover' => $row->text('image', 'cover', 'thumbnail', 'featured_image'),
            'tag' => $this->settleTag($row, $report),
            'author' => $row->text('author_name', 'author') ?? 'K-Beauty Bliss',
            'status' => in_array($status, self::PUBLISHED, true) ? 'published' : 'draft',
            'published_at' => $this->publishedAt($row, $context),
        ]);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $id, (int) $post->id);
    }

    /**
     * The address this article will actually be served at, or a refusal.
     *
     * Three outcomes, and they are deliberately not the same one:
     *
     *   the slug is servable        -> used unchanged, which is the case that
     *                                  keeps the indexed URL working
     *   the slug is the wrong SHAPE -> normalised, reported as an adjustment
     *   the slug is RESERVED        -> refused, reported as a discard
     *
     * @throws RowRejected
     */
    private function settleSlug(Row $row, string $title, \App\Services\Import\EntityReport $report): string
    {
        $address = self::address($row->text('slug', 'post_name'), $title);
        $candidate = $address['given'];
        $slug = (string) $address['slug'];

        if ($address['slug'] === null) {
            throw RowRejected::because(
                'no usable slug: '.($candidate === '' ? 'this row carries none' : "'".$candidate."'")
                .' and the title reduces to nothing a URL can carry. An article is served from '
                .'/{slug}/ and there is no address to serve this one from.'
            );
        }

        if ($address['normalised']) {
            $report->adjusted(
                'a slug this application cannot serve, normalised -- the article is published at the new '
                .'address and the old one will 404 until a redirect row is added for it',
                $row->line,
                $this->identify($row),
                'slug',
                $candidate === '' ? '(none; derived from the title)' : $candidate,
                $slug,
            );
        }

        if ($address['reserved']) {
            /*
             * ONE KIND FOR THE WHOLE CLASS OF LOSS, with the address in the
             * SAMPLE and not in the headline. A kind is the recurring
             * sentence EntityReport counts by; putting the slug in it would
             * produce one kind per article, each with a count of 1, which is
             * the shape that makes a discard list unreadable and therefore
             * unapproved.
             */
            $report->discarded(
                'an article whose address this storefront already owns -- that first path segment is served '
                .'by the shop itself, so the article cannot be published at the URL it is indexed at and is '
                .'NOT in the database',
                $row->line,
                $this->identify($row),
                'slug',
                $title.' (/'.$slug.'/)',
            );

            throw RowRejected::because(
                "slug '".$slug."' is a first path segment this storefront already serves "
                .'(PageController::RESERVED_SLUGS), so /'.$slug.'/ is the shop\'s page and never this '
                .'article. Importing it would write a row no request can reach. Either rename the article '
                .'in WordPress and add a redirect from the old address, or say which of the two /'.$slug
                .'/ should be — only the owner can settle that.'
            );
        }

        return $slug;
    }

    /**
     * The address a `posts.csv` row wants, decided and nothing else.
     *
     * =========================================================================
     * WHY THIS IS A PURE STATIC AND NOT LEFT INSIDE settleSlug()
     * =========================================================================
     *
     * The three outcomes below are also the question Store → Import's preview
     * has to answer for the owner without importing anything:
     * `App\Services\Import\ReservedArticleReport` runs over `posts.csv` and
     * lists every article whose address this storefront already owns, with its
     * title and the URL it wanted, because each one is a rename-and-redirect in
     * WordPress and only he can do it.
     *
     * That list HAS to agree with what the importer would actually do, and the
     * only way to guarantee that is for both to run the same code. CLAUDE.md
     * has the general form of this already — "two implementations of an import
     * mapping means two answers to what a row meant" — and a discard list that
     * disagreed with the import would be the worst possible version of it: the
     * owner renames nine articles in WordPress and the tenth still vanishes.
     *
     * So the DECISION lives here and takes no report, no context and no row:
     * given what the export says and the article's title, what address would
     * this be served at, and is that address one the shop already owns.
     * settleSlug() adds the reporting; the preview adds the listing.
     *
     * @return array{given: string, slug: string|null, normalised: bool, reserved: bool}
     */
    public static function address(?string $given, string $title): array
    {
        /*
         * WordPress percent-encodes the slug of a non-Latin title, so the raw
         * cell for an Arabic article is `%d8%a7%d9%84…`. Decoding first means
         * the normalisation below has letters to work with instead of hex.
         */
        $candidate = $given === null ? '' : trim(rawurldecode($given));
        $slug = mb_strtolower($candidate);
        $normalised = false;

        if (! self::wellShaped($slug)) {
            /*
             * NORMALISE FROM THE TITLE WHEN THE SLUG HAS NO LATIN IN IT AT ALL.
             * Str::slug() transliterates, so the percent-encoded Arabic slug
             * of a real article comes back as `alaanay-balbshr` -- a valid
             * address, and one no human will ever recognise. The title is the
             * better basis exactly then, and only then: `SPF_50_Every_Day`
             * carries information the title may not, so a slug with letters or
             * digits in it stays the basis.
             */
            $basis = preg_match('/[a-z0-9]/i', $candidate) === 1 ? $candidate : $title;
            $slug = Str::slug($basis !== '' ? $basis : $title);

            if (! self::wellShaped($slug)) {
                return ['given' => $candidate, 'slug' => null, 'normalised' => false, 'reserved' => false];
            }

            $normalised = true;
        }

        return [
            'given' => $candidate,
            'slug' => $slug,
            'normalised' => $normalised,
            /*
             * THE ROUTER'S OWN PATTERN, not a second copy of RESERVED_SLUGS. A
             * slug that is well shaped and still fails this is one the
             * storefront owns.
             */
            'reserved' => preg_match('/^(?:'.PageController::slugPattern().')$/', $slug) !== 1,
        ];
    }

    /** Lowercase letters, digits and single hyphens — what slugPattern()'s character class allows. */
    private static function wellShaped(string $slug): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }

    /**
     * The one label the index card and the article header print.
     *
     * `posts.tag` is a single string and a WordPress post has any number of
     * categories and tags. The first category wins, because that is what a
     * WordPress theme prints above the title; a tag is the fallback. Everything
     * else is named in the discard list rather than dropped in silence — a
     * three-category article whose other two labels simply vanish is exactly
     * the kind of loss this report exists to make visible.
     */
    private function settleTag(Row $row, \App\Services\Import\EntityReport $report): ?string
    {
        $categories = $row->list(',', 'categories', 'category');
        $tags = $row->list(',', 'tags', 'tag', 'post_tag');

        $all = array_values(array_unique(array_merge($categories, $tags)));

        if ($all === []) {
            return null;
        }

        $kept = $all[0];
        $lost = array_slice($all, 1);

        if ($lost !== []) {
            $report->discarded(
                'the other labels on an article -- `posts.tag` holds one and this shop has no post '
                .'taxonomy, so every category and tag after the first is in the export and not in the '
                .'database',
                $row->line,
                $this->identify($row),
                'categories/tags',
                implode(', ', $all),
                $kept,
            );
        }

        return Str::title(str_replace('-', ' ', $kept));
    }

    /**
     * When the article was published, in UTC.
     *
     * `date_created_gmt` is preferred and is read AS UTC. docs/GE-WP-EXPORTER.md
     * records that HPOS stores every date in GMT and `wp_posts` stores both, so
     * the GMT column is the one that needs no guess about which timezone the
     * old site was configured for; the local column is the fallback and is read
     * in the source timezone the owner chose on the import screen.
     */
    private function publishedAt(Row $row, ImportContext $context): ?\Carbon\CarbonImmutable
    {
        return $row->date('date_created_gmt', 'UTC', 'date_created_gmt', 'post_date_gmt')
            ?? $row->date('date_created', $context->timezone(), 'date_created', 'post_date', 'published_at');
    }

    /**
     * The allowlist over an imported article body, and a note of what it took.
     *
     * TWO STEPS, AND THE ORDER IS THE POINT.
     *
     * RichText::clean() allows h2..h6 and does NOT allow h1 — deliberately,
     * because "the page template owns the page's single h1". store/post.blade
     * already emits one for the title. But an h1 is not in RichText's hostile
     * set either, so it is UNWRAPPED rather than dropped: cleaning an imported
     * WordPress body straight off would turn every "Heading 1" block into
     * ordinary paragraph text and flatten the article's outline. App\Support\
     * BodyHeadings says so in as many words, and exists because imported bodies
     * carry h1s.
     *
     * So the h1 is demoted to an h2 BEFORE the allowlist runs. The heading
     * survives as a heading, the page still has exactly one h1, and the
     * executable content still does not survive. Demoting at import as well as
     * at render is not redundant: the render-time call protects admin-typed
     * copy, and this one is what lets the allowlist keep the heading at all.
     *
     * WHY CLEAN AT ALL. store/post.blade.php renders the body with `{!! !!}`.
     * A WordPress export carries whatever a plugin put in the post — the volume
     * fixture has seven product descriptions with a <script> in them at the
     * density a real export does — and an article body is the same raw channel
     * onto a public page that ProductImporter already cleans for exactly this
     * reason.
     *
     * Reported by LENGTH, like ProductImporter's: a diff of kilobytes of HTML
     * is not something a console report can carry, and the question the owner
     * is asking is "did this article lose anything, and how much".
     */
    private function cleanBodyReported(?string $html, string $field, Row $row, \App\Services\Import\EntityReport $report): ?string
    {
        if ($html === null) {
            return null;
        }

        $demoted = BodyHeadings::demoteH1($html);

        if ($demoted !== $html) {
            $report->adjusted(
                'an <h1> inside an article body, demoted to <h2> -- store/post.blade.php emits the page\'s '
                .'own <h1> for the title, so a second one competes with it',
                $row->line,
                $this->identify($row),
                $field,
                '<h1>',
                '<h2>',
            );
        }

        $cleaned = RichText::clean($demoted);

        if ($cleaned !== $demoted) {
            $report->discarded(
                'HTML the allowlist removed -- an article body is rendered raw onto a public page, so the '
                .'import strips what a browser would execute and the imported article is not byte-for-byte '
                .'what WordPress held',
                $row->line,
                $this->identify($row),
                $field,
                mb_strlen($demoted).' characters: '.$demoted,
                mb_strlen($cleaned).' characters kept',
            );
        }

        return $cleaned;
    }
}
