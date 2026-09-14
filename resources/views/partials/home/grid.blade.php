{{-- Product grid in the chosen skin — markup matches kbb-grid-skins.html verbatim. --}}
@php
    use App\Support\Money;
    use App\Support\Gradient;
@endphp
<div class="kbb-pgrid" data-skin="{{ $skin ?? 'classic' }}">
    @foreach ($items as $i => $p)
        @php
            $brand = $p->brand?->name ?? '';
            $sale  = $p->effectivePrice();
            $reg   = (int) $p->price;
            $off   = ($reg > 0 && $sale < $reg) ? (int) round((1 - $sale / $reg) * 100) : 0;
            $stars = (int) round((float) $p->rating);
        @endphp
        <a class="kbb-card" href="{{ $p->url() }}">
            <div class="kbb-card-thumb">
                @if ($p->image)
                    <img src="{{ $p->image }}" alt="{{ $p->name }}" loading="lazy" width="400" height="500">
                @else
                    <span class="ph2" style="background:{{ Gradient::for($brand . $p->name) }}"></span>
                @endif
                @if (($rank ?? false))<span class="kbb-badge kbb-badge-new">#{{ $i + 1 }}</span>
                @elseif (! $p->review_count)<span class="kbb-badge kbb-badge-new">New</span>@endif
                @if ($off)<span class="kbb-badge kbb-badge-sale">-{{ $off }}%</span>@endif
            </div>
            <div class="cb">
                @if (! empty($catLabel))<div class="kbb-card-cat">{{ $catLabel }}</div>@endif
                <div class="cn">@if ($brand)<span class="kbb-card-brand">{{ mb_strtoupper($brand) }}</span> @endif{{ $p->name }}</div>
                <div class="kbb-card-rate"><span class="kbb-crate">@for ($s = 1; $s <= 5; $s++)<span class="kbb-cstar{{ $s <= $stars ? ' on' : '' }}">★</span>@endfor</span> <span class="kbb-card-rc">({{ (int) $p->review_count }})</span></div>
                <div class="cp">@if ($off)<span class="kbb-card-reg">{!! Money::format($reg) !!}</span> @endif<span class="kbb-card-price">{!! Money::format($sale) !!}</span></div>
                <span class="kbb-card-cart" data-kbb-add="{{ $p->id }}" data-price="{{ number_format($p->effectivePrice() / 100, 2, '.', '') }}" data-name="{{ $p->name }}">Add to cart</span>
            </div>
        </a>
    @endforeach
</div>
