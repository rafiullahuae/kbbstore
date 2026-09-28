<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Load every set's members in one batch — and nothing at all when there are no
 * sets. (Lane SET)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TWO RULES PULL IN OPPOSITE DIRECTIONS HERE AND THIS CLASS IS HOW BOTH HOLD.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * StorefrontQueryBudgetTest is a budget: a basket holding one set must not cost
 * one query per member. The obvious answer is to add `items.product.setItems.-
 * member` to the four `with()` lists that feed the basket surfaces.
 *
 * But CLAUDE.md's first rule is that nothing which already works may change,
 * and an unconditional eager load is not free on a shop with no sets in it.
 * Laravel runs a relation's query whether or not any parent needs it, so adding
 * three relations to those lists would cost THREE QUERIES ON EVERY CART,
 * CHECKOUT AND DRAWER RENDER of a shop that has never created a set — the
 * ceilings in StorefrontQueryBudgetTest would move for a feature nobody is
 * using. Raising a budget to pay for a feature that is switched off is exactly
 * the kind of change that rule exists to stop.
 *
 * So this is asked AFTER the products are in hand, and it looks first:
 *
 *   - no set among them (which is every basket in this shop today): it returns
 *     without touching the database. Not one query. The budget does not move
 *     and StorefrontQueryBudgetTest's numbers are the same as they were.
 *
 *   - one or more sets: THREE queries, batched, for the whole page — the
 *     membership rows for every set at once, their member products, and the
 *     chosen variants with their option names. Three whether the page holds one
 *     set of two members or four sets of thirty, which is the flatness the
 *     budget test measures and the property that actually matters.
 *
 * ── WHY `whereKey` ON A SECOND QUERY RATHER THAN A CONSTRAINED with() ──────
 *
 * Because the products are already loaded. `load()` on the parent collection is
 * what Eloquent gives for "fill in this relation on rows I have", and it keys
 * off the models in hand, so the member lookup is one `where id in (...)` and
 * the variant lookup another. A constrained `with()` would have meant
 * re-fetching the products.
 */
final class SetEagerLoad
{
    /**
     * The relations a set row needs, in one place so the four call sites cannot
     * drift into loading different ones.
     *
     * `member.brand` because a set row prints the member's brand.
     * `variant.attributeValues` because SetContents reads the option NAME
     * through ProductVariant::label(), and that guard is deliberately silent —
     * a caller that omits this list gets no option label rather than one query
     * per member. See the comment in SetContents::fromProduct().
     */
    private const RELATIONS = [
        'setItems',
        'setItems.member:id,slug,name,brand_id,sku,price,sale_price,sale_starts_at,sale_ends_at,image,type',
        'setItems.member.brand:id,name,slug',
        'setItems.variant:id,product_id,sku,price,sale_price,sale_starts_at,sale_ends_at,image',
        'setItems.variant.attributeValues:id,attribute_id,name',
    ];

    /**
     * @param  iterable<mixed>  $products  Product models, nulls tolerated —
     *                                     a cart line whose product was deleted
     *                                     hands one over.
     */
    public static function on(iterable $products): void
    {
        /*
         * An ELOQUENT collection, not a base one: `loadMissing()` is a method of
         * Illuminate\Database\Eloquent\Collection and `Support\Collection` does
         * not have it. Written out because the two are interchangeable almost
         * everywhere else in this application and a base collection here is a
         * BadMethodCallException on the one page that holds a set.
         */
        $sets = Collection::make($products)
            ->filter(fn ($p) => $p instanceof Product && $p->isSet() && ! $p->relationLoaded('setItems'))
            ->values();

        if ($sets->isEmpty()) {
            return;
        }

        /*
         * NOT de-duplicated, and that is deliberate. The same set can be on one
         * page twice — a basket line and a browsed tile — as two DIFFERENT model
         * instances carrying one id. Keeping only one of them would leave the
         * other with no members and draw a set with an empty box. loadMissing()
         * gathers the keys of the whole collection and runs ONE query for them,
         * so a duplicate costs nothing and both instances are filled.
         */
        $sets->loadMissing(self::RELATIONS);
    }
}
