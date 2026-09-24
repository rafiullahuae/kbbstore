<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Update\StorefrontHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/_kbb-health` — the one question UpdateRunner asks before it keeps a package.
 *
 * Lifted out of a closure in routes/web.php so that the answer has somewhere to
 * live that can be read, reviewed and tested. The gate is unchanged from that
 * closure, deliberately and in both directions:
 *
 *   - NO TOKEN CONFIGURED, OR A TOKEN THAT DOES NOT MATCH, IS A 404. Not a 401
 *     and not a 403: an endpoint that answers "wrong token" tells an unattended
 *     scanner that there is something here worth guessing at. hash_equals, so
 *     the comparison does not leak the token's prefix through its timing.
 *
 *   - The body is machine-facing JSON and carries no shop data. What it adds
 *     over the previous version is a per-check breakdown, so the sentence the
 *     owner reads on the Core Updates screen after a rollback says which page
 *     failed and how, rather than "unexpected response body".
 *
 * `ok` and `version` keep their old names and meanings so an older UpdateRunner
 * reading this endpoint is unaffected.
 */
final class StorefrontHealthController extends Controller
{
    public function __invoke(Request $request, StorefrontHealth $health): JsonResponse
    {
        $token = (string) config('kbb.health_token', '');

        abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), 404);

        $result = $health->check($request);

        return response()->json([
            'ok' => $result['ok'],
            'version' => config('kbb.version'),
            'reason' => $result['reason'],
            'checks' => $result['checks'],
        ], $result['ok'] ? 200 : 503);
    }
}
