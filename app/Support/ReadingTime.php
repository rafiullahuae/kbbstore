<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long an article takes to read, in whole minutes.
 *
 * WHY THIS EXISTS.
 *
 * The home page's journal rail printed `$post->read_minutes ?? 5`. There is no
 * `posts.read_minutes` column — the table has slug, title, excerpt, body,
 * cover, tag, author, status, seo and published_at — and Eloquent answers null
 * for an attribute it does not have rather than failing. So the `?? 5` was not
 * a fallback, it was the only branch: EVERY article on the home page claimed
 * "5 min read", a 400-word note and a 3,000-word guide alike.
 *
 * A stored column was the other option and is the wrong one here. It is one
 * more field to fill in on every article, it goes stale the moment the body is
 * edited, and the number is already fully determined by the text. Derived, it
 * cannot disagree with the article it describes.
 *
 * 200 WORDS A MINUTE, which is the middle of the usual 200-250 range for adult
 * silent reading of ordinary prose, and the slower end on purpose: over-stating
 * how long a piece takes costs a reader nothing, under-stating it is the same
 * broken promise the flat 5 was.
 *
 * ROUNDED UP, AND NEVER ZERO. "0 min read" is not a claim anyone wants to make
 * about their own writing, and an empty or missing body gives 1 rather than 0
 * for the same reason — a card that renders at all should not announce that
 * there is nothing behind it.
 *
 * TAGS ARE NOT WORDS. `posts.body` is HTML written in the admin's rich-text
 * editor, so it is stripped before counting; `<div class="promo-wrapper">` is
 * markup, not two seconds of reading. Entities are decoded first so that a
 * `&nbsp;` between two words separates them instead of gluing them into one.
 */
final class ReadingTime
{
    /** Words an adult reads per minute of ordinary prose. */
    public const WORDS_PER_MINUTE = 200;

    /** Whole minutes, at least one. */
    public static function minutes(?string $html): int
    {
        return max(1, (int) ceil(self::words($html) / self::WORDS_PER_MINUTE));
    }

    /** The words in a rich-text body, with the markup taken out. */
    public static function words(?string $html): int
    {
        $text = strip_tags((string) $html);

        // &nbsp; decodes to U+00A0, which PCRE's \s does not match even under
        // /u, so it is flattened to a plain space before splitting -- otherwise
        // "one&nbsp;two" counts as a single word.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
