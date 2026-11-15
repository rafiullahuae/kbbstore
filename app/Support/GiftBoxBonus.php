<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\GiftOffers\GiftOffers;

/**
 * THE BONUS GIFT, DRAWN INSIDE THE FREE GIFTS BOX (Lane GBX2).
 *
 * The owner: 10 Nov 2026 "on the cart panel, cart and checkout, the additional
 * free gift product should inside the gifts box ... i don't want additional
 * separate box for it as we have currently"; 11 Nov "i wanted the additional
 * gift product at the last"; 15 Nov "in place of word bonus, i need the timer,
 * and remove the top bar timer row also" and "if time end, it will removed
 * completely from the box silently without anything showing behind like Ended
 * etc."
 *
 * The five approved designs, A-E, exactly as docs/plans/gift-box-merge/
 * designs.cjs draws them (CSS and markup), turned into the pieces
 * partials/free-gifts/box.blade.php prints:
 *
 *   tile   the LAST photo of the row (A, C, E), its live clock where the BONUS
 *          tag was;
 *   after  what follows the photo row: the name line (A, C, E) or the bonus
 *          card at the foot of the box (B, D, whose first chip is the clock);
 *   css    the chosen design's rules only, plus the shared ones;
 *   script the one clock, inline, once per page (window.kbbGbmT).
 *
 * Nothing sits above the gifts row. Every name and text is escaped here; the
 * design is one of GiftOffers::BOX_DESIGNS (normalise()), and the numbers are
 * integers, so nothing from a setting reaches CSS or a script.
 *
 * THE CLOCK: one 1-second interval for every box on the page, which stops when
 * no bonus is left. It sets text and transforms only -- nothing is measured.
 * Red under 2 minutes (.low). At zero the bonus gift, its clock and its name
 * line fade (opacity/transform, 0.4 s) and are removed: no "ended" text, no
 * gap. A box that held ONLY the bonus goes with it, leaving the same empty
 * marker the server draws for a basket with no gifts (data-fgs="0"), so the
 * live check (gift-live.js) still has its place. The server never draws an
 * expired claim (GiftOffers::liveClaim()) and Place order leaves it out
 * (forPlace()).
 */
final class GiftBoxBonus
{
    private const WATCH = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="13" r="8"/><path d="M10 2h4M12 2v3"/><path class="gbm-hd" d="M12 13V9"/></svg>';

    private const BASE_CSS = '.gbm [data-gbm-bonus]{transition:opacity .4s ease,transform .4s ease}.gbm .gbm-out,.gbm.gbm-out{opacity:0;transform:scale(.88)}.gbm.gbm-solo{transition:opacity .4s ease,transform .4s ease}.gbm.gbm-solo.gbm-out{transform:scale(.97)}.gbm .gbm-hd{transform-origin:12px 13px;animation:gbmhd 4s steps(8) infinite}@keyframes gbmhd{to{transform:rotate(360deg)}}'
        . '.gbm-clk{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-variant-numeric:tabular-nums;font-weight:800;letter-spacing:.03em;direction:ltr;unicode-bidi:isolate}'
        . '.gbm-bl{margin-top:4px;color:#a12d4f;font-size:11px;font-weight:700;line-height:1.3}'
        . '@media (prefers-reduced-motion:reduce){.gbm .gbm-hd,.gbm *,.gbm{animation:none!important;transition:none!important}}';

    private const CSS = [
        'A' => '.gbA-t{margin-bottom:12px;box-shadow:0 0 0 2px #fff,0 0 0 3.5px #c6395f}.gbA-t b{background:#c6395f!important}'
            . '.gbA-tag{position:absolute;left:50%;bottom:-14px;transform:translateX(-50%);z-index:1;overflow:hidden;padding:1px 5px 2px;border-radius:5px;background:#c6395f;color:#fff;font-size:10.5px;font-style:normal;line-height:1.25;box-shadow:0 0 0 1.5px #fff;white-space:nowrap}.gbA-tag .gbm-clk{font-weight:800;letter-spacing:.02em}.gbA-ln{position:absolute;left:0;right:0;bottom:0;height:2px;background:rgba(255,255,255,.75);transform-origin:0 50%;transition:transform 1s linear}[dir=rtl] .gbA-ln{transform-origin:100% 50%}'
            . '.gbA-cap{margin-top:7px;font-size:11.5px;line-height:1.3;color:#2a2228;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbA-cap b{color:#a12d4f}'
            . '.gbA.low .gbA-t{box-shadow:0 0 0 2px #fff,0 0 0 3.5px #d1213f}.gbA.low .gbA-tag,.gbA.low .gbA-t b{background:#d1213f!important}',
        'B' => '.gbB-card{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center;margin-top:8px;padding:7px 9px;border-radius:10px;background:#fffaf2;border:1px solid #f1e4cc}.gbB-card .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbB-card .fgb-t b{background:#8a6a2c}.gbB-nm{min-width:0;display:grid;gap:4px}.gbB-nm b{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbB-ch{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.gbB-ch i{font-style:normal;font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap;line-height:1.5}.gbB-ch i.k{display:inline-flex;align-items:center;gap:3px;padding:1px 7px 1px 5px;background:#2a2228;color:#fff;font-size:11px}.gbB-ch i.k svg{width:12px;height:12px}'
            . '.gbB.low .gbB-ch i.k{background:#d1213f}.gbB.low .gbB-card{border-color:#f3c3cb;background:#fff6f7}',
        'C' => '.gbC .fgb-th{align-items:center}.gbC-t{margin:5px 7px 14px 5px}.gbC-r{position:absolute;inset:-5px;border-radius:50%;pointer-events:none}.gbC-r::before{content:"";position:absolute;inset:0;border-radius:50%;border:2.5px solid #f6dbe2}.gbC-h{position:absolute;top:0;width:50%;height:100%;overflow:hidden}.gbC-hr{right:0}.gbC-hl{left:0}.gbC-h i{position:absolute;top:0;width:200%;height:100%;box-sizing:border-box;border-radius:50%;border:2.5px solid transparent;transition:transform 1s linear}.gbC-hr i{right:0;border-top-color:#c6395f;border-right-color:#c6395f}.gbC-hl i{left:0;border-bottom-color:#c6395f;border-left-color:#c6395f}.gbC-t.fgb-t,.gbC-t.fgb-t img{border-radius:50%!important}.gbC-t b{background:#c6395f!important;z-index:1}'
            . '.gbC-k{position:absolute;left:50%;bottom:-14px;transform:translateX(-50%);z-index:2;padding:0 5px;border-radius:999px;background:#fff;color:#c6395f;border:1.5px solid #c6395f;font-size:10.5px;font-style:normal;line-height:1.35;white-space:nowrap}.gbC-k .gbm-clk{font-weight:800}'
            . '.gbC-cap{margin-top:5px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#2a2228}.gbC-cap b{color:#c6395f;font-weight:800}'
            . '.gbC.low .gbC-k{background:#d1213f;border-color:#d1213f;color:#fff}.gbC.low .gbC-hr i{border-top-color:#d1213f;border-right-color:#d1213f}.gbC.low .gbC-hl i{border-bottom-color:#d1213f;border-left-color:#d1213f}',
        'D' => '.gbD-f{margin:8px -11px -2px;padding:8px 11px 4px;background:#fffaf2;border-top:1px dashed #2e8f62}'
            . '.gbD-g{display:grid;grid-template-columns:42px minmax(0,1fr);gap:9px;align-items:center}.gbD-g .fgb-t{width:42px!important;height:42px!important;border-color:#f1e4cc}.gbD-g .fgb-t b{background:#8a6a2c}.gbD-g b.n{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:12.5px;font-weight:800;line-height:1.25;max-height:2.5em}.gbD-ch{display:flex;gap:4px;margin-top:4px;flex-wrap:wrap;align-items:center}.gbD-ch i{font-style:normal;font-size:10px;font-weight:800;border-radius:6px;padding:1px 6px;background:#fbf1de;color:#8a6a2c;white-space:nowrap;line-height:1.5}.gbD-ch i.k{display:inline-flex;align-items:center;gap:3px;padding:1px 7px 1px 5px;border-radius:8px;background:#2a2228;color:#fff;font-size:11px}.gbD-ch i.k svg{width:12px;height:12px}'
            . '.gbD.low .gbD-ch i.k{background:#d1213f}.gbD.low .gbD-f{background:#fff4f5}',
        'E' => '.gbE-t{margin-bottom:13px;border:2px solid #c9a36a!important}.gbE-t b{background:#b8862a!important}'
            . '.gbE-tag{position:absolute;left:50%;bottom:-15px;transform:translateX(-50%);z-index:1;display:inline-flex;align-items:center;gap:1px;padding:2px 3px;border-radius:5px;background:#c9a36a;box-shadow:0 0 0 1.5px #fff;direction:ltr;white-space:nowrap}.gbE-tag i{display:inline-grid;place-items:center;width:9px;height:13px;border-radius:2px;background:#2a2228;color:#fff;font:800 10px/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-style:normal;box-shadow:inset 0 -6.5px 0 rgba(255,255,255,.08)}.gbE-tag i.in{animation:gbEin .35s ease-out}.gbE-tag i.in2{animation:gbEin2 .35s ease-out}@keyframes gbEin{from{transform:translateY(-35%);opacity:0}}@keyframes gbEin2{from{transform:translateY(-35%);opacity:0}}.gbE-tag u{text-decoration:none;font:800 10px/1 ui-monospace,monospace;color:#2a2228;padding:0 .5px}'
            . '.gbE-cap{margin-top:6px;font-size:11.5px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gbE-cap b{color:#8a6a2c}'
            . '.gbE.low .gbE-tag i{background:#d1213f}.gbE.low .gbE-tag{background:#f3c3cb}',
    ];

    /**
     * The one clock: text and transforms only, stops when no bonus is left.
     * A box that held only the bonus (.gbm-solo) is replaced by the empty
     * marker the server draws for no gifts, or simply removed.
     */
    public const SCRIPT = "<script>(function(w,d){if(w.kbbGbmT)return;w.kbbGbmT=1;function f(s){var h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;return(h?h+':':'')+(m<10?'0':'')+m+':'+(x<10?'0':'')+x}function end(b){b.removeAttribute('data-gbm-left');b.classList.remove('low');var solo=b.classList.contains('gbm-solo'),g=solo?[b]:[].slice.call(b.querySelectorAll('[data-gbm-bonus]'));g.forEach(function(e){e.classList.add('gbm-out')});setTimeout(function(){if(!solo){g.forEach(function(e){e.remove()});return}if(b.hasAttribute('data-fgs')){var t=d.createElement('template');t.setAttribute('data-fgs','0');if(b.classList.contains('fgb-page'))t.setAttribute('data-fgp','');b.replaceWith(t)}else b.remove()},450)}var iv=setInterval(function(){var bs=d.querySelectorAll('[data-gbm-left]'),live=0;for(var i=0;i<bs.length;i++){var b=bs[i];if(!b.gbmEnd)b.gbmEnd=Date.now()+(+b.getAttribute('data-gbm-left'))*1000;var s=Math.max(0,Math.round((b.gbmEnd-Date.now())/1000)),T=+b.getAttribute('data-gbm-total')||1,P=s/T,t=f(s),c=b.querySelectorAll('[data-gbm-clk]'),l=b.querySelectorAll('[data-gbm-line]'),a=P*360,k;for(k=0;k<c.length;k++)c[k].textContent=t;for(k=0;k<l.length;k++)l[k].style.transform='scaleX('+P.toFixed(3)+')';var r=b.querySelector('[data-gbm-ring=r]'),q=b.querySelector('[data-gbm-ring=l]');if(r)r.style.transform='rotate('+(Math.min(a,180)-135)+'deg)';if(q)q.style.transform='rotate('+(Math.max(a-180,0)-135)+'deg)';var fl=b.querySelector('[data-gbm-flip]');if(fl){var ds=fl.querySelectorAll('i'),ch=t.replace(/:/g,'');if(ds.length===ch.length)for(k=0;k<ds.length;k++)if(ds[k].textContent!==ch[k]){ds[k].textContent=ch[k];ds[k].className=ds[k].className==='in'?'in2':'in'}}b.classList.toggle('low',s>0&&s<=120);if(s<=0)end(b);else live++}if(!live){clearInterval(iv);w.kbbGbmT=0}},1000)})(window,document)</script>";

    /** mm:ss, or h:mm:ss from an hour up (the row's own format). */
    public static function clock(int $s): string
    {
        $s = max(0, $s);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }

    /** The design actually drawn: one of BOX_DESIGNS, or the default. */
    public static function design(mixed $d): string
    {
        return in_array($d, GiftOffers::BOX_DESIGNS, true) ? $d : GiftOffers::BOX_DESIGN;
    }

    /**
     * @param array $b GiftOffers::intoBox()'s kbbFgBonus
     * @return array{design:string,css:string,tile:string,after:string,low:bool,left:int,total:int}
     */
    public static function parts(array $b): array
    {
        $design = self::design($b['design'] ?? null);
        $left = max(0, (int) ($b['left'] ?? 0));
        $total = max(1, (int) ($b['total'] ?? 1));
        $p = max(0.0, min(1.0, $left / $total));
        $qty = max(1, (int) ($b['qty'] ?? 1));
        $name = e((string) ($b['name'] ?? ''));
        $held = e((string) ($b['held'] ?? ''));
        $clk = '<span class="gbm-clk" data-gbm-clk>' . self::clock($left) . '</span>';
        $img = (string) ($b['img'] ?? '');

        $tile = static function (string $cls, string $extra = '') use ($img, $name, $qty): string {
            $pic = $img !== ''
                ? '<img src="' . e($img) . '" alt="' . $name . '" width="42" height="42" loading="lazy" decoding="async">'
                : '<span class="fgb-ph" role="img" aria-label="' . $name . '">' . e(mb_strtoupper(mb_substr(html_entity_decode($name, ENT_QUOTES), 0, 2))) . '</span>';

            return '<span class="fgb-t gbm-t ' . $cls . '" data-gbm-bonus>' . $extra . $pic . ($qty > 1 ? '<b>×' . $qty . '</b>' : '') . '</span>';
        };
        $chips = '<i class="k" role="timer" aria-label="' . $held . '">' . self::WATCH . $clk . '</i><i>' . e((string) ($b['free'] ?? '')) . '</i><i>' . e((string) ($b['full'] ?? '')) . '</i><i>×' . $qty . '</i>';
        $times = $qty > 1 ? $qty . '× ' : '';
        $cap = static fn (string $cls, string $label, bool $size): string => '<div class="' . $cls . '" data-gbm-bonus><b>' . e($label) . '</b> ' . $times . $name . ($size ? ' · ' . e((string) ($b['size'] ?? '')) : '') . '</div>';

        [$t, $after] = match ($design) {
            'A' => [
                $tile('gbA-t', '<i class="gbA-tag" role="timer" aria-label="' . $held . '">' . $clk . '<i class="gbA-ln" data-gbm-line style="transform:scaleX(' . number_format($p, 3, '.', '') . ')"></i></i>'),
                $cap('gbA-cap', (string) ($b['bonus'] ?? ''), true),
            ],
            'B' => ['', '<div class="gbB-card" data-gbm-bonus>' . $tile('gbB-t') . '<span class="gbB-nm"><b>' . $name . '</b><span class="gbB-ch">' . $chips . '</span></span></div>'],
            'C' => (static function () use ($tile, $cap, $p, $held, $clk, $b): array {
                $a = $p * 360;
                $r = number_format(min($a, 180) - 135, 1, '.', '');
                $l = number_format(max($a - 180, 0) - 135, 1, '.', '');

                return [
                    $tile('gbC-t', '<span class="gbC-r" aria-hidden="true"><span class="gbC-h gbC-hr"><i data-gbm-ring="r" style="transform:rotate(' . $r . 'deg)"></i></span><span class="gbC-h gbC-hl"><i data-gbm-ring="l" style="transform:rotate(' . $l . 'deg)"></i></span></span><i class="gbC-k" role="timer" aria-label="' . $held . '">' . $clk . '</i>'),
                    $cap('gbC-cap', (string) ($b['plus'] ?? ''), false),
                ];
            })(),
            'D' => ['', '<div class="gbD-f" data-gbm-bonus><div class="gbD-g">' . $tile('gbD-t') . '<span style="min-width:0"><b class="n">' . $name . '</b><span class="gbD-ch">' . $chips . '</span></span></div></div>'],
            default => [
                $tile('gbE-t', '<span class="gbE-tag" role="timer" aria-label="' . $held . '" data-gbm-flip>' . implode('', array_map(static fn (string $c) => $c === ':' ? '<u>:</u>' : '<i>' . $c . '</i>', str_split(self::clock($left)))) . '</span>'),
                $cap('gbE-cap', (string) ($b['bonus'] ?? ''), true),
            ],
        };

        // Below the range's minimum: Place order would leave the gift out, so say so (the row did).
        $below = (string) ($b['below'] ?? '');
        if ($below !== '') {
            $after .= '<div class="gbm-bl" data-gbm-bonus>' . e($below) . '</div>';
        }

        return [
            'design' => $design,
            'css' => self::BASE_CSS . self::CSS[$design],
            'tile' => $t,
            'after' => $after,
            'low' => $left <= 120,
            'left' => $left,
            'total' => $total,
        ];
    }
}
