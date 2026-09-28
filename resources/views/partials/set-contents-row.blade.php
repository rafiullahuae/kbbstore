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
    which is Lane IM's file this round. `.ksl-ph` is 56px at every width and
    48px below 480px, so there is no viewport term to write and no `vw` to get
    wrong; 56px declared covers both, and the browser picks a variant for
    device-pixel-ratio against it — on a 3x phone a 168px source for a 48px
    box, comfortably sharp.

    A member with NO picture gets App\Support\Gradient::for(), which is a CSS
    background and not an <img>, so a broken image is not reachable — the same
    fallback a pictureless product has everywhere else in this shop.

    ── EVERY INTERPOLATION IS ESCAPED ────────────────────────────────────────

    The name, the brand and the option label are settings. The only {!! !!} is
    Money::format(), which returns markup this application builds itself.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;

    $kslName = (string) $kbbSetPageMember['name'];
    $kslBrand = (string) ($kbbSetPageMember['brand'] ?? '');
    $kslImage = $kbbSetPageMember['image'] ?? null;
    $kslSrcset = $kslImage ? ImageVariants::srcsetFor($kslImage) : '';
    $kslLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
@endphp
<div class="ksl-r">
    @if ($kslImage)
        <span class="ksl-ph"><img src="{{ $kslImage }}" alt="{{ $kslName }}" width="112" height="112" loading="lazy" decoding="async" @if ($kslSrcset !== '') srcset="{{ $kslSrcset }}" sizes="56px" @endif></span>
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
    <span class="ksl-end">
        <span class="ksl-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
        <span class="ksl-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
    </span>
</div>
