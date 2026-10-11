{{-- Lane RD · review-showcase PREVIEW. Shared by every design letter: tokens
     (the shop's own, through var() with its own values as fallbacks), the star
     row, the verified mark, the reviewer line, the product chip, carousel
     buttons. Printed ONCE per page. Not wired into any live page. --}}
<style>
.rsx{--rs-gold:var(--amber,#E2A13B);--rs-ink:var(--ink,#2A2228);--rs-mut:var(--muted,#756C74);--rs-line:var(--line,rgba(42,34,40,.1));--rs-pink:var(--pink,#C6395F);--rs-soft:var(--pink-soft,#FFF0F4);--rs-cream:var(--cream,#FFF8F5);--rs-ok:var(--kbb-save,#1F7A50);color:var(--rs-ink)}
.rsx *{box-sizing:border-box}
.rsx a{color:inherit;text-decoration:none}
.rs-eb{margin:0 0 6px;font-size:11.5px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--rs-pink)}
.rs-vh{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.rs-st{position:relative;display:inline-block;vertical-align:middle;line-height:1;letter-spacing:.1em;color:#E8DFE3;white-space:nowrap;font-family:system-ui,sans-serif}
.rs-st::before,.rs-st i::before{content:"★★★★★"}
.rs-st i{position:absolute;inset-block-start:0;inset-inline-start:0;width:calc(var(--v) * 20%);overflow:hidden;color:var(--rs-gold);font-style:normal}
.rs-vf{display:inline-flex;align-items:center;gap:4px;color:var(--rs-ok);font-size:11.5px;font-weight:600;white-space:nowrap}
.rs-vf svg{width:13px;height:13px;flex:none}
.rs-who{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;font-size:12.5px;color:var(--rs-mut);min-width:0}
.rs-who b{color:var(--rs-ink);font-weight:600}
.rs-av{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:var(--rs-soft);color:var(--pink-ink,#A82F53);font-weight:700;font-size:13px;flex:none}
.rs-pr{display:flex;align-items:center;gap:10px;min-width:0}
.rs-pr img,.rs-pr .rs-ph0{width:44px;height:44px;border-radius:10px;object-fit:cover;background:#F6F1F3;flex:none}
.rs-pr span{display:block;min-width:0}
.rs-pr small{display:block;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--rs-mut)}
.rs-pr em{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;font-style:normal;font-size:12.5px;font-weight:600;line-height:1.35}
a.rs-pr:hover em{color:var(--rs-pink)}
.rs-all{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13.5px;color:var(--rs-ink);border-bottom:1.5px solid currentColor;padding-block:2px;white-space:nowrap}
.rs-all:hover{color:var(--rs-pink)}
.rs-all svg,.rs-btn svg{width:16px;height:16px}
[dir=rtl] .rs-all svg,[dir=rtl] .rs-btn svg{transform:scaleX(-1)}
.rs-btn{width:42px;height:42px;border-radius:50%;border:1px solid var(--rs-line);background:#fff;color:var(--rs-ink);display:inline-grid;place-items:center;cursor:pointer;transition:border-color .2s,background .2s;padding:0;flex:none}
.rs-btn:hover{border-color:var(--rs-ink)}
.rs-btn:focus-visible,.rs-dots button:focus-visible,.rsx a:focus-visible,.rs-trk:focus-visible{outline:2px solid var(--rs-pink);outline-offset:2px}
.rs-dots{display:flex;justify-content:center;gap:2px}
.rs-dots button{width:22px;height:24px;border:0;background:none;padding:0;cursor:pointer;display:grid;place-items:center}
.rs-dots button::before{content:"";width:7px;height:7px;border-radius:99px;background:#E3D8DD;transition:width .25s,background .25s}
.rs-dots button.in::before{background:#C2B5BC}
.rs-dots button[aria-current=true]::before{width:18px;background:var(--rs-ink)}
.rs-trk{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;overscroll-behavior-x:contain}
.rs-trk::-webkit-scrollbar{display:none}
.rs-trk>*{scroll-snap-align:start;flex:none}
.rs-pp svg{width:14px;height:14px}
.rs-pp .p1,.rs-pp[aria-pressed=true] .p0{display:none}
.rs-pp[aria-pressed=true] .p1{display:block}
@media (prefers-reduced-motion:reduce){.rs-trk{scroll-behavior:auto}.rs-pp{display:none}}
</style>
