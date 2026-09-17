<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Mail\NewsletterConfirmation;
use App\Services\Mail\MailLog;
use App\Services\NewsletterList;
use App\Services\NewsletterSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Newsletter signup — now the first half of a round trip rather than the whole
 * of it.
 *
 * WHAT CHANGED AND WHY. This method used to write `status = 'subscribed'` and
 * return. Anybody could therefore put anybody on this shop's marketing list by
 * typing their address into a public box, and a typo'd address stayed on it
 * forever, bouncing. Now the row is written `pending` and nothing may mail it
 * until a link sent to that address has been clicked —
 * App\Services\NewsletterList holds the whole of that rule, and
 * `NewsletterList::marketable()` is the only sanctioned way to ask who is on
 * the list.
 *
 * Answers JSON to the fetch on the homepage and a redirect to a plain form post,
 * because the form has to keep working with scripts blocked — before this it
 * returned JSON either way, so anyone without the script running got a page of
 * {"ok":true} where the homepage had been.
 *
 * ---------------------------------------------------------------------------
 * ONE ANSWER, AND IT CLOSES AN ORACLE THAT WAS ALREADY OPEN
 * ---------------------------------------------------------------------------
 * routes/web.php records this endpoint as "a newsletter-membership oracle" and
 * throttles it on that basis, because it answered `nl_success` for a new
 * address and `nl_duplicate` for one already held. Anybody could ask this shop
 * whether a given person was on its list.
 *
 * Double opt-in makes that fixable rather than merely throttleable, because the
 * three outcomes now have the same truthful answer. New address, pending
 * address, address that had unsubscribed: a confirmation link is on its way,
 * and until it is clicked none of them is on the list. Already-confirmed is the
 * only case where nothing is sent — and "if that address is not already
 * confirmed, we have sent it a link" is true there too.
 *
 * This is the wording shape PasswordResetController::SENT_MESSAGE uses, for the
 * identical reason, and the message is a constant here for the identical reason
 * as well: three literals at three call sites drift into three subtly different
 * sentences, and a difference is all an oracle needs.
 *
 * `nl_success` and `nl_duplicate` remain in NewsletterSettings and remain
 * editable; they are simply no longer the thing that answers this endpoint. See
 * the note on CONFIRM_MESSAGE.
 */
class SubscribeController extends Controller
{
    /**
     * The one answer a signup ever gets.
     *
     * NOT owner-editable, and that is a deliberate reversal of this screen's
     * usual rule that wording belongs to the owner. The other newsletter
     * strings are decoration; this one is load-bearing. An owner who edited it
     * back to "You are on the list!" would be telling shoppers they are
     * subscribed when they are pending — the exact false success this whole
     * subject exists to remove — and an owner who wrote two different sentences
     * for two cases would reopen the membership oracle above without knowing
     * they had done it.
     */
    public const CONFIRM_MESSAGE = 'Almost there — check your inbox. If that address is not already on the list, we have just sent it a link to confirm. Please look in your spam folder too.';

    public function __construct(
        private NewsletterSettings $newsletter,
        private NewsletterList $list,
    ) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $data = $request->validate([
                // StorefrontEmail, not `email`: CLAUDE.md records the CRLF
                // injection advisory against the framework's own rule, and this
                // is a public form that takes an address from a stranger and
                // causes a message to be sent to it. Same reasoning
                // PasswordResetController's header sets out.
                'email' => ['required', 'string', 'max:160', new \App\Rules\StorefrontEmail],
            ]);
        } catch (ValidationException $e) {
            return $this->fail($request, (string) $this->newsletter->get('nl_error'));
        }

        $source = substr((string) $request->input('source', 'homepage'), 0, 40);

        if (! $this->newsletter->get('nl_source_tag')) {
            $source = 'homepage';
        }

        $result = $this->list->signUp($data['email'], $source);

        if ($result['outcome'] === 'sent') {
            $this->dispatchConfirmation($result['id'], $result['email']);
        }

        /*
         * The same message for every outcome, including the ones that sent
         * nothing. See the class header: this is what closes the membership
         * oracle, and it is true in all four cases.
         */
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => self::CONFIRM_MESSAGE])
            : back()->with('kbb_subscribed', self::CONFIRM_MESSAGE);
    }

    /**
     * Send the confirmation, after the response has gone.
     *
     * DEFERRED, AND FOR TWO SEPARATE REASONS.
     *
     * The first is the one PasswordResetController's header sets out: an SMTP
     * handshake to a shared host takes far longer than the rest of this request
     * and varies wildly, so leaving it inline would make "we sent one" and "we
     * sent nothing" distinguishable by a stopwatch — rebuilding, in the timing,
     * the oracle the wording above just closed.
     *
     * The second is plainer. This runs on the homepage. A shopper who types an
     * address into a footer box must not sit and watch a spinner while this
     * shop negotiates TLS with a mail server, and must not see the signup fail
     * because the mail server is down. The row is already written by the time
     * this is scheduled; the email is a consequence of the signup, never a
     * precondition for it.
     *
     * defer(), not app()->terminating(), and named — the same choice
     * PasswordResetController documents: terminating callbacks are never
     * cleared from the Application, which is harmless under PHP-FPM and a
     * duplicate send under anything that handles two requests in one process.
     */
    private function dispatchConfirmation(int $id, string $email): void
    {
        $confirm = $this->list->confirmLink($id, $email);
        $unsubscribe = $this->list->unsubscribeLink($id, $email);
        $days = (int) round(NewsletterList::CONFIRM_TTL / 86400);

        defer(function () use ($email, $confirm, $unsubscribe, $days): void {
            try {
                app(MailLog::class)->labelNext('newsletter.confirm');

                Mail::mailer(\App\Services\Mail\MailConfigurator::MAILER)
                    ->to($email)
                    ->send(new NewsletterConfirmation($confirm, $unsubscribe, $days));
            } catch (\Throwable $e) {
                /*
                 * Swallowed, and recorded twice: once in the delivery log the
                 * owner can actually read, and once here.
                 *
                 * The subscriber id and the exception CLASS only. Not the
                 * message — a mail transport puts the recipient and sometimes
                 * the body in it — and above all not the links, either of which
                 * would be a permanent second copy of a live token in a log
                 * file. CustomerPasswordReset's header makes the same rule for
                 * the same reason.
                 */
                try {
                    app(MailLog::class)->recordFailure($e);
                } catch (\Throwable) {
                    // A recorder that cannot record must not be the thing that
                    // breaks the request it was recording.
                }

                Log::warning('Newsletter confirmation could not be sent.', [
                    'subscriber_id' => $id,
                    'exception' => $e::class,
                ]);
            }
        }, 'kbb-newsletter-confirm-' . $id);
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => false, 'error' => $message], 422)
            : back()->withInput()->with('kbb_subscribe_error', $message);
    }
}
