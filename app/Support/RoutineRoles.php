<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE ROLE A PRODUCT PLAYS IN A ROUTINE. Five words, and the whole reason this
 * lane exists.
 *
 * ── WHY A COLUMN AT ALL ─────────────────────────────────────────────────────
 *
 * Lane FB deleted the skin quiz's `POOL`: seventeen invented products with
 * invented brands and invented prices, plus a 15% "bundle saving" no discount
 * rule in this shop has ever offered. What it left behind was the correct
 * finding, written into resources/views/store/skin-quiz.blade.php:
 *
 *     "DRIVING IT FROM THE CATALOGUE IS THE NEXT STEP AND NEEDS A SCHEMA
 *      CHANGE: a `routine_role` on products (cleanser / toner / treatment /
 *      moisturiser / spf), an admin field to set it, and an endpoint to read
 *      the visible, in-stock row per role."
 *
 * Product::toApi() publishes enough to RENDER a recommendation — a name, a
 * price, an image, a stock status — and nothing whatsoever to CHOOSE one. The
 * catalogue records what a product costs and which category page it sits on;
 * `categories` is a merchandising tree the owner reorders at will, and a
 * routine step is not a category. Nothing else on `products` says "this is the
 * thing you put on after the toner".
 *
 * ── WHY THESE FIVE, IN THIS ORDER ───────────────────────────────────────────
 *
 * They are the five the previous lane named, and they are the order the steps
 * already appear in inside the quiz's own STEPS table — cleanse, tone, treat,
 * moisturise, protect. That order is not a preference: a sunscreen under a
 * moisturiser is a sunscreen that does not work, and an active applied over an
 * occlusive is an active that does not reach skin. It is the one part of this
 * feature that is skincare rather than merchandising, so it is code, not a
 * setting, and it is stated once here.
 *
 * Roles the catalogue also sells — masks, eye creams, essences — are
 * deliberately NOT here. Every one of them would be a sixth step the owner has
 * to tag before anything renders, and none of them is load-bearing: a routine
 * without an eye cream is a routine. A sixth role is one line in ORDER plus
 * two keys in InterfaceStrings, and the storefront picks it up with no other
 * change; that is the extension point, and it is deliberately not taken now.
 *
 * ── NULL IS A REAL ANSWER ───────────────────────────────────────────────────
 *
 * `products.routine_role` is nullable and every row in the catalogue starts
 * that way. NULL means "nobody has said", not "none of the above", and the
 * admin screen's whole top half exists to make that number visible — a routine
 * engine that silently skips half the catalogue is worse than no routine
 * engine, because it looks like it is working.
 */
final class RoutineRoles
{
    /**
     * The roles, in the order a routine applies them.
     *
     * The key is what is stored in `products.routine_role` and what appears in
     * a /routines/ URL, so it is short, lowercase and never translated. The
     * shopper-facing wording is InterfaceStrings' — see labelKey() — because a
     * step name is a sentence a shopper reads and this shop is bilingual.
     *
     * @var list<string>
     */
    public const ORDER = ['cleanse', 'tone', 'treat', 'moisturise', 'protect'];

    /**
     * The back-office wording, which is NOT translated.
     *
     * InterfaceStrings' header states the rule: "NOT here either: the back
     * office. Store → anything is one operator on a screen that is not indexed
     * and not customer-facing." The admin screen draws these; the storefront
     * draws the keyed pair below.
     *
     * @var array<string, string>
     */
    public const ADMIN_LABELS = [
        'cleanse'    => 'Cleanser',
        'tone'       => 'Toner',
        'treat'      => 'Treatment',
        'moisturise' => 'Moisturiser',
        'protect'    => 'SPF',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::ORDER;
    }

    public static function isRole(mixed $role): bool
    {
        return is_string($role) && in_array($role, self::ORDER, true);
    }

    /**
     * A stored value, normalised, or null.
     *
     * Anything unrecognised becomes NULL rather than throwing, because the
     * caller is usually reading a row: a `routine_role` this build does not
     * know — one written by a newer package and then rolled back, say — must
     * read as untagged, which is the state the owner can see and correct. It
     * must not take a product page down.
     */
    public static function normalise(mixed $role): ?string
    {
        $role = is_string($role) ? mb_strtolower(trim($role)) : '';

        return self::isRole($role) ? $role : null;
    }

    /** The shopper-facing name of the step this role fills. */
    public static function labelKey(string $role): string
    {
        return 'store.routines.role_'.$role;
    }

    /** The sentence under it, saying what the step is for. */
    public static function helpKey(string $role): string
    {
        return 'store.routines.role_'.$role.'_help';
    }

    /**
     * The position of a role in the routine, for sorting a stored step list.
     *
     * An unknown role sorts last rather than first: a step this build cannot
     * name should not push the cleanser down the page.
     */
    public static function position(string $role): int
    {
        $at = array_search($role, self::ORDER, true);

        return $at === false ? count(self::ORDER) : (int) $at;
    }
}
