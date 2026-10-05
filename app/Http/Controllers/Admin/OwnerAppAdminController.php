<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\OwnerApp\OwnerAppPushText;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Services\OwnerApp\VapidKeys;
use App\Support\AdminRoles;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Platform → Users & Roles → Owner app (Lane MAC).
 *
 * Who may open the owner app, with which PIN, on which phones — and the app's
 * address, idle time and low-stock line. Capability `ownerapp.manage`, Full
 * Admin only by default (AdminCapabilities::RULES maps every path here).
 *
 * The PIN arrives once, is checked (6–8 digits, not 111111, not 123456), hashed
 * with Hash::make and never stored, logged or returned. The screen learns only
 * "has a PIN, set on <date>". Setting a new PIN, or switching access off, ends
 * every session that member holds; switching access off also stops their push
 * notifications.
 *
 * The same escalation rule as the Members tab: nobody changes the app access
 * of an account that can do things they cannot (AdminRoles::refusal()).
 */
final class OwnerAppAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $path = OwnerAppPath::ensure();
        VapidKeys::pair();

        $users = AdminUser::query()->orderBy('id')->get();
        $members = DB::table('owner_app_members')->get(['id', 'admin_user_id', 'enabled', 'pin_hash', 'pin_set_at', 'locked_until', 'lock_level', 'enrol_locked_until', 'enrol_lock_level', 'notify'])
            ->keyBy('admin_user_id');
        $devices = DB::table('owner_app_devices')->orderByDesc('last_seen_at')->orderByDesc('id')
            ->get(['id', 'member_id', 'name', 'ip', 'created_at', 'last_seen_at', 'revoked_at', 'revoked_reason'])
            ->groupBy('member_id');
        $pushing = DB::table('owner_app_push_subscriptions')->pluck('device_id')->map(fn ($v) => (int) $v)->flip();

        $logins = DB::table('owner_app_logins as l')
            ->leftJoin('owner_app_members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('admin_users as u', 'u.id', '=', 'm.admin_user_id')
            ->leftJoin('owner_app_devices as d', 'd.id', '=', 'l.device_id')
            ->orderByDesc('l.id')->limit(30)
            ->get(['l.id', 'l.kind', 'l.success', 'l.reason', 'l.ip', 'l.created_at', 'u.name as who', 'd.name as device']);

        return response()->json([
            'ok' => true,
            'url' => (($host = OwnerAppPath::host()) !== null ? 'https://'.$host : $request->getSchemeAndHttpHost().rtrim($request->getBasePath(), '/')).'/'.$path.'/',
            'path_from_env' => OwnerAppPath::isLockedByEnv(),
            'push_ready' => VapidKeys::publicKey() !== null,
            'settings' => OwnerAppSettings::forAdmin(),
            'groups' => OwnerAppEvents::GROUP_LABELS,
            'members' => $users->map(function (AdminUser $u) use ($members, $devices, $pushing) {
                $m = $members[$u->id] ?? null;

                return [
                    'admin_user_id' => (int) $u->id,
                    'name' => (string) ($u->name ?? ''),
                    'email' => (string) $u->email,
                    'role' => (string) (AdminRoles::roleOf($u)['name'] ?? 'No role'),
                    'full' => AdminRoles::isFull($u),
                    'enabled' => (bool) ($m->enabled ?? false),
                    'has_pin' => $m !== null && $m->pin_hash !== null,
                    'pin_set_at' => $m === null ? null : StoreTime::iso($m->pin_set_at),
                    'locked_until' => $m !== null && $m->locked_until !== null && now()->lt($m->locked_until) ? StoreTime::iso($m->locked_until) : null,
                    // Lane SEC: either ladder (PIN pad or sign-in form) locked,
                    // and whether only a Full Admin can lift it.
                    'locked' => $m !== null && OwnerAppAuth::lockState($m)['locked'],
                    'admin_locked' => $m !== null && OwnerAppAuth::lockState($m)['admin_only'],
                    'notify' => OwnerAppEvents::groupsFrom($m->notify ?? null),
                    'devices' => $m === null ? [] : ($devices[$m->id] ?? collect())->map(fn ($d) => [
                        'id' => (int) $d->id,
                        'name' => (string) $d->name,
                        'ip' => (string) ($d->ip ?? ''),
                        'enrolled_at' => StoreTime::iso($d->created_at),
                        'last_seen_at' => StoreTime::iso($d->last_seen_at),
                        'revoked_at' => StoreTime::iso($d->revoked_at),
                        'revoked_reason' => (string) ($d->revoked_reason ?? ''),
                        'push' => isset($pushing[(int) $d->id]),
                    ])->values(),
                ];
            })->values(),
            'logins' => $logins->map(fn ($l) => [
                'kind' => (string) $l->kind,
                'success' => (bool) $l->success,
                'reason' => (string) $l->reason,
                'ip' => (string) ($l->ip ?? ''),
                'who' => (string) ($l->who ?? ''),
                'device' => (string) ($l->device ?? ''),
                'at' => StoreTime::iso($l->created_at),
            ])->values(),
            // Lane SEC: Users & Roles → Owner app → Security.
            'security' => [
                'host' => OwnerAppPath::host() ?? '',
                'push_text' => OwnerAppPushText::current(),
                'push_text_options' => OwnerAppPushText::OPTIONS,
            ],
        ]);
    }

    public function member(Request $request, int $id): JsonResponse
    {
        $target = AdminUser::query()->find($id);
        $actor = auth('admin')->user();

        if ($target === null || ! $actor instanceof AdminUser) {
            return response()->json(['ok' => false, 'message' => 'That account no longer exists.'], 404);
        }

        if ($why = AdminRoles::refusal($actor, $target, ['full' => AdminRoles::isFull($target), 'caps' => AdminRoles::resolve($target)], false)) {
            return response()->json(['ok' => false, 'error' => $why[1], 'message' => $why[2]], $why[0]);
        }

        $pin = $request->input('pin');
        if ($pin !== null && $pin !== '') {
            $pin = (string) $pin;
            if (($problem = OwnerAppAuth::pinProblem($pin)) !== null) {
                return response()->json(['ok' => false, 'message' => $problem, 'errors' => ['pin' => [$problem]]], 422);
            }
        } else {
            $pin = null;
        }

        $row = DB::table('owner_app_members')->where('admin_user_id', $target->id)->first(['id', 'enabled', 'pin_hash']);
        $enabled = $request->has('enabled') ? $request->boolean('enabled') : (bool) ($row->enabled ?? false);

        if ($enabled && $pin === null && ($row->pin_hash ?? null) === null) {
            return response()->json(['ok' => false, 'message' => 'Set a PIN before switching the app on for this member.', 'errors' => ['pin' => ['Required.']]], 422);
        }

        $write = ['enabled' => $enabled, 'updated_at' => now()];
        if ($pin !== null) {
            $write += ['pin_hash' => Hash::make($pin), 'pin_length' => strlen($pin), 'pin_set_at' => now()];
        }
        if ($request->has('notify')) {
            $groups = OwnerAppEvents::groupsFromInput($request->input('notify'));
            if ($groups === null) {
                return response()->json(['ok' => false, 'message' => 'Choose from the listed notification groups.', 'errors' => ['notify' => ['Invalid.']]], 422);
            }
            $write['notify'] = json_encode($groups);
        }
        if ($pin !== null || $request->boolean('unlock')) {
            // Both ladders, every level — including the lock only a Full
            // Admin can lift (Lane SEC).
            $write = array_merge($write, OwnerAppAuth::UNLOCKED);
        }

        if ($row === null) {
            $memberId = (int) DB::table('owner_app_members')->insertGetId($write + ['admin_user_id' => $target->id, 'created_at' => now()]);
        } else {
            $memberId = (int) $row->id;
            DB::table('owner_app_members')->where('id', $memberId)->update($write);
        }

        if ($pin !== null || ! $enabled) {
            OwnerAppAuth::endSessions($memberId);
        }
        if (! $enabled) {
            DB::table('owner_app_push_subscriptions')->whereIn('device_id', DB::table('owner_app_devices')->where('member_id', $memberId)->select('id'))->delete();
        }

        return response()->json(['ok' => true, 'enabled' => $enabled, 'has_pin' => $pin !== null || ($row->pin_hash ?? null) !== null]);
    }

    /**
     * Sign one phone out for good. The same escalation rule as member(): a
     * phone belongs to an admin account, and nobody signs out the phone of an
     * account that can do things they cannot (Lane SEC — this used to skip
     * AdminRoles::refusal() entirely).
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $device = DB::table('owner_app_devices as d')->leftJoin('owner_app_members as m', 'm.id', '=', 'd.member_id')
            ->where('d.id', $id)->first(['d.id', 'm.admin_user_id']);

        if ($device === null) {
            return response()->json(['ok' => false, 'message' => 'That device is not on the list any more.'], 404);
        }

        $actor = auth('admin')->user();
        $target = $device->admin_user_id === null ? null : AdminUser::query()->find((int) $device->admin_user_id);

        if (! $actor instanceof AdminUser) {
            return response()->json(['ok' => false, 'message' => 'Sign in again.'], 401);
        }

        if ($target !== null && ($why = AdminRoles::refusal($actor, $target, ['full' => AdminRoles::isFull($target), 'caps' => AdminRoles::resolve($target)], false))) {
            return response()->json(['ok' => false, 'error' => $why[1], 'message' => $why[2]], $why[0]);
        }

        OwnerAppAuth::revoke($id, 'revoked_by_admin');

        return response()->json(['ok' => true]);
    }

    /**
     * Users & Roles → Owner app → Security (Lane SEC): the app's own host and
     * the lock-screen notification text. Full Admin only — the whole file is
     * `ownerapp.manage` — and checked again here, because a custom role that
     * was handed ownerapp.manage must still not move the app to a host.
     */
    public function security(Request $request): JsonResponse
    {
        if (! AdminRoles::isFull(auth('admin')->user())) {
            return response()->json(['ok' => false, 'message' => 'Only a Full Admin changes the owner app’s security settings.'], 403);
        }

        $out = [];

        if ($request->has('host')) {
            // '' arrives as null (ConvertEmptyStringsToNull): both clear it.
            $raw = $request->input('host') ?? '';
            $host = is_string($raw) ? strtolower(trim($raw)) : null;

            if ($host === null || ($host !== '' && ! OwnerAppPath::validHost($host))) {
                return response()->json(['ok' => false, 'message' => 'Enter a host name only, like owner.extrabeauty.ae — no https://, no slash, no port.',
                    'errors' => ['host' => ['Invalid.']]], 422);
            }

            if ($host !== (OwnerAppPath::host() ?? '')) {
                OwnerAppPath::setHost($host);

                // Every phone's cookies belong to the host it enrolled on and
                // its service worker lives there: sign them out, as a new
                // address does, rather than list them as live.
                foreach (DB::table('owner_app_devices')->whereNull('revoked_at')->pluck('id') as $deviceId) {
                    OwnerAppAuth::revoke((int) $deviceId, 'host_changed');
                }
            }
        }

        if ($request->has('push_text')) {
            $raw = $request->input('push_text');
            if (! is_string($raw) || ! array_key_exists($raw, OwnerAppPushText::OPTIONS)) {
                return response()->json(['ok' => false, 'message' => 'Choose Detailed or Generic.', 'errors' => ['push_text' => ['Invalid.']]], 422);
            }
            OwnerAppPushText::put($raw);
        }

        return $this->index($request);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idle_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'low_stock' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'stale_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
        ], [], ['idle_hours' => 'Lock after (hours unused)', 'low_stock' => 'Low stock at (units)', 'stale_minutes' => 'Show loading bars after (minutes)']);

        OwnerAppSettings::put([
            OwnerAppSettings::IDLE => $data['idle_hours'] ?? OwnerAppSettings::idleHours(),
            OwnerAppSettings::LOW_STOCK => $data['low_stock'] ?? OwnerAppSettings::lowStock(),
            OwnerAppSettings::STALE => $data['stale_minutes'] ?? OwnerAppSettings::staleMinutes(),
        ]);

        return response()->json(['ok' => true, 'settings' => OwnerAppSettings::forAdmin()]);
    }

    /** A new secret address. The old one stops answering and every phone signs in again at the new one. */
    public function address(Request $request): JsonResponse
    {
        if (OwnerAppPath::isLockedByEnv()) {
            return response()->json(['ok' => false, 'message' => 'The address is set by KBB_OWNER_APP_PATH in .env and can only be changed there.'], 422);
        }

        OwnerAppPath::set(OwnerAppPath::generate());

        // Every enrolment cookie was scoped to the old path and every service
        // worker lives under it: those phones cannot reach the new address with
        // them, so they are signed out here rather than left listed as live.
        foreach (DB::table('owner_app_devices')->whereNull('revoked_at')->pluck('id') as $deviceId) {
            OwnerAppAuth::revoke((int) $deviceId, 'address_changed');
        }

        return $this->index($request);
    }
}
