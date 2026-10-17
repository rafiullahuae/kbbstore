<?php

declare(strict_types=1);

/*
 * Face ID / fingerprint unlock for the owner app (Lane BIO).
 *
 * The owner: "after login with PIN, user can select auto login with default
 * mobile security method." A WebAuthn platform credential per enrolled device,
 * alongside the PIN. The phone here is a real P-256 (and RSA) key made with
 * openssl in the test; the attestationObject, authenticatorData and
 * clientDataJSON are built byte by byte, the way a phone builds them.
 *
 * Each test names the defect it would catch and the change that turns it red.
 */

use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\Passkeys;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

/** A phone's platform authenticator, in software. */
final class BioFakePhone
{
    public \OpenSSLAsymmetricKey $key;

    public string $credId;

    public int $count = 0;

    public function __construct(public string $type = 'ec')
    {
        $this->key = $type === 'ec'
            ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
            : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->credId = random_bytes(32);
    }

    public static function cbor(mixed $v): string
    {
        $head = static function (int $major, int $n): string {
            if ($n < 24) {
                return chr(($major << 5) | $n);
            }
            if ($n < 256) {
                return chr(($major << 5) | 24).chr($n);
            }
            if ($n < 65536) {
                return chr(($major << 5) | 25).pack('n', $n);
            }

            return chr(($major << 5) | 26).pack('N', $n);
        };

        if ($v instanceof BioBytes) {
            return $head(2, strlen($v->raw)).$v->raw;
        }
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_string($v)) {
            return $head(3, strlen($v)).$v;
        }
        if (is_array($v)) {
            $out = $head(5, count($v));
            foreach ($v as $k => $x) {
                $out .= self::cbor($k).self::cbor($x);
            }

            return $out;
        }

        throw new LogicException('cbor');
    }

    public function cose(): array
    {
        $d = openssl_pkey_get_details($this->key);
        if ($this->type === 'ec') {
            return [1 => 2, 3 => -7, -1 => 1,
                -2 => new BioBytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                -3 => new BioBytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))];
        }

        return [1 => 3, 3 => -257, -1 => new BioBytes($d['rsa']['n']), -2 => new BioBytes($d['rsa']['e'])];
    }

    public static function client(string $type, string $challenge, string $origin = 'http://localhost'): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** navigator.credentials.create(), answered. */
    public function create(string $challenge, array $o = []): array
    {
        $client = self::client('webauthn.create', $challenge, $o['origin'] ?? 'http://localhost');
        $auth = hash('sha256', $o['rp'] ?? 'localhost', true).chr($o['flags'] ?? 0x45).pack('N', $this->count)
            .str_repeat("\0", 16).pack('n', strlen($this->credId)).$this->credId.self::cbor($this->cose());
        $att = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new BioBytes($auth)]);

        return ['id' => Passkeys::b64u($this->credId), 'clientDataJSON' => Passkeys::b64u($client), 'attestationObject' => Passkeys::b64u($att)];
    }

    /** navigator.credentials.get(), answered. */
    public function get(string $challenge, array $o = []): array
    {
        // A synced passkey (iCloud, Google) always says 0; a count is given explicitly.
        $this->count = $o['count'] ?? $this->count;
        $client = self::client('webauthn.get', $challenge, $o['origin'] ?? 'http://localhost');
        $auth = hash('sha256', $o['rp'] ?? 'localhost', true).chr($o['flags'] ?? 0x05).pack('N', $this->count);
        openssl_sign($auth.hash('sha256', $client, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        if (! empty($o['tamper'])) {
            $sig[10] = chr(ord($sig[10]) ^ 0x01);
        }

        return ['id' => Passkeys::b64u($this->credId), 'clientDataJSON' => Passkeys::b64u($client), 'authenticatorData' => Passkeys::b64u($auth), 'signature' => Passkeys::b64u($sig)];
    }
}

final class BioBytes
{
    public function __construct(public string $raw) {}
}

beforeEach(function () {
    OA::wire($this->app);
});

/** Enrol a phone with the PIN; returns [cookies, csrf]. */
function bioEnrol($test): array
{
    if (DB::table('admin_users')->count() === 0) {
        OA::member(OA::admin());
    }

    return OA::enrol($test);
}

/** Face ID / fingerprint switched on, from an unlocked session. */
function bioRegister($test, array $c, string $csrf, BioFakePhone $phone): void
{
    $opt = OA::post($test, 'passkey/new', [], $c, $csrf)->assertOk()->json('publicKey');
    OA::post($test, 'passkey/save', $phone->create($opt['challenge']), $c, $csrf)->assertOk()->assertJson(['ok' => true]);
}

/** Lock, as "Lock now" or the idle timer does; returns the cookies left. */
function bioLock($test, array $c, string $csrf): array
{
    return OA::cookies(OA::post($test, 'lock', [], $c, $csrf)->assertOk(), $c);
}

function bioChallenge($test, array $c): string
{
    return (string) OA::post($test, 'passkey/start', [], $c, null)->assertOk()->json('publicKey.challenge');
}

function bioRefused($r): void
{
    $r->assertStatus(422);
    expect($r->json())->toBe(OwnerAppAuth::PASSKEY_REFUSED)
        ->and(collect($r->headers->getCookies())->contains(fn ($k) => $k->getName() === OwnerAppAuth::SESSION_COOKIE))->toBeFalse();
}

it('unlocks with Face ID / fingerprint into the very session a PIN opens', function (string $type) {
    // DEFECT: the passkey path forks the session code and opens something the
    // rest of the app does not accept (no CSRF value, no cookie, another shape).
    // MUTATION: return ['status' => 200, 'body' => ['ok' => true]] from
    // passkeyUnlock() instead of opened() and the state check is red.
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone($type);
    bioRegister($this, $c, $csrf, $phone);

    $row = DB::table('owner_app_passkeys')->first();
    expect($row->credential_id)->toBe(Passkeys::b64u($phone->credId))
        ->and($row->alg)->toBe($type === 'ec' ? -7 : -257)
        ->and($row->public_key)->toStartWith('-----BEGIN PUBLIC KEY-----');

    $c = bioLock($this, $c, $csrf);
    OA::get($this, 'state', $c, '')->assertJson(['stage' => 'pin']);

    $opt = OA::post($this, 'passkey/start', [], $c, null)->assertOk()->json('publicKey');
    expect($opt['rpId'])->toBe('localhost')->and($opt['userVerification'])->toBe('required')
        ->and($opt['allowCredentials'][0]['id'])->toBe(Passkeys::b64u($phone->credId));

    $r = OA::post($this, 'passkey/unlock', $phone->get($opt['challenge']), $c, null)->assertOk();
    $c = OA::cookies($r, $c);
    expect($r->json('csrf'))->toBe(OwnerAppAuth::csrfFor($c[OwnerAppAuth::SESSION_COOKIE]))
        ->and($r->json('me.first'))->toBe('Rafi');
    OA::get($this, 'state', $c, $r->json('csrf'))->assertJson(['stage' => 'app']);
    OA::get($this, 'dashboard', $c, $r->json('csrf'))->assertOk();

    // The same answer a PIN gets, key for key.
    $c2 = bioLock($this, $c, $r->json('csrf'));
    $pin = OA::post($this, 'unlock', ['pin' => '482613'], $c2, null)->assertOk();
    expect(array_keys($r->json()))->toBe(array_keys($pin->json()));

    expect(DB::table('owner_app_logins')->where('kind', 'passkey')->where('success', true)->count())->toBe(1);
})->with(['ES256 (P-256)' => 'ec', 'RS256' => 'rsa']);

it('asks the phone for a platform authenticator that verifies the user, with no attestation', function () {
    // DEFECT: a security key or a bare "tap" (no Face ID / fingerprint) is
    // accepted as the owner. MUTATION: 'userVerification' => 'preferred'.
    [$c, $csrf] = bioEnrol($this);
    $o = OA::post($this, 'passkey/new', [], $c, $csrf)->assertOk()->json('publicKey');

    expect($o['rp']['id'])->toBe('localhost')
        ->and($o['authenticatorSelection'])->toBe(['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred', 'requireResidentKey' => false])
        ->and($o['attestation'])->toBe('none')
        ->and(array_column($o['pubKeyCredParams'], 'alg'))->toBe([-7, -257])
        ->and(strlen(Passkeys::unb64u($o['challenge'], 64)))->toBe(32)
        ->and($o['user']['name'])->not->toContain('@');
    // The challenge is stored only as a hash.
    expect(DB::table('owner_app_devices')->value('passkey_challenge'))->toBe(hash('sha256', Passkeys::unb64u($o['challenge'], 64)));
});

it('refuses an answer signed over a challenge the server never issued, and spends the real one', function () {
    // DEFECT: the server takes the challenge from clientDataJSON on trust,
    // so a recorded answer unlocks forever. MUTATION: drop the hash_equals
    // on the challenge in Passkeys::clientProblem().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    $real = bioChallenge($this, $c);
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(Passkeys::b64u(random_bytes(32))), $c, null));
    // That attempt spent the real one too: a challenge answers once.
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get($real), $c, null));
    expect(DB::table('owner_app_logins')->where('kind', 'passkey')->pluck('reason')->all())->toBe(['challenge', 'challenge']);
});

it('refuses a replayed answer', function () {
    // DEFECT: a challenge that is not single-use, so an answer sniffed once
    // unlocks again. MUTATION: skip the UPDATE in Passkeys::take().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    $answer = $phone->get(bioChallenge($this, $c));
    OA::post($this, 'passkey/unlock', $answer, $c, null)->assertOk();
    bioRefused(OA::post($this, 'passkey/unlock', $answer, $c, null));
});

it('refuses a challenge older than two minutes', function () {
    // DEFECT: challenges that never expire. MUTATION: drop the _until check in take().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    $ch = bioChallenge($this, $c);
    $this->travel(Passkeys::TTL_SECONDS + 1)->seconds();
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get($ch), $c, null));
});

it('refuses an answer made for another site', function (array $o) {
    // DEFECT: a phishing page on another origin (or another rp id) relays the
    // owner's Face ID. MUTATION: drop the origin check (or the rpIdHash check).
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), $o), $c, null));
})->with(['other origin' => [['origin' => 'https://evil.example']], 'other rp id' => [['rp' => 'evil.example']]]);

it('refuses an answer without user verification (no Face ID / fingerprint actually ran)', function (int $flags) {
    // DEFECT: a phone that only saw a tap — no face, no finger, no passcode —
    // unlocks the shop's back office. MUTATION: drop FLAG_UV from authProblem().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['flags' => $flags]), $c, null));
})->with(['UP only' => 0x01, 'UV only' => 0x04, 'none' => 0x00]);

it('refuses a bad signature', function () {
    // DEFECT: a signature that is parsed but never checked. MUTATION: treat
    // openssl_verify() !== 1 as success in Passkeys::verify().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['tamper' => true]), $c, null));
    // And a key that is not the stored one (same credential id, other phone).
    $other = new BioFakePhone();
    $other->credId = $phone->credId;
    bioRefused(OA::post($this, 'passkey/unlock', $other->get(bioChallenge($this, $c)), $c, null));
});

it("refuses another device's credential, even the same member's", function () {
    // DEFECT: a credential looked up by its id alone, so the passkey of one
    // enrolled phone (synced to the owner's iPad by iCloud, say) opens another
    // device's session. MUTATION: look the row up by credential_id without
    // device_id in Passkeys::verify().
    [$a, $csrfA] = bioEnrol($this);
    [$b, $csrfB] = bioEnrol($this);
    $phoneA = new BioFakePhone();
    $phoneB = new BioFakePhone();
    bioRegister($this, $a, $csrfA, $phoneA);
    bioRegister($this, $b, $csrfB, $phoneB);
    $a = bioLock($this, $a, $csrfA);

    bioRefused(OA::post($this, 'passkey/unlock', $phoneB->get(bioChallenge($this, $a)), $a, null));
    expect(DB::table('owner_app_logins')->where('kind', 'passkey')->value('reason'))->toBe('no_cred');

    // A device with no credential gets no challenge at all, and no cookie gets the same.
    [$c] = bioEnrol($this);
    bioRefused(OA::post($this, 'passkey/start', [], $c, null));
    $this->unencryptedCookies = [];   // the test client keeps cookies between calls; a stranger has none
    bioRefused(OA::post($this, 'passkey/start', [], [], null));
    bioRefused(OA::post($this, 'passkey/unlock', $phoneA->get('x'), [], null));
});

it('refuses a signature counter that goes backwards, and lets a counter that stays at zero through', function () {
    // DEFECT: a cloned authenticator replaying an older count is accepted.
    // MUTATION: drop the `$count <= $stored` check in Passkeys::verify().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);

    // iCloud / Google passkeys always say 0: that must keep working.
    $r = OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c)), $c, null)->assertOk();
    $c = bioLock($this, OA::cookies($r, $c), $r->json('csrf'));

    $r = OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['count' => 7]), $c, null)->assertOk();
    $c = bioLock($this, OA::cookies($r, $c), $r->json('csrf'));
    expect((int) DB::table('owner_app_passkeys')->value('sign_count'))->toBe(7);

    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['count' => 7]), $c, null));
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['count' => 3]), $c, null));
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['count' => 0]), $c, null));
    OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['count' => 8]), $c, null)->assertOk();
});

it('lets only an unlocked session switch Face ID / fingerprint on', function () {
    // DEFECT: anybody holding the phone (or a script on the shop, which is
    // same-origin) registers their own finger without the PIN. MUTATION: move
    // passkey/new or passkey/save out of the OwnerAppSession group.
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();

    // No CSRF value (a script on the shop, a fresh launch): refused.
    OA::post($this, 'passkey/new', [], $c, null)->assertStatus(401);
    OA::post($this, 'passkey/save', $phone->create('x'), $c, null)->assertStatus(401);
    // Locked: refused.
    $locked = bioLock($this, $c, $csrf);
    OA::post($this, 'passkey/new', [], $locked, $csrf)->assertStatus(401);
    OA::post($this, 'passkey/save', $phone->create('x'), $locked, $csrf)->assertStatus(401);
    // No device at all.
    $this->unencryptedCookies = [];
    OA::post($this, 'passkey/new', [], [], null)->assertStatus(401);
    expect(DB::table('owner_app_passkeys')->count())->toBe(0);
});

it('refuses a registration that is not right, and keeps nothing', function (array $o, ?string $swap) {
    // DEFECT: a credential stored from an answer without user verification,
    // for another site, or over an unlock challenge. MUTATION: drop the
    // authProblem() call from Passkeys::register().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    $ch = OA::post($this, 'passkey/new', [], $c, $csrf)->json('publicKey.challenge');
    $body = $phone->create($swap === 'challenge' ? Passkeys::b64u(random_bytes(32)) : $ch, $o);
    if ($swap === 'type') {
        $body['clientDataJSON'] = Passkeys::b64u(BioFakePhone::client('webauthn.get', $ch));
    }
    if ($swap === 'junk') {
        $body['attestationObject'] = Passkeys::b64u("\xa1\x63fmt\x9f");
    }

    $r = OA::post($this, 'passkey/save', $body, $c, $csrf)->assertStatus(422);
    expect($r->json('code'))->toBe('passkey')->and(DB::table('owner_app_passkeys')->count())->toBe(0);
    // And it cannot be retried with the same challenge.
    OA::post($this, 'passkey/save', $phone->create($ch), $c, $csrf)->assertStatus(422);
})->with([
    'no UV' => [['flags' => 0x41], null],
    'no attested data' => [['flags' => 0x05], null],
    'other origin' => [['origin' => 'https://evil.example'], null],
    'other rp id' => [['rp' => 'evil.example'], null],
    'wrong challenge' => [[], 'challenge'],
    'get, not create' => [[], 'type'],
    'indefinite CBOR' => [[], 'junk'],
]);

it('refuses Face ID / fingerprint while the member is locked out or switched off', function (array $member) {
    // DEFECT: a lock a Full Admin set (or "access off") bypassed by a finger.
    // MUTATION: drop the isLocked() check from OwnerAppAuth::passkeyUnlock().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);
    $ch = bioChallenge($this, $c);

    // A pause is set from the test's own clock (a dataset value is made when the file loads).
    DB::table('owner_app_members')->update(array_map(fn ($v) => $v === 'in 10 min' ? now()->addMinutes(10) : $v, $member));
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get($ch), $c, null));
})->with([
    'admin-only lock' => [['lock_level' => 4]],
    '15-minute pause' => [['lock_level' => 1, 'locked_until' => 'in 10 min']],
    'access off' => [['enabled' => false]],
]);

it('removes the credential when the phone logs out, is revoked, or turns it off', function (string $how) {
    // DEFECT: a phone signed out (or revoked by the owner) whose credential
    // still sits on the server for the next enrolment of that browser.
    // MUTATION: drop Passkeys::forget() from OwnerAppAuth::revoke().
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    expect(DB::table('owner_app_passkeys')->count())->toBe(1);

    match ($how) {
        'forget' => OA::post($this, 'forget', [], $c, $csrf)->assertOk(),
        'off' => OA::post($this, 'passkey/off', [], $c, $csrf)->assertOk(),
        'admin' => OwnerAppAuth::revoke((int) DB::table('owner_app_devices')->value('id'), 'revoked_by_admin'),
    };

    expect(DB::table('owner_app_passkeys')->count())->toBe(0);
    if ($how === 'off') {
        $c = bioLock($this, $c, $csrf);
        bioRefused(OA::post($this, 'passkey/start', [], $c, null));
    }
})->with(['forget', 'off', 'admin']);

it('keeps ONE credential per device: switching it on again replaces the old one', function () {
    // DEFECT: every re-enable adds a row, and an old (perhaps lost) key keeps working.
    [$c, $csrf] = bioEnrol($this);
    $old = new BioFakePhone();
    bioRegister($this, $c, $csrf, $old);
    bioRegister($this, $c, $csrf, new BioFakePhone());
    expect(DB::table('owner_app_passkeys')->count())->toBe(1);

    $c = bioLock($this, $c, $csrf);
    bioRefused(OA::post($this, 'passkey/unlock', $old->get(bioChallenge($this, $c)), $c, null));
});

it('leaves the PIN exactly as it was, with Face ID on or off', function () {
    // DEFECT: the shared session code (opened()) changes what a PIN answers,
    // or a passkey failure eats PIN attempts. MUTATION: count passkey
    // refusals against failed_count.
    [$c, $csrf] = bioEnrol($this);
    $before = OA::post($this, 'unlock', ['pin' => '482613'], bioLock($this, $c, $csrf), null)->assertOk();
    $c = OA::cookies($before, $c);

    $phone = new BioFakePhone();
    bioRegister($this, $c, $before->json('csrf'), $phone);
    $c = bioLock($this, $c, $before->json('csrf'));
    bioRefused(OA::post($this, 'passkey/unlock', $phone->get(bioChallenge($this, $c), ['tamper' => true]), $c, null));
    expect((int) DB::table('owner_app_members')->value('failed_count'))->toBe(0)
        ->and((int) DB::table('owner_app_devices')->value('failed_count'))->toBe(0);

    OA::post($this, 'unlock', ['pin' => '000000'], $c, null)->assertStatus(422)->assertJson(['code' => 'bad_pin', 'left' => 4]);
    $after = OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertOk();
    expect(array_keys($after->json()))->toBe(array_keys($before->json()))
        ->and(DB::table('owner_app_logins')->where('kind', 'unlock')->where('success', true)->count())->toBe(2);
});

it('never writes a challenge, a key or a signature into the login log', function () {
    // DEFECT: credential material in a table every manager can read.
    [$c, $csrf] = bioEnrol($this);
    $phone = new BioFakePhone();
    bioRegister($this, $c, $csrf, $phone);
    $c = bioLock($this, $c, $csrf);
    $answer = $phone->get(bioChallenge($this, $c), ['tamper' => true]);
    OA::post($this, 'passkey/unlock', $answer, $c, null);

    $log = json_encode(DB::table('owner_app_logins')->get());
    foreach ($answer as $v) {
        expect($log)->not->toContain($v);
    }
});

it('parses only the CBOR it needs and refuses the rest', function (string $hex) {
    // DEFECT: a hostile attestationObject (tags, floats, indefinite lengths,
    // a length past the end) crashing or looping the reader.
    expect(fn () => Passkeys::cbor((string) hex2bin($hex), 0))->toThrow(UnexpectedValueException::class);
})->with(['tag' => 'c240', 'float' => 'f93c00', 'indefinite' => '9f', 'past the end' => '5a0000ffff00', 'empty' => '']);
