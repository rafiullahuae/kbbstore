{{--
    Pages → Page header (Lane PH): the configurable title block of a custom
    page. Included ONLY when App\Services\PageHeaders::forPage() returned a
    bag that differs from the page as it was; otherwise the view prints its
    original markup and this file is never reached.

    Every part the page has is printed, hidden per device by a class, so the
    front-end editor can show and hide them without a request. The H1 is never
    display:none: a hidden title is clipped to a pixel (.kbb-ph-v*) and is
    still the page's heading for search and screen readers.

    Raw output is the CSS constant only. The style attribute is built from
    constant letters and integers by PageHeaders::compile() and printed
    escaped; picture addresses went through SafeUrl::src(). $ph['title'] may be
    an HtmlString — a content page's title is the admin's own HTML, printed raw
    there exactly as page.blade.php always printed it.
--}}@php($phC = \App\Services\PageHeaders::compile($pageHeader['bag'], $pageHeader['kind'], ['intro' => ($ph['intro'] ?? '') !== '', 'image' => $pageHeader['img'] !== null, 'crumb' => \App\Services\PageHeaders::siteCrumb()]))
<style id="kbb-ph-css">{!! \App\Services\PageHeaders::CSS !!}</style>
<div class="{{ $phC['wrap'] }}" style="{{ $phC['style'] }}" data-kbb-ph="{{ $pageHeader['key'] }}">
<nav class="{{ $phC['cls']['crumb'] }}"><a href="{{ $ph['home'] }}">{{ __('store.breadcrumb.home') }}</a> / <span>{{ $ph['crumb'] }}</span></nav>
@if ($pageHeader['img'])
<picture class="{{ $phC['cls']['image'] }}">@if ($pageHeader['img']['two'])<source media="(max-width: {{ \App\Services\PageHeaders::BREAKPOINT }}px)" srcset="{{ $pageHeader['img']['m'] }}">@endif<img src="{{ $pageHeader['img']['d'] }}" alt="{{ $pageHeader['img']['alt'] }}" decoding="async"></picture>
@endif
<h1 class="{{ $phC['cls']['title'] }}">{{ $ph['title'] }}@if (isset($ph['count'])) <span class="{{ $phC['cls']['count'] }}">{{ $ph['count'] }}</span>@endif</h1>
@if (isset($ph['intro']))
<p class="{{ $phC['cls']['intro'] }}">{{ $ph['intro'] }}</p>
@endif
@if (isset($ph['button']))
<a class="{{ $phC['cls']['button'] }}" href="{{ $ph['button']['href'] }}">{{ $ph['button']['label'] }}</a>
@endif
</div>
