{{--
    The trust strip (Lane TS) — App\Support\TrustStrip carries the reasoning.
    $v: card (homepage), bare (Super Sale), foot (above the footer). $cls: the
    device class and, on the homepage, the order class. Raw output is the icon
    constants only; every word is an escaped interface string. The sub-line
    is drawn only above the footer and shown only on a laptop.
--}}<section class="ktr ktr-{{ $v }}{{ $cls !== '' ? ' '.$cls : '' }}" aria-label="{{ __('store.trust_strip.label') }}"><ul>@foreach (\App\Support\TrustStrip::ITEMS as $ktrItem)<li><i>{!! \App\Support\TrustStrip::ICONS[$ktrItem] !!}</i><b>{{ __('store.trust_strip.'.$ktrItem.'_a') }}<br> {{ __('store.trust_strip.'.$ktrItem.'_b') }}</b>@if ($v === 'foot')<small>{{ __('store.trust_strip.'.$ktrItem.'_sub') }}</small>@endif</li>@endforeach</ul></section>
