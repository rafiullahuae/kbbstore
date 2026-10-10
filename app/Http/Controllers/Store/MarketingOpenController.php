<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Marketing\OpenPixel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /email/o/{token}.gif — the open pixel of a marketing email.  (Lane ER)
 *
 * ALWAYS the same 42-byte GIF with the same headers, for a valid, a forged, a
 * tampered and an unknown token, with tracking on or off, over the rate limit
 * or not, and when the database is down: a broken image in somebody's inbox
 * is worse than a lost count, and a different answer would be an oracle for
 * "is this token live?". OpenPixel::record() never throws.
 *
 * The rate limit is INSIDE, not a throttle middleware: Gmail fetches every
 * Gmail reader's pixel from a handful of Google addresses, and a 429 there
 * would be a broken image AND a lost open. Past 600 a minute from one
 * address the GIF still goes back; only the write is skipped.
 *
 * Mounted without the session (routes/marketing-open-public.php): no cookie
 * is set and no session file is written per load.
 */
final class MarketingOpenController extends Controller
{
    public const PER_MINUTE = 600;

    public function __invoke(Request $request, string $token): Response
    {
        try {
            $key = 'mkt-open:' . sha1((string) $request->ip());

            if (! RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
                RateLimiter::hit($key, 60);
                OpenPixel::record(mb_substr($token, 0, 80), mb_substr((string) $request->userAgent(), 0, 400), (string) $request->ip());
            }
        } catch (\Throwable) {
            // The picture goes back whatever happened above.
        }

        return self::gif();
    }

    public static function gif(): Response
    {
        $body = (string) base64_decode(OpenPixel::GIF, true);

        return new Response($body, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
