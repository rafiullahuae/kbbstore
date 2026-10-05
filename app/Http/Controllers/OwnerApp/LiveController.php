<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\WebPush;
use App\Support\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The owner app's live half (Lane MAC): the "changes since" cursor the open
 * app polls, the Notifications screen, and the push subscription.
 *
 * changes() is the cheapest request the app makes, by design: one range query
 * on the primary key of owner_app_events, at most 50 short rows. It is the
 * ONLY thing the app polls, it polls only while the page is visible, and it
 * does not keep the session alive (route default `oa_passive`).
 */
final class LiveController extends Controller
{
    use Concerns;

    public function changes(Request $request): JsonResponse
    {
        $after = max(0, (int) $request->query('after', 0));

        if ($after === 0) {
            return response()->json(['ok' => true, 'cursor' => (int) DB::table('owner_app_events')->max('id'), 'events' => []]);
        }

        $rows = DB::table('owner_app_events')->where('id', '>', $after)->orderBy('id')->limit(50)
            ->get(['id', 'type', 'ref_id', 'title', 'body', 'created_at']);

        return response()->json([
            'ok' => true,
            'cursor' => $rows->isEmpty() ? $after : (int) $rows->last()->id,
            'events' => $this->visible($request, $rows),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $before = max(0, (int) $request->query('before', 0));

        $rows = DB::table('owner_app_events')
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(40)
            ->get(['id', 'type', 'ref_id', 'title', 'body', 'created_at']);

        return response()->json([
            'ok' => true,
            'events' => $this->visible($request, $rows),
            'next' => $rows->count() === 40 ? (int) $rows->last()->id : null,
        ]);
    }

    /** @return list<array<string,mixed>> only what this member's role may see */
    private function visible(Request $request, $rows): array
    {
        $admin = $this->admin($request);
        $memo = [];

        return $rows->filter(function ($e) use ($admin, &$memo) {
            $cap = OwnerAppEvents::capabilityFor((string) $e->type);

            return $memo[$cap] ??= AdminRoles::can($admin, $cap);
        })->map(fn ($e) => [
            'id' => (int) $e->id,
            'type' => (string) $e->type,
            'ref' => $e->ref_id === null ? null : (int) $e->ref_id,
            'title' => (string) $e->title,
            'body' => (string) ($e->body ?? ''),
            'at' => self::iso($e->created_at),
        ])->values()->all();
    }

    public function subscribe(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        $p256dh = (string) $request->input('keys.p256dh', '');
        $auth = (string) $request->input('keys.auth', '');

        $point = WebPush::b64uDecode($p256dh);
        if (! WebPush::allowedEndpoint($endpoint) || strlen($point) !== 65 || $point[0] !== "\x04"
            || strlen(WebPush::b64uDecode($auth)) !== 16 || WebPush::keyFromPoint($point) === null) {
            return response()->json(['ok' => false, 'message' => 'This browser offered a push address the app does not accept.'], 422);
        }

        $device = $request->attributes->get('oa.device');
        $hash = hash('sha256', $endpoint);

        DB::transaction(function () use ($device, $endpoint, $hash, $p256dh, $auth) {
            DB::table('owner_app_push_subscriptions')->where('endpoint_hash', $hash)->where('device_id', '!=', $device->id)->delete();
            DB::table('owner_app_push_subscriptions')->updateOrInsert(
                ['device_id' => $device->id],
                ['endpoint' => $endpoint, 'endpoint_hash' => $hash, 'p256dh' => $p256dh, 'auth' => $auth,
                    'fail_count' => 0, 'updated_at' => now(), 'created_at' => now()],
            );
        });

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        DB::table('owner_app_push_subscriptions')->where('device_id', $request->attributes->get('oa.device')->id)->delete();

        return response()->json(['ok' => true]);
    }

    /** "Send me a test" — to this device only, so a member can see it work. */
    public function test(Request $request): JsonResponse
    {
        $sub = DB::table('owner_app_push_subscriptions')->where('device_id', $request->attributes->get('oa.device')->id)
            ->first(['id', 'endpoint', 'p256dh', 'auth']);

        if ($sub === null) {
            return response()->json(['ok' => false, 'message' => 'Notifications are not switched on for this device.'], 422);
        }

        $sent = WebPush::send([$sub], [(int) $sub->id => (string) json_encode([
            't' => 'Notifications are on', 'b' => 'New orders and stock alerts will appear like this.', 'u' => '#/', 'g' => 'test',
        ])]);

        return response()->json(['ok' => $sent === 1, 'message' => $sent === 1 ? 'Sent.' : 'The push service did not accept it. Switch notifications off and on again.']);
    }

    public function notify(Request $request): JsonResponse
    {
        $groups = OwnerAppEvents::groupsFromInput($request->input('groups', []));
        if ($groups === null) {
            return response()->json(['ok' => false, 'message' => 'Choose from the listed notification groups.'], 422);
        }
        $member = $request->attributes->get('oa.member');

        DB::table('owner_app_members')->where('id', $member->id)->update(['notify' => json_encode($groups), 'updated_at' => now()]);

        return response()->json(['ok' => true, 'groups' => $groups]);
    }
}
