{{--
    THE GLOW JOURNAL, /blog/, ON THE SHOP'S OWN LAYOUT.              (Lane BH)

    The owner: "on blog page, and on article page, the main header is not
    coming correct. i need the same as we have on all other pages. fix it on
    desktop + mobile both."

    WHAT WAS WRONG. This view was a STANDALONE DOCUMENT -- its own <html>, its
    own <head>, its own inline stylesheet and its own hand-drawn header: a
    wordmark, three text links (Shop / Skin Quiz / Journal), a magnifier and a
    bag that both just linked to /shop/, and a burger opening a four-link
    drawer. No search box, no account, wishlist or cart count, no menu bar, no
    mega menus, no announcement bar, no phone header and no phone menu sheet:
    69px of something that looked like another website, measured against the
    94px (desktop) / 127px (phone) header every other shop page draws. Its
    footer was a one-line dark strip, not the shop's footer. Every lane that
    touched this file since (brand colour, site width, Arabic face, page
    background, wash, breadcrumbs) had to re-add by hand what the layout gives
    every other page for free -- the five long notes this file used to carry.

    WHAT IT IS NOW. It extends layouts/store.blade.php like store/page.blade.php
    does, so the header, the menu bar, the phone chrome, the drawers, the
    footer, the WhatsApp button, the fonts (Outfit and, on /ar/, Cairo), the
    brand colour, the site width, the page background and the wash all come
    from the one place every other page gets them. The <head> is the layout's:
    App\Support\Seo::render() runs there ONCE, from the `$seoCtx` that
    PageController::journal() hands in -- the same context it used to render
    into `$seo` itself, so the title, description, canonical, hreflang, Open
    Graph and JSON-LD (CollectionPage + BreadcrumbList) are the same tags.
    JournalSharedHeaderTest pins both halves.

    WHAT STAYED THE SAME. The page's own content -- hero, tag chips, the grid of
    cards and the tag filter script -- is the markup it always was, byte for
    byte (StorefrontEnglishUnchangedTest compares it), inside one wrapper,
    `.kbb-journal`, that every rule of its stylesheet is now scoped to. The old
    sheet styled `h1`, `footer`, `.wrap`, `.head`, `.logo`, `.tool`, `.mnav`,
    `.navov` and `*` bare; on the shared layout those would have reached the
    shop's own header and footer. The header and drawer rules are gone with the
    header and drawer they drew. --}}
@extends('layouts.store')

@push('styles')
@verbatim
<style id="kbb-journal-css">
  /* The Journal's own sheet, scoped to its content (Lane BH). The colour
     tokens (--pink, --ink, --line ...) are NOT redeclared: kbb.css declares
     the same values on :root and the owner's brand colour overrides them
     there, so declaring them again here would only take the brand colour off
     this page again. The three this page drew differently are restated on the
     wrapper, so its cards keep their own radius and shadow. */
  .kbb-journal{--r-m:14px;--r-l:20px;--sh-m:0 6px 20px rgba(42,34,40,.08);font-size:14px;line-height:1.5;color:var(--ink)}
  .kbb-journal a{color:inherit}
  .kbb-journal .hero{padding:0;background:linear-gradient(135deg,var(--pink-soft),var(--cream));border-bottom:1px solid var(--line-2)}
  .kbb-journal .hero-in{padding:46px 0 38px;text-align:center}
  .kbb-journal .hero .ey{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:var(--pink-deep)}
  .kbb-journal h1{font-size:38px;font-weight:700;letter-spacing:-.02em;margin:8px 0 6px}
  .kbb-journal .hero p{color:var(--ink-2);max-width:560px;margin:0 auto}
  .kbb-journal .chips{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:22px 0 4px}
  .kbb-journal .chip{font-size:13px;font-weight:500;padding:8px 15px;border-radius:99px;background:#fff;border:1px solid var(--line);cursor:pointer;color:var(--ink-2)}
  .kbb-journal .chip:hover,.kbb-journal .chip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  /* `#grid`, because kbb.css's PRODUCT grid is `.kbb-pgrid,.rel,#grid` and
     this page's cards sit in an element with the same id. An id outranks any
     class pair, so `.kbb-journal .grid` lost and the Journal laid out as a
     product listing: five columns at 1280 instead of three, measured. */
  .kbb-journal #grid.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;padding:34px 0 56px}
  .kbb-journal .post{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease);display:flex;flex-direction:column;background:#fff}
  .kbb-journal .post:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .kbb-journal .cover{aspect-ratio:16/10;background:var(--cream);position:relative;display:grid;place-items:center;font-size:40px;overflow:hidden}
  /* object-fit:cover reproduces the `center/cover` the CSS background had. */
  .kbb-journal .cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
  .kbb-journal .cover .ptag{position:absolute;top:12px;inset-inline-start:12px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#fff;color:var(--pink-deep);padding:4px 10px;border-radius:99px}
  .kbb-journal .pbody{padding:18px 18px 20px;display:flex;flex-direction:column;flex:1}
  .kbb-journal .pdate{font-size:11.5px;color:var(--muted);margin-bottom:7px}
  .kbb-journal .ptitle{font-size:17px;font-weight:600;line-height:1.32;margin-bottom:8px}
  .kbb-journal .pex{font-size:13px;color:var(--ink-2);line-height:1.5;flex:1}
  .kbb-journal .pmore{margin-top:12px;font-size:13px;font-weight:600;color:var(--pink-deep)}
  .kbb-journal .empty{padding:60px;text-align:center;color:var(--muted);grid-column:1/-1}
  @media(max-width:900px){.kbb-journal #grid.grid{grid-template-columns:1fr}.kbb-journal h1{font-size:29px}}
</style>
@endverbatim
@endpush

@section('content')
<div class="kbb-journal">
<section class="hero"><div class="wrap hero-in">
  <div class="ey">{{ __('store.journal.eyebrow') }}</div>
  <h1>{{ __('store.journal.heading') }}</h1>
  <p>{{ __('store.journal.subtitle') }}</p>
  <div class="chips" id="chips"></div>
</div></section>

<div class="wrap"><div class="grid" id="grid">

@forelse($posts as $p)
  {{-- Posts live at the site root, one slug per post — the Phase 9 decision.
       The /skincare-guide/{slug}/ form and the site-root form are both 301s. --}}
  <a class="post" data-tag="{{ $p->tag }}" href="{{ \App\Support\Url::to(\App\Support\UrlScheme::article($p->slug)) }}">
    {{-- A real <img> so the cover photographs are indexable, with the emoji
         placeholder kept for the posts that have no photograph. The <img>
         comes before .ptag in the DOM on purpose: both are absolutely
         positioned, .ptag carries no z-index, and painting order is what keeps
         the tag on top of the photograph. --}}
    @php
      $coverSrc = \App\Support\CoverImage::src($p->cover);
    @endphp
    <div class="cover" style="background:{{ \App\Support\CoverImage::background($p->cover) }}">
      @if($coverSrc)
        {{-- The first card is the one most likely to be the largest element in
             view on arrival, so it loads eagerly; the rest are below it. --}}
        <img src="{{ $coverSrc }}" alt="{{ $p->t('title') }}" width="800" height="500"
             loading="{{ $loop->first ? 'eager' : 'lazy' }}">
      @else
        {{ ['Routine' => '✍️', 'Ingredients' => '🌿', 'SPF' => '☀️', 'News' => '📰'][$p->tag] ?? '✨' }}
      @endif
      @if($p->tag)<span class="ptag">{{ $p->tag }}</span>@endif
    </div>
    <div class="pbody">
      <div class="pdate">{{ optional($p->published_at)->format('j F Y') }}</div>
      <div class="ptitle">{{ $p->t('title') }}</div>
      <div class="pex">{{ $p->t('excerpt') }}</div>
      <div class="pmore">{{ __('store.journal.read_more') }}</div>
    </div>
  </a>
@empty
  <div class="empty">{{ __('store.journal.empty') }}</div>
@endforelse

</div></div>

</div>
@endsection

@push('scripts')
<script>
  /* The "All" chip's LABEL, rendered here in the shopper's language. Keyed
     `store.js.blog_tag_all`, so the Translation console reaches it the same way
     it reaches every other front-end string; it arrives as a JSON literal so the
     filter below does not depend on which strings window.KBB_T happens to carry. */
  window.KBB_BLOG_ALL_LABEL = @json(__('store.js.blog_tag_all'));
</script>
@verbatim
<script>
  /* Tag filter — client-side show/hide over the server-rendered cards above,
     not a re-fetch against an API. The cards and their content are real; this
     only toggles which of them are visible.

     ── WHAT THIS USED TO DO, AND WHY IT COULD NOT SURVIVE A TRANSLATION ─────

     The active chip was found by comparing its RENDERED TEXT against the tag
     value:

         c.classList.toggle('on', c.textContent.trim() === t)

     A chip's text is what the shopper reads and a tag is what the post is
     filed under, and those are the same string only for as long as nothing is
     translated. The first Arabic label breaks the comparison for EVERY chip,
     not only the one that was translated, because the chip the shopper clicked
     no longer matches the value that was passed — so the highlight dies on the
     whole row while the filtering below it still works. A filter that filters
     without saying what it filtered by is worse than one that does nothing.

     'All' was the second half of the same mistake: a WORD used as a sentinel,
     in three places — the list of chips, the chip that starts active, and the
     "show everything" branch. Translating the label would have silently turned
     the All chip into a filter for posts tagged "الكل", of which there are
     none, and the page would have gone blank.

     So the value and the label are now two different things. The value lives in
     `data-tag`, exactly the attribute the cards are already keyed by, and the
     sentinel is `null` — the ABSENCE of a tag, which is not a string in any
     language and cannot collide with a tag an admin types. The label is only
     ever drawn.

     ── AND THE ESCAPING HOLE THE SAME LINE CARRIED ─────────────────────────

     The chips were built by concatenating the tag into an HTML string, twice:

         `<button class="chip" onclick="setTag('${t.replace(/'/g,"\\'")}')">${t}</button>`

     Only the apostrophe was escaped, and only for the JavaScript string. A tag
     of `x" onmouseover="alert(1)` closes the attribute; a tag of `<img src=x
     onerror=alert(1)>` never needed an attribute at all, because `${t}` is
     interpolated as markup. Tags come from the posts table, which the admin
     writes — so this is not anonymous input, but it is the shape of hole that
     turns one compromised admin session into a persistent one, and the page has
     no reason to accept markup from a tag in the first place.

     Nothing is escaped now because nothing is parsed: the chip is built with
     createElement, the label is assigned through textContent, the value through
     dataset, and the handler is a real listener over a closed-over value rather
     than a string of code in an attribute. There is no context to break out of. */

  /* The absence of a tag. Deliberately not a word. */
  const ALL_TAGS = null;

  function setTag(t){
    document.querySelectorAll('#chips .chip').forEach(function(c){
      /* A dataset entry that was never set reads undefined, which is not the
         sentinel, so it is normalised rather than compared loosely — `==` here
         would also equate the All chip with a tag of the empty string. */
      var value = ('tag' in c.dataset) ? c.dataset.tag : ALL_TAGS;
      c.classList.toggle('on', value === t);
    });
    document.querySelectorAll('#grid .post').forEach(function(a){
      a.style.display = (t === ALL_TAGS || a.dataset.tag === t) ? '' : 'none';
    });
  }
  window.setTag = setTag;

  (function(){
    var chips = document.getElementById('chips');
    var posts = [].slice.call(document.querySelectorAll('#grid .post'));
    var tags = [];

    posts.forEach(function(a){
      var tag = a.dataset.tag;
      if (tag && tags.indexOf(tag) === -1) tags.push(tag);
    });

    function chip(label, value){
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'chip';
      b.textContent = label;
      if (value !== ALL_TAGS) b.dataset.tag = value;
      b.addEventListener('click', function(){ setTag(value); });
      return b;
    }

    chips.textContent = '';

    /* The label is the translated word; the value it filters by is the
       sentinel. The fallback keeps the page working if the <script> above it
       did not run — it is the English this line has always said. */
    var allChip = chip(window.KBB_BLOG_ALL_LABEL || 'All', ALL_TAGS);
    allChip.classList.add('on');
    chips.appendChild(allChip);

    tags.forEach(function(t){ chips.appendChild(chip(t, t)); });
  })();
</script>
@endverbatim
@endpush
