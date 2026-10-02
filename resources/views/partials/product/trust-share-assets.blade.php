{{--
    The stylesheet and the script for the three trust and share blocks — Lane PW.

    @once, because up to three partials include this and the page needs one
    <link> and one <script>. Pushed onto the layout's `styles` stack, so it
    lands in the <head> only on a page that draws at least one of the blocks:
    a product page with all three switched off loads neither file.

    A Vite entry of its own rather than more rules in kbb-product.css or more
    code in app.js: app.js is on every page of the shop, and another lane holds
    kbb-product.css this round. The script is a module, so it runs after the
    document is parsed without a DOMContentLoaded wait.
--}}@once
@push('styles')
    @vite(['resources/css/kbb/kbb-pdp-trust.css', 'resources/js/kbb/pdp-trust.js'])
@endpush
@endonce
