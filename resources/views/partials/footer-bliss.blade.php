{{--
    The site footer — the approved design (docs/home-preview/footer-final.html,
    design C reworked).                                              (Lane HB)

    Master plan row 55, "Owner's picks, 3 Oct": "WhatsApp-green help strip
    drifting within the green range, 'Find your perfect K-beauty match' + '24/7
    available', no word 'return' anywhere, shorter on both devices … and a very
    big centred 'K-Beauty Bliss' in light, slowly changing colours at the
    bottom". Controls: Appearance → Footer → the three "Site footer" tabs.

    Every value comes from App\Services\SiteFooter::view(): the wordmark from
    Appearance → Header, WhatsApp from SupportContact, the profiles from the
    social_* settings through SafeUrl, the Help column from the footer menu with
    anything about returns taken out, the chips from PaymentChips. Nothing about
    the shop is typed into this file.

    The motion is CSS only (kbb.css, .kft-*), and stops for a visitor who asks
    for reduced motion. The big name is sized from its LENGTH, printed as an
    integer into --kft-n, so it fits one line without any script measuring it.

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
<footer class="kft{{ $kft['motion'] ? ' kft-motion' : '' }}">
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
      <nav class="kft-col" aria-labelledby="kft-shop"><h2 id="kft-shop" class="kft-ch">{{ __('store.footer.shop_heading') }}</h2><ul>
        <li><a href="{{ Url::to('/new-in/') }}">{{ __('store.footer.link_new_in') }}</a></li>
        <li><a href="{{ Url::to('/best-sellers/') }}">{{ __('store.footer.link_best_sellers') }}</a></li>
@if ($kft['brands'])
        <li><a href="{{ Url::to('/brands/') }}">{{ __('store.footer.link_brands') }}</a></li>
@endif
        <li><a href="{{ Url::to('/super-sale/') }}">{{ __('store.footer.link_super_sale') }}</a></li>
      </ul></nav>
      <nav class="kft-col" aria-labelledby="kft-help"><h2 id="kft-help" class="kft-ch">{{ __('store.footer.help_heading') }}</h2><ul>
@foreach ($kft['help_links'] as $kftLink)
        <li><a href="{{ $kftLink['url'] }}">{{ $kftLink['label'] }}</a></li>
@endforeach
      </ul></nav>
      <nav class="kft-col" aria-labelledby="kft-disc"><h2 id="kft-disc" class="kft-ch">{{ __('store.footer.discover_heading') }}</h2><ul>
        <li><a href="{{ Url::to('/about/') }}">{{ __('store.footer.link_about') }}</a></li>
        <li><a href="{{ Url::to('/blog/') }}">{{ __('store.footer.link_journal') }}</a></li>
        <li><a href="{{ Url::to(\App\Services\SpottedSettings::URL) }}">{{ __('store.footer.link_spotted') }}</a></li>
        <li><a href="{{ Url::to('/my-account/') }}">{{ __('store.footer.link_my_account') }}</a></li>
      </ul></nav>
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
    <p class="kft-name" aria-hidden="true" style="--kft-n:{{ max(8, mb_strlen($kft['name'])) }}">{{ $kft['name'] }}</p>
@endif
  </div></div>
  <div class="kft-bot"><div class="kft-wrap kft-bot-in">
    <span>{{ __('store.footer.copyright', ['year' => date('Y'), 'store' => $kbbSettings->get('store_name', 'K-Beauty Bliss')]) }}</span>
    <nav class="kft-legal"><a href="{{ Url::to('/privacy-policy/') }}">{{ __('store.footer.link_privacy') }}</a><a href="{{ Url::to('/terms-and-conditions/') }}">{{ __('store.footer.link_terms') }}</a></nav>
    <div class="kft-pay">@foreach (\App\Support\PaymentChips::row('footer') as $kftChip)<span>{{ $kftChip }}</span>@endforeach<span>{{ __('store.footer.pay_cod') }}</span></div>
  </div></div>
</footer>
