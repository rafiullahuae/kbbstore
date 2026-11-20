<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Autopilot\ApiTokens;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door of /autopilot-api/v1/*. (Lane AP-API)
 *
 * WHY ITS OWN PREFIX AND NOT /api. Everything under /api is public by this
 * repo's convention (CLAUDE.md, ApiSecurityTest sweeps it as such), and
 * /admin-api needs an admin's browser session. This is neither: a machine
 * reading the shop's own revenue with a key. A separate prefix keeps the two
 * conventions honest, and routes/autopilot-api.php is mounted in the
 * STATELESS group, so no session, no cookie and no CSRF token is involved.
 *
 * FAILS CLOSED, in this order:
 *   1. an address that has failed 20 times in a minute gets 429 before its
 *      key is even looked at (guessing costs nothing but time);
 *   2. no "Authorization: Bearer kbbap_…" header, a malformed key, an unknown
 *      key or a revoked key: 401, the same body for all four;
 *   3. a key without the route's scope (ping: without any scope): 403;
 *   4. more than 120 calls a minute on one key: 429.
 * Every answer is no-store, and every call -- refused ones too -- is a row in
 * autopilot_api_log (key id, path, status). The key is never logged.
 */
final class AutopilotApiAuth
{
    public const FAILS_PER_MINUTE = 20;

    public const CALLS_PER_MINUTE = 120;

    private const KEEP_LOG = 500;

    public function __construct(private ApiTokens $tokens) {}

    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $ip = (string) $request->ip();
        $failKey = 'autopilot-api-fail:' . sha1($ip);

        if (RateLimiter::tooManyAttempts($failKey, self::FAILS_PER_MINUTE)) {
            return $this->answer($request, null, 429, ['error' => 'too_many_attempts', 'message' => 'Too many refused calls from this address. Wait a minute.']);
        }

        $presented = (string) $request->bearerToken();
        $row = $presented === '' ? null : $this->tokens->find($presented);

        if ($row === null) {
            RateLimiter::hit($failKey, 60);

            return $this->answer($request, null, 401, ['error' => 'unauthorized', 'message' => 'A valid connection key is required (Growth & Marketing -> Ads Autopilot).'], ['WWW-Authenticate' => 'Bearer']);
        }

        $held = ApiTokens::scopesOf($row);
        $allowed = $scope === 'ping' ? $held !== [] : ($scope !== '' && in_array($scope, $held, true));

        if (! $allowed) {
            return $this->answer($request, (int) $row->id, 403, ['error' => 'forbidden', 'message' => 'This connection key cannot read ' . ($scope !== '' ? $scope : 'this') . '.']);
        }

        $callKey = 'autopilot-api-calls:' . (int) $row->id;

        if (RateLimiter::tooManyAttempts($callKey, self::CALLS_PER_MINUTE)) {
            return $this->answer($request, (int) $row->id, 429, ['error' => 'rate_limited', 'message' => 'More than ' . self::CALLS_PER_MINUTE . ' calls a minute. Slow down.']);
        }

        RateLimiter::hit($callKey, 60);

        // Once a minute is precise enough for "last used", and saves a write per call.
        if ($row->last_used_at === null || strtotime((string) $row->last_used_at) < time() - 60) {
            DB::table('autopilot_tokens')->where('id', (int) $row->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('autopilot_token_id', (int) $row->id);

        $response = $next($request);
        $rows = $response instanceof JsonResponse ? (int) ($response->getData(true)['count'] ?? 0) : 0;
        self::log((int) $row->id, $request, $response->getStatusCode(), $rows);
        self::noStore($response);

        return $response;
    }

    private function answer(Request $request, ?int $tokenId, int $status, array $body, array $headers = []): Response
    {
        self::log($tokenId, $request, $status, 0);

        return self::noStore(new JsonResponse($body, $status, $headers));
    }

    private static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private static function log(?int $tokenId, Request $request, int $status, int $rows): void
    {
        try {
            $id = (int) DB::table('autopilot_api_log')->insertGetId([
                'token_id' => $tokenId,
                'path' => mb_substr('/' . ltrim($request->path(), '/'), 0, 80),
                'status' => $status,
                'rows' => max(0, $rows),
                'created_at' => now(),
            ]);

            // Every 50th call trims to the newest KEEP_LOG rows: one indexed delete.
            if ($id % 50 === 0 && $id > self::KEEP_LOG) {
                DB::table('autopilot_api_log')->where('id', '<=', $id - self::KEEP_LOG)->delete();
            }
        } catch (\Throwable) {
        }
    }
}
