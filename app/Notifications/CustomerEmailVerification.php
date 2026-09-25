<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Customer;
use App\Support\CustomerLinkSigner;
use App\Support\Url;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm your email address."
 *
 * The link is signed by Support\CustomerLinkSigner rather than by
 * URL::signedRoute(), and that class's header explains at length why: the
 * signed-URL path-confusion advisory this project carries, and KBB_BASE_PATH.
 * The short version is that Laravel signs a rendered URL, this site's URLs
 * carry a configurable prefix, and a link whose validity depends on the prefix
 * being identical when it is clicked and when it was sent is a link that breaks
 * for every customer the day the prefix changes.
 *
 * Purpose string, customer id, a digest of the address and an expiry go into the
 * MAC. The host and the base path do not.
 */
class CustomerEmailVerification extends Notification
{
    public const PURPOSE = 'customer-email-verify';

    /** How long a verification link stays usable. */
    public const TTL_MINUTES = 60 * 24;

    public function __construct(private Customer $customer) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email address')
            ->view('store.account.mail.verify-email', [
                'name' => $this->customer->displayName(),
                'url' => self::linkFor($this->customer),
                'hours' => (int) (self::TTL_MINUTES / 60),
            ]);
    }

    /**
     * The absolute link, built the one way this project builds links.
     *
     * Exposed as a static so the controller's "resend" path and the tests use
     * the identical construction; two copies of a signing convention is how a
     * link that validates in a test stops validating in an inbox.
     */
    public static function linkFor(Customer $customer, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::TTL_MINUTES * 60;

        $signature = CustomerLinkSigner::sign(self::PURPOSE, self::claims($customer), $expiresAt);

        return Url::external(sprintf(
            '/my-account/verify/%d/%s/?expires=%d&signature=%s',
            $customer->getKey(),
            $customer->verificationHash(),
            $expiresAt,
            $signature,
        ));
    }

    /** @return array<string, string> */
    public static function claims(Customer $customer): array
    {
        return [
            'id' => (string) $customer->getKey(),
            'hash' => $customer->verificationHash(),
        ];
    }
}
