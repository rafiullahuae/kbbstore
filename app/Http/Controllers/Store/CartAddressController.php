<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Services\CartPage;
use App\Support\CartAddressState;
use App\Support\Countries;
use App\Support\ShopperCountry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The delivery address the docked row on the cart page names.
 *
 * ── WHY THIS IS NOT IN routes/api.php ───────────────────────────────────────
 *
 * /api/* in this application is unauthenticated, by design and with a landmine
 * in CLAUDE.md for every time that was forgotten. These endpoints read and
 * write a named person's home address. They live in the `web` group, they need
 * the session and the CSRF token, and the two that touch the address book are
 * behind `auth:customer`.
 *
 * ── OWNERSHIP, AND WHY A WRONG ID IS A 404 AND NEVER A 403 ──────────────────
 *
 * Every read and every write goes through `$customer->addresses()`, so an id
 * belonging to somebody else is not found rather than found-and-refused. That
 * is the same rule AddressController states for /my-account, and the same rule
 * QuizController::expertRequest was rewritten to obey: a 403 is a confirmation
 * that the row exists, so a stranger walking the ids learns how many addresses
 * this shop holds and which ids are live. A 404 tells them nothing. There is no
 * second check further down; the relation IS the check.
 *
 * ── THE SIGNED-OUT SHOPPER ──────────────────────────────────────────────────
 *
 * Goes in the session. NOT the database: writing an `addresses` row for a guest
 * means inventing a customer to hang it off, and a shop that manufactures
 * customer records for everyone who taps a button has a customer table that
 * cannot be counted and a GDPR answer nobody wants to give. The session copy
 * carries no id, is never listed to anyone, and dies with the session.
 *
 * ── GEO ─────────────────────────────────────────────────────────────────────
 *
 * App\Support\ShopperCountry, which is what the rest of this shop already uses
 * — an explicit choice, then the session, then the CDN's own country header,
 * then the configured default. No third-party geo lookup is added here: one
 * more outbound call on the cart page is one more thing between a shopper and
 * a checkout button, and this shop already knows the answer.
 */
class CartAddressController extends Controller
{
    /*
     * The session keys, the tag vocabulary and the reader are all in
     * App\Support\CartAddressState, because the cart page's Blade renders the
     * same row this controller answers with and the two must never work it out
     * separately. That class carries the reasoning.
     */

    public function __construct(private CartPage $page)
    {
        // Applied in the constructor so every action is covered. A gate on the
        // listing alone would leave the writes reachable with the squeezed
        // layout switched off, which is the shape of bug this app keeps
        // finding — see AddressController's own note.
        abort_unless($this->page->squeezed(), 404);
    }

    /** Everything the sheet needs to draw itself, in one call. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /**
     * Choose one of this customer's saved addresses.
     *
     * A guest reaches this with no addresses to choose from, so every id 404s
     * for them — which is the correct answer and not a special case.
     */
    public function choose(Request $request, int $id): JsonResponse
    {
        $address = $this->owned($id);

        $request->session()->put(CartAddressState::SESSION_ID, $address->id);
        $request->session()->forget(CartAddressState::SESSION_KEY);

        return response()->json($this->state($request));
    }

    /**
     * Add an address and choose it in one step.
     *
     * One step because the sheet has no confirm button: tapping an address IS
     * the choice, and saving a new one is the same act with more typing. A
     * separate "now select it" round trip would be a second chance to fail
     * between the two halves.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'area' => ['nullable', 'string', 'max:120'],
            'apartment' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'tag' => ['nullable', 'string', 'in:' . implode(',', CartAddressState::TAGS)],
        ]);

        $area = trim((string) ($data['area'] ?? ''));
        $apartment = trim((string) ($data['apartment'] ?? ''));

        // One of the two, not both: "Al Quoz" on its own is a deliverable
        // answer in this city and "Building 1-10, G-04" is not much less of
        // one. Refusing an address because the shopper filled the other box is
        // the kind of validation that loses a sale on a phone.
        if ($area === '' && $apartment === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Fill in the area or the building.',
            ], 422);
        }

        $country = strtoupper(trim((string) ($data['country'] ?? '')));

        if (! isset(Countries::NAMES[$country])) {
            $country = ShopperCountry::for($request)->code;
        }

        $fields = [
            'type' => 'shipping',
            'label' => $data['tag'] ?? 'home',
            'line1' => $apartment !== '' ? $apartment : $area,
            'line2' => $apartment !== '' ? $area : '',
            'city' => trim((string) ($data['city'] ?? '')),
            'country' => $country,
        ];

        $customer = $this->customer();

        if ($customer === null) {
            $request->session()->put(CartAddressState::SESSION_KEY, $fields);
            $request->session()->forget(CartAddressState::SESSION_ID);

            return response()->json($this->state($request));
        }

        $address = $customer->addresses()->create($fields);

        $request->session()->put(CartAddressState::SESSION_ID, $address->id);
        $request->session()->forget(CartAddressState::SESSION_KEY);

        return response()->json($this->state($request));
    }

    /* ------------------------------------------------------------------ */

    /** @see \App\Support\CartAddressState::all() */
    private function state(Request $request): array
    {
        return CartAddressState::all($request);
    }

    private function customer(): ?Customer
    {
        return CartAddressState::customer();
    }

    /** This customer's address, or a 404. Never a 403 — see the class note. */
    private function owned(int $id): Address
    {
        $customer = $this->customer();

        abort_if($customer === null, 404);

        return $customer->addresses()->findOrFail($id);
    }
}
