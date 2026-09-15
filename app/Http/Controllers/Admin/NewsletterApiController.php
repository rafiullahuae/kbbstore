<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $values = $this->newsletter->all();
        $fields = [];

        foreach (NewsletterSettings::SCHEMA as $key => $def) {
            [$type, $label, $default, $help] = array_pad($def, 4, '');

            $fields[$key] = [
                'key' => $key, 'type' => $type, 'label' => $label, 'help' => $help,
                'default' => $default, 'value' => $values[$key], 'options' => $def[4] ?? null,
            ];
        }

        $tabs = [];

        foreach (NewsletterSettings::TABS as $key => [$label, $description, $keys]) {
            $tabs[] = [
                'key' => $key, 'label' => $label, 'description' => $description,
                'fields' => array_values(array_filter(array_map(fn ($k) => $fields[$k] ?? null, $keys))),
            ];
        }

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
     */
    public function export(): StreamedResponse
    {
        $name = 'kbb-subscribers-' . now()->format('Y-m-d') . '.csv';

        $cell = $this->csvCell(...);

        $callback = static function () use ($cell): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'source', 'status', 'signed_up']);

            DB::table('subscribers')->orderBy('id')->chunk(500, static function ($rows) use ($out, $cell) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $cell($row->email),
                        $cell($row->source),
                        $cell($row->status),
                        $cell($row->created_at),
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
     * @return array{total: int, week: int, latest: ?string, ready: bool}
     */
    private function stats(): array
    {
        $empty = ['total' => 0, 'week' => 0, 'latest' => null, 'ready' => false];

        try {
            if (! Schema::hasTable('subscribers')) {
                return $empty;
            }

            return [
                'total' => (int) DB::table('subscribers')->count(),
                'week' => (int) DB::table('subscribers')->where('created_at', '>=', now()->subDays(7))->count(),
                'latest' => DB::table('subscribers')->orderByDesc('id')->value('email'),
                'ready' => true,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }
}
