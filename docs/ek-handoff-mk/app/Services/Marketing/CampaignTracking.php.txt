<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The three public things a campaign email can ask of the shop — Lane EK:
 * "I was opened" (a 1×1 picture), "I was clicked" (a redirect) and
 * "unsubscribe me" (one click, RFC 8058). All three are reached WITHOUT a
 * session, from an inbox, so all three are built to be safe in public:
 *
 * NO PII IN A URL. A recipient is named by a TOKEN: 43 characters of
 * HMAC-SHA256(APP_KEY, campaign | address). It is never stored — the row
 * keeps its SHA-256, so a database leak does not hand out working unsubscribe
 * or click links, and the address cannot be read back out of a link. A forged,
 * malformed or unknown token costs the same one hash and one indexed lookup as
 * a real one and gets the same answer (a blank pixel; the shop's home page; the
 * "this link is not valid" page).
 *
 * NO OPEN REDIRECT. A click carries an INDEX into the campaign's own list of
 * links — the URLs the email itself contains, fixed when the send started —
 * and a signature over (campaign, index, URL) checked with hash_equals(). The
 * redirect target always comes out of that list, never out of the request, so
 * even a valid token cannot be pointed anywhere the owner did not put in the
 * email. Anything that fails goes to the shop's own home page.
 *
 * Opens are counted but never trusted as a headline (Apple Mail opens every
 * message itself): the report says "at least N".
 */
final class CampaignTracking
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public static function token(int $campaignId, string $email): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'kbb-campaign|' . $campaignId . '|' . mb_strtolower(trim($email)), self::key(), true)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The signature on one link of one campaign. */
    public static function linkSignature(int $campaignId, int $index, string $url): string
    {
        return substr(hash_hmac('sha256', 'kbb-click|' . $campaignId . '|' . $index . '|' . $url, self::key()), 0, 32);
    }

    /** The recipient a token names, or null — the same single lookup either way. */
    public static function recipient(string $token): ?object
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $token = str_repeat('x', 43);
        }

        try {
            return DB::table('campaign_recipients')->where('token_hash', self::hash($token))->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function open(string $token): void
    {
        $r = self::recipient($token);

        if ($r === null) {
            return;
        }

        DB::table('campaign_recipients')->where('id', $r->id)->update([
            'open_count' => DB::raw('open_count + 1'),
            'opened_at' => $r->opened_at ?? now(),
            'updated_at' => now(),
        ]);
    }

    /** Where a click goes, or null when anything about it is wrong. */
    public function click(string $token, int $index, string $signature): ?string
    {
        $r = self::recipient($token);

        if ($r === null || $index < 0) {
            return null;
        }

        $links = Campaign::query()->whereKey($r->campaign_id)->value('links');
        $links = is_array($links) ? $links : [];
        $url = is_array($links[$index] ?? null) ? (string) ($links[$index]['url'] ?? '') : '';

        if ($url === '' || preg_match('#^https?://#i', $url) !== 1
            || ! hash_equals(self::linkSignature((int) $r->campaign_id, $index, $url), $signature)) {
            return null;
        }

        try {
            DB::transaction(static function () use ($r, $index): void {
                DB::table('campaign_clicks')->insert([
                    'campaign_id' => $r->campaign_id, 'recipient_id' => $r->id, 'link' => $index, 'created_at' => now(),
                ]);
                DB::table('campaign_recipients')->where('id', $r->id)->update([
                    'click_count' => DB::raw('click_count + 1'),
                    'clicked_at' => $r->clicked_at ?? now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // The shopper still gets where they were going.
            Log::warning('campaign click not recorded', ['exception' => class_basename($e)]);
        }

        return $url;
    }

    /**
     * Unsubscribe, now. The address goes on the opt-out list every campaign
     * checks at the moment it sends, and — for a newsletter subscriber — the
     * subscription itself ends, the same state NewsletterList::unsubscribe()
     * writes. Idempotent: pressing it twice, or a provider's one-click POST
     * plus the human's own, changes nothing the second time.
     */
    public function unsubscribe(string $token): bool
    {
        $r = self::recipient($token);

        if ($r === null) {
            return false;
        }

        $email = mb_strtolower(trim((string) $r->email));
        $now = now();

        DB::table('marketing_optouts')->insertOrIgnore([
            'email' => $email, 'campaign_id' => $r->campaign_id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('campaign_recipients')->where('id', $r->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => $now, 'updated_at' => $now]);

        DB::table('subscribers')->where('email', $email)->where('status', '!=', 'unsubscribed')
            ->update(['status' => 'unsubscribed', 'confirmed_at' => null, 'updated_at' => $now]);

        return true;
    }

    public static function optedOut(string $email): bool
    {
        return DB::table('marketing_optouts')->where('email', mb_strtolower(trim($email)))->exists();
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
