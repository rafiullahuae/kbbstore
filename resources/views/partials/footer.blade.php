@php use App\Support\Url; @endphp

<footer><div class="wrap">
    <div class="fcols">
        <div class="fcol">
            <div class="logo" style="font-size:24px;margin-bottom:12px">K-Beauty<span>Bliss</span></div>
            <p>Authentic Korean beauty, curated for the UAE.</p>
            {{-- Both numbers now come from App\Support\SupportContact, which is
                 the one place the shipped values live. The printed line and the
                 dialled link were two different settings with two different
                 literals; they are still two accessors, because the two
                 surfaces print the number differently and always have. --}}
            <div class="fcontact">📞 {{ \App\Support\SupportContact::phone() }}</div>
            <a class="wa" href="https://wa.me/{{ \App\Support\SupportContact::whatsappDigits() }}">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15l-1.3 4.7 4.8-1.3A10 10 0 1 0 12 2Z"/></svg>
                Chat on WhatsApp
            </a>
        </div>

        <div class="fcol"><h5>Shop</h5>
            <a href="{{ Url::to('/shop/') }}">All products</a>
            <a href="{{ Url::to('/shop/') }}?orderby=date">New in</a>
            <a href="{{ Url::to('/shop/') }}?orderby=popularity">Best sellers</a>
            <a href="{{ Url::to('/shop/') }}?on_sale=1">Super sale</a>
        </div>

        <div class="fcol"><h5>Customer Care</h5>
            @forelse ($kbbFooterNav as $link)
                <a href="{{ Url::to($link['url'] ?? '/') }}">{{ $link['label'] }}</a>
            @empty
                {{-- Real paths from the live site, so these are never dead links. --}}
                <a href="{{ Url::to('/track-my-order/') }}">Track my order</a>
                <a href="{{ Url::to('/delivery/') }}">Shipping &amp; Delivery</a>
                <a href="{{ Url::to('/refund_returns/') }}">Returns Information</a>
                <a href="{{ Url::to('/faqs/') }}">FAQs</a>
                <a href="{{ Url::to('/contact-us/') }}">Contact us</a>
            @endforelse
        </div>

        <div class="fcol"><h5>My Account</h5>
            <a href="{{ Url::to('/my-account/') }}">My Account</a>
            <a href="{{ Url::to('/my-account/orders/') }}">Orders</a>
            <a href="{{ Url::to('/my-account/edit-address/') }}">Shipping Address</a>
        </div>
    </div>

    <div class="fbot">
        <div>© {{ date('Y') }} {{ $kbbSettings->get('store_name', 'K-Beauty Bliss') }} UAE</div>
        <div class="fpay"><span>Tabby</span><span>Tamara</span><span>Visa</span><span>Mastercard</span><span>Apple Pay</span><span>COD</span></div>
        <div class="fsoc">
            <a href="https://www.instagram.com/kbeauty.bliss/" aria-label="Instagram">IG</a>
            <a href="https://www.tiktok.com/@kbeauty.bliss" aria-label="TikTok">TT</a>
            <a href="https://www.facebook.com/kbeautyblissuae" aria-label="Facebook">FB</a>
        </div>
    </div>
</div></footer>
