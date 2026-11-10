{{--
    THE EXIT POPUP'S MARKUP (Lane GO) -- designs A Ribbon card, B Bottom sheet,
    C Unwrap & reveal, D Luxe minimal (docs/plans/gift-offers/exit-popup-
    designs.html) with content styles S1-S5 (exit-popup-styles-S1-S5.html).
    The product name sits right under the photo in every design (decided).
    S1-S5 are the timer block and work in every design; S3 adds the chips
    under the name, S5 the gold dashed frame and the plum strip (its cream top
    is drawn on design A only, where the frame sits on the ribbon area).

    $p  GiftOffers::popupView(): plain strings, escaped here. The words' {p}
        becomes the product's (escaped) name in bold. The same markup is what
        Marketing -> Gift offers -> Live preview shows.
--}}
@php
    // Gives per order (Lane GQ): ×N in the chips (S3, and S5 when N > 1), and a
    // lone ×N chip under the name in the other styles when N > 1. At ×1 every
    // style prints exactly what it printed before.
    $goQty = (int) ($p['qty'] ?? 1);
    $goName = e($p['name']);
    $goText = str_replace('{p}', '<b>' . $goName . '</b>', e($p['p']));
    $goImg = $p['img'] !== '' ? '<img src="' . e($p['img']) . '"' . ($p['srcset'] !== '' ? ' srcset="' . e($p['srcset']) . '" sizes="120px"' : '') . ' alt="' . $goName . '" width="120" height="140" decoding="async">' : '';
    $goMm = str_pad((string) $p['minutes'], 2, '0', STR_PAD_LEFT);
    $goTimer = match ($p['style']) {
        'S1' => '<span class="kgo-pill">&#9201; ' . e($p['t']['held']) . ' <b class="kgo-clk" data-kgo-m>' . $goMm . ':00</b></span>',
        'S2' => '<div class="kgo-barl"><span>' . e($p['t']['held_for']) . '</span><b class="kgo-clk" data-kgo-m>' . $goMm . ':00</b></div><div class="kgo-bar"><i data-kgo-bar></i></div>',
        'S4' => '<div class="kgo-tiles" dir="ltr"><div><b data-kgo-mm>' . $goMm . '</b><small>' . e($p['t']['minutes']) . '</small></div><span class="kgo-colon">:</span><div><b data-kgo-ss>00</b><small>' . e($p['t']['seconds']) . '</small></div></div>',
        'S5' => '<div class="kgo-goldbar"><span>' . e($p['t']['gold']) . '</span><b class="kgo-clk" data-kgo-m>' . $goMm . ':00</b></div>',
        default => '<div class="kgo-cream"><small>' . e($p['t']['clock']) . '</small><b class="kgo-clk" data-kgo-m>' . $goMm . ':00</b></div>',
    };
    $goChips = '<div class="kgo-chips"><span class="kgo-c1">' . e($p['t']['free']) . '</span><span class="kgo-c2">' . e($p['t']['chip_full']) . ($p['style'] === 'S5' ? ' · ' . e($p['worth']) : '') . '</span>' . ($p['style'] === 'S5' && $goQty < 2 ? '' : '<span class="kgo-c3">×' . $goQty . '</span>') . '</div>';
    $goWorth = '<span class="kgo-worth"><s>' . e($p['worth']) . '</s><em>' . e($p['t']['free']) . '</em> ' . e($p['t']['full']) . '</span>';
    $goUnder = '<div class="kgo-pname">' . $goName . '</div>' . (in_array($p['style'], ['S3', 'S5'], true) ? $goChips : ($goQty > 1 ? '<div class="kgo-chips"><span class="kgo-c3">×' . $goQty . '</span></div>' : ''));
    $goPhoto = $p['style'] === 'S5'
        ? '<div class="kgo-frame"><div class="kgo-ph">' . $goImg . '</div>' . ($p['brand'] !== '' ? '<div class="kgo-brand">' . e($p['brand']) . '</div>' : '') . $goUnder . '</div>'
        : (in_array($p['style'], ['S3'], true) ? '' : $goWorth) . '<div class="kgo-ph">' . $goImg . '</div>' . $goUnder;
    $goHead = '<p class="kgo-wait">' . e($p['wait']) . '</p><p class="kgo-sub">' . e($p['h']) . '</p>';
    $goFoot = '<button type="button" class="kgo-claim" data-kgo-claim>' . e($p['c']) . '</button><button type="button" class="kgo-later" data-kgo-later>' . e($p['l']) . '</button>';
    $goX = '<button type="button" class="kgo-x" data-kgo-later aria-label="' . e($p['t']['close']) . '">&times;</button>';
    $goBow = '<svg class="kgo-bow" viewBox="0 0 84 26" width="84" height="26" aria-hidden="true" focusable="false"><path d="M42 13C30 0 12 2 14 13s18 8 28 0zm0 0c12-13 30-11 28 0s-18 8-28 0z" fill="#c6395f"/><circle cx="42" cy="13" r="5" fill="#a12d4f"/></svg>';
    $goTick = '<div class="kgo-tick" data-kgo-tick hidden><svg viewBox="0 0 120 120" width="120" height="120" aria-hidden="true" focusable="false"><circle class="kgo-tb" cx="60" cy="60" r="48"/><circle class="kgo-tc" cx="60" cy="60" r="48"/><path class="kgo-tk" d="M38 62l15 15 30-32"/></svg><b role="status">' . e($p['t']['added']) . '</b><small>' . e($p['t']['opening']) . '</small></div>';
    $goAria = 'role="dialog" aria-modal="true" aria-label="' . e($p['h']) . '"';
@endphp
<div class="kgo-ov kgo-d{{ $p['design'] }}" data-kgo-ov>@if ($p['design'] === 'B')<div class="kgo-pp kgo-pB kgo-{{ $p['style'] }}" {!! $goAria !!}><div class="kgo-grab"></div>{!! $goX !!}<div class="kgo-rowp"><div><div class="kgo-ph">{!! $goImg !!}</div><div class="kgo-pname">{!! $goName !!}</div></div><div>{!! $goHead !!}{!! $goWorth !!}</div></div>@if (in_array($p['style'], ['S3', 'S5'], true)){!! $goChips !!}@endif<p class="kgo-t">{!! $goText !!}</p>{!! $goTimer !!}{!! $goFoot !!}{!! $goTick !!}</div>@elseif ($p['design'] === 'C')<div class="kgo-pp kgo-pC kgo-{{ $p['style'] }}" {!! $goAria !!}>{!! $goX !!}<div class="kgo-step" data-kgo-step>{!! $goHead !!}<p class="kgo-t">{{ $p['t']['box'] }}</p><button type="button" class="kgo-box" data-kgo-open aria-label="{{ $p['t']['tap'] }}"><svg class="kgo-knot" viewBox="0 0 66 30" width="66" height="30" aria-hidden="true" focusable="false"><path d="M33 15C23 2 7 4 9 15s15 7 24 0zm0 0c10-13 26-11 24 0s-15 7-24 0z" fill="#ffd36e"/></svg><span class="kgo-lid"></span><span class="kgo-bx"></span><span class="kgo-rib"></span></button><span class="kgo-tap">{{ $p['t']['tap'] }}</span></div><div class="kgo-reveal" data-kgo-reveal hidden>{!! $goPhoto !!}<p class="kgo-t">{!! $goText !!}</p>{!! $goTimer !!}{!! $goFoot !!}</div>{!! $goTick !!}</div>@elseif ($p['design'] === 'D')<div class="kgo-pp kgo-pD kgo-{{ $p['style'] }}" {!! $goAria !!}>{!! $goX !!}<div class="kgo-top"><span class="kgo-lab">{{ $p['t']['chip_full'] }} · {{ $p['worth'] }}</span>{!! $goPhoto !!}</div><div class="kgo-bd">{!! $goHead !!}<p class="kgo-t">{!! $goText !!}</p>{!! $goTimer !!}{!! $goFoot !!}</div>{!! $goTick !!}</div>@else<div class="kgo-pp kgo-pA kgo-{{ $p['style'] }}" {!! $goAria !!}>{!! $goX !!}<div class="kgo-top">{!! $goBow !!}{!! $goPhoto !!}</div><div class="kgo-bd">{!! $goHead !!}<p class="kgo-t">{!! $goText !!}</p>{!! $goTimer !!}{!! $goFoot !!}</div>{!! $goTick !!}</div>@endif</div>
