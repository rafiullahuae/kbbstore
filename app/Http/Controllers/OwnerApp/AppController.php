<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Controller;
use App\Services\AppIcons;
use App\Services\SiteApp;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\OwnerApp\OwnerAppSettings;
use App\Services\OwnerApp\OwnerAppUi;
use App\Services\OwnerApp\VapidKeys;
use App\Support\AdminRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * The shell's asset addresses. An icon the owner uploaded under App →
     * Owner App → App icon (Lane IC) is served from the app's own address
     * (icon() below); every other icon is the shipped one from the build.
     *
     * @return array<string,string>
     */
    private static function assets(string $base): array
    {
        $out = ['js' => Vite::asset(self::JS), 'css' => Vite::asset(self::CSS), 'font' => Vite::asset(self::FONT)];
        foreach (self::ICONS as $k => $path) {
            $up = AppIcons::file('owner', $k);
            $out[$k] = $up !== null ? $base.'/icons/'.$k.'.png?v='.SiteApp::fileHash($up) : Vite::asset($path);
        }

        return $out;
    }

    /**
     * The tab icon tags once an icon is uploaded (Lane IC), else [] and the
     * shell keeps its one shipped rel=icon line.
     *
     * @return list<array{sizes: string, href: string}>
     */
    private static function favicons(string $base): array
    {
        $out = [];
        foreach (AppIcons::FAVICON_SET as $k => [$size]) {
            $up = AppIcons::file('owner', $k);
            if ($up === null) {
                return [];
            }
            $out[] = ['sizes' => $size.'x'.$size, 'href' => $base.'/icons/'.$k.'.png?v='.SiteApp::fileHash($up)];
        }

        return $out;
    }

    public function shell(Request $request): Response
    {
        // System font (Customise app, Lane OA4): no preload, and a class on
        // <html> that names no web font, so the file is never requested.
        $base = self::base($request);

        return response()->view('owner-app.shell', ['base' => $base, 'a' => self::assets($base), 'fav' => self::favicons($base), 'sysFont' => OwnerAppUi::systemFont(),
            // Top of the screen (Lane IC): the bar takes the app's own top colour.
            'top' => OwnerAppUi::topColour(), 'fullscreen' => OwnerAppUi::fullscreen()]);
    }

    /**
     * The owner's uploaded icon or favicon (Lane IC), by allowlisted name.
     * Only uploads: the shipped icons come from the build. Anything else is
     * the app's own 404, never a file read from the name.
     */
    public function icon(string $oa_icon): BinaryFileResponse|JsonResponse
    {
        $path = (isset(AppIcons::APP_SET[$oa_icon]) || isset(AppIcons::FAVICON_SET[$oa_icon])) ? AppIcons::file('owner', $oa_icon) : null;
        if ($path === null) {
            return $this->missing();
        }

        return response()->file($path, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function manifest(Request $request): JsonResponse
    {
        $base = self::base($request);
        $a = self::assets($base);

        $m = [
            'name' => 'K-Beauty Bliss Owner',
            'short_name' => 'KBB Owner',
            'id' => $base.'/',
            'start_url' => $base.'/',
            'scope' => $base.'/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            // The status bar continues the app's own top colour (Lane IC).
            'theme_color' => OwnerAppUi::topColour(),
            'icons' => [
                ['src' => $a['icon-192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $a['icon-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $a['maskable-512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        /*
         * Full screen on Android only when chosen under Customise app -> Top of
         * the screen (Lane IC). It hides the clock and Android letterboxes the
         * camera cutout in black: the band the owner asked to be rid of.
         */
        if (OwnerAppUi::fullscreen()) {
            $m = array_slice($m, 0, 6, true) + ['display_override' => ['fullscreen', 'standalone']] + array_slice($m, 6, null, true);
        }

        return response()->json($m, 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The service worker. Served from inside the app's path so its scope is
     * the app and nothing else; its precache list is the shell and nothing
     * else. resources/owner-app/sw.js says what it refuses to cache.
     */
    public function worker(Request $request): Response
    {
        $base = self::base($request);
        $a = self::assets($base);
        $shell = array_values(array_filter([$base.'/', $a['js'], $a['css'], OwnerAppUi::systemFont() ? null : $a['font'], $a['icon-192'], $a['badge-96']]));

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
     * hash, no token, and no CSRF value: that travels only in the answer to a
     * good PIN (enrol, unlock), which sets it on the body itself.
     *
     * @return array<string,mixed>
     */
    /**
     * The store's name for the app header (2.60.401). The owner: "keep for now
     * my store name K-Beauty Bliss". Its own setting, `owner_app_store_name`,
     * so a renamed SEO title never changes the app; plain text, 60 at most.
     */
    private static function storeName(): string
    {
        // Edited under Owner app → Customise app → Branding (Lane OA4); the
        // old owner_app_store_name row is still read until that card saves.
        return OwnerAppUi::storeName();
    }

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
            'stale_minutes' => OwnerAppSettings::staleMinutes(),
            // The header's title (2.60.401): the store's own name, as SEO
            // settings and the shop already resolve it, so it follows a rename.
            'store' => self::storeName(),
            'tz' => \App\Support\StoreTime::zone(),
            // Look, screens and functions (Customise app, Lane OA4): one memoised read, ~0.4 KB.
            'ui' => OwnerAppUi::forApp(),
            'vapid' => VapidKeys::publicKey(),
            'ask_push' => OwnerAppSettings::askPush(),   // Lane NT: offer "Allow notifications" on unlock
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
