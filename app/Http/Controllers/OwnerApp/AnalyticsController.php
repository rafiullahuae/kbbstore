<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Admin\SiteAnalyticsApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner app's Analytics screen (Lane AN): the same two reads as the
 * console's board, behind the app's own session (OwnerAppSession) and the
 * console's capability, analytics.view. Fails closed: no member, or a member
 * whose role lacks it, gets 403 and nothing else.
 */
final class AnalyticsController extends Controller
{
    use Concerns;

    public function summary(Request $request): JsonResponse
    {
        return $this->refuse($request, 'analytics.view')
            ?? response()->json(SiteAnalyticsApiController::summaryPayload($request));
    }

    public function live(Request $request): JsonResponse
    {
        return $this->refuse($request, 'analytics.view')
            ?? response()->json(SiteAnalyticsApiController::livePayload($request));
    }
}
