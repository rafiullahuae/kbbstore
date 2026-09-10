{{-- Ported from kbb_delivery_line_html(). Country-aware copy under the button. --}}
@if ($settings->moduleEnabled('delivery_line', true) && $settings->get('delivery_line_enabled', true) && $deliveryText)
<div class="kbb-delivery-line"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5h11v10H2z"/><path d="M13 8h4.5l3.5 3.5V15h-8z"/><circle cx="6" cy="18" r="1.7"/><circle cx="17" cy="18" r="1.7"/></svg><span>{{ $deliveryText }}</span></div>
@endif
