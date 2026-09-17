{{--
    /reviews — the shop's own reviews, and nothing else.

    WHAT THIS FILE USED TO BE. A JavaScript array of twelve invented customers,
    with names, star ratings, relative dates, review bodies and "helpful"
    counts; a client-side statistics function that derived "4.9" and "Based on
    128 reviews" from them; a header capsule hard-coded to "4.9 · 128 reviews";
    a wrapper class of `top-demo` advertising one named product the page never
    looked up; and a write-a-review sheet that validated its fields, answered
    "Thank you, your review is in and will appear once approved" and posted
    nothing anywhere.

    Store\PageController::reviewWall() passed no review data at all, so an
    approved review in this shop's database could not appear here and the twelve
    strangers could not be removed by anything the owner could do.

    Both of those shapes — the seeded array and the client-side statistics — are
    now banned from this file by tests/Feature/ReviewWallTruthTest.php, which
    reads this source as text. They are described in prose above rather than
    quoted, because a guard that reads comments as code cannot be handed the
    thing it bans.

    EVERY FIGURE BELOW COMES FROM App\Support\ReviewWall OR IS NOT PRINTED.
    There is no fixture and no default anywhere in this file.

    NO JAVASCRIPT, DELIBERATELY. Filtering and "load more" are ordinary links
    carrying ?rfilter= and ?rshow=, which is what the home page's wall has
    always linked to and what the product page already does. Every state of this
    page therefore has a real URL, works without scripting, and — the point
    here — cannot render a card the server did not send.

    THE HELPFUL COUNT IS A FIGURE, NOT A BUTTON. `reviews.helpful` is a real
    column and the number is real. Voting lives on the product page, which has
    the CSRF token and the script for POST /reviews/{id}/helpful; adding a
    second copy of it here would be new surface for no gain. A number nobody
    can change is still true.
--}}
@php
    use App\Support\Gradient;
    use App\Support\ReviewWall;
    use App\Support\StoreRating;
    use App\Support\Url;

    $shopUrl = Url::to('/shop/');
    $homeUrl = Url::to('/');
    $shopName = $settings->get('site_title') ?: 'K-Beauty Bliss';

    /** A star row that states its own rating, so nothing has to read the glyphs. */
    $stars = fn (int $n) => '<span class="sr-stars" data-rating="' . $n . '" role="img" aria-label="'
        . e(trans_choice('store.review_wall.stars_label', $n)) . '">'
        . str_repeat('★', $n)
        . '<span class="e">' . str_repeat('★', 5 - $n) . '</span></span>';
@endphp
<!DOCTYPE html>
{{--
    THE DOCUMENT SAYS WHICH LANGUAGE IT IS IN.

    This page carries its own <html> and does not extend
    layouts/store.blade.php, so it never picked up the two attributes that
    layout has emitted since the bilingual foundation landed. /ar/ served this
    document with a correct Arabic canonical and a correct hreflang set while
    declaring itself English -- a lie to every screen reader, hyphenator and
    translation tool that reads the attribute, on the pages a shopper is most
    likely to read with one.

    `dir` GOES THROUGH Locale::direction(), NEVER THROUGH THE LANGUAGE. This
    shop has two switches and not one: Arabic can be live while the mirrored
    layout is still being built, and direction() is the single place that
    answers which of the two states the shop is in. That is the contract
    resources/views/invoices/document.blade.php sets out at length and
    layouts/store.blade.php already follows; this is the same two attributes,
    not a second opinion on them.

    WHAT IS STILL PHYSICAL. The stylesheet below is inline and this file's own.
    Turning the mirrored layout on gives this document the right TEXT direction
    and not yet a mirrored layout, and it does not give it an Arabic-capable
    webfont either. Both measured, named and left for their owners in
    docs/rtl-standalone-documents.md rather than papered over here.
--}}<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
{!! $seo ?? '' !!}
{!! app(\App\Services\Analytics::class)->headTags() !!}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">{{--
    AND THE ARABIC FACE, WHICH THIS DOCUMENT ALSO HAS TO ASK FOR ITSELF.

    The webfont link above carries no Arabic glyph. Before this, /ar/reviews/
    served real Arabic text in a document that linked Poppins only and named an
    Arabic-capable family ZERO times, so every Arabic word rendered in whatever
    face the device happened to have. Measured at 40px against Cairo and matching
    neither -- docs/rtl-standalone-documents.md §2 found it, and
    docs/FS-ARABIC-TYPOGRAPHY.md has this lane's before-and-after numbers.

    THE WHITESPACE HERE IS LOAD-BEARING, and it is why this include is glued to
    the end of the link above rather than given a line of its own. Blade compiles
    the include to a PHP tag, and PHP SWALLOWS ONE NEWLINE immediately after `?>`.
    Every arrangement that leaves a single newline next to it therefore takes a
    newline OUT of the ENGLISH document, which is a change to output that is
    supposed to be unchanged. The line that follows puts the newline back.
    Checked by fetching all seven English pages before and after: byte-identical.

    body, NOT :root: this is the one of the five that does not put its font in
    a custom property. It hard-codes "Poppins",sans-serif on `body`, so
    html[lang="ar"] body (0,1,2) is what has to win over body (0,0,1). And this
    document's link is already outside the verbatim block, so nothing is closed
    here.

    (The two verbatim markers are never spelled out with their @ in a comment in
    these files. A verbatim block is extracted from the RAW source before
    comments are removed, so the word in a comment opens a block of its own.)
--}}@include('partials.arabic-face', [
    'weights' => '400;500;600;700;800',
    'stacks' => ['body' => ['font-family' => '"Poppins",sans-serif']],
])

@verbatim
<style>
  :root{
    /* KBB real tokens (never Sorina/Fraunces) */
    --cream:#FFF8F5; --pink-soft:#FFF0F4; --blush:#FCE0E8; --pink:#E0567B; --pink-deep:#C13E63; --pink-ink:#A82F53;
    --ink:#2A2228; --ink-2:#5E545A; --muted:#8C828A; --gold:#BE8E2E; --green:#2E9E6B; --line:rgba(42,34,40,.12); --card:#fff;
    --ease:cubic-bezier(.22,.61,.36,1);
    /* plugin-style review vars (KBB-toned) */
    --sr-accent:var(--pink); --sr-bg:var(--cream); --sr-card:#fff; --sr-ink:var(--ink); --sr-soft:var(--muted);
    --sr-gold:var(--gold); --sr-star-empty:#E7D2C9; --sr-radius:18px; --sr-cols:4;
  }
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;background:var(--cream);background-image:linear-gradient(180deg,#fff,var(--cream) 520px);background-repeat:no-repeat;color:var(--ink);font-family:"Poppins",sans-serif;-webkit-font-smoothing:antialiased}
  .page{max-width:1080px;margin:0 auto;padding:0 18px 80px}
  a{color:inherit}

  /* site bar — this page carries no header partial, and a page a search engine
     lands someone on must not be a dead end. */
  .sr-top{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:18px 0;border-bottom:1px solid var(--line);margin-bottom:26px}
  .sr-logo{font-size:19px;font-weight:700;letter-spacing:-.02em;text-decoration:none}
  .sr-logo span{color:var(--pink)}
  .sr-topnav{margin-inline-start:auto;display:flex;gap:16px;font-size:13.5px;font-weight:600}
  .sr-topnav a{color:var(--ink-2);text-decoration:none}
  .sr-topnav a:hover{color:var(--pink-deep)}

  .sr-head{text-align:center;margin-bottom:22px}
  .sr-eyebrow{font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:var(--pink);font-weight:700}
  .sr-title{font-size:26px;font-weight:700;margin:6px 0 0;color:var(--sr-ink)}
  .sr-sub{margin:8px auto 0;max-width:560px;font-size:13.5px;line-height:1.6;color:var(--ink-2)}

  .sr-summary{display:grid;grid-template-columns:auto 1fr;gap:26px;align-items:center;background:var(--sr-card);border:1px solid var(--line);border-radius:var(--sr-radius);padding:20px 24px;margin-bottom:16px}
  @media(max-width:560px){.sr-summary{grid-template-columns:1fr;gap:14px;text-align:center}}
  .sr-score{text-align:center;min-width:120px}
  .sr-avg{font-size:46px;font-weight:800;line-height:1;color:var(--sr-ink)}
  .sr-avg-stars{color:var(--sr-gold);font-size:17px;letter-spacing:2px;margin:6px 0 2px}
  .sr-count{font-size:12.5px;color:var(--sr-soft);font-weight:600}
  .sr-bars{display:flex;flex-direction:column;gap:6px}
  .sr-bar{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--sr-soft)}
  .sr-bar .lab{width:30px;font-weight:600;color:var(--ink-2)}
  .sr-bar .track{flex:1;height:8px;border-radius:30px;background:var(--pink-soft);overflow:hidden}
  .sr-bar .fill{height:100%;border-radius:30px;background:linear-gradient(90deg,var(--blush),var(--pink));display:block}
  .sr-bar .pct{width:34px;text-align:end;font-variant-numeric:tabular-nums}

  /* said instead of a score, below the threshold */
  .sr-too-few{background:var(--pink-soft);border:1px solid var(--line);border-radius:14px;padding:14px 18px;margin-bottom:16px;font-size:13px;line-height:1.6;color:var(--ink-2)}
  .sr-too-few b{color:var(--ink)}

  .sr-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:8px 0 16px}
  .sr-filters{display:flex;gap:8px;flex-wrap:wrap}
  .sr-chip{border:1px solid var(--line);background:#fff;border-radius:30px;padding:7px 14px;font-family:inherit;font-size:12.5px;font-weight:600;color:var(--ink-2);text-decoration:none;transition:.15s;display:inline-block}
  .sr-chip:hover{border-color:var(--pink)}
  .sr-chip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  .sr-shown{font-size:12.5px;color:var(--muted);font-weight:600}

  /* masonry grid */
  .sr-grid{column-count:var(--sr-cols);column-gap:14px}
  @media(max-width:1024px){.sr{--sr-cols:3}}
  @media(max-width:760px){.sr{--sr-cols:2}}
  @media(max-width:430px){.sr{--sr-cols:1}}
  .sr-card{break-inside:avoid;background:var(--sr-card);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:14px;display:block}
  .sr-ct{display:flex;align-items:center;gap:10px}
  .sr-av{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:15px;flex:0 0 auto;text-shadow:0 1px 2px rgba(42,34,40,.35)}
  .sr-cmeta{min-width:0}
  .sr-nm{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .sr-verified{font-size:10px;font-weight:700;color:var(--green);border:1px solid var(--green);border-radius:10px;padding:0 6px;line-height:15px}
  .sr-stars{color:var(--sr-gold);font-size:12.5px;letter-spacing:.5px;margin-top:1px;display:inline-block}
  .sr-stars .e{color:var(--sr-star-empty)}
  .sr-prod{font-size:11px;font-weight:600;color:var(--muted);margin-top:9px}
  .sr-prod a{color:var(--pink-deep);text-decoration:none}
  .sr-h{font-size:13.5px;font-weight:700;margin:9px 0 3px;color:var(--sr-ink)}
  .sr-tx{font-size:12.5px;line-height:1.55;color:var(--ink-2);white-space:pre-line}
  .sr-pp{margin-top:10px;display:flex;gap:5px;flex-wrap:wrap}
  /* Fixed box and clipped: a photo whose file has gone (a deleted upload, a
     path edited by hand in the admin) must not spill its alt text across the
     card. */
  .sr-ph{width:62px;height:62px;border-radius:9px;object-fit:cover;background:var(--pink-soft);display:block;overflow:hidden;font-size:9px;color:var(--muted)}
  .sr-reply{margin-top:10px;border-inline-start:2px solid var(--blush);padding-inline-start:10px;font-size:12px;color:var(--ink-2)}
  .sr-cf{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:11px;font-size:11.5px;color:var(--muted)}

  .sr-more-wrap{text-align:center;margin-top:8px}
  .sr-more{display:inline-block;border:1px solid var(--pink);background:#fff;color:var(--pink-deep);border-radius:30px;padding:11px 26px;font-family:inherit;font-weight:700;font-size:13px;text-decoration:none}
  .sr-more:hover{background:var(--pink-soft)}

  /* empty state — a sentence and a way out, not an emptied skeleton */
  .sr-empty{background:var(--sr-card);border:1px solid var(--line);border-radius:var(--sr-radius);padding:44px 26px;text-align:center}
  .sr-empty h2{margin:0 0 8px;font-size:20px;font-weight:700}
  .sr-empty p{margin:0 auto 20px;max-width:440px;font-size:13.5px;line-height:1.65;color:var(--ink-2)}
  .sr-cta{display:inline-block;background:var(--pink);color:#fff;border-radius:30px;padding:12px 26px;font-weight:700;font-size:13.5px;text-decoration:none}
  .sr-cta:hover{background:var(--pink-deep)}
  .sr-none{background:var(--sr-card);border:1px dashed var(--line);border-radius:14px;padding:26px;text-align:center;font-size:13px;color:var(--ink-2)}

  /* deliberately not built — same wording as the admin's frameNotBuiltHTML() */
  .sr-unbuilt{margin-top:34px;border-top:1px solid var(--line);padding-top:22px;font-size:12.5px;line-height:1.65;color:var(--muted);text-align:center}
  .sr-unbuilt b{color:var(--ink-2)}
  .sr-unbuilt a{color:var(--pink-deep);font-weight:600}

  /* Phone shopper pass - Lane EA. This page renders bare, so it gets none of
     the storefront's mobile tap-target rules: at 360px its wordmark was
     129x22, the Shop / Home links 34x15, and "Browse the shop" - the only way
     off an empty review wall - 161x39. */
  @media (max-width: 820px) {
    .sr-logo{min-height:44px;display:inline-flex;align-items:center}
    .sr-topnav a{min-height:44px;display:inline-flex;align-items:center}
    .sr-cta{min-height:44px;display:inline-flex;align-items:center;justify-content:center}
  }
</style>
@endverbatim
</head>
<body>
<div class="page">

  <div class="sr-top">
    <a class="sr-logo" href="{{ $homeUrl }}">{{ $shopName }}</a>
    <nav class="sr-topnav">
      <a href="{{ $shopUrl }}">{{ __('store.journal.nav_shop') }}</a>
      <a href="{{ $homeUrl }}">{{ __('store.breadcrumb.home') }}</a>
    </nav>
  </div>

  <section class="sr" id="sr">
    <div class="sr-head">
      <div class="sr-eyebrow">{{ __('store.review_wall.eyebrow') }}</div>
      <h1 class="sr-title">{{ __('store.review_wall.heading') }}</h1>
      @if ($summary['total'] > 0)
        <p class="sr-sub">{{ __('store.review_wall.subtitle') }}</p>
      @endif
    </div>

    @if ($summary['total'] === 0)
      {{-- STATE ONE: nothing to show, said plainly. --}}
      <div class="sr-empty">
        <h2>{{ __('store.review_wall.empty_heading') }}</h2>
        <p>{{ __('store.review_wall.empty_body') }}</p>
        <a class="sr-cta" href="{{ $shopUrl }}">{{ __('store.review_wall.empty_cta') }}</a>
      </div>
    @else
      @if ($summary['score'])
        {{-- STATE THREE: enough approved reviews for a shop-wide figure. --}}
        <div class="sr-summary">
          <div class="sr-score">
            <div class="sr-avg">{{ number_format($summary['average'], 1) }}</div>
            <div class="sr-avg-stars">{!! $stars((int) max(1, min(5, round($summary['average'])))) !!}</div>
            <div class="sr-count">{{ trans_choice('store.review_wall.based_on', (int) $summary['total'], ['formatted' => number_format($summary['total'])]) }}</div>
          </div>
          <div class="sr-bars">
            @foreach ($summary['bars'] as $star => $bar)
              <div class="sr-bar">
                <span class="lab">{{ $star }}★</span>
                <span class="track"><span class="fill" style="width:{{ $bar['pct'] }}%"></span></span>
                <span class="pct">{{ $bar['pct'] }}%</span>
              </div>
            @endforeach
          </div>
        </div>
      @else
        {{-- STATE TWO: some real reviews, too few for a score. The rule and the
             reason are App\Support\StoreRating's, not a second one invented here. --}}
        <div class="sr-too-few">
          <b>{{ trans_choice('store.review_wall.too_few_count', (int) $summary['total'], ['formatted' => number_format($summary['total'])]) }}</b>
          {{ __('store.review_wall.too_few_body', ['minimum' => StoreRating::MINIMUM]) }}
        </div>
      @endif

      <div class="sr-toolbar">
        <div class="sr-filters">
          @foreach (ReviewWall::FILTERS as $key => $label)
            <a class="sr-chip{{ $filter === $key ? ' on' : '' }}"
               href="{{ Url::to('/reviews/') }}{{ 'all' === $key ? '' : '?rfilter=' . $key }}">{{ $label }}</a>
          @endforeach
        </div>
        @if ($reviews->isNotEmpty())
          {{-- No "showing 12 of 137": the count of matching rows is a second
               query, and this page does not run one to print a number. It says
               what it is showing, and "load more" says the rest exists. --}}
          <div class="sr-shown">{{ $hasMore ? __('store.review_wall.showing_so_far', ['count' => $reviews->count()]) : __('store.review_wall.showing', ['count' => $reviews->count()]) }}</div>
        @endif
      </div>

      @if ($reviews->isEmpty())
        <div class="sr-none">{!! __('store.review_wall.no_match', ['link' => '<a href="' . e(Url::to('/reviews/')) . '">' . e(__('store.review_wall.no_match_link')) . '</a>']) !!}</div>
      @else
        <div class="sr-grid">
          @foreach ($reviews as $review)
            @php
              $name = trim((string) $review->author_name) ?: __('store.review_wall.anonymous');
              $photos = ReviewWall::photos($review);
            @endphp
            <article class="sr-card" data-review="{{ $review->id }}">
              <div class="sr-ct">
                <span class="sr-av" style="background:{{ Gradient::for($name) }}">{{ mb_substr($name, 0, 1) }}</span>
                <div class="sr-cmeta">
                  <div class="sr-nm"><span class="sr-name">{{ $name }}</span>@if ($review->verified)<span class="sr-verified">{{ __('store.reviews.verified_badge') }}</span>@endif</div>
                  {!! $stars((int) $review->rating) !!}
                </div>
              </div>
              @if ($review->product)
                <div class="sr-prod">{!! __('store.review_wall.on_product', ['product' => '<a href="' . e(Url::to('/product/' . $review->product->slug . '/')) . '">' . e($review->product->name) . '</a>']) !!}</div>
              @endif
              @if (trim((string) $review->title) !== '')
                <div class="sr-h">{{ $review->title }}</div>
              @endif
              <div class="sr-tx">{{ trim(strip_tags((string) $review->content)) }}</div>
              @if ($photos)
                <div class="sr-pp">
                  @foreach ($photos as $photo)
                    <img class="sr-ph" src="{{ Url::to($photo) }}" alt="{{ __('store.review_wall.photo_alt') }}" loading="lazy" width="62" height="62">
                  @endforeach
                </div>
              @endif
              <div class="sr-cf">
                <span>{{ $review->created_at?->format('j M Y') }}</span>
                @if ((int) $review->helpful > 0)
                  <span>{{ trans_choice('store.review_wall.found_helpful', (int) $review->helpful, ['formatted' => number_format((int) $review->helpful)]) }}</span>
                @endif
              </div>
            </article>
          @endforeach
        </div>

        @if ($hasMore)
          <div class="sr-more-wrap">
            <a class="sr-more" href="{{ request()->fullUrlWithQuery(['rshow' => $shown + ReviewWall::STEP]) }}#sr">{{ __('store.reviews.load_more') }}</a>
          </div>
        @endif
      @endif
    @endif

    {{--
      WHAT IS NOT BUILT HERE, SAID ON THE PAGE, in the admin's own words for the
      same situation (frameNotBuiltHTML()).

      The page used to carry a write-a-review sheet with a star picker, a photo
      uploader, a captcha and a Submit button that posted nothing anywhere and
      answered "Thank you" regardless. It could not have worked:
      POST /reviews/submit requires the id of a visible product, and a shop-wide
      page has no product. Rather than a second, shop-wide submission path — a
      new column, a new moderation case and a new decision for the owner — the
      page points at the one that is built and works.
    --}}
    <p class="sr-unbuilt">{!! __('store.review_wall.unbuilt_note', [
      'lead' => '<b>' . e(__('store.review_wall.unbuilt_lead')) . '</b>',
      'not_built' => '<b>' . e(__('store.review_wall.unbuilt_emphasis')) . '</b>',
      'link' => '<a href="' . e($shopUrl) . '">' . e(__('store.review_wall.unbuilt_link')) . '</a>',
    ]) !!}</p>
  </section>
</div>
</body>
</html>
