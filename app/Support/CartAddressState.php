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
 * A SIGNED-OUT SHOPPER'S ADDRESSES ARE IN THE SESSION AND NOWHERE ELSE. Writing
 * an `addresses` row for a guest would mean inventing a customer to hang it
 * off, and a shop that manufactures customer records for everybody who taps a
 * button has a customer table that cannot be counted. The session copies carry
 * no id and die with the session.
 *
 * THREE OF THEM, NOT ONE. The reported bug: the session key held a single
 * fields array and every save overwrote it, so a shopper who added a second
 * address watched the first disappear. It is a list now, capped at GUEST_MAX,
 * each entry named by a handle that is not an id of anything — the constants
 * below carry that reasoning, and so does routes/cart-address.php, which
 * explains why the handle gets an endpoint of its own rather than a wider
 * `{id}`.
 *
 * AND THEY MOVE INTO THE ACCOUNT ON SIGN-IN, once, deduplicated. See adopt().
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
    /**
     * Where a signed-out shopper's typed addresses live — a LIST, up to
     * GUEST_MAX of them, oldest first.
     *
     * It used to hold one `fields` array and `put()` overwrote it on every
     * save, which is the reported bug: a shopper added a second address and
     * watched the first one disappear. Entries are now
     * `['key' => <handle>, 'fields' => [...]]`, and normalise() still accepts
     * the old single-array shape so a session open on the live site when the
     * package lands keeps the address it already had.
     */
    public const SESSION_KEY = 'kbb_cart_address';

    /** Which saved address a signed-in shopper picked. An `addresses.id`. */
    public const SESSION_ID = 'kbb_cart_address_id';

    /**
     * Which SESSION address a signed-out shopper picked. A guest handle.
     *
     * A SECOND KEY AND NOT THE SAME ONE, deliberately. SESSION_ID holds a
     * database id and is read back as one; a guest handle is not an id of
     * anything and must never be mistaken for one. Two keys means neither
     * value can ever arrive where the other was expected, whatever the session
     * has been through — a sign-in, a sign-out, or a session row carried over
     * from before this change.
     */
    public const SESSION_GUEST_ID = 'kbb_cart_address_key';

    /** What the Home/Office pill can say. Stored in `addresses.label`. */
    public const TAGS = ['home', 'office'];

    /**
     * How many addresses a signed-out shopper keeps, and what the fourth does.
     *
     * THREE, AND THE FOURTH DROPS THE OLDEST rather than being refused. A
     * shopper part-way through a checkout who is told "you already have too
     * many addresses" has been handed a chore and no way to do it — the sheet
     * has no delete button, so the only honest next instruction would be "go
     * and sign in first", which is a worse thing to say to someone holding a
     * full basket. Dropping the oldest is the behaviour every phone keyboard,
     * recent-files list and map app already has, and the sheet says out loud
     * that it is what happens (CartPage's `sheet_guest_note`).
     */
    public const GUEST_MAX = 3;

    /**
     * A ceiling on the stored bytes as well as the stored count.
     *
     * THE COUNT ALONE IS NOT A CAP. Session state that grows with what a
     * shopper types is how a session driver ends up truncating, and this shop
     * is one env var away from the cookie driver, where the whole session has
     * about 4KB.
     *
     * The real bound is already in CartAddressController::store()'s validator
     * and this is the belt to its braces: area max:120, apartment max:180,
     * city max:80, country size:2, tag from TAGS. One entry is therefore ~390
     * characters of shopper input at the very most and three are ~1.2KB
     * serialised, so this ceiling is slack in normal use and only bites a
     * session that got large some other way — at which point the oldest entry
     * goes, exactly as it does when the count is exceeded. No new length
     * limits are invented here; trim() clamps to the validator's own numbers
     * so the two can never drift apart.
     */
    public const GUEST_MAX_BYTES = 2048;

    /**
     * What a guest handle looks like, and why it cannot be an id.
     *
     * `g` then twelve hex characters — `g4f1a09c3b7de`. It begins with a
     * letter, so it satisfies no numeric route constraint and casts to the
     * integer 0, and it is minted from random_bytes() rather than counted, so
     * one guest's handles say nothing about another's and nothing about how
     * many addresses this shop holds. It names a slot in ONE session and is
     * meaningless in any other.
     */
    public const GUEST_HANDLE = '/^g[0-9a-f]{12}$/';

    /**
     * Everything the sheet and the docked row render from, in one payload.
     *
     * One payload and not three calls, because the sheet needs the list, the
     * current choice and the geo defaults the moment it opens, and three
     * fetches on a tap is three chances to show a half-drawn sheet.
     */
    public static function all(Request $request): array
    {
        /*
         * ADOPTION HAPPENS HERE, IN THE ONE READER, and that is the same
         * reason everything else in this class does. If the controller adopted
         * and the Blade did not, a cart page rendered in the first moments
         * after a sign-in would draw a docked row from the account while the
         * sheet that opens on top of it still listed the session — the exact
         * disagreement this class exists to prevent. It is idempotent and it
         * is a no-op for everybody who is not signing in with session
         * addresses in hand; see adopt().
         */
        self::adopt($request);

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

        /*
         * `$customer === null` AND NOT MERELY `$chosen === null`. The session
         * list belongs to a signed-out shopper and to nobody else: adopt()
         * empties it the moment one signs in, so for a signed-in shopper it is
         * already [] — and gating on the customer rather than on the outcome
         * means a leftover entry could not be listed beside an account's own
         * addresses even if adoption had somehow not run.
         */
        if ($customer === null) {
            $picked = (string) $request->session()->get(self::SESSION_GUEST_ID, '');

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
             *
             * ALL THREE OF THEM, since the fix to the reported bug. Each one
             * carries its handle so the sheet can offer it back, and the one
             * the shopper picked is `chosen` — falling back to the most
             * recent, which is what a shopper who has just typed one expects
             * to see in the docked row.
             */
            foreach (self::guestList($request) as $entry) {
                $row = self::shape(new Address($entry['fields']), $entry['key']);
                $saved[] = $row;

                if ($entry['key'] === $picked) {
                    $chosen = $row;
                }
            }

            if ($chosen === null && $saved !== []) {
                $chosen = $saved[count($saved) - 1];
            }
        }

        $country = ShopperCountry::for($request);

        return [
            'ok' => true,
            'signedIn' => $customer !== null,
            'addresses' => $saved,
            'chosen' => $chosen,
            /*
             * So the sheet can say what happens to the fourth one WITHOUT
             * knowing the number itself. A 3 hard-coded in the script and a
             * GUEST_MAX of 3 here are two places to change one decision, and
             * the first shopper to find them disagreeing is told the rule the
             * server is not applying.
             */
            'guestMax' => self::GUEST_MAX,
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
     * `id` is null for a session copy and `key` is null for a saved row, and
     * EXACTLY ONE OF THE TWO IS EVER SET. That is not tidiness: it is how the
     * sheet knows which of the two endpoints a tap belongs to, and it is why a
     * guest handle can never be posted to the route that would look it up in
     * `addresses`. A row with neither — which shape() can still produce, for
     * an unsaved Address with no handle — is drawn as the current choice and
     * is not selectable at all, which is what it was before any of this.
     */
    public static function shape(Address $address, ?string $key = null): array
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
            // Never both. A saved row is chosen by id and a session row by
            // handle, and each endpoint accepts only its own.
            'key' => $address->exists ? null : self::validHandle($key),
            'tag' => in_array($tag, self::TAGS, true) ? $tag : 'home',
            'name' => trim(((string) $address->first_name) . ' ' . ((string) $address->last_name)),
            'line' => implode(' - ', $parts),
        ];
    }

    /* ------------------------------------------------------------------ *
     | The signed-out shopper's three addresses.                          |
     |                                                                    |
     | SESSION ONLY. Nothing below opens a table, writes a row or invents |
     | a customer — the whole of this half is array work over one         |
     | session key, which is what makes "a guest touches no table" a      |
     | property of the code rather than a promise about it.               |
     * ------------------------------------------------------------------ */

    /**
     * This session's guest addresses, oldest first, normalised and capped.
     *
     * @return list<array{key: string, fields: array<string, string>}>
     */
    public static function guestList(Request $request): array
    {
        return self::normalise($request->session()->get(self::SESSION_KEY));
    }

    /**
     * Add one, choose it, and keep the three most recent.
     *
     * Returns the handle it minted, so the caller never has to guess which
     * entry it just wrote.
     */
    public static function guestAdd(Request $request, array $fields): string
    {
        $key = self::newHandle();

        $list = self::guestList($request);
        $list[] = ['key' => $key, 'fields' => self::trim($fields)];

        self::putGuestList($request, $list);

        $request->session()->put(self::SESSION_GUEST_ID, $key);
        // A guest has no saved rows, so a lingering id here could only be one
        // left over from a previous sign-in on this browser.
        $request->session()->forget(self::SESSION_ID);

        return $key;
    }

    /**
     * Choose one of this session's guest addresses. False means "no such one".
     *
     * FALSE IS THE ONLY FAILURE and the caller turns every one of them into
     * the same 404 — a handle of the wrong shape, a handle that names no entry
     * in this session, and a handle offered by somebody who is signed in all
     * come back identically. There is nothing here to probe: a handle is
     * meaningful in one session and says nothing about any other, so there is
     * no oracle to protect. Refusing them all the same way is simply cheaper
     * to reason about than three ways of saying no.
     */
    public static function guestChoose(Request $request, string $key): bool
    {
        // A signed-in shopper chooses from their address book. Their session
        // list is empty the moment adopt() has run, and this says so outright
        // rather than depending on that having happened first.
        if (self::customer() !== null) {
            return false;
        }

        if (self::validHandle($key) === null) {
            return false;
        }

        foreach (self::guestList($request) as $entry) {
            if (hash_equals($entry['key'], $key)) {
                $request->session()->put(self::SESSION_GUEST_ID, $key);
                $request->session()->forget(self::SESSION_ID);

                return true;
            }
        }

        return false;
    }

    /**
     * Move a shopper's session addresses into their account, once.
     *
     * WHY THEY MOVE AT ALL. Somebody who typed an address into the cart sheet,
     * then signed in to pay, has typed it. Leaving it behind shows them an
     * address book that does not contain the address they are looking at,
     * which reads as "mine is gone" — and the shop's own comments already
     * record where that leads: they type it again and the shop holds it twice.
     * The owner asked for signed-in addresses to be kept permanently; this is
     * the only moment a guest's ever becomes one.
     *
     * WHY IT IS SAFE TO DO AUTOMATICALLY. These are addresses the same person
     * typed into the same browser minutes earlier, and they move into that
     * person's own account and nowhere else. Nothing is shared, nothing
     * becomes visible to anyone new, and nothing is created for a shopper who
     * had no session addresses.
     *
     * IDEMPOTENT, and by construction rather than by care: the session list is
     * emptied first, so a second call — the next request, the next page, the
     * Blade and the controller in the same request — has nothing to adopt.
     *
     * NO DUPLICATES. Each entry is matched against the account's existing rows
     * on line1/line2/city/country, folded and whitespace-collapsed, so signing
     * in with the address already on file adopts nothing and simply selects
     * the row that was there. Label is not part of the match: the same address
     * tagged Home and Office is one address.
     */
    public static function adopt(Request $request): void
    {
        $customer = self::customer();

        if ($customer === null) {
            return;
        }

        $list = self::guestList($request);
        $picked = (string) $request->session()->get(self::SESSION_GUEST_ID, '');

        /*
         * EMPTIED BEFORE A SINGLE ROW IS WRITTEN. If the writes came first and
         * one of them threw, the next request would find the list still there
         * and adopt the entries that had already landed a second time. Nothing
         * is lost by clearing early — the copy in $list is what gets written.
         */
        $request->session()->forget([self::SESSION_KEY, self::SESSION_GUEST_ID]);

        if ($list === []) {
            return;
        }

        /** @var array<string, int> $seen fingerprint => addresses.id */
        $seen = [];

        foreach ($customer->addresses()->get() as $row) {
            $seen[self::fingerprint($row->only(['line1', 'line2', 'city', 'country']))] ??= (int) $row->id;
        }

        $land = 0;   // the row the chosen entry became
        $last = 0;   // the row the most recent entry became

        foreach ($list as $entry) {
            $print = self::fingerprint($entry['fields']);
            $id = $seen[$print] ?? null;

            if ($id === null) {
                $id = (int) $customer->addresses()->create(self::trim($entry['fields']))->id;
                $seen[$print] = $id;
            }

            $last = $id;

            // The one they had chosen stays chosen, now by its new id — a
            // shopper who signs in at checkout should not have to pick their
            // address again to get back the row they were already looking at.
            if ($picked !== '' && $entry['key'] === $picked) {
                $land = $id;
            }
        }

        // No recorded choice — the most recent, which is the same fallback
        // all() applies to a guest who has typed one and not picked one.
        if ($land === 0) {
            $land = $last;
        }

        if ($land > 0) {
            $request->session()->put(self::SESSION_ID, $land);
        }
    }

    /** A fresh handle. Random, never counted — see GUEST_HANDLE. */
    public static function newHandle(): string
    {
        return 'g' . bin2hex(random_bytes(6));
    }

    /** The handle if it is one, and null for anything else. */
    public static function validHandle(?string $key): ?string
    {
        return is_string($key) && preg_match(self::GUEST_HANDLE, $key) === 1 ? $key : null;
    }

    /**
     * Whatever is in the session key, as a list this class can work with.
     *
     * IT STILL READS THE OLD SHAPE. Before this change the key held one bare
     * `fields` array, and there are live sessions holding one right now. A
     * shopper whose session predates the package should find their address
     * where they left it, not discover that the fix for "it only keeps one"
     * threw away the one it was keeping, so a bare fields array is read as a
     * one-entry list and given a handle on the spot.
     *
     * Everything else — a string, a null, an entry with no fields, an entry
     * whose handle is not a handle — is dropped rather than repaired. The
     * session is not a trust boundary here (it is server side and this is the
     * only writer), but a reader that guesses at malformed input is a reader
     * that can be surprised, and there is nothing to gain by guessing.
     *
     * @return list<array{key: string, fields: array<string, string>}>
     */
    private static function normalise(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        // The pre-package shape: one flat fields array, no handle, no nesting.
        if (! array_is_list($raw)) {
            $fields = self::trim($raw);

            return $fields === [] ? [] : [['key' => self::newHandle(), 'fields' => $fields]];
        }

        $out = [];

        foreach ($raw as $entry) {
            if (! is_array($entry) || ! isset($entry['fields']) || ! is_array($entry['fields'])) {
                continue;
            }

            $key = self::validHandle(is_string($entry['key'] ?? null) ? $entry['key'] : null);
            $fields = self::trim($entry['fields']);

            if ($key === null || $fields === []) {
                continue;
            }

            $out[] = ['key' => $key, 'fields' => $fields];
        }

        return self::cap($out);
    }

    /**
     * One entry's fields, clamped to the columns and the lengths that already
     * exist.
     *
     * The numbers are CartAddressController::store()'s validator's own — area
     * max:120, apartment max:180, city max:80, country size:2 — restated here
     * because this is the last thing before the session and because the old
     * single-array shape reaches it from a session nobody validated today. No
     * new limit is invented: a value longer than the validator would have
     * accepted was never going to be stored, and one the validator accepted is
     * untouched.
     */
    private static function trim(array $fields): array
    {
        $line1 = mb_substr(trim((string) ($fields['line1'] ?? '')), 0, 180);
        $line2 = mb_substr(trim((string) ($fields['line2'] ?? '')), 0, 180);

        // An entry with no address in it is not an address. store() already
        // refuses one; this refuses to keep one that got in another way.
        if ($line1 === '' && $line2 === '') {
            return [];
        }

        $label = strtolower(trim((string) ($fields['label'] ?? 'home')));
        $country = strtoupper(trim((string) ($fields['country'] ?? '')));

        return [
            'type' => 'shipping',
            'label' => in_array($label, self::TAGS, true) ? $label : 'home',
            'line1' => $line1,
            'line2' => $line2,
            'city' => mb_substr(trim((string) ($fields['city'] ?? '')), 0, 80),
            'country' => isset(Countries::NAMES[$country]) ? $country : '',
        ];
    }

    /**
     * The three most recent, and no more bytes than GUEST_MAX_BYTES.
     *
     * The count and the size are the same rule applied twice, and both drop
     * from the front: the oldest entry is the one the shopper is least likely
     * to be about to use.
     *
     * @param  list<array{key: string, fields: array<string, string>}>  $list
     * @return list<array{key: string, fields: array<string, string>}>
     */
    private static function cap(array $list): array
    {
        while (count($list) > self::GUEST_MAX) {
            array_shift($list);
        }

        while (count($list) > 1 && strlen(serialize($list)) > self::GUEST_MAX_BYTES) {
            array_shift($list);
        }

        return array_values($list);
    }

    /** @param  list<array{key: string, fields: array<string, string>}>  $list */
    private static function putGuestList(Request $request, array $list): void
    {
        $list = self::cap($list);

        if ($list === []) {
            $request->session()->forget(self::SESSION_KEY);

            return;
        }

        $request->session()->put(self::SESSION_KEY, $list);
    }

    /**
     * What makes two addresses the same one, for adoption's deduplication.
     *
     * Case-folded and whitespace-collapsed, because "Flat 802" and "flat  802"
     * are one address and a shopper who typed it twice on two days should end
     * up with one row. Label is out on purpose — see adopt().
     */
    private static function fingerprint(array $fields): string
    {
        $parts = [];

        foreach (['line1', 'line2', 'city', 'country'] as $col) {
            $parts[] = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) ($fields[$col] ?? ''))));
        }

        return implode('|', $parts);
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
