<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Payments\StripeConnect;

/**
 * The words on the Stripe Connect application panel.
 *
 * =============================================================================
 * WHY THERE IS A GUIDE AT ALL, AND WHAT IT IS ADMITTING
 * =============================================================================
 *
 * The owner asked, twice, for the WooCommerce behaviour: press Configure, a
 * Stripe window opens, press one button there, come back configured. He is
 * right that WooCommerce does that and right that this shop did not.
 *
 * WooCommerce does it because Automattic runs a Stripe Connect PLATFORM
 * APPLICATION and stands in the middle of the flow on every Woo shop's behalf
 * (the WooCommerce Connect Server). The merchant registers nothing because
 * somebody else already registered it for him.
 *
 * Nobody has done that for this shop. The one-click flow is built, it works,
 * and it needs a `ca_...` client id and a platform secret key that ONLY THE
 * OWNER CAN OBTAIN — they come out of his own Stripe Dashboard and no amount of
 * code here can conjure them. This guide is the part of the job that is his,
 * written out step by step so it is fifteen minutes rather than an afternoon.
 *
 * It is not an apology for a missing feature. Once these two values are saved
 * the button behaves exactly as he described, for good, including after a
 * disconnect — disconnect() deliberately leaves the platform values in place.
 *
 * =============================================================================
 * WHY EVERY `url` BELOW IS null
 * =============================================================================
 *
 * This is a credential-setup guide. A wrong link in one is the shape of a
 * phishing page: an owner who is already holding a secret key, already
 * expecting to be asked for it, following a link he was given by his own admin
 * panel.
 *
 * Outbound access from the machine this was written on is proxied, and
 * dashboard.stripe.com and docs.stripe.com are both blocked by it. Not one
 * Stripe URL could be loaded and confirmed to be the page it is described as.
 * So none is printed. Every step names the MENU PATH instead, which is what
 * survives Stripe reorganising its console anyway, and the panel tells him to
 * start from the dashboard he is already signed in to.
 *
 * If a later lane can reach and verify these pages, adding the links here is a
 * small change. Guessing them is not.
 *
 * =============================================================================
 * WHAT IS ASSERTED AND WHAT IS NOT
 * =============================================================================
 *
 * The mechanism, the parameter names and the two-client-ids fact are from
 * Stripe's own Connect OAuth documentation. The exact wording of menu items is
 * described as approximate wherever it could not be confirmed, because a guide
 * that says "press the button called X" and is wrong about X is worse than one
 * that says "look for the Connect settings".
 */
final class StripeConnectConsole
{
    /**
     * What the owner has to do himself before the button can work.
     *
     * Same shape as App\Support\TranslationConsole::setupGuide(), so the
     * `<details>` renderer the translation screens already use renders this one
     * with no second template.
     *
     * @return array{heading: string, intro: string, steps: list<array{title: string, body: string, url: ?string, link_label: ?string}>, closing: string}
     */
    public static function setupGuide(): array
    {
        return [
            'heading' => 'How to switch on one-click Connect',
            'intro' => 'One-click Connect works the way it does in WooCommerce because WooCommerce.com registered '
                . 'a Stripe Connect application and stands in the middle of the flow for every shop that uses it. '
                . 'Nobody has done that for this shop, so it has to register its own — once, in your own Stripe '
                . 'Dashboard. It takes about fifteen minutes and it never has to be done again. Until then, pasting '
                . 'your secret key below connects this shop just as completely; the only difference is the number of '
                . 'clicks.',
            'steps' => [
                [
                    'title' => 'Sign in to the Stripe Dashboard with the account that takes the money',
                    'body' => 'The same account this shop should be paid into. Everything below is done inside that '
                        . 'account — there is nothing to sign up for separately and no second Stripe account involved.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Switch Connect on for that account',
                    'body' => 'Menu path: the Connect section of the dashboard (it appears in the left-hand product '
                        . 'list; on an account that has never used it there is a "Get started with Connect" panel '
                        . 'instead). Stripe will ask you to accept the Connect platform terms. Accepting them does '
                        . 'not change anything about how your existing payments work and costs nothing on its own.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Complete the platform profile',
                    'body' => 'Stripe asks what your platform does before it will issue the id in the next step. '
                        . 'Answer it about this shop: you sell your own products directly to shoppers, you are not '
                        . 'running a marketplace, and there are no other businesses being paid out. It is a short '
                        . 'form and it is a genuine prerequisite — the client id box stays empty until it is done.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Turn on OAuth onboarding and copy the client id',
                    'body' => 'Menu path: Settings → Connect, then the onboarding options — on some dashboards the '
                        .'same page is reached from the Settings link inside the Connect section itself. Switch on onboarding with '
                        . 'OAuth. Stripe then shows a client id that starts with ca_ — copy it. THERE ARE TWO OF '
                        . 'THEM: a test-mode one and a live-mode one, and they are different values. The dashboard\'s '
                        . 'test-mode switch decides which one you are looking at, so copy each one with the switch in '
                        . 'that position and put it in the matching box on this panel.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Register this shop\'s return address, exactly as it is printed above',
                    'body' => 'On the same settings page there is a list of redirect URIs. Add the address this panel '
                        . 'shows, character for character — Stripe compares it exactly and refuses anything that '
                        . 'differs by a trailing slash or a missing folder. This shop lives in a sub-folder, so the '
                        . 'address includes it; that is not a mistake. In live mode Stripe additionally requires the '
                        . 'address to be https.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Copy the secret key for each mode and paste it here',
                    'body' => 'Menu path: Developers → API keys. The secret key starts sk_ and Stripe shows it once. '
                        . 'The test-mode key goes in the Test box on this panel and the live-mode key in the Live '
                        . 'box — they are not interchangeable, and this panel refuses a key in the wrong box rather '
                        . 'than letting you find out at the end of the flow. This is the value Stripe authenticates '
                        . 'the last step of the connection with; it is stored encrypted, never shown again, and never '
                        . 'sent to your browser.',
                    'url' => null,
                    'link_label' => null,
                ],
                [
                    'title' => 'Press Save, then press Connect with Stripe',
                    'body' => 'The one-click button lights up as soon as both boxes for the current mode are filled '
                        . 'in. A window opens on Stripe\'s own page, you approve, the window closes and this screen '
                        . 'repaints as connected — including the payment notifications, which are set up for you.',
                    'url' => null,
                    'link_label' => null,
                ],
            ],
            'closing' => 'If any of this stalls — Stripe declines to enable Connect, the platform profile asks for '
                . 'something that does not describe this shop, or you would simply rather not register a platform at '
                . 'all — you lose nothing by stopping here. Pasting the secret key into the box below connects this '
                . 'shop to exactly the same account, sets up exactly the same payment notifications, and leaves it in '
                . 'exactly the same working state. The one-click button saves clicks, not capability.',
        ];
    }

    /**
     * The sentence under the Connect application heading.
     *
     * Kept next to the guide so the two cannot drift: this is the short version
     * and the guide is the long one, and an owner who reads only this should
     * still be told the honest thing.
     */
    public static function platformNote(): string
    {
        return 'One-click Connect needs a Stripe Connect application registered in your own Stripe Dashboard — '
            . 'WooCommerce hides this step because WooCommerce.com registers one on every shop\'s behalf, and '
            . 'nobody has done that for this shop. Fill both boxes for the mode you are in and the button switches '
            . 'on. Leave them empty and nothing is lost: pasting your secret key connects this shop just as fully.';
    }

    /**
     * Why the return address looks the way it does.
     *
     * The web root on this host is a different directory from the application
     * root and every route carries KBB_BASE_PATH, so the address Stripe has to
     * be given is NOT the bare domain plus the path in the route file. Owners
     * shorten it; Stripe then refuses the authorize request with an error about
     * the redirect_uri and nothing on the Stripe side explains why.
     */
    public static function redirectNote(): string
    {
        return 'Copy this into the Connect application\'s redirect URI list exactly as it appears, including the '
            . 'folder in the middle. Stripe matches it character for character and refuses the connection if it '
            . 'differs at all.';
    }

    /**
     * Whether the flow is runnable, in words, for the mode being looked at.
     *
     * The screen could work this out from the two booleans, and it did — which
     * is how it came to say "not connected" for four different reasons with one
     * sentence. The distinction that matters is between "you have not set this
     * up" and "you set it up and half of it is missing", because only the
     * second one is a mistake.
     *
     * @param  array<string, mixed>  $status  the payload of StripeConnect::status()
     */
    public static function readiness(array $status): string
    {
        $mode = StripeConnect::normaliseMode($status['platform']['mode'] ?? null);
        $label = $mode === 'live' ? 'Live' : 'Test';

        if (! ($status['oauth_available'] ?? false)) {
            return 'No Connect application is registered, so one-click is off and the key box below is the way in.';
        }

        if (! ($status['oauth_ready'] ?? false)) {
            return 'A Connect application id is saved but the ' . $label . ' platform secret key is not, and Stripe '
                . 'authenticates the last step of the connection with it. The button is held back deliberately: '
                . 'without that key the window would open, you would grant this shop access to your Stripe account, '
                . 'and nothing would be saved.';
        }

        if ($status['platform']['falls_back_to_merchant_key'] ?? false) {
            return 'Ready, using this shop\'s own stored Stripe key to authenticate the exchange. That works while '
                . 'the shop stays connected; saving the platform secret key below makes it work from a clean start '
                . 'too, which is the state it will be in after a disconnect.';
        }

        return 'Ready. Press Connect with Stripe and approve it in the window that opens.';
    }
}
