<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics;
use App\Services\MarketingPixels;
use App\Services\Pixels\ConnectChecks;
use App\Services\Pixels\MetaConnect;
use App\Services\Pixels\PixelConfig;
use App\Services\Pixels\ServerEvents;
use App\Services\Seo\MerchantFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Growth & Marketing → Marketing Pixels → the Meta / Google / TikTok Connect
 * tabs and the Last events panel. (Lane MP)
 *
 * Capability `marketing.pixels.connect` (owner, manager) on every route, from
 * AdminCapabilities::RULES, failing closed. Secrets come back masked
 * ("••••1a2b") and never in full; a blank secret box on save means
 * "unchanged".
 */
final class PixelConnectApiController extends Controller
{
    /** The three IDs, writable here as well as on the Pixels tab. */
    private const IDS = ['meta_id', 'ga4_id', 'tiktok_id'];

    public function __construct(
        private PixelConfig $config,
        private Analytics $analytics,
        private MarketingPixels $pixels,
    ) {}

    public function show(Request $request, MetaConnect $meta, ServerEvents $events): JsonResponse
    {
        $values = [];

        foreach (array_keys(PixelConfig::SHAPES) as $key) {
            $values[$key] = in_array($key, PixelConfig::SECRET_KEYS, true) ? '' : $this->config->get($key);
        }

        $masked = [];

        foreach (PixelConfig::SECRET_KEYS as $key) {
            $masked[$key] = $this->config->masked($key);
        }

        foreach (PixelConfig::VERIFY_KEYS as $key => $label) {
            $values[$key] = (string) ($key === PixelConfig::GOOGLE_VERIFY_KEY ? $this->config->googleVerification() : $this->config->metaVerification());
        }

        $base = rtrim(substr(MerchantFeed::url(), 0, -strlen(MerchantFeed::PATH)), '/');

        return response()->json([
            'module_on' => $this->analytics->enabled(),
            'ids' => $this->pixels->all(),
            'values' => $values,
            'masked' => $masked,
            'server' => ['meta' => $events->metaOn(), 'ga4' => $events->ga4On(), 'tiktok' => $events->tiktokOn()],
            'feeds' => [
                'on' => MerchantFeed::enabled(),
                'google' => $base . MerchantFeed::PATH,
                'meta' => $base . '/feeds/meta-catalog.xml',
                'tiktok' => $base . '/feeds/tiktok-catalog.xml',
            ],
            'site' => $base,
            'meta_oauth' => [
                'source' => $meta->source(),
                'redirect_uri' => MetaConnect::redirectUri(),
                'pixels' => $meta->pending($request),
            ],
            'events' => ServerEvents::recent(),
            'checked' => now()->toDateString(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $values = $request->input('values', []);
        $ids = $request->input('ids', []);
        $clear = $request->input('clear', []);

        if (! is_array($values) || ! is_array($ids) || ! is_array($clear)) {
            return response()->json(['ok' => false, 'error' => 'Malformed request.'], 422);
        }

        $unknown = array_diff(array_keys($ids), self::IDS);

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: ' . implode(', ', $unknown)], 422);
        }

        $refused = $this->config->save($values, array_values(array_filter($clear, 'is_string')));

        if ($refused === [] && $ids !== []) {
            $refused = $this->pixels->save($ids);
        }

        if ($refused !== []) {
            return response()->json(['ok' => false, 'error' => '“' . implode('”, “', $refused) . '” is not in the right format.', 'fields' => array_keys($refused)], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function check(string $what, ConnectChecks $checks, MerchantFeed $feed): JsonResponse
    {
        $result = match ($what) {
            'meta' => $checks->meta(),
            'ga4' => $checks->ga4(),
            'tiktok' => $checks->tiktok(),
            'live' => $checks->live(),
            'feed' => $checks->feed($feed),
            default => null,
        };

        if ($result === null) {
            return response()->json(['ok' => false, 'error' => 'Unknown check.'], 404);
        }

        return response()->json($result);
    }

    public function events(): JsonResponse
    {
        return response()->json(['events' => ServerEvents::recent()]);
    }

    public function metaStart(Request $request, MetaConnect $meta): JsonResponse
    {
        $start = $meta->start($request);

        return response()->json($start, $start['ok'] ? 200 : 422);
    }

    public function metaCallback(Request $request, MetaConnect $meta): RedirectResponse
    {
        $error = (string) $request->query('error_description', $request->query('error', ''));

        if ($error !== '') {
            $request->session()->forget('kbb.pixels.meta.state');

            return $this->back(['mp_meta' => 'error', 'mp_msg' => mb_substr('Facebook: ' . $error, 0, 200)]);
        }

        $answer = $meta->callback($request, (string) $request->query('state', ''), (string) $request->query('code', ''));

        if (! $answer['ok']) {
            return $this->back(['mp_meta' => 'error', 'mp_msg' => mb_substr((string) $answer['error'], 0, 200)]);
        }

        $count = count($answer['pixels'] ?? []);

        return $this->back(['mp_meta' => $count === 1 ? 'connected' : ($count === 0 ? 'none' : 'pick')]);
    }

    public function metaPick(Request $request, MetaConnect $meta): JsonResponse
    {
        $id = (string) $request->input('pixel_id', '');

        return $meta->pick($request, $id)
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'error' => 'That pixel was not in the list Meta returned. Press Connect with Facebook again.'], 422);
    }

    /** Back to the console's Marketing Pixels screen, never to an outside address. */
    private function back(array $params): RedirectResponse
    {
        $path = (string) (parse_url(route('admin'), PHP_URL_PATH) ?: '/');

        return redirect()->to($path . '?' . http_build_query($params) . '#pixels');
    }
}
