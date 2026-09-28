{{--
 ══════════════════════════════════════════════════════════════════════════════
  DESIGN 1 of 4 — "Compact grid". THE ONE THE PAGE ALREADY DRAWS. (Lane SF)
 ══════════════════════════════════════════════════════════════════════════════

    ▲ THE MARKUP BELOW IS LANE SP'S, MOVED AND NOT REWRITTEN. ▲

    App\Support\SetPanelDesign::DEFAULT is GRID, so this is what every set page
    on the shop renders until the owner picks another design on
    Appearance -> Set contents. CLAUDE.md rule 1: a new setting ships at the
    value the page already has, and applying the package moves nothing.

    That is why this file is a MOVE. Every class, every attribute, every
    &times; and every escape is the one that was in
    partials/set-contents-panel.blade.php before this lane; the only change is
    which file the lines sit in. The CSS did not move at all -- it is still in
    the panel's own @once block, for the same reason: rules that already reach
    the shop should not be re-ordered on the way to it.

    As many columns as fit, and no measurement: `repeat(auto-fill,
    minmax(min(100%, 150px), 1fr))`. Two at 390px, seven or eight at 1280px.
    `min(100%, 150px)` rather than a bare `150px` is what keeps the page from
    scrolling sideways when the container is narrower than the track.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ImageVariants;
    use App\Support\Money;
@endphp
    <div class="ksp-grid">
        @foreach ($kbbSetPage['members'] as $kbbSetPageMember)
            @php
                $kspName = (string) $kbbSetPageMember['name'];
                $kspBrand = (string) ($kbbSetPageMember['brand'] ?? '');
                $kspImage = $kbbSetPageMember['image'] ?? null;
                $kspSrcset = $kspImage ? ImageVariants::srcsetFor($kspImage) : '';
                $kspLink = ($kbbSetPageMember['visible'] ?? false) && ($kbbSetPageMember['url'] ?? null);
            @endphp
            <div class="ksp-m">
                @if ($kspImage)
                    <span class="ksp-ph"><img src="{{ $kspImage }}" alt="{{ $kspName }}" width="300" height="300" loading="lazy" decoding="async" @if ($kspSrcset !== '') srcset="{{ $kspSrcset }}" sizes="{{ ImageVariants::setMemberSizesAttribute() }}" @endif></span>
                @else
                    <span class="ksp-ph is-blank" style="background:{{ Gradient::for($kspBrand . $kspName) }}"></span>
                @endif
                @if ($kspBrand !== '')<span class="ksp-br">{{ $kspBrand }}</span>@endif
                @if ($kspLink)
                    <a class="ksp-nm" href="{{ $kbbSetPageMember['url'] }}">{{ $kspName }}</a>
                @else
                    <span class="ksp-nm">{{ $kspName }}</span>
                @endif
                <span class="ksp-meta">
                    <span class="ksp-q">{{ (int) $kbbSetPageMember['quantity'] }}&times;</span>
                    @if (($kbbSetPageMember['variant'] ?? '') !== '')<span>{{ $kbbSetPageMember['variant'] }}</span>@endif
                    <span class="ksp-pr">{!! Money::format((int) $kbbSetPageMember['unit']) !!}</span>
                </span>
            </div>
        @endforeach
    </div>
@include('partials.set-contents.footing')
