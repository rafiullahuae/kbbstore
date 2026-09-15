<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The vocabulary of `reviews.status`, in one place.
 *
 * WHY THIS FILE EXISTS. The column had two spellings of "not approved" and
 * nothing that agreed on either. The schema declares the column
 * `pending | approved | spam` (0001_01_01_000000_create_kbb_schema.php, the
 * comment beside the column). The admin screen only ever WROTE `rejected` — a
 * fourth value nothing else in the application knows — and refused `spam` with
 * a 422, so a review that arrived carrying the schema's own third value could
 * not be moderated at all and appeared under no filter chip.
 *
 * SETTLED FROM THE SCHEMA AND THE READERS, NOT FROM THE WRITER.
 *
 *   - The schema says `pending | approved | spam`.
 *   - Review::scopeApproved() is `status = 'approved'`.
 *   - Every storefront read is that scope or its literal equivalent:
 *     Store\ProductController::reviewSummary(), Store\HomeController's review
 *     wall, Store\ReviewController::helpful(), Api\ReviewController::index()
 *     and Api\ProductController::reviews().
 *
 * So every reader asks exactly one question — "is this approved?" — and the
 * schema names the three buckets. `rejected` was never part of the vocabulary;
 * it was one screen's private spelling. The canonical set is the schema's.
 *
 * WHAT "REJECT" MEANS NOW. `spam` is this schema's name for "refused, not
 * published". The moderation screen's Reject action writes it, and `rejected`
 * is accepted on input as a LEGACY ALIAS that normalises to `spam` — so an old
 * admin build, a bookmarked call or a queued request keeps working instead of
 * 422ing, and the row it writes lands in the vocabulary rather than beside it.
 * The alias is one-directional: nothing stores `rejected` again.
 *
 * UNKNOWN VALUES BECOME `pending`, NOT `spam`. A value in neither set is a row
 * nobody has decided about — an import bug, a hand-edited row, a future source.
 * `pending` is the queue a human looks at; `spam` is the bucket nobody ever
 * opens again. Neither is published, so the safe default is the visible one.
 */
final class ReviewStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const SPAM = 'spam';

    /** The only values that may be stored. */
    public const ALL = [self::PENDING, self::APPROVED, self::SPAM];

    /**
     * Spellings accepted on input that are not themselves canonical.
     *
     * @var array<string, string>
     */
    public const ALIASES = ['rejected' => self::SPAM];

    /**
     * Everything a request may send for `status`, canonical plus aliases.
     *
     * @return list<string>
     */
    public static function accepted(): array
    {
        return array_values(array_unique(array_merge(self::ALL, array_keys(self::ALIASES))));
    }

    public static function isCanonical(?string $status): bool
    {
        return $status !== null && in_array($status, self::ALL, true);
    }

    /**
     * Fold any input onto the canonical set.
     *
     * Case and surrounding whitespace are ignored because the values this has
     * to meet came from a WordPress export and a hand-edited table, not from a
     * form with a select on it.
     */
    public static function normalise(?string $status): string
    {
        $key = mb_strtolower(trim((string) $status));

        if (in_array($key, self::ALL, true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? self::PENDING;
    }
}
