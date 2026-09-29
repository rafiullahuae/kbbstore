@php use App\Support\Url; @endphp

<footer><div class="wrap">
    <div class="fcols">
        <div class="fcol">
            <div class="logo" style="font-size:24px;margin-bottom:12px"><bdi>K-Beauty<span>Bliss</span></bdi></div>
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
        {{-- THE CHIPS ARE A CLAIM, SO THEY ARE ASKED RATHER THAN TYPED.

             This row printed "Apple Pay" on a shop that had no Apple Pay: no
             gateway, no button that did anything, no way to take the payment.
             The names now come from App\Support\PaymentChips, which gates the
             two wallets on App\Services\Payments\Wallets — one answer, one
             place, read the same way by the basket and the product page.

             In PHP and not as five @ifs here, for the reason
             CartPage::paymentMarks() already gives: they are company names, a
             translated one is a different company, and a template full of them
             is what StorefrontStringsAreKeyedTest exists to catch. COD stays
             in the template because it is an English abbreviation and is
             keyed. --}}<div class="fpay">@foreach (\App\Support\PaymentChips::row('footer') as $kbbChip)<span>{{ $kbbChip }}</span>@endforeach<span>{{ __('store.footer.pay_cod') }}</span></div>
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
            /*
             * SafeUrl::href($u, '') AND NOT ($u, '#').
             *
             * A refused address answers the EMPTY STRING here, so the filter
             * below — which is already the "the owner cleared this" rule — drops
             * the icon entirely. '#' would pass that filter and draw an
             * Instagram icon that links to the page it sits on, which is worse
             * than no icon: it looks like a working control.
             *
             * These three are operator-typed rather than imported, so this is
             * the weaker of the two cases. It is still worth the call: the
             * Appearance field takes free text, `javascript:` holds no
             * character {{ }} escapes and so reaches the href byte for byte,
             * and a shipped default is returned unchanged by href() — so every
             * footer that renders today renders the same bytes.
             */
            $fsoc = array_filter([
                ['Instagram', 'IG', $kbbSettings->get('social_instagram', 'https://www.instagram.com/kbeauty.bliss/')],
                ['TikTok',    'TT', $kbbSettings->get('social_tiktok',    'https://www.tiktok.com/@kbeauty.bliss')],
                ['Facebook',  'FB', $kbbSettings->get('social_facebook',  'https://www.facebook.com/kbeautyblissuae')],
            ], static fn (array $s) => \App\Support\SafeUrl::href((string) $s[2], '') !== '');
        @endphp
        @if ($fsoc !== [])
        <div class="fsoc">
            @foreach ($fsoc as [$label, $short, $url])
            <a href="{{ \App\Support\SafeUrl::href((string) $url, '') }}" aria-label="{{ $label }}">{{ $short }}</a>
            @endforeach
        </div>
        @endif
    </div>
</div></footer>
