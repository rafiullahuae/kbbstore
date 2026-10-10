<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\Mail\DomainCheck;
use App\Services\Marketing\Bounces\BounceBook;
use App\Services\Marketing\Bounces\BounceMailbox;
use App\Services\Marketing\Bounces\BounceReader;
use App\Services\Marketing\CampaignTick;
use App\Services\Marketing\SendBackoff;
use App\Services\Marketing\SendLimits;
use App\Services\SettingsService;
use App\Support\AdminRoles;
use App\Support\StoreTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Growth & Marketing → Bounces & unsubscribes (Lane EB) — the "Not sending
 * to" list, the bounce mailbox, the sending pace and the deliverability check.
 *
 * Every route is in routes/email-health-admin.php, inside the admin-api group,
 * and AdminCapabilities maps each one (fails closed: an unlisted write is
 * marketing.bounces.mailbox, owner only):
 *
 *   marketing.bounces.view     GET overview / list; POST deliverability/check
 *                              (a read-only public DNS lookup)
 *   marketing.bounces.restore  POST bounced/restore
 *   marketing.bounces.mailbox  POST mailbox, mailbox/test, mailbox/run
 *   marketing.email.send       POST pace (the same people as Sending limits)
 *   marketing.export           GET bounced/export (CSV)
 *
 * Every list is ONE page of 50 and a fixed handful of queries, whatever the
 * size of the list (EmailBouncesScreenTest renders 3 and 60 rows and compares
 * the query counts).
 */
final class EmailHealthController extends Controller
{
    public const PAGE = 50;

    public const TABS = ['bounced', 'unsubscribed', 'complaints', 'watching'];

    public function __construct(
        private BounceMailbox $box,
        private BounceBook $book,
        private SendLimits $limits,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $backoff = SendBackoff::state();

        return response()->json([
            'counts' => [
                'bounced' => DB::table('email_suppressions')->where('reason', 'bounce')->count(),
                'unsubscribed' => (int) DB::query()->fromSub($this->unsubscribed(''), 'u')->count(),
                'complaints' => DB::table('email_suppressions')->where('reason', 'complaint')->count(),
                'watching' => (int) DB::query()->fromSub($this->watching(''), 'w')->count(),
            ],
            'mailbox' => $this->box->state(),
            'last_run' => BounceReader::lastRun(),
            'auto_remove' => $this->book->autoRemove(),
            'pace' => [
                'gap' => $this->limits->gap(),
                'jitter' => SendLimits::JITTER,
                'per_minute' => $this->limits->effectivePerMinute(),
                'per_day' => $this->limits->perDay(),
                'used_today' => $this->limits->usedToday(),
                'backoff_wait' => SendBackoff::wait(),
                'backoff_strikes' => $backoff['strikes'],
                'backoff_why' => $backoff['why'],
            ],
            'cron' => ['alive' => CampaignTick::alive()],
            'deliverability' => app(DomainCheck::class)->last(),
            'can' => [
                'restore' => $this->can($request, 'marketing.bounces.restore'),
                'mailbox' => $this->can($request, 'marketing.bounces.mailbox'),
                'export' => $this->can($request, 'marketing.export'),
                'pace' => $this->can($request, 'marketing.email.send'),
            ],
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'bounced';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        $page = max(1, min(10000, (int) $request->query('page', 1)));

        $query = match ($tab) {
            'bounced' => $this->suppressed('bounce', $q),
            'complaints' => $this->suppressed('complaint', $q),
            'unsubscribed' => DB::query()->fromSub($this->unsubscribed($q), 'u'),
            'watching' => DB::query()->fromSub($this->watching($q), 'w'),
        };

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('at')->orderBy('email')->orderBy('id')->offset(($page - 1) * self::PAGE)->limit(self::PAGE)->get();

        return response()->json([
            'tab' => $tab,
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE)),
            'total' => $total,
            'rows' => $this->present($tab, $rows->all()),
        ]);
    }

    public function export(): StreamedResponse
    {
        $cell = static function (mixed $value): string {
            $s = (string) $value;

            // A cell a spreadsheet would run as a formula is defused.
            return $s !== '' && str_contains("=+-@\t\r", $s[0]) ? "'" . $s : $s;
        };

        $callback = function () use ($cell): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'bounced_at', 'status_code', 'reason', 'campaign', 'hard_bounces', 'soft_bounces']);

            DB::table('email_suppressions')->where('reason', 'bounce')->orderBy('id')
                ->chunk(500, function ($rows) use ($out, $cell): void {
                    foreach ($this->present('bounced', $rows->map(fn ($r) => (object) [
                        'email' => $r->email, 'at' => $r->created_at, 'code' => $r->code, 'detail' => $r->detail, 'campaign_id' => $r->campaign_id,
                    ])->all()) as $r) {
                        fputcsv($out, array_map($cell, [$r['email'], $r['at'], $r['code'], $r['detail'], $r['campaign'], $r['hard'], $r['soft']]));
                    }
                });

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="kbb-bounced-' . now()->format('Y-m-d') . '.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:191', 'email:rfc'],
            'confirm' => ['accepted'],
        ]);

        $ok = $this->book->restore((string) $data['email']);

        return response()->json(['ok' => $ok, 'message' => $ok
            ? $data['email'] . ' is back on the list. It will be emailed again from the next campaign.'
            : 'That address is not on the Bounced list.'], $ok ? 200 : 404);
    }

    public function saveMailbox(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_remove' => ['required', 'boolean'],
            'username' => ['nullable', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'max:64'],
            'forget_password' => ['nullable', 'boolean'],
        ]);

        $problems = $this->box->save($data);

        if ($problems !== []) {
            return response()->json(['ok' => false, 'error' => $problems[0]], 422);
        }

        $settings->set(BounceBook::AUTO_KEY, $data['auto_remove'] ? '1' : '0', false);

        return response()->json(['ok' => true, 'mailbox' => $this->box->state(), 'auto_remove' => $this->book->autoRemove()]);
    }

    public function testMailbox(BounceReader $reader): JsonResponse
    {
        return response()->json($reader->test());
    }

    public function runMailbox(BounceReader $reader): JsonResponse
    {
        return response()->json($reader->run(true));
    }

    public function savePace(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate(['gap' => ['required', 'integer', 'min:0', 'max:' . SendLimits::GAP_MAX]]);
        $gap = (int) $data['gap'];

        // 0 is "no pause"; 1–2 would be a pause in name only, so it is 3.
        $settings->set(SendLimits::GAP_KEY, $gap === 0 ? 0 : max(SendLimits::GAP_MIN, $gap), false);

        return response()->json(['ok' => true, 'gap' => $this->limits->gap(), 'per_minute' => $this->limits->effectivePerMinute()]);
    }

    public function checkDns(DomainCheck $check): JsonResponse
    {
        return response()->json(['ok' => true, 'deliverability' => $check->run()]);
    }

    /* ------------------------------------------------------------ queries */

    private function suppressed(string $reason, string $q): Builder
    {
        return DB::table('email_suppressions')->where('reason', $reason)
            ->when($q !== '', fn ($b) => $b->where('email', 'like', '%' . self::like($q) . '%'))
            ->select(['id', 'email', 'created_at as at', 'code', 'detail', 'campaign_id', 'source']);
    }

    /**
     * Everyone who asked to stop: the marketing unsubscribes, a newsletter row
     * marked unsubscribed, and the basket/stock reminder opt-outs (which
     * CampaignSender::blockedNow() honours too). One UNION, three indexed reads.
     */
    private function unsubscribed(string $q): Builder
    {
        $like = $q !== '' ? '%' . self::like($q) . '%' : null;

        $a = DB::table('email_suppressions')->whereIn('reason', ['unsubscribe', 'manual'])
            ->when($like, fn ($b) => $b->where('email', 'like', $like))
            ->selectRaw("id, email, created_at as at, source, 'marketing' as list");

        $b = DB::table('subscribers')->where('status', 'unsubscribed')
            ->whereNotIn('email', DB::table('email_suppressions')->select('email'))
            ->when($like, fn ($x) => $x->where('email', 'like', $like))
            ->selectRaw("id, email, updated_at as at, source, 'newsletter' as list");

        $c = DB::table('outbound_optouts')
            ->whereNotIn('email', DB::table('email_suppressions')->select('email'))
            ->when($like, fn ($x) => $x->where('email', 'like', $like))
            ->selectRaw("id, email, created_at as at, NULL as source, 'reminders' as list");

        return $a->unionAll($b)->unionAll($c);
    }

    /** Soft bounces in the last 30 days for addresses not yet suppressed. */
    private function watching(string $q): Builder
    {
        return DB::table('email_bounces')->where('kind', 'soft')->whereNull('cleared_at')
            ->where('created_at', '>=', now()->subDays(BounceBook::SOFT_DAYS))
            ->whereNotIn('email', DB::table('email_suppressions')->select('email'))
            ->when($q !== '', fn ($b) => $b->where('email', 'like', '%' . self::like($q) . '%'))
            ->groupBy('email')
            ->selectRaw('MIN(id) as id, email, MAX(created_at) as at, COUNT(*) as soft, MAX(code) as code');
    }

    /**
     * Rows as the screen shows them. For the Bounced tab: two more queries for
     * the page however many rows — the hard/soft counts, the campaign names.
     *
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(string $tab, array $rows): array
    {
        $emails = array_map(fn ($r) => (string) $r->email, $rows);
        $counts = [];
        $names = [];

        if ($tab === 'bounced' || $tab === 'complaints') {
            foreach (DB::table('email_bounces')->whereIn('email', $emails ?: ['-'])->whereNull('cleared_at')
                ->groupBy('email', 'kind')->selectRaw('email, kind, COUNT(*) as n')->get() as $c) {
                $counts[$c->email][$c->kind] = (int) $c->n;
            }

            $ids = array_values(array_unique(array_filter(array_map(fn ($r) => (int) ($r->campaign_id ?? 0), $rows))));
            $names = $ids === [] ? [] : DB::table('mkt_campaigns')->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        return array_map(function ($r) use ($tab, $counts, $names) {
            $out = ['email' => (string) $r->email, 'at' => $r->at ? StoreTime::iso((string) $r->at) : null];

            if ($tab === 'bounced' || $tab === 'complaints') {
                $cid = (int) ($r->campaign_id ?? 0);
                $out += [
                    'code' => (string) ($r->code ?? ''),
                    'detail' => (string) ($r->detail ?? ''),
                    'campaign' => $cid > 0 ? (string) ($names[$cid] ?? ('#' . $cid)) : '',
                    'hard' => $counts[$r->email]['hard'] ?? 0,
                    'soft' => $counts[$r->email]['soft'] ?? 0,
                ];
            } elseif ($tab === 'unsubscribed') {
                $out += ['list' => (string) $r->list, 'source' => (string) ($r->source ?? '')];
            } else {
                $out += ['soft' => (int) $r->soft, 'code' => (string) ($r->code ?? ''), 'of' => BounceBook::SOFT_LIMIT];
            }

            return $out;
        }, $rows);
    }

    private static function like(string $q): string
    {
        return addcslashes($q, '\\%_');
    }

    private function can(Request $request, string $capability): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof AdminUser && AdminRoles::can($admin, $capability);
    }
}
