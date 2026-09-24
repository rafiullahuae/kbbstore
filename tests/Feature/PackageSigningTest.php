<?php

/*
 * THE DEFECT, AS IT STOOD ON THE SHOP.
 *
 * `BuildPackage.php:199` wrote `'signature' => ''` unconditionally, so no
 * package this project has ever produced was signed -- and nothing said so, on
 * the build machine or on the screen, which is why it went unnoticed for the
 * life of the project. The scheme waiting behind that empty string was a
 * SYMMETRIC HMAC against KBB_UPDATE_SECRET: to verify a package a shop needs
 * the same secret that signs one, sitting in a .env file its owner can read,
 * and with it anyone can forge a package this updater accepts as genuine.
 *
 * So, concretely, on the shop: anyone who reached the Core Updates screen with
 * an admin session could install arbitrary PHP, and there was no mechanism --
 * not a broken one, none -- that could have told a package built on the owner's
 * machine from one built on anybody else's.
 *
 * WHAT THESE TESTS DO AND DO NOT CLAIM. A signature proves ORIGIN, never
 * CORRECTNESS. Every package behind the 24 September 2026 outage would have
 * been signed by this key and applied; checkMigrationsAreDeclared() and
 * ClassDependencyScan are what catch that, and they have their own tests. These
 * pin one thing: a package that did not come from the build machine cannot be
 * applied at all.
 *
 * MUTATIONS, all run:
 *
 *   1. Restore `'signature' => ''` in BuildPackage in place of $this->signature(...)
 *      -> `it signs a package when the build machine holds a key` goes red.
 *   2. Make UpdatePackage::checkSignature() `return true` for an ed25519
 *      signature it cannot verify -> `it refuses a forged package` and
 *      `it refuses a package signed by a key this shop does not trust` go red.
 *   3. Drop the second loop of checkChecksums() (the "package contains a file
 *      the manifest does not declare" half) -> `it refuses an undeclared file
 *      smuggled into a signed package` goes red. That is the whole reason a
 *      signature over the manifest binds the file bytes.
 *   4. Make SigningMode::current() ignore the hatch file -> `the escape hatch
 *      lets an unsigned package in` goes red.
 *   5. Make canonical() a shallow ksort -> `the builder and the server
 *      canonicalise identically` stays green but `it verifies a signature over
 *      a manifest whose keys arrive in another order` goes red.
 *   6. Default 'update_signing' to 'required' in config/kbb.php -> `applying
 *      this changes nothing about the packages that install today` goes red,
 *      which is rule 1's instrument for this patch.
 */

use App\Services\Update\PackageSignature;
use App\Services\Update\SigningKeyFile;
use App\Services\Update\SigningMode;
use App\Services\Update\UpdateGuard;
use App\Services\Update\UpdatePackage;

/**
 * A directory that genuinely is not inside the checkout.
 *
 * tests/bootstrap.php points sys_get_temp_dir() INSIDE the application, so each
 * lane's fixtures cannot delete another lane's. SigningKeyFile refuses to read a
 * key from inside the repository -- correctly, and that refusal has its own test
 * below -- so the cases that need a WORKING key need somewhere that is really
 * outside, the way a build machine's ~/.config is.
 */
function keyDirOutsideTheApp(): string
{
    $base = (is_dir('/tmp') && is_writable('/tmp')) ? '/tmp' : dirname(base_path());

    if (str_starts_with((string) realpath($base), rtrim(base_path(), '/').'/')) {
        $base = dirname(base_path());
    }

    $dir = rtrim($base, '/').'/kbb-signing-key-test-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0700, true);

    return $dir;
}

/** A throwaway Ed25519 pair. Nothing here ever touches a real signing key. */
function signingPair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
}

/**
 * Build a package the way the server will read it: the payload on disk, the
 * manifest describing it, and whatever the caller wants done to either.
 *
 * $mutateManifest runs AFTER signing, so a test can alter a signed manifest and
 * watch the signature stop matching -- which is the whole point.
 */
function signedPackage(
    array $files = ['app/Support/Example.php' => "<?php // an ordinary class\n"],
    ?string $secretKey = null,
    ?callable $mutateManifest = null,
    array $extraZipEntries = [],
): UpdatePackage {
    $dir = sys_get_temp_dir().'/kbb-sig-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);

    $zipPath = $dir.'/package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);

    $map = [];
    foreach ($files as $path => $body) {
        $zip->addFromString('files/'.$path, $body);
        $map[$path] = hash('sha256', $body);
    }

    foreach ($extraZipEntries as $path => $body) {
        $zip->addFromString('files/'.$path, $body);
    }

    $manifest = [
        'name' => 'KBB Storefront',
        'version' => '9.99.999',
        'requires_php' => '8.2',
        'notes' => '',
        'files' => $map,
        'migrations' => false,
    ];

    $manifest['signature'] = $secretKey === null ? '' : PackageSignature::sign($manifest, $secretKey);

    if ($mutateManifest !== null) {
        $manifest = $mutateManifest($manifest);
    }

    $zip->addFromString('update.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();

    return new UpdatePackage($zipPath, $dir.'/scratch', new UpdateGuard());
}

function trustKeys(string ...$rawPublicKeys): void
{
    config(['kbb.update_public_keys' => array_map('base64_encode', $rawPublicKeys)]);
}

beforeEach(function () {
    config(['kbb.update_secret' => '', 'kbb.update_signing' => 'permissive', 'kbb.update_public_keys' => []]);
    @unlink(SigningMode::hatchPath());
});

afterEach(function () {
    @unlink(SigningMode::hatchPath());
});

/* ------------------------------------------------------------------ *
 * RULE 1. This is the test that says the patch is inert.
 * ------------------------------------------------------------------ */

it('applying this changes nothing about the packages that install today', function () {
    /* Every package this project has built carries "signature": "". The shipped
     * default must accept one, or the day this lands is the day the owner can no
     * longer install anything -- with the fix inside a package he cannot apply,
     * which is precisely how 24 September 2026 went. */
    $package = signedPackage();

    expect($package->verify())->toBeTrue(implode(' ', $package->errors))
        ->and(SigningMode::current())->toBe(SigningMode::PERMISSIVE);
});

it('ships permissive as the built-in default, with no configuration at all', function () {
    // Read from the config file rather than from the test's own override: a
    // later edit that flips the shipped default to `required` would strand a
    // shop on the release that carried it.
    $shipped = require base_path('config/kbb.php');

    expect($shipped['update_signing'])->toBe('permissive')
        ->and($shipped['update_public_keys'])->toBe([]);
})->skip(fn () => ! function_exists('env'), 'config/kbb.php calls env()');

/* ------------------------------------------------------------------ *
 * A FORGED PACKAGE, AND A TAMPERED ONE. Both must be REFUSED.
 * ------------------------------------------------------------------ */

it('refuses a forged package', function () {
    // The attack: someone builds their own package, signs it with their own
    // key, and uploads it through a stolen admin session. Before this patch it
    // installed. It must now be refused whatever the mode.
    [$mine, $minePublic] = signingPair();
    [$theirs] = signingPair();

    trustKeys($minePublic);

    $package = signedPackage(secretKey: $theirs);

    expect($package->verify())->toBeFalse('A package signed by a key this shop does not trust was accepted.')
        ->and(implode(' ', $package->errors))->toContain('not produced by the build machine');

    expect($mine)->not->toBe($theirs);
});

it('refuses a tampered file inside a correctly signed package', function () {
    /* The signature is valid and the manifest is untouched; only the FILE has
     * been changed after signing. This is the link that makes a signature over
     * a manifest bind the bytes: `files` carries the SHA-256 and
     * checkChecksums() compares it with what is on disk. */
    [$secret, $public] = signingPair();
    trustKeys($public);

    $good = "<?php // an ordinary class\n";
    $dir = sys_get_temp_dir().'/kbb-sig-'.bin2hex(random_bytes(6));
    @mkdir($dir, 0775, true);
    $zipPath = $dir.'/package.zip';

    $manifest = [
        'name' => 'KBB Storefront',
        'version' => '9.99.999',
        'requires_php' => '8.2',
        'notes' => '',
        'files' => ['app/Support/Example.php' => hash('sha256', $good)],
        'migrations' => false,
    ];
    $manifest['signature'] = PackageSignature::sign($manifest, $secret);

    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('files/app/Support/Example.php', "<?php @unlink('/etc/passwd'); // swapped after signing\n");
    $zip->addFromString('update.json', (string) json_encode($manifest));
    $zip->close();

    $package = new UpdatePackage($zipPath, $dir.'/scratch', new UpdateGuard());

    expect($package->verify())->toBeFalse('A file swapped after signing was accepted.')
        ->and(implode(' ', $package->errors))->toContain('Checksum mismatch');
});

it('refuses a tampered file whose hash was updated in the manifest to match', function () {
    // The thorough version of the same attack: change the file AND its
    // checksum. The checksums now agree with each other and the signature no
    // longer covers them.
    [$secret, $public] = signingPair();
    trustKeys($public);

    $evil = "<?php // swapped, and the manifest updated to suit\n";

    $package = signedPackage(
        secretKey: $secret,
        mutateManifest: fn (array $m): array => [...$m, 'files' => ['app/Support/Example.php' => hash('sha256', $evil)]],
        extraZipEntries: [],
    );

    expect($package->verify())->toBeFalse('A manifest edited after signing was accepted.')
        ->and(implode(' ', $package->errors))->toContain('Signature does not match');
});

it('refuses an undeclared file smuggled into a signed package', function () {
    /* THE ATTACK A SIGNATURE OVER A MANIFEST DOES NOT STOP BY ITSELF. Take a
     * genuinely signed package and add one file the manifest never mentions.
     * The signature still verifies -- nothing in the signed payload changed --
     * so the ONLY thing standing between the shop and that file is
     * checkChecksums() refusing a file the manifest does not declare. Remove
     * that loop and this goes red while every other signing test stays green,
     * which is what makes it worth its own case. */
    [$secret, $public] = signingPair();
    trustKeys($public);

    $package = signedPackage(
        secretKey: $secret,
        extraZipEntries: ['app/Support/Smuggled.php' => "<?php // never declared\n"],
    );

    expect($package->verify())->toBeFalse('A file the manifest never declared rode along inside a signed package.')
        ->and(implode(' ', $package->errors))->toContain('which the manifest does not declare');
});

it('refuses a package whose migrations flag was flipped after signing', function () {
    /* `migrations` is the only thing that decides whether migrations run at
     * all. It is inside the signed payload for that reason: a package whose
     * flag can be edited without breaking the signature is a package whose
     * schema changes can be silently switched off in transit. */
    [$secret, $public] = signingPair();
    trustKeys($public);

    $package = signedPackage(
        files: ['database/migrations/2026_01_01_000000_example.php' => "<?php // a migration\n"],
        secretKey: $secret,
        mutateManifest: fn (array $m): array => [...$m, 'migrations' => true],
    );

    expect($package->verify())->toBeFalse('The migrations flag was edited after signing and the package was accepted.')
        ->and(implode(' ', $package->errors))->toContain('Signature does not match');
});

it('refuses a signed package when the shop holds no public key', function () {
    // Trusting nothing must mean verifying nothing, never waving it through.
    // A shop part-way through the rollout has the new verifier and not yet the
    // key, and that shop must refuse a signature rather than pretend to check it.
    [$secret] = signingPair();
    config(['kbb.update_public_keys' => []]);

    $package = signedPackage(secretKey: $secret);

    expect($package->verify())->toBeFalse()
        ->and(implode(' ', $package->errors))->toContain('holds no public key');
});

it('refuses a signature in a form it cannot check', function () {
    // Not ignored. A field that reads as proof to a human and means nothing to
    // the code is the theatre this whole patch exists to remove.
    $package = signedPackage(mutateManifest: fn (array $m): array => [...$m, 'signature' => str_repeat('a', 64)]);

    expect($package->verify())->toBeFalse()
        ->and(implode(' ', $package->errors))->toContain('does not recognise');
});

/* ------------------------------------------------------------------ *
 * THE HAPPY PATH, AND THE MODES.
 * ------------------------------------------------------------------ */

it('accepts a package signed by a key it trusts', function () {
    [$secret, $public] = signingPair();
    trustKeys($public);

    $package = signedPackage(secretKey: $secret);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('accepts a package signed by any one of several trusted keys, so a key can be rotated', function () {
    [, $oldPublic] = signingPair();
    [$newSecret, $newPublic] = signingPair();

    trustKeys($oldPublic, $newPublic);

    $package = signedPackage(secretKey: $newSecret);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});

it('refuses an unsigned package once the shop requires signatures', function () {
    [, $public] = signingPair();
    trustKeys($public);
    config(['kbb.update_signing' => 'required']);

    $package = signedPackage();

    expect($package->verify())->toBeFalse('An unsigned package installed on a shop set to `required`.')
        ->and(implode(' ', $package->errors))
        ->toContain('signed packages only')
        ->toContain(SigningMode::HATCH_FILE);
});

it('resolves an unrecognised mode to permissive rather than to required', function () {
    // A typo in configuration must not be able to lock a shop out of the
    // updater that would fix the typo.
    config(['kbb.update_signing' => 'requried']);

    expect(SigningMode::current())->toBe(SigningMode::PERMISSIVE)
        ->and(signedPackage()->verify())->toBeTrue();
});

/* ------------------------------------------------------------------ *
 * THE ESCAPE HATCH.
 * ------------------------------------------------------------------ */

it('the escape hatch lets an unsigned package in', function () {
    /* The state this exists for: the shop requires signatures and the key that
     * would sign the next package is lost or wrong. Without this the shop
     * refuses everything, including the package that would repair it -- which
     * is the exact shape that bricked the updater on 24 September 2026. */
    config(['kbb.update_signing' => 'required']);

    expect(signedPackage()->verify())->toBeFalse();

    touch(SigningMode::hatchPath());

    $package = signedPackage();

    expect($package->verify())->toBeTrue(implode(' ', $package->errors))
        ->and(SigningMode::current())->toBe(SigningMode::PERMISSIVE)
        ->and(SigningMode::describe()['emergency'])->toBeTrue();
});

it('does not let the escape hatch accept a signature that fails', function () {
    // The hatch relaxes "must be signed". It must never relax "if it is signed,
    // it must be genuine" -- that would make it a way to install a forgery.
    [, $public] = signingPair();
    [$theirs] = signingPair();
    trustKeys($public);
    touch(SigningMode::hatchPath());

    $package = signedPackage(secretKey: $theirs);

    expect($package->verify())->toBeFalse('The escape hatch accepted a forged package.');
});

it('keeps the escape hatch where no package can reach it', function () {
    /* storage/ is on UpdateGuard::FORBIDDEN_PREFIXES, so an update cannot
     * create the hatch to weaken a shop, nor delete it to strand one. Same
     * reasoning as public/kbb-recover.php. */
    $guard = new UpdateGuard();

    expect(SigningMode::hatchPath())->toContain('storage/app/')
        ->and($guard->check(['storage/app/'.SigningMode::HATCH_FILE])['ok'])->toBeFalse();
});

/* ------------------------------------------------------------------ *
 * THE CHAIN, AND THE TWO SIDES AGREEING.
 * ------------------------------------------------------------------ */

it('keeps checkChecksums in the chain, because that is what binds the file bytes', function () {
    /* The signature covers the manifest. The manifest covers each file by
     * SHA-256. checkChecksums() is the only thing that compares those hashes
     * with what is on disk -- drop it from verify() and the signature stops
     * meaning anything about the payload while every crypto test still passes. */
    $source = file_get_contents(app_path('Services/Update/UpdatePackage.php'));

    expect($source)->toContain('$this->checkSignature()')
        ->and($source)->toContain('$this->checkChecksums()')
        ->and(strpos($source, '&& $this->checkChecksums()'))
        ->toBeGreaterThan((int) strpos($source, '&& $this->checkSignature()'));
});

it('makes the builder and the server canonicalise through one function', function () {
    // The commonest way a signing scheme fails is the two sides disagreeing
    // about the bytes, and the symptom is every shop refusing every package.
    // There is one canonical() and neither side has a copy of it.
    $builder = file_get_contents(app_path('Console/Commands/BuildPackage.php'));
    $server = file_get_contents(app_path('Services/Update/UpdatePackage.php'));

    expect($builder)->toContain('PackageSignature::sign(')
        ->and($server)->toContain('PackageSignature::verify(')
        ->and($builder)->not->toContain('sodium_crypto_sign_detached(')
        ->and($server)->not->toContain('sodium_crypto_sign_verify_detached(');
});

it('verifies a signature over a manifest whose keys arrive in another order', function () {
    // json_decode preserves document order, so a manifest re-serialised by any
    // tool between the builder and the shop would change the signed bytes under
    // a shallow sort. canonical() sorts recursively for that reason.
    [$secret, $public] = signingPair();
    trustKeys($public);

    $manifest = [
        'files' => ['b.php' => 'x', 'a.php' => 'y'],
        'version' => '1.0.0',
        'name' => 'KBB Storefront',
        'migrations' => false,
        'notes' => '',
        'requires_php' => '8.2',
    ];
    $signature = PackageSignature::sign($manifest, $secret);

    $reordered = [
        'name' => 'KBB Storefront',
        'notes' => '',
        'requires_php' => '8.2',
        'version' => '1.0.0',
        'migrations' => false,
        'files' => ['a.php' => 'y', 'b.php' => 'x'],
        'signature' => $signature,
    ];

    expect(PackageSignature::verify($reordered, $signature, [$public]))->toBeTrue();
});

it('does not verify a manifest that differs by one character', function () {
    [$secret, $public] = signingPair();

    $manifest = ['name' => 'KBB Storefront', 'version' => '1.0.0', 'files' => []];
    $signature = PackageSignature::sign($manifest, $secret);

    expect(PackageSignature::verify($manifest, $signature, [$public]))->toBeTrue()
        ->and(PackageSignature::verify([...$manifest, 'version' => '1.0.1'], $signature, [$public]))->toBeFalse();
});

/* ------------------------------------------------------------------ *
 * THE BUILDER.
 * ------------------------------------------------------------------ */

it('signs a package when the build machine holds a key', function () {
    /* THE DEFECT ITSELF: BuildPackage wrote 'signature' => '' unconditionally,
     * so no package was ever signed. Restore that line and this goes red. */
    $keyDir = keyDirOutsideTheApp();
    $keyPath = $keyDir.'/package-signing.key';

    [$secret, $public] = signingPair();
    file_put_contents($keyPath, base64_encode($secret));
    chmod($keyPath, 0600);

    $out = 'storage/app/packages-test-'.bin2hex(random_bytes(4));

    $exit = Artisan::call('kbb:package', [
        'version' => '9.99.999',
        '--file' => ['config/kbb.php'],
        '--out' => $out,
        '--key' => $keyPath,
    ]);

    expect($exit)->toBe(0, Artisan::output());

    $zip = new ZipArchive();
    $zip->open(base_path($out).'/kbb-update-9.99.999.zip');
    $manifest = json_decode((string) $zip->getFromName('update.json'), true);
    $zip->close();

    expect($manifest['signature'])->toStartWith(PackageSignature::PREFIX)
        ->and(PackageSignature::verify($manifest, $manifest['signature'], [$public]))->toBeTrue();

    // And the shop accepts it, which is the end-to-end claim.
    trustKeys($public);
    $package = new UpdatePackage(
        base_path($out).'/kbb-update-9.99.999.zip',
        sys_get_temp_dir().'/kbb-sig-'.bin2hex(random_bytes(6)),
        new UpdateGuard(),
    );
    expect($package->verify())->toBeTrue(implode(' ', $package->errors));

    array_map('unlink', glob(base_path($out).'/*') ?: []);
    @rmdir(base_path($out));
    @unlink($keyPath);
    @rmdir($keyDir);
});

it('builds unsigned, loudly, when there is no key', function () {
    /* A lane with no key must still be able to build -- permissive accepts the
     * result -- but the silence is what let this go unnoticed for the life of
     * the project, so it says so every time. */
    $out = 'storage/app/packages-test-'.bin2hex(random_bytes(4));

    $exit = Artisan::call('kbb:package', [
        'version' => '9.99.998',
        '--file' => ['config/kbb.php'],
        '--out' => $out,
        '--key' => '/nonexistent/kbb-signing-key-that-is-not-there',
        '--unsigned' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('UNSIGNED');

    $zip = new ZipArchive();
    $zip->open(base_path($out).'/kbb-update-9.99.998.zip');
    $manifest = json_decode((string) $zip->getFromName('update.json'), true);
    $zip->close();

    expect($manifest['signature'])->toBe('');

    array_map('unlink', glob(base_path($out).'/*') ?: []);
    @rmdir(base_path($out));
});

/* ------------------------------------------------------------------ *
 * THE PRIVATE KEY NEVER TRAVELS.
 * ------------------------------------------------------------------ */

it('refuses to sign with a key that lives inside the repository', function () {
    // One `git add -A` away from being committed is not far enough, and this
    // project has already shipped a token into its own history once.
    $inside = base_path('storage/app/package-signing.key');
    [$secret] = signingPair();
    file_put_contents($inside, base64_encode($secret));
    chmod($inside, 0600);

    expect(fn () => SigningKeyFile::read($inside))
        ->toThrow(RuntimeException::class, 'inside the application directory');

    @unlink($inside);
});

it('refuses to sign with a key anyone else on the host can read', function () {
    // On the shared hosting this product targets, "other" is other customers.
    $dir = keyDirOutsideTheApp();
    $path = $dir.'/loose.key';
    [$secret] = signingPair();
    file_put_contents($path, base64_encode($secret));
    chmod($path, 0644);

    expect(fn () => SigningKeyFile::read($path))->toThrow(RuntimeException::class, 'chmod 600');

    @unlink($path);
    @rmdir($dir);
});

it('cannot ship a key file to a shop even if one were committed by accident', function () {
    // Two independent rules, neither of which is the builder's never-ship list:
    // `.key` is not an allowed extension and a home directory is not an allowed
    // prefix.
    $guard = new UpdateGuard();

    expect($guard->check(['config/package-signing.key'])['ok'])->toBeFalse()
        ->and($guard->check(['.config/kbb/package-signing.key'])['ok'])->toBeFalse();
});

it('holds no private key in this repository', function () {
    // The whole scheme rests on this. A base64 Ed25519 secret key is 88
    // characters; nothing tracked here may look like one.
    $hits = [];

    foreach (['app', 'config', 'database', 'resources', 'routes', 'tests', 'docs'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getSize() > 2_000_000) {
                continue;
            }

            if (preg_match('/[A-Za-z0-9+\/]{86}==/', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }
    }

    expect($hits)->toBe([]);
});

/* ------------------------------------------------------------------ *
 * THE SCREEN.
 * ------------------------------------------------------------------ */

it('tells the shop which mode it is in, in words', function () {
    // Store -> Core Updates, in the header line under the page title. The owner
    // must never have to infer his own update policy from a .env file he may
    // not be able to open.
    expect(SigningMode::describe()['label'])->toBe('unsigned packages accepted');

    [, $public] = signingPair();
    trustKeys($public);
    expect(SigningMode::describe()['label'])->toContain('unsigned packages still accepted')
        ->and(SigningMode::describe()['fingerprints'])->toBe([PackageSignature::fingerprint($public)]);

    config(['kbb.update_signing' => 'required']);
    expect(SigningMode::describe()['label'])->toBe('signed packages only');

    touch(SigningMode::hatchPath());
    expect(SigningMode::describe()['label'])->toContain('EMERGENCY')
        ->and(SigningMode::describe()['label'])->toContain(SigningMode::HATCH_FILE);
});

/* ------------------------------------------------------------------ *
 * THE HMAC BEING RETIRED.
 * ------------------------------------------------------------------ */

it('leaves a shop that set the shared secret behaving exactly as it did', function () {
    /* Rule 1 for the one install shape that is not this one. The legacy branch
     * verifies with the ORIGINAL shallow-ksort canonicalisation, not with
     * PackageSignature::canonical(), because a retiring scheme that checks
     * something slightly different from what it checked yesterday is not a
     * compatibility path, it is a second outage. */
    config(['kbb.update_secret' => 'a-shared-secret']);

    $legacySignature = function (array $manifest): string {
        $payload = $manifest;
        unset($payload['signature']);
        ksort($payload);

        return hash_hmac('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES), 'a-shared-secret');
    };

    $good = signedPackage(mutateManifest: fn (array $m): array => [...$m, 'signature' => $legacySignature($m)]);
    expect($good->verify())->toBeTrue(implode(' ', $good->errors));

    $bad = signedPackage(mutateManifest: fn (array $m): array => [...$m, 'signature' => str_repeat('b', 64)]);
    expect($bad->verify())->toBeFalse()
        ->and(implode(' ', $bad->errors))->toContain('not produced for this site');

    $unsigned = signedPackage();
    expect($unsigned->verify())->toBeFalse()
        ->and(implode(' ', $unsigned->errors))->toContain('only accepts signed updates');
});

it('lets an ed25519 signature win over the shared secret, so such a shop can migrate', function () {
    [$secret, $public] = signingPair();
    trustKeys($public);
    config(['kbb.update_secret' => 'a-shared-secret']);

    $package = signedPackage(secretKey: $secret);

    expect($package->verify())->toBeTrue(implode(' ', $package->errors));
});
