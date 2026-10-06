<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is this policy page still the placeholder this repository seeded? (Lane TP)
 *
 * The rule every write of the owner's pasted policy text goes through: a row
 * is replaced only while its TEXT is still the seed's. A page the owner has
 * edited in Pages → User pages fails the comparison and is never touched.
 *
 * The comparison is on the words, whitespace collapsed, so a seeded page the
 * editor merely re-saved still counts as seeded; and the seed's own editor
 * note ("This is placeholder wording. Edit this page in Store → Pages …") is
 * ignored, because deleting it is the first thing that note told him to do and
 * doing so is not writing a policy.
 */
final class SeededPolicyPages
{
    /**
     * sha256 of each seeded page's text, as fingerprint() reads it.
     *
     * privacy-policy: 2026_08_29_140000_seed_policy_pages. The other three:
     * 2026_11_06_000000_seed_footer_content_pages. OwnerPolicyPagesTest runs
     * both migrations' own pages() through fingerprint() and requires these
     * exact values, so a seed edited later cannot silently stop matching.
     */
    public const SEEDED = [
        'delivery' => '011f9fc871f3350d68e8523266cec520b2bc0a9c22130d0a5fe1687762dc3a40',
        'refund_returns' => 'fb42b28ad171521abe2298376b1e847c2b0df0d29484cadd874d0c8b8046cb82',
        'faqs' => 'eb055861cfc0ca0f9ca6da1716ac9b45f4c3f473aaa7daa795d1a5dcb8168763',
        'privacy-policy' => 'a0eff8e63baeef99c039b1c1388e424fe0e58c51318d33640766d9aa890c785c',
    ];

    public static function fingerprint(?string $html): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/This is placeholder wording\. Edit this page in Store\s*→\s*Pages to publish your own [^.]*\./u', '', $text);

        return hash('sha256', trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public static function isSeeded(string $slug, ?string $content): bool
    {
        return isset(self::SEEDED[$slug]) && hash_equals(self::SEEDED[$slug], self::fingerprint($content));
    }
}
