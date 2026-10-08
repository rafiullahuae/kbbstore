{{--
  Block 3 · "You may also like" — the shop's best sellers, minus blocks 1 and 2,
  a product's own picks first.                       (Lane PS; Lane RP2)

  WHAT CHOOSES THE CARDS is App\Services\ProductRecs, and WHAT HE CONTROLS is
  App\Services\AlsoLikeSettings — Appearance → Product page → You may also like
  — plus each product's own picks in Catalog → Products → (edit) → You may also
  like. Drawn by partials/product/recs-block.blade.php, the same markup as the
  other two blocks: the shop's own <x-product-card>s (cached by
  App\Support\CardFragments) in `.rel.kbb-pgrid[data-skin]`, as a carousel
  (`.ymal-track`, resources/js/kbb/ymal.js) or a grid, per device.

  NO JAVASCRIPT DECIDES ANY SIZE. A card is `(100% − gaps) ÷ cards-in-view`
  wide in CSS (kbb-product.css, `.ymal-track`); ymal.js only scrolls.
--}}@if (($alsoLike['products'] ?? collect())->isNotEmpty())
@include('partials.product.recs-block', ['kbbB' => [
    'h' => 'ymal-h',
    'r' => 'related',
    'eyebrow' => $alsoLike['wording']['eyebrow'],
    'title' => $alsoLike['wording']['title'],
    'products' => $alsoLike['products'],
    'layout' => $alsoLike['layout'] ?? \App\Services\AlsoLikeSettings::layoutFor($alsoLike['config'] ?? \App\Services\AlsoLikeSettings::defaults(), 'also'),
]])
@endif
