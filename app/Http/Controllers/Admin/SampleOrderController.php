<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\SampleOrder;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safety → Demo Content → Sample order.
 *
 * Three endpoints: what is there, make one, remove it. Every one of them is
 * behind the `orders.sample` capability, which App\Support\AdminCapabilities
 * grants to `owner` alone and EnforceAdminCapability refuses by default for
 * anything it cannot match — so an unmapped path added here later fails closed
 * rather than open.
 *
 * ── WHY ITS OWN CAPABILITY AND NOT `data.import` ────────────────────────────
 *
 * Demo Content's own endpoints are mapped to `data.import`, and reusing it
 * would have been one line less. These endpoints do something that one does
 * not: they WRITE A ROW INTO `orders`. That table is where this shop's money
 * lives, every figure in the back office is derived from it, and the row this
 * one writes is deliberately eligible for revenue and kept out of it by one
 * log row. The account that may do that should be named in the permissions map
 * on its own line, so that widening `data.import` one day — to let a support
 * account run a catalogue import, say — cannot hand out the ability to write
 * into the orders table as a side effect nobody was looking at.
 *
 * ── THE LANGUAGE IS A SELECT THAT STORES ONE OF ITS OWN OPTIONS ─────────────
 *
 * CLAUDE.md rule 5. `locale` arrives from the browser and reaches
 * `orders.locale`, which is the column that decides which language the invoice,
 * the order emails and the delivery note render in. It is validated against
 * Locale::codes() — the application's own table of languages, not a literal
 * list here, so there is no second opinion about what a language is — and
 * anything else is refused with a 422 rather than quietly written.
 */
class SampleOrderController extends Controller
{
    public function __construct(private SampleOrder $samples) {}

    /**
     * Is there a sample order, and where can it be looked at?
     *
     * The links are built here rather than in the browser because
     * InvoiceController already owns the four document paths and publishes
     * them as static methods; a second copy of those strings in JavaScript is
     * the kind that goes stale silently when a route moves.
     */
    public function status(): JsonResponse
    {
        $order = $this->samples->current();

        return response()->json([
            'ok' => true,
            'locales' => $this->localeOptions(),
            'order' => $order === null ? null : $this->present($order),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $locale = (string) $request->input('locale', Locale::DEFAULT);

        if (! in_array($locale, Locale::codes(), true)) {
            return response()->json([
                'ok' => false,
                'message' => 'That is not a language this shop speaks.',
            ], 422);
        }

        $order = $this->samples->create($locale);

        return response()->json(['ok' => true, 'order' => $this->present($order)]);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['ok' => true, 'removed' => $this->samples->destroy()]);
    }

    /**
     * What the screen shows about the order it just made.
     *
     * AN EXPLICIT ALLOWLIST, although this endpoint is behind `auth:admin` and
     * an owner-only capability rather than on the unauthenticated `/api/*`.
     * `orders` carries `ip_address`, `transaction_id` and `capture_ref`, and
     * the habit CLAUDE.md records — allowlist what a model returns, never the
     * model — is worth keeping on the screens where it is not load-bearing so
     * that it is still there on the ones where it is.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'locale' => (string) $order->locale,
            'locale_name' => Locale::LOCALES[(string) $order->locale]['native']
                ?? (string) $order->locale,
            'status' => (string) $order->status,
            'total_fils' => (int) $order->total,
            'lines' => $order->items()->count(),
            'created_at' => optional($order->created_at)->toIso8601String(),
            'links' => [
                'order' => 'orders/' . $order->id,
                'invoice' => InvoiceController::invoiceUrl($order->id),
                'packing_slip' => InvoiceController::packingSlipUrl($order->id),
                'delivery_note' => InvoiceController::deliveryNoteUrl($order->id),
                'shipping_label' => InvoiceController::shippingLabelUrl($order->id),
            ],
        ];
    }

    /**
     * The languages offered, from Locale's own table.
     *
     * EVERY language this build knows, not only the ones switched on for the
     * storefront. That is the point of the control: Arabic ships OFF on this
     * shop, and the owner still needs to see what an Arabic order's invoice
     * looks like before he decides to turn it on. Reading it back costs
     * nothing, because OrderLocale::render() sets the language from the ORDER
     * and asks Locale::isSupported() — which is the table, not the toggle — so
     * an Arabic sample order renders Arabic paperwork on a shop whose Arabic
     * storefront does not exist. Nothing about the shop changes either way.
     *
     * @return array<int, array{code: string, name: string, native: string}>
     */
    private function localeOptions(): array
    {
        $out = [];

        foreach (Locale::LOCALES as $code => $meta) {
            $out[] = [
                'code' => (string) $code,
                'name' => (string) $meta['name'],
                'native' => (string) $meta['native'],
            ];
        }

        return $out;
    }
}
