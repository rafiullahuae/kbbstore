@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
{!! app(\App\Services\Analytics::class)->headTags() !!}
@verbatim
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;
    --ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);--line-2:rgba(42,34,40,.06);
    --r-m:14px;--r-l:20px;--sh-m:0 6px 20px rgba(42,34,40,.08);--sans:'Poppins',system-ui,sans-serif;--ease:cubic-bezier(.22,.61,.36,1)}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--sans);color:var(--ink);background:var(--bg);font-size:14px;line-height:1.5}
  a{color:inherit}
  .wrap{max-width:1160px;margin:0 auto;padding:0 20px}
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
  .hero{background:linear-gradient(135deg,var(--pink-soft),var(--cream));border-bottom:1px solid var(--line-2)}
  .hero-in{padding:46px 0 38px;text-align:center}
  .hero .ey{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:var(--pink-deep)}
  h1{font-size:38px;font-weight:700;letter-spacing:-.02em;margin:8px 0 6px}
  .hero p{color:var(--ink-2);max-width:560px;margin:0 auto}
  .chips{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin:22px 0 4px}
  .chip{font-size:13px;font-weight:500;padding:8px 15px;border-radius:99px;background:#fff;border:1px solid var(--line);cursor:pointer;color:var(--ink-2)}
  .chip:hover,.chip.on{background:var(--pink);border-color:var(--pink);color:#fff}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;padding:34px 0 56px}
  .post{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease);display:flex;flex-direction:column;background:#fff}
  .post:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .cover{aspect-ratio:16/10;background:var(--cream);position:relative;display:grid;place-items:center;font-size:40px;overflow:hidden}
  /* object-fit:cover reproduces the `center/cover` the CSS background had. */
  .cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
  .cover .ptag{position:absolute;top:12px;inset-inline-start:12px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#fff;color:var(--pink-deep);padding:4px 10px;border-radius:99px}
  .pbody{padding:18px 18px 20px;display:flex;flex-direction:column;flex:1}
  .pdate{font-size:11.5px;color:var(--muted);margin-bottom:7px}
  .ptitle{font-size:17px;font-weight:600;line-height:1.32;margin-bottom:8px}
  .pex{font-size:13px;color:var(--ink-2);line-height:1.5;flex:1}
  .pmore{margin-top:12px;font-size:13px;font-weight:600;color:var(--pink-deep)}
  .empty{padding:60px;text-align:center;color:var(--muted);grid-column:1/-1}
  footer{background:var(--ink);color:#fff;padding:34px 0;margin-top:10px}
  footer .fin{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.75)}
  footer a{color:rgba(255,255,255,.75);text-decoration:none}footer a:hover{color:#fff}
  .navov{position:fixed;inset:0;background:rgba(42,34,40,.42);z-index:105;opacity:0;visibility:hidden;transition:.3s}
  .navov.on{opacity:1;visibility:visible}
  /* RTL-PHYSICAL: off-canvas panel — inset paired with translateX(-100%). */
  .mnav{position:fixed;top:0;left:0;height:100%;width:290px;max-width:85vw;background:#fff;z-index:110;transform:translateX(-100%);transition:.3s var(--ease);padding:20px;display:flex;flex-direction:column}
  .mnav.on{transform:none}
  .mnav a{padding:14px 6px;text-decoration:none;font-weight:500;border-bottom:1px solid var(--line-2)}
  .mnav-x{align-self:flex-end;font-size:20px;background:none;border:none;color:var(--ink-2);cursor:pointer;margin-bottom:6px}
  @media(max-width:900px){.grid{grid-template-columns:1fr}h1{font-size:29px}.nav-links{display:none}.burger{display:grid}}
</style>@endverbatim
</head>
<body>
<header class="head"><div class="wrap head-in">
  <button class="burger" onclick="document.getElementById('mnav').classList.add('on');document.getElementById('navov').classList.add('on')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="logo" href="/">K-Beauty<span>Bliss</span></a>
  <nav class="nav-links">
    <a href="/shop">{{ __('store.journal.nav_shop') }}</a>
    <a href="/skin-quiz">{{ __('store.journal.nav_quiz') }}</a>
    <a href="/blog" class="on">{{ __('store.journal.nav_journal') }}</a>
  </nav>
  <div class="tools">
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg></a>
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/></svg></a>
  </div>
</div></header>

<div class="navov" id="navov" onclick="this.classList.remove('on');document.getElementById('mnav').classList.remove('on')"></div>
<nav class="mnav" id="mnav">
  <button class="mnav-x" onclick="document.getElementById('mnav').classList.remove('on');document.getElementById('navov').classList.remove('on')">✕</button>
  <a href="/">{{ __('store.breadcrumb.home') }}</a><a href="/shop">{{ __('store.journal.nav_shop') }}</a><a href="/skin-quiz">{{ __('store.journal.nav_quiz') }}</a><a href="/blog">{{ __('store.journal.nav_journal') }}</a>
</nav>

<section class="hero"><div class="wrap hero-in">
  <div class="ey">{{ __('store.journal.eyebrow') }}</div>
  <h1>{{ __('store.journal.heading') }}</h1>
  <p>{{ __('store.journal.subtitle') }}</p>
  <div class="chips" id="chips"></div>
</div></section>

<div class="wrap"><div class="grid" id="grid">

@forelse($posts as $p)
  {{-- Posts live at the site root, one slug per post — the Phase 9 decision.
       The /skincare-guide/{slug}/ form this app used is now a 301. --}}
  <a class="post" data-tag="{{ $p->tag }}" href="{{ \App\Support\Url::to('/' . $p->slug . '/') }}">
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
        <img src="{{ $coverSrc }}" alt="{{ $p->title }}" width="800" height="500"
             loading="{{ $loop->first ? 'eager' : 'lazy' }}">
      @else
        {{ ['Routine' => '✍️', 'Ingredients' => '🌿', 'SPF' => '☀️', 'News' => '📰'][$p->tag] ?? '✨' }}
      @endif
      @if($p->tag)<span class="ptag">{{ $p->tag }}</span>@endif
    </div>
    <div class="pbody">
      <div class="pdate">{{ optional($p->published_at)->format('j F Y') }}</div>
      <div class="ptitle">{{ $p->title }}</div>
      <div class="pex">{{ $p->excerpt }}</div>
      <div class="pmore">{{ __('store.journal.read_more') }}</div>
    </div>
  </a>
@empty
  <div class="empty">{{ __('store.journal.empty') }}</div>
@endforelse

</div></div>

<footer><div class="wrap fin">
  <div>{{ __('store.journal.footer_line') }}</div>
  <div><a href="/shop">{{ __('store.journal.nav_shop') }}</a> · <a href="/skin-quiz">{{ __('store.journal.nav_quiz') }}</a> · <a href="/">{{ __('store.breadcrumb.home') }}</a></div>
</div></footer>
<script>
  /* The "All" chip's LABEL, rendered here in the shopper's language.
     This page is a standalone document — it does not extend layouts/store and
     carries no window.KBB_T — so the one string its script needs arrives as a
     JSON literal rather than through partials/js-strings.blade.php. It is still
     keyed `store.js.blog_tag_all`, so the Translation console reaches it the
     same way it reaches every other front-end string. */
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
</body>
</html>

@endverbatim
