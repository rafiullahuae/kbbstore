<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Address;
use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * Which delivery address the cart page's docked row is naming.
 *
 * ONE READER, TWO CALLERS, AND THAT IS THE POINT. The row is rendered by the
 * server on every page load and on every AJAX re-render of the basket, and it
 * is rewritten by the address sheet's script after a tap. If the Blade and
 * App\Http\Controllers\Store\CartAddressController each worked out "the chosen
 * address" for themselves, the two would eventually disagree — a shopper would
 * pick an address, see it in the row, change a quantity, and watch it vanish.
 * Both go through this class instead.
 *
 * A SIGNED-OUT SHOPPER'S ADDRESS IS IN THE SESSION AND NOWHERE ELSE. Writing an
 * `addresses` row for a guest would mean inventing a customer to hang it off,
 * and a shop that manufactures customer records for everybody who taps a button
 * has a customer table that cannot be counted. The session copy carries no id
 * and dies with the session.
 *
 * IT IS STILL IN THE PAYLOAD'S `addresses`, THOUGH — for the one shopper whose
 * session it is, and in the one response they can already read. "In the session
 * and nowhere else" is about STORAGE, not about what the sheet may draw: the
 * sheet decides between the saved list and the empty form on `addresses`, so
 * leaving the guest's own address out of it made "Change address" open a blank
 * form. It is listed to nobody else because nobody else's request can reach
 * another session. See the note in all().
 *
 * OWNERSHIP IS THE RELATION. Every saved address is reached through
 * `$customer->addresses()`, so an id belonging to somebody else is not found
 * rather than found-and-refused — a 404 and never a 403. A 403 confirms the row
 * exists, which is how a stranger learns how many addresses a shop holds. Same
 * rule as AddressController states for /my-account, and the same reasoning as
 * the note on Api\QuizController::expertRequest in CLAUDE.md.
 */
final class CartAddressState
{
    /** Where a signed-out shopper's typed address lives. */
    public const SESSION_KEY = 'kbb_cart_address';

    /** Which saved address a signed-in shopper picked. */
    public const SESSION_ID = 'kbb_cart_address_id';

    /** What the Home/Office pill can say. Stored in `addresses.label`. */
    public const TAGS = ['home', 'office'];

    /**
     * Everything the sheet and the docked row render from, in one payload.
     *
     * One payload and not three calls, because the sheet needs the list, the
     * current choice and the geo defaults the moment it opens, and three
     * fetches on a tap is three chances to show a half-drawn sheet.
     */
    public static function all(Request $request): array
    {
        $customer = self::customer();
        $chosenId = (int) $request->session()->get(self::SESSION_ID, 0);

        $saved = [];
        $chosen = null;

        if ($customer !== null) {
            $rows = $customer->addresses()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $saved[] = self::shape($row);
            }

            /*
             * A chosen id that no longer names one of THIS customer's rows is
             * dropped rather than echoed back: deleted from /my-account in
             * another tab, or left in the session from a previous sign-in on
             * the same browser. Echoing it would put somebody else's id — or a
             * deleted one — in the row a shopper is about to check out from.
             */
            $match = $rows->firstWhere('id', $chosenId);
            $chosen = $match ? self::shape($match) : null;
        }

        if ($chosen === null) {
            $guest = $request->session()->get(self::SESSION_KEY);
            $chosen = is_array($guest) ? self::shape(new Address($guest)) : null;

            /*
             * AND IT IS LISTED TOO — to the one shopper it belongs to, in the
             * one payload they can already see.
             *
             * This is the plumbing behind "Change address opens the ADD form".
             * The docked row calls itself "Change address" the moment there is
             * a chosen address, and a guest's chosen address lives in the
             * session. `addresses` was built from $customer->addresses() and
             * from nothing else, so for that shopper it came back EMPTY, the
             * sheet's `hasSaved` was false, and the button that says "change"
             * opened an empty form — which reads as "mine is gone", and is how
             * a shop ends up holding the same address twice.
             *
             * NOTHING IS WRITTEN AND NO CUSTOMER IS INVENTED. This is the
             * session copy the class note describes, shaped for the list it is
             * already shaped for elsewhere in this payload. It carries no id —
             * shape() returns null for a row that does not exist — and the
             * sheet draws an id-less row as the current choice rather than as
             * something to re-select, which is exactly what shape()'s own note
             * says the null is for. `Customer::count()` and `Address::count()`
             * are unchanged, which is what the signed-out test asserts.
             */
            if ($chosen !== null) {
                $saved[] = $chosen;
            }
        }

        $country = ShopperCountry::for($request);

        return [
            'ok' => true,
            'signedIn' => $customer !== null,
            'addresses' => $saved,
            'chosen' => $chosen,
            'geo' => [
                'country' => $country->code,
                'countryName' => $country->name(),
                'guessed' => $country->guessed(),
            ],
            /*
             * THE SHOP'S OWN LIST, App\Support\Countries::NAMES — the same one
             * the address book at /my-account and the checkout validate
             * against. A second list here could disagree with that one, and a
             * country a shopper can pick on the cart page but not at checkout
             * is a basket that cannot be completed.
             *
             * Sent in the payload rather than printed into the page. It is a
             * couple of hundred names: on the wire once, when somebody actually
             * opens the sheet, instead of on every cart page whether or not
             * anyone taps the button.
             */
            'countries' => self::countryList(),
        ];
    }

    /**
     * Every country the shop will accept, as [code, name], ordered by name.
     *
     * @return list<array{code: string, name: string}>
     */
    private static function countryList(): array
    {
        $out = [];

        foreach (Countries::NAMES as $code => $name) {
            $out[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        usort($out, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * One address as the sheet and the row draw it.
     *
     * `id` is null for the guest copy, and that is used: a row with no id is
     * the one address that cannot be re-selected by id, so it is shown as the
     * current choice rather than as an entry on the list.
     */
    public static function shape(Address $address): array
    {
        $parts = array_filter([
            trim((string) $address->line1),
            trim((string) $address->line2),
            trim((string) $address->city),
            Countries::NAMES[strtoupper((string) $address->country)] ?? '',
        ], static fn (string $p) => $p !== '');

        $tag = strtolower((string) $address->label);

        return [
            'id' => $address->exists ? (int) $address->id : null,
            'tag' => in_array($tag, self::TAGS, true) ? $tag : 'home',
            'name' => trim(((string) $address->first_name) . ' ' . ((string) $address->last_name)),
            'line' => implode(' - ', $parts),
        ];
    }

    /**
     * The signed-in shopper, or null.
     *
     * `auth('customer')`, never the bare guard: the default guard is `web`,
     * which is the admin-side users table. AddressController's own comment
     * records where that bit somebody, and it bit them on a route that carried
     * no middleware — exactly like the Blade that calls this.
     */
    public static function customer(): ?Customer
    {
        $customer = auth('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }
}
