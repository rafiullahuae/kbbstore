{{-- Matched to the live page source: a linked name (.bn), a price (.bp) and a
     .baddbtn carrying data-kbb-add — the same attribute cart.js's initCart()
     already listens for on every product card, so this reuses that handler
     rather than needing its own. No brand row. --}}
@php use App\Support\Gradient; use App\Support\Money; @endphp
@php
    $thumb = $bp->image
        ? "background-image:url('" . e($bp->image) . "')"
        : 'background:' . Gradient::for(($bp->brand?->name ?? '') . $bp->name);
@endphp
<div class="bitem"><a class="bth" href="{{ $bp->url() }}" style="{{ $thumb }}"></a><div class="binfo"><a class="bn" href="{{ $bp->url() }}">{{ $bp->name }}</a><div class="bp">@if ($bp->isOnSale())<del aria-hidden="true">{!! Money::format((int) $bp->price) !!}</del><ins aria-hidden="true">{!! Money::format($bp->effectivePrice()) !!}</ins>@else{!! Money::format($bp->effectivePrice()) !!}@endif</div></div><button type="button" class="baddbtn" data-kbb-add="{{ $bp->id }}" aria-label="Add to cart">Add</button></div>