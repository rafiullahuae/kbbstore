{{--
    ═══════════════════════════════════════════════════════════════════════════
    THE TABS. This is the piece he asked for an idea about, so it is the piece
    the five candidates differ on most.                               (Lane PDP)

        "and then product tabs like Description, Ingredients, How to use etc,
         but i want these tabs in same row with opened promient, and other
         slightely faded by default, and can be scroll left to right between
         tags and and can directly click on any tab, it will open. i want a nice
         idea for this."

    Four requirements, and every candidate meets all four:

      1. ONE ROW. Not a wrapped grid of chips, not an accordion stack.
      2. THE OPEN ONE PROMINENT, the rest faded — `opacity` on the closed
         labels, full ink and weight on the open one.
      3. SCROLLS LEFT TO RIGHT. `overflow-inline: auto` plus `scroll-snap-type`,
         so a flick lands on a tab rather than between two.
      4. TAP ANY TAB AND IT OPENS. Directly — no "more" menu, no second step.

    WHAT DIFFERS IS FIVE ANSWERS TO THE SAME THREE QUESTIONS:

      · where the panel opens         (inline under the row / under a pinned row
                                       / in a tinted well / inside the tab
                                       itself / as a sheet under a dark band)
      · whether the row follows you   (static / pinned / static / static /
                                       pinned on desktop only)
      · how "there is more to the
        right" is said WITHOUT an
        arrow eating a tab's width    (an edge fade / the next pill peeking /
                                       a clipped segment in a well / the next
                                       card's spine / a proportional hairline)

    ═══════════════════════════════════════════════════════════════════════════
    ▲ NO JAVASCRIPT, AND THAT IS NOT A FLOURISH. CLAUDE.md forbids JavaScript
      that measures layout, and a tab strip is the single most tempting place in
      this whole page to reach for `getBoundingClientRect()` — to underline the
      open tab, to slide a fill, to decide whether an overflow arrow is needed.
      None of those questions are asked. The switch is a radio group: the labels
      are `<label for>`, the panels are siblings, and `:checked ~` does the rest.
      The browser has always known which tab is open; nothing has to be told.

      It also means these drawings work with JavaScript off, are keyboard
      operable for free (a radio group is arrow-key navigable), and cannot
      regress into a measuring script later without somebody deleting this
      comment first.

    ▲ EVERY TAB'S TEXT IS IN THE HTML ON FIRST PAINT. All of them, open or not
      — `display:none` on a closed panel, never a fetch. The shipped page does
      the same for the same reason: a tab body that arrives later is a tab body
      Google never reads.

    ▲ THE TABS ARE THE REAL ONES. `$tabs` is App\Support\ProductTabs::forProduct()
      by way of Store\ProductController — the three built-ins read off the
      product's own columns plus every tab the owner has written in
      Catalog → Product tabs, global and per-product, with this product's hides
      and overrides applied, sorted on one scale. Nothing here invents a tab
      system; the bodies are printed exactly as the shipped partial prints them,
      through the same {!! !!} pair, already sanitised on the way in by
      App\Support\RichText.

    ▲ `$mode` IS A CONSTANT FROM THE CANDIDATE TEMPLATE, never a request value.
      It is concatenated into a class name, so it is the one string on this page
      that must not be able to come from outside — and it cannot: each of the
      five candidate files passes its own literal.
--}}
@php
    $pvMode = $mode ?? 'underline';
    $pvN = max(1, count($tabs));
@endphp

@if ($tabs)
<div class="pv-tabs pv-tabs-{{ $pvMode }}" style="--pv-n:{{ $pvN }}">
    {{-- The radio group. Hidden, but NOT `display:none` — a display:none radio
         is unreachable by keyboard, which would make the whole strip
         mouse-only. `.pv-tabin` parks them off-canvas instead. --}}
    @foreach ($tabs as $i => $tab)
        <input class="pv-tabin pv-tabin-{{ $i }}" type="radio" name="pvtab" id="pvtab{{ $i }}" @checked(0 === $i)>
    @endforeach

    @if ('deck' === $pvMode)
        {{-- D · DECK — THE ROW *IS* THE PANEL.

             Books on a shelf. The open tab is a spread: a full-width card with
             its body in it. Its neighbours are spines — narrow, faded, their
             titles stood on end — and tapping a spine opens it, which is
             requirement 4 met by the same element that meets requirement 1.

             The affordance for "there is more to the right" is therefore the
             design itself rather than something added to it: a spine is always
             visible at the edge, so the strip can never look finished when it
             is not, and nothing is spent on an arrow. --}}
        <div class="pv-deck">
            @foreach ($tabs as $i => $tab)
                <label class="pv-card" for="pvtab{{ $i }}">
                    <span class="pv-spine">{{ $tab['title'] }}</span>
                    <span class="pv-face">
                        <span class="pv-facetitle">{{ $tab['title'] }}</span>
                        <span class="pv-body"><span class="dcontent open">{!! $tab['body'] !!}</span></span>
                    </span>
                </label>
            @endforeach
        </div>
    @else
        <div class="pv-tabrow">
            @foreach ($tabs as $i => $tab)
                <label class="pv-tab" for="pvtab{{ $i }}">{{ $tab['title'] }}</label>
            @endforeach
            {{-- C only: the fill that slides under the open segment. One
                 absolutely-positioned block whose inline-start is
                 (index × segment width) — both numbers the server already knows,
                 so it is placed by arithmetic and not by measurement. It is
                 `inset-inline-start`, so on /ar it slides the other way with no
                 [dir] rule. Inert and aria-hidden everywhere else. --}}
            <span class="pv-segfill" aria-hidden="true"></span>
        </div>
        {{-- E only: the proportional hairline. Width is 1/n of the band and it
             sits at k/n along it, so it says both "there are more" and "you are
             here" in two pixels of height and no width at all. --}}
        <span class="pv-progress" aria-hidden="true"><i></i></span>
        <div class="pv-panels">
            @foreach ($tabs as $i => $tab)
                <div class="pv-panel"><div class="dcontent open">{!! $tab['body'] !!}</div></div>
            @endforeach
        </div>
    @endif
</div>
@endif
