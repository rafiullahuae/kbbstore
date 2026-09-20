<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ImportConsole\ImportChain;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function Illuminate\Support\defer;

/**
 * The one endpoint in this application that continues an import with no session
 * behind it — Lane GO.
 *
 * =============================================================================
 * READ THIS BEFORE CHANGING ANYTHING IN IT
 * =============================================================================
 * CLAUDE.md's standing rule is that a route which rewrites the catalogue must
 * live inside `auth:admin`, and routes/import-admin.php says so at length about
 * the endpoint this one is a sibling of. THIS ROUTE CANNOT, and the reason is
 * the whole feature: it is called by this server, with no browser, no cookie
 * and no session, precisely because the owner has closed the tab. There is no
 * session for it to be inside.
 *
 * So what authorises it has to be something the server can hold and a stranger
 * cannot obtain. The whole of that is `App\Services\ImportConsole\ImportChain`,
 * whose header is the argument; the six properties this controller depends on:
 *
 *   1. THE BATON IS 256 BITS from random_bytes(), and only its SHA-256 is
 *      stored. Guessing it is not a thing that happens.
 *
 *   2. IT IS SINGLE USE. claim() matches and replaces the stored digest in one
 *      conditional UPDATE, so a secret that reaches this endpoint twice works
 *      once. A captured baton cannot be replayed, and cannot be used to start a
 *      second chain alongside the real one — the real one's next call is then
 *      refused and the page says STALLED.
 *
 *   3. IT ONLY EXISTS WHILE A RUN DOES. An admin, signed in, pressed a button
 *      to create it. There is no baton at rest, so there is nothing for this
 *      endpoint to accept between imports.
 *
 *   4. IT TRAVELS IN A HEADER. Never a path segment, never a query parameter —
 *      so it is not written into the host's access log, into a proxy's, or into
 *      any Referer. This controller reads the header and NOTHING ELSE: there is
 *      no code path here by which a URL could carry it.
 *
 *   5. THE REQUEST DECIDES NOTHING. Not the entity, not the row count, not the
 *      options, not the mode. Every one of those is read from the run row that
 *      an authenticated admin wrote when they pressed Import. The body is never
 *      looked at. A caller holding a valid baton can make the owner's own
 *      import go forward and can do nothing else at all — it cannot start one,
 *      cannot change one, and cannot read one.
 *
 *   6. IT ANSWERS NOTHING. 204 or 404, both with an empty body. Nothing about
 *      the import, the run, the shop or the files comes back out of here, so
 *      there is no version of this endpoint that leaks.
 *
 * =============================================================================
 * WHY 404 AND NOT 401, AND WHY ALWAYS THE SAME 404
 * =============================================================================
 * A forged baton, a spent one, an expired one, a run that was stopped, a run
 * that was paused, a run that finished and a shop that has never imported
 * anything all return the identical empty 404 after the identical work.
 *
 * That is CLAUDE.md's own rule about Api\QuizController::expertRequest, applied
 * one endpoint over: findByPublicToken looks the row up BEFORE it checks the
 * signature so that a forged token and an id that was never issued do the same
 * work and return the same answer, and the note is explicit that branching
 * differently on the two restores the oracle. A 401 here would confirm the
 * endpoint exists; a 409 would confirm a run exists; a distinct message would
 * say which of them was wrong.
 *
 * The cost is paid by us and not by an attacker: the legitimate caller cannot
 * tell "this host did not route the request" from "the baton was refused"
 * either. ImportChain::kick() therefore reports what it tried rather than
 * claiming to know why, and the sentence the owner reads names both.
 *
 * =============================================================================
 * WHY IT ANSWERS BEFORE IT WORKS
 * =============================================================================
 * The slice runs inside `defer()`. That makes the chain a RELAY rather than a
 * STACK: this request's response is sent while the caller is still alive, the
 * caller's process then exits, and only then does the slice start. If the work
 * were done before the response, every link would hold its predecessor open and
 * a twenty-minute import would be one twenty-minute-deep nest of live PHP
 * workers — on shared hosting, an exhausted pool inside a minute.
 *
 * `defer()` and not `app()->terminating()`, for the reason OutboundTick's
 * header gives: terminating callbacks are never cleared off the Application and
 * fire a second time the moment one process handles two requests. Here that
 * would be two slices from one baton.
 *
 * THE BATON IS CLAIMED BEFORE THE RESPONSE, not in the deferred callback. The
 * caller has to be told whether the relay was taken up, and it is the claim
 * that decides. Deferring the claim would mean answering 204 to a request that
 * was about to do nothing.
 */
class ImportChainController extends Controller
{
    /**
     * Matches ImportApiController::STEP_SECONDS.
     *
     * RAISE ONLY, for the reason that controller records at length: under the
     * CLI the default is 0 (unlimited), so an unconditional set_time_limit is a
     * *lower* — and it armed a 110-second countdown that killed the test suite
     * in whatever innocent code happened to be running when it expired.
     */
    private const STEP_SECONDS = 110;

    public function __construct(
        private readonly ImportChain $chain = new ImportChain,
    ) {}

    public function continue(Request $request): Response
    {
        /*
         * THE HEADER, AND NOTHING ELSE. Not $request->input(), not the route,
         * not the query string. See §4 of the class comment — this line is the
         * whole of the guarantee that the secret cannot end up in a log.
         */
        $secret = (string) $request->header(ImportChain::HEADER, '');

        $next = $this->chain->claim($secret);

        if ($next === null) {
            return $this->nothing(404);
        }

        $limit = (int) ini_get('max_execution_time');

        if ($limit !== 0 && $limit < self::STEP_SECONDS) {
            @set_time_limit(self::STEP_SECONDS);
        }

        /*
         * The caller hangs up as soon as it has the 204 — that is the design.
         * Without this, the slice would be killed the moment it does, which is
         * every single time.
         */
        @ignore_user_abort(true);

        defer(fn () => $this->chain->advance($next), 'kbb-import-chain');

        return $this->nothing(204);
    }

    /**
     * An empty answer, identical in every case that is not a taken baton.
     *
     * no-store because a 204 cached by anything between here and the caller
     * would be a link of the chain that never ran; and because a proxy holding
     * ANY response to this URL is a proxy that has seen the header on it.
     */
    private function nothing(int $status): Response
    {
        return response('', $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
