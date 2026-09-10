{{-- Ported from kbb_checkout_thumbs_html(). Seven styles; "badges" is default
     and is the one with the ×n count on each circle. --}}
@php
    use App\Support\Gradient;
    use App\Support\Money;

    $style = (string) $settings->get('checkout_thumbs_style', 'badges');
    $show  = $settings->moduleEnabled('checkout_thumbs', true) && $style !== 'off' && $items->isNotEmpty();
    $count = (int) $items->sum('quantity');
    $countLbl = $count . ' ' . ($count === 1 ? 'item' : 'items');

    $circle = function ($item, $withQty = false) {
        $p = $item->product;
        $brand = $p?->brand?->name ?? '';
        $img = $item->variant?->image ?: $p?->image;
        $st = $img ? "background-image:url('" . e($img) . "')" : 'background:' . Gradient::for($brand . ($p?->name ?? ''));
        $init = $img ? '' : e(Gradient::initials($brand ?: ($p?->name ?? '?')));
        $q = $withQty ? '<span class="kthumb-q">&times;' . (int) $item->quantity . '</span>' : '';
        return '<span class="kthumb" style="' . e($st) . '" aria-hidden="true">' . $init . $q . '</span>';
    };
@endphp

@if ($show)
<div class="kbb-mobile-thumbs kbb-thumbs--{{ $style }}">
    @if ($style === 'total')
        <div class="kthumb-row"><span class="kstack">@foreach ($items->take(4) as $it){!! $circle($it) !!}@endforeach</span><span class="kmeta">{{ $countLbl }}<b>{!! \App\Support\Money::format($totals['total']) !!}</b></span></div>
    @elseif ($style === 'names')
        <div class="kthumb-lbl">Your order</div><div class="kthumb-row">
        @foreach ($items as $it)<span class="kthumb-it">{!! $circle($it) !!}<span class="kthumb-nm">{{ $it->product?->name }}</span></span>@endforeach
        </div>
    @elseif ($style === 'stack')
        @php $more = $items->count() - min(4, $items->count()); @endphp
        <div class="kthumb-lbl">You're ordering <span class="n">&middot; {{ $countLbl }}</span></div><div class="kthumb-row">
        @foreach ($items->take(4) as $it){!! $circle($it) !!}@endforeach
        @if ($more > 0)<span class="kthumb kthumb-more" aria-hidden="true">+{{ $more }}</span>@endif
        </div>
    @else
        @php $lbl = ['badges' => 'Your bag', 'scroll' => 'Your order', 'rings' => "You're ordering"][$style] ?? 'Your bag'; @endphp
        <div class="kthumb-lbl">{{ $lbl }} <span class="n">&middot; {{ $countLbl }}</span></div><div class="kthumb-row">
        @foreach ($items as $it){!! $circle($it, $style === 'badges') !!}@endforeach
        </div>
    @endif
</div>
@endif
