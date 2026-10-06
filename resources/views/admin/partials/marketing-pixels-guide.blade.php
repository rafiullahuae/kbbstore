{{--
    Growth & Marketing → Marketing Pixels → the eye button in front of each
    field, and the guide it opens.                                   (Lane PX)

    The owner: "on this page, i need proper guide for each one, along with live
    urls to create these things. make a beautiful popup eye icon infront of each
    one."

    ── HOW IT JOINS THE SCREEN WITHOUT EDITING IT ───────────────────────────

    paintPixels() lives in resources/views/admin/app.blade.php's first script block
    as a top-level function declaration, so it is a property of `window` and
    every call to it — the first paint and the repaint after Save — goes through
    that property. This file wraps it, the way product-trust-share-screen wraps
    paintProductPage(): the original paints the screen exactly as before, then
    an eye button goes in front of each field's label. The screen's own half is
    untouched, so the one @include in docs/px-wiring.json is the whole change to
    that file.

    ── LIGHT ────────────────────────────────────────────────────────────────

    Every guide is static text rendered once into a <template> below — opening
    one is a clone, not a request. The links are constants, every one an
    official Meta / Google / TikTok page (tests/Feature/MarketingPixelsGuideTest
    holds them to an allowlist of hosts, https, new tab, noopener noreferrer).
    The "events this shop sends" chips are read from MarketingPixels::SCHEMA's
    own help lines, so the guide cannot drift from what the shop fires. Both
    blocks stay under AdminConsoleAssets::MIN_BYTES: inline, no build file.

    The dialog reuses the console's .modal-bg / .modal / .modal-h / .modal-b
    look in its own element (the shared #modalBg has no Esc, no focus trap and
    no phone sheet, and changing it would change every other dialog). On a
    phone it is a bottom sheet; on a laptop a centred dialog. Esc, the backdrop
    and × close it; Tab stays inside it; focus goes back to the eye button.
--}}
@php
    $pxgSchema = \App\Services\MarketingPixels::SCHEMA;
    $pxgEvents = static function (string $help): array {
        $list = preg_replace('/^Fires\s+|\.$/', '', trim($help));

        return array_values(array_filter(array_map('trim', preg_split('/,\s*|\s+and\s+/', (string) $list) ?: [])));
    };
    $pxgGuides = [
        'meta_id' => [
            'badge' => 'M', 'tone' => 'meta',
            'what' => 'The number that tells Facebook and Instagram ads which visits and sales on this shop came from your ads.',
            'steps' => [
                'Open Meta Events Manager and sign in with the Facebook account that runs your business’s ads.',
                'Click Connect data, choose Web, then Connect.',
                'Name it (for example “Extra Beauty website”) and click Create pixel. If Meta offers ways to install it, you can stop there — this shop adds the code for you.',
                'In Events Manager, open the pixel (dataset) under Data sources. Its ID is the number under its name — copy it.',
            ],
            'looks' => 'Numbers only, 15 or 16 digits.',
            'sample' => '123456789012345',
            'test' => 'Install the Meta Pixel Helper in Chrome and open the shop — it should show PageView. Or in Events Manager open the pixel → Test events, enter the shop’s address and browse: events appear within seconds. Pause any ad blocker while testing.',
            'links' => [
                ['Open Meta Events Manager', 'https://business.facebook.com/events_manager2/'],
                ['Meta help: Set up and install the Meta Pixel', 'https://www.facebook.com/business/help/952192354843755'],
                ['Meta help: Navigate Events Manager (Test events)', 'https://www.facebook.com/business/help/898185560232180'],
                ['Meta Pixel Helper (browser check)', 'https://developers.facebook.com/docs/meta-pixel/support/pixel-helper/'],
            ],
        ],
        'ga4_id' => [
            'badge' => 'G', 'tone' => 'ga4',
            'what' => 'The ID of your website’s data stream in Google Analytics 4 — it sends visits, product views and sales to your GA4 reports.',
            'steps' => [
                'Open Google Analytics and sign in with your Google account.',
                'No property yet? Click Admin (the gear, bottom left) → Create → Property. Name it, set the time zone and currency (United Arab Emirates, AED) and follow the steps.',
                'When it asks for a platform choose Web, enter the shop’s address and a stream name, then Create stream.',
                'Already set up? Admin → Data collection and modification → Data streams → Web → click your stream. The Measurement ID is in the first row — copy it. You need Editor access or above to see it.',
            ],
            'looks' => '“G-” followed by capital letters and digits, usually ten.',
            'sample' => 'G-XXXXXXXXXX',
            'test' => 'Open Reports → Realtime and visit the shop in another tab: you should appear within a minute. For event-by-event detail use Admin → DebugView (it needs debug mode, for example from Google Tag Assistant).',
            'links' => [
                ['Open Google Analytics', 'https://analytics.google.com/'],
                ['Google help: Set up Analytics for a website', 'https://support.google.com/analytics/answer/9304153'],
                ['Google help: Find your Measurement ID', 'https://support.google.com/analytics/answer/12270356'],
                ['Google help: Realtime report', 'https://support.google.com/analytics/answer/9271392'],
                ['Google help: Monitor events in DebugView', 'https://support.google.com/analytics/answer/7201382'],
            ],
        ],
        'tiktok_id' => [
            'badge' => 'T', 'tone' => 'tiktok',
            'what' => 'The pixel code that tells TikTok ads which visits and purchases on this shop came from your TikTok campaigns.',
            'steps' => [
                'Open TikTok Ads Manager and sign in to the ad account you advertise with.',
                'Go to Tools → Events (this opens Events Manager) and click Connect data source.',
                'Choose Web, enter the shop’s address, pick Manual setup and the TikTok Pixel option, name it and click Create. You can skip the install code — this shop adds it for you.',
                'Under Data sources, open the pixel: its ID (the pixel code) is under its name — copy it.',
            ],
            'looks' => 'About 20 characters — capital letters and digits, usually starting with C.',
            'sample' => 'C4ABCDEF1GH2IJ3KL4M5',
            'test' => 'Install the TikTok Pixel Helper in Chrome and open the shop. Or in Events Manager open the pixel → Test events, scan the QR code with the TikTok app and browse the shop.',
            'links' => [
                ['Open TikTok Ads Manager', 'https://ads.tiktok.com/'],
                ['TikTok help: Set up and verify a pixel', 'https://ads.tiktok.com/help/article/get-started-pixel'],
                ['TikTok help: Create and find your Pixel ID', 'https://ads.tiktok.com/help/article/how-to-create-and-access-tiktok-pixel-id'],
                ['TikTok Pixel Helper 2.0', 'https://ads.tiktok.com/help/article/tiktok-pixel-helper-2.0'],
            ],
        ],
    ];
@endphp
@foreach ($pxgGuides as $pxgKey => $g)
@php $pxgLabel = $pxgSchema[$pxgKey]['label']; @endphp
<template id="pxg-t-{{ $pxgKey }}" data-name="How to get your {{ $pxgLabel }}">
  <div class="modal-h pxg-h">
    <span class="pxg-badge pxg-{{ $g['tone'] }}" aria-hidden="true">{{ $g['badge'] }}</span>
    <div class="pxg-ht"><b id="pxg-title">How to get your {{ $pxgLabel }}</b><span>{{ $g['what'] }}</span></div>
    <button type="button" class="x" data-pxg-close aria-label="Close"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
  </div>
  <div class="modal-b pxg-b">
    <p class="pxg-mod"><span class="pxg-off">The module is <b>off</b>, so no tag loads yet, even with an ID filled in. Turn it on under <a href="#modules" data-pxg-go>Store → Modules → Marketing Pixels</a>.</span><span class="pxg-on">The module is <b>on</b> (<a href="#modules" data-pxg-go>Store → Modules → Marketing Pixels</a>) — this tag loads as soon as its ID is saved.</span></p>
    <h4>Official links</h4>
    <ul class="pxg-links">
      @foreach ($g['links'] as [$text, $href])<li><a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $text }}<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg><span class="pxg-sr"> (opens in a new tab)</span></a></li>@endforeach
    </ul>
    <h4>Create it and copy the ID</h4>
    <ol class="pxg-steps">
      @foreach ($g['steps'] as $step)<li>{{ $step }}</li>@endforeach
    </ol>
    <div class="pxg-grid">
      <div><h4>What it looks like</h4><p>{{ $g['looks'] }}</p><code class="pxg-code">{{ $g['sample'] }}</code></div>
      <div><h4>Where it goes</h4><p>Paste it into <b>{{ $pxgLabel }}</b> on this page and press <b>Save changes</b>. Leave the box empty to switch this tag off.</p></div>
    </div>
    <h4>Events this shop sends</h4>
    <p class="pxg-ev">@foreach ($pxgEvents($pxgSchema[$pxgKey]['help']) as $ev)<span>{{ $ev }}</span>@endforeach</p>
    <h4>Test it</h4>
    <p class="pxg-tip">{{ $g['test'] }}</p>
    <div class="pxg-foot"><button type="button" class="btn primary" data-pxg-paste="{{ $pxgKey }}">Paste my ID</button></div>
  </div>
</template>
@endforeach
@verbatim
<style>
.pxg-eye{flex:none;width:26px;height:26px;border-radius:50%;display:inline-grid;place-items:center;border:1px solid transparent;background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a);cursor:pointer;padding:0;transition:background .15s,color .15s,transform .15s}
.pxg-eye{position:relative}
.pxg-eye::after{content:"";position:absolute;inset:-9px}
.pxg-eye:hover{background:var(--accent,#15a85a);color:#fff;transform:scale(1.06)}
.pxg-eye:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:2px}
.pxg-bg{z-index:130}
.pxg{max-width:620px;display:flex;flex-direction:column;overflow:hidden}
.pxg-h{align-items:flex-start;flex:none}
.pxg-ht{min-width:0}
.pxg-ht b{display:block;font-size:16px;line-height:1.3}
.pxg-ht span{display:block;font-size:12.5px;color:var(--ink-soft,#626c80);margin-top:3px;line-height:1.45}
.pxg-badge{flex:none;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-weight:800;font-size:16px;color:#fff}
.pxg-meta{background:linear-gradient(135deg,#0866ff,#4b9bff)}
.pxg-ga4{background:linear-gradient(135deg,#f9ab00,#e37400)}
.pxg-tiktok{background:linear-gradient(135deg,#111,#2b2b2b);box-shadow:inset 2px 0 #25f4ee,inset -2px 0 #fe2c55}
.pxg-b{overflow:auto;-webkit-overflow-scrolling:touch;font-size:13px;line-height:1.55;color:var(--ink-2,#2c3445)}
.pxg-b h4{margin:16px 0 6px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft,#626c80)}
.pxg-b p{margin:0}
.pxg-mod{border-radius:10px;padding:9px 12px;font-size:12.5px}
.pxg .pxg-on{display:none}
.pxg-mod{background:#FFF8E6;border:1px solid #F0DFB0;color:#7a5c14}
.pxg.is-on .pxg-mod{background:var(--accent-soft,#e7f7ee);border-color:transparent;color:var(--accent-ink,#0b6e3a)}
.pxg.is-on .pxg-on{display:inline}.pxg.is-on .pxg-off{display:none}
.pxg-mod a{color:inherit;font-weight:600}
.pxg-steps{margin:0;padding:0;list-style:none;counter-reset:s}
.pxg-steps li{counter-increment:s;position:relative;padding:0 0 9px 34px}
.pxg-steps li::before{content:counter(s);position:absolute;left:0;top:-1px;width:23px;height:23px;border-radius:50%;background:var(--accent-soft,#e7f7ee);color:var(--accent-ink,#0b6e3a);font-weight:700;font-size:12px;display:grid;place-items:center}
.pxg-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pxg-code{display:inline-block;margin-top:6px;padding:4px 9px;border-radius:7px;background:var(--surface-2,#f4f6fa);border:1px solid var(--border,#e6e9f2);font:600 12.5px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.03em;word-break:break-all}
.pxg-ev{display:flex;flex-wrap:wrap;gap:6px}
.pxg-ev span{padding:3px 9px;border-radius:99px;background:var(--surface-2,#f4f6fa);border:1px solid var(--border,#e6e9f2);font:500 12px ui-monospace,SFMono-Regular,Menlo,monospace}
.pxg-tip{padding:10px 12px;border-radius:10px;background:var(--surface-2,#f4f6fa)}
.pxg-links{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px}
.pxg-links a{display:flex;align-items:center;gap:8px;justify-content:space-between;height:100%;box-sizing:border-box;padding:9px 12px;line-height:1.35;border:1px solid var(--border,#e6e9f2);border-radius:10px;color:var(--ink,#101729);text-decoration:none;font-weight:600;font-size:12.5px}
.pxg-links a:hover{border-color:var(--accent,#15a85a);color:var(--accent-ink,#0b6e3a)}
.pxg-links a:focus-visible{outline:2px solid var(--accent,#15a85a);outline-offset:1px}
.pxg-links svg{flex:none}
.pxg-links li:first-child a{background:linear-gradient(120deg,var(--accent,#15a85a),var(--accent-strong,#0f8f4b));border-color:transparent;color:#fff}
.pxg-links li:first-child a:hover{color:#fff;filter:brightness(1.05)}
.pxg-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.pxg-foot{display:flex;justify-content:flex-end;margin-top:16px}
@media (max-width:640px){
  .pxg-bg{align-items:flex-end;padding:0}
  .pxg{max-width:none;max-height:90vh;border-radius:18px 18px 0 0;animation:pxgUp .26s var(--ease,ease-out)}
  .pxg-h{padding:22px 16px 14px;position:relative}
  .pxg-h::before{content:"";position:absolute;top:8px;left:50%;width:38px;height:4px;margin-left:-19px;border-radius:4px;background:var(--border,#e6e9f2)}
  .pxg-b{padding:4px 16px calc(18px + env(safe-area-inset-bottom,0px))}
  .pxg-grid,.pxg-links{grid-template-columns:1fr}
  .pxg-foot .btn{width:100%}
}
@keyframes pxgUp{from{transform:translateY(40px);opacity:0}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){.pxg,.pxg-eye{animation:none!important;transition:none!important}}
</style>
<script>
(function () {
  if (typeof window.paintPixels !== 'function' || window.paintPixels.__pxg) return;

  var EYE = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var bg = null, box = null, opener = null, lastKey = '';

  function decorate() {
    var inputs = document.querySelectorAll('#content [data-mp]');
    for (var i = 0; i < inputs.length; i++) {
      var key = inputs[i].getAttribute('data-mp');
      var tpl = document.getElementById('pxg-t-' + key);
      var row = inputs[i].closest('.ecopt');
      var ecl = row && row.querySelector('.ecl');
      if (!tpl || !ecl || ecl.querySelector('.pxg-eye')) continue;
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'pxg-eye';
      b.setAttribute('data-pxg', key);
      b.setAttribute('aria-haspopup', 'dialog');
      b.setAttribute('aria-label', tpl.getAttribute('data-name'));
      b.title = tpl.getAttribute('data-name');
      b.innerHTML = EYE;
      b.onclick = function () { open(this.getAttribute('data-pxg'), this); };
      ecl.insertBefore(b, ecl.firstChild);
    }
  }

  function focusables() {
    return box.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])');
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    var f = focusables();
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && (document.activeElement === first || !box.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && (document.activeElement === last || !box.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
  }

  function open(key, from) {
    var tpl = document.getElementById('pxg-t-' + key);
    if (!tpl) return;
    if (!bg) {
      bg = document.createElement('div');
      bg.className = 'modal-bg pxg-bg';
      bg.innerHTML = '<div class="modal pxg" role="dialog" aria-modal="true" aria-labelledby="pxg-title" tabindex="-1"></div>';
      box = bg.firstChild;
      bg.addEventListener('click', function (e) {
        var t = e.target;
        if (t === bg || t.closest('[data-pxg-close]')) { close(); return; }
        var go = t.closest('[data-pxg-go]');
        if (go) { e.preventDefault(); close(); if (typeof window.go === 'function') window.go('modules'); return; }
        var paste = t.closest('[data-pxg-paste]');
        if (paste) {
          var k = paste.getAttribute('data-pxg-paste');
          close(false);
          var inp = document.querySelector('#content [data-mp="' + k + '"]');
          if (inp) { inp.focus(); inp.select(); }
        }
      });
      document.body.appendChild(bg);
    }
    opener = from || null;
    lastKey = key;
    box.innerHTML = '';
    box.appendChild(tpl.content.cloneNode(true));
    box.classList.toggle('is-on', !!(typeof MP !== 'undefined' && MP && MP.module_on));
    bg.classList.add('on');
    document.documentElement.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey, true);
    var x = box.querySelector('[data-pxg-close]');
    (x || box).focus();
  }

  function close(back) {
    if (!bg || !bg.classList.contains('on')) return;
    bg.classList.remove('on');
    document.documentElement.style.overflow = '';
    document.removeEventListener('keydown', onKey, true);
    if (back !== false && opener && document.contains(opener)) opener.focus();
    else if (back !== false && lastKey) {
      var again = document.querySelector('#content [data-pxg="' + lastKey + '"]');
      if (again) again.focus();
    }
    opener = null;
  }

  var original = window.paintPixels;
  var wrapped = function () {
    var r = original.apply(this, arguments);
    decorate();
    return r;
  };
  wrapped.__pxg = true;
  window.paintPixels = wrapped;
  window.kbbPixelGuide = { open: open, close: close };

  if (document.querySelector('#content [data-mp]')) decorate();
})();
</script>
@endverbatim
