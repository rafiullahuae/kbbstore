<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\OutboundOptOut;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Getting out of the two shopper-triggered emails (Lane EN).
 *
 * ── GET RENDERS, POST ACTS ─────────────────────────────────────────────────
 *
 * The same split Store\NewsletterController makes, for the same reason, which
 * is worth repeating because it is not obvious and it is the difference between
 * an unsubscribe that works and one that fires by itself: corporate mail
 * filters, link-safety rewriters and inbox previewers fetch every URL in a
 * message before a human sees it — Outlook's Safe Links does it on delivery.
 * A GET that acted would be pressed by a scanner on every message this shop
 * sends.
 *
 * NO AUTO-SUBMIT HERE, and that IS a difference from the newsletter's confirm
 * page. That page auto-submits because a shopper who has just asked for
 * something should not have to press twice to get it; this one is destructive,
 * and a destructive action must be the human's press and not the page's.
 *
 * ── NO ORACLE, AND NOTHING ECHOED ──────────────────────────────────────────
 *
 * Every failure — forged signature, expired link, wrong kind, id that was never
 * issued — renders the identical page, and OutboundOptOut::act() keeps the WORK
 * identical too by verifying against a decoy row. CLAUDE.md's note on
 * Api\QuizController::expertRequest is the precedent.
 *
 * And these pages never print the address they just acted on. A link is a
 * bearer credential; anybody who has it can open this page, including whoever
 * found it in a forwarded email or a proxy log. Printing the address would turn
 * a link that grants one harmless action into a way to read it.
 */
class MailPreferencesController extends Controller
{
    /** The landing page. Changes nothing. */
    public function form(Request $request, string $kind, string $id): View
    {
        return view('store.mail-preferences.confirm', [
            'kind' => $kind,
            'id' => $id,
            'expires' => (string) $request->query('expires', ''),
            'signature' => (string) $request->query('signature', ''),
        ]);
    }

    /** The press. This is where the address goes onto the suppression list. */
    public function act(Request $request): View
    {
        $ok = OutboundOptOut::act(
            (string) $request->input('kind'),
            (int) $request->input('id'),
            (int) $request->input('expires'),
            (string) $request->input('signature'),
        );

        return view('store.mail-preferences.done', ['ok' => $ok]);
    }
}
