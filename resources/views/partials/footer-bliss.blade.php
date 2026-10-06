{{--
    The site footer — the approved design (docs/home-preview/footer-final.html,
    design C reworked).                                              (Lane HB)

    Master plan row 55, "Owner's picks, 3 Oct": "WhatsApp-green help strip
    drifting within the green range, 'Find your perfect K-beauty match' + '24/7
    available', no word 'return' anywhere, shorter on both devices … and a very
    big centred 'K-Beauty Bliss' in light, slowly changing colours at the
    bottom". Controls: Appearance → Footer → the seven "Site footer" tabs.

    Every value comes from App\Services\SiteFooter::view(): the wordmark from
    Appearance → Header, WhatsApp from SupportContact, the profiles from the
    social_* settings through SafeUrl, the Help column from the footer menu with
    anything about returns taken out, the chips from PaymentChips. Nothing about
    the shop is typed into this file.

    The motion is CSS only (kbb.css, .kft-*), and stops for a visitor who asks
    for reduced motion. The big name is sized from its LENGTH, printed as an
    integer into --kft-n, so it fits one line without any script measuring it.

    LANE HF, 4 October (the owner: "in mobile footer, remove the top logo …
    third column will be Account … i don't like the green … shiny bar going
    from left to right and on arabic right to left, also give control for
    complete footer, for desktop and mobile"). The <footer> carries its
    classes and --kft-* properties from SiteFooter::presentation() — literals,
    checked hex colours and clamped integers only — and the three link columns
    come from SiteFooter::columns(), the third one "Account". What shows on
    each device is a kft-xd-* / kft-xm-* class; the markup is the same on both.

    LANE FB, 6 October: the app row (.kfa) under the big name and above the
    bottom bar — the owner: "the frosted glass design is fine, but i don't want
    to cover the logo, it should downside the big logo". Printed only when
    SiteFooter::app() says so (Appearance → Footer → App row, and App → Site
    App on); its behaviour is in resources/site-app/site-app.js, which only
    pages with the Site App carry. The install sheets wait in a <template>, so
    opening one costs no request.

    LANE UA, 6 October: data-kfa-up, printed only once the owner has published
    an update (App → Site App → App update). Inside the installed app only,
    site-app.js turns this same row into "Update App"; a browser tab is
    unchanged, and until the first publish the page is byte for byte the same.

    Every class is kft- and nothing else on the shop uses that prefix. The
    <footer> element keeps its role; `footer.kft` out-specifies the old dark
    `footer{}` rule in kbb.css without touching it, so the classic design still
    draws exactly as it did.
--}}
@php
    use App\Support\Url;
    $kft = app(\App\Services\SiteFooter::class)->view($kbbFooterNav ?? []);
    $kftIcons = [
        'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>',
        'tiktok' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 3h3a4.5 4.5 0 0 0 4 4v3a7.4 7.4 0 0 1-4-1.3V15a6 6 0 1 1-6-6v3.1A3 3 0 1 0 14 15Z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5H16l.5-3h-3V8.6c0-.9.3-1.6 1.6-1.6h1.6V4.3A21 21 0 0 0 14.3 4C12 4 10.5 5.4 10.5 8v2.5H8v3h2.5V21Z"/></svg>',
        'youtube' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M22 8.2a3 3 0 0 0-2.1-2.1C18 5.6 12 5.6 12 5.6s-6 0-7.9.5A3 3 0 0 0 2 8.2 31 31 0 0 0 1.6 12 31 31 0 0 0 2 15.8a3 3 0 0 0 2.1 2.1c1.9.5 7.9.5 7.9.5s6 0 7.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .4-3.8 31 31 0 0 0-.4-3.8ZM10 15V9l5.2 3Z"/></svg>',
    ];
    $kftPin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
    $kftVisit = $kft['dubai'] !== '' || $kft['korea'] !== '';
    $kftContact = $kftVisit || $kft['news'];
@endphp
<footer class="{{ $kft['classes'] }}" style="{{ $kft['style'] }}">
@if ($kft['help_on'])
  <div class="kft-help"><div class="kft-wrap kft-help-in">
    <span class="kft-help-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11.5a8 8 0 0 1-11.8 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1.2-1.4-1.9-1-1 .9a3.6 3.6 0 0 1-2.4-2.4l.9-1-1-1.9Z" fill="#fff" stroke="none"/></svg></span>
    <div class="kft-help-tx">
      <h2 class="kft-help-h">{{ $kft['help_title'] }} <span class="kft-avail">{{ $kft['help_chip'] }}</span></h2>
      <p class="kft-help-p">{{ $kft['help_sub'] }}</p>
    </div>
@if ($kft['wa'] !== '' || $kft['track'] !== '')
    <div class="kft-help-bt">@if ($kft['wa'] !== '')<a class="kft-bt-p" href="{{ $kft['wa'] }}" target="_blank" rel="noopener">{{ __('store.footer.whatsapp_cta') }}</a>@endif @if ($kft['track'] !== '')<a class="kft-bt-o" href="{{ $kft['track'] }}">{{ __('store.footer.link_track_order') }}</a>@endif</div>
@endif
  </div></div>
@endif
  <div class="kft-main"><div class="kft-wrap">
    <div class="kft-grid{{ $kftContact ? '' : ' kft-grid-4' }}">
      <div class="kft-brand">
        <a class="kft-logo" href="{{ Url::to('/') }}"><bdi>{{ $kft['logo'][0] }}<b>{{ $kft['logo'][1] }}</b></bdi></a>
        <p class="kft-tag">{{ __('store.footer.tagline') }}</p>
@if ($kft['socials'] !== [])
        <div class="kft-soc" aria-label="{{ __('store.footer.follow_label') }}">@foreach ($kft['socials'] as [$kftKey, $kftName, $kftUrl])<a href="{{ $kftUrl }}" aria-label="{{ $kftName }}" target="_blank" rel="noopener">{!! $kftIcons[$kftKey] !!}</a>@endforeach</div>
@endif
      </div>
@foreach ($kft['columns'] as $kftCol)
      <nav class="kft-col kft-{{ $kftCol['part'] }}" aria-labelledby="kft-{{ $kftCol['id'] }}"><h2 id="kft-{{ $kftCol['id'] }}" class="kft-ch">{{ $kftCol['title'] }}</h2><ul>
@foreach ($kftCol['links'] as $kftLink)
        <li><a href="{{ $kftLink['url'] }}">{{ $kftLink['label'] }}</a></li>
@endforeach
      </ul></nav>
@endforeach
@if ($kftContact)
      <div class="kft-contact">
@if ($kftVisit)
        <h2 class="kft-ch">{{ __('store.footer.visit_heading') }}</h2>
        <div class="kft-visit">
@if ($kft['dubai'] !== '')
          <div>{!! $kftPin !!}<span><b>{{ __('store.footer.visit_dubai') }}</b>{{ $kft['dubai'] }}</span></div>
@endif
@if ($kft['korea'] !== '')
          <div>{!! $kftPin !!}<span><b>{{ __('store.footer.visit_korea') }}</b>{{ $kft['korea'] }}</span></div>
@endif
        </div>
@endif
@if ($kft['news'])
        <form class="kft-news" method="post" action="{{ Url::to('/api/subscribe') }}" data-kbb-subscribe>@csrf<input type="email" name="email" required placeholder="{{ __('store.footer.news_placeholder') }}" aria-label="{{ __('store.footer.news_label') }}" autocomplete="email"><button type="submit">{{ __('store.footer.news_button') }}</button></form>
@endif
      </div>
@endif
    </div>
@if ($kft['name'] !== '')
    <p class="kft-name" aria-hidden="true" style="--kft-n:{{ max(8, mb_strlen($kft['name'])) }}"@if ($kft['name_sheen']) data-kft-text="{{ $kft['name'] }}"@endif>{{ $kft['name'] }}</p>
@endif
  </div></div>
@if ($kft['app'])
@php($kfa = $kft['app'])
  <section class="kfa{{ $kfa['laptop'] ? ' kfa-lt' : '' }}" aria-labelledby="kfa-h" data-kfa="{{ $kfa['seq'] }}"@if ($kfa['update'] !== '') data-kfa-up="{{ $kfa['update'] }}"@endif{{ $kfa['sheet'] ? ' data-kfa-help=sheet' : '' }}><div class="kft-wrap"><div class="kfa-g">
    <span class="kfa-ic"><i role="img" aria-label="{{ __('store.footer.app_ic_apple') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#000" d="M16.4 12.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9-.8 0-1.8-.8-3-.8-1.5 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2-1.1 2.8-2.3.9-1.3 1.2-2.5 1.3-2.6-.1 0-2.5-.9-2.5-3.8ZM14.2 5.9c.6-.8 1.1-1.8 1-2.9-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.7-1 2.8 1 .1 2-.5 2.7-1.3Z"/></svg></i><i role="img" aria-label="{{ __('store.footer.app_ic_android') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#3DDC84" fill-rule="evenodd" d="M2 19.5a10 10 0 0 1 20 0ZM7.8 14.4a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Zm8.4 0a1.2 1.2 0 1 0 0 2.4 1.2 1.2 0 0 0 0-2.4Z"/><path stroke="#3DDC84" stroke-width="1.5" stroke-linecap="round" d="M6.6 11.8 4.4 8.4m13 3.4 2.2-3.4"/></svg></i><i role="img" aria-label="{{ __('store.footer.app_ic_ipad') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><defs><linearGradient id="kfa-scr" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F7A8BF"/><stop offset=".55" stop-color="#C9B3F0"/><stop offset="1" stop-color="#8FD3F4"/></linearGradient></defs><rect x="4" y="1.8" width="16" height="20.4" rx="2.6" fill="#3A3A3C"/><rect x="5.5" y="3.3" width="13" height="17.4" rx="1.3" fill="url(#kfa-scr)"/></svg></i></span>
    <p class="kfa-tx"><b id="kfa-h">{{ $kfa['title'] }}</b><span class="kfa-ln"><span class="kfa-ty" aria-hidden="true" lang="{{ $kfa['first'][0] ? 'ar' : 'en' }}" dir="{{ $kfa['first'][0] ? 'rtl' : 'ltr' }}">{{ $kfa['first'][1] }}</span><span class="kfa-vh">{{ implode(' ', $kfa['own']) }}</span></span></p>
    <button type="button" class="kfa-bt"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 19.5h14"/></svg><span>{{ $kfa['button'] }}</span></button>
  </div></div><template class="kfa-tpl" data-close="{{ __('store.footer.app_close') }}" data-hint="{{ $kfa['hints'] }}">
    <div data-s="ios"><h2>{{ __('store.footer.app_ios_title') }}</h2><ol><li><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M8 10H6.5A1.5 1.5 0 0 0 5 11.5v8A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5v-8a1.5 1.5 0 0 0-1.5-1.5H16"/></svg></i><span>{{ __('store.footer.app_ios_1') }}</span></li><li><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M12 8v8M8 12h8"/></svg></i><span>{{ __('store.footer.app_ios_2') }}</span></li></ol></div>
    <div data-s="and"><h2>{{ __('store.footer.app_and_title') }}</h2><ol><li><i><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></i><span>{{ __('store.footer.app_and_1') }}</span></li><li><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 19.5h14"/></svg></i><span>{{ __('store.footer.app_and_2') }}</span></li></ol></div>
    <div data-s="inapp"><h2>{{ __('store.footer.app_inapp_title') }}</h2><ol><li><i><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></i><span>{{ __('store.footer.app_inapp_1') }}</span></li><li><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5Z"/></svg></i><span>{{ __('store.footer.app_inapp_2') }}</span></li></ol></div>
@if ($kfa['qr'] !== '')
    <div data-s="qr"><h2>{{ __('store.footer.app_qr_title') }}</h2><span class="kfa-qr">{!! $kfa['qr'] !!}</span><p>{{ __('store.footer.app_qr_1') }}</p></div>
@endif
  </template></section>
@endif
  <div class="kft-bot"><div class="kft-wrap kft-bot-in">
    <span>{{ __('store.footer.copyright', ['year' => date('Y'), 'store' => $kbbSettings->get('store_name', 'K-Beauty Bliss')]) }}</span>
    <nav class="kft-legal"><a href="{{ Url::to('/privacy-policy/') }}">{{ __('store.footer.link_privacy') }}</a><a href="{{ Url::to('/terms-and-conditions/') }}">{{ __('store.footer.link_terms') }}</a></nav>
    <div class="kft-pay">@foreach (\App\Support\PaymentChips::row('footer') as $kftChip)<span>{{ $kftChip }}</span>@endforeach<span>{{ __('store.footer.pay_cod') }}</span></div>
  </div></div>
</footer>
