{{--
    A category page's CUSTOM HEADER AREA (Lane CH): the Super Sale page's
    header (partials/page-header, Pages → Page header's own markup and CSS)
    and its banner and strip (partials/page-banner), drawn in place of the
    category's title header when the shop's "Edit header" panel switched this
    category to it. Included only when App\Services\CategoryHeaders::
    forCategory() answered -- every other category page never reaches here.

    The words are the category's own, escaped by the partials' echoes. The only
    raw output is CategoryHeaders::CSS, a constant.
--}}
@php
    $chParts = [
        'home' => \App\Support\Url::to('/'),
        'crumb' => $ch['heading'],
        'title' => $ch['heading'],
        'count' => trans_choice('store.collection.product_count', $chTotal, ['formatted' => number_format($chTotal)]),
        'intro' => $ch['intro'],
        'button' => ['href' => \App\Support\Url::to('/shop/'), 'label' => __('store.collection.all_products')],
    ];
@endphp
<div class="kbb-home kbb-chc" data-kbb-ch="{{ $ch['id'] }}">
<style id="kbb-chc-css">{!! \App\Services\CategoryHeaders::CSS !!}</style>
@if ($ch['banner'] && $ch['banner_at'] === 'above')
@include('partials.page-banner', ['pageBanner' => $ch['banner']])
@endif
<div class="wrap">
@include('partials.page-header', ['pageHeader' => $ch['header'], 'ph' => $chParts])
</div>
@if ($ch['banner'] && $ch['banner_at'] === 'below')
@include('partials.page-banner', ['pageBanner' => $ch['banner']])
@endif
</div>
