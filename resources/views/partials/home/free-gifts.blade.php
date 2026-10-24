{{--
    THE HOMEPAGE FREE GIFTS SECTION -- Marketing -> Free gifts -> Homepage section.

    The owner, 23-24 October, picked design 07 "One card" with the coupon FREE
    tag (preview no. 20): one green-outlined card, a row per published range
    (amount, wording, FREE coupon, gift photos), and each row opens a popup
    with that range's gifts -- photo, brand, name -- that swipes between the
    ranges. "if we have new tier range, it should auto add and if we turn off
    any, it should remove": every row comes from FreeGifts::home(), which reads
    the ranges the checkout reads, so nothing here needs touching.

    THE POPUP IS ONE FIXED SIZE ("i want a fixed size popup, if more products
    in popup, then the products images sizes will be reduced"): a native
    <dialog>, 600x620 on a computer and a 78%-high sheet on a phone. The
    gifts' columns and rows come from how many there are (--c/--r computer,
    --cm/--rm phone, worked out below), every cell is a share of the height,
    and the photo sits absolutely inside it, so more gifts means smaller
    photos and never a bigger or scrolling popup.

    Light by construction: styles and the one small script live in this
    partial, so they load only on a homepage that draws the section. The
    popup photos are lazy and inside a closed dialog, so the browser fetches
    none of them until the owner's shopper opens it. The script measures no
    layout -- the slide on show comes from an IntersectionObserver, a jump is
    scrollIntoView -- and starts no timer. Every string printed is escaped.
--}}
@php
    $fghAr = (bool) ($fgh['ar'] ?? false);
    $fghT = $fghAr
        ? ['free' => 'مجاناً', 'hint' => 'تُضاف إلى حقيبتك تلقائياً عند وصول طلبك إلى هذا المبلغ.', 'of' => 'المستوى :i من :n', 'close' => 'إغلاق', 'prev' => 'المستوى السابق', 'next' => 'المستوى التالي', 'view' => 'اعرض الهدايا']
        : ['free' => 'Free', 'hint' => 'Added to your bag automatically when your order reaches this amount.', 'of' => 'Level :i of :n', 'close' => 'Close', 'prev' => 'Previous level', 'next' => 'Next level', 'view' => 'See the gifts'];
    $fghN = count($fgh['rows']);
    /* Columns x rows for n gifts: computer, then phone. */
    $fghLay = static function (int $n): string {
        $d = $n <= 3 ? [max(1, $n), 1] : ($n === 4 ? [2, 2] : ($n <= 6 ? [3, 2] : ($n <= 8 ? [4, 2] : ($n <= 9 ? [3, 3] : ($n <= 12 ? [4, 3] : [5, (int) ceil($n / 5)])))));
        $m = $n <= 2 ? [max(1, $n), 1] : ($n <= 4 ? [2, 2] : ($n <= 6 ? [2, 3] : ($n <= 9 ? [3, 3] : ($n <= 12 ? [3, 4] : [4, (int) ceil($n / 4)]))));

        return '--c:' . $d[0] . ';--r:' . $d[1] . ';--cm:' . $m[0] . ';--rm:' . $m[1];
    };
    $fghImg = static function (array $g, int $w, string $sizes): string {
        $img = (string) $g['image'];
        if ($img === '') {
            return '<span class="fgh-ph" role="img" aria-label="' . e($g['name']) . '">' . e(mb_strtoupper(mb_substr($g['brand'] !== '' ? $g['brand'] : $g['name'], 0, 2))) . '</span>';
        }
        $set = \App\Support\ImageVariants::srcsetFor($img);

        return '<img src="' . e(\App\Support\ImageVariants::variantUrl($img, $w)) . '" alt="' . e(trim($g['brand'] . ' ' . $g['name'])) . '" loading="lazy" decoding="async"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . $sizes . '"' : '') . '>';
    };
    $fghGift = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>';
    $fghGift2 = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><rect x="3.5" y="10" width="17" height="11" rx="2" fill="#fff"/><rect x="2.5" y="7" width="19" height="4.2" rx="1.4" fill="#ffe3ec"/><rect x="10.6" y="7" width="2.8" height="14" fill="#2e8f62"/><path d="M12 7c-1.2-2.6-3.6-4.3-5.3-3.6-1.4.6-1.2 2.6.4 3.3C8.4 7.2 10.4 7.2 12 7zm0 0c1.2-2.6 3.6-4.3 5.3-3.6 1.4.6 1.2 2.6-.4 3.3-1.3.5-3.3.5-4.9.3z" fill="#2e8f62"/></svg>';
    $fghArr = static fn (bool $next): string => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ($next ? 'M9 6l6 6-6 6' : 'M15 6l-6 6 6 6') . '"/></svg>';
@endphp
@php
    $fghBg = $fgh['bg'] ?? \App\Services\FreeGifts::homeBg([]);
    $fghW = \App\Services\FreeGifts::washed($fghBg);
@endphp
<section class="sec fgh fgh-bg-{{ $fghBg['bg'] }} {{ $cls ?? '' }}" aria-labelledby="fgh-h" data-fgh style="--fgw:{{ (int) $fghBg['width'] }}%@if ($fghBg['bg'] === 'flow' || $fghBg['bg'] === 'still');--fg1:{{ $fghW[0] }};--fg2:{{ $fghW[1] }};--fg3:{{ $fghW[2] }}@endif"><style>
/* The background (Marketing -> Free gifts -> Homepage section -> Background):
   three brand pinks washed to a few percent over white on the server, drawn on
   a layer behind the section. "Moving" slides that layer with transform only,
   which the compositor does without repainting the page; reduced motion stops it. */
.fgh{position:relative;isolation:isolate;overflow:hidden}
.fgh-bg-white{background:#fff}
.fgh-bg-flow::before,.fgh-bg-still::before{content:"";position:absolute;top:0;bottom:0;left:0;width:100%;z-index:-1;pointer-events:none;background:linear-gradient(115deg,var(--fg1),var(--fg2),var(--fg3),var(--fg1))}
.fgh-bg-flow::before{width:300%;background:linear-gradient(115deg,var(--fg1),var(--fg2),var(--fg3),var(--fg1),var(--fg2),var(--fg3),var(--fg1));animation:fghflow 30s linear infinite alternate;will-change:transform}
@keyframes fghflow{to{transform:translateX(-66.6667%)}}
@media(prefers-reduced-motion:reduce){.fgh-bg-flow::before{animation:none}}
.fgh .wrap{display:grid;justify-items:center}
/* The site's own width, like every other section (.wrap); on a computer the
   owner's percentage of it (Homepage section -> Width), centred. */
.fgh-in{width:100%;display:grid;gap:16px}
.fgh .hs-head{margin-bottom:0}
/* The heading runs the card's full width, so the button sits at its right
   edge (owner, 24 October: "need button to the most right"); the heading
   itself stays centred on the section. And the pill 10% longer: 148 -> 163px,
   by its leading padding, so the arrow circle keeps its size. */
.fgh .hs-head.hs-split{width:100%;max-width:none;margin-inline:0;box-sizing:border-box}
.fgh .hs-btn{padding-inline-start:35px}
.fgh .hs-ht h2 svg{width:.9em;height:.9em;vertical-align:-.08em;margin-inline-end:.3em;color:#2e8f62}
@media(min-width:901px){.fgh-in{max-width:var(--fgw,100%)}}
.fgh-card{background:#fff;border:1.5px solid #2e8f62;border-radius:18px;overflow:hidden}
.fgh .fgh-r{all:unset;box-sizing:border-box;width:100%;cursor:pointer;display:grid;grid-template-columns:140px minmax(0,320px) auto minmax(0,1fr) 14px;gap:16px;align-items:center;padding:14px 18px;color:#2a2228}
.fgh .fgh-r+.fgh-r{border-top:1px solid #ece4e8}
.fgh .fgh-r:hover{background:#f4faf6}
.fgh .fgh-r:focus-visible{outline:2px solid #2e8f62;outline-offset:-2px}
.fgh-a{font-weight:800;font-size:15.5px;color:#2e8f62;font-variant-numeric:tabular-nums;white-space:nowrap}
.fgh-w{min-width:0;font-weight:600;font-size:15px;line-height:1.35}
.fgh-ft{justify-self:start;display:inline-flex;align-items:center;white-space:nowrap;font-weight:800;line-height:1;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#2e8f62;background:#eaf7f0;border:1.5px dashed #2e8f62;border-radius:8px;padding:6px 14px;-webkit-mask:radial-gradient(circle 5px at 0 50%,#0000 98%,#000) left/51% 100% no-repeat,radial-gradient(circle 5px at 100% 50%,#0000 98%,#000) right/51% 100% no-repeat;mask:radial-gradient(circle 5px at 0 50%,#0000 98%,#000) left/51% 100% no-repeat,radial-gradient(circle 5px at 100% 50%,#0000 98%,#000) right/51% 100% no-repeat}
[dir=rtl] .fgh-ft{letter-spacing:0;font-size:13px}
.fgh-p{justify-self:end;display:flex;gap:6px;align-items:center;flex-wrap:nowrap}
.fgh-t{position:relative;display:block;width:46px;height:46px;border-radius:10px;background:#fff;border:1px solid rgba(42,34,40,.1);box-sizing:border-box;flex:none}
.fgh-t img{display:block;width:100%;height:100%;object-fit:contain;border-radius:10px}
.fgh-t b{position:absolute;top:-7px;inset-inline-start:-7px;background:#2e8f62;color:#fff;font-size:10px;font-weight:800;border-radius:999px;min-width:19px;height:19px;padding:0 4px;box-sizing:border-box;display:grid;place-items:center;line-height:1}
.fgh-ph{display:grid;place-items:center;width:100%;height:100%;font-size:11px;font-weight:800;color:#8a7d86;background:#faf5f7;border-radius:10px}
.fgh-mo{font-size:12.5px;font-weight:700;color:#756c74;white-space:nowrap}
.fgh-mo.m,.fgh-mo.t{display:none}
.fgh-c{width:9px;height:9px;border-top:2px solid #8a7d86;border-inline-end:2px solid #8a7d86;transform:rotate(45deg);justify-self:center}
[dir=rtl] .fgh-c{transform:rotate(-45deg)}
/* A tablet or a small laptop (761-1199px): the wording column gives way
   instead of the photos running over it -- up to four photos, "+N" past
   four, so every row keeps to its own lane at the site's full width. */
@media(min-width:761px) and (max-width:1199px){
.fgh .fgh-r{grid-template-columns:120px minmax(0,1fr) auto auto 14px;gap:14px}
.fgh-t{width:40px;height:40px}
.fgh-p .fgh-t:nth-child(n+5){display:none}
.fgh-mo.d{display:none}.fgh-mo.t{display:inline}
}
@media(max-width:760px){
/* A phone keeps the section short (owner, 24 October: "fixed height around
   500-550 px max"), as the photo strip he picked (option 27): one line per
   range -- the amount, up to five small round gift photos and "+N" past five
   ("max upto 5 and put + sign if there's more"), the coupon and an arrow. The
   wording and every gift's name are in the popup. The card never scrolls on
   its own (owner, 24 October: "i want that user can scroll the page in any
   case, unless they click on it to open the details popup") -- an inner
   scroller held the swipe and the page stood still. */
.fgh-in{gap:12px}
.fgh-card{border-radius:16px}
.fgh .fgh-r{grid-template-columns:minmax(0,1fr) auto auto 8px;gap:8px;padding:10px 10px 8px 14px;min-height:64px}
.fgh-a{grid-column:1;grid-row:1;font-size:14px;white-space:normal;line-height:1.2}
.fgh-p{grid-column:2;grid-row:1}.fgh-ft{grid-column:3;grid-row:1}.fgh-c{grid-column:4;grid-row:1}
.fgh-w{display:none}
.fgh-p{justify-self:end;gap:0;align-items:center}
.fgh-t{width:36px;height:36px;border-radius:50%;margin-inline-start:-9px;border:2px solid #fff;box-shadow:0 1px 4px rgba(42,34,40,.14);background:#fff}
.fgh-t:first-child{margin-inline-start:0}
.fgh-t img,.fgh-t .fgh-ph{border-radius:50%;padding:2px;box-sizing:border-box}
.fgh-t .fgh-ph{font-size:8px}
/* "if any gift we give 2x, then there should mention beautifully in a circle
   2x, 3x etc on each product image" (owner, 24 October): a small green circle
   on the photo's top leading corner -- the side the next photo never covers,
   since each photo overlaps the one before it on the other side. */
.fgh-t b{display:grid;top:-5px;inset-inline-start:-5px;inset-inline-end:auto;min-width:19px;height:19px;padding:0 4px;font-size:10px;border:1.5px solid #fff;z-index:1;box-shadow:0 1px 3px rgba(42,34,40,.2)}
.fgh-p .fgh-t:nth-child(n+6){display:none}
.fgh-mo{font-size:12px;margin-inline-start:4px}
.fgh-mo.d,.fgh-mo.t{display:none}.fgh-mo.m{display:inline}
.fgh-ft{font-size:10px;padding:5px 9px;letter-spacing:.06em}
.fgh-c{width:7px;height:7px}
}
dialog.fgh-pop{border:0;padding:0;background:transparent;max-width:none;max-height:none;width:100%;height:100%;margin:0;color:#2a2228;overflow:hidden}
dialog.fgh-pop::backdrop{background:rgba(32,22,28,.38);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.fgh-box{position:absolute;left:50%;top:50%;width:min(600px,calc(100% - 32px));height:min(620px,calc(100% - 48px));transform:translate(-50%,-50%);background:#fff;border-radius:26px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 40px 80px -24px rgba(40,10,25,.45);animation:fghin .22s ease-out}
@keyframes fghin{from{opacity:0;transform:translate(-50%,-46%)}}
@keyframes fghup{from{transform:translateY(40px);opacity:.4}}
.fgh-grab{display:none;width:40px;height:5px;border-radius:5px;background:#ece4e8;margin:8px auto 0;flex:none}
.fgh-bar{display:flex;align-items:center;gap:8px;padding:12px 14px 6px 20px;flex:none}
.fgh-ct{flex:1;font-size:12.5px;font-weight:700;color:#756c74;font-variant-numeric:tabular-nums}
.fgh-nav,.fgh-x{all:unset;box-sizing:border-box;cursor:pointer;width:36px;height:36px;border-radius:50%;display:grid;place-items:center;color:#2a2228}
.fgh-nav{border:1px solid #ece4e8;background:#fff}
.fgh-nav:disabled{opacity:.3;cursor:default}
.fgh-x{background:#f6f1f3;font-size:20px;line-height:1}
.fgh-nav:focus-visible,.fgh-x:focus-visible{outline:2px solid #2e8f62;outline-offset:2px}
[dir=rtl] .fgh-nav svg{transform:scaleX(-1)}
.fgh-tr{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;flex:1;min-height:0;overscroll-behavior-x:contain}
.fgh-tr::-webkit-scrollbar{display:none}
.fgh-s{flex:0 0 100%;scroll-snap-align:start;scroll-snap-stop:always;overflow:hidden;padding:6px 20px 16px;display:flex;flex-direction:column;gap:12px;min-height:0;box-sizing:border-box}
.fgh-top{display:grid;gap:6px;padding:16px;border-radius:18px;background:linear-gradient(135deg,#fde8ef,#fff7fa)}
.fgh-r1{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.fgh-am{background:#2a2228;color:#fff;border-radius:999px;padding:6px 13px;font-size:14px;font-weight:800;font-variant-numeric:tabular-nums}
.fgh-cn{font-size:12.5px;font-weight:800;color:#2e8f62;background:#fff;border-radius:999px;padding:5px 11px}
.fgh-top h3{margin:0;display:flex;align-items:center;gap:10px;font-size:20px;line-height:1.25;letter-spacing:-.01em}
.fgh-gi{flex:none;width:36px;height:36px;display:grid;place-items:center;border-radius:12px;background:linear-gradient(140deg,#ff8fb1,#e8457c);box-shadow:0 8px 16px -8px rgba(232,69,124,.8),inset 0 1px 0 rgba(255,255,255,.45)}
.fgh-gi svg{display:block}
.fgh-top p{margin:0;font-size:13px;color:#756c74}
.fgh-gl{flex:1 1 0;min-height:0;max-height:calc(var(--r) * 290px);display:grid;grid-template-columns:repeat(var(--c),minmax(0,190px));grid-template-rows:repeat(var(--r),minmax(0,1fr));justify-content:center;gap:10px}
.fgh-g{position:relative;min-width:0;min-height:0;overflow:hidden;display:grid;grid-template-rows:minmax(0,1fr) auto auto;gap:3px;background:#fff;border:1px solid #ece4e8;border-radius:16px;padding:8px 8px 10px;text-align:center}
.fgh-gp{position:relative;min-height:0;border-radius:12px;background:#f6f1f3}
.fgh-gp img,.fgh-gp .fgh-ph{position:absolute;inset:7%;width:86%;height:86%;object-fit:contain}
.fgh-fr{position:absolute;top:6px;inset-inline-start:6px;background:#2e8f62;color:#fff;font-size:10px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:3px 8px;z-index:1}
.fgh-q{position:absolute;top:6px;inset-inline-end:6px;background:#2a2228;color:#fff;font-size:11px;font-weight:800;border-radius:999px;padding:2px 7px;z-index:1}
.fgh-g small{margin-top:3px;font-size:10.5px;color:#756c74;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fgh-g .fgh-nm{font-size:13px;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.fgh-gl.dd small{display:none}.fgh-gl.dd .fgh-nm{-webkit-line-clamp:1;font-size:11.5px}.fgh-gl.dd .fgh-fr{font-size:8.5px;padding:2px 6px}
.fgh-dots{display:flex;justify-content:center;gap:6px;padding:10px 0 14px;border-top:1px solid #ece4e8;flex:none}
.fgh-dots i{width:7px;height:7px;border-radius:50%;background:#ece4e8;transition:width .2s}
.fgh-dots i.on{background:#2e8f62;width:18px;border-radius:9px}
@media(max-width:640px){
.fgh-box{left:0;right:0;top:auto;bottom:0;width:100%;height:68%;max-height:600px;transform:none;border-radius:26px 26px 0 0;padding-bottom:env(safe-area-inset-bottom,0px);animation:fghup .24s ease-out}
.fgh-grab{display:block}
.fgh-top{padding:10px 12px;gap:3px;border-radius:14px}.fgh-top h3{font-size:16px;gap:8px}.fgh-am{font-size:13px;padding:5px 11px}.fgh-cn{font-size:12px;padding:4px 10px}.fgh-gi{width:30px;height:30px;border-radius:10px}.fgh-gi svg{width:18px;height:18px}.fgh-top p{font-size:11.5px;line-height:1.35}.fgh-bar{padding:8px 10px 4px 16px}.fgh-nav,.fgh-x{width:32px;height:32px}.fgh-dots{padding:8px 0 10px}
.fgh-s{padding:4px 14px 12px;gap:10px}
.fgh-gl{grid-template-columns:repeat(var(--cm),minmax(0,190px));grid-template-rows:repeat(var(--rm),minmax(0,1fr));max-height:calc(var(--rm) * 290px);gap:8px}
.fgh-gl.dm small{display:none}.fgh-gl.dm .fgh-nm{-webkit-line-clamp:1;font-size:11px}.fgh-gl.dm .fgh-g{padding:6px 6px 8px;border-radius:13px}.fgh-gl.dm .fgh-fr{font-size:8.5px;padding:2px 6px;top:4px;inset-inline-start:4px}.fgh-gl.dm .fgh-q{font-size:10px;top:4px;inset-inline-end:4px}
}
@media(prefers-reduced-motion:reduce){.fgh-box{animation:none}.fgh-dots i{transition:none}}
</style><div class="wrap"><div class="fgh-in">
  {{-- The heading and its button as every other homepage section draws them
       (partials/home/hs-head): the shop's pill (.bndl-all) beside the centred
       heading on a computer, under the section on a tablet or phone
       (kbb.css hides one of the two). The gift icon stays in the heading. --}}
  @php $fghBtn = $fgh['btn'] !== '' && ($fgh['url'] ?? '') !== ''; @endphp
  <div class="hs-head{{ $fghBtn ? ' hs-split hs-split-c' : '' }}"><div class="hs-ht"><h2 id="fgh-h">{!! $fghGift !!}{{ $fgh['title'] }}</h2>@if ($fgh['sub'] !== '')<p>{{ $fgh['sub'] }}</p>@endif</div>@if ($fghBtn)<a class="bndl-all hs-btn hs-btn-top" href="{{ $fgh['url'] }}">{{ $fgh['btn'] }}<i>{!! \App\Support\HomeSections::ARROW !!}</i></a>@endif</div>
  <div class="fgh-card">
@foreach ($fgh['rows'] as $i => $r)
@php $fghMore = count($r['gifts']); @endphp
    <button type="button" class="fgh-r" data-fgo="{{ $i }}" aria-haspopup="dialog" aria-label="{{ $r['amount'] }}: {{ $r['words'] }} · {{ $fghT['view'] }}"><span class="fgh-a">{{ $r['amount'] }}</span><span class="fgh-w">{{ $r['words'] }}</span><span class="fgh-ft">{{ $fghT['free'] }}</span><span class="fgh-p">@foreach (array_slice($r['gifts'], 0, 6) as $g)<span class="fgh-t">{!! $fghImg($g, 200, '46px') !!}@if ((int) $g['quantity'] > 1)<b>×{{ (int) $g['quantity'] }}</b>@endif</span>@endforeach @if ($fghMore > 6)<span class="fgh-mo d">+{{ $fghMore - 6 }}</span>@endif @if ($fghMore > 4)<span class="fgh-mo t">+{{ $fghMore - 4 }}</span>@endif @if ($fghMore > 5)<span class="fgh-mo m">+{{ $fghMore - 5 }}</span>@endif</span><span class="fgh-c" aria-hidden="true"></span></button>
@endforeach
  </div>
  @if ($fghBtn)<div class="hs-foot"><a class="bndl-all hs-btn hs-btn-bottom" href="{{ $fgh['url'] }}">{{ $fgh['btn'] }}<i>{!! \App\Support\HomeSections::ARROW !!}</i></a></div>@endif
</div></div>
<dialog class="fgh-pop" aria-labelledby="fgh-pt" data-of="{{ $fghT['of'] }}"><div class="fgh-box"><span class="fgh-grab" aria-hidden="true"></span>
  <div class="fgh-bar"><span class="fgh-ct" aria-live="polite"></span><button type="button" class="fgh-nav" data-fn="-1" aria-label="{{ $fghT['prev'] }}">{!! $fghArr(false) !!}</button><button type="button" class="fgh-nav" data-fn="1" aria-label="{{ $fghT['next'] }}">{!! $fghArr(true) !!}</button><button type="button" class="fgh-x" data-fx aria-label="{{ $fghT['close'] }}">×</button></div>
  <div class="fgh-tr">
@foreach ($fgh['rows'] as $i => $r)
@php $n = count($r['gifts']); @endphp
    <div class="fgh-s" data-i="{{ $i }}" role="group" aria-roledescription="slide" aria-label="{{ str_replace([':i', ':n'], [$i + 1, $fghN], $fghT['of']) }}"><div class="fgh-top"><div class="fgh-r1"><span class="fgh-am">{{ $r['amount'] }}</span><span class="fgh-cn">{{ $r['count'] }}</span></div><h3 @if ($i === 0) id="fgh-pt" @endif><span class="fgh-gi">{!! $fghGift2 !!}</span>{{ $r['words'] }}</h3><p>{{ $fghT['hint'] }}</p></div>
      <div class="fgh-gl{{ $n > 8 ? ' dd' : '' }}{{ $n > 6 ? ' dm' : '' }}" style="{{ $fghLay($n) }}">@foreach ($r['gifts'] as $g)<div class="fgh-g"><span class="fgh-gp">{!! $fghImg($g, 400, '190px') !!}<span class="fgh-fr">{{ $fghT['free'] }}</span>@if ((int) $g['quantity'] > 1)<span class="fgh-q">×{{ (int) $g['quantity'] }}</span>@endif</span>@if ($g['brand'] !== '')<small>{{ $g['brand'] }}</small>@endif<span class="fgh-nm">{{ $g['name'] }}</span></div>@endforeach</div></div>
@endforeach
  </div>
  <div class="fgh-dots" aria-hidden="true">@for ($i = 0; $i < $fghN; $i++)<i></i>@endfor</div>
</div></dialog>
<script>
(function () {
  var s = document.currentScript.closest('[data-fgh]'), d = s && s.querySelector('dialog');
  if (!d || !d.showModal) return;
  var tr = d.querySelector('.fgh-tr'), sl = [].slice.call(tr.children), dots = d.querySelectorAll('.fgh-dots i'),
      ct = d.querySelector('.fgh-ct'), pv = d.querySelector('[data-fn="-1"]'), nx = d.querySelector('[data-fn="1"]'), cur = 0;
  function mark() {
    ct.textContent = d.dataset.of.replace(':i', cur + 1).replace(':n', sl.length);
    for (var j = 0; j < dots.length; j++) dots[j].classList.toggle('on', j === cur);
    pv.disabled = cur === 0; nx.disabled = cur === sl.length - 1;
  }
  function show(i, smooth) { cur = i; mark(); sl[i].scrollIntoView({behavior: smooth ? 'smooth' : 'auto', block: 'nearest', inline: 'start'}); }
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { cur = +e.target.dataset.i; mark(); } }); }, {root: tr, threshold: 0.6});
    sl.forEach(function (x) { io.observe(x); });
  }
  s.addEventListener('click', function (e) { var b = e.target.closest('[data-fgo]'); if (b) { d.showModal(); show(+b.dataset.fgo, false); } });
  d.addEventListener('click', function (e) {
    if (e.target === d || e.target.closest('[data-fx]')) { d.close(); return; }
    var n = e.target.closest('[data-fn]'); if (n) show(Math.max(0, Math.min(sl.length - 1, cur + +n.dataset.fn)), true);
  });
  d.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    var f = (e.key === 'ArrowRight') !== (document.documentElement.dir === 'rtl') ? 1 : -1;
    show(Math.max(0, Math.min(sl.length - 1, cur + f)), true);
  });
})();
</script></section>
