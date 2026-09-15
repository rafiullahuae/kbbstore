{{--
    The Browsed tab's list. Rendered by the page and again by the one-tap add
    endpoint, so the row that just went into the bag leaves the list because the
    SERVER says it is in the bag — CheckoutController::browsed() diffs the
    viewed-products cookie against the cart — rather than because JavaScript
    guessed and deleted a node.

    That also settles the count: #kbbBrowsedList and the .bcount badge are both
    driven by this one collection, so they cannot disagree, and when the window
    of six has room a product that had been pushed out of it comes back.

    Input: $browsed.
--}}
@forelse ($browsed as $bp)@include('partials.checkout.browsed-item', ['bp' => $bp])@empty<p class="bempty">Everything you've looked at is already in your bag.</p>@endforelse
