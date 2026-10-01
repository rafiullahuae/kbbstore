{{-- Shared scrim, drawers and toast. Ids match the theme so its CSS applies. --}}
<div class="ov" id="ov" data-kbb-close></div>

{{--
    The cart drawer's own .kbb-fragment is the DIRECT child of .drawer.

    kbb.css lays .drawer out as a flex column and expects .kc-fragment as its
    child — that is what pins the Subtotal and buttons to the bottom. Wrapping
    it in an extra <div id="cartDrawer"> broke that chain, so the footer floated
    up under the last line item instead of sitting at the foot of the panel.
--}}
@php $cp = app(\App\Services\CartPanel::class); @endphp
<aside class="drawer {{ $cp->bodyClass() }}" id="cart" style="{{ $cp->cssVariables() }}"
       data-cp="{{ json_encode($cp->jsConfig()) }}">
    @include('partials.cart-drawer')
{{-- The "added" tick: Appearance → Cart panel → Behaviour → "When
         something is added" → Animated tick. (Lane PI-B)

         INSIDE the panel and positioned against it, just outside its leading
         edge — over the dimmed page, beside the panel, never on top of the
         panel's own tabs, which is where the old pill sat on a phone. The
         panel is position:fixed, so it is this element's containing block and
         the tick travels with it; kbb.css carries the geometry and the whole
         animation. cart.js only adds and removes `on`. The message is the
         same "Added to bag" the pill printed, read out by screen readers from
         the status span; the drawing itself is decoration. --}}<div class="kbb-addmark" id="kbbAddMark"><span class="kbb-addmark-msg" role="status" aria-live="polite"></span><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" pathLength="1"/></svg></div>
</aside>

<nav class="mnav" id="mnav">
    <div class="mnav-h">
        <div class="logo"><bdi>K-Beauty<span>Bliss</span></bdi></div>
        <button class="x" style="margin-left:auto;width:32px;height:32px;border-radius:50%;background:var(--cream)" type="button" data-kbb-close>✕</button>
    </div>
    <div class="mnav-search"><input type="search" placeholder="{{ __('store.mobile_menu.search_placeholder') }}" data-kbb-msearch></div>
    <div class="mnav-hint">{{ __('store.mobile_menu.hint') }}</div>
    <div class="mnav-list" id="mlist"></div>
</nav>

<div class="msub" id="msub"></div>
<div class="toast" id="toast"></div>
