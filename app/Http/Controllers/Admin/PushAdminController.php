<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Services\Marketing\Audience;
use App\Services\Push\PushAudience;
use App\Services\Push\PushAutomations;
use App\Services\Push\PushGeo;
use App\Services\Push\PushLinks;
use App\Services\Push\PushRules;
use App\Services\Push\PushSender;
use App\Services\Push\PushStats;
use App\Services\Push\PushTick;
use App\Services\SiteAppPush;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\AdminRoles;
use App\Support\Locale;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing → Push Notifications (Lane PN). routes/push-admin.php.
 *
 * Every endpoint is behind `push.view` or `push.send` (AdminCapabilities::
 * RULES; owner and manager, like marketing.email.send), refused by
 * EnforceAdminCapability before this runs. Answers are built from explicit
 * keys, never a model: a subscription's endpoint and keys never leave the
 * server, and no phone is listed — the screen sees counts.
 */
final class PushAdminController extends Controller
{
    public const TITLE_MAX = 50;

    public const BODY_MAX = 120;

    public const TEMPLATE_TITLE_MAX = 60;

    public const TEMPLATE_BODY_MAX = 150;

    public function __construct(
        private PushRules $rules,
        private PushAudience $audience,
        private PushSender $sender,
        private PushStats $stats,
    ) {}

    /* --------------------------------------------------------------- reads */

    public function overview(Request $request): JsonResponse
    {
        $last = PushTick::lastTick();
        $cities = DB::table('site_app_push_subscriptions')->where('status', 'active')->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as n')->groupBy('city')->orderByDesc('n')->orderBy('city')->pluck('city')->all();
        // One row per distinct city (a few dozen at most), cut here rather than in SQL.
        $cities = array_slice($cities, 0, 40);

        return response()->json([
            'rules' => $this->rules->all(),
            'status_labels' => PushRules::STATUS_LABELS,
            'templates' => $this->templates(),
            'active' => (int) DB::table('site_app_push_subscriptions')->where('status', 'active')->count(),
            'test_devices' => count($this->ownDevices($request)),
            'can_send' => $this->canSend($request),
            'cron' => [
                'alive' => PushTick::alive(),
                'last_tick' => $last === null ? null : StoreTime::iso(date('Y-m-d H:i:s', $last)),
                'line' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
                'where' => 'Cloudways → your application → Cron Job Management → Add New Cron Job',
            ],
            'quiet_now' => $this->rules->quiet(),
            'geo' => PushGeo::status() + ['attribution' => PushGeo::ATTRIBUTION, 'attribution_url' => PushGeo::ATTRIBUTION_URL],
            'zone' => StoreTime::zone(),
            'icon' => \App\Services\SiteApp::iconUrl('icon-192'),
            'now_local' => StoreTime::now()->format('Y-m-d\TH:i'),
            'options' => [
                'emirates' => array_merge(
                    array_map(fn ($k) => ['key' => $k, 'label' => Audience::EMIRATES[$k][0]], array_keys(Audience::EMIRATES)),
                    [['key' => 'unknown', 'label' => 'Place unknown']],
                ),
                'cities' => array_values(array_map('strval', $cities)),
                'locales' => array_map(fn ($k) => ['key' => $k, 'label' => Locale::LOCALES[$k]['name']], array_keys(Locale::LOCALES)),
                'platforms' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(PushAudience::PLATFORMS), PushAudience::PLATFORMS),
                'who' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(PushAudience::WHO), PushAudience::WHO),
                'rule_fields' => array_map(fn ($f) => ['key' => $f, 'label' => Audience::FIELDS[$f][0],
                    'ops' => array_map(fn ($op, $kind) => ['key' => $op, 'label' => Audience::OPS[$op], 'kind' => $kind], array_keys(Audience::FIELDS[$f][2]), Audience::FIELDS[$f][2])],
                    PushAudience::RULE_FIELDS),
                'title_max' => self::TITLE_MAX,
                'body_max' => self::BODY_MAX,
            ],
        ]);
    }

    public function campaigns(): JsonResponse
    {
        $rows = DB::table('push_campaigns')->orderByDesc('id')->limit(50)->get();

        return response()->json(['campaigns' => $rows->map(fn ($c) => $this->row($c))->all()]);
    }

    public function campaign(int $id): JsonResponse
    {
        $c = DB::table('push_campaigns')->where('id', $id)->first();
        if ($c === null) {
            return response()->json(['ok' => false, 'error' => 'That campaign no longer exists.'], 404);
        }

        return response()->json(['campaign' => $this->row($c, true), 'by_emirate' => $this->stats->byEmirate($id)]);
    }

    public function count(Request $request): JsonResponse
    {
        $spec = PushAudience::clean($request->input('audience'), $errors);

        return response()->json(['n' => $this->audience->count($spec), 'label' => PushAudience::describe($spec), 'errors' => $errors]);
    }

    public function links(Request $request): JsonResponse
    {
        return response()->json(['links' => PushLinks::search((string) $request->query('q', ''))]);
    }

    public function analytics(): JsonResponse
    {
        return response()->json($this->stats->summary() + [
            'attribution' => PushGeo::ATTRIBUTION, 'attribution_url' => PushGeo::ATTRIBUTION_URL,
        ]);
    }

    /* ------------------------------------------------------------- drafts */

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $data = $this->cleanCampaign($request, $errors);
        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => implode(' ', $errors)], 422);
        }
        $now = now();
        if ($id === null) {
            $id = (int) DB::table('push_campaigns')->insertGetId($data + [
                'status' => 'draft', 'created_by' => $request->user('admin')?->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $n = DB::table('push_campaigns')->where('id', $id)->whereIn('status', ['draft', 'scheduled'])->update($data + ['updated_at' => $now]);
            if ($n !== 1) {
                return response()->json(['ok' => false, 'error' => 'Only a draft or a scheduled campaign can be changed.'], 422);
            }
        }

        return $this->campaign($id);
    }

    public function destroy(int $id): JsonResponse
    {
        $n = DB::table('push_campaigns')->where('id', $id)->whereIn('status', ['draft', 'cancelled'])->delete();
        if ($n !== 1) {
            return response()->json(['ok' => false, 'error' => 'Only a draft or a cancelled campaign can be deleted.'], 422);
        }
        DB::table('push_sends')->where('campaign_id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------- sending */

    public function send(Request $request, int $id): JsonResponse
    {
        $c = DB::table('push_campaigns')->where('id', $id)->first();
        if ($c === null || ! in_array($c->status, ['draft', 'scheduled'], true)) {
            return response()->json(['ok' => false, 'error' => 'This campaign has already been sent or cancelled.'], 422);
        }
        if (! $this->sender->start($id)) {
            return response()->json(['ok' => false, 'error' => 'This campaign has just been started elsewhere.'], 409);
        }
        // Driver A: the first batches inside this request, so a small shop's
        // campaign is finished before the owner's screen answers; the minute
        // tick carries on with anything left.
        $this->sender->step($id, PushSender::STEP_SECONDS);

        return $this->campaign($id);
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $at = $request->input('at');
        try {
            $when = is_string($at) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/', $at) === 1
                ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $at, StoreTime::timezone())->setTimezone('UTC')
                : null;
        } catch (\Throwable) {
            $when = null;
        }
        if ($when === null || $when->lessThan(CarbonImmutable::now('UTC')->addMinute()) || $when->greaterThan(CarbonImmutable::now('UTC')->addDays(90))) {
            return response()->json(['ok' => false, 'error' => 'Pick a time from a minute from now to 90 days ahead.'], 422);
        }
        $n = DB::table('push_campaigns')->where('id', $id)->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'scheduled', 'scheduled_at' => $when, 'updated_at' => now()]);
        if ($n !== 1) {
            return response()->json(['ok' => false, 'error' => 'Only a draft can be scheduled.'], 422);
        }

        return $this->campaign($id);
    }

    public function cancel(int $id): JsonResponse
    {
        if (! $this->sender->cancel($id)) {
            return response()->json(['ok' => false, 'error' => 'Only a scheduled or sending campaign can be cancelled.'], 422);
        }

        return $this->campaign($id);
    }

    public function step(int $id): JsonResponse
    {
        $this->sender->step($id, PushSender::STEP_SECONDS);

        return $this->campaign($id);
    }

    public function test(Request $request): JsonResponse
    {
        $data = $this->cleanCampaign($request, $errors, false);
        if ($errors !== []) {
            return response()->json(['ok' => false, 'error' => implode(' ', $errors)], 422);
        }
        $devices = $this->ownDevices($request);
        if ($devices === []) {
            return response()->json(['ok' => false, 'error' => 'No phone is marked as yours yet. Open the Devices tab, find your phone (a test names each one) and press “This is my phone”; or sign in to the installed shop app with '.($request->user('admin')?->email ?? 'your admin email').'.'], 422);
        }
        $n = $this->sender->test($devices, $data['title'], $data['body'], $data['url']);

        return response()->json(['ok' => $n > 0, 'sent' => $n, 'devices' => count($devices),
            'error' => $n > 0 ? null : 'The push service did not accept it. Open the app once on your phone and try again.']);
    }

    /* ------------------------------------------------------------ settings */

    public function saveSettings(Request $request): JsonResponse
    {
        $in = $request->input('rules');
        if (! is_array($in)) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }

        return response()->json(['ok' => true, 'rules' => $this->rules->save($in)]);
    }

    public function saveTemplates(Request $request): JsonResponse
    {
        $in = $request->input('templates');
        if (! is_array($in)) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }
        $names = array_merge(...array_values(PushAutomations::templates()));
        $errors = [];
        foreach ($in as $name => $pair) {
            if (! is_string($name) || ! in_array($name, $names, true) || ! is_array($pair)) {
                continue;
            }
            $key = PushAutomations::key($name);
            $english = (string) InterfaceStrings::english($key);
            $max = str_ends_with($name, '_title') ? self::TEMPLATE_TITLE_MAX : self::TEMPLATE_BODY_MAX;
            foreach (['en', 'ar'] as $locale) {
                if (! array_key_exists($locale, $pair)) {
                    continue;
                }
                $text = self::text($pair[$locale]);
                if (mb_strlen($text) > $max) {
                    $errors[] = "“{$name}” is longer than {$max} characters.";
                    continue;
                }
                if ($locale === 'en' && ($text === '' || $text === $english)) {
                    // Back to the built-in English: no override row.
                    Translation::query()->where(['locale' => 'en', 'group' => Translation::GROUP_UI, 'item_id' => 0, 'field' => TranslationStore::normaliseKey($key)])->delete();
                    continue;
                }
                if ($text === '') {
                    continue;
                }
                TranslationStore::put($locale, Translation::GROUP_UI, 0, $key, $text, Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL, $english);
            }
        }
        try {
            TranslationStore::flush();
            app('translator')->setLoaded([]);
        } catch (\Throwable) {
        }

        return response()->json(['ok' => $errors === [], 'error' => $errors === [] ? null : implode(' ', $errors), 'templates' => $this->templates()]);
    }

    /* ------------------------------------------------------------- helpers */

    /** @return array<string, array<string, mixed>> */
    private function templates(): array
    {
        $names = array_merge(...array_values(PushAutomations::templates()));
        $fields = array_map(fn ($n) => TranslationStore::normaliseKey(PushAutomations::key($n)), $names);
        $rows = DB::table('translations')->where('group', Translation::GROUP_UI)->where('item_id', 0)
            ->whereIn('locale', ['en', 'ar'])->whereIn('field', $fields)->get(['locale', 'field', 'value', 'status']);
        $by = [];
        foreach ($rows as $r) {
            $by[$r->locale][$r->field] = $r;
        }
        $out = [];
        foreach ($names as $i => $n) {
            $f = $fields[$i];
            $default = (string) InterfaceStrings::english(PushAutomations::key($n));
            $ar = $by['ar'][$f] ?? null;
            $en = $by['en'][$f] ?? null;
            $out[$n] = [
                'en' => $en !== null && $en->status === Translation::STATUS_PUBLISHED ? (string) $en->value : $default,
                'en_default' => $default,
                'ar' => $ar !== null ? (string) $ar->value : '',
                'ar_status' => $ar === null ? 'missing' : (string) $ar->status,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function cleanCampaign(Request $request, ?array &$errors, bool $withAudience = true): array
    {
        $errors = [];
        $title = self::text($request->input('title'));
        $body = self::text($request->input('body'));
        if ($title === '') {
            $errors[] = 'Write a title.';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors[] = 'The title is longer than '.self::TITLE_MAX.' characters.';
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            $errors[] = 'The message is longer than '.self::BODY_MAX.' characters.';
        }
        $rawUrl = $request->input('url');
        $url = null;
        if (is_string($rawUrl) && trim($rawUrl) !== '') {
            $url = PushLinks::path($rawUrl);
            if ($url === null) {
                $errors[] = 'The link must be a page on this shop, like /product/… or /sale/.';
            }
        }
        $out = ['title' => $title, 'body' => $body, 'url' => $url];
        if ($withAudience) {
            $spec = PushAudience::clean($request->input('audience'), $aErrors);
            array_push($errors, ...$aErrors);
            $label = self::text($request->input('link_label'));
            $out += ['audience' => json_encode($spec), 'link_label' => $label === '' ? null : mb_substr($label, 0, 160)];
        }

        return $out;
    }

    private static function text(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));

        return mb_check_encoding($v, 'UTF-8') ? $v : '';
    }

    /** @return array<string, mixed> */
    private function row(object $c, bool $full = false): array
    {
        $spec = json_decode((string) $c->audience, true) ?: [];
        $out = [
            'id' => (int) $c->id, 'title' => (string) $c->title, 'body' => (string) $c->body, 'url' => $c->url,
            'link_label' => $c->link_label, 'status' => (string) $c->status, 'audience_label' => PushAudience::describe($spec),
            'scheduled_at' => StoreTime::iso($c->scheduled_at), 'started_at' => StoreTime::iso($c->started_at),
            'finished_at' => StoreTime::iso($c->finished_at), 'targeted' => (int) $c->targeted, 'delivered' => (int) $c->delivered,
            'failed' => (int) $c->failed, 'gone' => (int) $c->gone, 'held' => (int) $c->held, 'clicks' => (int) $c->clicks,
            'ctr' => $c->delivered > 0 ? round($c->clicks * 100 / $c->delivered, 1) : 0.0,
        ];
        if ($full) {
            $out['audience'] = PushAudience::clean($spec);
            $out['scheduled_local'] = $c->scheduled_at ? StoreTime::display($c->scheduled_at)?->format('Y-m-d\TH:i') : null;
            $out['waiting_quiet'] = $c->status === 'sending' && $this->rules->quiet();
        }

        return $out;
    }

    /**
     * The admin's own subscribed phones: the one this browser's kbb_push
     * cookie names (if the admin uses the installed app in this browser), and
     * every phone signed in to the shop as a customer with the admin's email,
     * and every phone the admin marked "this is my phone" under Devices (Lane
     * PD) — the installed app on an iPhone keeps its own cookies, so without
     * the mark a phone nobody signed in on was never "mine".
     *
     * @return list<int>
     */
    private function ownDevices(Request $request): array
    {
        $admin = $request->user('admin');
        $token = SiteAppPush::token($request->cookie(SiteAppPush::COOKIE));
        $email = is_object($admin) ? strtolower(trim((string) ($admin->email ?? ''))) : '';
        $adminId = is_object($admin) ? (int) $admin->getKey() : 0;

        return DB::table('site_app_push_subscriptions as s')->where('s.status', 'active')
            ->where(function ($q) use ($token, $email, $adminId) {
                $q->whereRaw('1 = 0');
                if ($adminId > 0) {
                    $q->orWhere('s.admin_user_id', $adminId);
                }
                if ($token !== null) {
                    $q->orWhere('s.cookie_hash', hash('sha256', $token));
                }
                if ($email !== '') {
                    $q->orWhereIn('s.customer_id', DB::table('customers')->whereRaw('LOWER(email) = ?', [$email])->select('id'));
                }
            })
            ->orderByDesc('s.last_seen_at')->orderByDesc('s.id')->limit(5)->pluck('s.id')->map(fn ($id) => (int) $id)->all();
    }

    private function canSend(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof \App\Models\AdminUser && AdminRoles::can($admin, 'push.send');
    }
}
