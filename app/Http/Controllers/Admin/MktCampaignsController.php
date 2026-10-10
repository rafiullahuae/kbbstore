<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\MailSettings;
use App\Services\Marketing\Audience;
use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\CampaignReport;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\CampaignTick;
use App\Services\Marketing\SendLimits;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marketing Emails → Campaigns, the builder's preview, Review & send and
 * Reports (Lane MK). Mounted by routes/marketing-emails-admin.php; every
 * route is capability-mapped in AdminCapabilities (view / manage / send).
 *
 * WHAT A RESPONSE CARRIES. Campaign rows, counts and the admin's own
 * builder content — never a customer row: "See the N" (MktGroupsController)
 * is the one place addresses are listed, under marketing.email.view, the
 * capability that already reads Store → Customers' marketing list.
 *
 * TEXT IN, TEXT OUT. Subject, preview line and From name are single lines
 * (CR and LF refused: a header is built from them); blocks go through
 * Blocks::clean() and nothing else.
 */
final class MktCampaignsController extends Controller
{
    public function __construct(
        private Audience $audience,
        private CampaignRenderer $renderer,
        private CampaignSender $sender,
        private CampaignReport $report,
        private SendLimits $limits,
    ) {}

    /* ------------------------------------------------------------ overview */

    public function overview(Request $request): JsonResponse
    {
        return response()->json([
            'tiles' => $this->report->tiles($this->audience),
            'campaigns' => $this->rows(),
            'limits' => $this->limitsState(),
            'cron' => $this->cronState(),
            'can_send' => $this->canSend($request),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['campaigns' => $this->rows()]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return DB::table('mkt_campaigns as c')
            ->leftJoin('mkt_segments as g', 'g.id', '=', 'c.segment_id')
            ->orderByRaw("CASE c.status WHEN 'sending' THEN 0 WHEN 'paused' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")
            ->orderByDesc('c.updated_at')->orderByDesc('c.id')
            ->limit(200)
            ->get([
                'c.id', 'c.name', 'c.subject', 'c.status', 'c.scheduled_at', 'c.started_at', 'c.finished_at',
                'c.recipients', 'c.sent', 'c.failed', 'c.skipped', 'c.clicks', 'c.orders', 'c.revenue_fils',
                'c.rules_snapshot', 'c.audience', 'g.name as group_name', 'c.updated_at',
                // One statement: the click rate's numerator rides the list.
                DB::raw('(select count(*) from mkt_sends s where s.campaign_id = c.id and s.first_click_at is not null) as clickers'),
            ])
            ->map(function ($c) {
                $snap = json_decode((string) $c->rules_snapshot, true) ?: [];

                return [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'subject' => (string) $c->subject,
                    'status' => (string) $c->status,
                    'group' => (string) ($snap['segment'] ?? $c->group_name ?? ''),
                    'scheduled_at' => StoreTime::iso($c->scheduled_at),
                    'scheduled_label' => $c->scheduled_at ? StoreTime::formatDate($c->scheduled_at, 'j M H:i') : null,
                    'finished_label' => $c->finished_at ? StoreTime::formatDate($c->finished_at, 'j M') : null,
                    'recipients' => (int) $c->recipients,
                    'sent' => (int) $c->sent,
                    'failed' => (int) $c->failed,
                    'clicks' => (int) $c->clicks,
                    'clickers' => (int) $c->clickers,
                    'orders' => (int) $c->orders,
                    'revenue' => Money::plain((int) $c->revenue_fils),
                    'updated_at' => StoreTime::iso($c->updated_at),
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function limitsState(): array
    {
        $gmail = false;

        try {
            $gmail = app(MailSettings::class)->transport() === MailSettings::TRANSPORT_GMAIL;
        } catch (\Throwable) {
        }

        return [
            'per_minute' => $this->limits->perMinute(),
            'per_day' => $this->limits->perDay(),
            'default_cap' => $this->limits->defaultCap(),
            'max_cap' => $gmail ? SendLimits::CAP_GOOGLE_MAX : SendLimits::CAP_MAX,
            'google' => $gmail,
            'used_today' => $this->limits->usedToday(),
            'step_max' => SendLimits::STEP_MAX,
            // Lane EB: seconds between messages, and what that makes a minute.
            'gap' => $this->limits->gap(),
            'effective_per_minute' => $this->limits->effectivePerMinute(),
        ];
    }

    /** @return array<string, mixed> */
    private function cronState(): array
    {
        $last = CampaignTick::lastTick();

        return [
            'alive' => CampaignTick::alive(),
            'last_tick' => $last === null ? null : StoreTime::iso(date('Y-m-d H:i:s', $last)),
            'line' => '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
            'where' => 'Cloudways → your application → Cron Job Management → Add New Cron Job',
        ];
    }

    public function saveLimits(Request $request, SettingsService $settings): JsonResponse
    {
        $max = $this->limitsState()['max_cap'];
        $data = $request->validate([
            'per_minute' => ['required', 'integer', 'min:1', 'max:' . SendLimits::RATE_MAX],
            'per_day' => ['required', 'integer', 'min:1', 'max:' . $max],
        ]);

        $settings->set('mkt_rate_per_minute', (int) $data['per_minute'], false);
        $settings->set('mkt_daily_cap', (int) $data['per_day'], false);

        return response()->json(['ok' => true, 'limits' => $this->limitsState()]);
    }

    private function canSend(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof \App\Models\AdminUser && \App\Support\AdminRoles::can($admin, 'marketing.email.send');
    }

    /* -------------------------------------------------------------- drafts */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $t = isset($data['template_id']) ? DB::table('mkt_templates')->where('id', $data['template_id'])->first() : null;

        if (isset($data['template_id']) && $t === null) {
            return response()->json(['ok' => false, 'error' => 'That template no longer exists.'], 422);
        }

        $blocks = $t !== null
            ? Blocks::clean(json_decode((string) $t->blocks, true))
            : [Blocks::make('mini_header'), Blocks::make('heading', ['title' => 'Your headline', 'lead' => 'A sentence or two about why this email is worth opening.']), Blocks::make('button'), Blocks::make('footer')];

        $name = trim((string) ($data['name'] ?? '')) ?: (($t->name ?? 'New campaign') . ' — ' . StoreTime::formatDate(now(), 'j M'));

        $id = DB::table('mkt_campaigns')->insertGetId([
            'name' => mb_substr($name, 0, 120),
            'template_id' => $t?->id,
            'blocks' => json_encode($blocks),
            'subject' => (string) ($t->subject ?? ''),
            'preheader' => (string) ($t->preheader ?? ''),
            // The template's look and language come with it (Lane EC).
            ...($t !== null ? CampaignRenderer::look($t) : []),
            'status' => 'draft',
            'created_by' => $request->user('admin')?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'campaign' => $this->detail($id)]);
    }

    public function show(int $id): JsonResponse
    {
        $c = $this->detail($id);

        return $c === null ? response()->json(['error' => 'No such campaign.'], 404) : response()->json(['campaign' => $c]);
    }

    /** @return array<string, mixed>|null */
    private function detail(int $id): ?array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return null;
        }

        $warnings = [];
        $errors = [];
        $blocks = Blocks::clean(json_decode((string) $c->blocks, true), $errors, $warnings);
        $key = $c->template_id !== null ? DB::table('mkt_templates')->where('id', $c->template_id)->value('key') : null;

        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'subject' => (string) $c->subject,
            'preheader' => (string) $c->preheader,
            'from_name' => (string) ($c->from_name ?? ''),
            'segment_id' => $c->segment_id === null ? null : (int) $c->segment_id,
            'template_id' => $c->template_id === null ? null : (int) $c->template_id,
            ...CampaignRenderer::look($c),
            'subject_ideas' => \App\Services\Marketing\TemplateLibrary::subjectIdeas(is_string($key) ? $key : null),
            'status' => (string) $c->status,
            'blocks' => $blocks,
            'warnings' => array_merge($errors, $warnings),
            'scheduled_at' => StoreTime::iso($c->scheduled_at),
            'test_sent_at' => StoreTime::iso($c->test_sent_at),
            'test_sent_to' => (string) ($c->test_sent_to ?? ''),
            'editable' => in_array($c->status, ['draft', 'scheduled'], true),
            'updated_at' => StoreTime::iso($c->updated_at),
        ];
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return response()->json(['ok' => false, 'error' => 'No such campaign.'], 404);
        }

        if (! in_array($c->status, ['draft', 'scheduled'], true)) {
            return response()->json(['ok' => false, 'error' => 'This campaign has started, so it can no longer be changed. Duplicate it to make a new one.'], 409);
        }

        $line = ['string', 'regex:/^[^\r\n]*$/u'];
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', ...$line],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200', ...$line],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:200', ...$line],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:120', ...$line],
            'segment_id' => ['sometimes', 'nullable', 'integer'],
            'blocks' => ['sometimes', 'array'],
            // Lane EC: a select stores one of its own options.
            'theme' => ['sometimes', 'string', 'in:' . implode(',', array_keys(\App\Services\Marketing\EmailTheme::THEMES))],
            'locale' => ['sometimes', 'string', 'in:' . implode(',', array_keys(\App\Services\Marketing\EmailTheme::LOCALES))],
        ], [
            'regex' => 'A subject, preview line or name is one line: line breaks are not allowed.',
        ]);

        $row = ['updated_at' => now()];

        foreach (['name', 'subject', 'preheader', 'from_name', 'theme', 'locale'] as $k) {
            if (array_key_exists($k, $data)) {
                $row[$k] = trim((string) $data[$k]);
            }
        }

        if (array_key_exists('segment_id', $data)) {
            if ($data['segment_id'] !== null && ! DB::table('mkt_segments')->where('id', $data['segment_id'])->exists()) {
                return response()->json(['ok' => false, 'error' => 'That customer group no longer exists.'], 422);
            }

            $row['segment_id'] = $data['segment_id'];
        }

        $warnings = [];

        if (array_key_exists('blocks', $data)) {
            $errors = [];
            $blocks = Blocks::clean($request->input('blocks'), $errors, $warnings);

            if ($errors !== []) {
                return response()->json(['ok' => false, 'error' => $errors[0], 'errors' => $errors], 422);
            }

            $row['blocks'] = json_encode($blocks);
        }

        DB::table('mkt_campaigns')->where('id', $id)->update($row);

        return response()->json(['ok' => true, 'warnings' => $warnings, 'campaign' => $this->detail($id)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('mkt_campaigns')->where('id', $id)->whereIn('status', ['draft', 'cancelled', 'failed'])->delete();

        if ($deleted !== 1) {
            return response()->json(['ok' => false, 'error' => 'Only a draft, or a cancelled campaign, can be deleted. A sent campaign keeps its report.'], 409);
        }

        DB::table('mkt_links')->where('campaign_id', $id)->delete();
        DB::table('mkt_sends')->where('campaign_id', $id)->whereIn('status', ['pending', 'skipped'])->delete();

        return response()->json(['ok' => true]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return response()->json(['ok' => false, 'error' => 'No such campaign.'], 404);
        }

        $new = DB::table('mkt_campaigns')->insertGetId([
            'name' => mb_substr('Copy of ' . $c->name, 0, 120),
            'template_id' => $c->template_id,
            'blocks' => $c->blocks,
            'subject' => $c->subject,
            'preheader' => $c->preheader,
            'from_name' => $c->from_name,
            'segment_id' => $c->segment_id,
            ...CampaignRenderer::look($c),
            'status' => 'draft',
            'created_by' => $request->user('admin')?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'campaign' => $this->detail($new)]);
    }

    /* ------------------------------------------------------------- preview */

    /**
     * The builder's live preview: the real render of the blocks in the
     * request (not yet saved), at 600 or 390, with a <tbody data-mkb> per
     * block so the canvas can select one. Changes nothing.
     */
    public function preview(Request $request): JsonResponse
    {
        $errors = [];
        $warnings = [];
        $blocks = Blocks::clean($request->input('blocks'), $errors, $warnings);

        if ($errors !== [] && $blocks === []) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }

        $segment = $request->filled('segment_id') ? DB::table('mkt_segments')->where('id', (int) $request->input('segment_id'))->first() : null;
        $audience = $segment !== null ? (string) $segment->audience : (in_array($request->input('audience'), ['customers', 'subscribers'], true) ? (string) $request->input('audience') : 'customers');
        $top = $segment !== null && $this->sender->needsTopBrand($blocks)
            ? $this->audience->topBrand($audience, json_decode((string) $segment->rules, true) ?: [], Audience::cleanMatch($segment->match))
            : null;

        $frozen = $this->renderer->materialize($blocks, $top['id'] ?? null);
        $data = $this->renderer->data($frozen);
        $out = $this->renderer->render($frozen, CampaignSender::brandVars($top) + [
            'data' => $data,
            'audience' => $audience,
            'first_name' => 'Aisha',
            'subject' => (string) $request->input('subject', ''),
            'preheader' => (string) $request->input('preheader', ''),
            'unsubscribe' => \App\Support\Url::external('/email/u/0-' . str_repeat('0', 32)),
            'markers' => true,
        ] + CampaignRenderer::look((object) ['theme' => $request->input('theme'), 'locale' => $request->input('locale')]));

        return response()->json([
            'ok' => $errors === [],
            'html' => $out['html'],
            'bytes' => $out['bytes'],
            'max_bytes' => CampaignRenderer::MAX_BYTES,
            'errors' => $errors,
            'warnings' => $warnings,
            'top_brand' => $top,
            'fills' => $this->renderer->fillSummary($frozen, $data),
        ]);
    }

    /* --------------------------------------------------------- review/send */

    public function review(Request $request, int $id): JsonResponse
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return response()->json(['error' => 'No such campaign.'], 404);
        }

        $errors = [];
        $warnings = [];
        $blocks = Blocks::clean(json_decode((string) $c->blocks, true), $errors, $warnings);
        $segment = $c->segment_id ? DB::table('mkt_segments')->where('id', $c->segment_id)->first() : null;
        $audienceKey = $segment !== null ? (string) $segment->audience : 'customers';
        $rules = $segment !== null ? (json_decode((string) $segment->rules, true) ?: []) : [];
        $match = $segment !== null ? Audience::cleanMatch($segment->match) : 'all';
        $count = $segment !== null ? $this->audience->count($audienceKey, $rules, $match) : null;
        $top = $segment !== null && $this->sender->needsTopBrand($blocks) ? $this->audience->topBrand($audienceKey, $rules, $match) : null;

        $frozen = $this->renderer->materialize($blocks, $top['id'] ?? null);
        $data = $this->renderer->data($frozen);
        $out = $this->renderer->render($frozen, CampaignSender::brandVars($top) + [
            'data' => $data, 'audience' => $audienceKey, 'first_name' => 'Aisha',
            'subject' => $c->subject, 'preheader' => $c->preheader,
            'unsubscribe' => \App\Support\Url::external('/email/u/0-' . str_repeat('0', 32)),
        ] + CampaignRenderer::look($c));

        $brand = EmailBranding::forMailable(true, \App\Mail\CampaignMail::class);
        $places = array_column(array_filter((array) ($brand['addresses'] ?? []), fn ($a) => ! empty(array_filter((array) ($a['lines'] ?? [])))), 'place');

        $emailable = $count['emailable'] ?? 0;
        // The pace (Lane EB) can be slower than the per-minute cap.
        $perMinute = $this->limits->effectivePerMinute();

        return response()->json([
            'campaign' => $this->detail($id),
            'group' => $segment === null ? null : ['id' => (int) $segment->id, 'name' => (string) $segment->name, 'audience' => $audienceKey],
            'count' => $count,
            'top_brand' => $top,
            'html' => $out['html'],
            'bytes' => $out['bytes'],
            'max_bytes' => CampaignRenderer::MAX_BYTES,
            'checks' => [
                'unsubscribe' => in_array('footer', array_column($blocks, 'type'), true),
                'addresses' => $places,
                'test' => $c->test_sent_at ? ['to' => (string) $c->test_sent_to, 'at' => StoreTime::iso($c->test_sent_at)] : null,
                'fills' => $this->renderer->fillSummary($frozen, $data),
                'size_ok' => $out['bytes'] <= CampaignRenderer::MAX_BYTES,
                'subject' => trim((string) $c->subject) !== '',
                'errors' => array_merge($errors, $warnings),
            ],
            'minutes' => $perMinute > 0 ? (int) ceil($emailable / $perMinute) : null,
            'limits' => $this->limitsState(),
            'cron' => $this->cronState(),
            'can_send' => $this->canSend($request),
            'admin_email' => (string) ($request->user('admin')->email ?? ''),
        ]);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:191', new \App\Rules\StorefrontEmail],
        ]);

        [$ok, $message] = $this->sender->test($id, trim((string) $data['to']), (string) ($request->user('admin')->name ?? ''));

        return response()->json(['ok' => $ok, 'message' => $message, 'campaign' => $this->detail($id)], $ok ? 200 : 422);
    }

    /**
     * Send now. The recipient count must be typed back and must equal the
     * number that can be emailed RIGHT NOW — a list that grew or shrank since
     * the screen was drawn is a list the owner has not confirmed.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $refused = $this->confirmCount($request, $id);

        if ($refused !== null) {
            return $refused;
        }

        [$ok, $message] = $this->sender->start($id, $request->user('admin')?->id);

        if (! $ok) {
            return response()->json(['ok' => false, 'error' => $message], 422);
        }

        // The first step at once: the list starts being written while the
        // owner watches, and Driver A carries on from the screen.
        return response()->json(['ok' => true, 'progress' => $this->sender->step($id)]);
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'] . ' ' . $data['time'], StoreTime::timezone());

        if ($at === false || $at->lessThan(now()->addMinute())) {
            return response()->json(['ok' => false, 'error' => 'Pick a time in the future.'], 422);
        }

        $refused = $this->confirmCount($request, $id);

        if ($refused !== null) {
            return $refused;
        }

        $done = DB::table('mkt_campaigns')->where('id', $id)->whereIn('status', ['draft', 'scheduled'])->update([
            'status' => 'scheduled',
            'scheduled_at' => $at->utc()->format('Y-m-d H:i:s'),
            'sent_by' => $request->user('admin')?->id,
            'updated_at' => now(),
        ]);

        if ($done !== 1) {
            return response()->json(['ok' => false, 'error' => 'This campaign has already started.'], 409);
        }

        return response()->json([
            'ok' => true,
            'campaign' => $this->detail($id),
            'cron' => $this->cronState(),
        ]);
    }

    public function unschedule(int $id): JsonResponse
    {
        $done = DB::table('mkt_campaigns')->where('id', $id)->where('status', 'scheduled')
            ->update(['status' => 'draft', 'scheduled_at' => null, 'updated_at' => now()]);

        return response()->json(['ok' => $done === 1, 'campaign' => $this->detail($id)], $done === 1 ? 200 : 409);
    }

    private function confirmCount(Request $request, int $id): ?JsonResponse
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return response()->json(['ok' => false, 'error' => 'No such campaign.'], 404);
        }

        $segment = $c->segment_id ? DB::table('mkt_segments')->where('id', $c->segment_id)->first() : null;

        if ($segment === null) {
            return response()->json(['ok' => false, 'error' => 'Choose who gets it: pick a customer group.'], 422);
        }

        $errors = [];
        $warnings = [];
        Blocks::clean(json_decode((string) $c->blocks, true), $errors, $warnings);

        if ($errors !== [] || $warnings !== []) {
            return response()->json(['ok' => false, 'error' => array_merge($errors, $warnings)[0]], 422);
        }

        $count = $this->audience->count((string) $segment->audience, json_decode((string) $segment->rules, true) ?: [], Audience::cleanMatch($segment->match));
        $typed = (int) preg_replace('/\D/', '', (string) $request->input('confirm', ''));

        if ($count['emailable'] < 1) {
            return response()->json(['ok' => false, 'error' => 'Nobody in this group can be emailed.'], 422);
        }

        if ($typed !== $count['emailable']) {
            return response()->json([
                'ok' => false,
                'error' => 'Type the number of people it will go to — ' . number_format($count['emailable']) . ' — to confirm.',
                'expected' => $count['emailable'],
            ], 422);
        }

        return null;
    }

    public function step(int $id): JsonResponse
    {
        return response()->json(['ok' => true, 'progress' => $this->sender->step($id)]);
    }

    public function progress(int $id): JsonResponse
    {
        return response()->json(['ok' => true, 'progress' => $this->sender->progress($id)]);
    }

    public function pause(int $id): JsonResponse
    {
        return response()->json(['ok' => $this->sender->pause($id), 'progress' => $this->sender->progress($id)]);
    }

    public function resume(int $id): JsonResponse
    {
        return response()->json(['ok' => $this->sender->resume($id), 'progress' => $this->sender->progress($id)]);
    }

    public function cancel(int $id): JsonResponse
    {
        return response()->json(['ok' => $this->sender->cancel($id), 'progress' => $this->sender->progress($id)]);
    }

    /* ------------------------------------------------------------- reports */

    public function reports(): JsonResponse
    {
        $rows = array_values(array_filter($this->rows(), fn ($r) => in_array($r['status'], ['sent', 'sending', 'paused', 'cancelled'], true)));

        return response()->json(['campaigns' => $rows]);
    }

    public function report(int $id): JsonResponse
    {
        $r = $this->report->campaign($id);

        return $r === null ? response()->json(['error' => 'No such campaign.'], 404) : response()->json(['report' => $r]);
    }

    /* ------------------------------------------------------------- options */

    /**
     * What the builder's selects offer: brands, categories, coupons, the
     * fills and orders, and the shipped pictures. Names and ids only.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'types' => Blocks::LABELS,
            'fills' => Blocks::FILLS,
            'orders' => Blocks::ORDERS,
            'art' => array_map(fn ($a, $k) => ['key' => $k, 'alt' => $a['alt'], 'url' => \App\Support\Url::external('/email/art/' . $k . '.jpg')], Blocks::ART, array_keys(Blocks::ART)),
            'themes' => \App\Services\Marketing\EmailTheme::THEMES,
            'locales' => \App\Services\Marketing\EmailTheme::LOCALES,
            'badge_icons' => array_keys(Blocks::BADGE_ICONS),
            'icons' => array_keys(\App\Services\Mail\Kit\MailKit::ICONS),
            'tones' => array_keys(\App\Services\Mail\Kit\MailKit::TONES),
            'brands' => DB::table('brands')->orderBy('name')->orderBy('id')->limit(1000)->get(['id', 'name'])->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->all(),
            'categories' => DB::table('categories')->orderBy('name')->orderBy('id')->limit(1000)->get(['id', 'name'])->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->all(),
            'coupons' => DB::table('coupons')->orderByDesc('id')->limit(300)->get()
                ->map(fn ($c) => ['id' => (int) $c->id, 'code' => mb_strtoupper((string) $c->code), 'live' => CampaignRenderer::couponLive($c)])->all(),
            'order_brands' => DB::table('order_items')->whereNotNull('brand')->where('brand', '<>', '')
                ->groupBy(DB::raw('LOWER(TRIM(brand))'))->selectRaw('MIN(brand) as brand')->pluck('brand')->sort(SORT_NATURAL | SORT_FLAG_CASE)->take(500)->values()->all(),
            'emirates' => array_map(fn ($e, $k) => ['key' => $k, 'name' => $e[0]], Audience::EMIRATES, array_keys(Audience::EMIRATES)),
            'fields' => array_map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'audience' => $f[1], 'ops' => array_map(fn ($kind, $op) => ['op' => $op, 'label' => Audience::OPS[$op], 'kind' => $kind], $f[2], array_keys($f[2]))], Audience::FIELDS, array_keys(Audience::FIELDS)),
            'max_bytes' => CampaignRenderer::MAX_BYTES,
        ]);
    }

    /** Product search for a hand-picked block: id, name, brand, picture. */
    public function products(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids', '')))));

        $query = \App\Models\Product::query()->with('brand')->select(['id', 'name', 'brand_id', 'image', 'status', 'stock_status', 'is_visible']);

        if ($ids !== []) {
            $query->whereIn('id', array_slice($ids, 0, 12));
        } elseif ($q !== '') {
            // ESCAPE '!' on both engines, as Store -> Customers does: a % or _
            // typed into the search is a character, not a wildcard.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $query->whereRaw("name like ? escape '!'", [$like])->orderByDesc('total_sales')->orderBy('id')->limit(20);
        } else {
            $query->orderByDesc('total_sales')->orderBy('id')->limit(20);
        }

        return response()->json(['products' => $query->get()->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => (string) $p->name,
            'brand' => (string) ($p->brand?->name ?? ''),
            'img' => \App\Services\Mail\Kit\MailKit::image($p->image, 200),
            'live' => $p->status === 'publish' && (bool) $p->is_visible && $p->stock_status === 'instock',
        ])->all()]);
    }
}
