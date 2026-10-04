<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use Illuminate\Support\Facades\DB;

/**
 * The unsubscribe link's credential (Lane MK, docs/EMAILS-PLAN.md §5).
 *
 *     /email/u/{send id in base 36}-{HMAC(send id, address, purpose)}
 *
 * The HMAC is keyed on APP_KEY and covers the ADDRESS the message went to,
 * so a token cannot be replayed against another row, and the address itself
 * is never in the URL (a query string ends up in Referer headers, proxy logs
 * and whatever an inbox does when it prefetches links).
 *
 * NO ORACLE — the QuizSubmission::publicToken shape. find() looks the row up
 * FIRST, then computes the signature against the row's address, or against a
 * fixed decoy when there is no such row, and compares with hash_equals. A
 * forged token and an id that was never issued do the same work and come back
 * the same null; the controllers answer both with the same page.
 */
final class UnsubscribeToken
{
    public const PURPOSE = 'mkt-unsubscribe';

    public const PATTERN = '[0-9a-z]{1,13}-[0-9a-f]{32}';

    public static function for(int $sendId, string $email): string
    {
        return base_convert((string) $sendId, 10, 36) . '-' . self::signature($sendId, $email);
    }

    /**
     * The send row this token was issued for, or null.
     *
     * @return object{id:int, campaign_id:int, email:string}|null
     */
    public static function find(string $token): ?object
    {
        [$rawId, $sig] = array_pad(explode('-', $token, 2), 2, '');
        $id = preg_match('/^[0-9a-z]{1,13}$/', $rawId) === 1 ? (int) base_convert($rawId, 36, 10) : 0;

        $row = $id > 0
            ? DB::table('mkt_sends')->where('id', $id)->first(['id', 'campaign_id', 'email', 'unsubscribed_at'])
            : null;

        $expected = self::signature($id, $row !== null ? (string) $row->email : 'decoy@invalid.invalid');
        $valid = hash_equals($expected, strtolower($sig));

        return $valid && $row !== null ? $row : null;
    }

    private static function signature(int $sendId, string $email): string
    {
        return substr(hash_hmac('sha256', self::PURPOSE . '|' . $sendId . '|' . mb_strtolower(trim($email)), (string) config('app.key')), 0, 32);
    }
}
