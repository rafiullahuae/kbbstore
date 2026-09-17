@php use App\Support\Url; @endphp

<footer><div class="wrap">
    <div class="fcols">
        <div class="fcol">
            <div class="logo" style="font-size:24px;margin-bottom:12px">K-Beauty<span>Bliss</span></div>
            <p>{{ __('store.footer.tagline') }}</p>
            {{-- Both numbers now come from App\Support\SupportContact, which is
                 the one place the shipped values live. The printed line and the
                 dialled link were two different settings with two different
                 literals; they are still two accessors, because the two
                 surfaces print the number differently and always have. --}}
            <div class="fcontact">📞 {{ \App\Support\SupportContact::phone() }}</div>
            <a class="wa" href="https://wa.me/{{ \App\Support\SupportContact::whatsappDigits() }}">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Z"/></svg>
                {{ __('store.footer.whatsapp_cta') }}
            </a>
        </div>

        <div class="fcol"><h5>{{ __('store.footer.shop_heading') }}</h5>
            <a href="{{ Url::to('/shop/') }}">{{ __('store.footer.link_all_products') }}</a>
            <a href="{{ Url::to('/shop/') }}?orderby=date">{{ __('store.footer.link_new_in') }}</a>
            <a href="{{ Url::to('/shop/') }}?orderby=popularity">{{ __('store.footer.link_best_sellers') }}</a>
            <a href="{{ Url::to('/shop/') }}?on_sale=1">{{ __('store.footer.link_super_sale') }}</a>
        </div>

        <div class="fcol"><h5>{{ __('store.footer.care_heading') }}</h5>
            @forelse ($kbbFooterNav as $link)
                <a href="{{ Url::to($link['url'] ?? '/') }}">{{ $link['label'] }}</a>
            @empty
                {{-- Real paths from the live site, so these are never dead links. --}}
                <a href="{{ Url::to('/track-my-order/') }}">{{ __('store.footer.link_track_order') }}</a>
                <a href="{{ Url::to('/delivery/') }}">{{ __('store.footer.link_delivery') }}</a>
                <a href="{{ Url::to('/refund_returns/') }}">{{ __('store.footer.link_returns') }}</a>
                <a href="{{ Url::to('/faqs/') }}">{{ __('store.footer.link_faqs') }}</a>
                <a href="{{ Url::to('/contact-us/') }}">{{ __('store.footer.link_contact') }}</a>
            @endforelse
        </div>

        <div class="fcol"><h5>{{ __('store.footer.account_heading') }}</h5>
            <a href="{{ Url::to('/my-account/') }}">{{ __('store.footer.link_my_account') }}</a>
            <a href="{{ Url::to('/my-account/orders/') }}">{{ __('store.footer.link_orders') }}</a>
            <a href="{{ Url::to('/my-account/edit-address/') }}">{{ __('store.footer.link_address') }}</a>
        </div>
    </div>

    <div class="fbot">
        <div>{{ __('store.footer.copyright', ['year' => date('Y'), 'store' => $kbbSettings->get('store_name', 'K-Beauty Bliss')]) }}</div>
        <div class="fpay"><span>Tabby</span><span>Tamara</span><span>Visa</span><span>Mastercard</span><span>Apple Pay</span><span>{{ __('store.footer.pay_cod') }}</span></div>
        {{-- THE OWNER'S OWN PROFILES, NOT THE ONES THIS FILE WAS WRITTEN WITH.

             `social_instagram`, `social_tiktok` and `social_facebook` are real
             settings: validated in AdminController::SETTING_RULES, editable on
             Store → Search appearance, and already published in the schema.org
             `sameAs` node. So an owner who corrected a profile URL watched the
             structured data change and the footer of every page go on linking
             the old one — two things that must agree, disagreeing, with the
             wrong half being the one shoppers can actually click.

             The shipped literal stays as the fallback, so a shop that has never
             opened that screen keeps exactly the footer it has always had. A
             value the owner has deliberately CLEARED drops the icon instead of
             falling back, because blank is an answer: see SupportContact's
             header on why an empty row is not an absent one. --}}
        @php
            $fsoc = array_filter([
                ['Instagram', 'IG', $kbbSettings->get('social_instagram', 'https://www.instagram.com/kbeauty.bliss/')],
                ['TikTok',    'TT', $kbbSettings->get('social_tiktok',    'https://www.tiktok.com/@kbeauty.bliss')],
                ['Facebook',  'FB', $kbbSettings->get('social_facebook',  'https://www.facebook.com/kbeautyblissuae')],
            ], static fn (array $s) => trim((string) $s[2]) !== '');
        @endphp
        @if ($fsoc !== [])
        <div class="fsoc">
            @foreach ($fsoc as [$label, $short, $url])
            <a href="{{ trim((string) $url) }}" aria-label="{{ $label }}">{{ $short }}</a>
            @endforeach
        </div>
        @endif
    </div>
</div></footer>
