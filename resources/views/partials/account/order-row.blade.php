{{--
    ONE ORDER AS A ROW (Lane ACC; the owner chose "status header" with the
    "soft glow" finish): a coloured strip saying where it is, then the order
    number and date, up to five product photos (+N for the rest), the total,
    the item count and "View order". The whole row is one link to the order.

    $order must carry `items` and `items.product` (AccountController::THUMBS)
    and `items_count`. Photos are the img-cache copies at their drawn size,
    never the full original; a line whose product is gone shows its brand's
    first letters on a plain tile. Gift lines are not in `items`.
--}}
@php
    $kacTone = \App\Support\OrderProgress::tone((string) $order->status);
    $kacLines = $order->items->sortByDesc(fn ($i) => (int) $i->unit_price > 0)->values();
    $kacCount = (int) ($order->items_count ?? $kacLines->count());
    $kacShown = $kacLines->take($kacCount > 5 ? 4 : 5);
    $kacDate = (class_exists(\App\Support\LocalDate::class) ? \App\Support\LocalDate::format($order->created_at, 'j M Y') : (string) $order->created_at?->format('j M Y'));
    $kacSide = $kacTone === 'done' && $order->completed_at ? (class_exists(\App\Support\LocalDate::class) ? \App\Support\LocalDate::format($order->completed_at, 'j M') : $order->completed_at->format('j M')) : ($kacTone === 'off' ? $kacDate : '');
@endphp
<a class="kac-row" href="{{ \App\Support\Url::to('/my-account/orders/' . $order->id) }}">
    <div class="kac-band kac-t-{{ $kacTone }}"><span>{!! \App\Support\AccountIcons::svg(\App\Support\AccountIcons::forTone($kacTone), 17) !!}{{ __(\App\Support\OrderProgress::bandKey((string) $order->status)) }}</span>@if ($kacSide !== '')<span>{{ $kacSide }}</span>@endif</div>
    <div class="kac-rb">
        <div class="kac-l1"><span class="kac-no">{{ __('store.orders.order_number', ['number' => $order->order_number]) }}</span><span class="kac-dt">{{ $kacDate }}</span></div>
        @if ($kacShown->isNotEmpty())
        <div class="kac-ph">
            @foreach ($kacShown as $kacLine)
                @php $kacImg = (string) ($kacLine->product?->image ?? ''); @endphp
                @if ($kacImg !== '')
                    @php $kacSet = \App\Support\ImageVariants::srcsetFor($kacImg); @endphp
                    <img class="kac-th" src="{{ \App\Support\ImageVariants::variantUrl($kacImg, 200) }}"@if ($kacSet !== '') srcset="{{ $kacSet }}" sizes="50px"@endif width="50" height="50" alt="{{ $kacLine->name }}" loading="lazy" decoding="async">
                @else
                    <span class="kac-th" role="img" aria-label="{{ $kacLine->name }}">{{ mb_strtoupper(mb_substr((string) ($kacLine->brand ?: $kacLine->name), 0, 2)) }}</span>
                @endif
            @endforeach
            @if ($kacCount > $kacShown->count())<span class="kac-more">+{{ $kacCount - $kacShown->count() }}</span>@endif
        </div>
        @endif
        <div class="kac-l3"><span><span class="kac-amt">{!! \App\Support\Money::format((int) $order->total, \App\Services\Mail\OrderEmailPresenter::ledgerWidth($order)) !!}</span> <span class="kac-cnt">· {{ trans_choice('store.cart.item_count', $kacCount) }}</span></span><span class="kac-link">{{ __('store.orders.view_order') }}{!! \App\Support\AccountIcons::svg('chev', 15, 'kac-chev') !!}</span></div>
    </div>
</a>
