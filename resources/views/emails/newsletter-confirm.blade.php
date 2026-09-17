{{--
    The newsletter confirmation.

    Deliberately plain, and deliberately NOT built on emails/layout.blade.php.
    That layout is the order-email masthead: it carries the shop's wordmark, its
    support block and its marketing footer, and this is the one message that
    goes to an address which has not agreed to hear from this shop yet. Dressing
    a consent request up as a branded marketing email is the thing double opt-in
    exists to prevent — see App\Mail\NewsletterConfirmation's header.

    Same shape and same reasoning as store/account/mail/verify-email.blade.php:
    no layout, inline styles, and the URL printed in full for anyone whose
    client will not follow a button.
--}}
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d1d1f;">
    <p>Hello,</p>

    <p>Somebody — we hope it was you — asked for {{ $brand['storeName'] ?? 'our' }} emails to be sent to this address.</p>

    <p><strong>You are not on the list yet.</strong> Press the button below and you will be.</p>

    <p style="margin:24px 0;">
        <a href="{{ $confirmUrl }}" style="display:inline-block;padding:12px 22px;background:#1d1d1f;color:#ffffff;text-decoration:none;border-radius:6px;">Yes, subscribe me</a>
    </p>

    <p style="font-size:13px;color:#555;">Or paste this into your browser:<br>
        <span style="word-break:break-all;">{{ $confirmUrl }}</span></p>

    <p>The link works for {{ $days }} days.</p>

    {{--
        The "do nothing" sentence is the important one and it is the honest one,
        because it is true: signUp() writes the row as `pending` and
        NewsletterList::marketable() — the only query anything may mail from —
        requires `subscribed` AND a confirmation date. An address that ignores
        this message receives nothing further.
    --}}
    <p style="color:#555;">If it was not you, do nothing. Without that press we will not add this address, and you will not hear from us again.</p>

    <p style="color:#555;">— {{ $brand['storeName'] ?? 'K Beauty Bliss' }}</p>

    {{--
        An unsubscribe link on a message to somebody who is not subscribed looks
        redundant and is not. It is the remedy for the case this whole flow is
        designed around: somebody else typed this address in. "Do nothing" leaves
        a pending row that a future change could mishandle; this button settles
        it — the address goes to `unsubscribed` and a later signup has to prove
        the mailbox again before anything reaches it.
    --}}
    <p style="font-size:12px;color:#888;border-top:1px solid #eee;padding-top:12px;">
        Never want email from us at this address?
        <a href="{{ $unsubscribeUrl }}" style="color:#888;">Unsubscribe</a>.
    </p>
</div>
