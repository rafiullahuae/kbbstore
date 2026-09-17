{{-- Ported from kbb_backtocart_html(). Seven styles. --}}
@if ($settings->moduleEnabled('back_to_cart', true))
@php
    // Fully qualified deliberately: a `use` inside an @if compiles to a PHP
    // `use` inside a conditional block, which is a parse error.
    $style = (string) $settings->get('backtocart_style', 'ghost_rect');
    $chev  = in_array($style, ['text_chevron', 'ghost_rect'], true);
@endphp
<a class="co-tocart co-tocart--{{ $style }}" href="{{ \App\Support\Url::to('/cart/') }}" aria-label="{{ __('store.checkout.back_to_cart') }}" title="{{ __('store.checkout.back_to_cart') }}">
    @if ($chev)
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
    @else
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg>
    @endif
    @if ($style !== 'icon_round')<span>{{ __('store.checkout.back_to_cart') }}</span>@endif
</a>
@endif
