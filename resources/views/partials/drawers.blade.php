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
</aside>

<nav class="mnav" id="mnav">
    <div class="mnav-h">
        <div class="logo">K-Beauty<span>Bliss</span></div>
        <button class="x" style="margin-left:auto;width:32px;height:32px;border-radius:50%;background:var(--cream)" type="button" data-kbb-close>✕</button>
    </div>
    <div class="mnav-search"><input type="search" placeholder="{{ __('store.mobile_menu.search_placeholder') }}" data-kbb-msearch></div>
    <div class="mnav-hint">{{ __('store.mobile_menu.hint') }}</div>
    <div class="mnav-list" id="mlist"></div>
</nav>

<div class="msub" id="msub"></div>
<div class="toast" id="toast"></div>
