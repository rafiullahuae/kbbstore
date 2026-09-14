@extends('layouts.store')
@section('title', 'All brands')

@section('content')
@php use App\Support\Url; @endphp

<div class="brw">
    <nav class="brw-crumb" aria-label="Breadcrumb">
        <a href="{{ Url::to('/') }}">Home</a> <span>&rsaquo;</span> <span aria-current="page">Brands</span>
    </nav>

    <h1 class="brw-h1">All brands</h1>
    <p class="brw-sub">{{ $brands->count() }} brands, {{ $stocked }} with products in the shop right now.</p>

    @if ($brands->isEmpty())
        <p class="brw-empty">No brands have been added yet.</p>
    @else
        <div class="brw-grid">
            @foreach ($brands as $brand)
                @php $n = (int) $brand->products_count; @endphp
                <a class="brw-card{{ $n === 0 ? ' is-empty' : '' }}"
                   href="{{ $n > 0 ? $brand->url() : Url::to('/shop/') }}">
                    <span class="brw-logo">
                        @if ($brand->logo)
                            <img src="{{ $brand->logo }}" alt="{{ $brand->name }}" loading="lazy" decoding="async">
                        @else
                            <span class="brw-initial">{{ mb_strtoupper(mb_substr($brand->name, 0, 1)) }}</span>
                        @endif
                    </span>
                    <span class="brw-name">{{ $brand->name }}</span>
                    <span class="brw-count">{{ $n > 0 ? $n . ' ' . \Illuminate\Support\Str::plural('product', $n) : 'Coming soon' }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>

@push('scripts')
<style>
.brw{max-width:1180px;margin:0 auto;padding:22px 18px 60px}
.brw-crumb{font-size:12px;color:var(--muted);margin-bottom:14px}
.brw-crumb a{color:var(--muted)}
.brw-crumb span{margin:0 5px}
.brw-h1{font-size:26px;margin:0 0 5px;color:var(--ink)}
.brw-sub{font-size:13px;color:var(--muted);margin:0 0 24px}
.brw-empty{font-size:14px;color:var(--muted)}
.brw-grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(158px,1fr))}
.brw-card{display:flex;flex-direction:column;align-items:center;gap:9px;text-align:center;
  padding:20px 14px 16px;border:1px solid var(--line);border-radius:12px;background:#fff;
  text-decoration:none;transition:border-color .16s,transform .16s}
.brw-card:hover{border-color:var(--blush);transform:translateY(-2px)}
.brw-card.is-empty{opacity:.55}
.brw-logo{width:62px;height:62px;border-radius:50%;background:var(--cream);display:grid;place-items:center;overflow:hidden}
.brw-logo img{width:100%;height:100%;object-fit:contain;padding:7px}
.brw-initial{font-size:22px;color:var(--pink)}
.brw-name{font-size:13.5px;color:var(--ink);line-height:1.35}
.brw-count{font-size:11px;color:var(--muted)}
@media (max-width:520px){
  .brw-grid{grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:10px}
  .brw-logo{width:52px;height:52px}
}
</style>
@endpush
@endsection
