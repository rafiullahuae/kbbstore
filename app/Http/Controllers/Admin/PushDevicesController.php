<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\Audience;
use App\Services\Push\PushAudience;
use App\Services\Push\PushLinks;
use App\Services\Push\PushSender;
use App\Services\Push\PushStats;
use App\Support\Locale;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing → Push Notifications → Devices (Lane PD).
 *
 * The owner, 6 October: "on the notification for site, i need an option to
 * test to my device, or there should be proper list of installed devices, and
 * i can manually select and test notification."
 *
 *   GET  push/devices          push.view   the active phones, newest seen first
 *   PUT  push/devices/{id}     push.send   nickname, and "this is my phone"
 *   POST push/devices/test     push.send   a test to the chosen phones
 *
 * Capabilities come from AdminCapabilities::RULES ('GET admin-api/push/**' is
 * push.view; every other verb under the prefix is push.send), so this fails
 * closed without a rule of its own.
 *
 * WHAT A ROW CARRIES is built key by key below: never the endpoint, the keys
 * or the cookie hash, and of the shopper only the name the customer screen
 * already shows. Two queries per page whatever the catalogue (a count and one
 * joined page), so the screen's cost is flat as the phones grow.
 */
final class PushDevicesController extends Controller
{
    public const PER_PAGE = 25;

    public const NICKNAME_MAX = 40;

    /** Phones one test may reach; the route also allows TEST_PER_MINUTE requests a minute. */
    public const MAX_TEST_DEVICES = 20;

    public const TEST_PER_MINUTE = 10;

    public function __construct(private PushSender $sender) {}

    public function index(Request $request): JsonResponse
    {
        $q = self::text($request->query('q'));
        $q = mb_substr($q, 0, 60);
        $page = max(1, min(10000, (int) $request->query('page', 1)));
        $me = (int) ($request->user('admin')?->getKey() ?? 0);

        $base = DB::table('site_app_push_subscriptions as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.status', 'active');
        if ($q !== '') {
            // '!' escapes LIKE's wildcards: the same literal on MySQL and SQLite
            // (a backslash is itself an escape inside a MySQL string).
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
            $base->where(function ($w) use ($like) {
                foreach (['s.nickname', 'c.name', 's.city', 's.region'] as $col) {
                    $w->orWhereRaw("LOWER({$col}) LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        $total = (int) (clone $base)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $base->orderByRaw('CASE WHEN s.admin_user_id = ? THEN 0 ELSE 1 END', [$me])
            ->orderByDesc('s.last_seen_at')->orderByDesc('s.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['s.id', 's.platform', 's.locale', 's.country', 's.region', 's.city', 's.location_source', 's.nickname',
                's.admin_user_id', 's.customer_id', 's.last_seen_at', 's.created_at', 'c.name as customer_name']);

        return response()->json([
            'devices' => $rows->map(fn ($r) => $this->row($r, $me))->all(),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE, 'q' => $q,
            'max_test' => self::MAX_TEST_DEVICES, 'nickname_max' => self::NICKNAME_MAX,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $me = (int) ($request->user('admin')?->getKey() ?? 0);
        $set = [];
        if ($request->has('nickname')) {
            $raw = $request->input('nickname');
            if ($raw !== null && ! is_string($raw)) {
                return response()->json(['ok' => false, 'error' => 'A nickname is plain text.'], 422);
            }
            $nick = self::text($raw);
            if (mb_strlen($nick) > self::NICKNAME_MAX) {
                return response()->json(['ok' => false, 'error' => 'A nickname is at most '.self::NICKNAME_MAX.' characters.'], 422);
            }
            $set['nickname'] = $nick === '' ? null : $nick;
        }
        $mine = $request->input('mine');
        if ($mine !== null && ! is_bool($mine)) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }
        if ($set === [] && $mine === null) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'], 422);
        }

        $row = DB::table('site_app_push_subscriptions')->where('id', $id)->where('status', 'active')->first(['id', 'admin_user_id']);
        if ($row === null) {
            return response()->json(['ok' => false, 'error' => 'That device is no longer subscribed.'], 404);
        }
        if ($mine === true) {
            $set['admin_user_id'] = $me;
        } elseif ($mine === false && (int) $row->admin_user_id === $me) {
            // Only your own mark comes off; another admin's phone stays theirs.
            $set['admin_user_id'] = null;
        }
        if ($set !== []) {
            DB::table('site_app_push_subscriptions')->where('id', $id)->update($set);
        }

        $r = DB::table('site_app_push_subscriptions as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')->where('s.id', $id)
            ->first(['s.id', 's.platform', 's.locale', 's.country', 's.region', 's.city', 's.location_source', 's.nickname',
                's.admin_user_id', 's.customer_id', 's.last_seen_at', 's.created_at', 'c.name as customer_name']);

        return response()->json(['ok' => true, 'device' => $this->row($r, $me)]);
    }

    public function test(Request $request): JsonResponse
    {
        $raw = $request->input('ids');
        $ids = is_array($raw) ? array_values(array_unique(array_filter(array_map(
            static fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : 0, $raw), static fn ($v) => $v > 0))) : [];
        if ($ids === []) {
            return response()->json(['ok' => false, 'error' => 'Choose at least one device.'], 422);
        }
        if (count($ids) > self::MAX_TEST_DEVICES) {
            return response()->json(['ok' => false, 'error' => 'Choose at most '.self::MAX_TEST_DEVICES.' devices for one test.'], 422);
        }

        // The campaign's own words when the editor sent them; else a test that
        // names each phone, so the one that buzzes tells the owner its row.
        $title = self::text($request->input('title'));
        $custom = $title !== '';
        $body = self::text($request->input('body'));
        $url = null;
        if ($custom) {
            $errors = [];
            if (mb_strlen($title) > PushAdminController::TITLE_MAX) {
                $errors[] = 'The title is longer than '.PushAdminController::TITLE_MAX.' characters.';
            }
            if (mb_strlen($body) > PushAdminController::BODY_MAX) {
                $errors[] = 'The message is longer than '.PushAdminController::BODY_MAX.' characters.';
            }
            $rawUrl = $request->input('url');
            if (is_string($rawUrl) && trim($rawUrl) !== '') {
                $url = PushLinks::path($rawUrl);
                if ($url === null) {
                    $errors[] = 'The link must be a page on this shop, like /product/… or /sale/.';
                }
            }
            if ($errors !== []) {
                return response()->json(['ok' => false, 'error' => implode(' ', $errors)], 422);
            }
        }

        $found = DB::table('site_app_push_subscriptions')->whereIn('id', $ids)->where('status', 'active')
            ->get(['id', 'nickname'])->keyBy(fn ($r) => (int) $r->id);
        $messages = [];
        foreach ($ids as $id) {
            $d = $found->get($id);
            if ($d === null) {
                continue;
            }
            $messages[$id] = $custom ? [$title, $body, $url] : [
                'Test notification',
                'This is device #'.$id.($d->nickname !== null && $d->nickname !== '' ? ' · '.$d->nickname : '').' in Push Notifications → Devices.',
                null,
            ];
        }
        $sent = $this->sender->testEach($messages);

        $results = [];
        foreach ($ids as $id) {
            $results[] = ['id' => $id, 'result' => $sent[$id] ?? 'not_subscribed'];
        }
        $delivered = count(array_filter($results, static fn ($r) => $r['result'] === 'delivered'));

        return response()->json(['ok' => $delivered > 0, 'delivered' => $delivered, 'results' => $results]);
    }

    /** @return array<string, mixed> one device, key by key */
    private function row(object $r, int $me): array
    {
        $emirate = PushAudience::emirateOf($r->region, $r->city, $r->country);
        $emirateLabel = $emirate === null ? null : ($emirate === 'outside' ? 'Outside the UAE' : (Audience::EMIRATES[$emirate][0] ?? null));
        $city = $r->city !== null && trim((string) $r->city) !== '' ? (string) $r->city : null;
        $place = array_values(array_unique(array_filter([$city, $emirateLabel ?? ($r->region ?: null)])));
        $platform = isset(PushAudience::PLATFORMS[(string) $r->platform]) ? (string) $r->platform : 'other';

        return [
            'id' => (int) $r->id,
            'platform' => $platform,
            'platform_label' => PushAudience::PLATFORMS[$platform],
            'place' => $place === [] ? null : implode(', ', array_map('strval', $place)),
            'located_by' => PushStats::SOURCES[(string) ($r->location_source ?? '')] ?? PushStats::SOURCES[''],
            'language' => Locale::LOCALES[(string) $r->locale]['name'] ?? (string) $r->locale,
            'customer' => $r->customer_id !== null && $r->customer_name !== null ? (string) $r->customer_name : null,
            'nickname' => $r->nickname !== null ? (string) $r->nickname : null,
            'mine' => $me > 0 && (int) $r->admin_user_id === $me,
            'marked_by_other' => $r->admin_user_id !== null && (int) $r->admin_user_id !== $me,
            'installed_at' => StoreTime::iso($r->created_at),
            'last_seen_at' => StoreTime::iso($r->last_seen_at ?? $r->created_at),
        ];
    }

    private static function text(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));

        return mb_check_encoding($v, 'UTF-8') ? $v : '';
    }
}
