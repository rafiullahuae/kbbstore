<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Mail\ContactInquiryAlert;
use App\Models\ContactInquiry;
use App\Rules\StorefrontEmail;
use App\Services\Mail\MailLog;
use App\Support\ContactPage;
use App\Support\Url;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

use function Illuminate\Support\defer;

/**
 * POST /contact-us/send — the contact page's inquiry form.          (Lane CT)
 *
 * A plain form post that answers with a redirect back to the page, so it works
 * with no JavaScript at all; the page draws the outcome from the flash and puts
 * the focus on the first field in error (`autofocus`, server-side).
 *
 * ── IN THIS ORDER ───────────────────────────────────────────────────────────
 *
 *   1. CSRF — the `web` group, before this method runs.
 *   2. The shop's firewall (Store → Security → Firewall) — BlockGate, before
 *      that. A write is always "covered": a blocked address, a banned range or
 *      a blocked country is refused there, and in Protect mode a POST with no
 *      proof cookie AND no shop session is refused. A visitor who opened the
 *      page has the session, so the form never trips it.
 *   3. The rate limit: RATE_MAX posts per address per RATE_WINDOW, counted on
 *      every attempt, whatever it was. Over it, nothing is read or stored.
 *   4. The honeypot: a field no person sees. Filled in, the post is answered
 *      exactly as a real one is — the same redirect, the same thank-you — and
 *      nothing is stored, so a script learns nothing from the answer.
 *   5. The minimum fill time: the form carries its own signed issue time
 *      (ContactPage::stamp). Missing, forged, or under MIN_SECONDS old, the
 *      visitor is asked to send again — a person who really was that quick
 *      loses one press, never their message (withInput keeps it).
 *   6. Validation, with caps that match the table's columns.
 *   7. The row. THEN the mail, after the response (defer), inside a try: a
 *      mail server that is down never loses the inquiry and never shows the
 *      visitor an error.
 */
class ContactInquiryController extends Controller
{
    public const RATE_MAX = 5;

    public const RATE_WINDOW = 600;

    public function store(Request $request): RedirectResponse
    {
        $config = ContactPage::config();

        if (! $config['form']) {
            abort(404);
        }

        // No #fragment: a URL that targets an element makes the browser skip
        // autofocus, which is how the page puts focus on the outcome.
        $back = Url::to('/contact-us/');
        $key = 'contact-inquiry:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::RATE_MAX)) {
            return redirect()->to($back)->withInput($this->kept($request))
                ->with('ctc_error', __('store.contact.err_limit'));
        }

        RateLimiter::hit($key, self::RATE_WINDOW);

        if (trim((string) $request->input('website', '')) !== '') {
            return redirect()->to($back)->with('ctc_sent', true);
        }

        $age = ContactPage::age($request->input('ts'));

        if ($age === null || $age < ContactPage::MIN_SECONDS) {
            return redirect()->to($back)->withInput($this->kept($request))
                ->with('ctc_error', __('store.contact.err_fast'));
        }

        $topics = $config['topics'];
        $max = ContactPage::MAX;

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:'.$max['name']],
            'email' => ['required', 'string', 'max:'.$max['email'], new StorefrontEmail],
            'phone' => ['nullable', 'string', 'max:'.$max['phone'], 'regex:/^[0-9+()\-.\s]{6,}$/'],
            'topic' => ['required', 'integer', 'min:0', 'max:'.(count($topics) - 1)],
            'message' => ['required', 'string', 'min:'.ContactPage::MESSAGE_MIN, 'max:'.$max['message']],
        ], [
            'name.*' => __('store.contact.err_name'),
            'email.*' => __('store.contact.err_email'),
            'phone.*' => __('store.contact.err_phone'),
            'topic.*' => __('store.contact.err_topic'),
            'message.max' => __('store.contact.err_message_long'),
            'message.*' => __('store.contact.err_message'),
        ]);

        if ($validator->fails()) {
            return redirect()->to($back)->withErrors($validator)->withInput($this->kept($request));
        }

        $data = $validator->validated();

        $inquiry = ContactInquiry::create([
            'name' => ContactPage::singleLine((string) $data['name']),
            'email' => trim((string) $data['email']),
            'phone' => ($p = ContactPage::singleLine((string) ($data['phone'] ?? ''))) === '' ? null : $p,
            'topic' => mb_substr($topics[(int) $data['topic']], 0, 80),
            // Line breaks kept (it is a message); every other control character goes.
            'message' => trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $data['message'])),
            'locale' => substr((string) app()->getLocale(), 0, 8),
            'ip' => substr((string) $request->ip(), 0, 45),
        ]);

        $this->alert($inquiry->id, ContactPage::recipient($config));

        return redirect()->to($back)->with('ctc_sent', true);
    }

    /**
     * Send the owner's alert after the response has gone.
     *
     * defer(), named, for the reasons SubscribeController::dispatchConfirmation()
     * gives: an SMTP handshake must not hold the visitor's page, and a mail
     * server that is down must not become their error. Swallowed and recorded —
     * in the delivery log the owner reads (Emails → Sent mail) and in the app
     * log, with the inquiry id and the exception class only, never the message
     * body or the visitor's address.
     */
    private function alert(int $id, string $to): void
    {
        if ($to === '') {
            return;
        }

        defer(function () use ($id, $to): void {
            try {
                $inquiry = ContactInquiry::query()->find($id);

                if ($inquiry === null) {
                    return;
                }

                app(MailLog::class)->labelNext('contact.inquiry');

                Mail::mailer(\App\Services\Mail\MailConfigurator::MAILER)
                    ->to($to)
                    ->send(new ContactInquiryAlert($inquiry));

                ContactInquiry::query()->whereKey($id)->update(['mailed_at' => now()]);
            } catch (\Throwable $e) {
                try {
                    app(MailLog::class)->recordFailure($e);
                } catch (\Throwable) {
                    // A recorder that cannot record must not break anything.
                }

                Log::warning('Contact inquiry alert could not be sent.', [
                    'inquiry_id' => $id,
                    'exception' => $e::class,
                ]);
            }
        }, 'kbb-contact-inquiry-'.$id);
    }

    /** What the page may refill after a refusal: never the honeypot or the stamp. */
    private function kept(Request $request): array
    {
        return $request->only(['name', 'email', 'phone', 'topic', 'message']);
    }
}
