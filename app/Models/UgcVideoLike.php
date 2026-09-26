<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One like, from one browser, on one clip.
 *
 * ── THIS IS A PUBLIC WRITE, AND IT CARRIES NO PERSONAL DATA ────────────────
 *
 * `/api/*` is unauthenticated on this shop, so Api\UgcController::like() is a
 * row anybody on the internet can create. Two columns is the whole table:
 * the clip, and a SHA-256 of a token this shop minted itself and handed the
 * browser in a cookie.
 *
 * NOT STORED, deliberately, and each omission is a decision rather than a
 * shortcut:
 *
 *   IP address      the rate limit is per-IP and lives in the RateLimiter's own
 *                   cache, which expires. Writing it here would turn a like
 *                   button into a durable visitor log, and nothing in this
 *                   feature ever needs to ask which address liked what.
 *   user agent      same, with less use.
 *   customer id     a like is not an account action. Tying one to a customer
 *                   would make the table a behavioural profile and would also
 *                   be wrong: the guard is per-browser, and one person's two
 *                   phones are two browsers.
 *
 * ── THE UNIQUE INDEX IS THE RULE, NOT THE CODE ─────────────────────────────
 *
 * `(ugc_video_id, token_hash)` is unique in the schema. A read-then-write guard
 * in PHP is a race two clicks in the same second both win — which on a rail of
 * tiles with a tap target is not hypothetical. The controller therefore inserts
 * and treats a constraint violation as "already liked", which is the same answer
 * from the shopper's side and the only one that cannot double-count.
 *
 * ── NEVER SERIALISED ───────────────────────────────────────────────────────
 *
 * There is no endpoint that returns a row from this table and there must not be:
 * `token_hash` is the hash of a live bearer cookie. $hidden is the second lock;
 * the first is that UgcVideo::toApi() publishes the COUNT and never the ledger.
 */
class UgcVideoLike extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** The cookie that carries the browser's token. */
    public const COOKIE = 'kbb_ugc_liker';

    /** A year, the same life the reviews module gives its own vote cookie. */
    public const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Is this a token this shop could have minted?
     *
     * 32 hex characters, which is what mint() produces. Checked before the hash
     * is taken so a client that sends four kilobytes of junk in the cookie does
     * not get four kilobytes hashed and a row written for it — the cookie is
     * attacker-controlled, and "we only ever hash it" is not a reason to accept
     * any shape at all.
     */
    public static function looksMinted(?string $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{32}$/', $token) === 1;
    }

    /** A fresh browser token. */
    public static function mint(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** What goes in the column. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
