{{--
    "Product tabs" — ONE grid section drawn as tabs.                (Lane HTB)

    The owner: "i need Tabs section on homepage like different tabs in one
    section, like Best Sellers, Trending K-Beauty, New Arrivals etc." —
    "Options A and B are final. give us also the align on left or center." —
    "need also for arabic too".

    Included ONLY from partials/home/grid-section.blade.php, after its PHP
    block, for a section whose `tabs` column holds tabs — so every variable that
    block computed ($gsClass, $gsStyle, $gsOpen/$gsClose, the counts, the skin,
    the card label) is the grid's own, and an ordinary grid prints the bytes it
    always printed. The panels come from GridSections::withPanels(), on the
    model as the `tabPanels` relation, inside the homepage's existing cache.

    EVERY PANEL IS DRAWN ONCE, ON THE SERVER, WITH THE REAL CARD. The first is
    open; the others carry `hidden` (display:none), so their lazy pictures are
    not fetched until a shopper opens them. Switching is the shop's existing
    [data-rp-tabs] code in resources/js/kbb/ymal.js — role=tab/tabpanel,
    aria-selected, roving tabindex, ←/→ mirrored on /ar: no new script and no
    request. Each panel is its own [data-ymal] carousel when arrows are on.

    Words are the owner's, printed escaped: the Arabic twin on /ar when he
    typed one, the English otherwise (GridTabs::pick) — never a blank tab. The
    style (A underline / B pills) and the alignment arrive as classes from the
    allowlists in App\Support\GridTabs, never as typed text.
--}}
@php
    $gtCfg = \App\Support\GridTabs::of($section);
    $gtStyle = \App\Support\GridTabs::style($section->tab_style);
    $gtId = (int) $section->id;
    $gtPanels = $section->relationLoaded('tabPanels') ? $section->getRelation('tabPanels') : collect();
    // Lane GAR: the section's words are grid-section.blade.php's, resolved
    // once there (GridTabs::say) so the two modes cannot disagree.
    $gtEyebrow = $gsEyebrow;
    $gtHeading = $gsHeadingSay;
    $gtSub = $gsSubSay;
    $gtShowHead = $section->show_heading && ($gtHeading !== '' || $gtSub !== '' || $gtEyebrow !== '');
    $gtAll = $gsAll;
    $gtList = '';
    foreach ($gtPanels as $gtI => $gtP) {
        $gtList .= '<button type="button" class="gs-t" role="tab" id="gs-'.$gtId.'-t'.$gtI.'" aria-controls="gs-'.$gtId.'-p'.$gtI.'" aria-selected="'.($gtI === 0 ? 'true' : 'false').'"'.($gtI > 0 ? ' tabindex="-1"' : '').' data-rp-tab>'.e(\App\Support\GridTabs::pick((string) $gtP['title'], (string) $gtP['title_ar'])).'</button>';
    }
@endphp
<section class="sec kbb-gsec gs-tabs gs-tabs-{{ $gtStyle }} gs-al-{{ $gtCfg['align'] }} {{ $sections->classFor($gsKey) }}" style="padding-top:0" data-rp-tabs @if ($gtHeading !== '' && $gtShowHead) aria-labelledby="gs-{{ $gtId }}-h" @endif><div class="wrap">
  @if ($gtShowHead)
  <div class="hs-head">
    @if ($gtEyebrow !== '')<span class="hs-eyebrow">{{ $gtEyebrow }}</span>@endif
    @if ($gtHeading !== '')<h2 id="gs-{{ $gtId }}-h">{{ $gtHeading }}</h2>@endif
    @if ($gtSub !== '')<p>{{ $gtSub }}</p>@endif
  </div>
  @endif
  <div class="gs-tl" role="tablist">{!! $gtList !!}</div>
  @foreach ($gtPanels as $gtI => $gtP)
  <div class="gs-tp" id="gs-{{ $gtId }}-p{{ $gtI }}" role="tabpanel" aria-labelledby="gs-{{ $gtId }}-t{{ $gtI }}" @if ($gsArrows) data-ymal @endif @if ($gtI > 0) hidden @endif>
    {!! $gsOpen !!}<div class="{{ $gsClass }}" data-skin="{{ $gsSkin }}" style="{{ $gsStyle }}" @if ($gsArrows) data-ymal-track @endif>
      @foreach ($gtP['items'] as $gsI => $gsProduct)
      <div class="gs-cell{{ $gsI >= $gsDCount ? ' gs-m-only' : '' }}{{ $gsI >= $gsMCount ? ' gs-d-only' : '' }}"><x-product-card :product="$gsProduct" :cat-label="$gsCardLabel !== '' ? $gsCardLabel : null" :rank="$section->show_rank ? $gsI + 1 : null" /></div>
      @endforeach
    </div>{!! $gsClose !!}
    @php $gtHref = \App\Support\GridTabs::href((string) $gtP['url']); @endphp
    @if ($gtHref !== '')
    <div class="gs-foot"><a class="gs-all" href="{{ $gtHref }}">{{ $gtAll }}</a></div>
    @endif
  </div>
  @endforeach
</div></section>
@pushOnce('styles', 'kbb-gs-tabs'){!! \App\Services\GridSections::tabsCss() !!}@endPushOnce
