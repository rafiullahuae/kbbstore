{{--
    The floating WhatsApp button.                                    (Lane WA)
    Appearance -> WhatsApp button; App\Services\WhatsAppButton holds the
    schema, the link, the placement and every byte of CSS printed here.

    RAW OUTPUT HERE IS CONSTANTS ONLY. css, icon and symbols come from class
    constants of WhatsAppButton (rule 5: "anything printed unescaped is a
    constant, never a setting"). Every value the owner typed -- the four lines,
    the link -- goes through the escaping echo, and the style attribute holds
    only integers the service clamped and words from its own maps.

    NOTHING AT ALL when the button is off: view() answers null and this file
    emits not one byte, not even a newline.

    THE SCRIPT IS THE BUBBLE'S "SHOW ONCE" AND NOTHING ELSE. It runs inline,
    straight after the bubble, so a returning visitor's bubble is gone before
    it could paint (it pops in after a one-second delay anyway). localStorage
    can throw -- a private window, blocked site data -- so every touch of it is
    inside try/catch, and a visitor whose browser refuses simply sees the
    bubble again. No fetch, no timers, nothing that measures the page. With
    the bubble off there is no script at all.

    THE SIDE TAB (Lane WS) exists only on a page whose template declares
    WhatsAppButton::TAB_SECTION -- the cart and the checkout. Everywhere else
    $kbbWa['tab'] is null, $kbbWa['guard'] is '' and this file prints exactly
    the bytes it printed before. --kbtw is the tab's width, an integer the
    service clamped, through the escaping echo; the guard is a constant.
--}}
@php($kbbWa = app(\App\Services\WhatsAppButton::class)->view(null, null, View::hasSection(\App\Services\WhatsAppButton::TAB_SECTION)))
@if ($kbbWa)
<style id="kbb-wa">{!! $kbbWa['css'] !!}@if ($kbbWa['tab']):root{--kbtw:{{ $kbbWa['tab']['w'] }}px}@endif</style>
<div class="{{ $kbbWa['class'] }}" style="{{ $kbbWa['style'] }}" id="kbbWa">@if ($kbbWa['symbols'] !== '')<svg class="kbw-s" aria-hidden="true">{!! $kbbWa['symbols'] !!}</svg>@endif<div class="kbw-f">@for ($i = 1; $i <= $kbbWa['rings']; $i++)<span class="kbw-r{{ $i > 1 ? ' kbw-r'.$i : '' }}"></span>@endfor
@if ($kbbWa['design'] === 'D')<span class="kbw-h"></span>@endif
@if ($kbbWa['design'] === 'F' || $kbbWa['design'] === 'G')<span class="kbw-o">@foreach ($kbbWa['faces'] as $face)<span class="kbw-v" style="--a:{{ $face['a'] }}deg"><i><svg aria-hidden="true"><use href="#kbw{{ $face['g'] }}"/></svg></i></span>@endforeach</span>@endif
<a class="kbw-a" href="{{ $kbbWa['href'] }}" target="_blank" rel="noopener" aria-label="{{ $kbbWa['label'] }}">{!! $kbbWa['icon'] !!}@if ($kbbWa['capsule'])<span class="kbw-c"><span class="kbw-d"></span><span>@if ($kbbWa['lines']['cap1'] !== '')<b>{{ $kbbWa['lines']['cap1'] }}</b>@endif
@if ($kbbWa['lines']['cap2'] !== '')<small>{{ $kbbWa['lines']['cap2'] }}</small>@endif
</span></span>@endif
@if ($kbbWa['tag'])<span class="kbw-t">{{ $kbbWa['lines']['cap1'] }}</span>@endif
</a></div>@if ($kbbWa['bubble'])<div class="kbw-b" id="kbbWaB" role="status"><button type="button" class="kbw-x" aria-label="{{ $kbbWa['close'] }}">&times;</button>@if ($kbbWa['lines']['welcome'] !== '')<strong>{{ $kbbWa['lines']['welcome'] }}</strong>@endif
@if ($kbbWa['lines']['support'] !== '')<span>{{ $kbbWa['lines']['support'] }}</span>@endif
</div>@endif
</div>
@if ($kbbWa['tab'])<div class="{{ $kbbWa['tab']['class'] }}" style="{{ $kbbWa['tab']['style'] }}"><a class="kbt" href="{{ $kbbWa['tab']['href'] }}" target="_blank" rel="noopener" aria-label="{{ $kbbWa['tab']['aria'] }}"><span class="kbt-i">{!! $kbbWa['icon'] !!}</span>@if ($kbbWa['tab']['label'] !== '')<span class="kbt-l">{{ $kbbWa['tab']['label'] }}</span>@endif</a></div>
@endif
@if ($kbbWa['bubble'])
<script>(function(){var b=document.getElementById('kbbWaB'),k=@json($kbbWa['bubble_key']);if(!b)return;{!! $kbbWa['guard'] !!}try{if(localStorage.getItem(k)){b.parentNode.removeChild(b);return}localStorage.setItem(k,'1')}catch(e){}b.querySelector('button').addEventListener('click',function(){b.parentNode.removeChild(b)})})();</script>
@endif
@endif
