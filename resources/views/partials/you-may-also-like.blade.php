{{--
  The best-seller block — OFF by default since Lane BC (Appearance → Product
  page → You may also like → Best sellers · Show the best-sellers block). The
  shop's best sellers, in stock first, minus every block above it; a product's
  own picks lead it while it is on.                 (Lane PS; Lane RP2; Lane BC)

  WHAT CHOOSES THE CARDS is App\Services\ProductRecs, and WHAT HE CONTROLS is
  App\Services\AlsoLikeSettings, plus each product's own picks in Catalog →
  Products → (edit) → You may also like. Drawn by
  partials/product/recs-block.blade.php, the same markup as the other blocks:
  the shop's own <x-product-card>s (cached by App\Support\CardFragments) in
  `.rel.kbb-pgrid[data-skin]`, as a carousel or a grid, per device.

  NO JAVASCRIPT DECIDES ANY SIZE. A card is `(100% − gaps) ÷ cards-in-view`
  wide in CSS (kbb-product.css, `.ymal-track`); ymal.js only scrolls.
--}}@if (($alsoLike['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => [
    'h' => 'rpf-h',
    'r' => 'rpf-r',
    'eyebrow' => null,
    'title' => $alsoLike['title'] ?? '',
    'products' => $alsoLike['products'],
    'layout' => $alsoLike['layout'] ?? \App\Services\AlsoLikeSettings::layoutFor($alsoLike['config'] ?? \App\Services\AlsoLikeSettings::defaults(), 'best'),
]])
@endif
