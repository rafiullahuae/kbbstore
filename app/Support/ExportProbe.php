<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Is this download going to work?", asked before the browser is sent at it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CLOSING THE SECOND DOOR TOOK THE OWNER'S SCREEN AWAY WITH IT. THIS IS THE
 * HALF THAT GIVES IT BACK.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT IT IS FOR ─────────────────────────────────────────────────────────
 *
 * An admin-guarded address that does not carry the secret admin path answers a
 * browser with a plain 404 now, because the 302 it used to answer with named
 * `admin_path` in a Location header to anyone who typed a guessable prefix.
 *
 * Four buttons in the console reach such an address by NAVIGATING the whole
 * page at it — the orders, customers, reviews and catalogue exports. Before,
 * an expired session put the administrator on the admin login and he signed
 * back in. After, it put him on a blank 404 **with the console gone from the
 * screen**, losing the list he was working on. That is strictly worse than what
 * it replaced, and it is what this exists to prevent.
 *
 * ── WHY A PROBE AND NOT A BLOB ─────────────────────────────────────────────
 *
 * The obvious repair is to fetch the file through the console's own api() and
 * hand the browser a blob URL. It is the wrong repair, and the call sites say
 * so in a comment that predates all of this:
 *
 *     "A normal navigation, not a fetch: the browser carries the same admin
 *      session cookie, the server refuses anyone without it, and the file lands
 *      in Downloads instead of in memory."
 *
 * Every one of these four actions returns a `StreamedResponse` — rows are
 * written to `php://output` as they are read, so a catalogue of any size costs
 * the server one row of memory and the browser none. A blob buffers the whole
 * CSV in the tab before a single byte reaches the disk. Measured or not, that
 * is a different behaviour from the one the comment promises, and on a
 * catalogue this shop expects to grow it is the wrong direction.
 *
 * So the navigation stays exactly as it is, and a cheap question is asked
 * first.
 *
 * ── AND WHY IT IS A PARAMETER ON THE EXPORT ITSELF ─────────────────────────
 *
 * Not a new endpoint, which was the other candidate and is more surface for a
 * worse answer:
 *
 *   * A new admin endpoint needs its own route file, a line in `routes/web.php`
 *     (the integrator's), a `clear_caches_*` migration, a new capability, a row
 *     in `AdminCapabilities` and an entry in the test that enumerates every
 *     admin route. Six things to keep in step.
 *
 *   * And it would answer the WRONG QUESTION. "Is any admin session alive" is
 *     not what the button needs to know; "may THIS operator run THIS export" is.
 *     Asked here, the probe passes through the export's own capability —
 *     `orders.export`, `customers.export`, `reviews.export`, `catalog.export` —
 *     because `AdminCapabilities` matches on the route's URI and a query string
 *     is not part of it. An operator whose role has lost the capability is told
 *     so, instead of navigating to a 403 page and losing the console for that
 *     too.
 *
 * `/admin-api/health` was considered and is not the answer: it is
 * `throttle:6,1`, so the fourth export in a minute would be refused by the
 * probe rather than by the export.
 *
 * ── WHAT IT RETURNS, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────
 *
 * `{"ok":true}` and nothing else. No count, no filter echo, no row, nothing
 * about the shop at all — the answer is entirely carried by the STATUS, and the
 * body exists only because the console's `api()` wrapper ends in `r.json()` and
 * a 204 would make a successful probe throw.
 *
 * It fails closed by construction rather than by intention: it is the first
 * statement of an action that is already behind `auth:admin` and already behind
 * its own capability, so a probe that is not entitled never reaches this class.
 * A guest gets the same 401 every other admin-api XHR gets.
 *
 * `no-store`, because a cached "yes" from five minutes ago is exactly the
 * answer this must never give.
 */
final class ExportProbe
{
    /** The query parameter, and the only value that means anything. */
    public const PARAM = 'probe';

    /**
     * The probe's answer, or null when this is a real request for the file.
     *
     * Called as the first statement of each export action, so the streaming
     * closure below it is never built and no query is ever run for a probe.
     */
    public static function answer(Request $request): ?JsonResponse
    {
        if ((string) $request->query(self::PARAM, '') !== '1') {
            return null;
        }

        return response()
            ->json(['ok' => true])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
