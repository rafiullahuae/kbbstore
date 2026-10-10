<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

use Illuminate\Support\Facades\DB;

/**
 * Which send a bounce report is about (Lane EB).
 *
 * Every campaign message's Message-ID is
 *
 *     <{send id, base 36}.{HMAC(send id, address)}@{the From address's domain}>
 *
 * Google's own bounce report quotes it twice — X-Original-Message-ID in the
 * delivery-status part, and the original's headers in the attached copy — and
 * almost every other server quotes the original's headers. So the reader can
 * name the campaign and the send row without trusting anything else in the
 * report.
 *
 * WHY SIGNED. The bounce mailbox is a Gmail label a filter fills from
 * "mailer-daemon OR postmaster", and a From line is easy to forge. A forged
 * report naming a send id it was not issued for fails the HMAC (keyed on
 * APP_KEY, covering the ADDRESS), so it cannot attribute a bounce to a real
 * customer's send. The UnsubscribeToken shape, with its own purpose string so
 * neither token is ever valid as the other.
 *
 * Message-ID on the shop's From domain is also RFC 5322 §3.6.4's
 * "id-right … the domain of the host" and what Gmail's sender guidelines
 * expect: a Message-ID that looks like it came from the sending domain.
 */
final class BounceRef
{
    public const PURPOSE = 'mkt-bounce-ref';

    /** The id-left of a campaign Message-ID: "<base36>.<32 hex>". */
    public static function for(int $sendId, string $email): string
    {
        return base_convert((string) $sendId, 10, 36) . '.' . self::signature($sendId, $email);
    }

    public static function messageId(int $sendId, string $email, string $domain): string
    {
        return self::for($sendId, $email) . '@' . $domain;
    }

    /**
     * Every candidate ref in a block of text (a DSN, an original's headers).
     *
     * @return list<string>
     */
    public static function findAll(string $text): array
    {
        preg_match_all('/<([0-9a-z]{1,13}\.[0-9a-f]{32})@[a-z0-9.-]+>/i', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    }

    /**
     * The send row a ref was issued for, or null. Looks the row up FIRST and
     * then compares with hash_equals, so a forged ref and an unknown id do the
     * same work (UnsubscribeToken's no-oracle shape).
     *
     * @return object{id:int, campaign_id:int, email:string}|null
     */
    public static function resolve(string $ref): ?object
    {
        [$rawId, $sig] = array_pad(explode('.', strtolower($ref), 2), 2, '');
        $id = preg_match('/^[0-9a-z]{1,13}$/', $rawId) === 1 ? (int) base_convert($rawId, 36, 10) : 0;

        $row = $id > 0 ? DB::table('mkt_sends')->where('id', $id)->first(['id', 'campaign_id', 'email']) : null;
        $expected = self::signature($id, $row !== null ? (string) $row->email : 'decoy@invalid.invalid');

        return hash_equals($expected, $sig) && $row !== null ? $row : null;
    }

    private static function signature(int $sendId, string $email): string
    {
        return substr(hash_hmac('sha256', self::PURPOSE . '|' . $sendId . '|' . mb_strtolower(trim($email)), (string) config('app.key')), 0, 32);
    }
}
