<?php

declare(strict_types=1);

namespace App\Services\Invoices;

/**
 * What a bulk print asked for, checked before anything is loaded or allocated.
 *
 * ── WHY THIS IS A CLASS AND NOT A FORM REQUEST ──────────────────────────────
 *
 * The invoice route writes. InvoiceNumbers::allocate() mints a number out of a
 * sequence an accountant reconciles, and a number that is minted and then not
 * printed is a gap somebody has to explain. So the ORDER OF OPERATIONS is the
 * feature here, not the validation: every refusal this class can produce
 * happens before a single Order is loaded, which is the only way to be sure a
 * refused request allocated nothing.
 *
 * A Laravel validator would do the same job and would encourage the opposite
 * shape — validate, then fetch, then decide, with the cap and the "does this
 * order exist" check interleaved. Here `from()` throws or returns a list of
 * ids, and the controller may not touch the database until it has one.
 *
 * ── THE CAP, AND WHERE 100 COMES FROM ───────────────────────────────────────
 *
 * IT IS NOT A MEMORY LIMIT, AND THE MEASUREMENT IS WHY IT IS WORTH SAYING SO.
 * A hundred orders of ten lines each, rendered as packing slips in one warm
 * request, cost 127 ms, about 10 MB of PHP memory above the process baseline
 * and 1.59 MB of HTML (docs/GC-BULK-PRINTING.md records the whole table). The
 * server would carry two hundred without noticing, and the query string at a
 * hundred ids is around 700 characters — nowhere near any limit.
 *
 * SO THE CAP IS THE BLAST RADIUS, not the cost. On an invoice run one click
 * mints up to this many numbers out of a sequence an accountant reconciles, and
 * that cannot be undone from any screen this application has. A hundred is
 * roughly twice the biggest batch this shop packs in a day, which leaves the
 * real work unaffected while halving what a mis-click can do.
 *
 * It is deliberately HALF OrdersApiController::BULK_MAX (200), and they should
 * not be kept equal for tidiness: that one caps a single UPDATE over a list of
 * ids, this one caps an irreversible allocation and a hundred sheets of paper.
 *
 * The cap is also what makes the invoice path safe to reason about: a request
 * over the cap is refused with nothing loaded, so it cannot have allocated a
 * hundred invoice numbers before discovering it was too big.
 *
 * Raising it is a one-line change and a re-run of the measurement. Whether the
 * owner wants it raised is in docs/GC-BULK-PRINTING.md.
 */
final class BulkDocumentSelection
{
    /**
     * The most orders one bulk document may carry. See the class header.
     */
    public const MAX = 100;

    /**
     * The four documents, keyed by the value that appears in the URL.
     *
     * The key is a URL word and the value is the Blade partial that draws one
     * order's sheet — the SAME partial the single-order view includes, so the
     * batch of twenty a packer prints and the one sheet an operator reprints
     * from the order screen cannot drift apart.
     *
     * `dispatch-label` rather than `shipping-label`: the button, the document's
     * own title and InterfaceStrings all say "Dispatch label", and the single
     * route is called shipping-label only because that is what Lane AE named
     * the view before Lane EK settled the wording. A new URL does not have to
     * inherit the older name, and this one is the one the operator reads.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'invoice' => 'invoices.partials.sheet-invoice',
        'packing-slip' => 'invoices.partials.sheet-packing-slip',
        'delivery-note' => 'invoices.partials.sheet-delivery-note',
        'dispatch-label' => 'invoices.partials.sheet-dispatch-label',
    ];

    /**
     * @param  list<int>  $ids  Unique, in the order they were asked for.
     */
    private function __construct(
        public readonly string $type,
        public readonly array $ids,
    ) {}

    /**
     * What a batch of this type calls itself, as a LITERAL key per type.
     *
     * Written out rather than built with `'invoice.bulk.title.' . $type`,
     * because a key assembled at runtime is a key no guard can see:
     * StorefrontStringsAreKeyedTest reads every __('…') literal out of the
     * templates and fails when one names a string InterfaceStrings does not
     * define. A concatenated key slips past that check and shows the operator
     * the key itself the day somebody renames one. BulkDocumentsTest asserts
     * all four of these resolve.
     *
     * @var array<string, string>
     */
    public const TITLE_KEYS = [
        'invoice' => 'invoice.bulk.title.invoice',
        'packing-slip' => 'invoice.bulk.title.packing-slip',
        'delivery-note' => 'invoice.bulk.title.delivery-note',
        'dispatch-label' => 'invoice.bulk.title.dispatch-label',
    ];

    /** The translation key for this batch's own name. */
    public function titleKey(): string
    {
        return self::TITLE_KEYS[$this->type];
    }

    /** The Blade partial that draws one order's sheet for this type. */
    public function partial(): string
    {
        return self::TYPES[$this->type];
    }

    /** True for the one type that is A6 label stock rather than an A4 sheet. */
    public function isLabel(): bool
    {
        return $this->type === 'dispatch-label';
    }

    /**
     * True for the one type that mints an invoice number.
     *
     * The other three print a picking list, a handover record and an address on
     * a box. All three are printed for orders that may never be invoiced, and
     * printing one must not consume a number out of the sequence — the same
     * rule InvoiceController states for the single documents, restated here
     * because the bulk path is where breaking it would be cheapest.
     */
    public function allocatesInvoiceNumbers(): bool
    {
        return $this->type === 'invoice';
    }

    /**
     * True for the types the CUSTOMER reads, which is not the same question.
     *
     * ── WHY THIS IS NOT allocatesInvoiceNumbers() ───────────────────────────
     *
     * BulkDocumentController::sheets() used that method to decide whether to
     * wrap a sheet in OrderLocale::render(), and while the invoice was the only
     * customer-facing document of the four, the two questions had the same
     * answer. They are different questions and they have now come apart: the
     * delivery note goes IN THE PARCEL and follows the order's language, and it
     * still must not mint an invoice number.
     *
     * Leaving one method to answer both would have made the batch of twenty
     * disagree with the single sheet — the drift
     * resources/views/invoices/partials/sheet-delivery-note.blade.php exists to
     * prevent, and the kind that fails nothing: the bulk run would have printed
     * the customer's product names, because the partial is shared, inside
     * English headings, because the wrapper is not.
     *
     * A picking list and an address on a box are read by this shop and by a
     * courier. They stay in the operator's language.
     */
    public function readsInCustomerLanguage(): bool
    {
        return $this->type === 'invoice' || $this->type === 'delivery-note';
    }

    /**
     * Read a request's `type` and `ids`, or refuse.
     *
     * NOTHING IS LOADED HERE. Every one of the four refusals below is decided
     * from two strings, so a refused bulk print has touched no row and minted
     * no number.
     *
     * @throws BulkDocumentRefused
     */
    public static function from(mixed $type, mixed $ids): self
    {
        $type = is_string($type) ? trim($type) : '';

        if (! array_key_exists($type, self::TYPES)) {
            throw new BulkDocumentRefused(
                'That is not one of the four documents.',
                'Ask for one of: ' . implode(', ', array_keys(self::TYPES)) . '.',
            );
        }

        $parsed = self::parseIds($ids);

        if ($parsed === []) {
            throw new BulkDocumentRefused(
                'No orders were selected.',
                'Tick the orders you want on the Orders screen, then choose a document to print.',
            );
        }

        if (count($parsed) > self::MAX) {
            throw new BulkDocumentRefused(
                'That is ' . count($parsed) . ' orders, and one document holds at most ' . self::MAX . '.',
                'Print them in batches of ' . self::MAX . ' or fewer. Nothing was printed and, on an '
                . 'invoice run, no invoice numbers were issued.',
            );
        }

        return new self($type, $parsed);
    }

    /**
     * The ids in `1,2,3` or `ids[]=1&ids[]=2` form: unique, positive, ordered
     * as asked.
     *
     * ASKED-FOR ORDER, NOT SORTED. The operator ticked rows on a screen they
     * had sorted themselves — newest first, by value, by status — and the pile
     * of paper coming out of the printer should match the pile of parcels on
     * the bench. Sorting here would silently reorder it. (The admin console
     * sends the ids as the keys of a plain object, which JavaScript iterates in
     * ascending numeric order, so today the two happen to agree; that is a fact
     * about one caller and not a reason to hard-code it for the next one.)
     *
     * DE-DUPLICATED, because the same id twice is the same parcel twice: a
     * second sheet for one order is waste at best, and on an invoice run it is
     * a second sheet carrying the same invoice number, which is the one thing
     * an invoice may never be.
     *
     * @return list<int>
     */
    private static function parseIds(mixed $ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        if (! is_array($ids)) {
            return [];
        }

        $out = [];

        foreach ($ids as $raw) {
            if (is_int($raw)) {
                $value = $raw;
            } elseif (is_string($raw) && preg_match('/^\s*\d+\s*$/', $raw) === 1) {
                $value = (int) trim($raw);
            } else {
                // Anything that is not a plain positive integer is dropped
                // rather than 404'ing the whole batch: a stray empty segment
                // from a trailing comma must not cost the operator the other
                // nineteen sheets.
                continue;
            }

            if ($value <= 0) {
                continue;
            }

            $out[$value] = true;
        }

        return array_map('intval', array_keys($out));
    }
}
