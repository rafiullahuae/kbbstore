{{-- Shipping & Delivery and Returns Information, small, under the totals of
     the cart and the checkout. (Lane TP)

     The owner, 6 October: "also apply the links in the main footer and two
     links on cart / checkout pages". Neither page linked to any policy, so the
     two chosen are the two a shopper asks about before paying: what delivery
     costs and takes, and what happens if something is wrong. Privacy and Terms
     already have their place above Place order, in the owner's own checkout
     notice (Store → Ecommerce → Checkout), and are not repeated here.

     Constants only: two literal paths through Url::to() (base path and /ar/
     prefix right) and two interface strings the footer already uses, so the
     Arabic shop prints its own words. $on is the page's switch —
     Appearance → Cart page → Summary & trust, or Appearance → Checkout page →
     Trust & reviews. --}}
@if ($on)
<p class="kbb-pol"><a href="{{ \App\Support\Url::to('/delivery/') }}">{{ __('store.footer.link_delivery') }}</a><a href="{{ \App\Support\Url::to('/refund_returns/') }}">{{ __('store.footer.link_returns') }}</a></p>
@endif
