{{--
    Site header.

    Every part is switchable from Appearance → Header; appearance arrives as
    custom properties on the element, so the stylesheet stays static and
    cacheable and only this short block differs per shop.
--}}
@php
    use App\Support\Url;
    $hd = app(\App\Services\HeaderSettings::class);
    $h = $hd->all();
    /*
     * The chip is a WhatsApp button, so it DIALS the WhatsApp number and PRINTS
     * the number the rest of the chrome prints. Both used to come from
     * `whatsapp` — a setting nothing seeds and nothing writes, so the literal
     * beside it was what every shop showed. See App\Support\SupportContact.
     */
    $whatsapp = \App\Support\SupportContact::phone();
    $whatsappDigits = \App\Support\SupportContact::whatsappDigits();
@endphp

@php
    // Phone spacing and the divider above the search field, from
    // Appearance → Mobile Header. Everything it sets is read inside the existing
    // 900px media query, so the desktop header is untouched by it.
    $mhd = app(\App\Services\MobileHeader::class);
@endphp
<header class="{{ $hd->bodyClass() }}{{ $h['search_group_rule'] ? ' sg-rule' : '' }}{{ $h['search_row_rule'] ? ' sg-rowrule' : '' }}{{ 'compact' === $h['search_row_size'] ? ' sg-compact' : '' }} {{ $mhd->bodyClass() }}"
        style="{{ $hd->cssVariables() }};{{ $mhd->cssVariables() }}">
  <div class="wrap">
    <div class="hin">
      @include('partials.menu-icon')

      <a class="logo" href="{{ Url::to('/') }}">{{ $h['logo_text'] }}<span>{{ $h['logo_accent'] }}</span></a>

      @if ($h['search_show'])
        {{-- The script binds to `.search-in input[type=search]` and renders into
             `#kbbSuggest`; the stylesheet dresses `.sugg`. All three have to be
             here or suggestions silently never appear. --}}
        <form class="sbox" method="get" action="{{ Url::to('/shop/') }}" role="search">
          <div class="search-in {{ 'compact' === $h['search_row_size'] ? 'rs-compact' : 'rs-regular' }}{{ $h['search_native_clear'] ? '' : ' no-native-clear' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
            <input type="search" name="s" autocomplete="off" aria-label="Search"
                   value="{{ request('s') }}"
                   placeholder="{{ str_replace('{n}', number_format($kbbProductCount ?? 0), $h['search_text']) }}">
          </div>
          <div class="sugg" id="kbbSuggest" role="listbox" aria-label="Suggestions"
               data-brands-phone="{{ $h['search_brands_phone'] ? '1' : '0' }}"
               data-max="{{ (int) $h['search_results_max'] }}"></div>
        </form>
      @endif

      <div class="hact">
        @if ($h['icon_account'])
          @php
              // A panel needs someone to show it to. Guests go to the sign-in
              // page unless the shop has asked for the form in a panel.
              // The panel's own screen decides what a guest gets, so there is one
              // answer rather than two settings that could disagree.
              $apGuest = 'panel' === app(\App\Services\AccountPanel::class)->get('guest_mode');
              $acctPanel = $h['account_menu'] && (auth()->guard()->check() || $apGuest);
          @endphp
          <span class="ib-acct" @if ($acctPanel) data-acct data-acct-open="{{ app(\App\Services\AccountPanel::class)->get('panel_open') }}" @endif>
            <a class="ib{{ auth()->guard()->check() && $h['account_dot'] ? ' in' : '' }}"
               href="{{ Url::to('/my-account/') }}" aria-label="Account"
               @if ($acctPanel) aria-haspopup="true" aria-expanded="false" @endif>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
            </a>
            @if ($acctPanel)@include('partials.account-panel')@endif
          </span>
        @endif

        @if ($h['icon_wishlist'])
          <a class="ib" href="{{ Url::to('/my-wishlist/') }}" aria-label="Wishlist"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 14c1.5-1.5 3-3.4 3-5.5A4.5 4.5 0 0 0 12 5 4.5 4.5 0 0 0 2 8.5C2 12 5 14.5 12 21c7-6.5 7-7 7-7z"/></svg><i id="kbbWishCt" style="display:{{ ($kbbWishlistCount ?? 0) > 0 ? '' : 'none' }}">{{ $kbbWishlistCount ?? 0 }}</i></a>
        @endif

        @if ($h['icon_cart'])
          <a class="ib" href="{{ Url::to('/cart/') }}" data-kbb-open="cart" data-kbb-cart aria-label="Cart"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/></svg>@if (($kbbCartCount ?? 0) > 0)<i id="cartCt">{{ $kbbCartCount }}</i>@endif</a>
        @endif
      </div>

      @if ($h['support_show'])
        <div class="hinfo">
          <a class="hi hiwa" href="https://wa.me/{{ $whatsappDigits }}">
            <span class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Zm0 18a8 8 0 0 1-4.1-1.1l-.3-.2-2.8.7.8-2.8-.2-.3A8 8 0 1 1 12 20Zm4.4-6c-.2-.1-1.4-.7-1.6-.8-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1a6.5 6.5 0 0 1-3.2-2.8c-.1-.2 0-.4.1-.5l.4-.5c.1-.1.1-.3 0-.4l-.7-1.7c-.2-.4-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2c0 1.3 1 2.6 1.1 2.8.1.2 1.9 3 4.7 4.1 1.7.6 2.3.7 3.1.6.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1 0-.1-.2-.2-.4-.3Z"/></svg></span>
            <span class="tx"><b>{{ $h['support_label'] }}</b><span>{{ $whatsapp }}</span></span>
          </a>
        </div>
      @endif
    </div>

    @if ($h['trending_show'] && ! empty($kbbTrending))
      <div class="trend"><b>TRENDING</b>
        @foreach (array_slice($kbbTrending, 0, (int) $h['trending_limit']) as $term)
          <a href="{{ Url::to('/shop/') }}?s={{ urlencode($term) }}">{{ $term }}</a>
        @endforeach
      </div>
    @endif
  </div>

  @if ($h['nav_show'])
    @include('partials.nav-bar')
  @endif
</header>
