# What language an order line is in, and why there are two of them

Lane F, round 2. This settles the item `docs/fp-storefront-reads-translations.md`
left open as "Order line names are still snapshots of the English", so that
nobody has to derive it again.

## The question

`Store\CheckoutController` writes `'name' => $p?->name` onto every `order_items`
row — the English column, always, whatever language the shopper was in. Is a
snapshot right, or should an Arabic customer's order documents read in Arabic?

## The answer: both, and the shop had already decided which document gets which

**The snapshot mechanism is right and is untouched.**
`Services\Invoices\InvoiceDocument` states the rule and it is correct:

> the lines are the snapshot, never the live product. `product_id` is nullable
> and Product soft-deletes, so the catalogue row behind a line may have been
> renamed, repriced or removed. An invoice is a record of a transaction that
> happened; reprinting today's catalogue onto it makes it a record of nothing.

Three things follow, and each one on its own is enough to rule out translating
at document time:

1. It would make a historical record **mutate**. An order placed today would
   print a different product name next month if somebody edited the Arabic
   translation — and a refund dispute is exactly when that matters.
2. It is **undefined for a deleted product**, which is the case the snapshot
   exists for. Those lines would stay English while their neighbours changed,
   so a single document would be in two languages.
3. It costs a **query on every document, every email and the bulk printer**.

**But the language the snapshot captured was wrong for one of its two readers.**
This shop had already split its order documents in two, deliberately, and
`resources/views/invoices/document.blade.php` says so in as many words:

| document | wrapped in `OrderLocale::render()`? | language |
| --- | :---: | --- |
| the invoice (`Admin\InvoiceController`) | **yes** | the customer's |
| every order email (`Services\Mail\OrderMailer`) | **yes** | the customer's |
| packing slip | no | the operator's |
| delivery note | no | the operator's |
| bulk print (`Admin\BulkDocumentController`) | per order, for the invoice only | the operator's |

That split is right. `App\Support\Locale` says why the operator's half is
English: "The admin is deliberately NOT localised. It is one operator". And the
person who picks and packs the parcel reads the packing slip.

So an Arabic order's invoice had Arabic headings, Arabic labels and Arabic
totals — and English line names. The customer had seen the Arabic name on the
card, in the basket drawer, on the cart page and in the checkout summary (all
five read `t()` since Lane FP), pressed Pay, and then got paperwork calling it
something else.

**One column cannot answer two readers.** Whichever language it held, one of the
two documents would be wrong — and translating `name` in place would have put
Arabic product names on the operator's packing slip.

## What shipped

`order_items.name_localised`, nullable, written once at creation beside `name`,
in the language `orders.locale` records.

- `name` keeps its exact current meaning and value. Every historical row is
  untouched, and every operator document still reads it.
- `name_localised` is read by the invoice sheet, both invoice emails, and the
  customer's own order page under `/my-account`.
- Written by an `OrderItem::creating` hook in `App\Support\OrderLocale`, beside
  the `Order::creating` hook that is there for the same stated reason: six
  places in this application create order lines, and a rule applied in five of
  them is not a rule.

### It is inert until there is a second language

The hook returns on `Locale::enabledCodes()` before it looks up the order, the
product or the translation. With Arabic off — how the shop ships — creating an
order line issues **one** statement, the insert, even on an order row whose
`locale` says `ar`; that is a test, not a claim, and dropping the guard turns it
red at four.

The column is nullable with no backfill, so every existing row keeps a NULL and
every reader falls back to `name`.

### Null means one thing

`t()` already falls back to English, so storing its answer for an untranslated
product would put a second copy of `name` on every line. The column stays NULL
instead, and the reader's `?: $item->name` is the single place that decision
lives. NULL therefore means exactly: *this line has no name of its own in the
customer's language.*

### One thing deliberately left English

`resources/views/store/account/order-detail.blade.php` seeds its placeholder
tile colour with `Gradient::for($item->brand . $item->name)`. That stays the
English name, for the reason Lane FP gives for every other gradient seed: the
colour is a hash of the string it is given, so translating the seed would
repaint a shopper's order history between languages for a value nobody reads.

## What was NOT changed, and would be a separate decision

The **delivery note** goes in the parcel and is arguably the customer's, not the
operator's — but this shop renders it unwrapped today, in English, and changing
that is a change to which language a document is in rather than to what a line
is called. It is left exactly as it was. If the owner wants it in the
customer's language, it is one `OrderLocale::render()` in
`Admin\InvoiceController` plus pointing `sheet-delivery-note.blade.php` at
`nameForCustomer`, and both halves have to move together.
