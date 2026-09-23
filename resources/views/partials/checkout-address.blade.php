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
    {{-- THE CUE, AND ONLY ON THIS BRANCH.

         "i need some home, office icons and arrows towards the + address, but
         that must be super nice attractive and animated, so the user eye will
         go directly there."

         It is drawn on the EMPTY state alone. The moment an address is chosen
         the branch above renders instead and every part of this is gone --
         an animation pointing at a job already done is noise on the one page
         where noise costs money.

         aria-hidden on all three, and no text in any of them: the row already
         says what it needs in words, and a screen reader being told "home,
         office, arrow" between the prompt and the button is three
         interruptions that carry nothing. Appearance -> Checkout page ->
         Attention & trust switches each part off; it is all CSS, and all of it
         is inside a prefers-reduced-motion guard. --}}
    <div class="cka-empty">
        <span class="cka-cueic" aria-hidden="true">
            <i class="ci-home"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 10.5 12 3.5l8.5 7"/><path d="M5.5 9.7V20h13V9.7"/><path d="M10 20v-5.2h4V20"/></svg></i>
            <i class="ci-off"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/></svg></i>
        </span>
        <span class="cka-pr" id="ckaPrompt">{{ $kbbCoCfg->get('addr_heading') }}</span>
        <span class="cka-arrow" aria-hidden="true"><em></em><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg></span>
        <button type="button" class="cka-chg cka-cta" id="cpgAddrBtn">{{ $kbbCoCfg->get('addr_btn_add') }}</button>
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
.cka-empty .cka-pr{flex:1;min-width:0;font-size:13px;color:#6B7280}
.cka-chg{flex:none;border:0;background:none;cursor:pointer;color:var(--green,#1f7a4d);
  font:600 12.5px/1 inherit;padding:5px 2px}
.cka-chg:hover{text-decoration:underline}
.cka-err{margin:7px 0 0;font-size:12px;color:#b4443c}

/* ── THE ADDRESS CUE ──────────────────────────────────────────────────────
   Three parts, each with its own switch, all timed off ONE number so they
   read as a single movement rather than three things flickering at each
   other. --cop-cue-t is a DURATION factor (the owner sets a speed; see
   CheckoutPage::inverse()) and --cop-cue-s scales the two drawings.

   No script. Nothing is measured, nothing is observed, and there is no state
   to get out of step with the row -- the row itself only exists while no
   address is chosen. */
.cka-cueic{position:relative;flex:none;
  width:calc(40px * var(--cop-cue-s,1));height:calc(34px * var(--cop-cue-s,1))}
.cka-cueic i{position:absolute;display:grid;place-items:center;border-radius:50%;
  width:calc(26px * var(--cop-cue-s,1));height:calc(26px * var(--cop-cue-s,1));
  border:2px solid #fff;background:#EDF1F5;color:#5b6472}
.cka-cueic svg{width:calc(13px * var(--cop-cue-s,1));height:calc(13px * var(--cop-cue-s,1))}
/* Overlapped rather than side by side: two marks in one silhouette read as
   "your addresses", two in a row read as two buttons to press. */
.cka-cueic .ci-off{inset-inline-end:0;bottom:0}
.cka-cueic .ci-home{inset-inline-start:0;top:0;z-index:2;
  background:#DFF1E7;color:#1f7a4d;
  animation:ckaBob calc(3.4s * var(--cop-cue-t,1)) ease-in-out infinite}
@keyframes ckaBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}

.cka-arrow{flex:none;display:flex;align-items:center;gap:0;color:#1f7a4d;
  width:calc(36px * var(--cop-cue-s,1));
  animation:ckaNudge calc(1.5s * var(--cop-cue-t,1)) ease-in-out infinite}
.cka-arrow em{flex:1;height:2.2px;border-radius:2px;margin-inline-end:-1px;
  background:linear-gradient(90deg,rgba(31,122,77,0),currentColor)}
.cka-arrow svg{flex:none;width:calc(15px * var(--cop-cue-s,1));height:calc(15px * var(--cop-cue-s,1))}
@keyframes ckaNudge{0%,100%{transform:translateX(0);opacity:.5}50%{transform:translateX(4px);opacity:1}}
/* RTL: the arrow has to point at the button, and the button is on the other
   side. Mirrored, and the keyframes carry the mirror so the transform in them
   does not overwrite it. */
[dir="rtl"] .cka-arrow{animation-name:ckaNudgeRtl}
[dir="rtl"] .cka-arrow em{background:linear-gradient(270deg,rgba(31,122,77,0),currentColor)}
[dir="rtl"] .cka-arrow svg{transform:scaleX(-1)}
@keyframes ckaNudgeRtl{0%,100%{transform:translateX(0);opacity:.5}50%{transform:translateX(-4px);opacity:1}}

/* The halo. Roughly twice the arrow's period so the two beat together rather
   than against each other, and drawn on a pseudo-element so the button's own
   box, hit area and text are untouched. */
.cka-cta{position:relative}
.cka-cta::before{content:"";position:absolute;inset:-5px -9px;border-radius:99px;
  border:1.5px solid #1f7a4d;pointer-events:none;opacity:0;
  animation:ckaPulse calc(3s * var(--cop-cue-t,1)) ease-out infinite}
@keyframes ckaPulse{0%{transform:scale(.88);opacity:.5}60%{transform:scale(1.14);opacity:0}100%{opacity:0}}
/* It has done its job the moment the pointer or the keyboard arrives. */
.cka-cta:hover::before,.cka-cta:focus-visible::before{animation:none;opacity:0}

/* The switches. Every one is an OFF switch, so all-on -- the default -- puts
   no class on the page at all. */
.cop-nocue .cka-cueic,.cop-nocue .cka-arrow{display:none}
.cop-nocue .cka-cta::before{content:none}
.cop-nocue-ic .cka-cueic{display:none}
.cop-nocue-ar .cka-arrow{display:none}
.cop-nocue-pu .cka-cta::before{content:none}

/* Reduced motion keeps the meaning and drops the movement: the icons and the
   arrow are still there, still pointing, and nothing moves. */
@media (prefers-reduced-motion:reduce){
  .cka-cueic .ci-home,.cka-arrow{animation:none}
  .cka-arrow{opacity:1}
  .cka-cta::before{animation:none;opacity:0}
}

/* A phone is 350px wide here and the prompt is a whole sentence. The arrow is
   the part that can go: the icons still say "address" and the halo still says
   "here", and a 30px arrow squeezing the sentence to two lines costs more than
   it gives. */
@media (max-width:420px){
  .cka-arrow{display:none}
  .cka-empty{gap:8px}
}
</style>
@endpush
