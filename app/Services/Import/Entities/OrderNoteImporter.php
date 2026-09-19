<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\OrderNote;
use App\Services\Import\DateParser;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;

/**
 * The history of what was said and done on an order, matched on
 * `source_comment_id`.
 *
 * docs/FV-IMPORT-AT-VOLUME.md §11 names `order_notes` second among the tables
 * a clean full-volume import leaves empty: "The history of what was said and
 * done on an order. This application writes its own notes and would show these
 * beside them." It already does — Order::notes() is `latest()` and
 * AdminOrderController::show() renders the lot — so an imported note with its
 * real 2019 date files itself into the right place in that list without a
 * screen changing.
 *
 * `order_notes.source_comment_id` — nullable, UNIQUE — has been in the Phase 0
 * schema from the start and is named in
 * 2026_09_22_000000_add_import_external_ids as an external id that was already
 * present. No migration and no column are added here either.
 *
 * ============================================================================
 * `is_customer_note` IS THE COLUMN THAT MATTERS
 * ============================================================================
 *
 * WooCommerce keeps both kinds in `wp_comments` under comment_type
 * `order_note` and separates them with one meta key. The distinction is not
 * cosmetic: a customer note was EMAILED to the shopper and may be shown to
 * them again; an internal note is the shop talking to itself, and it routinely
 * carries a courier's phone number, a chargeback reference or a remark about
 * the customer. Defaulting the wrong way publishes the second kind.
 *
 * So the default here is FALSE — internal — which is the safe direction on a
 * column whose other value is "show this to the buyer", and it is the same
 * default the schema and PaymentLedger::note() already use.
 *
 * ============================================================================
 * WHAT IS NOT IMPORTED
 * ============================================================================
 *
 * `author_email`. The export carries it (WooCommerce stores the note author's
 * address on the comment) and this table has no column for it. That is the
 * right shape and it is not merely a gap: `order_notes` rows are rendered on
 * an admin screen and every column this schema carries is one more thing a
 * future endpoint can leak — CLAUDE.md records `reviews.author_email` and
 * `reviews.ip` as exactly that hazard. It is named in the discard channel with
 * its value so the owner approves the loss rather than never hearing about it.
 *
 * NO MAIL. Writing an `order_notes` row emails nobody: OrderMailObserver
 * listens to Order and Refund, and the customer-note email in this application
 * is sent by the controller that creates the note, not by the model. An
 * imported note is therefore silent by construction rather than by a guard,
 * which is worth stating because the refund half of this lane needed one.
 */
final class OrderNoteImporter extends EntityImporter
{
    public function name(): string
    {
        return 'order-notes';
    }

    public function conventionalFile(): string
    {
        return 'order_notes.csv';
    }

    /**
     * Notes the export supplied.
     *
     * On `source_comment_id`: this shop writes its own notes on every status
     * change and every settlement, so COUNT(*) on a store that has been live
     * for a week answers a different question entirely.
     */
    public function countImported(): ?int
    {
        return OrderNote::query()->whereNotNull('source_comment_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $commentId = $row->requireId('note_id', 'note_id', 'comment_id', 'id', 'comment_ID');
        $wcOrderId = $row->requireId('order_id', 'order_id', 'comment_post_id', 'wc_order_id');

        $orderId = $context->localId('orders', $wcOrderId);

        if ($orderId === null) {
            throw RowRejected::because(
                'order '.$wcOrderId.' is not in this database — import the orders first, or check whether that '
                .'order was rejected. `order_notes.order_id` is NOT NULL, so this note has nothing to attach to.'
            );
        }

        $content = $row->text('content', 'content', 'note', 'comment_content', 'note_content');

        if ($content === null) {
            throw RowRejected::because(
                'the note is empty. `order_notes.content` is NOT NULL and a blank line in an order\'s history '
                .'is worse than no line: it reads as something having been said that cannot be recovered.'
            );
        }

        $createdAt = $row->date('date_created', $context->timezone(), 'date_created', 'date', 'comment_date', 'created_at');

        if ($createdAt === null) {
            throw RowRejected::because(
                'date_created is empty. Order::notes() is ordered by it, so a note with no date would sit at '
                .'one end of the order\'s history regardless of when it was written.'
            );
        }

        $this->checkDeclaredTimezone($row, $context);

        $this->reportAuthorEmail($row, $context);

        $note = OrderNote::query()->where('source_comment_id', $commentId)->first() ?? new OrderNote;

        $outcome = $context->apply($note, [
            'source_comment_id' => $commentId,
            'order_id' => $orderId,
            'author' => $row->text('author', 'author', 'comment_author', 'added_by'),
            // FALSE is the default on purpose — see the class header. `bool()`
            // reads Woo's yes/no, 1/0 and true/false alike.
            'is_customer_note' => $row->bool(false, 'is_customer_note', 'customer_note', 'is_customer'),
            'content' => $content,
            // Explicit, both. A note stamped now() would file the whole of an
            // order's history under the day of the import, and would report as
            // `updated` on every later pass.
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $context->record($this->name(), $outcome);
    }

    /**
     * The author's email address, which this schema has no column for and
     * deliberately does not want one for. Named with its value so the loss is
     * approved rather than silent.
     */
    private function reportAuthorEmail(Row $row, ImportContext $context): void
    {
        $email = $row->text('author_email', 'comment_author_email');

        if ($email === null) {
            return;
        }

        $context->report->for($this->name())->discarded(
            'the note author\'s email address — `order_notes` has no column for it, and one more address on a '
            .'table an admin screen renders is one more thing an endpoint can leak',
            $row->line,
            $this->identify($row),
            'author_email',
            $email,
        );
    }

    /**
     * The same measured-offset check OrderImporter makes. A note four hours out
     * sorts into the wrong place in the history it exists to record.
     */
    private function checkDeclaredTimezone(Row $row, ImportContext $context): void
    {
        $disagreement = DateParser::disagreementWithGmt(
            $row->raw('date_created', 'date', 'comment_date', 'created_at'),
            $row->raw('date_created_gmt', 'comment_date_gmt', 'date_gmt'),
            'date_created',
            $context->timezone(),
        );

        if ($disagreement === null) {
            return;
        }

        [$declared, $gmt] = $disagreement;

        $context->report->for($this->name())->adjusted(
            'the export\'s own GMT column disagrees with --timezone='.$context->timezone().' -- every date '
            .'in this file is being read in the wrong zone. Re-run with the site timezone the export was '
            .'really written in.',
            $row->line,
            $this->identify($row),
            'date_created',
            $declared->toDateTimeString().'Z (reading it as '.$context->timezone().')',
            $gmt->toDateTimeString().'Z (what the export\'s GMT column says)',
        );
    }

    /** `note_id` first, so a rejection names the note and not its order. */
    public function identify(Row $row): string
    {
        $value = $row->raw('note_id');

        if ($value !== null && $value !== '') {
            return 'note_id='.$value;
        }

        return parent::identify($row);
    }
}
