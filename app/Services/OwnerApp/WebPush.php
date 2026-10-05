<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Web Push with nothing but PHP's openssl and hash extensions.
 *
 * The repository takes no new dependency, and Web Push needs two pieces of
 * cryptography, both small and both fully specified:
 *
 *   1. VAPID (RFC 8292): an ES256 JWT that tells the push service which
 *      server is sending. openssl_sign() over P-256 with SHA-256 gives a DER
 *      signature; JOSE wants the raw 64-byte r||s, so derToRaw() unpacks it.
 *
 *   2. Message encryption (RFC 8291, `aes128gcm` content coding, RFC 8188):
 *      an ephemeral P-256 key pair, ECDH against the browser's key
 *      (openssl_pkey_derive), HKDF-SHA-256 twice (hash_hkdf) with the
 *      subscription's auth secret and a random salt, then AES-128-GCM over
 *      the payload plus the 0x02 last-record delimiter. The push service
 *      sees ciphertext only; the device decrypts it.
 *
 * tests/Feature/OwnerAppPushTest.php decrypts what encrypt() produces with
 * the receiving side's private key, per the RFC, and checks the JWT verifies
 * against the public key — so neither half is "looks plausible".
 *
 * ── WHERE IT MAY SEND ──────────────────────────────────────────────────────
 *
 * A subscription's endpoint is a URL the BROWSER supplied, and this server
 * POSTs to it. Unchecked, that is a request-forgery primitive into the
 * server's own network for anybody with a member's PIN. allowedEndpoint()
 * admits https only, port 443, no credentials, and only the hosts the four
 * real push services use. Anything else is refused at subscribe time and
 * again at send time.
 */
final class WebPush
{
    /** Hosts the browsers' push services send from. Suffix match on a dot boundary. */
    public const HOSTS = [
        'fcm.googleapis.com',            // Chrome, Edge on Android, Samsung Internet
        'push.services.mozilla.com',     // Firefox (updates.push.services.mozilla.com)
        'push.apple.com',                // Safari / iOS home-screen apps (web.push.apple.com)
        'notify.windows.com',            // Edge on Windows (wns2-*.notify.windows.com)
    ];

    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function allowedEndpoint(string $url): bool
    {
        if (strlen($url) > 1000 || ! str_starts_with($url, 'https://')) {
            return false;
        }

        $p = parse_url($url);
        if (! is_array($p) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            return false;
        }

        $host = strtolower((string) ($p['host'] ?? ''));

        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------ encoding */

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $pad = strlen($s) % 4;

        return (string) base64_decode(strtr($s, '-_', '+/').($pad ? str_repeat('=', 4 - $pad) : ''), true);
    }

    /** The uncompressed point (0x04 || X || Y, 65 bytes) of an EC key. */
    public static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);

        return "\x04"
            .str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /** An OpenSSL public key from a raw uncompressed P-256 point. */
    public static function keyFromPoint(string $point): ?\OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }

        $der = hex2bin(self::SPKI_P256_PREFIX).$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /* ---------------------------------------------------------- encryption */

    /**
     * RFC 8291 `aes128gcm` body for one subscription.
     *
     * $ephemeral and $salt exist for the test, which needs the same inputs on
     * both sides; production leaves them null and gets fresh ones every call.
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?\OpenSSLAsymmetricKey $ephemeral = null, ?string $salt = null): ?string
    {
        $uaPublic = self::b64uDecode($p256dh);
        $authSecret = self::b64uDecode($auth);
        $uaKey = self::keyFromPoint($uaPublic);

        if ($uaKey === null || strlen($authSecret) !== 16 || strlen($payload) > 3000) {
            return null;
        }

        $as = $ephemeral ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($as === false) {
            return null;
        }
        $asPublic = self::publicPoint($as);

        $shared = openssl_pkey_derive($uaKey, $as, 32);
        if ($shared === false) {
            return null;
        }

        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            return null;
        }

        // Header: salt(16) | record size(4) | key id length(1) | key id (the sender's public point)
        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /* --------------------------------------------------------------- VAPID */

    /** `vapid t=<jwt>, k=<public key>` for one push service origin. */
    public static function vapidHeader(string $endpoint, ?array $pair = null, ?int $now = null): ?string
    {
        $pair ??= VapidKeys::pair();
        if ($pair === null) {
            return null;
        }

        $p = parse_url($endpoint);
        $aud = 'https://'.strtolower((string) ($p['host'] ?? ''));
        $now ??= time();

        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => $now + 12 * 3600, 'sub' => self::subject()], JSON_UNESCAPED_SLASHES));
        $input = $header.'.'.$claims;

        $key = openssl_pkey_get_private($pair['pem']);
        if ($key === false || ! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $raw = self::derToRaw($der);
        if ($raw === null) {
            return null;
        }

        return 'vapid t='.$input.'.'.self::b64u($raw).', k='.$pair['public'];
    }

    /** ECDSA DER SEQUENCE{INTEGER r, INTEGER s} -> 64 raw bytes. */
    public static function derToRaw(string $der): ?string
    {
        $o = 0;
        if (($der[$o++] ?? '') !== "\x30") {
            return null;
        }
        $len = ord($der[$o++]);
        if ($len & 0x80) {
            $o += $len & 0x7f;
        }

        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (($der[$o++] ?? '') !== "\x02") {
                return null;
            }
            $n = ord($der[$o++]);
            $int = substr($der, $o, $n);
            $o += $n;
            $int = ltrim($int, "\0");
            if (strlen($int) > 32) {
                return null;
            }
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }

        return $out;
    }

    /** RFC 8292 `sub`: the shop's own https address, which every push service accepts. */
    private static function subject(): string
    {
        $url = (string) config('app.url', '');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (str_starts_with($url, 'https://') && $host !== '') {
            return 'https://'.$host;
        }

        return 'mailto:owner-app@'.($host !== '' && str_contains($host, '.') ? $host : 'example.com');
    }

    /* ---------------------------------------------------------------- send */

    /**
     * Send one payload to many subscriptions at once (a concurrent pool, five
     * seconds each at most). Subscriptions the push service says are gone
     * (404/410) are deleted; one that keeps failing is deleted after 20.
     *
     * @param list<object{id:int, endpoint:string, p256dh:string, auth:string}> $subs
     * @param array<int,string> $payloads subscription id => JSON payload
     */
    public static function send(array $subs, array $payloads, int $ttl = 86400): int
    {
        $pair = VapidKeys::pair();
        if ($pair === null || $subs === []) {
            return 0;
        }

        $requests = [];
        foreach ($subs as $s) {
            if (! self::allowedEndpoint((string) $s->endpoint) || ! isset($payloads[(int) $s->id])) {
                continue;
            }
            $body = self::encrypt($payloads[(int) $s->id], (string) $s->p256dh, (string) $s->auth);
            $auth = self::vapidHeader((string) $s->endpoint, $pair);
            if ($body === null || $auth === null) {
                continue;
            }
            $requests[(int) $s->id] = [(string) $s->endpoint, $body, $auth];
        }

        if ($requests === []) {
            return 0;
        }

        $responses = Http::pool(function (Pool $pool) use ($requests, $ttl) {
            $out = [];
            foreach ($requests as $id => [$endpoint, $body, $auth]) {
                $out[] = $pool->as((string) $id)
                    ->timeout(5)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'Authorization' => $auth,
                        'TTL' => (string) $ttl,
                        'Urgency' => 'high',
                        'Content-Encoding' => 'aes128gcm',
                    ])
                    ->withBody($body, 'application/octet-stream')
                    ->post($endpoint);
            }

            return $out;
        });

        $sent = 0;
        $gone = [];
        $failed = [];
        foreach ($responses as $id => $response) {
            $status = $response instanceof \Illuminate\Http\Client\Response ? $response->status() : 0;
            if ($status >= 200 && $status < 300) {
                $sent++;
            } elseif ($status === 404 || $status === 410) {
                $gone[] = (int) $id;
            } else {
                $failed[] = (int) $id;
            }
        }

        try {
            if ($gone !== []) {
                DB::table('owner_app_push_subscriptions')->whereIn('id', $gone)->delete();
            }
            if ($failed !== []) {
                DB::table('owner_app_push_subscriptions')->whereIn('id', $failed)->increment('fail_count');
                DB::table('owner_app_push_subscriptions')->whereIn('id', $failed)->where('fail_count', '>', 20)->delete();
            }
            $ok = array_values(array_diff(array_keys($requests), $gone, $failed));
            if ($ok !== []) {
                DB::table('owner_app_push_subscriptions')->whereIn('id', $ok)->update(['fail_count' => 0, 'last_sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('owner app push bookkeeping failed', ['exception' => class_basename($e)]);
        }

        return $sent;
    }
}
