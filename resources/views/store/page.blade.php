@extends('layouts.store')
@php use App\Support\Url; @endphp

@section('title', strip_tags($page->title) . ' · K-Beauty Bliss')

@section('content')
<div class="kbb-home">
<section class="sec"><div class="wrap">
    <nav class="crumb"><a href="{{ Url::to('/') }}">Home</a> / <span>{!! $page->title !!}</span></nav>

    <article class="policy">
        <h1>{!! $page->title !!}</h1>
        {{-- Content is authored in the admin, so it is trusted HTML. --}}
        <div class="policy-body">{!! $page->content !!}</div>

        @if ($page->updated_at)
            <p class="policy-date">Last updated {{ $page->updated_at->format('j F Y') }}</p>
        @endif
    </article>
</div></section>
</div>
@endsection
