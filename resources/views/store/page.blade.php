@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', strip_tags($page->title) . ' · K-Beauty Bliss')

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">Home</a> / <span>{!! $page->title !!}</span></nav>

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
        <div class="policy-body">@shortcodes($page->content)</div>

        @if ($page->updated_at)
            <p class="policy-date">Last updated {{ $page->updated_at->format('j F Y') }}</p>
        @endif
    </article>
</div></section>
</div>
@endsection
