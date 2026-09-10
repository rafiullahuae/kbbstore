{{-- Ported from kbb_checkout_coupon_hint_html(). The code is clickable. --}}
@if ($settings->moduleEnabled('coupon_hint', true))
@php
    // Defaults match the live page, so the hint reads correctly before any
    // settings row exists.
    $text  = (string) ($settings->get('checkout_coupon_text') ?: 'Need more discount? Try {code} for 30% off ✨');
    $code  = strtoupper(trim((string) ($settings->get('checkout_coupon') ?: 'GLOW30')));
    $color = (string) ($settings->get('checkout_coupon_color') ?: '#1f7d52');
    $size  = max(9, min(18, (int) $settings->get('checkout_coupon_size', 11)));
    $badge = $code !== '' ? '<b data-code="' . e($code) . '">' . e($code) . '</b>' : '';
    $html  = str_contains($text, '{code}') ? str_replace('{code}', $badge, e($text)) : trim(e($text) . ' ' . $badge);
@endphp
@if (trim(strip_tags($html)) !== '')
    <div class="hint" style="color:{{ $color }};font-size:{{ $size }}px">{!! $html !!}</div>
@endif
@endif
