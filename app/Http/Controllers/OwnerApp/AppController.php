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
        $out = ['js' => Vite::asset(self::JS), 'css' => Vite::asset(self::CSS)];
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
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#E0567B',
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
        $shell = [$base.'/', $a['js'], $a['css'], $a['icon-192'], $a['badge-96']];

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
     */
    public function state(Request $request): JsonResponse
    {
        $device = OwnerAppAuth::device($request);
        $why = OwnerAppAuth::barred($device);

        if ($why !== null) {
            return response()->json(['ok' => true, 'stage' => $why === 'disabled' ? 'disabled' : 'enrol']);
        }

        if (! OwnerAppAuth::sessionValid($request, $device)) {
            return response()->json(['ok' => true, 'stage' => 'pin', 'name' => self::firstName((string) $device->member->admin->name)]);
        }

        OwnerAppAuth::touch($device);

        return response()->json(['ok' => true, 'stage' => 'app'] + self::me($request, $device));
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
     * hash, no token: the CSRF value is derived from the session cookie and
     * is only ever returned to a request that already holds that cookie.
     *
     * @return array<string,mixed>
     */
    private static function me(Request $request, \App\Models\OwnerAppDevice $device): array
    {
        $admin = $device->member->admin;
        $can = static fn (string $c) => AdminRoles::can($admin, $c);
        $session = (string) $request->cookies->get(OwnerAppAuth::SESSION_COOKIE, '');

        return [
            'me' => [
                'name' => (string) $admin->name,
                'first' => self::firstName((string) $admin->name),
                'device' => (string) $device->name,
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
            'vapid' => VapidKeys::publicKey(),
            'csrf' => $session !== '' && $device->session_hash !== null ? OwnerAppAuth::csrfFor($session) : null,
        ];
    }

    private static function firstName(string $name): string
    {
        $first = trim((string) strtok(trim($name), ' '));

        return $first !== '' ? $first : 'there';
    }
}
