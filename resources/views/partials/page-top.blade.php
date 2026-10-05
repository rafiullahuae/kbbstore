{{--
    The top of a custom page: the header area and the strip, in the owner's
    order, with his three spacings. (Lane SP3) Included ONLY when
    App\Services\PageHeaders::top() returned a bag — the page draws a
    configured header or has its strip switched on; otherwise the view prints
    its original markup and this file is never reached.

    Raw output is the TOP_CSS constant only. The style attribute is built by
    PageHeaders::topStyle() from constant names and clamped integers and is
    printed escaped. The two blocks are the existing partials, unchanged: the
    strip (partials/page-banner) outside any .wrap, edge to edge, and the
    header area inside a .sec > .wrap of its own (and a .policy on a content
    page), so the listing's widths and gutters and the content page's heading
    size apply to it exactly as they did where it stood before.
--}}
<style id="kbb-pt-css">{!! \App\Services\PageHeaders::TOP_CSS !!}</style>
<div class="kbb-pt" style="{{ $pageTop['style'] }}" data-kbb-pt="{{ $pageTop['key'] }}">
@foreach ($pageTop['order'] as $ptBlock)
@if ($ptBlock === 'strip')
@include('partials.page-banner', ['pageBanner' => $pageTop['banner']])
@elseif ($ptBlock === 'header')
<div class="sec kbb-pt-s"><div class="wrap">@if ($pageTop['header']['kind'] === 'page')<div class="policy">@endif

@include('partials.page-header', ['pageHeader' => $pageTop['header'], 'ph' => $ph])
@if ($pageTop['header']['kind'] === 'page')</div>@endif</div></div>
@endif
@endforeach
</div>
