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
<meta name="viewport" content="width=device-width, initial-scale=1">
@endverbatim
{!! $seo ?? '' !!}
{!! app(\App\Services\Analytics::class)->headTags() !!}
@verbatim
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">@endverbatim{{--
    AND THE ARABIC FACE, WHICH THIS DOCUMENT ALSO HAS TO ASK FOR ITSELF.

    The webfont link above carries no Arabic glyph. Before this, /ar/skin-quiz/
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
    'weights' => '300;400;500;600;700;800',
    'stacks' => [':root' => ['--sans' => '"Poppins",system-ui,-apple-system,Segoe UI,Roboto,sans-serif']],
])
@verbatim
<style>
:root{
  --bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;
  --pink-ink:#A82F53;--ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);
  --line-2:rgba(42,34,40,.06);--gold:#BE8E2E;--green:#2E9E6B;--sale:#E23A4E;--lav:#8B5CF6;--coral:#F2884E;
  --sans:"Poppins",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  --ease:cubic-bezier(.22,.61,.36,1);--sh-s:0 2px 12px rgba(42,34,40,.07);--sh-m:0 18px 40px -14px rgba(168,47,83,.34);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--sans);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased;line-height:1.45;
  background:
   radial-gradient(55% 45% at 88% -6%,#FFD3E4,transparent 70%),
   radial-gradient(50% 42% at 4% 8%,#EBDEFB,transparent 70%),
   radial-gradient(55% 45% at 50% 112%,#FFE3CF,transparent 70%),
   linear-gradient(180deg,#FFF6FA,#FFF8F5);}
.deco{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:0}
.blob{position:absolute;border-radius:50%;filter:blur(50px);opacity:.5}
.blob.b1{width:300px;height:300px;background:#FFC6DC;top:-60px;inset-inline-end:-40px}
.blob.b2{width:260px;height:260px;background:#D9C7Fb;bottom:6%;inset-inline-start:-60px}
.blob.b3{width:220px;height:220px;background:#FFE0C2;top:40%;inset-inline-end:-50px;opacity:.4}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
input,textarea{font-family:inherit}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:linear-gradient(120deg,var(--pink),var(--pink-deep));color:#fff;font-weight:600;font-size:13.5px;padding:12px 22px;border-radius:99px;transition:.18s var(--ease);box-shadow:0 6px 16px -6px rgba(193,62,99,.5)}
.btn:hover{transform:translateY(-1px);box-shadow:var(--sh-m)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--line);box-shadow:none}
.btn.ghost:hover{border-color:var(--pink);color:var(--pink-deep);background:#fff}
.btn.sm{padding:9px 15px;font-size:12.5px}
.btn.block{width:100%}
/* Phone shopper pass - Lane EA. This page draws its own buttons, so it gets
   none of the storefront's mobile tap-target rules: "Start the quiz" and every
   Next/Back after it were 40px tall at 360px. */
@media (max-width: 820px) { .btn{min-height:44px} }

.shell{position:relative;z-index:1;max-width:720px;margin:0 auto;padding:22px 18px 50px}
.qhead{display:flex;align-items:center;gap:10px;margin-bottom:12px}
/* font-size and font-weight are set here, so the heading that carries this
   class does not pick up the browser's 2em bold default. */
.brand{font-size:14px;font-weight:800;letter-spacing:-.01em;color:var(--pink-deep);margin:0}
.brand span{color:var(--ink);font-weight:600}
.qcount{margin-inline-start:auto;font-size:11.5px;font-weight:600;color:var(--muted)}
.pips{display:flex;gap:5px;margin-bottom:18px}
.pip{height:6px;flex:1;border-radius:99px;background:var(--blush);transition:.4s var(--ease);position:relative;overflow:hidden}
.pip.done,.pip.cur{background:linear-gradient(90deg,var(--pink),var(--coral) 50%,var(--lav))}
.pip.cur::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.75),transparent);transform:translateX(-100%);animation:sheen 1.6s var(--ease) infinite}
@keyframes sheen{to{transform:translateX(100%)}}

.card{position:relative;background:#fff;border:1px solid var(--line);border-radius:24px;box-shadow:0 20px 50px -22px rgba(168,47,83,.28),var(--sh-s);padding:clamp(20px,3.2vw,34px);overflow:hidden;animation:rise .4s var(--ease)}
.card::before{content:"";position:absolute;top:0;inset-inline-start:0;inset-inline-end:0;height:4px;background:linear-gradient(90deg,var(--pink),var(--coral),var(--lav),var(--pink))}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.qeye{display:inline-flex;align-items:center;gap:7px;font-size:10.5px;font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--pink-deep);margin-bottom:9px}
.qeye .dot{width:6px;height:6px;border-radius:50%;background:linear-gradient(120deg,var(--pink),var(--lav))}
.qttl{font-size:clamp(19px,2.5vw,23px);font-weight:700;letter-spacing:-.015em;line-height:1.2}
.qttl small{display:block;font-size:12.5px;font-weight:400;color:var(--muted);margin-top:4px;letter-spacing:0;line-height:1.45}
.grad{background:linear-gradient(100deg,var(--pink-deep),var(--lav) 55%,var(--pink));-webkit-background-clip:text;background-clip:text;color:transparent}

.opts{display:grid;gap:10px;margin-top:18px}
.opts.cols2{grid-template-columns:1fr 1fr}
.opts.cols3{grid-template-columns:1fr 1fr 1fr}
.opts.pills{display:flex;flex-wrap:wrap;gap:9px}
@media(max-width:560px){.opts.cols3{grid-template-columns:1fr 1fr}}
@media(max-width:440px){.opts.cols2{grid-template-columns:1fr}}
.opt{display:flex;align-items:center;gap:11px;text-align:start;border:1.5px solid var(--line);border-radius:14px;padding:12px 13px;background:#fff;transition:.15s var(--ease);position:relative;animation:pop .32s var(--ease) both}
@keyframes pop{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
.opt:hover{transform:translateY(-2px);box-shadow:var(--sh-s)}
.opt.sel{border-color:var(--pink);background:linear-gradient(120deg,#fff,var(--pink-soft));box-shadow:0 0 0 3px rgba(224,86,123,.13)}
.opt .oic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.opt .oic svg{width:19px;height:19px}
.opt .ot{font-size:13px;font-weight:600;line-height:1.2}
.opt .od{font-size:10.5px;color:var(--muted);margin-top:1px}
.opt .check{position:absolute;top:10px;inset-inline-end:10px;width:18px;height:18px;border-radius:50%;border:1.5px solid var(--line);display:grid;place-items:center;opacity:0;transition:.15s}
.opt.sel .check{opacity:1;background:var(--pink);border-color:var(--pink);color:#fff}
.opt .check svg{width:12px;height:12px}
.opts.pills .opt{padding:10px 17px;border-radius:99px}
.opts.pills .opt.sel{background:linear-gradient(120deg,var(--pink),var(--pink-deep));border-color:var(--pink);color:#fff}
.opts.pills .check{display:none}

.nav{display:flex;align-items:center;gap:10px;margin-top:22px}
.nav .spacer{flex:1}
.back{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--ink-2);padding:8px 4px}
.back:hover{color:var(--pink-deep)}
.hint{font-size:11.5px;color:var(--muted)}

.gate-ic{width:50px;height:50px;border-radius:15px;background:linear-gradient(135deg,var(--blush),#EBDEFB);display:grid;place-items:center;color:var(--pink-deep);margin-bottom:14px}
.gate-ic svg{width:26px;height:26px}
.field{margin-top:12px}
.field label{display:block;font-size:11.5px;font-weight:600;color:var(--ink-2);margin-bottom:5px}
.field input,.field textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:12px 14px;font-size:13.5px;color:var(--ink);background:#fff;transition:.15s}
.field input:focus,.field textarea:focus{outline:none;border-color:var(--pink);box-shadow:0 0 0 3px rgba(224,86,123,.12)}
.field.err input{border-color:var(--sale)}
.field .msg{font-size:11px;color:var(--sale);margin-top:4px;display:none}
.field.err .msg{display:block}
.two{display:grid;grid-template-columns:1fr 1fr;gap:11px}
@media(max-width:440px){.two{grid-template-columns:1fr}}
.trust-row{display:flex;flex-wrap:wrap;gap:7px;margin-top:14px}
.trust-row span{font-size:11px;color:var(--ink-2);background:var(--cream);border:1px solid var(--line-2);border-radius:99px;padding:6px 11px;display:inline-flex;gap:5px;align-items:center}
.trust-row svg{width:12px;height:12px;color:var(--green)}

.res-chips{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}
.res-chip{font-size:11px;font-weight:600;border-radius:99px;padding:5px 12px}
.avoid-note{margin-top:10px;font-size:11.5px;color:var(--ink-2);background:#FFF3E9;border:1px solid #FAD9BD;border-radius:11px;padding:9px 12px}
.avoid-note b{color:var(--coral)}
.routine{border:1px solid var(--line);border-radius:17px;overflow:hidden;margin-top:14px;background:#fff}
.rtabs{display:flex;gap:5px;background:var(--cream);border:1px solid var(--line);border-radius:99px;padding:5px;margin-top:16px}
.rtab{flex:1;font-size:11.5px;font-weight:600;color:var(--ink-2);padding:9px 6px;border-radius:99px;transition:.16s var(--ease);white-space:nowrap}
.rtab:hover{color:var(--pink-deep)}
.rtab.on{background:linear-gradient(120deg,var(--pink),var(--pink-deep));color:#fff;box-shadow:0 4px 12px -5px rgba(193,62,99,.55)}
#rpanel .routine{margin-top:12px;animation:rise .3s var(--ease)}
.rgrid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px}
@media(max-width:360px){.rgrid{grid-template-columns:1fr}}
.rcell{display:flex;align-items:flex-start;gap:10px;border:1px solid var(--line-2);border-radius:12px;padding:8px 9px;background:#fff;min-width:0}
.rcw{min-width:0;flex:1}
.rcell .rthumb{width:36px;height:36px;border-radius:9px;font-size:11px}
.rcell .rpname{font-size:12px;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* The step's one-line explanation. Its own class because .rpname is
   white-space:nowrap — correct for the product name it used to hold, and
   the reason a sentence in that slot pushed the grid track wider than the
   card it sits in. */
.rcell .rpdesc{font-size:11.5px;font-weight:500;line-height:1.3;color:var(--ink-2);overflow-wrap:anywhere}
.routine-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;background:linear-gradient(120deg,var(--cream),var(--pink-soft));border-bottom:1px solid var(--line)}
.routine-h .rt{font-size:15px;font-weight:700;letter-spacing:-.01em}
.routine-h .rd{font-size:11.5px;color:var(--ink-2);margin-top:2px;max-width:360px;line-height:1.4}
.routine-h .rtag{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#fff;padding:4px 9px;border-radius:99px;white-space:nowrap}
.rprod{display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line-2)}
.rthumb{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;font-weight:800;font-size:12px;flex-shrink:0}
.rstep{font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--pink-deep)}
.rpname{font-size:12.5px;font-weight:600;line-height:1.25}
.routine-f{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:13px 16px}
.routine-f .rsum{font-size:12.5px}

/* The hand-off to Build my routine — Lane FT. Drawn only when the module is on,
   so on the shipped shop these three rules style nothing. Deliberately quieter
   than .expert below it, which is this page's one promise of contact. */
.rtnlink{margin-top:16px;border:1px solid var(--line);border-radius:17px;padding:15px 16px;background:linear-gradient(120deg,var(--cream),var(--pink-soft));display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.rtnlink .rl{font-size:12px;color:var(--ink-2);line-height:1.45;max-width:420px}
.rtnlink .btn{flex-shrink:0}

.expert{margin-top:16px;border:1.5px solid #E7D6FB;border-radius:17px;padding:18px;background:linear-gradient(135deg,#fff,#F3ECFD)}
.expert h3{font-size:15px;font-weight:700;display:flex;align-items:center;gap:9px}
.expert h3 .eic{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,var(--lav),var(--pink));color:#fff;display:grid;place-items:center}
.expert h3 .eic svg{width:17px;height:17px}
.expert p{font-size:12px;color:var(--ink-2);margin:8px 0 12px;max-width:480px;line-height:1.45}
.expert .ex-sent{display:none;align-items:center;gap:11px;background:#fff;border:1px solid var(--green);border-radius:13px;padding:14px}
.expert.sent .ex-form{display:none}
.expert.sent .ex-sent{display:flex}
.ex-sent .ok{width:32px;height:32px;border-radius:50%;background:var(--green);color:#fff;display:grid;place-items:center;flex-shrink:0}

.beview{margin-top:18px;border:1px dashed var(--line);border-radius:14px;background:var(--cream);overflow:hidden}
.beview summary{cursor:pointer;list-style:none;padding:13px 16px;font-size:12.5px;font-weight:600;color:var(--ink-2);display:flex;align-items:center;gap:8px}
.beview summary::-webkit-details-marker{display:none}
.beview summary .lk{width:15px;height:15px;color:var(--pink-deep)}
.beview[open] summary{border-bottom:1px dashed var(--line)}
.beview pre{margin:0;padding:15px 16px;font-size:11px;line-height:1.65;color:var(--ink-2);overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap}
.beview .bk{color:var(--pink-deep)}

.start-perks{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}
.start-perks span{font-size:12px;font-weight:500;color:var(--ink-2);display:inline-flex;gap:6px;align-items:center}
.start-perks svg{width:16px;height:16px;color:var(--pink-deep)}

/* RTL-PHYSICAL: centring idiom (left:50% + translateX(-50%)). */
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;font-size:13px;font-weight:500;padding:12px 19px;border-radius:99px;box-shadow:var(--sh-m);opacity:0;pointer-events:none;transition:.3s var(--ease);z-index:50}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.preview-flag{position:fixed;top:10px;inset-inline-start:10px;font-size:9.5px;font-weight:700;letter-spacing:.04em;color:var(--pink-ink);background:#fff;border:1px solid var(--blush);padding:4px 10px;border-radius:99px;z-index:40;box-shadow:var(--sh-s)}
</style>
</head>
<body>
<div class="deco"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div></div>@endverbatim
<div class="preview-flag">{{ __('store.quiz.preview_flag') }}</div>
<div class="shell">
  <div class="qhead">

    {{-- <h1>, not <div>. /skin-quiz served no heading of any level: every
         heading on this page is written by renderStart() and the step
         renderers into #stage, so they exist only after the script runs and
         none of them is in the served HTML. This is the one heading the page
         has server-side, so it is the one that carries the level. The .brand
         rule keeps the size and weight, so the header looks exactly as it did.

         The verbatim block is closed around this note and reopened after it on
         purpose. Everything from the top of this file to the footer is one
         verbatim region, and a Blade comment inside a verbatim region is not a
         comment at all — it is literal text, and it was being served to every
         visitor as part of the page. --}}

    <h1 class="brand">{!! __('store.quiz.brand_heading', ['suffix' => '<span>' . e(__('store.quiz.brand_suffix')) . '</span>']) !!}</h1>
    <div class="qcount" id="qcount"></div>
  </div>
  <div class="pips" id="pips"></div>
  <div id="stage"></div>
</div>
<div class="toast" id="toast"></div>
{{-- The one real link the results panel has. Url::to() carries the deployment's
     base path (KBB_BASE_PATH on staging), so this cannot be a literal '/shop/'. --}}
@php
    // This page's own string table — see FrontEndStrings::forPrefix() for why
    // the quiz does not share the store.js.* one. Empty on an English page, so
    // no <script> is emitted and the page is byte-for-byte what it was.
    $kbbQuizStrings = \App\Services\Translation\FrontEndStrings::forPrefix('quiz.js_', app()->getLocale());

    // The hand-off to Build my routine, or null. NULL IS THE SHIPPED ANSWER —
    // the module is off by default, /routines 404s in that state, and a quiz
    // that offers a dead link is worse than one that offers none. See
    // App\Support\QuizRoutineLink: null emits no script, no element and not one
    // byte of difference from the page that is live today.
    $kbbQuizRoutines = \App\Support\QuizRoutineLink::map();

    // The hand-off to the concern COLLECTION pages — Lane Q. Also null on the
    // shop as it ships, but for a different reason and one the owner can clear
    // on his own: /concern/{slug}/ exists once a concern has copy and at least
    // ConcernCollections::MIN_PRODUCTS live tagged products. Nothing is tagged
    // here, so this is null, no script is emitted and the page is byte-for-byte
    // what it is today. It is NOT gated on the routine module, because that
    // page is not either.
    $kbbQuizConcernPages = \App\Support\QuizRoutineLink::concernPages();
@endphp
<script>const SHOP_URL = @json(\App\Support\Url::to('/shop/'));</script>
@if ($kbbQuizStrings !== [])
<script>window.KBB_T = @json($kbbQuizStrings);</script>
@endif
@if ($kbbQuizRoutines !== null)
{{-- Emitted exactly the way window.KBB_T is, and absent for a related reason: a
     table the script reads if it is there and does without if it is not. Keyed
     by the quiz's own English concern, so looking one up is a property access
     rather than a mapping table — which is what App\Support\RoutineConcerns was
     built to make possible. --}}
<script>window.KBB_ROUTINES = @json($kbbQuizRoutines);</script>
@endif
@if ($kbbQuizConcernPages !== null)
{{-- The same shape as the table above, and absent in the same way when there is
     nothing to point at. Kept as a SEPARATE table rather than merged into
     KBB_ROUTINES because the two destinations are different pages and get
     different wording: one offers a routine, the other offers a shelf, and a
     button that says "Build my acne routine" over a collection URL would be
     promising something the page does not do. --}}
<script>window.KBB_CONCERN_PAGES = @json($kbbQuizConcernPages);</script>
@endif
@verbatim

<script>
/* The shopper's wording for a key, or the English written beside it.
 *
 * A COPY OF resources/js/kbb/i18n.js's t(), AND IT HAS TO BE. That module is
 * an ES module compiled by Vite into the bundle; this is an inline <script> in
 * a standalone document, so it cannot import it. The contract is the one that
 * matters and it is identical: the English is passed at every call site and is
 * what renders when the table is absent, which on this host is the normal
 * state of affairs rather than an accident — assets are built off-server and
 * the shipped bundle is routinely older than this repository.
 *
 * WHAT IS NOT PASSED THROUGH HERE: every value a shopper PICKS. The skin
 * types, concerns, ages, routine depths, budgets and allergens are compared
 * (`state.concerns.includes('Hydration')`, `/Oily|Combination/.test(...)`) and
 * are POSTed to /api/quiz, where they are kept as the lead's answers.
 * Translating them would break recommend() outright and would write Arabic
 * answers into a table the owner reads in English. Separating those labels
 * from their values is a real change with a persistence contract attached and
 * is written up rather than done here. */
function t(key, english, replace){
  const table = (typeof window !== 'undefined' && window.KBB_T) || null;
  let out = (table && typeof table[key] === 'string' && table[key]) || english;
  if (replace) {
    Object.keys(replace).sort((a,b)=>b.length-a.length).forEach(function(name){
      out = out.split(':' + name).join(String(replace[name]));
    });
  }
  return out;
}
const $=(s,r=document)=>r.querySelector(s);
const stage=$('#stage');
let stepIndex=0;
let RES=[];
const API='';
const state={skin:null,concerns:[],age:null,depth:null,budget:null,allergies:[],allergyNote:'',name:'',phone:'',email:'',expertMsg:'',expertSent:false,leadId:null};

/* pastel palette for option icons / chips */
const PAL=[['#FCE0E8','#C13E63'],['#FFE3D6','#C9602F'],['#FFF1CE','#A9802A'],['#E0F4E7','#2E9E6B'],['#E1ECFB','#3B73B8'],['#ECE2FB','#7C5CD8'],['#FBE0EF','#B23A80'],['#FCE6DB','#D0552E']];

const SKINS=[
  {v:'Dry',d:'Tight, flaky',ic:'<path d="M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z"/>'},
  {v:'Oily',d:'Shiny, large pores',ic:'<circle cx="12" cy="13" r="6"/><path d="M12 3v3"/>'},
  {v:'Combination',d:'Oily T-zone',ic:'<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/>'},
  {v:'Sensitive',d:'Reacts easily',ic:'<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/>'},
  {v:'Normal',d:'Balanced',ic:'<circle cx="12" cy="12" r="9"/><path d="M8 14c1.4 1.4 6.6 1.4 8 0"/>'},
  {v:'Not sure',d:'Help me out',ic:'<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.5 1.5c0 1.5-2 2-2 3M12 17h.01"/>'}
];
const CONCERNS=[
  {v:'Hydration',ic:'<path d="M12 3c4 5 6 8 6 11a6 6 0 0 1-12 0c0-3 2-6 6-11z"/>'},
  {v:'Dark spots & tone',ic:'<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1.2"/><circle cx="15" cy="13" r="1.2"/>'},
  {v:'Acne & blemishes',ic:'<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9" r="1"/><circle cx="14" cy="14" r="1.2"/>'},
  {v:'Fine lines & aging',ic:'<path d="M4 18c4-2 4-12 8-12s4 10 8 12"/>'},
  {v:'Redness & sensitivity',ic:'<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10z"/>'},
  {v:'Pores & oil',ic:'<circle cx="12" cy="12" r="9"/><circle cx="10" cy="9" r=".9"/><circle cx="14" cy="11" r=".9"/><circle cx="11" cy="14" r=".9"/>'},
  {v:'Dullness & glow',ic:'<circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>'},
  {v:'Sun protection',ic:'<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2"/>'}
];
const AGES=['Under 18','18–24','25–34','35–44','45+'];
const DEPTHS=[{v:'Minimal',d:'2–3 steps'},{v:'Balanced',d:'4–5 steps'},{v:'Full ritual',d:'6+ steps'}];
const BUDGETS=[{v:'Best value',d:'Wallet-friendly'},{v:'Mid-range',d:'Smart quality'},{v:'Premium',d:'The best'},{v:'Mix it up',d:'Value + heroes'}];
const ALLERGENS=[
  {v:'Fragrance / parfum'},{v:'Essential oils'},{v:'Alcohol (drying)'},
  {v:'Nut oils'},{v:'Lanolin'},{v:'None / not sure'}
];

/* ── THE ROUTINE IS STEPS, NOT PRODUCTS — Lane FB ────────────────────────────
 *
 * This block used to be `const POOL`: seventeen products with brands and
 * prices, none of which this shop sells. Thirteen of the names existed nowhere
 * else in the repository and two of the brands did not exist at all, so the
 * quiz recommended a basket that could not be bought, quoted a total nobody had
 * set, and printed a 15% bundle saving that no discount rule in this shop has
 * ever offered.
 *
 * WHERE THE INVENTED NAMES ACTUALLY WENT, MEASURED RATHER THAN ASSUMED. An
 * earlier pass at this lane recorded that buildPayload() posted them to
 * /api/quiz and that they were kept in quiz_submissions.recommended_routines.
 * That is NOT what happens, and the correction matters because it changes what
 * anybody has to clean up: nothing.
 *
 * Api\QuizController::store() validates a fixed list of keys and passes only
 * those to create(); `recommended_routines` is not among the columns it writes,
 * and nothing else in the application writes it either. Posting a product list
 * and a bundle total to that endpoint and then reading the row back leaves
 * `recommended_routines` NULL. So the fiction reached the wire and stopped
 * there — it was never persisted, no stored lead carries an invented product
 * name, and no migration or data repair is owed.
 *
 * What the banner genuinely failed to contain was the PAGE: a shopper read
 * seventeen products, their prices and a 15% saving on a public URL, and the
 * word PREVIEW above them does not make a price true.
 *
 * WHAT REPLACES IT, AND WHAT DOES NOT. The quiz can say truthfully which STEPS
 * a skin type and a set of concerns call for, and in what order — that is
 * skincare, not catalogue. It cannot say which product to buy, because nothing
 * on this page reads `products` and no column records which routine step a
 * product belongs to. So it recommends the shape of a routine and sends the
 * shopper to the real shop to fill it.
 *
 * DRIVING IT FROM THE CATALOGUE IS THE NEXT STEP AND NEEDS A SCHEMA CHANGE: a
 * `routine_role` on products (cleanser / toner / treatment / moisturiser / spf),
 * an admin field to set it, and an endpoint to read the visible, in-stock row
 * per role within the shopper's budget. That is a lane of its own and is
 * written up in this lane's report. Nothing here invents a stand-in for it.
 */
/* `n` is the step's ENGLISH name and stays English: buildPayload() sends it to
   /api/quiz as the routine's steps, and a lead whose steps arrive in the
   shopper's language is a lead the owner cannot read beside the others. `k` is
   what the display looks the wording up by. */
const STEPS={
  cleanse:{nk:'store.quiz.js_step_cleanse_name',dk:'store.quiz.js_step_cleanse_desc',n:'Cleanse',d:'Lift off sunscreen, sweat and the day.'},
  cleanseOil:{nk:'store.quiz.js_step_cleanse_oil_name',dk:'store.quiz.js_step_cleanse_oil_desc',n:'Cleanse (oil first)',d:'An oil or balm to break down SPF, then a gentle wash.'},
  tone:{nk:'store.quiz.js_step_tone_name',dk:'store.quiz.js_step_tone_desc',n:'Tone',d:'Rebalance and soften before anything active.'},
  treat:{nk:'store.quiz.js_step_treat_name',dk:'store.quiz.js_step_treat_desc',n:'Treat',d:'The active step for your main concern.'},
  boost:{nk:'store.quiz.js_step_boost_name',dk:'store.quiz.js_step_boost_desc',n:'Boost',d:'A second active for your next concern.'},
  barrier:{nk:'store.quiz.js_step_barrier_name',dk:'store.quiz.js_step_barrier_desc',n:'Barrier',d:'A calming, repairing layer when skin feels reactive.'},
  moisturise:{nk:'store.quiz.js_step_moisturise_name',dk:'store.quiz.js_step_moisturise_desc',n:'Moisturise',d:'Seal the water in so the actives are tolerated.'},
  rich:{nk:'store.quiz.js_step_rich_name',dk:'store.quiz.js_step_rich_desc',n:'Moisturise (richer)',d:'A heavier cream for dry or mature skin.'},
  protect:{nk:'store.quiz.js_step_protect_name',dk:'store.quiz.js_step_protect_desc',n:'Protect',d:'Sunscreen every morning — the UAE sun is the whole game.'},
  mask:{nk:'store.quiz.js_step_mask_name',dk:'store.quiz.js_step_mask_desc',n:'Weekly',d:'A mask once or twice a week, not daily.'},
  eye:{nk:'store.quiz.js_step_eye_name',dk:'store.quiz.js_step_eye_desc',n:'Eye',d:'A lighter formula for the thinner skin around the eye.'}
};

/* Which STEPS this shopper's answers call for. The branches are the same skin
 * logic the old version used to pick products; only what they select changed. */
function recommend(){
  const oily=/Oily|Combination/.test(state.skin)||state.concerns.some(c=>/Pores|Acne/.test(c));
  const dry=/Dry|Sensitive/.test(state.skin)||state.concerns.includes('Hydration');
  const sensitive=/Sensitive|Redness/.test(state.skin)||state.concerns.includes('Redness & sensitivity');
  const aging=state.concerns.includes('Fine lines & aging');
  const cleanse=oily?STEPS.cleanse:STEPS.cleanseOil;
  const cream=dry?(aging?STEPS.rich:STEPS.rich):STEPS.moisturise;
  const second=state.concerns.length>1;

  const essentials=[cleanse,STEPS.treat,cream,STEPS.protect];
  const glass=[cleanse,STEPS.tone,STEPS.treat];
  if(second)glass.push(STEPS.boost);
  glass.push(cream,STEPS.protect);
  const targeted=[STEPS.mask];
  if(aging)targeted.push(STEPS.eye);
  else if(sensitive)targeted.push(STEPS.barrier);
  else if(second)targeted.push(STEPS.boost);
  else targeted.push(STEPS.barrier);

  /* `t` is the routine's ENGLISH name and stays English — buildPayload() sends
     it as the lead's recommended routine and the admin leads screen lists it. */
  return [
    {tk:'store.quiz.js_routine_essentials_title',dk:'store.quiz.js_routine_essentials_desc',gk:'store.quiz.js_routine_essentials_tag',t:'Everyday Essentials',short:'Essentials',tag:'Start here',d:'The core steps for 90% of your goals.',items:essentials},
    {tk:'store.quiz.js_routine_glass_title',dk:'store.quiz.js_routine_glass_desc',gk:'store.quiz.js_routine_glass_tag',t:'Glass-Skin Ritual',short:'Glass-Skin',tag:'Best results',d:'The full layering routine, in order.',items:glass},
    {tk:'store.quiz.js_routine_boosters_title',dk:'store.quiz.js_routine_boosters_desc',gk:'store.quiz.js_routine_boosters_tag',t:'Targeted Boosters',short:'Boosters',tag:'Add-ons',d:'Extra steps for your top concerns.',items:targeted}
  ];
}

const FLOW=['start','skin','concerns','age','depth','budget','allergy','contact','results'];
const QSTEPS=6;
function setProgress(){
  let html='';
  for(let p=1;p<=7;p++){const cls=stepIndex>p?'done':(stepIndex===p?'cur':'');html+=`<div class="pip ${cls}"></div>`;}
  $('#pips').innerHTML=html;
  const s=FLOW[stepIndex];let label='';
  if(s==='start')label=t('store.quiz.js_about_a_minute','~1 min');
  else if(s==='results')label=t('store.quiz.js_your_plan','Your plan ✨');
  else if(s==='contact')label=t('store.quiz.js_last_step','Last step');
  else label=t('store.quiz.js_step_of','Step :n / :total',{n:stepIndex,total:QSTEPS});
  $('#qcount').textContent=label;
}
function go(i){stepIndex=i;render();}
function next(){go(stepIndex+1);}
function prev(){go(stepIndex-1);}

function render(){
  setProgress();
  const s=FLOW[stepIndex];
  if(s==='start')return renderStart();
  if(s==='skin')return renderSingle({key:'skin',eye:t('store.quiz.js_eye_skin','Your skin'),q:t('store.quiz.js_q_skin',"What's your skin type?"),sub:t('store.quiz.js_sub_skin','Pick what sounds most like you.'),opts:SKINS,cols:'cols3'});
  if(s==='concerns')return renderMulti();
  if(s==='age')return renderSingle({key:'age',eye:t('store.quiz.js_eye_about','About you'),q:t('store.quiz.js_q_age','Your age range?'),sub:t('store.quiz.js_sub_age','Helps us pick the right actives.'),opts:AGES.map(a=>({v:a})),cols:'pills'});
  if(s==='depth')return renderSingle({key:'depth',eye:t('store.quiz.js_eye_routine','Your routine'),q:t('store.quiz.js_q_depth','How many steps feel right?'),sub:t('store.quiz.js_sub_depth',"We'll size it to your life."),opts:DEPTHS,cols:'cols3'});
  if(s==='budget')return renderSingle({key:'budget',eye:t('store.quiz.js_eye_routine','Your routine'),q:t('store.quiz.js_q_budget','Your budget vibe?'),sub:t('store.quiz.js_sub_budget','So picks feel right for you.'),opts:BUDGETS,cols:'cols2'});
  if(s==='allergy')return renderAllergy();
  if(s==='contact')return renderContact();
  if(s==='results')return renderResults();
}
function optHTML(o,selected,i){
  const col=PAL[i%PAL.length];
  const ic=o.ic?`<span class="oic" style="background:${col[0]};color:${col[1]}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">${o.ic}</svg></span>`:'';
  const d=o.d?`<div class="od">${o.d}</div>`:'';
  return `<button class="opt${selected?' sel':''}" data-v="${o.v}">${ic}<span><span class="ot">${o.v}</span>${d}</span>
    <span class="check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span></button>`;
}
function applyStagger(){stage.querySelectorAll('.opt').forEach((b,i)=>b.style.animationDelay=(i*32)+'ms');}

function renderSingle({key,eye,q,sub,opts,cols}){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${eye}</span>
    <h2 class="qttl">${q}<small>${sub}</small></h2>
    <div class="opts ${cols}">${opts.map((o,i)=>optHTML(o,state[key]===o.v,i)).join('')}</div>
    <div class="nav"><button class="back" onclick="prev()">${t('store.quiz.js_back','‹ Back')}</button><div class="spacer"></div><span class="hint">${t('store.quiz.js_tap_continue','Tap to continue')}</span></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    state[key]=b.dataset.v;
    stage.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
    b.classList.add('sel');
    setTimeout(next,240);
  });
}
function renderMulti(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${t('store.quiz.js_eye_goals','Your goals')}</span>
    <h2 class="qttl">${t('store.quiz.js_q_goals','What do you want to work on?')}<small>${t('store.quiz.js_sub_goals','Choose up to 3.')}</small></h2>
    <div class="opts cols2">${CONCERNS.map((o,i)=>optHTML(o,state.concerns.includes(o.v),i)).join('')}</div>
    <div class="nav"><button class="back" onclick="prev()">${t('store.quiz.js_back','‹ Back')}</button><div class="spacer"></div>
      <button class="btn" id="mNext" ${state.concerns.length?'':'disabled'} onclick="next()">${t('store.quiz.js_continue','Continue')}</button></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    const v=b.dataset.v, i=state.concerns.indexOf(v);
    if(i>-1)state.concerns.splice(i,1);
    else{if(state.concerns.length>=3){toast(t('store.quiz.js_toast_max_three','Pick up to 3 concerns'));return;}state.concerns.push(v);}
    b.classList.toggle('sel');
    $('#mNext').disabled=!state.concerns.length;
  });
}
function renderAllergy(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${t('store.quiz.js_eye_safety','Safety check')}</span>
    <h2 class="qttl">${t('store.quiz.js_q_allergy','Any allergies or sensitivities?')}<small>${t('store.quiz.js_sub_allergy',"So we steer clear of ingredients that don't agree with you. Optional — pick any that apply.")}</small></h2>
    <div class="opts cols2">${ALLERGENS.map((o,i)=>optHTML({v:o.v,ic:'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.8-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>'},state.allergies.includes(o.v),i)).join('')}</div>
    <div class="field" style="margin-top:13px"><textarea id="iAllergyNote" rows="2" placeholder="${t('store.quiz.js_allergy_placeholder','Anything else we should avoid? (optional)')}">${state.allergyNote}</textarea></div>
    <div class="nav"><button class="back" onclick="prev()">${t('store.quiz.js_back','‹ Back')}</button><div class="spacer"></div>
      <button class="btn" onclick="allergyNext()">${t('store.quiz.js_continue','Continue')}</button></div>
  </div>`;
  applyStagger();
  stage.querySelectorAll('.opt').forEach(b=>b.onclick=()=>{
    const v=b.dataset.v;
    if(/None/.test(v)) state.allergies=state.allergies.includes(v)?[]:[v];
    else{
      state.allergies=state.allergies.filter(x=>!/None/.test(x));
      const i=state.allergies.indexOf(v);
      if(i>-1)state.allergies.splice(i,1); else state.allergies.push(v);
    }
    stage.querySelectorAll('.opt').forEach(x=>x.classList.toggle('sel',state.allergies.includes(x.dataset.v)));
  });
}
function allergyNext(){state.allergyNote=$('#iAllergyNote').value.trim();next();}

function renderContact(){
  stage.innerHTML=`<div class="card">
    <span class="gate-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M22 6 12 13 2 6"/></svg></span>
    <span class="qeye"><span class="dot"></span>${t('store.quiz.js_eye_almost','Almost there')}</span>
    <h2 class="qttl">${t('store.quiz.js_q_contact','Where do we send your routine?')}<small>${t('store.quiz.js_sub_contact',"We'll save your results & email your plan. No spam, ever.")}</small></h2>
    <div class="field" id="fName"><label>${t('store.quiz.js_label_name','Name')}</label><input id="iName" placeholder="${t('store.quiz.js_ph_name','e.g. Fatima')}" value="${state.name}"><div class="msg">${t('store.quiz.js_err_name','Please enter your name')}</div></div>
    <div class="two">
      <div class="field" id="fPhone"><label>${t('store.quiz.js_label_phone','Phone (WhatsApp)')}</label><input id="iPhone" inputmode="tel" placeholder="+971 5X XXX XXXX" value="${state.phone}"><div class="msg">${t('store.quiz.js_err_phone','Enter a valid phone')}</div></div>
      <div class="field" id="fEmail"><label>${t('store.quiz.js_label_email','Email')}</label><input id="iEmail" inputmode="email" placeholder="you@email.com" value="${state.email}"><div class="msg">${t('store.quiz.js_err_email','Enter a valid email')}</div></div>
    </div>
    <div class="trust-row">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg> 10% off your first order</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg> ${t('store.quiz.js_perk_expert','Free expert help')}</span>
    </div>
    <div class="nav"><button class="back" onclick="prev()">${t('store.quiz.js_back','‹ Back')}</button><div class="spacer"></div>
      <button class="btn" onclick="submitContact()">${t('store.quiz.js_see_routine','See my routine →')}</button></div>
  </div>`;
}
async function submitContact(){
  const name=$('#iName').value.trim(), phone=$('#iPhone').value.trim(), email=$('#iEmail').value.trim();
  let ok=true;const setErr=(id,bad)=>{$('#'+id).classList.toggle('err',bad);if(bad)ok=false;};
  setErr('fName',name.length<2);
  setErr('fPhone',phone.replace(/[^\d]/g,'').length<7);
  setErr('fEmail',!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email));
  if(!ok)return;
  state.name=name;state.phone=phone;state.email=email;
  await ensureLead();                 // save the lead to the backend (offline: continues anyway)
  next();
}
async function ensureLead(){
  if(state.leadId) return state.leadId;
  try{
    const payload=buildPayload(); payload.consent=true; payload.source_url=location.pathname;
    const r=await fetch(API+'/api/quiz',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    if(r.ok){ const j=await r.json(); state.leadId=j.id||null; return state.leadId; }
  }catch(e){/* offline — preview still shows results */}
  return null;
}
function buildPayload(){
  return {submittedAt:new Date().toISOString(),skinType:state.skin,concerns:state.concerns.slice(),
    answers:{age:state.age,routineDepth:state.depth,budget:state.budget,allergies:state.allergies.slice(),allergyNote:state.allergyNote},
    contact:{name:state.name,phone:state.phone,email:state.email},
    /* The routine's NAME and its STEPS. A product list and a bundle total used
       to ride along here — products the shop does not sell and a total nobody
       set. Those were never stored, because store() did not write this column
       at all; it does now (Lane FJ), which is what makes the admin leads
       screen's `recommended` cell say anything. Api\QuizController keeps the
       name and the step names and discards the rest of each object, so this
       key cannot carry a product or a total again whatever is posted here.

       WHAT THIS PAYLOAD SENDS AND THE SHOP DOES NOT KEEP: answers.allergies
       and answers.allergyNote. recommend() reads them here, in the browser,
       and they stop at the endpoint — the allergy step offers to steer clear
       of ingredients while the quiz is on screen, it does not say the answer
       is kept on file, and the box beside it is where somebody types
       "pregnant". `status` and `expertRequest` are sent and ignored too: this
       endpoint is public, so neither may be honoured from the body. */
    recommendedRoutines:recommend().map(r=>({name:r.t,steps:r.items.map(s=>s.n)})),
    expertRequest:state.expertSent?{requested:true,message:state.expertMsg}:{requested:false},status:'new'};
}
const TAGCOL=['#E0567B','#BE8E2E','#8B5CF6'];
/* One routine, as an ordered list of steps.
 *
 * No price, no bundle, no saving pill. The footer used to quote a bundle total,
 * a struck-through original and a percentage saved, over a button whose only
 * action was a toast saying the products had been added to the bag — nothing
 * was added, no such products existed, and the percentage was arithmetic on
 * figures that were invented in the first place.
 * What replaces it is the one true thing the page can offer: a way into the
 * shop, where the prices are the shop's own.
 */
function routinePanel(r,idx){
  const cells=r.items.map((step,j)=>{const col=PAL[(idx*2+j)%PAL.length];
    return `<div class="rcell"><span class="rthumb" style="background:${col[0]};color:${col[1]}">${j+1}</span>
      <div class="rcw"><div class="rstep">${t(step.nk,step.n)}</div><div class="rpdesc">${t(step.dk,step.d)}</div></div></div>`;}).join('');
  return `<div class="routine"><div class="routine-h"><div><div class="rt">${t(r.tk,r.t)}</div><div class="rd">${t(r.dk,r.d)}</div></div><span class="rtag" style="background:${TAGCOL[idx%3]}">${t(r.gk,r.tag)}</span></div>
    <div class="rgrid">${cells}</div>
    <div class="routine-f"><div class="rsum">${t('store.quiz.js_step_count',':count steps',{count:r.items.length})}</div>
      <a class="btn sm" href="${SHOP_URL}">${t('store.quiz.js_shop_steps','Shop these steps')}</a></div></div>`;
}
function selRoutine(){}

/* THE WAY OUT OF THE QUIZ AND INTO A ROUTINE — Lane FT.
 *
 * The panels above are the SHAPE of a routine; Lane FM's routine pages
 * fill those steps with products this shop has. The join is a LOOKUP because
 * App\Support\RoutineConcerns holds the quiz's eight concern strings verbatim.
 *
 * window.KBB_ROUTINES IS ABSENT ON THE SHIPPED SHOP — the module is off,
 * /routines 404s, QuizRoutineLink returns null and this returns ''. The whole
 * off-switch is that absence. Looked up in the shopper's OWN order, with no
 * fallback: "Sun protection" must not be handed an acne routine.
 */
function pickFrom(table){
  if(!table) return null;
  for(let i=0;i<state.concerns.length;i++){
    const c=state.concerns[i];
    if(typeof table[c]==='string' && table[c]) return {url:table[c],concern:c};
  }
  return null;
}
function routinePick(){
  return pickFrom((typeof window!=='undefined' && window.KBB_ROUTINES)||null);
}

/* THE SECOND DESTINATION, AND THE ONE THE SHIPPED SHOP CAN ACTUALLY REACH —
 * Lane Q.
 *
 * A routine page is behind the build_my_routine module and that module ships
 * OFF, so routinePick() is null on the shop as applied. (This comment is served
 * to the browser, so it does not spell that page's address: QuizFollowThroughTest
 * asserts the shipped quiz contains no such path anywhere, comments included,
 * and it is right to — a path in a comment is still a path in the page.)
 * The concern collection page is not behind it: it exists the day the concern has copy and enough live
 * tagged products, which is the tagging job the owner does on Catalog -> Build
 * my routine. window.KBB_CONCERN_PAGES is the table for it, absent for exactly
 * as long as no such page exists.
 */
function concernPick(){
  return pickFrom((typeof window!=='undefined' && window.KBB_CONCERN_PAGES)||null);
}

/* Routine first, collection second, nothing third.
 *
 * A routine is a stronger answer than a shelf — it is five named steps filled
 * from this shop's stock — so when both exist the routine wins. The two get
 * DIFFERENT wording because they are different promises, and a button that
 * said "Build my acne routine" over a collection URL would be describing a page
 * the shopper is not about to see. */
function routineLinkHTML(){
  const pick=routinePick();
  /* English, exactly as the chips above are: the concerns are VALUES, not
     labels. The destination page prints the translated name at the far end. */
  if(pick) return `<div class="rtnlink">
    <div class="rl">${t('store.quiz.js_routine_link_lead','A :concern routine, step by step, from what this shop stocks. Steps it stocks nothing for are shown empty rather than filled with a guess.',{concern:pick.concern})}</div>
    <a class="btn sm" href="${pick.url}">${t('store.quiz.js_routine_link_cta','Build my :concern routine →',{concern:pick.concern})}</a>
  </div>`;
  const cpick=concernPick();
  if(!cpick) return '';
  return `<div class="rtnlink">
    <div class="rl">${t('store.quiz.js_concern_link_lead','Everything this shop stocks for :concern, in one place — picked by us, not by a filter.',{concern:cpick.concern})}</div>
    <a class="btn sm" href="${cpick.url}">${t('store.quiz.js_concern_link_cta','Shop :concern →',{concern:cpick.concern})}</a>
  </div>`;
}

function renderResults(){
  const routines=recommend();
  const chips=[state.skin,...state.concerns].map((c,i)=>{const col=PAL[i%PAL.length];return `<span class="res-chip" style="background:${col[0]};color:${col[1]}">${c}</span>`;}).join('');
  const avoid=state.allergies.filter(a=>!/None/.test(a));
  const avoidLine=(avoid.length||state.allergyNote)?`<div class="avoid-note"><b>${t('store.quiz.js_avoiding','Avoiding for you:')}</b> ${avoid.join(', ')}${avoid.length&&state.allergyNote?' · ':''}${state.allergyNote||''}</div>`:'';
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${t('store.quiz.js_eye_results','Your results')}</span>
    <h2 class="qttl"><span class="grad">${t('store.quiz.js_results_title',":name, here's your glow plan",{name:state.name})}</span><small>${t('store.quiz.js_results_sub','Built for your skin & the UAE climate · emailed to :email',{email:state.email})}</small></h2>
    <div class="res-chips">${chips}</div>
    ${avoidLine}
    ${routines.map((r,i)=>routinePanel(r,i)).join('')}
    ${routineLinkHTML()}
    <div class="expert" id="expert">
      <div class="ex-form">
        <h3><span class="eic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M5 21c0-3.5 3-6 7-6s7 2.5 7 6"/></svg></span> ${t('store.quiz.js_expert_heading','Want a human to check it?')}</h3>
        <p>${t('store.quiz.js_expert_body',"Send your quiz to a K-Beauty Bliss skin expert — we'll review your routine and message you on WhatsApp.")}</p>
        <div class="field"><textarea id="iMsg" rows="2" placeholder="${t('store.quiz.js_expert_placeholder','Optional note (allergies, pregnancy, current products…)')}"></textarea></div>
        <div style="margin-top:12px"><button class="btn sm" onclick="sendExpert()">${t('store.quiz.js_expert_send','Send request to skin expert')}</button></div>
      </div>
      <div class="ex-sent"><span class="ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></span>
        <div><div style="font-weight:700;font-size:13.5px">${t('store.quiz.js_expert_sent','Request sent — talk soon!')}</div><div style="font-size:11.5px;color:var(--ink-2)">${t('store.quiz.js_expert_reach','An expert will reach out on :phone.',{phone:state.phone})}</div></div>
      </div>
    </div>
    <details class="beview"><summary><svg class="lk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> ${t('store.quiz.js_backend_preview','What your store saves (backend preview)')}</summary><pre id="bePre"></pre></details>
    <div class="nav" style="justify-content:center"><button class="back" onclick="restart()">${t('store.quiz.js_retake','↺ Retake the quiz')}</button></div>
  </div>`;
  $('#bePre').innerHTML=prettyPayload(buildPayload());
}
async function sendExpert(){
  state.expertMsg=$('#iMsg').value.trim();state.expertSent=true;
  $('#expert').classList.add('sent');
  $('#bePre').innerHTML=prettyPayload(buildPayload());
  const id=await ensureLead();
  if(id){ try{ await fetch(API+'/api/quiz/'+id+'/expert-request',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:state.expertMsg})}); }catch(e){} }
  toast(t('store.quiz.js_toast_expert_sent','Sent to a skin expert ✓'));
}
function prettyPayload(o){return JSON.stringify(o,null,2).replace(/"([^"]+)":/g,'<span class="bk">"$1"</span>:');}
function renderStart(){
  stage.innerHTML=`<div class="card">
    <span class="qeye"><span class="dot"></span>${t('store.quiz.js_start_eye','1-minute skin quiz')}</span>
    <h2 class="qttl"><span class="grad">${t('store.quiz.js_start_title','Find your glow.')}</span><small>${t('store.quiz.js_start_sub','A few quick questions → a personalised Korean routine, matched to your concerns and the UAE climate.')}</small></h2>
    <div class="start-perks">
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> ${t('store.quiz.js_perk_minute','~1 minute')}</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 2.9 6.3 6.9.7-5.1 4.6 1.4 6.8L12 17.8 5.9 20.4l1.4-6.8L2.2 9l6.9-.7z"/></svg> ${t('store.quiz.js_perk_routines','Personalised routines')}</span>
      <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM5 21c0-3.5 3-6 7-6s7 2.5 7 6"/></svg> ${t('store.quiz.js_perk_expert','Free expert help')}</span>
    </div>
    <div style="margin-top:20px"><button class="btn block" onclick="next()">${t('store.quiz.js_start_cta','Start the quiz →')}</button></div>
  </div>`;
}
function restart(){Object.assign(state,{skin:null,concerns:[],age:null,depth:null,budget:null,allergies:[],allergyNote:'',name:'',phone:'',email:'',expertMsg:'',expertSent:false});go(0);}
let toastT;
/* `el`, not `t` — a local named t would shadow the translation helper. */
function toast(m){const el=$('#toast');el.textContent=m;el.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>el.classList.remove('show'),2200);}
window.prev=prev;window.next=next;window.submitContact=submitContact;window.sendExpert=sendExpert;window.restart=restart;window.toast=toast;window.allergyNext=allergyNext;
render();
</script>
</body>
</html>

@endverbatim
