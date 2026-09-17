<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE CONCERNS A ROUTINE IS BUILT FOR — and they are the quiz's concerns, not
 * a second list beside them.
 *
 * ── WHY THE SAME EIGHT ──────────────────────────────────────────────────────
 *
 * resources/views/store/skin-quiz.blade.php has a `CONCERNS` array of exactly
 * these eight, and those strings are load-bearing in a way that is easy to miss
 * and expensive to break. The quiz POSTs them to /api/quiz as the lead's
 * `concerns`, Api\QuizController stores them comma-joined, the owner's Quiz
 * Leads screen lists them, and recommend() COMPARES them
 * (`state.concerns.includes('Hydration')`). InterfaceStrings' own note says so:
 * they stay English for that reason.
 *
 * So a shop with nine concerns in one place and eight in another has two
 * vocabularies, and the day somebody wires "the quiz said Acne, show me that
 * routine" the join fails silently on the spellings that do not match. The
 * English on the right-hand side below is therefore copied from that array
 * character for character, including the ampersands and the American "aging",
 * and QuizAndRoutinesShareOneConcernListTest reads both files and fails if they
 * ever drift apart.
 *
 * ── SLUG LEFT, ENGLISH RIGHT, AND WHY THAT WAY ROUND ────────────────────────
 *
 * The slug is what a URL carries and what `products.routine_concerns` stores.
 * It never changes and is never translated. The English is a LABEL, which this
 * shop translates — `store.routines.concern_<slug>` in InterfaceStrings — so
 * storing the English against a product would mean an Arabic shop matching a
 * product against a word nobody there typed.
 *
 * The slugs are deliberately shorter than the labels ('ageing', not
 * 'fine-lines-aging'): a slug is a URL segment the owner may one day have to
 * read out, and the label is free to be a sentence.
 *
 * ── WHAT A CONCERN IS NOT ───────────────────────────────────────────────────
 *
 * It is not a category and not a tag. `categories` is the merchandising tree
 * (Cleansers, Toners, Serums) the shop already sorts by, and `tags` is a
 * WooCommerce import whose vocabulary nobody here chose. Either would have
 * saved a column and neither says what this says.
 */
final class RoutineConcerns
{
    /**
     * slug => the exact English the skin quiz uses.
     *
     * @var array<string, string>
     */
    public const LIST = [
        'hydration'   => 'Hydration',
        'dark-spots'  => 'Dark spots & tone',
        'acne'        => 'Acne & blemishes',
        'ageing'      => 'Fine lines & aging',
        'sensitivity' => 'Redness & sensitivity',
        'pores'       => 'Pores & oil',
        'dullness'    => 'Dullness & glow',
        'sun'         => 'Sun protection',
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::LIST);
    }

    public static function exists(mixed $slug): bool
    {
        return is_string($slug) && array_key_exists($slug, self::LIST);
    }

    /** The back-office wording. Not translated — see InterfaceStrings' header. */
    public static function adminLabel(string $slug): string
    {
        return self::LIST[$slug] ?? $slug;
    }

    /** The shopper-facing wording, which is. */
    public static function labelKey(string $slug): string
    {
        return 'store.routines.concern_'.$slug;
    }

    /**
     * A stored list of concern slugs, cleaned.
     *
     * Unknown slugs are DROPPED rather than kept or refused. The column is
     * written by one admin screen against this constant, so an unknown value
     * means the list shrank under a row that already existed — and the honest
     * reading of "tagged for a concern this shop no longer has" is "not tagged
     * for any concern that exists", which is what an empty list already means
     * here: suits any routine.
     *
     * @return list<string>
     */
    public static function clean(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $slug) {
            $slug = is_string($slug) ? mb_strtolower(trim($slug)) : '';

            if (self::exists($slug) && ! in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }

        // Stored in the order this class lists them, never in the order the
        // operator happened to click the boxes, so two products tagged the same
        // way compare equal and the admin screen reads consistently.
        return array_values(array_filter(self::slugs(), static fn ($s) => in_array($s, $out, true)));
    }
}
