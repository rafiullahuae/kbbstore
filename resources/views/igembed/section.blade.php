{{--
    Instagram embeds — the section. Lane IGE.

    Reached from App\Support\Shortcodes::instagramEmbeds() and from nowhere else
    (the homepage row goes through the shortcode too), so a grep for this file's
    name finds nothing: it is rendered by view name.

    Every value here was built by App\Services\InstagramEmbeds::section(): the
    classes and the one custom property come from select keys, `src` and `href`
    are a constant host plus a checked shortcode, and the label is plain text
    printed through the escaping echo. The only raw prints are the stylesheet and
    the glyph, both constants.

    TWO WAYS TO LOAD, NEITHER WITH A SCRIPT:
      near  the iframe is in the markup with loading="lazy": the browser fetches
            it only once it is near the viewport. The facade underneath shows
            until Instagram's page paints over it.
      tap   the iframe sits inside a closed <details>; a lazy frame inside a
            closed details is not fetched at all (measured in Chromium), and the
            summary IS the facade, so a tap opens it and the frame loads.
    The box is the same height before and after either, so nothing shifts.
--}}
@if ($withAssets)
<style>{!! \App\Services\InstagramEmbeds::css() !!}</style>
@endif
<div class="{{ $s['classes'] }}" style="{{ $s['style'] }}">
@if ($s['heading'] !== '')
<h2 class="kie-h">{{ $s['heading'] }}</h2>
@endif
<ul class="kie-list" role="list">
@foreach ($s['cards'] as $card)
@php $name = $card['label'] !== '' ? $card['label'] : __($card['kind'] === 'reel' ? 'store.spotted.reel' : 'store.instagram.post_alt'); @endphp
<li class="kie-card kie-k-{{ $card['kind'] }}"><div class="kie-fr"><div class="kie-in">
@if ($s['badge'] || $card['label'] !== '')
<div class="kie-top">@if ($s['badge']){!! \App\Services\InstagramEmbeds::GLYPH !!}@endif @if ($card['label'] !== '')<span>{{ $card['label'] }}</span>@endif</div>
@endif
@if ($s['load'] === 'tap')
<details class="kie-box kie-tap"><summary class="kie-fac">{!! \App\Services\InstagramEmbeds::GLYPH !!}<span>{{ $name }}</span><span class="kie-go">{{ __('store.igembed.load') }}</span></summary><iframe class="kie-if" src="{{ $card['src'] }}" loading="lazy" title="{{ $name }}" sandbox="{{ \App\Services\InstagramEmbeds::SANDBOX }}" referrerpolicy="{{ \App\Services\InstagramEmbeds::REFERRER }}" allow="encrypted-media; picture-in-picture"></iframe></details>
@else
<div class="kie-box"><div class="kie-fac" aria-hidden="true">{!! \App\Services\InstagramEmbeds::GLYPH !!}<span>{{ $name }}</span></div><iframe class="kie-if" src="{{ $card['src'] }}" loading="lazy" title="{{ $name }}" sandbox="{{ \App\Services\InstagramEmbeds::SANDBOX }}" referrerpolicy="{{ \App\Services\InstagramEmbeds::REFERRER }}" allow="encrypted-media; picture-in-picture"></iframe></div>
@endif
@if ($s['link'])
<a class="kie-more" href="{{ $card['href'] }}" target="_blank" rel="noopener nofollow">{{ __('store.spotted.view_on_ig') }} <span aria-hidden="true">↗</span></a>
@endif
</div></div></li>
@endforeach
</ul>
</div>
