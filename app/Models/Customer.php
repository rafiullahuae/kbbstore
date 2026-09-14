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

    protected $guarded = [];

    protected $hidden = ['password', 'legacy_password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_order_at' => 'datetime',
            'whatsapp_optin' => 'bool',
            'total_spent' => 'int',
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
