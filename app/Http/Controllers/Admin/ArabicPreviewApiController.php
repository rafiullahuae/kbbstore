<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ArabicPreview;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Translation → Settings → Private Arabic preview link (Lane AR, stage 2).
 *
 *   POST /admin-api/translations/preview-link          {days?: 1..30}  -> {url, expires_at, days}
 *   POST /admin-api/translations/preview-link/revoke                   -> {revoked: true}
 *
 * Inside the admin-api group (auth:admin, NoStoreAdminApi), and each route has
 * its own capability, `translations.preview`, held by the owner alone: the link
 * shows an unpublished language to whoever holds it, the same class of lever as
 * the master switch beside it. EnforceAdminCapability answers 403 to anyone
 * else; an unmapped path would be refused too (fail closed).
 */
final class ArabicPreviewApiController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $days = filter_var($request->input('days', ArabicPreview::DEFAULT_DAYS), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > ArabicPreview::MAX_DAYS) {
            return response()->json(['message' => 'Choose between 1 and '.ArabicPreview::MAX_DAYS.' days.'], 422);
        }

        $link = ArabicPreview::issue($days, $this->base($request));

        return response()->json($link + ['days' => $days]);
    }

    public function revoke(): JsonResponse
    {
        ArabicPreview::revoke();

        return response()->json(['revoked' => true]);
    }

    /** The shop's public address (the one its emails use), else this request's own. */
    private function base(Request $request): string
    {
        $origin = \App\Support\SiteUrl::externalOrigin();

        return ($origin !== '' ? $origin : $request->getSchemeAndHttpHost()).Url::base();
    }
}
