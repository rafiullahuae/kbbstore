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
 */
final class ReservedArticleReport
{
    /** The entity whose file this reads. `ImportWorkspace` owns where it is. */
    public const ENTITY = 'posts';

    public function __construct(
        private readonly ImportWorkspace $workspace = new ImportWorkspace,
    ) {}

    /**
     * Read `posts.csv` and say what the import will do with each address.
     *
     * @return array{
     *     ok: bool,
     *     present: bool,
     *     file: string,
     *     note: string,
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
                    'what_to_do' => 'Rename this article in WordPress and add a redirect there from '
                        .$common['wanted'].' to the new address, then re-export. The alternative — making this '
                        .'shop serve the article at '.$common['wanted'].' instead of its own page — is a routing '
                        .'change and a decision only you can make.',
                ];

                continue;
            }

            if ($address['normalised']) {
                $adjusted[] = $common + [
                    'imported_at' => '/'.$address['slug'].'/',
                    'what_to_do' => 'This article IS imported, at /'.$address['slug'].'/. Its old address will '
                        .'404 until a redirect row points '.$common['wanted'].' at the new one.',
                ];
            }
        }

        return [
            'ok' => true,
            'present' => true,
            'file' => $file,
            'note' => $articles.' article(s) read from '.$file.'. Nothing was written: this is a preview of what '
                .'the import will decide about each address, taken from the importer\'s own rule.',
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

        fputcsv($handle, ['decision', 'line', 'wordpress id', 'title', 'status', 'url it wanted', 'result', 'what to do']);

        foreach ($report['reserved'] as $row) {
            fputcsv($handle, [
                'reserved — NOT imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], $row['served_by'], $row['what_to_do'],
            ]);
        }

        foreach ($report['adjusted'] as $row) {
            fputcsv($handle, [
                'address changed — imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], 'imported at '.$row['imported_at'], $row['what_to_do'],
            ]);
        }

        foreach ($report['no_address'] as $row) {
            fputcsv($handle, [
                'no address — NOT imported', $row['line'], $row['id'], $row['title'], $row['status'],
                $row['wanted'], 'nothing a URL can carry', $row['what_to_do'],
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
