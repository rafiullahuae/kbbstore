{{--
    ONE MEMBER OF A SET, AS THE BUY COLUMN'S LIST DRAWS IT. (Lane SF)

    Its own file because it is drawn from TWO places in
    partials/set-contents-panel.blade.php — the rows that stand, and the rows
    folded into the <details> — and a second copy of it inside the disclosure
    is the copy that drifts the first time one of them is edited.

    It takes `$kbbSetPageMember`, which is one row of the array
    App\Support\SetContents::fromProduct() returns. It reads nothing else and
    decides nothing: whether this member is a link is `visible`, computed by
    SetContents::memberIsLive() off columns already in hand, and it FAILS
    CLOSED — a caller that selected a narrower column list gets no link rather
    than a link to a 404.

    ── THE PICTURE ───────────────────────────────────────────────────────────

    `sizes` IS A LITERAL HERE and not a method on App\Support\ImageVariants,
    which is Lane IM's file this round. `.ksl-ph` is 40px at every width and
    36px below 480px — the squeeze took 16px and 12px off it — so there is no
    viewport term to write and no `vw` to get wrong; 40px declared covers both,
    and the browser picks a variant for device-pixel-ratio against it — on a 3x
    phone a 120px source for a 36px box, comfortably sharp.

    A member with NO picture gets App\Support\Gradient::for(), which is a CSS
    background and not an <img>, so a broken image is not reachable — the same
    fallback a pictureless product has everywhere else in this shop.

    ── NO PER-MEMBER PRICE. (Lane PP) ────────────────────────────────────────

    The owner, looking at the list on his phone:

      "the set products list, i want super squeeze, without pricing mentioned
       for each product inside the set."

    So the third track is the QUANTITY ALONE. The price is not hidden with CSS
    and it is not printed and covered -- the span is gone, which is the only
    version of "without pricing" that is also true of the page source, of a
    screen reader, and of the text a shopper can select and paste.

    ▲ AND THE FOOTING KEEPS ITS THREE FIGURES. "Bought separately / Set price /
      You save" is the SET's own economics, not a per-member price, and it is
      the only reason the box reads as a bargain at all. It lives in
      partials/set-contents-panel.blade.php and this file never touched it.

    Money is no longer used here, so the import went with the span. A member's
    unit price is still carried by SetContents::fromProduct() for every other
    caller -- the cart row, the invoice, the order email -- and this view simply
    stopped printing it.

    ── EVERY INTERPOLATION IS ESCAPED ────────────────────────────────────────

    The name, the brand and the option label are settings. There is no {!! !!}
    on this row at all now: the one that existed was Money::format(), and it
    left with the price.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;

    $kslName = (string) $kbbSetPageMember['name'];
    $kslBrand = (string) ($kbbSetPageMember['brand'] ?? '');
    $kslImage = $kbbSetPageMember['image'] ?? null;
    $kslSrcset = $kslImage ? ImageVariants::srcsetFor($kslImage) : '';
    /*
     * `p_link_on` — Appearance -> Set -> Desktop -> "Link each member to its own
     * page". It ships ON, which is what this list does today.
     *
     * IT NARROWS AND NEVER WIDENS. `visible` still has to be true and there
     * still has to be a url, so switching the control on can never produce a
     * link to a draft — the fail-closed property SetContents::memberIsLive()
     * gives this row is preserved rather than replaced. $kbbSetAp is handed
     * down by partials/set-contents-panel.blade.php, which reads the settings
     * once per page; the `??` is there for a caller that has not, and its
     * answer is the shipped behaviour.                              (Lane SA)
     */
    $kslLink = ($kbbSetAp['p_link_on'] ?? true)
        && ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
@endphp
<div class="ksl-r">
    @if ($kslImage)
        <span class="ksl-ph"><img src="{{ $kslImage }}" alt="{{ $kslName }}" width="112" height="112" loading="lazy" decoding="async" @if ($kslSrcset !== '') srcset="{{ $kslSrcset }}" sizes="40px" @endif></span>
    @else
        <span class="ksl-ph is-blank" style="background:{{ Gradient::for($kslBrand . $kslName) }}"></span>
    @endif
    <span class="ksl-w">
        @if ($kslBrand !== '')<span class="ksl-br">{{ $kslBrand }}</span>@endif
        @if ($kslLink)
            <a class="ksl-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kslName }}</a>
        @else
            <span class="ksl-nm">{{ $kslName }}</span>
        @endif
        @if (($kbbSetPageMember['variant'] ?? '') !== '')<span class="ksl-var">{{ $kbbSetPageMember['variant'] }}</span>@endif
    </span>
    {{-- THE QUANTITY, AND NOTHING ELSE. The `.ksl-end` wrapper went with the
         price: a flex column holding one child is a box that exists to stack
         things that are no longer there, and removing it is 2px of gap and one
         element per row that the browser no longer lays out. --}}
    <span class="ksl-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
</div>
