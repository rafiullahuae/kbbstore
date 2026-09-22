{{--
    The checkout's Shipping address section: the delivery-address picker that
    replaced five typed fields.

    THE ADDRESSES ARE THE CART PAGE'S ADDRESSES. A signed-out shopper's three
    live in the session and a signed-in one's in `addresses`, and both pages
    reach that one state through the same /cart/address endpoints — so an
    address added on the cart is already here, and one added here is already
    there, with nothing syncing anything. partials/address-sheet.blade.php,
    which this page includes at the foot, is the same sheet in both places.

    ── THE FOUR HIDDEN INPUTS ARE THE POINT ───────────────────────────────────

    The order still posts `billing_address_1`, `billing_city`, `billing_state`
    and `billing_country`, all four required in place()'s rules, and place()
    is untouched by any of this. The shopper no longer types them; the address
    they pick fills them. They are rendered from the SERVER's idea of the
    chosen address, so a page loaded with one already chosen posts the right
    values with no script having run at all.

    CITY IS ALSO THE EMIRATE, at the owner's instruction. CartAddressState's
    `form` block carries the same value into both, and the reasoning — plus
    what happens when a city names no emirate — is written there.

    ── AND THE COUNTRY DRIVES THE PRICE ───────────────────────────────────────

    `billing_country` keeps its id, because checkout.js listens for `change` on
    exactly that element to re-fetch delivery rates, the free-shipping bar and
    the totals. Choosing an address dispatches that event rather than posting
    to a second endpoint of its own: one pricing path, which is the only way
    the rates a shopper is shown cannot disagree with the rates they are
    charged.
--}}
@php
    $kbbAddrState = \App\Support\CartAddressState::all(request());
    $kbbChosen = $kbbAddrState['chosen'] ?? null;
    $kbbSaved = $kbbAddrState['addresses'] ?? [];
    $kbbCoCfg = app(\App\Services\CartPage::class);

    /*
     * THREE SOURCES, IN THIS ORDER, and the order is the whole of the rule.
     *
     *   1. old()      — a submission that came back with an error keeps what
     *                   it posted, rather than silently reverting.
     *   2. the CHOSEN address — what the shopper picked in the sheet.
     *   3. $prefill   — the signed-in customer's default address, which is
     *                   what this page filled the five typed fields from
     *                   before any of this existed.
     *
     * THREE IS NOT OPTIONAL. CheckoutPrefillTest caught its absence: a
     * signed-in shopper with a default address used to arrive at a filled-in
     * checkout, and dropping to an empty picker would have made them choose an
     * address the shop already had. It is a fallback and not a choice — it
     * fills the inputs and draws the row, and the moment they pick something
     * the chosen address wins.
     */
    $kbbPre = [
        'line1' => (string) ($prefill['line1'] ?? ''),
        'city' => (string) ($prefill['city'] ?? ''),
        'state' => (string) ($prefill['state'] ?? ($prefill['city'] ?? '')),
        'country' => (string) ($prefill['country'] ?? ''),
    ];

    $kbbForm = $kbbChosen['form'] ?? array_filter($kbbPre, static fn (string $v) => $v !== '');
    $kbbVal = fn (string $k, string $fallback = '') => (string) old('billing_' . $k, $kbbForm[$k] ?? $fallback);

    /*
     * The row shows whatever those inputs are about to post, so the page never
     * says "choose an address" while quietly carrying one.
     */
    $kbbShow = $kbbChosen ?: (($kbbForm['line1'] ?? '') !== '' ? [
        'tag' => 'home',
        'line' => implode(' - ', array_filter([
            $kbbForm['line1'] ?? '', $kbbForm['city'] ?? '',
            \App\Support\Countries::NAMES[strtoupper((string) ($kbbForm['country'] ?? ''))] ?? '',
        ], static fn (string $p) => $p !== '')),
    ] : null);
@endphp
<div class="cka" id="cka">
@if ($kbbShow)
    <div class="cka-list">
        <div class="cka-row on">
            {{-- The same two glyphs the sheet draws, stroked from currentColor.
                 Inline rather than through a helper: these two are the only
                 place in PHP that needs them, and the sheet's own copies are
                 JavaScript strings it builds its rows from. --}}
            <span class="cka-ic">
@if ($kbbShow['tag'] === 'office')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/></svg>
@else
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.5 12 3.5l8.5 7"/><path d="M5.5 9.7V20h13V9.7"/><path d="M10 20v-5.2h4V20"/></svg>
@endif
            </span>
            <span class="cka-ad">
                <b id="ckaTag">{{ $kbbCoCfg->get('sheet_' . ($kbbShow['tag'] === 'office' ? 'office' : 'home')) }}</b>
                <i id="ckaLine">{{ $kbbShow['line'] }}</i>
            </span>
            <button type="button" class="cka-chg" id="cpgAddrBtn">{{ $kbbCoCfg->get('addr_btn_change') }}</button>
        </div>
    </div>
@else
    <div class="cka-empty">
        <span id="ckaPrompt">{{ $kbbCoCfg->get('addr_heading') }}</span>
        <button type="button" class="cka-chg" id="cpgAddrBtn">{{ $kbbCoCfg->get('addr_btn_add') }}</button>
    </div>
@endif
    {{-- Named and id'd exactly as the fields they replace, so place() and
         checkout.js both find what they have always found. --}}
    <input type="hidden" name="billing_address_1" id="billing_address_1" value="{{ $kbbVal('address_1', $kbbForm['line1'] ?? '') }}">
    <input type="hidden" name="billing_city" id="billing_city" value="{{ $kbbVal('city') }}">
    <input type="hidden" name="billing_state" id="billing_state" value="{{ $kbbVal('state') }}">
    <input type="hidden" name="billing_country" id="billing_country" value="{{ $kbbVal('country', $defaultCountry ?? 'AE') }}">
@error('billing_address_1')<p class="cka-err">{{ $message }}</p>@enderror
@error('billing_city')<p class="cka-err">{{ $message }}</p>@enderror
</div>
@push('styles')
<style>
/* The checkout's address row. It borrows the checkout's own palette rather
   than the cart page's, because it lives among that page's fields and a block
   styled like a different page reads as pasted in. */
.cka{margin-top:4px}
.cka-row,.cka-empty{display:flex;align-items:center;gap:10px;border:1px solid var(--line-2,#E4E7EC);
  border-radius:10px;padding:12px 13px;background:#fff}
.cka-row.on{border-color:var(--green,#1f7a4d);background:#f4fbf7}
.cka-ic{width:28px;height:28px;border-radius:50%;background:#EDF1F5;color:#5b6472;
  display:grid;place-items:center;flex:none}
.cka-row.on .cka-ic{background:#DFF1E7;color:var(--green,#1f7a4d)}
.cka-ic svg{width:15px;height:15px}
.cka-ad{min-width:0;flex:1}
.cka-ad b{display:block;font-size:13.5px;font-weight:700;color:#17181C;margin-bottom:2px}
/* One line, cut off at the right. An address is longer than the box on a
   phone and wrapping it to three lines pushes Delivery off the screen. */
.cka-ad i{display:block;font-style:normal;font-size:12px;color:#6B7280;line-height:1.35;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cka-empty span{flex:1;min-width:0;font-size:13px;color:#6B7280}
.cka-chg{flex:none;border:0;background:none;cursor:pointer;color:var(--green,#1f7a4d);
  font:600 12.5px/1 inherit;padding:5px 2px}
.cka-chg:hover{text-decoration:underline}
.cka-err{margin:7px 0 0;font-size:12px;color:#b4443c}
</style>
@endpush
