<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\Mail\Kit\KitBlocks;
use App\Services\Mail\Kit\MailKit;
use App\Services\Mail\MailSettings;
use App\Services\Marketing\CampaignAudience;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignSender;
use App\Services\SettingsService;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Growth & Marketing → Email Marketing — Lane EK.
 *
 * Campaigns (m1), the builder (m2), Customer groups (m3), Review & send (m4)
 * and Reports (m5). Capabilities (AdminCapabilities): campaign.view for every
 * read and the two read-only POSTs (preview, live count); campaign.manage for
 * creating and editing and testing to yourself; campaign.send — owner and
 * manager ("Owner and Administrator", row 53) — for the buttons that reach
 * customers. Unmapped means owner-only, so a route added here and forgotten in
 * the map fails closed.
 *
 * SECURITY, the short version: blocks are KitBlocks (no HTML from the admin,
 * scheme-checked URLs), group rules are CampaignAudience's closed vocabulary
 * (no SQL from the admin), every select stores one of its own options, and the
 * Send button needs the exact recipient count the admin was shown — so a
 * confirmation dialog that went stale (somebody unsubscribed, a group changed)
 * is refused with the new figure instead of quietly sending to a different
 * list.
 */
class EmailMarketingController extends Controller
{
    public function __construct(
        private CampaignAudience $audience,
        private CampaignRenderer $renderer,
        private CampaignSender $sender,
        private CampaignReport $report,
    ) {}

    /* --------------------------------------------------------- campaigns (m1) */

    public function overview(): JsonResponse
    {
        $groups = CampaignGroup::query()->pluck('name', 'id')->all();
        $campaigns = Campaign::query()->orderByDesc('id')->limit(100)->get();
        // Figures for the whole list in a fixed number of queries.
        $figures = $this->report->summaries($campaigns->all());
        $rows = [];

        foreach ($campaigns as $c) {
            $rows[] = $this->summary($c, $groups, $figures[$c->id] ?? null);
        }

        return response()->json([
            'kpi' => $this->report->overview($this->audience),
            'campaigns' => $rows,
            'cap' => ['per_day' => $this->sender->cap(), 'sent_today' => $this->sender->sentToday(), 'set' => (int) app(SettingsService::class)->get(CampaignSender::CAP_KEY, 0),
                'google' => app(MailSettings::class)->transport() === MailSettings::TRANSPORT_GMAIL],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:160']]);
        $name = trim((string) ($data['name'] ?? '')) ?: 'New campaign';

        $campaign = Campaign::create([
            'name' => $name,
            'subject' => $name,
            'audience' => 'customers',
            'blocks' => [
                ['type' => 'heading', 'icon' => 'spark', 'eyebrow' => 'Picked for you', 'title' => $name, 'lead' => 'A few words about why this email is worth opening.'],
                ['type' => 'products', 'fill' => 'best', 'count' => 4, 'cols' => 2, 'order' => 'best', 'cta' => 'Shop now', 'sale' => true],
                ['type' => 'button', 'label' => 'Shop now', 'url' => '/shop/'],
            ],
            'status' => 'draft',
            'created_by' => $this->who($request),
        ]);

        return response()->json($this->state($campaign), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->state(Campaign::query()->findOrFail($id)));
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);

        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['ok' => false, 'error' => 'This campaign has started sending and can no longer be changed.'], 409);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[^<>"\r\n]*$/'],
            'audience' => ['sometimes', 'string', 'in:' . implode(',', array_keys(CampaignAudience::AUDIENCES))],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'blocks' => ['sometimes', 'array', 'max:' . KitBlocks::MAX_BLOCKS],
        ]);

        if (array_key_exists('group_id', $data) && $data['group_id'] !== null) {
            $group = CampaignGroup::query()->find((int) $data['group_id']);

            if ($group === null) {
                return response()->json(['ok' => false, 'error' => 'That group does not exist.'], 422);
            }

            // A campaign goes to ONE list; the group's list is the campaign's.
            $data['audience'] = (string) $group->audience;
        }

        if (isset($data['blocks'])) {
            $data['blocks'] = KitBlocks::clean($data['blocks']);
        }

        foreach (['subject', 'preheader', 'from_name'] as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = trim((string) ($data[$f] ?? ''));
            }
        }

        $campaign->forceFill($data)->save();

        return response()->json(['ok' => true] + $this->state($campaign->fresh()));
    }

    /** The builder's canvas: unsaved blocks drawn for one look, never stored. */
    public function preview(Request $request, int $id): Response
    {
        $campaign = Campaign::query()->findOrFail($id);
        $data = $request->validate([
            'blocks' => ['sometimes', 'array', 'max:' . KitBlocks::MAX_BLOCKS],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:255'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'selected' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $draft = $campaign->replicate();
        $draft->id = $campaign->id;

        foreach (['blocks', 'subject', 'preheader', 'group_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $draft->{$f} = $f === 'blocks' ? KitBlocks::clean((array) $data[$f]) : $data[$f];
            }
        }

        $html = $this->renderer->preview($draft, isset($data['selected']) ? (int) $data['selected'] : null, 'Aisha', $this->sender->context($draft));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);
        $admin = $request->user('admin');
        $to = (string) ($admin?->email ?? '');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Your admin account has no email address to send the test to.'], 422);
        }

        $first = (string) strtok(trim((string) ($admin?->name ?? '')), ' ');

        return response()->json($this->sender->test($campaign, $to, $first === false ? '' : $first) + ['to' => $to]);
    }

    /* ------------------------------------------------------- review & send (m4) */

    public function review(int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);
        [$audience, $rules, $match] = $this->sender->target($campaign);
        $count = $this->audience->count($audience, $rules, $match);
        $ctx = $this->sender->context($campaign);
        $blocks = KitBlocks::clean((array) $campaign->blocks);
        $products = 0;
        $coupons = [];

        foreach ($blocks as $b) {
            if ($b['type'] === 'products') {
                $products += count(KitBlocks::products($b, $ctx));
            }

            if ($b['type'] === 'coupon') {
                $coupons[$b['code']] = Coupon::query()->whereRaw('UPPER(code) = ?', [$b['code']])->exists();
            }
        }

        $k = MailKit::for([]);

        return response()->json([
            'campaign' => $this->state($campaign),
            'recipients' => $count['emailable'],
            'count' => $count,
            'checks' => [
                ['ok' => true, 'text' => 'Unsubscribe link and one-click unsubscribe'],
                ['ok' => $k['addresses'] !== [], 'text' => $k['addresses'] !== [] ? 'Dubai and Korea addresses in the footer' : 'No address in the footer yet (Emails → Design & branding)'],
                ['ok' => $campaign->test_sent_at !== null, 'text' => $campaign->test_sent_at !== null
                    ? 'Test sent to ' . $this->mask((string) $campaign->test_sent_to) . ' at ' . StoreTime::display($campaign->test_sent_at)?->format('H:i')
                    : 'No test sent yet'],
                ['ok' => $products > 0 || ! collect($blocks)->contains('type', 'products'), 'text' => $products > 0
                    ? 'Products filled: ' . ($ctx['group_brand_name'] ?? '') . ($ctx['group_brand_name'] ?? '' ? ', ' : '') . $products . ' in stock'
                    : (collect($blocks)->contains('type', 'products') ? 'The product block found nothing in stock' : 'No product block')],
                ...array_map(static fn (string $code, bool $ok) => ['ok' => $ok, 'text' => $ok ? 'Coupon ' . $code . ' exists' : 'Coupon ' . $code . ' does not exist in Marketing → Coupons'], array_keys($coupons), array_values($coupons)),
            ],
            'cap' => ['per_day' => $this->sender->cap(), 'remaining_today' => $this->sender->remainingToday(), 'per_minute' => (int) (60 / CampaignSender::LOCK_SECONDS) * CampaignSender::BATCH],
        ]);
    }

    /**
     * Send now, or schedule. campaign.send. The confirmation step's figure must
     * still be true: `confirm` is the number of recipients the admin saw.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:now,schedule'],
            'confirm' => ['required', 'integer', 'min:0'],
            'date' => ['required_if:mode,schedule', 'nullable', 'date_format:Y-m-d'],
            'time' => ['required_if:mode,schedule', 'nullable', 'date_format:H:i'],
        ]);

        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return response()->json(['ok' => false, 'error' => 'This campaign has already been sent or is sending.'], 409);
        }

        if (trim((string) $campaign->subject) === '' || KitBlocks::clean((array) $campaign->blocks) === []) {
            return response()->json(['ok' => false, 'error' => 'A campaign needs a subject and at least one block.'], 422);
        }

        [$audience, $rules, $match] = $this->sender->target($campaign);
        $now = $this->audience->count($audience, $rules, $match)['emailable'];

        if ($now !== (int) $data['confirm']) {
            return response()->json(['ok' => false, 'recipients' => $now,
                'error' => 'The list changed: it is now ' . number_format($now) . ' ' . ($now === 1 ? 'person' : 'people') . '. Check and confirm again.'], 409);
        }

        if ($now === 0) {
            return response()->json(['ok' => false, 'error' => 'Nobody in this group can be emailed.'], 422);
        }

        $by = $this->who($request);

        if ($data['mode'] === 'schedule') {
            $at = CarbonImmutable::parse($data['date'] . ' ' . $data['time'], StoreTime::timezone());

            if ($at->lte(now()->addMinute())) {
                return response()->json(['ok' => false, 'error' => 'Pick a time in the future, or Send now.'], 422);
            }

            $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $at->utc(), 'sent_by' => $by])->save();

            return response()->json(['ok' => true] + $this->state($campaign->fresh()));
        }

        $this->sender->start($campaign, $by);
        $this->sender->sweep();

        return response()->json(['ok' => true] + $this->state($campaign->fresh()));
    }

    public function cancel(int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);

        if ($campaign->status === 'scheduled') {
            $campaign->forceFill(['status' => 'draft', 'scheduled_at' => null])->save();
        } elseif ($campaign->status === 'sending') {
            // Stop: what has gone has gone; nothing more goes.
            \Illuminate\Support\Facades\DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', 'queued')
                ->update(['status' => 'skipped', 'error' => 'Stopped by ' . 'the shop before it was sent.', 'updated_at' => now()]);
            $campaign->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        }

        return response()->json(['ok' => true] + $this->state($campaign->fresh()));
    }

    /** "Without the cron line, keep this page open and it sends from here." */
    public function pump(): JsonResponse
    {
        $sent = $this->sender->sweep();
        $sending = Campaign::query()->whereIn('status', ['sending', 'scheduled'])->get(['id', 'status', 'sent_count', 'total_recipients', 'scheduled_at']);

        return response()->json(['sent' => $sent, 'campaigns' => $sending->map(static fn (Campaign $c) => [
            'id' => $c->id, 'status' => $c->status, 'sent' => $c->sent_count, 'total' => $c->total_recipients,
        ])->all()]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['daily_cap' => ['required', 'integer', 'min:0', 'max:100000']]);
        app(SettingsService::class)->set(CampaignSender::CAP_KEY, (string) $data['daily_cap']);
        SettingsService::forgetMemo();

        return response()->json(['ok' => true, 'per_day' => $this->sender->cap()]);
    }

    /* ---------------------------------------------------------------- reports */

    public function report(int $id): JsonResponse
    {
        $campaign = Campaign::query()->findOrFail($id);
        $group = $campaign->group_id ? CampaignGroup::query()->find($campaign->group_id) : null;

        return response()->json([
            'campaign' => $this->summary($campaign, $group ? [$group->id => $group->name] : []),
            'report' => $this->report->for($campaign),
        ]);
    }

    /* ----------------------------------------------------------- groups (m3) */

    public function groups(): JsonResponse
    {
        $out = [];

        foreach (CampaignGroup::query()->orderByDesc('id')->limit(50)->get() as $g) {
            $count = $this->audience->count((string) $g->audience, (array) $g->rules, (string) $g->match);
            $out[] = [
                'id' => $g->id, 'name' => $g->name, 'audience' => $g->audience, 'match' => $g->match, 'rules' => $g->rules,
                'list' => CampaignAudience::AUDIENCES[$g->audience] ?? $g->audience,
                'can_email' => $count['emailable'], 'matched' => $count['matched'],
                'describe' => array_values(array_filter(array_map([CampaignAudience::class, 'describe'], (array) $g->rules))),
            ];
        }

        $totals = $this->audience->totals();

        return response()->json([
            'groups' => $out,
            'totals' => $totals,
            'vocabulary' => [
                'fields' => array_map(static fn (array $f) => ['label' => $f[0], 'list' => $f[1], 'ops' => array_keys($f[2]), 'shapes' => $f[2]], CampaignAudience::FIELDS),
                'ops' => CampaignAudience::OPS,
                'emirates' => array_map(static fn (array $e) => $e[0], CampaignAudience::EMIRATES),
                'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->map(static fn ($b) => ['id' => $b->id, 'name' => $b->name])->all(),
            ],
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:' . implode(',', array_keys(CampaignAudience::AUDIENCES))],
            'match' => ['sometimes', 'string', 'in:all,any'],
            'rules' => ['sometimes', 'array', 'max:' . CampaignAudience::MAX_RULES],
        ]);

        $rules = CampaignAudience::clean($data['audience'], (array) ($data['rules'] ?? []), $dropped);

        return response()->json($this->audience->count($data['audience'], $rules, $data['match'] ?? 'all') + ['ignored' => $dropped]);
    }

    public function saveGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['required', 'string', 'in:' . implode(',', array_keys(CampaignAudience::AUDIENCES))],
            'match' => ['sometimes', 'string', 'in:all,any'],
            'rules' => ['sometimes', 'array', 'max:' . CampaignAudience::MAX_RULES],
        ]);

        $rules = CampaignAudience::clean($data['audience'], (array) ($data['rules'] ?? []), $dropped);

        if ($dropped !== []) {
            return response()->json(['ok' => false, 'error' => 'One of the rules is not complete. Fill it in or remove it.', 'rows' => $dropped], 422);
        }

        $group = ($data['id'] ?? null) ? CampaignGroup::query()->findOrFail((int) $data['id']) : new CampaignGroup;
        $group->forceFill(['name' => trim($data['name']), 'audience' => $data['audience'], 'match' => $data['match'] ?? 'all', 'rules' => $rules])->save();

        return response()->json(['ok' => true, 'id' => $group->id]);
    }

    public function deleteGroup(int $id): JsonResponse
    {
        if (Campaign::query()->where('group_id', $id)->whereIn('status', ['scheduled', 'sending'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'A scheduled or sending campaign uses this group.'], 409);
        }

        CampaignGroup::query()->whereKey($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** "See the 86": who matches, at most 200, and whether each can be emailed. */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:' . implode(',', array_keys(CampaignAudience::AUDIENCES))],
            'match' => ['sometimes', 'string', 'in:all,any'],
            'rules' => ['sometimes', 'array', 'max:' . CampaignAudience::MAX_RULES],
        ]);

        $rules = CampaignAudience::clean($data['audience'], (array) ($data['rules'] ?? []));
        $emailable = array_flip(array_column($this->audience->recipients($data['audience'], $rules, $data['match'] ?? 'all'), 'email'));
        $people = [];

        foreach (array_slice($this->audience->matching($data['audience'], $rules, $data['match'] ?? 'all'), 0, 200) as $row) {
            $people[] = ['email' => $row['email'], 'name' => $row['first_name'], 'can_email' => isset($emailable[$row['email']])];
        }

        return response()->json(['people' => $people]);
    }

    /* ----------------------------------------------------- builder lookups */

    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Product::query()->visible()->with('brand')
            ->when($q !== '', static fn ($b) => $b->where('name', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'))
            ->orderByDesc('total_sales')->limit(20)->get(['id', 'name', 'brand_id', 'stock_status']);

        return response()->json(['products' => $rows->map(static fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'brand' => $p->brand?->name, 'in_stock' => $p->stock_status === 'instock',
        ])->all()]);
    }

    /* ------------------------------------------------------------- internals */

    private function state(Campaign $campaign): array
    {
        return [
            'campaign' => [
                'id' => $campaign->id, 'name' => $campaign->name, 'subject' => $campaign->subject, 'preheader' => $campaign->preheader,
                'from_name' => $campaign->from_name, 'audience' => $campaign->audience, 'group_id' => $campaign->group_id,
                'blocks' => KitBlocks::clean((array) $campaign->blocks), 'status' => $campaign->status,
                'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                'updated_at' => $campaign->updated_at?->toIso8601String(),
                'sent' => $campaign->sent_count, 'total' => $campaign->total_recipients,
                'test_sent_at' => $campaign->test_sent_at?->toIso8601String(),
            ],
            'palette' => KitBlocks::TYPES,
            'icons' => array_keys(MailKit::ICONS),
            'fills' => KitBlocks::FILLS,
            'groups' => CampaignGroup::query()->orderBy('name')->get(['id', 'name', 'audience'])->toArray(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'categories' => Category::query()->orderBy('name')->limit(300)->get(['id', 'name'])->toArray(),
            'from_address' => app(MailSettings::class)->fromAddress(),
        ];
    }

    private function summary(Campaign $c, array $groups, ?array $report = null): array
    {
        $report ??= in_array($c->status, ['sending', 'sent', 'cancelled'], true) ? $this->report->for($c) : null;

        return [
            'id' => $c->id, 'name' => $c->name, 'subject' => $c->subject, 'status' => $c->status,
            'group' => $c->group_id ? ($groups[$c->group_id] ?? 'A deleted group') : (CampaignAudience::AUDIENCES[$c->audience] ?? $c->audience) . ' — everyone',
            'scheduled_at' => $c->scheduled_at?->toIso8601String(),
            'started_at' => $c->started_at?->toIso8601String(),
            'finished_at' => $c->finished_at?->toIso8601String(),
            'sent' => $c->sent_count, 'total' => $c->total_recipients,
            'click_rate' => $report['click_rate'] ?? null,
            'orders' => $report !== null ? ['n' => $report['orders'], 'revenue' => $report['revenue']] : null,
        ];
    }

    private function mask(string $email): string
    {
        [$local] = explode('@', $email) + [''];

        return mb_substr($local, 0, 4) . '@…';
    }

    private function who(Request $request): ?string
    {
        $admin = $request->user('admin');

        return $admin === null ? null : mb_substr((string) ($admin->email ?? $admin->name ?? ''), 0, 191);
    }
}
