{{--
    One #KBeautyBliss Spotted card — design B, the polaroid frame.  (Lane HB)

    $card is SpottedPost::toCard(): an allowlist of values already checked on
    the way out (the picture's scheme, the Instagram host, the handle's
    alphabet). Nothing here reads a model column.

    A REAL <a href> on every card, so a crawler and a keyboard reach the post
    as easily as a thumb: to Instagram in a new tab (rel="noopener"), or to the
    linked product on this shop. The heart count is drawn ONLY when the owner
    typed one — `likes` is null otherwise and there is no 0 to fall back to.

    $lazy is false for the first cards of a grid above the fold, so the page's
    first pictures are not held back behind the lazy loader.
--}}
@php $sptLazy = $lazy ?? true; @endphp
<a class="spt-card" href="{{ $card['href'] }}"@if ($card['external']) target="_blank" rel="noopener"@endif>
  <span class="spt-ph"><img src="{{ $card['image'] }}" alt="{{ $card['alt'] }}" width="400" height="500"@if ($sptLazy) loading="lazy"@endif decoding="async"><span class="spt-top"><span class="spt-av" aria-hidden="true"></span><span class="spt-hd">{{ '@'.$card['handle'] }}</span></span></span>
  <span class="spt-cap"><span class="spt-txt">{{ $card['caption'] }}</span>@if ($card['likes'] !== null)<b class="spt-likes"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 20.7 10.6 19.4C5.4 14.7 2 11.6 2 7.8 2 4.7 4.4 2.3 7.5 2.3c1.7 0 3.4.8 4.5 2.1 1.1-1.3 2.8-2.1 4.5-2.1 3.1 0 5.5 2.4 5.5 5.5 0 3.8-3.4 6.9-8.6 11.6Z"/></svg>{{ $card['likes'] }}<span class="spt-sr"> {{ __('store.spotted.likes') }}</span></b>@endif</span>@if ($card['external'])<span class="spt-sr"> {{ __('store.spotted.new_tab') }}</span>@endif
</a>
