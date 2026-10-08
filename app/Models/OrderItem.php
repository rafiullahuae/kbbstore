<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PlainText;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        /*
         * `set_contents` (Lane SET) is the second JSON snapshot on this row and
         * it is here for the same reason as the first: a set's contents change,
         * and an order sold last month must still print what was in the box.
         * App\Support\SetContents is the only thing that writes it and the only
         * thing that reads it. NULL on every ordinary line.
         */
        return ['variant_attributes' => 'array', 'set_contents' => 'array', 'quantity' => 'int', 'unit_price' => 'int', 'subtotal' => 'int', 'total' => 'int'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /*
     * ── THE LINE'S NAME, READ AS TEXT (Lane AMP) ───────────────────────────────
     *
     * An order imported from WooCommerce carries `order_item_name` as Woo stored
     * it, HTML-encoded: "SKIN&amp;LAB - Vitamin C Brightening Serum". Every
     * screen that prints it escapes it, so the account page, the invoice and the
     * emails read "SKIN&amp;LAB".
     *
     * Decoded HERE, at read time, and NOT in the table: an order line is a
     * historical snapshot, and the history rows stay byte for byte as they were
     * imported. A getter is the one place every reader passes through -- the
     * account page, OrderEmailPresenter, InvoiceDocument, the owner app, the
     * admin order screen -- and the stored attribute is untouched, so nothing is
     * dirtied and a save never writes the decoded form back. A line written by
     * this shop's own checkout has no reference in it and costs one
     * str_contains(). Still escaped wherever it is printed.
     */
    protected function name(): Attribute
    {
        return Attribute::get(static fn (?string $value): ?string => PlainText::decode($value));
    }

    protected function brand(): Attribute
    {
        return Attribute::get(static fn (?string $value): ?string => PlainText::decode($value));
    }

    protected function nameLocalised(): Attribute
    {
        return Attribute::get(static fn (?string $value): ?string => PlainText::decode($value));
    }

}
