<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Super Sale banner's occasion templates.                       (Lane SOT)
 *
 * The owner, 6 November 2026: "the function will be just select and switch the
 * heading layout as per the occasion from the list ... 11.11, then UAE
 * National Day Sale, and general we have already super sale. keep that too",
 * then "new year sale design too", then "just like pre-templates chosen
 * option and to edit it if needed. don't disturb anything existing".
 *
 * Ten templates: Super Sale (the banner as it is, the default) and the nine
 * approved designs of docs/plans/sale-occasions (three each for 11.11, UAE
 * National Day and New Year). A template is ONE class on the banner
 * (`ssb-t-<key>`), its own stylesheet and its own default words; the banner's
 * markup, pictures, gifts and every control stay SuperSaleBanner's.
 *
 * ── WHAT A TEMPLATE CHANGES, AND WHAT STAYS HIS ─────────────────────────────
 *
 * Its look (background, art, glow) is fixed CSS. Its sizes and colours are
 * NOT: each is the var() FALLBACK of the same custom property the editor's
 * controls write (--ssb-title-cd ...), and SuperSaleBanner::styleDefaults()
 * reads the same numbers from STYLE, so his inline override still wins and an
 * untouched control still prints nothing. The big number ("11.11", "2027") is
 * a word he can edit and an element he can size, colour, move and hide, like
 * the title.
 *
 * ── LIGHT ───────────────────────────────────────────────────────────────────
 *
 * The page prints the CHOSEN template's rules only (css()), after the
 * banner's own; Super Sale prints nothing at all, so the default banner is
 * byte for byte what it was. The art is inline SVG in data: URIs (the
 * constants below, made by tools/sale-templates-art.py): no file, no request,
 * no font, no script. Nothing here is a setting: rule 5.
 */
final class SaleTemplates
{
    public const DEFAULT = 'super';

    /** key => [label, occasion, swatch background (a constant, the picker's chip)]. */
    public const TEMPLATES = [
        'super' => ['Super Sale (current)', 'Super Sale', 'linear-gradient(118deg,#fde8ef,#fff8fb 48%,#fdeee6)'],
        'm1' => ['11.11 Mega Sale · Midnight Neon', '11.11 Mega Sale', 'radial-gradient(60% 90% at 86% 20%,rgba(255,61,139,.55),transparent 60%),linear-gradient(120deg,#1a0a2e,#4a0d4f)'],
        'm2' => ['11.11 Mega Sale · Pink Burst', '11.11 Mega Sale', 'linear-gradient(115deg,#e8306f,#ff4f93 45%,#ff8a5c)'],
        'm3' => ['11.11 Mega Sale · Blush & Gold Ticket', '11.11 Mega Sale', 'linear-gradient(118deg,#fff1e6,#fff9f3 50%,#fde9ef)'],
        'u1' => ['UAE National Day · Skyline by Day', 'UAE National Day', 'linear-gradient(90deg,#E31B23 0 12%,transparent 12%),linear-gradient(180deg,#eef5f7 0 60%,#00732F 60% 74%,#fff 74% 87%,#000 87%)'],
        'u2' => ['UAE National Day · Golden Dusk Skyline', 'UAE National Day', 'linear-gradient(90deg,#E31B23 0 12%,transparent 12%),linear-gradient(180deg,#f7d9a8 0 60%,#00732F 60% 74%,#fff 74% 87%,#000 87%)'],
        'u3' => ['UAE National Day · Flag Sky', 'UAE National Day', 'linear-gradient(90deg,#E31B23 0 12%,transparent 12%),linear-gradient(180deg,#00732F 0 60%,#0a8a3c 60% 74%,#fff 74% 87%,#000 87%)'],
        'n1' => ['New Year · Midnight Gold', 'New Year', 'radial-gradient(55% 90% at 55% 30%,rgba(245,201,105,.35),transparent 65%),linear-gradient(120deg,#0b1026,#231a3d)'],
        'n2' => ['New Year · Champagne Blush', 'New Year', 'linear-gradient(118deg,#fde4ec,#fff6f1 50%,#fbe7d8)'],
        'n3' => ['New Year · Fireworks', 'New Year', 'linear-gradient(120deg,#1d0b3a,#3a1466 55%,#6a1f6e)'],
    ];

    /**
     * The words a template fills in when he picks it: [English, Arabic]. The
     * coupon, the gifts and the pay-later line stay his. An empty tagline is
     * not drawn (the owner, 6 November: no "ONE DAY ONLY" line on 11.11 A).
     */
    public const WORDS = [
        'super' => ['title' => ['SUPER SALE', 'تخفيضات كبرى'], 'tagline' => ['PERFECT SKINCARE TO GLOW', 'عناية مثالية لبشرة متوهّجة'], 'num' => ['', '']],
        'm1' => ['title' => ['MEGA SALE', 'تخفيضات ميجا'], 'tagline' => ['', ''], 'num' => ['11.11', '11.11']],
        'm2' => ['title' => ['MEGA SALE', 'تخفيضات ميجا'], 'tagline' => ['BIGGEST SALE OF THE YEAR', 'أكبر تخفيضات العام'], 'num' => ['11.11', '11.11']],
        'm3' => ['title' => ['MEGA SALE', 'تخفيضات ميجا'], 'tagline' => ['DOUBLE ELEVEN · 11 NOV', 'مهرجان ١١.١١ · ١١ نوفمبر'], 'num' => ['11.11', '11.11']],
        'u1' => ['title' => ['NATIONAL DAY SALE', 'تخفيضات اليوم الوطني'], 'tagline' => ['EID AL ETIHAD · 2 DECEMBER', 'عيد الاتحاد · ٢ ديسمبر'], 'num' => ['', '']],
        'u2' => ['title' => ['NATIONAL DAY SALE', 'تخفيضات اليوم الوطني'], 'tagline' => ['CELEBRATING 55 YEARS · EID AL ETIHAD', 'نحتفل بـ٥٥ عاماً · عيد الاتحاد'], 'num' => ['', '']],
        'u3' => ['title' => ['NATIONAL DAY SALE', 'تخفيضات اليوم الوطني'], 'tagline' => ['EID AL ETIHAD · 55 YEARS', 'عيد الاتحاد · ٥٥ عاماً'], 'num' => ['', '']],
        'n1' => ['title' => ['NEW YEAR SALE', 'تخفيضات العام الجديد'], 'tagline' => ['NEW YEAR, NEW GLOW', 'عام جديد، إشراقة جديدة'], 'num' => ['2027', '2027']],
        'n2' => ['title' => ['NEW YEAR SALE', 'تخفيضات العام الجديد'], 'tagline' => ['CHEERS TO GLOWING SKIN', 'احتفلي ببشرة متوهّجة'], 'num' => ['2027', '2027']],
        'n3' => ['title' => ['NEW YEAR SALE', 'تخفيضات العام الجديد'], 'tagline' => ['START THE YEAR GLOWING', 'ابدئي العام بإشراقة'], 'num' => ['2027', '2027']],
    ];

    /**
     * The big number as an element of the banner: SuperSaleBanner::ELEMENTS'
     * shape (label, selector, phone size, computer size, weight, colour,
     * display, shown on a phone, shown on a computer); the sizes and colour
     * are each template's (NUM).
     */
    public const NUM_ELEMENT = ['Big number', '.ssb-num', 11.0, 13.0, 900, '#FFFFFF', 'block', true, true];

    /** Per template with a number: [phone size u, computer size u, colour]. */
    private const NUM = [
        'm1' => [11.0, 13.0, '#FF4F9A'], 'm2' => [11.0, 13.0, '#FFFFFF'], 'm3' => [11.0, 13.0, '#C9952F'],
        'n1' => [11.0, 11.5, '#F5C969'], 'n2' => [11.0, 11.5, '#D9A441'], 'n3' => [11.0, 11.5, '#FFFFFF'],
    ];

    /** Each family's sizes, in u: the title per device, the tagline on a phone, the copy's line gap per device. */
    private const SIZES = [
        'm' => ['title' => [6.4, 5.0], 'tag' => 2.7, 'g' => [0.9, 0.55]],
        'u' => ['title' => [6.6, 4.2], 'tag' => 2.7, 'g' => [0.9, 0.55]],
        'n' => ['title' => [6.2, 4.4], 'tag' => 2.7, 'g' => [0.9, 0.55]],
    ];

    /**
     * Arabic's own sizes where its words need them, in u: "تخفيضات العام
     * الجديد" is a third wider than "NEW YEAR SALE" and ran under the picture
     * on a phone of 390px and narrower at the English size (the sweep).
     */
    private const SIZES_AR = ['n' => ['m' => ['title' => 5.5]]];

    /** Each template's text colours (both devices), as the approved designs drew them. */
    private const COLOURS = [
        'm1' => ['tag' => '#F8D7E6', 'pay' => '#FFFFFF', 'title' => '#FFD36E', 'clabel' => '#FFFFFF', 'cvalue' => '#D61F69'],
        'm2' => ['tag' => '#FFFFFF', 'pay' => '#FFFFFF', 'title' => '#FFFFFF', 'clabel' => '#FFFFFF', 'cvalue' => '#D61F69'],
        'm3' => ['tag' => '#8A6420', 'title' => '#E2336F', 'cvalue' => '#8A6420'],
        'u1' => ['tag' => '#E31B23', 'title' => '#00732F', 'clabel' => '#000000', 'cvalue' => '#E31B23'],
        'u2' => ['tag' => '#7A3B12', 'title' => '#00732F', 'clabel' => '#000000', 'cvalue' => '#E31B23'],
        'u3' => ['tag' => '#FFFFFF', 'pay' => '#FFFFFF', 'title' => '#FFFFFF', 'clabel' => '#FFFFFF', 'cvalue' => '#E31B23'],
        'n1' => ['tag' => '#F5D78E', 'pay' => '#FFFFFF', 'title' => '#FFFFFF', 'clabel' => '#FFFFFF', 'cvalue' => '#9A6B12'],
        'n2' => ['tag' => '#9A6B12', 'title' => '#E2336F', 'cvalue' => '#9A6B12'],
        'n3' => ['tag' => '#FFD1E6', 'pay' => '#FFFFFF', 'title' => '#F5C969', 'clabel' => '#FFFFFF', 'cvalue' => '#C3306A'],
    ];

    /** The hero's ground per template (mirrored for Arabic by mirror()). */
    private const BG = [
        'm1' => 'radial-gradient(60% 90% at 86% 20%,rgba(255,61,139,.55),transparent 60%),radial-gradient(50% 80% at 8% 110%,rgba(124,58,237,.55),transparent 60%),linear-gradient(120deg,#1a0a2e 0%,#2d0b45 55%,#4a0d4f 100%)',
        'm2' => 'repeating-conic-gradient(from 0deg at 82% 60%,rgba(255,255,255,.13) 0 6deg,transparent 6deg 18deg),radial-gradient(70% 90% at 82% 55%,#ffb38a,transparent 60%),linear-gradient(115deg,#e8306f 0%,#ff4f93 45%,#ff8a5c 100%)',
        'm3' => 'radial-gradient(90% 120% at 85% 10%,#fff,transparent 55%),linear-gradient(118deg,#fff1e6 0%,#fff9f3 50%,#fde9ef 100%)',
        'u1' => 'linear-gradient(180deg,#eef5f7 0%,#ffffff 70%)',
        'u2' => 'radial-gradient(40% 60% at 62% 70%,#fff3c4,transparent 70%),linear-gradient(180deg,#f7d9a8 0%,#f6c49a 55%,#f2b08f 100%)',
        'u3' => 'linear-gradient(180deg,#00732F 0%,#0a8a3c 100%)',
        'n1' => 'radial-gradient(55% 90% at 55% 30%,rgba(245,201,105,.22),transparent 65%),linear-gradient(120deg,#0b1026 0%,#141a3a 55%,#231a3d 100%)',
        'n2' => '{CONFETTI} 0 0/300px 200px,radial-gradient(80% 110% at 85% 10%,#fff,transparent 55%),linear-gradient(118deg,#fde4ec 0%,#fff6f1 50%,#fbe7d8 100%)',
        'n3' => 'linear-gradient(120deg,#1d0b3a 0%,#3a1466 55%,#6a1f6e 100%)',
    ];

    /** A plain ground printed first, for a browser that cannot read the one after it. */
    private const BG_PLAIN = ['m2' => 'linear-gradient(115deg,#e8306f 0%,#ff4f93 45%,#ff8a5c 100%)'];

    /** The dotted sky of 11.11 A and New Year A: [colour one, colour two, step px, opacity]. */
    private const DOTS = ['m1' => ['#FFD36E', '#ff6fae', 46, '.45', '1.5px', '2.5px'], 'n1' => ['#F5D78E', '#fff', 44, '.5', '1.4px', '2.4px']];

    /**
     * Each template's own rules: 'a' every device, 'm' the phone, 'd' the
     * computer. `&` is the banner (.kbb-ssb.ssb-t-<key>); {CITY} {LAYERS}
     * {FIREWORKS} are the art constants.
     */
    private const RULES = [
        'm1' => [
            'a' => [
                '& .ssb-copy{position:relative}',
                '& .ssb-super{text-shadow:0 0 calc(var(--u)*1.6) rgba(255,211,110,.55)}',
                '& .ssb-num{text-shadow:0 0 calc(var(--u)*.6) #ff4f9a,0 0 calc(var(--u)*2.4) rgba(255,79,154,.8),0 0 calc(var(--u)*5) rgba(255,79,154,.5)}',
                '& .ssb-model::before{content:"";position:absolute;left:8%;right:8%;bottom:0;height:78%;border-radius:50% 50% 0 0;background:radial-gradient(60% 60% at 50% 45%,rgba(255,79,154,.6),rgba(124,58,237,.25) 60%,transparent 72%)}',
                '& .ssb-model picture{position:relative}',
                '& .ssb-coupon b{border-color:#ff4f9a}',
                '& .ssb-gifts{background:#fff}',
            ],
        ],
        'm2' => [
            'a' => [
                '& .ssb-super{text-shadow:0 calc(var(--u)*.45) 0 #b3124f,0 calc(var(--u)*1.2) calc(var(--u)*2) rgba(120,0,40,.35)}',
                '& .ssb-num{text-shadow:calc(var(--u)*.5) calc(var(--u)*.5) 0 #b3124f,calc(var(--u)*1) calc(var(--u)*1) 0 rgba(120,0,40,.25)}',
                '&[dir=rtl] .ssb-num{text-shadow:calc(var(--u)*-.5) calc(var(--u)*.5) 0 #b3124f,calc(var(--u)*-1) calc(var(--u)*1) 0 rgba(120,0,40,.25)}',
                '& .ssb-coupon b{border-color:#fff;box-shadow:0 0 0 calc(var(--u)*.3) rgba(255,255,255,.35)}',
                '& .ssb-gifts{background:rgba(255,255,255,.94)}',
            ],
        ],
        'm3' => [
            'a' => [
                '& .ssb-hero{position:relative}',
                '& .ssb-hero::after{content:"\2727  \2726";position:absolute;inset-inline-end:36%;top:16%;color:#d4a24c;letter-spacing:.6em;font-size:calc(var(--u)*2.4);opacity:.8}',
                '& .ssb-num{text-shadow:0 calc(var(--u)*.35) 0 #8A6420,0 calc(var(--u)*1) calc(var(--u)*1.6) rgba(138,100,32,.3)}',
                '& .ssb-num::after{content:"\2726";position:absolute;margin-inline-start:.12em;top:-.05em;font-size:.38em;color:#d4a24c;text-shadow:none}',
                '& .ssb-coupon b{border-color:#C9952F;background:#fffaf0}',
                '& .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:86%;border-radius:50% 50% 0 0;border:calc(var(--u)*.35) dashed #d4a24c;border-bottom:0;background:radial-gradient(60% 60% at 50% 40%,#fff4dc,transparent 75%)}',
                '& .ssb-model picture{position:relative}',
            ],
        ],
        'u1' => ['a' => ['& .ssb-coupon b{border-color:#00732F}']],
        'u2' => ['a' => ['& .ssb-super{text-shadow:0 calc(var(--u)*.3) 0 rgba(255,255,255,.8)}', '& .ssb-coupon b{border-color:#E31B23}']],
        'u3' => ['a' => [
            '& .ssb-super{text-shadow:0 calc(var(--u)*.35) 0 rgba(0,0,0,.3)}',
            '& .ssb-coupon b{border-color:#fff}',
            '& .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:84%;border-radius:50% 50% 0 0;background:radial-gradient(58% 58% at 50% 42%,rgba(255,255,255,.35),transparent 72%)}',
        ]],
        'n1' => [
            'a' => [
                '& .ssb-copy{position:relative}',
                '& .ssb-super{text-shadow:0 0 calc(var(--u)*1.4) rgba(245,215,142,.5)}',
                '& .ssb-num{text-shadow:0 calc(var(--u)*.35) 0 #9a6b12,0 0 calc(var(--u)*3) rgba(245,201,105,.55)}',
                '& .ssb-coupon b{border-color:#E2B04A;background:#fffaf0}',
                '& .ssb-model::before{content:"";position:absolute;left:6%;right:6%;bottom:0;height:80%;border-radius:50% 50% 0 0;background:radial-gradient(60% 60% at 50% 42%,rgba(245,201,105,.5),transparent 72%)}',
                '& .ssb-model picture{position:relative}',
                '& .ssb-gifts{background:#fff;border-top-color:#E2B04A}',
            ],
        ],
        'n2' => [
            'a' => [
                '& .ssb-num{text-shadow:0 calc(var(--u)*.35) 0 #a87a22,0 calc(var(--u)*1) calc(var(--u)*1.6) rgba(168,122,34,.3)}',
                '& .ssb-coupon b{border-color:#D9A441;background:#fffaf0}',
                '& .ssb-model::before{content:"";position:absolute;left:4%;right:4%;bottom:0;height:86%;border-radius:50% 50% 0 0;border:calc(var(--u)*.35) solid #E8C77E;border-bottom:0;background:radial-gradient(60% 60% at 50% 40%,#fff3df,transparent 75%)}',
                '& .ssb-model picture{position:relative}',
            ],
        ],
        'n3' => [
            'a' => [
                '& .ssb-hero{position:relative}',
                '& .ssb-hero::before{content:"";position:absolute;inset-inline-start:30%;inset-inline-end:0;top:0;bottom:0;background:{FIREWORKS} center/cover no-repeat;opacity:.9;pointer-events:none}',
                '& .ssb-copy,& .ssb-model{position:relative;z-index:1}',
                '& .ssb-super{text-shadow:0 0 calc(var(--u)*1.6) rgba(245,201,105,.55)}',
                '& .ssb-num{text-shadow:0 0 calc(var(--u)*.6) #fff,0 0 calc(var(--u)*2.4) rgba(255,143,196,.9),0 0 calc(var(--u)*5) rgba(255,143,196,.5)}',
                '& .ssb-coupon b{border-color:#ff8fc4}',
                '& .ssb-gifts{background:#fff}',
            ],
            'm' => ['& .ssb-hero::before{inset-inline-start:52%;opacity:.8}'],
        ],
    ];

    /**
     * National Day: the skyline of real UAE landmarks with a waving flag
     * (before), then the paper-cut flag layers and the red hoist on the start
     * edge (after). [the skyline art, the layers' height on a computer].
     */
    private const SKYLINE = ['u1' => ['CITY_A', '28%'], 'u2' => ['CITY_B', '28%'], 'u3' => ['CITY_C', '30%']];

    // The art, made by tools/sale-templates-art.py from the approved drawings.
    private const FIREWORKS = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 600 300\'%3E%3Cg fill=\'none\' stroke-linecap=\'round\'%3E%3Cpath stroke=\'%23F5C969\' stroke-width=\'2.4\' d=\'M141 80L180 80M139 89L174 106M133 96L157 127M125 100L133 138M115 100L107 138M107 96L83 127M101 89L66 106M99 80L60 80M101 71L66 54M107 64L83 33M115 60L107 22M125 60L133 22M133 64L157 33M139 71L174 54\'/%3E%3Cpath stroke=\'%23F5C969\' stroke-width=\'5.2\' d=\'M180 80h0M174 106h0M157 127h0M133 138h0M107 138h0M83 127h0M66 106h0M60 80h0M66 54h0M83 33h0M107 22h0M133 22h0M157 33h0M174 54h0\'/%3E%3Cpath stroke=\'%23ff8fc4\' stroke-width=\'2.4\' d=\'M345 60L374 60M344 67L370 79M340 72L357 94M333 75L340 103M327 75L320 103M320 72L303 94M316 67L290 79M315 60L286 60M316 53L290 41M320 48L303 26M327 45L320 17M333 45L340 17M340 48L357 26M344 53L370 41\'/%3E%3Cpath stroke=\'%23ff8fc4\' stroke-width=\'5.2\' d=\'M374 60h0M370 79h0M357 94h0M340 103h0M320 103h0M303 94h0M290 79h0M286 60h0M290 41h0M303 26h0M320 17h0M340 17h0M357 26h0M370 41h0\'/%3E%3Cpath stroke=\'%23F5C969\' stroke-width=\'2.4\' d=\'M494 150L540 150M493 158L536 174M489 166L524 195M482 171L505 211M474 174L482 219M466 174L458 219M458 171L435 211M451 166L416 195M447 158L404 174M446 150L400 150M447 142L404 126M451 134L416 105M458 129L435 89M466 126L458 81M474 126L482 81M482 129L505 89M489 134L524 105M493 142L536 126\'/%3E%3Cpath stroke=\'%23F5C969\' stroke-width=\'5.2\' d=\'M540 150h0M536 174h0M524 195h0M505 211h0M482 219h0M458 219h0M435 211h0M416 195h0M404 174h0M400 150h0M404 126h0M416 105h0M435 89h0M458 81h0M482 81h0M505 89h0M524 105h0M536 126h0\'/%3E%3Cpath stroke=\'%239fd8ff\' stroke-width=\'2.4\' d=\'M253 210L276 210M251 215L272 226M248 220L262 238M243 222L248 245M237 222L232 245M232 220L218 238M229 215L208 226M227 210L204 210M229 205L208 194M232 200L218 182M237 198L232 175M243 198L248 175M248 200L262 182M251 205L272 194\'/%3E%3Cpath stroke=\'%239fd8ff\' stroke-width=\'5.2\' d=\'M276 210h0M272 226h0M262 238h0M248 245h0M232 245h0M218 238h0M208 226h0M204 210h0M208 194h0M218 182h0M232 175h0M248 175h0M262 182h0M272 194h0\'/%3E%3C/g%3E%3C/svg%3E")';

    private const CONFETTI = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 300 200\'%3E%3Crect x=\'20\' y=\'30\' width=\'10\' height=\'4\' rx=\'2\' fill=\'%23E2B04A\' transform=\'rotate%2830 25 32%29\'/%3E%3Crect x=\'110\' y=\'70\' width=\'10\' height=\'4\' rx=\'2\' fill=\'%23ec4f86\' transform=\'rotate%28-25 115 72%29\'/%3E%3Crect x=\'200\' y=\'20\' width=\'10\' height=\'4\' rx=\'2\' fill=\'%23E2B04A\' transform=\'rotate%2860 205 22%29\'/%3E%3Crect x=\'260\' y=\'120\' width=\'10\' height=\'4\' rx=\'2\' fill=\'%23f7a6c4\' transform=\'rotate%2815 265 122%29\'/%3E%3Crect x=\'60\' y=\'150\' width=\'10\' height=\'4\' rx=\'2\' fill=\'%23E2B04A\' transform=\'rotate%28-50 65 152%29\'/%3E%3Ccircle cx=\'160\' cy=\'140\' r=\'3\' fill=\'%23E2B04A\'/%3E%3Ccircle cx=\'40\' cy=\'95\' r=\'2.5\' fill=\'%23ec4f86\'/%3E%3Ccircle cx=\'235\' cy=\'75\' r=\'2.5\' fill=\'%23E2B04A\'/%3E%3C/svg%3E")';

    private const CITY_A = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1000 240\' preserveAspectRatio=\'xMidYMax meet\'%3E%3Cpath fill=\'%23c9d6dc\' d=\'M20 170h26v70h-26zM50 130h22v110h-22zM95 155h30v85h-30zM140 110h20v130h-20zM175 145h28v95h-28zM300 120h24v120h-24zM330 150h30v90h-30zM420 100h22v140h-22zM455 140h26v100h-26zM560 115h24v125h-24zM595 145h30v95h-30zM700 90h22v150h-22zM735 130h26v110h-26zM850 110h24v130h-24zM880 140h30v100h-30zM940 120h26v120h-26zM975 160h25v80h-25z\'/%3E%3Cpath fill=\'%235d6f78\' d=\'M30 200h150v40H30zM70 200a35 35 0 0 1 70 0zM103 166v-10l2-4 2 4v10zM44 205a14 14 0 0 1 28 0zM138 205a14 14 0 0 1 28 0zM34 140h7v100h-7zM34 140l3.5-12 3.5 12zM172 140h7v100h-7zM172 140l3.5-12 3.5 12zM205 240V110h60v130h-12V122h-36v118zM290 240V95l16-25v170zM312 240V115l14-21v146zM470 240v-40h8v-40h6v-40h5V82h4V48h3V10l2-10 2 10v38h3v34h4v38h5v40h6v40h8v40zM370 240q35-18 70 0zM371 190a34 30 0 1 0 68 0a34 30 0 1 0-68 0zM760 240V70l6-10q76 60 70 180zM756 50h4v190h-4zM880 240V120q12-20 24-8v128zM910 240V140q10-16 20-6v106zM612 240l28-90 28 90h-8l-20-70-20 70zM560 70h3v170h-3z\'/%3E%3Cellipse cx=\'408\' cy=\'188\' rx=\'17\' ry=\'14\' fill=\'%23eef5f7\'/%3E%3Cg fill=\'none\' stroke=\'%235d6f78\'%3E%3Ccircle cx=\'640\' cy=\'150\' r=\'52\' stroke-width=\'3\'/%3E%3Cpath stroke-width=\'1.4\' d=\'M640 150L692 150M640 150L685 176M640 150L666 195M640 150L640 202M640 150L614 195M640 150L595 176M640 150L588 150M640 150L595 124M640 150L614 105M640 150L640 98M640 150L666 105M640 150L685 124\'/%3E%3C/g%3E%3Cpath fill=\'%23E31B23\' d=\'M563 72h15v40h-15z\'/%3E%3Cpath fill=\'%2300732F\' d=\'M578 72L578 72L582 73.2L585 74.2L588 74.9L592 75L596 74.6L599 73.8L602 72.6L606 71.4L610 70.2L613 69.4L616 69L620 69.1L624 69.8L627 70.8L630 72L630 85.3L627 84.1L624 83.1L620 82.4L616 82.3L613 82.7L610 83.5L606 84.7L602 85.9L599 87.1L596 87.9L592 88.3L588 88.2L585 87.5L582 86.5L578 85.3Z\'/%3E%3Cpath fill=\'%23fff\' stroke=\'%230002\' stroke-width=\'.4\' d=\'M578 85.3L578 85.3L582 86.5L585 87.5L588 88.2L592 88.3L596 87.9L599 87.1L602 85.9L606 84.7L610 83.5L613 82.7L616 82.3L620 82.4L624 83.1L627 84.1L630 85.3L630 98.6L627 97.4L624 96.4L620 95.7L616 95.6L613 96L610 96.8L606 98L602 99.2L599 100.4L596 101.2L592 101.6L588 101.5L585 100.8L582 99.8L578 98.6Z\'/%3E%3Cpath fill=\'%23000\' d=\'M578 98.6L578 98.6L582 99.8L585 100.8L588 101.5L592 101.6L596 101.2L599 100.4L602 99.2L606 98L610 96.8L613 96L616 95.6L620 95.7L624 96.4L627 97.4L630 98.6L630 112L627 110.8L624 109.8L620 109.1L616 109L613 109.4L610 110.2L606 111.4L602 112.6L599 113.8L596 114.6L592 115L588 114.9L585 114.2L582 113.2L578 112Z\'/%3E%3C/svg%3E")';

    private const CITY_B = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1000 240\' preserveAspectRatio=\'xMidYMax meet\'%3E%3Cpath fill=\'%23e9a77c\' d=\'M20 170h26v70h-26zM50 130h22v110h-22zM95 155h30v85h-30zM140 110h20v130h-20zM175 145h28v95h-28zM300 120h24v120h-24zM330 150h30v90h-30zM420 100h22v140h-22zM455 140h26v100h-26zM560 115h24v125h-24zM595 145h30v95h-30zM700 90h22v150h-22zM735 130h26v110h-26zM850 110h24v130h-24zM880 140h30v100h-30zM940 120h26v120h-26zM975 160h25v80h-25z\'/%3E%3Cpath fill=\'%233a2633\' d=\'M30 200h150v40H30zM70 200a35 35 0 0 1 70 0zM103 166v-10l2-4 2 4v10zM44 205a14 14 0 0 1 28 0zM138 205a14 14 0 0 1 28 0zM34 140h7v100h-7zM34 140l3.5-12 3.5 12zM172 140h7v100h-7zM172 140l3.5-12 3.5 12zM205 240V110h60v130h-12V122h-36v118zM290 240V95l16-25v170zM312 240V115l14-21v146zM470 240v-40h8v-40h6v-40h5V82h4V48h3V10l2-10 2 10v38h3v34h4v38h5v40h6v40h8v40zM370 240q35-18 70 0zM371 190a34 30 0 1 0 68 0a34 30 0 1 0-68 0zM760 240V70l6-10q76 60 70 180zM756 50h4v190h-4zM880 240V120q12-20 24-8v128zM910 240V140q10-16 20-6v106zM612 240l28-90 28 90h-8l-20-70-20 70zM560 70h3v170h-3z\'/%3E%3Cellipse cx=\'408\' cy=\'188\' rx=\'17\' ry=\'14\' fill=\'%23f6c49a\'/%3E%3Cg fill=\'none\' stroke=\'%233a2633\'%3E%3Ccircle cx=\'640\' cy=\'150\' r=\'52\' stroke-width=\'3\'/%3E%3Cpath stroke-width=\'1.4\' d=\'M640 150L692 150M640 150L685 176M640 150L666 195M640 150L640 202M640 150L614 195M640 150L595 176M640 150L588 150M640 150L595 124M640 150L614 105M640 150L640 98M640 150L666 105M640 150L685 124\'/%3E%3C/g%3E%3Cpath fill=\'%23E31B23\' d=\'M563 72h15v40h-15z\'/%3E%3Cpath fill=\'%2300732F\' d=\'M578 72L578 72L582 73.2L585 74.2L588 74.9L592 75L596 74.6L599 73.8L602 72.6L606 71.4L610 70.2L613 69.4L616 69L620 69.1L624 69.8L627 70.8L630 72L630 85.3L627 84.1L624 83.1L620 82.4L616 82.3L613 82.7L610 83.5L606 84.7L602 85.9L599 87.1L596 87.9L592 88.3L588 88.2L585 87.5L582 86.5L578 85.3Z\'/%3E%3Cpath fill=\'%23fff\' stroke=\'%230002\' stroke-width=\'.4\' d=\'M578 85.3L578 85.3L582 86.5L585 87.5L588 88.2L592 88.3L596 87.9L599 87.1L602 85.9L606 84.7L610 83.5L613 82.7L616 82.3L620 82.4L624 83.1L627 84.1L630 85.3L630 98.6L627 97.4L624 96.4L620 95.7L616 95.6L613 96L610 96.8L606 98L602 99.2L599 100.4L596 101.2L592 101.6L588 101.5L585 100.8L582 99.8L578 98.6Z\'/%3E%3Cpath fill=\'%23000\' d=\'M578 98.6L578 98.6L582 99.8L585 100.8L588 101.5L592 101.6L596 101.2L599 100.4L602 99.2L606 98L610 96.8L613 96L616 95.6L620 95.7L624 96.4L627 97.4L630 98.6L630 112L627 110.8L624 109.8L620 109.1L616 109L613 109.4L610 110.2L606 111.4L602 112.6L599 113.8L596 114.6L592 115L588 114.9L585 114.2L582 113.2L578 112Z\'/%3E%3C/svg%3E")';

    private const CITY_C = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1000 240\' preserveAspectRatio=\'xMidYMax meet\'%3E%3Cpath fill=\'rgba%28255,255,255,.35%29\' d=\'M20 170h26v70h-26zM50 130h22v110h-22zM95 155h30v85h-30zM140 110h20v130h-20zM175 145h28v95h-28zM300 120h24v120h-24zM330 150h30v90h-30zM420 100h22v140h-22zM455 140h26v100h-26zM560 115h24v125h-24zM595 145h30v95h-30zM700 90h22v150h-22zM735 130h26v110h-26zM850 110h24v130h-24zM880 140h30v100h-30zM940 120h26v120h-26zM975 160h25v80h-25z\'/%3E%3Cpath fill=\'%23fff\' d=\'M30 200h150v40H30zM70 200a35 35 0 0 1 70 0zM103 166v-10l2-4 2 4v10zM44 205a14 14 0 0 1 28 0zM138 205a14 14 0 0 1 28 0zM34 140h7v100h-7zM34 140l3.5-12 3.5 12zM172 140h7v100h-7zM172 140l3.5-12 3.5 12zM205 240V110h60v130h-12V122h-36v118zM290 240V95l16-25v170zM312 240V115l14-21v146zM470 240v-40h8v-40h6v-40h5V82h4V48h3V10l2-10 2 10v38h3v34h4v38h5v40h6v40h8v40zM370 240q35-18 70 0zM371 190a34 30 0 1 0 68 0a34 30 0 1 0-68 0zM760 240V70l6-10q76 60 70 180zM756 50h4v190h-4zM880 240V120q12-20 24-8v128zM910 240V140q10-16 20-6v106zM612 240l28-90 28 90h-8l-20-70-20 70zM560 70h3v170h-3z\'/%3E%3Cellipse cx=\'408\' cy=\'188\' rx=\'17\' ry=\'14\' fill=\'%2300732F\'/%3E%3Cg fill=\'none\' stroke=\'%23fff\'%3E%3Ccircle cx=\'640\' cy=\'150\' r=\'52\' stroke-width=\'3\'/%3E%3Cpath stroke-width=\'1.4\' d=\'M640 150L692 150M640 150L685 176M640 150L666 195M640 150L640 202M640 150L614 195M640 150L595 176M640 150L588 150M640 150L595 124M640 150L614 105M640 150L640 98M640 150L666 105M640 150L685 124\'/%3E%3C/g%3E%3Cpath fill=\'%23E31B23\' d=\'M563 72h15v40h-15z\'/%3E%3Cpath fill=\'%2300732F\' d=\'M578 72L578 72L582 73.2L585 74.2L588 74.9L592 75L596 74.6L599 73.8L602 72.6L606 71.4L610 70.2L613 69.4L616 69L620 69.1L624 69.8L627 70.8L630 72L630 85.3L627 84.1L624 83.1L620 82.4L616 82.3L613 82.7L610 83.5L606 84.7L602 85.9L599 87.1L596 87.9L592 88.3L588 88.2L585 87.5L582 86.5L578 85.3Z\'/%3E%3Cpath fill=\'%23fff\' stroke=\'%230002\' stroke-width=\'.4\' d=\'M578 85.3L578 85.3L582 86.5L585 87.5L588 88.2L592 88.3L596 87.9L599 87.1L602 85.9L606 84.7L610 83.5L613 82.7L616 82.3L620 82.4L624 83.1L627 84.1L630 85.3L630 98.6L627 97.4L624 96.4L620 95.7L616 95.6L613 96L610 96.8L606 98L602 99.2L599 100.4L596 101.2L592 101.6L588 101.5L585 100.8L582 99.8L578 98.6Z\'/%3E%3Cpath fill=\'%23000\' d=\'M578 98.6L578 98.6L582 99.8L585 100.8L588 101.5L592 101.6L596 101.2L599 100.4L602 99.2L606 98L610 96.8L613 96L616 95.6L620 95.7L624 96.4L627 97.4L630 98.6L630 112L627 110.8L624 109.8L620 109.1L616 109L613 109.4L610 110.2L606 111.4L602 112.6L599 113.8L596 114.6L592 115L588 114.9L585 114.2L582 113.2L578 112Z\'/%3E%3C/svg%3E")';

    private const LAYERS = 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 1000 120\' preserveAspectRatio=\'none\'%3E%3Cpath fill=\'%230000002e\' transform=\'translate%280 4%29\' d=\'M0 56L20 56L40 57L60 57L80 57L100 56L120 54L140 53L160 50L180 48L200 45L220 42L240 38L260 35L280 33L300 30L320 28L340 27L360 26L380 25L400 26L420 26L440 27L460 28L480 29L500 29L520 30L540 30L560 29L580 28L600 27L620 25L640 23L660 20L680 18L700 15L720 12L740 10L760 9L780 7L800 7L820 6L840 7L860 8L880 9L900 10L920 12L940 13L960 15L980 16L1000 17L1000 120H0z\'/%3E%3Cpath fill=\'%2300732F\' d=\'M0 56L20 56L40 57L60 57L80 57L100 56L120 54L140 53L160 50L180 48L200 45L220 42L240 38L260 35L280 33L300 30L320 28L340 27L360 26L380 25L400 26L420 26L440 27L460 28L480 29L500 29L520 30L540 30L560 29L580 28L600 27L620 25L640 23L660 20L680 18L700 15L720 12L740 10L760 9L780 7L800 7L820 6L840 7L860 8L880 9L900 10L920 12L940 13L960 15L980 16L1000 17L1000 120H0z\'/%3E%3Cpath fill=\'%230000002e\' transform=\'translate%280 4%29\' d=\'M0 92L20 91L40 89L60 87L80 84L100 82L120 79L140 76L160 73L180 70L200 67L220 65L240 64L260 63L280 62L300 62L320 62L340 62L360 62L380 63L400 63L420 64L440 64L460 63L480 62L500 61L520 59L540 57L560 55L580 52L600 50L620 47L640 45L660 43L680 42L700 41L720 40L740 40L760 41L780 41L800 42L820 43L840 44L860 45L880 46L900 47L920 47L940 46L960 46L980 45L1000 43L1000 120H0z\'/%3E%3Cpath fill=\'%23fff\' d=\'M0 92L20 91L40 89L60 87L80 84L100 82L120 79L140 76L160 73L180 70L200 67L220 65L240 64L260 63L280 62L300 62L320 62L340 62L360 62L380 63L400 63L420 64L440 64L460 63L480 62L500 61L520 59L540 57L560 55L580 52L600 50L620 47L640 45L660 43L680 42L700 41L720 40L740 40L760 41L780 41L800 42L820 43L840 44L860 45L880 46L900 47L920 47L940 46L960 46L980 45L1000 43L1000 120H0z\'/%3E%3Cpath fill=\'%230000002e\' transform=\'translate%280 4%29\' d=\'M0 121L20 118L40 115L60 112L80 110L100 107L120 105L140 103L160 102L180 101L200 100L220 100L240 100L260 100L280 100L300 100L320 100L340 100L360 99L380 98L400 97L420 95L440 94L460 91L480 89L500 87L520 84L540 82L560 80L580 79L600 78L620 77L640 76L660 76L680 77L700 77L720 78L740 79L760 79L780 80L800 80L820 80L840 79L860 79L880 78L900 76L920 75L940 73L960 71L980 70L1000 69L1000 120H0z\'/%3E%3Cpath fill=\'%23000\' d=\'M0 121L20 118L40 115L60 112L80 110L100 107L120 105L140 103L160 102L180 101L200 100L220 100L240 100L260 100L280 100L300 100L320 100L340 100L360 99L380 98L400 97L420 95L440 94L460 91L480 89L500 87L520 84L540 82L560 80L580 79L600 78L620 77L640 76L660 76L680 77L700 77L720 78L740 79L760 79L780 80L800 80L820 80L840 79L860 79L880 78L900 76L920 75L940 73L960 71L980 70L1000 69L1000 120H0z\'/%3E%3C/svg%3E")';

    /** @var array<string,string> */
    private static array $memo = [];

    /** Is this a template key? */
    public static function has(mixed $key): bool
    {
        return is_string($key) && in_array($key, SuperSaleBanner::TEMPLATES, true) && isset(self::TEMPLATES[$key]);
    }

    /** The family: 'm' (11.11), 'u' (National Day), 'n' (New Year), or '' for Super Sale. */
    public static function family(string $key): string
    {
        return $key === self::DEFAULT || ! self::has($key) ? '' : $key[0];
    }

    /** Does the template draw the big number? */
    public static function hasNum(string $key): bool
    {
        return isset(self::NUM[$key]);
    }

    /** The class the banner carries: '' for Super Sale (the banner byte for byte as before). */
    public static function cls(string $key): string
    {
        return self::family($key) === '' ? '' : ' ssb-t-'.$key;
    }

    /**
     * The template's style values, in u and colours, over SuperSaleBanner's:
     * [dev => [el => ['s' => u, 'c' => hex]], '_' => ['g' => u]] per device.
     *
     * @return array{m: array<string,array<string,mixed>>, d: array<string,array<string,mixed>>}
     */
    public static function style(string $key): array
    {
        $fam = self::family($key);
        if ($fam === '') {
            return ['m' => [], 'd' => []];
        }

        $sz = self::SIZES[$fam];
        $out = ['m' => [], 'd' => []];
        foreach (['m' => 0, 'd' => 1] as $dev => $i) {
            foreach (self::COLOURS[$key] as $el => $c) {
                $out[$dev][$el]['c'] = $c;
            }
            $out[$dev]['title']['s'] = $sz['title'][$i];
            $out[$dev]['_']['g'] = $sz['g'][$i];
            if (isset(self::NUM[$key])) {
                $out[$dev]['num'] = ['s' => self::NUM[$key][$i], 'w' => self::NUM_ELEMENT[4], 'c' => self::NUM[$key][2], 'x' => 0, 'y' => 0, 'v' => true];
            }
        }
        $out['m']['tag']['s'] = $sz['tag'];

        return $out;
    }

    /**
     * Arabic's own values over style(), the same shape: [dev => [el => ['s' => u]]].
     *
     * @return array{m: array<string,array<string,mixed>>, d: array<string,array<string,mixed>>}
     */
    public static function styleAr(string $key): array
    {
        $own = self::SIZES_AR[self::family($key)] ?? [];
        $out = ['m' => [], 'd' => []];
        foreach ($own as $dev => $els) {
            foreach ($els as $el => $u) {
                $out[$dev][$el]['s'] = $u;
            }
        }

        return $out;
    }

    /**
     * The stylesheet a page prints for this template: '' for Super Sale. The
     * phone rules at 900px and under, the computer's above, each written
     * `:not()` the editor's force class for the other device, as
     * SuperSaleBanner::css() is.
     */
    public static function css(string $key): string
    {
        if (self::family($key) === '') {
            return '';
        }
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        [$all, $phone, $desk] = self::rules($key);
        $root = '.kbb-ssb.ssb-t-'.$key;

        return self::$memo[$key] = self::at($all, $root)
            .'@media (max-width:900px){'.self::at($phone, $root.':not(.ssb-fd)').'}'
            .'@media (min-width:901px){'.self::at($desk, $root.':not(.ssb-fm)').'}';
    }

    /** The editor's device preview of this template (.ssb-fm / .ssb-fd), never on a shop page. */
    public static function cssForced(string $key): string
    {
        if (self::family($key) === '') {
            return '';
        }
        [, $phone, $desk] = self::rules($key);
        $root = '.kbb-ssb.ssb-t-'.$key;

        return self::at($phone, $root.'.ssb-fm').self::at($desk, $root.'.ssb-fd');
    }

    /**
     * What the editor previews every template with: every template's rules
     * and their forced twins. Admin only (SuperSaleBanner::editorContext()).
     */
    public static function editorCss(): string
    {
        $out = '';
        foreach (array_keys(self::TEMPLATES) as $key) {
            $out .= self::css($key).self::cssForced($key);
        }

        return $out;
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<string>} every device's rules, the phone's, the computer's */
    private static function rules(string $key): array
    {
        $fam = self::family($key);
        $all = [];
        $phone = [];
        $desk = [];

        // The ground, mirrored for Arabic so the light stays on the picture's side.
        $bg = self::art(self::BG[$key]);
        $all[] = '& .ssb-in{'.(isset(self::BG_PLAIN[$key]) ? 'background:'.self::BG_PLAIN[$key].';' : '').'background:'.$bg.'}';
        $mirrored = self::mirror($bg);
        if ($mirrored !== $bg) {
            $all[] = '&[dir=rtl] .ssb-in{background:'.$mirrored.'}';
        }

        if (isset(self::DOTS[$key])) {
            [$c1, $c2, $step, $op, $r1, $r2] = self::DOTS[$key];
            $half = intdiv($step, 2);
            $all[] = '& .ssb-hero{position:relative}';
            $all[] = '& .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;'
                ."background:radial-gradient(circle,{$c1} 0 {$r1},transparent {$r2}) 0 0/{$step}px {$step}px,radial-gradient(circle,{$c2} 0 {$r1},transparent {$r2}) {$half}px {$half}px/{$step}px {$step}px;opacity:{$op};"
                .'-webkit-mask:linear-gradient(90deg,transparent 0,#000 40%);mask:linear-gradient(90deg,transparent 0,#000 40%)}';
            $all[] = '&[dir=rtl] .ssb-hero::before{transform:scaleX(-1)}';
        }

        if ($fam === 'u') {
            [$city, $layers] = self::SKYLINE[$key];
            $city = constant(self::class.'::'.$city);
            array_push($all,
                '& .ssb-hero{position:relative}',
                '& .ssb-copy,& .ssb-model{position:relative;z-index:1}',
                '& .ssb-super{text-shadow:none}',
                '& .ssb-model picture{position:relative}',
                '& .ssb-gifts{background:#fff;border-top:0;box-shadow:none}',
                '& .ssb-hero::before{content:"";position:absolute;inset:0;pointer-events:none;background:'.$city.' no-repeat}',
                '& .ssb-hero::after{content:"";position:absolute;inset:0;pointer-events:none;background:linear-gradient(#E31B23,#E31B23) 0 0/calc(var(--u)*2) 100% no-repeat,'.self::LAYERS.' bottom/100% '.$layers.' no-repeat}',
                // Arabic: the hoist on the right and the layers rising the other way.
                '&[dir=rtl] .ssb-hero::after{transform:scaleX(-1)}',
            );
            array_push($desk,
                '& .ssb-hero::before{background-position:right calc(var(--u)*3) bottom calc(var(--u)*3);background-size:auto 76%}',
                '&[dir=rtl] .ssb-hero::before{background-position:left calc(var(--u)*3) bottom calc(var(--u)*3)}',
            );
            array_push($phone,
                '& .ssb-hero::before{background-position:right -26% bottom calc(var(--u)*2);background-size:auto 60%;-webkit-mask:linear-gradient(90deg,transparent 45%,#000 72%);mask:linear-gradient(90deg,transparent 45%,#000 72%)}',
                '&[dir=rtl] .ssb-hero::before{background-position:left -26% bottom calc(var(--u)*2);-webkit-mask:linear-gradient(270deg,transparent 45%,#000 72%);mask:linear-gradient(270deg,transparent 45%,#000 72%)}',
                '& .ssb-hero::after{background-size:calc(var(--u)*2) 100%,100% 20%}',
                '& .ssb-super{white-space:normal;max-width:12ch}',
            );
        }

        foreach (self::RULES[$key] ?? [] as $where => $list) {
            foreach ($list as $r) {
                $r = self::art($r);
                match ($where) {
                    'a' => $all[] = $r,
                    'm' => $phone[] = $r,
                    'd' => $desk[] = $r,
                };
            }
        }

        // The sizes and colours: each the var() fallback of his own control.
        $style = self::style($key);
        $sized = static function (string $dev) use ($style, $key): array {
            $list = [];
            foreach ($style[$dev] as $el => $props) {
                if ($el === '_' || $el === 'num') {
                    continue;
                }
                $decl = [];
                if (isset($props['s'])) {
                    $decl[] = 'font-size:calc(var(--u)*var(--ssb-'.$el.'-s'.$dev.','.SuperSaleBanner::num($props['s']).'))';
                }
                if (isset($props['c'])) {
                    $decl[] = 'color:var(--ssb-'.$el.'-c'.$dev.','.$props['c'].')';
                }
                $list[] = '& '.SuperSaleBanner::ELEMENTS[$el][1].'{'.implode(';', $decl).'}';
            }
            $list[] = '& .ssb-copy{gap:calc(var(--u)*var(--ssb-g'.$dev.','.SuperSaleBanner::num($style[$dev]['_']['g']).'))}';
            foreach (self::styleAr($key)[$dev] as $el => $props) {
                $list[] = '&[dir=rtl] '.SuperSaleBanner::ELEMENTS[$el][1].'{font-size:calc(var(--u)*var(--ssb-'.$el.'-s'.$dev.','.SuperSaleBanner::num($props['s']).'))}';
            }

            return $list;
        };
        array_push($phone, ...$sized('m'));
        array_push($desk, ...$sized('d'));

        if (isset(self::NUM[$key])) {
            [$sm, $sd, $c] = self::NUM[$key];
            $w = self::NUM_ELEMENT[4];
            $all[] = '& .ssb-num{line-height:.9;letter-spacing:'.($fam === 'm' ? '-.02em' : '-.01em').'}';
            // The same digits in Arabic: the shop's Latin face, not Cairo's.
            $all[] = '&[dir=rtl] .ssb-num{font-family:var(--sans,system-ui),sans-serif}';
            // A phone: on its own line above the title.
            $phone[] = '& .ssb-num{font-size:calc(var(--u)*var(--ssb-num-sm,'.SuperSaleBanner::num($sm).'));font-weight:var(--ssb-num-wm,'.$w.');color:var(--ssb-num-cm,'.$c.');'
                .'display:var(--ssb-num-vm,block);position:relative;left:var(--ssb-num-xm,0px);top:var(--ssb-num-ym,0px)}';
            // A computer: the centrepiece of the empty middle. The copy is a
            // two-column grid -- its lines in the first column, as wide as the
            // widest of them, and the number centred in the room left between
            // them and the picture -- so the number can never sit on the title,
            // in English or in the much wider Arabic, at any width. The lines
            // keep their gap as a margin, because a row gap would also open
            // under a line he has emptied.
            $gap = 'calc(var(--u)*var(--ssb-gd,'.SuperSaleBanner::num($style['d']['_']['g']).'))';
            $desk[] = '& .ssb-copy{display:grid;grid-template-columns:auto minmax(0,1fr);gap:0;align-content:center;justify-items:var(--ssb-al-ad,stretch)}';
            $desk[] = '& .ssb-copy>*{grid-column:1}';
            $desk[] = '& .ssb-copy>:not(.ssb-num)~:not(.ssb-num){margin-top:'.$gap.'}';
            $desk[] = '& .ssb-num{grid-column:2;grid-row:1/span 4;align-self:center;justify-self:center;white-space:nowrap;'
                .'font-size:calc(var(--u)*var(--ssb-num-sd,'.SuperSaleBanner::num($sd).'));font-weight:var(--ssb-num-wd,'.$w.');color:var(--ssb-num-cd,'.$c.');'
                .'display:var(--ssb-num-vd,block);position:relative;z-index:1;left:var(--ssb-num-xd,0px);top:var(--ssb-num-yd,0px)}';
        }

        return [$all, $phone, $desk];
    }

    /** A ground for right-to-left: every `at X%` across becomes 100-X, every linear angle its mirror. */
    public static function mirror(string $bg): string
    {
        $bg = (string) preg_replace_callback('/ at (\d+(?:\.\d+)?)% /', static fn (array $m): string => ' at '.SuperSaleBanner::num(100 - (float) $m[1]).'% ', $bg);

        return (string) preg_replace_callback('/linear-gradient\((\d+)deg/', static fn (array $m): string => 'linear-gradient('.((360 - (int) $m[1]) % 360).'deg', $bg);
    }

    private static function art(string $rule): string
    {
        return str_replace(['{CONFETTI}', '{FIREWORKS}'], [self::CONFETTI, self::FIREWORKS], $rule);
    }

    /** @param list<string> $rules */
    private static function at(array $rules, string $root): string
    {
        return implode('', array_map(static fn (string $r): string => str_replace('&', $root, $r), $rules));
    }

    /**
     * What the editor offers: every template's label, occasion, chip, words
     * and whether it draws the number. Constants only.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEditor(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $key => [$label, $occasion, $swatch]) {
            $words = [];
            foreach (self::WORDS[$key] as $k => [$en, $ar]) {
                $words['en'][$k] = $en;
                $words['ar'][$k] = $ar;
            }
            $out[] = ['key' => $key, 'label' => $label, 'occasion' => $occasion, 'swatch' => $swatch, 'num' => self::hasNum($key), 'words' => $words];
        }

        return $out;
    }
}
