<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSetItem;

/**
 * Does selling a Set take one of each member off the shelf? (Lane SP)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IT SHIPS SWITCHED TO WHAT THE SHOP DOES TODAY, SO APPLYING THE PACKAGE
 * MOVES NOTHING. THE ANSWER IS ONE SWITCH AWAY WHICHEVER WAY THE OWNER GOES.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE QUESTION, AND WHY IT IS A SWITCH RATHER THAN A DECISION ────────────
 *
 * Lane SET put it in its report and it has been asked of the owner twice
 * without an answer, because there is a real argument either way and it is
 * commercial rather than technical:
 *
 *   A set is stock of its own. The shop buys thirty gift boxes pre-made from
 *   the distributor, counts thirty, and sells thirty. Its members' own shelves
 *   are a different pile of jars and nothing to do with it.
 *
 *   A set is three jars in a box. The shop makes a set up when one is ordered,
 *   out of the same stock it sells singly, and a set that sells out its toner
 *   has sold out the toner.
 *
 * Both are true of real shops and only the owner knows which is true of his.
 * So the rule is built, tested, and DEFAULTED TO THE FIRST -- which is not a
 * preference, it is literally what this application did before this class
 * existed: StockClaim claimed the set's own row and nothing else.
 *
 * ── WHERE THE SWITCH IS ────────────────────────────────────────────────────
 *
 *     Catalog → Sets → Stock · When a set is sold
 *
 * Two options, and it stores one of its own options or the default -- never
 * what arrived (CLAUDE.md rule 5). See Admin\SetStockApiController.
 *
 * ── WHAT `members` ACTUALLY DOES, AND WHAT IT DELIBERATELY DOES NOT ────────
 *
 * It EXPANDS the claim list: a line for one Glow Set becomes that same line
 * PLUS one line per member, each for the member's own quantity times the
 * number of sets. It does not replace the set's line, and that is deliberate:
 * the set is still a `products` row with its own `manage_stock` and its own
 * `stock_status`, and a set the owner has marked sold out by hand must stay
 * sold out whichever way this switch is set. A set created on Catalog → Sets
 * has `manage_stock` false (the schema's default), so on a normal shop that
 * line costs nothing and the members are the whole of the effect.
 *
 * THE EXPANSION HAPPENS BEFORE StockClaim::perShelf(), which is what makes a
 * basket holding BOTH the Glow Set and the toner inside it come off the toner's
 * shelf ONCE, for the total -- perShelf() sums by shelf. Expanding afterwards
 * would take the same jar twice and could refuse a sale the shop could fill.
 *
 * ONE LEVEL, NEVER RECURSION. A member that is itself a set is expanded no
 * further. A set of sets is not a thing Catalog → Sets can build today, and a
 * routine that followed the pivot as far as it went would be a routine that
 * hangs on the day somebody puts two sets inside each other.
 *
 * ── AND IT COSTS NOTHING WHEN IT IS OFF ────────────────────────────────────
 *
 * mode() answers from the settings table, which every checkout has already
 * read many times over by the time it gets here, and the default answer returns
 * the caller's own array untouched: NO QUERY, no change to any budget, and no
 * difference at all to the bytes of any order this shop places today.
 * SetStockRuleTest measures both halves.
 */
final class StockSetRule
{
    /** The settings row. Absent means MODE_SET, which is today's behaviour. */
    public const KEY = 'set_stock_mode';

    /**
     * The set carries its own stock. THE DEFAULT, and what this application
     * did before this class existed.
     */
    public const MODE_SET = 'set';

    /** Selling a set also takes each member off its own shelf. */
    public const MODE_MEMBERS = 'members';

    /** The only two values that may ever be stored. */
    public const MODES = [self::MODE_SET, self::MODE_MEMBERS];

    public function __construct(private SettingsService $settings) {}

    /**
     * Which rule is live.
     *
     * ANYTHING THAT IS NOT ONE OF THE TWO IS THE DEFAULT, including a row
     * written by hand, by an import, or by a future screen that has not been
     * written yet. A stock rule that did something unexpected because a string
     * in a table was unexpected is the worst shape this could take.
     */
    public function mode(): string
    {
        $stored = (string) $this->settings->get(self::KEY, self::MODE_SET);

        return in_array($stored, self::MODES, true) ? $stored : self::MODE_SET;
    }

    public function decrementsMembers(): bool
    {
        return $this->mode() === self::MODE_MEMBERS;
    }

    /**
     * Turn a claim list into the claim list this rule asks for.
     *
     * @param  list<array{product_id:int,variant_id:?int,quantity:int,label:string}>  $lines
     * @return list<array{product_id:int,variant_id:?int,quantity:int,label:string}>
     */
    public function expand(array $lines): array
    {
        if ($lines === [] || ! $this->decrementsMembers()) {
            return $lines;
        }

        $setIds = $this->setIdsAmong($lines);

        if ($setIds === []) {
            return $lines;
        }

        $membersBySet = $this->membersOf($setIds);

        if ($membersBySet === []) {
            return $lines;
        }

        $out = $lines;

        foreach ($lines as $line) {
            $setId = (int) ($line['product_id'] ?? 0);

            foreach ($membersBySet[$setId] ?? [] as $row) {
                /*
                 * `quantity` MULTIPLIES. Two Glow Sets, each holding two
                 * toners, is four toners off the toner's shelf -- and both
                 * factors are integers, so there is no rounding here to get
                 * wrong. max(1, ...) mirrors SetContents::fromProduct(), which
                 * reads a membership row of quantity 0 as one item rather than
                 * as a member that is not there.
                 */
                $out[] = [
                    'product_id' => (int) $row->member_product_id,
                    'variant_id' => $row->member_variant_id === null ? null : (int) $row->member_variant_id,
                    'quantity' => max(1, (int) $row->quantity) * max(0, (int) ($line['quantity'] ?? 0)),
                    /*
                     * THE MEMBER'S OWN NAME, and the set's beside it. A shopper
                     * refused at checkout is told "Heartleaf Toner (in Glow
                     * Starter Set) is sold out" -- the name on the basket row
                     * they can see is the SET's, so naming only the member
                     * would send them looking for a line that is not there.
                     */
                    'label' => $this->memberLabel($row, (string) ($line['label'] ?? 'Item')),
                ];
            }
        }

        return $out;
    }

    /**
     * Which of these lines are sets.
     *
     * ONE QUERY, over the distinct product ids on the lines, selecting `type`
     * so Product::isSet() can answer without a second look -- the same shape
     * Api\ProductController's index uses. A basket with no set in it costs this
     * one statement and returns here.
     *
     * @return list<int>
     */
    private function setIdsAmong(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', array_keys($ids))
            ->where('type', '=', 'set')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The membership rows of every set on the page, batched.
     *
     * ONE QUERY FOR ALL OF THEM, with the member's name eager-loaded in a
     * second -- never one per set and never one per member, which is the N+1
     * StorefrontQueryBudgetTest is a budget against and which would be worst
     * exactly here, inside the transaction that places the order.
     *
     * @param  list<int>  $setIds
     * @return array<int, list<ProductSetItem>>
     */
    private function membersOf(array $setIds): array
    {
        $rows = ProductSetItem::query()
            ->whereIn('set_product_id', $setIds)
            ->with('member:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            /*
             * A membership row whose product has been deleted is a hole, not a
             * member -- the same reading SetContents::fromProduct() gives it.
             * Claiming stock against a null product id would throw
             * StockUnavailable and refuse a sale over a row nobody can see.
             */
            if ($row->member_product_id === null) {
                continue;
            }

            $out[(int) $row->set_product_id][] = $row;
        }

        return $out;
    }

    private function memberLabel(ProductSetItem $row, string $setLabel): string
    {
        $name = trim((string) ($row->member?->name ?? ''));

        if ($name === '') {
            $name = 'Item';
        }

        return $setLabel === '' ? $name : $name.' (in '.$setLabel.')';
    }
}
