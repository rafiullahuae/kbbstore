{{--
    Product gallery — main image with a thumbnail strip beneath, matching
    kbb-product.html.

    Thumbnails are rendered server-side so they are present without JavaScript;
    the script only swaps which one is active.
--}}
@php use App\Support\Gradient; @endphp

<div class="gallery">
    <div class="gmain" id="gmain"
         style="background:{{ $gallery[0]['image'] ?? '' ? '#fff url(\'' . e($gallery[0]['image']) . '\') center/contain no-repeat' : Gradient::for($product->name) }}">
        @if (empty($gallery[0]['image']))
            <span class="cap" id="gcap">{{ $product->brand?->name }}<br>{{ $gallery[0]['label'] ?? 'Front' }}</span>
        @endif

        @php $kbbLabel = app(\App\Services\ProductLabels::class)->for($product); @endphp
        @if (! $modules->hidden('badge') && $kbbLabel)
            <span class="{{ $modules->classFor('badge') }} lbl" style="top:14px;left:14px;background:{{ $kbbLabel['colour'] }}">{{ $kbbLabel['text'] }}</span>
        @elseif (! $modules->hidden('badge') && ! app(\App\Services\SettingsService::class)->moduleEnabled('product_labels', false) && $product->badge_text)
            <span class="{{ $modules->classFor('badge') }} lbl" style="top:14px;left:14px;background:{{ $product->badge_colour ?: '#1b9e77' }}">{{ $product->badge_text }}</span>
        @elseif (! $modules->hidden('badge') && ! app(\App\Services\SettingsService::class)->moduleEnabled('product_labels', false) && $onSale && $off)
            <span class="{{ $modules->classFor('badge') }} lbl" style="top:14px;left:14px">-{{ $off }}%</span>
        @endif

        @unless ($modules->hidden('wishlist'))
            <button class="{{ $modules->classFor('wishlist') }} gwish" type="button" id="gwish"
                    data-kbb-wish="{{ $product->id }}" aria-label="Save to wishlist">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg>
            </button>
        @endunless
    </div>

    @if (count($gallery) > 1)
        <div class="gthumbs" id="gthumbs">
            @foreach ($gallery as $i => $shot)
                <div class="gthumb{{ 0 === $i ? ' on' : '' }}{{ ! empty($shot['video']) ? ' vid' : '' }}"
                     data-i="{{ $i }}"
                     data-image="{{ $shot['image'] ?? '' }}"
                     data-label="{{ $shot['label'] ?? '' }}"
                     style="background:{{ ! empty($shot['image']) ? '#fff url(\'' . e($shot['image']) . '\') center/contain no-repeat' : Gradient::for($product->name . $i) }}">
                    @if (empty($shot['image'])){{ $shot['label'] ?? '' }}@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
