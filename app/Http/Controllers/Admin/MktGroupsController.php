<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\Audience;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marketing Emails → Customer groups (m3) — Lane MK.
 *
 * Rules are cleaned by Audience::clean() — whitelisted fields, operators and
 * value shapes — on the way into the database AND on every count, so a row
 * written by hand cannot reach a statement either. The live count is "N match
 * · M can be emailed" with the reasons for the difference; "See the N" pages
 * through 50 at a time, one statement a page.
 *
 * THE SAVED LIST COUNTS EACH GROUP: one aggregate statement per group, listed
 * at most 50 groups. That is a query per GROUP, never per person — the cost
 * does not move with the size of the shop's customer list.
 */
final class MktGroupsController extends Controller
{
    public const LIST_MAX = 50;

    public function __construct(private Audience $audience) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('mkt_segments')->orderByDesc('preset')->orderBy('name')->orderBy('id')->limit(self::LIST_MAX)->get();

        $totals = $this->audience->totals();

        return response()->json([
            'totals' => $totals,
            'groups' => $rows->map(function ($g) {
                $rules = json_decode((string) $g->rules, true) ?: [];
                $count = $this->audience->count((string) $g->audience, $rules, Audience::cleanMatch($g->match));

                return [
                    'id' => (int) $g->id,
                    'name' => (string) $g->name,
                    'audience' => (string) $g->audience,
                    'match' => Audience::cleanMatch($g->match),
                    'rules' => Audience::clean((string) $g->audience, $rules),
                    'preset' => (bool) $g->preset,
                    'matched' => $count['matched'],
                    'emailable' => $count['emailable'],
                    'used' => DB::table('mkt_campaigns')->where('segment_id', $g->id)->whereIn('status', ['scheduled', 'sending', 'paused'])->exists(),
                ];
            })->all(),
        ]);
    }

    /** "N match · M can be emailed", for rules that are not saved yet. */
    public function count(Request $request): JsonResponse
    {
        [$audience, $rules, $match, $errors] = $this->input($request);

        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => $errors[0], 'errors' => $errors], 422);
        }

        $count = $this->audience->count($audience, $rules, $match);
        $top = $audience === 'customers' ? $this->audience->topBrand($audience, $rules, $match) : null;

        return response()->json(['ok' => true, 'audience' => $audience] + $count + [
            'reason_labels' => Audience::REASONS,
            'top_brand' => $top === null ? null : ['name' => $top['name']],
        ]);
    }

    /** "See the N", 50 at a time. */
    public function people(Request $request): JsonResponse
    {
        [$audience, $rules, $match, $errors] = $this->input($request);

        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => $errors[0]], 422);
        }

        $page = max(1, (int) $request->input('page', 1));

        return response()->json([
            'ok' => true,
            'page' => $page,
            'per_page' => Audience::PAGE_SIZE,
            'people' => $this->audience->page($audience, $rules, $match, $page),
            'reason_labels' => Audience::REASONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! DB::table('mkt_segments')->where('id', $id)->exists()) {
            return response()->json(['ok' => false, 'error' => 'No such group.'], 404);
        }

        return $this->save($request, $id);
    }

    private function save(Request $request, ?int $id): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:120', 'regex:/^[^\r\n]*$/u']]);
        [$audience, $rules, $match, $errors] = $this->input($request);

        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => $errors[0], 'errors' => $errors], 422);
        }

        $row = [
            'name' => trim((string) $request->input('name')),
            'audience' => $audience,
            'match' => $match,
            'rules' => json_encode($rules),
            'updated_at' => now(),
        ];

        if ($id === null) {
            $id = DB::table('mkt_segments')->insertGetId($row + [
                'preset' => false, 'created_by' => $request->user('admin')?->id, 'created_at' => now(),
            ]);
        } else {
            DB::table('mkt_segments')->where('id', $id)->update($row);
        }

        return response()->json(['ok' => true, 'id' => $id] + $this->audience->count($audience, $rules, $match));
    }

    public function destroy(int $id): JsonResponse
    {
        if (DB::table('mkt_campaigns')->where('segment_id', $id)->whereIn('status', ['scheduled', 'sending', 'paused'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'A scheduled or sending campaign uses this group. Cancel it first.'], 409);
        }

        DB::table('mkt_campaigns')->where('segment_id', $id)->where('status', 'draft')->update(['segment_id' => null]);
        $deleted = DB::table('mkt_segments')->where('id', $id)->delete();

        return response()->json(['ok' => $deleted === 1], $deleted === 1 ? 200 : 404);
    }

    /**
     * Download a group as CSV (marketing.export, as the newsletter export
     * is). Every matched person with whether they can be emailed and why not.
     * Cells that a spreadsheet would execute are neutralised.
     */
    public function export(int $id): StreamedResponse|JsonResponse
    {
        $g = DB::table('mkt_segments')->where('id', $id)->first();

        if ($g === null) {
            return response()->json(['error' => 'No such group.'], 404);
        }

        $rules = json_decode((string) $g->rules, true) ?: [];
        $query = $this->audience->annotated((string) $g->audience, $rules, Audience::cleanMatch($g->match));
        $name = 'group-' . $id . '-' . StoreTime::today()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'first_name', 'name', 'can_be_emailed', 'reason']);
            $cell = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v;

            DB::query()->fromSub($query, 'a')->orderBy('a.id')->chunk(500, function ($rows) use ($out, $cell) {
                foreach ($rows as $r) {
                    fputcsv($out, [$cell($r->email), $cell($r->first_name ?? ''), $cell($r->name ?? ''), $r->reason === 'ok' ? 'yes' : 'no', $r->reason === 'ok' ? '' : (Audience::REASONS[$r->reason] ?? $r->reason)]);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /** @return array{0:string, 1:list<array<string,mixed>>, 2:string, 3:list<string>} */
    private function input(Request $request): array
    {
        $audience = (string) $request->input('audience', 'customers');
        $errors = [];
        $rules = Audience::clean($audience, $request->input('rules', []), $errors);

        return [$audience, $rules, Audience::cleanMatch($request->input('match')), $errors];
    }
}
