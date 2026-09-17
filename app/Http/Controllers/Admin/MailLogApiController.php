<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Mail → Sent mail: what this shop sent, to whom, and what failed.
 *
 * WHY THIS ENDPOINT EXISTS AT ALL. Every mail failure in this application is
 * swallowed — OrderMailer, OrderMailObserver, PasswordResetController and
 * SubscribeController all catch a transport exception and log it, deliberately,
 * so that a dead mail server cannot turn into a failed order or a 500 on the
 * admin's "mark as dispatched" button. That is the right trade and it leaves a
 * hole: the failures go to storage/logs, and the owner of this shop has no
 * shell on this host and cannot read them. A swallowed failure nobody can see
 * is the same defect as a send that only looked like it worked.
 *
 * ADMIN ONLY, AND NOT NEGOTIABLE. Every row carries a customer's email address,
 * and the table holds every address the shop has ever sent to — which is a
 * better customer list than the customers table, because it includes people who
 * only ever asked for a password reset. CLAUDE.md records that `/api/*` is
 * unauthenticated and has leaked `author_email` and `ip` before; this is
 * mounted in the admin-api group beside the rest of the Mail screen, behind
 * `auth:admin`, and tests/Feature/ApiSecurityTest.php pins that no public route
 * serves it.
 *
 * NO BODIES AND NO TOKENS. The table never stores a message body — see
 * MailLog's header and the migration's — so there is nothing here that could
 * render a live reset link or verification token. This controller adds no
 * lookup that could reintroduce one: it returns rows as MailLog::present()
 * built them and nothing else.
 */
class MailLogApiController extends Controller
{
    public function __construct(private MailLog $log) {}

    public function show(Request $request): JsonResponse
    {
        /*
         * `status` is validated against a closed list rather than passed
         * through. It reaches a query builder, and "fail closed on an unknown
         * value" is the rule PaymentsApiController and MailApiController::save()
         * already enforce on this screen's siblings.
         */
        $status = (string) $request->query('status', 'all');

        if (! in_array($status, ['all', 'sent', 'failed'], true)) {
            $status = 'all';
        }

        $limit = (int) $request->query('limit', 100);

        return response()->json([
            'entries' => $this->log->recent($limit, $status === 'all' ? null : $status),
            'counts' => $this->log->counts(),
            // What the screen should say when there is nothing to show. An
            // empty list means two very different things and the difference is
            // the whole point of the screen: nothing has been sent, or nothing
            // has been RECORDED because this package's migration has not run.
            'filter' => $status,
        ]);
    }
}
