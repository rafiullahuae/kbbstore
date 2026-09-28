<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrderItem;
use App\Models\Product;

/**
 * What is in a Set — one writer, several readers. (Lane SET)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THERE IS EXACTLY ONE DESCRIPTION OF A SET'S CONTENTS IN THIS APPLICATION
 * AND IT IS THE ARRAY THIS CLASS RETURNS.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Seven surfaces draw a set: the cart panel, the cart page, the checkout
 * summary, the browsed rail, the order-confirmation email, the invoice and the
 * customer's own order page. An eighth (the admin order screen) reads it too.
 * If each of them built its own list, the eight would disagree the first time
 * one of them was changed -- which is the defect this shop has already paid for
 * twice, in two places that both decided what a product costs.
 *
 * So: `fromProduct()` for a set that is being SHOPPED (live, off the pivot) and
 * `fromOrderItem()` for a set that has been SOLD (the snapshot, off the order).
 * Both return the same shape, so one partial draws both.
 *
 * ── THE SHAPE ──────────────────────────────────────────────────────────────
 *
 *   [
 *     'members' => [
 *        ['name' => string, 'brand' => string, 'sku' => string,
 *         'variant' => string, 'quantity' => int, 'unit' => int, 'image' => ?string,
 *         'url' => ?string, 'visible' => bool],
 *        ...
 *     ],
 *     'count'      => int,   // how many physical items are in the box
 *     'partsTotal' => int,   // fils: what the members cost bought separately
 *     'setPrice'   => int,   // fils: what the set costs
 *     'saving'     => int,   // fils: partsTotal - setPrice, floored at 0
 *   ]
 *
 * ── MONEY IS INTEGER FILS, EVERYWHERE ON THIS PATH ─────────────────────────
 *
 * Every figure above is an int. Not one of them is ever a float, not in a
 * fixture and not in an intermediate: `(int)` casts on the way in, integer
 * arithmetic throughout, and `max(0, ...)` rather than `abs()` on the saving,
 * because a set priced ABOVE its parts is a pricing mistake to be shown at zero
 * and not a negative saving to be printed with a minus sign.
 *
 * ── WHY A SOLD SET READS THE SNAPSHOT AND NEVER THE PIVOT ──────────────────
 *
 * A set's contents change. `order_items` already carries a JSON snapshot for
 * exactly this reason and says so on the line above the one `set_contents` was
 * added to: "Snapshots, so an order still reads correctly after a product is
 * renamed or deleted." An invoice reprinted next year must show what was in the
 * box, not what is in the box now. fromOrderItem() therefore touches no
 * relation and costs no query -- it reads one JSON column that was written on
 * the day.
 */
final class SetContents
{
    /** The empty answer, so no caller has to special-case a missing key. */
    public const NONE = ['members' => [], 'count' => 0, 'partsTotal' => 0, 'setPrice' => 0, 'saving' => 0];

    /**
     * A set that is being shopped: the live pivot.
     *
     * NO QUERY IS MADE HERE WHEN THE CALLER EAGER-LOADED, which is the whole
     * reason this takes a loaded relation rather than running its own query.
     * The cart, drawer and checkout all load `items.product.setItems.member`
     * and `.variant` in one batch; a set with thirty members costs them the
     * same as a set with three. See StorefrontQueryBudgetTest.
     *
     * `$unitPrice` is what THIS basket line is being charged for the set, so a
     * line priced by a quantity bundle reports the saving it actually got
     * rather than the catalogue's. Omitted, the product's own effective price
     * is used, which is what a browsed-rail tile and a product page want.
     */
    public static function fromProduct(?Product $product, ?int $unitPrice = null): array
    {
        if ($product === null || ! $product->isSet()) {
            return self::NONE;
        }

        $members = [];
        $partsTotal = 0;
        $count = 0;

        foreach ($product->setItems as $row) {
            $member = $row->member;

            // A membership row whose product has been deleted is a hole, not a
            // member. It is skipped rather than drawn as a blank line -- the
            // cascade on the foreign key means this is only reachable on a
            // model loaded with a stale relation, but a shopper must never be
            // shown an empty row where a product used to be.
            if ($member === null) {
                continue;
            }

            $quantity = max(1, (int) $row->quantity);
            $variant = $row->variant;
            $unit = (int) ($variant?->effectivePrice() ?? $member->effectivePrice());

            $partsTotal += $unit * $quantity;
            $count += $quantity;

            $members[] = [
                'name' => (string) ($member->t('name') ?? $member->name),
                'brand' => (string) ($member->brand?->t('name') ?? $member->brand?->name ?? ''),
                'sku' => (string) ($variant?->sku ?? $member->sku ?? ''),
                /*
                 * ▲ label() READS THE `attributeValues` RELATION, which is one
                 * query per variant on a model that did not eager-load it --
                 * exactly the N+1 StorefrontQueryBudgetTest is a budget
                 * against. Every caller on a measured path loads
                 * `setItems.variant.attributeValues` in the same batch as the
                 * members (see CartController::CART_SET_WITH). This guard is
                 * what makes a caller that forgot cost NOTHING rather than cost
                 * one query per member; SetCheckoutSnapshotTest pins that the
                 * checkout's own eager-load is really there, by asserting the
                 * option name reaches the snapshot.
                 */
                'variant' => $variant !== null && $variant->relationLoaded('attributeValues')
                    ? $variant->label()
                    : '',
                'quantity' => $quantity,
                'unit' => $unit,
                'image' => $variant?->image ?: $member->image,
                /*
                 * ── THE TWO KEYS THE PRODUCT PAGE ADDED (Lane SP) ───────────
                 *
                 * A set's own product page names its members with their own
                 * pictures AND THEIR OWN LINKS, because on that page a member is
                 * a product the shopper may want to open. That is the only
                 * surface that wants an address; the fanned row does not, and
                 * neither does anything printed.
                 *
                 * ▲ NEITHER KEY REACHES A SNAPSHOT OR THE PUBLIC FEED, and
                 *   that is by construction rather than by care: snapshot() and
                 *   toApi() below both build their rows from an EXPLICIT list of
                 *   keys, so a key added here cannot travel to `order_items`
                 *   (where a stored address would go stale the first time a
                 *   member was renamed) or to /api/* (where it is simply not
                 *   wanted). tests/Feature/SetApiSecurityTest.php asserts the
                 *   feed's absent keys by name for exactly this reason.
                 *
                 * `visible` FAILS CLOSED, the same rule Product::isSet()
                 * documents. `status`, `is_visible` and `published_at` are the
                 * three columns storefrontVisible() needs, and a caller that
                 * selected a narrower list has not said this member is hidden —
                 * it has said nothing. Answering `false` there costs a link;
                 * answering `true` prints one that 404s, which is the worse of
                 * the two on a page a shopper reached from Google.
                 */
                'url' => $member->slug === null || $member->slug === '' ? null : $member->url(),
                'visible' => self::memberIsLive($member),
            ];
        }

        $setPrice = (int) ($unitPrice ?? $product->effectivePrice());

        return [
            'members' => $members,
            'count' => $count,
            'partsTotal' => $partsTotal,
            'setPrice' => $setPrice,
            'saving' => max(0, $partsTotal - $setPrice),
        ];
    }

    /**
     * What gets written into `order_items.set_contents` at checkout.
     *
     * The MEMBER LIST ONLY -- name, brand, sku, variant, quantity and the
     * member's own unit price on the day. Not the saving and not the parts
     * total: both are derived, and a derived figure stored beside its inputs is
     * a figure that can disagree with them. fromOrderItem() recomputes them
     * from the same numbers, so a printed invoice adds up however it is read.
     *
     * Returns null for a line that is not a set, which is what the column
     * stores for every ordinary line -- absent and empty both mean "not a set"
     * to every reader.
     */
    public static function snapshot(?Product $product): ?array
    {
        if ($product === null || ! $product->isSet()) {
            return null;
        }

        $members = [];

        foreach (self::fromProduct($product)['members'] as $m) {
            $members[] = [
                'name' => $m['name'],
                'brand' => $m['brand'],
                'sku' => $m['sku'],
                'variant' => $m['variant'],
                'quantity' => (int) $m['quantity'],
                'unit' => (int) $m['unit'],
                /*
                 * THE PICTURE TOO, and it is a snapshot like everything else on
                 * this row. The chosen design draws a fan of the members' own
                 * thumbnails, and an order page that fell back to a gradient for
                 * every member would show the customer a different row from the
                 * one they checked out on. Storing the address on the day is the
                 * same promise the name and the price make: this is what was in
                 * the box. If the file is later deleted the circle falls back to
                 * the gradient, which is what a pictureless product draws
                 * anyway -- a broken <img> is not possible, because it is a CSS
                 * background and not an element.
                 */
                'image' => $m['image'] ?? null,
            ];
        }

        // A set with no members at all still writes an empty list rather than
        // null: "this line was sold as a set and the box was empty" is a real
        // (and alarming) fact about an order, and null would erase it.
        return $members;
    }

    /**
     * A set that has been sold: the snapshot, and nothing else.
     *
     * Costs no query and consults no relation. `$item->unit_price` is what the
     * customer was charged for one set, which is the figure the saving on a
     * receipt has to be measured against -- not today's catalogue price.
     */
    public static function fromOrderItem(?OrderItem $item): array
    {
        $raw = $item?->set_contents;

        if (! is_array($raw) || $raw === []) {
            return self::NONE;
        }

        $members = [];
        $partsTotal = 0;
        $count = 0;

        foreach ($raw as $m) {
            if (! is_array($m)) {
                continue;
            }

            $quantity = max(1, (int) ($m['quantity'] ?? 1));
            $unit = max(0, (int) ($m['unit'] ?? 0));

            $partsTotal += $unit * $quantity;
            $count += $quantity;

            $members[] = [
                'name' => trim((string) ($m['name'] ?? '')),
                'brand' => trim((string) ($m['brand'] ?? '')),
                'sku' => trim((string) ($m['sku'] ?? '')),
                'variant' => trim((string) ($m['variant'] ?? '')),
                'quantity' => $quantity,
                'unit' => $unit,
                // The picture as it was on the day. Absent on a row written
                // before the column carried one, which draws the gradient --
                // the same fallback a pictureless product has always had.
                'image' => is_string($m['image'] ?? null) && $m['image'] !== '' ? $m['image'] : null,
            ];
        }

        $setPrice = (int) ($item?->unit_price ?? 0);

        return [
            'members' => $members,
            'count' => $count,
            'partsTotal' => $partsTotal,
            'setPrice' => $setPrice,
            'saving' => max(0, $partsTotal - $setPrice),
        ];
    }

    /**
     * The one-line-per-member form, for the two surfaces that have no room for
     * a partial: the plain-text twin of every order email, and the packing
     * documents' single cell.
     *
     * "2 x Anua Heartleaf Toner" -- the quantity first, because the people who
     * pack these orders read this and a quantity after a name gets read as part
     * of the name. The same reasoning as emails/partials/items.blade.php's
     * quantity column.
     *
     * @return list<string>
     */
    public static function lines(array $contents): array
    {
        $out = [];

        foreach ($contents['members'] ?? [] as $m) {
            $name = trim((string) ($m['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $variant = trim((string) ($m['variant'] ?? ''));

            $out[] = ((int) ($m['quantity'] ?? 1)) . ' × ' . $name . ($variant === '' ? '' : ' (' . $variant . ')');
        }

        return $out;
    }

    /**
     * Is this member a page a shopper can actually open? (Lane SP)
     *
     * The same three conditions as Product::scopeVisible() — `status`,
     * `is_visible` and the scheduled-publish window — asked of a model in hand
     * rather than of the database, because the members are already loaded and
     * one query per member is exactly what StorefrontQueryBudgetTest is a budget
     * against.
     *
     * READS getAttributes() AND NOT THE ACCESSORS, so a column that was never
     * selected is ABSENT rather than null, and absent means "this caller did not
     * ask" rather than "this product is hidden". Both answer false here, which
     * is the fail-closed direction: no link.
     */
    private static function memberIsLive(Product $member): bool
    {
        $row = $member->getAttributes();

        foreach (['status', 'is_visible', 'published_at'] as $column) {
            if (! array_key_exists($column, $row)) {
                return false;
            }
        }

        if ((string) $row['status'] !== 'publish' || ! (bool) $row['is_visible']) {
            return false;
        }

        // published_at NULL means "not scheduled", which is every row that
        // existed before the column did — see App\Support\ProductVisibility.
        $published = $row['published_at'] ?? null;

        if ($published === null || $published === '') {
            return true;
        }

        return strtotime((string) $published) <= time();
    }

    /**
     * The member list a PUBLIC endpoint may publish. (CLAUDE.md rule 5)
     *
     * ▲ A MEMBER IS A PRODUCT, AND A PRODUCT ROW CARRIES `wc_id`, `sku` AND
     *   `total_sales`. /api/* is unauthenticated -- every endpoint there is
     *   public -- so this is an explicit allowlist of five keys and never the
     *   model, exactly as Product::toApi() is. `sku` is dropped here although
     *   fromProduct() carries it for the admin screen and the packing slip:
     *   a supplier code is an internal identifier and the shop's public feed
     *   has no use for it.
     *
     *   tests/Feature/SetApiSecurityTest.php asserts the absent keys BY NAME
     *   rather than counting them, because a key added to fromProduct() must
     *   not reach the feed by simply being there.
     */
    public static function toApi(array $contents): array
    {
        $members = [];

        foreach ($contents['members'] ?? [] as $m) {
            $members[] = [
                'name' => (string) ($m['name'] ?? ''),
                'brand' => (string) ($m['brand'] ?? ''),
                'variant' => (string) ($m['variant'] ?? ''),
                'quantity' => (int) ($m['quantity'] ?? 1),
                'price' => (int) ($m['unit'] ?? 0),
            ];
        }

        return [
            'members' => $members,
            'item_count' => (int) ($contents['count'] ?? 0),
            'parts_total' => (int) ($contents['partsTotal'] ?? 0),
            'saving' => (int) ($contents['saving'] ?? 0),
        ];
    }
}
