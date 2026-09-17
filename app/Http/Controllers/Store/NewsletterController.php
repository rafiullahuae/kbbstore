<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\NewsletterList;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The two ends of the newsletter round trip: confirming, and getting out.
 *
 * Both are reached from a link in an email, by somebody who is not signed in
 * and very often has no account at all. That single fact decides most of what
 * follows.
 *
 * ---------------------------------------------------------------------------
 * WHY UNSUBSCRIBE IS GET-THEN-POST AND NOT A SINGLE GET
 * ---------------------------------------------------------------------------
 * A GET that acts is a GET that fires when nobody pressed anything. Corporate
 * mail filters, link-safety scanners and inbox previewers all fetch the URLs in
 * a message before a human sees it — Outlook's Safe Links does it on delivery —
 * and every one of them would silently unsubscribe the recipient. The same
 * fetch would confirm a subscription the recipient never agreed to if confirm
 * worked that way round, which would hollow out double opt-in entirely.
 *
 * So the GET renders a page with a button and changes nothing, and the POST
 * does the work. A scanner fetching the link gets a page; a human pressing the
 * button gets the action.
 *
 * CONFIRM IS THE ONE EXCEPTION, and it is an exception on purpose. It also acts
 * on a POST only, for the scanner reason above — but its GET page auto-submits
 * for anyone with scripts, because a shopper who has just asked for a discount
 * code should not have to press two buttons to get it. The no-script path is
 * the same page with the button visible, so it is never a dead end.
 *
 * ---------------------------------------------------------------------------
 * NO ORACLE
 * ---------------------------------------------------------------------------
 * Every failure — forged signature, expired link, id that was never issued —
 * produces the identical page. NewsletterList::rowOrDecoy() keeps the WORK
 * identical too, which is the half that matters: see CLAUDE.md on
 * Api\QuizController::expertRequest, the precedent this follows.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE ECHOES THE ADDRESS
 * ---------------------------------------------------------------------------
 * These pages never print which address they just acted on, and the controller
 * never reads one. A link is a bearer credential; anybody who has it can open
 * these pages, including anyone who found it in a forwarded email or a proxy
 * log. Printing "you have unsubscribed rafi@example.com" would turn a link that
 * grants one harmless action into a way to read the address it belongs to.
 */
class NewsletterController extends Controller
{
    public function __construct(private NewsletterList $list) {}

    /** The landing page for a confirmation link. Changes nothing. */
    public function confirmForm(Request $request, string $id): View
    {
        return view('store.newsletter.confirm', [
            'id' => $id,
            'expires' => (string) $request->query('expires', ''),
            'signature' => (string) $request->query('signature', ''),
        ]);
    }

    /** The press. This is where an address joins the list. */
    public function confirm(Request $request): View
    {
        $ok = $this->list->confirm(
            (int) $request->input('id'),
            (int) $request->input('expires'),
            (string) $request->input('signature'),
        );

        return view('store.newsletter.done', [
            'ok' => $ok,
            'action' => 'confirm',
        ]);
    }

    /** The landing page for an unsubscribe link. Changes nothing. */
    public function unsubscribeForm(Request $request, string $id): View
    {
        return view('store.newsletter.unsubscribe', [
            'id' => $id,
            'expires' => (string) $request->query('expires', ''),
            'signature' => (string) $request->query('signature', ''),
        ]);
    }

    /** The press. This is where an address leaves the list. */
    public function unsubscribe(Request $request): View
    {
        $ok = $this->list->unsubscribe(
            (int) $request->input('id'),
            (int) $request->input('expires'),
            (string) $request->input('signature'),
        );

        return view('store.newsletter.done', [
            'ok' => $ok,
            'action' => 'unsubscribe',
        ]);
    }
}
