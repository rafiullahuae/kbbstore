@verbatim<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
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
  .nav-links{display:flex;gap:20px;margin-left:6px}
  .nav-links a{color:var(--ink-2);text-decoration:none;font-size:14px;font-weight:500}
  .nav-links a:hover,.nav-links a.on{color:var(--pink-deep)}
  .tools{margin-left:auto;display:flex;gap:8px}
  .tool{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;border:none;background:none;color:var(--ink);cursor:pointer;text-decoration:none}
  .tool:hover{background:var(--pink-soft);color:var(--pink-deep)}.tool svg{width:22px;height:22px}
  .burger{display:none;width:44px;height:44px;border-radius:12px;place-items:center;color:var(--ink);background:none;border:none;cursor:pointer}
  .burger svg{width:24px;height:24px}
  article{max-width:720px;margin:0 auto;padding:34px 20px 20px}
  .crumb{font-size:12.5px;color:var(--muted);margin-bottom:16px}.crumb a{text-decoration:none}.crumb a:hover{color:var(--pink-deep)}
  .atag{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pink-deep);background:var(--pink-soft);padding:5px 12px;border-radius:99px}
  h1{font-size:34px;font-weight:700;letter-spacing:-.02em;line-height:1.18;margin:14px 0 12px}
  .ameta{font-size:12.5px;color:var(--muted);display:flex;gap:8px;align-items:center;margin-bottom:22px}
  .cover{aspect-ratio:16/8;border-radius:var(--r-l);margin-bottom:28px;display:grid;place-items:center;font-size:56px;background:var(--cream)}
  .abody{font-size:16px;line-height:1.72;color:var(--ink)}
  .abody p{margin:0 0 18px}
  .abody h3{font-size:20px;font-weight:600;margin:28px 0 10px;letter-spacing:-.01em}
  .abody b{font-weight:600}
  .abody i{color:var(--ink-2)}
  .backrow{max-width:720px;margin:10px auto 0;padding:0 20px}
  .backrow a{font-size:13px;font-weight:600;color:var(--pink-deep);text-decoration:none}
  .more{border-top:1px solid var(--line-2);margin-top:40px;padding-top:30px}
  .more h2{font-size:19px;font-weight:700;margin-bottom:16px}
  .mgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
  .mcard{border:1px solid var(--line-2);border-radius:var(--r-l);overflow:hidden;text-decoration:none;color:var(--ink);transition:.2s var(--ease)}
  .mcard:hover{box-shadow:var(--sh-m);transform:translateY(-3px)}
  .mcover{aspect-ratio:16/10;display:grid;place-items:center;font-size:30px;background:var(--cream)}
  .mc{padding:14px}.mt{font-size:14px;font-weight:600;line-height:1.35}.mtag{font-size:10.5px;font-weight:700;text-transform:uppercase;color:var(--pink-deep);margin-bottom:5px}
  footer{background:var(--ink);color:#fff;padding:34px 0;margin-top:44px}
  footer .fin{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px;color:rgba(255,255,255,.75)}
  footer a{color:rgba(255,255,255,.75);text-decoration:none}footer a:hover{color:#fff}
  .navov{position:fixed;inset:0;background:rgba(42,34,40,.42);z-index:105;opacity:0;visibility:hidden;transition:.3s}
  .navov.on{opacity:1;visibility:visible}
  .mnav{position:fixed;top:0;left:0;height:100%;width:290px;max-width:85vw;background:#fff;z-index:110;transform:translateX(-100%);transition:.3s var(--ease);padding:20px;display:flex;flex-direction:column}
  .mnav.on{transform:none}
  .mnav a{padding:14px 6px;text-decoration:none;font-weight:500;border-bottom:1px solid var(--line-2)}
  .mnav-x{align-self:flex-end;font-size:20px;background:none;border:none;color:var(--ink-2);cursor:pointer;margin-bottom:6px}
  @media(max-width:900px){h1{font-size:26px}.mgrid{grid-template-columns:1fr}.nav-links{display:none}.burger{display:grid}}
</style>
</head>
<body>
<header class="head"><div class="wrap head-in">
  <button class="burger" onclick="document.getElementById('mnav').classList.add('on');document.getElementById('navov').classList.add('on')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  <a class="logo" href="/">K-Beauty<span>Bliss</span></a>
  <nav class="nav-links">
    <a href="/shop">Shop</a>
    <a href="/skin-quiz">Skin Quiz</a>
    <a href="/blog" class="on">Journal</a>
  </nav>
  <div class="tools">
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg></a>
    <a class="tool" href="/shop"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/></svg></a>
  </div>
</div></header>

<div class="navov" id="navov" onclick="this.classList.remove('on');document.getElementById('mnav').classList.remove('on')"></div>
<nav class="mnav" id="mnav">
  <button class="mnav-x" onclick="document.getElementById('mnav').classList.remove('on');document.getElementById('navov').classList.remove('on')">✕</button>
  <a href="/">Home</a><a href="/shop">Shop</a><a href="/skin-quiz">Skin Quiz</a><a href="/blog">Journal</a>
</nav>

<article id="article">
  @endverbatim
  <div class="crumb"><a href="{{ \App\Support\Url::to('/') }}">Home</a> / <a href="{{ \App\Support\Url::to('/skincare-guide/') }}">Journal</a></div>
  @if($post->tag)<span class="atag">{{ $post->tag }}</span>@endif
  <h1>{{ $post->title }}</h1>
  <div class="ameta"><span>{{ $post->author ?: 'K-Beauty Bliss' }}</span> · <span>{{ optional($post->published_at)->format('j F Y') }}</span></div>
  <div class="cover" style="{{ $post->cover && (str_contains($post->cover, 'gradient') || str_contains($post->cover, '#') || str_contains($post->cover, 'url')) ? 'background:' . $post->cover : 'background:linear-gradient(135deg,#FFF0F4,#FCE0E8)' }}">
    @if(!$post->cover || !(str_contains($post->cover, 'url') || str_contains($post->cover, 'http')))
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
       article body made two of them. See App\Support\BodyHeadings. --}}
  <div class="abody">{!! \App\Support\BodyHeadings::demoteH1(\App\Support\Shortcodes::render($post->body ?: '<p>' . e($post->excerpt) . '</p>')) !!}</div>
  @verbatim
</article>
@endverbatim
{{-- Straight to the index. "/blog" only 301s here, and a hardcoded root path
     drops the base prefix the staging subdirectory needs. --}}
<div class="backrow"><a href="{{ \App\Support\Url::to('/skincare-guide/') }}">&larr; Back to the Journal</a></div>
@verbatim

<div class="wrap">
@endverbatim
@unless($related->isEmpty())
  @verbatim
  <div class="more" id="more">
    <h2>More from the Journal</h2>
    <div class="mgrid" id="mgrid">
  @endverbatim
  @foreach($related as $r)
    <a class="mcard" href="{{ \App\Support\Url::to('/' . $r->slug . '/') }}">
      <div class="mcover" style="{{ $r->cover && (str_contains($r->cover, 'gradient') || str_contains($r->cover, '#') || str_contains($r->cover, 'url')) ? 'background:' . $r->cover : 'background:linear-gradient(135deg,#FFF0F4,#FCE0E8)' }}">
        {{ ['Routine' => '✍️', 'Ingredients' => '🌿', 'SPF' => '☀️', 'News' => '📰'][$r->tag] ?? '✨' }}
      </div>
      <div class="mc"><div class="mtag">{{ $r->tag }}</div><div class="mt">{{ $r->title }}</div></div>
    </a>
  @endforeach
  @verbatim
    </div>
  </div>
  @endverbatim
@endunless
@verbatim
</div>

<footer><div class="wrap fin">
  <div>© K-Beauty Bliss · Authentic Korean beauty in the UAE</div>
  <div><a href="/shop">Shop</a> · <a href="/skin-quiz">Skin Quiz</a> · <a href="/blog">Journal</a></div>
</div></footer>
</body>
</html>

@endverbatim
