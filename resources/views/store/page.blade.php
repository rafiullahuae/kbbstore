@extends('layouts.store')
@php use App\Support\Url; $pageTitle = strip_tags($page->t('title')); @endphp

@section('title', __('store.page.page_title', ['title' => $pageTitle]))

@section('content')
<div class="kbb-home">
@include('partials.page-banner', ['pageBanner' => $pageBanner ?? null])
<section class="sec"><div class="wrap">
{{-- Pages → Page header (Lane PH): with a configured header the breadcrumb moves into it, inside the article. --}}@if (empty($pageHeader))
    <nav class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <span>{!! $page->t('title') !!}</span></nav>
@endif

    <article class="policy">
{{-- Null = the original <h1> below, byte for byte. The title is the admin's own HTML, printed raw as it always was. --}}@if (! empty($pageHeader))
@include('partials.page-header', ['ph' => ['home' => Url::to('/'), 'crumb' => new \Illuminate\Support\HtmlString($page->t('title')), 'title' => new \Illuminate\Support\HtmlString($page->t('title'))]])
@else
        <h1>{!! $page->t('title') !!}</h1>
@endif
        {{-- Content is authored in the admin, so it is trusted HTML.

             @shortcodes, not {!! !!}. AppServiceProvider has registered this
             directive since the Phase 0 baseline, with the comment "renders
             [kbb_products ...] anywhere: pages, posts, HTML blocks" — and no
             view in this repo had ever used it, so a shortcode written into a
             page was printed to the shopper verbatim, brackets and all. That
             is checked, not assumed: `@shortcodes` and `Shortcodes::render`
             appear nowhere under resources/views at the tip this lane branched
             from. Content -> HTML Blocks is the screen that makes writing one
             easy, so this is the line that has to mean something first. --}}
        {{-- BodyHeadings::demoteH1 wraps the shortcode expansion rather than
             the raw column, because a [kbb_block] can itself carry an h1. The
             <h1> above is this page's own. See App\Support\BodyHeadings.

             t(), not the column. `content` is one of
             TranslationStore::LONG_FIELDS — deliberately not in the map every
             Arabic page loads, and read by the one page that prints it. --}}
        <div class="policy-body">{!! \App\Support\BodyHeadings::demoteH1(\App\Support\Shortcodes::render($page->t('content'))) !!}</div>

        @if ($page->updated_at)
            <p class="policy-date">{{ __('store.page.last_updated', ['date' => $page->updated_at->format('j F Y')]) }}</p>
        @endif
    </article>
</div></section>
</div>
@endsection
