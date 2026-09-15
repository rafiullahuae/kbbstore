<?php

/**
 * The three open advisories, pinned as behaviour rather than as a note — Lane AC.
 *
 * CLAUDE.md has recorded for a long time that "Laravel 11 carries three open
 * advisories (CRLF injection in the email rule, signed-URL path confusion)" and
 * that fixing them means a 12.x upgrade. `composer audit` still reports all
 * three against laravel/framework v11.56.1. docs/DEPENDENCY-ADVISORIES.md
 * records, per advisory, what this application actually does with the
 * vulnerable code path.
 *
 * The short version is that neither advisory is exploitable here, and this file
 * is the reason that stays true. A verdict written in a document decays; a
 * verdict written as a test fails the moment the thing it depended on moves.
 *
 * Each test below pins one of the load-bearing facts:
 *
 *   1. A CR or LF can never reach a mail header — whichever layer stops it.
 *      Stated as the property, not as "the framework rejects it", so the test
 *      survives the 12.x upgrade instead of having to be deleted by it.
 *   2. symfony/mime is the layer that actually stops it today. That is a
 *      transitive dependency nobody chose deliberately, so it gets its own
 *      assertion: a downgrade there turns the CRLF advisory live.
 *   3. Nothing in this application mints or verifies a Laravel signed URL, so
 *      the path-confusion advisory has nothing to confuse. That is an
 *      architectural fact a future lane could undo in one line, so it is
 *      asserted over the source tree.
 *
 * @see docs/DEPENDENCY-ADVISORIES.md
 * @see docs/LARAVEL-UPGRADE.md
 */

use App\Rules\StorefrontEmail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mime\Address;

/**
 * The addresses the INSTALLED framework demonstrably accepts under the default
 * `email` rule, carrying a raw CR/LF.
 *
 * Not hand-invented: each was found by running every shape past
 * Egulias\EmailValidator\Validation\RFCValidation, which is what `email`
 * delegates to, and keeping the ones that came back valid. `RFCValidation`
 * permits folding whitespace and quoted local parts, and a bare CR lives
 * legally inside both — which is the whole of GHSA-5vg9-5847-vvmq.
 *
 * The obvious payload — "victim@example.com\r\nBcc: attacker@evil.test" — is
 * NOT here, because the framework already rejects it. Testing only that shape
 * is how this advisory gets mistakenly written off as unreachable.
 */
function crlfAddressesTheFrameworkAccepts(): array
{
    return [
        'quoted local part' => "\"us\r\ner\"@example.com",
        'folding whitespace before @' => "user\r\n @example.com",
    ];
}

it('cannot put a carriage return into a mail header, whichever layer stops it', function () {
    /*
     * The security property, stated once and without naming a defender.
     *
     * Today the framework's `email` rule ACCEPTS both of these (that is the
     * advisory) and symfony/mime refuses them at the transport. After a 12.x
     * upgrade the rule is expected to reject them and the transport to refuse
     * them anyway. Either way this test passes, and it fails only if BOTH
     * layers stop refusing — which is the only state that actually hurts.
     */
    foreach (crlfAddressesTheFrameworkAccepts() as $why => $address) {
        $validatorRejects = Validator::make(['email' => $address], ['email' => 'email'])->fails();

        $transportRejects = false;

        try {
            new Address($address);
        } catch (\Throwable $e) {
            $transportRejects = true;
        }

        expect($validatorRejects || $transportRejects)->toBeTrue(
            "Neither the validator nor the mail transport refused {$why}: "
            . json_encode($address) . '. CRLF can now reach a message header; '
            . 'read docs/DEPENDENCY-ADVISORIES.md before shipping anything.'
        );
    }
});

it('is symfony/mime that closes the CRLF advisory today', function () {
    /*
     * Named explicitly, because this is load-bearing and accidental.
     *
     * Symfony\Component\Mime\Address::__construct rejects /[\x00-\x1F\x7F]/ in
     * the address before any validator opinion is consulted, and every Laravel
     * mail path — Mail::to(), Mail::raw(), a notification's via('mail') —
     * constructs one. symfony/mime is a transitive dependency of
     * laravel/framework; nothing in composer.json asks for it by name and
     * nothing pins its major. If it were ever downgraded below that guard, the
     * CRLF advisory would go live in this application with no other change and
     * no audit line to announce it.
     */
    foreach (crlfAddressesTheFrameworkAccepts() as $address) {
        expect(fn () => new Address($address))
            ->toThrow(\Symfony\Component\Mime\Exception\InvalidArgumentException::class);
    }

    // And through the real mailer, not just the value object, so this holds for
    // the way the application actually sends rather than for a constructor.
    foreach (crlfAddressesTheFrameworkAccepts() as $address) {
        expect(function () use ($address) {
            Mail::mailer('array')->raw('body', fn ($m) => $m->to($address)->subject('s'));
        })->toThrow(\Symfony\Component\Mime\Exception\InvalidArgumentException::class);
    }
});

it('mints and verifies no Laravel signed URL anywhere', function () {
    /*
     * GHSA-crmm-hgp2-wgrp is path confusion between the path a temporary signed
     * URL was signed over and the path the verifier re-derives. This
     * application has no such URL: App\Support\CustomerLinkSigner replaced them
     * deliberately (its header says why — the advisory, and KBB_BASE_PATH
     * making the re-derived path differ from the signed one), and it HMACs a
     * canonical claim string that contains no URL at all, so there is no path
     * to confuse.
     *
     * That makes the advisory unreachable by architecture rather than by luck,
     * and architecture is one `URL::temporarySignedRoute()` away from changing.
     * This assertion is the tripwire. A lane that needs a signed URL should
     * read docs/DEPENDENCY-ADVISORIES.md first, not delete this test.
     */
    $offenders = [];

    $sources = array_merge(
        glob(base_path('routes/*.php')) ?: [],
        iterator_to_array(
            new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())),
                '/\.php$/'
            ),
            false
        ),
    );

    foreach ($sources as $file) {
        $path = (string) $file;
        $code = (string) file_get_contents($path);

        // Comments are stripped first. Several files in app/ discuss these APIs
        // at length precisely to explain why they are not used, and a grep that
        // cannot tell prose from code would report every one of them.
        $stripped = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $forbidden = [
            'signedRoute(',
            'temporarySignedRoute(',
            'signedUrl(',
            'hasValidSignature(',
            'hasValidRelativeSignature(',
            'hasCorrectSignature(',
            'ValidateSignature',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($stripped, $needle)) {
                $offenders[] = str_replace(base_path() . '/', '', $path) . ' -> ' . $needle;
            }
        }

        // The `signed` route middleware reaches the same verifier without ever
        // naming it, so the alias is checked as well as the API.
        if (preg_match("/middleware\(\s*\[?\s*'signed'/", $stripped) === 1) {
            $offenders[] = str_replace(base_path() . '/', '', $path) . " -> middleware('signed')";
        }
    }

    expect($offenders)->toBeEmpty(
        "Laravel signed URLs are in use again, so GHSA-crmm-hgp2-wgrp applies to this install: \n"
        . implode("\n", $offenders)
        . "\nSee docs/DEPENDENCY-ADVISORIES.md — App\\Support\\CustomerLinkSigner exists for this."
    );
});

it('rejects the exact addresses the framework rule lets through', function () {
    /*
     * StorefrontEmail is the storefront's replacement for `email` on the
     * forgot-password form, and the guard EmailVerificationController runs over
     * a STORED address before sending to it. Its own test file checks a list of
     * hand-picked payloads; this checks the two shapes that the installed
     * framework was measured accepting, which is the list that matters.
     *
     * Deliberately NOT asserted here: that the framework still accepts them.
     * That would be a true statement today and a broken test the morning the
     * upgrade lands, and the upgrade lane does not need a booby trap. Noticing
     * that upstream has fixed it is the advisory diff's job in ci.yml, which
     * reports a KNOWN advisory that has gone away as loudly as a new one.
     */
    foreach (crlfAddressesTheFrameworkAccepts() as $why => $address) {
        expect(StorefrontEmail::passes($address))
            ->toBeFalse("StorefrontEmail accepted {$why}: " . json_encode($address));
    }

    // The shape the store actually receives is still accepted, so the rule is
    // narrow rather than broken.
    expect(StorefrontEmail::passes('ordinary.customer+tag@example.co.uk'))->toBeTrue();
});
