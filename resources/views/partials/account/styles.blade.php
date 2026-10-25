{{--
    THE REDESIGNED ACCOUNT AREA'S STYLES (Lane ACC), pushed once into the page's
    `styles` stack by partials.account.chrome, so they ride only on the account
    pages and cost no extra request or render-blocking file anywhere else.

    Class prefix `kac-`: the global stylesheet already claims the short names
    (CLAUDE.md, and the kbbod- comment on the order page). Logical properties
    only (inline-start/end), so /ar mirrors with no second rule set; the two
    chevrons that point somewhere flip under [dir="rtl"]. Inputs are 16px so a
    phone never zooms the page on focus. No layout is measured anywhere: the
    grid and flex rules size everything.
--}}
@once
@push('styles')
<style>
.kac{--kac-g:#2E8F62;--kac-gs:#E3F4EB;--kac-a:#B26A00;--kac-as:#FFF2DC}
.acw.wide.kac{max-width:1040px}
.kac-top{display:flex;align-items:center;gap:10px;margin:0 0 12px}
.kac-tb{flex:none;width:42px;height:42px;border-radius:13px;border:1px solid var(--line-2);background:#fff;display:grid;place-items:center;color:var(--ink);text-decoration:none}
.kac-tb:hover{border-color:var(--pink);color:var(--pink-deep)}
.kac-tb.on{background:var(--pink-soft,#FFF1F5);border-color:var(--pink-soft,#FFF1F5);color:var(--pink-deep)}
[dir="rtl"] .kac-back svg,[dir="rtl"] .kac-chev{transform:scaleX(-1)}
.kac-title{flex:1 1 auto;min-width:0;margin:0;font-size:21px;font-weight:800;letter-spacing:-.01em;line-height:1.2;overflow-wrap:anywhere}
.kac-menu{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 18px}
.kac-out{margin:0}
.kac-trk{margin-bottom:0}
.kac-trk .kac-menu{margin-bottom:4px}
.kac-pill{display:inline-flex;align-items:center;gap:6px;min-height:38px;padding:0 13px;border-radius:999px;background:#fff;border:1px solid var(--line-2);font:inherit;font-size:13px;font-weight:600;color:var(--ink-2);text-decoration:none;cursor:pointer;white-space:nowrap}
.kac-pill:hover{border-color:var(--pink);color:var(--pink-deep)}
.kac-pill.on{background:var(--pink);border-color:var(--pink);color:#fff}
.kac-tb:focus-visible,.kac-pill:focus-visible,.kac-li:focus-visible,.kac-row:focus-visible,.kac-btn:focus-visible,.kac-chip:focus-visible{outline:2px solid var(--pink);outline-offset:2px}
.kac-card{background:#fff;border:1px solid var(--line-2);border-radius:18px;padding:16px}
.kac-h2{display:flex;justify-content:space-between;align-items:baseline;gap:8px;font-size:15px;font-weight:700;margin:22px 0 10px}
.kac-h2 a{font-size:12.5px;color:var(--pink-deep);font-weight:600;text-decoration:none}
.kac-hello{display:flex;gap:12px;align-items:center}
.kac-av{flex:none;width:52px;height:52px;border-radius:50%;background:var(--pink-soft,#FFF1F5);color:var(--pink-deep);display:grid;place-items:center;font-weight:800;font-size:20px}
.kac-hello b{display:block;font-size:17px;overflow-wrap:anywhere}
.kac-hello small{display:block;font-size:12.5px;color:var(--muted);overflow-wrap:anywhere}
.kac-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px}
.kac-stat{background:var(--cream);border-radius:13px;padding:11px 6px;text-align:center;color:var(--ink);text-decoration:none;min-width:0}
.kac-stat b{display:block;font-size:20px;font-variant-numeric:tabular-nums}
.kac-stat span{font-size:11.5px;color:var(--muted)}
.kac-list{background:#fff;border:1px solid var(--line-2);border-radius:18px;overflow:hidden}
.kac-li{display:flex;align-items:center;gap:12px;width:100%;padding:12px 14px;min-height:60px;color:var(--ink);text-decoration:none;border:0;border-top:1px solid var(--line-2);background:none;font:inherit;text-align:start;cursor:pointer}
.kac-list>:first-child,.kac-list>:first-child .kac-li{border-top:0}
.kac-li:hover{background:#FFFAFB}
.kac-ib{flex:none;width:38px;height:38px;border-radius:12px;background:var(--pink-soft,#FFF1F5);color:var(--pink-deep);display:grid;place-items:center}
.kac-ib.g{background:var(--kac-gs);color:var(--kac-g)}
.kac-lt{flex:1 1 auto;min-width:0;font-weight:600;font-size:14px}
.kac-lt small{display:block;font-weight:400;font-size:12px;color:var(--muted)}
.kac-chev{flex:none;color:var(--muted)}
.kac-empty{color:var(--muted);font-size:14px;margin:0}
.kac-empty a{color:var(--pink-deep);font-weight:600}
@media (min-width:900px){.kac-dash{display:grid;grid-template-columns:1.15fr 1fr;gap:22px;align-items:start}.kac-dash>.kac-side>.kac-h2:first-child{margin-top:0}}
/* the four steps */
.kac-steps{list-style:none;margin:14px 0 2px;padding:0;display:grid;grid-template-columns:repeat(4,1fr)}
.kac-steps li{position:relative;display:grid;justify-items:center;align-content:start;gap:4px;text-align:center;font-size:11.5px;font-weight:600;color:var(--muted);min-width:0}
.kac-steps li::before{content:"";position:absolute;top:14px;inset-inline-end:50%;width:100%;height:3px;background:var(--line-2)}
.kac-steps li:first-child::before{display:none}
.kac-steps li.done::before,.kac-steps li.now::before,.kac-steps li.hold::before{background:var(--kac-g)}
.kac-dot{position:relative;z-index:1;width:30px;height:30px;border-radius:50%;background:#fff;border:2px solid var(--line-2);display:grid;place-items:center;color:var(--muted)}
.kac-steps li.done .kac-dot{background:var(--kac-g);border-color:var(--kac-g);color:#fff}
.kac-steps li.now .kac-dot{border-color:var(--kac-g);color:var(--kac-g);box-shadow:0 0 0 5px var(--kac-gs)}
.kac-steps li.hold .kac-dot{border-color:#D99A2B;color:var(--kac-a);box-shadow:0 0 0 5px var(--kac-as)}
.kac-steps li.done,.kac-steps li.now,.kac-steps li.hold{color:var(--ink)}
.kac-steps em{font-style:normal;font-weight:400;font-size:10.5px;color:var(--muted)}
/* order rows: a coloured strip, then photos and total (status header, soft glow) */
.kac-rows{display:grid;gap:24px}
@media (min-width:900px){.kac-rows{grid-template-columns:1fr 1fr;gap:28px}.kac-rows.kac-one{grid-template-columns:1fr}}
.kac-row{display:block;min-width:0;border-radius:16px;overflow:hidden;border:1.5px solid #F1CBD8;color:var(--ink);text-decoration:none;background:linear-gradient(135deg,#FFEFF4 0%,#fff 55%);box-shadow:0 12px 24px -10px rgba(198,57,95,.30),0 3px 6px -2px rgba(42,34,40,.06);transition:box-shadow .15s,transform .15s}
.kac-rows>.kac-row:nth-child(even){background:linear-gradient(135deg,#F3EEFF 0%,#fff 55%)}
@media (min-width:900px){.kac-rows>.kac-row:nth-child(4n+2),.kac-rows>.kac-row:nth-child(4n+3){background:linear-gradient(135deg,#F3EEFF 0%,#fff 55%)}.kac-rows>.kac-row:nth-child(4n),.kac-rows>.kac-row:nth-child(4n+1){background:linear-gradient(135deg,#FFEFF4 0%,#fff 55%)}}
.kac-row:hover{transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(198,57,95,.36),0 3px 6px -2px rgba(42,34,40,.06)}
@media (prefers-reduced-motion:reduce){.kac-row{transition:none}.kac-row:hover{transform:none}}
.kac-band{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 14px;font-size:13px;font-weight:700}
.kac-band>span{display:inline-flex;align-items:center;gap:7px;min-width:0}
.kac-band>span+span{font-weight:500;white-space:nowrap}
.kac-t-pay,.kac-t-hold{background:#FFF4E5;color:var(--kac-a)}
.kac-t-proc{background:#EAF2FB;color:#2F6FB5}
.kac-t-ship{background:#FFF0F4;color:#A82F53}
.kac-t-done{background:#EAF6EF;color:#1F7D52}
.kac-t-off{background:#FDECEF;color:#B3243F}
.kac-rb{padding:12px 14px 14px;display:grid;gap:11px}
.kac-l1,.kac-l3{display:flex;justify-content:space-between;align-items:center;gap:8px;min-width:0}
.kac-no{font-weight:700;font-size:15px;overflow-wrap:anywhere;min-width:0}
.kac-dt{font-size:12.5px;color:var(--muted);white-space:nowrap}
.kac-ph{display:flex;gap:6px;overflow:hidden}
.kac-th{flex:none;width:50px;height:50px;border-radius:11px;background:#fff;border:1px solid var(--line-2);object-fit:contain;display:grid;place-items:center;font-size:11px;font-weight:800;color:#8A7D86}
.kac-more{flex:none;width:50px;height:50px;border-radius:11px;background:var(--pink-soft,#FFF1F5);color:var(--pink-deep);font-weight:800;font-size:13px;display:grid;place-items:center}
.kac-amt{font-weight:800;font-size:15.5px;font-variant-numeric:tabular-nums}
.kac-cnt{font-size:12.5px;color:var(--muted);font-weight:500}
.kac-link{display:inline-flex;align-items:center;gap:2px;font-size:13px;font-weight:700;color:var(--pink-deep);white-space:nowrap}
/* search and dates */
.kac-filter{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;margin:0 0 10px}
.kac-search{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line-2);border-radius:13px;min-height:46px;padding:0 12px;color:var(--muted);min-width:0}
.kac-search:focus-within,.kac-when:focus{border-color:var(--pink)}
.kac-search input{flex:1 1 auto;min-width:0;border:0;outline:0;background:transparent;font:inherit;font-size:16px;color:var(--ink);height:44px;padding:0}
.kac-when{grid-column:1/-1;min-height:46px;border:1px solid var(--line-2);border-radius:13px;background:#fff;font:inherit;font-size:16px;color:var(--ink);padding:0 12px;outline:0;max-width:100%}
.kac-go{min-height:46px;border:0;border-radius:13px;background:var(--pink);color:#fff;font:inherit;font-size:14px;font-weight:700;padding:0 18px;cursor:pointer}
@media (min-width:700px){.kac-filter{grid-template-columns:minmax(0,1fr) 220px auto}.kac-when{grid-column:auto;grid-row:1;grid-column-start:2}.kac-go{grid-row:1;grid-column-start:3}}
.kac-chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px}
.kac-chip{border:1px solid var(--line-2);background:#fff;border-radius:999px;padding:7px 12px;font-size:12.5px;font-weight:600;color:var(--ink-2);text-decoration:none}
.kac-chip.on{background:var(--ink);border-color:var(--ink);color:#fff}
.kac-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:24px}
.kac-page{flex:1 1 auto;text-align:center;font-size:12.5px;color:var(--muted);font-weight:600}
.kac-step{flex:none;border:1px solid var(--line-2);border-radius:12px;padding:10px 15px;font-size:13px;font-weight:700;color:var(--ink-2);background:#fff;text-decoration:none}
.kac-step:hover{border-color:var(--pink);color:var(--pink-deep)}
.kac-step.is-off{opacity:.4;pointer-events:none}
/* one order: the status card and its buttons */
.kac-status{border-radius:18px;overflow:hidden;border:1.5px solid #F1CBD8;background:#fff;box-shadow:0 12px 24px -12px rgba(198,57,95,.25);margin:0 0 16px}
.kac-status .kac-sb{padding:12px 16px 16px}
.kac-meta{font-size:13px;color:var(--muted)}
.kac-meta b{color:var(--ink)}
.kac-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.kac-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:0 16px;border-radius:12px;border:1px solid var(--line-2);background:#fff;color:var(--ink);font:inherit;font-size:13.5px;font-weight:700;text-decoration:none;cursor:pointer}
.kac-btn.p{background:var(--pink);border-color:var(--pink);color:#fff}
.kac-btn:hover{border-color:var(--pink)}
/* profile and password */
.kac-form{display:grid;gap:12px}
.kac-grid2{display:grid;gap:12px}
@media (min-width:700px){.kac-grid2{grid-template-columns:1fr 1fr}}
.kac-f{display:grid;gap:5px;font-size:12.5px;font-weight:600;color:var(--ink-2);min-width:0}
.kac-f input{min-height:46px;border:1px solid var(--line-2);border-radius:12px;background:#fff;font:inherit;font-size:16px;font-weight:400;color:var(--ink);padding:0 12px;outline:0;width:100%;min-width:0}
.kac-f input:focus{border-color:var(--pink)}
.kac-f input[readonly]{background:var(--cream);color:var(--ink-2)}
.kac-note{font-size:12px;color:var(--muted);margin:0}
.kac-err{font-size:12.5px;color:#B3243F;font-weight:600;margin:0}
.kac-ok{display:flex;gap:8px;align-items:center;background:#EAF6EF;color:#1F7D52;border-radius:12px;padding:11px 13px;font-size:13.5px;font-weight:600;margin:0 0 14px}
.kac-card h2{font-size:15.5px;margin:0 0 4px;display:flex;align-items:center;gap:8px}
.kac-stack{display:grid;gap:16px}
@media (min-width:900px){.kac-stack.two{grid-template-columns:1fr 1fr;align-items:start}}
</style>
@endpush
@endonce
