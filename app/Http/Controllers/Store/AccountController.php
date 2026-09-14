<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\AccountPanel;
use App\Services\HumanCheck;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The account area: dashboard, order list, order detail, order tracking.
 *
 * TWO RULES GOVERN EVERY LOOKUP IN HERE.
 *
 * 1. THE GUARD IS NAMED. Every call is `auth('customer')`, never
 *    `auth()->guard()`. The default guard in config/auth.php is `web`, which
 *    is the admin-side `users` table and has nothing to do with shoppers.
 *    Illuminate\Auth\Middleware\Authenticate calls shouldUse() on the guard it
 *    matched, so inside the `auth:customer` group the bare call happened to
 *    resolve correctly — but `/my-account` and `/track-my-order` carry no such
 *    middleware, so on those two routes it resolved to `web` and a genuinely
 *    signed-in customer was treated as a guest. index() therefore served the
 *    LOGIN FORM to a customer who was already logged in, on the one page the
 *    site header links to. Confirmed against a real session cookie; the reason
 *    it was never caught is that `actingAs($c, 'customer')` in a test calls
 *    shouldUse() itself and so papers over exactly this bug.
 *
 * 2. ORDERS ARE REACHED THROUGH THE CUSTOMER, NEVER BY ID. Everything goes
 *    through `$customer->orders()`, so an id belonging to somebody else cannot
 *    load in the first place. That yields a 404, not a 403: order numbers here
 *    are sequential, and a 403 would confirm the row exists, which is the whole
 *    thing being defended against.
 *
 * The queries are Eloquent rather than DB::table() on purpose — `orders`
 * soft-deletes (see add_order_detail_fields), and a raw query builder ignores
 * `deleted_at`, so an order the admin has moved to trash went on being listed
 * and served here as though nothing had happened.
 */
class AccountController extends Controller
{
    /** Orders per page in the list. */
    private const PER_PAGE = 10;

    /** Orders on the dashboard's recent strip. */
    private const RECENT = 3;

    /**
     * Track-order attempts allowed per IP + email, and the window they decay
     * over.
     *
     * The form is public by design — a guest checkout has no account to sign
     * in to — so the throttle is what stops it being walked. Ten is generous
     * for a shopper mistyping their own order number and useless for anybody
     * enumerating: order numbers are sequential, so an unlimited form is a
     * complete customer list given enough requests.
     */
    private const TRACK_ATTEMPTS = 10;

    private const TRACK_DECAY = 600;

    public function __construct(
        private AccountPanel $panel,
        private HumanCheck $check,
    ) {}

    public function index(): View
    {
        $customer = $this->customer();

        if ($customer !== null) {
            return view('store.account.dashboard', [
                'customer' => $customer,
                'orders' => $customer->orders()->latest('id')->limit(self::RECENT)->get(),
                'panel' => $this->panel->all(),
            ]);
        }

        return view('store.account.login', [
            'panel' => $this->panel->all(),
            'hc' => $this->panel->get('sum_show') ? $this->check->issue() : null,
            'tab' => request()->query('tab') === 'register' ? 'register' : 'login',
        ]);
    }

    /**
     * The customer's own orders, newest first, paginated.
     *
     * Was a flat `limit(50)` with no pager, so a customer's fifty-first order
     * was unreachable from the account area — and the list is the only route
     * to the detail page.
     */
    public function orders(): View
    {
        $customer = $this->requireCustomer();

        $orders = $customer->orders()
            ->withCount('items')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('store.account.orders', [
            'customer' => $customer,
            'orders' => $orders,
        ]);
    }

    /**
     * One order: its lines, its totals, where it is going, and the gift
     * message if it carries one.
     *
     * `items.product` is eager-loaded for the thumbnails and for nothing else.
     * Every word and every number on the page comes off `order_items`, which
     * snapshots name, brand, sku, quantity and price at the moment the order
     * was placed. A product that has since been renamed, unpublished, trashed
     * or hard-deleted therefore changes nothing here except that the thumbnail
     * falls back to its gradient — `product_id` is nullOnDelete and Product
     * soft-deletes, so `$item->product` is simply null.
     */
    public function orderDetail(int $id): View
    {
        $customer = $this->requireCustomer();

        /** @var Order $order */
        $order = $customer->orders()
            ->with(['items' => fn ($q) => $q->orderBy('id'), 'items.product'])
            ->findOrFail($id);

        return view('store.account.order-detail', [
            'customer' => $customer,
            'order' => $order,
            'items' => $order->items,
        ]);
    }

    public function forgot(): View
    {
        return view('store.account.forgot');
    }

    /**
     * Track an order by number + email. Public, and it has to be: a guest
     * checkout has no account to sign into.
     *
     * The order-received page links here carrying the NUMBER ONLY
     * (`/track-my-order/?order=123`). That is deliberate and must stay that
     * way — the email is the half that proves identity, and a URL is shared,
     * pasted into chats and kept in browser history. Never put the email in
     * the link, and never accept the number on its own.
     *
     * THREE THINGS KEEP THIS FROM BEING AN ORDER-NUMBER ORACLE:
     *
     *   - one answer. "No such order" and "that is not the email on this
     *     order" produce the same page, so a reply cannot be read as
     *     confirmation that a number exists;
     *   - one shape of work. The row is fetched and hash_equals() is called on
     *     every attempt, including the ones where no row came back, so the
     *     comparison is constant-time and the two outcomes do not separate on
     *     the clock either;
     *   - a throttle, keyed on IP + email, since without one the other two only
     *     slow an attacker down rather than stopping them.
     */
    public function track(Request $request): View|Response
    {
        $number = trim((string) $request->query('order', ''));
        $email = trim((string) $request->query('email', ''));

        $data = ['order' => null, 'notFound' => false, 'retryAfter' => 0];

        if ($number === '' || $email === '') {
            return view('store.account.track', $data);
        }

        $key = 'kbb-track:' . sha1($request->ip() . '|' . mb_strtolower($email));

        if (RateLimiter::tooManyAttempts($key, self::TRACK_ATTEMPTS)) {
            $data['retryAfter'] = RateLimiter::availableIn($key);

            return response()->view('store.account.track', $data, 429);
        }

        RateLimiter::hit($key, self::TRACK_DECAY);

        $candidate = Order::query()->where('order_number', $number)->first();

        /*
         * hash_equals() runs whether or not a row came back: with none, it
         * compares against the empty string and returns false, which is the
         * answer we want and takes the same path as a real mismatch. Emails are
         * stored lower-cased by checkout, and a shopper retyping their own
         * address should not be turned away over a capital letter, so both
         * sides are folded before the compare.
         */
        $stored = mb_strtolower((string) ($candidate->email ?? ''));
        $given = mb_strtolower($email);

        if ($candidate !== null && hash_equals($stored, $given)) {
            // Their own order, so this attempt should not count against them.
            RateLimiter::clear($key);
            $data['order'] = $candidate;
        } else {
            $data['notFound'] = true;
        }

        return view('store.account.track', $data);
    }

    /** The signed-in shopper, or null. Never the `web` guard — see the class docblock. */
    private function customer(): ?Customer
    {
        $user = auth('customer')->user();

        return $user instanceof Customer ? $user : null;
    }

    /**
     * The signed-in shopper, or a 404.
     *
     * The routes carry `auth:customer`, so this cannot normally fire. It is
     * here because the middleware is one line in a file this lane does not own,
     * and a controller that would fatal on a null customer is one edit away
     * from being an unauthenticated order reader. 404 rather than 401 for the
     * same reason every other lookup here 404s.
     */
    private function requireCustomer(): Customer
    {
        $customer = $this->customer();

        abort_if($customer === null, 404);

        return $customer;
    }
}
