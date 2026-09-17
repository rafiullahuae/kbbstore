<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\CustomerEmailVerification;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Customer extends Authenticatable
{

    use Notifiable;
    use SoftDeletes;

    /**
     * Columns that exist, look authoritative, and are not.
     *
     * `orders_count`, `total_spent` and `last_order_at` were created by the
     * Phase 0 schema as denormalised customer lifetime figures. Nothing in this
     * application has ever written any of the three. On every install they read
     * 0, 0 and NULL for every customer, including one with hundreds of dirhams
     * of order history, so the only thing they can do is mislead.
     *
     * THE DECISION: they are retired in place, not maintained and not dropped.
     *
     * Not maintained, because a lifetime total is a derived value with five
     * separate write paths — checkout, an admin status change, a refund, a
     * capture, and the importer itself — and a cache that any one of them can
     * forget to update is a cache that is wrong without saying so. Store →
     * Customers already computes all three from `orders` in SQL, in the same
     * query as the page of rows, using Order::REAL_STATUSES so a cancelled
     * order is not spend. That is one source of truth and it is correct by
     * construction. A second one that agrees most of the time is worse than no
     * second one, because it gets believed.
     *
     * Not dropped, because the live server is MySQL carrying real customer rows
     * and reaching production through signed zip packages with no shell access.
     * A DROP COLUMN there is irreversible, buys nothing a rule cannot, and this
     * project has already paid once for a migration that looked like a no-op
     * and was not.
     *
     * So the columns stay in the table and are made unmistakable in the code:
     * listed here, hidden from serialisation so no endpoint can leak a
     * confident-looking zero, and stripped of their casts, which were the only
     * thing suggesting anything ever read them. CustomerDerivedColumnsTest
     * fails if any of that is undone.
     *
     * THE IMPORTER MUST LEAVE ALL THREE ALONE. Filling them is the one change
     * that would turn a wrong-but-harmless column into a second, disagreeing
     * source of truth for a number the owner makes decisions with.
     *
     * @var list<string>
     */
    public const UNMAINTAINED_COLUMNS = ['orders_count', 'total_spent', 'last_order_at'];

    protected $guarded = [];

    /**
     * The credentials, plus the three columns above: a derived figure that is
     * always zero must not reach an API response, where it would be indistinguishable
     * from a customer who genuinely has never ordered.
     */
    protected $hidden = [
        'password',
        'legacy_password',
        'remember_token',
        'orders_count',
        'total_spent',
        'last_order_at',
        // Not a secret — a `cus_...` authorises nothing on its own — but it is
        // an internal handle on somebody's saved cards, and /api/* is
        // unauthenticated. Nothing serialises this model there today; this is
        // so that nothing can start to by accident.
        'stripe_customer_ids',
    ];

    /**
     * `last_order_at` and `total_spent` were cast here as datetime and int.
     * Casting a column implies something reads it; nothing does, and the casts
     * were the last thing making them look alive. See UNMAINTAINED_COLUMNS.
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'whatsapp_optin' => 'bool',
            // ['test' => 'cus_…', 'live' => 'cus_…']. See stripeCustomerId().
            'stripe_customer_ids' => 'array',
        ];
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function defaultAddress(string $type = 'shipping'): ?Address
    {
        return $this->addresses()->where('type', $type)->where('is_default', true)->first()
            ?? $this->addresses()->where('type', $type)->first();
    }

    /* ------------------------------------------------------- saved cards */

    /**
     * This customer's Stripe Customer id for one set of keys, or null.
     *
     * KEYED BY MODE, and that is the whole reason this is a map rather than a
     * column holding one string. A `cus_...` created with test keys does not
     * exist to an account using live keys: stored as a single value, the first
     * real order placed by somebody who had also ordered while the shop was in
     * test mode would hand Stripe a customer it has never heard of and the
     * PaymentIntent — the payment itself — would be refused. Each half is kept
     * and read on its own, so throwing that switch costs nothing.
     *
     * Anything that is not a `cus_...` reads as absent, including a value left
     * by an older build or edited by hand.
     */
    public function stripeCustomerId(string $mode): ?string
    {
        $map = $this->stripe_customer_ids;
        $id = is_array($map) ? ($map[$mode] ?? null) : null;

        return is_string($id) && str_starts_with($id, 'cus_') ? $id : null;
    }

    /**
     * Remember one, leaving the other mode's alone.
     *
     * forceFill/save rather than update(): this is written from a payment path
     * that has no business touching any other column on the row.
     */
    public function rememberStripeCustomerId(string $mode, string $id): void
    {
        $map = is_array($this->stripe_customer_ids) ? $this->stripe_customer_ids : [];
        $map[$mode] = $id;

        $this->forceFill(['stripe_customer_ids' => $map])->save();
    }

    public function displayName(): string
    {
        return $this->name ?: trim($this->first_name . ' ' . $this->last_name) ?: $this->email;
    }

    /* --------------------------------------------------------- password reset */

    /**
     * Override the framework's default, which would build its link with
     * `route('password.reset')` — a route that does not exist on this install
     * and, if it did, would be generated from APP_URL and so would double the
     * KBB_BASE_PATH prefix. Ours is built with Support\Url like every other
     * internal link on the site.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomerPasswordReset((string) $token));
    }

    /**
     * Apply a new password, and retire the WordPress hash in the same write.
     *
     * The second half is the part that matters and the part that is easy to
     * leave out. 3,712 imported customers have no `password` and a real phpass
     * or wp-bcrypt hash in `legacy_password`, and CustomerAuthController::verify()
     * will happily sign someone in against that column. Writing a new password
     * WITHOUT clearing it therefore leaves the old one working — so a customer
     * who resets precisely because their old password leaked has changed
     * nothing. The whole point of the reset is that the previous credential
     * stops being a credential.
     *
     * This is the same pair of columns, written together, that a successful
     * legacy sign-in already writes (`password` set, `legacy_password` null).
     * The two paths converge on one representation instead of two.
     *
     * Not silent: it is the documented behaviour of a reset, it is stated on
     * the reset screen, and CustomerPasswordResetTest asserts both columns.
     */
    public function applyNewPassword(string $plain): void
    {
        // `password` is cast `hashed`, so the plain value is hashed on assign.
        $this->forceFill([
            'password' => $plain,
            'legacy_password' => null,
        ])->save();
    }

    /* ---------------------------------------------------- email verification */

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        if ($this->hasVerifiedEmail()) {
            return false;
        }

        return $this->forceFill(['email_verified_at' => $this->freshTimestamp()])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new CustomerEmailVerification($this));
    }

    /**
     * A digest of the address the link was minted for.
     *
     * Signed into the verification link so that changing the address invalidates
     * every link already in flight for the old one. sha256 of the lowercased
     * address, truncated: it only has to be unguessable-to-tamper-with in
     * combination with the MAC, not secret — the address is in the customer's
     * own inbox either way.
     */
    public function verificationHash(): string
    {
        return substr(hash('sha256', mb_strtolower((string) $this->email)), 0, 32);
    }

    /* ------------------------------------------------------------- sessions */

    /**
     * Drop every server-side session belonging to this customer.
     *
     * A password reset that leaves the thief's session logged in has not
     * recovered the account. Two mechanisms, because one alone is not enough:
     *
     *  - `remember_token` is rotated, which invalidates every "remember me"
     *    cookie for this customer everywhere, immediately and without needing
     *    to find anything.
     *  - the `sessions` rows are deleted. Production runs SESSION_DRIVER=database
     *    (env.staging.txt), so those rows ARE the sessions; deleting one logs
     *    that browser out on its next request.
     *
     * The rows cannot be found with a WHERE clause: `sessions.user_id` is filled
     * from the DEFAULT guard, which here is `web`, never `customer`. So the
     * payload is decoded and matched on SessionGuard's own session key. That is
     * a full scan of a table with one row per active visitor — acceptable
     * because it happens once, on a password reset, and never on a page view.
     *
     * `allowed_classes: false` on the unserialize: session payloads are ours,
     * but a table scan that instantiates whatever it finds is not a thing to
     * write in an account-recovery path.
     *
     * @return int how many sessions were ended
     */
    public function invalidateSessions(?string $exceptSessionId = null): int
    {
        $this->forceFill(['remember_token' => Str::random(60)])->save();

        if (! Schema::hasTable('sessions')) {
            return 0;
        }

        $key = 'login_customer_' . sha1(SessionGuard::class);
        $ended = 0;

        foreach (DB::table('sessions')->select(['id', 'payload'])->cursor() as $row) {
            if ($exceptSessionId !== null && $row->id === $exceptSessionId) {
                continue;
            }

            $raw = base64_decode((string) $row->payload, true);

            if ($raw === false) {
                continue;
            }

            $data = @unserialize($raw, ['allowed_classes' => false]);

            if (! is_array($data) || ! isset($data[$key])) {
                continue;
            }

            if ((int) $data[$key] !== (int) $this->id) {
                continue;
            }

            DB::table('sessions')->where('id', $row->id)->delete();
            $ended++;
        }

        return $ended;
    }
}
