{{--
    The contact page's own rules (Lane CT): one inline <style>, pushed to the
    head only by store/page.blade.php on /contact-us/, so no other page gains a
    byte and no page gains a request. Because this sheet exists on this page
    alone, the few rules below that reach outside .ctc (the article's spacing,
    the float) still touch nothing anywhere else. Colours are kbb.css's tokens;
    the WhatsApp tint is the header support chip's (#E8F7EE / #1F9D55). Grid
    and clamp(), nothing measured by script.

    THE PHONE GRID, BY COUNT AND NOT BY CARD (Lane CT2). Two columns; when
    the number of cards is odd the FIRST one spans the row (the
    :first-child:nth-last-child(odd) selector — CSS, no script, no class).
    Three cards in the shipped order is exactly the grid the owner approved
    (WhatsApp across the top, Instagram and Email side by side); four or six
    are pairs; five is one across and two pairs. Desktop stays three a row,
    so four or more wrap into rows of three. It used to key on
    [data-ct=wa], which stopped being right once the order became his.

    SHORT, because the owner asked: "it's very long, i need short page ... keep
    whatsapp section in one row, and down instagram and email in one row, two
    columns. for desktop keep in one row all three". Compact cards (phone:
    WhatsApp across the top, Instagram and Email side by side; desktop: three
    in a row), tighter text, a compact form, and Follow us + hours as strips.

    THE FIELDS are the checkout's floating-label fields (Lane CD's .fld.kbb-fl,
    kbb-checkout.css "FLOATING LABELS"). That sheet does not load here, so its
    rules are copied with the checkout's own numbers and scoped under .ctc-form:
    1.5px var(--line) border, 12px corners, 19px + --fld-gap(3px) above the
    text and 7px below, 42px inline-start with the icon, the label at 14px from
    the top lifting by 8px + 3px to 10.5px pink-deep, the hint shown only once
    the label has lifted, the pink focus ring (0 0 0 3px var(--pink-soft)), and
    the 16px font floor and 44px minimum below 820px. kbb.css's account `.fld`
    rules also reach these boxes; every one of them is restated here.

    The floating WhatsApp button (#kbbWa, chip and bubble inside it) is hidden
    on this page only: the WhatsApp card is the first thing here, so the float
    was redundant, and at 390px its chip and bubble covered the centre of the
    card buttons and Send at some scroll positions.
--}}
<style>
.ctc{max-width:1080px;margin:0 auto;font-family:var(--sans)}
.kbb-home .policy{padding-top:0;padding-bottom:4px}
.kbb-home .policy h1{margin-bottom:14px}
.policy>.ctc-top{--ctc-w:max(100%,min(1080px,100vw - 60px));width:var(--ctc-w);margin:0 calc((100% - var(--ctc-w)) / 2) 22px;padding:0}
.ctc-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.ctc-card{display:grid;grid-template-columns:40px minmax(0,1fr);gap:1px 12px;align-content:start;padding:16px;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--sh-s);color:var(--ink);text-decoration:none;transition:transform .2s var(--ease),box-shadow .2s var(--ease),border-color .2s}
.ctc-card:hover{transform:translateY(-2px);box-shadow:var(--sh-m);border-color:rgba(198,57,95,.35)}
.ctc-card:focus-visible{outline:3px solid rgba(198,57,95,.45);outline-offset:3px}
.ctc-ic{grid-row:1/4;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:var(--pink-soft);color:var(--pink)}
.ctc-ic svg{width:21px;height:21px}
.ctc-card[data-ct=wa] .ctc-ic{background:#E8F7EE;color:#1F9D55}
.ctc-card[data-ct=email] .ctc-ic{background:#FFF1E4;color:#B0782A}
.ctc-card h3{font-size:16px;font-weight:700;margin:0;letter-spacing:-.01em;line-height:1.3}
.ctc-note{font-size:13px;color:var(--muted);margin:0;line-height:1.35}
.ctc-val{font-size:14px;font-weight:600;margin:2px 0 0;line-height:1.35;overflow-wrap:anywhere;unicode-bidi:isolate}
.ctc-go{grid-column:1/-1;margin-top:12px;display:flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:4px 10px;box-sizing:border-box;border-radius:999px;background:var(--pink-soft);color:var(--pink-ink);font-weight:700;font-size:14px;line-height:1.2;text-align:center}
.ctc-go::after{content:"\2192"}
[dir=rtl] .ctc-go::after{content:"\2190"}
.ctc-card[data-ct=wa] .ctc-go{background:#1F9D55;color:#fff}
.ctc-card:hover .ctc-go{filter:brightness(.97)}
@media (max-width:599px){
.ctc-cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ctc-card{padding:12px;gap:0 10px;grid-template-columns:34px minmax(0,1fr);border-radius:16px}
.ctc-card:first-child:nth-last-child(odd){grid-column:1/-1}
.ctc-ic{width:34px;height:34px;border-radius:10px;grid-row:1/3}
.ctc-ic svg{width:19px;height:19px}
.ctc-card h3{font-size:15px}
.ctc-note{display:none}
.ctc-card:not(:first-child:nth-last-child(odd)) .ctc-ic{grid-row:1}
.ctc-card:not(:first-child:nth-last-child(odd)) h3{align-self:center}
.ctc-card:not(:first-child:nth-last-child(odd)) .ctc-val{grid-column:1/-1;margin-top:8px}
.ctc-val{font-size:12.5px}
.ctc-go{margin-top:10px;font-size:13.5px}
.ctc-card:not(:first-child:nth-last-child(odd)) .ctc-go::after{content:none}
.policy>.ctc-top{margin-bottom:16px}
}
.kbb-home .policy-body{font-size:14.5px;line-height:1.6}
.kbb-home .policy-body p{margin:0 0 8px}
.kbb-home .policy-body h3{font-size:16px;margin:16px 0 4px}
.kbb-home .policy-date{margin-top:10px}
.ctc-low{display:grid;gap:14px;margin:18px auto 4px;align-items:start}
@media (min-width:900px){.ctc-low.has-form.has-side{grid-template-columns:minmax(0,1.7fr) minmax(0,1fr)}}
.ctc-panel{background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:var(--sh-s);padding:clamp(14px,2.4vw,24px)}
.ctc-h{font-size:clamp(19px,1.9vw,23px);font-weight:800;letter-spacing:-.02em;color:var(--ink);margin:0 0 2px}
.ctc-sub{color:var(--muted);font-size:13.5px;margin:0 0 12px}
.ctc-side{display:grid;gap:12px;padding:14px 16px}
.ctc-strip{display:flex;align-items:center;justify-content:space-between;gap:10px 14px;flex-wrap:wrap}
.ctc-strip+.ctc-strip{border-top:1px solid var(--line-2);padding-top:12px}
.ctc-strip h2{font-size:15px;font-weight:700;margin:0;color:var(--ink)}
.ctc-soc{display:flex;flex-wrap:wrap;gap:8px}
.ctc-soc a{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:var(--pink-soft);color:var(--pink);transition:background .2s,color .2s}
.ctc-soc a:hover{background:var(--pink);color:#fff}
.ctc-soc svg{width:20px;height:20px}
.ctc-hours{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:2px 14px;font-size:13.5px;color:var(--ink-2)}
.ctc-hours li{unicode-bidi:isolate}
.ctc-status{border-radius:12px;padding:12px 14px;margin:0 0 12px;font-size:14px;line-height:1.5}
.ctc-status:focus{outline:none}
.ctc-status.ok{background:#E8F7EE;border:1px solid #BFE6CF;color:#14532D}
.ctc-status.bad{background:#FFF0F2;border:1px solid #F7C5CF;color:#9F1239}
.ctc-status strong{display:block;font-size:15px;margin-bottom:2px}
.ctc-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:10px}
@media (min-width:600px){.ctc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ctc-form .form-row.wide{grid-column:1/-1}}
.ctc-form .form-row{margin:0;padding:0;display:block}
.ctc-form .fld.kbb-fl{position:relative;display:block;margin:0}
.ctc-form .fld.kbb-fl :is(input,select,textarea){display:block;width:100%;box-sizing:border-box;font-family:inherit;font-size:14px;font-weight:400;color:var(--ink);background:#fff;border:1.5px solid var(--line);border-radius:12px;height:auto;line-height:1.3;margin:0;padding:calc(19px + var(--fld-gap,3px)) 13px 7px 42px;padding-inline-start:42px;padding-inline-end:13px;outline:0;box-shadow:none;transition:.15s}
.ctc-form .fld.kbb-fl select{appearance:auto;-webkit-appearance:auto}
.ctc-form .fld.kbb-fl textarea{resize:vertical;min-height:104px}
.ctc-form .fld.kbb-fl :is(input,select,textarea):focus{border-color:var(--pink);box-shadow:0 0 0 3px var(--pink-soft)}
.ctc-form .fld.kbb-fl > label{position:absolute;inset-inline-start:43px;top:14px;margin:0;max-width:calc(100% - 58px);font-size:14px;line-height:1.3;font-weight:400;letter-spacing:0;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;transform-origin:left top;transition:transform .16s ease,font-size .16s ease,color .16s ease}
.ctc-form .fld.kbb-fl :is(input,textarea):is(:focus,:not(:placeholder-shown),:-webkit-autofill,:autofill) ~ label,
.ctc-form .fld.kbb-fl select ~ label{transform:translateY(calc(-8px - var(--fld-gap,3px)));font-size:10.5px;letter-spacing:.04em;color:var(--pink-deep)}
.ctc-form .fld.kbb-fl select:has(option[value=""]:checked):not(:focus) ~ label{transform:none;font-size:14px;letter-spacing:0;color:var(--muted)}
.ctc-form .fld.kbb-fl :is(input,textarea)::placeholder{color:var(--muted);opacity:1;font-size:1em}
.ctc-form .fld.kbb-fl :is(input,textarea):not(:focus)::placeholder{color:transparent}
.ctc-form .fld.kbb-fl .lead{position:absolute;inset-inline-start:14px;top:50%;transform:translateY(-50%);display:grid;place-items:center;color:var(--muted);pointer-events:none}
.ctc-form .fld.kbb-fl .lead svg{width:18px;height:18px;display:block}
.ctc-form .fld.kbb-fl:focus-within .lead{color:var(--pink)}
.ctc-form .fld.kbb-fl:has(textarea) .lead{top:16px;transform:none}
.ctc-form .fld.kbb-fl .required{color:var(--pink)}
.ctc-form .fld.kbb-fl .optional{color:var(--muted)}
.ctc-form .form-row.kbb-invalid .fld.kbb-fl > label{color:var(--sale)}
.ctc-form .form-row.kbb-invalid :is(input,select,textarea){border-color:var(--sale);background:#FFF6F6}
@media (max-width:820px){.ctc-form .fld.kbb-fl :is(input,select,textarea){font-size:16px;min-height:44px}}
.ctc-err{display:block;color:#B0182F;font-size:12.5px;margin:4px 0 0}
.ctc-hp{position:absolute;top:0;inset-inline-start:0;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
.ctc-hp input{width:1px}
.ctc-form{position:relative}
.ctc-act{display:flex;align-items:center;flex-wrap:wrap;gap:8px 14px;margin-top:14px}
.ctc-send{height:48px;padding:0 32px;border:0;border-radius:999px;background:linear-gradient(135deg,var(--pink) 0%,var(--pink-deep) 100%);color:#fff;font:inherit;font-size:15.5px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px -12px rgba(198,57,95,.7);transition:transform .2s var(--ease),box-shadow .2s}
.ctc-send:hover{transform:translateY(-1px);box-shadow:0 14px 28px -12px rgba(198,57,95,.8)}
.ctc-send:focus-visible{outline:3px solid rgba(198,57,95,.45);outline-offset:3px}
.ctc-priv{font-size:12px;color:var(--muted)}
@media (max-width:599px){.ctc-send{width:100%}}
@media (prefers-reduced-motion:reduce){.ctc-card,.ctc-soc a,.ctc-send{transition:none}.ctc-card:hover,.ctc-send:hover{transform:none}}
#kbbWa{display:none!important}
</style>
