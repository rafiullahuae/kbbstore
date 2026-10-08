<?php

declare(strict_types=1);

namespace App\Services\Instagram;

use App\Mail\OwnerAppSecurityAlert;
use App\Services\Mail\MailConfigurator;
use App\Services\OwnerApp\OwnerAppAlerts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Instagram needs reconnecting" — one email to every Full Admin, once per
 * invalidation (Lane IG2).
 *
 * Sent when Meta answers the stored token with error 190 (or Instagram's 401):
 * a password change, the app removed from the account, the Page role revoked, or
 * a token that ran out. The posts already on the shop keep showing; only new
 * ones stop arriving, so this is a nudge rather than an alarm.
 *
 * InstagramCredentials::markInvalid() returns true only the first time, so a
 * refresh pressed ten times — or the daily command running for a week — sends
 * one email, not ten. A reconnect clears the flag.
 *
 * The same generic subject-and-lines mailable and the same recipients (every Full
 * Admin with an address) as the owner app's security alerts. NO LINK and no
 * token in the body — words that name the admin screen, nothing else.
 */
final class InstagramReconnectNotice
{
    public static function send(string $via): int
    {
        $sent = 0;
        $route = $via === 'facebook' ? 'Connect with Facebook' : 'Connect with Instagram';

        try {
            foreach (OwnerAppAlerts::recipients() as $to) {
                try {
                    Mail::mailer(MailConfigurator::MAILER)->to($to)->send(new OwnerAppSecurityAlert(
                        'Instagram needs reconnecting',
                        [
                            'Meta has stopped accepting this shop\'s Instagram connection. This usually means a '
                                .'password was changed, the app was removed, or the Page role changed.',
                            'Your posts are still showing on the shop. Only new posts have stopped arriving.',
                            'To fix it: admin → Content → Instagram → press Reconnect ('.$route.'), and log in again.',
                        ],
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('instagram reconnect notice not sent', ['exception' => class_basename($e)]);
                }
            }
        } catch (\Throwable) {
        }

        return $sent;
    }
}
