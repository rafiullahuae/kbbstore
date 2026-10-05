<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\NotFoundPage;
use App\Support\SupportContact;
use App\Support\WebFonts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safety → 404 page.                                                 (Lane NF)
 *
 *     GET  admin-api/not-found-page   everything the screen needs, once: the
 *                                     settings, the defaults, the four designs
 *                                     (copy, palette, both illustrations) and
 *                                     the page's own sheet, so the live preview
 *                                     redraws in the browser with no request
 *     POST admin-api/not-found-page   save {config}; one write
 *
 * Both behind `notfoundpage.manage` (AdminCapabilities::RULES); a path missing
 * from that map is owner-only, so a mistake fails closed. No database read
 * beyond the cached settings map: the payload is the same size whatever the
 * catalogue holds.
 */
class NotFoundPageApiController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $config = $request->json('config');

        if (! is_array($config)) {
            return response()->json(['ok' => false, 'error' => 'Not saved — nothing to save.'], 422);
        }

        $errors = NotFoundPage::save($config);

        if ($errors !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Not saved — '.implode(' ', array_slice(array_unique(array_values($errors)), 0, 3)),
                'fields' => $errors,
            ], 422);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $designs = [];
        foreach (NotFoundPage::DESIGNS as $d) {
            $designs[$d] = [
                'name' => NotFoundPage::NAMES[$d],
                'copy' => NotFoundPage::COPY[$d],
                'palette' => NotFoundPage::PALETTE[$d],
                'bg' => NotFoundPage::HERO_BG[$d],
                'art' => ['en' => NotFoundPage::art($d, false), 'ar' => NotFoundPage::art($d, true)],
            ];
        }

        return [
            'config' => NotFoundPage::config(),
            'defaults' => NotFoundPage::defaults(),
            'designs' => $designs,
            'labels' => NotFoundPage::LABELS,
            'ranges' => NotFoundPage::RANGES,
            'max' => NotFoundPage::MAX,
            'minContrast' => NotFoundPage::MIN_CONTRAST,
            'icons' => NotFoundPage::ICONS,
            'whatsapp' => SupportContact::whatsappDigits() !== '',
            'shop' => \App\Services\Seo\SeoSettings::firstFilled(\App\Services\Seo\SeoSettings::get('seo_site_name', ''), \App\Services\Seo\SeoSettings::get('store_name', ''), 'K-Beauty Bliss'),
            'css' => NotFoundPage::css(),
            'fontCss' => WebFonts::faceCss(WebFonts::OUTFIT).WebFonts::faceCss(WebFonts::CAIRO),
        ];
    }
}
