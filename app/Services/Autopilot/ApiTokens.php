<?php

declare(strict_types=1);

namespace App\Services\Autopilot;

use Illuminate\Support\Facades\DB;

/**
 * Connection keys for the owner's KBB Ads Autopilot Mac app. (Lane AP-API)
 *
 * A key is "kbbap_" + 40 random base62 characters (~238 bits). Only its
 * SHA-256 is stored, so a leaked database row cannot be replayed; the owner
 * sees the key ONCE, in the answer to "Create connection key". A key carries
 * scopes (orders, products), can be revoked, and records when it was last used.
 *
 * Lookup is by the hash of what was presented (a unique index), so a wrong key
 * and a revoked key cost the same one indexed read.
 */
final class ApiTokens
{
    public const PREFIX = 'kbbap_';

    public const SCOPES = ['orders' => 'Orders, refunds and revenue', 'products' => 'Products, stock and cost prices'];

    /** At most this many live keys at once: one per Mac is plenty. */
    public const MAX_LIVE = 10;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A key's shape, checked before any query: anything else is refused unread. */
    public static function wellFormed(string $token): bool
    {
        return preg_match('/^kbbap_[A-Za-z0-9]{40}$/', $token) === 1;
    }

    /**
     * @param  list<string>  $scopes
     * @return array{id: int, token: string, hint: string}|null null when the live-key cap is reached
     */
    public function create(string $name, array $scopes, ?int $adminId): ?array
    {
        $live = (int) DB::table('autopilot_tokens')->whereNull('revoked_at')->count();

        if ($live >= self::MAX_LIVE) {
            return null;
        }

        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $name = mb_substr($name !== '' ? $name : 'KBB Ads Autopilot', 0, 80);

        $body = '';
        for ($i = 0; $i < 40; $i++) {
            $body .= self::ALPHABET[random_int(0, 61)];
        }

        $token = self::PREFIX . $body;
        $hint = self::PREFIX . '…' . substr($body, -4);

        $id = (int) DB::table('autopilot_tokens')->insertGetId([
            'name' => $name,
            'token_hash' => self::hash($token),
            'hint' => $hint,
            'scopes' => implode(',', $scopes),
            'created_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'token' => $token, 'hint' => $hint];
    }

    /** @return object|null the live row for this key: id, name, scopes; null for unknown or revoked */
    public function find(string $token): ?object
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        $row = DB::table('autopilot_tokens')->where('token_hash', self::hash($token))
            ->first(['id', 'name', 'scopes', 'revoked_at', 'last_used_at']);

        return $row === null || $row->revoked_at !== null ? null : $row;
    }

    public function revoke(int $id): bool
    {
        return DB::table('autopilot_tokens')->where('id', $id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;
    }

    /** @return list<string> */
    public static function scopesOf(object $row): array
    {
        return array_values(array_filter(explode(',', (string) $row->scopes)));
    }

    /** @return list<array<string, mixed>> for the admin screen: never the hash */
    public function all(): array
    {
        return DB::table('autopilot_tokens')->orderByDesc('id')->limit(50)
            ->get(['id', 'name', 'hint', 'scopes', 'last_used_at', 'revoked_at', 'created_at'])
            ->map(static fn ($r) => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'hint' => (string) $r->hint,
                'scopes' => self::scopesOf($r),
                'last_used_at' => $r->last_used_at,
                'revoked_at' => $r->revoked_at,
                'created_at' => $r->created_at,
            ])->all();
    }
}
