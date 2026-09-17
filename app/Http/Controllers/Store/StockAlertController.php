<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Tell me when this is back." (Lane EN)
 *
 * ── ONE ANSWER, ALWAYS ─────────────────────────────────────────────────────
 *
 * Stored, already waiting, opted out, module off: the shopper is told the same
 * thing. Store\SubscribeController's header sets out why at length — three
 * outcomes with three sentences is a membership oracle, and this endpoint is
 * public, unauthenticated and takes an address a stranger chose. Anyone could
 * otherwise ask this shop whether a given person had asked about a given
 * product, or whether they had opted out of its email.
 *
 * The only thing that answers differently is a MALFORMED ADDRESS, and that is
 * not an oracle: the answer depends on what was typed, not on what this shop
 * knows. Refusing it is also the whole point of validating.
 *
 * ── StorefrontEmail, NOT `email` ───────────────────────────────────────────
 *
 * CLAUDE.md records the CRLF injection advisory against the framework's own
 * email rule, and this is a public form that takes an address from a stranger
 * and causes a message to be sent to it — the exact artefact that advisory is
 * about. Same rule, same reasoning, as PasswordResetController and
 * SubscribeController.
 *
 * ── THE PRODUCT IS RESOLVED, NOT TRUSTED ───────────────────────────────────
 *
 * The posted id is looked up through Product::visible(), so a request cannot be
 * lodged against a draft, a hidden product or one scheduled for next month —
 * any of which would later produce an alert linking to a page that 404s. The
 * variant, if one is named, must belong to that product; a variant id from
 * another product would otherwise put a shopper on the list for a shelf they
 * never looked at, and the sweep would happily mail them about it.
 *
 * ── AND IT DOES NOT CHECK THAT THE PRODUCT IS SOLD OUT ─────────────────────
 *
 * Deliberately. A shopper looking at a sold-out page and a shopper whose page
 * was cached before the last unit went are indistinguishable from here, and
 * refusing the second one loses the request from the person who wanted it most.
 * An in-stock product simply makes the request due immediately, and the sweep
 * sends the alert — which is the truthful answer to "tell me when it's back"
 * for something that is, right now, back.
 */
class StockAlertController extends Controller
{
    public function __construct(private StockAlerts $alerts) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $data = $request->validate([
                'product_id' => ['required', 'integer', 'min:1'],
                'variant_id' => ['nullable', 'integer', 'min:0'],
                'email' => ['required', 'string', 'max:160', new \App\Rules\StorefrontEmail],
            ]);
        } catch (ValidationException) {
            return $this->fail($request, 'Please check that email address and try again.');
        }

        $product = Product::query()->visible()->whereKey((int) $data['product_id'])->first();

        /*
         * A product that is not on the storefront gets CONFIRM_MESSAGE and no
         * row, for the same reason the outcomes above are identical: answering
         * "no such product" would make this endpoint a way to enumerate which
         * ids are live, drafts included.
         */
        if ($product === null) {
            return $this->done($request);
        }

        $variantId = (int) ($data['variant_id'] ?? 0);

        if ($variantId > 0) {
            $belongs = $product->variants()->whereKey($variantId)->exists();

            // Not an error. An unknown or foreign variant falls back to the
            // product itself, which is the request the shopper plainly meant.
            if (! $belongs) {
                $variantId = 0;
            }
        }

        $this->alerts->request((int) $product->id, $variantId, (string) $data['email']);

        return $this->done($request);
    }

    /**
     * The same answer, in whichever shape the caller can read.
     *
     * JSON for the fetch on the product page, a redirect for a plain form post,
     * because the form has to keep working with scripts blocked —
     * SubscribeController was changed for exactly this reason after returning
     * `{"ok":true}` to anyone without the script running.
     */
    private function done(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => StockAlerts::CONFIRM_MESSAGE])
            : back()->with('kbb_stock_alert', StockAlerts::CONFIRM_MESSAGE);
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => false, 'error' => $message], 422)
            : back()->withInput()->with('kbb_stock_alert_error', $message);
    }
}
