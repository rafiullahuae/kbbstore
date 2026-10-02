{{--
    The share button in the title row — Lane QA, by contract with Lane QB.

      "the share icon will also desktop beside the title on right side."

    THE BUTTON IS THIS LANE'S AND THE SHEET IS QB'S. It is a real <button>
    naming the dialog it opens (`aria-controls="pdpShareSheet"`, the id of the
    sheet in partials/product/share-sheet.blade.php, @includeIf'd once at the
    foot of the product page); QB's script binds `[data-share-open]`. Until
    that lands the button is inert — `type="button"`, so it submits nothing.

    The glyph is a constant: three dots joined by two lines (top-right,
    middle-left, bottom-right), stroked in currentColor at 22px. The tap target
    is 40px square in kbb-product.css.
--}}<button type="button" class="pdp-share-btn" data-share-open aria-haspopup="dialog" aria-controls="pdpShareSheet" aria-label="{{ __('store.product.share_open') }}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="18" cy="5" r="2.6"/><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="19" r="2.6"/><path d="m8.3 10.8 7.4-4.4M8.3 13.2l7.4 4.4"/></svg></button>
