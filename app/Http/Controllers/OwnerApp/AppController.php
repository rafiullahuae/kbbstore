<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Services\OwnerApp\VapidKeys;
use App\Support\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;

/**
 * The owner app's shell, its PWA files and its front door (Lane MAC).
 *
 * The shell is the same bytes for everybody — no name, no order, no token in
 * it — so the service worker may cache it, and a stranger who somehow has the
 * address learns nothing but that a PIN pad exists.
 */
final class AppController extends Controller
{
    /** Shell asset entries, resolved through the Vite manifest. */
    public const JS = 'resources/js/owner-app/owner-app.js';

    public const CSS = 'resources/css/owner-app/owner-app.css';

    /** Plus Jakarta Sans, Latin, variable 200–800: the Petal face, self-hosted (already a library font). */
    public const FONT = 'resources/fonts/lib/plus-jakarta-sans/plus-jakarta-sans-latin.woff2';

    public const ICONS = [
        'icon-192' => 'resources/owner-app/icons/icon-192.png',
        'icon-512' => 'resources/owner-app/icons/icon-512.png',
        'maskable-512' => 'resources/owner-app/icons/maskable-512.png',
        'apple-180' => 'resources/owner-app/icons/apple-180.png',
        'badge-96' => 'resources/owner-app/icons/badge-96.png',
    ];

    public static function base(Request $request): string
    {
        return rtrim($request->getBasePath(), '/').'/'.OwnerAppPath::current();
    }

    /** @return array<string,string> */
    private static function assets(): array
    {
        $out = ['js' => Vite::asset(self::JS), 'css' => Vite::asset(self::CSS), 'font' => Vite::asset(self::FONT)];
        foreach (self::ICONS as $k => $path) {
            $out[$k] = Vite::asset($path);
        }

        return $out;
    }

    public function shell(Request $request): Response
    {
        return response()->view('owner-app.shell', ['base' => self::base($request), 'a' => self::assets()]);
    }

    public function manifest(Request $request): JsonResponse
    {
        $base = self::base($request);
        $a = self::assets();

        return response()->json([
            'name' => 'K-Beauty Bliss Owner',
            'short_name' => 'KBB Owner',
            'id' => $base.'/',
            'start_url' => $base.'/',
            'scope' => $base.'/',
            'display' => 'standalone',
            // An installed Android app opens full screen; anything that cannot, standalone.
            'display_override' => ['fullscreen', 'standalone'],
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#FBE3EA',
            'icons' => [
                ['src' => $a['icon-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $a['icon-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $a['maskable-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The service worker. Served from inside the app's path so its scope is
     * the app and nothing else; its precache list is the shell and nothing
     * else. resources/owner-app/sw.js says what it refuses to cache.
     */
    public function worker(Request $request): Response
    {
        $base = self::base($request);
        $a = self::assets();
        $shell = [$base.'/', $a['js'], $a['css'], $a['font'], $a['icon-192'], $a['badge-96']];

        $src = (string) file_get_contents(resource_path('owner-app/sw.js'));
        $src = str_replace(
            ['__OA_BASE__', '__OA_SHELL__', '__OA_VERSION__', '__OA_ICON__', '__OA_BADGE__'],
            [
                json_encode($base, JSON_UNESCAPED_SLASHES),
                json_encode($shell, JSON_UNESCAPED_SLASHES),
                json_encode(substr(hash('sha256', implode('|', $shell)), 0, 12)),
                json_encode($a['icon-192'], JSON_UNESCAPED_SLASHES),
                json_encode($a['badge-96'], JSON_UNESCAPED_SLASHES),
            ],
            $src,
        );

        return response($src, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => $base.'/',
        ]);
    }

    /**
     * Where this phone stands: not enrolled, enrolled but locked, or unlocked.
     * Answers for a stranger too — with nothing but "not enrolled".
     *
     * NEVER the CSRF value (Lane SEC). "Unlocked" also needs the request to
     * carry it already: a live session cookie alone is what any same-origin
     * script on the shop holds too, so without the header the answer is the
     * PIN pad, and the PIN (/api/unlock) is what hands the value out.
     */
    public function state(Request $request): JsonResponse
    {
        $device = OwnerAppAuth::device($request);
        $why = OwnerAppAuth::barred($device);

        if ($why !== null) {
            return response()->json(['ok' => true, 'stage' => $why === 'disabled' ? 'disabled' : 'enrol']);
        }

        if (! OwnerAppAuth::sessionValid($request, $device) || ! OwnerAppAuth::csrfMatches($request)) {
            return response()->json([
                'ok' => true,
                'stage' => 'pin',
                'name' => self::firstName((string) $device->member->admin->name),
                'pin_length' => (int) ($device->member->pin_length ?? 0) ?: null,
                'idle_hours' => OwnerAppSettings::idleHours(),
            ]);
        }

        if (! \App\Http\Middleware\OwnerAppSession::passive($request)) {
            OwnerAppAuth::touch($device);
        }

        return response()->json(['ok' => true, 'stage' => 'app'] + self::me($request, $device) + ['pulse' => self::pulse($device)]);
    }

    /**
     * The four numbers the "Refreshing the app" card ticks off as it lands:
     * three small COUNTs, each only for a member allowed to see it.
     *
     * @return array<string,int|null>
     */
    private static function pulse(\App\Models\OwnerAppDevice $device): array
    {
        $admin = $device->member->admin;
        $day = \App\Support\StoreTime::startOfDayUtc();

        return [
            'orders_today' => AdminRoles::can($admin, 'orders.view')
                ? DB::table('orders')->whereNull('deleted_at')->where('created_at', '>=', $day)->whereIn('status', \App\Models\Order::REAL_STATUSES)->count() : null,
            'low_stock' => AdminRoles::can($admin, 'catalog.view')
                ? DB::table('products')->whereNull('deleted_at')->where('manage_stock', true)->where('stock', '>', 0)->where('stock', '<=', OwnerAppSettings::lowStock())->count() : null,
            'customers_today' => AdminRoles::can($admin, 'customers.view')
                ? DB::table('customers')->whereNull('deleted_at')->where('created_at', '>=', $day)->count() : null,
        ];
    }

    public function enrol(Request $request): JsonResponse
    {
        $email = mb_substr((string) $request->input('email', ''), 0, 190);
        $pin = mb_substr((string) $request->input('pin', ''), 0, 16);
        $name = mb_substr((string) $request->input('device_name', ''), 0, 80);

        $r = OwnerAppAuth::enrol($request, $email, $pin, $name);
        $response = response()->json($r['body'], $r['status']);

        if (isset($r['device'], $r['session'])) {
            $device = \App\Models\OwnerAppDevice::query()->with('member.admin')->find($r['device_id']);
            $response->setData($r['body'] + ($device ? self::me($request, $device) : []));
            $response->headers->setCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::DEVICE_COOKIE, $r['device'], 60 * 24 * 365));
            $response->headers->setCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::SESSION_COOKIE, $r['session'], 60 * 24 * 8));
        }

        return $response;
    }

    public function unlock(Request $request): JsonResponse
    {
        $device = OwnerAppAuth::device($request);

        if ($device === null) {
            OwnerAppAuth::log($request, 'unlock', false, 'no_device', null, null);

            return response()->json(['ok' => false, 'code' => 'no_device', 'message' => 'Sign in with your email and PIN.'], 401);
        }

        $r = OwnerAppAuth::unlock($request, $device, mb_substr((string) $request->input('pin', ''), 0, 16));
        $response = response()->json($r['body'] + (isset($r['session']) ? self::me($request, $device->fresh(['member.admin'])) : []), $r['status']);

        if (isset($r['session'])) {
            $response->headers->setCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::SESSION_COOKIE, $r['session'], 60 * 24 * 8));
        } elseif (($r['body']['code'] ?? '') === 'no_device') {
            $response->headers->setCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::DEVICE_COOKIE, null, 0));
        }

        return $response;
    }

    /** Lock now: the PIN is needed again; the device stays enrolled. */
    public function lock(Request $request): JsonResponse
    {
        OwnerAppAuth::lock($request->attributes->get('oa.device'));

        return response()->json(['ok' => true])
            ->withCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::SESSION_COOKIE, null, 0));
    }

    /** Sign this device out for good: revoked, its push subscription gone. */
    public function forget(Request $request): JsonResponse
    {
        OwnerAppAuth::revoke((int) $request->attributes->get('oa.device')->id, 'signed_out');

        return response()->json(['ok' => true])
            ->withCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::SESSION_COOKIE, null, 0))
            ->withCookie(OwnerAppAuth::cookie($request, OwnerAppAuth::DEVICE_COOKIE, null, 0));
    }

    /**
     * The member as the app needs them — an allowlist. No email, no PIN, no
     * hash, no token, and no CSRF value: that travels only in the answer to a
     * good PIN (enrol, unlock), which sets it on the body itself.
     *
     * @return array<string,mixed>
     */
    private static function me(Request $request, \App\Models\OwnerAppDevice $device): array
    {
        $admin = $device->member->admin;
        $can = static fn (string $c) => AdminRoles::can($admin, $c);
        return [
            'me' => [
                'name' => (string) $admin->name,
                'first' => self::firstName((string) $admin->name),
                'device' => (string) $device->name,
                'role' => AdminRoles::isFull($admin) ? 'Full access' : (string) (AdminRoles::roleOf($admin)['name'] ?? ''),
                'can' => [
                    'orders' => $can('orders.view'),
                    'orders_manage' => $can('orders.manage'),
                    'orders_payment' => $can('orders.payment'),
                    'sales' => $can('analytics.view'),
                    'products' => $can('catalog.view'),
                    'products_edit' => $can('catalog.manage'),
                    'customers' => $can('customers.view'),
                ],
                'notify' => OwnerAppEvents::groupsFrom($device->member->notify),
            ],
            'notify_groups' => OwnerAppEvents::GROUP_LABELS,
            'idle_hours' => OwnerAppSettings::idleHours(),
            'tz' => \App\Support\StoreTime::zone(),
            'vapid' => VapidKeys::publicKey(),
        ];
    }

    /**
     * Anything under the app's address that no route above matched. Answered
     * HERE, inside the group, so it carries OwnerAppHeaders (noindex,
     * no-store) and never reaches the shop's 404 handler, which would write
     * the secret address into not_found_log for every manager to read.
     */
    public function missing(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'not_found'], 404);
    }

    private static function firstName(string $name): string
    {
        $first = trim((string) strtok(trim($name), ' '));

        return $first !== '' ? $first : 'there';
    }
}
