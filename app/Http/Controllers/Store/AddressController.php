<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Services\SettingsService;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The account address book.
 *
 * The `addresses` table, the Address model and Customer::addresses() have all
 * existed since the original schema; what was missing was any way for a
 * customer to reach them. `store/account/addresses.blade.php` was a stub that
 * printed "No addresses saved yet" unconditionally -- it never queried
 * anything, so a customer with ten saved addresses saw exactly the same words
 * as one with none.
 *
 * Every query here is scoped through $this->customer()->addresses() rather than
 * Address::find(), so an id belonging to somebody else 404s instead of loading.
 * That is the whole ownership model; there is no second check further down.
 */
class AddressController extends Controller
{
    /** Matches the `type` column's own comment in the schema. */
    private const TYPES = ['billing', 'shipping'];

    public function __construct(private SettingsService $settings)
    {
        // Applied in the constructor so every action is covered -- a gate on
        // index() alone would leave the POST paths reachable with the module
        // switched off, which is the shape of bug this app keeps finding.
        abort_unless($this->settings->moduleEnabled('address_book', true), 404);
    }

    public function index(): View
    {
        return $this->render(null);
    }

    public function edit(int $id): View
    {
        return $this->render($this->find($id));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $address = $this->customer()->addresses()->create($data);

        // First address of its type is the default whether or not it was asked
        // for -- otherwise a customer can have exactly one shipping address and
        // still have no default, which reads as a bug at checkout.
        if ($data['is_default'] || $this->countOfType($data['type']) === 1) {
            $this->promote($address);
        }

        return $this->back('Address saved.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $address = $this->find($id);
        $data = $this->validated($request);

        $address->update($data);

        if ($data['is_default']) {
            $this->promote($address);
        }

        return $this->back('Address updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $address = $this->find($id);
        $wasDefault = $address->is_default;
        $type = (string) $address->type;

        $address->delete();

        // Deleting the default must hand the flag on, not leave the type with
        // none. Oldest surviving address of that type takes it.
        if ($wasDefault) {
            $next = $this->customer()->addresses()
                ->where('type', $type)
                ->orderBy('id')
                ->first();

            if ($next) {
                $this->promote($next);
            }
        }

        return $this->back('Address deleted.');
    }

    public function makeDefault(int $id): RedirectResponse
    {
        $this->promote($this->find($id));

        return $this->back('Default address updated.');
    }

    /* ---------------------------------------------------------------- */

    private function render(?Address $editing): View
    {
        $customer = $this->customer();

        return view('store.account.addresses', [
            'customer' => $customer,
            'addresses' => $customer->addresses()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get(),
            'editing' => $editing,
            'types' => self::TYPES,
            'countries' => Countries::NAMES,
        ]);
    }

    private function customer()
    {
        return auth()->guard()->user();
    }

    private function find(int $id): Address
    {
        return $this->customer()->addresses()->findOrFail($id);
    }

    private function countOfType(string $type): int
    {
        return $this->customer()->addresses()->where('type', $type)->count();
    }

    /**
     * Exactly one default per type. Done as two statements rather than a
     * toggle so a half-applied update cannot leave two rows flagged.
     */
    private function promote(Address $address): void
    {
        $this->customer()->addresses()
            ->where('type', $address->type)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        $address->forceFill(['is_default' => true])->save();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', self::TYPES)],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:120'],
            'line1' => ['required', 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['country'] = strtoupper($data['country']);
        $data['is_default'] = $request->boolean('is_default');

        return $data;
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()
            ->route('account.addresses')
            ->with('kbb_status', $message);
    }
}
