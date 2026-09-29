{{--
    "2-3 lines short description with fade read more." (Lane PDP)

    THREE LINES, THEN A FADE, THEN ONE TAP TO THE REST — AND NO JAVASCRIPT.

    ▲ WHY IT IS NOT `-webkit-line-clamp`. Clamp ends the third line with an
      ellipsis, and an ellipsis is a truncation mark, not a fade. He drew a fade.
      So the paragraph is capped at three line-boxes with `max-block-size:
      calc(3 * <line-height>)` and the bottom of it is dissolved with
      `mask-image` — the last line reads as light grey going to nothing, which
      is the thing in the picture.

    ▲ AND WHY IT IS NOT MEASURED. CLAUDE.md forbids JavaScript that measures
      layout, and "is this text actually longer than three lines" is the
      canonical reason a page reaches for scrollHeight. It is not asked. The cap
      and the mask are declared in CSS and paint identically whether the blurb
      runs to two lines or to nine; a short blurb simply never reaches the mask,
      so it fades nothing.

    ▲ THE TOGGLE IS A CHECKBOX, so opening it is a browser behaviour rather than
      a script: `:checked ~` lifts the cap and removes the mask in one rule.
      That also means it works with JavaScript off, which is how the rest of
      this page's text already behaves.

    ▲ THE LABEL DISAPPEARS WHEN IT IS OPEN rather than turning into "Read less".
      `store.product.read_more` is a key this shop already has, in both
      languages; "Read less" is not, and a preview is not the place to commit
      the owner's Arabic to a word he has not typed.

    ▲ $product->t('short_description'), AND THE @if ASKS THE ENGLISH COLUMN.
      Whether this product has a blurb at all is a fact about the product, not
      about the language it is being read in — the rule the shipped page's
      .bb-desc already follows.

    ONE ID PER PAGE and it is a constant, not a loop index: exactly one blurb is
    drawn on a product page, so `pvMore` cannot collide with itself.
--}}
@if ($product->short_description)
    <input class="pv-morebox" type="checkbox" id="pvMore" hidden>
    <p class="pv-blurb">{{ $product->t('short_description') }}</p>
    <label class="pv-more" for="pvMore">{{ __('store.product.read_more') }}<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></label>
@endif
