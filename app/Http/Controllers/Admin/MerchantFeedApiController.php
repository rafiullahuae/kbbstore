<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SecurityModule;
use App\Services\Seo\MerchantFeed;
use App\Services\Seo\SeoSettings;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Growth & Marketing -> Google Shopping feed. (Lane SEO)
 *
 *   GET  /admin-api/merchant-feed   the switch, the URL to paste, and what the
 *                                   feed holds right now (counts + 3 sample items)
 *   POST /admin-api/merchant-feed   {enabled: bool} -- the only thing it writes
 *
 * Capability `marketing.feed` (owner, manager), mapped for both verbs in
 * AdminCapabilities::ROUTES and failing closed like every admin-api path.
 * The sample is parsed back out of the feed the public URL serves, so the
 * screen shows exactly what Merchant Center will read -- never a model.
 */
final class MerchantFeedApiController extends Controller
{
    public function show(MerchantFeed $feed): JsonResponse
    {
        return response()->json(['ok' => true] + $this->payload($feed));
    }

    public function save(Request $request, SettingsService $settings, MerchantFeed $feed): JsonResponse
    {
        $enabled = $request->json('enabled');

        if (! is_bool($enabled)) {
            return response()->json(['ok' => false, 'message' => 'Not saved: choose on or off.'], 422);
        }

        $settings->set(MerchantFeed::SETTING, $enabled ? '1' : '0');
        MerchantFeed::forget();

        try {
            app(SecurityModule::class)->record('merchant_feed', 'Google Shopping feed '.($enabled ? 'switched on' : 'switched off'), [
                'subject' => 'merchant_feed',
                'after' => $enabled ? '1' : '0',
                'severity' => 'notice',
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true] + $this->payload($feed));
    }

    /** @return array<string, mixed> */
    private function payload(MerchantFeed $feed): array
    {
        $s = SeoSettings::map();
        $enabled = MerchantFeed::enabled($s);
        $built = $feed->cached();

        return [
            'enabled' => $enabled,
            'private' => \App\Support\SiteHost::isPrivate(),
            'url' => MerchantFeed::url($s),
            'items' => (int) $built['items'],
            'products' => (int) $built['products'],
            'built_at' => (string) $built['built_at'],
            'sample' => self::sample((string) $built['xml'], 3),
        ];
    }

    /**
     * The first $n items as Merchant Center reads them: element name => text.
     *
     * @return list<array<string, string>>
     */
    private static function sample(string $xml, int $n): array
    {
        $out = [];
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        foreach ($doc->channel->item as $item) {
            $row = [];

            foreach ($item->children('http://base.google.com/ns/1.0') as $name => $value) {
                $row[$name] = isset($row[$name]) ? $row[$name].' · '.(string) $value : (string) $value;
            }

            $out[] = $row;

            if (count($out) >= $n) {
                break;
            }
        }

        return $out;
    }
}
