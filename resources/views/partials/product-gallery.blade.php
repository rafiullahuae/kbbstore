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
    use App\Support\ImageVariants;
    use App\Support\ProductTitle;

    $brandName = $product->brand?->name;
    $shotCount = count($gallery);
    $mainShot = $gallery[0] ?? ['image' => null, 'label' => __('store.product.gallery_front'), 'video' => false];
    $mainImage = $mainShot['image'] ?? null;

    /* THE MAIN SHOT IS THE LARGEST ASSET ON THIS PAGE, and until now every
       phone downloaded all of it. The frame it is painted into is ~350 CSS
       pixels wide on a 390px handset; the file behind it is the catalogue's
       full-size photograph.

       What is offered instead is whatever phone-sized copy of it is on disk
       right now — App\Support\ImageVariants explains why the copies are made
       when an image is uploaded rather than when a page asks for one, and why
       the answer is read off the filesystem rather than out of a column.

       detailSrcsetFor() and not srcsetFor(): the tile method deliberately
       leaves the original out of the list, which is right for a 399px tile and
       wrong for this frame — see its comment. It returns '' when there is
       nothing safe to offer, and '' is the important case: most of this
       catalogue predates the copies and some of it will never have any. With
       `w` descriptors a browser picks a candidate and never looks at `src`, so
       one missing file is a blank hero with nothing to fall back to. The
       attributes are therefore emitted only when a real file backs every
       candidate, and a product with nothing to offer renders exactly the
       markup it rendered before. */
    $mainSrcset = $mainImage ? ImageVariants::detailSrcsetFor($mainImage) : '';
@endphp

<div class="gallery">
    <div class="gmain" id="gmain"
         style="background:{{ $mainImage ? '#fff' : Gradient::for($product->name) }}">
        @if ($mainImage)
            {{-- The LCP element: eager, high priority, never lazy. --}}
            <img class="gmain-img" id="gmainImg"
                 src="{{ $mainImage }}"
                 {{-- altFor() returns the alt the owner typed for THIS image if
                      there is one, and falls back to ProductTitle::alt() when
                      there is not. Keyed by image URL rather than by position,
                      so reordering the gallery cannot slide one photograph's
                      description onto another. --}}
                 alt="{{ $product->altFor($mainImage, 0, $shotCount) }}"
                 @if ($mainSrcset !== '')
                 srcset="{{ $mainSrcset }}"
                 sizes="{{ ImageVariants::detailSizesAttribute() }}"
                 @endif
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
            <span class="{{ $modules->classFor('badge') }} lbl" style="top:14px;left:14px">{{ \App\Support\Bidi::number('-' . $off . '%') }}</span>
        @endif

        @unless ($modules->hidden('wishlist'))
            <button class="{{ $modules->classFor('wishlist') }} gwish" type="button" id="gwish"
                    data-kbb-wish="{{ $product->id }}" aria-label="{{ __('store.product.save_to_wishlist') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg>
            </button>
        @endunless
    </div>

    @if ($shotCount > 1)
        <div class="gthumbs" id="gthumbs">
            @foreach ($gallery as $i => $shot)
                @php
                    $shotImage = $shot['image'] ?? null;
                    $shotAlt = $product->altFor($shotImage, $i, $shotCount);

                    // What the 66px square itself needs: srcsetFor(), because a
                    // thumbnail has no more use for a 1000px original than a
                    // shop tile does, and so no header to read.
                    $thumbSrcset = $shotImage ? ImageVariants::srcsetFor($shotImage) : '';

                    /* AND WHAT THE MAIN FRAME WILL NEED WHEN THIS THUMBNAIL IS
                       TAPPED, which is a different list for a different box.

                       pdp.js swaps the main shot by assigning to the <img>'s
                       `src`. That was complete while the <img> had no srcset;
                       it is not any more. `srcset` OUTRANKS `src` in every
                       browser that supports it, so an <img> whose src is
                       changed and whose srcset is left alone goes on showing
                       the PREVIOUS photograph — the gallery would look broken
                       in exactly the way that is hardest to notice from the
                       server. The swap therefore has to carry the new srcset
                       with it, and this is where the server-rendered answer
                       for each shot is put so that the script does not have to
                       work one out. initGallery() reads both. */
                    $shotMainSrcset = $shotImage ? ImageVariants::detailSrcsetFor($shotImage) : '';
                @endphp
                <div class="gthumb{{ 0 === $i ? ' on' : '' }}{{ ! empty($shot['video']) ? ' vid' : '' }}"
                     data-i="{{ $i }}"
                     data-image="{{ $shotImage ?? '' }}"
                     data-srcset="{{ $shotMainSrcset }}"
                     data-sizes="{{ $shotMainSrcset === '' ? '' : ImageVariants::detailSizesAttribute() }}"
                     data-label="{{ $shot['label'] ?? '' }}"
                     data-alt="{{ $shotAlt }}"
                     style="background:{{ $shotImage ? '#fff' : Gradient::for($product->name . $i) }}">
                    @if ($shotImage)
                        {{-- Below the fold on a phone and never the LCP: lazy. --}}
                        <img class="gthumb-img" src="{{ $shotImage }}" alt="{{ $shotAlt }}"
                             @if ($thumbSrcset !== '')
                             srcset="{{ $thumbSrcset }}"
                             sizes="{{ ImageVariants::thumbSizesAttribute() }}"
                             @endif
                             width="66" height="66" loading="lazy" decoding="async">
                    @else
                        {{ $shot['label'] ?? '' }}
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
