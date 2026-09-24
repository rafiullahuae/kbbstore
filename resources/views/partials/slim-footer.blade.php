{{--
    THE SLIM FOOTER — one bar, drawn at the foot of the cart page and the
    checkout, from App\Services\SlimFooter.

    ── WHY IT IS A PARTIAL AND NOT TWO COPIES ─────────────────────────────────

    Both pages draw the same bar from the same settings. A second copy would be
    a second place for the support number to be wrong, and the wrong one would
    be whichever page nobody looked at.

    ── EVERY WORD IS A SETTING, AND EMPTY MEANS ABSENT ────────────────────────

    Nothing below is a literal a shopper reads, which is what
    StorefrontStringsAreKeyedTest walks this directory for. An empty setting
    draws NO element rather than an empty one — so a shop that wants a brand
    and a phone number and nothing else gets a bar with a brand and a phone
    number in it, not a bar with four empty gaps.

    ── THE ARROW SCROLLS, IT DOES NOT JUMP ────────────────────────────────────

    `href="#"` would put a fragment in the address bar of a page somebody is
    paying from, and back-button its way into a half-finished order. It is a
    button, and it calls scrollTo with `behavior:'smooth'` — which the browser
    itself turns into an instant jump for a shopper who has asked for reduced
    motion, so there is nothing here to guard.
--}}
@php
    $sf = app(\App\Services\SlimFooter::class);
    $sfC = $sf->all();

    // Drawn once each, rather than asked for four times inside the markup.
    $sfPhoneUrl = $sfC['phone'] !== '' ? $sf->url($sfC['phone_url']) : null;
    $sfMailUrl = $sfC['email'] !== '' ? $sf->url('mailto:'.$sfC['email']) : null;
    $sfL1 = $sfC['l1_text'] !== '' ? $sf->url($sfC['l1_url']) : null;
    $sfL2 = $sfC['l2_text'] !== '' ? $sf->url($sfC['l2_url']) : null;
    $sfL3 = $sfC['l3_text'] !== '' ? $sf->url($sfC['l3_url']) : null;
    $sfMarks = $sf->paymentMarks();

    /* THE HEADER'S OWN WORDMARK, read live rather than copied into a setting
       of its own -- Appearance -> Footer -> "The wordmark". Resolved here so
       the markup below branches on a plain string rather than calling into a
       service inside an @if. */
    $sfWm = $sfC['brand_style'] === 'wordmark' ? $sf->headerLogo() : null;
    $sfHasBrand = $sfWm !== null
        ? ($sfWm['text'] !== '' || $sfWm['accent'] !== '')
        : $sfC['brand'] !== '';
@endphp
<footer class="kbb-slimfoot{{ $sf->bodyClass() }}"{!! $sf->styleAttr() !!}>
    <div class="sf-in">
@if ($sfHasBrand || $sfC['byline'] !== '')
        <div class="sf-brand">
{{-- WHOLE TAGS IN EVERY BRANCH, the same rule the phone and email links keep:
     a <b> whose CONTENTS are interpolated differently per branch would leave
     the scanner in StorefrontStringsAreKeyedTest reading a sentence. The two
     halves of the wordmark are the header's own Wordmark and Accent word, so
     neither is English this file owns. --}}
@if ($sfWm !== null && $sfHasBrand)<b class="sf-wm">{{ $sfWm['text'] }}<span>{{ $sfWm['accent'] }}</span></b>@elseif ($sfHasBrand)<b>{{ $sfC['brand'] }}</b>@endif
@if ($sfC['byline'] !== '')<i>{{ $sfC['byline'] }}</i>@endif
        </div>
@endif
@if ($sfC['help_title'] !== '' || $sfC['help_sub'] !== '')
        <div class="sf-help">
@if ($sfC['help_title'] !== '')<b>{{ $sfC['help_title'] }}</b>@endif
@if ($sfC['help_sub'] !== '')<span>{{ $sfC['help_sub'] }}</span>@endif
        </div>
@endif
{{-- WHOLE TAGS IN EVERY BRANCH, not one tag with its name interpolated.
     `<{{ $url ? 'a href=...' : 'span' }}>` works and reads terribly, and the
     scanner in StorefrontStringsAreKeyedTest -- which strips Blade expressions
     before looking for English -- saw what was left as a sentence. Written out,
     each branch is a tag somebody can read. --}}
@if ($sfC['phone'] !== '' || $sfC['email'] !== '')
        <div class="sf-con">
@if ($sfC['phone'] !== '' && $sfPhoneUrl !== null)
            <a class="sf-c" href="{{ $sfPhoneUrl }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.7 15l-1.2 4.3a.6.6 0 0 0 .7.7L7.2 20.8A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2.9.9 0 0 0-.7-.1l-2.7.8.8-2.6a.9.9 0 0 0-.1-.8A8.2 8.2 0 0 1 12 3.8Zm-3.3 4c-.2 0-.5.1-.7.4-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.7 2.7 4.2 3.7 2.1.8 2.5.7 3 .6.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3-.3-.2-1.5-.8-1.8-.9-.2 0-.4-.1-.5.1-.2.3-.6.9-.8 1-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2-1.2-.8-.7-1.3-1.5-1.4-1.7-.2-.3 0-.4.1-.5l.4-.5c.1-.2.2-.3.2-.5s0-.4-.1-.5c0-.2-.5-1.4-.7-1.9-.2-.4-.4-.4-.5-.4h-.4Z"/></svg><span>{{ $sfC['phone'] }}</span></a>
@elseif ($sfC['phone'] !== '')
            <span class="sf-c"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.7 15l-1.2 4.3a.6.6 0 0 0 .7.7L7.2 20.8A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2.9.9 0 0 0-.7-.1l-2.7.8.8-2.6a.9.9 0 0 0-.1-.8A8.2 8.2 0 0 1 12 3.8Zm-3.3 4c-.2 0-.5.1-.7.4-.3.3-.9.9-.9 2.1s.9 2.4 1 2.6c.1.2 1.7 2.7 4.2 3.7 2.1.8 2.5.7 3 .6.5 0 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3-.3-.2-1.5-.8-1.8-.9-.2 0-.4-.1-.5.1-.2.3-.6.9-.8 1-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2-1.2-.8-.7-1.3-1.5-1.4-1.7-.2-.3 0-.4.1-.5l.4-.5c.1-.2.2-.3.2-.5s0-.4-.1-.5c0-.2-.5-1.4-.7-1.9-.2-.4-.4-.4-.5-.4h-.4Z"/></svg><span>{{ $sfC['phone'] }}</span></span>
@endif
@if ($sfC['email'] !== '' && $sfMailUrl !== null)
            <a class="sf-c" href="{{ $sfMailUrl }}"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5.5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1Zm1.6 1.8L12 12.4l7.4-5.1H4.6Z"/></svg><span>{{ $sfC['email'] }}</span></a>
@elseif ($sfC['email'] !== '')
            <span class="sf-c"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 5.5h18a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-11a1 1 0 0 1 1-1Zm1.6 1.8L12 12.4l7.4-5.1H4.6Z"/></svg><span>{{ $sfC['email'] }}</span></span>
@endif
        </div>
@endif
@if ($sfC['l1_text'] !== '' || $sfC['l2_text'] !== '' || $sfC['l3_text'] !== '')
        <div class="sf-links">
@if ($sfC['l1_text'] !== '' && $sfL1 !== null)<a href="{{ $sfL1 }}">{{ $sfC['l1_text'] }}</a>@elseif ($sfC['l1_text'] !== '')<span>{{ $sfC['l1_text'] }}</span>@endif
@if ($sfC['l2_text'] !== '' && $sfL2 !== null)<a href="{{ $sfL2 }}">{{ $sfC['l2_text'] }}</a>@elseif ($sfC['l2_text'] !== '')<span>{{ $sfC['l2_text'] }}</span>@endif
@if ($sfC['l3_text'] !== '' && $sfL3 !== null)<a href="{{ $sfL3 }}">{{ $sfC['l3_text'] }}</a>@elseif ($sfC['l3_text'] !== '')<span>{{ $sfC['l3_text'] }}</span>@endif
        </div>
@endif
@if ($sfMarks !== [])
        {{-- UNESCAPED, AND SAFE BECAUSE OF WHAT IS ON THE OTHER SIDE.
             App\Support\PaymentMarkArt holds six drawings as a hardcoded
             constant with no setting, no database read and no interpolation in
             it -- its own header explains that an SVG assembled from a setting
             would be a stored-XSS sink on the page orders are placed from. The
             switches on Appearance -> Footer -> Payment marks choose WHICH of
             those constants is printed and can do nothing else. --}}
        <div class="sf-pay" aria-hidden="true">
@foreach ($sfMarks as $sfMark)
            <span class="sf-mk">{!! $sfMark !!}</span>
@endforeach
        </div>
@endif
@if ($sfC['copy'] !== '')
        <div class="sf-copy">{{ $sfC['copy'] }}</div>
@endif
@if ($sfC['top_on'])
        <button type="button" class="sf-top" data-sf-top
@if ($sfC['top_label'] !== '') aria-label="{{ $sfC['top_label'] }}"@else aria-hidden="true" tabindex="-1"@endif>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><path d="m5.5 11.5 6.5-6.5 6.5 6.5"/></svg>
        </button>
@endif
    </div>
</footer>
@once
@push('styles')
<style>
/* ── THE SLIM FOOTER ───────────────────────────────────────────────────────
   One bar, three shapes, and a height that is mostly --sf-pady. Every number
   here is a var() with its own default, so a shop that has never opened
   Appearance -> Footer gets exactly what ships and sends no style attribute.

   Scoped under .kbb-slimfoot and nothing else: it is drawn on two pages whose
   stylesheets have nothing in common, so it cannot inherit its way to
   correctness on either. */
.kbb-slimfoot{
  --sf-pady:12px; --sf-padx:20px; --sf-gap:18px; --sf-f:1; --sf-bf:1;
  /* The gap above the bar is a MARGIN and not padding: padding would be inside
     the bar and would carry the tone with it, so a white bar on a cream page
     would grow a white stripe above itself. A margin leaves the page's own
     ground showing, which is what "space above the footer" means.
     --sf-rowp had no control until now: `rows` derived it from the block gap as
     calc(var(--sf-gap) * .5), so opening the rows meant opening every gap in
     the bar. 9px is exactly what that produced at the shipped gap. */
  --sf-above:0px; --sf-rowp:9px; --sf-rowh:0px;
  --sf-max:1240px; --sf-r:0px; --sf-lw:1px;
  --sf-ink:#17181C; --sf-ink-2:#5E545A; --sf-line:#EBE3E6; --sf-bg:#FBF5F4;
  background:var(--sf-bg);color:var(--sf-ink-2);
  border-top:var(--sf-lw) solid var(--sf-line);
  border-radius:var(--sf-r) var(--sf-r) 0 0;
  font-family:inherit;font-size:calc(12px * var(--sf-f));line-height:1.45;
  /* kbb.css carries a BARE ELEMENT rule -- `footer{background:#241C20;
     color:#CDBFC6;padding:52px 0 26px}` -- for the site footer. A class beats
     an element selector on colour, so the two above were already ours; padding
     was not, and 78px of it arrived uninvited. Measured: the bar rendered
     161px tall against the 82px its own contents needed. Reset here, where
     .sf-in owns every pixel of spacing this footer has. */
  padding:0;margin:0;
  /* ...and then the one margin this bar DOES want, put back deliberately: the
     gap above it. It has to be written after the reset above, not folded into
     it, or a future reader has to work out which half of `margin:0` was the
     landmine and which was the control. */
  margin-block-start:var(--sf-above);
}
.kbb-slimfoot.sf-noline{border-top:0}
.kbb-slimfoot.sf-t-white{--sf-bg:#FFFFFF}
.kbb-slimfoot.sf-t-ink{--sf-bg:#17181C;--sf-ink:#FFFFFF;--sf-ink-2:#C9C1C5;--sf-line:#2C2A2E}
.kbb-slimfoot.sf-t-pink{--sf-bg:#FFF1F5;--sf-line:#F3DCE4}
/* The page's own ground shows through. The rule stays, because with no
   background of its own that line is the only thing separating the bar from
   what is above it. */
.kbb-slimfoot.sf-t-clear{--sf-bg:transparent}
/* Above the bar, not below it: it is the foot of the page, so the only edge
   that has anything to cast onto is the top one. */
.kbb-slimfoot.sf-lift{box-shadow:0 -8px 24px -16px rgba(23,24,28,.45)}

.kbb-slimfoot .sf-in{
  max-width:var(--sf-max);margin:0 auto;
  padding:var(--sf-pady) var(--sf-padx);
  display:flex;align-items:center;flex-wrap:wrap;
  gap:calc(var(--sf-gap) * .55) var(--sf-gap);
  min-width:0;
}
.kbb-slimfoot .sf-in > *{min-width:0}

.kbb-slimfoot b{color:var(--sf-ink);font-weight:700}
.kbb-slimfoot .sf-brand b{
  display:block;font-size:calc(14px * var(--sf-bf));font-weight:800;
  letter-spacing:.02em;text-transform:uppercase;line-height:1.15;
}
.kbb-slimfoot.sf-nocaps .sf-brand b{text-transform:none;letter-spacing:0}
/* ── THE HEADER'S WORDMARK ─────────────────────────────────────────────────
   The same face, weight and tracking the header sets on `.logo` -- 800 at
   -.02em -- and the same two colours, handed in as properties by the service
   from Appearance -> Header rather than written here, so changing the accent
   in one place changes it in both.

   NEVER UPPERCASED, whatever the capitals switch says. That switch exists for
   the typed brand box, which ships in capitals; the header's logo is mixed
   case by design and "K-BEAUTYBLISS" is not the same logo. Both selectors are
   written out rather than relying on source order, so the rule wins whether or
   not sf-nocaps is on the element.

   A <span> AND NOT AN <i> for the accent word, which is also what the header
   writes: the byline is an <i> inside this same block, and `.sf-bar .sf-brand
   i` gives it a 6px inline-start margin -- which on the accent half would open
   a gap in the middle of the logo. */
.kbb-slimfoot .sf-brand b.sf-wm,
.kbb-slimfoot.sf-nocaps .sf-brand b.sf-wm{
  text-transform:none;letter-spacing:-.02em;font-weight:800;
  color:var(--sf-wm-c,#2A2228);
}
.kbb-slimfoot .sf-brand b.sf-wm span{color:var(--sf-wm-a,#E0567B)}
/* The dark tone knocks the first half out to white and keeps the accent, which
   is the only reason a two-tone wordmark survives an ink background at all. */
.kbb-slimfoot.sf-t-ink .sf-brand b.sf-wm{color:#fff}
.kbb-slimfoot.sf-noic .sf-c svg{display:none}
.kbb-slimfoot .sf-copy{flex-basis:100%;font-size:calc(10.5px * var(--sf-f));opacity:.8}
/* The marks are a row of their own drawings; the height comes from the em of
   the block they sit in, which is how PaymentMarkArt sizes every one of them. */
.kbb-slimfoot .sf-pay{display:flex;align-items:center;flex-wrap:wrap;
  gap:calc(var(--sf-gap) * .4);font-size:calc(6.5px * var(--sf-f))}
/* A WHITE CHIP UNDER EVERY MARK, ON EVERY TONE.
   The drawings are scheme artwork in scheme colours, made for a light ground:
   Apple Pay's wordmark is currentColor and Google Pay's is #5F6368, so on the
   dark tone both went nearly invisible -- checked in Chromium. A chip is also
   how a real acceptance row is drawn, so this is the conventional answer
   rather than a patch for one tone. */
.kbb-slimfoot .sf-mk{display:block;line-height:0;background:#fff;border-radius:3px;
  padding:0.45em 0.5em;box-shadow:0 0 0 1px rgba(23,24,28,.08) inset}
.kbb-slimfoot .sf-brand i{display:block;font-style:normal;font-size:calc(10.5px * var(--sf-f));opacity:.85}
.kbb-slimfoot .sf-help{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.kbb-slimfoot .sf-con{display:flex;align-items:center;flex-wrap:wrap;gap:calc(var(--sf-gap) * .5) var(--sf-gap)}
.kbb-slimfoot .sf-c{display:inline-flex;align-items:center;gap:6px;color:inherit;text-decoration:none;min-width:0}
.kbb-slimfoot .sf-c svg{flex:none;width:calc(15px * var(--sf-f));height:calc(15px * var(--sf-f));color:var(--sf-ink)}
.kbb-slimfoot .sf-c span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kbb-slimfoot a:hover{color:var(--sf-ink)}
.kbb-slimfoot .sf-links{display:flex;flex-wrap:wrap;gap:calc(var(--sf-gap) * .5) var(--sf-gap)}
.kbb-slimfoot .sf-links a,.kbb-slimfoot .sf-links span{color:inherit;text-decoration:underline;text-underline-offset:2px}

/* Pushed to the far end whatever the shape, because "back to top" belongs at
   the end of the line the eye is already travelling along. */
.kbb-slimfoot .sf-top{
  margin-inline-start:auto;flex:none;
  width:calc(30px * var(--sf-f));height:calc(30px * var(--sf-f));
  display:grid;place-items:center;border-radius:50%;
  background:transparent;border:1px solid var(--sf-line);color:var(--sf-ink);
  cursor:pointer;transition:.15s;
}
.kbb-slimfoot .sf-top:hover{background:var(--sf-line)}
.kbb-slimfoot .sf-top svg{width:calc(15px * var(--sf-f));height:calc(15px * var(--sf-f))}

/* ── ALIGNMENT ─────────────────────────────────────────────────────────────
   A separate axis from shape, so four structures and four alignments give
   sixteen looks out of two controls rather than sixteen entries in one list.
   `start` is the base rule and has no class of its own. */
.kbb-slimfoot.sf-a-center .sf-in{justify-content:center;text-align:center}
.kbb-slimfoot.sf-a-end .sf-in{justify-content:flex-end}
.kbb-slimfoot.sf-a-between .sf-in{justify-content:space-between}
/* The arrow's auto margin is what pushes it to the far end under `start`, and
   it is exactly what breaks the other three -- it would eat the whole gap
   before justify-content ever got to distribute it. */
.kbb-slimfoot:is(.sf-a-center,.sf-a-end,.sf-a-between) .sf-top{margin-inline-start:0}
.kbb-slimfoot.sf-a-center .sf-con,
.kbb-slimfoot.sf-a-center .sf-links,
.kbb-slimfoot.sf-a-center .sf-pay{justify-content:center}

/* ── THE SEPARATOR ─────────────────────────────────────────────────────────
   Drawn as an ::after on each block but the last, so it lands in the space
   between two blocks. ONLY on the one-line shapes: in `stack` and `rows` the
   blocks are on separate lines and a separator would hang off the end of each
   one. */
.kbb-slimfoot:is(.sf-bar,.sf-split) .sf-in > *:not(:last-child):not(.sf-top)::after{
  margin-inline-start:calc(var(--sf-gap) * .5);opacity:.45;font-weight:400}
.kbb-slimfoot:is(.sf-bar,.sf-split).sf-sep-dot .sf-in > *:not(:last-child):not(.sf-top)::after{content:"·"}
.kbb-slimfoot:is(.sf-bar,.sf-split).sf-sep-pipe .sf-in > *:not(:last-child):not(.sf-top)::after{content:"|"}
.kbb-slimfoot:is(.sf-bar,.sf-split).sf-sep-slash .sf-in > *:not(:last-child):not(.sf-top)::after{content:"/"}

/* ── THE ARROW'S THREE TREATMENTS ──────────────────────────────────────────
   `ring` is the base rule above. */
.kbb-slimfoot.sf-top-solid .sf-top{background:var(--sf-ink);border-color:var(--sf-ink);color:var(--sf-bg)}
.kbb-slimfoot.sf-top-solid .sf-top:hover{opacity:.85;background:var(--sf-ink)}
.kbb-slimfoot.sf-top-plain .sf-top{border-color:transparent;width:auto;height:auto}
.kbb-slimfoot.sf-top-plain .sf-top:hover{background:transparent;opacity:.6}

/* ── THE FOUR SHAPES ───────────────────────────────────────────────────────
   `bar` is the default and the shortest: everything on one row, wrapping only
   when it has to. The other three group the content instead. */
.kbb-slimfoot.sf-bar .sf-brand b{display:inline}
.kbb-slimfoot.sf-bar .sf-brand i{display:inline;margin-inline-start:6px}
.kbb-slimfoot.sf-bar .sf-help b{margin-inline-end:2px}

.kbb-slimfoot.sf-split .sf-in{align-items:flex-start}
.kbb-slimfoot.sf-split .sf-brand{margin-inline-end:auto}
.kbb-slimfoot.sf-split .sf-help,
.kbb-slimfoot.sf-split .sf-con,
.kbb-slimfoot.sf-split .sf-links{align-self:center}

.kbb-slimfoot.sf-stack .sf-in{flex-direction:column;align-items:flex-start}
.kbb-slimfoot.sf-stack .sf-top{margin-inline-start:0;margin-top:calc(var(--sf-gap) * -.35);align-self:flex-end}
.kbb-slimfoot.sf-stack .sf-help{flex-direction:column;align-items:flex-start;gap:1px}

/* `rows`: one block per line with a hairline between, which is the shape that
   reads as a footer rather than as a bar. The rule is on the CHILD and not on
   a divider element, so a block that draws nothing takes its line with it. */
.kbb-slimfoot.sf-rows .sf-in{flex-direction:column;align-items:stretch;gap:0}
.kbb-slimfoot.sf-rows .sf-in > * + *{
  border-top:1px solid var(--sf-line);
  padding-top:var(--sf-rowp);margin-top:var(--sf-rowp);
}
/* The floor applies to EVERY row including the first, which has no top border
   and so never matched the rule above. align-items keeps the words centred in
   a row taller than they are rather than sitting them on its top edge. */
.kbb-slimfoot.sf-rows .sf-in > *{min-height:var(--sf-rowh);display:flex;align-items:center;flex-wrap:wrap}
.kbb-slimfoot.sf-rows .sf-in > .sf-brand{display:block}
.kbb-slimfoot.sf-rows .sf-top{margin-inline-start:0;align-self:flex-end}
.kbb-slimfoot.sf-rows.sf-a-center .sf-in{align-items:center}
.kbb-slimfoot.sf-rows.sf-a-end .sf-in{align-items:flex-end}

/* ── WHATSAPP GREEN ────────────────────────────────────────────────────────
   The glyph was always WhatsApp's; drawn in currentColor at 15px it reads as a
   generic contact mark, which is why "put whatsapp icon beside the phone" was
   asked for a number that already had one. #25D366 is WhatsApp's own and is a
   constant here, not a colour box — the same rule the payment marks follow.
   FIRST CHILD ONLY: the second .sf-c is the email and stays in the bar's ink. */
.kbb-slimfoot.sf-wa .sf-con .sf-c:first-child svg{color:#25D366}

/* ── THE PHONE'S OWN SHAPE ─────────────────────────────────────────────────
   900px and not this file's own 640: the bar is a checkout bar, and the page
   above it calls a phone 900 and below (CheckoutPage::MOBILE_MAX). Two screens
   disagreeing about where a phone stops is how an owner gets a footer in one
   shape under a page in the other.

   EVERY RULE IS GATED ON .sf-msplit, which the service emits only while the
   switch is on. With it off there is no class here to match and the bar renders
   from the desktop rules alone, exactly as it did before this block existed.

   The shape rules are the four above restated under the mobile class rather
   than shared, because sharing them would mean a selector list that reads
   `.sf-rows, .sf-msplit.sf-m-rows` on every line — and the first half of that
   would then apply at every width, which is the bug this whole block exists to
   avoid. */
@media (max-width:900px){
  .kbb-slimfoot.sf-msplit{
    --sf-pady:var(--sf-m-pady,10px);
    --sf-padx:var(--sf-m-padx,20px);
    --sf-gap:var(--sf-m-gap,14px);
    --sf-f:var(--sf-m-f,1);
    --sf-above:var(--sf-m-above,0px);
    --sf-rowp:var(--sf-m-rowp,7px);
    --sf-rowh:var(--sf-m-rowh,0px);
  }

  /* Alignment. `start` has no class, so these three are the departures. */
  .kbb-slimfoot.sf-msplit .sf-in{justify-content:flex-start;text-align:start}
  .kbb-slimfoot.sf-msplit .sf-top{margin-inline-start:auto}
  .kbb-slimfoot.sf-msplit.sf-ma-center .sf-in{justify-content:center;text-align:center}
  .kbb-slimfoot.sf-msplit.sf-ma-end .sf-in{justify-content:flex-end}
  .kbb-slimfoot.sf-msplit.sf-ma-between .sf-in{justify-content:space-between}
  .kbb-slimfoot.sf-msplit:is(.sf-ma-center,.sf-ma-end,.sf-ma-between) .sf-top{margin-inline-start:0}
  .kbb-slimfoot.sf-msplit.sf-ma-center .sf-con,
  .kbb-slimfoot.sf-msplit.sf-ma-center .sf-links,
  .kbb-slimfoot.sf-msplit.sf-ma-center .sf-pay{justify-content:center}

  /* Shape. Each one first undoes what the desktop shape did, then states its
     own — a phone set to `bar` under a desktop set to `rows` must not keep the
     hairlines. */
  .kbb-slimfoot.sf-msplit .sf-in{flex-direction:row;align-items:center;gap:calc(var(--sf-gap) * .5) var(--sf-gap)}
  .kbb-slimfoot.sf-msplit .sf-in > * + *{border-top:0;padding-top:0;margin-top:0}
  .kbb-slimfoot.sf-msplit .sf-brand{margin-inline-end:0}

  .kbb-slimfoot.sf-msplit.sf-m-bar .sf-brand b{display:inline}
  .kbb-slimfoot.sf-msplit.sf-m-bar .sf-brand i{display:block;margin-inline-start:0}
  .kbb-slimfoot.sf-msplit.sf-m-bar .sf-help b{margin-inline-end:2px}

  .kbb-slimfoot.sf-msplit.sf-m-split .sf-in{align-items:flex-start}
  .kbb-slimfoot.sf-msplit.sf-m-split .sf-brand{margin-inline-end:auto}
  .kbb-slimfoot.sf-msplit.sf-m-split .sf-help,
  .kbb-slimfoot.sf-msplit.sf-m-split .sf-con,
  .kbb-slimfoot.sf-msplit.sf-m-split .sf-links{align-self:center}

  .kbb-slimfoot.sf-msplit.sf-m-stack .sf-in{flex-direction:column;align-items:flex-start}
  .kbb-slimfoot.sf-msplit.sf-m-stack .sf-top{margin-inline-start:0;margin-top:calc(var(--sf-gap) * -.35);align-self:flex-end}
  .kbb-slimfoot.sf-msplit.sf-m-stack .sf-help{flex-direction:column;align-items:flex-start;gap:1px}

  .kbb-slimfoot.sf-msplit.sf-m-rows .sf-in{flex-direction:column;align-items:stretch;gap:0}
  .kbb-slimfoot.sf-msplit.sf-m-rows .sf-in > * + *{
    border-top:1px solid var(--sf-line);
    padding-top:var(--sf-rowp);margin-top:var(--sf-rowp);
  }
  /* The floor on every row including the first, which the `* + *` rule above
     cannot reach. --sf-rowp and --sf-rowh are already the phone's own values
     here: the .sf-msplit block at the top of this media query reassigns both. */
  .kbb-slimfoot.sf-msplit.sf-m-rows .sf-in > *{min-height:var(--sf-rowh);display:flex;align-items:center;flex-wrap:wrap}
  .kbb-slimfoot.sf-msplit.sf-m-rows .sf-in > .sf-brand{display:block}
  .kbb-slimfoot.sf-msplit.sf-m-rows .sf-top{margin-inline-start:0;align-self:flex-end}
  .kbb-slimfoot.sf-msplit.sf-m-rows.sf-ma-center .sf-in{align-items:center}
  .kbb-slimfoot.sf-msplit.sf-m-rows.sf-ma-end .sf-in{align-items:flex-end}

  /* The separator belongs to the one-line shapes only, here as above. */
  .kbb-slimfoot.sf-msplit:not(.sf-m-bar):not(.sf-m-split) .sf-in > *::after{content:none}
}

/* A phone is 390px and the bar has five blocks in it. Wrapping is the point;
   what must not happen is a bar that makes the page scroll sideways. */
@media (max-width:640px){
  .kbb-slimfoot .sf-in{align-items:flex-start}
  .kbb-slimfoot .sf-top{align-self:center}
  .kbb-slimfoot.sf-bar .sf-brand i{display:block;margin-inline-start:0}
}
</style>
@endpush
@push('scripts')
<script>
/* ONE DELEGATED LISTENER, and it is the whole of this footer's JavaScript.
   Bound to document rather than to the button because the cart page replaces
   whole regions of itself on every quantity change, and a handler bound to an
   element would go with the element. */
document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-sf-top]')) return;
    window.scrollTo({ top: 0, behavior: 'smooth' });
});
</script>
@endpush
@endonce
