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
    <span class="sig-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><path d="M3 8.5h18M8.5 3l3 5.5M14 3l3 5.5"/><path d="m10.5 12 4.5 2.6-4.5 2.6z" fill="currentColor"/></svg>@if ($card['views'] !== null)<span>{{ $card['views'] }}</span><span class="sig-sr"> {{ __('store.spotted.views') }}</span>@else<span class="sig-sr">{{ __('store.spotted.reel') }}</span>@endif</span>
    <span class="sig-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg></span>
@elseif ($card['album'])
    <span class="sig-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="7" width="14" height="14" rx="3"/><path d="M17 3H6a3 3 0 0 0-3 3v11"/></svg><span class="sig-sr">{{ __('store.spotted.album') }}</span></span>
@endif
  </span>
  <span class="sig-body">
    <span class="sig-prof"><span class="sig-av">@if ($profile['avatar'] !== null)<img src="{{ $profile['avatar'] }}" alt="" width="28" height="28"@if ($sigLazy) loading="lazy"@endif decoding="async">@endif</span><b dir="ltr">{{ '@'.$profile['handle'] }}</b><svg class="sig-ig" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg></span>
    <span class="sig-cap" dir="auto">{{ $card['caption'] }}</span>
@if ($card['date'] !== null)
    <time class="sig-date" datetime="{{ $card['date'] }}">{{ \Illuminate\Support\Carbon::parse($card['date'])->translatedFormat('j M Y') }}</time>
@endif
    <span class="sig-stats">
@if ($card['likes'] !== null)
      <span class="sig-st"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 20.7 10.6 19.4C5.4 14.7 2 11.6 2 7.8 2 4.7 4.4 2.3 7.5 2.3c1.7 0 3.4.8 4.5 2.1 1.1-1.3 2.8-2.1 4.5-2.1 3.1 0 5.5 2.4 5.5 5.5 0 3.8-3.4 6.9-8.6 11.6Z"/></svg>{{ $card['likes'] }}<span class="sig-sr"> {{ __('store.spotted.likes') }}</span></span>
@endif
@if ($card['comments'] !== null)
      <span class="sig-st"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linejoin="round" aria-hidden="true"><path d="M20.7 12a8.7 8.7 0 0 1-12.9 7.6L3 21l1.4-4.6A8.7 8.7 0 1 1 20.7 12Z"/></svg>{{ $card['comments'] }}<span class="sig-sr"> {{ __('store.spotted.comments') }}</span></span>
@endif
@if ($card['shares'] !== null)
      <span class="sig-st"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linejoin="round" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>{{ $card['shares'] }}<span class="sig-sr"> {{ __('store.spotted.shares') }}</span></span>
@endif
    </span>
  </span><span class="sig-sr"> {{ $sigPlay ? __('store.spotted.play') : __('store.spotted.new_tab') }}</span>
</a>
