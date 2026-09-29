{{--
    "and then small thin rating bar." (Lane PDP)

    Five stars, the average, a hairline filled to that average, and the count as
    a link to the reviews. A BAR rather than the shipped capsule, because a
    capsule says "4.8 · 212 reviews" and a bar also says how far off five that
    is, in two pixels of height and no extra row.

    ▲ THE FILL IS ARITHMETIC ON A NUMBER THE SERVER ALREADY HAS. `$rating / 5`
      rounded to a percent, written into the style attribute. Nothing in the
      browser measures anything; see the note at the head of parts/tabs.blade.php
      and CLAUDE.md rule 4. It is `inline-size`, so the bar fills from the
      inline-start edge and therefore from the RIGHT on /ar, with no [dir] rule.

    ▲ NO REVIEWS, NO BAR. The shipped page already refuses to print a rating it
      does not have — the reviews table is the answer, with no fallback to the
      denormalised `products.rating` column, which DemoCatalogueSeeder had
      filled with mt_rand(4, 1400) and which advertised "4.9 · 3,204 reviews" on
      products nobody had ever reviewed. A 0%-filled bar under "0.0" is a worse
      answer than silence, so an unreviewed product draws nothing here.

    ▲ THE COUNT'S WORDING AND THE STAR COLOUR ARE THE OWNER'S, from
      Store → Ecommerce → Product page → Review badges. This lane adds no new
      interface string in either language.
--}}
@if ($rcount)
    @php
        $pvFill = max(0, min(100, (int) round(($rating / 5) * 100)));
        $pvBadgeColour = (string) $settings->get('review_badge_colour', '#E8A33D');
        $pvRcountLabel = str_replace('{n}', number_format($rcount), (string) $settings->get('review_badge_label', __('store.product.review_badge_label')));
    @endphp
    <div class="pv-rate">
        <span class="pv-stars" style="color:{{ $pvBadgeColour }}">@for ($i = 1; $i <= 5; $i++){!! $i <= round($rating) ? '<span class="f">★</span>' : '<span>★</span>' !!}@endfor</span>
        <span class="pv-avg">{{ number_format($rating, 1) }}</span>
        <span class="pv-ratebar"><i style="inline-size:{{ $pvFill }}%"></i></span>
        {{-- PLAIN TEXT, NOT A LINK. These five pages stop where his list stops — at the
             payment marks — so there is no reviews section beneath them for an anchor
             to reach. A link to an id that is not on the page is a link that does
             nothing, and a drawing that contains one is a drawing that is lying about
             one of its own controls. On the shipped page this is a link to #sr and
             stays one. --}}
        <span class="pv-rcount">{{ $pvRcountLabel }}</span>
    </div>
@endif
