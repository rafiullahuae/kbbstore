{{--
    THE SLIDER'S TEXT BOX -- the stylesheet. Lane HB.

    Included by slider-banner.blade.php ONLY when at least one slide draws a
    box, so a homepage with no words on any picture serves exactly the bytes
    it served before this file existed.

    No measuring, no script: every size is a custom property the server printed
    on the slider from BannerTextBox::cssVariables() -- numbers only -- and the
    one media query at 768px picks the desktop or the phone value. The box is
    positioned inside the slide, which is inside the frame whose height
    `aspect-ratio` already reserved, so it cannot shift the page.

    THE BUTTON RULES ARE `.kbbs .hb-*` (0,2,0) ON PURPOSE: the homepage's own
    `.kbb-home a{color:inherit}` (0,1,1) beat a bare `.hb-fill` and drew the
    Filled button's label in ink on pink -- measured in Chromium, rgb(42,34,40).

    WHERE THE BOX SITS (Lane HB2) is .hb-pos: a flex column spanning the frame
    between its insets, with a spacer above the box and one below. Their
    flex-grow shares out the free height (top 0/1, middle .5/.5, bottom 1/0, a
    custom % is p/1-p) and a custom px is the upper spacer's shrinkable basis,
    so the box can never leave the frame and nothing is measured. Across, the
    box's own width is known CSS, so `clamp(0, offset + room x p, room)` on its
    margin-inline-start keeps it between the column's edges.
    BannerTextBox::positionNumbers() carries the full argument.

    INSIDE THE SITE WIDTH: the header's .wrap is max-width --hd-max (the site
    width, or the header's own number), centred, padded --site-gutter -- and
    --mh-l / --mh-r at 900px and under. The server prints those three values
    onto the slider (--hb-hdmax, --hb-mhl, --hb-mhr, from HeaderSettings and
    MobileHeader, the same methods the header itself uses). The frame is
    centred too, so the logo's edge sits  (frame - header width) / 2 + padding
    in from the frame's edge, and never less than that padding on a full-bleed
    banner -- the max() below, with 100cqi as the frame's width. The two sides
    are separate because the phone header's two paddings can differ.

    NEVER TALLER THAN ITS FRAME (Lane HB3). The owner's preview showed a phone
    box spilling over a short, wide frame. .hb-pos is a size container, so the
    box can take `max-block-size:100%` of the room between the insets: when it
    is short, the short text gives up lines first and the heading after it,
    down to one line (Lane HB4: tight spacing on a 367px desktop frame emptied
    both), as they are the only two that shrink, and when the room is under 120px -- too little for even a
    heading and a button -- the box is not drawn at all. Pure CSS: the browser
    sizes its own boxes; nothing is measured by script.

    SPACING (Lane HB4): the box's padding, the three gaps, D's sticker offset
    and the space above/below the box are --hb-sp-* numbers the server prints
    ONLY when he has moved them; every var() below falls back to the value
    2.60.445 drew, so an untouched banner is pixel-identical. The gaps are
    margins on the element AFTER each pair (the box's own gap is 0); the
    button keeps its old .4-of-a-gap margin when nothing comes before it. A
    space below can come closer to the edge than Auto, but never into the
    slider bars' strip (--hb-room).

    The box is NOT a link and is `pointer-events:none`: a click anywhere on it
    except the button falls through to the picture's own link, which is the
    whole-slide click the slider always had. The button is the one thing in it
    that takes a pointer.
--}}<style>
.kbbs.has-hb .kbbs-s{position:relative}
.kbbs.has-hb{--hb-h:var(--hb-h-m,26px);--hb-t:var(--hb-t-m,14px);--hb-e:var(--hb-e-m,11px);--hb-b:var(--hb-b-m,1);--hb-w:var(--hb-w-m,100%);
  --hb-vg1:var(--hb-vg1-m,1);--hb-vb:var(--hb-vb-m,0px);--hb-vg2:var(--hb-vg2-m,0);--hb-xp:var(--hb-xp-m,0);--hb-xo:var(--hb-xo-m,0px);
  --hb-in:16px;--hb-ins:var(--hb-in);--hb-ine:var(--hb-in);--hb-tb:16px;--hb-bot:16px;--hb-bot0:16px;--hb-o:0px;--hb-g:8px;--hb-lc:3;
  --hb-pt:var(--hb-sp-pt-m,18px);--hb-pb:var(--hb-sp-pb-m,20px);--hb-px:var(--hb-sp-px-m,18px);--hb-geh:var(--hb-sp-geh-m,8px);
  --hb-ght:var(--hb-sp-ght-m,8px);--hb-gtb:var(--hb-sp-gtb-m,11.2px);--hb-su:var(--hb-sp-su-m,28px);--hb-so:var(--hb-sp-so-m,8px);--hb-rm:min(var(--hb-w),100%);
  --hb-gs:var(--site-gutter,22px);--hb-ge:var(--site-gutter,22px);--hb-pads:0px;--hb-pade:0px;
  --hb-site:var(--hb-hdmax,var(--site-max,1680px))}
.kbb-secw-bleed .kbbs.has-hb{--hb-pads:var(--hb-gs);--hb-pade:var(--hb-ge)}
@media (max-width:900px){.kbbs.has-hb{--hb-gs:var(--hb-mhl,12px);--hb-ge:var(--hb-mhr,12px)}}
.kbbs.has-hb.hb-d{--hb-o:var(--hb-su)}
.hb-d .hb-pos{inset-inline-end:max(var(--hb-ine),var(--hb-so) + 4px)}
.kbbs.has-hb.is-bars.is-inset{--hb-bot:66px;--hb-bot0:66px;--hb-room:66px}
.kbbs.has-hb.is-corner.is-bars,.kbbs.has-hb.is-corner.is-arrows{--hb-bot:64px;--hb-bot0:64px;--hb-room:64px}
.hb-pos{position:absolute;z-index:1;inset-block:calc(var(--hb-tb) + var(--hb-o)) var(--hb-bot);inset-inline:var(--hb-ins) var(--hb-ine);
  display:flex;flex-direction:column;justify-content:flex-end;align-items:flex-start;pointer-events:none;container:hbpos / size}
.hb-pos::before{content:"";flex:var(--hb-vg1) 1 var(--hb-vb)}
.hb-pos::after{content:"";flex:var(--hb-vg2) 1 0px}
.hb-box{position:relative;flex:none;max-block-size:100%;inline-size:var(--hb-rm);margin-inline-start:clamp(0px,var(--hb-xo) + (100% - var(--hb-rm)) * var(--hb-xp),100% - var(--hb-rm));
  box-sizing:border-box;display:flex;flex-direction:column;align-items:flex-start;gap:0;pointer-events:none;
  font-family:var(--sans,"Outfit",system-ui,sans-serif);text-align:start}
.hb-box > *{flex-shrink:0}
.hb-box > .hb-h,.hb-box > .hb-t{flex-shrink:1;min-block-size:0}
.hb-box > .hb-h{flex-shrink:.25;min-block-size:1lh}
@container hbpos (max-height:119px){.hb-box{display:none}}
.hb-eb,.hb-h,.hb-t{margin:0;max-inline-size:100%}
.hb-eb{font-size:var(--hb-e);font-weight:600;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hb-h{font-size:var(--hb-h);line-height:1.12;font-weight:600;letter-spacing:-.015em;color:var(--ink,#2A2228);text-wrap:balance}
.hb-t{font-size:var(--hb-t);line-height:1.45;color:var(--ink-2,#5E545A)}
.hb-h,.hb-t{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--hb-lc);overflow:hidden}
html[lang=ar] .hb-h{letter-spacing:0;line-height:1.3}
html[lang=ar] .hb-eb,html[lang=ar] .kbbs .hb-under{letter-spacing:0}
.kbbs .hb-btn{--s:var(--hb-b);pointer-events:auto;position:relative;display:inline-flex;align-items:center;gap:calc(7px * var(--s));margin-top:calc(var(--hb-g) * .4);
  padding:calc(10px * var(--s)) calc(18px * var(--s));border:1.5px solid transparent;border-radius:99px;
  font:600 calc(13.5px * var(--s))/1 var(--sans,"Outfit",system-ui,sans-serif);text-decoration:none;white-space:nowrap;-webkit-user-drag:none}
.hb-eb + .hb-h{margin-top:var(--hb-geh)}
:is(.hb-eb,.hb-h) + .hb-t{margin-top:var(--hb-ght)}
.kbbs :is(.hb-eb,.hb-h,.hb-t) + .hb-btn{margin-top:var(--hb-gtb)}
.kbbs .hb-btn svg{width:calc(15px * var(--s));height:calc(15px * var(--s));flex:none}
[dir=rtl] .kbbs .hb-btn svg{transform:scaleX(-1)}
.kbbs .hb-btn::after{content:"";position:absolute;inset-inline:-6px;top:50%;height:max(100%,44px);transform:translateY(-50%)}
.kbbs .hb-btn:focus-visible{outline:2px solid var(--ink,#2A2228);outline-offset:3px}
.kbbs .hb-fill{background:var(--pink,#C6395F);color:#fff;box-shadow:0 8px 18px -10px rgba(198,57,95,.8)}
.kbbs .hb-outline{border-color:#A82F53;color:#A82F53}
.kbbs .hb-soft{background:#FCE0E8;color:#A82F53}
.kbbs .hb-text{padding-inline:0;border-radius:0;color:#A82F53}
.kbbs .hb-under{padding-inline:0;border-radius:0;color:var(--ink,#2A2228);font-size:calc(12px * var(--s));letter-spacing:.14em;text-transform:uppercase;
  text-decoration:underline 1.5px;text-underline-offset:5px}
.kbbs .hb-grad{background:linear-gradient(90deg,#C6395F,#9E3A8C);color:#fff;box-shadow:0 8px 18px -10px rgba(158,58,140,.8)}
.hb-a .hb-box{padding:var(--hb-pt) var(--hb-px) var(--hb-pb);border-radius:20px;background:rgba(255,243,247,.9);
  -webkit-backdrop-filter:blur(14px) saturate(1.4);backdrop-filter:blur(14px) saturate(1.4)}
.hb-a .hb-eb{display:flex;align-items:center;gap:8px;letter-spacing:.16em;text-transform:uppercase;color:#A82F53}
.hb-a .hb-eb::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--pink,#C6395F);flex:none}
.hb-d .hb-box{padding:var(--hb-pt) var(--hb-px) var(--hb-pb);border-radius:24px;background:#FFFDFE;transform:rotate(-1.2deg)}
.hb-d .hb-h{font-weight:700;letter-spacing:-.02em}
.hb-d .hb-h mark{background:linear-gradient(transparent 58%,#FFD3E2 58%,#FFD3E2 92%,transparent 92%);color:inherit;padding:0 2px}
.hb-d .hb-eb{color:#7A4FA8}
.hb-glow-pastel .hb-box{border:3px solid transparent;
  box-shadow:0 0 22px -4px rgba(247,168,196,.55),0 22px 40px -20px rgba(130,80,150,.55)}
.hb-a.hb-glow-pastel .hb-box{background:linear-gradient(rgba(255,243,247,.9),rgba(255,243,247,.9)) padding-box,linear-gradient(135deg,#F7A8C4,#C9B2F2 50%,#FFCDA3) border-box}
.hb-d.hb-glow-pastel .hb-box{background:linear-gradient(#FFFDFE,#FFFDFE) padding-box,linear-gradient(135deg,#F7A8C4,#C9B2F2 50%,#FFCDA3) border-box}
.hb-glow-white .hb-box{border:1px solid rgba(255,255,255,.85);box-shadow:0 0 18px rgba(255,255,255,.55),0 18px 50px -22px rgba(168,47,83,.5)}
.hb-stk{position:absolute;top:calc(var(--hb-su) * -1);inset-inline-end:calc(var(--hb-so) * -1);width:76px;height:76px;border-radius:50%;display:grid;place-items:center;
  background:linear-gradient(135deg,#FFD3E0,#FFE6CF);border:2px solid #fff;transform:rotate(14deg);box-shadow:0 8px 18px -8px rgba(168,47,83,.55)}
.hb-stk svg{direction:ltr;position:absolute;inset:3px;width:calc(100% - 6px);height:calc(100% - 6px)}
.hb-stk text{fill:#A82F53;font:600 10.5px var(--sans,"Outfit",system-ui,sans-serif);letter-spacing:2px}
html[lang=ar] .hb-stk text{letter-spacing:0;font-size:11px}
.hb-stk b{font-size:16px;font-weight:800;line-height:1;color:#A82F53}
html[lang=ar] .hb-stk b{font-size:13px}
@media (max-width:767.98px){
  .hb-pos.no-m{display:none}
  .kbbs.kbbs.has-hb.hb-sb-m{--hb-bot:max(var(--hb-sp-sb-m),var(--hb-room,0px))}
  .kbbs.kbbs.has-hb.hb-st-m{--hb-tb:var(--hb-sp-st-m)}
  .kbbs.has-hb.hb-site-m{--hb-ins:max(var(--hb-pads),(100cqi - var(--hb-site)) / 2 + var(--hb-gs));--hb-ine:max(var(--hb-pade),(100cqi - var(--hb-site)) / 2 + var(--hb-ge))}
  .hb-hs-m .hb-box.is-end{margin-inline-start:clamp(0px,100% - var(--hb-rm) - var(--hb-xo),100% - var(--hb-rm))}
  .kbbs.has-hb.is-arrows:not(.is-corner) .kbbs-nav{top:34%}
  .kbbs.has-hb.hb-na-m .kbbs-nav{display:none}
}
@media (min-width:768px){
  .kbbs.has-hb{--hb-h:var(--hb-h-d,34px);--hb-t:var(--hb-t-d,15px);--hb-e:var(--hb-e-d,11.5px);--hb-b:var(--hb-b-d,1);--hb-w:var(--hb-w-d,420px);
    --hb-vg1:var(--hb-vg1-d,1);--hb-vb:var(--hb-vb-d,0px);--hb-vg2:var(--hb-vg2-d,0);--hb-xp:var(--hb-xp-d,0);--hb-xo:var(--hb-xo-d,0px);
    --hb-in:clamp(60px,5.5cqi,80px);--hb-tb:clamp(24px,4cqi,52px);--hb-bot:clamp(24px,4cqi,52px);--hb-bot0:clamp(24px,4cqi,52px);--hb-g:10px;--hb-lc:2;
    --hb-pt:var(--hb-sp-pt-d,22px);--hb-pb:var(--hb-sp-pb-d,24px);--hb-px:var(--hb-sp-px-d,28px);--hb-geh:var(--hb-sp-geh-d,10px);
    --hb-ght:var(--hb-sp-ght-d,10px);--hb-gtb:var(--hb-sp-gtb-d,14px);--hb-su:var(--hb-sp-su-d,24px);--hb-so:var(--hb-sp-so-d,28px)}
  .kbbs.has-hb.hb-site-d{--hb-ins:max(var(--hb-pads),(100cqi - var(--hb-site)) / 2 + var(--hb-gs));--hb-ine:max(var(--hb-pade),(100cqi - var(--hb-site)) / 2 + var(--hb-ge))}
  .hb-hs-d .hb-box.is-end{margin-inline-start:clamp(0px,100% - var(--hb-rm) - var(--hb-xo),100% - var(--hb-rm))}
  .hb-d.hb-va-d .hb-pos{inset-block-start:calc(var(--hb-bot0) + 24px)}
  .kbbs.has-hb.is-bars.is-inset{--hb-bot:max(66px,4cqi);--hb-bot0:max(66px,4cqi);--hb-room:66px}
  .kbbs.has-hb.is-corner.is-bars,.kbbs.has-hb.is-corner.is-arrows{--hb-bot:68px;--hb-bot0:68px;--hb-room:68px}
  .kbbs.kbbs.has-hb.hb-sb-d{--hb-bot:max(var(--hb-sp-sb-d),var(--hb-room,0px))}
  .kbbs.kbbs.has-hb.hb-st-d{--hb-tb:var(--hb-sp-st-d)}
  .hb-a .hb-box{border-radius:24px}
  .hb-d .hb-box{transform:rotate(-1.6deg);border-radius:28px}
  .hb-stk{width:88px;height:88px}
  .hb-stk b{font-size:18px}
}
</style>
