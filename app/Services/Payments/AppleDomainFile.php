<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\SiteHost;
use App\Support\SiteUrl;

/**
 * Apple's domain association file: what it looks like, whether the shop has
 * the right one, and where it is served. (Lane WL.)
 *
 * WHY THIS EXISTS. On 9 October 2026 kbeautybliss.com answered
 * /.well-known/apple-developer-merchantid-domain-association with 28 bytes:
 * `pmd_1UNuE9LD8AK6OnidAz8Neu5t`. That is the ID Stripe gives a row on
 * Settings → Payment method domains, not the file Apple asks for. Apple cannot
 * verify a domain from it, so Stripe's Express Checkout Element reported no
 * Apple Pay on an iPhone with a card in Wallet, the express row removed itself,
 * and the owner saw nothing at all — no error, because there is nothing to
 * show a shopper. The box took any text and the route served any text, so the
 * mistake was invisible everywhere it could have been caught.
 *
 * WHAT APPLE'S FILE IS. One line of hexadecimal (0-9, A-F): a hex-encoded JSON
 * document carrying Stripe's merchant identifier and Apple's signature, several
 * kilobytes long. Every association file Stripe hands out has that shape, so
 * that is what is accepted — and anything else is refused BY NAME, with what to
 * paste instead, on save and on the Stripe status block.
 *
 * Pure: no query, no network. The caller hands in the stored value.
 */
final class AppleDomainFile
{
    /** Where Apple looks, at the root of the domain. Apple will not follow a prefix or a redirect. */
    public const PATH = '/.well-known/apple-developer-merchantid-domain-association';

    /** The gateway-config key (Stripe row) the pasted file is stored under. */
    public const CONFIG_KEY = 'apple_domain_association';

    /** A file placed by hand, relative to storage/. Wins over the paste. */
    public const FILE_PATH = 'app/apple-pay/domain-association';

    /**
     * A real file is thousands of characters. Anything shorter is an ID, a
     * fragment or a placeholder — never the file.
     */
    public const MIN_BYTES = 200;

    /**
     * A bound, not a guess at the size. It was 8 KB, under the size the
     * signed file can reach; the hex-only rule is what keeps this response
     * harmless, so the cap only has to stop an unbounded body.
     */
    public const MAX_BYTES = 32768;

    /** What the owner should do, in one sentence, wherever a problem is shown. */
    public const WHAT_TO_PASTE = 'Download Apple’s file from Stripe (Settings → Payments → Payment method domains → Add a new domain offers it; it is also at https://stripe.com/files/apple-pay/apple-developer-merchantid-domain-association), open it in a text editor and paste ALL of it into Store → Payments → Credit or debit card → Apple Pay domain file: one long line of digits and the letters A–F.';

    /**
     * Null when $raw is a usable association file; otherwise a plain-English
     * sentence saying what it is and what to paste instead.
     */
    public static function problem(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return 'Nothing has been pasted in. '.self::WHAT_TO_PASTE;
        }

        // The exact mistake that reached the live shop.
        if (preg_match('/^pmd_[A-Za-z0-9]+$/', $value) === 1) {
            return 'That is Stripe’s ID for your domain ('.substr($value, 0, 8).'…), not Apple’s file, and Apple cannot verify your domain from it. '.self::WHAT_TO_PASTE;
        }

        // Any other Stripe or Apple identifier: apwc_…, acct_…, merchant.com.…
        if (preg_match('/^(?:[a-z]+_[A-Za-z0-9]+|merchant\.[A-Za-z0-9.\-]+)$/', $value) === 1) {
            return 'That looks like an ID, not Apple’s file. '.self::WHAT_TO_PASTE;
        }

        if (strlen($value) > self::MAX_BYTES) {
            return 'That is far longer than Apple’s file. '.self::WHAT_TO_PASTE;
        }

        if (preg_match('/^[0-9A-Fa-f]+$/', $value) !== 1) {
            return 'Apple’s file is only digits and the letters A–F, with no spaces; this has other characters in it. '.self::WHAT_TO_PASTE;
        }

        if (strlen($value) < self::MIN_BYTES) {
            return 'That is too short to be Apple’s file (it runs to thousands of characters). '.self::WHAT_TO_PASTE;
        }

        return null;
    }

    /** The bytes to serve — trimmed, otherwise exactly as given — or null. */
    public static function clean(string $raw): ?string
    {
        return self::problem($raw) === null ? trim($raw) : null;
    }

    /** The file placed by hand under storage/, raw, or null when there is none. */
    public static function onDisk(): ?string
    {
        $path = storage_path(self::FILE_PATH);

        // is_file before is_readable: a directory there is a different
        // mistake, and file_get_contents would warn rather than return false.
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1024);

        return is_string($raw) ? $raw : null;
    }

    /**
     * What /.well-known/… answers with, and why — the same order
     * AppleDomainController uses: a valid hand-placed file, else a valid paste.
     *
     * @return array{source: string, ok: bool, problem: string|null}
     */
    public static function status(string $pasted): array
    {
        $disk = self::onDisk();
        $diskProblem = $disk === null ? null : self::problem($disk);

        if ($disk !== null && $diskProblem === null) {
            return ['source' => 'file', 'ok' => true, 'problem' => null];
        }

        if (trim($pasted) !== '') {
            $problem = self::problem($pasted);

            return ['source' => 'pasted', 'ok' => $problem === null, 'problem' => $problem];
        }

        if ($disk !== null) {
            return ['source' => 'file', 'ok' => false, 'problem' => 'The file at storage/'.self::FILE_PATH.' on the server: '.$diskProblem];
        }

        return ['source' => 'none', 'ok' => false, 'problem' => 'No Apple Pay domain file is in place, so Apple cannot verify your domain and the Apple Pay button will not appear. '.self::WHAT_TO_PASTE];
    }

    /**
     * The domain wallets run on: the main address (Platform → Site address),
     * else APP_URL's host. Never a hard-coded name — the shop has moved once.
     */
    public static function host(): string
    {
        $host = SiteHost::canonical() ?: SiteHost::normalise(SiteUrl::configuredHost());

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** https://<your domain>/.well-known/apple-developer-merchantid-domain-association */
    public static function url(): string
    {
        $host = self::host();

        return $host === '' ? self::PATH : 'https://'.$host.self::PATH;
    }

    /**
     * The names to add in Stripe → Settings → Payment method domains.
     *
     * @return list<string>
     */
    public static function domainsToRegister(): array
    {
        $host = self::host();

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host === '' ? [] : [$host];
        }

        return [$host, 'www.'.$host];
    }
}
