@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', __('store.page.page_title', ['title' => strip_tags($page->title)]))

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <span>{!! $page->title !!}</span></nav>

    <article class="policy">
        <h1>{!! $page->title !!}</h1>
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
             <h1> above is this page's own. See App\Support\BodyHeadings. --}}
        <div class="policy-body">{!! \App\Support\BodyHeadings::demoteH1(\App\Support\Shortcodes::render($page->content)) !!}</div>

        @if ($page->updated_at)
            <p class="policy-date">{{ __('store.page.last_updated', ['date' => $page->updated_at->format('j F Y')]) }}</p>
        @endif
    </article>
</div></section>
</div>
@endsection
