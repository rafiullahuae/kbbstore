{{-- One batch of a listing for "Load more on scroll" (Lane PI-B). The same
     card the page draws; see App\Support\ListingBatch for what else is sent. --}}
@foreach ($products as $product)
<x-product-card :product="$product" :cat-label="$catLabel" />
@endforeach
