@extends('layouts.store')

@section('title', 'Design check · KBB')

@push('styles')
    @vite('resources/css/kbb/kbb-shop.css')
@endpush

@section('content')
<div class="wrap" style="padding:28px 0 60px">
    <h1 style="margin:0 0 6px">Phase 1 — design check</h1>
    <p style="color:var(--muted);margin:0 0 26px">
        Announcement bar, header, navigation, search, drawers, toast, footer and the product card.
        Compare against the theme's <code>preview/</code> files.
    </p>

    <p style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:28px">
        <button class="addbtn" type="button" data-kbb-open="cart">Open cart drawer</button>
        <button class="addbtn" type="button" data-kbb-open="mnav">Open mobile menu</button>
        <button class="addbtn ghost" type="button" onclick="window.kbbToast('Toast looks right ✓')">Fire a toast</button>
    </p>

    @if ($products->isEmpty())
        <p style="color:var(--muted)">Products appear here once your catalogue is imported.</p>
    @else
        <div class="grid" data-cols="4">
            @foreach ($products as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    @endif
</div>
@endsection
