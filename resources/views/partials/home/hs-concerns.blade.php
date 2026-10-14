@php
/*
    Shop by skin concern — design E, the concern chips.             (Lane CX)
    $cc   App\Support\HomeConcerns::section(): classes and custom properties
          built from option keys, integers and checked colours; the words
          already plain text; each chip's url the category's own url().
    $cls  HomepageSections::classFor('concerns') — the Laptop / Phone switches.

    THE WHOLE CAPSULE IS THE LINK: one <a> per chip, the icon and the label
    inside it, so a tap anywhere on the pill opens the collection. The icons
    are constants (HomeConcerns::ICONS), printed raw for that reason, each
    with its own width and height. A phone's sideways row is CSS scroll-snap;
    no script.
*/
@endphp
<section class="sec {{ $cc['classes'] }} {{ $cls }}" style="{{ $cc['style'] }}"@if ($cc['title'] !== '') aria-labelledby="hs-concerns-h"@endif><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-concerns-h', 'h' => $cc])
<ul class="hs-cc-list">@foreach ($cc['chips'] as $chip)<li><a class="hs-cc-i" href="{{ $chip['url'] }}" style="{{ $chip['style'] }}"><span class="hs-cc-ic">{!! $chip['icon'] !!}</span><span class="hs-cc-t">{{ $chip['label'] }}</span></a></li>@endforeach</ul>
@include('partials.home.hs-foot', ['h' => $cc])
</div></section>
