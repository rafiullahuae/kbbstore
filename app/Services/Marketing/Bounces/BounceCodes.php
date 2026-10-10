<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

/**
 * What a mail server's answer means for the ADDRESS (Lane EB).
 *
 * Two places ask: the bounce reports read from the mailbox (a DSN's Status
 * and Diagnostic-Code, RFC 3464) and the refusal Google's SMTP server gives
 * at the moment of sending. Both are classified here so the two can never
 * disagree about the same code.
 *
 * ── HARD: the address is dead, stop now ───────────────────────────────────
 *   5.1.x  bad mailbox / bad domain / no MX          (RFC 3463 §3.2)
 *   5.2.1  mailbox disabled
 *   5.4.4  unable to route (no such domain)
 *   any 5.x.x whose words say "user unknown", "does not exist", … — the
 *   generic 550 5.0.0 many servers send for an unknown recipient.
 *
 * ── SOFT: counted; three within 30 days is treated as hard ────────────────
 *   4.x.x  a final failure after retries
 *   5.2.2  MAILBOX FULL — soft, DECIDED: RFC 3463 says X.2.2 is "useful for
 *          both permanent and persistent transient errors"; a full mailbox
 *          is emptied by its owner, and Gmail sends 552 5.2.2 for it. Three in
 *          a month and it is suppressed anyway.
 *   5.7.x  POLICY — soft, DECIDED: a block for spam, reputation or DMARC is
 *          about the SENDER's message, not the recipient's address. Removing a
 *          good customer because the shop's DKIM was missing that day would be
 *          wrong twice.
 *   every other 5.x.x
 *
 * ── SENDER (send time only): Google is telling the SHOP to slow down ──────
 *   421, any 4xx not naming the mailbox (4.2.x), 4.7.x, 5.7.x, 5.4.5
 *   ("Daily user sending limit exceeded"), 454, 535/530
 *   (sign-in), "rate limit", "temporarily deferred", "try again later", and
 *   a refusal with no code at all (the connection dropped). None of these
 *   says anything about the recipient, so none of them may mark or suppress
 *   one: the row goes back to pending and sending backs off (SendBackoff).
 *
 *   The defect this replaces: CampaignSender matched /\b5\d\d\b/ and called
 *   every 5xx "HARD". Google's "550 5.4.5 Daily user sending limit exceeded"
 *   is a 5xx — so the day the limit was hit, every remaining recipient of the
 *   campaign was marked HARD, and the next campaign suppressed them all.
 */
final class BounceCodes
{
    public const HARD = 'hard';

    public const SOFT = 'soft';

    public const SENDER = 'sender';

    /** Words that mean "no such recipient", whatever code they came with. */
    private const UNKNOWN_USER = '/user unknown|unknown user|no such (user|mailbox|recipient)|does not exist|doesn\'t exist|address (not found|rejected)|recipient (address )?rejected|invalid (recipient|mailbox|address)|unknown recipient|mailbox (not found|unavailable|does not exist)|account (is )?(disabled|inactive|has been disabled)|not a valid mailbox|undeliverable address/i';

    /** Google telling the sender to slow down or stop for the day. */
    private const THROTTLE = '/rate limit|ratelimit|temporarily (deferred|rejected)|try again later|too many|sending limit|quota exceeded|exceeded the rate|unusual rate|daily user sending/i';

    /**
     * hard | soft for an RFC 3463 status (e.g. "5.1.1") and its diagnostic.
     */
    public static function kindFor(string $status, string $diagnostic = ''): string
    {
        $status = trim($status);

        if (preg_match('/^([245])\.(\d{1,3})\.(\d{1,3})$/', $status, $m) !== 1) {
            // No status: only words that plainly say "no such user" are hard.
            return preg_match(self::UNKNOWN_USER, $diagnostic) === 1 && preg_match('/\b5\d\d\b/', $diagnostic) === 1
                ? self::HARD : self::SOFT;
        }

        if ($m[1] !== '5') {
            return self::SOFT;
        }

        if ($m[2] === '1' || $status === '5.2.1' || $status === '5.4.4') {
            return self::HARD;
        }

        if ($status === '5.2.2' || $m[2] === '7') {
            return self::SOFT;
        }

        return preg_match(self::UNKNOWN_USER, $diagnostic) === 1 ? self::HARD : self::SOFT;
    }

    /**
     * A refusal at send time: hard | soft | sender, with the code found.
     *
     * @return array{class:string, code:?string}
     */
    public static function atSend(string $message): array
    {
        $enhanced = preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $message, $e) === 1 ? $e[1] : null;
        // The reply code itself — Symfony's 'got code "550"', or a line that
        // starts with one — never any three digits (a port, "smtp.gmail.com:587").
        $basic = preg_match('/(?:got code "|with message "|^|\n)\s*([245]\d\d)(?=[\s"-]|$)/', $message, $b) === 1 ? $b[1] : null;
        $code = $enhanced ?? $basic;

        if ($code === null
            || preg_match(self::THROTTLE, $message) === 1
            || in_array($basic, ['421', '454', '530', '535'], true)
            // A temporary refusal from Google's submission server is about the
            // server, unless it names the mailbox (4.2.x).
            || (str_starts_with($code, '4') && ! str_starts_with((string) $enhanced, '4.2.'))
            || ($enhanced !== null && (str_starts_with($enhanced, '4.7.') || $enhanced === '5.4.5' || str_starts_with($enhanced, '5.7.')))) {
            return ['class' => self::SENDER, 'code' => $code];
        }

        if ($enhanced !== null) {
            return ['class' => self::kindFor($enhanced, $message), 'code' => $enhanced];
        }

        // A bare 5xx with words: hard only if the words say "no such user".
        return ['class' => str_starts_with((string) $basic, '5') && preg_match(self::UNKNOWN_USER, $message) === 1 ? self::HARD : self::SOFT, 'code' => $basic];
    }
}
