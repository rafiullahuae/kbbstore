<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Review;

/**
 * The few customer reviews a product page hands to App\Support\Seo as
 * schema.org `review` items (Lane PS).
 *
 * WHY THIS EXISTS. Search Console -> Product snippets flagged "Missing field
 * review" on every product. The page already drew real, approved reviews and
 * already published an aggregateRating from them; it just never published any
 * of the reviews themselves. This turns the rows the page has ALREADY loaded
 * for its visible review list into plain arrays -- no query of its own.
 *
 * WHAT IT REFUSES, each for a reason Google or a shopper would care about:
 *
 *  - A rating outside 1..5. The scale is stated as 1..5; a 0 is not a review.
 *  - An author that is empty, an e-mail address, a link, or carries no letter
 *    at all. Google requires a Person with a real name, and an address typed
 *    into the name box must never be published to a search engine. The review
 *    is dropped rather than published as "Anonymous".
 *  - The surname. A shopper typed "Fatima Al Mansoori" into a review form,
 *    not into a search index: the first name plus the last word's initial is
 *    the most this publishes ("Fatima M."). Never the email, never the IP --
 *    neither column is read here.
 *  - Bytes json_encode() cannot encode. Seo::encodeJsonLd() returns NULL on
 *    invalid UTF-8, and NULL drops the WHOLE Product node -- one imported
 *    review with a stray Latin-1 byte would cost the product its price in
 *    search. Every string is scrubbed to valid UTF-8 first.
 *
 * `</script>` is not handled here: Seo::encodeJsonLd() encodes with
 * JSON_HEX_TAG, so `<` and `>` leave as < / > whatever this returns.
 */
final class ReviewSchema
{
    /** How many reviews a product publishes: "a few", per Google's examples. */
    public const MAX = 3;

    /** A review body longer than this is cut at a word, so three long reviews cannot bloat every page. */
    public const BODY_MAX = 1000;

    /**
     * @param  iterable<Review|object>  $reviews  in the order the page draws them
     * @return list<array{author: string, rating: int, date: ?string, title: ?string, body: ?string}>
     */
    public static function rows(iterable $reviews, int $max = self::MAX): array
    {
        $out = [];

        foreach ($reviews as $review) {
            if (count($out) >= $max) {
                break;
            }

            $rating = (int) ($review->rating ?? 0);

            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $author = self::author((string) ($review->author_name ?? ''));

            if ($author === null) {
                continue;
            }

            $created = $review->created_at ?? null;
            $date = $created instanceof \DateTimeInterface ? $created->format('Y-m-d') : null;

            $out[] = [
                'author' => $author,
                'rating' => $rating,
                'date' => $date,
                'title' => self::text((string) ($review->title ?? ''), 200),
                'body' => self::text((string) ($review->content ?? ''), self::BODY_MAX),
            ];
        }

        return $out;
    }

    /** First name plus the last word's initial, or NULL when it is not a name at all. */
    public static function author(string $raw): ?string
    {
        $name = self::text($raw, 200);

        if ($name === null
            || str_contains($name, '@')
            || preg_match('~https?:|www\.|\.(com|net|org|ae)\b~iu', $name) === 1
            || preg_match('/\p{L}/u', $name) !== 1) {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [$name];
        $first = mb_substr((string) $words[0], 0, 40);

        if (count($words) < 2) {
            return $first;
        }

        $initial = mb_strtoupper(mb_substr((string) end($words), 0, 1));

        return preg_match('/\p{L}/u', $initial) === 1 ? $first.' '.$initial.'.' : $first;
    }

    /** Valid UTF-8, no control characters, single-spaced, trimmed, capped at a word. */
    private static function text(string $raw, int $max): ?string
    {
        $s = mb_scrub($raw, 'UTF-8');
        $s = preg_replace('/[\p{Cc}\s]+/u', ' ', $s) ?? '';
        $s = trim($s);

        if ($s === '') {
            return null;
        }

        if (mb_strlen($s) > $max) {
            $cut = mb_substr($s, 0, $max);
            $space = mb_strrpos($cut, ' ');
            $s = rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut).'…';
        }

        return $s;
    }
}
