<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Security\CspViolations;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where a browser posts a content-security-policy violation.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PUBLIC, AND IT HAS TO BE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There is no authenticated version of this endpoint. The browser posts it
 * from whatever page the visitor is on, with no session, no token and no
 * signature — the spec provides none, and a shopper who is not signed in to
 * anything is the ordinary case. So it sits under `/api`, beside the payment
 * webhooks, for the reason routes/payments-webhooks.php sets out in its own
 * header: the web group's CSRF check would answer 419 to every report, because
 * a browser has no token to send.
 *
 * Being unauthenticated is therefore the design and not an oversight — but
 * unlike a webhook there is no secret in the URL and no signature to verify,
 * because there is nothing to verify one against. What protects this is not
 * authentication; it is that nothing a caller sends can do anything. See
 * App\Services\Security\CspViolations for the five bounds on the body, and
 * below for the two on the answer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT ANSWERS 204 TO EVERYTHING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * THE SAME ANSWER WHATEVER HAPPENED, which is the same rule
 * Api\QuizController::expertRequest follows for a forged token: a caller must
 * not be able to learn anything from the difference between two answers. A
 * report that was stored, a report that was dropped for being malformed, a
 * report whose switch is off and a report that hit the row ceiling all get
 * 204 and an empty body. That closes the oracle that would otherwise tell a
 * prober whether the shop is collecting, what shape it accepts, and — by
 * timing the difference between an INSERT and nothing — roughly how full the
 * table is.
 *
 * AND THE BODY IS NEVER ECHOED. Not the report, not a validation message
 * naming a field, not an error quoting what was sent. An endpoint that reflects
 * attacker-controlled bytes is a reflected-XSS surface even when it answers
 * JSON, and this one has nothing to say in the first place: 204 has no body by
 * definition.
 */
class CspReportController extends Controller
{
    public function __construct(private CspViolations $violations) {}

    public function store(Request $request): Response
    {
        $this->violations->receive($request);

        return response()->noContent();
    }
}
