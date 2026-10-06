{{--
    AN ARTICLE, /blog/{slug}/, ON THE SHOP'S OWN LAYOUT.             (Lane BH)

    The owner: "on blog page, and on article page, the main header is not
    coming correct. i need the same as we have on all other pages. fix it on
    desktop + mobile both."

    Same defect and same fix as store/blog.blade.php, whose header note has the
    whole account: this was a standalone document with a hand-drawn 69px
    header (wordmark, three text links, a magnifier and a bag that both went to
    /shop/, a four-link burger drawer) and a one-line footer strip, where every
    other shop page draws the shared header, menu bar, mega menus, phone
    header, phone menu and footer. It now extends layouts/store.blade.php.

    THE <head> IS THE LAYOUT'S. Seo::render() runs there once, from the
    `$seoCtx` PageController::post() hands in -- the same context it used to
    render into `$seo` here -- so the title (and the editor's override with
    `%%title%%`), description, canonical, hreflang, og:image, Article and
    BreadcrumbList JSON-LD are the same tags. JournalSharedHeaderTest pins it.

    THE ARTICLE IS THE MARKUP IT WAS, byte for byte (StorefrontEnglishUnchanged-
    Test compares it), inside `.kbb-journal`, which every rule below is scoped
    to: the old sheet styled `h1`, `article`, `footer`, `.wrap`, `.head`,
    `.logo`, `.tool`, `.mnav` and `*` bare, and on the shared layout those would
    have reached the shop's own header and footer. The 720px reading measure
    stays on the article column; the related-posts row stays on the site width
    through the shared `.wrap`. --}}
@extends('layouts.store')

@push('styles')
@verbatim
<style id="kbb-journal-css">
  /* The article's own sheet, scoped to its content (Lane BH). The colour
     tokens are NOT redeclared -- kbb.css carries the same values on :root and
     the owner's brand colour overrides them there; the three this page drew
     differently are restated on the wrapper. */
  .kbb-journal{--r-m:14px;--r-l:20px;--sh-m:0 6px 20px rgba(42,34,40,.08);font-size:14px;line-height:1.5;color:var(--ink)}
  .kbb-journal a{color:inherit}
  .kbb-journal article{max-width:720px;margin:0 auto;padding:34px 20px 20px}
  .kbb-journal .crumb{font-size:12.5px;color:var(--muted);margin-bottom:16px}.kbb-journal .crumb a{text-decoration:none}.kbb-journal .crumb a:hover{color:var(--pink-deep)}
  .kbb-journal .atag{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pink-deep);background:var(--pink-soft);padding:5px 12px;border-radius:99px}
  .kbb-journal h1{font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1.18;margin:14px 0 12px}
  .kbb-journal .ameta{font-size:12.5px;color:var(--muted);display:flex;gap:8px;align-items:center;margin-bottom:22px}
  .kbb-journal .cover{aspect-ratio:16/8;border-radius:var(--r-l);margin-bottom:28px;display:grid;place-items:center;font-size:56px;background:var(--cream);position:relative;overflow:hidden}
  /* The cover is a real image element when the post has a photograph. It is
     absolutely positioned so the emoji placeholder keeps the grid centring
     above, and object-fit:cover reproduces the `center/cover` it had as a
     background. */
  .kbb-journal .cover img,.kbb-journal .mcover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
  .kbb-journal .abody{font-size:16px;line-height:1.72;color:var(--ink)}
  .kbb-journal .abody p{margin:0 0 18px}
  .kbb-journal .abody h3{font-size:20px;font-weight:600;margin:28px 0 10px;letter-spacing:-.01em}
  .kbb-journal .abody b{font-weight:600}
  .kbb-journal .abody i{color:var(--ink-2)}
  /* A body photograph scales into the column. Without it one plain <img> took
     this page's scrollWidth to 1220 at 390px and 1500 at 1280. `height:auto`
     is half the rule: WordPress writes width= and height= attributes, and
     constraining the width alone against a fixed height squashes the picture. */
  .kbb-journal .abody img{max-width:100%;height:auto}
  .kbb-journal .backrow{max-width:720px;margin:10px auto 0;padding:0 20px}
  .kbb-journal .backrow a{font-size:13px;font-weight:600;color:var(--pink-deep);text-decoration:none}
  .kbb-journal .more{border-top:1px solid var(--line-2);margin-top:40px;padding-top:30px}
  .kbb-journal .more h2{font-size:19px;font-weight:700;margin-bottom:16px}
  .kbb-journal .mgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .kbb-journal .mcard{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease)}
  .kbb-journal .mcard:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .kbb-journal .mcover{aspect-ratio:16/10;display:grid;place-items:center;font-size:30px;background:var(--cream);position:relative;overflow:hidden}
  .kbb-journal .mc{padding:14px}.kbb-journal .mt{font-size:14px;font-weight:600;line-height:1.35}.kbb-journal .mtag{font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--pink-deep);margin-bottom:5px}
  /* The bottom gap the old footer strip's own margin-top:44px gave the page. */
  .kbb-journal > .wrap:last-child{padding-bottom:44px}
  @media(max-width:900px){.kbb-journal h1{font-size:26px}.kbb-journal .mgrid{grid-template-columns:1fr}}
</style>
@endverbatim
@endpush

@section('content')
<div class="kbb-journal">
<article id="article">
  
  <div class="crumb"><a href="{{ \App\Support\Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <a href="{{ \App\Support\Url::to(\App\Support\UrlScheme::blogIndex()) }}">{{ __('store.journal.nav_journal') }}</a></div>
  @if($post->tag)<span class="atag">{{ $post->tag }}</span>@endif
  <h1>{{ $post->t('title') }}</h1>
  <div class="ameta"><span>{{ $post->author ?: 'K-Beauty Bliss' }}</span> · <span>{{ optional($post->published_at)->format('j F Y') }}</span></div>
  {{-- The hero photograph is a real <img>, not a CSS background.
       PageController::post() hands this same column to Seo::render as the
       article's `image`, so the page publishes it as og:image and as the
       schema.org Article.image — it tells Google there is a hero photograph
       here. A background-image is invisible to Google Images, so what the page
       claimed and what it could actually be indexed for did not match.
       No loading="lazy": this is the article's opening image, above the fold
       on every viewport, and lazy-loading the LCP element delays it. --}}
  @php
    $coverSrc = \App\Support\CoverImage::src($post->cover);
  @endphp
  <div class="cover" style="background:{{ \App\Support\CoverImage::background($post->cover) }}">
    @if($coverSrc)
      <img src="{{ $coverSrc }}" alt="{{ $post->t('title') }}" width="1200" height="600" fetchpriority="high">
    @else
      {{ ['Routine' => '✍️', 'Ingredients' => '🌿', 'SPF' => '☀️', 'News' => '📰'][$post->tag] ?? '✨' }}
    @endif
  </div>
  {{-- @shortcodes for the same reason as store/page.blade.php: the directive
       names posts explicitly and no view was using it, so [kbb_products] or
       [kbb_block] in an article body reached the reader as literal text. The
       excerpt fallback keeps its e() — it is plain text from the admin list
       and is not markup. --}}
  {{-- BodyHeadings::demoteH1 wraps the shortcode expansion rather than the raw
       column: a [kbb_block] can itself carry an h1, so the demotion has to see
       the expanded HTML. The <h1> above is this page's own — an h1 in an
       article body made two of them. See App\Support\BodyHeadings.

       t(), not the column. `body` is one of TranslationStore::LONG_FIELDS:
       not in the map that every Arabic page loads, fetched by the one page
       that prints it. One article, one row, one query. --}}
  <div class="abody">{!! \App\Support\BodyHeadings::demoteH1(\App\Support\Shortcodes::render($post->t('body') ?: '<p>' . e($post->t('excerpt')) . '</p>')) !!}</div>
  @verbatim
</article>
@endverbatim
{{-- Straight to the index. "/blog" only 301s here, and a hardcoded root path
     drops the base prefix the staging subdirectory needs. --}}
<div class="backrow"><a href="{{ \App\Support\Url::to(\App\Support\UrlScheme::blogIndex()) }}">&larr; {{ __('store.journal.back_to_index') }}</a></div>
@verbatim

<div class="wrap">
@endverbatim
@unless($related->isEmpty())
  
  <div class="more" id="more">
    <h2>{{ __('store.journal.more_heading') }}</h2>
    <div class="mgrid" id="mgrid">
  
  @foreach($related as $r)
    <a class="mcard" href="{{ \App\Support\Url::to(\App\Support\UrlScheme::article($r->slug)) }}">
      @php
        $relSrc = \App\Support\CoverImage::src($r->cover);
      @endphp
      <div class="mcover" style="background:{{ \App\Support\CoverImage::background($r->cover) }}">
        @if($relSrc)
          <img src="{{ $relSrc }}" alt="{{ $r->t('title') }}" width="400" height="250" loading="lazy">
        @else
          {{ ['Routine' => '✍️', 'Ingredients' => '🌿', 'SPF' => '☀️', 'News' => '📰'][$r->tag] ?? '✨' }}
        @endif
      </div>
      <div class="mc"><div class="mtag">{{ $r->tag }}</div><div class="mt">{{ $r->t('title') }}</div></div>
    </a>
  @endforeach
  @verbatim
    </div>
  </div>
  @endverbatim
@endunless

</div>

</div>
@endsection
