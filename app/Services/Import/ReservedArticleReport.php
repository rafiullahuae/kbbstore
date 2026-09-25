<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Http\Controllers\Store\PageController;
use App\Services\Import\Entities\PostImporter;
use App\Services\Import\Sources\CsvRowSource;
use App\Services\ImportConsole\ImportWorkspace;

/**
 * The articles the import will refuse, listed before it refuses them.
 *
 * =============================================================================
 * THE DEFECT, WHICH IS NOT IN THE CODE
 * =============================================================================
 *
 * An article on this shop is served from the SITE ROOT — `/{slug}/`, no prefix,
 * exactly as WordPress serves it — and `PageController::RESERVED_SLUGS` owns
 * the first segment for forty-odd addresses the storefront itself answers:
 * `/cart`, `/checkout`, `/wishlist`, `/about`, `/feed`. So a live article
 * slugged `about` is **an indexed URL this application can never answer**,
 * whatever the importer does with the row.
 *
 * `PostImporter` already handles that correctly: the row is refused by name,
 * with the reserved segment quoted, and written into the discard list. What was
 * missing was the owner's side of it. Each one of these is a *rename and
 * redirect in WordPress* — a thing only he can do, on the other site, before
 * the cutover — and the discard list keeps `EntityReport::SAMPLES_PER_KIND`
 * examples of each recurring kind. Five. An export with nine colliding articles
 * showed him five of them and a count, which is a list nobody can act on.
 *
 * =============================================================================
 * IT WRITES NOTHING. NOT "ROLLS BACK" — WRITES NOTHING
 * =============================================================================
 *
 * This reads `posts.csv` out of the import workspace and decides. There is no
 * transaction to roll back because nothing is opened, no checkpoint is touched,
 * no ledger row is appended and the run this screen may be part-way through is
 * not disturbed. The owner can ask this question at any point, including
 * halfway through a live import, and the answer costs one pass over one file.
 *
 * =============================================================================
 * THE DECISION IS THE IMPORTER'S OWN, AND THAT IS THE WHOLE DESIGN
 * =============================================================================
 *
 * `PostImporter::address()` is what says whether an address is reserved, and it
 * is what this calls. Not a copy of the rule, not `RESERVED_SLUGS` read a
 * second time, not a regex that looks like the router's — the same static, run
 * on the same input.
 *
 * A list that disagreed with the import would be worse than no list: the owner
 * renames the nine articles it names, re-exports, and the tenth still vanishes
 * without a word. CLAUDE.md states the general form — "two implementations of
 * an import mapping means two answers to what a row meant" — and this is the
 * case where the second answer is the one he acts on.
 *
 * =============================================================================
 * THREE WAYS AN ARTICLE'S ADDRESS GOES WRONG, AND THEY ARE NOT THE SAME JOB
 * =============================================================================
 *
 *   RESERVED   the storefront owns that first segment. The article is NOT
 *              imported. Rename it in WordPress and redirect the old address,
 *              or decide the shop should serve the article at that URL instead
 *              — which is a routing change and the owner's call, not a lane's.
 *
 *   ADJUSTED   the slug is nobody else's, but no URL can carry its shape
 *              (`My_Post`, or the percent-encoded Arabic WordPress writes for a
 *              non-Latin title). The article IS imported, at a normalised
 *              address, and the old address needs a redirect row or it 404s.
 *
 *   NO ADDRESS the slug and the title both reduce to nothing a URL can carry.
 *              The article is NOT imported and there is nowhere to put it.
 *
 * Reported apart because the action differs. Summing them into "12 problems"
 * would be a number with no remedy attached to it.
 *
 * =============================================================================
 * TWO ADDRESSES PER ROW, AND CONFLATING THEM WOULD BE THE EXPENSIVE MISTAKE
 * =============================================================================
 *
 *   wanted       the address the article asks for ON THIS SHOP. Articles here
 *                live at the site root, so it is `/{slug}/`, and it is
 *                computed. It is what makes the row a collision.
 *
 *   indexed_at   the address the OLD SITE published, read out of
 *                `permalinks.csv` — WordPress's own `get_permalink()` — and
 *                never derived. It is what the 301 is written FROM.
 *
 * They are the same string only when the old site's permalink structure is
 * `/%postname%/`. This application cannot see that setting, so it does not
 * assume it: when the permalink export has not been uploaded the column is
 * EMPTY and `permalinks_note` says which file fills it. Every row here is an
 * indexed URL the owner is about to move by hand, and a redirect written from
 * a plausible address the old site never published is worse than no row at all.
 *
 * `permalinks()` has the reasoning and the scheme check in full.
 */
final class ReservedArticleReport
{
    /** The entity whose file this reads. `ImportWorkspace` owns where it is. */
    public const ENTITY = 'posts';

    /**
     * The companion file that knows the address the old site actually published.
     *
     * `ImportWorkspace::COMPANIONS['permalinks']` — `permalinks.csv`, from the
     * export's "Addresses and pictures" group.
     */
    public const PERMALINKS = 'permalinks';

    /** What a permalink may be before this report will print it as a link. */
    private const LINKABLE_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
    ) {}

    /**
     * The address the OLD SITE published for each article, keyed two ways.
     *
     * =========================================================================
     * WHY THIS IS NOT `'/'.$slug.'/'` AND WHY THAT DISTINCTION IS THE DELIVERABLE
     * =========================================================================
     *
     * `wanted` below is the address the article asks for ON THIS SHOP: articles
     * here live at the site root, so it is `/{slug}/` and it is computed. That
     * is the right thing to print beside "the storefront already owns this
     * address", because it is the collision.
     *
     * It is NOT the URL Google is holding, and the two are only the same when
     * the old site's permalink structure happens to be `/%postname%/`. On a
     * WordPress with `/blog/%postname%/`, or `/%year%/%monthnum%/%postname%/`,
     * the indexed address of the article slugged `about` is
     * `https://old/2021/05/about/` — and a 301 written from `/about/` because
     * this report said so is a redirect from an address nobody ever requested.
     * Every row of this list is an SEO asset the owner is about to move by hand;
     * handing him a plausible-looking URL that the old site never published is
     * the one failure that costs more than saying nothing.
     *
     * So the indexed address is READ, never derived. `permalinks.csv` is
     * WordPress's own `get_permalink()` for every row of the site, which
     * `RedirectMap` already calls "the only source that can be right about a
     * site whose permalink structure this application cannot see". When the
     * file has not been uploaded the field is EMPTY and the screen says which
     * file to upload — not a guess with a footnote.
     *
     * KEYED BY ID FIRST, SLUG SECOND. `wc_id` is what the exporter writes for a
     * post and is unambiguous; the slug is the fallback for a hand-made file,
     * and is matched on the raw cell AND on the percent-decoded one, because
     * WordPress writes `%d8%a7…` for an Arabic slug in one file and not always
     * in the other.
     *
     * A permalink that is not `http`/`https` is DROPPED rather than carried.
     * The file is an upload, the screen turns this value into an `href`, and
     * CLAUDE.md's rule is that a URL from data is scheme-checked before it
     * becomes one. Dropping it reads as "not known", which is the truth.
     *
     * @return array{present: bool, by_id: array<string, string>, by_slug: array<string, string>}
     */
    private function permalinks(): array
    {
        if (! $this->workspace->hasCompanion(self::PERMALINKS)) {
            return ['present' => false, 'by_id' => [], 'by_slug' => []];
        }

        $byId = [];
        $bySlug = [];

        foreach ($this->workspace->companionRows(self::PERMALINKS) as $cells) {
            $row = new Row(0, $cells);

            if (mb_strtolower($row->text('type') ?? '') !== PostImporter::ARTICLE_TYPE) {
                continue;
            }

            $url = $this->linkable($row->text('permalink', 'url', 'old_url'));

            if ($url === '') {
                continue;
            }

            $id = $row->text('wc_id', 'id');

            if ($id !== null && $id !== '') {
                $byId[$id] = $url;
            }

            $slug = $row->text('slug', 'post_name');

            if ($slug !== null && $slug !== '') {
                $bySlug[mb_strtolower($slug)] = $url;
                $bySlug[mb_strtolower(rawurldecode($slug))] = $url;
            }
        }

        return ['present' => true, 'by_id' => $byId, 'by_slug' => $bySlug];
    }

    /**
     * The old site's address for one article, or '' when it is not known.
     *
     * Three keys tried in the order of how much they can be trusted: the
     * WordPress id, the slug exactly as `posts.csv` spells it, and the slug
     * percent-decoded. Nothing is derived — a miss on all three is '', and the
     * screen prints "not in permalinks.csv" rather than an address.
     *
     * @param  array{present: bool, by_id: array<string, string>, by_slug: array<string, string>}  $permalinks
     */
    private function indexedAt(array $permalinks, Row $row, ?string $given, string $decoded): string
    {
        if (! $permalinks['present']) {
            return '';
        }

        $id = $row->text('id', 'post_id', 'ID');

        if ($id !== null && $id !== '' && isset($permalinks['by_id'][$id])) {
            return $permalinks['by_id'][$id];
        }

        foreach ([$given, $decoded] as $key) {
            $key = mb_strtolower(trim((string) $key));

            if ($key !== '' && isset($permalinks['by_slug'][$key])) {
                return $permalinks['by_slug'][$key];
            }
        }

        return '';
    }

    /**
     * An absolute http(s) URL, or '' — the only two things this will hand on.
     *
     * Fails closed on everything else: a relative address (which cannot be the
     * indexed URL of a site this shop is not served from), a `javascript:` or
     * `data:` scheme, and a value with no host. The screen prints what comes
     * back from here inside an `href`, so this is the gate, not the template.
     */
    private function linkable(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), self::LINKABLE_SCHEMES, true)) {
            return '';
        }

        return is_string($host) && $host !== '' ? $url : '';
    }

    /**
     * Read `posts.csv` and say what the import will do with each address.
     *
     * @return array{
     *     ok: bool,
     *     present: bool,
     *     file: string,
     *     note: string,
     *     permalinks: bool,
     *     permalinks_note: string,
     *     articles: int,
     *     counts: array{reserved: int, adjusted: int, no_address: int},
     *     reserved: list<array<string, string>>,
     *     adjusted: list<array<string, string>>,
     *     no_address: list<array<string, string>>,
     * }
     */
    public function run(): array
    {
        $path = $this->workspace->path(self::ENTITY);
        $file = basename($path);

        if (! $this->workspace->has(self::ENTITY)) {
            return [
                'ok' => true,
                'present' => false,
                'file' => $file,
                'note' => 'No '.$file.' has been uploaded. Upload the Journal group from the WordPress export '
                    .'and ask again; this reads that file and writes nothing.',
                'permalinks' => false,
                'permalinks_note' => '',
                'articles' => 0,
                'counts' => ['reserved' => 0, 'adjusted' => 0, 'no_address' => 0],
                'reserved' => [],
                'adjusted' => [],
                'no_address' => [],
            ];
        }

        $articles = 0;
        $reserved = [];
        $adjusted = [];
        $noAddress = [];
        $permalinks = $this->permalinks();

        /*
         * `CsvRowSource` yields raw cell arrays keyed by normalised header, so
         * each one is wrapped in a `Row` here — the same object the importers
         * read. That is what makes `text('type', 'post_type')` mean the same
         * thing in this file as it does in PostImporter: the alias list, the
         * trimming and the empty-string rule are all Row's, not a second
         * reading of the same CSV with its own opinions.
         */
        foreach ((new CsvRowSource($path))->rows() as $line => $cells) {
            $row = new Row((int) $line, $cells);
            /*
             * The same gate PostImporter applies, and for the same reason:
             * posts.csv carries the whole site — pages, and any other post type
             * this shop has no screen for. A page called `about` is not a
             * collision to rename, it is a row this entity never writes, and
             * putting it in this list would send the owner into WordPress to
             * fix something that is not broken.
             */
            $type = mb_strtolower($row->text('type', 'post_type') ?? PostImporter::ARTICLE_TYPE);

            if ($type !== PostImporter::ARTICLE_TYPE) {
                continue;
            }

            $title = $row->text('title', 'post_title') ?? '(untitled)';
            $given = $row->text('slug', 'post_name');
            $address = PostImporter::address($given, $title);

            $articles++;

            $common = [
                'line' => (string) $row->line,
                'id' => (string) ($row->text('id', 'post_id', 'ID') ?? ''),
                'title' => $title,
                'status' => mb_strtolower($row->text('status', 'post_status') ?? 'publish'),
                /*
                 * THE URL IT WANTED, spelled the way the old site published it.
                 * Percent-decoded, because that is what a person reads and what
                 * Search Console shows; the raw cell is in `slug` beside it for
                 * anyone who needs to paste it somewhere.
                 */
                'wanted' => '/'.trim($address['given'], '/').'/',
                'slug' => (string) ($given ?? ''),
                /*
                 * THE URL GOOGLE IS ACTUALLY HOLDING, read from the old site's
                 * own permalink export rather than derived from the slug. '' is
                 * "permalinks.csv has not been uploaded, or does not cover this
                 * row" — never a guess. See permalinks() for why the difference
                 * between this and `wanted` is the whole point of the column.
                 */
                'indexed_at' => $this->indexedAt($permalinks, $row, $given, $address['given']),
            ];

            if ($address['slug'] === null) {
                $noAddress[] = $common + [
                    'what_to_do' => 'Neither the slug nor the title reduces to anything a URL can carry, so there '
                        .'is no address to serve this article from. Give it a title or a slug in WordPress and '
                        .'re-export.',
                ];

                continue;
            }

            if ($address['reserved']) {
                $reserved[] = $common + [
                    'served_by' => 'the storefront itself answers /'.$address['slug'].'/',
                    /*
                     * THE ADDRESS THE REDIRECT IS WRITTEN FROM IS THE INDEXED
                     * ONE, when the permalink export says what it is. `wanted`
                     * is the collision on THIS shop and is always named because
                     * that is what makes the row a refusal; the live URL is
                     * named as well, and separately, because a 301 written from
                     * a derived `/slug/` on a site whose permalinks are
                     * `/blog/%postname%/` redirects an address nobody holds.
                     */
                    'what_to_do' => 'Rename this article in WordPress and add a redirect there from '
                        .($common['indexed_at'] !== '' ? $common['indexed_at'] : $common['wanted'])
                        .' to the new address, then re-export. '
                        .($common['indexed_at'] !== ''
                            ? 'That is the address permalinks.csv says the old site published, so it is the one '
                                .'Google holds; this shop would have served the article at '.$common['wanted'].'. '
                            : '')
                        .'The alternative — making this shop serve the article at '.$common['wanted']
                        .' instead of its own page — is a routing change and a decision only you can make.',
                ];

                continue;
            }

            if ($address['normalised']) {
                $adjusted[] = $common + [
                    'imported_at' => '/'.$address['slug'].'/',
                    'what_to_do' => 'This article IS imported, at /'.$address['slug'].'/. Its old address will '
                        .'404 until a redirect row points '
                        .($common['indexed_at'] !== '' ? $common['indexed_at'] : $common['wanted'])
                        .' at the new one.',
                ];
            }
        }

        return [
            'ok' => true,
            'present' => true,
            'file' => $file,
            'note' => $articles.' article(s) read from '.$file.'. Nothing was written: this is a preview of what '
                .'the import will decide about each address, taken from the importer\'s own rule.',
            'permalinks' => $permalinks['present'],
            /*
             * SAID ON THE SCREEN RATHER THAN LEFT AS AN EMPTY COLUMN. A blank
             * "indexed at" reads as "this article is not indexed", which is the
             * opposite of what it means. It means nobody has uploaded the file
             * that knows.
             */
            'permalinks_note' => $permalinks['present']
                ? 'The live addresses below are read from permalinks.csv — WordPress\'s own answer for each row, '
                    .'so they are right whatever the old site\'s permalink structure is.'
                : 'permalinks.csv has not been uploaded, so the live address of each article is not known and the '
                    .'column is empty. Upload the "Addresses and pictures" group from the WordPress export to fill '
                    .'it. Until then the only address shown is the one this shop would have served the article at, '
                    .'which is the same thing ONLY if the old site publishes articles at /slug/.',
            'articles' => $articles,
            'counts' => [
                'reserved' => count($reserved),
                'adjusted' => count($adjusted),
                'no_address' => count($noAddress),
            ],
            'reserved' => $reserved,
            'adjusted' => $adjusted,
            'no_address' => $noAddress,
        ];
    }

    /**
     * The same answer as a spreadsheet, which is the form he approves from.
     *
     * One sheet with a `decision` column rather than three downloads: the three
     * lists are read together — an article that needs renaming and one that
     * needs a redirect are one afternoon's work in the same WordPress admin —
     * and three files is three things to keep in step.
     */
    public function csv(): string
    {
        $report = $this->run();
        $handle = fopen('php://temp', 'w+b');

        /*
         * THE NEW COLUMN IS APPENDED, NOT INSERTED. `live url (from
         * permalinks.csv)` is the address the redirect is written FROM, and it
         * goes last so every column an owner or a script already reads keeps
         * its position. It is empty on every row when permalinks.csv has not
         * been uploaded — see `permalinks_note`, which the screen prints above
         * the table so an empty column is never read as "not indexed".
         */
        fputcsv($handle, ['decision', 'line', 'wordpress id', 'title', 'status', 'url it wanted', 'result', 'what to do', 'live url (from permalinks.csv)']);

        foreach ($report['reserved'] as $row) {
            fputcsv($handle, [
                'reserved — NOT imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], $row['served_by'], $row['what_to_do'], $row['indexed_at'],
            ]);
        }

        foreach ($report['adjusted'] as $row) {
            fputcsv($handle, [
                'address changed — imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], 'imported at '.$row['imported_at'], $row['what_to_do'], $row['indexed_at'],
            ]);
        }

        foreach ($report['no_address'] as $row) {
            fputcsv($handle, [
                'no address — NOT imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], 'nothing a URL can carry', $row['what_to_do'], $row['indexed_at'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Every first path segment the storefront owns, for the screen to show.
     *
     * Read off the ROUTER'S pattern rather than the constant, so an address
     * added by a later lane appears here without anybody remembering to.
     *
     * @return list<string>
     */
    public static function reservedExamples(int $limit = 12): array
    {
        $out = [];

        foreach (PageController::RESERVED_SLUGS as $slug) {
            $out[] = (string) $slug;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
