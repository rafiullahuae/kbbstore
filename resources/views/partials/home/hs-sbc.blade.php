@php
/*
    Shop by Concern — "Content 1" of docs/sbc-preview/template-v3.html.  (Lane SBC)
    $sb   App\Support\ShopByConcern::section(): the words already plain text,
          every class and style an option key, an integer, a checked colour
          or a constant; each tab's url a category's own url() or a
          scheme-checked address.
    $cls  HomepageSections::classFor('sbc') — the Laptop / Phone switches.

    EVERY TAB IS DRAWN HERE, the others `hidden`, so switching asks the server
    for nothing: the script below only flips aria-selected and `hidden`. Lazy
    pictures inside a hidden panel are not fetched until it is shown. The
    phone carousel is CSS scroll-snap; its dots follow an IntersectionObserver
    (no measuring, no timer). The CSS is here, printed only when the section
    is, so a homepage with the section off is byte-for-byte what it was and no
    other page pays for it. Logical properties only (RTL), no newer CSS
    function outside @supports. Icons are constants with their own width and
    height, printed raw for that reason; every word goes through {{ }}.
*/
@endphp
<style>
.kbb-home .sec.sbc{--sbc-ink:#2A2228;--sbc-ink2:#5E545A;--sbc-soft:#FFF0F4;--sbc-deep:#C13E63;--sbc-red:#D7263D;background:var(--sbc-bg,#fff);color:var(--sbc-ink);padding-block:44px 48px}
.sbc-head h2{margin:0;font-size:30px;font-weight:700;line-height:1.2;display:flex;align-items:center;gap:10px;letter-spacing:-.01em;color:var(--sbc-ink)}
.sbc-hi{display:inline-flex;flex:0 0 34px;width:34px;height:34px;color:var(--sbc-pk)}
.sbc-head p{margin:6px 0 0;color:var(--sbc-ink2);font-size:15px}
.sbc svg{display:block}
.sbc-tabs{display:flex;flex-wrap:nowrap;gap:10px;margin-block:22px 24px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.sbc-tabs::-webkit-scrollbar{display:none}
.sbc-tab{display:flex;flex:0 0 auto;align-items:center;gap:9px;border:1px solid #E3DADD;background:#fff;border-radius:9px;padding-block:8px;padding-inline:9px 16px;font:inherit;font-size:15px;line-height:1.2;color:var(--sbc-ink2);cursor:pointer;white-space:nowrap;-webkit-tap-highlight-color:transparent}
.sbc-ic{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:var(--sbc-soft);color:var(--sbc-deep);--sbc-icf:transparent}
.sbc-ic svg{width:20px;height:20px}
.sbc-ic .f{fill:var(--sbc-icf)}
.sbc-tab[aria-selected="true"]{background:var(--sbc-pk);border-color:var(--sbc-pk);color:#fff}
.sbc-tab[aria-selected="true"] .sbc-ic{background:#fff;color:var(--sbc-pk);--sbc-icf:var(--sbc-soft)}
.sbc-tab:focus-visible,.sbc-go:focus-visible,.sbc-ln:focus-visible,.sbc-cp:focus-visible{outline:2px solid var(--sbc-deep);outline-offset:2px}
.sbc-pn{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,2fr);gap:12px}
.sbc-pn[hidden]{display:none}
.sbc-pn:focus{outline:none}
.sbc-hero{position:relative;overflow:hidden;border-radius:4px;padding:36px;min-height:420px;isolation:isolate}
.sbc-bgi{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:-2}
.sbc-photo::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(90deg,rgba(255,255,255,.93) 0%,rgba(255,255,255,.84) 58%,rgba(255,255,255,.12) 100%)}
[dir="rtl"] .sbc-photo::before{background:linear-gradient(270deg,rgba(255,255,255,.93) 0%,rgba(255,255,255,.84) 58%,rgba(255,255,255,.12) 100%)}
.sbc-photo h3,.sbc-photo p{text-shadow:0 0 14px rgba(255,255,255,.7)}
.sbc-tip{display:inline-block;background:var(--sbc-pk);color:#fff;border-radius:99px;font-size:12px;line-height:1.5;padding-block:2px;padding-inline:10px;font-weight:600;letter-spacing:.04em}
.sbc-hero h3{margin:10px 0;font-size:30px;font-weight:600;line-height:1.2;color:var(--sbc-ink)}
.sbc-hero p{margin:0;font-size:15.5px;line-height:1.45;max-width:250px;color:var(--sbc-ink2)}
.sbc-photo p{color:var(--sbc-ink)}
.sbc-shot{position:absolute;border-radius:50%;background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.08);display:grid;place-items:center;overflow:hidden;width:132px;height:132px}
.sbc-shot img{width:84%;height:84%;object-fit:contain}
.sbc-s1{inset-inline-end:28px;bottom:28px}
.sbc-s2{inset-inline-end:18px;bottom:150px;width:96px;height:96px}
.sbc-go{position:absolute;inset-inline-start:36px;bottom:30px;z-index:1;min-width:170px;text-align:center;background:#fff;color:var(--sbc-ink);border-radius:99px;padding-block:12px;padding-inline:28px;font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.sbc-go:hover{color:var(--sbc-deep)}
.sbc-rail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-content:start}
.sbc-it{position:relative;display:flex;background:var(--sbc-card,#F6F2F3);border-radius:4px;min-width:0}
.sbc-ln{display:flex;flex:1 1 auto;min-width:0;gap:14px;align-items:stretch;padding:0;color:inherit;text-decoration:none}
.sbc-th{display:grid;place-items:center;flex:0 0 120px;width:120px;min-height:120px;align-self:stretch;box-sizing:border-box;padding:5%;background:transparent;overflow:hidden}
.sbc-th img{width:100%;height:100%;object-fit:cover;mix-blend-mode:multiply;border-radius:3px}
.sbc-ph{display:grid;place-items:center;width:100%;height:100%;font-weight:700;font-size:18px;color:rgba(255,255,255,.92);letter-spacing:.04em}
.sbc-tx{display:block;align-self:center;min-width:0;padding-block:14px;padding-inline-end:52px}
.sbc-b{display:block;font-weight:700;font-size:12.5px;letter-spacing:.03em;text-transform:uppercase;color:var(--sbc-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sbc-n{display:-webkit-box;font-size:14.5px;margin-block:3px 6px;line-height:1.3;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:var(--sbc-ink)}
.sbc-pr{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;font-size:16px;font-weight:700}
.sbc-off{color:var(--sbc-red)}
.sbc-was{color:#796C75;font-weight:500;font-size:.8em;text-decoration:line-through}
.sbc-cp{position:absolute;inset-inline-end:12px;bottom:12px;width:36px;height:36px;border-radius:9px;background:var(--sbc-pk);color:#fff;display:grid;place-items:center;text-decoration:none}
.sbc-cp:hover{background:var(--sbc-deep);color:#fff}
.kbb-home .sbc-cp,.kbb-home .sbc-cp:hover,.kbb-home .sbc-cp:visited{color:#fff}
.sbc-cp svg{width:20px;height:20px}
.sbc-cp[disabled]{opacity:.6}
.sbc-dots{display:none}
@media (hover:hover){.sbc-tab:not([aria-selected="true"]):hover{border-color:var(--sbc-pk);color:var(--sbc-ink)}}
@media (min-width:901px){.sbc-rail{align-content:stretch;grid-auto-rows:1fr}}
@media (max-width:900px){
 .kbb-home .sec.sbc{padding-block:28px 30px;--sbc-bleed:clamp(18px,2vw,28px)}
 .sbc-head h2{font-size:23px}
 .sbc-hi{flex-basis:28px;width:28px;height:28px}
 .sbc-head p{font-size:13.5px}
 .sbc-tabs{margin-block:14px;margin-inline:calc(-1 * var(--sbc-bleed));padding-inline:var(--sbc-bleed);gap:8px}
 .sbc-tab{font-size:13.5px;padding-block:6px;padding-inline:6px 12px}
 .sbc-ic{flex-basis:26px;width:26px;height:26px}
 .sbc-ic svg{width:17px;height:17px}
 .sbc-pn{grid-template-columns:minmax(0,1fr);gap:10px}
 .sbc-hero{min-height:220px;padding-block:18px 16px;padding-inline:18px;flex-direction:column;display:flex}
 .sbc-tip{align-self:flex-start}
 .sbc-hero h3{font-size:20px;margin-block:6px 4px}
 .sbc-hero p{font-size:13px;max-width:58%;margin-block-end:14px}
 .sbc-photo p{max-width:100%}
 .sbc-photo::before,[dir="rtl"] .sbc-photo::before{background:linear-gradient(180deg,rgba(255,255,255,.92) 0%,rgba(255,255,255,.84) 62%,rgba(255,255,255,.3) 100%)}
 .sbc-s1{width:118px;height:118px;inset-inline-end:16px;top:24px;bottom:auto}
 .sbc-s2{display:none}
 .sbc-go{position:static;display:block;margin-top:auto;min-width:0;width:auto;align-self:flex-start;padding-block:10px;padding-inline:16px;font-size:14px}
 .sbc-rail{grid-template-columns:none;grid-auto-flow:column;grid-template-rows:repeat(2,auto);grid-auto-columns:82%;overflow-x:auto;scroll-snap-type:x mandatory;gap:8px;margin-inline:calc(-1 * var(--sbc-bleed));padding-inline:var(--sbc-bleed);scroll-padding-inline:var(--sbc-bleed);scrollbar-width:none;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch}
 .sbc-rail::-webkit-scrollbar{display:none}
 .sbc-it{scroll-snap-align:start}
 .sbc-ln{padding:0;gap:10px}
 .sbc-th{flex-basis:92px;width:92px;min-height:92px}
 .sbc-tx{padding-block:10px;padding-inline-end:44px}
 .sbc-b{font-size:11.5px}
 .sbc-n{font-size:13px}
 .sbc-pr{font-size:14.5px}
 .sbc-cp{width:32px;height:32px;inset-inline-end:8px;bottom:8px}
 .sbc-cp svg{width:18px;height:18px}
 .sbc-dots{display:flex;justify-content:center;gap:6px;margin-top:2px}
 .sbc-dots i{width:6px;height:6px;border-radius:99px;background:#E3D6DB;transition:width .2s}
 .sbc-dots i.on{width:18px;background:var(--sbc-pk)}
}
@media (max-width:680px){.kbb-home .sec.sbc{--sbc-bleed:12px}}
@media (prefers-reduced-motion:reduce){.sbc-dots i{transition:none}}
</style>
<section class="sec sbc {{ $cls }}" id="sbc" style="{{ $sb['style'] }}"@if ($sb['title'] !== '') aria-labelledby="sbc-h"@endif><div class="wrap">
{!! \App\Support\ShopByConcern::CART_SYMBOL !!}
@if ($sb['title'] !== '' || $sb['sub'] !== '')<div class="sbc-head hs-ht">@if ($sb['title'] !== '')<h2 id="sbc-h">@if ($sb['icon'])<span class="sbc-hi">{!! \App\Support\ShopByConcern::HEAD_ICON !!}</span>@endif{{ $sb['title'] }}</h2>@endif
@if ($sb['sub'] !== '')<p>{{ $sb['sub'] }}</p>@endif</div>@endif
<div class="sbc-tabs" role="tablist"@if ($sb['title'] !== '') aria-labelledby="sbc-h"@endif>@foreach ($sb['tabs'] as $i => $t)<button type="button" role="tab" class="sbc-tab" id="sbc-t{{ $i }}" aria-controls="sbc-p{{ $i }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" tabindex="{{ $i === 0 ? '0' : '-1' }}" data-i="{{ $i }}"><span class="sbc-ic">{!! $t['icon'] !!}</span><span>{{ $t['label'] }}</span></button>@endforeach</div>
@foreach ($sb['tabs'] as $i => $t)
<div class="sbc-pn" role="tabpanel" id="sbc-p{{ $i }}" aria-labelledby="sbc-t{{ $i }}" tabindex="0"@if ($i > 0) hidden @endif>
<div class="sbc-hero{{ $t['img'] !== '' ? ' sbc-photo' : '' }}" style="background:{{ $t['grad'] }}">@if ($t['img'] !== '')<img class="sbc-bgi" src="{{ $t['img'] }}" alt="{{ $t['title'] }}" width="800" height="900"@if ($t['srcset'] !== '') srcset="{{ $t['srcset'] }}" sizes="(max-width:900px) 100vw, 480px"@endif loading="{{ $t['eager'] ? 'eager' : 'lazy' }}" decoding="async">@endif
@if ($sb['tip'] !== '')<span class="sbc-tip">{{ $sb['tip'] }}</span>@endif
@if ($t['title'] !== '')<h3>{{ $t['title'] }}</h3>@endif
@if ($t['tip'] !== '')<p>{{ $t['tip'] }}</p>@endif
@if ($t['img'] === '')@foreach (array_slice($t['items'], 0, 2) as $n => $shot)@if ($shot['img'] !== '')<span class="sbc-shot sbc-s{{ $n + 1 }}" aria-hidden="true"><img src="{{ $shot['img'] }}" alt=""@if ($shot['srcset'] !== '') srcset="{{ $shot['srcset'] }}" sizes="132px"@endif width="132" height="132" loading="lazy" decoding="async"></span>@endif @endforeach @endif
@if ($t['url'] !== '' && $sb['all'] !== '')<a class="sbc-go" href="{{ $t['url'] }}">{{ $sb['all'] }} <span aria-hidden="true">{{ \App\Support\Locale::isRtl() ? '‹' : '›' }}</span></a>@endif
</div>
<div class="sbc-rail">@foreach ($t['items'] as $p)<div class="sbc-it"><a class="sbc-ln" href="{{ $p['url'] }}"><span class="sbc-th">@if ($p['img'] !== '')<img src="{{ $p['img'] }}" alt="{{ $p['alt'] }}"@if ($p['srcset'] !== '') srcset="{{ $p['srcset'] }}" sizes="(max-width:900px) 92px, 120px"@endif width="84" height="84" loading="lazy" decoding="async">@else<span class="sbc-ph" style="background:{{ $p['ph'] }}">{{ $p['ini'] }}</span>@endif</span><span class="sbc-tx">@if ($p['brand'] !== '')<span class="sbc-b">{{ mb_strtoupper($p['brand']) }}</span>@endif<span class="sbc-n">{{ $p['name'] }}</span><span class="sbc-pr">@if ($p['was'] !== '')<span class="sbc-was">{!! $p['was'] !!}</span>@endif<span class="sbc-now">{!! $p['price'] !!}</span></span></span></a>@if ($p['add'])<a class="sbc-cp" href="?add-to-cart={{ $p['pid'] }}" data-kbb-add="{{ $p['id'] }}" data-quantity="1" data-price="{{ $p['amount'] }}" data-name="{{ $p['name'] }}" rel="nofollow" aria-label="{{ $sb['add'] }}: {{ $p['name'] }}">{!! \App\Support\ShopByConcern::CART !!}</a>@else<a class="sbc-cp" href="{{ $p['url'] }}" aria-label="{{ $sb['view'] }}: {{ $p['name'] }}">{!! \App\Support\ShopByConcern::CART !!}</a>@endif</div>@endforeach</div>
@if (count($t['items']) > 2)<div class="sbc-dots" aria-hidden="true">@for ($d = 0; $d < (int) ceil(count($t['items']) / 2); $d++)<i{!! $d === 0 ? ' class="on"' : '' !!}></i>@endfor</div>@endif
</div>
@endforeach
</div></section>
<script>
/* No less-than sign anywhere in this script: strip_tags() and other naive
   readers of the page take one followed by a letter for a tag and swallow the
   page after it (TrustClaimsAreTheOwnersTest). Comparisons are written b>a. */
(function(){var r=document.getElementById('sbc');if(!r||r.getAttribute('data-sbc'))return;r.setAttribute('data-sbc','1');
var t=r.querySelectorAll('.sbc-tab'),p=r.querySelectorAll('.sbc-pn'),rtl=document.documentElement.dir==='rtl';
function dots(n){if(!n||n.getAttribute('data-io')||!('IntersectionObserver' in window))return;n.setAttribute('data-io','1');var d=n.querySelectorAll('.sbc-dots i'),rail=n.querySelector('.sbc-rail');if(!d.length||!rail)return;
var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){var k=+e.target.getAttribute('data-c');for(var j=0;d.length>j;j++)d[j].classList.toggle('on',j===k)}})},{root:rail,threshold:.6});
for(var j=0;rail.children.length>j;j+=2){rail.children[j].setAttribute('data-c',j/2);o.observe(rail.children[j])}}
function go(i,f){for(var j=0;t.length>j;j++){var on=j===i;t[j].setAttribute('aria-selected',on?'true':'false');t[j].tabIndex=on?0:-1;p[j].hidden=!on}if(f)t[i].focus();dots(p[i])}
r.addEventListener('click',function(e){var b=e.target.closest&&e.target.closest('.sbc-tab');if(b)go(+b.getAttribute('data-i'))});
r.addEventListener('keydown',function(e){var b=e.target.closest&&e.target.closest('.sbc-tab');if(!b)return;var i=+b.getAttribute('data-i'),n=t.length,k=e.key;
if(k==='ArrowRight'||k==='ArrowLeft'){i=(i+((k==='ArrowRight')!==rtl?1:-1)+n)%n}else if(k==='Home'){i=0}else if(k==='End'){i=n-1}else{return}e.preventDefault();go(i,true)});
dots(p[0])})();
</script>
