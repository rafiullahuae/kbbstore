<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edit presence and Take over (Lane RL).
 *
 * The owner: "if any user is editing something ... it should notify to another
 * user if he wants to edit the same. and have function to take control over
 * it ... real time, and super light".
 *
 * ONE TABLE, ONE ROW PER RECORD. `admin_edit_locks` holds the current holder
 * of each record somebody has open (unique resource_type + resource_id), and one
 * 'console' row per admin for "online now". No websockets and no queue: the
 * console beats every ~15s while its tab is visible, and a lock nobody has
 * beaten for TTL seconds is free.
 *
 * COST PER BEAT, measured by EditPresenceTest:
 *   holder refreshing its own lock  1 query  (UPDATE ... WHERE type, id, token, admin)
 *   anybody else on the record      2 queries (SELECT by the unique key, upsert own console row)
 *   on any other admin screen       1 query  (upsert own console row)
 *
 * THE SAVE GUARD (refuseSave) is the authority, not the banner. It runs inside
 * EnforceAdminCapability on the save routes in GUARDED -- an array lookup on
 * every other request -- and refuses with 409 naming the holder:
 *   1. a save carrying a token that was taken over (X-KBB-Edit-Lock), and
 *   2. any save, token or none, while somebody else holds a live lock.
 * So a tab with stale text cannot overwrite the person who took over, whether
 * or not its script noticed.
 *
 * It is a collaboration lock, not a permission: it fails OPEN if the table is
 * not there yet (code before migration), and it applies to the owner as well.
 */
final class EditPresence
{
    /** A lock nobody has beaten for this long is free. Three missed beats. */
    public const TTL = 45;

    /** A console row this fresh is "online now". Beats there are every 30s. */
    public const ONLINE = 75;

    public const TABLE = 'admin_edit_locks';

    /** Record types a lock may name, and how a sentence says them. */
    public const TYPES = [
        'product' => 'product', 'category' => 'category', 'brand' => 'brand',
        'page' => 'page', 'post' => 'article', 'coupon' => 'coupon',
        'order' => 'order', 'email_template' => 'email template', 'campaign' => 'campaign',
    ];

    /**
     * The save routes the lock guards: "METHOD uri" => [type, route parameter].
     * Keyed by the route's own URI pattern, so it is one isset() per request.
     * Order status, notes and refunds are deliberately absent: they are actions
     * taken from the list as often as from the record, and none of them
     * overwrites text somebody is typing.
     */
    public const GUARDED = [
        'PUT admin-api/products/{id}' => ['product', 'id'],
        'POST admin-api/product-editor-save/{id}' => ['product', 'id'],
        'PUT admin-api/categories/{category}' => ['category', 'category'],
        'PUT admin-api/brands/{brand}' => ['brand', 'brand'],
        'POST admin-api/page-editor-save/{id}' => ['page', 'id'],
        'POST admin-api/post-editor-save/{id}' => ['post', 'id'],
        'PUT admin-api/coupons/manage/{coupon}' => ['coupon', 'coupon'],
        'PUT admin-api/orders/{id}/address' => ['order', 'id'],
        'PUT admin-api/orders/{id}/customer' => ['order', 'id'],
        'POST admin-api/orders/{id}/items' => ['order', 'id'],
        'PUT admin-api/orders/{id}/items/{itemId}' => ['order', 'id'],
        'DELETE admin-api/orders/{id}/items/{itemId}' => ['order', 'id'],
        'POST admin-api/emails/templates/{template}' => ['email_template', 'template'],
        'PUT admin-api/email-marketing/campaigns/{id}' => ['campaign', 'id'],
    ];

    public const HEADER = 'X-KBB-Edit-Lock';

    /* ================================================================ beats */

    /**
     * A beat from $me. With no record it only marks $me online. With one it
     * refreshes $me's lock, claims a free one, or reports who holds it.
     *
     * @return array{holder:?string, since:?int, you_hold:bool, taken_over_by:?string, token?:string}
     */
    public static function beat(AdminUser $me, ?string $type, ?string $id, ?string $token): array
    {
        $now = now();

        if ($type === null) {
            self::online($me, $now);

            return self::answer(null, null, false, null);
        }

        // The steady state: the holder refreshing its own lock. One UPDATE.
        if ($token !== null && DB::table(self::TABLE)
            ->where('resource_type', $type)->where('resource_id', $id)
            ->where('token', $token)->where('admin_id', $me->id)
            ->update(['heartbeat_at' => $now]) === 1) {
            return self::answer($me->name, null, true, null);
        }

        $row = self::row($type, $id);

        // A tab that was taken over stays view-only until it reloads, even
        // after the taker has gone: the text on its screen is older than theirs.
        $displaced = $row !== null && $token !== null && $row->displaced_token !== null && hash_equals((string) $row->displaced_token, $token);

        // Free: never opened, released, or nobody beat it for TTL seconds.
        if (! $displaced && ($row === null || self::expired($row, $now))) {
            $new = self::claim($me, $type, $id, $row, $now);
            if ($new !== null) {
                return self::answer($me->name, 0, true, null) + ['token' => $new];
            }
            $row = self::row($type, $id);   // lost the race: report the winner
        }

        // Mine from another tab of mine: share it rather than fight myself.
        if (! $displaced && $row !== null && (int) $row->admin_id === (int) $me->id && ! self::expired($row, $now)) {
            DB::table(self::TABLE)->where('id', $row->id)->update(['heartbeat_at' => $now]);

            return self::answer($me->name, self::ago($row->since_at, $now), true, null) + ['token' => (string) $row->token];
        }

        self::online($me, $now);
        $takenBy = $displaced ? ($row->taker_name ?? 'Another admin') : null;
        $live = $row !== null && ! self::expired($row, $now);

        return self::answer($live ? ($row->holder_name ?? 'Another admin') : null, $live ? self::ago($row->since_at, $now) : null, false, $takenBy);
    }

    /**
     * $me takes the record from whoever holds it. The caller has already been
     * checked for presence.takeover by the route's capability.
     *
     * @return array{holder:?string, since:?int, you_hold:bool, taken_over_by:?string, token?:string}|null null when nobody holds it any more (a plain beat claims it)
     */
    public static function take(AdminUser $me, string $type, string $id): ?array
    {
        $now = now();
        $row = self::row($type, $id);
        if ($row === null || self::expired($row, $now) || (int) $row->admin_id === (int) $me->id) {
            return null;
        }

        $token = self::token();
        // Conditional on the token we read: two people pressing Take over at
        // once cannot both win, and nobody displaces a holder they did not see.
        $won = DB::table(self::TABLE)->where('id', $row->id)->where('token', $row->token)->update([
            'admin_id' => $me->id, 'token' => $token, 'since_at' => $now, 'heartbeat_at' => $now,
            'taken_over_by' => $me->id, 'displaced_token' => $row->token,
        ]) === 1;

        if (! $won) {
            $row = self::row($type, $id);

            return $row === null ? null : self::answer($row->holder_name ?? 'Another admin', self::ago($row->since_at, $now), false, null);
        }

        try {
            app(\App\Services\SecurityModule::class)->record('admin.takeover',
                'Took over editing '.(self::TYPES[$type] ?? $type).' #'.$id.' from '.($row->holder_name ?? 'another admin'), [
                    'subject' => $type.':'.$id, 'severity' => 'notice',
                ]);
        } catch (\Throwable) {
            // The audit line is a courtesy; the takeover has happened.
        }

        return self::answer($me->name, 0, true, null) + ['token' => $token];
    }

    /** The tab is leaving: its lock is free now rather than in TTL seconds. One UPDATE. */
    public static function release(AdminUser $me, string $type, string $id, string $token): void
    {
        // Expired, not deleted: the row keeps displaced_token, so a tab that was
        // taken over still cannot save its stale text after the taker has left.
        DB::table(self::TABLE)->where('resource_type', $type)->where('resource_id', $id)
            ->where('token', $token)->where('admin_id', $me->id)
            ->update(['heartbeat_at' => now()->subDay()]);
    }

    /* ============================================================ the guard */

    /** A 409 when this save would overwrite somebody else's open record; null otherwise. */
    public static function refuseSave(Request $request, AdminUser $me): ?JsonResponse
    {
        $route = $request->route();
        if ($route === null || ! isset(self::GUARDED[$request->method().' '.$route->uri()])) {
            return null;
        }
        [$type, $param] = self::GUARDED[$request->method().' '.$route->uri()];
        $id = (string) $route->originalParameter($param);

        try {
            $row = self::row($type, $id);
        } catch (\Throwable) {
            return null;   // the table is not there yet: a lock that does not exist holds nothing
        }
        if ($row === null) {
            return null;
        }

        $token = (string) $request->header(self::HEADER, '');
        $what = self::TYPES[$type] ?? 'record';

        if ($token !== '' && $row->displaced_token !== null && hash_equals((string) $row->displaced_token, $token)) {
            $who = $row->taker_name ?? 'Another admin';

            return self::conflict('taken_over', $who, "{$who} took over editing this {$what}, so your changes were not saved. Copy anything you need, then reload to see theirs.");
        }

        if (! self::expired($row, now()) && (int) $row->admin_id !== (int) $me->id) {
            $who = $row->holder_name ?? 'Another admin';

            return self::conflict('edit_locked', $who, "{$who} is editing this {$what}, so your changes were not saved. Ask them to finish, or take over from the banner.");
        }

        return null;
    }

    /**
     * Who, other than $me, holds a live lock on this record -- or null.
     *
     * For a write that is not one of GUARDED's routes but still changes a
     * record somebody may have open: SEO Keywords' "Use title / Use
     * description" writes the same `seo` column the product editor saves, so
     * applying it under an open editor would be silently undone by that
     * editor's next Save. Absent table = nothing held, as in refuseSave().
     */
    public static function heldByOther(string $type, string $id, AdminUser $me): ?string
    {
        try {
            $row = self::row($type, $id);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null || self::expired($row, now()) || (int) $row->admin_id === (int) $me->id) {
            return null;
        }

        return $row->holder_name ?? 'Another admin';
    }

    /* ======================================================== online now */

    /**
     * Who has beaten in the last ONLINE seconds, and what each holds: one
     * query, whatever the size of the staff list.
     *
     * @return array<int, array{online:bool, editing:?string}>
     */
    public static function onlineMap(): array
    {
        try {
            $rows = DB::table(self::TABLE)->select(['admin_id', 'resource_type', 'heartbeat_at'])
                ->where('heartbeat_at', '>=', now()->subSeconds(self::ONLINE))->get();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $lockCutoff = now()->subSeconds(self::TTL);
        foreach ($rows as $r) {
            $id = (int) $r->admin_id;
            $out[$id] ??= ['online' => true, 'editing' => null];
            if ($r->resource_type !== 'console' && \Illuminate\Support\Carbon::parse($r->heartbeat_at)->gte($lockCutoff)) {
                $out[$id]['editing'] = self::TYPES[$r->resource_type] ?? null;
            }
        }

        return $out;
    }

    /* ============================================================ internals */

    /** One indexed read: the row by its unique key, with both names joined. */
    private static function row(string $type, string $id): ?object
    {
        return DB::table(self::TABLE.' as l')
            ->leftJoin('admin_users as h', 'h.id', '=', 'l.admin_id')
            ->leftJoin('admin_users as t', 't.id', '=', 'l.taken_over_by')
            ->where('l.resource_type', $type)->where('l.resource_id', $id)
            ->first(['l.id', 'l.admin_id', 'l.token', 'l.since_at', 'l.heartbeat_at', 'l.displaced_token', 'h.name as holder_name', 't.name as taker_name']);
    }

    /** Claim a free record. Returns the new token, or null when somebody else got there first. */
    private static function claim(AdminUser $me, string $type, string $id, ?object $row, $now): ?string
    {
        $token = self::token();
        $fields = ['admin_id' => $me->id, 'token' => $token, 'since_at' => $now, 'heartbeat_at' => $now];

        if ($row === null) {
            $won = DB::table(self::TABLE)->insertOrIgnore($fields + ['resource_type' => $type, 'resource_id' => $id, 'taken_over_by' => null, 'displaced_token' => null]) === 1;
        } else {
            // Conditional on the stale token: of two people claiming one free
            // record, exactly one wins.
            $won = DB::table(self::TABLE)->where('id', $row->id)->where('token', $row->token)->update($fields) === 1;
        }

        if ($won && random_int(1, 50) === 1) {
            // Rows for records nobody has opened in a day. Cheap, and rare.
            DB::table(self::TABLE)->where('heartbeat_at', '<', now()->subDay()->subHour())->delete();
        }

        return $won ? $token : null;
    }

    private static function online(AdminUser $me, $now): void
    {
        DB::table(self::TABLE)->upsert(
            [['resource_type' => 'console', 'resource_id' => (string) $me->id, 'admin_id' => $me->id, 'token' => '', 'since_at' => $now, 'heartbeat_at' => $now]],
            ['resource_type', 'resource_id'],
            ['heartbeat_at'],
        );
    }

    private static function expired(object $row, $now): bool
    {
        return \Illuminate\Support\Carbon::parse($row->heartbeat_at)->lt($now->copy()->subSeconds(self::TTL));
    }

    private static function ago($since, $now): int
    {
        return max(0, (int) \Illuminate\Support\Carbon::parse($since)->diffInSeconds($now, true));
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The only shape a beat ever answers with: a display name and four facts. */
    private static function answer(?string $holder, ?int $since, bool $youHold, ?string $takenOverBy): array
    {
        return ['holder' => $holder, 'since' => $since, 'you_hold' => $youHold, 'taken_over_by' => $takenOverBy];
    }

    private static function conflict(string $error, string $holder, string $message): JsonResponse
    {
        return response()->json(['error' => $error, 'holder' => $holder, 'message' => $message], 409);
    }
}
