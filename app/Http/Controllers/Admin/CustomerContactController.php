<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderNote;
use App\Rules\StorefrontEmail;
use App\Services\Mail\OrderMailer;
use App\Support\CustomerContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Store -> Customers -> (a customer) -> Edit email / phone. (Lane CE)
 *
 * THE OWNER'S ASK: "we have mostly cases that our customers didn't input the
 * correct emails, and their order details etc didn't reached to them ... give
 * me facility to edit the customer's email / phone, and will have option to
 * send order details again to customer upon checkbox confirmation."
 *
 * Three routes (routes/customer-contact-admin.php), all `customers.edit`
 * (owner, manager), all inside the admin-api group (auth:admin, CSRF):
 *
 *   GET  /admin-api/customers/{id}/contact               what the dialog needs
 *   POST /admin-api/customers/{id}/contact               save the correction
 *   POST /admin-api/customers/{id}/resend-confirmation   re-send one receipt
 *
 * DECISIONS, each pinned by CustomerContactEditTest:
 *
 *  - NEVER MERGE. `customers.email` is UNIQUE across every row, guest or
 *    account, trashed or not. An address another row already holds is refused
 *    with a sentence naming that row (this is the admin console; the answer is
 *    never given anywhere public). For a REGISTERED owner of the address that
 *    is the obvious answer; for a GUEST it is the same answer, because the
 *    only alternatives are a database error or silently folding two people's
 *    histories together, and folding is a decision that needs a person
 *    looking at both records, not a side effect of fixing a typo.
 *
 *  - A REGISTERED CUSTOMER'S SIGN-IN MOVES WITH THE EMAIL. email_verified_at
 *    is cleared, the password-reset token for the old address is deleted, and
 *    an unused account-invite link is retired. Verification links die on
 *    their own: Customer::verificationHash() signs the address into them.
 *    Sessions are NOT ended: nothing in this codebase logs a customer out on
 *    an email change (invalidateSessions() runs on password reset only), and
 *    the person signed in is the person the shop is correcting the address
 *    FOR.
 *
 *  - ORDERS FOLLOW ONLY WHERE THEY CARRY THE OLD VALUE. orders.email, orders.
 *    phone and the billing/shipping snapshot's `email` / `phone` keys are
 *    rewritten only where they equal the value being corrected: an order the
 *    customer deliberately placed under another address keeps it. Written with
 *    the query builder, not the model, so no Order::updated listener (pixels,
 *    review requests, the owner app, order mail) fires for a contact fix.
 *    Nothing else on the row moves: totals, status, payment fields, the lot.
 *
 *  - THE RESEND GOES TO THE ADDRESS ON THE ORDER, through OrderMailer::
 *    resendConfirmation() -- the same mailer, mailable, module switch, locale
 *    and Sent mail log as Order actions -> Resend confirmation. There is no
 *    "send to" box: the request names an order, and the order must already
 *    carry the customer's corrected address.
 */
class CustomerContactController extends Controller
{
    /** How many history rows the dialog shows. */
    private const HISTORY = 20;

    /** How many orders the resend pick-list offers. */
    private const PICK = 25;

    /* ------------------------------------------------------------------ read */

    public function show(int $id): JsonResponse
    {
        $customer = Customer::query()->withTrashed()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'message' => 'Customer not found.'], 404);
        }

        return response()->json($this->payload($customer));
    }

    /* ------------------------------------------------------------------ save */

    public function update(Request $request, int $id): JsonResponse
    {
        $customer = Customer::query()->withTrashed()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'message' => 'Customer not found.'], 404);
        }

        $request->merge([
            'email' => CustomerContact::normaliseEmail($request->input('email')),
            'phone' => CustomerContact::normalisePhone($request->input('phone')),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'max:' . CustomerContact::EMAIL_MAX, new StorefrontEmail()],
            'phone' => ['nullable', 'string', 'max:' . CustomerContact::PHONE_MAX, 'regex:' . CustomerContact::PHONE_REGEX],
            'orders_follow' => ['sometimes', 'boolean'],
            // What the dialog was showing. A second admin's save in between is
            // refused rather than silently overwritten.
            'was_email' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'phone.regex' => 'A phone number can hold digits, spaces, + ( ) - and . only.',
        ]);

        if (array_key_exists('was_email', $data) && $data['was_email'] !== null
            && ! CustomerContact::sameEmail($data['was_email'], $customer->email)) {
            return response()->json([
                'ok' => false,
                'message' => 'This customer\'s email was changed by someone else while the dialog was open. Close it and open it again.',
            ], 409);
        }

        $oldEmail = (string) $customer->email;
        $oldPhone = CustomerContact::normalisePhone($customer->phone);
        $newEmail = (string) $data['email'];
        $newPhone = $data['phone'] ?? null;

        $emailChanged = $newEmail !== $oldEmail;
        $phoneChanged = $newPhone !== $oldPhone;

        if (! $emailChanged && ! $phoneChanged) {
            return response()->json(['ok' => true, 'changed' => false, 'message' => 'Nothing changed.'] + $this->payload($customer));
        }

        if ($emailChanged && ! CustomerContact::sameEmail($newEmail, $oldEmail)) {
            $owner = Customer::query()->withTrashed()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($newEmail)])
                ->where('id', '!=', $customer->id)
                ->first(['id', 'name', 'first_name', 'last_name', 'password', 'legacy_password', 'deleted_at']);

            if ($owner !== null) {
                $registered = $owner->password !== null || $owner->legacy_password !== null;

                return response()->json([
                    'ok' => false,
                    'in_use' => true,
                    'other_id' => (int) $owner->id,
                    'message' => ($registered
                        ? 'That address already belongs to another customer account'
                        : 'That address is already on another guest customer record')
                        . ' (customer #' . $owner->id . ($owner->deleted_at !== null ? ', in the trash' : '') . ').'
                        . ' Nothing was changed: two customers are never merged from here.',
                ], 422);
            }
        }

        $follow = (bool) ($data['orders_follow'] ?? true);
        $admin = auth('admin')->user();
        $by = trim((string) ($admin?->name ?? '')) ?: 'Admin';
        $registered = $customer->password !== null || $customer->legacy_password !== null;

        $touched = DB::transaction(function () use ($customer, $oldEmail, $oldPhone, $newEmail, $newPhone, $emailChanged, $phoneChanged, $follow, $admin, $by): array {
            $write = ['email' => $newEmail, 'phone' => $newPhone];

            if ($emailChanged && ! CustomerContact::sameEmail($newEmail, $oldEmail)) {
                // The sign-in moves: whatever proved the OLD address no longer
                // proves anything.
                $write['email_verified_at'] = null;

                if (array_key_exists('invite_token_hash', $customer->getAttributes())) {
                    $write['invite_token_hash'] = null;
                    $write['invite_expires_at'] = null;
                }

                try {
                    DB::table('customer_password_reset_tokens')
                        ->whereRaw('LOWER(email) = ?', [mb_strtolower($oldEmail)])
                        ->delete();
                } catch (\Throwable) {
                    // Older schema without the table: there is no token to retire.
                }
            }

            $customer->forceFill($write)->save();

            $touched = $follow ? $this->followOrders($customer, $oldEmail, $oldPhone, $newEmail, $newPhone, $emailChanged, $phoneChanged, $by) : [];

            DB::table('customer_contact_changes')->insert([
                'customer_id' => $customer->id,
                'kind' => 'edit',
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'old_phone' => $oldPhone,
                'new_phone' => $newPhone,
                'orders_updated' => count($touched),
                'order_ids' => $touched === [] ? null : implode(',', $touched),
                'actor_id' => $admin?->getKey(),
                'actor_name' => $by,
                'created_at' => now(),
            ]);

            return $touched;
        });

        $parts = [];
        if ($emailChanged) $parts[] = 'email';
        if ($phoneChanged) $parts[] = 'phone';

        return response()->json([
            'ok' => true,
            'changed' => true,
            'orders_updated' => count($touched),
            'sign_in_changed' => $registered && $emailChanged && ! CustomerContact::sameEmail($newEmail, $oldEmail),
            'message' => ucfirst(implode(' and ', $parts)) . ' saved'
                . ($touched === [] ? '.' : ', and corrected on ' . count($touched) . ' order' . (count($touched) === 1 ? '' : 's') . '.'),
        ] + $this->payload($customer->refresh()));
    }

    /* ---------------------------------------------------------------- resend */

    public function resend(Request $request, int $id, OrderMailer $mailer): JsonResponse
    {
        $customer = Customer::query()->withTrashed()->find($id);

        if ($customer === null) {
            return response()->json(['ok' => false, 'message' => 'Customer not found.'], 404);
        }

        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            // The dialog's confirmation step. Without it nothing is sent.
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm the send first. Nothing was sent.',
        ]);

        $order = Order::query()->whereKey((int) $data['order_id'])->first();

        $ours = $order !== null && (
            (int) $order->customer_id === (int) $customer->id
            || ($order->customer_id === null && CustomerContact::sameEmail($order->email, $customer->email))
        );

        if (! $ours) {
            // One answer for "no such order" and "somebody else's order".
            return response()->json(['ok' => false, 'message' => 'That order is not one of this customer\'s.'], 404);
        }

        if (! in_array((string) $order->status, OrderMailer::PLACED_STATUSES, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Order ' . $order->order_number . ' is ' . $order->status . ': a confirmation goes only for an order that was placed for real.',
            ], 422);
        }

        // The mail goes to the address ON THE ORDER, and only once that is the
        // customer's corrected address. Never to a value typed into a box.
        if (! CustomerContact::sameEmail($order->email, $customer->email)) {
            return response()->json([
                'ok' => false,
                'message' => 'Order ' . $order->order_number . ' still carries ' . $order->email . '. Correct it on the order first (tick "Also correct it on their orders").',
            ], 422);
        }

        $result = $mailer->resendConfirmation($order);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'message' => (string) $result['message']], 422);
        }

        $admin = auth('admin')->user();
        $by = trim((string) ($admin?->name ?? '')) ?: 'Admin';
        $to = trim((string) $order->email);

        // "after correcting the email from ..." when this address came from a
        // correction made here.
        $from = null;
        try {
            $from = DB::table('customer_contact_changes')
                ->where('customer_id', $customer->id)
                ->where('kind', 'edit')
                ->whereRaw('LOWER(new_email) = ?', [mb_strtolower($to)])
                ->orderByDesc('id')
                ->value('old_email');
        } catch (\Throwable) {
            $from = null;
        }

        $sentence = 'Order confirmation re-sent to ' . $to . ' by ' . $by
            . ($from !== null && ! CustomerContact::sameEmail($from, $to) ? ' after correcting the email from ' . $from : '')
            . '.';

        try {
            OrderNote::create([
                'order_id' => $order->id,
                'author' => $by,
                'is_customer_note' => false,
                'content' => $sentence,
            ]);

            DB::table('customer_contact_changes')->insert([
                'customer_id' => $customer->id,
                'kind' => 'resend',
                'old_email' => $from,
                'new_email' => $to,
                'orders_updated' => 0,
                'order_ids' => (string) $order->id,
                'actor_id' => $admin?->getKey(),
                'actor_name' => $by,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The email has gone; the record of it is a courtesy that must not
            // turn a sent receipt into an error on screen.
            Log::error('customer contact: resend note not written', ['order' => $order->order_number, 'exception' => class_basename($e)]);
        }

        return response()->json(['ok' => true, 'message' => $sentence, 'order_number' => (string) $order->order_number]);
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * Rewrite the old value on this customer's orders, where it is the old value.
     *
     * @return list<int> the ids of the orders that changed
     */
    private function followOrders(Customer $customer, string $oldEmail, ?string $oldPhone, string $newEmail, ?string $newPhone, bool $emailChanged, bool $phoneChanged, string $by): array
    {
        $rows = CustomerContact::ordersOf((int) $customer->id, $oldEmail)
            ->orderBy('id')
            ->get(['id', 'email', 'phone', 'billing_address', 'shipping_address']);

        $touched = [];
        $now = now();

        foreach ($rows as $row) {
            $write = [];
            $changes = [];

            if ($emailChanged && CustomerContact::sameEmail($row->email, $oldEmail) && (string) $row->email !== $newEmail) {
                $write['email'] = $newEmail;
                $changes[] = 'Email: ' . $row->email . ' → ' . $newEmail;
            }

            $phoneFollows = $phoneChanged && $oldPhone !== null;

            if ($phoneFollows && CustomerContact::samePhone($row->phone, $oldPhone)) {
                $write['phone'] = $newPhone;
                $changes[] = 'Phone: ' . $row->phone . ' → ' . ($newPhone ?? '(none)');
            }

            foreach (['billing_address', 'shipping_address'] as $column) {
                $snap = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

                if (! is_array($snap)) {
                    continue;
                }

                $moved = false;

                if ($emailChanged && isset($snap['email']) && is_string($snap['email']) && CustomerContact::sameEmail($snap['email'], $oldEmail)) {
                    $snap['email'] = $newEmail;
                    $moved = true;
                }

                if ($phoneFollows && isset($snap['phone']) && is_string($snap['phone']) && CustomerContact::samePhone($snap['phone'], $oldPhone)) {
                    if ($newPhone === null) {
                        unset($snap['phone']);
                    } else {
                        $snap['phone'] = $newPhone;
                    }
                    $moved = true;
                }

                if ($moved) {
                    $write[$column] = json_encode($snap);
                }
            }

            if ($write === []) {
                continue;
            }

            $write['updated_at'] = $now;
            DB::table('orders')->where('id', $row->id)->update($write);

            if ($changes === []) {
                $changes[] = 'billing / shipping contact';
            }

            OrderNote::create([
                'order_id' => $row->id,
                'author' => $by,
                'is_customer_note' => false,
                'content' => 'Contact corrected from the customer record by ' . $by . ': ' . implode('; ', $changes) . '.',
            ]);

            $touched[] = (int) $row->id;
        }

        return $touched;
    }

    /** What the dialog draws. Every value is printed through esc() there. */
    private function payload(Customer $customer): array
    {
        $email = (string) $customer->email;
        $phone = CustomerContact::normalisePhone($customer->phone);

        // ONE query for the person's orders, counted here exactly as the save
        // will match them: carrying this email, or this phone. A customer's own
        // order history is a few rows, never the catalogue.
        $orders = CustomerContact::ordersOf((int) $customer->id, $email)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'order_number', 'status', 'email', 'phone', 'created_at']);

        $withEmail = [];
        $withPhone = [];
        $resendable = [];

        foreach ($orders as $o) {
            if (CustomerContact::sameEmail($o->email, $email)) {
                $withEmail[] = (int) $o->id;
            }

            if ($phone !== null && CustomerContact::samePhone($o->phone, $phone)) {
                $withPhone[] = (int) $o->id;
            }

            if (count($resendable) < self::PICK && in_array((string) $o->status, OrderMailer::PLACED_STATUSES, true)) {
                $resendable[] = [
                    'id' => (int) $o->id,
                    'order_number' => (string) $o->order_number,
                    'status' => (string) $o->status,
                    'email' => (string) $o->email,
                    'placed_at' => $o->created_at === null ? null : (string) $o->created_at,
                ];
            }
        }

        $history = [];
        try {
            $history = DB::table('customer_contact_changes')
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->limit(self::HISTORY)
                ->get(['id', 'kind', 'old_email', 'new_email', 'old_phone', 'new_phone', 'orders_updated', 'actor_name', 'created_at'])
                ->map(fn ($h) => [
                    'id' => (int) $h->id,
                    'kind' => (string) $h->kind,
                    'old_email' => $h->old_email,
                    'new_email' => $h->new_email,
                    'old_phone' => $h->old_phone,
                    'new_phone' => $h->new_phone,
                    'orders_updated' => (int) $h->orders_updated,
                    'by' => $h->actor_name,
                    'at' => $h->created_at === null ? null : (string) $h->created_at,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            $history = [];
        }

        return [
            'customer' => [
                'id' => (int) $customer->id,
                'email' => $email,
                'phone' => $phone,
                'account_type' => ($customer->password !== null || $customer->legacy_password !== null) ? 'account' : 'guest',
                'email_verified' => $customer->email_verified_at !== null,
            ],
            'orders_total' => count($orders),
            // Ids, so the dialog can count the UNION for whichever fields the
            // owner edits ("Also correct it on their N orders") with no request.
            'orders_with_email' => $withEmail,
            'orders_with_phone' => $withPhone,
            'resendable' => $resendable,
            'history' => $history,
        ];
    }
}
