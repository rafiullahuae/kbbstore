<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CustomerLinkSigner;
use App\Support\Url;
use Illuminate\Support\Facades\DB;

/**
 * The newsletter list, and the round trip that gets an address onto it.
 *
 * Until this class existed, `Store\SubscribeController` wrote
 * `status = 'subscribed'` the instant a stranger typed an address into the
 * homepage box. That is single opt-in, and it has two problems that are not
 * about compliance paperwork:
 *
 *   1. Anybody could put ANYBODY on this shop's marketing list. A bored visitor
 *      types a rival's address, or a thousand of them, and the shop's first
 *      campaign is a spam run from its own domain, against a list it cannot
 *      defend because it has no evidence anyone consented.
 *   2. A typo'd address sits on the list forever, bouncing, quietly ruining the
 *      sending reputation the whole rest of this subject exists to protect.
 *
 * Double opt-in fixes both with one property: NOTHING IS ON THE LIST UNTIL A
 * LINK SENT TO THAT ADDRESS HAS BEEN CLICKED. `marketable()` below is the only
 * sanctioned way to ask who may be mailed, and it is the single place that
 * property is enforced.
 *
 * ---------------------------------------------------------------------------
 * THE LINKS
 * ---------------------------------------------------------------------------
 * Signed by Support\CustomerLinkSigner, the same primitive the email
 * verification link uses, and for the reasons set out at length in that class:
 * this install carries the signed-URL path-confusion advisory, and every route
 * here is prefixed by KBB_BASE_PATH. A link that travels through an inbox must
 * not depend on the prefix being identical when it is clicked and when it was
 * sent.
 *
 * The claims are the row id and a digest of the address. The digest is what
 * stops a link being replayed against a row that has since been re-signed up
 * under a different address, and it is a DIGEST rather than the address itself
 * because the address must not appear in the URL: a query string ends up in
 * Referer headers, in proxy logs and in whatever an inbox provider does when it
 * prefetches links. CustomerPasswordReset::url() makes the same argument for
 * carrying an integer instead of `?email=`.
 *
 * ---------------------------------------------------------------------------
 * NO ORACLE, THE SAME SHAPE AS THE ONE THIS SHOP ALREADY KEEPS
 * ---------------------------------------------------------------------------
 * CLAUDE.md records that `Api\QuizController::expertRequest` looks its row up
 * BEFORE it checks the signature, so that a forged token and an id that was
 * never issued do the same work and return the same answer, and that branching
 * differently on the two restores the id oracle it was written to close.
 *
 * `confirm()` and `unsubscribe()` keep that shape exactly. The row is fetched
 * first; a missing row is replaced by a decoy with the same fields, so the
 * signature is computed and compared in every case; and both paths end in one
 * `false`. Subscriber ids are small integers, so a version that returned early
 * on "no such row" would let anyone count this shop's mailing list by walking
 * them.
 */
class NewsletterList
{
    /* What a signature is for. Separate purposes so a confirm link can never be
     * replayed as an unsubscribe, or the reverse. */
    public const PURPOSE_CONFIRM = 'newsletter-confirm';

    public const PURPOSE_UNSUBSCRIBE = 'newsletter-unsubscribe';

    /** Statuses. Their meanings are set out in the double-opt-in migration. */
    public const PENDING = 'pending';

    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    /**
     * How long a confirmation link lasts.
     *
     * Long, because this one is not a credential in the way a password reset is
     * — the worst a stale one does is add an address that asked to be added —
     * and because a newsletter signup is exactly the mail somebody reads a week
     * later. Short enough that an address abandoned in a `pending` row does not
     * stay confirmable forever.
     */
    public const CONFIRM_TTL = 60 * 60 * 24 * 14;

    /**
     * How long an unsubscribe link lasts: ten years, which is "forever" with a
     * bound on it.
     *
     * NOT the same number as above, and the difference is the point. A
     * confirmation link that has expired costs somebody a second signup. An
     * unsubscribe link that has expired costs somebody the ability to get off a
     * list they are still being mailed on — and it will expire precisely in the
     * case that matters, the campaign somebody is still receiving two years
     * later. Every marketing email this shop ever sends will carry one of these,
     * and it has to work whenever it is clicked.
     */
    public const UNSUBSCRIBE_TTL = 60 * 60 * 24 * 365 * 10;

    /**
     * How long before the same address can be sent another confirmation.
     *
     * The signup form is public and unauthenticated. Without this, it is a
     * button that mails an address a stranger chose, as often as the route's
     * throttle allows — which is how a shop becomes the tool somebody else uses
     * to bombard an inbox, with this shop's domain in the From line.
     */
    public const RESEND_COOLDOWN = 300;

    /**
     * Who may be sent marketing.
     *
     * THE ONE PLACE THAT DECIDES, and everything that ever mails this list must
     * go through it. Both halves are required and neither is redundant:
     * `status` alone would include a row grandfathered by the double-opt-in
     * migration that has since unsubscribed, and `confirmed_at` alone would
     * include somebody who confirmed and later opted out.
     *
     * Returns a builder rather than rows: the list is meant to grow, and the
     * caller chunks it.
     */
    public static function marketable(): \Illuminate\Database\Query\Builder
    {
        return DB::table('subscribers')
            ->where('status', self::SUBSCRIBED)
            ->whereNotNull('confirmed_at');
    }

    /**
     * Take a signup.
     *
     * Returns one of:
     *   'sent'      a confirmation is on its way (new address, or a pending one
     *               past its cooldown, or somebody who had unsubscribed)
     *   'pending'   already pending and inside the cooldown; nothing was sent
     *   'already'   already confirmed and on the list; nothing was sent
     *
     * The CALLER decides what the shopper is told, and Store\SubscribeController
     * deliberately tells them the same thing for all three — see the constant
     * there. This method's job is to report what actually happened so that the
     * delivery record and the tests can see it; it is not the thing that
     * chooses the wording.
     *
     * @return array{outcome:string,id:int,email:string}
     */
    public function signUp(string $email, string $source = 'homepage'): array
    {
        $email = mb_strtolower(trim($email));
        $now = now();

        $existing = DB::table('subscribers')->where('email', $email)->first();

        if ($existing !== null && (string) $existing->status === self::SUBSCRIBED) {
            /*
             * Already confirmed. Nothing is sent, and that is a safety
             * property rather than an optimisation: re-mailing a confirmed
             * subscriber every time somebody types their address into a public
             * form is the inbox-bombing vector the cooldown below exists to
             * close, and here it can be closed completely.
             */
            return ['outcome' => 'already', 'id' => (int) $existing->id, 'email' => $email];
        }

        if ($existing !== null && (string) $existing->status === self::PENDING) {
            $lastSent = strtotime((string) $existing->updated_at) ?: 0;

            if ($lastSent > 0 && ($now->getTimestamp() - $lastSent) < self::RESEND_COOLDOWN) {
                return ['outcome' => 'pending', 'id' => (int) $existing->id, 'email' => $email];
            }
        }

        /*
         * Written as `pending`, whatever it was before.
         *
         * Including the unsubscribed case, and that is deliberate: somebody who
         * opted out and has now typed their address back into the form is
         * asking to return, and the correct answer is to make them prove the
         * mailbox again rather than to either refuse them or silently re-add
         * them. `confirmed_at` is cleared in the same write, so a row cannot
         * carry a confirmation from a previous life through an unsubscribe and
         * back; without that, marketable() would pick up a returning address
         * the moment its status flipped, before the new round trip finished.
         */
        $row = [
            'status' => self::PENDING,
            'confirmed_at' => null,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $row['created_at'] = $now;
            $row['source'] = substr($source, 0, 40);
        }

        DB::table('subscribers')->updateOrInsert(['email' => $email], $row);

        $id = (int) DB::table('subscribers')->where('email', $email)->value('id');

        return ['outcome' => 'sent', 'id' => $id, 'email' => $email];
    }

    /**
     * Clicking the confirmation link. The address joins the list here and
     * nowhere else.
     */
    public function confirm(int $id, int $expiresAt, string $signature): bool
    {
        $row = $this->rowOrDecoy($id);

        if (! CustomerLinkSigner::verify(self::PURPOSE_CONFIRM, $this->claims($id, (string) $row->email), $expiresAt, $signature)) {
            return false;
        }

        // A decoy never gets past here: its email is random, so the claims it
        // produced cannot match any signature this application issued.
        if ($row->real !== true) {
            return false;
        }

        /*
         * Idempotent. Mail clients prefetch links and people click twice; the
         * second click must land on the same "you are confirmed" page rather
         * than on an error, and must not move `confirmed_at` forward, which
         * would rewrite the record of WHEN consent was given.
         */
        if ((string) $row->status === self::SUBSCRIBED) {
            return true;
        }

        DB::table('subscribers')->where('id', $id)->update([
            'status' => self::SUBSCRIBED,
            'confirmed_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Clicking unsubscribe. Never requires signing in — that is the whole
     * point of it, and a recipient who has to create an account to get off a
     * list has not been given a way off the list.
     */
    public function unsubscribe(int $id, int $expiresAt, string $signature): bool
    {
        $row = $this->rowOrDecoy($id);

        if (! CustomerLinkSigner::verify(self::PURPOSE_UNSUBSCRIBE, $this->claims($id, (string) $row->email), $expiresAt, $signature)) {
            return false;
        }

        if ($row->real !== true) {
            return false;
        }

        /*
         * The row is kept, not deleted, and `confirmed_at` is cleared.
         *
         * Kept, because a deleted row is indistinguishable from an address that
         * was never here — so the next CSV import, or the next signup, would
         * put them straight back on a list they asked to leave. Cleared,
         * because consent ended: marketable() tests both, and leaving a stale
         * confirmation date on an unsubscribed row is how a later change to
         * that query silently starts mailing them again.
         */
        DB::table('subscribers')->where('id', $id)->update([
            'status' => self::UNSUBSCRIBED,
            'confirmed_at' => null,
            'updated_at' => now(),
        ]);

        return true;
    }

    /* ---------------------------------------------------------------- links */

    public function confirmLink(int $id, string $email, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::CONFIRM_TTL;

        return $this->link('/newsletter/confirm', self::PURPOSE_CONFIRM, $id, $email, $expiresAt);
    }

    public function unsubscribeLink(int $id, string $email, ?int $expiresAt = null): string
    {
        $expiresAt ??= time() + self::UNSUBSCRIBE_TTL;

        return $this->link('/newsletter/unsubscribe', self::PURPOSE_UNSUBSCRIBE, $id, $email, $expiresAt);
    }

    private function link(string $path, string $purpose, int $id, string $email, int $expiresAt): string
    {
        $signature = CustomerLinkSigner::sign($purpose, $this->claims($id, $email), $expiresAt);

        return Url::external(sprintf(
            '%s/%d/?expires=%d&signature=%s',
            $path,
            $id,
            $expiresAt,
            $signature,
        ));
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The claims a link's signature covers.
     *
     * The address goes in as a digest, never as itself: see the class header.
     * Lower-cased first so that a row stored lower-case and a link built from a
     * differently-cased copy of the same address agree — otherwise a signature
     * would verify or not depending on how the shopper happened to type it.
     *
     * @return array<string, string>
     */
    private function claims(int $id, string $email): array
    {
        return [
            'id' => (string) $id,
            'hash' => hash('sha256', mb_strtolower(trim($email))),
        ];
    }

    /**
     * The row, or something shaped like it.
     *
     * THE ORACLE-CLOSING HALF, and the reason it looks like wasted work. A
     * missing row returns a decoy carrying a random address, so the caller
     * computes and compares a signature in exactly the same way it would for a
     * real one. Both paths then end in the same `false`.
     *
     * Returning null here and letting the caller bail out early would be
     * simpler and would be an id oracle: subscriber ids are sequential small
     * integers, and a response that differed for "row 41 exists" and "row 41
     * does not" lets anyone binary-search the size of this shop's list, and
     * confirm whether a particular signup went through.
     *
     * The decoy's address is random per call rather than a constant, so the
     * comparison it feeds cannot be precomputed either.
     */
    private function rowOrDecoy(int $id): object
    {
        $row = DB::table('subscribers')->where('id', $id)->first();

        if ($row !== null) {
            return (object) [
                'real' => true,
                'email' => (string) $row->email,
                'status' => (string) $row->status,
            ];
        }

        return (object) [
            'real' => false,
            'email' => bin2hex(random_bytes(16)) . '@invalid.example',
            'status' => self::PENDING,
        ];
    }
}
