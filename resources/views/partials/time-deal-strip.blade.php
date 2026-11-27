{{--
    THE TIME DEAL STRIP (Lane TDS) -- App\Services\TimeDealStrip. Appearance -> Time deal strip.
    Design K "Holographic", the owner's pick, in the shop's own Outfit (no new font request). ONE row on every
    screen: the heading with the sub-line UNDER it, the clock, the button. Every size is a clamp() of the viewport,
    so it shrinks with the screen instead of colliding; at the very narrowest the words end in "…" rather than ever
    running under the clock. Not sticky: it scrolls away above the sticky header. Printed only when on and not yet
    over, so off means not one byte of it on the page. No layout is measured; the script only writes three numbers
    once a second and stops at zero.
--}}@php($kbbTds = app(\App\Services\TimeDealStrip::class)->view())
<style>{!! \App\Services\TimeDealStrip::CSS !!}</style>
<div class="kds{{ $kbbTds['phone'] ? '' : ' kds-np' }}{{ $kbbTds['desktop'] ? '' : ' kds-nd' }}{{ $kbbTds['motion'] ? '' : ' kds-still' }}" id="kbb-tds" dir="{{ $kbbTds['rtl'] ? 'rtl' : 'ltr' }}" data-end="{{ $kbbTds['end'] }}" data-daily="{{ $kbbTds['daily'] ? '1' : '0' }}"><span class="kds-t"><span class="kds-h">{{ $kbbTds['heading'] }}</span>@if ($kbbTds['sub'] !== '')<span class="kds-s">{{ $kbbTds['sub'] }}</span>@endif</span><span class="kds-c" role="timer"><b data-u>{{ sprintf('%02d', intdiv($kbbTds['left'], 3600)) }}</b><i>:</i><b data-u>{{ sprintf('%02d', intdiv($kbbTds['left'], 60) % 60) }}</b><i>:</i><b data-u>{{ sprintf('%02d', $kbbTds['left'] % 60) }}</b></span>@if ($kbbTds['button'] !== '')<a class="kds-b" href="{{ $kbbTds['href'] }}">{{ $kbbTds['button'] }}</a>@endif</div>
<script>(function(){var s=document.getElementById('kbb-tds');if(!s)return;var e=+s.dataset.end,d=s.dataset.daily==='1',D=864e5,b=s.querySelectorAll('[data-u]'),i;function p(n){return(n<10?'0':'')+n}function t(){var n=Date.now(),l=e-n;if(l<=0){if(!d){clearInterval(i);s.remove();return}while(e<=n)e+=D;l=e-n}var x=Math.floor(l/1e3);b[0].textContent=p(Math.floor(x/3600));b[1].textContent=p(Math.floor(x/60)%60);b[2].textContent=p(x%60)}i=setInterval(t,1e3);t()})()</script>
