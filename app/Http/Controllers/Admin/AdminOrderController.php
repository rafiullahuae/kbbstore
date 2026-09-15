<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\ManualOrderBuilder;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\AggregatesQueries;
use App\Support\Csv;
use App\Support\Fils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Store -> New Order: placing an order on a customer's behalf.
 *
 * The shop takes orders on WhatsApp and in Instagram DMs as well as through
 * the website, and there was no way to get those into the system at all. This
 * is the screen's back end.
 *
 * Every route here is registered inside the `auth:admin` group in
 * routes/web.php (see routes/manual-orders-admin.php for the require and why
 * it goes where it goes). Nothing here does its own authorisation, so it MUST
 * NOT be registered anywhere else — /api/* is unauthenticated, and this
 * returns customer emails, phone numbers and street addresses.
 *
 * No pricing happens in this file. It validates, hands the payload to
 * ManualOrderBuilder — which prices through the very services the storefront
 * checkout uses — and shapes the answer as JSON.
 */
class AdminOrderController extends Controller
{
    /**
     * Counting a builder that is also used to fetch a page of rows is how this
     * repo shipped MySQL error 1140 to production twice.
     */
    use AggregatesQueries;

    /** How many rows a search returns at once. */
    private const PAGE = 20;

    /**
     * The LIKE escape character.
     *
     * Without it, a customer searching for "50% off" or an operator typing an
     * underscore gets every row back: % and _ are wildcards inside LIKE, and a
     * bound parameter does not escape them — binding protects against SQL
     * injection, not against pattern injection.
     */
    private const LIKE_ESCAPE = '!';

    public function __construct(
        private ManualOrderBuilder $builder,
        private ShippingService $shipping,
        private SettingsService $settings,
    ) {}

    /* ===================================================================
     | GET /admin-api/manual-orders/bootstrap
     |=================================================================== */

    /**
     * Everything the form needs to render: the real vocabularies, not invented
     * ones.
     *
     * A sibling lane found AdminController::updateProduct validating status as
     * in:active,draft,archived when the products column carries
     * publish|draft|private — a save through it hid the product from the whole
     * storefront. So every list below is read from the schema or from the
     * table that owns it, and the same constants feed both this endpoint and
     * the validator in store().
     */
    public function bootstrap(): JsonResponse
    {
        $countries = $this->shipping->coveredCountries();

        if ($countries === []) {
            // No zone configured at all — the same floor the storefront
            // checkout falls back to, so the two never offer different lists.
            $countries = ['AE' => 'United Arab Emirates'];
        }

        return response()->json([
            'statuses' => ManualOrderBuilder::STATUSES,
            'default_status' => ManualOrderBuilder::DEFAULT_STATUS,
            'channels' => ManualOrderBuilder::CHANNELS,
            'payment_methods' => $this->builder->paymentMethods(),
            'countries' => $countries,
            'default_country' => (string) $this->settings->get('store_country', 'AE'),
            // Free text on the live site, offered as suggestions only — the
            // addresses table does not constrain state.
            'emirates' => [
                'Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman',
                'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah',
            ],
            'currency' => 'AED',
            'cod_fee_fils' => (int) $this->settings->get('cod_fee', 0),
            'email' => $this->confirmationEmailCapability(),
        ]);
    }

    /* ===================================================================
     | GET /admin-api/manual-orders/customers?q=
     |=================================================================== */

    /** Search the customer list by name, email or phone. */
    public function customers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $term = trim((string) ($data['q'] ?? ''));
        $page = (int) ($data['page'] ?? 1);

        // Customer soft-deletes, so the model's global scope already excludes
        // removed rows; nothing extra is needed here.
        $query = Customer::query();

        if ($term !== '') {
            $like = '%' . $this->escapeLike($term) . '%';

            $query->where(function ($q) use ($like) {
                foreach (['name', 'first_name', 'last_name', 'email', 'phone'] as $column) {
                    $q->orWhereRaw(
                        $column . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'",
                        [$like],
                    );
                }
            });
        }

        // The total is taken through the trait, from a copy of the builder with
        // its select list, ordering and paging stripped. Counting $query as it
        // stands — after orderByDesc and limit below — is MySQL error 1140.
        $total = $this->aggregateCount($query);

        $rows = (clone $query)
            ->orderByDesc('id')
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
            'customers' => $rows->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->displayName(),
                'email' => $c->email,
                'phone' => $c->phone,
                'orders_count' => (int) $c->orders_count,
                'total_spent_fils' => (int) $c->total_spent,
                'address' => $this->addressOf($c),
            ])->values(),
        ]);
    }

    /* ===================================================================
     | GET /admin-api/manual-orders/products?q=
     |=================================================================== */

    /** Search the real catalogue by name or SKU. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $term = trim((string) ($data['q'] ?? ''));
        $page = (int) ($data['page'] ?? 1);

        // Everything sellable, including products hidden from the catalogue:
        // staff take orders for things that are not on the shop grid. Drafts
        // are excluded — 'publish' and 'private' are the two live values in
        // this schema's products.status (publish | draft | private).
        $query = Product::query()->whereIn('status', ['publish', 'private']);

        if ($term !== '') {
            $like = '%' . $this->escapeLike($term) . '%';

            $query->where(function ($q) use ($like) {
                $q->orWhereRaw("name LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like])
                    ->orWhereRaw("sku LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'", [$like]);
            });
        }

        $total = $this->aggregateCount($query);

        $rows = (clone $query)
            ->with(['brand:id,name', 'variants'])
            ->orderBy('name')
            ->forPage($page, self::PAGE)
            ->get();

        return response()->json([
            'total' => $total,
            'page' => $page,
            'per_page' => self::PAGE,
            'products' => $rows->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'brand' => $p->brand?->name,
                'image' => $p->image,
                // effectivePrice() honours the sale window. The quantity-bundle
                // tier is applied later, by CartService, once a quantity exists.
                'price_fils' => $p->effectivePrice(),
                'stock' => $p->stock,
                'stock_status' => $p->stock_status,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'price_fils' => $v->effectivePrice(),
                    'stock_status' => $v->stock_status,
                ])->values(),
            ])->values(),
        ]);
    }

    /* ===================================================================
     | POST /admin-api/manual-orders/quote
     |=================================================================== */

    /**
     * Price a basket without saving anything.
     *
     * Fired whenever a line, quantity, destination or coupon changes, so the
     * operator sees the same total the customer will be charged before they
     * commit to it.
     */
    public function quote(Request $request): JsonResponse
    {
        $input = $this->validatedPayload($request, forCreate: false);

        $priced = $this->builder->quote($input);

        if (! $priced['ok']) {
            return response()->json(['ok' => false, 'error' => $priced['error']], 422);
        }

        return response()->json(['ok' => true] + $this->totalsPayload($priced));
    }

    /* ===================================================================
     | POST /admin-api/manual-orders
     |=================================================================== */

    /** Create the order. */
    public function store(Request $request): JsonResponse
    {
        $input = $this->validatedPayload($request, forCreate: true);

        $result = $this->builder->create(
            $input,
            $request->user('admin')?->name ?: $request->user('admin')?->email,
        );

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 422);
        }

        /** @var Order $order */
        $order = $result['order'];

        return response()->json([
            'ok' => true,
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'origin' => $order->origin,
                'email' => $order->email,
                'phone' => $order->phone,
                'currency' => $order->currency,
                'subtotal_fils' => (int) $order->subtotal,
                'discount_fils' => (int) $order->discount_total,
                'shipping_fils' => (int) $order->shipping_total,
                'fee_fils' => (int) $order->fee_total,
                'total_fils' => (int) $order->total,
                'coupon_code' => $order->coupon_code,
                'shipping_method' => $order->shipping_method,
                'payment_method' => $order->payment_method,
                'payment_method_title' => $order->payment_method_title,
                'shipping_address' => $order->shipping_address,
                'items' => $order->items->map(fn ($i) => [
                    'name' => $i->name,
                    'sku' => $i->sku,
                    'quantity' => (int) $i->quantity,
                    'unit_price_fils' => (int) $i->unit_price,
                    'line_total_fils' => (int) $i->total,
                ])->values(),
            ],
            // Said plainly rather than left to be discovered: see
            // confirmationEmailCapability() and the stock note below.
            'email' => $this->emailOutcome((bool) ($input['send_confirmation'] ?? false)),
            'stock' => [
                'adjusted' => false,
                'reason' => 'No order path in this build moves stock — a website '
                    . 'order does not decrement it either. Adjust it in Catalog → Inventory.',
            ],
        ], 201);
    }

    /* ===================================================================
     | GET /admin-api/manual-orders/{order}/packing-list.csv
     |=================================================================== */

    /**
     * The packing list, for the team who pack from the order record.
     *
     * Every cell goes through Csv::cell(), which prefixes =, +, -, @, tab and
     * CR with an apostrophe. A product name is merchant-supplied text and a
     * customer note is customer-supplied text; either can begin with = and
     * become a live formula the moment the file is opened in Excel.
     */
    public function packingList(int $order): Response
    {
        $record = Order::with('items')->find($order);

        if (! $record) {
            return response('Not found', 404);
        }

        $address = (array) ($record->shipping_address ?? []);

        $rows = [
            ['Order', 'Placed', 'Channel', 'Status', 'Customer', 'Email', 'Phone', 'Address', 'Payment'],
            [
                $record->order_number,
                (string) $record->created_at,
                (string) $record->origin,
                (string) $record->status,
                trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
                (string) $record->email,
                (string) ($address['phone'] ?? $record->phone ?? ''),
                implode(', ', array_filter([
                    $address['line1'] ?? null,
                    $address['city'] ?? null,
                    $address['state'] ?? null,
                    $address['country'] ?? null,
                ])),
                (string) ($record->payment_method_title ?: $record->payment_method),
            ],
            [],
            ['SKU', 'Item', 'Qty', 'Unit (AED)', 'Line (AED)'],
        ];

        foreach ($record->items as $item) {
            $rows[] = [
                (string) $item->sku,
                (string) $item->name,
                (int) $item->quantity,
                Fils::toDecimalString((int) $item->unit_price),
                Fils::toDecimalString((int) $item->total),
            ];
        }

        $rows[] = [];
        $rows[] = ['', '', '', 'Subtotal', Fils::toDecimalString((int) $record->subtotal)];
        $rows[] = ['', '', '', 'Discount', Fils::toDecimalString(-(int) $record->discount_total)];
        $rows[] = ['', '', '', 'Delivery', Fils::toDecimalString((int) $record->shipping_total)];
        $rows[] = ['', '', '', 'Fee', Fils::toDecimalString((int) $record->fee_total)];
        $rows[] = ['', '', '', 'Total', Fils::toDecimalString((int) $record->total)];

        return response(Csv::document($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="packing-' . $record->order_number . '.csv"',
        ]);
    }

    /* ===================================================================
     | Validation
     |=================================================================== */

    /**
     * One validator for quote() and store(), so the two can never disagree
     * about what a valid order is — a quote that prices something the create
     * call then rejects is the worst possible version of this screen.
     */
    private function validatedPayload(Request $request, bool $forCreate): array
    {
        $paymentIds = $this->builder->paymentMethodIds();

        $rules = [
            // Either an existing customer, or enough to make one. The
            // required_without pair is what enforces "one or the other".
            'customer_id' => ['nullable', 'integer', 'required_without:new_customer', 'exists:customers,id'],
            'new_customer' => ['nullable', 'array', 'required_without:customer_id'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:120'],
            'new_customer.email' => ['required_with:new_customer', 'email', 'max:160'],
            'new_customer.phone' => ['nullable', 'string', 'max:40'],

            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            // 99 is CartService's own ceiling; asking for more silently becomes
            // 99 there, so it is refused here instead of quietly changed.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],

            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.city' => ['required', 'string', 'max:120'],
            // Free text on the live site, not a fixed list — the addresses
            // table does not constrain it either.
            'address.state' => ['required', 'string', 'max:120'],
            'address.country' => ['required', 'string', 'size:2'],
            'address.phone' => ['nullable', 'string', 'max:40'],

            'coupon_code' => ['nullable', 'string', 'max:60'],
            'shipping_method_id' => ['nullable', 'integer'],
            // Typed by the operator in AED. Parsed digit-by-digit below; the
            // rule only checks it is a shape Fils::parse can hold exactly.
            'shipping_override' => ['nullable', 'string', 'max:20', function (string $attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! Fils::isValid($value)) {
                    $fail('Enter the delivery charge as a plain amount, with at most two decimals.');
                }
            }],

            // payment_providers.id is the vocabulary. Nothing invented.
            'payment_method' => ['required', 'string', Rule::in($paymentIds)],
            'channel' => ['nullable', 'string', Rule::in(ManualOrderBuilder::CHANNELS)],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'whatsapp_optin' => ['nullable', 'boolean'],
            'send_confirmation' => ['nullable', 'boolean'],
        ];

        if ($forCreate) {
            // orders.status is a free-form string so imported WooCommerce
            // statuses survive; the console's working vocabulary is these
            // seven, and AdminController::updateOrderStatus accepts exactly
            // the same set. An order created with anything else would be one
            // the Orders screen could never edit again.
            $rules['status'] = ['required', 'string', Rule::in(ManualOrderBuilder::STATUSES)];
        } else {
            $rules['status'] = ['nullable', 'string', Rule::in(ManualOrderBuilder::STATUSES)];
        }

        $data = $request->validate($rules);

        // The one operator-typed money field in this screen. Fils::parse reads
        // the string one character at a time and never multiplies by 100 —
        // (int) (1.15 * 100) is 114, and a one-fil error on a delivery charge
        // is exactly the kind nobody spots until it is on an invoice.
        $override = $data['shipping_override'] ?? null;
        $data['shipping_override_fils'] = ($override === null || $override === '')
            ? null
            : Fils::parse((string) $override);

        unset($data['shipping_override']);

        return $data;
    }

    /* ===================================================================
     | Helpers
     |=================================================================== */

    /**
     * Escape the LIKE wildcards in an operator's search term.
     *
     * The escape character itself has to go first, or "!%" would become "!!%"
     * the wrong way round and stop escaping anything.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term,
        );
    }

    /** The shape the screen renders totals from. All amounts are fils. */
    private function totalsPayload(array $priced): array
    {
        $totals = $priced['totals'];

        return [
            'lines' => $priced['lines'],
            'rates' => $priced['rates'],
            'chosen_rate_id' => $priced['chosen_rate']['id'] ?? null,
            'totals' => [
                'subtotal_fils' => (int) $totals['subtotal'],
                'discount_fils' => (int) $totals['discount'],
                'shipping_fils' => (int) $totals['shipping'],
                'fee_fils' => (int) $priced['fee'],
                'total_fils' => (int) $priced['grand_total'],
                'coupon_code' => $totals['coupon_code'],
                'item_count' => (int) $totals['item_count'],
                'free_shipping_threshold_fils' => $totals['free_shipping_threshold'],
                'free_shipping_remaining_fils' => $totals['free_shipping_remaining'],
                // Display only — never added to the total (D-64).
                'vat' => $totals['vat'],
            ],
        ];
    }

    /**
     * Whether a confirmation email can be sent at all.
     *
     * There is no mail in this build: no app/Mail, no Mailable, not one
     * Mail:: call anywhere in app/. The checkbox on the form is therefore
     * rendered disabled with this reason shown beside it, rather than being a
     * control that silently does nothing — an operator ticking "email the
     * customer" and no email arriving is worse than no checkbox at all.
     *
     * The capability check is a class_exists on the Mailable this would send,
     * so the day one is added the checkbox becomes live without this file
     * changing.
     */
    private function confirmationEmailCapability(): array
    {
        $mailable = 'App\\Mail\\OrderConfirmation';
        $hasMailable = class_exists($mailable);
        $mailer = (string) config('mail.default', '');
        $mailerSends = ! in_array($mailer, ['', 'array', 'log'], true);

        if (! $hasMailable) {
            return [
                'available' => false,
                'reason' => 'This build has no order-confirmation email — there is no mailable '
                    . 'to send and no order path that sends one. Nothing will be emailed.',
            ];
        }

        if (! $mailerSends) {
            return [
                'available' => false,
                'reason' => 'Mail is set to "' . $mailer . '", which does not deliver. '
                    . 'Configure a mailer before relying on this.',
            ];
        }

        return ['available' => true, 'reason' => null];
    }

    /** What actually happened to the confirmation email on this order. */
    private function emailOutcome(bool $requested): array
    {
        $capability = $this->confirmationEmailCapability();

        return [
            'requested' => $requested,
            'sent' => false,
            'reason' => $requested
                ? ($capability['reason'] ?? 'Sending is not wired up yet.')
                : 'Not requested — the operator left the box unticked.',
        ];
    }

    /** The customer's saved address, for prefilling the form. */
    private function addressOf(Customer $customer): ?array
    {
        $address = $customer->defaultAddress('shipping') ?? $customer->defaultAddress('billing');

        if (! $address) {
            return null;
        }

        return [
            'line1' => $address->line1,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'phone' => $address->phone,
        ];
    }
}
