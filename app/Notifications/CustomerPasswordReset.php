<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Url;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The reset link, sent to a customer who asked for one.
 *
 * NOT Laravel's built-in ResetPassword notification, for one reason: that one
 * builds its URL with `route('password.reset', ...)`, which renders against
 * APP_URL. On this install APP_URL already ends in /kbb-upgrade
 * (env.staging.txt), so the framework's link comes out as
 * https://…/kbb-upgrade/kbb-upgrade/… — the exact doubling that
 * Support\Url::redirect() was written to stop, and the reason every internal
 * link in this project is built by Support\Url instead of by url()/route().
 *
 * The token is carried in the PATH, not the query string. Query strings end up
 * in Referer headers, in proxy logs and in analytics; a path is only marginally
 * better but it is better, and nothing on the reset page loads a third-party
 * asset that could carry it away.
 *
 * Nothing here is ever logged. The token is not written to the log, the
 * rendered URL is not written to the log, and the message body is not written
 * to the log. The only breadcrumb a failed send leaves is the customer id —
 * enough to answer "did we try", not enough to be a second copy of the link.
 */
class CustomerPasswordReset extends Notification
{
    public function __construct(private string $token, private ?int $customerId = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.customers.expire', 60);

        return (new MailMessage)
            ->subject('Reset your K Beauty Bliss password')
            ->view('store.account.mail.password-reset', [
                'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName() : '',
                'url' => $this->url($notifiable),
                'minutes' => $minutes,
                // Told plainly rather than discovered later: a customer who has
                // been signing in with their original WordPress password needs
                // to know that password stops working, because otherwise the
                // next sign-in failure looks like the reset did not take.
                'retiresOldPassword' => true,
            ]);
    }

    /**
     * An absolute URL, because it is going into an inbox.
     *
     * Url::external() is the helper that prefixes the base path exactly once
     * however APP_URL is written, from APP_URL AND NEVER FROM THE REQUEST. The
     * checkout redirects use Url::redirect(), which is the in-band twin and
     * does read the request host: correct there, because the visitor is already
     * on it, and account takeover here, because a `Host:` a stranger chose
     * would become the address this customer is asked to type a new password
     * into.
     *
     * The customer ID, not the email address. Laravel's own reset link carries
     * `?email=` in the query string, and a query string is the part of a URL
     * that leaks: into Referer headers, into proxy and CDN access logs, into
     * whatever an inbox provider does when it prefetches links. An integer is
     * worth nothing on its own — the token is still what authorises the reset,
     * and the controller reads the address out of the database rather than out
     * of the link.
     */
    private function url(object $notifiable): string
    {
        $id = $this->customerId ?? (int) ($notifiable->getKey() ?? 0);

        return Url::external('/my-account/reset/' . $id . '/' . rawurlencode($this->token) . '/');
    }
}
