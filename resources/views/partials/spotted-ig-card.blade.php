{{--
    One #KBeautyBliss Spotted card drawn from OUR Instagram post.  (Lane SG)

    $card is App\Services\SpottedInstagram::card(): an allowlist — our own
    picture, a permalink rebuilt on https://www.instagram.com/ from a checked
    shortcode, counts already formatted (null = Instagram did not tell us, and
    then nothing is drawn — never a 0), plain-text caption. $profile is the
    synced handle and our copy of the avatar. Every value goes through {{ }}.

    A REAL <a href> to the post in every case, so a crawler, a keyboard and a
    browser with no script all reach Instagram. A video, with "Videos play on
    our page" on, ALSO carries data-sig-embed: the page's script opens
    Instagram's own player over the page on a tap instead. No iframe is in the
    markup — nothing loads from instagram.com until somebody taps.

    Controls: Appearance → #KBeautyBliss Spotted → From Instagram (which posts,
    in what order) and → Settings → Spotted page (source, videos, columns).
--}}
@php
    $sigLazy = $lazy ?? true;
    $sigPlay = ($play ?? true) && $card['embed'] !== null;
    $sigSet = \App\Support\ImageVariants::srcsetFor($card['image']);
    $sigAlt = __($card['video'] ? 'store.spotted.ig_alt_video' : 'store.spotted.ig_alt', ['handle' => $profile['handle']])
        .($card['caption'] !== '' ? ': '.\Illuminate\Support\Str::limit($card['caption'], 90) : '');
@endphp
<a class="sig-card" href="{{ $card['href'] }}" target="_blank" rel="noopener"@if ($sigPlay) data-sig-embed="{{ $card['embed'] }}"@endif>
  <span class="sig-ph"><img src="{{ $card['image'] }}"@if ($sigSet !== '') srcset="{{ $sigSet }}" sizes="{{ $sizes }}"@endif alt="{{ $sigAlt }}" width="400" height="500"@if ($sigLazy) loading="lazy"@endif decoding="async">
@if ($card['video'])
    <span class="sig-badge"><svg aria-hidden="true"><use href="#sgi-reel"/></svg>@if ($card['views'] !== null)<span>{{ $card['views'] }}</span><span class="sig-sr"> {{ __('store.spotted.views') }}</span>@else<span class="sig-sr">{{ __('store.spotted.reel') }}</span>@endif</span>
    <span class="sig-play" aria-hidden="true"><svg><use href="#sgi-play"/></svg></span>
@elseif ($card['album'])
    <span class="sig-badge"><svg aria-hidden="true"><use href="#sgi-album"/></svg><span class="sig-sr">{{ __('store.spotted.album') }}</span></span>
@endif
  </span>
  <span class="sig-body">
    <span class="sig-prof"><span class="sig-av">@if ($profile['avatar'] !== null)<img src="{{ $profile['avatar'] }}" alt="" width="28" height="28"@if ($sigLazy) loading="lazy"@endif decoding="async">@endif</span><b dir="ltr">{{ '@'.$profile['handle'] }}</b><svg class="sig-ig" aria-hidden="true"><use href="#sgi-ig"/></svg></span>
    <span class="sig-cap" dir="auto">{{ $card['caption'] }}</span>
@if ($card['date'] !== null)
    <time class="sig-date" datetime="{{ $card['date'] }}">{{ \Illuminate\Support\Carbon::parse($card['date'])->translatedFormat('j M Y') }}</time>
@endif
    <span class="sig-stats">
@if ($card['likes'] !== null)
      <span class="sig-st"><svg aria-hidden="true"><use href="#sgi-heart"/></svg>{{ $card['likes'] }}<span class="sig-sr"> {{ __('store.spotted.likes') }}</span></span>
@endif
@if ($card['comments'] !== null)
      <span class="sig-st"><svg aria-hidden="true"><use href="#sgi-com"/></svg>{{ $card['comments'] }}<span class="sig-sr"> {{ __('store.spotted.comments') }}</span></span>
@endif
@if ($card['shares'] !== null)
      <span class="sig-st"><svg aria-hidden="true"><use href="#sgi-share"/></svg>{{ $card['shares'] }}<span class="sig-sr"> {{ __('store.spotted.shares') }}</span></span>
@endif
    </span>
  </span><span class="sig-sr"> {{ $sigPlay ? __('store.spotted.play') : __('store.spotted.new_tab') }}</span>
</a>
