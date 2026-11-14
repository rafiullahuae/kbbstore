{{--
    THE DELIVERY ROAD MAP (Quiqup P3, QUIQUP-PLAN §7.1), on /track-my-order and
    My account -> Orders -> (order). Included ONLY when
    DeliveryTimeline::for() returned one, i.e. the order has a Quiqup shipment;
    every other order's page is byte-identical to before.

    $tl   App\Support\DeliveryTimeline::build()
    $live the refresh URL (same origin) while the parcel is moving, else null

    CSS-only, logical properties (inline-start/end), so /ar mirrors with no
    second rule set. Nothing measures layout.

    THE REFRESH (the owner approved it on these two pages, plan §6.4): a few
    hundred bytes, inline, printed only while the parcel is moving. It checks
    when the shopper comes back to the tab or the connection returns (at most
    once a minute), and every 5 minutes while the tab is visible, at most 6
    times in all, then stops for good; at once on an end state. A conditional
    GET (ETag -> 304) swaps the list from server-rendered, escaped markup. No
    polling loop, no timer that never stops, no layout reads.
--}}
@once
@push('styles')
<style>
.kdt{margin:16px 0 4px;border:1px solid var(--line-2);border-radius:16px;background:#fff;padding:16px 16px 6px}
.kdt-hd{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.kdt-now{font-size:15px;font-weight:800;color:var(--ink);overflow-wrap:anywhere}
.kdt-st{font-size:12px;font-weight:700;color:#1F7D52}
.kdt-st:empty{display:none}
.kdt-ol{list-style:none;margin:0;padding:0}
.kdt-s{position:relative;display:flex;align-items:flex-start;gap:12px;padding:0 0 18px}
.kdt-s::before{content:"";position:absolute;inset-inline-start:14px;top:30px;bottom:0;width:2px;background:var(--line-2)}
.kdt-s:last-child::before{display:none}
.kdt-s.done::before{background:#2E8F62}
.kdt-dot{flex:none;position:relative;z-index:1;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#fff;border:2px solid var(--line-2);color:var(--muted)}
.kdt-s.done .kdt-dot{background:#2E8F62;border-color:#2E8F62;color:#fff}
.kdt-s.now .kdt-dot{border-color:#2E8F62;color:#2E8F62;box-shadow:0 0 0 5px #E3F4EB}
.kdt-s.warn .kdt-dot{border-color:#D99A2B;color:#B26A00;background:#FFF4E5}
.kdt-s.bad .kdt-dot{border-color:#D45B72;color:#B3243F;background:#FDECEF}
.kdt-s.warn.now .kdt-dot{box-shadow:0 0 0 5px #FFF2DC}
.kdt-s.bad.now .kdt-dot{box-shadow:0 0 0 5px #FDECEF}
.kdt-t{display:grid;gap:2px;padding-top:5px;min-width:0;font-size:13.5px;line-height:1.35;color:var(--muted)}
.kdt-t b{font-weight:600;overflow-wrap:anywhere}
.kdt-s.done .kdt-t b,.kdt-s.now .kdt-t b{color:var(--ink)}
.kdt-s.now .kdt-t b{font-weight:800}
.kdt-s.warn .kdt-t b{color:#B26A00}
.kdt-s.bad .kdt-t b{color:#B3243F}
.kdt-t em{font-style:normal;font-size:12px}
.kdt-t small{font-size:12px;font-weight:700;color:#1F7D52}
.kdt-live{display:inline-flex;align-items:center;gap:8px;min-height:44px;margin:0 0 12px;padding:0 16px;border-radius:12px;border:1px solid var(--line-2);background:#fff;color:var(--ink);font-size:13px;font-weight:700;text-decoration:none}
.kdt-live:hover{border-color:var(--pink);color:var(--pink-deep)}
.kdt-note{margin:0 0 12px;font-size:11.5px;color:var(--muted)}
@media (prefers-reduced-motion:no-preference){.kdt-s.now:not(.warn):not(.bad) .kdt-dot{animation:kdtp 2.4s ease-in-out infinite}@keyframes kdtp{50%{box-shadow:0 0 0 8px #E3F4EB}}}
</style>
@endpush
@endonce
<section class="kdt" data-kbb-tl data-v="{{ $tl['version'] }}"@if ($live) data-live="{{ $live }}" data-ok="{{ __('store.delivery.updated') }}"@endif>
    <div class="kdt-hd"><span class="kdt-now" data-kdt-now>{{ $tl['label'] }}</span><span class="kdt-st" role="status" aria-live="polite" data-kdt-st></span></div>
    <div data-kdt-body>@include('partials.account.delivery-timeline-list', ['tl' => $tl])</div>
    @if ($live)<p class="kdt-note">{{ __('store.delivery.live_note') }}</p>@endif
</section>
@if ($live)
@once
@push('scripts')
<script>(function(){var e=document.querySelector('[data-kbb-tl][data-live]');if(!e)return;var u=e.getAttribute('data-live'),v=e.getAttribute('data-v'),n=0,m=0,l=Date.now(),t;function s(){n=6;clearTimeout(t);document.removeEventListener('visibilitychange',c);removeEventListener('online',c)}function c(){if(n>5){s();return}if(document.visibilityState!=='visible'||Date.now()-l<6e4)return;n++;l=Date.now();fetch(u,{headers:{'If-None-Match':'"'+v+'"',Accept:'application/json'},credentials:'same-origin',cache:'no-store'}).then(function(r){return r.status===200?r.json():null}).then(function(j){if(!j)return;v=j.v;e.setAttribute('data-v',v);e.querySelector('[data-kdt-body]').innerHTML=j.html;e.querySelector('[data-kdt-now]').textContent=j.label;e.querySelector('[data-kdt-st]').textContent=e.getAttribute('data-ok');if(j.ended)s()}).catch(function(){})}function k(){if(++m>6)return;t=setTimeout(function(){c();if(n<6)k()},3e5)}document.addEventListener('visibilitychange',c);addEventListener('online',c);k()})();</script>
@endpush
@endonce
@endif
