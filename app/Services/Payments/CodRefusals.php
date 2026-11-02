<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Customer;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cash on delivery for shoppers who refused a dispatched order.   (Lane CODR)
 *
 * The owner, 1 November 2026: "hide the COD payment option for all the
 * cancelled orders, because they didn't accept their order after dispatch ...
 * ignore if system found any 1 order completed ... the real customer must not
 * be affected by this at all."
 *
 * WHO. An order that went shipped -> cancelled (this shop's own note, or the
 * one WooCommerce wrote) and whose customer has no completed order, matched by
 * EMAIL and PHONE only. Never by IP: UAE mobile carriers put thousands of
 * shoppers behind one address, so an IP rule would hide COD from real
 * customers -- the one thing the owner said must not happen.
 *
 * WHERE. One row per refused order in `cod_refusals`, written when the order
 * moves (OrderStatus::moveTo -> transitioned()) and once for the past by the
 * migration. Checkout asks blocks(): ONE indexed query, and none at all for a
 * guest the page knows nothing about yet.
 *
 * WHAT THE SHOPPER SEES. Nothing, until it matters. There is no live check
 * while typing: the checkout's email capture answers the same 204 for every
 * address, so it cannot be used to ask "is this email blocked?", and no
 * endpoint added here can either. A signed-in refuser never sees COD; a guest
 * refuser sees it, is refused politely on Place order, and the session then
 * keeps it hidden. Every other method is untouched.
 *
 * Switched off (Store -> Payments -> Cash on delivery -> Refused deliveries)
 * nothing anywhere changes: every method returns early before any query.
 *
 * No static state: an instance per resolve, so no StaticMemos entry is owed.
 */
final class CodRefusals
{
    public const SETTING = 'cod_refusals_on';

    /** The session flag that keeps COD hidden after a refused Place order. */
    /**
     * The second switch (2.60.636): customers whose only history is a
     * cancelled order from before this package, whatever its route there.
     * Store -> Payments -> Cash on delivery -> Refused deliveries. ON, as the
     * owner asked; off leaves the Shipped -> Cancelled refusers hidden.
     */
    public const HISTORY_SETTING = 'cod_refusals_history_on';

    public const SESSION = 'kbb_cod_refused';

    /** What the order notes say, lower-cased, on both systems. */
    public const NEEDLE = 'from shipped to cancelled';

    public function __construct(private SettingsService $settings) {}

    // ------------------------------------------------------------ normalisers

    /** Trimmed, lower-cased; null for anything that is not an address. */
    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return ($email !== '' && str_contains($email, '@') && mb_strlen($email) <= 190) ? $email : null;
    }

    /**
     * Digits only, UAE numbers in one shape (9715XXXXXXXX):
     *   00971501234567 -> 971501234567   (leading 00 dropped)
     *   0501234567     -> 971501234567   (10 digits starting 0)
     *   501234567      -> 971501234567   (9 digits starting 5)
     * Anything else is kept as its digits. Under 9 digits is not a phone
     * number anybody can be matched on, so it is null.
     */
    public static function phone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }

        if (strlen($d) === 10 && $d[0] === '0') {
            $d = '971' . substr($d, 1);
        } elseif (strlen($d) === 9 && $d[0] === '5') {
            $d = '971' . $d;
        }

        return (strlen($d) >= 9 && strlen($d) <= 20) ? $d : null;
    }

    // ------------------------------------------------------------ reading

    public function enabled(): bool
    {
        return $this->on(self::SETTING);
    }

    public function historyEnabled(): bool
    {
        return $this->on(self::HISTORY_SETTING);
    }

    /** Either switch on: anything at all to check. */
    public function anyEnabled(): bool
    {
        return $this->enabled() || $this->historyEnabled();
    }

    /** The row kinds whose switch is on: `refused`, `history`, both or none. */
    public function activeSources(): array
    {
        return array_keys(array_filter(['refused' => $this->enabled(), 'history' => $this->historyEnabled()]));
    }

    private function on(string $key): bool
    {
        $v = $this->settings->get($key, '1');

        return ! in_array(strtolower(trim((string) $v)), ['0', '', 'false', 'off', 'no'], true);
    }

    /** Is COD withheld from this email or phone? One indexed query. */
    public function blocks(?string $email, ?string $phone): bool
    {
        return $this->blocksAny([$email], [$phone]);
    }

    /**
     * The same answer for several contact details at once (the billing pair and
     * the signed-in account's pair), still ONE query.
     *
     * @param  array<int, ?string>  $emails
     * @param  array<int, ?string>  $phones
     */
    public function blocksAny(array $emails, array $phones): bool
    {
        $sources = $this->activeSources();

        if ($sources === []) {
            return false;
        }

        $emails = array_values(array_unique(array_filter(array_map([self::class, 'email'], $emails))));
        $phones = array_values(array_unique(array_filter(array_map([self::class, 'phone'], $phones))));

        if ($emails === [] && $phones === []) {
            return false;
        }

        return DB::table('cod_refusals')
            ->whereNull('lifted_at')
            ->whereIn('source', $sources)
            ->where(function ($q) use ($emails, $phones) {
                if ($emails !== []) {
                    $q->whereIn('email', $emails);
                }
                if ($phones !== []) {
                    $q->orWhereIn('phone', $phones);
                }
            })
            ->exists();
    }

    /**
     * The checkout's payment list with COD taken out when it must be: this
     * session was refused COD, or the signed-in customer's own email or saved
     * phone is blocked. Untouched -- and no query -- when COD is not in the
     * list, the switch is off, or a guest has not been refused.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @return array<int, array<string, mixed>>
     */
    public function filterCheckoutList(array $list, ?Request $request): array
    {
        if ($request === null || ! collect($list)->contains('id', 'cod') || ! $this->anyEnabled()) {
            return $list;
        }

        if (! $this->hiddenForShopper($request)) {
            return $list;
        }

        return array_values(array_filter($list, static fn ($g) => ($g['id'] ?? null) !== 'cod'));
    }

    /** Session flag, then the signed-in account. The guest case costs nothing. */
    public function hiddenForShopper(Request $request): bool
    {
        if (! $this->anyEnabled()) {
            return false;
        }

        if ($request->hasSession() && $request->session()->get(self::SESSION) === true) {
            return true;
        }

        $customer = $request->user('customer');

        return $customer instanceof Customer && $this->blocks($customer->email, $customer->phone);
    }

    /**
     * Place order's lock: the billing pair and the signed-in pair, one query.
     * A refusal also sets the session flag, so the list stops offering COD.
     */
    /**
     * The checkout's email or phone field was left (2.60.636, "silently"):
     * a blocked shopper's session stops being offered COD from this answer
     * on, with no sentence anywhere. One indexed query, and only when there
     * is an address or number to ask about.
     */
    public function noteShopper(Request $request, ?string $email, ?string $phone): void
    {
        if (! $request->hasSession() || $request->session()->get(self::SESSION) === true) {
            return;
        }

        if ($this->blocks($email, $phone)) {
            $request->session()->put(self::SESSION, true);
        }
    }

    public function refusesAtPlace(Request $request, ?string $email, ?string $phone): bool
    {
        if (! $this->anyEnabled()) {
            return false;
        }

        $customer = $request->user('customer');
        $refused = ($request->hasSession() && $request->session()->get(self::SESSION) === true)
            || $this->blocksAny(
                [$email, $customer instanceof Customer ? $customer->email : null],
                [$phone, $customer instanceof Customer ? $customer->phone : null],
            );

        if ($refused && $request->hasSession()) {
            $request->session()->put(self::SESSION, true);
        }

        return $refused;
    }

    // ------------------------------------------------------------ writing

    /**
     * Called by OrderStatus::moveTo() after a transition has committed. Only
     * three transitions do anything, each a query or two:
     *   shipped -> cancelled   record the refusal (unless they have a completed order)
     *   cancelled -> anything  the order is alive again: its row goes
     *   anything -> completed  every row on that email or phone is lifted
     *
     * It never fails the status change: the change has already committed, the
     * writes are query-builder statements that leave no model state behind,
     * and an error is logged at ERROR (live runs LOG_LEVEL=error).
     */
    public function transitioned(int $orderId, string $from, string $to): void
    {
        $from = strtolower($from);
        $to = strtolower($to);

        $record = $from === 'shipped' && $to === 'cancelled';
        $revive = $from === 'cancelled' && $to !== 'cancelled';
        $lift = $to === 'completed';

        if (! $record && ! $revive && ! $lift) {
            return;
        }

        try {
            if ($revive) {
                DB::table('cod_refusals')->where('order_id', $orderId)->delete();
            }

            if (! $record && ! $lift) {
                return;
            }

            $order = DB::table('orders')->where('id', $orderId)
                ->first(['id', 'order_number', 'email', 'phone', 'payment_method']);

            if ($order === null) {
                return;
            }

            // Only cash on delivery orders are recorded (the owner, 2 November:
            // "ignore if any customer's order failed with tabby, tamara or card
            // payment"). A completed order of ANY method still lifts below.
            if ($record && strtolower(trim((string) $order->payment_method)) !== 'cod') {
                return;
            }

            $email = self::email($order->email);
            $phone = self::phone($order->phone);

            if ($lift) {
                $this->liftFor($email, $phone, 'completed order #' . $order->order_number);

                return;
            }

            if (($email === null && $phone === null) || $this->hasCompleted($email, $phone)) {
                return;
            }

            $now = now();
            DB::table('cod_refusals')->upsert([[
                'email' => $email,
                'phone' => $phone,
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'refused_at' => $now,
                'lifted_at' => null,
                'lifted_by' => null,
                'source' => 'refused',
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['order_id'], ['email', 'phone', 'order_number', 'refused_at', 'lifted_at', 'lifted_by', 'source', 'updated_at']);
        } catch (\Throwable $e) {
            Log::error('COD refusals: could not record a status change', [
                'order_id' => $orderId, 'from' => $from, 'to' => $to, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** "Allow COD again" from the admin. True when a row was lifted. */
    public function allow(int $id, string $by): bool
    {
        return DB::table('cod_refusals')->where('id', $id)->whereNull('lifted_at')
            ->update(['lifted_at' => now(), 'lifted_by' => mb_substr($by, 0, 190), 'updated_at' => now()]) > 0;
    }

    private function liftFor(?string $email, ?string $phone, string $by): void
    {
        if ($email === null && $phone === null) {
            return;
        }

        DB::table('cod_refusals')->whereNull('lifted_at')
            ->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->where('email', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->update(['lifted_at' => now(), 'lifted_by' => $by, 'updated_at' => now()]);
    }

    /**
     * Does this customer have a completed order? One query. The phone is
     * stored however it was typed, so the SQL narrows on its last nine digits
     * with separators stripped (REPLACE is portable) and PHP makes the exact
     * comparison on the same normaliser as everything else.
     */
    public function hasCompleted(?string $email, ?string $phone): bool
    {
        if ($email === null && $phone === null) {
            return false;
        }

        $rows = DB::table('orders')
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }
                if ($phone !== null) {
                    $q->orWhereRaw(self::strippedPhoneSql() . ' LIKE ?', ['%' . substr($phone, -9)]);
                }
            })
            ->limit(50)
            ->get(['email', 'phone']);

        foreach ($rows as $row) {
            if (($email !== null && self::email($row->email) === $email)
                || ($phone !== null && self::phone($row->phone) === $phone)) {
                return true;
            }
        }

        return false;
    }

    /** orders.phone with the usual separators removed, in SQL both engines read. */
    public static function strippedPhoneSql(): string
    {
        $sql = 'phone';
        foreach ([' ', '+', '-', '(', ')', '.'] as $c) {
            $sql = "REPLACE({$sql}, '{$c}', '')";
        }

        return $sql;
    }
}
