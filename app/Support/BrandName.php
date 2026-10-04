<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shop's name, K-Beauty Bliss — and the one place that knows the old one.
 *
 * Lane BR. The owner, 4 October, ahead of pointing kbeautybliss.com at this
 * app: "please replace the word Extra Beauty > K-Beauty Bliss everywhere ... we
 * need to focus on our original name K-Beauty Bliss". Shoppers who search
 * "kbeauty" / "k beauty" / "k-beauty" land on a competitor and pay them,
 * believing it is this shop, so the brand has to read the same everywhere a
 * search engine or a shopper reads it.
 *
 * WHAT IS HERE AND WHY IN ONE FILE
 *   NAME / ALTERNATES   what the Organization and WebSite nodes publish.
 *                       Google's site-name system reads WebSite.name and
 *                       alternateName; the alternates are the spellings a
 *                       shopper actually types, never a list of keywords.
 *   OLD                 the pattern for "Extra Beauty" AS A NAME — never as a
 *                       domain, an address, a handle or a legal entity. The
 *                       migration, the admin check and appName() share it, so
 *                       "what counts as the old name" is decided once.
 *   switches            three settings, each ON unless set to '0' — he asked
 *                       for all of it (CLAUDE.md, 30 September reversal), and
 *                       each can be put back from Store → SEO Keywords → Brand.
 *
 * THE DOMAIN IS NOT THE NAME. extrabeauty.ae keeps working until the cutover
 * moves the shop (docs/CUTOVER-EXTRABEAUTY.md); rewriting it here would break
 * links, mail and Apple Pay. The lookarounds in OLD are what keep it out.
 */
final class BrandName
{
    public const NAME = 'K-Beauty Bliss';

    /** WebSite/Organization alternateName, exactly as the owner's brief lists them. */
    public const ALTERNATES = ['KBeauty Bliss', 'K Beauty Bliss', 'Kbeautybliss', 'K-Beauty Bliss UAE'];

    /** Settings keys. Absent or anything but '0' means ON. */
    public const TITLES = 'seo_brand_titles';

    public const ALTERNATES_KEY = 'seo_brand_alternates';

    public const KBEAUTY_ONE = 'seo_kw_kbeauty_one';

    public const SWITCHES = [self::TITLES, self::ALTERNATES_KEY, self::KBEAUTY_ONE];

    /*
     * "Extra Beauty" used as a NAME. Case-insensitive; space, hyphen or nothing
     * between the words. NOT matched:
     *   - inside a host, path, email or handle: extrabeauty.ae, info@extra…,
     *     instagram.com/extrabeauty, #extrabeauty (lookbehind on . / @ # _ - and
     *     letters; lookahead on ".x" so "extrabeauty.ae" is a domain while
     *     "Extra Beauty." at the end of a sentence is still the name);
     *   - a legal entity: "Extra Beauty Trading LLC", "Extra Beauty FZE" — a
     *     tax invoice must carry the trade-licence name, and only the owner
     *     knows whether that changed. The admin check lists these as left on
     *     purpose.
     * A JSON-escaped newline or tab before the name ("\nExtra Beauty") is
     * text, not a word character, so it is let through explicitly.
     */
    private const LEGAL = '(?:\s+(?:general\s+)?(?:trading|llc|l\.l\.c|fze|fzco|fz[\s\-]?llc|est\b|est\.|establishment|company|co\.|co\b|ltd|limited|inc\b|group))';

    public const OLD = '/(?:(?<=\\\\[nrt])|(?<![\p{L}\p{N}@#.\/_\\\\\-]))extra[ \x{00A0}\-]?beauty(?![\p{L}\p{N}@_\-]|\.[\p{L}\p{N}]|'.self::LEGAL.')/iu';

    /** Any spelling at all — what the admin check counts as "still there". */
    public const ANY = '/extra[\s\-_]?beauty|[اإأ]كسترا\s*بيوتي|\\\\u06(?:27|25|23)\\\\u0643\\\\u0633\\\\u062a\\\\u0631\\\\u0627(?:\s|\\\\u0020)?\\\\u0628\\\\u064a\\\\u0648\\\\u062a\\\\u064a/iu';

    /** The Arabic spelling, raw and as json_encode() writes it into a JSON column. */
    private const OLD_AR = '/[اإأ]كسترا\s*بيوتي/u';

    private const OLD_AR_ESCAPED = '/\\\\u06(?:27|25|23)\\\\u0643\\\\u0633\\\\u062a\\\\u0631\\\\u0627(?:\s|\\\\u0020)?\\\\u0628\\\\u064a\\\\u0648\\\\u062a\\\\u064a/i';

    /**
     * $value with every old-name occurrence replaced, and how many there were.
     * Case follows the match: "extra beauty" (a keyword) becomes
     * "k-beauty bliss", "EXTRA BEAUTY" becomes "K-BEAUTY BLISS", anything else
     * "K-Beauty Bliss". The replacement never matches OLD, so a second pass
     * finds nothing — which is what makes the migration idempotent.
     *
     * ALL-LOWERCASE "extra beauty" INSIDE A SENTENCE IS LEFT ALONE unless
     * $lowercaseToo: "add extra beauty to your routine" is English, not the
     * shop. It is replaced when it is the whole value (a store name typed in
     * lower case) and always in the keyword tables, where every phrase is
     * lower case by construction (KeywordText::clean()).
     *
     * @return array{0: string, 1: int}
     */
    public static function replace(string $value, bool $lowercaseToo = false): array
    {
        $n = 0;
        $whole = mb_strtolower(trim($value));
        $out = (string) preg_replace_callback(self::OLD, static function (array $m) use (&$n, $lowercaseToo, $whole): string {
            $hit = $m[0];

            if (! $lowercaseToo && $hit === mb_strtolower($hit) && $whole !== $hit) {
                return $hit;
            }

            $n++;

            return match (true) {
                $hit === mb_strtolower($hit) => mb_strtolower(self::NAME),
                $hit === mb_strtoupper($hit) => mb_strtoupper(self::NAME),
                default => self::NAME,
            };
        }, $value);

        $out = (string) preg_replace(self::OLD_AR, self::NAME, $out, -1, $ar);
        $out = (string) preg_replace(self::OLD_AR_ESCAPED, self::NAME, $out, -1, $arEsc);

        return [$out, $n + $ar + $arEsc];
    }

    /** How many occurrences of any spelling remain, replaceable or not. */
    public static function anyCount(string $value): int
    {
        return (int) preg_match_all(self::ANY, $value);
    }

    /**
     * config('app.name'), unless it is blank, the framework's "Laravel", or the
     * old name — then K-Beauty Bliss. Every fallback that read APP_NAME reads
     * this instead: the live .env is not something a package can rewrite, and a
     * stale APP_NAME="Extra Beauty" there would otherwise reach the From line
     * of every email the moment the mail_from_name setting was blank.
     */
    public static function appName(): string
    {
        $name = trim((string) config('app.name'));

        if ($name === '' || strcasecmp($name, 'Laravel') === 0 || self::anyCount($name) > 0) {
            return self::NAME;
        }

        return $name;
    }

    /** True when $name is this brand, however it is spelled or spaced. */
    public static function isOurs(string $name): bool
    {
        $fold = static fn (string $s): string => (string) preg_replace('/[^a-z]/', '', mb_strtolower($s));

        return $fold($name) === $fold(self::NAME);
    }

    /**
     * The alternate names for the node named $name — only when that node IS
     * K-Beauty Bliss, so a shop renamed again later does not go on claiming
     * these. Never repeats the name itself.
     *
     * @return list<string>
     */
    public static function alternatesFor(string $name): array
    {
        if (! self::isOurs($name)) {
            return [];
        }

        return array_values(array_filter(self::ALTERNATES, static fn (string $a): bool => $a !== $name));
    }

    /** @param array<string, mixed> $s a settings map */
    public static function on(array $s, string $key): bool
    {
        return (string) ($s[$key] ?? '1') !== '0';
    }

    /**
     * The home page's title when the owner has not typed one: the brand first,
     * then what the shop is. 56 characters in English, inside Google's ~60.
     */
    public static function homeTitle(string $locale): string
    {
        return $locale === 'ar'
            ? self::NAME.' — متجر العناية بالبشرة الكورية وكي بيوتي في الإمارات'
            : self::NAME.' — Korean Skincare & K-Beauty Store in UAE';
    }

    /** The home page's description when the owner has not typed one. Names the brand once. */
    public static function homeDescription(string $locale): string
    {
        return $locale === 'ar'
            ? self::NAME.' متجر إلكتروني للعناية بالبشرة الكورية في الإمارات: منتجات كي بيوتي أصلية من أشهر العلامات الكورية — سيروم، تونر، واقي شمس والمزيد، مع التوصيل داخل الإمارات.'
            : self::NAME.' is the UAE online store for authentic Korean skincare: original K-beauty serums, toners, sunscreens and more from the top Korean brands, delivered across the Emirates.';
    }
}
