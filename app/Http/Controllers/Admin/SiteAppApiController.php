<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SiteApp;
use App\Services\SiteAppPush;
use App\Services\SiteAppUpdate;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App -> Site App (Lane PW): the whole app on or off, its name, and a
 * read-only look at the icon. Nothing else is configurable yet — how the
 * shop offers the install is decided later (docs/pw-preview/PLAN.md).
 */
final class SiteAppApiController extends Controller
{
    public function __construct(private SiteApp $app, private SiteAppPush $push, private SiteAppUpdate $update) {}

    /**
     * App update -> "Publish an update to installed apps" (Lane UA): the next
     * update number, the message in both languages, and the row switched on.
     * The worker's VERSION moves with it, so every installed app has a new
     * worker to take. {en?, ar?, icon?}; refused, never coerced.
     */
    public function publishUpdate(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        $r = $this->update->publish(is_array($in) ? $in : [], (string) (auth('admin')->user()?->name ?? 'Admin'));
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'error' => $r['error']] + $this->payload(), 422);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** App update -> "Show the Update App row in installed apps": {show: bool}. */
    public function showUpdate(Request $request): JsonResponse
    {
        $show = $request->json()->all()['show'] ?? null;
        if (! is_bool($show)) {
            return response()->json(['ok' => false, 'error' => 'Show must be true or false.'] + $this->payload(), 422);
        }
        $r = $this->update->setShow($show);
        if (! $r['ok']) {
            return response()->json(['ok' => false, 'error' => $r['error']] + $this->payload(), 422);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $in = $request->json()->all();
        if (! is_array($in) || $in === []) {
            return response()->json(['ok' => false, 'error' => 'Nothing to save.'] + $this->payload(), 422);
        }

        // "Ask shoppers for notifications when the app opens" (Lane NT) is
        // SiteAppPush's own setting; on/name stay SiteApp's. A boolean or refused.
        $ask = null;
        if (array_key_exists('ask_push', $in)) {
            if (! is_bool($in['ask_push'])) {
                return response()->json(['ok' => false, 'error' => 'Ask for notifications must be true or false.'] + $this->payload(), 422);
            }
            $ask = $in['ask_push'];
            unset($in['ask_push']);
        }

        if ($in !== []) {
            $r = $this->app->save($in);
            if (! $r['ok']) {
                return response()->json(['ok' => false, 'error' => $r['error']] + $this->payload(), 422);
            }
        }
        if ($ask !== null) {
            $this->push->setAsk($ask);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $icons = [];
        foreach (SiteApp::ICONS as $key => [$size, $purpose]) {
            $icons[] = ['key' => $key, 'size' => $size, 'purpose' => $purpose ?? 'apple-touch-icon', 'url' => SiteApp::iconUrl($key)];
        }

        return [
            'values' => $this->app->all(),
            'ask_push' => $this->push->ask(),
            'name_max' => SiteApp::NAME_MAX,
            'icons' => $icons,
            // The owner's own icon and favicon card (Lane IC).
            'icon' => AppIconController::sitePayload(),
            // App update (Lane UA).
            'update' => $this->update->state() + [
                'look_changed' => $this->update->lookChanged(),
                'defaults' => ['en' => SiteAppUpdate::DEFAULT_EN, 'ar' => SiteAppUpdate::DEFAULT_AR],
                'msg_max' => SiteAppUpdate::MSG_MAX,
                'worker_version' => SiteApp::version(),
            ],
            'links' => [
                'manifest' => Url::raw('/manifest.webmanifest'),
                'worker' => Url::raw('/sw.js'),
                'offline' => Url::raw('/offline'),
                'shop' => Url::raw('/'),
            ],
        ];
    }
}
