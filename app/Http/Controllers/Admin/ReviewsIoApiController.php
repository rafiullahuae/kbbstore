<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reviews\ReviewCsvImport;
use App\Support\ReviewStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reviews → Export / Import.
 *
 * ADMIN ONLY, AND NOT INCIDENTALLY. The export hands over every reviewer's
 * email address in one file and the import writes rows that appear on public
 * product pages and feed the aggregateRating published to Google. These routes
 * live in routes/reviews-screens-admin.php, mounted inside the `admin-api`
 * group in routes/web.php — the one already wrapped in `auth:admin`. Nothing
 * here may move to routes/api.php: CLAUDE.md records that everything under
 * /api/* is unauthenticated by design, and `reviews` is on its list of tables
 * that have already leaked.
 *
 * WHY THIS EXPORT EXISTS WHEN THERE IS ALREADY ONE.
 * Admin\ReviewsApiController::export() (Lane AM) streams the MODERATION
 * SCREEN'S CURRENT VIEW — the rows the owner is looking at, in the order they
 * are looking at them, with the filters they applied. That is the right export
 * for that screen and it has not been touched or replaced.
 *
 * It is not, however, re-importable, and it was never meant to be: it carries
 * `id` and `source` but not `source_id`, and `source_id` is half of the key
 * that makes a re-import an update instead of a second copy. It also has no
 * `sku`, so a file exported from this store could not be re-imported into a
 * catalogue that had been rebuilt. This export is the round-trip one. The two
 * have different jobs and the screens say which is which.
 *
 * WHAT MAKES THE ROUND TRIP WORK, precisely: `review_id` identifies the row
 * here, (`source`, `source_id`) identifies it in the system it came from, and
 * `product_sku` identifies the product independently of either store's ids.
 * ReviewCsvImport::resolveKey() and ::resolveProduct() consume exactly those
 * columns, in that order.
 */
class ReviewsIoApiController extends Controller
{
    /** Rows streamed per round trip. Matches the sibling export. */
    private const EXPORT_CHUNK = 500;

    /** The hard ceiling on one export, as rows written. */
    private const EXPORT_MAX = 50000;

    /**
     * The upload ceiling, in kilobytes, as Laravel's `max:` rule counts them.
     *
     * 4 MB is roughly 25,000 review rows of ordinary length — comfortably past
     * ReviewCsvImport::MAX_ROWS, so the row limit is what an oversized file
     * actually trips, with a message that says how to split it. The byte limit
     * is the backstop that keeps a 900 MB upload from being read at all.
     */
    private const UPLOAD_MAX_KB = 4096;

    /** The re-importable column order. Also the documentation the screen prints. */
    private const COLUMNS = [
        'review_id', 'source', 'source_id', 'status', 'rating', 'product_id', 'product_sku',
        'product', 'author', 'email', 'verified', 'title', 'content', 'reply', 'helpful', 'created_at',
    ];

    /**
     * GET /admin-api/reviews-io/summary
     *
     * What is here to export, and what the two halves understand. The screen
     * draws its column documentation from this rather than carrying a second
     * copy that can drift from the importer.
     */
    public function summary(): JsonResponse
    {
        $counts = ['all' => 0];

        foreach (ReviewStatus::ALL as $status) {
            $counts[$status] = 0;
        }

        foreach (DB::table('reviews')->select('status')->selectRaw('COUNT(*) as n')->groupBy('status')->get() as $row) {
            $key = ReviewStatus::normalise((string) $row->status);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->n;
            $counts['all'] += (int) $row->n;
        }

        $sources = DB::table('reviews')
            ->select('source')
            ->selectRaw('COUNT(*) as n')
            ->groupBy('source')
            ->orderByDesc('n')
            ->limit(20)
            ->get()
            ->map(fn ($r) => ['source' => (string) ($r->source ?? ''), 'reviews' => (int) $r->n])
            ->all();

        return response()->json([
            'counts' => $counts,
            'sources' => $sources,
            'business' => (int) DB::table('reviews')->whereNull('product_id')->count(),
            'products_with_reviews' => (int) DB::table('reviews')->whereNotNull('product_id')->distinct()->count('product_id'),
            'columns' => self::COLUMNS,
            'aliases' => $this->aliasDocumentation(),
            'limits' => [
                'max_rows' => ReviewCsvImport::MAX_ROWS,
                'max_kb' => self::UPLOAD_MAX_KB,
                'batch' => ReviewCsvImport::BATCH,
                'export_max' => self::EXPORT_MAX,
            ],
            'timezone' => (string) (config('app.timezone') ?: 'UTC'),
        ]);
    }

    /**
     * GET /admin-api/reviews-io/export
     *
     * Streamed in chunks, and bounded by counting rows written rather than by
     * ->limit(): chunk() walks with forPage(), which SETS limit and offset
     * instead of intersecting with one already on the builder, so a limit
     * placed here would be overwritten on the first round trip and the export
     * would stream the whole table. That mistake is recorded in
     * CustomersApiController, which found it, and in the sibling export above.
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_merge(['all'], ReviewStatus::ALL))],
            'emails' => ['sometimes', 'in:0,1'],
        ]);

        $status = (string) ($data['status'] ?? 'all');

        // Emails are included by default because they are part of what makes
        // the file re-importable into another copy of this store. The switch is
        // for the other case — handing the file to somebody outside the
        // business — and the screen says which is which.
        $withEmail = (string) ($data['emails'] ?? '1') !== '0';

        $query = DB::table('reviews')
            ->leftJoin('products', 'products.id', '=', 'reviews.product_id')
            ->select([
                'reviews.id', 'reviews.source', 'reviews.source_id', 'reviews.status', 'reviews.rating',
                'reviews.product_id', 'reviews.author_name', 'reviews.author_email', 'reviews.verified',
                'reviews.title', 'reviews.content', 'reviews.reply', 'reviews.helpful', 'reviews.created_at',
                'products.sku as product_sku', 'products.name as product_name',
            ])
            ->orderBy('reviews.id');

        if ($status !== 'all') {
            $query->where('reviews.status', '=', $status);
        }

        $filename = 'kbb-reviews-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query, $withEmail): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM, or Excel on Windows reads an accented or Arabic
            // reviewer name as mojibake — and this store has both. The importer
            // strips it again; normaliseHeader() says so.
            fwrite($out, "\xEF\xBB\xBF");

            $columns = self::COLUMNS;

            if (! $withEmail) {
                $columns = array_values(array_filter($columns, static fn (string $c): bool => $c !== 'email'));
            }

            fputcsv($out, $columns);

            $written = 0;

            $query->chunk(self::EXPORT_CHUNK, function ($chunk) use ($out, &$written, $withEmail): bool {
                foreach ($chunk as $r) {
                    if ($written >= self::EXPORT_MAX) {
                        return false;
                    }

                    $written++;

                    $row = [
                        $r->id,
                        $r->source ?? '',
                        $r->source_id ?? '',
                        ReviewStatus::normalise((string) $r->status),
                        (int) $r->rating,
                        $r->product_id ?? '',
                        $r->product_sku ?? '',
                        $r->product_name ?? '',
                        $r->author_name ?? '',
                        $r->author_email ?? '',
                        $r->verified ? 'yes' : 'no',
                        $r->title ?? '',
                        $r->content ?? '',
                        $r->reply ?? '',
                        (int) $r->helpful,
                        (string) ($r->created_at ?? ''),
                    ];

                    if (! $withEmail) {
                        unset($row[9]);
                        $row = array_values($row);
                    }

                    fputcsv($out, array_map($this->csvCell(...), $row));
                }

                return true;
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * POST /admin-api/reviews-io/import
     *
     * `mode=check` validates and reports without writing a row, which is the
     * button the owner should press first and the one the screen puts first.
     */
    public function import(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                /*
                 * Three separate guards, none of which is the other's job.
                 *
                 * `file` proves an upload actually arrived rather than a string
                 * of text posted at the endpoint; `max` bounds the bytes before
                 * anything is read; `mimetypes` bounds the CONTENT TYPE.
                 *
                 * text/plain is on the list and has to be: a .csv written by
                 * Excel, by Numbers, or by a WordPress plugin is very often
                 * sniffed as text/plain, and refusing it would refuse most real
                 * WooCommerce exports. That is why the extension is checked
                 * too, below — neither signal alone is worth much, and the
                 * parser itself trusts nothing either way.
                 */
                'file' => ['required', 'file', 'max:' . self::UPLOAD_MAX_KB, 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/comma-separated-values'],
                'mode' => ['sometimes', 'in:check,import'],
                'on_duplicate' => ['sometimes', 'in:skip,update'],
                'default_source' => ['sometimes', 'string', 'max:64'],
                'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
                'allow_business' => ['sometimes', 'in:0,1'],
            ]);

            $file = $request->file('file');

            $extension = mb_strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($extension, ['csv', 'txt', 'tsv'], true)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'That is a .' . ($extension === '' ? 'file with no extension' : $extension)
                        . '. This screen reads a CSV — export one from WooCommerce, or save your spreadsheet as CSV.',
                ], 422);
            }

            $report = (new ReviewCsvImport)->run($file->getRealPath(), [
                'mode' => (string) ($data['mode'] ?? 'import'),
                'on_duplicate' => (string) ($data['on_duplicate'] ?? 'skip'),
                'default_source' => (string) ($data['default_source'] ?? 'wp_comment'),
                'timezone' => (string) ($data['timezone'] ?? config('app.timezone') ?: 'UTC'),
                'allow_business' => (string) ($data['allow_business'] ?? '0') === '1',
            ]);

            $report['file'] = (string) $file->getClientOriginalName();

            return response()->json($report, ($report['ok'] ?? true) ? 200 : 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'review_import_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * The header spellings the importer understands, for the screen to print.
     *
     * Read off ReviewCsvImport rather than written out again here: a second
     * copy of this list is a list that goes stale, and the owner reading it is
     * doing so precisely because their file did not import.
     *
     * @return array<string, list<string>>
     */
    private function aliasDocumentation(): array
    {
        return ReviewCsvImport::ALIASES;
    }

    /**
     * Neutralise a spreadsheet formula before it reaches a cell.
     *
     * Excel, LibreOffice and Sheets all execute a cell beginning =, +, - or @,
     * and a leading tab or carriage return sneaks past a naive check of the
     * first character. EVERY value in this export is typed by the public — the
     * review body most of all. The same guard, for the same reason, as
     * ReviewsApiController::csvCell(), which established it here; and
     * ReviewCsvImport::cell() takes the apostrophe back off on the way in, so
     * the round trip does not corrupt a little more text on each pass.
     */
    private function csvCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'" . $string;
        }

        return $string;
    }
}
