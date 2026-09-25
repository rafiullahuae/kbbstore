<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\NewsletterSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Growth & Marketing → Newsletter. */
class NewsletterApiController extends Controller
{
    public function __construct(private NewsletterSettings $newsletter) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = ModuleSchema::tabs(
            NewsletterSettings::SCHEMA,
            NewsletterSettings::TABS,
            $this->newsletter->all(),
            NewsletterSettings::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            'stats' => $this->stats(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(NewsletterSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $this->newsletter->save($data['settings']);

        return response()->json(['ok' => true, 'stats' => $this->stats()]);
    }

    /**
     * The list as a CSV download.
     *
     * Streamed rather than assembled in memory: this table only grows, and a
     * shared host will not thank us for holding thirty thousand rows to build
     * a string that is written straight out again.
     *
     * EVERY CELL GOES THROUGH csvCell(). Both columns that carry text here are
     * written by the public: `email` comes off the signup form, and `source` is
     * taken straight from the request by Store\SubscribeController with nothing
     * but a 40-character truncation applied — and `nl_source_tag` defaults to
     * on, so it is populated on a stock install. An unauthenticated POST to
     * /subscribe could therefore put `=cmd|'/c calc'!A1` in a cell that the
     * owner's spreadsheet runs when they open this file. fputcsv() does not
     * help: it quotes the field for CSV, and Excel strips that quoting before
     * it decides the cell is a formula.
     *
     * The sibling exports (OrdersApiController and CustomersApiController) have
     * guarded their cells since they were written; this one was the odd one out.
     *
     * ---------------------------------------------------------------------
     * CONFIRMED ADDRESSES ONLY (Lane EE)
     * ---------------------------------------------------------------------
     * This used to export the whole table. That was correct when every row in
     * it was `subscribed`, and became a leak the moment double opt-in started
     * writing `pending` rows: this file is downloaded in order to be pasted
     * into a mail-merge or an email platform, so an unconfirmed address in it
     * is an unconfirmed address that gets marketed to — through a route that
     * never touches any of the guards in NewsletterList.
     *
     * The filter is NewsletterList::marketable(), the same builder the
     * application's own sending path uses, and NOT a second copy of its
     * conditions. Two places that decide who may be mailed is how they come to
     * disagree, and the disagreement would show up as "the CSV has people the
     * shop would not email", which nobody would notice until a complaint.
     *
     * `confirmed_at` is now a column in the file. Without it the owner cannot
     * tell, from the export alone, that anything was withheld — and silently
     * handing back fewer rows than the screen's total says is its own kind of
     * lie. The stats block below reports both numbers for the same reason.
     */
    public function export(): StreamedResponse
    {
        $name = 'kbb-subscribers-' . now()->format('Y-m-d') . '.csv';

        $cell = $this->csvCell(...);

        $callback = static function () use ($cell): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'source', 'status', 'signed_up', 'confirmed_at']);

            \App\Services\NewsletterList::marketable()
                ->orderBy('id')
                ->chunk(500, static function ($rows) use ($out, $cell) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $cell($row->email),
                            $cell($row->source),
                            $cell($row->status),
                            $cell($row->created_at),
                            $cell($row->confirmed_at),
                        ]);
                    }
                });

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /**
     * Neutralise a spreadsheet formula before it reaches a cell.
     *
     * Excel, LibreOffice and Sheets all execute a cell beginning =, +, - or @,
     * and a leading tab or carriage return sneaks past a naive check of the
     * first character. Prefixing a single quote is the mitigation those
     * applications understand — the cell reads as text and the original
     * characters survive in the raw file.
     *
     * Character-for-character the same rule as OrdersApiController::csvCell()
     * and CustomersApiController::csvCell(), deliberately: three exports that
     * disagree about what is dangerous are three different bugs waiting.
     */
    private function csvCell(mixed $value): string
    {
        $string = (string) $value;

        if ($string !== '' && str_contains("=+-@\t\r", $string[0])) {
            return "'" . $string;
        }

        return $string;
    }

    /**
     * List counts for the header strip.
     *
     * Never allowed to throw. This was the one admin screen whose show() touched
     * a table, and when `subscribers` was not there the query took the whole
     * page down with it — a settings screen that will not open because a
     * decorative counter failed. The settings are the screen; the counts are
     * trim, and they degrade to zero and say so.
     *
     * `confirmed` and `pending` are reported separately from `total` (Lane EE),
     * and the separation is the point rather than extra detail. With double
     * opt-in a signup no longer means a subscriber, so a single headline number
     * would tell the owner he has a list of 400 when 90 of them never clicked
     * anything and will never receive a campaign. `confirmed` is counted
     * through NewsletterList::marketable() — the one builder that decides who
     * may be mailed — so the figure on the screen and the rows in the export
     * cannot drift apart.
     *
     * @return array{total: int, confirmed: int, pending: int, week: int, latest: ?string, ready: bool}
     */
    private function stats(): array
    {
        $empty = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'week' => 0, 'latest' => null, 'ready' => false];

        try {
            if (! Schema::hasTable('subscribers')) {
                return $empty;
            }

            return [
                'total' => (int) DB::table('subscribers')->count(),
                'confirmed' => (int) \App\Services\NewsletterList::marketable()->count(),
                'pending' => (int) DB::table('subscribers')
                    ->where('status', \App\Services\NewsletterList::PENDING)
                    ->count(),
                'week' => (int) DB::table('subscribers')->where('created_at', '>=', now()->subDays(7))->count(),
                'latest' => DB::table('subscribers')->orderByDesc('id')->value('email'),
                'ready' => true,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
