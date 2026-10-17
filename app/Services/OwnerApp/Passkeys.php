<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use App\Models\OwnerAppDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Face ID / fingerprint for the owner app (Lane BIO): WebAuthn with the
 * phone's own platform authenticator, IN ADDITION to the PIN, never instead.
 *
 * The owner: "after login with PIN, user can select auto login with default
 * mobile security method."
 *
 * ── WHAT IS STORED ─────────────────────────────────────────────────────────
 *
 *   owner_app_passkeys     one row per enrolled DEVICE: the credential id, its
 *                          public key as PEM, the signature counter. No secret:
 *                          a copy of the table unlocks nothing.
 *   owner_app_devices      passkey_challenge (SHA-256 of the one live
 *                          challenge), _kind ('reg' | 'get') and _until (2
 *                          minutes). Taken by ONE conditional UPDATE before
 *                          anything is checked, so a challenge is spent by the
 *                          first answer that names it, right or wrong, and a
 *                          replay finds nothing.
 *
 * ── WHAT IS CHECKED (unlock) ───────────────────────────────────────────────
 *
 * The device cookie first (the credential must be THIS device's: another
 * phone's credential, even the same member's, is refused); then clientDataJSON
 * type, challenge and origin; the rpIdHash; User Present AND User Verified
 * (Face ID / fingerprint / the phone's own passcode actually ran); the
 * signature over authData || SHA-256(clientDataJSON) with the stored key
 * (ES256 signatures arrive DER-encoded already; RS256 is PKCS#1 v1.5); and the
 * counter, which must move forward whenever either side is non-zero (a cloned
 * authenticator replays an old count). Every refusal is the same answer.
 *
 * Attestation is 'none': the shop does not care which make of phone it is,
 * only that the same key signs next time. The minimal CBOR reader below reads
 * exactly what that needs (attestationObject -> authData, and the COSE key)
 * and refuses floats, tags and indefinite lengths. No dependency.
 */
final class Passkeys
{
    public const TTL_SECONDS = 120;

    public const TIMEOUT_MS = 60000;

    /** COSE algorithms offered and accepted. */
    public const ES256 = -7;

    public const RS256 = -257;

    private const MAX_ID_BYTES = 255;

    private const FLAG_UP = 0x01;

    private const FLAG_UV = 0x04;

    private const FLAG_AT = 0x40;

    /* ------------------------------------------------------------ options */

    /**
     * Registration options for an UNLOCKED device (the route sits behind
     * OwnerAppSession). Replaces any challenge the device had.
     *
     * @return array<string,mixed>
     */
    public static function registrationOptions(Request $request, OwnerAppDevice $device): array
    {
        $challenge = self::issue((int) $device->id, 'reg');
        $first = trim((string) strtok(trim((string) $device->member?->admin?->name), ' '));
        $label = mb_substr(($first !== '' ? $first.' · ' : '').(string) $device->name, 0, 64);

        return [
            'challenge' => self::b64u($challenge),
            'rp' => ['id' => self::rpId($request), 'name' => 'KBB Owner'],
            // A per-DEVICE handle, never the member id: two enrolled browsers
            // on one phone (Safari and the installed app) each keep their own.
            'user' => ['id' => self::b64u(substr(hash_hmac('sha256', 'owner-app-passkey|'.$device->id, (string) config('app.key'), true), 0, 32)), 'name' => $label, 'displayName' => $label],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ES256], ['type' => 'public-key', 'alg' => self::RS256]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred', 'requireResidentKey' => false],
            'attestation' => 'none',
            'timeout' => self::TIMEOUT_MS,
        ];
    }

    /**
     * Unlock options for this device, or null when it has no credential.
     *
     * @return array<string,mixed>|null
     */
    public static function unlockOptions(Request $request, OwnerAppDevice $device): ?array
    {
        $id = DB::table('owner_app_passkeys')->where('device_id', $device->id)->value('credential_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        return [
            'challenge' => self::b64u(self::issue((int) $device->id, 'get')),
            'rpId' => self::rpId($request),
            'allowCredentials' => [['type' => 'public-key', 'id' => $id, 'transports' => ['internal', 'hybrid']]],
            'userVerification' => 'required',
            'timeout' => self::TIMEOUT_MS,
        ];
    }

    /* ----------------------------------------------------------- register */

    /**
     * Check a registration and store it as this device's one credential.
     * Null on success, else a short reason for the log (never shown).
     */
    public static function register(Request $request, OwnerAppDevice $device, string $clientDataB64, string $attestationB64): ?string
    {
        $challenge = self::take((int) $device->id, 'reg');
        $clientJson = self::unb64u($clientDataB64, 4096);
        $att = self::unb64u($attestationB64, 16384);

        if ($challenge === null) {
            return 'challenge';
        }
        if ($clientJson === null || $att === null) {
            return 'malformed';
        }
        if (($why = self::clientProblem($request, $clientJson, 'webauthn.create', $challenge)) !== null) {
            return $why;
        }

        try {
            [$obj] = self::cbor($att, 0);
            $auth = is_array($obj) ? ($obj['authData'] ?? null) : null;
            if (! is_array($auth) || ($auth[0] ?? null) !== 'bytes') {
                return 'malformed';
            }
            $auth = $auth[1];
            if (($why = self::authProblem($request, $auth, true)) !== null) {
                return $why;
            }
            if (strlen($auth) < 55) {
                return 'malformed';
            }
            $len = unpack('n', substr($auth, 53, 2))[1];
            if ($len < 16 || $len > self::MAX_ID_BYTES || strlen($auth) < 55 + $len + 1) {
                return 'malformed';
            }
            $credId = substr($auth, 55, $len);
            [$cose] = self::cbor($auth, 55 + $len);
            [$pem, $alg] = self::coseToPem($cose);
        } catch (\Throwable) {
            return 'malformed';
        }

        $count = unpack('N', substr($auth, 33, 4))[1];

        DB::transaction(function () use ($device, $credId, $pem, $alg, $count) {
            DB::table('owner_app_passkeys')->where('device_id', $device->id)->delete();
            DB::table('owner_app_passkeys')->insert([
                'device_id' => $device->id,
                'credential_id' => self::b64u($credId),
                'public_key' => $pem,
                'alg' => $alg,
                'sign_count' => $count,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return null;
    }

    /* ------------------------------------------------------------- unlock */

    /**
     * Check an assertion from THIS device. Null when it is good (the counter
     * is already advanced), else a short reason for the log (never shown).
     *
     * @param array{id:string,clientDataJSON:string,authenticatorData:string,signature:string} $a
     */
    public static function verify(Request $request, OwnerAppDevice $device, array $a): ?string
    {
        // Spend the challenge before anything else: one answer per challenge.
        $challenge = self::take((int) $device->id, 'get');
        $row = DB::table('owner_app_passkeys')->where('device_id', $device->id)->first(['id', 'credential_id', 'public_key', 'alg', 'sign_count']);

        if ($challenge === null) {
            return 'challenge';
        }
        if ($row === null || strlen($a['id']) > 400 || ! hash_equals((string) $row->credential_id, $a['id'])) {
            return 'no_cred';
        }

        $clientJson = self::unb64u($a['clientDataJSON'], 4096);
        $auth = self::unb64u($a['authenticatorData'], 2048);
        $sig = self::unb64u($a['signature'], 1024);

        if ($clientJson === null || $auth === null || $sig === null || $sig === '') {
            return 'malformed';
        }
        if (($why = self::clientProblem($request, $clientJson, 'webauthn.get', $challenge)) !== null) {
            return $why;
        }
        if (($why = self::authProblem($request, $auth, false)) !== null) {
            return $why;
        }

        $key = @openssl_pkey_get_public((string) $row->public_key);
        if ($key === false || @openssl_verify($auth.hash('sha256', $clientJson, true), $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return 'bad_sig';
        }

        $count = unpack('N', substr($auth, 33, 4))[1];
        $stored = (int) $row->sign_count;
        if (($count !== 0 || $stored !== 0) && $count <= $stored) {
            return 'counter';
        }

        // Compare-and-set: two answers cannot both move the same count.
        $moved = DB::table('owner_app_passkeys')->where('id', $row->id)->where('sign_count', $stored)
            ->update(['sign_count' => $count, 'last_used_at' => now(), 'updated_at' => now()]);

        return $moved === 1 ? null : 'counter';
    }

    /** This device's credentials and any live challenge, gone. */
    public static function forget(int $deviceId): void
    {
        try {
            DB::table('owner_app_passkeys')->where('device_id', $deviceId)->delete();
            DB::table('owner_app_devices')->where('id', $deviceId)->update(['passkey_challenge' => null, 'passkey_challenge_kind' => null, 'passkey_challenge_until' => null]);
        } catch (\Throwable) {
            // Code ahead of its migration: there is nothing to forget yet.
        }
    }

    /* ----------------------------------------------------------- checking */

    private static function rpId(Request $request): string
    {
        return strtolower($request->getHost());
    }

    private static function clientProblem(Request $request, string $json, string $type, string $challenge): ?string
    {
        $c = json_decode($json, true);

        if (! is_array($c) || ($c['type'] ?? null) !== $type || ! is_string($c['challenge'] ?? null)) {
            return 'client';
        }
        $sent = self::unb64u($c['challenge'], 64);
        if ($sent === null || ! hash_equals($challenge, hash('sha256', $sent))) {
            return 'challenge';
        }
        if (! is_string($c['origin'] ?? null) || ! hash_equals(strtolower($request->getSchemeAndHttpHost()), strtolower($c['origin']))) {
            return 'origin';
        }
        if (($c['crossOrigin'] ?? false) === true) {
            return 'origin';
        }

        return null;
    }

    private static function authProblem(Request $request, string $auth, bool $attested): ?string
    {
        if (strlen($auth) < 37) {
            return 'malformed';
        }
        if (! hash_equals(hash('sha256', self::rpId($request), true), substr($auth, 0, 32))) {
            return 'rp';
        }
        $flags = ord($auth[32]);
        if (($flags & self::FLAG_UP) === 0 || ($flags & self::FLAG_UV) === 0) {
            return 'flags';
        }
        if ($attested && ($flags & self::FLAG_AT) === 0) {
            return 'flags';
        }

        return null;
    }

    /* --------------------------------------------------------- challenges */

    private static function issue(int $deviceId, string $kind): string
    {
        $raw = random_bytes(32);
        DB::table('owner_app_devices')->where('id', $deviceId)->update([
            'passkey_challenge' => hash('sha256', $raw),
            'passkey_challenge_kind' => $kind,
            'passkey_challenge_until' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $raw;
    }

    /**
     * Take the device's live challenge of this kind, once. Returns its SHA-256
     * (hex, as stored) for comparing with the one the browser signed, or null.
     */
    private static function take(int $deviceId, string $kind): ?string
    {
        $row = DB::table('owner_app_devices')->where('id', $deviceId)->first(['passkey_challenge', 'passkey_challenge_kind', 'passkey_challenge_until']);

        if ($row === null || $row->passkey_challenge === null) {
            return null;
        }

        $taken = DB::table('owner_app_devices')->where('id', $deviceId)->where('passkey_challenge', $row->passkey_challenge)
            ->update(['passkey_challenge' => null, 'passkey_challenge_kind' => null, 'passkey_challenge_until' => null]);

        if ($taken !== 1 || $row->passkey_challenge_kind !== $kind || $row->passkey_challenge_until === null
            || now()->gte(\Illuminate\Support\Carbon::parse($row->passkey_challenge_until))) {
            return null;
        }

        return (string) $row->passkey_challenge;
    }

    /* ------------------------------------------------------------ encoding */

    public static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Strict base64url (padding tolerated), with a size ceiling; null when not. */
    public static function unb64u(string $s, int $maxBytes): ?string
    {
        $s = rtrim($s, '=');
        if ($s === '' || strlen($s) > (int) ceil($maxBytes * 4 / 3) + 2 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $s)) {
            return null;
        }
        $raw = base64_decode(strtr($s, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }

    /**
     * The minimal CBOR reader: unsigned and negative integers, byte strings
     * (as ['bytes', raw] so they are never mistaken for text), text, arrays,
     * maps, false/true/null. Anything else throws. Returns [value, next offset].
     *
     * @return array{0:mixed,1:int}
     */
    public static function cbor(string $b, int $o, int $depth = 0): array
    {
        if ($depth > 8 || $o >= strlen($b)) {
            throw new \UnexpectedValueException('cbor');
        }
        $ib = ord($b[$o++]);
        $major = $ib >> 5;
        $ai = $ib & 0x1F;

        if ($major === 7) {
            return match ($ai) {
                20 => [false, $o], 21 => [true, $o], 22 => [null, $o],
                default => throw new \UnexpectedValueException('cbor'),
            };
        }

        if ($ai < 24) {
            $n = $ai;
        } elseif ($ai <= 27) {
            $size = 1 << ($ai - 24);
            if ($o + $size > strlen($b)) {
                throw new \UnexpectedValueException('cbor');
            }
            $n = 0;
            for ($i = 0; $i < $size; $i++) {
                $n = ($n << 8) | ord($b[$o + $i]);
            }
            if ($n < 0) {
                throw new \UnexpectedValueException('cbor');
            }
            $o += $size;
        } else {
            throw new \UnexpectedValueException('cbor');   // indefinite lengths, reserved
        }

        switch ($major) {
            case 0:
                return [$n, $o];
            case 1:
                return [-1 - $n, $o];
            case 2:
            case 3:
                if ($n > strlen($b) - $o) {
                    throw new \UnexpectedValueException('cbor');
                }
                $s = substr($b, $o, $n);

                return [$major === 2 ? ['bytes', $s] : $s, $o + $n];
            case 4:
                if ($n > 64) {
                    throw new \UnexpectedValueException('cbor');
                }
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    [$out[], $o] = self::cbor($b, $o, $depth + 1);
                }

                return [$out, $o];
            case 5:
                if ($n > 64) {
                    throw new \UnexpectedValueException('cbor');
                }
                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    [$k, $o] = self::cbor($b, $o, $depth + 1);
                    if (! is_int($k) && ! is_string($k)) {
                        throw new \UnexpectedValueException('cbor');
                    }
                    [$out[$k], $o] = self::cbor($b, $o, $depth + 1);
                }

                return [$out, $o];
        }

        throw new \UnexpectedValueException('cbor');   // tags (6)
    }

    /**
     * A COSE public key as PEM: EC2 P-256 for ES256, RSA (2048 bits or more)
     * for RS256. Anything else throws.
     *
     * @return array{0:string,1:int}
     */
    public static function coseToPem(mixed $k): array
    {
        $bytes = static fn ($v) => is_array($v) && ($v[0] ?? null) === 'bytes' ? (string) $v[1] : throw new \UnexpectedValueException('cose');

        if (! is_array($k)) {
            throw new \UnexpectedValueException('cose');
        }
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;

        if ($kty === 2 && $alg === self::ES256 && ($k[-1] ?? null) === 1) {
            $x = $bytes($k[-2] ?? null);
            $y = $bytes($k[-3] ?? null);
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \UnexpectedValueException('cose');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;
        } elseif ($kty === 3 && $alg === self::RS256) {
            $n = ltrim($bytes($k[-1] ?? null), "\0");
            $e = ltrim($bytes($k[-2] ?? null), "\0");
            if (strlen($n) < 256 || strlen($n) > 1024 || $e === '' || strlen($e) > 8) {
                throw new \UnexpectedValueException('cose');
            }
            $int = static fn (string $v) => "\x02".self::derLen(strlen(ord($v[0]) & 0x80 ? "\0".$v : $v)).(ord($v[0]) & 0x80 ? "\0".$v : $v);
            $rsa = $int($n).$int($e);
            $rsa = "\x30".self::derLen(strlen($rsa)).$rsa;
            $bit = "\x03".self::derLen(strlen($rsa) + 1)."\0".$rsa;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = "\x30".self::derLen(strlen($algId) + strlen($bit)).$algId.$bit;
        } else {
            throw new \UnexpectedValueException('cose');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        // Only a key openssl itself reads back as the expected type is kept.
        $key = @openssl_pkey_get_public($pem);
        $d = $key === false ? null : openssl_pkey_get_details($key);
        $want = $alg === self::ES256 ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA;
        if (! is_array($d) || ($d['type'] ?? null) !== $want) {
            throw new \UnexpectedValueException('cose');
        }

        return [$pem, (int) $alg];
    }

    private static function derLen(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $s = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($s)).$s;
    }
}
