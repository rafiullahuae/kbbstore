{{--
    Product gallery — main image with a thumbnail strip beneath, matching
    kbb-product.html.

    Thumbnails are rendered server-side so they are present without JavaScript;
    the script only swaps which one is active.

    Every photograph is a real <img>. It used to be a CSS background on .gmain
    and on each .gthumb, which cost three things that matter to a shop selling
    on how its products look: a crawler treats a background as decoration, so
    not one product photograph could be indexed by image search; the browser's
    preload scanner cannot see a URL that only exists inside a style attribute,
    so the largest element on the page was not discoverable until CSS had been
    parsed; and nothing carried intrinsic dimensions.

    The frames are square by CSS (.gmain is aspect-ratio:1, .gthumb a fixed
    square) and the images are contained inside them, so the width/height here
    state the *box's* ratio, not the file's. That is deliberate — see the note
    on .gmain-img in kbb-product.css.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ProductTitle;

    $brandName = $product->brand?->name;
    $shotCount = count($gallery);
    $mainShot = $gallery[0] ?? ['image' => null, 'label' => 'Front', 'video' => false];
    $mainImage = $mainShot['image'] ?? null;
@endphp

<div class="gallery">
    <div class="gmain" id="gmain"
         style="background:{{ $mainImage ? '#fff' : Gradient::for($product->name) }}">
        @if ($mainImage)
            {{-- The LCP element: eager, high priority, never lazy. --}}
            <img class="gmain-img" id="gmainImg"
                 src="{{ $mainImage }}"
                 alt="{{ ProductTitle::alt($brandName, $product->name, 0, $shotCount) }}"
                 width="1000" height="1000"
                 loading="eager" decoding="async" fetchpriority="high">
        @endif

        {{-- Always present, hidden while a photograph is showing, so that
             swapping to a placeholder shot can reveal it again. --}}
        <span class="cap" id="gcap" data-brand="{{ $brandName }}" @if ($mainImage) hidden @endif>{{ $brandName }}<br>{{ $mainShot['label'] ?? 'Front' }}</span>

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

    @if ($shotCount > 1)
        <div class="gthumbs" id="gthumbs">
            @foreach ($gallery as $i => $shot)
                @php
                    $shotImage = $shot['image'] ?? null;
                    $shotAlt = ProductTitle::alt($brandName, $product->name, $i, $shotCount);
                @endphp
                <div class="gthumb{{ 0 === $i ? ' on' : '' }}{{ ! empty($shot['video']) ? ' vid' : '' }}"
                     data-i="{{ $i }}"
                     data-image="{{ $shotImage ?? '' }}"
                     data-label="{{ $shot['label'] ?? '' }}"
                     data-alt="{{ $shotAlt }}"
                     style="background:{{ $shotImage ? '#fff' : Gradient::for($product->name . $i) }}">
                    @if ($shotImage)
                        {{-- Below the fold on a phone and never the LCP: lazy. --}}
                        <img class="gthumb-img" src="{{ $shotImage }}" alt="{{ $shotAlt }}"
                             width="66" height="66" loading="lazy" decoding="async">
                    @else
                        {{ $shot['label'] ?? '' }}
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
