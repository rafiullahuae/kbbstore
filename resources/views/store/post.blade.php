@verbatim<!DOCTYPE html>
@endverbatim{{--
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
--}}<html lang="{{ \App\Support\Locale::htmlLang() }}" dir="{{ \App\Support\Locale::direction() }}">@verbatim
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
{!! app(\App\Services\Analytics::class)->headTags() !!}
@verbatim
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">@endverbatim{{--
    AND THE ARABIC FACE, WHICH THIS DOCUMENT ALSO HAS TO ASK FOR ITSELF.

    The webfont link above carries no Arabic glyph. Before this, /ar/<post-slug>/
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

    (The two verbatim markers are never spelled out with their @ in a comment in
    these files. A verbatim block is extracted from the RAW source before
    comments are removed, so the word in a comment opens a block of its own.)
--}}@include('partials.arabic-face', [
    'weights' => '400;500;600;700',
    'stacks' => [
        ':root' => ['--sans' => "'Poppins',system-ui,sans-serif"],
        // AND `button`, which this document never gives a font to at all. A
        // <button> does not inherit font-family from its parent -- the UA
        // stylesheet sets it -- and unlike the other four documents this one
        // carries no `button{font-family:inherit}`. So .chip (the tag filter,
        // whose labels ARE translated) and .mnav-x rendered in the UA's Arial
        // while every other element on the page moved to Cairo: 22 of 24
        // text-bearing elements, measured. Scoped to html[lang="ar"] like the
        // rest, so the English page keeps exactly the Arial it has today -- that
        // half is a real defect of these two documents and belongs to whoever
        // owns their typography, not to a lane that may not move English bytes.
        'button' => ['font-family' => "'Poppins',system-ui,sans-serif"],
    ],
])
@verbatim
<style>
  :root{--bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;
    --ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);--line-2:rgba(42,34,40,.06);
    --r-m:14px;--r-l:20px;--sh-m:0 6px 20px rgba(42,34,40,.08);--sans:'Poppins',system-ui,sans-serif;--ease:cubic-bezier(.22,.61,.36,1)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--sans);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5}
  a{color:inherit}
  /* THE PAGE CONTAINER, on the site width.                        Lane W1

     This is a STANDALONE DOCUMENT: it carries its own <html>, its own <head>
     and its own :root, and it does not load resources/css/kbb/kbb.css. So
     --site-max is not inherited from anywhere and the literal below is the one
     place in this repo other than that sheet's :root where the number 1680
     appears. That duplication is unavoidable for a document that loads no
     shared stylesheet, so it is pinned instead of trusted:
     SiteLayoutDefaultsMatchCssTest asserts that every var(--site-max, N)
     fallback in resources/views agrees with kbb.css, and goes red if either
     moves alone.

     The var() is not decoration. Appearance -> Site layout emits
     <style id="kbb-layout"> into this document's head too, so a moved slider
     reaches the Journal as well as the shop; the fallback is what this page
     uses until one moves.

     The ARTICLE column is NOT on this. store/post.blade.php keeps
     `article{max-width:720px}` because 720px is a reading measure and a 1680px
     paragraph is unreadable. This width is the page chrome around it. */
  .wrap{max-width:var(--site-max,1680px);margin-inline:auto;
    /* The clamp is spelled out rather than taken from --site-gutter, which is
       declared in kbb.css and this document does not load it. The emitted block
       sets the two ENDS, so both halves reach here. */
    padding-inline:clamp(var(--site-gutter-min,22px),2.2vw,var(--site-gutter-max,22px))}
  .head{position:sticky;top:0;z-index:60;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line-2)}
  .head-in{display:flex;align-items:center;gap:18px;height:68px}
  .logo{font-size:20px;font-weight:700;letter-spacing:-.02em;text-decoration:none}.logo span{color:var(--pink)}
  .nav-links{display:flex;gap:20px;margin-inline-start:6px}
  .nav-links a{color:var(--ink-2);text-decoration:none;font-size:14px;font-weight:500}
  .nav-links a:hover,.nav-links a.on{color:var(--pink-deep)}
  .tools{margin-inline-start:auto;display:flex;gap:8px}
  .tool{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;border:none;background:none;color:var(--ink);cursor:pointer;text-decoration:none}
  .tool:hover{background:var(--pink-soft);color:var(--pink-deep)}.tool svg{width:22px;height:22px}
  .burger{display:none;width:44px;height:44px;border-radius:12px;place-items:center;color:var(--ink);background:none;border:none;cursor:pointer}
  .burger svg{width:24px;height:24px}
  article{max-width:720px;margin:0 auto;padding:34px 20px 20px}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:16px}.crumb a{text-decoration:none}.crumb a:hover{color:var(--pink-deep)}
  .atag{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pink-deep);background:var(--pink-soft);padding:5px 12px;border-radius:99px}
  h1{font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1.18;margin:14px 0 12px}
  .ameta{font-size:12.5px;color:var(--muted);display:flex;gap:8px;align-items:center;margin-bottom:22px}
  .cover{aspect-ratio:16/8;border-radius:var(--r-l);margin-bottom:28px;display:grid;place-items:center;font-size:56px;background:var(--cream);position:relative;overflow:hidden}
  /* The cover is a real image element when the post has a photograph. It is
     absolutely positioned so the emoji placeholder keeps the grid centring
     above, and object-fit:cover reproduces the `center/cover` it had as a
     background. */
  .cover img,.mcover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
  .abody{font-size:16px;line-height:1.72;color:var(--ink)}
  .abody p{margin:0 0 18px}
  .abody h3{font-size:20px;font-weight:600;margin:28px 0 10px;letter-spacing:-.01em}
  .abody b{font-weight:600}
  .abody i{color:var(--ink-2)}
  /* There was no rule for a body photograph here at all, and one plain <img>
     took this page's scrollWidth to 1220 at 390px and 1500 at 1280 -- a
     sideways scrollbar on every article carrying a picture. It had been
     invisible because `posts` was empty on a fresh shop and the import that
     fills it was itself removing every <picture> before it reached the column;
     both halves changed in one release. `height:auto` is half the rule:
     WordPress writes width= and height= attributes, and constraining the width
     alone against a fixed height squashes the picture instead of scaling it. */
  .abody img{max-width:100%;height:auto}
  .backrow{max-width:720px;margin:10px auto 0;padding:0 20px}
  .backrow a{font-size:13px;font-weight:600;color:var(--pink-deep);text-decoration:none}
  .more{border-top:1px solid var(--line-2);margin-top:40px;padding-top:30px}
  .more h2{font-size:19px;font-weight:700;margin-bottom:16px}
  .mgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .mcard{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease)}
  .mcard:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .mcover{aspect-ratio:16/10;display:grid;place-items:center;font-size:30px;background:var(--cream);position:relative;overflow:hidden}
  .mc{padding:14px}.mt{font-size:14px;font-weight:600;line-height:1.35}.mtag{font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--pink-deep);margin-bottom:5px}
  footer{background:var(--ink);color:#fff;padding:34px 0;margin-top:44px}
  footer .fin{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.75)}
  footer a{color:rgba(255,255,255,.75);text-decoration:none}footer a:hover{color:#fff}
  .navov{position:fixed;inset:0;background:rgba(42,34,40,.42);z-index:105;opacity:0;visibility:hidden;transition:.3s}
  .navov.on{opacity:1;visibility:visible}
  /* THE MOBILE NAV, MIRRORED (Lane FK, completing T6 §11.4's deferral).
     The RTL lane converted every other off-canvas panel and left this one
     physical for a stated reason: "[dir="rtl"] cannot match there", because
     this document hard-coded <html lang="en"> with no dir at all, so the
     conversion bought nothing while changing a live page's English bytes. It
     asked for it to be done "when someone gives those views a real <html dir>,
     in one change that repins the guard once". That is this change.

     The inset is logical AND the transform is flipped, because translateX has
     no logical form: inset-inline-start alone would pin the panel to the
     reading edge under RTL while translateX(-100%) went on pushing it the other
     way — out of the viewport in English, INTO it in Arabic. The override sits
     before .mnav.on deliberately, so the open state still wins on equal
     specificity. Both halves copied from kbb.css's own .mnav, which is this
     same panel in the shared layout. */
  .mnav{position:fixed;top:0;inset-inline-start:0;height:100%;width:290px;max-width:85vw;background:#fff;z-index:110;transform:translateX(-100%);transition:.3s var(--ease);padding:20px;display:flex;flex-direction:column}
  [dir="rtl"] .mnav{transform:translateX(100%)}
  .mnav.on{transform:none}
  .mnav a{padding:14px 6px;text-decoration:none;font-weight:500;border-bottom:1px solid var(--line-2)}
  .mnav-x{align-self:flex-end;font-size:20px;background:none;border:none;color:var(--ink-2);cursor:pointer;margin-bottom:6px}
  @media(max-width:900px){h1{font-size:26px}.mgrid{grid-template-columns:1fr}.nav-links{display:none}.burger{display:grid}}
</style>@endverbatim
{{--
    Appearance → Site layout, in a document that loads no shared stylesheet.
                                                                       Lane W1
    This page carries its own <html> and its own :root, so the only way a moved
    slider reaches it is for the same block the shared layout emits to be emitted
    here too. AFTER the <style> above, so the owner's number wins over the
    literal fallback in it; and empty while every setting is at its shipped
    value, so this document gains no bytes until one moves — which is why it can
    be added to a page StorefrontEnglishUnchangedTest pins.

    ▲ ARRANGED TO EMIT NOTHING. The directives share lines with the comment and
    with the tag they guard, and both close at end of line so PHP eats the
    newline after each `?>`. Written the obvious way this added blank lines to
    the <head> of this document and of every page using the shared layout, and
    the walk reported all of them — for a change it cannot otherwise see, because
    the width itself is CSS. See the long note in layouts/store.blade.php.
--}}@php
    $kbbLayoutCss = app(\App\Services\SiteLayout::class)->css();
@endphp
@if ($kbbLayoutCss !== '')<style id="kbb-layout">{!! $kbbLayoutCss !!}</style>
@endif
</head>
<body>
<header class="head"><div class="wrap head-in">
  <button class="burger" onclick="document.getElementById('mnav').classList.add('on');document.getElementById('navov').classList.add('on')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="logo" href="{{ \App\Support\Url::to('/') }}">K-Beauty<span>Bliss</span></a>
  <nav class="nav-links">
    <a href="{{ \App\Support\Url::to('/shop/') }}">{{ __('store.journal.nav_shop') }}</a>
    <a href="{{ \App\Support\Url::to('/skin-quiz/') }}">{{ __('store.journal.nav_quiz') }}</a>
    <a href="{{ \App\Support\Url::to('/skincare-guide/') }}" class="on">{{ __('store.journal.nav_journal') }}</a>
  </nav>
  <div class="tools">
    <a class="tool" href="{{ \App\Support\Url::to('/shop/') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg></a>
    <a class="tool" href="{{ \App\Support\Url::to('/shop/') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/></svg></a>
  </div>
</div></header>

<div class="navov" id="navov" onclick="this.classList.remove('on');document.getElementById('mnav').classList.remove('on')"></div>
<nav class="mnav" id="mnav">
  <button class="mnav-x" onclick="document.getElementById('mnav').classList.remove('on');document.getElementById('navov').classList.remove('on')">✕</button>
  <a href="{{ \App\Support\Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a><a href="{{ \App\Support\Url::to('/shop/') }}">{{ __('store.journal.nav_shop') }}</a><a href="{{ \App\Support\Url::to('/skin-quiz/') }}">{{ __('store.journal.nav_quiz') }}</a><a href="{{ \App\Support\Url::to('/skincare-guide/') }}">{{ __('store.journal.nav_journal') }}</a>
</nav>

<article id="article">
  
  <div class="crumb"><a href="{{ \App\Support\Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> / <a href="{{ \App\Support\Url::to('/skincare-guide/') }}">{{ __('store.journal.nav_journal') }}</a></div>
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
<div class="backrow"><a href="{{ \App\Support\Url::to('/skincare-guide/') }}">&larr; {{ __('store.journal.back_to_index') }}</a></div>
@verbatim

<div class="wrap">
@endverbatim
@unless($related->isEmpty())
  
  <div class="more" id="more">
    <h2>{{ __('store.journal.more_heading') }}</h2>
    <div class="mgrid" id="mgrid">
  
  @foreach($related as $r)
    <a class="mcard" href="{{ \App\Support\Url::to('/' . $r->slug . '/') }}">
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

<footer><div class="wrap fin">
  <div>{{ __('store.journal.footer_line') }}</div>
  <div><a href="{{ \App\Support\Url::to('/shop/') }}">{{ __('store.journal.nav_shop') }}</a> · <a href="{{ \App\Support\Url::to('/skin-quiz/') }}">{{ __('store.journal.nav_quiz') }}</a> · <a href="{{ \App\Support\Url::to('/skincare-guide/') }}">{{ __('store.journal.nav_journal') }}</a></div>
</div></footer>@verbatim
</body>
</html>

@endverbatim
