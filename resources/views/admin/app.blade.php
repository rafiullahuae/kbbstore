@verbatim
<!DOCTYPE html>
<html lang="en" data-env="live">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>K-Beauty Bliss — Admin · Phase 0 Foundation</title>
<style>
:root{
  --bg:#f6f7fb;--surface:#fff;--surface-2:#f2f4fb;--surface-3:#eef1f9;--border:#e6e9f2;--border-2:#eef0f6;
  --ink:#101729;--ink-2:#3c465c;--ink-soft:#626c80;--ink-faint:#97a0b2;
  --accent:#15a85a;--accent-strong:#0f8f4b;--accent-soft:#e7f7ee;--accent-ink:#0b6e3a;
  --green:#15a85a;--amber:#e0922f;--red:#e3493f;--blue:#3f6fe0;--violet:#7b6cf0;
  --amber-soft:#fdf2e2;--red-soft:#fdeceb;--blue-soft:#eaf0fd;--violet-soft:#efedfd;
  --m1:16,168,90;--m2:20,190,180;--m3:99,128,255;
  --r:18px;--r-sm:12px;--r-xs:9px;
  --sh-s:0 1px 2px rgba(16,24,40,.05);
  --sh:0 1px 2px rgba(16,24,40,.05),0 14px 32px -16px rgba(16,24,40,.16);
  --sh-l:0 24px 60px -22px rgba(16,24,40,.30);
  --sans:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  --ease:cubic-bezier(.22,.61,.36,1);
}
/* ===== admin console themes ===== */
:root[data-theme="indigo"]{--accent:#4f63e0;--accent-strong:#3a4cc4;--accent-soft:#ebedfd;--accent-ink:#2c3aa0;--m1:99,128,255;--m2:120,110,240;--m3:60,150,255}
:root[data-theme="rose"]{--accent:#e0567b;--accent-strong:#c13e63;--accent-soft:#fce6ee;--accent-ink:#a82f53;--m1:224,86,123;--m2:240,136,78;--m3:160,108,240}
:root[data-theme="slate"]{--accent:#4b5a72;--accent-strong:#374255;--accent-soft:#eef1f6;--accent-ink:#2f3a4c;--m1:99,116,140;--m2:120,140,170;--m3:90,110,150}
:root[data-theme="midnight"]{
  --bg:#0c1120;--surface:#141a2b;--surface-2:#1b2235;--surface-3:#232c42;--border:#28324b;--border-2:#1f2840;
  --ink:#eef1f9;--ink-2:#c6cee0;--ink-soft:#9aa5bd;--ink-faint:#6f7b96;
  --accent:#22c06c;--accent-strong:#16a85a;--accent-soft:#10301f;--accent-ink:#7fe3a8;
  --green:#22c06c;--amber:#e9a64a;--red:#ef5e54;--blue:#5b82ef;--violet:#937ff5;
  --amber-soft:#33260f;--red-soft:#341a18;--blue-soft:#16213f;--violet-soft:#211c40;
  --sh-s:0 1px 2px rgba(0,0,0,.35);--sh:0 1px 2px rgba(0,0,0,.35),0 14px 32px -16px rgba(0,0,0,.55);--sh-l:0 24px 60px -22px rgba(0,0,0,.6)
}
:root[data-theme="midnight"] body{background:radial-gradient(120% 80% at 50% -10%,#121a2e 0%,#0c1120 55%,#080c17 100%)}
:root[data-theme="midnight"] .side{background:rgba(16,21,36,.74)}
:root[data-theme="midnight"] .top{background:rgba(16,21,36,.6)}
:root[data-theme="midnight"] .toast{background:#1f2740}
:root[data-theme="midnight"] .iconbtn,:root[data-theme="midnight"] .userchip,:root[data-theme="midnight"] .btn.ghost,:root[data-theme="midnight"] .flag,:root[data-theme="midnight"] .envtog button.on{background:var(--surface)}
:root[data-theme="midnight"] .tog{background:#3a4566}
:root[data-theme="midnight"] .pill.amber{color:#f0b86a}
:root[data-theme="midnight"] .pill.red{color:#f59289}
:root[data-theme="midnight"] .pill.blue{color:#93acf5}
:root[data-theme="midnight"] .sev.err{color:#f59289}
:root[data-theme="midnight"] .sev.warn{color:#f0b86a}
:root[data-theme="midnight"] .banner{color:#93acf5}
:root[data-theme="midnight"] .envcard.live{background:linear-gradient(180deg,#11251a,#141a2b);border-color:#1f5538}
:root[data-theme="midnight"] .envcard.sand{background:linear-gradient(180deg,#2a2113,#141a2b);border-color:#5a4420}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{height:100%}
body{font-family:var(--sans);color:var(--ink);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;
  background:radial-gradient(120% 80% at 50% -10%,#fff 0%,var(--bg) 55%,#eef1f9 100%);overflow:hidden}
.mesh{position:fixed;inset:-20%;z-index:-1;pointer-events:none;filter:blur(70px) saturate(120%);opacity:.7}
.mesh::before,.mesh::after{content:"";position:absolute;inset:0}
.mesh::before{background:
  radial-gradient(34% 34% at 12% 14%,rgba(var(--m1),.26),transparent 70%),
  radial-gradient(30% 30% at 88% 8%,rgba(var(--m2),.22),transparent 70%)}
.mesh::after{background:
  radial-gradient(34% 38% at 78% 92%,rgba(var(--m3),.18),transparent 70%),
  radial-gradient(28% 28% at 30% 86%,rgba(var(--m1),.16),transparent 70%)}
button{font-family:inherit;cursor:pointer;border:0;background:none;color:inherit}
svg{display:block}
::-webkit-scrollbar{width:10px;height:10px}
::-webkit-scrollbar-thumb{background:#d6dbe7;border-radius:99px;border:3px solid transparent;background-clip:content-box}
::-webkit-scrollbar-thumb:hover{background:#c3cad9;background-clip:content-box}

/* ---------- layout ---------- */
.app{display:grid;grid-template-columns:248px 1fr;height:100vh}
.side{background:rgba(255,255,255,.72);backdrop-filter:blur(14px);border-right:1px solid var(--border);
  display:flex;flex-direction:column;min-height:0}
.brand{display:flex;align-items:center;gap:11px;padding:18px 18px 14px}
.logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-strong));
  color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;box-shadow:0 6px 14px -5px rgba(21,168,90,.6)}
.brand b{font-size:14.5px;font-weight:700;letter-spacing:-.01em;line-height:1.1}
.brand small{display:block;font-size:10.5px;color:var(--ink-soft);font-weight:500}
.nav{flex:1;overflow-y:auto;padding:6px 12px 18px;display:flex;flex-direction:column}
/* Core Updates is the last NAV group and is pinned to the bottom, so it sits
   directly above the Console button rather than floating mid-sidebar. */
/* The final nav entry is pushed to the bottom of the flex column so it sits
   directly above the pinned Console button instead of floating mid-list.
   flex-shrink:0 stops it collapsing when the menu is long enough to scroll. */
.nav .nav-pinned{margin-top:auto!important;flex-shrink:0;border-top:1px solid var(--border);
  padding-top:14px;margin-bottom:2px}
.nav-sec{margin-top:14px}
.nav-sec h6{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink-faint);
  padding:0 10px 6px}
/* collapsible module groups: closed by default, open on hover, pinned open when active */
.nav-group{margin-top:4px}
.nav-gh{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;font-size:13px;font-weight:600;color:var(--ink-2);width:100%;text-align:left;cursor:pointer;transition:.14s var(--ease)}
.nav-gh:hover{background:var(--surface-2);color:var(--ink)}
.nav-gh .gh-name{flex:1}
.nav-gh .chev{width:13px;height:13px;color:var(--ink-soft);transition:transform .22s var(--ease);flex-shrink:0}
.nav-gh .gh-badge{font-size:10px;font-weight:700;background:var(--accent);color:#fff;border-radius:99px;padding:1px 7px;min-width:18px;text-align:center}
.nav-sub{overflow:hidden;max-height:0;transition:max-height .34s var(--ease)}
/* 560px fitted about 15 rows. Store has 20, so Business Details, Customers,
   Quiz Leads and Content & Pages were cut off by overflow:hidden with no way
   to reach them -- the group looked complete and simply ended at Media
   Library. Raised well clear of the longest group so new entries do not
   silently disappear the same way.

   max-height has to stay a fixed number for the transition to animate, so
   this is headroom rather than a true fit. The outer .nav already scrolls
   (flex:1 + overflow-y:auto), which is what carries the overflow once a group
   is taller than the sidebar. */
.nav-group:hover .nav-sub,.nav-group.open .nav-sub{max-height:1600px}
.nav-group:hover .chev,.nav-group.open .chev{transform:rotate(90deg)}
.nav-group.open .nav-gh{color:var(--ink)}
.nav-group.open .gh-badge,.nav-group:hover .gh-badge{display:none}
.nav-sub .nav-item{padding-left:14px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;font-size:13.5px;
  font-weight:500;color:var(--ink-2);transition:.14s var(--ease);width:100%;text-align:left;position:relative}
.nav-item svg{width:18px;height:18px;flex-shrink:0;color:var(--ink-soft);transition:.14s}
.nav-item:hover{background:var(--surface-2);color:var(--ink)}
.nav-item.on{background:var(--accent-soft);color:var(--accent-ink);font-weight:600}
.nav-item.on svg{color:var(--accent)}
.nav-item .tag{margin-left:auto;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:99px;background:var(--surface-3);color:var(--ink-soft)}
.nav-item.locked{opacity:.62}
.nav-item .cnt{margin-left:auto;font-size:10.5px;font-weight:700;background:var(--accent);color:#fff;border-radius:99px;padding:1px 7px;min-width:18px;text-align:center}
.side-foot{padding:12px 16px;border-top:1px solid var(--border);font-size:11px;color:var(--ink-faint)}

.main{display:flex;flex-direction:column;min-width:0;min-height:0}
.top{display:flex;align-items:center;gap:14px;padding:14px 24px;border-bottom:1px solid var(--border);
  background:rgba(255,255,255,.6);backdrop-filter:blur(10px)}
.top h1{font-size:17px;font-weight:700;letter-spacing:-.01em}
.top .crumb{font-size:11.5px;color:var(--ink-soft);font-weight:500;margin-bottom:1px}
.top .sp{flex:1}
.envtog{display:flex;background:var(--surface-2);border:1px solid var(--border);border-radius:99px;padding:3px;gap:2px}
.envtog button{font-size:12px;font-weight:600;color:var(--ink-soft);padding:6px 13px;border-radius:99px;display:flex;align-items:center;gap:6px;transition:.16s}
.envtog button .d{width:7px;height:7px;border-radius:50%;background:currentColor}
.envtog button.on[data-e="live"]{background:#fff;color:var(--accent-ink);box-shadow:var(--sh-s)}
.envtog button.on[data-e="sandbox"]{background:#fff;color:var(--amber);box-shadow:var(--sh-s)}
.iconbtn{width:38px;height:38px;border-radius:11px;border:1px solid var(--border);background:#fff;display:grid;place-items:center;color:var(--ink-soft);transition:.15s;position:relative}
.iconbtn:hover{color:var(--ink);border-color:#d6dbe7}
.iconbtn svg{width:18px;height:18px}
.dciconbox svg{width:20px;height:20px}
.dcokicon svg{width:13px;height:13px}
.dcgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media(max-width:1000px){.dcgrid{grid-template-columns:1fr 1fr}}
.iconbtn .dot{position:absolute;top:8px;right:9px;width:7px;height:7px;border-radius:50%;background:var(--red);border:2px solid #fff}
.userchip{display:flex;align-items:center;gap:9px;padding:5px 7px 5px 5px;border:1px solid var(--border);border-radius:99px;background:#fff}
.avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#7b6cf0,#3f6fe0);color:#fff;display:grid;place-items:center;font-size:12px;font-weight:700}
.userchip b{font-size:12.5px;font-weight:600}
.userchip small{font-size:10.5px;color:var(--ink-soft);display:block;line-height:1}

.envbar{display:none;align-items:center;gap:9px;padding:8px 24px;background:var(--amber-soft);border-bottom:1px solid #f3dcb6;font-size:12.5px;color:#8a5a14;font-weight:500}
body[data-env="sandbox"] .envbar{display:flex}
.envbar svg{width:15px;height:15px}

.content{flex:1;overflow-y:auto;padding:24px;min-height:0}
.view{display:none;animation:fade .3s var(--ease)}
.view.on{display:block}
@keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* ---------- components ---------- */
.row{display:flex;align-items:center;gap:12px}
.between{display:flex;align-items:center;justify-content:space-between;gap:12px}
.wrap{max-width:1180px}
.page-head{margin-bottom:18px}
.page-head h2{font-size:20px;font-weight:700;letter-spacing:-.015em}
.page-head p{font-size:13px;color:var(--ink-soft);margin-top:3px;max-width:680px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--sh-s)}
.card.pad{padding:20px}
.odcard .pad{padding:20px 22px}
.sec-title{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-faint);margin:26px 0 12px}
.sec-title:first-child{margin-top:0}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:linear-gradient(120deg,var(--accent),var(--accent-strong));
  color:#fff;font-weight:600;font-size:13px;padding:10px 16px;border-radius:11px;transition:.16s var(--ease);box-shadow:0 6px 14px -6px rgba(21,168,90,.55)}
.btn:hover{transform:translateY(-1px);box-shadow:0 12px 22px -8px rgba(21,168,90,.55)}
.btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;filter:grayscale(.3)}
.btn svg{width:16px;height:16px}
.btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--border);box-shadow:none}
.btn.ghost:hover{border-color:#d2d8e6;background:var(--surface-2)}
.btn.sm{padding:7px 12px;font-size:12px;border-radius:9px}
.btn.danger{background:linear-gradient(120deg,#ef5a50,var(--red));box-shadow:0 6px 14px -6px rgba(227,73,63,.5)}

.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 17px;box-shadow:var(--sh-s);position:relative;overflow:hidden}
.kpi .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;margin-bottom:12px}
.kpi .ic svg{width:18px;height:18px}
.kpi .lbl{font-size:12px;color:var(--ink-soft);font-weight:500}
.kpi .val{font-size:23px;font-weight:800;letter-spacing:-.02em;margin-top:2px}
.kpi .sub{font-size:11px;color:var(--ink-faint);margin-top:4px}

.grid2{display:grid;grid-template-columns:1.5fr 1fr;gap:16px}
.grid2b{display:grid;grid-template-columns:1fr 1fr;gap:16px}

.pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px}
.pill .d{width:6px;height:6px;border-radius:50%;background:currentColor}
.pill.green{background:var(--accent-soft);color:var(--accent-ink)}
.pill.amber{background:var(--amber-soft);color:#9a6512}
.pill.red{background:var(--red-soft);color:#b8362d}
.pill.blue{background:var(--blue-soft);color:#2f53b0}
.pill.grey{background:var(--surface-3);color:var(--ink-soft)}

/* toggle */
.tog{width:40px;height:23px;border-radius:99px;background:#d4dae6;position:relative;transition:.2s var(--ease);flex-shrink:0}
.tog::after{content:"";position:absolute;top:2.5px;left:2.5px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.2s var(--ease)}
.tog.on{background:var(--accent)}
.tog.on::after{transform:translateX(17px)}
.tog.lock{opacity:.55;cursor:not-allowed}

/* module list */
.mod-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mod{display:flex;align-items:flex-start;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px;transition:.15s}
.mod:hover{border-color:#d8dde9;box-shadow:var(--sh-s)}
.mod .mic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex-shrink:0;background:var(--surface-2);color:var(--ink-2)}
.mod .mic svg{width:18px;height:18px}
.mod .mname{font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:7px}
.mod .mdesc{font-size:11.5px;color:var(--ink-soft);margin-top:2px;line-height:1.4}
.mod .mmeta{display:flex;align-items:center;gap:8px;margin-top:9px}
.mod .ver{font-size:10.5px;color:var(--ink-faint);font-family:var(--mono)}
.mod-r{margin-left:auto;display:flex;flex-direction:column;align-items:flex-end;gap:8px}

/* health */
.health{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}
.hrow{display:flex;align-items:center;gap:10px;padding:11px 13px;border:1px solid var(--border);border-radius:var(--r-sm);background:var(--surface)}
.hrow .hd{width:9px;height:9px;border-radius:50%;flex-shrink:0;position:relative}
.hrow .hd::after{content:"";position:absolute;inset:-4px;border-radius:50%;background:currentColor;opacity:.18}
.hd.green{background:var(--green);color:var(--green)}
.hd.amber{background:var(--amber);color:var(--amber)}
.hd.red{background:var(--red);color:var(--red)}
.hrow b{font-size:12.5px;font-weight:600}
.hrow small{font-size:11px;color:var(--ink-soft);margin-left:auto}

/* error rows */
.errs{display:flex;flex-direction:column;gap:9px}
.err{display:flex;align-items:flex-start;gap:12px;padding:13px;border:1px solid var(--border);border-radius:var(--r-sm);background:var(--surface);transition:.15s}
.err:hover{border-color:#dbe0ec}
.sev{font-size:9.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;padding:3px 8px;border-radius:7px;flex-shrink:0;margin-top:1px}
.sev.crit{background:var(--red);color:#fff}
.sev.err{background:var(--red-soft);color:#b8362d}
.sev.warn{background:var(--amber-soft);color:#9a6512}
.err .etitle{font-size:13px;font-weight:600;font-family:var(--mono)}
.err .emeta{font-size:11px;color:var(--ink-soft);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap}
.err .emeta span{display:inline-flex;gap:4px;align-items:center}

/* checks */
.checks{display:flex;flex-direction:column;gap:2px}
.check{display:flex;align-items:center;gap:11px;padding:11px 4px}
.check + .check{border-top:1px solid var(--border-2)}
.check .ci{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;flex-shrink:0}
.check .ci svg{width:13px;height:13px;color:#fff}
.ci.green{background:var(--green)}.ci.amber{background:var(--amber)}.ci.run{background:var(--surface-3)}
.check b{font-size:13px;font-weight:600}
.check small{font-size:11.5px;color:var(--ink-soft);margin-left:auto}

/* env cards */
.envcards{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.envcard{border:1px solid var(--border);border-radius:var(--r);padding:18px;background:var(--surface);position:relative}
.envcard.live{border-color:#bfe6cf;background:linear-gradient(180deg,#f4fcf7,#fff)}
.envcard.sand{border-color:#f3dcb6;background:linear-gradient(180deg,#fdf8ef,#fff)}
.envcard .et{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;display:flex;align-items:center;gap:7px}
.envcard.live .et{color:var(--accent-ink)}.envcard.sand .et{color:#9a6512}
.envcard .ev{font-size:13px;color:var(--ink-2);margin-top:10px}
.envcard .ev b{font-family:var(--mono);color:var(--ink)}

.diff{display:flex;gap:18px;flex-wrap:wrap;padding:13px 15px;background:var(--surface-2);border-radius:var(--r-sm);font-size:12px;color:var(--ink-2);margin:14px 0}
.diff b{color:var(--ink)}
.diff .plus{color:var(--accent-strong);font-weight:700}

/* placeholder */
.ph{text-align:center;padding:60px 20px;max-width:520px;margin:0 auto}
.ph .pic{width:60px;height:60px;border-radius:16px;background:var(--surface-2);display:grid;place-items:center;margin:0 auto 16px;color:var(--ink-soft)}
.ph .pic svg{width:28px;height:28px}
.ph h3{font-size:17px;font-weight:700}
.ph p{font-size:13px;color:var(--ink-soft);margin:7px 0 18px}

/* theme tokens preview */
.swatches{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.sw{width:46px;height:46px;border-radius:10px;border:1px solid rgba(0,0,0,.06);position:relative}
.sw span{position:absolute;bottom:-17px;left:0;right:0;text-align:center;font-size:9px;color:var(--ink-faint)}
.tcards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.tcard{border:1px solid var(--border);border-radius:var(--r-sm);padding:15px;transition:.15s;cursor:pointer;background:var(--surface)}
.tcard:hover{border-color:var(--accent);box-shadow:var(--sh-s);transform:translateY(-1px)}
.tcard .ti{width:34px;height:34px;border-radius:9px;background:var(--accent-soft);color:var(--accent-ink);display:grid;place-items:center;margin-bottom:10px}
.tcard .ti svg{width:18px;height:18px}
.tcard b{font-size:13px;font-weight:600}
.tcard p{font-size:11px;color:var(--ink-soft);margin-top:3px;line-height:1.4}

.banner{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:var(--r-sm);background:var(--blue-soft);border:1px solid #d4e0fb;font-size:12.5px;color:#2f53b0;margin-bottom:18px}
.banner svg{width:18px;height:18px;flex-shrink:0}

table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;font-weight:600;color:var(--ink-soft);padding:10px 12px;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.04em}
td{padding:12px;border-bottom:1px solid var(--border-2);font-size:13px}
tr:last-child td{border-bottom:0}

/* modal */
.modal-bg{position:fixed;inset:0;background:rgba(16,23,41,.5);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
.modal-bg.on{display:flex;animation:fade .2s}
.modal{background:#fff;border-radius:var(--r);box-shadow:var(--sh-l);max-width:560px;width:100%;max-height:88vh;overflow:auto;animation:pop .25s var(--ease)}
@keyframes pop{from{opacity:0;transform:scale(.96) translateY(8px)}to{opacity:1;transform:none}}
.modal-h{display:flex;align-items:center;gap:11px;padding:18px 20px;border-bottom:1px solid var(--border)}
.modal-h b{font-size:15px;font-weight:700}
.modal-h .x{margin-left:auto;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;color:var(--ink-soft)}
.modal-h .x:hover{background:var(--surface-2)}
.modal-b{padding:20px}
.report{background:#0f1729;color:#cdd6e8;border-radius:var(--r-sm);padding:15px;font-family:var(--mono);font-size:11.5px;line-height:1.7;white-space:pre-wrap;max-height:340px;overflow:auto}
.report .k{color:#7fd1a0}.report .v{color:#e8c98c}.report .c{color:#6b7794}

.toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);background:var(--ink);color:#fff;font-size:13px;font-weight:500;padding:12px 20px;border-radius:99px;box-shadow:var(--sh-l);opacity:0;pointer-events:none;transition:.3s var(--ease);z-index:120;display:flex;align-items:center;gap:9px}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast svg{width:16px;height:16px;color:#5fe39b}

.flag{position:fixed;top:12px;right:14px;z-index:90;font-size:10px;font-weight:700;letter-spacing:.04em;color:var(--accent-ink);background:#fff;border:1px solid var(--accent-soft);padding:4px 11px;border-radius:99px;box-shadow:var(--sh-s)}
.themewrap{position:relative}
.pop{position:absolute;top:46px;right:0;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--sh-l);padding:8px;width:212px;z-index:70;display:none;animation:fade .15s var(--ease)}
.pop.open{display:block}
.pop h6{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-faint);padding:5px 8px 7px}
.popitem{display:flex;align-items:center;gap:10px;padding:8px;border-radius:9px;cursor:pointer;font-size:13px;font-weight:500;color:var(--ink-2);width:100%;text-align:left;transition:.12s}
.popitem:hover{background:var(--surface-2)}
.popitem.on{background:var(--accent-soft);color:var(--accent-ink);font-weight:600}
.popsw{width:22px;height:22px;border-radius:7px;border:1px solid rgba(0,0,0,.1);flex-shrink:0}
.popitem .ck{margin-left:auto;color:var(--accent);opacity:0}
.popitem.on .ck{opacity:1}
.popitem .ck svg{width:15px;height:15px}
.side-pin{padding:8px 12px;border-top:1px solid var(--border)}
.theme-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:1080px){.theme-grid{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.theme-grid{grid-template-columns:1fr}}
.theme-card{border:1.5px solid var(--border);border-radius:14px;padding:10px;cursor:pointer;transition:.15s var(--ease);background:var(--surface)}
.theme-card:hover{border-color:var(--accent);box-shadow:var(--sh-s);transform:translateY(-1px)}
.theme-card.on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.theme-card .tcb{display:flex;align-items:center;gap:8px;margin-top:10px}
.theme-card b{font-size:13px;font-weight:600}
.theme-card .ck2{margin-left:auto;width:18px;height:18px;border-radius:50%;border:1.5px solid var(--border);display:grid;place-items:center;color:#fff}
.theme-card.on .ck2{background:var(--accent);border-color:var(--accent)}
.theme-card .ck2 svg{width:11px;height:11px;opacity:0}
.theme-card.on .ck2 svg{opacity:1}
.tpv{height:74px;border:1px solid;border-radius:9px;display:flex;overflow:hidden}
.tpv-side{width:34%;border-right:1px solid;padding:7px;display:flex;flex-direction:column;gap:5px}
.tpv-logo{width:14px;height:14px;border-radius:4px}
.tpv-line{height:5px;border-radius:3px}
.tpv-main{flex:1;padding:8px;display:flex;flex-direction:column;gap:6px}
.tpv-bar{height:8px;width:55%;border-radius:3px}
.tpv-card2{flex:1;border:1px solid;border-radius:5px}
.pref{display:flex;align-items:center;gap:14px;padding:14px 2px}
.pref+.pref{border-top:1px solid var(--border-2)}
.pref .pl{flex:1}
.pref .pl b{font-size:13px;font-weight:600;display:block}
.pref .pl small{font-size:11.5px;color:var(--ink-soft)}
.seg{display:flex;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;padding:3px;gap:2px}
.seg button{font-size:12px;font-weight:600;color:var(--ink-soft);padding:6px 12px;border-radius:7px;transition:.14s}
.seg button.on{background:var(--surface);color:var(--accent-ink);box-shadow:var(--sh-s)}
select.inp{border:1px solid var(--border);border-radius:9px;padding:8px 11px;font-size:13px;font-family:inherit;background:var(--surface);color:var(--ink);cursor:pointer}
input.inp{border:1px solid var(--border);border-radius:9px;padding:8px 11px;font-size:13px;font-family:inherit;background:var(--surface);color:var(--ink)}
input.inp[type=file]{padding:6px 9px}
/* ---- catalog + importer ---- */
.subtabs{display:flex;gap:2px;border-bottom:1px solid var(--border);margin-bottom:18px;overflow-x:auto}
.subtab{font-size:13px;font-weight:600;color:var(--ink-soft);padding:11px 14px;border-bottom:2px solid transparent;white-space:nowrap;transition:.14s}
.subtab:hover{color:var(--ink)}
.subtab.on{color:var(--accent-ink);border-color:var(--accent)}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.search{flex:1;min-width:200px;display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:9px 12px}
.search input{border:0;outline:0;background:none;flex:1;font-size:13px;font-family:inherit;color:var(--ink)}
.search svg{width:16px;height:16px;color:var(--ink-soft)}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{font-size:12px;font-weight:600;color:var(--ink-soft);padding:7px 12px;border-radius:99px;border:1px solid var(--border);background:var(--surface);transition:.14s}
.chip:hover{border-color:#d2d8e6}
.chip.on{background:var(--accent-soft);color:var(--accent-ink);border-color:transparent}
.cbx{width:18px;height:18px;border:1.5px solid var(--border);border-radius:5px;display:grid;place-items:center;cursor:pointer;background:var(--surface);flex-shrink:0}
.cbx.on{background:var(--accent);border-color:var(--accent)}
.cbx svg{width:12px;height:12px;color:#fff;opacity:0}.cbx.on svg{opacity:1}
.bulkbar{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--accent-soft);border:1px solid var(--border);border-radius:11px;margin-bottom:12px;font-size:13px;font-weight:600;color:var(--accent-ink)}
.pthumb{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;font-weight:800;font-size:11px;flex-shrink:0;color:#fff}
.pname{font-size:13px;font-weight:600;line-height:1.2}
.pbrand{font-size:11px;color:var(--ink-soft)}
.price b{font-weight:700}.price s{color:var(--ink-faint);font-size:11px;margin-left:5px}
.pager{display:flex;align-items:center;justify-content:space-between;padding:13px 4px 2px;font-size:12.5px;color:var(--ink-soft)}
.pagebtns{display:flex;gap:5px}
.pagebtns button{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:var(--surface);font-size:12px;font-weight:600;color:var(--ink-2)}
.pagebtns button.on{background:var(--accent);border-color:var(--accent);color:#fff}
/* drawer */
.drawer-bg{position:fixed;inset:0;background:rgba(16,23,41,.45);backdrop-filter:blur(3px);z-index:100;display:none}
.drawer-bg.on{display:block;animation:fade .2s}
.drawer{position:fixed;top:0;right:0;height:100%;width:560px;max-width:95vw;background:var(--surface);box-shadow:var(--sh-l);z-index:101;transform:translateX(100%);transition:.3s var(--ease);display:flex;flex-direction:column}
.drawer.on{transform:none}
.drawer-h{display:flex;align-items:center;gap:11px;padding:17px 20px;border-bottom:1px solid var(--border)}
.drawer-h b{font-size:15px;font-weight:700}
.drawer-h .x{margin-left:auto;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;color:var(--ink-soft)}
.drawer-h .x:hover{background:var(--surface-2)}
.drawer-b{flex:1;overflow-y:auto;padding:20px}
.drawer-f{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.fld{margin-bottom:15px}
.fld>label{display:block;font-size:12px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
.fld input,.fld textarea,.fld select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:13px;font-family:inherit;background:var(--surface);color:var(--ink)}
.fld input:focus,.fld textarea:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.fld textarea{resize:vertical;min-height:78px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.odgrid{display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start}
.odmain{min-width:0}
.odside{min-width:0}
.odcard{background:#fff;border:1px solid var(--border);border-radius:13px;box-shadow:0 1px 2px rgba(18,21,31,.04),0 1px 10px rgba(18,21,31,.03);overflow:hidden}
.odgiftfee{display:inline-block;margin-left:8px;font-size:11px;color:var(--ink-soft)}
.odgiftflag{display:inline-block;background:#fff0f4;color:#b4517a;border-radius:99px;padding:3px 11px;font-size:11px;letter-spacing:.04em;text-transform:uppercase}
.odgiftmsg,.odcustnote{margin-bottom:12px}
.odgiftmsg:last-child,.odcustnote:last-child{margin-bottom:0}
.odgiftmsg b,.odcustnote b{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:5px}
.odgiftmsg p,.odcustnote p{margin:0;font-size:13.5px;line-height:1.6;white-space:pre-wrap}
.odgiftmsg p{padding:10px 12px;background:#fff7fa;border:1px solid #f0dde4;border-radius:8px}
.odcardhead{background:#FFF8EC;color:#92600A;padding:13px 22px;font-weight:700;font-size:12.5px;display:flex;justify-content:space-between;align-items:center}
.odchev{display:flex;gap:4px}
.odtoggle{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;opacity:.75;cursor:pointer}
.odtoggle:hover{opacity:1;background:rgba(0,0,0,.06)}
.odcols3{display:grid;grid-template-columns:1fr 1fr 1fr}
.odcolcell{padding:20px 22px}
.odcolcell.odcolmid{border-left:1px solid var(--border);border-right:1px solid var(--border)}
.odcols2{display:grid;grid-template-columns:1fr 1fr;gap:28px;padding:20px 22px}
.odcollabel{font-size:10px;font-weight:700;color:var(--ink-faint);letter-spacing:.06em;margin-bottom:14px;display:flex;justify-content:space-between}
.odcollabel a{font-weight:600;font-size:11px;color:#E08A1A;text-decoration:none;letter-spacing:0}
.odfld{margin-bottom:13px}
.odfld label{display:block;font-size:10.5px;color:var(--ink-2);margin-bottom:6px;font-weight:600}
.odinp,.odcard select{width:100%;padding:10px 12px;border:1.5px solid #D8DCE3;border-radius:8px;font-size:12.5px;color:var(--ink);background:#FAFBFC;font-family:inherit;font-weight:500;box-shadow:inset 0 1px 2px rgba(18,21,31,.03)}
.odinp:focus,.odcard select:focus{outline:none;border-color:#E08A1A;background:#fff;box-shadow:0 0 0 3px #FFF3E0}
.odtimegrid{display:grid;grid-template-columns:1.3fr .7fr .7fr;gap:7px}
.odaddr{font-size:11.5px;line-height:1.8;color:var(--ink-2)}
.odaddr a{color:#E08A1A;text-decoration:none;font-weight:500}
.odaddr .odname{font-weight:700;color:var(--ink)}
.odcustchip{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1.5px solid #D8DCE3;border-radius:8px;font-size:12px;font-weight:600;background:#FAFBFC;box-shadow:inset 0 1px 2px rgba(18,21,31,.03)}
@media(max-width:1100px){.odgrid{grid-template-columns:1fr}.odcols3{grid-template-columns:1fr}.odcols2{grid-template-columns:1fr}.odcolcell.odcolmid{border-left:none;border-right:none;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}}
.imgdrop{border:1.5px dashed var(--border);border-radius:12px;padding:22px;text-align:center;color:var(--ink-soft);font-size:12.5px}
.imgrow{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.imgrow .ph2{width:54px;height:54px;border-radius:9px;background:var(--surface-2);display:grid;place-items:center;color:var(--ink-faint);font-size:10px}
.tagchips{display:flex;gap:6px;flex-wrap:wrap}
.tagchip{font-size:11.5px;font-weight:600;padding:5px 10px;border-radius:99px;background:var(--surface-2);color:var(--ink-2);border:1px solid var(--border)}
.tagchip.on{background:var(--accent-soft);color:var(--accent-ink);border-color:transparent}
.dsec{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-faint);margin:20px 0 11px}
.dsec:first-child{margin-top:0}
/* wizard */
.wiz{display:grid;grid-template-columns:230px 1fr;gap:22px}
@media(max-width:880px){.wiz{grid-template-columns:1fr}}
.steps{display:flex;flex-direction:column;gap:4px}
.stp{display:flex;align-items:center;gap:11px;padding:11px;border-radius:10px;font-size:13px;font-weight:600;color:var(--ink-soft)}
.stp .sn{width:24px;height:24px;border-radius:50%;border:1.5px solid var(--border);display:grid;place-items:center;font-size:12px;flex-shrink:0}
.stp.on{background:var(--surface);box-shadow:var(--sh-s);color:var(--ink)}
.stp.on .sn{background:var(--accent);border-color:var(--accent);color:#fff}
.stp.done .sn{background:var(--accent-soft);border-color:var(--accent);color:var(--accent-ink)}
.stp.done .sn svg{width:13px;height:13px}
.optcard{display:flex;align-items:flex-start;gap:12px;border:1.5px solid var(--border);border-radius:12px;padding:14px;cursor:pointer;transition:.15s;background:var(--surface)}
.optcard:hover{border-color:var(--accent)}
.optcard.on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.optcard .oi{width:34px;height:34px;border-radius:9px;background:var(--surface-2);display:grid;place-items:center;color:var(--ink-2);flex-shrink:0}
.optcard b{font-size:13px;font-weight:600}.optcard p{font-size:11.5px;color:var(--ink-soft);margin-top:2px}
.pbar{height:8px;background:var(--surface-3);border-radius:99px;overflow:hidden}
.pbar i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--accent-strong));border-radius:99px;transition:.5s var(--ease)}
.rlist{display:flex;flex-direction:column;gap:8px}
.ritem{display:flex;align-items:center;gap:12px;border:1px solid var(--border);border-radius:11px;padding:10px 13px;background:var(--surface)}
.ritem .ord{font-size:12px;font-weight:700;color:var(--ink-faint);width:20px;text-align:center}
.ritem .mv{display:flex;flex-direction:column;gap:2px;margin-left:auto}
.ritem .mv button{width:24px;height:18px;border-radius:5px;border:1px solid var(--border);display:grid;place-items:center;color:var(--ink-soft);background:var(--surface)}
.ritem .mv button:hover{color:var(--accent-ink);border-color:var(--accent)}
.ritem .mv svg{width:12px;height:12px}
/* ---- full product editor (WooCommerce-parity) ---- */
.btn.block{width:100%}
.pe-top{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.pe-grid{display:grid;grid-template-columns:1fr 322px;gap:18px;align-items:start}
@media(max-width:1000px){.pe-grid{grid-template-columns:1fr}}
.pe-main,.pe-side{display:flex;flex-direction:column;gap:16px;min-width:0}
.pe-title{width:100%;border:1px solid var(--border);border-radius:12px;padding:14px 16px;font-size:18px;font-weight:700;font-family:inherit;background:var(--surface);color:var(--ink)}
.pe-title:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pe-perma{font-size:11.5px;color:var(--ink-soft);margin:8px 0 0}
.pe-perma span{color:var(--accent-ink)}
.pe-perma .lk{font-size:11.5px;color:var(--accent-ink);font-weight:600;margin-left:6px;background:none}
.pe-card .pe-h{font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);margin-bottom:12px}
.rte{border:1px solid var(--border);border-radius:10px;overflow:hidden}
.rte-bar{display:flex;flex-wrap:wrap;gap:2px;padding:6px;background:var(--surface-2);border-bottom:1px solid var(--border)}
.rte-bar button{font-size:11.5px;font-weight:600;color:var(--ink-2);padding:5px 8px;border-radius:6px;background:none}
.rte-bar button:hover{background:var(--surface)}
.rte-area{width:100%;border:0;outline:0;padding:12px;font-size:13px;font-family:inherit;min-height:120px;resize:vertical;background:var(--surface);color:var(--ink)}
.pe-pdhead{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:13px 16px;border-bottom:1px solid var(--border);background:var(--surface-2)}
.pe-pdhead b{font-size:13px}
.pdchk{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:500;color:var(--ink-2);cursor:pointer}
.pd-wrap{display:grid;grid-template-columns:172px 1fr}
@media(max-width:640px){.pd-wrap{grid-template-columns:1fr}}
.pd-tabs{display:flex;flex-direction:column;border-right:1px solid var(--border);padding:8px;gap:2px}
@media(max-width:640px){.pd-tabs{flex-direction:row;flex-wrap:wrap;border-right:0;border-bottom:1px solid var(--border)}}
.pd-tab{text-align:left;font-size:12.5px;font-weight:600;color:var(--ink-soft);padding:9px 11px;border-radius:8px;background:none}
.pd-tab:hover{color:var(--ink)}
.pd-tab.on{background:var(--accent-soft);color:var(--accent-ink)}
.pd-body{padding:16px;min-width:0}
.pubrow{display:flex;align-items:center;justify-content:space-between;font-size:12.5px;padding:7px 0;border-bottom:1px solid var(--border-2)}
.pubrow span{color:var(--ink-soft)}
.catlist{display:flex;flex-direction:column;gap:9px;max-height:210px;overflow:auto}
.catopt{display:flex;align-items:center;gap:9px;font-size:13px;cursor:pointer}
.seo-snip{border:1px solid var(--border);border-radius:10px;padding:13px;background:var(--surface-2)}
.seo-snip .u{font-size:11.5px;color:var(--accent-ink)}
.seo-snip .t{color:#2456d6;font-size:15px;margin:3px 0;font-weight:500}
.seo-snip .d{font-size:12px;color:var(--ink-2);line-height:1.4}
/* metabox look */
.pe-box{padding:0;overflow:hidden}
.pe-bh{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--ink-soft);cursor:pointer;border-bottom:1px solid var(--border);user-select:none}
.pe-box.col .pe-bh{border-bottom:0}
.pe-bh .chev{width:15px;height:15px;transition:.2s;color:var(--ink-faint)}
.pe-box.col .pe-bh .chev{transform:rotate(-90deg)}
.pe-bb{padding:16px}
.pe-box.col .pe-bb{display:none}
.htabs{display:flex;gap:3px;flex-wrap:wrap;border-bottom:1px solid var(--border);margin-bottom:16px}
.htab{font-size:12px;font-weight:600;color:var(--ink-soft);padding:9px 11px;border-bottom:2px solid transparent;white-space:nowrap;background:none}
.htab:hover{color:var(--ink)}
.htab.on{color:var(--accent-ink);border-color:var(--accent)}
.minitabs{display:flex;gap:14px;font-size:11.5px;margin-bottom:11px;border-bottom:1px solid var(--border-2);padding-bottom:8px}
.minitabs button{font-weight:600;color:var(--ink-soft);background:none}
.minitabs button.on{color:var(--accent-ink)}
.gal{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.gal .g{aspect-ratio:1;border-radius:8px;background:var(--surface-2);display:grid;place-items:center;color:var(--ink-faint);font-size:9.5px;border:1px solid var(--border)}
.gal .g.add{border:1.5px dashed var(--border);background:none;color:var(--accent-ink);font-weight:600;cursor:pointer}
.pe-link{color:var(--accent-ink);font-weight:600;font-size:11.5px;background:none}
.wcount{font-size:11px;color:var(--ink-soft);padding:7px 12px;border-top:1px solid var(--border);background:var(--surface-2);display:flex;justify-content:space-between;align-items:center}
.featimg{border:1px solid var(--border);border-radius:10px;overflow:hidden}
.featimg .ph{aspect-ratio:4/3;background:linear-gradient(135deg,var(--accent-soft),#eaf0ff);display:grid;place-items:center;color:var(--accent-ink);font-weight:700;font-size:12px}
.featimg .cap{padding:9px 11px;display:flex;justify-content:space-between;font-size:11px}
.pe-builder{display:inline-flex;align-items:center;gap:7px;background:linear-gradient(135deg,#7b5cf0,#9b6cf0);color:#fff;border-radius:9px;padding:9px 14px;font-size:12.5px;font-weight:600;margin-top:10px}
.rte-sub{display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid var(--border);background:var(--surface)}
.rte-sub .vc{margin-left:auto;display:flex;border:1px solid var(--border);border-radius:7px;overflow:hidden}
.rte-sub .vc button{font-size:11px;font-weight:600;padding:5px 11px;background:var(--surface);color:var(--ink-soft)}
.rte-sub .vc button.on{background:var(--accent-soft);color:var(--accent-ink)}
.rte-sub .am{font-size:11px;font-weight:600;color:var(--ink-2);padding:5px 9px;border:1px solid var(--border);border-radius:7px;background:var(--surface)}
/* ---- product labels + meta ---- */
.posgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;width:108px}
.posgrid button{aspect-ratio:1;border:1px solid var(--border);border-radius:6px;background:var(--surface);display:grid;place-items:center;cursor:pointer}
.posgrid button .dot{width:7px;height:7px;border-radius:50%;background:var(--border)}
.posgrid button.on{border-color:var(--accent);background:var(--accent-soft)}
.posgrid button.on .dot{background:var(--accent)}
.thumbprev{position:relative;width:160px;aspect-ratio:1;border-radius:14px;background:linear-gradient(135deg,#fde7ef,#eef1ff);border:1px solid var(--border);overflow:hidden;display:grid;place-items:center;color:var(--ink-faint);font-weight:700;font-size:12px}
.onlabel{position:absolute;display:inline-flex;align-items:center;gap:4px;font-weight:800;color:#fff;border-radius:7px;box-shadow:0 4px 12px rgba(0,0,0,.18);white-space:nowrap;line-height:1}
.lbl-card-thumb{width:46px;height:46px;border-radius:11px;display:grid;place-items:center;font-size:15px;font-weight:800;color:#fff;flex-shrink:0}
.metarow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:760px){.metarow{grid-template-columns:1fr}}
/* ---- shop filters control ---- */
.sfrow{display:flex;align-items:center;gap:13px;padding:14px 0;border-bottom:1px solid var(--border-2)}
.sfrow:last-child{border-bottom:0}
.sfgrip{color:var(--ink-faint);cursor:grab;flex-shrink:0}
.sfmv{display:flex;flex-direction:column;gap:2px;flex-shrink:0}
.sfmv button{width:22px;height:17px;border-radius:5px;border:1px solid var(--border);display:grid;place-items:center;color:var(--ink-soft);background:var(--surface)}
.sfmv button:hover{color:var(--accent-ink);border-color:var(--accent)}
.sfmv svg{width:11px;height:11px}
.sfseg{display:inline-flex;border:1px solid var(--border);border-radius:8px;overflow:hidden;flex-shrink:0}
.sfseg button{font-size:11.5px;font-weight:600;padding:6px 11px;color:var(--ink-soft);background:var(--surface)}
.sfseg button.on{background:var(--accent-soft);color:var(--accent-ink)}
.sfseg button+button{border-left:1px solid var(--border)}
.sfchecks{display:flex;flex-wrap:wrap;gap:7px;margin-top:11px;padding-left:35px}
.sfprev .fg{padding:11px 0;border-bottom:1px solid var(--border-2)}
.sfprev .fg:last-child{border-bottom:0}
.sfprev .fgt{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#A82F53;margin-bottom:8px}
/* screen options + bulk inventory */
.so-cols{display:flex;flex-wrap:wrap;gap:10px 18px}
.so-col{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink-2);cursor:pointer}
.invbulk{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
tr.invdirty{background:var(--accent-soft)}
.invsave{position:sticky;bottom:0;display:flex;align-items:center;gap:10px;margin-top:14px;padding:12px 16px;background:var(--surface);border:1px solid var(--accent);border-radius:12px;box-shadow:0 -8px 22px rgba(16,23,41,.10);font-size:13px;font-weight:600;z-index:5}

@media(max-width:1080px){.kpis{grid-template-columns:1fr 1fr}.mod-grid,.grid2,.grid2b,.tcards,.envcards,.health{grid-template-columns:1fr}}
@media(max-width:880px){.app{grid-template-columns:1fr}.side{position:fixed;z-index:80;width:248px;height:100%;transform:translateX(-100%);transition:.25s var(--ease)}.side.open{transform:none}.menubtn{display:grid!important}}
.menubtn{display:none}

.skingrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:10px}
.skinsw{border:1px solid #e2e8f0;background:#fff;border-radius:10px;padding:8px;cursor:pointer;text-align:left;transition:.15s}
.skinsw:hover{border-color:#cbd5e1;transform:translateY(-1px)}
.skinsw.on{border-color:#E0567B;box-shadow:0 0 0 2px rgba(224,86,123,.18)}
.skinsw-p{display:block;height:52px;border-radius:7px;margin-bottom:6px;background:linear-gradient(135deg,#ffe3ec,#ffc6da)}
.skinsw-l{font-size:11px;color:#475569;line-height:1.3;display:block}

/* ---------- Store · Ecommerce ---------- */
.ecwrap{max-width:1020px}
.ecsearch input{border:1px solid #e6ebf2;border-radius:10px;padding:8px 12px;font:400 13px inherit;width:230px;background:#fbfcfe}
.ecsearch input:focus{outline:0;border-color:#E0567B;box-shadow:0 0 0 3px rgba(224,86,123,.12);background:#fff}

.ectabs{display:flex;gap:2px;overflow-x:auto;border-bottom:1px solid #e9edf3;margin-top:16px}
.ectab{position:relative;border:0;background:none;padding:11px 14px 13px;font:600 13px inherit;color:#7b8697;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:8px;border-radius:9px 9px 0 0}
.ectab:hover{color:#3c4655;background:#f3f6fa}
.ectab.on{color:#C13E63}
.ectab.on::after{content:'';position:absolute;left:14px;right:14px;bottom:-1px;height:2px;background:#E0567B;border-radius:2px}
.ectab .ct{font-size:11px;font-weight:600;color:#7b8697;background:#f3f6fa;border-radius:99px;padding:1px 7px}
.ectab.on .ct{background:#FFF1F5;color:#C13E63}

.ecbody{padding:20px 0 8px}

/* section = its own card, so nothing bleeds together */
.ecsec{border:1px solid #e9edf3;border-radius:14px;overflow:hidden;margin-bottom:16px;background:#fff}
.ecsech{display:flex;align-items:center;gap:10px;padding:13px 16px;background:#fbfcfe;border-bottom:1px solid #e9edf3}
.ecic{width:28px;height:28px;border-radius:8px;background:#FFF1F5;color:#C13E63;display:grid;place-items:center;flex:none}
.ecic svg{width:15px;height:15px}
.ecsect h3{font-size:13.5px;margin:0;letter-spacing:-.005em}
.ecsect p{font-size:12px;color:#7b8697;margin:1px 0 0}
.ecsp{flex:1}
.ecsecb{padding:2px 16px 6px}

/* row: label left, control right */
.ecopt{display:flex;align-items:center;gap:18px;padding:13px 0;border-bottom:1px solid #f3f6fa}
.ecopt:last-of-type{border-bottom:0}
.ecom{flex:1;min-width:0}
.ecl{display:flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600}
.ecl label{cursor:pointer}
.echelp{font-size:12.5px;color:#7b8697;margin-top:2px;max-width:60ch}
.ecctl{flex:none;display:flex;align-items:center;gap:9px}
.ecopt.wide{display:block}
.ecopt.wide .ecctl{margin-top:10px}
.ecopt.wide .inp{width:100%;max-width:520px}
.ecunit{font-size:12px;color:#7b8697}
.ecclr{width:52px;height:34px;padding:2px;border:1px solid #dde4ec;border-radius:8px;background:#fff;cursor:pointer}

.ectog{width:38px;height:22px;border-radius:99px;background:#cfd7e2;position:relative;display:inline-block;cursor:pointer;transition:.18s;flex:none}
.ectog::after{content:'';position:absolute;top:2.5px;left:2.5px;width:17px;height:17px;border-radius:50%;background:#fff;transition:.18s;box-shadow:0 1px 2px rgba(16,24,40,.14)}
.ectog.on{background:#1F7D52}
.ectog.on::after{left:18.5px}
.ectog:focus-visible{outline:2px solid #E0567B;outline-offset:2px}

.eceye{width:22px;height:22px;flex:none;border:1px solid #e9edf3;background:#fff;border-radius:6px;display:grid;place-items:center;cursor:pointer;color:#9aa5b4;transition:.15s;padding:0}
.eceye:hover,.eceye.on{background:#FFF1F5;border-color:#E0567B;color:#C13E63}
.eceye svg{width:13px;height:13px}
.ecsech .eceye{width:24px;height:24px}
.ecsech .eceye svg{width:14px;height:14px}

.ecpv{max-height:0;overflow:hidden;transition:max-height .3s cubic-bezier(.4,0,.2,1)}
.ecpv.on{max-height:520px}
.ecpvi{padding:14px 16px 16px;background:#fbfcfe;border-top:1px solid #e9edf3}
.ecopt + .ecpv .ecpvi{border-top:0;border:1px solid #e9edf3;border-radius:11px;margin:0 16px 12px}
.ecpvc{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#C13E63;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.ecpvc .d{width:5px;height:5px;border-radius:50%;background:#E0567B}
.ecpvs{background:#fff;border:1px solid #e9edf3;border-radius:10px;padding:14px}
.ecmark{position:relative;display:inline-block}
.ecmark::after{content:'';position:absolute;inset:-7px;border:2px dashed #E0567B;border-radius:10px;animation:ecp 1.8s ease-in-out infinite}
@keyframes ecp{0%,100%{opacity:.38}50%{opacity:.95}}
.ecco{position:absolute;top:-10px;left:-10px;width:20px;height:20px;border-radius:50%;background:#E0567B;color:#fff;font:700 11px inherit;display:grid;place-items:center;z-index:2}
.eclg{margin-top:10px;font-size:12.5px;color:#3c4655;display:flex;gap:9px;line-height:1.5}
.eclg .n{width:19px;height:19px;flex:none;border-radius:50%;background:#E0567B;color:#fff;font:700 11px inherit;display:grid;place-items:center;margin-top:1px}
.ecmb{height:10px;border-radius:99px;background:#e9edf2;overflow:hidden}
.ecmb i{display:block;height:100%;background:repeating-linear-gradient(45deg,#2fae6f,#2fae6f 6px,#48c98a 6px,#48c98a 12px)}
.ecmr{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0}

.ecsave{position:sticky;bottom:0;background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border-top:1px solid #e9edf3;padding:13px 0;display:flex;align-items:center;gap:11px;margin-top:8px}
.ecdirty{font-size:12.5px;color:#B7791F;margin-right:auto;display:flex;align-items:center;gap:7px}
.ecdirty::before{content:'';width:7px;height:7px;border-radius:50%;background:#B7791F}
.ecdirty.ok{color:#1F7D52}
.ecdirty.ok::before{background:#1F7D52}

/* ── tablet ── */
@media (max-width:900px){
  .ecsearch,.ecsearch input{width:100%}
  .ectabs{scrollbar-width:none}
  .ectabs::-webkit-scrollbar{display:none}
}

/* ── phone ── */
@media (max-width:720px){
  /* control below the label: a select squeezed into a right column is unusable */
  .ecopt{display:block;padding:14px 0}
  .ecopt .ecctl{margin-top:10px}
  .ecopt .echelp{max-width:none}
  .ecopt .inp{width:100%;max-width:none}
  .ecopt select.inp{min-width:0}
  /* toggles stay on the row — alone in a column they look orphaned */
  .ecopt.istog{display:flex;align-items:center;gap:14px}
  .ecopt.istog .ecctl{margin-top:0}

  .ecsech{padding:12px 13px}
  .ecsecb{padding:2px 13px 6px}
  .ecsec{margin-bottom:13px}
  .ecopt + .ecpv .ecpvi{margin:0 13px 12px}
  .ecpv.on{max-height:640px}
  .ecpvi{padding:12px}
  .ecpvs{padding:12px;overflow-x:auto}

  /* comfortable touch targets, and 14px inputs so iOS does not zoom */
  .eceye{width:30px;height:30px}
  .eceye svg{width:15px;height:15px}
  .ectab{padding:12px 13px 14px;font-size:13.5px}
  .inp{padding:11px 12px;font-size:14px}
  .ectog{width:44px;height:26px}
  .ectog::after{width:21px;height:21px}
  .ectog.on::after{left:21px}

  .ecsave{flex-wrap:wrap;gap:9px}
  .ecdirty{width:100%;margin:0 0 2px}
  .ecsave .btn{flex:1;padding:12px 14px}
}

@media (max-width:400px){
  .ecsect p{font-size:11.5px}
}

/* Appearance · Homepage */
.hphead{display:grid;grid-template-columns:1fr 74px 74px;gap:8px;padding:0 16px 8px;
  font-size:10.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#7b8697}
.hphead span:not(:first-child){text-align:center}
.hplist{border:1px solid #e9edf3;border-radius:14px;overflow:hidden;background:#fff}
.hprow{display:grid;grid-template-columns:38px 1fr 74px 74px;gap:8px;align-items:center;
  padding:12px 16px 12px 8px;border-bottom:1px solid #f3f6fa}
.hprow:last-child{border-bottom:0}
.hprow.alloff{background:#fbfcfe}
.hprow.alloff .hpmain b{opacity:.45;text-decoration:line-through}
.hpmove{display:flex;flex-direction:column;gap:2px}
.hpb{width:26px;height:19px;border:1px solid #e9edf3;background:#fff;border-radius:6px;cursor:pointer;
  font-size:11px;line-height:1;color:#3c4655;padding:0}
.hpb:disabled{opacity:.3;cursor:default}
.hpb:not(:disabled):hover{background:#FFF1F5;border-color:#E0567B;color:#C13E63}
.hpmain b{display:block;font-size:13.5px;font-weight:600}
.hpmain span{font-size:12px;color:#7b8697}
.hpskin{display:flex;align-items:center;gap:8px;margin-top:8px}
.hpskin label{font-size:11.5px;color:#7b8697}
.hpskin select{border:1px solid #dde4ec;border-radius:8px;padding:6px 9px;font:600 12px inherit;background:#fff}
.hprow .ectog{margin:0 auto}
@media(max-width:720px){
  .hphead{grid-template-columns:1fr 58px 58px;padding:0 12px 8px}
  .hprow{grid-template-columns:32px 1fr 58px 58px;padding:11px 12px 11px 6px}
  .hpskin select{flex:1;min-width:0}
}

/* Appearance · Homepage — layout presets */
.hpsec-h{margin:0 0 10px}
.hpsec-h b{display:block;font-size:14px;font-weight:700}
.hpsec-h span{font-size:12.5px;color:#7b8697}
.hplayouts{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:6px}
.hpl{border:1px solid #e9edf3;border-radius:14px;padding:14px;background:#fff;transition:.22s;display:flex;flex-direction:column}
.hpl:hover{border-color:#E0567B;box-shadow:0 14px 34px -22px rgba(16,24,40,.3)}
.hpl.on{border-color:#E0567B;box-shadow:0 0 0 2px rgba(224,86,123,.16)}
.hpl-p{background:#FBF7F9;border:1px solid #F1E6EC;border-radius:10px;padding:8px;margin-bottom:11px}
.wire{display:flex;flex-direction:column;gap:3px}
.wire i{display:block;border-radius:3px}
.hpl b{font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:7px}
.hpl b i{font-style:normal;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  background:#FFF1F5;color:#C13E63;border-radius:99px;padding:2px 8px}
.hpl .bl{font-size:12px;color:#3c4655;margin-top:5px;line-height:1.45}
.hpl .su{font-size:11.5px;color:#7b8697;margin-top:6px;line-height:1.45}
.hpl-m{display:flex;gap:8px;margin:10px 0 10px;font-size:11px;color:#7b8697}
.hpl-m .offl{color:#B7791F}
.hpl .hpl-b{margin-top:auto;width:100%}
@media(max-width:1100px){.hplayouts{grid-template-columns:1fr 1fr}}
@media(max-width:620px){.hplayouts{grid-template-columns:1fr}}

/* demo content row + confirmation dialog */
.demorow{display:flex;align-items:center;gap:14px;border:1px solid #e9edf3;border-radius:14px;
  padding:14px 16px;background:#fff;margin-bottom:18px}
.demo-ic{width:38px;height:38px;border-radius:11px;background:#FFF1F5;color:#C13E63;display:grid;place-items:center;font-size:17px;flex:none}
.demo-t{flex:1;min-width:0}
.demo-t b{display:block;font-size:13.5px;font-weight:600}
.demo-t span{display:block;font-size:12px;color:#7b8697;margin-top:2px}
.demo-t .demo-safe{color:#1F7D52;font-weight:500}
.kdlg{position:fixed;inset:0;z-index:200;display:grid;place-items:center;opacity:0;transition:.2s;pointer-events:none}
.kdlg.on{opacity:1;pointer-events:auto}
.kdlg-s{position:absolute;inset:0;background:rgba(21,26,34,.5)}
.kdlg-b{position:relative;background:#fff;border-radius:16px;padding:24px;width:min(92vw,460px);
  box-shadow:0 30px 70px -20px rgba(16,24,40,.4);transform:translateY(8px);transition:.2s}
.kdlg.on .kdlg-b{transform:none}
.kdlg-b h3{font-size:17px;margin:0 0 10px}
.kdlg-b p{font-size:13.5px;color:#3c4655;margin:0 0 10px;line-height:1.55}
.kdlg-safe{background:#F0F9F4;border:1px solid #CFE9DB;border-radius:10px;padding:10px 12px;color:#1F7D52!important;font-size:12.5px!important}
.kdlg-a{display:flex;gap:9px;justify-content:flex-end;margin-top:16px}
@media(max-width:520px){.kdlg-a{flex-direction:column-reverse}.kdlg-a .btn{width:100%}}

/* mobile menu screen */
.mmgrid{display:grid;grid-template-columns:1fr 330px;gap:20px;align-items:start}
.mmcard{padding:0;overflow:hidden;margin-bottom:14px}
.mmhd{padding:13px 16px;background:#FAFBFC;border-bottom:1px solid #e9edf3}
.mmhd b{display:block;font-size:13.5px}
.mmhd span{font-size:12px;color:#7b8697}
.mmbody{padding:4px 16px 10px}
.mmrow{display:flex;align-items:center;gap:14px;padding:11px 0;border-bottom:1px solid #f2f5f8}
.mmrow:last-child{border-bottom:0}
.mmlbl{flex:1;min-width:0}
.mmlbl b{display:block;font-size:13px;font-weight:500}
.mmlbl span{display:block;font-size:11.5px;color:#7b8697;margin-top:1px}
.mmrow input[type=text],.mmrow select{border:1px solid #dfe5ec;border-radius:8px;padding:7px 10px;font:400 13px inherit;min-width:150px}
.mmrange{display:flex;align-items:center;gap:9px}

/* ===== Mega Menu — brand-matched to the storefront's own rose palette,
   not the admin console's default green, since this screen edits exactly
   what a shopper sees rendered in that palette a moment later. ===== */
.mgm-wrap .page-head p{max-width:64ch}
.mgm-menubar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:18px 0 16px;padding-bottom:14px;border-bottom:1px solid var(--line-2,#eee)}
.mgm-menutab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:99px;border:1px solid #f0e4e9;background:#fff;font-size:13px;font-weight:600;color:#5E545A;transition:all .12s}
.mgm-menutab:hover{border-color:#E0567B}
.mgm-menutab.on{background:#2A2228;border-color:#2A2228;color:#fff}
.mgm-menutab-new{background:transparent;border-style:dashed;color:#C13E63}
.mgm-loctag{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:rgba(255,255,255,.16);padding:2px 7px;border-radius:99px}
.mgm-menutab:not(.on) .mgm-loctag{background:#FFF0F4;color:#C13E63}
.mgm-menusettings{margin-left:auto;font-size:12.5px;font-weight:600;color:#5E545A;background:none;border:none;padding:6px 8px;border-radius:8px;cursor:pointer}
.mgm-menusettings:hover{background:#f5eef0;color:#2A2228}
.mgm-offbanner{padding:14px 18px;border:1px solid #f0d9a2;background:#fffaf0;border-radius:12px;margin-bottom:18px;font-size:13px}
.mgm-offbanner a{color:#C13E63;font-weight:600}
.mgmpv{background:#FFF8F5;border:1px solid #FCE0E8;border-radius:14px;padding:16px 18px;margin-bottom:20px}
.mgmpv-label{font-size:11px;font-weight:600;color:#8C828A;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.mgmpv-label::before{content:'';width:6px;height:6px;border-radius:50%;background:#2F9E6B;display:inline-block}
.mgmpv-bar{display:flex;flex-wrap:wrap;gap:4px;font-family:"Poppins",system-ui,sans-serif}
.mgmpv-link{font-size:13px;font-weight:600;color:#2A2228;padding:6px 4px;display:inline-flex;align-items:center;gap:5px}
.mgmpv-link em{font:700 9px/1 inherit;background:#15a85a;color:#fff;border-radius:99px;padding:2px 6px;font-style:normal}
.mgmpv-empty{font-size:12.5px;color:#8C828A;font-style:italic}
.mgm-card{padding:16px;box-shadow:0 1px 2px rgba(42,34,40,.04)}
.mgm-treehead{display:flex;align-items:center;justify-content:space-between;padding:0 2px 8px;font-size:11.5px;color:#8C828A}
.mgm-expandall{background:none;border:none;color:#C13E63;font-size:11.5px;font-weight:600;cursor:pointer;padding:2px 4px}
.mgm-expandall:hover{text-decoration:underline}
.mgmtree{position:relative}
.mgmrow-wrap{position:relative}
.mgmdropline{height:0;overflow:hidden;transition:height .1s}
.mgmdropline.on{height:6px}
.mgmdropline.on::before{content:'';display:block;height:2px;margin:2px 0;border-radius:99px;background:#E0567B;box-shadow:0 0 0 3px #FCE0E8}
.mgmitem{display:flex;align-items:center;gap:7px;padding:5px 8px;border:1px solid #f0e4e9;border-left:3px solid transparent;border-radius:8px;background:#fff;margin-bottom:2px;transition:box-shadow .12s,opacity .12s,border-color .12s;min-height:30px}
.mgmitem:hover{box-shadow:0 3px 10px -6px rgba(42,34,40,.2);border-color:#f0d3dc}
.mgmitem.mgm-dragging{opacity:.35}
.mgmhandle{cursor:grab;color:#d4c5cb;font-size:12px;line-height:1;user-select:none;flex:none}
.mgmhandle:hover{color:#E0567B}
.mgmhandle:active{cursor:grabbing}
.mgmtoggle{width:16px;height:16px;flex:none;border:none;background:none;color:#a89ba1;font-size:9px;cursor:pointer;transition:transform .12s;display:grid;place-items:center}
.mgmtoggle.open{transform:rotate(90deg)}
.mgmtoggle-sp{width:16px;flex:none}
.mgmlabel{flex:1;min-width:0;overflow:hidden}
.mgmlabel-top{display:flex;align-items:baseline;gap:6px;flex-wrap:nowrap;overflow:hidden}
.mgmlabel-top b{font-size:12.5px;font-weight:600;color:#2A2228;white-space:nowrap;flex:none}
.mgm-swatch{width:10px;height:10px;border-radius:50%;border:1px solid rgba(0,0,0,.12);flex:none}
.mgm-vistag{font-size:9px;font-weight:600;letter-spacing:.02em;color:#C13E63;background:#FFF0F4;padding:1px 6px;border-radius:99px;flex:none}
.mgm-tabtag{font-size:10px;color:#8C828A;flex:none}
.mgm-childcount{font-size:10px;color:#a89ba1;background:#f5eef0;border-radius:99px;padding:0px 6px;flex:none}
.mgmurl{font-size:10.5px;color:#c2b6bc;font-family:ui-monospace,monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.mgmactions{display:flex;gap:4px;flex:none;opacity:0;transition:opacity .12s}
.mgmitem:hover .mgmactions{opacity:1}
.mgmactions .btn{padding:3px 9px;font-size:11px}
.mgmchildren{margin-left:23px;padding-left:10px;border-left:2px dashed #FCE0E8;transition:background .1s,border-color .1s}
.mgmchildren.mgm-collapsed{display:none}
.mgmchildren.mgm-into{background:#FFF0F4;border-left-color:#E0567B;border-radius:8px}
.mgmitem.mgm-into-row{background:#FFF0F4;border-color:#E0567B;box-shadow:inset 0 0 0 1.5px #E0567B}
.mgmadd-child{background:#fff;color:#C13E63;border:1.5px dashed #E0567B;margin:2px 0 6px;padding:3px 10px;font-size:11px}
.mgm-colorpick{display:flex;align-items:center;gap:8px}
.mgm-colorpick input[type=color]{width:38px;height:32px;border:1px solid #dfe5ec;border-radius:8px;padding:2px;cursor:pointer}
.mgm-modal{max-width:440px}
.mgm-modal select{border:1px solid #dfe5ec;border-radius:8px;padding:8px 10px;font:400 13px inherit;min-width:170px;flex:none}
.mgm-iconpick{position:relative;flex:none}
.mgm-iconbtn{width:42px;height:38px;border:1px solid #dfe5ec;border-radius:9px;background:#fff;font-size:19px;display:grid;place-items:center;cursor:pointer;transition:border-color .12s}
.mgm-iconbtn:hover{border-color:#E0567B}
.mgm-iconph{color:#c9b8c0;font-size:15px}
.mgm-iconpop{position:fixed;z-index:400;background:#fff;border:1px solid #f0e4e9;border-radius:14px;box-shadow:var(--sh-l, 0 24px 60px -22px rgba(42,34,40,.4));padding:12px;width:236px}
.mgm-icongrid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.mgm-iconopt{width:28px;height:28px;border-radius:8px;font-size:16px;display:grid;place-items:center;cursor:pointer;transition:background .1s}
.mgm-iconopt:hover{background:#FFF0F4}
.mgm-iconcustom{display:flex;gap:6px;margin-top:10px;padding-top:10px;border-top:1px solid #f2f5f8}
.mgm-iconcustom input{flex:1;min-width:0;border:1px solid #dfe5ec;border-radius:8px;padding:6px 8px;font:400 12.5px inherit}
.mgm-iconclear{display:block;width:100%;text-align:center;margin-top:8px;font-size:12px;color:#8C828A;background:none;border:none;padding:5px;cursor:pointer;border-radius:6px}
.mgm-iconclear:hover{background:#f5eef0;color:#2A2228}
.mgm-slotboxes{display:flex;flex-direction:column;gap:9px;flex:none}
.mgm-slotbox{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:500;color:#2A2228;cursor:pointer}
.mgm-slotbox input[type=checkbox]{width:17px;height:17px;accent-color:#E0567B;cursor:pointer}

.mmrange input{width:130px}
.mmrange i{font-style:normal;font-size:12px;color:#7b8697;min-width:44px;text-align:right}
.mmcol{display:flex;align-items:center;gap:8px}
.mmcol input{width:34px;height:26px;border:1px solid #dfe5ec;border-radius:6px;padding:0;background:none}
.mmcol code{font-size:11px;color:#7b8697}
.mmpv{position:sticky;top:16px}
.mmpv-in{background:#eef1f5;border-radius:18px;padding:16px;display:grid;place-items:center}
.mmpv-note{text-align:center;font-size:11.5px;color:#7b8697;margin:8px 0 0}
/* Growth & Marketing · Newsletter. New class names throughout — nl- is unused in
   this file, and reusing an existing family is what made the menu icon fight. */
.nlstats{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;margin:0 0 14px}
.nlstat{background:#fff;border:1px solid #e6e9ef;border-radius:10px;padding:10px 14px;min-width:104px}
.nlstat b{display:block;font-size:19px;font-weight:800;letter-spacing:-.02em}
.nlstat span{font-size:11px;color:#7b8697}
.nlstat.wide{flex:1;min-width:180px}
.nlstat.wide b{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nlprev{border-radius:14px;padding:20px 16px;text-align:center}
.nlprev-k{font-size:10px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}
.nlprev-h{font-size:16px;font-weight:800;margin:8px 0 5px;letter-spacing:-.02em;color:#1d2430}
.nlprev-s{font-size:11.5px;color:#5c6675;line-height:1.45}
.nlprev-f{display:flex;gap:6px;margin:12px 0 0}
.nlprev-f span{flex:1;background:#fff;border-radius:99px;padding:8px 12px;font-size:11px;color:#9aa3b0;text-align:left}
.nlprev-f b{border-radius:99px;padding:8px 14px;font-size:11px;font-weight:700}
.nlprev-n{font-size:11px;font-weight:600;margin:10px 0 0}
.nlwarn{background:#FFF8E6;border:1px solid #F0DFB0;border-radius:10px;padding:10px 13px;font-size:11.5px;color:#7a5c14;margin:0 0 12px;line-height:1.5}
/* Appearance · Cart panel preview. cpp- prefix; nothing else in this file uses it. */
.cpp{background:#fff;border:1px solid #e6e9ef;border-radius:12px;overflow:hidden;margin:0 auto;
     display:flex;flex-direction:column;max-width:100%}
.cpp-tabs{display:flex;align-items:center;gap:4px;padding:0 6px;border-bottom:1px solid #eef1f6}
.cpp-tabs b{flex:1;text-align:center;font-size:11px;font-weight:800;padding:9px 4px;border-bottom:2px solid}
.cpp-tabs i{flex:1;text-align:center;font-size:11px;font-weight:700;color:#9aa3b0;font-style:normal;padding:9px 4px}
.cpp-tabs u{color:#9aa3b0;text-decoration:none;font-size:11px;padding:0 4px}
.cpp-ship{padding:8px var(--pad);border-bottom:1px solid #eef1f6;font-size:10.5px;color:#5c6675}
.cpp-bar{height:5px;border-radius:5px;background:#f0e2e8;overflow:hidden;margin-top:5px}
.cpp-bar div{height:100%;width:100%}
.cpp-body{padding:6px var(--pad);max-height:230px;overflow:auto}
.cpp-item{display:flex;gap:8px;align-items:center;padding:var(--rowpad) 0;border-bottom:1px solid #f2f4f8}
.cpp-item:last-child{border-bottom:0}
.cpp-th{width:var(--thumb);height:var(--thumb);border-radius:8px;flex:none;display:grid;place-items:center;
        color:#fff;font-weight:700;font-size:9px}
.cpp-mid{flex:1;min-width:0}
.cpp-nm{font-size:var(--nm);font-weight:600;line-height:1.25;margin-bottom:4px;
        display:-webkit-box;-webkit-line-clamp:var(--lines);-webkit-box-orient:vertical;overflow:hidden}
.cpp-qty{display:inline-flex;align-items:center;border:1px solid #e6e9ef;border-radius:7px;overflow:hidden}
.cpp-qty span,.cpp-qty b{width:var(--step);height:var(--step);display:grid;place-items:center;font-size:10.5px;font-weight:600}
.cpp-right{text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex:none}
.cpp-rm{color:#c3b3bb;font-size:11px}
.cpp-pr{font-size:11px;font-weight:800;color:var(--acc)}
.cpp-promo{padding:7px var(--pad);background:#fff0f4;font-size:10px;color:#5e545a}
.cpp-foot{border-top:1px solid #eef1f6;padding:10px var(--pad)}
.cpp-sum{display:flex;justify-content:space-between;font-size:12px;font-weight:700;margin-bottom:8px}
.cpp-btns{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.cpp-btns a{text-align:center;padding:8px;border-radius:99px;font-size:10.5px;font-weight:700;
            border:1px solid #e6e9ef;color:#1d2430}
/* Appearance · Section dividers. dv- prefix; unused elsewhere in this file. */
.dvsec{padding:10px 0}
.dvsec.off{opacity:.5}
.dvsec-hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 9px}
.dvsec-hd b{font-size:12px}
.dvsec-hd span{font-size:10.5px;color:#8b94a3;flex:1;min-width:140px}
.dvsec-act{display:flex;gap:5px}
.dvsec-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px}
.dvchk{display:flex;align-items:center;gap:7px;border:1px solid #e6e9ef;border-radius:8px;
       padding:6px 9px;font-size:11.5px;background:#fff;cursor:pointer}
.dvchk.on{border-color:#2f9e63;background:#F4FBF6}
.dvchk input{accent-color:#2f9e63;margin:0}
.dvsec-note{font-size:10.5px;color:#8b94a3;margin:8px 0 0}
.dvp{background:#FDEFF3;border:1px solid #e6e9ef;border-radius:10px;overflow:hidden;padding:0 0 4px}
.dvp-sec{position:relative;padding:14px 12px 12px}
.dvp-h{font-size:11.5px;font-weight:800;margin:0 0 8px}
.dvp-g{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.dvp-g i{display:block;height:44px;border-radius:8px;background:#fff;border:1px solid rgba(42,34,40,.08)}
.dvp-note{font-size:10.5px;color:#7b8697;margin:8px 0 0;text-align:center}
.dvp-ticks .dvp-sec + .dvp-sec::before,
.dvp-ticks .dvp-sec + .dvp-sec::after{content:'';position:absolute;top:0;width:var(--dv-len,26px);
  height:var(--dv-w,2px);border-radius:var(--dv-w,2px)}
.dvp-ticks .dvp-sec + .dvp-sec::before{left:var(--dv-in,12px);
  background:linear-gradient(90deg,var(--dv-col,#C13E63),transparent)}
.dvp-ticks .dvp-sec + .dvp-sec::after{right:var(--dv-in,12px);
  background:linear-gradient(270deg,var(--dv-col,#C13E63),transparent)}
.dvp-hairline .dvp-sec + .dvp-sec::before,
.dvp-breathe .dvp-sec + .dvp-sec::before{content:'';position:absolute;top:0;left:var(--dv-in,12px);
  right:var(--dv-in,12px);height:1px;opacity:.4;
  background:linear-gradient(90deg,transparent,var(--dv-col,#C13E63) 22%,var(--dv-col,#C13E63) 78%,transparent)}
.dvp-stitch .dvp-sec + .dvp-sec::before{content:'';position:absolute;top:0;left:var(--dv-in,12px);
  right:var(--dv-in,12px);height:var(--dv-w,2px);opacity:.5;
  background-image:linear-gradient(90deg,var(--dv-col,#C13E63) 0 8px,transparent 8px 16px);
  background-size:16px var(--dv-w,2px)}
.dvp-drift .dvp-sec + .dvp-sec::before{content:'';position:absolute;top:-4px;left:0;right:0;height:9px;opacity:.5;
  background-image:radial-gradient(circle,var(--dv-col,#C13E63) 0 46%,transparent 47%);
  background-size:9px 9px;background-repeat:repeat-x}
.dvp-petal .dvp-sec + .dvp-sec::before{content:'';position:absolute;top:2px;left:50%;margin-left:-8px;
  width:16px;height:13px;border-radius:50%;background:radial-gradient(circle at 50% 62%,#EFA9BC 0 34%,transparent 35%),
  radial-gradient(circle at 26% 34%,#F7C6D4 0 30%,transparent 31%),
  radial-gradient(circle at 74% 34%,#F7C6D4 0 30%,transparent 31%)}
.dvp-gradient .dvp-sec + .dvp-sec::before{content:'';position:absolute;top:0;left:0;right:0;
  height:var(--dv-w,2px);opacity:.6;
  background:linear-gradient(90deg,#E0567B,#EFAF6B,#B9D6C1,#E0567B)}
/* Appearance · Mobile Header preview. mhp- prefix; unused elsewhere. The
   divider rules mirror the storefront ones in kbb.css so the two cannot drift. */
.mhp{width:250px;margin:0 auto;border-radius:14px;overflow:hidden;border:1px solid #e6e9ef;
     background:#FDEFF3}
.mhp-hdr{background:rgba(255,250,252,.96);border-bottom:1px solid rgba(42,34,40,.10)}
.mhp-wrap{padding-left:var(--mh-l,22px);padding-right:var(--mh-r,22px)}
.mhp-in{display:flex;align-items:center;flex-wrap:wrap;row-gap:0;
        gap:var(--mh-igap,10px);padding:var(--mh-t,10px) 0 var(--mh-b,10px)}
.mhp-bg{width:26px;height:26px;border-radius:6px;background:#EFE6EA;flex:none}
.mhp-logo{flex:1;text-align:center;font-size:13px;font-weight:800;letter-spacing:-.02em;color:#2A2228}
.mhp-logo b{color:#C13E63}
.mhp-act{display:flex;gap:4px;flex:none}
.mhp-act i{width:22px;height:22px;border-radius:50%;background:#EFE6EA;display:block}
.mhp-sbox{order:99;flex:0 0 100%;position:relative;margin:var(--mh-gap,0) 0 var(--mh-sgap,10px)}
.mhp-sbox em{display:flex;align-items:center;gap:7px;border:1px solid #E6DADF;
             background:var(--mh-sbg,#fff);border-radius:var(--mh-srad,9px);
             padding:7px var(--mh-spad,10px);font-size:10.5px;font-style:normal;
             color:var(--mh-sph,#9aa3b0)}
.mhp-sbox em::before{content:'';width:11px;height:11px;border:1.6px solid var(--mh-sicon,#9aa3b0);
                     border-radius:50%;flex:none}
.mhp-mg{display:none}
.mhp-snb .mhp-sbox em{border-color:transparent}
.mhp-sfull .mhp-sbox em{margin-left:calc(var(--mh-l,22px) * -1);margin-right:calc(var(--mh-r,22px) * -1);
  border-radius:0;border-left:0;border-right:0;
  padding-left:var(--mh-l,22px);padding-right:var(--mh-r,22px)}
.mhp-skeep .mhp-sbox em{padding-left:calc(var(--mh-l,22px) + var(--mh-spad,16px));
  padding-right:calc(var(--mh-r,22px) + var(--mh-spad,16px))}
.mhp-hair .mhp-sbox::before{content:'';position:absolute;left:var(--mh-dvin,0);right:var(--mh-dvin,0);
  top:calc(var(--mh-gap,0px) / -2 - var(--mh-dvw,1px));height:var(--mh-dvw,1px);
  background:linear-gradient(90deg,transparent,var(--mh-dv) 22%,var(--mh-dv) 78%,transparent)}
.mhp-full .mhp-sbox::before{content:'';position:absolute;left:calc(var(--mh-l,22px) * -1);
  right:calc(var(--mh-r,22px) * -1);top:calc(var(--mh-gap,0px) / -2 - var(--mh-dvw,1px));
  height:var(--mh-dvw,1px);background:var(--mh-dv)}
.mhp-ticks .mhp-sbox::before,.mhp-ticks .mhp-sbox::after{content:'';position:absolute;
  top:calc(var(--mh-gap,0px) / -2 - var(--mh-dvw,2px));width:var(--mh-dvlen,26px);
  height:var(--mh-dvw,2px);border-radius:var(--mh-dvw,2px)}
.mhp-ticks .mhp-sbox::before{left:var(--mh-dvin,0);background:linear-gradient(90deg,var(--mh-dv),transparent)}
.mhp-ticks .mhp-sbox::after{right:var(--mh-dvin,0);background:linear-gradient(270deg,var(--mh-dv),transparent)}
.mhp-soft .mhp-sbox::before{content:'';position:absolute;left:calc(var(--mh-l,22px) * -1);
  right:calc(var(--mh-r,22px) * -1);top:calc(var(--mh-gap,0px) * -1 - 8px);height:8px;
  background:linear-gradient(180deg,var(--mh-dv),transparent)}
.mhp-page{padding:8px 0 12px}
.mhp-sec{padding:0 12px}
.mhp-sec b{display:block;font-size:11px;font-weight:800;margin:0 0 7px}
.mhp-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.mhp-grid u{display:block;height:46px;border-radius:8px;background:#fff;border:1px solid rgba(42,34,40,.08)}
.mmrow.dim{opacity:.45}
/* Store · Modules. md- prefix; unused elsewhere in this file. */
.mdtools{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;align-items:center}
.mdtools input{flex:1;min-width:180px;border:1px solid #dfe4ec;border-radius:8px;padding:7px 11px;font-size:12px}
/* Two columns wide, one narrow — the same 1080px breakpoint the other admin
   grids use, so the console changes shape once rather than twice. */
.mdcols{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}
.mdcol{min-width:0}
@media (max-width:1080px){.mdcols{grid-template-columns:1fr;gap:0}}
.mdcard{margin:0 0 12px}
.mdbody{padding:2px 14px 8px}
.mdrow{display:flex;align-items:flex-start;gap:12px;padding:11px 0;border-bottom:1px solid #f2f4f8}
.mdrow:last-child{border-bottom:0}
.mdrow .ectog{margin-top:2px;flex:none}
.mdlbl{flex:1;min-width:0}
.mdlbl b{font-size:12.5px;font-weight:700}
.mdlbl span{display:block;font-size:11px;color:#7b8697;line-height:1.45;margin:2px 0 0;max-width:70ch}
.mdoff{font-style:normal;background:#F1F3F7;color:#7b8697;border-radius:99px;padding:1px 7px;
       font-size:9.5px;font-weight:800;letter-spacing:.06em;margin-left:7px;vertical-align:middle}
.mdlink{display:block;font-style:normal;font-size:10.5px;color:#2F7D51;margin:5px 0 0;font-weight:600}
.mdlink.none{color:#9aa3b0;font-weight:500}
.mdlink.soon{color:#b08243}
a.mdlink.go{display:inline-flex;align-items:center;gap:5px;text-decoration:none;
            border:1px solid #CFE7DA;background:#F4FBF7;border-radius:99px;
            padding:3px 9px 3px 7px;margin-top:6px}
a.mdlink.go svg{width:11px;height:11px}
a.mdlink.go:hover{background:#2F7D51;border-color:#2F7D51;color:#fff}
.mdpop-l.none{color:#b08243}
.mddev{border:1px solid #dfe4ec;border-radius:7px;padding:5px 8px;font-size:11px;background:#fff;flex:none}
.mddev:disabled{opacity:.4}
/* Group heading: the count, then the three bulk buttons for that group only.
   They wrap under the count on a narrow screen rather than squeezing the label. */
.mdghd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.mdghd em{font-style:normal;font-size:11px;color:#7b8697;white-space:nowrap}
.mdgbtns{display:flex;gap:4px}
.mdgbtns .btn{padding:3px 8px;font-size:10.5px;border-radius:7px;border:1px solid #dfe4ec;
              background:#fff;color:#1d2430;font-weight:700}
.mdgbtns .btn:hover{background:#1d2430;color:#fff;border-color:#1d2430}
/* A module whose switch is not wired to anything yet. */
.mdrow.inert{opacity:.62}
.mdrow.inert .ectog{cursor:default}
.ectog.off{background:#dfe4ec}
.mdstat{font-style:normal;border-radius:99px;padding:1px 7px;font-size:9.5px;font-weight:800;
        letter-spacing:.06em;margin-left:7px;vertical-align:middle;white-space:nowrap}
.mdstat.where{background:#EAF2FB;color:#31628f}
.mdstat.todo{background:#FDF3E3;color:#8a6416}
/* Growth & Marketing · Product Labels preview. pl- prefix; unused elsewhere. */
.plgrid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.plc{background:#fff;border:1px solid #e6e9ef;border-radius:10px;overflow:hidden}
.plc.off{opacity:.42}
.plc-im{height:56px;position:relative;background:linear-gradient(140deg,#FFF1F5,#FFE3EC)}
.plc-im .lbl{position:absolute;top:7px;left:7px;color:#fff;border-radius:99px;
             padding:3px 8px;font-size:9.5px;font-weight:800;letter-spacing:.02em}
.plc-cap{padding:6px 8px;font-size:10px;color:#7b8697;line-height:1.35}
/* Store · Payment & Shipping Rules. ps- prefix; unused elsewhere. */
.psmoney{display:inline-flex;align-items:center;gap:6px}
.psmoney i{font-style:normal;font-size:11px;color:#7b8697}
.psmoney input{width:96px;border:1px solid #dfe4ec;border-radius:7px;padding:5px 9px;font-size:12px}
.pspv{display:flex;flex-direction:column;gap:6px}
.psrow{display:flex;align-items:center;justify-content:space-between;gap:8px;border-radius:8px;
       padding:8px 10px;font-size:11px}
.psrow b{font-size:12px}
.psrow.yes{background:#F1FAF4;border:1px solid #CFE7DA;color:#2F7D51}
.psrow.no{background:#FFF3F5;border:1px solid #F2CDD5;color:#a33049}
/* Store · Delivery & Shipping. sh- prefix; unused elsewhere. */
.shgrid{display:grid;grid-template-columns:1fr 230px;gap:14px;padding:10px 14px 14px}
@media (max-width:900px){.shgrid{grid-template-columns:1fr}}
.shbody{display:flex;flex-direction:column;gap:2px}
.shrow{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #f2f4f8}
.shrow:last-child{border-bottom:0}
.shrow.off{opacity:.5}
.shrow .ectog{flex:none}
.shlbl{flex:1;min-width:0}
.shlbl input{width:100%;max-width:230px;border:1px solid #dfe4ec;border-radius:7px;
             padding:5px 9px;font-size:12px;font-weight:600}
.shlbl span{display:block;font-size:10.5px;color:#8b94a3;margin-top:3px}
.shamt{display:inline-flex;align-items:center;gap:6px;flex:none}
.shamt i{font-style:normal;font-size:11px;color:#7b8697}
.shamt input{width:88px;border:1px solid #dfe4ec;border-radius:7px;padding:5px 9px;font-size:12px;text-align:right}
.shamt u{text-decoration:none;font-size:10.5px;color:#8b94a3;white-space:nowrap}
.shpv-wrap{background:#F7F8FB;border:1px solid #eef1f6;border-radius:10px;padding:10px}
.shpv{display:flex;flex-direction:column;gap:6px}
.shpv-r{display:flex;align-items:center;justify-content:space-between;gap:8px;
        background:#fff;border:1px solid #e6e9ef;border-radius:8px;padding:7px 9px;font-size:11px}
.shpv-r b{font-size:12px}
.shpv-r.yes{background:#F1FAF4;border-color:#CFE7DA;color:#2F7D51}
/* Extended delivery. xd- and c- prefixes; unused elsewhere in this file. */
.cpick{border:1px solid #e6e9ef;border-radius:10px;overflow:hidden;background:#fff}
.cpick-hd{display:flex;gap:8px;align-items:center;padding:9px 11px;border-bottom:1px solid #eef1f6;flex-wrap:wrap}
.cpick-hd input{flex:1;min-width:160px;border:1px solid #dfe4ec;border-radius:7px;padding:6px 10px;font-size:11.5px}
.chips{display:flex;gap:4px;flex-wrap:wrap}
.chip{border:1px solid #dfe4ec;background:#fff;border-radius:99px;padding:3px 9px;
      font-size:10.5px;font-weight:700;color:#5c6675}
.chip:hover{background:#1d2430;color:#fff;border-color:#1d2430}
.cpick-b{max-height:210px;overflow:auto;padding:9px 11px;
         display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:5px}
.cchk{display:flex;align-items:center;gap:7px;border:1px solid #e6e9ef;border-radius:8px;
      padding:5px 9px;font-size:11.5px;background:#fff;cursor:pointer}
.cchk.on{border-color:#2f9e63;background:#F4FBF6}
.cchk input{accent-color:#2f9e63;margin:0;flex:none}
.crow{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f2f4f8;flex-wrap:wrap}
.crow:last-child{border-bottom:0}
.cname{flex:1;min-width:140px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:7px}
.cname em{font-style:normal;font-size:9.5px;font-weight:800;letter-spacing:.08em;color:#9aa3b0;
          background:#F1F3F7;border-radius:99px;padding:1px 6px}
.cfield{display:inline-flex;align-items:center;gap:5px}
.cfield i{font-style:normal;font-size:10px;color:#8b94a3;white-space:nowrap}
.cfield input{width:78px;border:1px solid #dfe4ec;border-radius:7px;padding:5px 8px;
              font-size:11.5px;text-align:right;background:#fff}
.cfield input.wide{width:110px;text-align:left}
.crm{border:0;background:none;color:#c3b3bb;font-size:13px;padding:0 2px}
.crm:hover{color:#b4243c}
.card.dim{opacity:.5}
.ectabs-hint{font-size:11px;color:#7b8697;margin:8px 0 14px}
@media (max-width:520px){.mmhd{flex-wrap:wrap;gap:6px}.mdghd{width:100%;justify-content:flex-start}}
/* The where-does-this-show icon and its card. Hover and keyboard focus both
   open it, so it is reachable without a mouse. */
.mdeye{position:relative;flex:none;width:26px;height:26px;border-radius:50%;display:grid;
       place-items:center;color:#9aa3b0;border:1px solid #e6e9ef;background:#fff;cursor:help;margin-top:1px}
.mdeye svg{width:14px;height:14px}
.mdeye:hover,.mdeye:focus{color:#C13E63;border-color:#F0C8D6;background:#FFF7FA;outline:none}
.mdpop{position:absolute;right:0;top:32px;z-index:40;width:236px;background:#fff;
       border:1px solid #e6e9ef;border-radius:12px;padding:11px;
       box-shadow:0 18px 40px -18px rgba(16,24,40,.45);
       opacity:0;visibility:hidden;transform:translateY(-4px);transition:.14s ease;
       text-align:left;pointer-events:none}
.mdeye:hover .mdpop,.mdeye:focus .mdpop{opacity:1;visibility:visible;transform:none}
.mdpop-h{display:block;font-size:12px;font-weight:800;color:#1d2430}
.mdpop-s{display:block;font-size:9.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;
         color:#C13E63;margin:1px 0 8px}
.mdpop-t{display:block;font-size:11px;color:#5c6675;line-height:1.45;margin:8px 0 0}
.mdpop-l{display:block;font-size:10px;color:#2F7D51;font-weight:600;margin:6px 0 0}

/* Wireframes. Grey is the page; pink is where the module puts something. */
.mdw{background:#F7F8FB;border:1px solid #eef1f6;border-radius:8px;padding:6px;display:flex;
     flex-direction:column;gap:4px}
.mdw span,.mdw i,.mdw u{display:block;border-radius:3px;background:#E3E7EE}
.mdw .lit,.mdw .lit i,.mdw .lit u{background:#F3A7BF}
.mdw-hd{height:11px}
.mdw-strip{height:6px}
.mdw-nav{height:7px}
.mdw-hero{height:26px}
.mdw-b{height:7px}
.mdw-b.short{width:60%}
.mdw-ft{height:9px}
.mdw-cta{height:10px;border-radius:99px}
.mdw-mid{display:flex;gap:4px;padding:0;background:none;border-radius:3px}
.mdw-mid i{flex:1;height:22px}
.mdw-mid.lit{background:rgba(243,167,191,.35);padding:3px;margin:-3px}
.mdw-tabs{height:9px}
.mdw-list{display:flex;flex-direction:column;gap:3px;background:none}
.mdw-list i{height:9px}
.mdw-list.lit{background:rgba(243,167,191,.35);padding:3px;margin:-3px;border-radius:5px}
.mdw-cols{display:flex;gap:5px}
.mdw-main{flex:1;display:flex;flex-direction:column;gap:4px}
.mdw-aside{width:56px;display:flex;flex-direction:column;gap:3px;background:none}
.mdw-aside i{height:8px}
.mdw-aside.lit{background:rgba(243,167,191,.35);padding:3px;margin:-3px;border-radius:5px}
.mdw-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px}
.mdw-card{background:#fff;border:1px solid #E3E7EE;border-radius:6px;padding:4px;display:flex;
          flex-direction:column;gap:3px}
.mdw-card i{height:22px}
.mdw-card u{height:5px;width:70%}
.mdw-card.lit{border-color:#F3A7BF;box-shadow:0 0 0 2px rgba(243,167,191,.35)}
.mdw-card.lit i{background:#E3E7EE}
.mdw-none{display:block;font-style:normal;text-align:center;font-size:9px;font-weight:800;
          letter-spacing:.1em;text-transform:uppercase;color:#9aa3b0;background:none;margin:2px 0 0}
/* Near the foot of a long list the card would fall off the page. */
/* Near the foot of a column the card would fall off the page, so it opens
   upward instead. Scoped to the last card in each column now there are two. */
.mdcol > .mdcard:last-child .mdrow:nth-last-child(-n+2) .mdpop{top:auto;bottom:32px;transform:translateY(4px)}
.mdcol > .mdcard:last-child .mdrow:nth-last-child(-n+2) .mdeye:hover .mdpop,
.mdcol > .mdcard:last-child .mdrow:nth-last-child(-n+2) .mdeye:focus .mdpop{transform:none}
.pvphone{width:270px;height:480px;background:#fff;border-radius:22px;position:relative;overflow:hidden;
  box-shadow:0 12px 30px -12px rgba(16,24,40,.3)}
.pvscrim{position:absolute;inset:0;background:var(--mm-scrim,rgba(42,34,40,.5))}
.pvmenu{position:absolute;left:0;right:0;bottom:0;top:var(--mm-top,20%);background:#fff;
  border-radius:var(--mm-radius,20px) var(--mm-radius,20px) 0 0;display:flex;flex-direction:column;overflow:hidden}
.pvmenu .mm-body{flex:1;overflow-y:auto}
.pvmenu .mm-srch input{width:100%;border:1px solid #F0E8EB;background:#F8F3F5;border-radius:9px;padding:7px 10px;font-size:11.5px}
.pvmenu .mm-srch{padding:6px 10px}
.pvmenu .mm-x{position:absolute;top:6px;right:8px;width:24px;height:24px;border:0;border-radius:50%;background:#F8F3F5;cursor:default}
/* The rest of the sheet. mmPreview() has always emitted these class names, but
   only .mm-body, .mm-srch and .mm-x were ever styled here — the other twelve had
   no rule at all, so the preview rendered as a run of unstyled text. The admin
   shell cannot load the storefront sheet, so the values below are copied from
   the real rules in kbb.css rather than invented, and read the same custom
   properties mmPreview() already sets. */
.pvmenu .mm-grab{width:38px;height:4px;border-radius:99px;background:#EFE6EA;margin:9px auto 4px;flex:none}
.pvmenu .mm-head{padding:9px 12px;border-bottom:1px solid #F3E9EC;flex:none}
.pvmenu .mm-head b{font-size:12.5px}
.pvmenu .mm-node{display:block}
.pvmenu .mm-grp{font-size:9px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
  color:#9aa3b0;padding:9px 12px 3px}
.pvmenu .mm-it{display:flex;align-items:center;gap:8px;width:100%;text-align:left;border:0;background:none;
  padding:var(--mm-pad,8px) 12px;border-bottom:1px solid #F8F3F5;
  font:400 var(--mm-size,13px)/1.3 inherit;color:#2A2228}
.pvmenu .mm-it.hot{color:#E23B57;font-weight:700}
.pvmenu .mm-par{background:var(--mm-parent-bg,transparent);color:var(--mm-parent-fg,#2A2228)}
.pvmenu .mm-ct{margin-left:auto;font-size:10px;color:#9aa3b0;font-weight:500}
.pvmenu.mm-nocounts .mm-ct{display:none}
.pvmenu .mm-car{color:#9aa3b0;font-size:14px;margin-left:6px}
.pvmenu .mm-node.on > .mm-par .mm-car{transform:rotate(90deg)}
.pvmenu .mm-node .mm-kid{display:none}
.pvmenu .mm-node.on > .mm-kid{display:block;position:relative;padding-left:var(--mm-rule-w,3px);
  background:var(--mm-card,#FFF9FB)}
.pvmenu .mm-node.on > .mm-kid::before{content:'';position:absolute;left:0;top:0;bottom:0;
  width:var(--mm-rule-w,3px);background:var(--mm-rule,#E0567B)}
.pvmenu .mm-c2{display:grid;grid-template-columns:var(--mm-cols,1fr 1fr)}
.pvmenu .mm-si{display:block;padding:6px 10px;font-size:calc(var(--mm-size,13px) - 1.5px);line-height:1.3;
  border-bottom:1px solid #F3E9EC;border-right:1px solid #F3E9EC;color:#2A2228;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pvmenu .mm-si:nth-child(2n){border-right:0}
.pvmenu .mm-foot{flex:none;border-top:1px solid #F3E9EC;padding:8px 10px}
.pvmenu .mm-wa{display:flex;align-items:center;justify-content:center;gap:6px;background:#E8F7EE;
  color:#1F9D55;border-radius:9px;padding:7px 10px;font-size:10.5px;font-weight:700}
@media (max-width:1080px){.mmgrid{grid-template-columns:1fr}.mmpv{position:static}}

/* header preview */
.hdpv{background:var(--hd-bg,#fff);border-radius:12px;overflow:hidden;width:100%;color:#2A2228}
.hdpv-bar{display:flex;align-items:center;gap:10px;padding:0 12px;min-height:var(--hd-h,64px)}
.hdpv-bar.bd{border-bottom:1px solid #F0E8EB}
.hdpv-logo{font-size:var(--hd-logo,22px);font-weight:700;color:var(--hd-logo-c,#2A2228);flex:1;letter-spacing:-.02em}
.hdpv-logo em{font-style:normal;color:var(--hd-logo-a,#E0567B)}
.hdpv-icons{display:flex;gap:8px}
.hdpv-icons i{font-style:normal;font-size:calc(var(--hd-icon,21px) * .8);position:relative}
.hdpv-icons i.bg::after{content:"2";position:absolute;top:-4px;right:-6px;background:var(--hd-badge,#E0567B);
  color:#fff;font-size:8px;border-radius:99px;padding:1px 4px}
.hdpv-srch{padding:8px 12px}
.hdpv-srch span{display:block;border:1px solid #F0E8EB;background:#F8F3F5;border-radius:var(--hd-radius,99px);
  padding:8px 12px;font-size:11px;color:#9A8D94}
.hdpv-trend{display:flex;gap:6px;padding:0 12px 8px;flex-wrap:wrap}
.hdpv-trend span{font-size:10px;color:#6A5C64;background:#F8F3F5;border-radius:99px;padding:3px 8px}
.hdpv-nav{display:flex;gap:var(--hd-gap,26px);padding:8px 12px;border-top:1px solid #F8F3F5;overflow:hidden}
.hdpv-nav span{font-size:calc(var(--hd-nav,14px) * .8);white-space:nowrap}
.hdpv-nav .hot{color:var(--hd-hot,#E23B57);font-weight:700}
.hdpv-sup{display:flex;align-items:center;gap:7px;padding:8px 12px;font-size:11px;border-top:1px solid #F8F3F5}
.hdpv-sup i{width:20px;height:20px;border-radius:50%;background:var(--hd-sup-bg,#E8F7EE);display:block}

/* ── card preview styles ──
   Inlined rather than linked: this whole template is raw output, so a
   Blade asset directive would render as literal text and load nothing.
   Generated from resources/css/kbb/admin-skin-preview.css. */

/* Skin previews for the admin — generated from kbb-grid-skins.css. */
.skinprev *{box-sizing:border-box}
.skinprev .kbb-pgrid{gap:20px}
.skinprev .kbb-pgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.skinprev .kbb-card{border:1px solid rgba(42,34,40,.06);border-radius:14px;overflow:hidden;text-decoration:none;color:#2A2228;display:block}
.skinprev .kbb-card img{width:100%;aspect-ratio:1;object-fit:cover;background:#FFF8F5}
.skinprev .kbb-card .cb{padding:10px}
.skinprev .kbb-card .cn{font-size:13px;font-weight:600;font-family:'Poppins',sans-serif}
.skinprev .kbb-card .cp{font-size:13px;color:#C13E63;font-weight:700;margin-top:3px}
.skinprev .kbb-card{background:#fff;transition:.18s}
.skinprev .kbb-card-thumb{position:relative;aspect-ratio:1;background:#FFF8F5;overflow:hidden}
.skinprev .kbb-card-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.skinprev .kbb-badge{position:absolute;top:9px;font-size:9.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:4px 9px;border-radius:20px;font-family:'Poppins',sans-serif;color:#fff;z-index:2;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.skinprev .kbb-badge-new{left:9px;background:linear-gradient(135deg,#1cc36a,#12965a)}
.skinprev .kbb-badge-sale{right:9px;background:linear-gradient(135deg,#ff6f91,#C13E63)}
.skinprev .kbb-card-cat{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8C828A;font-weight:600;margin-bottom:2px}
.skinprev .kbb-card-brand{color:#8C828A;font-weight:700}
.skinprev .kbb-card-sdesc{font-size:11.5px;color:#8C828A;margin-top:3px;line-height:1.4}
.skinprev .kbb-card-rate{margin-top:4px;display:flex;align-items:center;gap:4px;font-size:12px}
.skinprev .kbb-crate{letter-spacing:1px}
.skinprev .kbb-cstar{color:#E3DADF}
.skinprev .kbb-cstar.on{color:#FFB400}
.skinprev .kbb-card-rc{color:#8C828A;font-size:11px}
.skinprev .kbb-card-reg{color:#b3aab0;font-weight:500;text-decoration:line-through;font-size:12px}
.skinprev .kbb-card-price{color:#C13E63;font-weight:700}
.skinprev .kbb-card-cart{margin-top:8px;display:block;text-align:center;border:0;background:#E0567B;color:#fff;border-radius:9px;padding:8px;font-weight:600;font-size:12px;cursor:pointer;font-family:'Poppins',sans-serif}
.skinprev .kbb-pgrid .kbb-card{display:flex;flex-direction:column;height:100%}
.skinprev .kbb-pgrid .kbb-card>.kbb-card-thumb{flex:none}
.skinprev .kbb-pgrid .kbb-card>.cb{flex:1;display:flex;flex-direction:column}
.skinprev .kbb-pgrid .kbb-card>.cb>.cp{margin-top:auto}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card{position:relative;border:0;border-radius:16px;overflow:hidden}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-thumb{aspect-ratio:3/4}
.skinprev .kbb-pgrid[data-skin="overlay"] .cb{position:absolute;left:0;right:0;bottom:0;padding:14px;background:linear-gradient(to top,rgba(0,0,0,.82),rgba(0,0,0,.15) 62%,transparent)}
.skinprev .kbb-pgrid[data-skin="overlay"] .cn,.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-brand,.skinprev .kbb-pgrid[data-skin="overlay"] .cp,.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-price{color:#fff}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-cat{color:rgba(255,255,255,.82)}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-reg{color:rgba(255,255,255,.6)}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-rc{color:rgba(255,255,255,.75)}
.skinprev .kbb-pgrid[data-skin="spotlight"] .kbb-card{border:0;border-radius:18px;box-shadow:0 10px 30px rgba(42,34,40,.10);overflow:hidden;transition:transform .25s,box-shadow .25s}
.skinprev .kbb-pgrid[data-skin="spotlight"] .kbb-card:hover{transform:translateY(-6px);box-shadow:0 20px 44px rgba(42,34,40,.16)}
.skinprev .kbb-pgrid[data-skin="spotlight"] .cp{display:inline-flex;align-items:center;gap:6px;background:#FFF0F4;padding:5px 12px;border-radius:20px;margin-top:8px}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card{border:0;background:none;text-align:center}
.skinprev .kbb-pgrid[data-skin="editorial"] .cb{padding:14px 6px}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card-brand{display:block;text-transform:uppercase;letter-spacing:.18em;font-size:10px;color:#8C828A;margin-bottom:4px}
.skinprev .kbb-pgrid[data-skin="editorial"] .cn{font-weight:500}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="editorial"] .cp{justify-content:center}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card-thumb{overflow:hidden}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card img,.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card .im{transition:transform .5s}
.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card:hover img,.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card:hover .im{transform:scale(1.06)}
.skinprev .kbb-pgrid[data-skin="minimal"] .kbb-card{border:0;background:none}
.skinprev .kbb-pgrid[data-skin="minimal"] .kbb-card-thumb{border-radius:12px}
.skinprev .kbb-pgrid[data-skin="minimal"] .cb{padding:12px 2px}
.skinprev .kbb-pgrid[data-skin="horizontal"]{grid-template-columns:repeat(auto-fill,minmax(min(100%,330px),1fr))!important}
.skinprev .kbb-pgrid[data-skin="horizontal"] .kbb-card{display:grid;grid-template-columns:40% 1fr;min-height:148px;border:1px solid rgba(42,34,40,.08);border-radius:14px;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="horizontal"] .kbb-card-thumb{aspect-ratio:auto;height:100%;border-radius:0}
.skinprev .kbb-pgrid[data-skin="horizontal"] .kbb-card-thumb img,.skinprev .kbb-pgrid[data-skin="horizontal"] .kbb-card-thumb .im{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.skinprev .kbb-pgrid[data-skin="horizontal"] .cb{padding:14px;justify-content:center}
.skinprev .kbb-pgrid .kbb-card{transition:transform .25s ease,box-shadow .25s ease}
.skinprev .kbb-pgrid .kbb-card-thumb img{transition:transform .6s cubic-bezier(.2,.7,.2,1)}
.skinprev .kbb-pgrid .kbb-card:hover .kbb-card-thumb img{transform:scale(1.06)}
.skinprev .kbb-pgrid[data-skin="classic"] .kbb-card:hover,.skinprev .kbb-pgrid[data-skin="minimal"] .kbb-card:hover,.skinprev .kbb-pgrid[data-skin="editorial"] .kbb-card:hover{box-shadow:0 14px 34px rgba(42,34,40,.12)}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card:hover{transform:translateY(-4px)}
.skinprev .kbb-pgrid[data-skin="editorial"] .cn{position:relative;padding-bottom:8px}
.skinprev .kbb-pgrid[data-skin="editorial"] .cn:after{content:'';position:absolute;left:50%;bottom:0;transform:translateX(-50%);width:26px;height:2px;background:var(--kbb-acc,#E0567B);opacity:.5}
.skinprev .kbb-pgrid[data-skin="classic"] .kbb-card{background:linear-gradient(180deg,#fff,#fffafc)}
.skinprev .kbb-pgrid[data-skin="overlay"] .cb{background:linear-gradient(to top,rgba(18,10,14,.9),rgba(18,10,14,.22) 55%,transparent)}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-brand{text-transform:uppercase;letter-spacing:.12em;font-size:10px;opacity:.92}
.skinprev .kbb-pgrid[data-skin="overlay"] .cn{font-size:14px;font-weight:600}
.skinprev .kbb-pgrid[data-skin="spotlight"] .kbb-card{background:#fff;box-shadow:0 6px 16px rgba(42,34,40,.07),0 16px 40px rgba(42,34,40,.09)}
.skinprev .kbb-pgrid[data-skin="soft"] .kbb-card{border:0;background:#FFF0F4;border-radius:18px;padding:10px}
.skinprev .kbb-pgrid[data-skin="soft"] .kbb-card-thumb{border-radius:12px;background:#fff}
.skinprev .kbb-pgrid[data-skin="soft"] .cb{padding:12px 6px 4px}
.skinprev .kbb-pgrid[data-skin="soft"] .kbb-card:hover{background:#ffe6ee;box-shadow:0 14px 32px rgba(224,86,123,.16);transform:translateY(-3px)}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card{border:0;background:#241b20;border-radius:16px;overflow:hidden;color:#fff}
.skinprev .kbb-pgrid[data-skin="bold"] .cn,.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-price{color:#fff}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-brand{color:#d9b8c4}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-cat{color:#9a8890}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-price{color:#ffcf8f}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-reg{color:rgba(255,255,255,.45)}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card-rc{color:rgba(255,255,255,.6)}
.skinprev .kbb-pgrid[data-skin="bold"] .kbb-card:hover{box-shadow:0 20px 44px rgba(36,27,32,.45)}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card{position:relative;border:0;border-radius:18px;overflow:hidden}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-thumb{aspect-ratio:3/4}
.skinprev .kbb-pgrid[data-skin="glass"] .cb{position:absolute;left:10px;right:10px;bottom:10px;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.16);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.35)}
.skinprev .kbb-pgrid[data-skin="glass"] .cn,.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-brand,.skinprev .kbb-pgrid[data-skin="glass"] .cp,.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-price{color:#fff}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-cat{color:rgba(255,255,255,.85)}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-reg{color:rgba(255,255,255,.65)}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-rc{color:rgba(255,255,255,.82)}
.skinprev .kbb-pgrid[data-skin="glass"] .kbb-card-cart{background:rgba(255,255,255,.9);color:#2A2228;margin-top:8px}
.skinprev .kbb-pgrid[data-skin="split"] .kbb-card{border:0;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 8px 22px rgba(42,34,40,.08)}
.skinprev .kbb-pgrid[data-skin="split"] .cb{background:#FFF0F4;padding:14px}
.skinprev .kbb-pgrid[data-skin="split"] .kbb-card-cat{color:#C13E63}
.skinprev .kbb-pgrid[data-skin="split"] .cp{display:inline-flex;align-items:center;gap:6px;background:#E0567B;padding:5px 12px;border-radius:20px;margin-top:8px}
.skinprev .kbb-pgrid[data-skin="split"] .cp .kbb-card-price{color:#fff}
.skinprev .kbb-pgrid[data-skin="split"] .cp .kbb-card-reg{color:rgba(255,255,255,.72)}
.skinprev .kbb-pgrid[data-skin="round"] .kbb-card{border:0;background:none;text-align:center}
.skinprev .kbb-pgrid[data-skin="round"] .kbb-card-thumb{width:66%;margin:8px auto 0;aspect-ratio:1;border-radius:50%;box-shadow:0 12px 28px rgba(42,34,40,.16)}
.skinprev .kbb-pgrid[data-skin="round"] .cb{padding:14px 8px}
.skinprev .kbb-pgrid[data-skin="round"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="round"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="round"] .kbb-badge{top:4px}
.skinprev .kbb-pgrid[data-skin="polaroid"] .kbb-card{background:#fff;border:0;border-radius:4px;padding:10px 10px 4px;box-shadow:0 6px 18px rgba(42,34,40,.12);transition:transform .3s,box-shadow .3s}
.skinprev .kbb-pgrid[data-skin="polaroid"] .kbb-card-thumb{border-radius:2px}
.skinprev .kbb-pgrid[data-skin="polaroid"] .cb{padding:12px 4px 10px;text-align:center}
.skinprev .kbb-pgrid[data-skin="polaroid"] .kbb-card:hover{transform:rotate(-1.5deg) translateY(-4px);box-shadow:0 16px 34px rgba(42,34,40,.2)}
.skinprev .kbb-pgrid[data-skin="polaroid"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="polaroid"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="stacked"] .kbb-card{border:0;background:none}
.skinprev .kbb-pgrid[data-skin="stacked"] .kbb-card-thumb{border-radius:16px;overflow:hidden}
.skinprev .kbb-pgrid[data-skin="stacked"] .cb{position:relative;margin:-42px 12px 0;background:#fff;border-radius:14px;padding:14px;box-shadow:0 10px 28px rgba(42,34,40,.14);transition:transform .25s}
.skinprev .kbb-pgrid[data-skin="stacked"] .kbb-card:hover .cb{transform:translateY(-4px)}
.skinprev .kbb-pgrid[data-skin="petal"] .kbb-card{border:0;background:#FFF4F8;border-radius:20px;padding:12px;text-align:center}
.skinprev .kbb-pgrid[data-skin="petal"] .kbb-card-thumb{border-radius:48% 52% 46% 54% / 56% 46% 54% 44%;background:#fff;transition:border-radius .6s ease}
.skinprev .kbb-pgrid[data-skin="petal"] .kbb-card:hover .kbb-card-thumb{border-radius:54% 46% 52% 48% / 46% 56% 44% 54%}
.skinprev .kbb-pgrid[data-skin="petal"] .cb{padding:12px 6px 4px}
.skinprev .kbb-pgrid[data-skin="petal"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="petal"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="glow"] .kbb-card{border:0;background:radial-gradient(130% 90% at 50% 8%, #FFE3EE 0%, #FFF6FA 55%, #fff 100%);border-radius:20px;text-align:center;padding:16px 14px 6px}
.skinprev .kbb-pgrid[data-skin="glow"] .kbb-card-thumb{border-radius:16px;box-shadow:0 16px 32px rgba(224,86,123,.20)}
.skinprev .kbb-pgrid[data-skin="glow"] .cb{padding:12px 4px 4px}
.skinprev .kbb-pgrid[data-skin="glow"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="glow"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card{border:1px solid #E5D2A8;background:#FFFDF8;border-radius:6px;padding:10px}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card-thumb{border-radius:3px}
.skinprev .kbb-pgrid[data-skin="luxe"] .cb{padding:12px 4px 4px;text-align:center}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card-brand{display:block;text-transform:uppercase;letter-spacing:.22em;font-size:9.5px;color:#B8942E;margin-bottom:5px}
.skinprev .kbb-pgrid[data-skin="luxe"] .cn{font-weight:500}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card-price{color:#9A7B1F}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="luxe"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="luxe"] .kbb-card:hover{box-shadow:0 12px 30px rgba(184,148,46,.18)}
.skinprev .kbb-pgrid[data-skin="pastel"] .kbb-card{border:0;background:linear-gradient(160deg,#F3E7FF,#FFE8F1 55%,#E7FBF3);border-radius:20px;padding:12px}
.skinprev .kbb-pgrid[data-skin="pastel"] .kbb-card-thumb{border-radius:14px;background:rgba(255,255,255,.6)}
.skinprev .kbb-pgrid[data-skin="pastel"] .cb{padding:12px 6px 4px}
.skinprev .kbb-pgrid[data-skin="pastel"] .cp{display:inline-flex;align-items:center;gap:6px;background:#fff;padding:5px 12px;border-radius:20px;margin-top:8px;box-shadow:0 4px 12px rgba(0,0,0,.05)}
.skinprev .kbb-pgrid[data-skin="rosegold"] .kbb-card{border:0;background:#fff;border-radius:16px;overflow:hidden;position:relative;box-shadow:0 6px 18px rgba(42,34,40,.07)}
.skinprev .kbb-pgrid[data-skin="rosegold"] .kbb-card:before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#f7c6a8,#e8a0b0,#d98fae,#f0b9c8);z-index:3}
.skinprev .kbb-pgrid[data-skin="rosegold"] .cb{padding:13px}
.skinprev .kbb-pgrid[data-skin="rosegold"] .kbb-card-price{background:linear-gradient(90deg,#e0967f,#d98fae);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:#d98fae}
.skinprev .kbb-pgrid[data-skin="rosegold"] .kbb-card:hover{box-shadow:0 14px 34px rgba(217,143,174,.25)}
.skinprev .kbb-pgrid[data-skin="actions"] .kbb-card{position:relative;border:1px solid rgba(42,34,40,.08);border-radius:14px;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="actions"] .cb{padding:12px 12px 14px}
.skinprev .kbb-pgrid[data-skin="actions"] .kbb-card-cart{position:absolute;left:0;right:0;bottom:0;margin:0;border-radius:0;transform:translateY(100%);transition:transform .28s ease}
.skinprev .kbb-pgrid[data-skin="actions"] .kbb-card:hover .kbb-card-cart{transform:none}
.skinprev .kbb-pgrid[data-skin="ribbon"] .kbb-card{border:1px solid rgba(42,34,40,.1);border-radius:12px;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="ribbon"] .kbb-badge-sale{top:16px;right:-36px;left:auto;transform:rotate(45deg);width:140px;text-align:center;border-radius:0;padding:5px 0;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.skinprev .kbb-pgrid[data-skin="frame"] .kbb-card{border:0;background:#fff;padding:14px;position:relative}
.skinprev .kbb-pgrid[data-skin="frame"] .kbb-card:before{content:'';position:absolute;inset:8px;border:1px solid #E7D9DE;pointer-events:none;z-index:2}
.skinprev .kbb-pgrid[data-skin="frame"] .cb{text-align:center;padding:14px 6px 4px}
.skinprev .kbb-pgrid[data-skin="frame"] .kbb-card-rate,.skinprev .kbb-pgrid[data-skin="frame"] .cp{justify-content:center;display:flex}
.skinprev .kbb-pgrid[data-skin="duotone"] .kbb-card{border:0;border-radius:14px;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="duotone"] .kbb-card-thumb:after{content:'';position:absolute;inset:0;background:rgba(224,86,123,.3);mix-blend-mode:multiply;transition:opacity .4s;z-index:1;pointer-events:none}
.skinprev .kbb-pgrid[data-skin="duotone"] .kbb-card:hover .kbb-card-thumb:after{opacity:0}
.skinprev .kbb-pgrid[data-skin="magazine"] .kbb-card{border:0;background:none}
.skinprev .kbb-pgrid[data-skin="magazine"] .kbb-card-thumb{border-radius:10px}
.skinprev .kbb-pgrid[data-skin="magazine"] .cb{padding:12px 2px}
.skinprev .kbb-pgrid[data-skin="magazine"] .kbb-card-brand{display:block;font-size:11px;color:#E0567B;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px}
.skinprev .kbb-pgrid[data-skin="magazine"] .cn{font-size:17px;font-weight:700;line-height:1.25}
.skinprev .kbb-pgrid[data-skin="magazine"] .cp{font-size:15px}
.skinprev .kbb-pgrid[data-skin="fab"] .kbb-card{border:0;border-radius:16px;background:#fff;box-shadow:0 6px 18px rgba(42,34,40,.08);position:relative}
.skinprev .kbb-pgrid[data-skin="fab"] .kbb-card-thumb{border-radius:16px 16px 0 0}
.skinprev .kbb-pgrid[data-skin="fab"] .cb{position:relative;padding:22px 12px 12px}
.skinprev .kbb-pgrid[data-skin="fab"] .kbb-card-cart{position:absolute;right:12px;top:-22px;margin:0;width:44px;height:44px;border-radius:50%;padding:0;font-size:0;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(224,86,123,.4);transition:transform .2s}
.skinprev .kbb-pgrid[data-skin="fab"] .kbb-card-cart:after{content:'+';font-size:24px;font-weight:400;line-height:1}
.skinprev .kbb-pgrid[data-skin="fab"] .kbb-card:hover .kbb-card-cart{transform:scale(1.08) translateY(-2px)}
.skinprev .kbb-pgrid[data-skin="outline"] .kbb-card{border:1px solid transparent;background:#fff;border-radius:14px;transition:border-color .2s,box-shadow .2s,transform .2s}
.skinprev .kbb-pgrid[data-skin="outline"] .kbb-card:hover{border-color:#E0567B;box-shadow:0 12px 28px rgba(224,86,123,.14);transform:translateY(-3px)}
.skinprev .kbb-pgrid[data-skin="pricetag"] .kbb-card{border:1px solid rgba(42,34,40,.08);border-radius:14px;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="pricetag"] .cb{position:relative;padding:20px 12px 12px}
.skinprev .kbb-pgrid[data-skin="pricetag"] .cp{position:absolute;left:12px;top:-26px;margin:0;background:#fff;border-radius:24px;padding:8px 14px;box-shadow:0 6px 16px rgba(42,34,40,.16);font-size:13px;z-index:3}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card{position:relative;border:0;border-radius:16px;overflow:hidden}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-thumb{aspect-ratio:4/5}
.skinprev .kbb-pgrid[data-skin="reveal"] .cb{position:absolute;inset:0;display:flex;flex-direction:column;justify-content:flex-end;padding:18px;background:linear-gradient(to top,rgba(0,0,0,.75),transparent 68%)}
.skinprev .kbb-pgrid[data-skin="reveal"] .cn,.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-brand,.skinprev .kbb-pgrid[data-skin="reveal"] .cp,.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-price{color:#fff}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-cat{color:rgba(255,255,255,.8)}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-reg{color:rgba(255,255,255,.6)}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-rc{color:rgba(255,255,255,.75)}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card-cart{background:rgba(255,255,255,.92);color:#2A2228}
.skinprev .kbb-pgrid[data-skin="accentbar"] .kbb-card{border:1px solid rgba(42,34,40,.08);border-left:4px solid #E0567B;border-radius:0 12px 12px 0;overflow:hidden;background:#fff}
.skinprev .kbb-pgrid[data-skin="accentbar"] .cp{font-size:16px}
.skinprev .kbb-pgrid[data-skin="accentbar"] .kbb-card-price{font-weight:800}
@media(hover:hover){.skinprev .kbb-pgrid[data-skin="reveal"] .cb{opacity:0;transition:opacity .35s}
.skinprev .kbb-pgrid[data-skin="reveal"] .kbb-card:hover .cb{opacity:1}}
@media(hover:hover){.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card-cart{opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}
.skinprev .kbb-pgrid[data-skin="overlay"] .kbb-card:hover .kbb-card-cart{opacity:1;transform:none}}
@media (max-width: 1180px){.skinprev .kbb-pgrid{grid-template-columns:repeat(3,1fr)}}
@media (max-width: 900px){.skinprev .kbb-pgrid{grid-template-columns:repeat(2,1fr)}}
@media (max-width: 400px){.skinprev .kbb-pgrid{gap:10px}}
@media (max-width: 640px){.skinprev .kbb-pgrid[data-skin="horizontal"]{grid-template-columns:1fr !important}}
.skinprev .kbb-pgrid{gap:var(--kbb-gap,16px)}
.skinprev .kbb-pgrid .kbb-card{border-radius:var(--kbb-radius,14px)}
.skinprev .kbb-pgrid .kbb-card-thumb{aspect-ratio:var(--kbb-ratio,1/1.02)}
.skinprev .kbb-pgrid .cn{display:-webkit-box;-webkit-line-clamp:var(--kbb-name-lines,2);-webkit-box-orient:vertical;overflow:hidden}
.skinprev .kbb-pgrid .kbb-badge-sale{background:var(--kbb-sale,#E23B57)}
.skinprev .kbb-pgrid .kbb-badge-new{background:var(--kbb-new,#1F9D55)}
.skinprev .kbb-pgrid .kbb-card-price{color:var(--kbb-price,#2A2228)}
.skinprev .kbb-pgrid .kbb-cstar.on{color:var(--kbb-star,#E8A33D)}
.skinprev .kbb-pgrid .kbb-card-cart{background:var(--kbb-cart-bg,#E0567B);color:var(--kbb-cart-fg,#fff)}
.skinprev .pc-nobrand .kbb-card-brand{display:none}
.skinprev .pc-nocat .kbb-card-cat{display:none}
.skinprev .pc-norate .kbb-card-rate{display:none}
.skinprev .pc-nowas .kbb-card-reg{display:none}
.skinprev .pc-nodisc .kbb-badge-sale{display:none}
.skinprev .pc-nonew .kbb-badge-new{display:none}
.skinprev .pc-nocart .kbb-card-cart{display:none}
.skinprev .kbb-gridhead{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 16px}
.skinprev .kbb-gridhead h2{font-size:clamp(19px,2.2vw,26px);font-weight:800;letter-spacing:-.02em;margin:0}
.skinprev .kbb-gridhead p{margin:4px 0 0;color:var(--ink-2,#6A5C64);font-size:13.5px}
.skinprev .kbb-gridhead .lnk{font-size:13px;font-weight:600;color:var(--pink-deep,#C13E63);white-space:nowrap}
@media (min-width: 1181px){.skinprev .kbb-pgrid{grid-template-columns:repeat(var(--kbb-cols,4),1fr)}}
@media (max-width: 1180px){.skinprev .kbb-pgrid{grid-template-columns:repeat(var(--kbb-cols-t,3),1fr)}}
@media (max-width: 900px){.skinprev .kbb-pgrid{grid-template-columns:repeat(var(--kbb-cols-m,2),1fr)}}
.skinprev .kbb-pgrid .kbb-card-brand{display:block;margin-bottom:2px}
.skinprev .kbb-pgrid:not([data-skin="horizontal"]) .kbb-card{height:100%}
.skinprev .kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]) .cb{
    display:flex;flex-direction:column;flex:1 1 auto;min-height:0}
.skinprev .kbb-pgrid:not([data-skin="horizontal"]):not([data-skin="overlay"]):not([data-skin="glass"]):not([data-skin="reveal"]) .kbb-card-cart{
    margin-top:auto}
.skinprev .kbb-pgrid .cn{min-height:calc(var(--kbb-name-lines,2) * 1.35em)}
.skinprev .kbb-pgrid .cp,.skinprev .kbb-pgrid .kbb-card-rate{flex:none}
.skinprev{width:132px;display:block}
.skinprev .kbb-pgrid{display:block !important;gap:0 !important;grid-template-columns:none !important}
.skinprev .kbb-card{width:132px;height:auto !important}
.skinprev .kbb-card-thumb{aspect-ratio:1/1.02;position:relative;overflow:hidden;display:block}
.skinprev .kbb-card-ph,.skinprev .ph2{position:absolute;inset:0;display:block;background:linear-gradient(150deg,#FFE0E8,#EFA3B8)}
.skinprev .kbb-card{display:block;text-decoration:none;color:#2A2228;overflow:hidden;background:#fff}
.skinprev .cb{display:block;padding:10px}
.skinprev .cn{display:block;font-size:11.5px;line-height:1.35;font-weight:600;margin:3px 0}
.skinprev .cp{display:block;margin-top:4px}
.skinprev .kbb-card-cat{display:block;font-size:8.5px;letter-spacing:.1em;text-transform:uppercase;color:#9A8D94}
.skinprev .kbb-card-brand{font-size:9px;letter-spacing:.08em;color:#6A5C64;display:block}
.skinprev .kbb-card-rate{display:flex;align-items:center;gap:4px;font-size:9px;margin:3px 0}
.skinprev .kbb-cstar{color:#DDD5D9;font-size:9px}
.skinprev .kbb-cstar.on{color:var(--kbb-star,#E8A33D)}
.skinprev .kbb-card-rc{color:#9A8D94}
.skinprev .kbb-card-reg{text-decoration:line-through;color:#9A8D94;font-size:10px;margin-right:4px}
.skinprev .kbb-card-price{font-weight:800;font-size:13px;color:var(--kbb-price,#2A2228)}
.skinprev .kbb-badge{position:absolute;top:7px;font-size:8px;font-weight:700;color:#fff;border-radius:5px;padding:2px 6px;line-height:1.4}
.skinprev .kbb-badge-new{left:7px;background:var(--kbb-new,#1F9D55)}
.skinprev .kbb-badge-sale{right:7px;background:var(--kbb-sale,#E23B57)}
.skinprev .kbb-card-cart{display:block;margin-top:7px;text-align:center;font-size:9.5px;font-weight:700;border-radius:7px;padding:6px;background:var(--kbb-cart-bg,#E0567B);color:var(--kbb-cart-fg,#fff)}


/* skin picker */
.skinpick{position:relative;display:inline-block}
.skinpick-b{border:1px solid #dfe5ec;background:#fff;border-radius:8px;padding:7px 11px;font:400 13px inherit;
  cursor:pointer;min-width:190px;text-align:left;display:flex;align-items:center;justify-content:space-between;gap:8px}
.skinpick-b i{font-style:normal;color:#7b8697;font-size:11px}
.skinpick-b:hover{border-color:#E0567B}
.skinpop{position:absolute;top:calc(100% + 6px);right:0;z-index:40;background:#fff;border:1px solid #e2e8f0;
  border-radius:14px;box-shadow:0 22px 50px -18px rgba(16,24,40,.35);padding:10px;
  display:none;grid-template-columns:repeat(3,1fr);gap:8px;width:470px;max-height:380px;overflow-y:auto}
.skinpop.on{display:grid}
.skinopt{border:1px solid #eef1f5;background:#fff;border-radius:11px;padding:8px;cursor:pointer;text-align:center;
  transition:border-color .15s,transform .15s,box-shadow .15s}
.skinopt:hover{border-color:#E0567B;transform:translateY(-2px);box-shadow:0 8px 18px -10px rgba(224,86,123,.5)}
.skinopt.on{border-color:#E0567B;box-shadow:0 0 0 2px rgba(224,86,123,.18)}
.skinopt-l{display:block;font-size:10.5px;color:#475569;margin-top:6px;line-height:1.3}
.skinprev{display:block;transform:scale(.86);transform-origin:top center;pointer-events:none}
@media (max-width:720px){ .skinpop{width:min(92vw,340px);grid-template-columns:repeat(2,1fr)} }

.scout{display:block;background:#0f172a;color:#a5f3c9;border-radius:10px;padding:12px 14px;
  font:500 12.5px ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all;line-height:1.6}
.scok{font-size:12px;color:#1F9D55;margin-left:10px;font-weight:600}

/* trending words editor */
.tagrow{align-items:flex-start}
.tagbox{flex:1;min-width:0;max-width:420px}
.tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.tag{display:inline-flex;align-items:center;gap:6px;background:#FFF1F5;border:1px solid #F3D5DF;color:#C13E63;
  border-radius:99px;padding:5px 6px 5px 11px;font-size:12px;font-weight:600}
.tag button{border:0;background:none;color:#C13E63;cursor:pointer;font-size:14px;line-height:1;
  padding:0 3px;border-radius:50%;opacity:.7}
.tag button:hover{opacity:1;background:#F8D5E0}
.tagempty{font-size:12px;color:#9aa3af}
.tagadd{display:flex;gap:6px}
.tagadd input{flex:1;border:1px solid #dfe5ec;border-radius:8px;padding:7px 10px;font:400 13px inherit}
.tagsug{margin-top:10px;display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.tagsug > span{font-size:11px;color:#7b8697;width:100%;margin-bottom:2px}
.tagsug .sug{border:1px dashed #dfe5ec;background:#fff;border-radius:99px;padding:4px 10px;
  font-size:11.5px;color:#475569;cursor:pointer}
.tagsug .sug:hover{border-style:solid;border-color:#E0567B;color:#C13E63}

/* search panel preview */
.pfield{background:#F8F3F5;border:1px solid #F0E8EB;border-radius:99px;padding:7px 12px;
  font-size:11px;color:#9A8D94;margin-bottom:8px}
.ppanel{background:#fff;border:1px solid #eef1f5;border-radius:10px;overflow:hidden;text-align:left}
.ppanel.two{display:grid;grid-template-columns:1.3fr 1fr}
.ppanel.two > div:first-child{border-right:1px solid #f2f5f8}
.pgh{font-size:8px;letter-spacing:.12em;text-transform:uppercase;color:#9aa3af;padding:8px 9px 3px}
.pchips{display:flex;flex-wrap:wrap;gap:4px;padding:2px 9px 6px}
.pchips span{background:#F8F3F5;border:1px solid #F0E8EB;border-radius:99px;padding:2px 7px;font-size:9px;color:#6A5C64}
.prec{font-size:9.5px;color:#6A5C64;padding:4px 9px;display:flex;align-items:center;gap:6px}
.prec::before{content:"";width:9px;height:9px;border-radius:50%;border:1.3px solid #F0E8EB;flex:none}
.pprod{display:flex;align-items:center;gap:6px;padding:4px 9px}
.pprod i{width:20px;height:20px;border-radius:5px;background:linear-gradient(150deg,#FFE0E8,#EFA3B8);flex:none}
.pprod span{height:7px;border-radius:3px;background:#F0E8EB;flex:1}

/* header height preview */
.hhblock{margin-bottom:12px}
.hhcap{font-size:11px;color:#7b8697;margin-bottom:5px;display:flex;justify-content:space-between}
.hhcap b{color:#1F7D52}
.hh{background:#fff;border:1px solid #eef1f5;border-radius:9px;overflow:hidden}
.hh.phone{max-width:190px}
.hhrow{position:relative;display:flex;align-items:center;padding:0 9px;border-bottom:1px solid #f2f5f8;
  background:#FAFBFC}
.hhrow:last-child{border-bottom:0}
.hhrow.nav{background:#F2FBF6}
.hhrow.srch{background:#FFF6F9}
.hhrow > span:first-child{font-size:9.5px;color:#7b8697;font-weight:600}
.hhrow i{position:absolute;right:8px;font-style:normal;font:700 9px Poppins;background:#2A2228;color:#fff;
  border-radius:4px;padding:1px 5px}
.hhrow.nav i{background:#1F7D52}
.hhfld{position:absolute;left:50%;transform:translateX(-50%);background:#FFE3EC;border:1px solid #F3C7D6;
  border-radius:99px;display:flex;align-items:center;padding:0 8px;font:600 8.5px Poppins;color:#C13E63}

/* login / register panel preview */
.apdev{display:flex;gap:5px;margin-bottom:9px}
.apd{flex:1;border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:6px;
  font:600 11.5px inherit;color:#7b8697;cursor:pointer}
.apd.on{background:#2A2228;border-color:#2A2228;color:#fff}
.apstage{background:linear-gradient(180deg,#FFF8FA,#fff);border-radius:12px;padding:0 0 16px;
  border:1px solid #eef1f5;overflow:hidden}
.apstage.phone{max-width:270px;margin:0 auto}
.apbar{display:flex;align-items:center;padding:0 12px;min-height:40px;background:#fff;
  border-bottom:1px solid #F0E8EB}
.aplg{font:700 14px Poppins;letter-spacing:-.02em;color:#2A2228}
.aplg em{font-style:normal;color:#E0567B}
.apic{margin-left:auto;width:16px;height:16px;border-radius:50%;border:1.6px solid #2A2228;position:relative}
.apic::after{content:"";position:absolute;top:-2px;right:-2px;width:6px;height:6px;border-radius:50%;
  background:#1F9D55;box-shadow:0 0 0 2px #fff}
.appanel{width:var(--ap-w,296px);border-radius:var(--ap-r,14px);background:#fff;border:1px solid #F0E8EB;
  margin:12px auto 0;padding:6px;box-shadow:0 18px 40px -22px rgba(42,34,40,.4);text-align:left;overflow:hidden}
.ap-welcome{overflow:hidden;text-align:center;max-height:0;opacity:0;
  animation:apW calc(var(--ap-hold,2500ms) + 1400ms) cubic-bezier(.3,.7,.3,1) forwards}
@keyframes apW{0%{max-height:0;opacity:0;padding:0}
  8%{max-height:130px;opacity:1;padding:14px 8px 12px}
  76%{max-height:130px;opacity:1;padding:14px 8px 12px}
  100%{max-height:0;opacity:0;padding:0}}
.ap-kick{display:block;font:400 9px Poppins;letter-spacing:.24em;text-transform:uppercase;color:#A2939B;margin-bottom:4px}
.ap-name{display:block;font-family:var(--ap-font);font-weight:var(--ap-weight,300);
  font-size:var(--ap-size,27px);line-height:1.15;color:#C13E63}
.ap-fill .ap-name,.ap-shimmer .ap-name,.ap-drift .ap-name,.ap-focus .ap-name{
  background-size:230% 100%;-webkit-background-clip:text;background-clip:text;color:transparent}
.ap-fill .ap-name{background-image:linear-gradient(90deg,#C13E63,#E0567B 42%,#E8A33D 70%,#E9DEE3 70.1%);
  background-position:100% 0;animation:apFill var(--ap-hold,2500ms) cubic-bezier(.3,.7,.3,1) forwards}
@keyframes apFill{to{background-position:0 0}}
.ap-shimmer .ap-name{background-image:linear-gradient(100deg,#C13E63 38%,#fff 47%,#E8A33D 53%,#C13E63 62%);
  background-size:280% 100%;background-position:130% 0;animation:apSh var(--ap-hold,2500ms) ease-in-out forwards}
@keyframes apSh{to{background-position:-30% 0}}
.ap-drift .ap-name{background-image:linear-gradient(90deg,#E0567B,#E8A33D,#1F9D55,#E0567B);
  background-size:300% 100%;animation:apDr calc(var(--ap-hold,2500ms) * 1.6) linear infinite}
@keyframes apDr{to{background-position:300% 0}}
.ap-focus .ap-name{background-image:linear-gradient(90deg,#C13E63,#E0567B,#E8A33D);
  animation:apFo var(--ap-hold,2500ms) cubic-bezier(.2,.8,.3,1) forwards}
@keyframes apFo{0%{filter:blur(9px);letter-spacing:.14em;opacity:0}45%,100%{filter:blur(0);letter-spacing:0;opacity:1}}
.aphead{display:flex;align-items:center;gap:9px;padding:8px 9px 10px;border-bottom:1px solid #F8F3F5}
.apav{width:30px;height:30px;border-radius:50%;background:#FFF1F5;color:#C13E63;display:grid;place-items:center;
  font:800 12px Poppins;flex:none}
.apwho b{display:block;font:600 12px Poppins;color:#2A2228}
.apwho span{font-size:10.5px;color:#A2939B}
.apit{display:block;padding:7px 10px;font:400 12px Poppins;color:#2A2228;border-radius:8px}
.apout{display:block;margin-top:3px;border-top:1px solid #F8F3F5;padding:8px;text-align:center;
  font:600 11.5px Poppins;color:#6A5C64}

/* the name animating in the panel head, as it does on the storefront */
.apwho .ap-greet{display:block;font-family:var(--ap-font);font-weight:var(--ap-weight,300);
  font-size:var(--ap-size,27px);line-height:1.2;color:#2A2228;
  background-size:230% 100%;-webkit-background-clip:text;background-clip:text}
.ap-greet.ap-fill{background-image:linear-gradient(90deg,#C13E63,#E0567B 42%,#E8A33D 70%,#E9DEE3 70.1%);
  animation:apFill var(--ap-hold,2500ms) cubic-bezier(.3,.7,.3,1) forwards}
@keyframes apFill{0%{background-position:100% 0;-webkit-text-fill-color:transparent}
  62%{background-position:0 0;-webkit-text-fill-color:transparent}
  100%{background-position:0 0;-webkit-text-fill-color:#2A2228}}
.ap-greet.ap-shimmer{background-image:linear-gradient(100deg,#C13E63 38%,#fff 47%,#E8A33D 53%,#C13E63 62%);
  background-size:280% 100%;animation:apShimmer var(--ap-hold,2500ms) ease-in-out forwards}
@keyframes apShimmer{0%{background-position:130% 0;-webkit-text-fill-color:transparent}
  70%{background-position:-30% 0;-webkit-text-fill-color:transparent}
  100%{background-position:-30% 0;-webkit-text-fill-color:#2A2228}}
.ap-greet.ap-drift{background-image:linear-gradient(90deg,#E0567B,#E8A33D,#1F9D55,#E0567B);
  background-size:300% 100%;animation:apDrift var(--ap-hold,2500ms) linear forwards}
@keyframes apDrift{0%{background-position:0 0;-webkit-text-fill-color:transparent}
  72%{background-position:300% 0;-webkit-text-fill-color:transparent}
  100%{background-position:300% 0;-webkit-text-fill-color:#2A2228}}
.ap-greet.ap-focus{background-image:linear-gradient(90deg,#C13E63,#E0567B,#E8A33D);
  animation:apFocus var(--ap-hold,2500ms) cubic-bezier(.2,.8,.3,1) forwards}
@keyframes apFocus{0%{filter:blur(8px);letter-spacing:.12em;opacity:0;-webkit-text-fill-color:transparent}
  40%{filter:blur(0);letter-spacing:0;opacity:1;-webkit-text-fill-color:transparent}
  72%{-webkit-text-fill-color:transparent}100%{-webkit-text-fill-color:#2A2228}}
.ap-greet.ap-letters{-webkit-text-fill-color:#C13E63;animation:apLetters var(--ap-hold,2500ms) ease-in-out forwards}
@keyframes apLetters{0%{opacity:0;transform:translateY(4px)}40%{opacity:1;transform:none}
  100%{opacity:1;-webkit-text-fill-color:#2A2228}}
.apsub{display:block;position:relative;height:15px}
.apsub > span{position:absolute;left:0;top:0;font-size:10.5px;color:#A2939B;white-space:nowrap}
.ap-kick{letter-spacing:.14em;text-transform:uppercase;font-size:9px;
  animation:apKick var(--ap-hold,2500ms) ease-in-out forwards}
@keyframes apKick{0%{opacity:0;transform:translateY(3px)}20%,66%{opacity:1;transform:none}
  88%,100%{opacity:0;transform:translateY(-3px)}}
.ap-mail{opacity:0;animation:apMail var(--ap-hold,2500ms) ease-in-out forwards}
@keyframes apMail{0%,74%{opacity:0}100%{opacity:1}}
.aphead{align-items:flex-start}

/* account form preview */
.apform{background:radial-gradient(120% 90% at 50% -10%,#FFF2F6,#FDFBFC 60%);padding:16px;border-radius:12px;
  border:1px solid #eef1f5}
.apform.phone{max-width:250px;margin:0 auto}
.apform .authcard{background:#fff;border:1px solid #EDE4E8;border-radius:16px;padding:16px;
  box-shadow:0 12px 30px -20px rgba(36,29,34,.3)}
.apform .mark{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;margin-bottom:10px;
  background:linear-gradient(140deg,#E0567B,#C13E63);color:#fff;font:800 10px Poppins}
.apform h1{margin:0;font-size:15px;font-weight:700;letter-spacing:-.02em;color:#241D22}
.apform .lede{margin:3px 0 12px;font-size:10.5px;color:#A2939B}
.apform .segs{display:flex;gap:3px;padding:3px;background:#F6F0F3;border-radius:8px;margin-bottom:12px}
.apform .seg{flex:1;text-align:center;padding:5px;border-radius:6px;font:600 9.5px Poppins;color:#6A5C64}
.apform .seg.on{background:#fff;color:#241D22;box-shadow:0 1px 2px rgba(36,29,34,.1)}
.apform .fld{position:relative;margin-bottom:8px}
.apform .fld input{width:100%;border:1.5px solid #EDE4E8;border-radius:9px;background:#fff;
  padding:calc(15px + var(--fld-gap,3px)) 10px 5px;font:400 11px Poppins;color:#241D22;outline:0}
.apform .fld.ico input{padding-left:32px}
.apform .fld label{position:absolute;left:10px;top:10px;font-size:11px;color:#A2939B;pointer-events:none;
  transition:.16s;transform-origin:left top}
.apform .fld.ico label{left:32px}
.apform .fld input:focus ~ label,.apform .fld input:not(:placeholder-shown) ~ label{
  transform:translateY(calc(-6px - var(--fld-gap,3px)));font-size:8.5px;color:#C13E63}
.apform .fld input:focus{border-color:#E0567B;box-shadow:0 0 0 3px rgba(224,86,123,.13)}
.apform .lead{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#A2939B;display:grid}
.apform .lead svg{width:14px;height:14px}
.apform .row{display:flex;align-items:center;justify-content:space-between;margin:6px 0 10px;font-size:9.5px;color:#6A5C64}
.apform .row a{color:#C13E63}
.apform .chk{display:flex;align-items:center;gap:5px}
.apform .chk .bx{width:12px;height:12px;border-radius:4px;border:1.5px solid #EDE4E8;display:block}
.apform .go{width:100%;border:0;border-radius:9px;padding:9px;color:#fff;font:700 11px Poppins;
  background:linear-gradient(180deg,#E0567B,#C13E63);box-shadow:0 8px 18px -10px rgba(193,62,99,.75)}
/* grouped */
.apform.fs-grouped .fgroup{border:1.5px solid #EDE4E8;border-radius:10px;overflow:hidden}
.apform.fs-grouped .fgroup .fld{margin:0}
.apform.fs-grouped .fgroup .fld + .fld{border-top:1.5px solid #EDE4E8}
.apform.fs-grouped .fgroup .fld input{border:0;border-radius:0;box-shadow:none}
.apform.fs-grouped .fgroup:focus-within{border-color:#E0567B;box-shadow:0 0 0 3px rgba(224,86,123,.13)}
/* filled */
.apform.fs-filled .fld input{background:#FBF7F9;border-color:transparent}
.apform.fs-filled .fld input:focus{background:#fff;border-color:#E0567B}
/* generous */
.apform.fs-generous .fld input{padding:calc(18px + var(--fld-gap,3px)) 12px 7px;border-radius:11px;font-size:12px}
.apform.fs-generous .fld label{left:12px;top:12px}
.apform.fs-generous .fld.ico input{padding-left:34px}
.apform.fs-generous .fld.ico label{left:34px}
/* no icons */
.apform.fs-noicons .lead{display:none}
.apform.fs-noicons .fld.ico input{padding-left:10px}
.apform.fs-noicons .fld.ico label{left:10px}
</style>
</head>
<body data-env="live">
<div class="mesh"></div>
<div class="flag">PHASE 0 · FOUNDATION PREVIEW</div>

<div class="app">
  <!-- SIDEBAR -->
  <aside class="side" id="side">
    <div class="brand">
      <div class="logo">K</div>
      <div><b>K-Beauty Bliss</b><small>Admin Console</small></div>
    </div>
    <nav class="nav" id="nav"></nav>
    <div class="side-pin"><button class="nav-item" data-go="console"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h8M16 6h4M4 12h4M12 12h8M4 18h10M18 18h2"/><circle cx="14" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="16" cy="18" r="2"/></svg><span>Console</span></button></div>
    <div class="side-foot">v0.1.0 · Foundation<br>Aurora admin · KBB platform</div>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <div class="top">
      <button class="iconbtn menubtn" onclick="document.getElementById('side').classList.toggle('open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg></button>
      <div><div class="crumb" id="crumb">Platform</div><h1 id="ptitle">Dashboard</h1></div>
      <div class="sp"></div>
      <div class="envtog" id="envtog">
        <button data-e="live" class="on"><span class="d"></span>Live</button>
        <button data-e="sandbox"><span class="d"></span>Sandbox</button>
      </div>
      <div class="themewrap">
        <button class="iconbtn" id="themeBtn" title="Console theme" onclick="go('console')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22a10 10 0 1 1 9-14c0 3-3 3-5 3s-3 2-2 4 1 3-2 3z"/><circle cx="8.5" cy="10.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="15.5" cy="10.5" r="1"/></svg></button>
      </div>
      <button class="iconbtn" onclick="go('debug')" title="Alerts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg><span class="dot"></span></button>
      <div class="userchip"><div class="avatar">R</div><div><b>Rafi</b><small>Owner</small></div></div>
    </div>
    <div class="envbar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.8-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg> You're viewing <b>&nbsp;Sandbox&nbsp;</b> — changes here are isolated from the live store until you deploy.</div>

    <div class="content" id="content"></div>
  </div>
</div>

<div class="modal-bg" id="modalBg"><div class="modal" id="modal"></div></div>
<div class="drawer-bg" id="drawerBg"></div>
<div class="drawer" id="drawer"></div>
<div class="toast" id="toast"></div>

<script>
const $=(s,r=document)=>r.querySelector(s);
const $$=(s,r=document)=>[...r.querySelectorAll(s)];
const ic=(p)=>`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">${p}</svg>`;
const I={
  dash:'<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
  modules:'<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><path d="M17 13v8M13 17h8"/>',
  theme:'<circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><path d="M12 22a10 10 0 1 1 9-14c0 3-3 3-5 3s-3 2-2 4 1 3-2 3z"/>',
  users:'<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 0 0 0-6M19.5 20a5.5 5.5 0 0 0-3-5"/>',
  settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7.7 1.6 1.6 0 0 0-1.6 1.3H12a2 2 0 0 1-2-2 1.6 1.6 0 0 0-2.7-1.1 1.6 1.6 0 0 1-1.8.3 2 2 0 1 1-2.8-2.8 1.6 1.6 0 0 0 .7-2.7 1.6 1.6 0 0 0-1.3-1.6V12a2 2 0 0 1 2-2 1.6 1.6 0 0 0 1.1-2.7 1.6 1.6 0 0 1 .3-1.8 2 2 0 1 1 2.8-2.8 1.6 1.6 0 0 0 2.7.7H12a2 2 0 0 1 2 2 1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 1 1.8-.3 2 2 0 1 1 2.8 2.8 1.6 1.6 0 0 0-.7 2.7 1.6 1.6 0 0 0 1.3 1.6V12a2 2 0 0 1-2 2 1.6 1.6 0 0 0-1.3.9z"/>',
  debug:'<rect x="5" y="8" width="14" height="12" rx="6"/><path d="M9 8V6a3 3 0 0 1 6 0v2M3 13h2M19 13h2M4 18l2-1M20 18l-2-1M4 8l2 1M20 8l-2 1"/>',
  sandbox:'<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/>',
  catalog:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
  orders:'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
  cust:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  mkt:'<path d="M3 11v2a1 1 0 0 0 1 1h2l4 4V6L6 10H4a1 1 0 0 0-1 1zM15 8a4 4 0 0 1 0 8M19 5a8 8 0 0 1 0 14"/>',
  content:'<path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  rocket:'<path d="M5 13c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 15l-3-3M15 9l3-3M14.5 4.5C18 3 21 3 21 3s0 3-1.5 6.5C18 13 14 16 12 17l-5-5c1-2 4-6 7.5-7.5z"/><circle cx="14.5" cy="9.5" r="1.2"/>',
  bell:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
  shield:'<path d="M12 2 4 5v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V5z"/><path d="M9 12l2 2 4-4"/>',
  cash:'<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
  revenue:'<path d="M3 17l5-5 4 4 8-8M21 8h-4M21 8v4"/>',
  copy:'<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
};

/* ---------- admin base path ----------
   Read from the address bar rather than hard-coded, so changing the admin
   address in Core Updates moves every link here with it — no second place to
   keep in sync. */
const ADMIN_BASE = window.location.pathname.replace(/\/+$/, '');

/* ---------- nav ---------- */
const NAV=[
  {sec:'Overview',items:[['dash','Dashboard',I.dash]]},
  {sec:'Platform',items:[['theme','K-Beauty Bliss Theme',I.theme],['users','Users & Roles',I.users],['settings','Settings',I.settings]]},
  {sec:'Safety',items:[['debug','Debug & Monitor',I.debug,'live'],['sandbox','Sandbox & Deploy',I.sandbox],['democontent','Demo Content','<path d=\"M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z\"/><circle cx=\"7.5\" cy=\"7.5\" r=\"1.3\"/>']]},
  {sec:'Store',items:[['modules','Modules','<path d="M4 7h7v7H4z"/><path d="M13 4h7v7h-7z"/><path d="M13 13h7v7h-7z"/>'],['megamenu','Mega Menu','<path d="M3 5h18M3 5v4h18V5M7 13h10M7 17h6"/>'],['ecommerce','Ecommerce','<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/>'],['payship','Payment & Shipping Rules','<path d="M3 7h18v10H3z"/><path d="M3 11h18"/><circle cx="7.5" cy="14" r="1"/>'],['shipping','Delivery & Shipping','<path d="M2 6h11v9H2z"/><path d="M13 9h4.5l3.5 3.5V15h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>'],['catalog','Catalog',I.catalog],['import','Import / Export',I.sandbox],['orders','Orders',I.orders],['payments','Payments','<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'],['analytics','Analytics','<path d="M3 3v18h18"/><path d="M7 14l3-4 4 3 5-7"/>'],['search','Site Search','<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>'],['seo','SEO & Meta','<path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/><path d="m22 20-1.5-1.5"/>'],['blog','Blog','<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>'],['posts','Posts','<path d="M4 4h11l5 5v11H4z"/><path d="M14 4v5h5"/><path d="M8 13h6"/>'],['htmlblocks','HTML Blocks','<path d="M8 8l-4 4 4 4M16 8l4 4-4 4"/>'],['media','Media Library','<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L6 21"/>'],['store-settings','Business Details',I.settings],['customers','Customers',I.cust],['quiz-leads','Quiz Leads','<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>'],['p-content','Content & Pages',I.content,'lock']]},
  {sec:'Appearance',items:[['homepage','Homepage','<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/>'],['prodstyles','Product styles','<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/>'],['mobilehdr','Mobile Header','<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M7 9h10"/>'],['dividers','Section dividers','<path d="M4 12h5"/><path d="M15 12h5"/><circle cx="12" cy="12" r="1.6"/>'],['cartpanel','Cart panel','<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>'],['acctpanel','Login / Register panel','<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 12h8M8 15h5"/>'],['header','Header','<path d="M3 5h18v5H3z"/><path d="M3 14h10"/>'],['mobilemenu','Mobile menu','<path d="M7 2h10v20H7z"/><path d="M10 18h4"/>'],['productpage','Product page','<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="7.5" r="1.2"/>'],['bundles','Quantity bundles','<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>'],['layout','Product grid','<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>']]},
  {sec:'Pages',items:[['pages-store','Store pages','<path d="M3 9h18M3 15h18M9 3v18"/><rect x="3" y="3" width="18" height="18" rx="2"/>'],['pages-user','User pages','<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>']]},
  {sec:'Growth & Marketing',items:[['newsletter','Newsletter','<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>'],['labels','Product Labels','<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.3"/>'],['meta','Meta & Facebook','<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'],['pixels','Marketing Pixels','<path d="M13 2 3 14h7l-1 8 10-12h-7z"/>']]},
  {sec:'Reviews',items:[['rev-all','All Reviews','<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 21l1.1-6.5L2.6 9.8l6.5-.9z"/>','3'],['rev-add','Bulk Add','<path d="M12 5v14M5 12h14"/>'],['rev-likes','Bulk Likes','<path d="M7 11v9H4v-9z"/><path d="M7 11l4-7a2 2 0 0 1 2 2v3h5a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 16.8 20H7"/>'],['rev-assign','Assign / Duplicate',I.copy],['rev-io','Export / Import','<path d="M8 7h11l-3-3M16 17H5l3 3"/>'],['rev-badge','Badge Themes','<path d="M12 2l4 4-4 4-4-4z"/><path d="M4 12l8 8 8-8"/>'],['rev-capsule','Rating Capsule','<path d="M12 21s-7-4.5-9.5-9A4.5 4.5 0 0 1 12 7a4.5 4.5 0 0 1 9.5 5c-2.5 4.5-9.5 9-9.5 9z"/>'],['rev-settings','Review Settings',I.settings]]},
  {sec:'Storefront',items:[['shopfilters','Shop Filters','<path d="M4 5h16l-6 7v5l-4 2v-7z"/>']]},
  {sec:'Core Updates',items:[['updates','Core Updates','<path d=\"M21 12a9 9 0 1 1-3-6.7\"/><path d=\"M21 3v6h-6\"/><path d=\"M12 8v5l3 2\"/>']]}
];
function navItemHTML([id,name,icon,tag]){
  let extra='';if(tag==='lock')extra=`<span class="tag">soon</span>`;else if(tag==='live')extra=`<span class="cnt">3</span>`;else if(tag)extra=`<span class="tag">${tag}</span>`;
  return `<button class="nav-item${tag==='lock'?' locked':''}" data-go="${id}">${ic(icon)}<span>${name}</span>${extra}</button>`;
}
function buildNav(){
  $('#nav').innerHTML=NAV.map((g,gi)=>{
    if(g.items.length===1){
      // Single-item groups render as a plain top-level link. The last one is
      // tagged so CSS can pin it to the bottom of the sidebar — a selector on
      // .nav-group would never match, because no .nav-group is created here.
      const html = navItemHTML(g.items[0]);
      return gi === NAV.length - 1
        ? html.replace('class="nav-item', 'class="nav-item nav-pinned')
        : html;
    }
    const sum=g.items.reduce((s,it)=>s+(/^\d+$/.test(it[3]||'')?parseInt(it[3]):0),0);
    const badge=sum>0?`<span class="gh-badge">${sum}</span>`:'';
    const chev='<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 6 6 6-6 6"/></svg>';
    return `<div class="nav-group" data-sec="${g.sec}"><button class="nav-gh"><span class="gh-name">${g.sec}</span>${badge}${chev}</button><div class="nav-sub">${g.items.map(navItemHTML).join('')}</div></div>`;
  }).join('');
  $$('#nav .nav-item').forEach(b=>b.onclick=()=>go(b.dataset.go));
  $$('#nav .nav-gh').forEach(h=>h.onclick=()=>h.closest('.nav-group').classList.toggle('open'));
  syncNavOpen(cur);
}
function syncNavOpen(id){
  $$('#nav .nav-group').forEach(g=>{
    const has=[...g.querySelectorAll('.nav-item')].some(b=>b.dataset.go===id);
    g.classList.toggle('open',has);
  });
}

const TITLES={dash:['Overview','Dashboard'],updates:['Platform','Core Updates'],modules:['Platform','Modules'],theme:['Platform','K-Beauty Bliss Theme'],users:['Platform','Users & Roles'],settings:['Platform','Settings'],debug:['Safety','Debug & Monitor'],sandbox:['Safety','Sandbox & Deploy'],democontent:['Safety','Demo Content'],console:['Console','Console settings'],catalog:['Store','Catalog'],import:['Store','Import / Export'],newsletter:['Growth & Marketing','Newsletter'],labels:['Growth & Marketing','Product Labels'],pixels:['Growth & Marketing','Marketing Pixels'],meta:['Growth & Marketing','Meta & Facebook'],shopfilters:['Storefront','Shop Filters'],'rev-all':['Reviews','All Reviews'],'rev-add':['Reviews','Bulk Add'],'rev-likes':['Reviews','Bulk Likes'],'rev-assign':['Reviews','Assign / Duplicate'],'rev-io':['Reviews','Export / Import'],'rev-badge':['Reviews','Badge Themes'],'rev-capsule':['Reviews','Rating Capsule'],'rev-settings':['Reviews','Review Settings'],orders:['Store','Orders'],'store-settings':['Store','Business Details'],customers:['Store','Customers'],payments:['Store','Payments'],analytics:['Store','Analytics'],search:['Store','Site Search'],'quiz-leads':['Store','Quiz Leads'],'seo':['Store','SEO & Meta'],'blog':['Content','Blog'],'layout':['Appearance','Product grid'],'bundles':['Appearance','Quantity bundles'],'homepage':['Appearance','Homepage'],'productpage':['Appearance','Product page'],'mobilemenu':['Appearance','Mobile menu'],'header':['Appearance','Header'],'mobilehdr':['Appearance','Mobile Header'],'dividers':['Appearance','Section dividers'],'cartpanel':['Appearance','Cart panel'],'acctpanel':['Appearance','Login / Register panel'],'prodstyles':['Appearance','Product styles'],'modules':['Store','Modules'],'megamenu':['Store','Mega Menu'],'shipping':['Store','Delivery & Shipping'],'payship':['Store','Payment & Shipping Rules'],'ecommerce':['Store','Ecommerce'],'pages-store':['Pages','Store pages'],'pages-user':['Pages','User pages'],'posts':['Content','Posts'],'htmlblocks':['Content','HTML Blocks'],'media':['Content','Media Library']};
let cur='dash';
function go(id){
  if(FRAME_SRC[id])return renderFrame(id);
  if(id.startsWith('p-'))return renderPlaceholder(id);
  if(id.startsWith('rev-'))return renderReviewFrame(id);
  cur=id;
  if(id==='catalog'){catTab='products';catSel.clear();}
  if(id==='import')impStep=1;
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  const t=TITLES[id]||['Platform',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $('#content').innerHTML='';
  ({dash:renderDash,updates:renderUpdates,layout:renderLayout,bundles:renderBundles,homepage:renderHomepage,productpage:renderProductPage,mobilemenu:renderMobileMenu,header:renderHeader,search:renderSiteSearch,acctpanel:renderAcctPanel,cartpanel:renderCartPanel,dividers:renderDividers,mobilehdr:renderMobileHdr,prodstyles:renderProdStyles,newsletter:renderNewsletter,ecommerce:renderEcommerce,modules:renderModules,megamenu:renderMegaMenu,payship:renderPayShip,shipping:renderShipping,'pages-store':renderStorePages,'pages-user':renderUserPages,theme:renderTheme,users:renderUsers,settings:renderSettings,debug:renderDebug,sandbox:renderSandbox,console:renderConsole,catalog:renderCatalog,import:renderImport,labels:renderLabels,pixels:renderPixels,meta:renderMeta,shopfilters:renderShopFilters,democontent:renderDemoContent}[id]||renderDash)();
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ---------- Dashboard ---------- */
function renderDash(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="banner">${ic('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>')}<div>This is the <b>foundation</b>. Real numbers appear once your WooCommerce data is imported in <b>Phase 1</b> — the layout, modules, and safety tools below are live and clickable now.</div></div>
    <div class="kpis">
      ${kpi(I.revenue,'#15a85a','var(--accent-soft)','Revenue (30d)','AED —','Awaiting import')}
      ${kpi(I.orders,'#3f6fe0','var(--blue-soft)','Orders','—','2,419 to import')}
      ${kpi(I.cust,'#7b6cf0','var(--violet-soft)','Customers','—','with logins preserved')}
      ${kpi(I.revenue,'#e0922f','var(--amber-soft)','Conversion','—','tracked after launch')}
    </div>
    <div class="grid2" style="margin-top:16px">
      <div class="card pad">
        <div class="between"><b style="font-size:14px">System health</b><span class="pill green"><span class="d"></span>All core OK</span></div>
        <div class="health" style="margin-top:14px">
          ${hrow('green','App server','operational')}
          ${hrow('green','Database (SQLite)','WAL · healthy')}
          ${hrow('green','Storefront API','operational')}
          ${hrow('amber','Payments','not configured')}
          ${hrow('amber','Email / SMTP','not configured')}
          ${hrow('green','Sandbox','in sync')}
        </div>
        <button class="btn ghost sm" style="margin-top:14px" onclick="go('debug')">Open Debug & Monitor →</button>
      </div>
      <div class="card pad">
        <b style="font-size:14px">Build progress</b>
        <div style="margin-top:14px;display:flex;flex-direction:column;gap:13px">
          ${prog('Phase 0 · Foundation',100,'In preview')}
          ${prog('Phase 1 · Catalog + Import',0,'Next')}
          ${prog('Phase 2 · Storefront',0,'')}
          ${prog('Phase 3 · Selling + Payments',0,'')}
        </div>
      </div>
    </div>
    <div class="card pad" style="margin-top:16px">
      <div class="between"><b style="font-size:14px">Recent activity</b><span class="pill grey">live feed</span></div>
      <div style="margin-top:8px">
        ${act('green','Foundation initialised','Core, Modules Manager, Theme engine created','just now')}
        ${act('blue','Debug & Monitor enabled','Watching app, database, storefront','just now')}
        ${act('amber','Sandbox ready','Pre-flight checks armed for first deploy','just now')}
      </div>
    </div>
  </div>`;
}
const kpi=(icon,col,bg,lbl,val,sub)=>`<div class="kpi"><div class="ic" style="background:${bg};color:${col}">${ic(icon)}</div><div class="lbl">${lbl}</div><div class="val">${val}</div><div class="sub">${sub}</div></div>`;
const hrow=(s,n,m)=>`<div class="hrow"><span class="hd ${s}"></span><b>${n}</b><small>${m}</small></div>`;
const prog=(n,pct,tag)=>`<div><div class="between" style="margin-bottom:6px"><span style="font-size:12.5px;font-weight:600">${n}</span>${tag?`<span class="pill ${pct===100?'green':'grey'}">${tag}</span>`:''}</div><div style="height:7px;background:var(--surface-3);border-radius:99px;overflow:hidden"><div style="height:100%;width:${pct}%;background:linear-gradient(90deg,var(--accent),var(--accent-strong));border-radius:99px;transition:.6s"></div></div></div>`;
const act=(c,t,d,w)=>`<div class="row" style="padding:11px 0;border-bottom:1px solid var(--border-2)"><span class="hd ${c==='blue'?'green':c}" style="background:var(--${c==='blue'?'blue':c});width:8px;height:8px;border-radius:50%;flex-shrink:0"></span><div><div style="font-size:13px;font-weight:600">${t}</div><div style="font-size:11.5px;color:var(--ink-soft)">${d}</div></div><small style="margin-left:auto;font-size:11px;color:var(--ink-faint)">${w}</small></div>`;

/* ---------- Modules Manager ---------- */
const MODULES=[
  {g:'Core platform',locked:true,items:[
    ['Dashboard','KPIs & overview',I.dash],['Modules Manager','Install / enable / disable modules',I.modules],
    ['Users & Roles','Staff accounts, roles, permissions',I.users],['Settings','Global configuration',I.settings],
    ['Media','Media library',I.content],['K-Beauty Bliss Theme','All design, layout & storefront settings',I.theme],
    ['Debug & Monitor','Error capture, health, AI reports',I.debug],['Sandbox & Deploy','Safe staging & deploys',I.sandbox]]},
  {g:'Catalog',built:true,items:[['Catalog','Products, categories, tags, brands, attributes',I.catalog],['Inventory','Stock & low-stock alerts',I.catalog],['Catalog Order','Reorder products in any category',I.catalog]]},
  {g:'Growth',built:true,items:[['Product Labels','Image/text badges on thumbnails — seasonal offers, assign by product or category','<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.3"/>'],['Meta & Facebook','Pixel, Conversions API & product catalog feed','<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>']]},
  {g:'Selling',phase:'P3',items:[['Orders','Orders, statuses, refunds',I.orders],['Invoices','Invoice & packing PDFs',I.orders],['Checkout','Checkout flow & fields',I.orders],['Shipping','Per-country rates, on/off',I.orders],['Tax','VAT & tax rates',I.cash],['Payments','Stripe · Tabby · Tamara · COD',I.cash]]},
  {g:'Customers',phase:'P3',items:[['Customers','Accounts, addresses, logins',I.cust],['Reviews','Ratings, photos, moderation',I.cust]]},
  {g:'Growth & Experience',phase:'P2–P4',items:[
    ['Bundles & Routine Builder','Build Your Own Routine, kits',I.mkt],['Shoppable Video / UGC','Video gallery, shop-the-look',I.mkt],
    ['Loyalty & Rewards','Points, tiers, referrals',I.mkt],['Gift Cards & Store Credit','Digital cards + credit',I.cash],
    ['Subscriptions','Subscribe & save',I.mkt],['Returns / RMA','Self-service returns + tracking',I.orders],
    ['Localization & Currency','Arabic / English (RTL), AED/SAR/KWD',I.mkt],['WhatsApp Commerce','Order / cart / stock messages',I.mkt],
    ['Coupons & Marketing','Coupons, campaigns',I.mkt],['Cart Recovery','Abandoned-cart flows',I.mkt],
    ['SEO','Meta, schema, sitemaps, redirects',I.mkt],['Email / SMTP','Transactional + delivery log',I.mkt],
    ['PWA','Installable app, push, offline',I.modules],['Ad & Catalog Feeds','Meta / Google / TikTok',I.mkt],
    ['Recommendations','FBT, complete-the-routine',I.mkt],['Affiliate / Influencer','Codes & commissions',I.mkt],
    ['Ingredient Glossary','Ingredient pages + filter',I.catalog],['Stock & Price Alerts','Back-in-stock, price-drop',I.bell],
    ['Skin Quiz','Quiz + lead capture',I.mkt],['Advanced Search','Typo-tolerant, synonyms',I.catalog]]},
  {g:'Content',phase:'P5',items:[['KBB Page Builder','Elementor-class builder',I.content],['HTML Blocks','Reusable blocks',I.content],['Blog','Posts & comments',I.content],['Forms','Form builder + submissions',I.content]]},
  {g:'System',phase:'P6',items:[['Reports & Analytics','Sales & traffic',I.revenue],['Security','IP rules, login protection, 2FA',I.shield],['Import / Export','Migration engine',I.sandbox],['Custom Fields','Extra fields',I.settings],['Cosmetics','Occasion themes + bar',I.theme],['Consent & Privacy','PDPL + cookie consent',I.shield],['Accessibility','WCAG helpers',I.shield],['Bulk Catalog Editing','Large-catalog edits',I.catalog],['AI Agent','On-site assistant',I.bell]]}
];
let modState={};

/* ---------- Core Updates ----------
   Rendered inside the console like every other screen. The standalone page at
   /{admin}/updates stays as the fallback: if this bundle ever fails to load,
   you still need somewhere to install the update that fixes it. */
let UPD = null;

/* The API sits at <app root>/admin-api/updates, one level above the admin path,
   so it keeps working whatever the admin address is renamed to. */
function uBase(){
  return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/updates';
}

/* Read the CSRF cookie here rather than borrow the console's cookie() helper —
   that one is declared inside a later IIFE and is not in scope at this point in
   the file. Depending on it is what produced "cookie is not defined". */
function uToken(){
  const m = document.cookie.split('; ').find(c => c.indexOf('XSRF-TOKEN=') === 0);
  return m ? decodeURIComponent(m.split('=').slice(1).join('=')) : '';
}

async function uApi(path, opts){
  const o = Object.assign({credentials:'same-origin', headers:{}}, opts||{});
  o.headers['X-XSRF-TOKEN'] = uToken();
  o.headers['Accept'] = 'application/json';

  const url = uBase() + path;
  let r;
  try {
    r = await fetch(url, o);
  } catch (e) {
    return {ok:false, data:{errors:['Could not reach ' + url + ' — ' + e.message]}};
  }

  const text = await r.text();
  try {
    return {ok:r.ok, data:JSON.parse(text)};
  } catch (e) {
    // A non-JSON body means an error page, not an API response. Show the first
    // part of it rather than failing silently on a blank screen.
    return {ok:false, data:{errors:[
      'HTTP ' + r.status + ' from ' + url,
      text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)
    ]}};
  }
}

async function renderUpdates(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Core Updates</h2>
    <p>Install patches and new features. Every package is verified, backed up and health-checked before it counts as applied.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;

  let res;
  try {
    res = await uApi('');
  } catch (e) {
    res = {ok:false, data:{errors:['Unexpected error: ' + e.message]}};
  }

  if(!res.ok || !res.data || !res.data.releases){
    const errs = (res.data && res.data.errors) || ['The updates endpoint did not return data.'];
    $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Core Updates</h2></div>
      <div class="card" style="padding:22px;border-color:#f0c2c2;background:#fdf3f3">
        <b>Could not load updates.</b>
        <ul style="margin:8px 0 0 18px">${errs.map(e=>`<li style="word-break:break-word">${e}</li>`).join('')}</ul>
        <p class="mdesc" style="margin-top:12px">The standalone page still works:
          <a href="${window.location.pathname.replace(/\/+$/,'')}/updates?fallback=1">open the fallback page</a>.</p>
      </div></div>`;
    return;
  }

  UPD = res.data;
  paintUpdates();
}

function paintUpdates(msg, err){
  const d = UPD;
  const p = d.pending;

  const banner = msg ? `<div class="card" style="padding:14px 18px;border-color:#b7e2c6;background:#f2fbf5;margin-bottom:16px">${escHtml(msg)}</div>` : '';
  const errors = err && err.length ? `<div class="card" style="padding:14px 18px;border-color:#f0c2c2;background:#fdf3f3;margin-bottom:16px">
      <b>Nothing was changed.</b><ul style="margin:8px 0 0 18px">${err.map(e=>`<li>${escHtml(e)}</li>`).join('')}</ul></div>` : '';

  const upload = p ? `
    <div class="card" style="padding:22px;border-color:#b7e2c6">
      <div class="between"><div><b style="font-size:15px">${escHtml(p.name)} ${escHtml(p.version)}</b>
        <div class="mdesc" style="margin-top:4px">${escHtml(p.notes||'')}</div></div>
        <span class="pill green">Ready to apply</span></div>
      <p style="margin:14px 0 6px"><b>${p.changes.length} files</b> will change.
        ${p.migrations ? 'This update also changes the database — a full dump is taken first.' : ''}</p>
      <details><summary style="cursor:pointer;color:#2563eb">Show every file</summary>
        <div style="max-height:220px;overflow:auto;background:#f8fafc;border-radius:8px;padding:10px;margin-top:8px;font:12px ui-monospace,monospace">
          ${p.changes.map(c=>`<div><span class="pill ${c.action==='add'?'green':'grey'}" style="font-size:9px;padding:1px 6px">${c.action}</span> ${c.path}</div>`).join('')}
        </div></details>
      <div style="background:#f8fafc;border-left:3px solid #16a34a;padding:10px 14px;margin:14px 0;font-size:13px">
        Every file being replaced is backed up first. Afterwards the site is checked over HTTP — if it does not answer correctly the update is undone automatically.
      </div>
      <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn primary" onclick="uApply()">Apply update</button>
        <button class="btn" onclick="uCancel()">Cancel</button>
      </div>
    </div>`
  : `
    <div class="card" style="padding:22px">
      <b style="font-size:15px">Upload an update</b>
      <p class="mdesc" style="margin:6px 0 14px">The package is checked first — file list, checksums, permitted paths. Nothing is written until you confirm.</p>
      <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <input type="file" id="uFile" accept=".zip" class="inp">
        <button class="btn primary" onclick="uCheck()">Check package</button>
      </div>
    </div>`;

  const history = `
    <div class="sec-title">History</div>
    <div class="card" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr>${['Version','Status','Files','When',''].map(h=>`<th style="text-align:left;padding:10px 14px;background:#f8fafc;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b">${h}</th>`).join('')}</tr></thead>
        <tbody>${d.releases.length ? d.releases.map(r=>`<tr style="border-top:1px solid #eef2f7">
          <td style="padding:10px 14px"><b>${escHtml(r.version)}</b><div class="mdesc">${escHtml(r.name)}</div>
            ${r.superseded_by?`<div class="mdesc" style="color:#b45309">⚠ Superseded by ${escHtml(r.superseded_by)} — see that version's notes</div>`:''}</td>
          <td style="padding:10px 14px"><span class="pill ${r.status==='applied'?'green':'grey'}">${escHtml(r.status.replace('_',' '))}</span>
            ${r.error?`<div class="mdesc" style="max-width:520px">${escHtml(r.error)}</div>`:''}</td>
          <td style="padding:10px 14px">${r.files}</td>
          <td style="padding:10px 14px" class="mdesc">${r.when||''}</td>
          <td style="padding:10px 14px">${r.has_archive?`<a href="${uBase()}/${r.id}/download" class="btn small">Download</a>`:''}</td></tr>`).join('')
          : `<tr><td colspan="5" style="padding:14px" class="mdesc">No updates yet.</td></tr>`}</tbody>
      </table>
    </div>`;

  const restore = `
    <div class="sec-title">Restore a previous version</div>
    <div class="card" style="padding:0;overflow:hidden">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <tbody>${d.backups.length ? d.backups.map(b=>`<tr style="border-top:1px solid #eef2f7">
          <td style="padding:10px 14px"><code>${b.id}</code><div class="mdesc">${b.created_at||''}</div></td>
          <td style="padding:10px 14px" class="mdesc">${b.replaced} files restored, ${b.added} removed${b.database?' · + database dump':''}</td>
          <td style="padding:10px 14px;text-align:right">
            <button class="btn" onclick="uRestore('${b.id}')">Restore</button></td></tr>`).join('')
          : `<tr><td style="padding:14px" class="mdesc">No backups yet — the first update creates one.</td></tr>`}</tbody>
      </table>
    </div>`;

  const path = `
    <div class="sec-title">Admin address</div>
    <div class="card" style="padding:22px">
      <p class="mdesc" style="margin-top:0">The console currently answers at <code>/${d.admin_path}</code>.
      Changing it makes the old address return 404 — not a redirect, which would only announce where it moved.</p>
      ${d.admin_path_locked
        ? `<div style="background:#fffaf0;border:1px solid #f0d391;border-radius:8px;padding:12px 15px;font-size:13px">
             <b>Locked by .env.</b> <code>KBB_ADMIN_PATH</code> is set there and always wins. Remove that line to manage it here.</div>`
        : `<div style="background:#f8fafc;border-left:3px solid #16a34a;padding:10px 14px;margin-bottom:14px;font-size:13px">
             A secret address stops automated scanners. It is not a security control — anyone who sees one admin link has it.
             Your password and the rate limiter are the real protection.<br><br>
             <b>If you ever lock yourself out</b>, set <code>KBB_ADMIN_PATH</code> in <code>.env</code>; it overrides whatever is stored here.
           </div>
           <div class="row" style="gap:8px;align-items:center;flex-wrap:wrap">
             <input type="text" id="uPath" value="${d.admin_path}" class="inp" style="width:220px">
             <input type="password" id="uPathPass" placeholder="Admin password" class="inp" style="width:200px">
             <button class="btn primary" onclick="uSavePath()">Change address</button>
           </div>`}
    </div>`;

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Core Updates</h2>
      <p>Version <b>${d.version}</b>${d.signed?' · signed packages only':' · <span style="color:#b45309">unsigned packages accepted</span>'}</p></div>
    ${banner}${errors}${upload}${history}${restore}${path}
  </div>`;
}

async function uCheck(){
  const f = $('#uFile').files[0];
  if(!f){ toast('Choose a .zip first'); return; }
  const fd = new FormData(); fd.append('package', f);
  toast('Checking package…');
  const r = await uApi('/check', {method:'POST', body:fd});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.ok?'Package verified. Review the file list, then apply.':null, r.ok?null:(r.data?.errors||['Upload failed.']));
}

async function uApply(){
  toast('Applying — do not close this tab…');
  const r = await uApi('/apply', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({})});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.data?.message||null, r.ok?null:(r.data?.errors||null));
}

async function uCancel(){
  await uApi('/cancel', {method:'POST'});
  const s = await uApi(''); UPD = s.data;
  paintUpdates('Package discarded.');
}

async function uRestore(id){
  const r = await uApi('/restore/'+encodeURIComponent(id), {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({})});
  const s = await uApi(''); UPD = s.data;
  paintUpdates(r.data?.message||null, r.ok?null:(r.data?.errors||null));
}

async function uSavePath(){
  const v = $('#uPath').value, pass = $('#uPathPass').value;
  if(!pass){ toast('Enter your password'); return; }
  const r = await uApi('/admin-path', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({admin_path:v, password:pass})});
  if(r.ok && r.data?.redirect){ toast(r.data.message); setTimeout(()=>{ window.location.href = r.data.redirect; }, 1200); return; }
  const s = await uApi(''); UPD = s.data;
  paintUpdates(null, r.data?.errors||['Could not change the address.']);
}


/* ---------- Pages ----------
   Split deliberately. Store pages are routes the application owns and cannot be
   deleted; user pages are rows in the pages table. Showing them in one list is
   how someone ends up trying to delete /checkout. */
async function pagesApi(path){
  const base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/pages';
  try{
    const r = await fetch(base + path, {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) return null;
    return await r.json();
  }catch(e){ return null; }
}

function pagesTable(rows, system){
  if(!rows.length) return `<div class="card" style="padding:22px"><span class="mdesc">No pages yet.</span></div>`;
  return `<div class="card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr>${['Page','Address', system?'What it does':'Status', ''].map(h=>`<th style="text-align:left;padding:10px 14px;background:#f8fafc;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b">${h}</th>`).join('')}</tr></thead>
      <tbody>${rows.map(p=>`<tr style="border-top:1px solid #eef2f7">
        <td style="padding:10px 14px"><b>${p.name}</b>${system?' <span class="pill grey" style="font-size:9px;padding:1px 7px">system</span>':''}</td>
        <td style="padding:10px 14px"><code>${p.path}</code></td>
        <td style="padding:10px 14px" class="mdesc">${system ? (p.note||'') : (p.status||'')}${!system&&p.updated?` · ${p.updated}`:''}</td>
        <td style="padding:10px 14px;text-align:right"><a class="btn small" href="${p.url}" target="_blank" rel="noopener">View</a></td>
      </tr>`).join('')}</tbody>
    </table></div>`;
}

async function renderStorePages(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Store pages</h2>
    <p>Built into the storefront. These addresses are fixed — they cannot be created or deleted here, only viewed.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;
  const d = await pagesApi('/store');
  if(!d){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load store pages.</div></div>`; return; }
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Store pages</h2>
    <p>Built into the storefront. These addresses are fixed — they cannot be created or deleted here, only viewed.</p></div>
    ${pagesTable(d.pages, true)}</div>`;
}

async function renderUserPages(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>User pages</h2>
    <p>Pages you create — About, Delivery, FAQs, Terms. Anything added here appears in this list.</p></div>
    <div class="card" style="padding:22px">Loading…</div></div>`;
  const d = await pagesApi('/user');
  if(!d){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load user pages.</div></div>`; return; }
  $('#content').innerHTML = `<div class="wrap"><div class="between" style="margin-bottom:16px">
      <div class="page-head" style="margin:0"><h2>User pages</h2><p>Pages you create. ${d.pages.length} so far.</p></div>
      <button class="btn" onclick="toast('Page editor arrives with the CMS in Phase 11')">${ic('<path d="M12 5v14M5 12h14"/>')} New page</button></div>
    ${pagesTable(d.pages, false)}</div>`;
}


/* ---------- Appearance · Product grid ----------
   28 card templates. Hovering a swatch previews it live in the sample grid, so
   you can compare without saving. */
async function renderLayout(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/layout';
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Product grid</h2><p>Loading…</p></div></div>`;
  let d; try{ d = await (await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}})).json(); }
  catch(e){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load layout settings.</div></div>`; return; }

  const swatches = d.skins.map(s=>`<button class="skinsw${s.key===d.current?' on':''}" data-skin="${s.key}" title="${s.label}">
      <span class="skinsw-p" data-skin-preview="${s.key}"></span><span class="skinsw-l">${s.label}</span></button>`).join('');

  const codes = d.shortcodes.map(c=>`<tr style="border-top:1px solid #eef2f7">
      <td style="padding:8px 12px"><code style="background:#f1f5f9;padding:2px 6px;border-radius:5px">${c.code.replace(/</g,'&lt;')}</code>
      <button class="btn small" style="margin-left:8px" data-copy="${c.code.replace(/"/g,'&quot;')}">Copy</button></td>
      <td style="padding:8px 12px" class="mdesc">${c.desc}</td></tr>`).join('');

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Product grid</h2><p>Pick the card template used across the shop, category pages and every <code>[kbb_products]</code> shortcode.</p></div>

    <div class="card" style="padding:18px">
      <div class="between" style="margin-bottom:12px">
        <b style="font-size:13px">Skin</b>
        <label style="font-size:12px;color:#64748b">Columns
          <select id="gridCols" style="margin-left:6px;padding:4px 8px;border:1px solid #dbe3ec;border-radius:7px">
            ${[1,2,3,4,5,6].map(n=>`<option value="${n}"${n===d.columns?' selected':''}>${n}</option>`).join('')}
          </select></label>
      </div>
      <div class="skingrid">${swatches}</div>
      <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
        <button class="btn primary" id="saveLayout">Save</button>
        <span class="mdesc" id="layoutMsg"></span>
      </div>
    </div>

    <div class="page-head" style="margin-top:26px"><h2>Shortcodes</h2><p>Paste any of these into a page, post or HTML block.</p></div>
    <div class="card" style="padding:0;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead><tr><th style="text-align:left;padding:10px 12px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">Shortcode</th>
      <th style="text-align:left;padding:10px 12px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">What it does</th></tr></thead>
      <tbody>${codes}</tbody></table></div>
  </div>`;

  let picked = d.current;
  $('#content').addEventListener('click', async (e)=>{
    const sw = e.target.closest('[data-skin]');
    if(sw){ picked = sw.dataset.skin;
      document.querySelectorAll('[data-skin]').forEach(b=>b.classList.toggle('on', b===sw)); return; }

    const cp = e.target.closest('[data-copy]');
    if(cp){ navigator.clipboard?.writeText(cp.dataset.copy); toast('Shortcode copied'); return; }

    if(e.target.id==='saveLayout'){
      const cols = Number($('#gridCols').value);
      try{
        const r = await fetch(base,{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({skin:picked,columns:cols})});
        const j = await r.json();
        $('#layoutMsg').textContent = j.ok ? 'Saved — live on the storefront now.' : (j.error||'Could not save.');
      }catch(err){ $('#layoutMsg').textContent = 'Could not save.'; }
    }
  });
}


/* ---------- Appearance · Quantity bundles ----------
   Buy-more-save-more tiers, applied to every product. The preview shows the
   effect on a AED 55 product so a change can be judged before saving. */
async function renderBundles(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/bundles';
  let d; try{ d = await (await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}})).json(); }
  catch(e){ $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load bundle settings.</div></div>`; return; }

  const row = (t,i)=>`<tr data-row="${i}" style="border-top:1px solid #eef2f7">
      <td style="padding:8px 10px"><input type="number" min="1" max="99" value="${t.qty}" data-f="qty" style="width:70px;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px"><input type="number" min="0" max="90" step="0.5" value="${t.discount}" data-f="discount" style="width:80px;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"> %</td>
      <td style="padding:8px 10px"><input value="${t.label.replace(/"/g,'&quot;')}" data-f="label" style="width:100%;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px"><input value="${(t.tag||'').replace(/"/g,'&quot;')}" data-f="tag" placeholder="Save {n}%" style="width:100%;padding:6px 8px;border:1px solid #dbe3ec;border-radius:7px"></td>
      <td style="padding:8px 10px;text-align:right"><button class="btn small" data-del="${i}">Remove</button></td></tr>`;

  const prev = (rows)=>rows.map(r=>`<tr style="border-top:1px solid #eef2f7">
      <td style="padding:7px 10px"><b>${r.label}</b> <span class="mdesc">×${r.qty}</span></td>
      <td style="padding:7px 10px">AED ${r.total}</td>
      <td style="padding:7px 10px" class="mdesc"><s>AED ${r.was}</s></td>
      <td style="padding:7px 10px;color:#1F7D52">saves AED ${r.saved}</td></tr>`).join('');

  $('#content').innerHTML = `<div class="wrap">
    <div class="page-head"><h2>Quantity bundles</h2><p>Offer a better rate for buying more of the same product. Applies to every product automatically — no per-product setup.</p></div>

    <div class="card" style="padding:18px">
      <label class="row" style="gap:8px;font-size:13.5px;cursor:pointer;margin-bottom:14px">
        <input type="checkbox" id="bEnabled" ${d.enabled?'checked':''}> Show bundle options on product pages
      </label>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr>${['Quantity','Discount','Label','Tag',''].map(h=>`<th style="text-align:left;padding:8px 10px;background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">${h}</th>`).join('')}</tr></thead>
        <tbody id="bRows">${d.tiers.map(row).join('')}</tbody>
      </table>
      <div style="margin-top:12px;display:flex;gap:10px;align-items:center">
        <button class="btn" id="bAdd">${ic('<path d="M12 5v14M5 12h14"/>')} Add tier</button>
        <button class="btn primary" id="bSave">Save</button>
        <span class="mdesc" id="bMsg"></span>
      </div>
      <p class="mdesc" style="margin-top:10px">Use <code>{n}</code> in a tag to insert the saving, e.g. <code>Save {n}%</code>.</p>
    </div>

    <div class="page-head" style="margin-top:24px"><h2>Preview</h2><p>On a AED 55 product.</p></div>
    <div class="card" style="padding:0;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody id="bPrev">${prev(d.preview)}</tbody></table></div>
  </div>`;

  $('#content').addEventListener('click', async (e)=>{
    if(e.target.id==='bAdd'){
      const i = $('#bRows').children.length;
      $('#bRows').insertAdjacentHTML('beforeend', row({qty:i+1,discount:0,label:(i+1)+'-pack bundle',tag:'Save {n}%'}, i));
      return;
    }
    const del = e.target.closest('[data-del]');
    if(del){ del.closest('tr').remove(); return; }

    if(e.target.id==='bSave'){
      const tiers=[...$('#bRows').children].map(tr=>({
        qty:Number(tr.querySelector('[data-f=qty]').value),
        discount:Number(tr.querySelector('[data-f=discount]').value),
        label:tr.querySelector('[data-f=label]').value,
        tag:tr.querySelector('[data-f=tag]').value }));
      try{
        const r = await fetch(base,{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({enabled:$('#bEnabled').checked, tiers})});
        const j = await r.json();
        $('#bMsg').textContent = j.ok ? 'Saved — live on the storefront now.' : (j.error||'Could not save.');
        if(j.ok && j.preview) $('#bPrev').innerHTML = prev(j.preview);
      }catch(err){ $('#bMsg').textContent='Could not save.'; }
    }
  });
}


/* ---------- Store · Ecommerce ----------
   Tabs on top, sections within, and a preview icon beside every name. The
   preview expands in place — never a popup, never a new page. */
let ECOM=null, ETAB=0, EDIRTY=false;

const EEYE='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';

function ecomBase(){
  return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/ecommerce';
}

async function renderEcommerce(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Ecommerce</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(ecomBase(), {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    ECOM = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load settings. <button class="btn small" onclick="renderEcommerce()">Retry</button></div></div>`;
    return;
  }
  ETAB=0; EDIRTY=false; paintEcom();
}

function ecField(f){
  const id='ec_'+f.name;
  const v=f.value;
  let ctl;
  if(f.type==='bool'){
    ctl = `<span class="ectog${v?' on':''}" data-ec="${f.name}" data-t="bool" role="switch" aria-checked="${v?'true':'false'}" tabindex="0"></span>`;
  }else if(f.type==='select'){
    ctl = `<select id="${id}" data-ec="${f.name}" data-t="select" class="inp">`
        + Object.entries(f.options||{}).map(([k,l])=>`<option value="${escAttr(k)}"${String(v)===k?' selected':''}>${escHtml(l)}</option>`).join('')
        + `</select>`;
  }else if(f.type==='textarea'){
    ctl = `<textarea id="${id}" data-ec="${f.name}" data-t="text" class="inp" rows="2" style="width:100%;max-width:520px">${escHtml(v??'')}</textarea>`;
  }else if(f.type==='colour'){
    ctl = `<input type="color" id="${id}" data-ec="${f.name}" data-t="colour" value="${escAttr(v||'#000000')}" class="ecclr">`;
  }else if(f.type==='int'||f.type==='money'){
    ctl = `<input type="number" min="0" id="${id}" data-ec="${f.name}" data-t="${f.type}" value="${escAttr(v??0)}" class="inp" style="width:130px">`
        + (f.type==='money' ? ` <span class="ecunit">fils</span>` : '');
  }else{
    ctl = `<input type="text" id="${id}" data-ec="${f.name}" data-t="text" value="${escAttr(v??'')}" class="inp" style="width:100%;max-width:440px">`;
  }
  const pv = ECOM.previews && ECOM.previews[f.preview];
  const wide = f.type==='text' || f.type==='textarea';
  return `<div class="ecopt${wide?' wide':''}${f.type==='bool'?' istog':''}">
      <div class="ecom"><div class="ecl"><label for="${id}">${escHtml(f.label)}</label>${pv?`<button class="eceye" title="Show what this changes">${EEYE}</button>`:''}</div>
      ${f.help?`<div class="echelp">${f.help}</div>`:''}</div>
      <div class="ecctl">${ctl}</div>
    </div>${pv?ecPreview(pv):''}`;
}

function ecPreview(pv){
  return `<div class="ecpv"><div class="ecpvi">
      <div class="ecpvc"><span class="d"></span>${escHtml(pv.caption||'Preview')}</div>
      <div class="ecpvs">${pv.stage||''}</div>
      ${(pv.legend||[]).map((l,i)=>`<div class="eclg"><span class="n">${i+1}</span><span>${l}</span></div>`).join('')}
    </div></div>`;
}

const ECIC={
 globe:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/></svg>',
 grid:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
 truck:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 6h13v10H2z"/><path d="M15 9h4l3 3.5V16h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="18" cy="18" r="1.6"/></svg>',
 bag:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/></svg>',
 card:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>',
 phone:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>',
 pct:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m19 5-14 14"/><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/></svg>',
 star:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9z"/></svg>',
 find:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>',
 box:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/></svg>'
};

function paintEcom(){
  const t = ECOM.tabs[ETAB];
  $('#content').innerHTML = `<div class="wrap ecwrap">
    <div class="echd">
      <div class="row" style="align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Ecommerce</h2>
          <p class="mdesc" style="margin:0">Everything the storefront reads at runtime. Changes go live as soon as you save.</p></div>
        <div style="flex:1"></div>
        <div class="ecsearch"><input id="ecFind" placeholder="Search settings…" autocomplete="off"></div>
      </div>
      <div class="ectabs">${ECOM.tabs.map((x,i)=>
        `<button class="ectab${i===ETAB?' on':''}" data-ectab="${i}">${escHtml(x.label)}<span class="ct">${x.count}</span></button>`).join('')}</div>
    </div>

    <div class="ecbody">${t.sections.map(sec=>{
      const pv = ECOM.previews && ECOM.previews[sec.preview];
      return `<div class="ecsec">
        <div class="ecsech">
          <span class="ecic">${ECIC[sec.icon]||ECIC.box}</span>
          <div class="ecsect"><h3>${escHtml(sec.label)}</h3>${sec.desc?`<p>${escHtml(sec.desc)}</p>`:''}</div>
          <span class="ecsp"></span>
          ${pv?`<button class="eceye" title="Preview this section">${EEYE}</button>`:''}
        </div>
        ${pv?ecPreview(pv):''}
        <div class="ecsecb">${sec.fields.map(ecField).join('')}</div>
      </div>`;}).join('')}</div>

    <div class="ecsave">
      <span class="ecdirty" id="ecDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="ecDiscard">Discard</button>
      <button class="btn primary" id="ecSave">Save changes</button>
    </div>
  </div>`;
  if(EDIRTY) $('#ecDirty').style.visibility='visible';
}

function ecMarkDirty(){ EDIRTY=true; const d=$('#ecDirty'); if(d) d.style.visibility='visible'; }

function ecTogglePreview(btn){
  // A section eye opens the panel right after the header; an option eye opens
  // the panel that follows its row.
  const anchor = btn.closest('.ecsech') || btn.closest('.ecopt');
  const pv = anchor.nextElementSibling;
  if(!pv || !pv.classList.contains('ecpv')) return;
  const open = pv.classList.contains('on');
  document.querySelectorAll('.ecpv.on').forEach(p=>p.classList.remove('on'));
  document.querySelectorAll('.eceye.on').forEach(e=>e.classList.remove('on'));
  if(!open){ pv.classList.add('on'); btn.classList.add('on'); }
}

async function ecSaveNow(){
  const settings={};
  document.querySelectorAll('[data-ec]').forEach(el=>{
    settings[el.dataset.ec] = el.dataset.t==='bool' ? el.classList.contains('on') : el.value;
  });
  const msg=$('#ecDirty');
  try{
    const r = await fetch(ecomBase(), {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings})});
    const j = await r.json();
    if(j.ok){
      EDIRTY=false;
      msg.style.visibility='visible'; msg.textContent='Saved — live on the storefront'; msg.classList.add('ok');
      // Keep the loaded copy in step so switching tabs does not revert.
      ECOM.tabs.forEach(t=>t.sections.forEach(s=>s.fields.forEach(f=>{
        if(f.name in settings) f.value = settings[f.name];
      })));
      setTimeout(()=>{ msg.classList.remove('ok'); msg.textContent='Unsaved changes'; msg.style.visibility='hidden'; }, 2600);
    }else{
      msg.style.visibility='visible'; msg.textContent = j.error || 'Could not save.';
    }
  }catch(e){
    msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.';
  }
}

let ECHOVER;
document.addEventListener('click', e=>{
  const tb=e.target.closest('[data-ectab]');
  if(tb){ ETAB=+tb.dataset.ectab; paintEcom(); return; }
  const tg=e.target.closest('.ectog');
  if(tg){ tg.classList.toggle('on'); tg.setAttribute('aria-checked', tg.classList.contains('on')); ecMarkDirty(); return; }
  const ey=e.target.closest('.eceye');
  if(ey){ ecTogglePreview(ey); return; }
  if(e.target.id==='ecSave'){ ecSaveNow(); return; }
  if(e.target.id==='ecDiscard'){ renderEcommerce(); return; }
});
document.addEventListener('keydown', e=>{
  if(e.target.classList && e.target.classList.contains('ectog') && (e.key===' '||e.key==='Enter')){
    e.preventDefault(); e.target.click();
  }
});
document.addEventListener('mouseover', e=>{
  const ey=e.target.closest('.eceye'); if(!ey) return;
  clearTimeout(ECHOVER);
  ECHOVER=setTimeout(()=>{ if(!ey.classList.contains('on')) ecTogglePreview(ey); }, 260);
});
document.addEventListener('mouseout', e=>{ if(e.target.closest('.eceye')) clearTimeout(ECHOVER); });
document.addEventListener('input', e=>{
  if(e.target.id==='ecFind'){ ecFilter(e.target.value); return; }
  if(e.target.dataset && e.target.dataset.ec) ecMarkDirty();
});
document.addEventListener('change', e=>{ if(e.target.dataset && e.target.dataset.ec) ecMarkDirty(); });

function ecFilter(q){
  q=(q||'').trim().toLowerCase();
  document.querySelectorAll('.ecopt').forEach(o=>{
    const t=o.textContent.toLowerCase();
    o.style.display = !q || t.includes(q) ? '' : 'none';
  });
  document.querySelectorAll('.ecsec').forEach(s=>{
    const any=[...s.querySelectorAll('.ecopt')].some(o=>o.style.display!=='none');
    s.style.display = any ? '' : 'none';
  });
}

function escHtml(v){ return String(v??'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])); }
function escAttr(v){ return String(v??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }


/* ---------- Appearance · Homepage ----------
   Every section can be switched off independently for desktop and mobile, and
   product sections carry their own grid skin. Order is drag-free: up and down
   arrows, which are far easier on a touch screen. */
let HP=null;

async function renderHomepage(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/homepage';
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Homepage</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    HP = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load homepage settings. <button class="btn small" onclick="renderHomepage()">Retry</button></div></div>`;
    return;
  }
  await loadDemo();
  paintHomepage(base);
}


/* A wireframe of the layout, drawn from its real section order — not a stock
   thumbnail, so it always matches what applying it will do. */

/* ---------- demo content ----------
   A confirmation before either direction: switching it on changes what the
   storefront shows to real visitors, and switching it off is what someone
   worried about their catalogue will hesitate over. The dialog says plainly
   that nothing is stored either way. */
let DEMO = null;

async function loadDemo(){
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/demo';
  try{ DEMO = await (await fetch(base,{credentials:'same-origin',headers:{Accept:'application/json'}})).json(); }
  catch(e){ DEMO = {enabled:false}; }
}

function demoConfirm(turningOn){
  return new Promise(resolve=>{
    const w=document.createElement('div');
    w.className='kdlg';
    w.innerHTML=`<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
        <h3>${turningOn?'Show demo content?':'Hide demo content?'}</h3>
        <p>${turningOn
          ? 'Sample products, brands, reviews and articles will appear in any homepage section that does not have enough real content yet. Visitors to the storefront will see them.'
          : 'Sample items will stop appearing. Sections without enough real content will simply show fewer items, or nothing.'}</p>
        <p class="kdlg-safe">Demo content is only ever displayed. Nothing is written to or removed from your catalogue, and your real products, reviews and articles are not touched either way.</p>
        <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
          <button class="btn primary" data-yes>${turningOn?'Show demo content':'Hide demo content'}</button></div>
      </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(()=>w.classList.add('on'));
    const done=v=>{w.classList.remove('on');setTimeout(()=>w.remove(),200);resolve(v)};
    w.querySelector('[data-no]').onclick=()=>done(false);
    w.querySelector('.kdlg-s').onclick=()=>done(false);
    w.querySelector('[data-yes]').onclick=()=>done(true);
    document.addEventListener('keydown',function esc(e){
      if(e.key==='Escape'){ document.removeEventListener('keydown',esc); done(false); }
    });
  });
}

document.addEventListener('click', async e=>{
  const tog=e.target.closest('#demotog'); if(!tog) return;
  const turningOn = !tog.classList.contains('on');
  if(!await demoConfirm(turningOn)) return;

  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/demo';
  try{
    const r = await fetch(base,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({enabled:turningOn})});
    const j = await r.json();
    if(j.ok){
      DEMO.enabled=j.enabled;
      tog.classList.toggle('on', j.enabled);
      tog.setAttribute('aria-checked', j.enabled);
      const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.add('ok');
        d.textContent = j.enabled ? 'Demo content shown — live now' : 'Demo content hidden — live now';
        setTimeout(()=>{d.classList.remove('ok');d.textContent='Unsaved changes';d.style.visibility='hidden';},2600);}
    }
  }catch(err){
    const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent='Could not change demo content.';}
  }
});


/* A real card in the chosen skin, using the storefront's own markup so a
   preview cannot drift from what ships. */
function skinCard(skin, cartLabel){
  /* The same elements and classes as components/product-grid.blade.php.
     Element types matter: .cb, .cn, .cp and .kbb-card-thumb never set display,
     so building the preview from spans left them inline and the card collapsed. */
  return `<div class="kbb-pgrid" data-skin="${skin}">
    <a class="kbb-card" href="#" onclick="return false">
      <div class="kbb-card-thumb">
        <span class="kbb-card-ph"></span>
        <span class="kbb-badge kbb-badge-new">New</span>
        <span class="kbb-badge kbb-badge-sale">-30%</span>
      </div>
      <div class="cb">
        <div class="kbb-card-cat">Sun care</div>
        <div class="cn"><span class="kbb-card-brand">BEAUTY OF JOSEON</span> Relief Sun Rice + Probiotics SPF50+</div>
        <div class="kbb-card-rate"><span class="kbb-crate"><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span><span class="kbb-cstar on">★</span></span> <span class="kbb-card-rc">(3204)</span></div>
        <div class="cp"><span class="kbb-card-reg">&#1583;.&#1573;102</span> <span class="kbb-card-price">&#1583;.&#1573;71</span></div>
        <span class="kbb-card-cart">${escHtml(cartLabel || 'Add to cart')}</span>
      </div>
    </a></div>`;
}

/* Opening a picker closes any other, so only one panel is ever on screen. */
document.addEventListener('click', e=>{
  const open=e.target.closest('[data-open]');
  if(open){
    const pop=document.querySelector(`[data-pop="${open.dataset.open}"]`);
    document.querySelectorAll('.skinpop.on').forEach(p=>{ if(p!==pop) p.classList.remove('on'); });
    pop.classList.toggle('on');
    return;
  }
  const pick=e.target.closest('[data-pickskin]');
  if(pick){
    const [i,key]=pick.dataset.pickskin.split('|');
    if(typeof HP!=='undefined' && HP.sections[i]){
      HP.sections[i].skin=key;
      const btn=document.querySelector(`[data-open="${i}"]`);
      if(btn) btn.firstChild.textContent=(HP.skins.find(s=>s.key===key)||{}).label||key;
      pick.closest('.skinpop').querySelectorAll('.skinopt').forEach(o=>o.classList.toggle('on',o===pick));
      pick.closest('.skinpop').classList.remove('on');
      const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';}
    }
    return;
  }
  if(!e.target.closest('.skinpick')) document.querySelectorAll('.skinpop.on').forEach(p=>p.classList.remove('on'));
});

function hpWire(L){
  const BAR={'Hero slider':22,'Category circles':13,'Big savings bundles':17,'Recommended for you':17,
    'Best sellers':17,'Flash sale':17,'Build your routine':13,'Skin quiz':15,'Top brands':11,
    '#KBeautyBliss spotted':13,'Skincare guide':13,'About us':13,'Customer reviews':15,
    'Trust row':7,'Newsletter':11,'Promo ticker':5,'Delivery strip':5};
  const ACC={'Hero slider':'#E0567B','Skin quiz':'#3c4655','Flash sale':'#E23B57','Newsletter':'#1F7D52'};
  return `<div class="wire">${L.order.map(n=>
    `<i style="height:${BAR[n]||11}px;background:${ACC[n]||'#E9DDE3'}" title="${n}"></i>`).join('')}</div>`;
}

function paintHomepage(base){
  const rows = HP.sections.map((s,i)=>`
    <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
      <div class="hpmove">
        <button class="hpb" data-mv="-1" ${i===0?'disabled':''} aria-label="Move up">↑</button>
        <button class="hpb" data-mv="1" ${i===HP.sections.length-1?'disabled':''} aria-label="Move down">↓</button>
      </div>
      <div class="hpmain">
        <b>${escHtml(s.label)}</b>
        <span>${escHtml(s.description)}</span>
        ${s.has_grid?`<div class="hpskin"><label>Grid style</label>
          <span class="skinpick">
            <button class="skinpick-b" type="button" data-open="${i}">${escHtml((HP.skins.find(k=>k.key===s.skin)||{}).label||s.skin)}<i>&#9662;</i></button>
            <span class="skinpop" data-pop="${i}">
              ${HP.skins.map(k=>`<button class="skinopt${k.key===s.skin?' on':''}" type="button" data-pickskin="${i}|${escAttr(k.key)}">
                <span class="skinprev">${skinCard(k.key)}</span><span class="skinopt-l">${escHtml(k.label)}</span></button>`).join('')}
            </span></span></div>`:''}
      </div>
      <span class="ectog${s.desktop?' on':''}" data-tg="${i}" data-k="desktop" role="switch" aria-checked="${s.desktop}" tabindex="0"></span>
      <span class="ectog${s.mobile?' on':''}" data-tg="${i}" data-k="mobile" role="switch" aria-checked="${s.mobile}" tabindex="0"></span>
    </div>`).join('');

  $('#content').innerHTML = `<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Homepage</h2>
      <p class="mdesc" style="margin:0">Switch any section off, per device. A section off for both is not rendered at all.</p></div>

    <div class="demorow" id="demorow">
      <div class="demo-ic">${DEMO && DEMO.enabled ? '👁' : '👁'}</div>
      <div class="demo-t"><b>Demo content</b>
        <span>Fills empty sections with sample products, brands, reviews and articles so the page can be judged before the catalogue is imported.</span>
        <span class="demo-safe">Displayed only — never saved. Real content always takes priority and is never modified.</span></div>
      <span class="ectog${DEMO && DEMO.enabled ? ' on' : ''}" id="demotog" role="switch" aria-checked="${DEMO && DEMO.enabled}" tabindex="0"></span>
    </div>

    <div class="hpsec-h">
      <div><b>Layouts</b><span>Presets that set order, visibility and grid styles in one move. Anything can be adjusted afterwards.</span></div>
    </div>
    <div class="hplayouts">${(HP.layouts||[]).map(L=>`
      <div class="hpl${L.key===HP.layout?' on':''}" data-layout="${escAttr(L.key)}">
        <div class="hpl-p">${hpWire(L)}</div>
        <b>${escHtml(L.name)}${L.key===HP.layout?'<i>Applied</i>':''}</b>
        <span class="bl">${escHtml(L.blurb)}</span>
        <span class="su">${escHtml(L.suits)}</span>
        <div class="hpl-m"><span>${L.count} sections</span>${L.off.length?`<span class="offl">${L.off.length} off</span>`:''}</div>
        <button class="btn small hpl-b">${L.key===HP.layout?'Re-apply':'Apply layout'}</button>
      </div>`).join('')}</div>

    <div class="hpsec-h" style="margin-top:22px">
      <div><b>Sections</b><span>Switch any section off, per device. A section off for both is not rendered at all.</span></div>
    </div>
    <div class="hphead"><span>Section</span><span>Desktop</span><span>Mobile</span></div>
    <div class="hplist">${rows}</div>

    <div class="ecsave">
      <span class="ecdirty" id="hpDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="hpDiscard">Discard</button>
      <button class="btn primary" id="hpSave">Save changes</button>
    </div>
  </div>`;
}

function hpDirty(){ const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';} }

async function hpSaveNow(base){
  const msg=$('#hpDirty');
  try{
    const r = await fetch(base,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({sections:HP.sections.map(s=>({key:s.key,desktop:s.desktop,mobile:s.mobile,skin:s.skin}))})});
    const j = await r.json();
    if(j.ok){
      HP.sections = j.sections;
      msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} sections — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600);
    }else{
      msg.style.visibility='visible'; msg.textContent = j.error || 'Could not save.';
    }
  }catch(e){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
}

document.addEventListener('click', e=>{
  if(!HP) return;
  const base = window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/homepage';

  const tg=e.target.closest('[data-tg]');
  if(tg){
    const s=HP.sections[+tg.dataset.tg];
    s[tg.dataset.k]=!s[tg.dataset.k];
    tg.classList.toggle('on', s[tg.dataset.k]);
    tg.setAttribute('aria-checked', s[tg.dataset.k]);
    tg.closest('.hprow').classList.toggle('alloff', !s.desktop && !s.mobile);
    hpDirty(); return;
  }
  const mv=e.target.closest('[data-mv]');
  if(mv){
    const i=+mv.closest('.hprow').dataset.i, j=i+ +mv.dataset.mv;
    if(j<0||j>=HP.sections.length) return;
    [HP.sections[i],HP.sections[j]]=[HP.sections[j],HP.sections[i]];
    paintHomepage(base); hpDirty(); return;
  }
  const lay=e.target.closest('.hpl-b');
  if(lay){
    const key=lay.closest('[data-layout]').dataset.layout;
    lay.disabled=true; lay.textContent='Applying…';
    fetch(base+'/layout',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({layout:key})})
      .then(r=>r.json()).then(j=>{
        if(j.ok){ HP.sections=j.sections; HP.layout=j.layout; paintHomepage(base);
          const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.classList.add('ok');d.textContent='Layout applied — live now';
            setTimeout(()=>{d.classList.remove('ok');d.textContent='Unsaved changes';d.style.visibility='hidden';},2600);} }
        else { lay.disabled=false; lay.textContent='Apply layout';
          const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent=j.error||'Could not apply.';} }
      })
      .catch(()=>{ lay.disabled=false; lay.textContent='Apply layout';
        const d=$('#hpDirty'); if(d){d.style.visibility='visible';d.textContent='Could not apply.';} });
    return;
  }
  if(e.target.id==='hpSave'){ hpSaveNow(base); return; }
  if(e.target.id==='hpDiscard'){ renderHomepage(); return; }
});
document.addEventListener('change', e=>{
  const sel=e.target.closest('[data-skin]');
  if(sel && HP){ HP.sections[+sel.dataset.skin].skin=sel.value; hpDirty(); }
});
document.addEventListener('keydown', e=>{
  if(e.target.dataset && e.target.dataset.tg && (e.key===' '||e.key==='Enter')){ e.preventDefault(); e.target.click(); }
});


/* ---------- Appearance · Product page ----------
   Same shape as the Homepage screen: a switch per device for every module. */
let PP = null;

function ppBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-page'; }

async function renderProductPage(){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Product page</h2><p>Loading…</p></div></div>`;
  try{
    const r = await fetch(ppBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PP = await r.json();
  }catch(e){
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">Could not load product page settings. <button class="btn small" onclick="renderProductPage()">Retry</button></div></div>`;
    return;
  }
  paintProductPage();
}

function paintProductPage(){
  $('#content').innerHTML = `<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product page</h2>
      <p class="mdesc" style="margin:0">Switch any module off, per device. A module off for both is not rendered at all.</p></div>
    <div class="hphead"><span>Module</span><span>Desktop</span><span>Mobile</span></div>
    <div class="hplist">${PP.sections.map((s,i)=>`
      <div class="hprow${(!s.desktop&&!s.mobile)?' alloff':''}" data-i="${i}">
        <div class="hpmove"></div>
        <div class="hpmain"><b>${escHtml(s.label)}</b><span>${escHtml(s.description)}</span></div>
        <span class="ectog${s.desktop?' on':''}" data-pp="${i}" data-k="desktop" role="switch" aria-checked="${s.desktop}" tabindex="0"></span>
        <span class="ectog${s.mobile?' on':''}" data-pp="${i}" data-k="mobile" role="switch" aria-checked="${s.mobile}" tabindex="0"></span>
      </div>`).join('')}</div>
    <div class="ecsave">
      <span class="ecdirty" id="ppDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="ppDiscard">Discard</button>
      <button class="btn primary" id="ppSave">Save changes</button>
    </div></div>`;
}

document.addEventListener('click', async e=>{
  if(!PP) return;
  const tg=e.target.closest('[data-pp]');
  if(tg){
    const s=PP.sections[+tg.dataset.pp];
    s[tg.dataset.k]=!s[tg.dataset.k];
    tg.classList.toggle('on', s[tg.dataset.k]);
    tg.setAttribute('aria-checked', s[tg.dataset.k]);
    tg.closest('.hprow').classList.toggle('alloff', !s.desktop && !s.mobile);
    const d=$('#ppDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';}
    return;
  }
  if(e.target.id==='ppDiscard'){ renderProductPage(); return; }
  if(e.target.id!=='ppSave') return;

  const msg=$('#ppDirty');
  try{
    const r=await fetch(ppBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({sections:PP.sections.map(s=>({key:s.key,desktop:s.desktop,mobile:s.mobile}))})});
    const j=await r.json();
    if(j.ok){
      PP.sections=j.sections;
      msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} modules — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600);
    }else{ msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});
document.addEventListener('keydown', e=>{
  if(e.target.dataset && e.target.dataset.pp && (e.key===' '||e.key==='Enter')){ e.preventDefault(); e.target.click(); }
});




/* ---------- Appearance · Product styles ----------
   Two halves: how cards look, and a builder that writes a shortcode for
   dropping a grid into any page. */
let PS=null, PSTAB='layout';

function psBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-styles'; }

async function renderProdStyles(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Product styles</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(psBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PS=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load product styles. <button class="btn small" onclick="renderProdStyles()">Retry</button></div></div>`;
    return;
  }
  paintProdStyles();
}
function psGet(k){ for(const t of PS.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function psSet(k,v){ for(const t of PS.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function psField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ps="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ps="${f.key}">
        <i id="psv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <select data-ps="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-ps="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='skin')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="skinpick">
        <button class="skinpick-b" type="button" data-open="ps">${escHtml((PS.skins.find(k=>k.key===v)||{}).label||v)}<i>&#9662;</i></button>
        <span class="skinpop" data-pop="ps">${PS.skins.map(k=>`<button class="skinopt${k.key===v?' on':''}" type="button" data-psskin="${escAttr(k.key)}">
          <span class="skinprev">${skinCard(k.key)}</span><span class="skinopt-l">${escHtml(k.label)}</span></button>`).join('')}</span>
      </span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-ps="${f.key}"></div>`;
}

function paintProdStyles(){
  const tab=PS.tabs.find(t=>t.key===PSTAB)||PS.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product styles</h2>
      <p class="mdesc" style="margin:0">How product cards look everywhere — grids, rails, search results and shortcodes.</p></div>
    <div class="ectabs">${PS.tabs.map(t=>`<button class="ectab${t.key===PSTAB?' on':''}" data-pstab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}
      <button class="ectab${PSTAB==='shortcode'?' on':''}" data-pstab="shortcode">Shortcode builder</button></div>
    ${PSTAB==='shortcode' ? psBuilder() : `
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(psField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in"><span class="skinprev" id="psPrev"></span></div>
        <p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="psDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="psReset">Reset to defaults</button>
      <button class="btn primary" id="psSave">Save changes</button>
    </div>`}
  </div>`;
  if(PSTAB!=='shortcode') psPreview();
  else psShortcode();
}

function psPreview(){
  const el=$('#psPrev'); if(!el) return;
  el.className='skinprev ' + ['show_brand|pc-nobrand','show_category|pc-nocat','show_rating|pc-norate',
    'show_was_price|pc-nowas','show_discount|pc-nodisc','show_cart|pc-nocart']
    .filter(p=>!psGet(p.split('|')[0])).map(p=>p.split('|')[1]).join(' ');
  el.setAttribute('style',`--kbb-sale:${psGet('sale_colour')};--kbb-new:${psGet('new_colour')};
    --kbb-price:${psGet('price_colour')};--kbb-star:${psGet('star_colour')};
    --kbb-cart-bg:${psGet('cart_bg')};--kbb-cart-fg:${psGet('cart_fg')};
    --kbb-radius:${psGet('card_radius')}px;--kbb-name-lines:${psGet('name_lines')}`);
  el.innerHTML=skinCard(psGet('grid_skin'), psGet('cart_label'));
}

/* ---- shortcode builder ---- */
const SC={source:'new',limit:8,columns:4,skin:'',category:'',brand:'',ids:'',title:'',link:'',orderby:''};
function psBuilder(){
  const opt=(v,l,cur)=>`<option value="${escAttr(v)}"${v===cur?' selected':''}>${escHtml(l)}</option>`;
  return `<div class="mmgrid"><div class="mmcols"><div class="card mmcard">
    <div class="mmhd"><b>Shortcode builder</b><span>Choose what to show, then paste the shortcode into any page or post.</span></div>
    <div class="mmbody">
      <div class="mmrow"><div class="mmlbl"><b>Products</b><span>Where the products come from.</span></div>
        <select data-sc="source">
          ${opt('new','Newest',SC.source)}${opt('bestsellers','Best sellers',SC.source)}${opt('sale','On sale',SC.source)}
          ${opt('featured','Featured',SC.source)}${opt('top_rated','Top rated',SC.source)}${opt('in_stock','In stock',SC.source)}
          ${opt('category','From a category',SC.source)}${opt('brand','From a brand',SC.source)}${opt('ids','Chosen by hand',SC.source)}
        </select></div>
      ${SC.source==='category'?`<div class="mmrow"><div class="mmlbl"><b>Category</b></div>
        <select data-sc="category">${PS.categories.map(c=>opt(c.slug,c.name,SC.category)).join('')}</select></div>`:''}
      ${SC.source==='brand'?`<div class="mmrow"><div class="mmlbl"><b>Brand</b></div>
        <select data-sc="brand">${PS.brands.map(b=>opt(b.slug,b.name,SC.brand)).join('')}</select></div>`:''}
      ${SC.source==='ids'?`<div class="mmrow"><div class="mmlbl"><b>Product ids</b><span>Comma separated. They keep this order.</span></div>
        <input type="text" data-sc="ids" value="${escAttr(SC.ids)}" placeholder="12, 47, 103"></div>`:''}
      <div class="mmrow"><div class="mmlbl"><b>How many</b></div>
        <span class="mmrange"><input type="range" min="2" max="24" value="${SC.limit}" data-sc="limit"><i id="scv-limit">${SC.limit}</i></span></div>
      <div class="mmrow"><div class="mmlbl"><b>Columns</b></div>
        <span class="mmrange"><input type="range" min="2" max="6" value="${SC.columns}" data-sc="columns"><i id="scv-columns">${SC.columns}</i></span></div>
      <div class="mmrow"><div class="mmlbl"><b>Card style</b><span>Leave as default to follow the setting above.</span></div>
        <select data-sc="skin">${opt('','Use the default',SC.skin)}${PS.skins.map(k=>opt(k.key,k.label,SC.skin)).join('')}</select></div>
      <div class="mmrow"><div class="mmlbl"><b>Heading</b><span>Optional, shown above the grid.</span></div>
        <input type="text" data-sc="title" value="${escAttr(SC.title)}" placeholder="Best sellers"></div>
      <div class="mmrow"><div class="mmlbl"><b>View all link</b><span>Optional.</span></div>
        <input type="text" data-sc="link" value="${escAttr(SC.link)}" placeholder="/shop/"></div>
    </div></div>

    <div class="card mmcard"><div class="mmhd"><b>Your shortcode</b><span>Paste this anywhere that accepts content.</span></div>
      <div class="mmbody"><code class="scout" id="scOut"></code>
        <button class="btn small" id="scCopy" style="margin-top:10px">Copy</button>
        <span id="scCopied" class="scok"></span></div></div>
  </div>
  <div class="mmpv"><div class="mmpv-in"><span class="skinprev" id="scPrev"></span></div>
    <p class="mmpv-note">Card style used</p></div></div>`;
}
function psShortcode(){
  const a=[];
  if(SC.source==='category') a.push(`category="${SC.category||PS.categories[0]?.slug||''}"`);
  else if(SC.source==='brand') a.push(`brand="${SC.brand||PS.brands[0]?.slug||''}"`);
  else if(SC.source==='ids') a.push(`ids="${SC.ids}"`);
  else a.push(`source="${SC.source}"`);
  a.push(`limit="${SC.limit}"`,`columns="${SC.columns}"`);
  if(SC.skin) a.push(`skin="${SC.skin}"`);
  if(SC.title) a.push(`title="${SC.title}"`);
  if(SC.link) a.push(`link="${SC.link}"`);
  const out=`[kbb_products ${a.join(' ')}]`;
  const el=$('#scOut'); if(el) el.textContent=out;
  const pv=$('#scPrev'); if(pv) pv.innerHTML=skinCard(SC.skin||psGet('grid_skin'));
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ps]');
  if(el&&PS){ let v=el.value; if(el.type==='range'){ v=+v;
      const o=$('#psv-'+el.dataset.ps); if(o){ let u=''; for(const t of PS.tabs){const f=t.fields.find(x=>x.key===el.dataset.ps); if(f&&f.options) u=f.options.unit||'';} o.textContent=v+u; } }
    if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
    psSet(el.dataset.ps,v); psPreview(); const d=$('#psDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';}
    return; }
  const sc=e.target.closest('[data-sc]');
  if(sc){ SC[sc.dataset.sc]= sc.type==='range' ? +sc.value : sc.value;
    if(sc.type==='range'){ const o=$('#scv-'+sc.dataset.sc); if(o) o.textContent=sc.value; }
    psShortcode(); }
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ps]');
  if(el&&PS){ psSet(el.dataset.ps, el.value); psPreview(); const d=$('#psDirty'); if(d){d.style.visibility='visible';d.textContent='Unsaved changes';} return; }
  const sc=e.target.closest('select[data-sc]');
  if(sc){ SC[sc.dataset.sc]=sc.value; if(sc.dataset.sc==='source') paintProdStyles(); else psShortcode(); }
});
document.addEventListener('click', async e=>{
  if(!PS) return;
  const tb=e.target.closest('[data-pstab]');
  if(tb){ PSTAB=tb.dataset.pstab; paintProdStyles(); return; }
  const sk=e.target.closest('[data-psskin]');
  if(sk){ psSet('grid_skin', sk.dataset.psskin); paintProdStyles();
    const d=$('#psDirty'); if(d){d.style.visibility='visible';d.textContent='Unsaved changes';} return; }
  if(e.target.id==='scCopy'){
    const t=$('#scOut').textContent;
    try{ await navigator.clipboard.writeText(t); }catch(err){
      const ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); ta.remove(); }
    const ok=$('#scCopied'); if(ok){ ok.textContent='Copied'; setTimeout(()=>ok.textContent='',1800); }
    return; }
  if(e.target.id==='psReset'){ PS.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); paintProdStyles(); return; }
  if(e.target.id!=='psSave') return;
  const payload={}; PS.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#psDirty');
  try{
    const r=await fetch(psBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});


/* ---- trending words ---- */
function hdWords(){ return String(hdGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean); }
function hdSetWords(list){
  const seen=new Set(), out=[];
  list.forEach(w=>{ w=String(w).trim().replace(/\s+/g,' ');
    if(w && w.length<=40 && !seen.has(w.toLowerCase())){ seen.add(w.toLowerCase()); out.push(w); } });
  hdSet('trending_words', out.slice(0,20).join(', '));
  paintHeader(); hdDirty();
}
document.addEventListener('click', e=>{
  if(!HD) return;
  const del=e.target.closest('[data-tagdel]');
  if(del){ const w=hdWords(); w.splice(+del.dataset.tagdel,1); hdSetWords(w); return; }
  const add=e.target.closest('[data-tagadd]');
  if(add){ hdSetWords(hdWords().concat(add.dataset.tagadd)); return; }
  if(e.target.id==='tagAdd'){
    const inp=$('#tagInput'); if(inp && inp.value.trim()){ hdSetWords(hdWords().concat(inp.value.split(','))); }
    return; }
});
document.addEventListener('keydown', e=>{
  if(e.target.id!=='tagInput') return;
  if(e.key==='Enter'){ e.preventDefault(); if(e.target.value.trim()) hdSetWords(hdWords().concat(e.target.value.split(','))); }
  // Backspace on an empty field removes the last word, as tag fields usually do.
  if(e.key==='Backspace' && e.target.value===''){ const w=hdWords(); w.pop(); hdSetWords(w); }
});



/* ---------- Growth & Marketing · Newsletter ----------
   Wording, messages and colours for the homepage signup, plus the list itself.
   Whether the block shows at all stays in Appearance → Homepage; two switches
   for one thing is how a setting ends up doing nothing. */
let NL=null, NLTAB='content';

function nlBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/newsletter'; }

async function renderNewsletter(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Newsletter</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(nlBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    NL=await r.json();
  }catch(e){
    /* Name the failure. The first version said only "could not load", which is
       the same sentence for a 404 from a stale route cache and a 500 from a
       query — two problems with nothing in common except the message. */
    const why = String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered. The cache-clearing migration for this release may not have run — check Platform → Core Updates.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log for the last entry.'
      : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the newsletter settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderNewsletter()">Retry</button></div></div>`;
    return;
  }
  paintNewsletter();
}
function nlGet(k){ for(const t of NL.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function nlSet(k,v){ for(const t of NL.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function nlField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-nl="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmcol"><input type="color" value="${v}" data-nl="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-nl="${f.key}"></div>`;
}

function nlPreview(){
  const from=nlGet('nl_bg_from'), to=nlGet('nl_bg_to');
  return `<div class="nlprev" style="background:linear-gradient(140deg,${escAttr(from)},${escAttr(to)})">
    <div class="nlprev-k" style="color:${escAttr(nlGet('nl_note_colour'))}">${escHtml(nlGet('nl_eyebrow'))}</div>
    <div class="nlprev-h">${escHtml(nlGet('nl_heading'))}</div>
    <div class="nlprev-s">${escHtml(nlGet('nl_subheading'))}</div>
    <div class="nlprev-f"><span>${escHtml(nlGet('nl_placeholder'))}</span>
      <b style="background:${escAttr(nlGet('nl_btn_bg'))};color:${escAttr(nlGet('nl_btn_fg'))}">${escHtml(nlGet('nl_button'))}</b></div>
    <div class="nlprev-n" style="color:${escAttr(nlGet('nl_note_colour'))}">${escHtml(nlGet('nl_success'))}</div>
  </div>`;
}

function paintNewsletter(){
  const tab=NL.tabs.find(t=>t.key===NLTAB)||NL.tabs[0];
  const st=NL.stats||{total:0,week:0,latest:null};
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Newsletter</h2>
      <p class="mdesc" style="margin:0">Wording and colours for the signup panel, and the list it collects.
      Whether the panel appears is set in <b>Appearance → Homepage</b>.</p></div>
    ${st.ready===false ? `<div class="nlwarn">The subscriber list table has not been created yet, so counts and the CSV are unavailable.
      Everything on this screen still saves. Apply the latest update, or re-run migrations, and this will fill in.</div>` : `
    <div class="nlstats">
      <div class="nlstat"><b>${st.total}</b><span>on the list</span></div>
      <div class="nlstat"><b>${st.week}</b><span>this week</span></div>
      <div class="nlstat wide"><b>${st.latest?escHtml(st.latest):'—'}</b><span>most recent</span></div>
      <a class="btn small" href="${nlBase()}/export">Download CSV</a>
    </div>`}
    <div class="ectabs">${NL.tabs.map(t=>`<button class="ectab${t.key===NLTAB?' on':''}" data-nltab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(nlField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${nlPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="nlDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="nlSave">Save changes</button>
    </div>
  </div>`;
  bindNewsletter();
}

function nlDirty(){ const d=$('#nlDirty'); if(d) d.style.visibility='visible'; }

function bindNewsletter(){
  $$('[data-nltab]').forEach(b=>b.onclick=()=>{ NLTAB=b.dataset.nltab; paintNewsletter(); });

  $$('[data-nl]').forEach(el=>{
    const k=el.dataset.nl;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); nlSet(k,v); nlDirty(); paintNewsletter(); };
      return;
    }
    el.oninput=()=>{ nlSet(k,el.value); nlDirty();
      /* Colour and text both feed the preview, so repaint rather than patching
         individual nodes — the panel is small and this cannot drift. */
      const active=document.activeElement===el?k:null;
      paintNewsletter();
      if(active){ const again=document.querySelector(`[data-nl="${active}"]`); if(again){ again.focus(); if(again.setSelectionRange && again.type==='text') again.setSelectionRange(again.value.length,again.value.length); } }
    };
  });

  const save=$('#nlSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of NL.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(nlBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.stats) NL.stats=d.stats;
      $('#nlDirty').style.visibility='hidden';
      toast('Newsletter settings saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}





/* ---------- Store · Modules ----------
   The 29 modules from KBB Modules v2.39.0, in the plugin's own eight groups with
   its names, descriptions and defaults carried across. Each is an on/off switch
   plus the devices it is allowed on — the per-device part is this app's addition.
   Where a module's settings already live on another screen, the row says so
   rather than duplicating the controls. */
let MD=null, MDQ='';

function mdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/modules'; }

async function renderModules(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Modules</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MD=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the modules.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderModules()">Retry</button></div></div>`;
    return;
  }
  paintModules();
}
function mdFind(k){ for(const g of MD.groups){ const m=g.modules.find(x=>x.key===k); if(m) return m; } return null; }
function mdDirty(){ const d=$('#mdDirty'); if(d) d.style.visibility='visible'; }

let mdAutosaveTimer;
/**
 * The on/off switch saves itself the moment it's clicked, rather than
 * waiting on the page's own "Save changes" button. That button still
 * exists for the desktop/mobile scope choice below each module, but a
 * toggle that visually flips to "on" without actually being saved yet —
 * easy to miss, easy to walk away from — is exactly the kind of thing
 * that looks enabled right up until the next page load undoes it.
 * A short debounce lets a few quick toggles in a row become one request
 * instead of a flood of them, without changing what actually gets saved.
 */
function mdAutosave(key, on){
  clearTimeout(mdAutosaveTimer);
  mdAutosaveTimer = setTimeout(async () => {
    const modules={};
    MD.groups.forEach(g=>g.modules.forEach(m=>{ modules[m.key]={on:m.on,device:m.device}; }));
    try{
      const r=await fetch(mdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({modules})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.counts) MD.counts=d.counts;
      toast((on ? 'Turned on: ' : 'Turned off: ') + (mdFind(key)?.name || key));
    }catch(e){
      // Roll the switch back rather than leave it showing "on" when it
      // isn't really saved — the exact gap this exists to close.
      const m=mdFind(key); if(m) m.on=!on;
      paintModules();
      toast('Could not save that: ' + e.message);
    }
  }, 350);
}


/* The hover card.
   Each module names a surface and a strip of it. The wireframes below are the
   shapes of the real pages — header, homepage, product, cart panel, cart page,
   checkout, grid — with the named strip lit in pink. Modules that render nothing
   visible say so instead of lighting an arbitrary band, which would be a lie. */
const MD_SURFACE = {
  header:   ['Header',        ['top','nav','mid']],
  home:     ['Homepage',      ['top','mid','bottom']],
  product:  ['Product page',  ['top','mid','bottom']],
  grid:     ['Product cards', ['card','all']],
  drawer:   ['Cart panel',    ['top','mid','bottom']],
  cartpage: ['Cart page',     ['top','mid','bottom']],
  checkout: ['Checkout',      ['top','mid','bottom','aside']],
  site:     ['Every page',    ['all']],
};

function mdWire(surface, band){
  const lit = (b) => band === b || band === 'all' ? ' lit' : '';
  if (surface === 'grid') {
    return `<div class="mdw mdw-grid">
      ${[0,1,2,3].map(()=>`<span class="mdw-card${band==='card'||band==='all'?' lit':''}"><i></i><u></u></span>`).join('')}
    </div>`;
  }
  if (surface === 'site') {
    return `<div class="mdw mdw-page"><span class="mdw-hd"></span><span class="mdw-b"></span>
      <span class="mdw-b"></span><span class="mdw-b short"></span><span class="mdw-ft"></span>
      <b class="mdw-none">nothing visible</b></div>`;
  }
  if (surface === 'checkout') {
    return `<div class="mdw mdw-co">
      <span class="mdw-hd${lit('top')}"></span>
      <div class="mdw-cols">
        <div class="mdw-main"><span class="mdw-b${lit('mid')}"></span><span class="mdw-b${lit('mid')}"></span>
          <span class="mdw-b short${lit('mid')}"></span><span class="mdw-cta${lit('bottom')}"></span></div>
        <div class="mdw-aside${lit('aside')}"><i></i><i></i><i></i></div>
      </div></div>`;
  }
  if (surface === 'drawer') {
    return `<div class="mdw mdw-dr">
      <span class="mdw-tabs${lit('top')}"></span>
      <span class="mdw-b${lit('top')}"></span>
      <div class="mdw-list${lit('mid')}"><i></i><i></i><i></i></div>
      <span class="mdw-b short${lit('bottom')}"></span>
      <span class="mdw-cta${lit('bottom')}"></span></div>`;
  }
  if (surface === 'header') {
    return `<div class="mdw mdw-page">
      <span class="mdw-strip${lit('top')}"></span>
      <span class="mdw-hd${lit('mid')}"></span>
      <span class="mdw-nav${lit('nav')}"></span>
      <span class="mdw-b"></span><span class="mdw-b short"></span></div>`;
  }
  // homepage, product page and cart page share the same three-band shape
  return `<div class="mdw mdw-page">
    <span class="mdw-hd"></span>
    <span class="mdw-hero${lit('top')}"></span>
    <div class="mdw-mid${lit('mid')}"><i></i><i></i></div>
    <span class="mdw-b${lit('bottom')}"></span>
    <span class="mdw-ft${lit('bottom')}"></span></div>`;
}

function mdCard(m){
  const s = MD_SURFACE[m.surface] || ['', []];
  return `<span class="mdpop">
    <b class="mdpop-h">${escHtml(m.name)}</b>
    <span class="mdpop-s">${escHtml(s[0])}</span>
    ${mdWire(m.surface, m.band)}
    <span class="mdpop-t">${escHtml(m.where)}</span>
    ${m.route?`<span class="mdpop-l">Opens ${escHtml(m.screen)}</span>`
             :m.screen?`<span class="mdpop-l none">${escHtml(m.screen)} — screen not built yet</span>`:''}
  </span>`;
}

/* The settings line on each row.
   Three states, because a link that goes nowhere is worse than no link:
     - a console route exists  -> a real link straight to that screen
     - a screen is named but not built -> the path, greyed, saying so
     - no settings at all      -> says that
   The plugin puts sixteen modules' settings on their own page; those pages are
   the next block of Phase 3, and until they exist their rows say so. */
function mdSettings(m){
  if (m.route) {
    return `<a class="mdlink go" href="#${escAttr(m.route)}" onclick="go('${escAttr(m.route)}');return false;">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
      ${escHtml(m.screen)}</a>`;
  }
  if (m.screen) return `<em class="mdlink soon">${escHtml(m.screen)} — not built yet</em>`;
  return '<em class="mdlink none">No settings</em>';
}

function mdRow(m){
  const q=MDQ.toLowerCase();
  if(q && !(m.name+' '+m.desc+' '+m.key).toLowerCase().includes(q)) return '';
  const devs=Object.keys(MD.devices).map(d=>`<option value="${escAttr(d)}"${d===m.device?' selected':''}>${escHtml(MD.devices[d])}</option>`).join('');
  /* A toggle is only offered where something actually reads it. The other two
     states show the switch's position but will not let it be moved — a control
     that appears to work and does nothing is worse than one that says why. */
  const live = m.status === 'live';
  const note = m.status === 'elsewhere'
      ? `<i class="mdstat where">Switched in ${escHtml(m.screen)}</i>`
      : m.status === 'todo' ? '<i class="mdstat todo">Not ported yet</i>' : '';

  return `<div class="mdrow${m.on?' on':''}${live?'':' inert'}">
    <span class="ectog${m.on?' on':''}${live?'':' off'}"${live?` data-md="${escAttr(m.key)}" role="switch" aria-checked="${m.on}" tabindex="0"`:' aria-disabled="true"'}></span>
    <div class="mdlbl">
      <b>${escHtml(m.name)}</b>${m.default?'':'<i class="mdoff">off by default</i>'}${note}
      <span>${escHtml(m.desc)}</span>
      ${mdSettings(m)}
    </div>
    <span class="mdeye" tabindex="0" aria-label="Where this shows">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="2.6"/></svg>
      ${mdCard(m)}
    </span>
    <select class="mddev" data-mddev="${escAttr(m.key)}"${m.on?'':' disabled'}>${devs}</select>
  </div>`;
}

function paintModules(){
  const c=MD.counts;
  /* Two columns on a wide screen, one on a narrow one.
     The groups are dealt into the two columns here rather than left to CSS: the
     eight of them are very uneven — Checkout has eleven modules, three groups
     have one — so a plain two-column grid would leave one side half empty, and
     CSS multi-column would put an absolutely positioned hover card inside a
     fragmentation context, which is where popovers land in the wrong place.
     Dealt largest-first into whichever column is shorter, recounted on every
     repaint so filtering rebalances. */
  const cards=MD.groups.map(g=>{
    const rows=g.modules.map(mdRow).join('');
    if(!rows.trim()) return null;
    const on=g.modules.filter(m=>m.on).length;
    const shown=(rows.match(/class="mdrow/g)||[]).length;
    return {shown, html:`<div class="card mdcard">
      <div class="mmhd"><b>${escHtml(g.label)}</b>
        <span class="mdghd"><em>${on} of ${g.modules.length} on</em>
          <span class="mdgbtns">
            <button class="btn" data-mdgall="${escAttr(g.key)}">All on</button>
            <button class="btn" data-mdgnone="${escAttr(g.key)}">All off</button>
            <button class="btn" data-mdgdef="${escAttr(g.key)}">Defaults</button>
          </span></span></div>
      <div class="mdbody">${rows}</div></div>`};
  }).filter(Boolean);

  const col=[[],[]], height=[0,0];
  [...cards].sort((a,b)=>b.shown-a.shown).forEach(c=>{
    const i = height[0] <= height[1] ? 0 : 1;
    col[i].push(c); height[i] += c.shown + 2;   // +2 for the group heading
  });
  const body = cards.length
    ? `<div class="mdcols"><div class="mdcol">${col[0].map(c=>c.html).join('')}</div>
       <div class="mdcol">${col[1].map(c=>c.html).join('')}</div></div>`
    : '';

  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Modules</h2>
      <p class="mdesc" style="margin:0">Every feature is an independent switch — the storefront renders with all of them off.
      <b>${c.on} of ${c.total} on.</b></p></div>
    <div class="mdtools"><input type="search" id="mdSearch" placeholder="Filter modules…" value="${escAttr(MDQ)}">
      <button class="btn small" data-mdall="1">Turn all on</button>
      <button class="btn small" data-mdnone="1">Turn all off</button>
      <button class="btn small" data-mddef="1">Back to defaults</button></div>
    ${body || '<div class="card" style="padding:20px;font-size:12.5px;color:#7b8697">Nothing matches that filter.</div>'}
    <div class="ecsave">
      <span class="ecdirty" id="mdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mdSave">Save changes</button>
    </div>
  </div>`;
  bindModules();
}

function bindModules(){
  const search=$('#mdSearch');
  if(search) search.oninput=()=>{ MDQ=search.value; paintModules();
    const again=$('#mdSearch'); if(again){ again.focus(); again.setSelectionRange(again.value.length,again.value.length); } };

  $$('[data-md]').forEach(el=>el.onclick=()=>{
    const m=mdFind(el.dataset.md); if(!m) return;
    m.on=!m.on; paintModules();
    mdAutosave(m.key, m.on);
  });
  $$('[data-mddev]').forEach(el=>el.onchange=()=>{
    const m=mdFind(el.dataset.mddev); if(!m) return;
    m.device=el.value; mdDirty();
  });

  /* Bulk actions.
     Page-level ones ask first: they rewrite all thirty-one rows at once and
     there is no undo short of reloading the screen and losing anything else
     changed. Group-level ones act straight away — a handful of rows, and still
     nothing written until Save changes. */
  const apply=(fn,scope)=>{
    // Only the live ones: turning all on should not pretend to switch on
    // sixteen features that are not built.
    MD.groups.forEach(g=>{ if(!scope||g.key===scope) g.modules.filter(m=>m.status==='live').forEach(fn); });
    mdDirty(); paintModules();
  };

  const askThen=(title,body,label,fn)=>{
    openModal(`<div class="modal-h"><b>${escHtml(title)}</b>
        <button class="x" onclick="closeModal()">✕</button></div>
      <div class="modal-b"><p style="font-size:12.5px;color:var(--ink-soft);margin:0 0 4px">${escHtml(body)}</p>
        <p style="font-size:11.5px;color:var(--ink-soft);margin:0">Nothing is written until you press <b>Save changes</b>.</p>
        <div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">
          <button class="btn ghost" onclick="closeModal()">Cancel</button>
          <button class="btn" id="mdConfirm">${escHtml(label)}</button></div></div>`);
    $('#mdConfirm').onclick=()=>{ closeModal(); fn(); };
  };

  const count=MD.groups.reduce((n,g)=>n+g.modules.filter(m=>m.status==='live').length,0);
  const all=$('[data-mdall]');
  if(all) all.onclick=()=>askThen('Turn every module on',
    `The ${count} modules that are wired up will be switched on. The rest are left alone — they are either switched on another screen or not ported yet.`,
    'Turn all on', ()=>apply(m=>{m.on=true;}));
  const none=$('[data-mdnone]');
  if(none) none.onclick=()=>askThen('Turn every module off',
    `The ${count} modules that are wired up will be switched off. The storefront still renders — that is what a module is — but the free-shipping bar, promo lines and reassurance blocks will all go.`,
    'Turn all off', ()=>apply(m=>{m.on=false;}));
  const def=$('[data-mddef]');
  if(def) def.onclick=()=>askThen('Back to defaults',
    'Every module returns to the state the plugin ships it in, and every device choice returns to Everywhere.',
    'Restore defaults', ()=>apply(m=>{m.on=m.default;m.device='both';}));

  $$('[data-mdgall]').forEach(b=>b.onclick=()=>apply(m=>{m.on=true;}, b.dataset.mdgall));
  $$('[data-mdgnone]').forEach(b=>b.onclick=()=>apply(m=>{m.on=false;}, b.dataset.mdgnone));
  $$('[data-mdgdef]').forEach(b=>b.onclick=()=>apply(m=>{m.on=m.default;m.device='both';}, b.dataset.mdgdef));

  const save=$('#mdSave');
  if(save) save.onclick=async()=>{
    const modules={};
    MD.groups.forEach(g=>g.modules.forEach(m=>{ modules[m.key]={on:m.on,device:m.device}; }));
    save.disabled=true;
    try{
      const r=await fetch(mdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({modules})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      if(d.counts) MD.counts=d.counts;
      $('#mdDirty').style.visibility='hidden';
      toast('Modules saved');
      paintModules();
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Mobile Header ----------
   Spacing and the divider above the search field, phones only. The desktop
   header stays under Appearance → Header. */
let MH=null, MHTAB='spacing';

function mhBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mobile-header'; }

async function renderMobileHdr(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Mobile Header</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mhBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MH=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the mobile header settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderMobileHdr()">Retry</button></div></div>`;
    return;
  }
  paintMobileHdr();
}
function mhGet(k){ for(const t of MH.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function mhSet(k,v){ for(const t of MH.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function mhField(f){
  const v=f.value;
  /* Left and Right are meaningless while Match the page is on, so they dim
     rather than disappearing — the value stays visible. */
  const dim=(f.key==='pad_left'||f.key==='pad_right') && mhGet('match_page');
  const only=(f.key==='dv_length'&&mhGet('divider')!=='ticks')
          || (f.key==='dv_inset'&&['full','soft'].includes(mhGet('divider')))
          || (['dv_colour','dv_alpha','dv_width','dv_inset','dv_length'].includes(f.key)&&mhGet('divider')==='off');
  const cls=(dim||only)?' class="dim"':'';
  if(f.type==='bool')
    return `<div class="mmrow"${cls}><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-mh="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='select'){ const o=f.options||{};
    return `<div class="mmrow"${cls}><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-mh="${f.key}">${Object.keys(o).map(k=>`<option value="${escAttr(k)}"${k===v?' selected':''}>${escHtml(o[k])}</option>`).join('')}</select></div>`; }
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"${cls}><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-mh="${f.key}">
        <i id="mhv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"${cls}><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-mh="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"${cls}><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-mh="${f.key}"></div>`;
}

function mhRgba(){
  const hex=String(mhGet('dv_colour')||'#2A2228').replace('#','');
  const n=hex.length===3?hex.split('').map(c=>c+c).join(''):hex;
  const r=parseInt(n.slice(0,2),16),g=parseInt(n.slice(2,4),16),b=parseInt(n.slice(4,6),16);
  return `rgba(${r},${g},${b},${(mhGet('dv_alpha')/100).toFixed(2)})`;
}

/* A phone at the chosen numbers, using the header's own class names so the
   screen and the storefront cannot drift. */
function mhPreview(){
  const l=mhGet('match_page')?12:mhGet('pad_left');
  const r=mhGet('match_page')?12:mhGet('pad_right');
  const vars=`--mh-l:${l}px;--mh-r:${r}px;--mh-t:${mhGet('pad_top')}px;--mh-b:${mhGet('pad_bottom')}px;
    --mh-gap:${mhGet('row_gap')}px;--mh-sgap:${mhGet('search_gap')}px;--mh-igap:${mhGet('item_gap')}px;
    --mh-dv:${mhRgba()};--mh-dvw:${mhGet('dv_width')}px;--mh-dvin:${mhGet('dv_inset')}px;--mh-dvlen:${mhGet('dv_length')}px;
    --mh-srad:${mhGet('search_full')?0:mhGet('search_radius')+'px'};--mh-spad:${mhGet('search_pad')}px;--mh-sbg:${escAttr(mhGet('search_bg'))};
    --mh-sicon:${escAttr(mhGet('search_icon'))};--mh-stext:${escAttr(mhGet('search_text'))};--mh-sph:${escAttr(mhGet('search_ph'))}`;
  const dv=mhGet('divider');
  const sf=(mhGet('search_full')?' mhp-sfull':'')+(mhGet('search_border')?'':' mhp-snb')
    +((mhGet('search_full')&&mhGet('search_align')==='field')?' mhp-skeep':'');
  return `<div class="mhp">
    <div class="mhp-hdr ${dv==='off'?'':'mhp-'+dv}${sf}" style="${vars}">
      <div class="mhp-wrap">
        <div class="mhp-in">
          <span class="mhp-bg"></span>
          <span class="mhp-logo">K-Beauty<b>Bliss</b></span>
          <span class="mhp-act"><i></i><i></i></span>
          <span class="mhp-sbox"><i class="mhp-mg"></i><em>Search 671 products…</em></span>
        </div>
      </div>
    </div>
    <div class="mhp-page"><div class="mhp-sec"><b>Best sellers</b><div class="mhp-grid"><u></u><u></u></div></div></div>
  </div>`;
}

function paintMobileHdr(){
  const tab=MH.tabs.find(t=>t.key===MHTAB)||MH.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Mobile Header</h2>
      <p class="mdesc" style="margin:0">Spacing and the divider above the search field, on phones only. The desktop header is set under <b>Appearance → Header</b>.</p></div>
    <div class="ectabs">${MH.tabs.map(t=>`<button class="ectab${t.key===MHTAB?' on':''}" data-mhtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(mhField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${mhPreview()}</div><p class="mmpv-note">Live preview · phone width</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mhDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mhSave">Save changes</button>
    </div>
  </div>`;
  bindMobileHdr();
}

function bindMobileHdr(){
  const dirty=()=>{ const d=$('#mhDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-mhtab]').forEach(b=>b.onclick=()=>{ MHTAB=b.dataset.mhtab; paintMobileHdr(); });

  $$('[data-mh]').forEach(el=>{
    const k=el.dataset.mh;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); mhSet(k,v); dirty(); paintMobileHdr(); };
      return;
    }
    if(el.tagName==='SELECT'){ el.onchange=()=>{ mhSet(k,el.value); dirty(); paintMobileHdr(); }; return; }
    if(el.type==='range'){
      el.oninput=()=>{ mhSet(k,Number(el.value)); dirty();
        const b=$('#mhv-'+k); if(b){ const f=MH.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=mhPreview(); };
      return;
    }
    el.oninput=()=>{ mhSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=mhPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });

  const save=$('#mhSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of MH.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(mhBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#mhDirty').style.visibility='hidden';
      toast('Mobile header saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Section dividers ----------
   Same generic renderer as the other settings screens, with one extra field
   type: a tick list of the homepage sections, used when Where is set to the
   chosen ones. */
let DV=null, DVTAB='style';
const DV_NAMES={ticks:'Corner ticks',hairline:'Fading hairline',petal:'Petal on a hairline',
  stitch:'Stitched dashes',drift:'Drifting petals',breathe:'Breathing hairline',gradient:'Drifting gradient edge'};

function dvBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/dividers'; }

async function renderDividers(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Section dividers</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(dvBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    DV=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the divider settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderDividers()">Retry</button></div></div>`;
    return;
  }
  paintDividers();
}
function dvGet(k){ for(const t of DV.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function dvSet(k,v){ for(const t of DV.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }
function dvPicked(){ return String(dvGet('sections')||'').split(',').filter(Boolean); }

function dvField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-dv="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='select'){
    const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-dv="${f.key}">${Object.keys(o).map(k=>`<option value="${escAttr(k)}"${k===v?' selected':''}>${escHtml(o[k])}</option>`).join('')}</select></div>`;
  }
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-dv="${f.key}">
        <i id="dvv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-dv="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='sections'){
    const on=dvPicked(), chosen=dvGet('scope')==='chosen';
    return `<div class="dvsec${chosen?'':' off'}">
      <div class="dvsec-hd"><b>${escHtml(f.label)}</b><span>${escHtml(f.help||'')}</span>
        <span class="dvsec-act"><button class="btn small" data-dvall="1">All</button><button class="btn small" data-dvnone="1">None</button></span></div>
      <div class="dvsec-grid">${DV.sections.map(sc=>`
        <label class="dvchk${on.includes(sc.key)?' on':''}"><input type="checkbox" data-dvsec="${escAttr(sc.key)}"${on.includes(sc.key)?' checked':''}>
          <span>${escHtml(sc.label)}</span></label>`).join('')}</div>
      ${chosen?'':'<p class="dvsec-note">Set <b>Where</b> to “Only above the sections ticked below” to use this list.</p>'}
    </div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-dv="${f.key}"></div>`;
}

/* Three stacked sections at the chosen style, drawn with the same measurements
   as the storefront so the screen cannot drift from the page. */
function dvPreview(){
  const style=dvGet('style'), random=style.indexOf('random')===0;
  const shown=random?DV.resolved:style;
  const vars=`--dv-col:${escAttr(dvGet('colour'))};--dv-len:${dvGet('length')}px;--dv-w:${dvGet('thickness')}px;--dv-in:${dvGet('inset')}px`;
  const band=(t)=>`<div class="dvp-sec"><div class="dvp-h">${escHtml(t)}</div>
      <div class="dvp-g"><i></i><i></i></div></div>`;

  return `<div class="dvp dvp-${escAttr(shown)}" style="${vars}">
      ${band('Big savings bundles')}${band('Best sellers')}${band('Flash sale')}
    </div>
    ${random?`<p class="dvp-note">Random is on. This visit landed on <b>${escHtml(DV_NAMES[shown]||shown)}</b>.</p>`:''}`;
}

function paintDividers(){
  const tab=DV.tabs.find(t=>t.key===DVTAB)||DV.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Section dividers</h2>
      <p class="mdesc" style="margin:0">The mark between homepage sections. Phone only unless you turn desktop on — sections still have their card frame up there.</p></div>
    <div class="ectabs">${DV.tabs.map(t=>`<button class="ectab${t.key===DVTAB?' on':''}" data-dvtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(dvField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${dvPreview()}</div><p class="mmpv-note">Live preview · phone width</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="dvDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="dvSave">Save changes</button>
    </div>
  </div>`;
  bindDividers();
}

/* ================= Mega Menu ================= */

let MGM = null;
let MGM_MENUS = [];
let MGM_CURRENT_MENU_ID = null;
let mgmDragId = null;       // id currently being dragged
let mgmDragTarget = null;   // {parentId, beforeId} to insert before, or {into: id} to become a child

function mgmBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mega-menu'; }

async function mgmApi(path, opts){
  opts = opts || {};
  opts.headers = Object.assign({'Accept':'application/json'}, opts.headers||{});
  if(opts.method && opts.method !== 'GET'){
    opts.headers['X-XSRF-TOKEN'] = uToken();
    opts.headers['Content-Type'] = 'application/json';
  }
  opts.credentials = 'same-origin';
  const r = await fetch(mgmBase() + path, opts);
  const data = await r.json().catch(() => ({}));
  return {ok: r.ok, status: r.status, data};
}

async function renderMegaMenu(menuId){
  $('#content').innerHTML = `<div class="wrap"><div class="page-head"><h2>Mega Menu</h2><p>Loading…</p></div></div>`;
  let detail = null;
  try{
    const menusResp = await mgmApi('/menus');
    if(!menusResp.ok) throw new Error(String(menusResp.status));
    MGM_MENUS = menusResp.data.menus || [];

    const wantId = menuId || MGM_CURRENT_MENU_ID || (MGM_MENUS[0] && MGM_MENUS[0].id);
    const qs = wantId ? ('?menu_id=' + wantId) : '';
    const r = await fetch(mgmBase() + qs, {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok){
      // The endpoint now returns the real exception message and file:line
      // on a 500, instead of a bare status code with nothing else to go on.
      const body = await r.json().catch(() => null);
      if(body && body.errors) detail = body;
      throw new Error(String(r.status));
    }
    MGM = await r.json();
    MGM_CURRENT_MENU_ID = MGM.menu_id;

    // The GET above may have just auto-created the very first menu — the
    // list fetched a moment earlier wouldn't know about it yet.
    if(! MGM_MENUS.some(m => m.id === MGM_CURRENT_MENU_ID)){
      const refreshed = await mgmApi('/menus');
      if(refreshed.ok) MGM_MENUS = refreshed.data.menus || [];
    }
  }catch(e){
    const why = String(e.message||e);
    const hint = detail
      ? (detail.errors[0] || 'The server errored.') + (detail.where ? ` (${detail.where})` : '')
      : why==='404'
        ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
        : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML = `<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the menu.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)}</p>
      <button class="btn small" onclick="renderMegaMenu()">Retry</button></div></div>`;
    return;
  }
  paintMegaMenu();
}

const MGM_VIS_LABEL = {always: 'Everyone', guest: 'Signed out only', auth: 'Signed in only'};

let MGM_EXPANDED = new Set(); // ids the user has explicitly opened — everything else starts collapsed

function mgmRow(item, depth){
  const kids = item.children || [];
  const childLabel = depth === 0 ? 'column' : 'link';
  const canAddChild = depth < 2;
  const swatch = item.highlight_color
    ? `<span class="mgm-swatch" style="background:${escAttr(item.highlight_color)}" title="Highlighted"></span>` : '';
  const visTag = item.visibility && item.visibility !== 'always'
    ? `<span class="mgm-vistag">${escHtml(MGM_VIS_LABEL[item.visibility] || item.visibility)}</span>` : '';
  const tabTag = item.new_tab ? `<span class="mgm-tabtag">↗</span>` : '';
  const hasKids = kids.length > 0;
  const open = hasKids && MGM_EXPANDED.has(item.id);

  return `<div class="mgmrow-wrap" data-mgmid="${item.id}" data-mgmdepth="${depth}">
    <div class="mgmdropline" data-mgmdropline="before"></div>
    <div class="mgmitem" data-mgmitem="${item.id}" style="${item.highlight_color ? `border-left-color:${escAttr(item.highlight_color)}` : ''}">
      <span class="mgmhandle" draggable="true" data-mgmdrag="${item.id}" title="Drag to move">⠿</span>
      ${hasKids ? `<button type="button" class="mgmtoggle${open ? ' open' : ''}" data-mgmtoggle="${item.id}" title="${open ? 'Collapse' : 'Expand'}">▸</button>` : '<span class="mgmtoggle-sp"></span>'}
      <div class="mgmlabel">
        <span class="mgmlabel-top">
          <b>${escHtml(item.label)}</b>
          ${swatch}${item.badge ? `<span class="npill" style="background:#15a85a">${escHtml(item.badge)}</span>` : ''}${visTag}${tabTag}
          ${hasKids ? `<span class="mgm-childcount">${kids.length}</span>` : ''}
          ${item.url ? `<span class="mgmurl">${escHtml(item.url)}</span>` : ''}
        </span>
      </div>
      <div class="mgmactions">
        <button class="btn small" data-mgmedit="${item.id}">Edit</button>
        <button class="btn small danger" data-mgmdel="${item.id}" data-mgmlabel="${escAttr(item.label)}">Delete</button>
      </div>
    </div>
    <div class="mgmchildren${open ? '' : ' mgm-collapsed'}" data-mgmchildzone="${item.id}">
      ${kids.map(k => mgmRow(k, depth + 1)).join('')}

      <div class="mgmdropline" data-mgmdropline-end="${item.id}"></div>
      ${canAddChild ? `<button class="btn small mgmadd-child" data-mgmaddchild="${item.id}" data-mgmparentdepth="${depth}">+ Add ${childLabel}</button>` : ''}
    </div>
  </div>`;
}

/* A slim, real rendering of the top bar, using the site's own classes and
   CSS (kbb.css is already loaded in the admin shell for a couple of other
   previews) so this isn't a guess at what it'll look like — it's what it
   already looks like, just scoped to a small preview strip. */
function mgmPreview(){
  const top = (MGM.tree || []).slice(0, 6);
  return `<div class="mgmpv">
    <div class="mgmpv-label">Live preview</div>
    <div class="mgmpv-bar">
      ${top.map(i => `<span class="mgmpv-link" style="${i.highlight_color ? `background:${escAttr(i.highlight_color)};border-radius:7px;padding:3px 9px` : ''}">${escHtml(i.label)}${i.badge ? `<em>${escHtml(i.badge)}</em>` : ''}${(i.children||[]).length ? ' ▾' : ''}</span>`).join('')}
      ${!top.length ? '<span class="mgmpv-empty">Nothing added yet — showing the built-in fallback on the real site</span>' : ''}
    </div>
  </div>`;
}

function mgmSlotTags(m){
  const tags = [];
  if (m.show_desktop) tags.push('Desktop');
  if (m.show_mobile) tags.push('Mobile');
  if (m.show_footer) tags.push('Footer');
  return tags.map(t => `<span class="mgm-loctag">${t}</span>`).join('');
}

function mgmMenuBar(){
  return `<div class="mgm-menubar">
    ${MGM_MENUS.map(m => `<button class="mgm-menutab${m.id === MGM_CURRENT_MENU_ID ? ' on' : ''}" data-mgmswitch="${m.id}">
      ${escHtml(m.name)}
      ${mgmSlotTags(m)}
    </button>`).join('')}
    <button class="mgm-menutab mgm-menutab-new" id="mgmNewMenu">+ New menu</button>
    <button class="mgm-menutab mgm-menutab-new" id="mgmLoadDemo">✨ Load kbeautybliss.com menu</button>
    ${MGM_CURRENT_MENU_ID ? `<button class="mgm-menusettings" id="mgmMenuSettings" title="Rename or assign this menu">⚙ Menu settings</button>` : ''}
  </div>`;
}

function paintMegaMenu(){
  const tree = MGM.tree || [];
  const current = MGM_MENUS.find(m => m.id === MGM_CURRENT_MENU_ID);

  $('#content').innerHTML = `<div class="wrap mgm-wrap">
    <div class="page-head">
      <h2>Mega Menu</h2>
      <p>What shows in the header nav bar, and what drops down or opens as a mega panel underneath each item. Drag the ⠿ handle to reorder or move an item to a different column. Changes take effect immediately.</p>
    </div>

    ${mgmMenuBar()}

    ${current && !current.show_desktop && !current.show_mobile && !current.show_footer ? `<div class="mgm-offbanner">
      <b>"${escHtml(current.name)}" isn't assigned anywhere yet.</b> It exists, but nothing on the site is showing it. Use <b>⚙ Menu settings</b> above to assign it to the desktop header, mobile menu, or footer.
    </div>` : ''}

    ${mgmPreview()}

    <div class="card mgm-card">
      ${tree.length ? `<div class="mgm-treehead"><span>${tree.length} top-level item${tree.length===1?'':'s'}</span><button class="mgm-expandall" id="mgmExpandAll">Expand / collapse all</button></div>` : ''}
      <div class="mgmtree" id="mgmTree">
        ${tree.length ? tree.map(i => mgmRow(i, 0)).join('') : '<p class="mdesc" style="padding:8px 0">Nothing here yet — the header is showing its built-in fallback (Home, New In, Best Sellers, Shop). Add the first item below.</p>'}
        <div class="mgmdropline" data-mgmdropline-end="root"></div>
      </div>
      <button class="btn primary" id="mgmAddTop" style="margin-top:16px">+ Add top-level item</button>
    </div>
  </div>`;

  bindMegaMenu();
}

function bindMegaMenu(){
  $('#mgmAddTop').onclick = () => mgmOpenForm(null, 0);

  $$('[data-mgmswitch]').forEach(b => b.onclick = () => {
    const id = Number(b.dataset.mgmswitch);
    if(id === MGM_CURRENT_MENU_ID) return;
    renderMegaMenu(id);
  });

  $('#mgmNewMenu').onclick = () => mgmNewMenuPrompt();
  $('#mgmLoadDemo').onclick = () => mgmLoadDemoConfirm();

  const settingsBtn = $('#mgmMenuSettings');
  if(settingsBtn) settingsBtn.onclick = () => mgmMenuSettingsForm();

  $$('[data-mgmtoggle]').forEach(b => b.onclick = () => {
    const id = Number(b.dataset.mgmtoggle);
    if(MGM_EXPANDED.has(id)) MGM_EXPANDED.delete(id); else MGM_EXPANDED.add(id);
    paintMegaMenu();
  });

  const expandAllBtn = $('#mgmExpandAll');
  if(expandAllBtn) expandAllBtn.onclick = () => {
    const allWithKids = [];
    (function walk(nodes){ nodes.forEach(n => { if((n.children||[]).length){ allWithKids.push(n.id); walk(n.children); } }); })(MGM.tree || []);
    const allOpen = allWithKids.every(id => MGM_EXPANDED.has(id));
    MGM_EXPANDED = allOpen ? new Set() : new Set(allWithKids);
    paintMegaMenu();
  };

  $$('[data-mgmaddchild]').forEach(b => b.onclick = () =>
    mgmOpenForm(Number(b.dataset.mgmaddchild), Number(b.dataset.mgmparentdepth) + 1));

  $$('[data-mgmedit]').forEach(b => b.onclick = () => {
    const item = mgmFind(Number(b.dataset.mgmedit));
    if(item) mgmOpenForm(item.parent_id ?? null, item._depth, item);
  });

  $$('[data-mgmdel]').forEach(b => b.onclick = async () => {
    const ok = await mgmDeleteConfirm(b.dataset.mgmlabel);
    if(!ok) return;
    const r = await mgmApi('/' + b.dataset.mgmdel + '/delete', {method:'POST'});
    if(!r.ok){ toast('Could not delete that.'); return; }
    toast('Deleted.');
    renderMegaMenu();
  });

  mgmBindDragDrop();
}

/* Flat lookup with parent_id and depth annotated, since the tree from the
   server is nested but edit/reorder need to know both about a single node. */
function mgmFind(id, nodes, depth, parentId){
  nodes = nodes || MGM.tree; depth = depth || 0; parentId = parentId ?? null;
  for(const n of nodes){
    if(n.id === id) return Object.assign({}, n, {_depth: depth, parent_id: parentId});
    if(n.children && n.children.length){
      const hit = mgmFind(id, n.children, depth + 1, n.id);
      if(hit) return hit;
    }
  }
  return null;
}

/* Chain of real node objects (not copies) from MGM.tree down to id, inclusive. */
function mgmPathTo(id, nodes, trail){
  nodes = nodes || MGM.tree; trail = trail || [];
  for(const n of nodes){
    if(n.id === id) return trail.concat([n]);
    if(n.children && n.children.length){
      const hit = mgmPathTo(id, n.children, trail.concat([n]));
      if(hit) return hit;
    }
  }
  return null;
}

function mgmSiblingsAndParent(id){
  const path = mgmPathTo(id);
  if(!path) return null;
  const parent = path.length > 1 ? path[path.length - 2] : null;
  const siblings = parent ? parent.children : MGM.tree;
  return {parent, siblings};
}

/* How many levels exist below this node — 0 for a leaf. */
function mgmSubtreeDepth(node){
  if(!node.children || !node.children.length) return 0;
  return 1 + Math.max(...node.children.map(mgmSubtreeDepth));
}

/* Mirrors the server's own check (MegaMenuApiController::move) so the "drop
   into" zone only appears where the drop would actually be accepted —
   nothing worse than a drop zone that lights up and then bounces. */
function mgmCanNestInto(targetId){
  if(mgmDragId === null || targetId === mgmDragId) return false;
  const targetPath = mgmPathTo(targetId);
  if(!targetPath) return false;
  if(targetPath.some(n => n.id === mgmDragId)) return false;
  const dragged = mgmFind(mgmDragId);
  if(!dragged) return false;
  const targetDepth = targetPath.length;
  const ownDepth = mgmSubtreeDepth(dragged);
  return targetDepth + ownDepth <= 2;
}

/* ---------- Drag and drop ---------- */

function mgmBindDragDrop(){
  $$('[data-mgmdrag]').forEach(handle => {
    handle.addEventListener('dragstart', e => {
      mgmDragId = Number(handle.dataset.mgmdrag);
      e.dataTransfer.effectAllowed = 'move';
      // Firefox requires data to be set for the drag to start at all.
      e.dataTransfer.setData('text/plain', String(mgmDragId));
      handle.closest('[data-mgmitem]').classList.add('mgm-dragging');
    });
    handle.addEventListener('dragend', () => {
      handle.closest('[data-mgmitem]')?.classList.remove('mgm-dragging');
      mgmClearDropIndicators();
      mgmDragId = null; mgmDragTarget = null;
    });
  });

  $$('[data-mgmitem]').forEach(row => {
    row.addEventListener('dragover', e => {
      if(mgmDragId === null) return;
      e.preventDefault();
      // A row's own decision — reorder or nest — must win outright. Without
      // this, the event keeps bubbling past this row into whatever outer
      // item's children zone happens to contain it, and that ancestor's
      // dragover handler fires afterward and silently overwrites this
      // row's own, more specific target with its own. Confirmed directly:
      // dropping "into" a nested row was landing in its grandparent instead.
      e.stopPropagation();
      const id = Number(row.dataset.mgmitem);
      if(id === mgmDragId) return;
      const rect = row.getBoundingClientRect();
      const frac = (e.clientY - rect.top) / rect.height;
      const info = mgmSiblingsAndParent(id);
      if(!info) return;

      // Middle third of the row = "drop into this item," so nesting works
      // by dropping directly on a row instead of needing its (possibly
      // collapsed, possibly not-yet-existing) children area to be open and
      // visible first. Top/bottom thirds keep the existing reorder behavior.
      if(frac >= 0.33 && frac <= 0.67 && mgmCanNestInto(id)){
        mgmSetDropIndicator({into: id});
        return;
      }

      const upperHalf = frac < 0.5;
      if(upperHalf){
        // Insert directly before this row.
        mgmSetDropIndicator({parentId: info.parent ? info.parent.id : null, beforeId: id});
      } else {
        // Insert before whatever comes after this row in the same group —
        // or at the end of the group if this is the last one.
        const idx = info.siblings.findIndex(s => s.id === id);
        const next = info.siblings[idx + 1];
        mgmSetDropIndicator({parentId: info.parent ? info.parent.id : null, beforeId: next ? next.id : null, endOfGroup: !next, groupId: info.parent ? info.parent.id : 'root'});
      }
    });
  });

  // Dropping inside an item's own children zone (below its existing kids,
  // above the "+ Add" button) makes the dragged item a new child of it.
  $$('[data-mgmchildzone]').forEach(zone => {
    zone.addEventListener('dragover', e => {
      if(mgmDragId === null) return;
      e.preventDefault();
      e.stopPropagation();
      const id = Number(zone.dataset.mgmchildzone);
      if(id === mgmDragId) return;
      mgmSetDropIndicator({into: id});
    });
  });

  $('#mgmTree').addEventListener('drop', async e => {
    e.preventDefault();
    if(mgmDragId === null || !mgmDragTarget) return;
    await mgmPerformDrop(mgmDragId, mgmDragTarget);
  });
}

function mgmClearDropIndicators(){
  $$('.mgmdropline.on').forEach(l => l.classList.remove('on'));
  $$('[data-mgmchildzone].mgm-into').forEach(z => z.classList.remove('mgm-into'));
  $$('[data-mgmitem].mgm-into-row').forEach(r => r.classList.remove('mgm-into-row'));
}

/* target is either {parentId, beforeId} — insert before the row with id
   beforeId inside parentId's group (beforeId null means end of that group,
   parentId null means the top level) — or {into: id} to become a new child
   of that id. Kept as one small object rather than several loose globals
   so mgmPerformDrop reads exactly what mgmSetDropIndicator decided, with
   nothing left to fall out of sync between them. */
function mgmSetDropIndicator(target){
  mgmClearDropIndicators();
  mgmDragTarget = target;

  if(target.into !== undefined){
    $(`[data-mgmchildzone="${target.into}"]`)?.classList.add('mgm-into');
    $(`[data-mgmitem="${target.into}"]`)?.classList.add('mgm-into-row');
    return;
  }
  if(target.beforeId !== null && target.beforeId !== undefined){
    $(`.mgmrow-wrap[data-mgmid="${target.beforeId}"] > [data-mgmdropline="before"]`)?.classList.add('on');
    return;
  }
  // End of group — parentId null means the root list's own end line;
  // otherwise the end line inside that parent's children zone.
  const sel = target.parentId === null
    ? '[data-mgmdropline-end="root"]'
    : `[data-mgmchildzone="${target.parentId}"] > [data-mgmdropline-end]`;
  $(sel)?.classList.add('on');
}

async function mgmPerformDrop(draggedId, target){
  const dragged = mgmSiblingsAndParent(draggedId);
  if(!dragged) return;
  const draggedParentId = dragged.parent ? dragged.parent.id : null;

  if(target.into !== undefined){
    if(target.into === draggedId) return;
    const targetPath = mgmPathTo(target.into);
    const newSiblings = (targetPath[targetPath.length - 1].children || [])
      .map(c => c.id).filter(id => id !== draggedId);
    newSiblings.push(draggedId);
    const r = await mgmApi('/' + draggedId + '/move', {method:'POST', body: JSON.stringify({parent_id: target.into, ids: newSiblings})});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not move that.'); return; }
    toast('Moved.');
    MGM_EXPANDED.add(target.into);
    renderMegaMenu();
    return;
  }

  const newParentId = target.parentId;
  const newGroup = newParentId === null ? MGM.tree : (mgmPathTo(newParentId)?.slice(-1)[0]?.children || []);
  let newIds = newGroup.map(s => s.id).filter(id => id !== draggedId);
  const insertAt = target.beforeId === null ? newIds.length : newIds.indexOf(target.beforeId);
  newIds.splice(insertAt < 0 ? newIds.length : insertAt, 0, draggedId);

  const sameParent = draggedParentId === newParentId;

  if(sameParent){
    const r = await mgmApi('/reorder', {method:'POST', body: JSON.stringify({ids: newIds})});
    if(!r.ok){ toast('Could not save the new order.'); renderMegaMenu(); return; }
  } else {
    const r = await mgmApi('/' + draggedId + '/move', {method:'POST', body: JSON.stringify({parent_id: newParentId, ids: newIds})});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not move that.'); renderMegaMenu(); return; }
  }
  toast('Moved.');
  renderMegaMenu();
}

/* ---------- Add / edit form ---------- */

function mgmOpenForm(parentId, depth, editing){
  const kind = depth === 0 ? 'top-level item' : depth === 1 ? 'column' : 'link';
  const isEdit = !!editing;
  const color = editing?.highlight_color || '';
  const vis = editing?.visibility || 'always';

  openModal(`<div class="modal-b mgm-modal">
    <h3>${isEdit ? 'Edit' : 'Add'} ${kind}</h3>
    <div class="mmrow"><div class="mmlbl"><b>Label</b></div>
      <input type="text" id="mgmfLabel" maxlength="60" value="${escAttr(editing?.label || '')}" placeholder="e.g. Skincare"></div>
    <div class="mmrow"><div class="mmlbl"><b>Link</b><span>${depth < 2 ? 'Leave blank for a heading that only opens a panel.' : ''}</span></div>
      <input type="text" id="mgmfUrl" maxlength="255" value="${escAttr(editing?.url || '')}" placeholder="/product-category/cleansers/"></div>
    ${depth === 0 ? `<div class="mmrow"><div class="mmlbl"><b>Badge</b><span>Optional — small pill next to the label, e.g. NEW</span></div>
      <input type="text" id="mgmfBadge" maxlength="20" value="${escAttr(editing?.badge || '')}" placeholder="NEW"></div>` : ''}
    ${depth === 2 ? `<div class="mmrow"><div class="mmlbl"><b>Icon</b><span>Optional — shown before the link</span></div>
      <span class="mgm-iconpick">
        <button type="button" class="mgm-iconbtn" id="mgmfIconBtn">${editing?.icon ? escHtml(editing.icon) : '<span class="mgm-iconph">＋</span>'}</button>
        <input type="hidden" id="mgmfIcon" value="${escAttr(editing?.icon || '')}">
      </span></div>` : ''}
    <div class="mmrow"><div class="mmlbl"><b>Highlight colour</b><span>Optional — a background tint to call this item out, e.g. for Sale</span></div>
      <span class="mgm-colorpick">
        <input type="color" id="mgmfColor" value="${color || '#E0567B'}">
        <button type="button" class="btn small" id="mgmfColorClear" ${color ? '' : 'style="display:none"'}>Clear</button>
        <input type="hidden" id="mgmfColorVal" value="${escAttr(color)}">
      </span></div>
    ${depth === 0 ? `<div class="mmrow"><div class="mmlbl"><b>Desktop columns</b><span>Only matters once this item has sub-items — auto splits into a new column roughly every 10, or set an exact count</span></div>
      <select id="mgmfColumns">
        <option value=""${!editing?.columns ? ' selected' : ''}>Auto</option>
        ${[1,2,3,4,5,6].map(n => `<option value="${n}"${editing?.columns===n ? ' selected' : ''}>${n} column${n===1?'':'s'}</option>`).join('')}
      </select></div>` : ''}
    <div class="mmrow"><div class="mmlbl"><b>Who sees it</b><span>Show or hide this item based on whether a shopper is signed in</span></div>
      <select id="mgmfVisibility">
        <option value="always"${vis==='always'?' selected':''}>Everyone</option>
        <option value="guest"${vis==='guest'?' selected':''}>Signed out only — e.g. "Sign In"</option>
        <option value="auth"${vis==='auth'?' selected':''}>Signed in only — e.g. "My Account"</option>
      </select></div>
    <div class="mmrow"><div class="mmlbl"><b>Open in a new tab</b><span>For links that leave the site</span></div>
      <span class="ectog${editing?.new_tab ? ' on' : ''}" id="mgmfNewTab" role="switch" aria-checked="${editing?.new_tab ? 'true' : 'false'}" tabindex="0"></span></div>
    <div class="kdlg-a"><button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmfSave">${isEdit ? 'Save' : 'Add'}</button></div>
  </div>`);

  const colorInput = $('#mgmfColor');
  const colorVal = $('#mgmfColorVal');
  const colorClear = $('#mgmfColorClear');
  if(color) colorVal.value = color;
  colorInput.oninput = () => { colorVal.value = colorInput.value; colorClear.style.display = ''; };
  colorClear.onclick = () => { colorVal.value = ''; colorClear.style.display = 'none'; };

  const iconBtn = $('#mgmfIconBtn');
  if(iconBtn) iconBtn.onclick = (e) => { e.preventDefault(); mgmIconPicker(iconBtn); };

  const newTabToggle = $('#mgmfNewTab');
  newTabToggle.onclick = () => {
    const on = !newTabToggle.classList.contains('on');
    newTabToggle.classList.toggle('on', on);
    newTabToggle.setAttribute('aria-checked', String(on));
  };

  $('#mgmfSave').onclick = async () => {
    const label = $('#mgmfLabel').value.trim();
    if(!label){ toast('Give it a label first.'); return; }

    const payload = {
      label,
      url: $('#mgmfUrl').value.trim() || null,
      badge: depth === 0 ? ($('#mgmfBadge').value.trim() || null) : null,
      icon: depth === 2 ? ($('#mgmfIcon').value.trim() || null) : null,
      highlight_color: colorVal.value || null,
      visibility: $('#mgmfVisibility').value,
      new_tab: newTabToggle.classList.contains('on'),
    };
    if(depth === 0){
      const colVal = $('#mgmfColumns').value;
      payload.columns = colVal ? Number(colVal) : null;
    }
    if(!isEdit){ payload.parent_id = parentId; payload.menu_id = MGM_CURRENT_MENU_ID; }

    const r = isEdit
      ? await mgmApi('/' + editing.id, {method:'POST', body: JSON.stringify(payload)})
      : await mgmApi('', {method:'POST', body: JSON.stringify(payload)});

    if(!r.ok){
      toast((r.data.errors && r.data.errors[0]) || 'Could not save that.');
      return;
    }
    toast(isEdit ? 'Saved.' : 'Added.');
    closeModal();
    renderMegaMenu();
  };
}

function mgmLoadDemoConfirm(){
  const w = document.createElement('div');
  w.className = 'kdlg';
  w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
    <h3>Load the kbeautybliss.com menu?</h3>
    <p>Creates a new menu with the real, current kbeautybliss.com navigation — same items, same order, brand links and all — and puts it live on both the desktop header and mobile menu right away. Whatever is currently showing there gets replaced; it isn't deleted, just no longer assigned anywhere.</p>
    <div class="kdlg-a"><button class="btn ghost" data-no>Cancel</button>
      <button class="btn primary" data-yes>Load it</button></div>
  </div>`;
  document.body.appendChild(w);
  requestAnimationFrame(() => w.classList.add('on'));
  const done = () => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); };
  w.querySelector('[data-no]').onclick = done;
  w.querySelector('.kdlg-s').onclick = done;
  w.querySelector('[data-yes]').onclick = async () => {
    done();
    const r = await mgmApi('/demo', {method: 'POST'});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not load the demo menu.'); return; }
    toast('Loaded — live on desktop and mobile now.');
    renderMegaMenu(r.data.id);
  };
}

function mgmNewMenuPrompt(){
  openModal(`<div class="modal-b mgm-modal">
    <h3>New menu</h3>
    <div class="mmrow"><div class="mmlbl"><b>Name</b><span>Just for you to tell menus apart — not shown to shoppers</span></div>
      <input type="text" id="mgmNewName" maxlength="60" placeholder="e.g. Footer Links"></div>
    <div class="kdlg-a"><button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmNewSave">Create</button></div>
  </div>`);

  $('#mgmNewSave').onclick = async () => {
    const name = $('#mgmNewName').value.trim();
    if(!name){ toast('Give it a name first.'); return; }
    const r = await mgmApi('/menus', {method:'POST', body: JSON.stringify({name})});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not create that.'); return; }
    toast('Menu created.');
    closeModal();
    renderMegaMenu(r.data.id);
  };
}

function mgmMenuSettingsForm(){
  const current = MGM_MENUS.find(m => m.id === MGM_CURRENT_MENU_ID);
  if(!current) return;

  const slot = (field, label) => `<label class="mgm-slotbox">
    <input type="checkbox" id="mgmMs_${field}" ${current[field] ? 'checked' : ''}>
    <span>${label}</span>
  </label>`;

  openModal(`<div class="modal-b mgm-modal">
    <h3>Menu settings</h3>
    <div class="mmrow"><div class="mmlbl"><b>Name</b></div>
      <input type="text" id="mgmMsName" maxlength="60" value="${escAttr(current.name)}"></div>
    <div class="mmrow" style="align-items:flex-start">
      <div class="mmlbl"><b>Show this menu at</b><span>Leave everything unchecked to keep it built but not shown anywhere. Checking a box here takes that spot from whichever menu currently has it.</span></div>
      <div class="mgm-slotboxes">
        ${slot('show_desktop', 'Desktop header')}
        ${slot('show_mobile', 'Mobile menu')}
        ${slot('show_footer', 'Footer')}
      </div>
    </div>
    <div class="kdlg-a">
      ${MGM_MENUS.length > 1 ? `<button class="btn danger" id="mgmMsDelete" style="margin-right:6px">Delete menu</button>` : ''}
      <button class="btn ghost" id="mgmMsDuplicate" style="margin-right:auto">Duplicate</button>
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn primary" id="mgmMsSave">Save</button>
    </div>
  </div>`);

  $('#mgmMsSave').onclick = async () => {
    const name = $('#mgmMsName').value.trim();
    if(!name){ toast('Give it a name first.'); return; }
    const payload = {
      name,
      show_desktop: $('#mgmMs_show_desktop').checked,
      show_mobile: $('#mgmMs_show_mobile').checked,
      show_footer: $('#mgmMs_show_footer').checked,
    };
    const r = await mgmApi('/menus/' + current.id, {method:'POST', body: JSON.stringify(payload)});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not save that.'); return; }
    toast('Menu settings saved.');
    closeModal();
    renderMegaMenu(current.id);
  };

  $('#mgmMsDuplicate').onclick = async () => {
    const r = await mgmApi('/menus/' + current.id + '/duplicate', {method:'POST'});
    if(!r.ok){ toast((r.data.errors && r.data.errors[0]) || 'Could not duplicate that.'); return; }
    toast('Duplicated — not shown anywhere yet, assign it in Menu settings when ready.');
    closeModal();
    renderMegaMenu(r.data.id);
  };

  const delBtn = $('#mgmMsDelete');
  if(delBtn) delBtn.onclick = async () => {
    const ok = await mgmDeleteMenuConfirm(current.name);
    if(!ok) return;
    const r = await mgmApi('/menus/' + current.id + '/delete', {method:'POST'});
    if(!r.ok){ toast('Could not delete that.'); return; }
    toast('Menu deleted.');
    closeModal();
    MGM_CURRENT_MENU_ID = null;
    renderMegaMenu();
  };
}

function mgmDeleteMenuConfirm(name){
  return new Promise(resolve => {
    const w = document.createElement('div');
    w.className = 'kdlg';
    w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
      <h3>Delete the whole "${escHtml(name)}" menu?</h3>
      <p>Every item in it goes too. If it's currently assigned to the desktop header, mobile menu, or footer, that spot falls back to nothing.</p>
      <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
        <button class="btn primary" data-yes>Delete menu</button></div>
    </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(() => w.classList.add('on'));
    const done = v => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); resolve(v); };
    w.querySelector('[data-no]').onclick = () => done(false);
    w.querySelector('.kdlg-s').onclick = () => done(false);
    w.querySelector('[data-yes]').onclick = () => done(true);
    document.addEventListener('keydown', function esc(e){
      if(e.key === 'Escape'){ document.removeEventListener('keydown', esc); done(false); }
    });
  });
}

/* Curated for a K-beauty storefront rather than a generic picker with
   thousands of irrelevant options — skincare/beauty first, then shopping
   and general-nav symbols an admin is actually likely to reach for here. */
const MGM_ICON_SET = [
  '🧴','💧','🧼','🌿','✨','🫧','💆','🧖','🩹','🌸','🧊','☀️','💄','🪞',
  '🎁','🔥','🏷️','💯','🆕','📦','🛍️','⭐','💖','🎉',
  '🏠','👤','❤️','🔍','🚚','💳',
];

function mgmIconPicker(anchorBtn){
  document.querySelectorAll('.mgm-iconpop').forEach(p => p.remove());

  const pop = document.createElement('div');
  pop.className = 'mgm-iconpop';
  pop.innerHTML = `
    <div class="mgm-icongrid">
      ${MGM_ICON_SET.map(e => `<button type="button" class="mgm-iconopt" data-icon="${escAttr(e)}">${e}</button>`).join('')}
    </div>
    <div class="mgm-iconcustom">
      <input type="text" id="mgmIconCustom" maxlength="10" placeholder="or type any emoji">
      <button type="button" class="btn small" id="mgmIconCustomUse">Use</button>
    </div>
    <button type="button" class="mgm-iconclear" id="mgmIconClearBtn">No icon</button>
  `;
  // Appended to <body>, not the button's own parent — that parent sits
  // inside .modal-b, and .modal has overflow:auto for long forms. A
  // position:absolute popover nested in there gets silently clipped at the
  // modal's edge instead of floating above it. Confirmed directly by
  // rendering it before shipping: the grid was cut off mid-row. Fixed
  // popovers positioned from the anchor's real screen coordinates avoid
  // that scrolling context entirely.
  document.body.appendChild(pop);
  const r = anchorBtn.getBoundingClientRect();
  pop.style.top = (r.bottom + 8) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 236 - 16) + 'px';

  const pick = (val) => {
    $('#mgmfIcon').value = val;
    anchorBtn.innerHTML = val ? escHtml(val) : '<span class="mgm-iconph">＋</span>';
    pop.remove();
  };

  pop.querySelectorAll('[data-icon]').forEach(b => b.onclick = () => pick(b.dataset.icon));
  $('#mgmIconClearBtn', pop).onclick = () => pick('');
  $('#mgmIconCustomUse', pop).onclick = () => pick($('#mgmIconCustom', pop).value.trim());

  const closeOnOutside = (e) => {
    if(!pop.contains(e.target) && e.target !== anchorBtn){
      pop.remove();
      document.removeEventListener('click', closeOnOutside, true);
    }
  };
  setTimeout(() => document.addEventListener('click', closeOnOutside, true), 0);
}

function mgmDeleteConfirm(label){
  return new Promise(resolve => {
    const w = document.createElement('div');
    w.className = 'kdlg';
    w.innerHTML = `<div class="kdlg-s"></div><div class="kdlg-b" role="dialog" aria-modal="true">
      <h3>Delete "${escHtml(label)}"?</h3>
      <p>Anything nested under it — columns, links — is deleted too. This can't be undone; you'd have to rebuild it.</p>
      <div class="kdlg-a"><button class="btn" data-no>Cancel</button>
        <button class="btn primary" data-yes>Delete</button></div>
    </div>`;
    document.body.appendChild(w);
    requestAnimationFrame(() => w.classList.add('on'));
    const done = v => { w.classList.remove('on'); setTimeout(() => w.remove(), 200); resolve(v); };
    w.querySelector('[data-no]').onclick = () => done(false);
    w.querySelector('.kdlg-s').onclick = () => done(false);
    w.querySelector('[data-yes]').onclick = () => done(true);
    document.addEventListener('keydown', function esc(e){
      if(e.key === 'Escape'){ document.removeEventListener('keydown', esc); done(false); }
    });
  });
}


function bindDividers(){
  const dirty=()=>{ const d=$('#dvDirty'); if(d) d.style.visibility='visible'; };

  $$('[data-dvtab]').forEach(b=>b.onclick=()=>{ DVTAB=b.dataset.dvtab; paintDividers(); });

  $$('[data-dv]').forEach(el=>{
    const k=el.dataset.dv;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); dvSet(k,v); dirty(); paintDividers(); };
      return;
    }
    if(el.tagName==='SELECT'){ el.onchange=()=>{ dvSet(k,el.value); dirty(); paintDividers(); }; return; }
    if(el.type==='range'){
      el.oninput=()=>{ dvSet(k,Number(el.value)); dirty();
        const b=$('#dvv-'+k); if(b){ const f=DV.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=dvPreview(); };
      return;
    }
    el.oninput=()=>{ dvSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=dvPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });

  const write=(list)=>{ dvSet('sections',list.join(',')); dirty(); paintDividers(); };
  $$('[data-dvsec]').forEach(cb=>cb.onchange=()=>{
    const k=cb.dataset.dvsec, on=dvPicked();
    write(cb.checked ? [...new Set([...on,k])] : on.filter(x=>x!==k));
  });
  const all=$('[data-dvall]'); if(all) all.onclick=()=>write(DV.sections.map(s=>s.key));
  const none=$('[data-dvnone]'); if(none) none.onclick=()=>write([]);

  const save=$('#dvSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of DV.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(dvBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#dvDirty').style.visibility='hidden';
      toast('Dividers saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Cart panel ----------
   Same shape as the other settings screens: the server sends tabs and fields,
   this renders them generically, so adding a setting to the service is all it
   takes to have a control appear here. */
let CP=null, CPTAB='size';

function cpBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/cart-panel'; }

async function renderCartPanel(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Cart panel</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(cpBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    CP=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the cart panel settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderCartPanel()">Retry</button></div></div>`;
    return;
  }
  paintCartPanel();
}
function cpGet(k){ for(const t of CP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function cpSet(k,v){ for(const t of CP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function cpField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-cp="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-cp="${f.key}">
        <i id="cpv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-cp="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-cp="${f.key}"></div>`;
}

/* A real panel at the chosen size and density, using the drawer's own class
   names so the preview and the storefront cannot drift apart. */
function cpPreview(){
  const on = (k)=>cpGet(k)!==false;
  const line = (init,name,price,grad)=>`<div class="cpp-item">
      ${on('show_thumb')?`<div class="cpp-th" style="background:${grad}">${init}</div>`:''}
      <div class="cpp-mid"><div class="cpp-nm">${escHtml(name)}</div>
        ${on('show_qty')?`<div class="cpp-qty"><span>−</span><b>2</b><span>+</span></div>`:''}</div>
      <div class="cpp-right">${on('show_remove')?`<span class="cpp-rm">✕</span>`:''}
        ${on('show_price')?`<div class="cpp-pr">${price} د.إ</div>`:''}</div>
    </div>`;

  const acc = cpGet('accent'), cta = cpGet('checkout_bg'), ctaFg = cpGet('checkout_fg');

  return `<div class="cpp" style="width:${Math.round(cpGet('panel_width')*0.62)}px;
      --pad:${cpGet('list_pad')}px;--rowpad:${cpGet('row_pad')}px;--thumb:${cpGet('thumb_size')}px;
      --nm:${cpGet('name_size')}px;--lines:${cpGet('name_lines')};--step:${cpGet('stepper_size')}px;--acc:${acc}">
    <div class="cpp-tabs"><b style="color:${escAttr(acc)};border-color:${escAttr(acc)}">${escHtml(cpGet('txt_tab_cart'))} 4</b>${on('show_browsed')?`<i>${escHtml(cpGet('txt_tab_browsed'))}</i>`:''}<u>✕</u></div>
    ${on('show_ship_bar')?`<div class="cpp-ship">${escHtml(cpGet('txt_ship_done'))}<div class="cpp-bar"><div style="background:${escAttr(acc)}"></div></div></div>`:''}
    <div class="cpp-body">
      ${line('I','Age-R Booster Pro Device','80','linear-gradient(140deg,#F6C6A0,#E89B6C)')}
      ${line('RL','Hyaluronic Acid Watery Sun Gel that runs to a second line','133','linear-gradient(140deg,#F4A6B8,#E0567B)')}
      ${line('M','Cellmazing Fit Serum','299','linear-gradient(140deg,#A8D0F0,#5F9BD4)')}
      ${line('S','Ceramide Daily Moisturiser','329','linear-gradient(140deg,#C4B5F0,#8B6FD4)')}
    </div>
    ${on('show_promo')?`<div class="cpp-promo">🎁 Spend 199 for free delivery</div>`:''}
    <div class="cpp-foot"><div class="cpp-sum"><span>${escHtml(cpGet('txt_subtotal'))}</span><span>841 د.إ</span></div>
      <div class="cpp-btns"><a>${escHtml(cpGet('txt_btn_cart'))}</a><a style="background:${escAttr(cta)};color:${escAttr(ctaFg)};border-color:${escAttr(cta)}">${escHtml(cpGet('txt_btn_checkout'))}</a></div></div>
  </div>`;
}

function paintCartPanel(){
  const tab=CP.tabs.find(t=>t.key===CPTAB)||CP.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Cart panel</h2>
      <p class="mdesc" style="margin:0">The slide-out bag: how wide it is, how tightly the lines pack, and what each line shows.</p></div>
    <div class="ectabs">${CP.tabs.map(t=>`<button class="ectab${t.key===CPTAB?' on':''}" data-cptab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(cpField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${cpPreview()}</div><p class="mmpv-note">Live preview · desktop width, shown smaller</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="cpDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="cpSave">Save changes</button>
    </div>
  </div>`;
  bindCartPanel();
}

function bindCartPanel(){
  $$('[data-cptab]').forEach(b=>b.onclick=()=>{ CPTAB=b.dataset.cptab; paintCartPanel(); });

  $$('[data-cp]').forEach(el=>{
    const k=el.dataset.cp;
    const dirty=()=>{ const d=$('#cpDirty'); if(d) d.style.visibility='visible'; };

    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); cpSet(k,v); dirty(); paintCartPanel(); };
      return;
    }
    if(el.type==='range'){
      /* Repaint the preview on every drag but leave the slider alone, so the
         thumb does not jump out from under the pointer mid-drag. */
      el.oninput=()=>{ cpSet(k,Number(el.value)); dirty();
        const badge=$('#cpv-'+k); if(badge){ const f=CP.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          badge.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=cpPreview(); };
      return;
    }
    el.oninput=()=>{ cpSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=cpPreview();
      const code=el.parentElement.querySelector('code'); if(code) code.textContent=el.value; };
  });

  const save=$('#cpSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of CP.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(cpBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#cpDirty').style.visibility='hidden';
      toast('Cart panel saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Appearance · Login / Register panel ---------- */
let AP=null, APTAB='welcome', APDEV='desktop';

function apBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/account-panel'; }

async function renderAcctPanel(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Login / Register panel</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(apBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    AP=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the panel settings. <button class="btn small" onclick="renderAcctPanel()">Retry</button></div></div>`;
    return;
  }
  apPaint();
}
function apGet(k){ for(const t of AP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function apSet(k,v){ for(const t of AP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }
function apDirty(){ const d=$('#apDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent='Unsaved changes';} }

function apField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ap="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ap="${f.key}">
        <i id="apv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-ap="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-ap="${f.key}"></div>`;
}

function apPaint(){
  const tab=AP.tabs.find(t=>t.key===APTAB)||AP.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Login / Register panel</h2>
      <p class="mdesc" style="margin:0">The panel behind the account icon, and the welcome shown once after signing in.</p></div>
    <div class="ectabs">${AP.tabs.map(t=>`<button class="ectab${t.key===APTAB?' on':''}" data-aptab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(apField).join('')}</div></div></div>
      <div class="mmpv">
        <div class="apdev"><button class="apd${APDEV==='desktop'?' on':''}" data-apdev="desktop">Desktop</button>
          <button class="apd${APDEV==='phone'?' on':''}" data-apdev="phone">Phone</button></div>
        <div class="mmpv-in" id="apPrev"></div>
        <p class="mmpv-note">${APTAB==='forms'?'The sign-in page':'Signed in · the welcome then folds away'}</p>
        <button class="btn small" id="apReplay" style="margin-top:8px">Play again</button>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="apDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="apReset">Reset to defaults</button>
      <button class="btn primary" id="apSave">Save changes</button>
    </div></div>`;
  apPreview();
}

/* The panel drawn with the storefront's own class names. */

/* The sign-in form in the chosen style, drawn with the storefront's own
   class names so the two cannot drift. */
function apFormPreview(phone){
  const g=apGet;
  const cls='fs-'+g('form_style')+(g('form_icons')?'':' fs-noicons')+(g('form_strength')?'':' fs-nometer');
  const ico=(n)=>g('form_icons')?`<span class="lead">${APICONS[n]}</span>`:'';
  const fld=(label,n,type)=>`<div class="fld${g('form_icons')?' ico':''}">${ico(n)}
      <input type="${type||'text'}" placeholder=" "><label>${label}</label></div>`;
  $('#apPrev').innerHTML=`<div class="apform ${cls}${phone?' phone':''}" style="--fld-gap:${g('field_gap')}px">
    <div class="authcard">
      ${g('form_mark')?'<span class="mark">KB</span>':''}
      <h1>Welcome back</h1><p class="lede">Sign in to see your orders.</p>
      <div class="segs"><span class="seg on">Sign in</span><span class="seg">Create account</span></div>
      <div class="fgroup">${fld('Email address','mail','email')}${fld('Password','lock','password')}</div>
      <div class="row"><span class="chk"><span class="bx"></span>Stay signed in</span><a>Forgot password?</a></div>
      <button class="go">Sign in</button>
    </div></div>`;
}
const APICONS={
  mail:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/></svg>',
  lock:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="10" width="16" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
  user:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c0-3.6 3.4-5.6 7.5-5.6s7.5 2 7.5 5.6"/></svg>'};

function apPreview(){
  const g=apGet, phone=APDEV==='phone';
  if(APTAB==='forms'){ apFormPreview(phone); return; }
  const font=AP.fonts[g('welcome_font')]||AP.fonts.cormorant;
  const size=phone?g('welcome_size_m'):g('welcome_size');
  const vars=`--ap-w:${phone?250:g('panel_width')}px;--ap-r:${g('panel_radius')}px;
    --ap-font:${font.stack};--ap-weight:${font.weight};--ap-size:${size}px;--ap-hold:${g('welcome_hold')}ms`;
  const links=[['link_orders','Orders'],['link_wishlist','Wishlist'],['link_address','Addresses'],['link_track','Track my order']]
    .filter(([k])=>g(k)).map(([,l])=>`<span class="apit">${l}</span>`).join('');
  $('#apPrev').innerHTML=`<div class="apstage${phone?' phone':''}">
    <div class="apbar"><span class="aplg">K-Beauty<em>Bliss</em></span><span class="apic"></span></div>
    <div class="appanel ap-${g('welcome_style')}" style="${vars}" id="apPanelBox">
      <div class="aphead"><span class="apav">R</span><span class="apwho">
        <b class="${g('welcome_show')?'ap-greet ap-'+g('welcome_style'):''}">Rafi</b>
        ${g('welcome_show')
          ? `<span class="apsub"><span class="ap-kick">${escHtml(g('welcome_back'))}</span>
             ${g('show_email')?'<span class="ap-mail">rafi@kbeautybliss.com</span>':''}</span>`
          : (g('show_email')?'<span>rafi@kbeautybliss.com</span>':'')}
      </span></div>
      <span class="apit">My account</span>${links}
      <span class="apout">Sign out</span>
    </div></div>`;
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ap]'); if(!el||!AP) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const o=$('#apv-'+el.dataset.ap);
    if(o){ let u=''; for(const t of AP.tabs){const f=t.fields.find(x=>x.key===el.dataset.ap); if(f&&f.options) u=f.options.unit||'';} o.textContent=v+u; } }
  apSet(el.dataset.ap,v); apPreview(); apDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ap]'); if(!el||!AP) return;
  apSet(el.dataset.ap, el.value); apPreview(); apDirty();
});
document.addEventListener('click', async e=>{
  if(!AP) return;
  const tb=e.target.closest('[data-aptab]'); if(tb){ APTAB=tb.dataset.aptab; apPaint(); return; }
  const dv=e.target.closest('[data-apdev]'); if(dv){ APDEV=dv.dataset.apdev; apPaint(); return; }
  const tg=e.target.closest('.ectog[data-ap]');
  if(tg){ const k=tg.dataset.ap, cur=apGet(k); apSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); apPreview(); apDirty(); return; }
  if(e.target.id==='apReplay'){ apPreview(); return; }
  if(e.target.id==='apReset'){ AP.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); apPaint(); apDirty(); return; }
  if(e.target.id!=='apSave') return;
  const payload={}; AP.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#apDirty');
  try{
    const r=await fetch(apBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* ---------- Appearance · Header ----------
   Tabs across the top because the header has seven distinct parts; one long
   column would bury the thing being looked for. */
let HD=null, HDTAB='bar';

function hdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/header'; }

async function renderHeader(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Header</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(hdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    HD=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the header settings. <button class="btn small" onclick="renderHeader()">Retry</button></div></div>`;
    return;
  }
  paintHeader();
}

function hdField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-hd="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-hd="${f.key}">
        <i id="hdv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-hd="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-hd="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='tags'){
    const words=String(v||'').split(',').map(w=>w.trim()).filter(Boolean);
    const used=words.map(w=>w.toLowerCase());
    const spare=(HD.suggestions||[]).filter(x=>!used.includes(String(x).toLowerCase())).slice(0,14);
    return `<div class="mmrow tagrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <div class="tagbox">
        <div class="tags" id="tagList">${words.map((w,i)=>`<span class="tag">${escHtml(w)}<button type="button" data-tagdel="${i}" aria-label="Remove">&times;</button></span>`).join('') || '<span class="tagempty">No words yet</span>'}</div>
        <div class="tagadd"><input type="text" id="tagInput" placeholder="Type a word and press Enter" maxlength="40">
          <button class="btn small" type="button" id="tagAdd">Add</button></div>
        ${spare.length?`<div class="tagsug"><span>From your catalogue</span>${spare.map(x=>`<button type="button" class="sug" data-tagadd="${escAttr(x)}">${escHtml(x)}</button>`).join('')}</div>`:''}
      </div></div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-hd="${f.key}"></div>`;
}

function hdGet(k){ for(const t of HD.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }

function paintHeader(){
  const tab=HD.tabs.find(t=>t.key===HDTAB)||HD.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Header</h2>
      <p class="mdesc" style="margin:0">Every part of the header, grouped. The mobile menu itself has its own screen.</p></div>
    <div class="ectabs">${HD.tabs.map(t=>`<button class="ectab${t.key===HDTAB?' on':''}" data-hdtab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(hdField).join('')}</div>
      </div></div>
      <div class="mmpv"><div class="mmpv-in" id="hdPhone"></div><p class="mmpv-note">Live preview</p>
        <div id="hdHeightWrap" style="display:none">
          <div class="mmpv-in" style="margin-top:12px" id="hdHeights"></div>
          <p class="mmpv-note">Rows and their heights</p></div>
        <div id="hdPanelWrap" style="display:none">
          <div class="mmpv-in" style="margin-top:12px" id="hdPanel"></div>
          <p class="mmpv-note">Search panel, before typing</p></div>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="hdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="hdReset">Reset to defaults</button>
      <button class="btn primary" id="hdSave">Save changes</button>
    </div></div>`;
  hdPreview();
  hdPanelPreview();
  hdHeightPreview();
}

/* The preview uses the storefront's own classes, so it cannot drift. */
function hdPreview(){
  const g=hdGet;
  const vars=`--hd-h:${g('bar_height')}px;--hd-bg:${g('bar_bg')};--hd-logo:${g('logo_size')}px;
    --hd-logo-c:${g('logo_colour')};--hd-logo-a:${g('logo_accent_col')};--hd-radius:${g('search_radius')}px;
    --hd-icon:${g('icon_size')}px;--hd-badge:${g('badge_bg')};--hd-nav:${g('nav_size')}px;--hd-gap:${g('nav_gap')}px;
    --hd-hot:${g('nav_hot_colour')};--hd-sup-bg:${g('support_icon_bg')};--hd-sup-fg:${g('support_icon_fg')};
    --mi-size:${g('menu_icon_size')}px;--mi-speed:${g('menu_icon_speed')}s;
    --mi-c1:${g('menu_icon_c1')};--mi-c2:${g('menu_icon_c2')};--mi-c3:${g('menu_icon_c3')}`;
  const icon=g('menu_icon');
  const fam = icon==='tiles'?'tiles' : icon==='dots9'?'dots9' : icon==='dots3'?'dots3' : 'bars';
  const inner = fam==='tiles' ? '<span class="s"></span>'.repeat(4)
              : fam==='dots9' ? '<span class="d"></span>'.repeat(9)
              : fam==='dots3' ? '<span class="d"></span>'.repeat(3)
              : '<span class="b"></span>'.repeat(3);
  const nav=['Brands','Skincare','Sunscreens','SUPER SALE','BLOG'];
  $('#hdPhone').innerHTML=`<div class="hdpv" style="${vars}">
    <div class="hdpv-bar${g('bar_border')?' bd':''}">
      <span class="kbbmi kbbmi-${icon}">${inner}</span>
      <span class="hdpv-logo">${escHtml(g('logo_text'))}<em>${escHtml(g('logo_accent'))}</em></span>
      <span class="hdpv-icons">${g('icon_account')?'<i>&#128100;</i>':''}${g('icon_wishlist')?'<i>&#9825;</i>':''}${g('icon_cart')?'<i class="bg">&#128722;</i>':''}</span>
    </div>
    ${g('search_show')?`<div class="hdpv-srch"><span>${escHtml(String(g('search_text')).replace('{n}','671'))}</span></div>`:''}
    ${g('trending_show')?`<div class="hdpv-trend">${['Madeca','PDRN','Retinol'].slice(0,Math.max(1,Math.min(3,g('trending_limit')))).map(t=>`<span>${t}</span>`).join('')}</div>`:''}
    ${g('nav_show')?`<div class="hdpv-nav">${nav.map(n=>`<span class="${n==='SUPER SALE'?'hot':''}">${n}</span>`).join('')}</div>`:''}
    ${g('support_show')?`<div class="hdpv-sup"><i></i>${escHtml(g('support_label'))}</div>`:''}
  </div>`;
}


/* The search panel, drawn from the same settings so the choice can be seen
   rather than imagined. */

/* Both headers drawn at their configured heights, each row labelled, so the
   numbers can be judged as a shape rather than read as digits. */
function hdHeightPreview(){
  const wrap=$('#hdHeightWrap'); if(!wrap) return;
  if(HDTAB!=='bar'){ wrap.style.display='none'; return; }
  wrap.style.display='';
  const g=hdGet;
  const bar=g('bar_height'), nav=g('nav_height'), fld=g('field_height');
  const mbar=g('bar_height_mobile'), mfld=g('field_height_mobile');
  const dTotal=bar+nav+2, mRow=mfld+10, mTotal=mbar+mRow+2;
  $('#hdHeights').innerHTML=`
    <div class="hhblock">
      <div class="hhcap">Desktop <b>${dTotal}px</b></div>
      <div class="hh">
        <div class="hhrow" style="height:${bar}px"><span>Bar</span><i>${bar}px</i>
          <span class="hhfld" style="height:${fld}px">field ${fld}px</span></div>
        <div class="hhrow nav" style="height:${nav}px"><span>Categories</span><i>${nav}px</i></div>
      </div>
    </div>
    <div class="hhblock">
      <div class="hhcap">Phone <b>${mTotal}px</b></div>
      <div class="hh phone">
        <div class="hhrow" style="height:${mbar}px"><span>Bar</span><i>${mbar}px</i></div>
        <div class="hhrow srch" style="height:${mRow}px"><span>Search row</span><i>${mRow}px</i>
          <span class="hhfld" style="height:${mfld}px">field ${mfld}px</span></div>
      </div>
    </div>`;
}

function hdPanelPreview(){
  const wrap=$('#hdPanelWrap'); if(!wrap) return;
  if(HDTAB!=='search'){ wrap.style.display='none'; return; }
  wrap.style.display='';
  const layout=hdGet('search_panel');
  const words=String(hdGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
  const t=words.slice(0, hdGet('trending_limit'));
  const chips=a=>`<div class="pchips">${a.map(w=>`<span>${escHtml(w)}</span>`).join('')}</div>`;
  const right=`<div class="pgh">Trending</div>${chips(t)}<div class="pgh">Popular Brands</div>${chips(['Anua','COSRX','Medicube'])}`;
  const recent=['anua toner','sunscreen spf50','cosrx snail','pdrn serum','retinol']
    .slice(0, hdGet('search_recent_count')).map(x=>`<div class="prec">${escHtml(x)}</div>`).join('');
  const prods=Array.from({length:Math.min(3,hdGet('search_results_max'))},()=>'<div class="pprod"><i></i><span></span></div>').join('');
  let inner;
  if(layout==='wide-then-two') inner=`<div class="ppanel">${right}</div>`;
  else if(layout==='two-columns') inner=`<div class="ppanel two"><div><div class="pgh">Popular right now</div>${prods}</div><div>${right}</div></div>`;
  else inner=`<div class="ppanel two"><div><div class="pgh">Most searched this week</div>${recent}<div class="pgh">Popular right now</div>${prods}</div><div>${right}</div></div>`;
  $('#hdPanel').innerHTML=`<div class="pfield"><span>Search skincare, brands…</span></div>${inner}`;
}

function hdDirty(t){ const d=$('#hdDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }
function hdSet(k,v){ for(const t of HD.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-hd]'); if(!el||!HD) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const out=$('#hdv-'+el.dataset.hd); if(out){
    let unit=''; for(const t of HD.tabs){const f=t.fields.find(x=>x.key===el.dataset.hd); if(f&&f.options) unit=f.options.unit||'';}
    out.textContent=v+unit; } }
  if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
  hdSet(el.dataset.hd, v); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-hd]'); if(!el||!HD) return;
  hdSet(el.dataset.hd, el.value); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty();
});
document.addEventListener('click', async e=>{
  if(!HD) return;
  const tb=e.target.closest('[data-hdtab]');
  if(tb){ HDTAB=tb.dataset.hdtab; paintHeader(); return; }
  const tg=e.target.closest('.ectog[data-hd]');
  if(tg){ const k=tg.dataset.hd; const cur=hdGet(k); hdSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); hdPreview(); hdPanelPreview(); hdHeightPreview(); hdDirty(); return; }
  if(e.target.id==='hdReset'){ HD.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); paintHeader(); hdDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='hdSave') return;
  const payload={}; HD.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#hdDirty');
  try{
    const r=await fetch(hdBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* ---------- Store · Site Search ----------
   Same schema-driven approach as Header (ssField mirrors hdField), kept as
   its own set of functions rather than reusing the Header ones directly —
   both screens' fields would otherwise answer to the same data-hd attribute
   and the same global listeners, so a change made here would also trip
   Header's own preview and dirty-state logic. Storage is still the same
   HeaderSettings-backed fields; only the editing surface has moved. */
let SS=null, SSTAB='search';

function ssBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/site-search'; }

async function renderSiteSearch(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Site Search</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(ssBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    SS=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load search settings. <button class="btn small" onclick="renderSiteSearch()">Retry</button></div></div>`;
    return;
  }
  paintSiteSearch();
}

function ssField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ss="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-ss="${f.key}">
        <i id="ssv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-ss="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-ss="${f.key}"><code>${v}</code></span></div>`;
  if(f.type==='tags'){
    const words=String(v||'').split(',').map(w=>w.trim()).filter(Boolean);
    return `<div class="mmrow tagrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <div class="tagbox">
        <div class="tags" id="ssTagList">${words.map((w,i)=>`<span class="tag">${escHtml(w)}<button type="button" data-sstagdel="${i}" aria-label="Remove">&times;</button></span>`).join('') || '<span class="tagempty">No words yet</span>'}</div>
        <div class="tagadd"><input type="text" id="ssTagInput" placeholder="Type a word and press Enter" maxlength="40">
          <button class="btn small" type="button" id="ssTagAdd">Add</button></div>
      </div></div>`;
  }
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-ss="${f.key}"></div>`;
}

function ssGet(k){ for(const t of SS.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function ssSet(k,v){ for(const t of SS.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function paintSiteSearch(){
  const tab=SS.tabs.find(t=>t.key===SSTAB)||SS.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Site Search</h2>
      <p class="mdesc" style="margin:0">The header search box, its suggestion panel, and how it matches a query.</p></div>
    <div class="ectabs">${SS.tabs.map(t=>`<button class="ectab${t.key===SSTAB?' on':''}" data-sstab="${t.key}">${escHtml(t.label)}<span class="ecn">${t.fields.length}</span></button>`).join('')}</div>
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(ssField).join('')}</div>
      </div></div>
      <div class="mmpv"><div class="mmpv-in" id="ssPanel"></div><p class="mmpv-note">Live preview — a sample match for "cream"</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="ssDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="ssReset">Reset to defaults</button>
      <button class="btn primary" id="ssSave">Save changes</button>
    </div></div>`;
  ssPreview();
}

/* A static mock of the suggestion panel — same markup and classes the real
   one uses, so this cannot drift from what a shopper actually sees. Only
   colour/shape settings visibly change it; the behavioural ones (limits,
   minimum characters) do not have a meaningful static preview and are left
   to speak for themselves through their labels and help text. */
function ssPreview(){
  const g=ssGet;
  const vars=`--pink:${g('search_style_accent')};--pink-deep:${g('search_style_accent_deep')};
    --line-2:${g('search_style_chip_bg')};--ink-2:${g('search_style_chip_text')}`;
  $('#ssPanel').innerHTML=`<div class="sugg on" style="position:static;box-shadow:none;${vars};border-radius:${g('search_radius')}px">
    <div class="colA">
      <div class="sgh">Products</div>
      <a class="sgi" style="pointer-events:none"><i style="background:linear-gradient(135deg,#ffd1e2,#ff9fc1)"></i>
        <span><b>Snail Mucin Cream</b><small>COSRX · <span class="price">89 AED</span></small></span></a>
      <a class="viewall" style="border-radius:${g('search_style_radius')}px;pointer-events:none">View all results <b>(12 found)</b> <i>→</i></a>
    </div>
    <div class="colB">
      <div class="sgh">Trending</div>
      <div class="chips">
        <span class="chip" style="pointer-events:none">Retinol</span>
        <span class="chip" style="pointer-events:none">Snail mucin</span>
        <span class="chip" style="pointer-events:none">Medicube</span>
      </div>
    </div>
  </div>`;
}

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-ss]'); if(!el||!SS) return;
  let v=el.value;
  if(el.type==='range'){ v=+v; const out=$('#ssv-'+el.dataset.ss); if(out){
    let unit=''; for(const t of SS.tabs){const f=t.fields.find(x=>x.key===el.dataset.ss); if(f&&f.options) unit=f.options.unit||'';}
    out.textContent=v+unit; } }
  if(el.type==='color'){ const c=el.nextElementSibling; if(c) c.textContent=v; }
  ssSet(el.dataset.ss, v); ssPreview(); ssDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-ss]'); if(!el||!SS) return;
  ssSet(el.dataset.ss, el.value); ssPreview(); ssDirty();
});
function ssDirty(t){ const d=$('#ssDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }
document.addEventListener('click', async e=>{
  if(!SS) return;
  const tb=e.target.closest('[data-sstab]');
  if(tb){ SSTAB=tb.dataset.sstab; paintSiteSearch(); return; }
  const tg=e.target.closest('.ectog[data-ss]');
  if(tg){ const k=tg.dataset.ss; const cur=ssGet(k); ssSet(k,!cur);
    tg.classList.toggle('on',!cur); tg.setAttribute('aria-checked',!cur); ssPreview(); ssDirty(); return; }
  if(e.target.id==='ssTagAdd' || (e.target.id==='ssTagInput' && e.key==='Enter')){
    const inp=$('#ssTagInput'); const val=(inp?.value||'').trim(); if(!val) return;
    const cur=String(ssGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
    if(!cur.some(w=>w.toLowerCase()===val.toLowerCase())){ cur.push(val); ssSet('trending_words', cur.join(', ')); paintSiteSearch(); ssDirty(); }
    return;
  }
  const del=e.target.closest('[data-sstagdel]');
  if(del){ const idx=+del.dataset.sstagdel; const cur=String(ssGet('trending_words')||'').split(',').map(w=>w.trim()).filter(Boolean);
    cur.splice(idx,1); ssSet('trending_words', cur.join(', ')); paintSiteSearch(); ssDirty(); return; }
  if(e.target.id==='ssReset'){ SS.tabs.forEach(t=>t.fields.forEach(f=>f.value=f.default)); paintSiteSearch(); ssDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='ssSave') return;
  const payload={}; SS.tabs.forEach(t=>t.fields.forEach(f=>payload[f.key]=f.value));
  const msg=$('#ssDirty');
  try{
    const r=await fetch(ssBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});
document.addEventListener('keydown', e=>{
  if(e.target.id==='ssTagInput' && e.key==='Enter'){ e.preventDefault(); $('#ssTagAdd')?.click(); }
});

/* ---------- Appearance · Mobile menu ----------
   Controls on the left, a live phone on the right. Every change repaints the
   phone immediately, so the setting is judged against the result rather than
   against its label. */
let MM=null;

function mmBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/mobile-menu'; }

async function renderMobileMenu(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Mobile menu</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mmBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MM=await r.json();
  }catch(e){
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">Could not load the mobile menu settings. <button class="btn small" onclick="renderMobileMenu()">Retry</button></div></div>`;
    return;
  }
  paintMobileMenu();
}

function mmVal(k){ const f=MM.fields.find(x=>x.key===k); return f?f.value:null; }
function mmField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-mm="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){
    const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-mm="${f.key}">
        <i id="mmv-${f.key}">${v}${o.unit||''}</i></span></div>`;
  }
  if(f.type==='select')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <select data-mm="${f.key}">${Object.entries(f.options||{}).map(([k,l])=>`<option value="${k}"${k===v?' selected':''}>${escHtml(l)}</option>`).join('')}</select></div>`;
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-mm="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escHtml(String(v))}" data-mm="${f.key}"></div>`;
}

function paintMobileMenu(){
  const byKey={}; MM.fields.forEach(f=>byKey[f.key]=f);
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Mobile menu</h2>
      <p class="mdesc" style="margin:0">The sheet that slides up from the bottom on phones.</p></div>
    <div class="mmgrid">
      <div class="mmcols">
        ${MM.groups.map(g=>`<div class="card mmcard"><div class="mmhd"><b>${escHtml(g.label)}</b><span>${escHtml(g.description)}</span></div>
          <div class="mmbody">${g.fields.map(k=>byKey[k]?mmField(byKey[k]):'').join('')}</div></div>`).join('')}
      </div>
      <div class="mmpv"><div class="mmpv-in" id="mmPhone"></div>
        <p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mmDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn" id="mmReset">Reset to defaults</button>
      <button class="btn primary" id="mmSave">Save changes</button>
    </div></div>`;
  mmPreview();
}

/* The preview mirrors the storefront markup, so what is shown is what ships. */
function mmPreview(){
  const g=mmVal, cols=g('child_columns')==='1'?'1fr':'1fr 1fr';
  const pad={compact:'8px',regular:'10px',roomy:'12px'}[g('density')]||'8px';
  const size={compact:'13px',regular:'13.5px',roomy:'14px'}[g('density')]||'13px';
  const vars=`--mm-top:${100-g('height')}%;--mm-radius:${g('radius')}px;--mm-rule:${g('rule_colour')};
    --mm-rule-w:${g('rule_width')}px;--mm-card:${g('card_bg')};--mm-parent-bg:${g('parent_bg')};
    --mm-parent-fg:${g('parent_colour')};--mm-pad:${pad};--mm-size:${size};--mm-cols:${cols};
    --mm-scrim:rgba(42,34,40,${(g('scrim')/100).toFixed(2)})`;
  const kids=['Sunscreens','Exfoliators','Toners','Eye Care','Face Masks','Face Serums','Moisturizers','Cleansers'];
  $('#mmPhone').innerHTML=`
    <div class="pvphone">
      <div class="pvscrim"></div>
      <div class="pvmenu mm-card-${g('card_style')} mm-rule-${g('rule_position')}${g('show_counts')?'':' mm-nocounts'}" style="${vars}">
        ${g('show_grab')?'<div class="mm-grab"></div>':''}
        ${g('show_close')?'<button class="mm-x">&times;</button>':''}
        ${g('show_heading')?`<div class="mm-head"><b>${escHtml(g('heading_text'))}</b></div>`:''}
        ${g('show_search')?`<div class="mm-srch"><input placeholder="${escHtml(g('search_text'))}" readonly></div>`:''}
        <div class="mm-body">
          <div class="mm-node"><button class="mm-it mm-par">Brands<span class="mm-ct">65</span><span class="mm-car">›</span></button></div>
          <div class="mm-node on"><button class="mm-it mm-par">Skincare<span class="mm-ct">8</span><span class="mm-car">›</span></button>
            <div class="mm-kid"><div class="mm-c2">${kids.map(k=>`<a class="mm-si">${k}</a>`).join('')}</div></div></div>
          <a class="mm-it">Lip Care</a><a class="mm-it hot">SUPER SALE</a><a class="mm-it">Beauty Devices</a>
          ${g('show_account')?`<div class="mm-grp">${escHtml(g('account_label'))}</div><a class="mm-it">Sign in</a><a class="mm-it">Wishlist</a>`:''}
        </div>
        ${g('show_support')?`<div class="mm-foot"><a class="mm-wa">${escHtml(g('support_text'))} · +971 58 505 2611</a></div>`:''}
      </div>
    </div>`;
}

function mmDirty(t){ const d=$('#mmDirty'); if(d){d.style.visibility='visible';d.classList.remove('ok');d.textContent=t||'Unsaved changes';} }

document.addEventListener('input', e=>{
  const el=e.target.closest('[data-mm]'); if(!el||!MM) return;
  const f=MM.fields.find(x=>x.key===el.dataset.mm); if(!f) return;
  f.value = f.type==='range' ? +el.value : el.value;
  if(f.type==='range'){ const o=f.options||{}; const out=$('#mmv-'+f.key); if(out) out.textContent=f.value+(o.unit||''); }
  if(f.type==='colour'){ const c=el.nextElementSibling; if(c) c.textContent=el.value; }
  mmPreview(); mmDirty();
});
document.addEventListener('change', e=>{
  const el=e.target.closest('select[data-mm]'); if(!el||!MM) return;
  const f=MM.fields.find(x=>x.key===el.dataset.mm); if(f){ f.value=el.value; mmPreview(); mmDirty(); }
});
document.addEventListener('click', async e=>{
  if(!MM) return;
  const tg=e.target.closest('.ectog[data-mm]');
  if(tg){ const f=MM.fields.find(x=>x.key===tg.dataset.mm);
    f.value=!f.value; tg.classList.toggle('on',f.value); tg.setAttribute('aria-checked',f.value);
    mmPreview(); mmDirty(); return; }
  if(e.target.id==='mmReset'){ MM.fields.forEach(f=>f.value=f.default); paintMobileMenu(); mmDirty('Defaults restored — not saved yet'); return; }
  if(e.target.id!=='mmSave') return;
  const payload={}; MM.fields.forEach(f=>payload[f.key]=f.value);
  const msg=$('#mmDirty');
  try{
    const r=await fetch(mmBase(),{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({settings:payload})});
    const j=await r.json();
    if(j.ok){ msg.style.visibility='visible'; msg.classList.add('ok'); msg.textContent=`Saved ${j.saved} settings — live now`;
      setTimeout(()=>{msg.classList.remove('ok');msg.textContent='Unsaved changes';msg.style.visibility='hidden';},2600); }
    else { msg.style.visibility='visible'; msg.textContent=j.error||'Could not save.'; }
  }catch(err){ msg.style.visibility='visible'; msg.textContent='Could not save — check your connection.'; }
});

/* The mock Modules screen that used to live here is gone.
   It rendered a hard-coded MODULES list with toggles held in a JavaScript
   variable — nothing was saved and nothing reached the storefront. The real
   one is `renderModules` further up, backed by ModuleRegistry and the
   /admin-api/modules endpoint.

   It mattered that this went: two functions of the same name in one file
   means the later definition wins, so while this was here the real screen
   was defined and then immediately overwritten. */

/* ---------- Theme ---------- */
function renderTheme(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>K-Beauty Bliss Theme</h2><p>One place for all design, layout and storefront settings. The storefront keeps its rose identity; this is the engine that controls it.</p></div>
    <div class="card pad" style="margin-bottom:18px">
      <div class="between"><b style="font-size:14px">Brand tokens</b><span class="pill grey">storefront palette</span></div>
      <div class="swatches" style="margin-top:14px">
        ${['#E0567B|Rose','#C13E63|Deep','#A82F53|Ink rose','#FFF0F4|Soft','#FCE0E8|Blush','#BE8E2E|Gold','#2A2228|Ink'].map(s=>{const[c,n]=s.split('|');return `<div class="sw" style="background:${c}"><span>${n}</span></div>`}).join('')}
      </div>
      <div style="height:14px"></div>
    </div>
    <div class="sec-title">Theme areas</div>
    <div class="tcards">
      ${[['Header & Mega Menu','Logo, nav, visual mega-menu builder','<path d="M3 5h18M3 5v4h18V5M7 13h10M7 17h6"/>'],
        ['Homepage','Sections, hero, rails, promos','<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>'],
        ['Product Page','Gallery, swatches, sticky add-to-cart','<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h4"/>'],
        ['Shop & Filters','AJAX grid, off-canvas filters, search','<path d="M3 5h18M6 12h12M10 19h4"/>'],
        ['Cart & Checkout','Cart panel, progress checkout','<circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/><path d="M2 3h3l2.5 13h10L20 7H6"/>'],
        ['Typography','Fonts, scale, weights','<path d="M4 7V5h16v2M9 19h6M12 5v14"/>'],
        ['Colours','Link & accent palette, tokens','<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>'],
        ['Performance','Load only what is used','<path d="M5 13c-1.5 1.5-2 5-2 5s3.5-.5 5-2M14.5 4.5C18 3 21 3 21 3s0 3-1.5 6.5C18 13 14 16 12 17l-5-5c1-2 4-6 7.5-7.5z"/>']
      ].map(t=>`<div class="tcard" onclick="toast('${t[0]} — builder opens in Phase 2')"><div class="ti">${ic(t[2])}</div><b>${t[0]}</b><p>${t[1]}</p></div>`).join('')}
    </div>
  </div>`;
}

/* ---------- Users ---------- */
function renderUsers(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="between" style="margin-bottom:16px"><div class="page-head" style="margin:0"><h2>Users & Roles</h2><p>Staff accounts and what each can do. Customer accounts (with imported logins) live in the Customers module.</p></div><button class="btn" onclick="toast('Invite flow — Phase 0 build')">${ic('<path d="M12 5v14M5 12h14"/>')} Invite user</button></div>
    <div class="card"><table>
      <thead><tr><th>User</th><th>Role</th><th>2FA</th><th>Status</th></tr></thead>
      <tbody>
        ${urow('Rafi','RA','Owner','green','On','green','Active')}
        ${urow('Store Manager','SM','Manager','amber','Off','green','Active')}
        ${urow('Support Agent','SA','Support','amber','Off','grey','Invited')}
      </tbody></table></div>
    <div class="sec-title">Roles</div>
    <div class="mod-grid">
      ${['Owner|Full access to everything','Manager|Catalog, orders, customers, marketing','Support|Orders & customers (read + reply)','Content Editor|Pages, blog, media'].map(r=>{const[n,d]=r.split('|');return `<div class="mod"><div class="mic">${ic(I.shield)}</div><div><div class="mname">${n}</div><div class="mdesc">${d}</div></div></div>`}).join('')}
    </div>
  </div>`;
}
const urow=(n,av,role,r2,f,sc,st)=>`<tr><td><div class="row"><div class="avatar" style="width:30px;height:30px">${av}</div><b>${n}</b></div></td><td>${role}</td><td><span class="pill ${r2}">${f}</span></td><td><span class="pill ${sc}">${st}</span></td></tr>`;

/* ---------- Settings ---------- */
function renderSettings(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Settings</h2><p>Global configuration. Each module also keeps its own settings — this is the platform-level base.</p></div>
    <div class="tcards">
      ${[['Store details','Name, logo, contact, address','<path d="M3 9l9-6 9 6v11a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>'],
        ['Regional','Country, currency, units, timezone','<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>'],
        ['Localisation','Languages, RTL, translations','<path d="M4 5h7M9 3v2c0 4-2 7-5 8M5 9c0 3 3 5 6 6M13 19l4-9 4 9M14.5 16h5"/>'],
        ['Notifications','Admin alerts & channels','<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>'],
        ['Security','Sessions, passwords, login rules','<path d="M12 2 4 5v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V5z"/>'],
        ['API & Keys','Encrypted credentials store','<path d="M21 2l-2 2m-7 7a5 5 0 1 1-7 7 5 5 0 0 1 7-7zM15 7l4 4"/>']
      ].map(t=>`<div class="tcard" onclick="toast('${t[0]} settings — Phase 0 build')"><div class="ti">${ic(t[2])}</div><b>${t[0]}</b><p>${t[1]}</p></div>`).join('')}
    </div>
  </div>`;
}

/* ---------- Debug & Monitor ---------- */
function renderDebug(){
  go._cur='debug';
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Debug & Monitor</h2><p>The site's eyes. Catches errors across the whole stack and can hand you a report written for Claude — so a breakage becomes an instant fix.</p></div>
    <div class="card pad" style="margin-bottom:16px">
      <div class="between"><b style="font-size:14px">Service health</b><span class="pill amber"><span class="d"></span>2 need setup</span></div>
      <div class="health" style="margin-top:14px">
        ${hrow('green','App server','120ms avg')}${hrow('green','Database','WAL · 4ms reads')}
        ${hrow('green','Storefront API','operational')}${hrow('amber','Stripe','keys not added')}
        ${hrow('amber','Tabby / Tamara','not configured')}${hrow('amber','Email / SMTP','not configured')}
      </div>
    </div>
    <div class="between" style="margin-bottom:12px"><b style="font-size:14px">Error console</b><div class="row"><span class="pill grey">grouped</span><span class="pill red">3 open</span></div></div>
    <div class="errs">
      ${err('warn','PaymentGateway: Stripe keys missing','Payments','sandbox',2,'5m ago')}
      ${err('err','Image 404 on import preview','Catalog','sandbox',1,'12m ago')}
      ${err('warn','SMTP not configured — email queued','Email/SMTP','sandbox',5,'18m ago')}
    </div>
    <div class="card pad" style="margin-top:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div style="flex:1;min-width:240px"><b style="font-size:13.5px">Found a bug on live?</b><div style="font-size:12px;color:var(--ink-soft);margin-top:3px">Generate a complete, secret-redacted diagnostic written for Claude, then send it over for an immediate fix.</div></div>
      <button class="btn" onclick="reportModal()">${ic(I.copy)} Copy report for Claude</button>
    </div>
  </div>`;
}
const err=(sev,title,mod,env,cnt,when)=>`<div class="err"><span class="sev ${sev}">${sev==='crit'?'critical':sev}</span><div style="flex:1;min-width:0"><div class="etitle">${title}</div><div class="emeta"><span>${ic('<rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/>')} ${mod}</span><span>env: ${env}</span><span>×${cnt}</span><span>${when}</span></div></div><button class="btn ghost sm" onclick="reportModal()">Report</button></div>`;

function reportModal(){
  openModal(`<div class="modal-h">${ic(I.copy)}<b>Diagnostic report for Claude</b><button class="x" onclick="closeModal()">${ic('<path d="M18 6 6 18M6 6l12 12"/>')}</button></div>
  <div class="modal-b">
    <p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">Secrets are redacted automatically. Copy this and send it to Claude for an immediate fix.</p>
    <div class="report"><span class="c">// KBB diagnostic · auto-generated</span>
<span class="k">error</span>: <span class="v">"PaymentGateway: Stripe secret key missing"</span>
<span class="k">severity</span>: <span class="v">"warning"</span>
<span class="k">module</span>: <span class="v">"payments"</span>
<span class="k">route</span>: <span class="v">"POST /api/payments/intent"</span>
<span class="k">environment</span>: <span class="v">"sandbox"</span>
<span class="k">version</span>: <span class="v">"0.1.0"</span>
<span class="k">file</span>: <span class="v">"modules/payments/api.js:48"</span>
<span class="k">breadcrumb</span>: [<span class="v">"checkout.start"</span>, <span class="v">"payment.select:stripe"</span>, <span class="v">"intent.create"</span>]
<span class="k">request</span>: { amount: 549.78, currency: <span class="v">"AED"</span>, key: <span class="c">"[redacted]"</span> }
<span class="k">stack</span>: <span class="c">at createIntent (api.js:48) › at route (api.js:12)</span>
<span class="k">suggestion</span>: <span class="v">"add Stripe secret key in Payments settings"</span></div>
    <div class="row" style="margin-top:16px;justify-content:flex-end;gap:9px">
      <button class="btn ghost" onclick="closeModal()">Close</button>
      <button class="btn" onclick="closeModal();toast('Report copied — paste it to Claude')">${ic(I.copy)} Copy report</button>
    </div>
  </div>`);
}

/* ---------- Sandbox & Deploy ---------- */
let deployed=false;
function renderSandbox(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Sandbox & Deploy</h2><p>Nothing risky touches the live store. Changes land in Sandbox, get checked, and only deploy when everything is green — with one-click rollback.</p></div>
    <div class="envcards">
      <div class="envcard live"><div class="et">${ic('<circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/>')} Live</div>
        <div class="ev">Version <b id="liveVer">${deployed?'0.1.0':'0.0.0'}</b></div>
        <div class="ev">Last deploy: <b>${deployed?'just now':'—'}</b></div>
        <div class="ev" style="margin-top:8px"><span class="pill green"><span class="d"></span>Stable</span></div>
      </div>
      <div class="envcard sand"><div class="et">${ic(I.sandbox)} Sandbox</div>
        <div class="ev">Change set: <b>Phase 0 · Foundation</b></div>
        <div class="ev">Clone of live data: <b>yes</b></div>
        <div class="ev" style="margin-top:8px"><span class="pill amber"><span class="d"></span>${deployed?'Clean':'Ready to deploy'}</span></div>
      </div>
    </div>
    <div class="card pad">
      <div class="between"><b style="font-size:14px">Pre-flight checks</b><span class="pill green" id="checkSum"><span class="d"></span>5 / 5 passed</span></div>
      <div class="checks" style="margin-top:8px">
        ${chk('green','Syntax & JS validation','no errors')}
        ${chk('green','Conflict scan','no table / route / style clashes')}
        ${chk('green','Migration dry-run','8 tables · non-destructive')}
        ${chk('green','Smoke tests','admin, dashboard, nav OK')}
        ${chk('green','Debug scan','0 critical errors')}
      </div>
      <div class="diff">${ic('<path d="M6 3v12M6 15a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM18 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM18 9v6a6 6 0 0 1-6 6"/>')}&nbsp;<span><b class="plus">+8</b> tables</span><span><b class="plus">+34</b> files</span><span><b>8</b> core modules</span><span><b>0</b> conflicts</span></div>
      <div class="row" style="justify-content:flex-end;gap:10px;margin-top:4px">
        <button class="btn ghost" onclick="toast('Showing full diff — Phase 0 build')">View full diff</button>
        <button class="btn danger" ${deployed?'':'disabled'} onclick="rollback()">${ic('<path d="M3 7v6h6M3 13a9 9 0 1 0 3-7"/>')} Rollback</button>
        <button class="btn" ${deployed?'disabled':''} id="deployBtn" onclick="deploy()">${ic(I.rocket||I.check)} Deploy to Live</button>
      </div>
    </div>
    <div class="banner" style="margin:18px 0 0;background:var(--accent-soft);border-color:#bfe6cf;color:var(--accent-ink)">${ic('<path d="M12 2 4 5v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V5z"/><path d="M9 12l2 2 4-4"/>')}<div>Every deploy auto-backs-up the live database first and keeps the previous version for instant rollback.</div></div>
  </div>`;
}
const chk=(s,n,m)=>`<div class="check"><div class="ci ${s}">${ic(I.check)}</div><b>${n}</b><small>${m}</small></div>`;
function deploy(){
  const b=$('#deployBtn');b.disabled=true;b.innerHTML=ic('<circle cx="12" cy="12" r="9"/>')+' Deploying…';
  setTimeout(()=>{deployed=true;toast('Deployed to Live ✓ · backup saved');renderSandbox();},1100);
}
function rollback(){deployed=false;toast('Rolled back to previous version');renderSandbox();}

/* ---------- placeholder (future modules) ---------- */
function renderPlaceholder(id){
  const map={'p-catalog':['Catalog','products, categories, brands, attributes','P1'],'p-orders':['Orders','orders, refunds, invoices','P3'],'p-cust':['Customers','accounts, addresses, reviews','P3'],'p-mkt':['Growth & Marketing','coupons, loyalty, routines, video, WhatsApp','P2–P4'],'p-content':['Content & Pages','page builder, blog, forms','P5']};
  const m=map[id];$('#crumb').textContent='Store';$('#ptitle').textContent=m[0];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  $('#content').innerHTML=`<div class="wrap"><div class="ph">
    <div class="pic">${ic(I.modules)}</div>
    <h3>${m[0]} isn't installed yet</h3>
    <p>This area is part of the plan and arrives in <b>Phase ${m[2]}</b> (${m[1]}). Every feature is a module you install and toggle from the Modules screen — that's the pluggable foundation we just built.</p>
    <button class="btn" onclick="go('modules')">Open Modules →</button>
  </div></div>`;
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ---------- Store screens that load as standalone files (iframe) ---------- */
const FRAME_SRC={'orders':'kbb-admin-orders.html','payments':'kbb-admin-payments.html','analytics':'kbb-admin-analytics.html','store-settings':'kbb-admin-settings.html','customers':'kbb-admin-customers.html','quiz-leads':'kbb-admin-quiz-leads.html','seo':'kbb-admin-seo.html','blog':'kbb-admin-blog.html','posts':'kbb-admin-blog.html','htmlblocks':'kbb-admin-blocks.html','media':'kbb-admin-media.html'};
function renderFrame(id,query){
  cur=id;
  const t=TITLES[id]||['Store',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  $('#content').innerHTML=`<iframe src="${FRAME_SRC[id]}${query?('?'+query):''}" title="${t[1]}" style="width:100%;height:calc(100vh - 116px);border:0;display:block;background:var(--bg)"></iframe>`;
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}
function goTab(id,tab){ if(FRAME_SRC[id])return renderFrame(id,'tab='+encodeURIComponent(tab)); return go(id); }
window.goTab=goTab;

/* ---------- Reviews module (screens load into the content area) ---------- */
const REV_SRC={'rev-all':'kbb-admin-reviews.html','rev-add':'kbb-admin-bulkadd.html','rev-likes':'kbb-admin-bulklikes.html','rev-assign':'kbb-admin-assign.html','rev-io':'kbb-admin-exportimport.html','rev-badge':'kbb-admin-badgethemes.html','rev-capsule':'kbb-capsule-editor.html','rev-settings':'kbb-admin-reviews-settings.html'};
function renderReviewFrame(id){
  cur=id;
  const t=TITLES[id]||['Reviews',id];$('#crumb').textContent=t[0];$('#ptitle').textContent=t[1];
  $$('.side .nav-item').forEach(b=>b.classList.toggle('on',b.dataset.go===id));syncNavOpen(id);
  $('#content').innerHTML=`<iframe src="${REV_SRC[id]}" title="${t[1]}" style="width:100%;height:calc(100vh - 116px);border:0;display:block;background:var(--bg)"></iframe>`;
  $('#content').scrollTop=0;$('#side').classList.remove('open');
}

/* ===================== CATALOG ===================== */
const CAT_PRODUCTS=[
 ['Shark CryoGlow Under-Eye Cooling + LED Mask','Shark','SHK-CRYO','Beauty Devices',2450,null,8],
 ['Heartleaf 77% Soothing Toner','Anua','ANU-77T','Toners',89,null,142],
 ['Low pH Good Morning Gel Cleanser','COSRX','CSX-LPH','Cleansers',49,39,88],
 ['Advanced Snail 96 Mucin Power Essence','COSRX','CSX-S96','Essences',79,null,24],
 ['Glow Deep Serum Rice + Arbutin','Beauty of Joseon','BOJ-GLW','Serums',75,null,0],
 ['Relief Sun Rice + Probiotics SPF50+','Beauty of Joseon','BOJ-SPF','Sun Care',65,55,210],
 ['Dive-In Low Molecular HA Serum','Torriden','TOR-DHA','Serums',69,null,65],
 ['PDRN Pink Collagen Capsule Cream','Medicube','MED-PDRN','Moisturisers',129,99,12],
 ['No.5 Vitamin C Serum','Numbuzin','NMB-N5','Serums',99,null,47],
 ['AHA-BHA-PHA 30 Days Miracle Serum','Some By Mi','SBM-30D','Serums',89,null,5],
 ['Centella Ampoule','SKIN1004','SK1-CEN','Ampoules',79,null,91],
 ['Heartleaf Quercetinol Cleansing Oil','Anua','ANU-OIL','Cleansers',79,null,33],
 ['Collagen Night Wrapping Mask','Medicube','MED-NWM','Masks',99,null,18],
 ['Water-Fit Sun Serum SPF50+','SKIN1004','SK1-SUN','Sun Care',72,null,60],
 ['Hyaluronic Acid Watery Sun Gel','Isntree','ISN-SUN','Sun Care',79,null,0]
];
const CAT_DRAFT=new Set(['SK1-CEN','ISN-SUN']);
const CAT_CATEGORIES=[['Beauty Devices',11],['Cleansers',38],['Toners',26],['Essences',19],['Serums',64],['Ampoules',12],['Moisturisers',41],['Sun Care',23],['Masks',31],['Eye Care',9],['Sets & Bundles',15]];
const CAT_BRANDS=[['Anua',42],['COSRX',58],['Beauty of Joseon',37],['Medicube',29],['Torriden',18],['Numbuzin',21],['Some By Mi',24],['SKIN1004',16],['Isntree',14],['MEDIHEAL',26],['Round Lab',12],["Dr.Althea",9],['Shark',6]];
const PE_CATS=['Beauty Devices','Hair Care Silk','Hair Tools','Medicube','Makeup','Best Sellers','Under AED 54','Cleansers','Toners','Serums','Sun Care','Masks','Uncategorized'];
const CAT_ATTRS=[['Skin Type',['Dry','Oily','Combination','Sensitive','Normal']],['Concern',['Hydration','Brightening','Acne','Anti-aging','Soothing','Pores']],['Size',['30ml','50ml','100ml','150ml','200ml']],['Finish',['Dewy','Matte','Natural']]];
const TCOL=['#15a85a','#3f6fe0','#7b6cf0','#e0922f','#e0567b','#2bb3a3','#c13e63','#4b5a72'];
const tcol=s=>TCOL[[...s].reduce((a,c)=>a+c.charCodeAt(0),0)%TCOL.length];
const initials=s=>s.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
const stockPill=n=>n===0?`<span class="pill red"><span class="d"></span>Out</span>`:n<=15?`<span class="pill amber"><span class="d"></span>Low · ${n}</span>`:`<span class="pill green"><span class="d"></span>${n}</span>`;

let catTab='products',catFilter='all',catSel=new Set();
let catSOopen=false,catPerPage=250;
function renderCatalog(){
  const tabs=['products','categories','brands','attributes','inventory','reorder'];
  const lbl={products:'Products',categories:'Categories',brands:'Brands',attributes:'Attributes',inventory:'Inventory',reorder:'Reorder'};
  $('#content').innerHTML=`<div class="wrap">
    <div class="between" style="margin-bottom:8px"><div class="page-head" style="margin:0"><h2>Catalog</h2><p>Your products and how they're organised. Reorder works inside any category.</p></div>
      <div class="row" style="gap:8px">${catTab==='products'?`<button class="btn" onclick="openProduct(-1)">${ic('<path d="M12 5v14M5 12h14"/>')} Add product</button>`:''}</div></div>
    <div class="subtabs">${tabs.map(t=>`<button class="subtab${t===catTab?' on':''}" data-t="${t}">${lbl[t]}</button>`).join('')}</div>
    <div id="catBody"></div></div>`;
  $$('#content .subtab').forEach(b=>b.onclick=()=>{catTab=b.dataset.t;renderCatalog();});
  ({products:catProducts,categories:catCategories,brands:catBrands,attributes:catAttributes,inventory:catInventory,reorder:catReorder}[catTab])();
}
const PCOLDEF=[['image','Image'],['sku','SKU'],['stock','Stock'],['price','Price'],['categories','Categories'],['featured','Featured'],['date','Date added'],['brand','Brand'],['orders','Orders']];
const pcColsDefault={image:true,sku:true,stock:true,price:true,categories:true,featured:true,date:true,brand:true,orders:true};
let pCols=JSON.parse(localStorage.getItem('kbb_pcols')||'null')||{...pcColsDefault};
function catSOToggle(){catSOopen=!catSOopen;pPaint();}
let pTab=null,pScopes=null,pData=null,pLocal=null,pPage=1,pSearch='',pFilter='all',pSort='newest',pSel=new Set(),pBusy=false,pPerPage=+(localStorage.getItem('kbb_products_pp')||50),pPpCustomMode=false;
const PP_PRESETS=[25,50,100,200];
function pApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/catalog/products'; }
function redirectsApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/redirects'; }
function schemaInspectApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/schema-inspect'; }
function catalogueAuditApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/catalogue-audit'; }
function pFmtMoney(n){ return n==null ? '' : 'AED '+(Math.round(n*100)/100).toLocaleString(); }

async function catProducts(){
  const body=$('#catBody');
  body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading products…</p>`;
  await pLoad();
}

async function pLoad(){
  const body=$('#catBody');
  const listArea=$('#pListArea');
  if(listArea) listArea.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading…</p>`;
  try{
    const q=new URLSearchParams({page:pPage, search:pSearch, filter:pFilter, sort:pSort, per_page:pPerPage});
    const r=await fetch(pApiBase()+'?'+q,{credentials:'same-origin',headers:{Accept:'application/json'}});
    pData=await r.json();
  }catch(e){ body.innerHTML=`<p style="padding:24px;color:var(--sale)">Could not load products — ${escHtml(e.message)}</p>`; return; }
  pPerPage=pData.per_page;
  pPaint();
}

function pPaint(){
  const body=$('#catBody');
  const d=pData;
  const cols=PCOLDEF.filter(c=>c[0]!=='image'&&pCols[c[0]]);
  const ppIsPreset=PP_PRESETS.includes(pPerPage) && !pPpCustomMode;
  const filters=[['all','All',d.counts.all],['published','Published',d.counts.published],['draft','Draft',d.counts.draft],['low','Low stock',d.counts.low],['out','Out of stock',d.counts.out]];

  body.innerHTML=`
    ${catSOopen?`<div class="card pad" style="margin-bottom:14px">
      <div class="pe-h" style="margin-bottom:11px">Columns</div>
      <div class="so-cols">${PCOLDEF.map(c=>`<label class="so-col"><span class="cbx${pCols[c[0]]?' on':''}" data-pcol="${c[0]}">${ic(I.check)}</span> ${c[1]}</label>`).join('')}</div>
      <div style="margin-top:14px"><button class="btn ghost sm" id="pColsReset">Reset to default</button></div>
    </div>`:''}
    <div class="toolbar" style="flex-wrap:wrap;gap:10px">
      <button class="btn ghost" id="pScreenOpts">${ic('<path d="M4 6h16M7 12h10M10 18h4"/>')} Screen Options ${catSOopen?'▴':'▾'}</button>
      <div class="search" style="min-width:220px">${ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>')}<input id="pSearch" placeholder="Search ${d.total} products by name, brand or SKU…" value="${escHtml(pSearch)}"></div>
      <select class="inp" id="pSort">
        <option value="newest"${pSort==='newest'?' selected':''}>Sort: Newest</option>
        <option value="name"${pSort==='name'?' selected':''}>Name A–Z</option>
        <option value="price_desc"${pSort==='price_desc'?' selected':''}>Price high→low</option>
        <option value="stock_asc"${pSort==='stock_asc'?' selected':''}>Stock low→high</option>
      </select>
    </div>
    <div class="chips" style="margin-bottom:6px">${filters.map(c=>`<button class="chip${pFilter===c[0]?' on':''}" data-pf="${c[0]}">${c[1]} <span style="opacity:.6">${c[2]}</span></button>`).join('')}</div>
    <div class="toolbar" style="margin-bottom:10px">
      <span style="font-size:12.5px;color:var(--ink-soft)">Show</span>
      <select class="inp" id="pPerPage" style="width:100px">
        ${PP_PRESETS.map(n=>`<option value="${n}"${n===pPerPage&&ppIsPreset?' selected':''}>${n} per page</option>`).join('')}
        <option value="custom"${ppIsPreset?'':' selected'}>Custom…</option>
      </select>
      ${ppIsPreset?'':`<input class="inp" id="pPerPageCustom" type="number" min="10" max="500" value="${pPerPage}" style="width:80px" placeholder="10–500">`}
      <span style="font-size:11px;color:var(--ink-soft)">10–500</span>
      <div style="flex:1"></div>
      <span style="font-size:12px;color:var(--ink-soft)">${d.total} products · page ${d.page} of ${d.last_page}</span>
    </div>
    <div id="pBulkWrap"></div>
    <div id="pListArea">${pRenderTable(d.products, cols)}</div>
    <div class="pager" style="margin-top:14px">
      <span>Showing ${d.products.length ? ((d.page-1)*d.per_page+1) : 0}–${(d.page-1)*d.per_page+d.products.length} of ${d.total}</span>
      <div class="row" style="gap:8px;align-items:center">
        <button class="btn ghost sm" style="white-space:nowrap" ${d.page<=1?'disabled':''} id="pPrev">‹ Prev</button>
        <button class="btn ghost sm" style="white-space:nowrap" ${d.page>=d.last_page?'disabled':''} id="pNext">Next ›</button>
      </div>
    </div>`;

  $('#pScreenOpts').onclick=catSOToggle;
  if(catSOopen){
    $$('#catBody .so-col .cbx[data-pcol]').forEach(c=>c.onclick=()=>{
      const k=c.dataset.pcol; pCols[k]=!pCols[k]; localStorage.setItem('kbb_pcols', JSON.stringify(pCols)); pPaint();
    });
    const resetBtn=$('#pColsReset'); if(resetBtn) resetBtn.onclick=()=>{ pCols={...pcColsDefault}; localStorage.setItem('kbb_pcols', JSON.stringify(pCols)); pPaint(); };
  }
  let searchT;$('#pSearch').oninput=e=>{clearTimeout(searchT);const v=e.target.value;searchT=setTimeout(()=>{pSearch=v;pPage=1;pLoad();},300);};
  $('#pSort').onchange=e=>{pSort=e.target.value;pPage=1;pLoad();};
  $$('#catBody .chip[data-pf]').forEach(c=>c.onclick=()=>{pFilter=c.dataset.pf;pPage=1;pLoad();});
  $('#pPerPage').onchange=e=>{
    if(e.target.value==='custom'){ pPpCustomMode=true; pPaint(); setTimeout(()=>$('#pPerPageCustom')?.focus(),0); return; }
    pPpCustomMode=false; pPerPage=+e.target.value; localStorage.setItem('kbb_products_pp', pPerPage); pPage=1; pLoad();
  };
  const ppCustom=$('#pPerPageCustom');
  if(ppCustom){
    const applyCustom=()=>{
      let v=parseInt(ppCustom.value,10);
      if(!v||v<10) v=10; if(v>500) v=500;
      pPerPage=v; localStorage.setItem('kbb_products_pp', v); pPage=1; pLoad();
    };
    ppCustom.onkeydown=e=>{ if(e.key==='Enter'){ applyCustom(); } };
    ppCustom.onblur=applyCustom;
  }
  $('#pPrev').onclick=()=>{ if(d.page>1){pPage--;pLoad();} };
  $('#pNext').onclick=()=>{ if(d.page<d.last_page){pPage++;pLoad();} };
  $$('#catBody [data-pfeat]').forEach(el=>el.onclick=async()=>{
    const id=+el.dataset.pfeat;
    try{
      const r=await fetch(pApiBase()+'/'+id+'/toggle-featured',{method:'POST',credentials:'same-origin',
        headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
      const j=await r.json();
      if(j.ok){ el.textContent=j.featured?'★':'☆'; el.style.color=j.featured?'#e0a11e':'var(--border)'; }
    }catch(e){ toast('Could not update — check your connection.'); }
  });
  $$('#catBody [data-pedit]').forEach(b=>b.onclick=()=>toast('Product editing isn\'t built yet — this list is real, editing is next.'));
}

function pRenderTable(products, cols){
  if(!products.length) return `<p style="padding:24px;color:var(--ink-soft)">No products match this view.</p>`;
  return `<div class="card" style="overflow:auto"><table style="table-layout:fixed;width:100%">
    <colgroup><col style="width:34%"><col style="width:12%">${cols.map(c=>{
      const w={sku:'10%',stock:'11%',price:'12%',categories:'14%',featured:'8%',date:'11%',brand:'12%',orders:'9%'}[c[0]]||'10%';
      return `<col style="width:${w}">`;
    }).join('')}<col style="width:9%"></colgroup>
    <thead><tr><th>Product</th><th>Status</th>${cols.map(c=>`<th style="${['price','stock','orders'].includes(c[0])?'text-align:right':''}">${c[1]}</th>`).join('')}<th></th></tr></thead>
    <tbody>${products.map(p=>`<tr>
      <td><div class="row" style="min-width:0">${pCols.image?`<span class="pthumb" style="background:${tcol(p.brand||p.name)};flex-shrink:0">${initials(p.brand||p.name)}</span>`:''}
        <div style="min-width:0"><div class="pname" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.name)}</div><div class="pbrand">${escHtml(p.brand||'')}</div></div></div></td>
      <td><span class="pill ${p.status==='publish'?'green':'grey'}">${p.status==='publish'?'Published':(p.status==='draft'?'Draft':'Private')}</span></td>
      ${cols.map(c=>pCellFor(c[0],p)).join('')}
      <td><button class="btn ghost sm" data-pedit="${p.id}">Edit</button></td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

function pCellFor(k,p){
  switch(k){
    case 'sku':return `<td style="font-family:var(--mono);font-size:11px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.sku||'')}</td>`;
    case 'stock':return `<td style="text-align:right">${p.stock==null?`<span class="pill ${p.stock_status==='outofstock'?'red':'green'}">${p.stock_status==='outofstock'?'Out':'In stock'}</span>`:stockPill(p.stock)}</td>`;
    case 'price':return `<td class="price" style="text-align:right">${p.sale_price!=null?`<b>${pFmtMoney(p.sale_price)}</b><br><s style="color:var(--ink-soft);font-size:11px">${pFmtMoney(p.price)}</s>`:`<b>${pFmtMoney(p.price)}</b>`}</td>`;
    case 'categories':return `<td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.categories.join(', '))||'<span style="color:var(--ink-soft)">—</span>'}</td>`;
    case 'featured':return `<td style="font-size:15px;color:${p.featured?'#e0a11e':'var(--border)'};cursor:pointer" data-pfeat="${p.id}">${p.featured?'★':'☆'}</td>`;
    case 'date':return `<td style="font-size:11.5px;color:var(--ink-soft)">${p.date||''}</td>`;
    case 'brand':return `<td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.brand||'')}</td>`;
    case 'orders':return `<td style="text-align:right;font-size:11.5px;color:var(--ink-soft)">${p.orders_count}</td>`;
    default:return '<td></td>';
  }
}
function catCategories(){
  $('#catBody').innerHTML=`<div class="between" style="margin-bottom:12px"><span class="pill grey">${CAT_CATEGORIES.length} categories</span><button class="btn sm" onclick="toast('Add category (preview)')">${ic('<path d="M12 5v14M5 12h14"/>')} Add category</button></div>
  <div class="card"><table><thead><tr><th>Category</th><th>Products</th><th>Slug</th><th></th></tr></thead><tbody>
  ${CAT_CATEGORIES.map(c=>`<tr><td><b>${c[0]}</b></td><td>${c[1]}</td><td style="font-family:var(--mono);font-size:11.5px;color:var(--ink-soft)">/${c[0].toLowerCase().replace(/[^a-z]+/g,'-')}</td><td><button class="btn ghost sm" onclick="toast('Edit category (preview)')">Edit</button></td></tr>`).join('')}
  </tbody></table></div>`;
}
function catBrands(){
  $('#catBody').innerHTML=`<div class="between" style="margin-bottom:12px"><span class="pill grey">${CAT_BRANDS.length}+ brands</span><button class="btn sm" onclick="toast('Add brand (preview)')">${ic('<path d="M12 5v14M5 12h14"/>')} Add brand</button></div>
  <div class="mod-grid">${CAT_BRANDS.map(b=>`<div class="mod"><span class="pthumb" style="background:${tcol(b[0])};width:40px;height:40px">${initials(b[0])}</span><div><div class="mname">${b[0]}</div><div class="mdesc">${b[1]} products</div></div><div class="mod-r"><button class="btn ghost sm" onclick="toast('Edit brand (preview)')">Edit</button></div></div>`).join('')}</div>`;
}
function catAttributes(){
  $('#catBody').innerHTML=`<div class="between" style="margin-bottom:12px"><span class="pill grey">${CAT_ATTRS.length} attributes</span><button class="btn sm" onclick="toast('Add attribute (preview)')">${ic('<path d="M12 5v14M5 12h14"/>')} Add attribute</button></div>
  ${CAT_ATTRS.map(a=>`<div class="card pad" style="margin-bottom:12px"><div class="between"><b style="font-size:13.5px">${a[0]}</b><button class="btn ghost sm" onclick="toast('Edit terms (preview)')">Edit terms</button></div><div class="tagchips" style="margin-top:11px">${a[1].map(t=>`<span class="tagchip">${t}</span>`).join('')}</div></div>`).join('')}`;
}
let invFilter='all',invSearch='',invDraft={};
const invStatus=q=>q===0?'out':q<=15?'low':'in';
const invDirty=()=>Object.keys(invDraft).filter(k=>invDraft[k]!==CAT_PRODUCTS[k][6]).length;
function invList(){let l=CAT_PRODUCTS.map((p,i)=>i);
  if(invSearch){const q=invSearch.toLowerCase();l=l.filter(i=>{const p=CAT_PRODUCTS[i];return p[0].toLowerCase().includes(q)||p[2].toLowerCase().includes(q)||p[1].toLowerCase().includes(q);});}
  if(invFilter!=='all')l=l.filter(i=>invStatus(invDraft[i]??CAT_PRODUCTS[i][6])===invFilter);
  return l;}
function renderInvSaveBar(){const bar=$('#invSaveBar');if(!bar)return;const n=invDirty();
  bar.innerHTML=n?`<div class="invsave"><span class="pill amber" style="font-size:11px"><span class="d"></span>${n}</span> unsaved change${n>1?'s':''} across the catalogue<div style="flex:1"></div><button class="btn ghost sm" onclick="invDiscard()">Discard</button><button class="btn" onclick="invSave()">${ic(I.check)} Save all changes</button></div>`:'';}
function invBulk(mode){const raw=$('#invSetVal').value;const v=parseInt(raw,10);if(isNaN(v)){toast('Enter a quantity first');return;}
  invList().slice(0,catPerPage).forEach(i=>{const base=invDraft[i]??CAT_PRODUCTS[i][6];invDraft[i]=mode==='set'?v:mode==='inc'?base+v:Math.max(0,base-v);});
  catInventory();toast('Applied to visible rows');}
function invSave(){Object.keys(invDraft).forEach(k=>{CAT_PRODUCTS[k][6]=invDraft[k];});const n=Object.keys(invDraft).length;invDraft={};catInventory();toast(`Saved ${n} product${n>1?'s':''} (preview)`);}
function invDiscard(){invDraft={};catInventory();toast('Changes discarded');}
function catInventory(){
  const low=CAT_PRODUCTS.filter(p=>p[6]>0&&p[6]<=15).length,out=CAT_PRODUCTS.filter(p=>p[6]===0).length;
  const list=invList(),page=list.slice(0,catPerPage);
  $('#catBody').innerHTML=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:13px">Edit stock inline for any number of products, then commit everything with one <b>Save</b>. Built for large catalogues — search to narrow, bulk-set the visible rows, and only changed rows are sent to the server.</p>
  <div class="toolbar"><div class="search">${ic('<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>')}<input id="invSearch" placeholder="Search by name, brand or SKU…" value="${invSearch}"></div>
    <div class="invbulk"><input class="inp" id="invSetVal" style="width:72px" placeholder="qty"><button class="btn ghost sm" onclick="invBulk('set')">Set visible</button><button class="btn ghost sm" onclick="invBulk('inc')">+ Add</button><button class="btn ghost sm" onclick="invBulk('dec')">− Sub</button></div></div>
  <div class="chips" style="margin-bottom:14px">${[['all','All'],['in','In stock'],['low','Low'],['out','Out']].map(c=>`<button class="chip${invFilter===c[0]?' on':''}" data-if="${c[0]}">${c[1]}</button>`).join('')}<span class="pill amber" style="margin-left:6px"><span class="d"></span>${low} low</span><span class="pill red"><span class="d"></span>${out} out</span></div>
  <div class="card" style="overflow:auto"><table><thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Current</th><th>New stock</th><th>Status</th></tr></thead><tbody>
  ${page.map(i=>{const p=CAT_PRODUCTS[i];const val=invDraft[i]??p[6];const dirty=invDraft[i]!==undefined&&invDraft[i]!==p[6];
    return `<tr class="${dirty?'invdirty':''}"><td><div class="row"><span class="pthumb" style="background:${tcol(p[1])};width:30px;height:30px;font-size:10px">${initials(p[1])}</span><b style="font-size:12.5px">${p[0]}</b></div></td>
    <td style="font-family:var(--mono);font-size:11px;color:var(--ink-soft)">${p[2]}</td><td>${p[3]}</td>
    <td style="color:var(--ink-soft)">${p[6]}</td>
    <td><input class="inp invq" data-i="${i}" style="width:84px;padding:6px 9px" value="${val}" inputmode="numeric"></td>
    <td class="invstat">${stockPill(val)}</td></tr>`;}).join('')}
  </tbody></table></div>
  <div class="pager"><span>Showing ${page.length} of ${list.length}${list.length>catPerPage?` · ${catPerPage}/page`:''}</span><button class="btn ghost sm" onclick="toast('Export stock CSV (preview)')">Export CSV</button></div>
  <div id="invSaveBar"></div>`;
  const s=$('#invSearch');s.oninput=e=>{const pos=e.target.selectionStart;invSearch=e.target.value;catInventory();const n=$('#invSearch');if(n){n.focus();n.setSelectionRange(pos,pos);}};
  $$('#catBody .chip[data-if]').forEach(c=>c.onclick=()=>{invFilter=c.dataset.if;catInventory();});
  $$('#catBody .invq').forEach(inp=>inp.oninput=()=>{const i=+inp.dataset.i;const v=inp.value===''?0:Math.max(0,parseInt(inp.value,10)||0);invDraft[i]=v;const tr=inp.closest('tr');tr.classList.toggle('invdirty',v!==CAT_PRODUCTS[i][6]);tr.querySelector('.invstat').innerHTML=stockPill(v);renderInvSaveBar();});
  renderInvSaveBar();
}
let reorderType='category',reorderScopes=null,reorderScopeId=null,reorderScopeName=null,reorderData=null,reorderPage=1,reorderSearch='',reorderLocal=null,reorderDirty=false,reorderSelected=new Set(),reorderBusy=false,reorderPerPage=+(localStorage.getItem('kbb_reorder_pp')||50),reorderPpCustomMode=false;
function reorderApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/catalog/reorder'; }
function reorderFmtMoney(n){ return n==null ? '' : 'AED '+(Math.round(n*100)/100).toLocaleString(); }

async function catReorder(){
  const body=$('#catBody');
  if(!reorderScopes){
    body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading…</p>`;
    try{
      const r=await fetch(reorderApiBase()+'/scopes?type='+reorderType,{credentials:'same-origin',headers:{Accept:'application/json'}});
      reorderScopes=(await r.json()).scopes||[];
    }catch(e){ body.innerHTML=`<p style="padding:24px;color:var(--sale)">Could not load — ${escHtml(e.message)}</p>`; return; }
    if(!reorderScopes.length){ body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Nothing with visible products yet.</p>`; return; }
    if(!reorderScopeId){
      const first=reorderScopes[0];
      reorderScopeId=first.id; reorderScopeName=first.name;
    }
  }
  await reorderLoadProducts();
}

function reorderConfirmDiscard(){
  if(!reorderDirty) return true;
  return confirm('You have unsaved reorder changes on this page. Discard them?');
}

function reorderScopeOptions(){
  if(reorderType==='brand') return reorderScopes.map(b=>`<option value="${b.id}"${b.id===reorderScopeId?' selected':''}>${escHtml(b.name)}</option>`).join('');
  const opts=[];
  reorderScopes.forEach(c=>{
    opts.push(`<option value="${c.id}"${c.id===reorderScopeId?' selected':''}>${escHtml(c.name)}</option>`);
    (c.children||[]).forEach(k=>opts.push(`<option value="${k.id}"${k.id===reorderScopeId?' selected':''}>&nbsp;&nbsp;&nbsp;&nbsp;↳ ${escHtml(k.name)}</option>`));
  });
  return opts.join('');
}

const REORDER_PP_PRESETS=[25,50,100,200];

async function reorderLoadProducts(){
  const body=$('#catBody');
  const listArea=$('#reListArea');
  if(listArea) listArea.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading…</p>`;
  else body.innerHTML=`<p style="padding:24px;color:var(--ink-soft)">Loading products…</p>`;
  try{
    const q=new URLSearchParams({page:reorderPage, search:reorderSearch, per_page:reorderPerPage});
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/products?'+q,{credentials:'same-origin',headers:{Accept:'application/json'}});
    reorderData=await r.json();
  }catch(e){ body.innerHTML=`<p style="padding:24px;color:var(--sale)">Could not load products — ${escHtml(e.message)}</p>`; return; }
  reorderPerPage=reorderData.per_page;
  reorderLocal=reorderData.products.map(p=>({...p}));
  reorderDirty=false;
  reorderSelected.clear();
  reorderPaint();
}

function reorderPaint(){
  const body=$('#catBody');
  const d=reorderData;
  const list=reorderLocal;
  const ppIsPreset=REORDER_PP_PRESETS.includes(reorderPerPage) && !reorderPpCustomMode;
  body.innerHTML=`
  <div class="toolbar" style="flex-wrap:wrap;gap:10px">
    <div class="row" style="gap:6px">
      <button class="chip${reorderType==='category'?' on':''}" id="reTypeCat">Categories</button>
      <button class="chip${reorderType==='brand'?' on':''}" id="reTypeBrand">Brands</button>
    </div>
    <div class="row" style="gap:8px"><span style="font-size:13px;font-weight:600">${reorderType==='brand'?'Brand':'Category'}</span>
      <select class="inp" id="reCat" style="min-width:190px">${reorderScopeOptions()}</select></div>
    <div class="search" style="min-width:180px"><input id="reSearch" placeholder="Find a product…" value="${escHtml(reorderSearch)}"></div>
    <div class="row" style="gap:6px"><span style="font-size:12.5px;color:var(--ink-soft)">Start from</span>
      <select class="inp" id="reAutoSort" style="width:140px">
        <option value="">Auto-sort…</option>
        <option value="bestselling">Best selling</option>
        <option value="newest">Newest</option>
        <option value="price">Price: low to high</option>
        <option value="name">Name A–Z</option>
      </select></div>
    <div style="flex:1"></div>
    <span style="font-size:12px;color:var(--ink-soft)">${d.total} products · page ${d.page} of ${d.last_page}</span>
  </div>
  <div class="toolbar" style="margin-top:2px">
    <span style="font-size:12.5px;color:var(--ink-soft)">Show</span>
    <select class="inp" id="rePerPage" style="width:100px">
      ${REORDER_PP_PRESETS.map(n=>`<option value="${n}"${n===reorderPerPage&&ppIsPreset?' selected':''}>${n} per page</option>`).join('')}
      <option value="custom"${ppIsPreset?'':' selected'}>Custom…</option>
    </select>
    ${ppIsPreset?'':`<input class="inp" id="rePerPageCustom" type="number" min="10" max="500" value="${reorderPerPage}" style="width:80px" placeholder="10–500">`}
    <span style="font-size:11px;color:var(--ink-soft)">10–500</span>
  </div>
  <p style="font-size:12px;color:var(--ink-soft);margin:6px 0 12px">
    This is the product's real, global sort order — the same one the shop's default view uses — edited here one ${reorderType} at a time. A product shared across more than one moves everywhere it appears, matching how the live storefront's own "menu order" always worked.
  </p>
  <div id="reBulkBar" style="${reorderSelected.size?'':'display:none'};background:var(--pink-soft,#fff0f4);border:1px solid var(--accent,#E0567B);border-radius:10px;padding:8px 14px;margin-bottom:10px;display:flex;align-items:center;gap:12px">
    <span style="font-size:12.5px;font-weight:600">${reorderSelected.size} selected</span>
    <button class="btn ghost sm" id="reBulkTop">Move to top of this page</button>
    <button class="btn ghost sm" id="reBulkClear">Clear selection</button>
  </div>
  <div id="reListArea">${reorderRenderList(list)}</div>
  <div class="pager" style="margin-top:14px">
    <span>Showing ${list.length ? ((d.page-1)*d.per_page+1) : 0}–${(d.page-1)*d.per_page+list.length} of ${d.total}</span>
    <div class="row" style="gap:8px;align-items:center">
      <button class="btn ghost sm" style="white-space:nowrap" ${d.page<=1?'disabled':''} id="rePrev">‹ Prev</button>
      <button class="btn ghost sm" style="white-space:nowrap" ${d.page>=d.last_page?'disabled':''} id="reNext">Next ›</button>
    </div>
  </div>
  <div class="between" style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
    <span style="font-size:12.5px;color:${reorderDirty?'var(--sale)':'var(--ink-soft)'}">${reorderDirty?'Unsaved changes on this page':'No changes to save'}</span>
    <button class="btn primary" id="reSave" ${reorderDirty?'':'disabled'}>Save changes</button>
  </div>`;

  $('#reTypeCat').onclick=()=>{ if(reorderType==='category'||!reorderConfirmDiscard())return; reorderType='category'; reorderScopes=null; reorderScopeId=null; reorderPage=1; reorderSearch=''; catReorder(); };
  $('#reTypeBrand').onclick=()=>{ if(reorderType==='brand'||!reorderConfirmDiscard())return; reorderType='brand'; reorderScopes=null; reorderScopeId=null; reorderPage=1; reorderSearch=''; catReorder(); };
  $('#reCat').onchange=e=>{ if(!reorderConfirmDiscard()){e.target.value=reorderScopeId;return;} reorderScopeId=+e.target.value;const opt=e.target.selectedOptions[0];reorderScopeName=opt.textContent.replace(/^[\s↳]+/,'');reorderPage=1;reorderSearch='';reorderLoadProducts(); };
  let searchT;$('#reSearch').oninput=e=>{ if(!reorderConfirmDiscard()){e.target.value=reorderSearch;return;} clearTimeout(searchT);const v=e.target.value;searchT=setTimeout(()=>{reorderSearch=v;reorderPage=1;reorderLoadProducts();},300); };
  $('#reAutoSort').onchange=async e=>{
    const by=e.target.value; if(!by) return;
    if(!confirm(`Re-sort the whole ${reorderType} (${d.total} products) by ${e.target.selectedOptions[0].textContent}? This saves immediately and can't be undone by a Save button — you can still fine-tune afterward.`)){e.target.value='';return;}
    reorderBusy=true;
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/auto-sort',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},body:JSON.stringify({by})});
    const j=await r.json();
    reorderBusy=false;
    if(j.ok){toast(`Sorted ${j.sorted} products — fine-tune below`);reorderPage=1;reorderLoadProducts();}
    else{toast('Could not auto-sort.');}
  };
  $('#rePerPage').onchange=e=>{
    if(!reorderConfirmDiscard()){ reorderPaint(); return; }
    if(e.target.value==='custom'){ reorderPpCustomMode=true; reorderPaint(); setTimeout(()=>$('#rePerPageCustom')?.focus(),0); return; }
    reorderPpCustomMode=false; reorderPerPage=+e.target.value; localStorage.setItem('kbb_reorder_pp', reorderPerPage); reorderPage=1; reorderLoadProducts();
  };
  const ppCustom=$('#rePerPageCustom');
  if(ppCustom){
    const applyCustom=()=>{
      let v=parseInt(ppCustom.value,10);
      if(!v || v<10) v=10; if(v>500) v=500;
      reorderPerPage=v; localStorage.setItem('kbb_reorder_pp', v); reorderPage=1;
      if(!reorderConfirmDiscard()) return;
      reorderLoadProducts();
    };
    ppCustom.onkeydown=e=>{ if(e.key==='Enter'){ applyCustom(); } };
    ppCustom.onblur=applyCustom;
  }
  $('#rePrev').onclick=()=>{ if(d.page>1 && reorderConfirmDiscard()){reorderPage--;reorderLoadProducts();} };
  $('#reNext').onclick=()=>{ if(d.page<d.last_page && reorderConfirmDiscard()){reorderPage++;reorderLoadProducts();} };
  const bulkTop=$('#reBulkTop'); if(bulkTop) bulkTop.onclick=reorderBulkTopOfPage;
  const bulkClear=$('#reBulkClear'); if(bulkClear) bulkClear.onclick=()=>{reorderSelected.clear();reorderPaint();};
  $('#reSave').onclick=reorderSave;
  reorderWireList();
}

function reorderRenderList(list){
  if(!list.length) return reorderSearch
    ? `<p style="padding:24px;color:var(--ink-soft)">No products match "${escHtml(reorderSearch)}".</p>`
    : `<p style="padding:24px;color:var(--ink-soft)">No visible products here.</p>`;
  return `<div class="rlist" id="rlist">${list.map((p,i)=>`
    <div class="ritem" draggable="true" data-i="${i}" data-id="${p.id}">
      <span class="cbx${reorderSelected.has(p.id)?' on':''}" data-rsel="${p.id}">${ic(I.check)}</span>
      <span class="grip">${ic('<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>')}</span>
      <input class="inp" id="rerank-${p.id}" value="${(reorderData.page-1)*reorderData.per_page+i+1}" style="width:50px;text-align:center;padding:5px 4px;font-size:12px" data-rankinput="${p.id}">
      <span class="pthumb" style="background:${tcol(p.brand||p.name)};width:32px;height:32px;font-size:10px">${initials(p.brand||p.name)}</span>
      <div style="flex:1;min-width:0"><div class="pname" style="font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.name)}</div><div class="pbrand" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.brand||'')}${p.sku?' · '+escHtml(p.sku):''}</div></div>
      <div style="text-align:right;flex:0 0 88px">
        ${p.sale_price!=null
          ? `<div style="font-size:12.5px;font-weight:700;color:var(--sale)">${reorderFmtMoney(p.sale_price)}</div><div style="font-size:10.5px;color:var(--ink-soft);text-decoration:line-through">${reorderFmtMoney(p.price)}</div>`
          : `<div style="font-size:12.5px;font-weight:700">${reorderFmtMoney(p.price)}</div>`}
      </div>
      <div style="text-align:right;flex:0 0 76px;font-size:11px;color:var(--ink-soft)" title="Distinct orders this product has appeared in">${p.orders_count} order${p.orders_count===1?'':'s'}</div>
      <div class="mv" style="flex:0 0 auto;flex-direction:row;gap:4px">
        <button data-rtop="${p.id}" title="Move to top of this page">${ic('<path d="M12 19V5M5 12l7-7 7 7"/>')}</button>
        <button data-rbottom="${p.id}" title="Move to bottom of this page">${ic('<path d="M12 5v14M5 12l7 7 7-7"/>')}</button>
      </div>
    </div>`).join('')}</div>`;
}

function reorderWireList(){
  $$('#rlist .cbx[data-rsel]').forEach(c=>c.onclick=()=>{
    const id=+c.dataset.rsel;
    reorderSelected.has(id)?reorderSelected.delete(id):reorderSelected.add(id);
    reorderPaint();
  });
  $$('#rlist [data-rankinput]').forEach(inp=>{
    inp.onkeydown=e=>{ if(e.key==='Enter'){ e.target.blur(); } };
    inp.onblur=e=>{
      const id=+e.target.dataset.rankinput;
      const rank=parseInt(e.target.value,10);
      if(!rank || rank<1){ e.target.value=e.target.defaultValue; return; }
      reorderJumpToRank(id, rank);
    };
  });
  $$('#rlist [data-rtop]').forEach(b=>b.onclick=()=>reorderLocalMove(+b.dataset.rtop, 0));
  $$('#rlist [data-rbottom]').forEach(b=>b.onclick=()=>reorderLocalMove(+b.dataset.rbottom, reorderLocal.length-1));
  let dragI=null;
  $$('#rlist .ritem').forEach(it=>{
    it.ondragstart=e=>{ if(e.target.closest('[data-rankinput],[data-rsel]')){e.preventDefault();return;} dragI=+it.dataset.i; };
    it.ondragover=e=>e.preventDefault();
    it.ondrop=()=>{
      const to=+it.dataset.i;
      if(dragI===null||dragI===to) return;
      const i=dragI; dragI=null;
      reorderLocalMove(reorderLocal[i].id, to);
    };
  });
}

function reorderLocalMove(productId, toIndex){
  const from=reorderLocal.findIndex(p=>p.id===productId);
  if(from===-1) return;
  const [moved]=reorderLocal.splice(from,1);
  reorderLocal.splice(Math.min(toIndex,reorderLocal.length),0,moved);
  reorderDirty=true;
  reorderPaint();
}

function reorderBulkTopOfPage(){
  if(!reorderSelected.size) return;
  const selected=reorderLocal.filter(p=>reorderSelected.has(p.id));
  const rest=reorderLocal.filter(p=>!reorderSelected.has(p.id));
  reorderLocal=[...selected,...rest];
  reorderDirty=true;
  reorderPaint();
}

async function reorderJumpToRank(productId, rank){
  const pageStart=(reorderData.page-1)*reorderData.per_page+1;
  const pageEnd=pageStart+reorderLocal.length-1;
  if(rank>=pageStart && rank<=pageEnd){
    reorderLocalMove(productId, rank-pageStart);
    return;
  }
  if(reorderDirty && !confirm('Moving to a position outside this page saves immediately, including any unsaved changes already made on this page. Continue?')) {
    reorderPaint();
    return;
  }
  reorderBusy=true;
  try{
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/move',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({product_id:productId, to:rank-1})});
    const j=await r.json();
    if(j.ok){ toast('Moved and saved'); await reorderLoadProducts(); }
    else{ toast(j.message||'Could not move that product.'); }
  }catch(e){ toast('Could not save — check your connection.'); }
  reorderBusy=false;
}

async function reorderSave(){
  if(reorderBusy || !reorderDirty) return;
  reorderBusy=true;
  const btn=$('#reSave'); if(btn){btn.disabled=true;btn.textContent='Saving…';}
  try{
    const r=await fetch(reorderApiBase()+'/'+reorderType+'/'+reorderScopeId+'/save-page',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
      body:JSON.stringify({page:reorderData.page, per_page:reorderData.per_page, product_ids:reorderLocal.map(p=>p.id)})});
    const j=await r.json();
    if(j.ok){ toast(`Saved — ${j.updated} products`); reorderDirty=false; await reorderLoadProducts(); }
    else{ toast(j.message||'Could not save — reload and try again.'); }
  }catch(e){ toast('Could not save — check your connection.'); }
  reorderBusy=false;
}
let pdTab='general',reyTab='misc',yoastTab='seo',pageTab='general',peCtx={};
const ICO={img:'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',ring:'<circle cx="12" cy="12" r="9"/>'};
const peBox=(title,body,open=true)=>`<div class="card pe-box${open?'':' col'}"><div class="pe-bh" onclick="this.parentElement.classList.toggle('col')">${title}<span class="chev">${ic('<path d="m6 9 6 6 6-6"/>')}</span></div><div class="pe-bb">${body}</div></div>`;
function wireCbx(s){$$(s+' .cbx:not([data-sel])').forEach(c=>c.onclick=()=>c.classList.toggle('on'));}
function wireChips(s){$$(s+' .tagchip').forEach(c=>c.onclick=()=>c.classList.toggle('on'));}
function wireMini(s){$$(s+' .minitabs').forEach(g=>$$('button',g).forEach(btn=>btn.onclick=()=>{$$('button',g).forEach(x=>x.classList.remove('on'));btn.classList.add('on');}));}
function descSample(n,b,cat){return `Introducing ${n}. ${b} brings clinically-tested Korean skincare technology, lightweight and formulated for the UAE climate.\n\nHow to Use\nStart with clean, dry skin. Apply an even layer and follow with the rest of your routine. Use morning and night for best results.\n\nGet the Perfect Fit\nResults may vary by skin type. Patch test before first use.`;}

function peProductData(){
  return `<div class="card pe-box">
    <div class="pe-pdhead"><b>Product data —</b><select class="inp"><option>Simple product</option><option>Variable product</option><option>Grouped product</option><option>External / Affiliate</option></select>
      <label class="pdchk"><span class="cbx">${ic(I.check)}</span> Virtual</label><label class="pdchk"><span class="cbx">${ic(I.check)}</span> Downloadable</label></div>
    <div class="pd-wrap"><div class="pd-tabs">${[['general','General'],['inventory','Inventory'],['shipping','Shipping'],['linked','Linked Products'],['attributes','Attributes'],['variations','Variations'],['advanced','Advanced'],['labels','Advanced label'],['facebook','Facebook']].map(t=>`<button class="pd-tab${t[0]===pdTab?' on':''}" data-pd="${t[0]}">${t[1]}</button>`).join('')}</div>
      <div class="pd-body" id="pdBody"></div></div></div>`;
}
function renderPDBody(){
  const b=$('#pdBody');if(!b)return;const{price,sale,sku,stock}=peCtx;let h='';
  if(pdTab==='general'){
    h=`<div class="g2"><div class="fld"><label>Regular price (AED)</label><input value="${price}"></div><div class="fld"><label>Sale price (AED)</label><input value="${sale||''}" placeholder="optional"></div></div>
    <div class="fld"><button class="lk" style="font-size:12px;color:var(--accent-ink);font-weight:600;background:none" onclick="toast('Schedule sale dates (preview)')">Schedule sale dates</button></div>
    <div class="g2"><div class="fld"><label>Tax status</label><select><option>Taxable</option><option>Shipping only</option><option>None</option></select></div><div class="fld"><label>Tax class</label><select><option>Standard (5% VAT)</option><option>Zero rate</option></select></div></div>`;
  } else if(pdTab==='inventory'){
    h=`<div class="g2"><div class="fld"><label>SKU</label><input value="${sku}"></div><div class="fld"><label>Stock status</label><select><option${stock>0?' selected':''}>In stock</option><option${stock===0?' selected':''}>Out of stock</option><option>On backorder</option></select></div></div>
    <div class="fld"><label class="row" style="gap:8px;font-weight:600;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Manage stock at product level</label></div>
    <div class="g2"><div class="fld"><label>Stock quantity</label><input value="${stock}"></div><div class="fld"><label>Low-stock threshold</label><input value="15"></div></div>
    <div class="g2"><div class="fld"><label>Backorders</label><select><option>Do not allow</option><option>Allow</option><option>Allow, notify</option></select></div><div class="fld"><label>Restrictions</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;padding-top:9px"><span class="cbx">${ic(I.check)}</span> Sold individually</label></div></div>`;
  } else if(pdTab==='shipping'){
    h=`<div class="fld"><label>Weight (kg)</label><input placeholder="0.0"></div><div class="g2" style="grid-template-columns:1fr 1fr 1fr"><div class="fld"><label>Length</label><input></div><div class="fld"><label>Width</label><input></div><div class="fld"><label>Height</label><input></div></div><div class="fld" style="margin:0"><label>Shipping class</label><select><option>No class</option><option>Bulky</option><option>Fragile</option></select></div>`;
  } else if(pdTab==='linked'){
    h=`<div class="fld"><label>Upsells</label><input placeholder="Search products to upsell…"></div><div class="fld"><label>Cross-sells</label><input placeholder="Search products to cross-sell…"></div><p style="font-size:11.5px;color:var(--ink-soft)">Upsells show on the product page; cross-sells show in the cart.</p>`;
  } else if(pdTab==='attributes'){
    h=`<div style="display:flex;flex-direction:column;gap:10px">${CAT_ATTRS.slice(0,2).map(a=>`<div class="card" style="box-shadow:none;padding:12px"><div class="between"><b style="font-size:12.5px">${a[0]}</b><label class="row" style="gap:7px;font-size:11.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Used for variations</label></div><div class="tagchips" style="margin-top:9px">${a[1].map(t=>`<span class="tagchip on">${t}</span>`).join('')}</div></div>`).join('')}</div><button class="btn ghost sm" style="margin-top:12px" onclick="toast('Add attribute (preview)')">+ Add attribute</button>`;
  } else if(pdTab==='variations'){
    h=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">Generated from attributes marked “used for variations”.</p>${['30ml','50ml','100ml'].map((sz,i)=>`<div class="ritem" style="margin-bottom:8px"><b style="font-size:12px;width:54px">${sz}</b><div class="fld" style="margin:0;flex:1"><input value="${price?(+price+i*20):''}" placeholder="Price"></div><div class="fld" style="margin:0;width:84px"><input value="${stock}" placeholder="Stock"></div><button class="btn ghost sm" onclick="toast('Edit variation (preview)')">Edit</button></div>`).join('')}<button class="btn ghost sm" onclick="toast('Generate variations (preview)')">Generate variations</button>`;
  } else if(pdTab==='labels'){
    h=`<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:14px">Global labels that match this product appear automatically. Force-add, skip, or add a one-off label just for this product.</p>
    <div class="dsec" style="margin-top:0">Auto-applied here</div>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px">${[['Eid Mega Sale','#1b9e77','🌙'],['Up to 30% Off','#d6455a','%']].map(l=>`<div class="ritem"><span class="lbl-card-thumb" style="width:30px;height:30px;font-size:12px;background:${l[1]}">${l[2]}</span><b style="font-size:12.5px">${l[0]}</b><span class="pill grey" style="margin-left:8px">auto</span><div style="flex:1"></div><label class="row" style="gap:7px;font-size:11.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Skip for this product</label></div>`).join('')}</div>
    <div class="dsec">Force-add a label</div>
    <div class="tagchips" style="margin-bottom:16px">${LABELS.map(l=>`<span class="tagchip">${l.ic} ${l.name}</span>`).join('')}</div>
    <button class="btn ghost sm" onclick="go('labels')">Manage all labels →</button>`;
  } else if(pdTab==='facebook'){
    h=`<label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;margin-bottom:14px"><span class="cbx on">${ic(I.check)}</span> Sync this product to the Meta catalog</label>
    <div class="fld"><label>Facebook title (override)</label><input placeholder="Defaults to product name"></div>
    <div class="fld"><label>Facebook description (override)</label><textarea placeholder="Defaults to the short description…"></textarea></div>
    <div class="fld"><label>Catalog image (override)</label><div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Defaults to the featured image</div></div></div>
    <div class="g2"><div class="fld"><label>Google product category</label><input placeholder="e.g. Health & Beauty > Skin Care"></div><div class="fld"><label>Condition</label><select><option>New</option><option>Refurbished</option><option>Used</option></select></div></div>
    <div class="g2"><div class="fld"><label>Brand</label><input value="${peCtx.b||''}"></div><div class="fld"><label>GTIN / MPN</label><input placeholder="barcode or MPN"></div></div>
    <button class="pe-link" onclick="go('meta')">Open Meta &amp; Facebook settings →</button>`;
  } else {
    h=`<div class="fld"><label>Purchase note</label><textarea placeholder="Note emailed to the customer after purchase…"></textarea></div><div class="g2"><div class="fld"><label>Menu order</label><input value="0"></div><div class="fld"><label>Reviews</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;padding-top:9px"><span class="cbx on">${ic(I.check)}</span> Enable reviews</label></div></div>`;
  }
  b.innerHTML=h;wireCbx('#pdBody');wireChips('#pdBody');
}
/* ---- product editor: Custom Tabs manager (fills CUSTOM_TABS on the PDP) ---- */
let PE_TABS=[
 {id:1,title:'How to use',scope:'product',enabled:true,content:'<p>Apply as the last step of your morning routine over face and neck. Reapply every 2 hours of sun exposure.</p>'},
 {id:2,title:'Shipping & returns',scope:'all',enabled:true,content:'<ul><li>Free UAE delivery over AED 100</li><li>Same-day dispatch before 4pm</li><li>Tabby, Tamara, card, Apple Pay or COD</li><li>14-day easy returns on unopened items</li></ul>'}
];
let peTabSeq=2, peTabEditing=null;
function peEsc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function peTabsBody(){
  const rows = PE_TABS.length ? PE_TABS.map((t,i)=>{
    const ed = peTabEditing===t.id;
    return `<div class="ritem" style="flex-wrap:wrap;${ed?'background:var(--surface-2)':''}">
      <b style="font-size:12.5px;flex:1;min-width:120px">${peEsc(t.title)||'Untitled tab'}</b>
      <span class="pill ${t.scope==='all'?'grey':'green'}">${t.scope==='all'?'All products':'This product'}</span>
      <button class="btn ghost sm" ${i===0?'disabled':''} onclick="peTabMove(${t.id},-1)">↑</button>
      <button class="btn ghost sm" ${i===PE_TABS.length-1?'disabled':''} onclick="peTabMove(${t.id},1)">↓</button>
      <button class="btn ghost sm" onclick="peTabToggle(${t.id})">${t.enabled?'On':'Off'}</button>
      <button class="btn ghost sm" onclick="peTabEdit(${t.id})">Edit</button>
      <button class="btn ghost sm" style="color:#b32d2e" onclick="peTabDel(${t.id})">Delete</button>
      ${ed?`<div style="flex-basis:100%;margin-top:10px">
        <div class="g2"><div class="fld"><label>Tab title</label><input id="peTabTitle" value="${peEsc(t.title)}" placeholder="e.g. How to use"></div>
        <div class="fld"><label>Show on</label><select id="peTabScope"><option value="product"${t.scope==='product'?' selected':''}>This product</option><option value="all"${t.scope==='all'?' selected':''}>All products (global)</option></select></div></div>
        <div class="fld" style="margin:0"><label>Content <span style="font-weight:400;color:var(--ink-soft)">— basic HTML ok</span></label><textarea id="peTabContent" rows="4">${peEsc(t.content)}</textarea></div>
        <div class="row" style="gap:8px;margin-top:10px"><button class="btn ghost sm" style="background:var(--accent);color:#fff;border-color:var(--accent)" onclick="peTabSaveRow(${t.id})">Save tab</button><button class="btn ghost sm" onclick="peTabCancel()">Cancel</button></div>
      </div>`:''}
    </div>`;
  }).join('') : `<p style="font-size:12px;color:var(--ink-soft)">No custom tabs yet — add one below.</p>`;
  return `<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:12px">Description &amp; Ingredients are built in. Custom tabs appear after them on the product page. Set each to this product, or all products.</p>
   <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">${rows}</div>
   <button class="btn ghost sm" onclick="peTabAdd()">+ Add a custom tab</button>`;
}
function peTabMove(id,d){const i=PE_TABS.findIndex(t=>t.id===id),j=i+d;if(j<0||j>=PE_TABS.length)return;const x=PE_TABS.splice(i,1)[0];PE_TABS.splice(j,0,x);renderReyBody();}
function peTabToggle(id){const t=PE_TABS.find(t=>t.id===id);t.enabled=!t.enabled;renderReyBody();}
function peTabEdit(id){peTabEditing=(peTabEditing===id?null:id);renderReyBody();}
function peTabCancel(){peTabEditing=null;renderReyBody();}
function peTabDel(id){if(!confirm('Delete this tab?'))return;PE_TABS=PE_TABS.filter(t=>t.id!==id);if(peTabEditing===id)peTabEditing=null;renderReyBody();toast('Tab deleted');}
function peTabAdd(){const t={id:++peTabSeq,title:'New tab',scope:'product',enabled:true,content:'<p>Tab content…</p>'};PE_TABS.push(t);peTabEditing=t.id;renderReyBody();}
function peTabSaveRow(id){const t=PE_TABS.find(t=>t.id===id);if(!t)return;t.title=($('#peTabTitle').value||'').trim()||'Untitled tab';t.scope=$('#peTabScope').value;t.content=$('#peTabContent').value;peTabEditing=null;renderReyBody();toast('Tab saved');}
function reyPanel(){
  const tabs=[['misc','Misc.'],['cart','Add to cart'],['delivery','Estimated Delivery'],['threed','360 Image'],['badge','Badge'],['qty','Quantity Min/Max'],['catalog','Catalog Display'],['video','Video'],['tabs','Custom Tabs/Blocks'],['global','Global Sections']];
  return peBox('Product settings · KBB theme',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===reyTab?' on':''}" data-rey="${t[0]}">${t[1]}</button>`).join('')}</div><div id="reyBody"></div>`);
}
function renderReyBody(){
  const b=$('#reyBody');if(!b)return;let h='';
  if(reyTab==='misc'){
    h=`<div class="fld"><label>Product Info Block/Tab</label><select><option>— Select —</option><option>Ingredients</option><option>How to use</option><option>Shipping &amp; returns</option></select><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Shown after the product summary. Pre-defined in Customizer › Product Page.</p></div>
    <div class="fld"><label>Specifications Block/Tab</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show specifications block</label></div>
    <div class="fld"><label>Evergreen Sale</label><div class="g2" style="grid-template-columns:1fr 1fr 1fr"><input placeholder="Duration (days)"><input placeholder="Starting from"><input placeholder="Repeat count"></div><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Permanent sale badge / countdown, regardless of the scheduled sale.</p></div>
    <div class="fld" style="margin:0"><label>“Buy Now” button</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Show a Buy Now button for this product</label></div>`;
  } else if(reyTab==='cart'){
    h=`<div class="fld"><label>Add-to-cart action</label><select><option>Default</option><option>Open slide-out cart</option><option>Go to checkout</option><option>Stay on page (AJAX)</option></select></div><div class="fld" style="margin:0"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Enable sticky add-to-cart bar</label></div>`;
  } else if(reyTab==='delivery'){
    h=`<div class="g2"><div class="fld"><label>Min. days</label><input value="1"></div><div class="fld"><label>Max. days</label><input value="3"></div></div><div class="fld" style="margin:0"><label>Delivery note</label><input placeholder="e.g. Order before 4pm for same-day dispatch"></div>`;
  } else if(reyTab==='threed'){
    h=`<div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Upload a 360° image sequence (.zip)</div></div>`;
  } else if(reyTab==='badge'){
    h=`<div class="fld"><label>Custom badge text</label><input placeholder="e.g. MedSpa at home"></div><div class="fld" style="margin:0"><label>Badge style</label><select><option>Solid accent</option><option>Outline</option><option>Gradient</option></select></div>`;
  } else if(reyTab==='qty'){
    h=`<div class="g2"><div class="fld"><label>Minimum qty</label><input value="1"></div><div class="fld"><label>Maximum qty</label><input placeholder="no limit"></div></div><div class="fld" style="margin:0"><label>Step</label><input value="1"></div>`;
  } else if(reyTab==='catalog'){
    h=`<div class="fld" style="margin-bottom:9px"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show in Quick View</label></div><div class="fld" style="margin-bottom:9px"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Show swatches on catalog card</label></div><div class="fld" style="margin:0"><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Hide from search</label></div>`;
  } else if(reyTab==='video'){
    h=`<div class="fld" style="margin:0"><label>Product video URL</label><input placeholder="YouTube, Vimeo or MP4 — plays on hover in catalog"></div>`;
  } else if(reyTab==='tabs'){
    h=peTabsBody();
  } else {
    h=`<div class="fld" style="margin:0"><label>Global section</label><select><option>None</option><option>K-Beauty trust badges</option><option>Ingredient glossary block</option><option>Reviews wall</option></select><p style="font-size:11px;color:var(--ink-soft);margin-top:5px">Insert a reusable section built in the KBB Page Builder.</p></div>`;
  }
  b.innerHTML=h;wireCbx('#reyBody');
}
function yoastPanel(){
  const tabs=[['seo','SEO'],['read','Readability'],['schema','Schema'],['social','Social']];
  return peBox('Yoast SEO',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===yoastTab?' on':''}" data-yo="${t[0]}">${t[1]}</button>`).join('')}</div><div id="yoastBody"></div>`);
}
function renderYoastBody(){
  const b=$('#yoastBody');if(!b)return;const{n,b:brand,cat,slug}=peCtx;let h='';
  if(yoastTab==='seo'){
    h=`<div class="fld"><label>Focus keyphrase</label><input placeholder="e.g. ${(brand||'korean')} ${(cat||'skincare').toLowerCase()}"><button class="pe-link" style="margin-top:6px" onclick="toast('Get related keyphrases (preview)')">Get related keyphrases</button></div>
    <div class="dsec" style="margin-top:2px">Search appearance</div>
    <div class="minitabs"><button class="on">Mobile result</button><button>Desktop result</button></div>
    <div class="seo-snip"><div class="u">kbeautybliss.com › product › ${slug}</div><div class="t">${n||'Product'} | K-Beauty Bliss | Korean Skincare</div><div class="d">Shop ${n||'this product'}${brand?' by '+brand:''} at K-Beauty Bliss — authentic Korean skincare, fast UAE delivery, pay with Tabby &amp; Tamara.</div></div>
    <div class="fld" style="margin-top:13px"><label>SEO title</label><div class="tagchips" style="margin-bottom:7px">${['Title','Page','Separator','Site title'].map(v=>`<span class="tagchip">${v}</span>`).join('')}</div><input value="${n?n+' | K-Beauty Bliss':''}"></div>
    <div class="fld"><label>Slug</label><input value="${slug}"></div>
    <div class="fld" style="margin:0"><label>Meta description</label><textarea placeholder="Write the snippet Google shows…">${n?'Shop '+n+' at K-Beauty Bliss — authentic Korean skincare with fast UAE delivery.':''}</textarea></div>
    <div class="checks" style="margin-top:14px"><div class="check"><div class="ci" style="background:#fdecd2;color:#b7791f">${ic(ICO.ring)}</div><b>SEO analysis</b><small>Add a focus keyphrase</small></div><div class="check"><div class="ci green">${ic(I.check)}</div><b>Readability</b><small>OK</small></div></div>`;
  } else if(yoastTab==='read'){
    h=`<div class="checks"><div class="check"><div class="ci green">${ic(I.check)}</div><b>Sentence length</b><small>OK</small></div><div class="check"><div class="ci green">${ic(I.check)}</div><b>Paragraph length</b><small>OK</small></div><div class="check"><div class="ci" style="background:#fdecd2;color:#b7791f">${ic(ICO.ring)}</div><b>Subheadings</b><small>Add a few more</small></div></div>`;
  } else if(yoastTab==='schema'){
    h=`<div class="g2"><div class="fld"><label>Page type</label><select><option>Item Page</option><option>Web Page</option></select></div><div class="fld"><label>Product schema</label><select><option>Product</option><option>None</option></select></div></div><p style="font-size:11.5px;color:var(--ink-soft);margin:0">Emits Product rich-result schema (price, availability, rating) for Google.</p>`;
  } else {
    h=`<div class="fld"><label>Facebook / OG title</label><input value="${n||''}"></div><div class="fld"><label>Description</label><textarea></textarea></div><div class="fld" style="margin:0"><label>Social share image</label><div class="imgdrop">${ic(ICO.img)}<div style="margin-top:6px">Upload a 1200×630 image</div></div></div>`;
  }
  b.innerHTML=h;wireCbx('#yoastBody');wireChips('#yoastBody');wireMini('#yoastBody');
}
function pagePanel(){
  const tabs=[['general','General'],['header','Header'],['footer','Footer'],['layout','Page layout'],['advanced','Advanced']];
  return peBox('Page Settings · KBB theme',`<div class="htabs">${tabs.map(t=>`<button class="htab${t[0]===pageTab?' on':''}" data-pg="${t[0]}">${t[1]}</button>`).join('')}</div><div id="pageBody"></div>`,false);
}
function renderPageBody(){
  const b=$('#pageBody');if(!b)return;let h='';
  if(pageTab==='general'){
    h=`<div class="g2"><div class="fld"><label>Title Display</label><select><option>Inherit</option><option>Show</option><option>Hide</option></select></div><div class="fld"><label>Page Cover</label><select><option>Inherit</option><option>Enable</option><option>Disable</option></select></div></div><div class="fld" style="margin:0"><label>Body CSS Classes</label><input placeholder="extra classes for the body tag"></div>`;
  } else if(pageTab==='header'){
    h=`<div class="g2"><div class="fld"><label>Header</label><select><option>Inherit</option><option>Transparent</option><option>Hidden</option></select></div><div class="fld"><label>Sticky</label><select><option>Inherit</option><option>On</option><option>Off</option></select></div></div>`;
  } else if(pageTab==='footer'){
    h=`<div class="fld" style="margin:0"><label>Footer</label><select><option>Inherit</option><option>Show</option><option>Hide</option></select></div>`;
  } else if(pageTab==='layout'){
    h=`<div class="g2"><div class="fld"><label>Content width</label><select><option>Inherit</option><option>Full width</option><option>Boxed</option></select></div><div class="fld"><label>Sidebar</label><select><option>None</option><option>Left</option><option>Right</option></select></div></div>`;
  } else {
    h=`<div class="fld" style="margin:0"><label>Custom CSS (this product)</label><textarea placeholder="/* scoped to this product page */"></textarea></div>`;
  }
  b.innerHTML=h;
}
function openProduct(idx){
  pdTab='general';reyTab='misc';yoastTab='seo';pageTab='general';
  const p=idx>=0?CAT_PRODUCTS[idx]:null;const[n,b,sku,cat,price,sale,stock]=p||['','','','Beauty Devices','','',''];
  const draft=p?CAT_DRAFT.has(sku):false;const slug=(n||'new-product').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
  peCtx={n,b,sku,cat,price,sale,stock,slug,draft};
  const cats=PE_CATS.includes(cat)?PE_CATS:[cat,...PE_CATS];
  $('#crumb').textContent='Catalog';$('#ptitle').textContent=p?'Edit product':'New product';
  $('#content').innerHTML=`<div class="wrap">
    <div class="pe-top"><button class="btn ghost sm" onclick="go('catalog')">${ic('<path d="m15 18-6-6 6-6"/>')} Catalog</button><div style="flex:1"></div>
      <span class="pill ${draft?'grey':'green'}">${draft?'Draft':'Published'}</span>
      <button class="btn ghost sm" onclick="toast('Open preview (preview)')">View product</button>
      <button class="btn" onclick="go('catalog');toast('${p?'Product updated (preview)':'Product created (preview)'}')">${p?'Update':'Publish'}</button></div>
    <div class="pe-grid">
      <div class="pe-main">
        <div><input class="pe-title" value="${n}" placeholder="Product name"><div class="pe-perma">Permalink: <span>kbeautybliss.com/product/${slug}</span><button class="lk" onclick="toast('Edit slug (preview)')">Edit</button></div>
          <button class="pe-builder" onclick="toast('Opens the KBB Page Builder (preview)')">${ic('<path d="m12 19 7-7 3 3-7 7-3-3z"/><path d="m18 13-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/>')} Edit with Page Builder</button></div>
        ${peBox('Product description',`<div class="rte"><div class="rte-sub"><button class="am" onclick="toast('Add Media (preview)')">＋ Add Media</button><button class="am" onclick="toast('Add Form (preview)')">＋ Form</button><div class="vc"><button class="on">Visual</button><button>Code</button></div></div><div class="rte-bar">${['Paragraph ▾','B','I','U','• List','1. List','❝','🔗','Align'].map(t=>`<button>${t}</button>`).join('')}</div><textarea class="rte-area" placeholder="Full product description…">${p?descSample(n,b,cat):''}</textarea><div class="wcount"><span>Word count: ${p?251:0}</span><span>Last edited Jun 23, 2026 at 3:33 pm</span></div></div>`)}
        ${peProductData()}
        ${peBox('Product short description',`<textarea class="rte-area" style="border:1px solid var(--border);border-radius:10px;min-height:74px" placeholder="Short summary shown near the title…">${p?'A MedSpa-inspired '+cat.toLowerCase().replace(/s$/,'')+' from '+b+' that combines clinically-tested technology to deliver visible results.':''}</textarea>`)}
        ${reyPanel()}
        ${yoastPanel()}
        ${pagePanel()}
        ${peBox('Revisions',`<div style="display:flex;flex-direction:column;gap:10px">${[['K Beauty Bliss','4 days ago · Jun 25, 2026','Autosave'],['Pak Web Idea','5 days ago · Jun 23, 2026','']].map(r=>`<div class="row" style="gap:10px;font-size:12.5px"><span class="pthumb" style="width:28px;height:28px;font-size:9px;background:${tcol(r[0])}">${initials(r[0])}</span><div><b>${r[0]}</b> <span style="color:var(--ink-soft)">${r[1]}</span> ${r[2]?`<span class="pill grey" style="font-size:9px;padding:1px 6px">${r[2]}</span>`:''}</div></div>`).join('')}</div>`,false)}
      </div>
      <div class="pe-side">
        ${peBox('Publish',`<button class="btn ghost block" onclick="toast('Open preview (preview)')">Preview changes</button>
          <div class="pubrow" style="margin-top:11px"><span>Status</span><b>${draft?'Draft':'Published'} <button class="pe-link" onclick="toast('Edit (preview)')">Edit</button></b></div>
          <div class="pubrow"><span>Visibility</span><b>Public <button class="pe-link" onclick="toast('Edit (preview)')">Edit</button></b></div>
          <div class="pubrow"><span>Revisions</span><b>2 <button class="pe-link" onclick="toast('Browse (preview)')">Browse</button></b></div>
          <div class="pubrow"><span>Published</span><b>Jun 23, 2026</b></div>
          <div class="pubrow"><span>SEO analysis</span><b style="color:var(--ink-soft);font-weight:600">Not available</b></div>
          <div class="pubrow"><span>Readability</span><b style="color:var(--accent-strong)">OK</b></div>
          <div class="pubrow" style="border:0"><span>Catalog visibility</span><b>Shop &amp; search</b></div>
          <div class="row" style="gap:8px;margin-top:12px;align-items:center"><button class="pe-link" onclick="toast('Copied to new draft (preview)')">Copy to draft</button><div style="flex:1"></div><button class="btn" onclick="go('catalog');toast('Updated (preview)')">${p?'Update':'Publish'}</button></div>`)}
        ${peBox('Product image',`<div class="featimg"><div class="ph">${p?initials(b||'KB'):'No image'}</div><div class="cap"><button class="pe-link" onclick="toast('Edit image (preview)')">Edit</button><button class="pe-link" style="color:#d6455a" onclick="toast('Removed (preview)')">Remove</button></div></div>`)}
        ${peBox('Product gallery',`<div class="gal">${(p?[1,2,3,4,5]:[]).map(i=>`<div class="g">IMG ${i}</div>`).join('')}<div class="g add" onclick="toast('Add gallery images (preview)')">＋ Add</div></div>`)}
        ${peBox('Product categories',`<div class="minitabs"><button class="on">All categories</button><button>Most Used</button></div><div class="catlist">${cats.map(c=>`<label class="catopt"><span class="cbx${c===cat?' on':''}">${ic(I.check)}</span> <span style="flex:1">${c}</span>${c===cat?'<span class="pill green" style="font-size:9px;padding:2px 7px">Primary</span>':''}</label>`).join('')}</div><button class="pe-link" style="margin-top:11px" onclick="toast('Add new category (preview)')">+ Add new category</button>`)}
        ${peBox('Product tags',`<div class="row" style="gap:6px"><input class="inp" style="flex:1" placeholder="Add tag"><button class="btn ghost sm" onclick="toast('Tag added (preview)')">Add</button></div><div class="tagchips" style="margin-top:10px">${['k-beauty','bestseller','led-mask'].map(t=>`<span class="tagchip on">${t}</span>`).join('')}</div><button class="pe-link" style="margin-top:9px" onclick="toast('Most used tags (preview)')">Choose from most used</button>`)}
        ${peBox('Post Attributes',`<div class="fld" style="margin:0"><label>Template</label><select><option>Default template</option><option>Full width</option><option>Landing</option></select></div>`,false)}
        ${peBox('Brands',`<div class="minitabs"><button class="on">All Brands</button><button>Most Used</button></div><select class="inp" style="width:100%">${CAT_BRANDS.map(x=>`<option${x[0]===b?' selected':''}>${x[0]}</option>`).join('')}</select><button class="pe-link" style="margin-top:11px" onclick="toast('Add new brand (preview)')">+ Add New Brand</button>`)}
        ${peBox('Product Subtitle · KBB',`<input class="inp" style="width:100%" placeholder="e.g. MedSpa-inspired cooling mask"><div class="fld" style="margin:12px 0 0"><label>Badge</label><select><option>None</option><option>Best seller</option><option>New</option><option>Sale</option></select></div>`,false)}
      </div>
    </div></div>`;
  $$('#content .pd-tab').forEach(t=>t.onclick=()=>{pdTab=t.dataset.pd;renderPDBody();});
  $$('#content .htab[data-rey]').forEach(t=>t.onclick=()=>{reyTab=t.dataset.rey;$$('#content .htab[data-rey]').forEach(x=>x.classList.toggle('on',x===t));renderReyBody();});
  $$('#content .htab[data-yo]').forEach(t=>t.onclick=()=>{yoastTab=t.dataset.yo;$$('#content .htab[data-yo]').forEach(x=>x.classList.toggle('on',x===t));renderYoastBody();});
  $$('#content .htab[data-pg]').forEach(t=>t.onclick=()=>{pageTab=t.dataset.pg;$$('#content .htab[data-pg]').forEach(x=>x.classList.toggle('on',x===t));renderPageBody();});
  renderPDBody();renderReyBody();renderYoastBody();renderPageBody();
  wireCbx('#content');wireChips('#content');wireMini('#content');
  $('#content').scrollTop=0;
}
function closeDrawer(){$('#drawerBg').classList.remove('on');$('#drawer').classList.remove('on');}

/* ===================== IMPORT / EXPORT ===================== */
let impStep=1,impSource='woocsv',impData={products:true,taxos:true,orders:true,customers:true,users:true,reviews:true,coupons:true,seo:true};
function renderImport(){
  const steps=['Source','What to import','Field mapping','Validate in Sandbox','Import'];
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Import / Export</h2><p>Bring your WooCommerce store across — products, images, variations, pricing, SEO, orders, customers and logins. Nothing on the live site is touched.</p></div>
    <div class="wiz"><div class="steps">${steps.map((s,i)=>`<div class="stp ${impStep===i+1?'on':impStep>i+1?'done':''}"><span class="sn">${impStep>i+1?ic(I.check):i+1}</span>${s}</div>`).join('')}</div>
      <div class="card pad" id="impBody"></div></div></div>`;
  impBody();
}
function impNav(){return `<div class="row" style="justify-content:space-between;margin-top:20px"><button class="btn ghost" ${impStep===1?'disabled':''} onclick="impBack()">Back</button><button class="btn" onclick="impNext()">${impStep===4?'Looks good — continue':'Continue'} →</button></div>`;}
function impBody(){
  const b=$('#impBody');
  if(impStep===1){
    b.innerHTML=`<b style="font-size:14px">Where is your data coming from?</b><p style="font-size:12.5px;color:var(--ink-soft);margin:5px 0 16px">Pick how you'll provide the export from kbeautybliss.com.</p>
    <div style="display:flex;flex-direction:column;gap:10px">${[['woocsv','WooCommerce CSV','Products export from Woo → Products → Export'],['wpxml','WordPress XML','Tools → Export (posts, pages, media)'],['wcrest','WooCommerce REST API','Live pull via API keys — products, orders, customers'],['json','JSON file','Custom JSON in our import format']].map(o=>`<div class="optcard${impSource===o[0]?' on':''}" data-s="${o[0]}"><div class="oi">${ic(I.sandbox)}</div><div style="flex:1"><b>${o[1]}</b><p>${o[2]}</p></div><span class="cbx${impSource===o[0]?' on':''}">${ic(I.check)}</span></div>`).join('')}</div>${impNav()}`;
    $$('#impBody .optcard').forEach(c=>c.onclick=()=>{impSource=c.dataset.s;impBody();});
  } else if(impStep===2){
    const items=[['products','Products','incl. images, variations, prices, SKUs'],['taxos','Categories, Tags, Brands, Attributes',''],['orders','Orders','2,419 incl. line items & statuses'],['customers','Customers','addresses & history'],['users','Users with logins','passwords preserved (phpass verify)'],['reviews','Reviews','45 reviews'],['coupons','Coupons',''],['seo','SEO meta','Yoast titles, descriptions, redirects']];
    b.innerHTML=`<b style="font-size:14px">What should we bring across?</b><p style="font-size:12.5px;color:var(--ink-soft);margin:5px 0 16px">Everything's on by default — untick anything you don't need.</p>
    <div style="display:flex;flex-direction:column;gap:8px">${items.map(it=>`<div class="optcard${impData[it[0]]?' on':''}" data-d="${it[0]}"><span class="cbx${impData[it[0]]?' on':''}">${ic(I.check)}</span><div><b>${it[1]}</b>${it[2]?`<p>${it[2]}</p>`:''}</div></div>`).join('')}</div>${impNav()}`;
    $$('#impBody .optcard').forEach(c=>c.onclick=()=>{impData[c.dataset.d]=!impData[c.dataset.d];impBody();});
  } else if(impStep===3){
    const maps=[['Name','name'],['Regular price','price'],['Sale price','sale_price'],['SKU','sku'],['Categories','category'],['Images','images[]'],['Stock','stock_qty'],['Description','description'],['_yoast_wpseo_title','seo.title']];
    b.innerHTML=`<b style="font-size:14px">Field mapping · Products</b><p style="font-size:12.5px;color:var(--ink-soft);margin:5px 0 16px">Matched automatically — adjust if your export uses different columns.</p>
    <div class="card" style="box-shadow:none"><table><thead><tr><th>WooCommerce field</th><th></th><th>KBB field</th></tr></thead><tbody>${maps.map(m=>`<tr><td style="font-family:var(--mono);font-size:11.5px">${m[0]}</td><td style="color:var(--ink-faint)">${ic('<path d="M5 12h14M13 6l6 6-6 6"/>')}</td><td><span class="pill green">${m[1]}</span></td></tr>`).join('')}</tbody></table></div>${impNav()}`;
  } else if(impStep===4){
    b.innerHTML=`<b style="font-size:14px">Dry-run in Sandbox</b><p style="font-size:12.5px;color:var(--ink-soft);margin:5px 0 16px">We simulate the whole import against a sandbox copy of live — zero risk.</p>
    <div class="checks">${[['Source parsed','642 products found'],['Images reachable','3,948 URLs OK · 2 flagged'],['No ID conflicts','matched on woo_id'],['Variations linked','1,180 variations'],['Logins verifiable','phpass hashes valid'],['Migration dry-run','non-destructive']].map(c=>`<div class="check"><div class="ci green">${ic(I.check)}</div><b>${c[0]}</b><small>${c[1]}</small></div>`).join('')}</div>
    <div class="diff" style="margin-top:16px"><span><b class="plus">642</b> products</span><span><b class="plus">1,180</b> variations</span><span><b class="plus">3,948</b> images</span><span><b class="plus">48</b> categories</span><span><b class="plus">5,312</b> customers</span><span><b class="plus">2,419</b> orders</span><span><b>0</b> conflicts</span></div>${impNav()}`;
  } else {
    b.innerHTML=`<b style="font-size:14px">Import to live</b><p style="font-size:12.5px;color:var(--ink-soft);margin:5px 0 16px" id="impMsg">A backup of the live database is taken automatically before importing.</p>
    <div style="display:flex;flex-direction:column;gap:13px">${[['Products','imp1'],['Images','imp2'],['Orders','imp3'],['Customers & logins','imp4'],['SEO meta','imp5']].map(x=>`<div><div class="between" style="margin-bottom:6px"><span style="font-size:12.5px;font-weight:600">${x[0]}</span><span style="font-size:11px;color:var(--ink-soft)" id="${x[1]}t">queued</span></div><div class="pbar"><i id="${x[1]}"></i></div></div>`).join('')}</div>
    <div class="row" style="justify-content:flex-end;gap:10px;margin-top:18px"><button class="btn ghost" onclick="impGo(4)">Back</button><button class="btn" id="runImp" onclick="runImport()">${ic(I.rocket)} Start import</button></div>`;
  }
}
function runImport(){
  $('#runImp').disabled=true;$('#runImp').innerHTML='Importing…';
  const bars=['imp1','imp2','imp3','imp4','imp5'];let k=0;
  const step=()=>{if(k>=bars.length){$('#impMsg').innerHTML='<b style="color:var(--accent-strong)">Import complete ✓</b> — your catalogue is now in the app.';toast('Import complete ✓');return;}
    const id=bars[k];$('#'+id).style.width='100%';$('#'+id+'t').textContent='done';k++;setTimeout(step,640);};
  setTimeout(step,400);
}
window.openProduct=openProduct;window.closeDrawer=closeDrawer;window.runImport=runImport;
window.clearSel=()=>{catSel.clear();catProducts();};
window.impNext=()=>{impStep++;renderImport();};window.impBack=()=>{impStep--;renderImport();};window.impGo=n=>{impStep=n;renderImport();};

/* ===================== PRODUCT LABELS ===================== */
const OCCASIONS=[['none','None'],['eid','Eid'],['ramadan','Ramadan'],['xmas','Christmas'],['ny','New Year'],['bf','Black Friday'],['summer','Summer Sale']];
const LABELS=[
 {name:'Eid Mega Sale',type:'image',occ:'eid',pos:'tl',size:'M',apply:'On-sale products',excl:'—',status:'Active',color:'#1b9e77',ic:'🌙'},
 {name:'Up to 30% Off',type:'text',occ:'none',pos:'tr',size:'S',apply:'Categories: Serums, Sun Care',excl:'2 products',status:'Active',color:'#d6455a',ic:'%'},
 {name:'Christmas Gift',type:'image',occ:'xmas',pos:'bl',size:'M',apply:'Category: Sets & Bundles',excl:'—',status:'Scheduled · Dec 1–26',color:'#c13e63',ic:'🎁'},
 {name:'Bestseller',type:'text',occ:'none',pos:'tr',size:'S',apply:'Best Sellers category',excl:'—',status:'Active',color:'#0f8f4b',ic:'★'}
];
const POSN={tl:'Top-left',tc:'Top',tr:'Top-right',ml:'Left',mc:'Center',mr:'Right',bl:'Bottom-left',bc:'Bottom',br:'Bottom-right'};
function posStyle(pos){const m='9px';return ({tl:`top:${m};left:${m}`,tc:`top:${m};left:50%;transform:translateX(-50%)`,tr:`top:${m};right:${m}`,ml:`top:50%;left:${m};transform:translateY(-50%)`,mc:`top:50%;left:50%;transform:translate(-50%,-50%)`,mr:`top:50%;right:${m};transform:translateY(-50%)`,bl:`bottom:${m};left:${m}`,bc:`bottom:${m};left:50%;transform:translateX(-50%)`,br:`bottom:${m};right:${m}`}[pos])||`top:${m};left:${m}`;}
function sizeStyle(s){return s==='L'?'font-size:12px;padding:6px 11px':s==='M'?'font-size:11px;padding:5px 9px':'font-size:10px;padding:4px 8px';}


/* ---------- Store · Delivery & Shipping ----------
   Edits the shipping_methods rows the storefront already reads. The zones and
   their countries are seeded and are not editable here yet — that is a larger
   screen and would have been guesswork; what was actually missing was any way
   to change the charge and the free-delivery threshold. */
let SH=null;

function shBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/shipping'; }
function shMoney(fils){ return Math.round(Number(fils||0)/100); }

async function renderShipping(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Delivery &amp; Shipping</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(shBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    SH=await r.json();
    await loadExtended();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the delivery settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderShipping()">Retry</button></div></div>`;
    return;
  }
  paintShipping();
}
function shFind(id){ for(const z of SH.zones){ const m=z.methods.find(x=>x.id===id); if(m) return m; } return null; }

function shMethod(m){
  const free = m.type==='free_shipping';
  return `<div class="shrow${m.enabled?'':' off'}">
    <span class="ectog${m.enabled?' on':''}" data-shon="${m.id}" role="switch" aria-checked="${m.enabled}" tabindex="0"></span>
    <div class="shlbl">
      <input type="text" value="${escAttr(m.title)}" data-shtitle="${m.id}" maxlength="60">
      <span>${free?'Applies once the order reaches the amount below.':'Charged when free delivery does not apply.'}</span>
    </div>
    <span class="shamt">
      <i>${escHtml(SH.currency)}</i>
      ${free
        ? `<input type="number" min="0" step="1" value="${shMoney(m.min_amount)}" data-shmin="${m.id}">`
        : `<input type="number" min="0" step="1" value="${shMoney(m.cost)}" data-shcost="${m.id}">`}
      <u>${free?'and over':'per order'}</u>
    </span>
  </div>`;
}

/* Three baskets against the zone's own numbers, so the rule reads as a sentence
   rather than two figures to reconcile. */
function shPreview(z){
  const flat=z.methods.find(m=>m.type!=='free_shipping'&&m.enabled);
  const free=z.methods.find(m=>m.type==='free_shipping'&&m.enabled);
  const t=free?Number(free.min_amount||0):null;
  const c=flat?Number(flat.cost||0):0;
  const line=(fils)=>{
    const isFree = t!==null && fils>=t;
    return `<div class="shpv-r${isFree?' yes':''}"><b>${SH.currency} ${shMoney(fils)}</b>
      <span>${isFree?'Free delivery':(flat?`${SH.currency} ${shMoney(c)} delivery`:'No delivery method')}</span></div>`;
  };
  if(t===null && !flat) return '<p class="mmpv-note">No method is switched on for this zone, so nothing can be delivered to it.</p>';
  const at = t===null ? 20000 : t;
  return `<div class="shpv">${line(Math.max(0,at-100))}${line(at)}${line(at+50000)}</div>
    <p class="mmpv-note">${t===null?'No free-delivery method is on for this zone.':`Free from ${SH.currency} ${shMoney(t)}.`}</p>`;
}

let SHTAB='zones';

function shIntro(){
  const codes=[...new Set(SH.zones.flatMap(z=>z.locations))];
  return `<p class="mdesc" style="margin:0 0 10px">What delivery costs, and the order value at which it becomes free.
    <b>${codes.length?escHtml(codes.join(', ')):'Your'} delivery charges are set on the <u>Zones</u> tab below</b> —
    that is where UAE and the Gulf countries live today. <u>Extended</u> is only for adding countries
    beyond those.</p>`;
}

function paintShipping(){
  if(SHTAB==='extended'){ paintExtended(); return; }
  if(SHTAB==='gift'){ paintGift(); return; }
  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}
    ${SH.zones.map(z=>`<div class="card mdcard">
      <div class="mmhd"><b>${escHtml(z.name)}</b><span>${z.locations.length} ${z.locations.length===1?'country':'countries'} · ${escHtml(z.locations.join(', '))}</span></div>
      <div class="shgrid">
        <div class="shbody">${z.methods.map(shMethod).join('')}</div>
        <div class="shpv-wrap" id="shpv-${z.id}">${shPreview(z)}</div>
      </div></div>`).join('')}
    <div class="ecsave">
      <span class="ecdirty" id="shDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="shSave">Save changes</button>
    </div>
  </div>`;
  bindShipping();
}

function bindShTabs(){
  // Called from both bindShipping() and bindExtended(): whichever view is on
  // screen, its copy of the Zones/Extended buttons needs a handler, since
  // paintShipping() re-renders the tab strip fresh every time.
  $$('[data-shtab]').forEach(b=>b.onclick=()=>{ SHTAB=b.dataset.shtab; paintShipping(); });
}

function bindShipping(){
  bindShTabs();
  const dirty=()=>{ const d=$('#shDirty'); if(d) d.style.visibility='visible'; };
  const repaint=(id)=>{
    const z=SH.zones.find(z=>z.methods.some(m=>m.id===id));
    const el=$('#shpv-'+z.id); if(el) el.innerHTML=shPreview(z);
  };

  $$('[data-shon]').forEach(el=>el.onclick=()=>{
    const m=shFind(Number(el.dataset.shon)); if(!m) return;
    m.enabled=!m.enabled; dirty(); paintShipping();
  });
  $$('[data-shtitle]').forEach(el=>el.oninput=()=>{
    const m=shFind(Number(el.dataset.shtitle)); if(m){ m.title=el.value; dirty(); }
  });
  $$('[data-shcost]').forEach(el=>el.oninput=()=>{
    const id=Number(el.dataset.shcost), m=shFind(id);
    if(m){ m.cost=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); repaint(id); }
  });
  $$('[data-shmin]').forEach(el=>el.oninput=()=>{
    const id=Number(el.dataset.shmin), m=shFind(id);
    if(m){ m.min_amount=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); repaint(id); }
  });

  const save=$('#shSave');
  if(save) save.onclick=async()=>{
    const methods=[];
    SH.zones.forEach(z=>z.methods.forEach(m=>methods.push(
      {id:m.id,title:m.title,enabled:m.enabled,cost:m.cost,min_amount:m.min_amount})));
    save.disabled=true;
    try{
      const r=await fetch(shBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({methods})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#shDirty').style.visibility='hidden';
      toast('Delivery saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}


/* ---------- Store · Delivery & Shipping · Extended ----------
   Per-country charges, a country list, and detection. Off by default; while off
   it changes nothing and the zone rules answer as they always have. */
let XD=null;

function xdBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/extended-delivery'; }
function shTabs(){
  // A short caption under the strip, not just the bare word "Zones" — the
  // one thing that was easy to skim past when looking for where the Gulf
  // charges live.
  return `<div class="ectabs">
    <button class="ectab${SHTAB==='zones'?' on':''}" data-shtab="zones">Zones</button>
    <button class="ectab${SHTAB==='extended'?' on':''}" data-shtab="extended">Extended${XD&&XD.on?'<span class="ecn">on</span>':''}</button>
    <button class="ectab${SHTAB==='gift'?' on':''}" data-shtab="gift">Gift wrapping${GIFT&&GIFT.gift_enabled==='1'?'<span class="ecn">on</span>':''}</button>
  </div>
  <p class="ectabs-hint">${SHTAB==='zones'?'Your current delivery charges — including the Gulf countries — are the cards below.':SHTAB==='gift'?'An optional gift-wrap tick at checkout, and what it costs.':'Countries added here on top of your zones.'}</p>`;
}

/* ---------- Store · Delivery & Shipping · Gift wrapping ----------
 *
 * Lives here rather than on Business Details because it is a fulfilment
 * charge: it rides the same order total as delivery, and the field it
 * controls sits in the Delivery step of checkout.
 *
 * Self-contained on purpose. SETTINGS, sval() and loadSettings() belong to
 * the Business Details block, which is a different script scope -- reaching
 * for them from here throws a ReferenceError and takes the whole Delivery &
 * Shipping screen down with it. This block talks to /admin-api/settings
 * directly, the same way loadExtended() talks to its own endpoint.
 */
let GIFT=null;

function giftBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/settings'; }

function giftCookie(name){
  var m = document.cookie.match(new RegExp('(^| )'+name+'=([^;]+)'));
  return m ? decodeURIComponent(m[2]) : '';
}

async function loadGift(){
  try{
    const r = await fetch(giftBase(), {credentials:'same-origin', headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    const d = await r.json();
    GIFT = d.settings || {};
  }catch(e){ GIFT = {}; }
  return GIFT;
}

async function paintGift(){
  if(!GIFT) await loadGift();

  // Read straight from the stored value -- no default guessed here. A
  // migration seeds the row, so this screen and the storefront are looking at
  // the same string. Guessing a default in two places is what made the tab
  // read Off above a checkout that was showing the tick.
  const on = GIFT.gift_enabled === '1';
  const fee = GIFT.gift_fee ? (parseInt(GIFT.gift_fee, 10) / 100) : 0;

  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}
    <div class="card mdcard">
      <div class="mmhd"><b>Gift wrapping</b><span>Shown in the Delivery step at checkout</span></div>
      <div style="padding:16px 18px 18px">
        <p class="mdesc" style="margin:0 0 14px">With this on, shoppers see a <b>This order is a gift</b> tick and a
          600-character message printed on the card. The fee below is added to the order total, alongside any
          cash-on-delivery fee. Set it to 0 to offer wrapping free.</p>
        <div class="g2">
          <div class="fld"><label>Offer gift wrapping</label>
            <select id="gf_on"><option value="0"${on?'':' selected'}>Off</option><option value="1"${on?' selected':''}>On</option></select>
          </div>
          <div class="fld"><label>Gift wrapping fee (AED)</label>
            <input id="gf_fee" type="number" step="1" min="0" value="${fee}">
          </div>
        </div>
      </div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="gfDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="gfSave">Save changes</button>
    </div>
  </div>`;

  bindShTabs();

  const dirty=()=>{ const d=document.getElementById('gfDirty'); if(d) d.style.visibility='visible'; };
  ['gf_on','gf_fee'].forEach(id=>{ const el=document.getElementById(id); if(el) el.onchange=dirty; });

  document.getElementById('gfSave').onclick=async function(){
    const val=id=>{ const el=document.getElementById(id); return el?el.value:''; };
    const payload={
      gift_enabled: val('gf_on'),
      // fils, like cod_fee and delivery_flat, so Money::format handles it and
      // no screen has to remember which unit this one uses.
      gift_fee: String(Math.round((parseFloat(val('gf_fee'))||0)*100))
    };
    try{
      const r = await fetch(giftBase(), {
        method:'PUT',
        credentials:'same-origin',
        headers:{
          'Accept':'application/json',
          'Content-Type':'application/json',
          'X-XSRF-TOKEN': giftCookie('XSRF-TOKEN')
        },
        body: JSON.stringify({settings: payload})
      });
      if(!r.ok) throw new Error(r.status);
      const res = await r.json().catch(()=>({}));
      // The endpoint answers ok even when it skipped every key, which is how
      // this screen reported success while writing nothing. Trust the counts.
      if(res.rejected && res.rejected.length){
        toast('Not saved: '+res.rejected.join(', ')+' \u2014 the server rejected these keys');
        return;
      }
      if(res.saved === 0){ toast('Nothing was saved \u2014 check the server log'); return; }
      Object.assign(GIFT, payload);
      const d=document.getElementById('gfDirty'); if(d) d.style.visibility='hidden';
      toast('Gift wrapping saved');
      GIFT = null;
      paintGift();
    }catch(e){ toast('Save failed \u2014 check connection'); }
  };
}

async function loadExtended(){
  const r=await fetch(xdBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!r.ok) throw new Error(r.status);
  XD=await r.json();
  // The table can be legitimately absent — a migration that has not run yet.
  // Say so plainly rather than let the tab behave as if nothing is wrong.
  if(XD.ready===false) toast('Extended delivery: its database table is missing. Re-apply the last update, or check the server log.');
}

function xdName(c){ return (XD.names||{})[c] || c; }
function xdMoney(f){ return Math.round(Number(f||0)/100); }
function xdChosen(){ return XD.rows.map(r=>r.code); }

function xdPick(){
  const chosen=xdChosen();
  const q=(XD._q||'').toLowerCase();
  const codes=Object.keys(XD.names)
    .filter(c=>!q || xdName(c).toLowerCase().includes(q) || c.toLowerCase()===q)
    .sort((a,b)=>xdName(a).localeCompare(xdName(b)));
  return `<div class="cpick">
    <div class="cpick-hd">
      <input type="search" id="xdSearch" placeholder="Search ${Object.keys(XD.names).length} countries…" value="${escAttr(XD._q||'')}">
      <span class="chips">${Object.keys(XD.regions).map(r=>`<button class="chip" data-xdreg="${escAttr(r)}">${escHtml(r)}</button>`).join('')}
        <button class="chip" data-xdclear="1">Clear all</button></span>
    </div>
    <div class="cpick-b">${codes.map(c=>`
      <label class="cchk${chosen.includes(c)?' on':''}"><input type="checkbox" data-xdc="${c}"${chosen.includes(c)?' checked':''}>
        <span>${escHtml(xdName(c))}</span></label>`).join('')}</div>
  </div>`;
}

function xdRow(r,i){
  return `<div class="crow">
    <span class="ectog${r.enabled?' on':''}" data-xdon="${i}" role="switch" aria-checked="${r.enabled}" tabindex="0"></span>
    <span class="cname">${escHtml(xdName(r.code))} <em>${escHtml(r.code)}</em></span>
    <span class="cfield"><i>Charge</i><input type="number" min="0" step="1" value="${xdMoney(r.charge)}" data-xdcharge="${i}"></span>
    <span class="cfield"><i>Free from</i><input type="number" min="0" step="1" placeholder="never" value="${r.free_from===null?'':xdMoney(r.free_from)}" data-xdfree="${i}"></span>
    <span class="cfield"><i>Arrives in</i><input type="text" class="wide" maxlength="40" value="${escAttr(r.eta||'')}" data-xdeta="${i}"></span>
    <button class="crm" data-xdrm="${i}" title="Remove">✕</button>
  </div>`;
}

function paintExtended(){
  const on=XD.on;
  $('#content').innerHTML=`<div class="wrap ecwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Delivery &amp; Shipping</h2>
      ${shIntro()}</div>
    ${shTabs()}

    <div class="card mdcard">
      <div class="mmhd"><b>Extended delivery</b><span>Your ${XD.zoneCount||0} zone ${XD.zoneCount===1?'country always delivers':'countries always deliver'} — this adds more</span></div>
      <div class="mmbody">
        <div class="mmrow"><div class="mmlbl"><b>Deliver to more countries</b>
          <span>Adds the countries below on top of your zones. Your zones are never affected — turning this off just removes the extra countries, nothing else.</span></div>
          <span class="ectog${on?' on':''}" data-xdmain="1" role="switch" aria-checked="${on}" tabindex="0"></span></div>
        <div class="mmrow"><div class="mmlbl"><b>Detect the shopper's country</b>
          <span>Pre-selects it at checkout, for your zone countries as well as any added here. A saved address always wins, and once the shopper picks one nothing overrides it.</span></div>
          <span class="ectog${XD.detect?' on':''}" data-xddetect="1" role="switch" aria-checked="${XD.detect}" tabindex="0"></span></div>
        <div class="mmrow${on?'':' dim'}"><div class="mmlbl"><b>List countries you do not deliver to</b>
          <span>Off keeps the list short. On shows them so a shopper learns why, rather than not finding their country.</span></div>
          <span class="ectog${XD.show_all?' on':''}" data-xdshowall="1" role="switch" aria-checked="${XD.show_all}" tabindex="0"></span></div>
      </div>
    </div>

    <div class="card mdcard${on?'':' dim'}">
      <div class="mmhd"><b>Additional countries</b><span>${XD.rows.length} chosen · your zone countries are not listed here</span></div>
      <div class="mmbody">${xdPick()}</div>
    </div>

    <div class="card mdcard${on?'':' dim'}">
      <div class="mmhd"><b>Charges</b><span>Leave “free from” empty for no free delivery</span></div>
      <div class="mmbody">
        ${XD.rows.length ? XD.rows.map(xdRow).join('')
          : '<p class="mmpv-note" style="padding:14px 0">No additional countries chosen yet. Tick some above.</p>'}
        ${XD.rows.length>1 ? '<div style="display:flex;gap:8px;margin-top:12px"><button class="btn small" data-xdcopy="1">Copy first row\'s charges to all</button></div>' : ''}
      </div>
    </div>

    <div class="ecsave">
      <span class="ecdirty" id="xdDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="xdSave">Save changes</button>
    </div>
  </div>`;
  bindExtended();
}

function bindExtended(){
  bindShTabs();
  const dirty=()=>{ const d=$('#xdDirty'); if(d) d.style.visibility='visible'; };

  const flag=(sel,key)=>{ const el=$(sel); if(el) el.onclick=()=>{ XD[key]=!XD[key]; dirty(); paintExtended(); }; };
  flag('[data-xdmain]','on'); flag('[data-xddetect]','detect'); flag('[data-xdshowall]','show_all');

  const search=$('#xdSearch');
  if(search) search.oninput=()=>{ XD._q=search.value; paintExtended();
    const again=$('#xdSearch'); if(again){ again.focus(); again.setSelectionRange(again.value.length,again.value.length); } };

  $$('[data-xdc]').forEach(cb=>cb.onchange=()=>{
    const c=cb.dataset.xdc;
    if(cb.checked){ if(!xdChosen().includes(c)) XD.rows.push({code:c,enabled:true,charge:0,free_from:null,eta:''}); }
    else XD.rows=XD.rows.filter(r=>r.code!==c);
    dirty(); paintExtended();
  });
  $$('[data-xdreg]').forEach(b=>b.onclick=()=>{
    (XD.regions[b.dataset.xdreg]||[]).forEach(c=>{
      if(XD.names[c] && !xdChosen().includes(c)) XD.rows.push({code:c,enabled:true,charge:0,free_from:null,eta:''});
    });
    dirty(); paintExtended();
  });
  const clear=$('[data-xdclear]');
  if(clear) clear.onclick=()=>{ XD.rows=[]; dirty(); paintExtended(); };

  $$('[data-xdon]').forEach(el=>el.onclick=()=>{ const r=XD.rows[+el.dataset.xdon]; r.enabled=!r.enabled; dirty(); paintExtended(); });
  $$('[data-xdrm]').forEach(el=>el.onclick=()=>{ XD.rows.splice(+el.dataset.xdrm,1); dirty(); paintExtended(); });
  $$('[data-xdcharge]').forEach(el=>el.oninput=()=>{ XD.rows[+el.dataset.xdcharge].charge=Math.max(0,Math.round(Number(el.value)||0))*100; dirty(); });
  $$('[data-xdfree]').forEach(el=>el.oninput=()=>{
    const r=XD.rows[+el.dataset.xdfree];
    r.free_from = el.value.trim()==='' ? null : Math.max(0,Math.round(Number(el.value)||0))*100;
    dirty();
  });
  $$('[data-xdeta]').forEach(el=>el.oninput=()=>{ XD.rows[+el.dataset.xdeta].eta=el.value; dirty(); });

  const copy=$('[data-xdcopy]');
  if(copy) copy.onclick=()=>{
    if(!XD.rows.length) return;
    const first=XD.rows[0];
    XD.rows.forEach(r=>{ r.charge=first.charge; r.free_from=first.free_from; r.eta=first.eta; });
    dirty(); paintExtended(); toast('Charges copied to every country');
  };

  const save=$('#xdSave');
  if(save) save.onclick=async()=>{
    save.disabled=true;
    try{
      const r=await fetch(xdBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({on:XD.on,detect:XD.detect,show_all:XD.show_all,
          rows:XD.rows.map(r=>({code:r.code,enabled:r.enabled,charge:r.charge,free_from:r.free_from,eta:r.eta||''}))})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#xdDirty').style.visibility='hidden';
      toast('Extended delivery saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Store · Payment & Shipping Rules ----------
   Ported from the plugin's pay_ship_rules module. Two rules; the second edits a
   setting that already existed rather than a new key of its own. */
let PSR=null;

function psrBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/pay-ship-rules'; }

async function renderPayShip(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Payment &amp; Shipping Rules</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(psrBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PSR=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the rules.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderPayShip()">Retry</button></div></div>`;
    return;
  }
  paintPayShip();
}
function psrGet(k){ for(const t of PSR.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function psrSet(k,v){ for(const t of PSR.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

/* Amounts are held in fils everywhere in this app but nobody thinks in fils, so
   the field shows and accepts whole currency and converts on the way in and out. */
function psrField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-ps="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='money')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="psmoney"><i>${escHtml(PSR.currency)}</i>
        <input type="number" min="0" step="1" value="${Math.round(Number(v)/100)}" data-ps="${f.key}"></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
    <input type="text" value="${escAttr(String(v))}" data-ps="${f.key}"></div>`;
}

/* Three order values against the window, so the effect is visible rather than
   something to work out from two numbers. */
function psrPreview(){
  const min=Number(psrGet('cod_min')), max=Number(psrGet('cod_max'));
  const money=(f)=>`${PSR.currency} ${Math.round(f/100)}`;
  const row=(fils)=>{
    const blocked=(min>0&&fils<min)||(max>0&&fils>max);
    return `<div class="psrow${blocked?' no':' yes'}">
      <b>${money(fils)}</b><span>${blocked?'Cash on delivery hidden':'Cash on delivery offered'}</span></div>`;
  };
  const mid = max>0 ? Math.round((Math.max(min,0)+max)/2) : Math.max(min,0)+20000;
  return `<div class="pspv">
      ${row(Math.max(0,min>0?min-1000:1000))}
      ${row(mid)}
      ${row(max>0?max+1000:mid+50000)}
    </div>
    <p class="mmpv-note">${min===0&&max===0?'No limits set — Cash on delivery is always offered.':'Zero means no limit at that end.'}</p>`;
}

function paintPayShip(){
  const tab=PSR.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Payment &amp; Shipping Rules</h2>
      <p class="mdesc" style="margin:0">Limit Cash on delivery by order value, and offer only free delivery when it applies.</p></div>
    ${PSR.module_on?'':`<div class="nlwarn">This module is off, so these rules are not applied.
      Turn <b>Payment &amp; Shipping Rules</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(psrField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${psrPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="psDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="psSave">Save changes</button>
    </div>
  </div>`;
  bindPayShip();
}

function bindPayShip(){
  const dirty=()=>{ const d=$('#psDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-ps]').forEach(el=>{
    const k=el.dataset.ps;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); psrSet(k,v); dirty(); paintPayShip(); };
      return;
    }
    el.oninput=()=>{ psrSet(k, Math.max(0, Math.round(Number(el.value)||0)) * 100); dirty();
      $('.mmpv-in').innerHTML=psrPreview(); };
  });
  const save=$('#psSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of PSR.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(psrBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#psDirty').style.visibility='hidden';
      toast('Rules saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}


/* ---------- Growth & Marketing · Marketing Pixels ----------
   Ported from the plugin's marketing_pixels module: Meta, GA4 and TikTok IDs.
   Each fires independently once its ID is filled in — the module switch is a
   gate in front of them, not itself a source of any event. Separate from the
   existing "Meta & Facebook" screen, which is design-preview work for a much
   larger Conversions-API/catalog-feed feature and not this module. */
let MP=null;

function mpBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/marketing-pixels'; }

async function renderPixels(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Marketing Pixels</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(mpBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    MP=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the pixel settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderPixels()">Retry</button></div></div>`;
    return;
  }
  paintPixels();
}
function mpGet(k){ for(const t of MP.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return ''; }
function mpSet(k,v){ for(const t of MP.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function mpField(f){
  // .ecopt.wide is the Ecommerce screen's own layout for exactly this shape —
  // a full-width text field with a help sentence long enough to wrap. Reused
  // rather than the compact .mmrow (built for a short toggle or a narrow
  // number field), which put the input on the same line as multi-line help
  // text and the two overlapped.
  return `<div class="ecopt wide">
    <div class="ecom"><div class="ecl"><label>${escHtml(f.label)}</label></div>
      ${f.help?`<div class="echelp">${escHtml(f.help)}</div>`:''}</div>
    <div class="ecctl"><input type="text" class="inp" placeholder="${f.key==='ga4_id'?'G-XXXXXXXXXX':f.key==='tiktok_id'?'e.g. CABC123…':'e.g. 1234567890'}"
           value="${escAttr(f.value)}" data-mp="${f.key}" maxlength="60"></div></div>`;
}

function paintPixels(){
  const tab=MP.tabs[0];
  const anySet = tab.fields.some(f=>f.value);
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Marketing Pixels</h2>
      <p class="mdesc" style="margin:0">Meta, Google (GA4) and TikTok tags with standard e-commerce events.</p></div>
    ${MP.module_on?'':`<div class="nlwarn">This module is off, so none of these tags load, even for a filled-in ID.
      Turn <b>Marketing Pixels</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    ${MP.module_on && !anySet ? '<div class="nlwarn">On, but no ID is filled in below yet — nothing fires until at least one is.</div>' : ''}
    <div class="mmcard">
      <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
      <div class="mmbody">${tab.fields.map(mpField).join('')}</div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="mpDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="mpSave">Save changes</button>
    </div>
  </div>`;
  bindPixels();
}

function bindPixels(){
  const dirty=()=>{ const d=$('#mpDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-mp]').forEach(el=>el.oninput=()=>{ mpSet(el.dataset.mp, el.value); dirty(); });

  const save=$('#mpSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of MP.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(mpBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#mpDirty').style.visibility='hidden';
      toast('Pixels saved');
      paintPixels();
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}

/* ---------- Growth & Marketing · Product Labels ----------
   Ported from the plugin's product_labels module. Four automatic badges — sold
   out, sale, new, bestseller — with one showing at a time in that order.

   This replaces a mock that described a richer feature than the module has:
   image badges assigned per product or category, scheduled, several stacked on
   one thumbnail. None of that existed; it was a hard-coded array with no API
   behind it. The richer version is worth building, but it is a separate item —
   shipping the plugin's behaviour first means the screen now tells the truth. */
let PL=null;

function plBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api/product-labels'; }

async function renderLabels(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Product Labels</h2><p>Loading…</p></div></div>`;
  try{
    const r=await fetch(plBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
    if(!r.ok) throw new Error(r.status);
    PL=await r.json();
  }catch(e){
    const why=String(e.message||e);
    const hint = why==='404'
      ? 'The admin route is not registered — the cache-clearing migration for this release may not have run.'
      : why==='500' ? 'The server errored. Check storage/logs/laravel.log.' : 'The request did not complete.';
    $('#content').innerHTML=`<div class="wrap"><div class="card" style="padding:22px">
      <b>Could not load the label settings.</b>
      <p style="margin:6px 0 12px;color:#7b8697;font-size:12.5px">${escHtml(hint)} <code>${escHtml(why)}</code></p>
      <button class="btn small" onclick="renderLabels()">Retry</button></div></div>`;
    return;
  }
  paintLabels();
}
function plGet(k){ for(const t of PL.tabs){ const f=t.fields.find(x=>x.key===k); if(f) return f.value; } return null; }
function plSet(k,v){ for(const t of PL.tabs){ const f=t.fields.find(x=>x.key===k); if(f){ f.value=v; return; } } }

function plField(f){
  const v=f.value;
  if(f.type==='bool')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="ectog${v?' on':''}" data-pl="${f.key}" role="switch" aria-checked="${v}" tabindex="0"></span></div>`;
  if(f.type==='range'){ const o=f.options||{};
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
      <span class="mmrange"><input type="range" min="${o.min}" max="${o.max}" step="${o.step||1}" value="${v}" data-pl="${f.key}">
        <i id="plv-${f.key}">${v}${o.unit||''}</i></span></div>`; }
  if(f.type==='colour')
    return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b></div>
      <span class="mmcol"><input type="color" value="${v}" data-pl="${f.key}"><code>${v}</code></span></div>`;
  return `<div class="mmrow"><div class="mmlbl"><b>${escHtml(f.label)}</b>${f.help?`<span>${escHtml(f.help)}</span>`:''}</div>
    <input type="text" value="${escAttr(String(v))}" data-pl="${f.key}" maxlength="40"></div>`;
}

/* Four cards at the current text and colour, in the order the badge is chosen. */
function plPreview(){
  const card=(on,text,colour,caption)=>`<div class="plc${on?'':' off'}">
      <div class="plc-im">${on?`<span class="lbl" style="background:${escAttr(colour)}">${escHtml(text)}</span>`:''}</div>
      <div class="plc-cap">${escHtml(caption)}</div></div>`;
  const sale=String(plGet('sale_text')).replace('{off}','30');
  return `<div class="plgrid">
      ${card(plGet('oos_on'), plGet('oos_text'), plGet('oos_color'), 'Sold out')}
      ${card(plGet('sale_on'), sale, plGet('sale_color'), 'On sale, 30% off')}
      ${card(plGet('new_on'), plGet('new_text'), plGet('new_color'), `Added in the last ${plGet('new_days')} days`)}
      ${card(plGet('feat_on'), plGet('feat_text'), plGet('feat_color'), 'Marked featured')}
    </div>
    <p class="mmpv-note">One badge shows at a time, in this order.</p>`;
}

function paintLabels(){
  const tab=PL.tabs[0];
  $('#content').innerHTML=`<div class="wrap ecwrap mmwrap">
    <div class="echd"><h2 style="margin:0 0 3px;font-size:20px;letter-spacing:-.015em">Product Labels</h2>
      <p class="mdesc" style="margin:0">Sale, New, Sold-out and Bestseller badges on product cards and the product page.</p></div>
    ${PL.module_on?'':`<div class="nlwarn">This module is off, so none of these badges show and the theme's own are used instead.
      Turn <b>Product Labels</b> on under <a href="#modules" onclick="go('modules');return false;">Store → Modules</a>.</div>`}
    <div class="mmgrid">
      <div class="mmcols"><div class="card mmcard">
        <div class="mmhd"><b>${escHtml(tab.label)}</b><span>${escHtml(tab.description)}</span></div>
        <div class="mmbody">${tab.fields.map(plField).join('')}</div></div></div>
      <div class="mmpv"><div class="mmpv-in">${plPreview()}</div><p class="mmpv-note">Live preview</p></div>
    </div>
    <div class="ecsave">
      <span class="ecdirty" id="plDirty" style="visibility:hidden">Unsaved changes</span>
      <button class="btn primary" id="plSave">Save changes</button>
    </div>
  </div>`;
  bindLabels();
}

function bindLabels(){
  const dirty=()=>{ const d=$('#plDirty'); if(d) d.style.visibility='visible'; };
  $$('[data-pl]').forEach(el=>{
    const k=el.dataset.pl;
    if(el.classList.contains('ectog')){
      el.onclick=()=>{ const v=!el.classList.contains('on'); el.classList.toggle('on',v);
        el.setAttribute('aria-checked',String(v)); plSet(k,v); dirty(); paintLabels(); };
      return;
    }
    if(el.type==='range'){
      el.oninput=()=>{ plSet(k,Number(el.value)); dirty();
        const b=$('#plv-'+k); if(b){ const f=PL.tabs.flatMap(t=>t.fields).find(x=>x.key===k);
          b.textContent=el.value+((f.options||{}).unit||''); }
        $('.mmpv-in').innerHTML=plPreview(); };
      return;
    }
    el.oninput=()=>{ plSet(k,el.value); dirty(); $('.mmpv-in').innerHTML=plPreview();
      const c=el.parentElement.querySelector('code'); if(c) c.textContent=el.value; };
  });
  const save=$('#plSave');
  if(save) save.onclick=async()=>{
    const settings={};
    for(const t of PL.tabs) for(const f of t.fields) settings[f.key]=f.value;
    save.disabled=true;
    try{
      const r=await fetch(plBase(),{method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
        body:JSON.stringify({settings})});
      const d=await r.json();
      if(!r.ok||!d.ok) throw new Error(d.error||r.status);
      $('#plDirty').style.visibility='hidden';
      toast('Labels saved');
    }catch(e){ toast('Could not save: '+e.message); }
    finally{ save.disabled=false; }
  };
}
function lblSync(){const chip=$('#lblChip');if(!chip)return;const el=$('#lblName');const nm=(el&&el.value)||'Label';chip.style.cssText=posStyle(lblPos)+';'+sizeStyle(lblSize)+';background:'+(window.lblColor||'#15a85a');chip.textContent=(window.lblIc||'🏷️')+' '+nm;}

/* ===================== META & FACEBOOK ===================== */
function renderMeta(){
  $('#content').innerHTML=`<div class="wrap"><div class="page-head"><h2>Meta &amp; Facebook</h2><p>Pixel tracking, Conversions API and your product catalog feed for Facebook &amp; Instagram Shops and ads.</p></div>
    <div style="display:flex;flex-direction:column;gap:14px">
      <div class="card pad"><div class="between"><div class="row"><span class="lbl-card-thumb" style="background:#1877f2">f</span><div><b style="font-size:13.5px">Meta connection</b><div style="font-size:11.5px;color:var(--ink-soft)">Not connected — link your Business account to enable Pixel, CAPI and Catalog.</div></div></div><button class="btn" onclick="toast('Connect Meta (preview)')">Connect Facebook</button></div></div>
      <div class="metarow">
        ${peCard('Meta Pixel',`<div class="fld"><label>Pixel ID</label><input placeholder="e.g. 1234567890"></div><div class="fld" style="margin:0"><label>Events tracked</label><div class="tagchips">${['PageView','ViewContent','AddToCart','InitiateCheckout','Purchase'].map(e=>`<span class="tagchip on">${e}</span>`).join('')}</div></div>`)}
        ${peCard('Conversions API · server-side',`<div class="fld"><label>Access token</label><input type="password" value="••••••••••••"></div><div class="fld"><label>Test event code</label><input placeholder="TEST12345"></div><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx on">${ic(I.check)}</span> Send server events with deduplication</label>`)}
      </div>
      ${peCard('Catalog / Product feed',`<div class="g2"><div class="fld"><label>Catalog ID</label><input placeholder="e.g. 9876543210"></div><div class="fld"><label>Auto-sync</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;padding-top:9px"><span class="cbx on">${ic(I.check)}</span> Keep catalog in sync automatically</label></div></div><div class="fld"><label>Feed URL</label><div class="row" style="gap:8px"><input style="flex:1" readonly value="https://kbeautybliss.com/feed/meta-catalog.xml"><button class="btn ghost sm" onclick="toast('Copied')">Copy</button></div></div><div class="pubrow"><span>Last synced</span><b>—</b></div><div class="pubrow"><span>Products in feed</span><b>642</b></div><div class="pubrow" style="border:0"><span>Status</span><b style="color:var(--accent-strong)">Ready</b></div><button class="btn" style="margin-top:12px" onclick="toast('Sync started (preview)')">${ic(I.rocket)} Sync catalog now</button>`)}
      ${peCard('Catalog field mapping',`<div class="card" style="box-shadow:none"><table><thead><tr><th>Meta field</th><th></th><th>KBB field</th></tr></thead><tbody>${[['id','sku'],['title','name'],['description','short_description'],['availability','stock_status'],['condition','new'],['price','price'],['sale_price','sale_price'],['brand','brand'],['google_product_category','category_map'],['image_link','featured_image']].map(m=>`<tr><td style="font-family:var(--mono);font-size:11.5px">${m[0]}</td><td style="color:var(--ink-faint)">${ic('<path d="M5 12h14M13 6l6 6-6 6"/>')}</td><td><span class="pill green">${m[1]}</span></td></tr>`).join('')}</tbody></table></div>`)}
      ${peCard('Sync rules',`<label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;margin-bottom:10px"><span class="cbx on">${ic(I.check)}</span> Exclude out-of-stock products</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer;margin-bottom:10px"><span class="cbx on">${ic(I.check)}</span> Exclude drafts &amp; hidden products</label><label class="row" style="gap:8px;font-size:12.5px;cursor:pointer"><span class="cbx">${ic(I.check)}</span> Send sale price only during scheduled sales</label>`)}
    </div></div>`;
  wireCbx('#content');wireChips('#content');
}
window.lblSync=lblSync;

/* ===================== SHOP FILTERS (storefront panel control) ===================== */
const SF_LABELS={category:'Category',brand:'Brand',price:'Price',concern:'Skin concern',offers:'Offers'};
const SF_MANUAL={category:true,brand:true,concern:true,price:false,offers:false};
const SF_CONCERNS=['Hydration','Brightening','Acne','Soothing','Anti-aging','Pores','Sun protection'];
const SF_ITEMS={category:()=>CAT_CATEGORIES.map(c=>c[0]),brand:()=>CAT_BRANDS.map(b=>b[0]),concern:()=>SF_CONCERNS};
let SFCFG={order:['category','brand','price','concern','offers'],on:{category:true,brand:true,price:true,concern:true,offers:true},mode:{category:'all',brand:'all',concern:'all'},picks:{category:new Set(),brand:new Set(),concern:new Set()},sticky:true,cols:'4',counts:true};
function renderShopFilters(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Shop Filters</h2><p>Control exactly what appears in the storefront filter panel — turn groups on or off, reorder them, and hand-pick which categories, brands and concerns show.</p></div>
    <div class="grid2-sf" style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:start">
      <div style="display:flex;flex-direction:column;gap:14px">
        <div class="card pad"><div class="pe-h" style="margin-bottom:6px">Filter groups</div><div id="sfGroups"></div></div>
        <div class="card pad"><div class="pe-h" style="margin-bottom:12px">Panel display</div>
          <div class="pref"><div class="pl"><b>Sticky panel</b><small>Keeps filters in view while the page scrolls</small></div><div class="tog${SFCFG.sticky?' on':''}" data-sf="sticky"></div></div>
          <div class="pref"><div class="pl"><b>Show product counts</b><small>e.g. “Serums (64)”</small></div><div class="tog${SFCFG.counts?' on':''}" data-sf="counts"></div></div>
          <div class="pref" style="border:0"><div class="pl"><b>Default grid columns</b><small>Shoppers can still switch</small></div><div class="sfseg" id="sfCols">${['2','3','4'].map(c=>`<button data-c="${c}" class="${SFCFG.cols===c?'on':''}">${c}</button>`).join('')}</div></div>
          <button class="btn" style="margin-top:14px" onclick="toast('Shop filters saved (preview)')">Save changes</button>
        </div>
      </div>
      <div class="card pad sfprev"><div class="pe-h" style="margin-bottom:4px">Storefront preview</div><div id="sfPrev"></div></div>
    </div></div>`;
  renderSFGroups();renderSFPrev();
  $$('#content .tog[data-sf]').forEach(t=>t.onclick=()=>{SFCFG[t.dataset.sf]=!SFCFG[t.dataset.sf];t.classList.toggle('on');renderSFPrev();});
  $$('#sfCols button').forEach(b=>b.onclick=()=>{SFCFG.cols=b.dataset.c;$$('#sfCols button').forEach(x=>x.classList.toggle('on',x===b));});
}
function renderSFGroups(){
  const box=$('#sfGroups');
  box.innerHTML=SFCFG.order.map((g,i)=>{
    const manual=SF_MANUAL[g], mode=SFCFG.mode[g], on=SFCFG.on[g];
    let checks='';
    if(manual&&on&&mode==='manual'){checks=`<div class="sfchecks">${SF_ITEMS[g]().map(it=>`<span class="tagchip${SFCFG.picks[g].has(it)?' on':''}" data-pick="${g}" data-v="${it}">${it}</span>`).join('')}</div>`;}
    return `<div class="sfrow">
      <div class="sfmv"><button data-mv="up" data-i="${i}">${ic('<path d="m6 15 6-6 6 6"/>')}</button><button data-mv="down" data-i="${i}">${ic('<path d="m6 9 6 6 6-6"/>')}</button></div>
      <div style="flex:1;min-width:0"><b style="font-size:13.5px">${SF_LABELS[g]}</b>${manual?`<div style="font-size:11px;color:var(--ink-soft)">${mode==='all'?'Showing all':SFCFG.picks[g].size+' selected'}</div>`:`<div style="font-size:11px;color:var(--ink-soft)">Fixed options</div>`}</div>
      ${manual?`<div class="sfseg" data-modeg="${g}"><button data-m="all" class="${mode==='all'?'on':''}">Show all</button><button data-m="manual" class="${mode==='manual'?'on':''}">Manual</button></div>`:''}
      <div class="tog${on?' on':''}" data-on="${g}"></div>
    </div>${checks}`;
  }).join('');
  $$('#sfGroups .sfmv button').forEach(b=>b.onclick=()=>{const i=+b.dataset.i,j=b.dataset.mv==='up'?i-1:i+1;if(j<0||j>=SFCFG.order.length)return;[SFCFG.order[i],SFCFG.order[j]]=[SFCFG.order[j],SFCFG.order[i]];renderSFGroups();renderSFPrev();});
  $$('#sfGroups .tog[data-on]').forEach(t=>t.onclick=()=>{SFCFG.on[t.dataset.on]=!SFCFG.on[t.dataset.on];renderSFGroups();renderSFPrev();});
  $$('#sfGroups .sfseg[data-modeg] button').forEach(b=>b.onclick=()=>{SFCFG.mode[b.closest('.sfseg').dataset.modeg]=b.dataset.m;renderSFGroups();renderSFPrev();});
  $$('#sfGroups .tagchip[data-pick]').forEach(c=>c.onclick=()=>{const g=c.dataset.pick,s=SFCFG.picks[g];s.has(c.dataset.v)?s.delete(c.dataset.v):s.add(c.dataset.v);renderSFGroups();renderSFPrev();});
}
function renderSFPrev(){
  const box=$('#sfPrev');
  const groups=SFCFG.order.filter(g=>SFCFG.on[g]);
  if(!groups.length){box.innerHTML=`<p style="font-size:12px;color:var(--ink-soft)">All filter groups are hidden.</p>`;return;}
  box.innerHTML=groups.map(g=>{
    let items=[];
    if(g==='price')items=['Under AED 50','50–100','100–200','200+'];
    else if(g==='offers')items=['On sale','In stock'];
    else{const all=SF_ITEMS[g]();items=SFCFG.mode[g]==='manual'?[...SFCFG.picks[g]]:all;}
    if(!items.length)items=['(none selected)'];
    const shown=items.slice(0,8);
    return `<div class="fg"><div class="fgt">${SF_LABELS[g]}</div><div class="tagchips">${shown.map(t=>`<span class="tagchip">${t}${SFCFG.counts&&g!=='price'&&g!=='offers'&&t!=='(none selected)'?'':''}</span>`).join('')}${items.length>8?`<span class="tagchip" style="opacity:.6">+${items.length-8}</span>`:''}</div></div>`;
  }).join('');
}
window.renderShopFilters=renderShopFilters;

/* ---------- modal + toast + env ---------- */
function openModal(html){$('#modal').innerHTML=html;$('#modalBg').classList.add('on');}
function closeModal(){$('#modalBg').classList.remove('on');}
$('#modalBg').onclick=e=>{if(e.target===$('#modalBg'))closeModal();};
$('#drawerBg').onclick=closeDrawer;
let toastT;function toast(m){const t=$('#toast');t.innerHTML=ic(I.check)+'<span>'+m+'</span>';t.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>t.classList.remove('show'),2400);}
$$('#envtog button').forEach(b=>b.onclick=()=>{
  $$('#envtog button').forEach(x=>x.classList.remove('on'));b.classList.add('on');
  document.body.dataset.env=b.dataset.e;
  toast(b.dataset.e==='sandbox'?'Switched to Sandbox':'Switched to Live');
});
window.go=go;window.toast=toast;window.reportModal=reportModal;window.closeModal=closeModal;window.deploy=deploy;window.rollback=rollback;

/* ---------- console settings + theme ---------- */
/* id, name, gradient, bg, surface, accent, line, ink, accentSoft */
const THEMES=[
  ['aurora','Aurora Green','linear-gradient(135deg,#15a85a,#0f8f4b)','#f6f7fb','#ffffff','#15a85a','#e6e9f2','#101729','#e7f7ee'],
  ['indigo','Indigo','linear-gradient(135deg,#4f63e0,#3a4cc4)','#f6f7fb','#ffffff','#4f63e0','#e6e9f2','#101729','#ebedfd'],
  ['rose','Rose','linear-gradient(135deg,#e0567b,#c13e63)','#fbf7f8','#ffffff','#e0567b','#ede2e6','#2a2228','#fce6ee'],
  ['slate','Slate','linear-gradient(135deg,#4b5a72,#374255)','#f5f6f8','#ffffff','#4b5a72','#e5e8ee','#101729','#eef1f6'],
  ['midnight','Midnight','linear-gradient(135deg,#1b2235,#0c1120)','#0c1120','#141a2b','#22c06c','#28324b','#eef1f9','#10301f']
];
let theme='aurora';
let consolePrefs={density:'comfortable',landing:'dash',perpage:'50',lang:'en',reduce:false};
function setTheme(t){
  theme=t;
  if(t==='aurora')document.documentElement.removeAttribute('data-theme');
  else document.documentElement.dataset.theme=t;
  toast('Console theme: '+THEMES.find(x=>x[0]===t)[1]);
  if(cur==='console')$$('#content .theme-card').forEach(c=>c.classList.toggle('on',c.dataset.t===t));
}
function tpv(th){const[,,,bg,sf,ac,ln,ink,as]=th;
  return `<div class="tpv" style="background:${bg};border-color:${ln}">
    <div class="tpv-side" style="background:${sf};border-color:${ln}">
      <div class="tpv-logo" style="background:${ac}"></div>
      <div class="tpv-line" style="background:${as};width:80%"></div>
      <div class="tpv-line" style="background:${ln};width:64%"></div>
      <div class="tpv-line" style="background:${ln};width:72%"></div></div>
    <div class="tpv-main"><div class="tpv-bar" style="background:${ac}"></div>
      <div class="tpv-card2" style="background:${sf};border-color:${ln}"></div></div></div>`;
}
const segHTML=(opts,cur,pref)=>`<div class="seg" data-pref="${pref}">${opts.map(o=>`<button data-v="${o[0]}" class="${o[0]===cur?'on':''}">${o[1]}</button>`).join('')}</div>`;
const optHTML=(o,cur)=>Object.entries(o).map(([v,l])=>`<option value="${v}"${v===cur?' selected':''}>${l}</option>`).join('');
/* ---------- Demo Content ---------- */
const DEMO_CONTENT_TYPES=[
  ['orders','Demo Orders','Sample orders across every status — processing, shipped, refunded — so you can try the order detail page, refunds, and status changes on something real.','<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 01-8 0"/>','#4F46E5','#EEF2FF'],
  ['customers','Demo Customers','Sample customer accounts, for testing account pages, the customer history panel, and order lookups.','<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>','#2563EB','#EFF6FF'],
  ['products','Demo Products','Sample products with brands, categories and pricing already filled in — including a few on sale.','<path d="M21 8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/>','#059669','#ECFDF5'],
  ['pages','Demo Pages','A few sample static pages — About, Shipping, Returns — to preview the page layout before writing the real ones.','<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>','#7C3AED','#F5F3FF'],
  ['posts','Demo Blog Posts','Sample Journal articles, so the blog is not empty while you plan out real content.','<path d="M4 22h16a2 2 0 002-2V4a2 2 0 00-2-2H8a2 2 0 00-2 2v16a2 2 0 01-2 2zm0 0a2 2 0 01-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8z"/>','#D97706','#FFFBEB'],
  ['reviews','Demo Reviews','Sample product reviews at a mix of ratings, for testing the review moderation queue and star display.','<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>','#E0567B','#FDF2F6'],
  ['menu','Demo Mega Menu','A ready-made navigation menu with brand and category dropdowns already wired up and set live.','<path d="M3 12h18M3 6h18M3 18h18"/>','#0891B2','#ECFEFF'],
];
/**
 * Computes the admin-api base the same way pApiBase() does — from the
 * current page's own path, not a hardcoded leading slash. A hardcoded
 * '/admin-api/...' resolves against the domain root; on a subdirectory
 * deployment (the live site runs at easywebsol.com/kbb-upgrade/) that
 * silently points at a URL with no matching route at all, which is
 * exactly the "route could not be found" this shape of bug produces.
 */
function dcApiBase(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + '/admin-api'; }
async function dcApi(path, opts){
  var o = Object.assign({credentials:'same-origin', headers:{}}, opts||{});
  o.headers = Object.assign({'X-XSRF-TOKEN':uToken(), 'Accept':'application/json'}, o.headers);
  var r = await fetch(dcApiBase()+path, o);
  var text = await r.text();
  var data;
  try{ data = JSON.parse(text); }
  catch(e){
    // A non-JSON body means an error page (a 419 CSRF page, a 404, a raw
    // 500), not a real API response. Surface a short, real snippet instead
    // of a silent "could not import" that hides what actually happened.
    throw new Error('HTTP '+r.status+': '+text.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,160));
  }
  if(!r.ok || data.ok===false) throw new Error(data.message||('HTTP '+r.status));
  return data;
}
async function renderDemoContent(){
  $('#content').innerHTML='<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading…</p></div>';
  var counts={};
  try{ counts=(await dcApi('/demo-content')).counts||{}; }catch(e){}

  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Demo Content</h2><p>Sample data so you can try every part of the admin without needing real customer information yet. Import what you need, remove it whenever you are ready to go live.</p></div>

    <div class="card pad" style="margin:18px 0 22px;background:linear-gradient(120deg,#FFF8EC,#FFFBF5);border-color:#F5E1BC">
      <div class="between" style="flex-wrap:wrap;gap:14px">
        <div style="display:flex;gap:12px;align-items:flex-start">
          <div style="color:#B36A0E;flex-shrink:0;margin-top:2px">${ic('<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>')}</div>
          <div>
            <b style="font-size:13.5px">This is sample data, clearly separate from anything real</b>
            <p style="font-size:12px;color:var(--ink-soft);margin:4px 0 0;max-width:520px">Every demo record is tracked, so removing it never touches your real orders, customers, or products. Safe to import and remove as many times as you like.</p>
          </div>
        </div>
        <div class="row" style="gap:10px;flex-shrink:0">
          <button class="btn ghost" id="dcRemoveAll" style="border-color:#c0392b;color:#c0392b;gap:7px">${ic('<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>')} Remove All Demo Content</button>
          <button class="btn" id="dcImportAll" style="gap:7px">${ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>')} Import All Demo Content</button>
        </div>
      </div>
    </div>

    <div class="dcgrid" id="dcGrid">${DEMO_CONTENT_TYPES.map(t=>dcCard(t,counts[t[0]]||0)).join('')}</div>
  </div>`;

  wireDemoContent();
}
function dcCard([key,title,desc,icon,color,tint],count){
  var imported=count>0;
  var status=imported
    ? `<span style="display:inline-flex;align-items:center;gap:5px;color:#0EA968;font-size:11.5px;font-weight:700" class="dcokicon">${ic('<path d="M20 6L9 17l-5-5"/>')} ${count} imported</span>`
    : `<span style="color:#94A3B8;font-size:11.5px;font-weight:600">Not imported yet</span>`;
  return `<div class="card pad dccard" data-type="${key}" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start">
      <div class="dciconbox" style="width:42px;height:42px;border-radius:11px;background:${tint};color:${color};display:flex;align-items:center;justify-content:center">${ic(icon)}</div>
      <span class="dcstatus">${status}</span>
    </div>
    <div><b style="font-size:14px">${title}</b><p style="font-size:12px;color:var(--ink-soft);margin:6px 0 0;line-height:1.55">${desc}</p></div>
    <div class="row" style="gap:8px;margin-top:auto;padding-top:4px">
      <button class="btn ghost sm dcimport" style="flex:1;gap:6px">${ic('<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>')} Import</button>
      <button class="btn ghost sm dcremove" style="color:#c0392b;gap:6px" ${imported?'':'disabled style="opacity:.4;cursor:not-allowed"'}>${ic('<path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z"/>')} Remove</button>
    </div>
  </div>`;
}
function wireDemoContent(){
  document.querySelectorAll('.dccard').forEach(card=>{
    var type=card.dataset.type;
    card.querySelector('.dcimport').onclick=async function(btn){
      var b=card.querySelector('.dcimport');
      b.disabled=true;
      try{
        var r=await dcApi('/demo-content/'+type+'/import',{method:'POST'});
        toast(r.already?'Already imported':'Imported');
        renderDemoContent();
      }catch(e){ toast('Could not import: '+e.message); b.disabled=false; }
    };
    card.querySelector('.dcremove').onclick=async function(){
      if(card.querySelector('.dcremove').disabled)return;
      if(!confirm('Remove this demo content? This cannot be undone.'))return;
      try{
        await dcApi('/demo-content/'+type+'/remove',{method:'POST'});
        toast('Removed');
        renderDemoContent();
      }catch(e){ toast('Could not remove: '+e.message); }
    };
  });
  document.getElementById('dcImportAll').onclick=async function(){
    this.disabled=true;
    try{ await dcApi('/demo-content/import-all',{method:'POST'}); toast('All demo content imported'); renderDemoContent(); }
    catch(e){ toast('Could not import all: '+e.message); this.disabled=false; }
  };
  document.getElementById('dcRemoveAll').onclick=async function(){
    if(!confirm('Remove ALL demo content? This cannot be undone.'))return;
    this.disabled=true;
    try{ await dcApi('/demo-content/remove-all',{method:'POST'}); toast('All demo content removed'); renderDemoContent(); }
    catch(e){ toast('Could not remove all: '+e.message); this.disabled=false; }
  };
}

function renderConsole(){
  $('#content').innerHTML=`<div class="wrap">
    <div class="page-head"><h2>Console</h2><p>Preferences for this admin console — yours and your team's. Separate from store settings; new console options will keep landing here.</p></div>
    <div class="sec-title">Appearance · theme</div>
    <div class="theme-grid">
      ${THEMES.map(t=>`<div class="theme-card${t[0]===theme?' on':''}" data-t="${t[0]}">${tpv(t)}<div class="tcb"><span class="popsw" style="background:${t[2]}"></span><b>${t[1]}</b><span class="ck2">${ic(I.check)}</span></div></div>`).join('')}
    </div>
    <div class="sec-title">Console preferences</div>
    <div class="card pad">
      <div class="pref"><div class="pl"><b>Sidebar density</b><small>Spacing of the navigation</small></div>${segHTML([['comfortable','Comfortable'],['compact','Compact']],consolePrefs.density,'density')}</div>
      <div class="pref"><div class="pl"><b>Default landing page</b><small>Where the console opens</small></div><select class="inp" data-pref="landing">${optHTML({dash:'Dashboard',modules:'Modules',debug:'Debug & Monitor',sandbox:'Sandbox & Deploy'},consolePrefs.landing)}</select></div>
      <div class="pref"><div class="pl"><b>Rows per page</b><small>For lists & tables</small></div><select class="inp" data-pref="perpage">${optHTML({'25':'25','50':'50','100':'100'},consolePrefs.perpage)}</select></div>
      <div class="pref"><div class="pl"><b>Console language</b><small>Admin interface language</small></div><select class="inp" data-pref="lang">${optHTML({en:'English',ar:'العربية (RTL)'},consolePrefs.lang)}</select></div>
      <div class="pref"><div class="pl"><b>Reduced motion</b><small>Minimise animations</small></div><div class="tog${consolePrefs.reduce?' on':''}" data-pref="reduce"></div></div>
    </div>
    <div class="banner" style="margin-top:18px">${ic('<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>')}<div>These are console-only preferences. Storefront design lives in <b>K-Beauty Bliss Theme</b>; store configuration lives in <b>Settings</b>.</div></div>
  </div>`;
  $$('#content .theme-card').forEach(c=>c.onclick=()=>setTheme(c.dataset.t));
  $$('#content .seg button').forEach(b=>b.onclick=()=>{const sg=b.closest('.seg');$$('button',sg).forEach(x=>x.classList.remove('on'));b.classList.add('on');consolePrefs[sg.dataset.pref]=b.dataset.v;toast('Saved (preview)');});
  $$('#content .tog[data-pref]').forEach(t=>t.onclick=()=>{t.classList.toggle('on');consolePrefs[t.dataset.pref]=t.classList.contains('on');toast('Saved (preview)');});
  $$('#content select.inp').forEach(s=>s.onchange=()=>{consolePrefs[s.dataset.pref]=s.value;toast('Saved (preview)');});
}

$('.side-pin .nav-item').onclick=()=>go('console');
buildNav();

/* Deep link: /{admin}?go=updates or /{admin}#updates opens that panel directly.
   This is how the retired standalone page hands over — it redirects here rather
   than rendering a second copy of the same screen. */
(function(){
  const q = new URLSearchParams(window.location.search).get('go');
  const h = (window.location.hash || '').replace('#', '');
  const target = q || h;
  go(target && TITLES[target] ? target : 'dash');
})();
</script>
<script>
/* ============================================================================
   KBB admin — live wiring (mechanical): connects the adopted kbb-admin.html to
   the real /admin-api. The UI above is untouched; this only feeds it real data
   and persists writes. All functions it overrides are window-level declarations.
   ============================================================================ */
(function(){
  function cookie(name){
    return document.cookie.split('; ').reduce(function(r,c){
      var i=c.indexOf('='); var k=c.slice(0,i);
      return k===name ? decodeURIComponent(c.slice(i+1)) : r;
    },'');
  }
  /**
   * A URL written as '/admin-api/...' assumes the app lives at the domain
   * root. On a subdirectory deployment (the live site runs at
   * easywebsol.com/kbb-upgrade/) that silently resolves to a URL with no
   * matching route at all. Shared by api() and every raw fetch() call in
   * this file that still builds its own URL by hand, so the fix lives in
   * one place rather than being repeated at each call site.
   */
  function fixAdminApiUrl(url){
    if(url.indexOf('/admin-api/')===0){
      return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,'') + url;
    }
    return url;
  }
  async function api(url, opts){
    opts = opts || {};
    url = fixAdminApiUrl(url);
    opts.headers = Object.assign({'Accept':'application/json'}, opts.headers||{});
    if(opts.method && opts.method!=='GET'){
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      // FormData bodies (file uploads) must NOT have Content-Type set here —
      // the browser has to generate its own multipart boundary, which it
      // only does when the header is left unset entirely.
      if(!(opts.body instanceof FormData)){
        opts.headers['Content-Type'] = opts.headers['Content-Type'] || 'application/json';
      }
    }
    opts.credentials = 'same-origin';
    var r = await fetch(url, opts);
    if(!r.ok) throw new Error('api '+url+' -> '+r.status);
    return r.json();
  }

  /* ---------- Catalog: populate the seed arrays with real data ---------- */
  async function loadCatalog(){
    try{
      var d = await api('/admin-api/products');
      d = d || {}; d.products=d.products||[]; d.categories=d.categories||[]; d.brands=d.brands||[];
      CAT_PRODUCTS.length = 0; CAT_DRAFT.clear();
      d.products.forEach(function(p){
        var sku = p.sku || ('KBB-'+p.id);
        CAT_PRODUCTS.push([p.name, p.brand||'', sku, p.category||'\u2014',
          (p.price_aed==null?0:p.price_aed), (p.sale_aed==null?null:p.sale_aed),
          (p.stock==null?0:p.stock), p.id, p.status, p.slug]);
        if(p.status!=='active') CAT_DRAFT.add(sku);
      });
      CAT_CATEGORIES.length = 0; d.categories.forEach(function(c){ CAT_CATEGORIES.push([c.name, c.count]); });
      CAT_BRANDS.length = 0; d.brands.forEach(function(b){ CAT_BRANDS.push([b.name, b.count]); });
      if(cur==='catalog') renderCatalog();
    }catch(e){ console.warn('catalog load failed', e); }
  }

  /* ---------- Dashboard: real KPIs + recent orders ---------- */
  async function hydrateDash(){
    var s; try{ s = await api('/admin-api/stats'); }catch(e){ return; }
    function setKpi(label, val, sub){
      document.querySelectorAll('#content .kpi').forEach(function(k){
        var l=k.querySelector('.lbl');
        if(l && l.textContent.trim()===label){
          var v=k.querySelector('.val'); if(v) v.textContent=val;
          if(sub!=null){ var su=k.querySelector('.sub'); if(su) su.textContent=sub; }
        }
      });
    }
    setKpi('Revenue (30d)', 'AED '+s.revenue_30d_aed.toLocaleString(), 'last 30 days');
    setKpi('Orders', s.orders.toLocaleString(), s.paid_orders+' paid');
    setKpi('Customers', s.customers.toLocaleString(), 'total accounts');
    var conv = s.orders ? Math.round((s.paid_orders/s.orders)*100) : 0;
    setKpi('Conversion', conv+'%', 'paid / total');

    var banner = document.querySelector('#content .banner div');
    if(banner) banner.innerHTML = 'Live data. <b>'+s.products+'</b> products, <b>'+s.orders+'</b> orders, <b>'+s.customers+'</b> customers. '+(s.low_stock? ('<b>'+s.low_stock+'</b> low on stock.') : 'Stock levels healthy.');

    var cards = Array.prototype.slice.call(document.querySelectorAll('#content .card.pad'));
    var feedCard = cards.filter(function(c){ return /Recent activity/.test(c.textContent); })[0];
    if(feedCard && s.recent && s.recent.length){
      var feed = feedCard.querySelector('div:last-child');
      if(feed) feed.innerHTML = s.recent.map(function(o){
        var dot = o.status==='completed'?'green':(o.status==='cancelled'||o.status==='failed'?'red':'amber');
        return '<div class="row" style="padding:11px 0;border-bottom:1px solid var(--border-2)"><span class="hd '+dot+'" style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span><div><div style="font-size:13px;font-weight:600">Order #'+o.id+' \u00b7 '+sesc(o.customer)+'</div><div style="font-size:11.5px;color:var(--ink-soft)">AED '+o.total_aed.toLocaleString()+' \u00b7 '+sesc(o.status)+'</div></div><small style="margin-left:auto;font-size:11px;color:var(--ink-faint)">'+(o.created_at||'').slice(0,10)+'</small></div>';
      }).join('');
    }
  }

  /* ---------- Orders screen (new; built from the admin's own tokens) ---------- */
  var ORDER_STATUSES=['draft','pending','processing','onhold','shipped','completed','cancelled','refunded','failed'];
  var ORD=[], ordFilter='all';
  function statusPill(s){
    var m={completed:'green',processing:'amber',onhold:'amber',shipped:'blue',pending:'grey',draft:'grey',cancelled:'red',refunded:'red',failed:'red'}[s]||'grey';
    return '<span class="pill '+m+'"><span class="d"></span>'+s+'</span>';
  }
  /**
   * Replaces the sidebar's "Blog" and "Posts" links, which previously
   * loaded an iframe pointing at a standalone file that was never built.
   * Read-only for now — listing and a real preview link on the live site
   * is what was actually missing; a full editor is separate, larger scope.
   */
  function appRoot(){ return window.location.pathname.replace(/\/+$/,'').replace(/\/[^\/]*$/,''); }
  async function renderPosts(){
    var posts;
    try{ posts = (await api('/admin-api/posts')).posts; }catch(e){ posts = null; }

    if(posts === null){
      document.querySelector('#content').innerHTML = '<div class="wrap"><div class="page-head"><h2>Posts</h2></div>'+
        '<p style="padding:24px;color:var(--sale,#c0392b)">Could not load posts.</p></div>';
      return;
    }

    var statusColor = function(s){ return s==='published' ? 'green' : 'grey'; };

    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Posts</h2><p>Every article on the Journal blog. Editing happens on the real page for now — click Preview to open it.</p></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Title</th><th>Tag</th><th>Author</th><th>Status</th><th>Published</th><th></th></tr></thead><tbody>'+
      (posts.length ? posts.map(function(p){
        return '<tr><td><b>'+sesc(p.title)+'</b></td>'+
          '<td>'+(p.tag?sesc(p.tag):'<span style="color:var(--ink-faint)">\u2014</span>')+'</td>'+
          '<td>'+sesc(p.author||'')+'</td>'+
          '<td><span class="pill '+statusColor(p.status)+'"><span class="d"></span>'+sesc(p.status)+'</span></td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(p.published_at?p.published_at.slice(0,10):'\u2014')+'</td>'+
          '<td><a class="btn ghost sm" href="'+appRoot()+'/'+encodeURIComponent(p.slug)+'/'+'" target="_blank" rel="noopener">Preview</a></td></tr>';
      }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">No posts yet.</td></tr>')+
      '</tbody></table></div></div>';
  }

  async function renderOrders(){
    try{ var d=await api('/admin-api/orders'); ORD=d.orders; }catch(e){ ORD=[]; }
    var counts={all:ORD.length};
    ORDER_STATUSES.forEach(function(s){ counts[s]=ORD.filter(function(o){return o.status===s;}).length; });
    var list = ordFilter==='all' ? ORD : ORD.filter(function(o){return o.status===ordFilter;});
    var chips = [['all','All']].concat(ORDER_STATUSES.map(function(s){return [s, s.charAt(0).toUpperCase()+s.slice(1)];}));
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Orders</h2><p>Every order placed through the storefront. Open one to update its status.</p></div>'+
      '<div class="chips" style="margin:12px 0 14px">'+chips.map(function(c){
        return '<button class="chip'+(ordFilter===c[0]?' on':'')+'" data-of="'+c[0]+'">'+c[1]+(counts[c[0]]?(' \u00b7 '+counts[c[0]]):'')+'</button>';
      }).join('')+'</div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>'+
      (list.length? list.map(function(o){
        return '<tr><td><b>#'+o.id+'</b></td>'+
          '<td><div class="pname">'+sesc(o.customer)+'</div><div class="pbrand">'+sesc(o.email||'')+'</div></td>'+
          '<td>'+sesc(o.items)+'</td>'+
          '<td class="price"><b>AED '+o.total_aed.toLocaleString()+'</b></td>'+
          '<td>'+statusPill(o.status)+'</td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(o.created_at||'').slice(0,10)+'</td>'+
          '<td><button class="btn ghost sm" data-view="'+o.id+'">View</button></td></tr>';
      }).join('') : '<tr><td colspan="7" style="text-align:center;color:var(--ink-soft);padding:34px">No orders yet.</td></tr>')+
      '</tbody></table></div><div class="pager"><span>Showing '+list.length+' of '+ORD.length+'</span></div></div>';
    document.querySelectorAll('#content .chip[data-of]').forEach(function(c){ c.onclick=function(){ ordFilter=c.dataset.of; renderOrders(); }; });
    document.querySelectorAll('#content [data-view]').forEach(function(b){ b.onclick=function(){ renderOrderDetail(+b.dataset.view); }; });
  }

  /**
   * The detailed order page, built from Rafi's own WooCommerce reference
   * screenshot. Card-stack layout, every section open by default (his
   * choice) rather than collapsed — the ^v▲ controls just toggle a
   * section shut for anyone who wants to tidy the page, they don't start
   * that way. Talks to AdminOrderController, which already existed fully
   * built and tested by the time this page was written — this is the
   * missing other half, not a rebuild of that work.
   */
  async function renderOrderDetail(id){
    document.querySelector('#content').innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--ink-soft)">Loading order…</p></div>';
    var o;
    try{ o = await api('/admin-api/orders/'+id+'/detail'); }
    catch(e){ document.querySelector('#content').innerHTML = '<div class="wrap"><p style="padding:40px;color:var(--sale)">Could not load this order.</p></div>'; return; }

    var paidLine = o.payment_method_title
      ? 'Payment via '+sesc(o.payment_method_title)+'.'+(o.transaction_id?' ('+sesc(o.transaction_id)+').':'')+(o.paid_at?' Paid on '+fmtDT(o.paid_at)+'.':'')+(o.ip_address?' Customer IP: '+sesc(o.ip_address)+'.':'')
      : 'No payment recorded yet.';

    document.querySelector('#content').innerHTML =
      '<div class="wrap">'+
      '<p style="margin-bottom:10px"><a href="#" id="ordBack" style="font-size:12.5px;color:var(--pink-deep,#c0392b);text-decoration:none">\u2190 Back to Orders</a></p>'+
      '<div class="page-head" style="margin-bottom:4px"><h2>Order #'+sesc(o.order_number)+'</h2></div>'+
      '<p style="font-size:12.5px;color:var(--ink-soft);margin-bottom:18px">'+paidLine+'</p>'+
      '<div class="odgrid">'+
      '<div class="odmain">'+odOverviewAddressesCard(o)+odCustomerNoteCard(o)+odItemsCard(o)+odNotesCard(o)+'</div>'+
      '<div class="odside">'+odAttributionCard(o)+odActionsCard(o)+odHistoryCard(o)+odInvoiceCard(o)+'</div>'+
      '</div></div>';

    document.getElementById('ordBack').onclick = function(e){ e.preventDefault(); renderOrders(); };
    wireOrderDetail(o);
  }

  function odCardHead(title){
    return '<div class="odcardhead"><b>'+title+'</b><div class="odchev">'+
      '<span class="odtoggle" data-odsec="1">'+ic('<path d="M18 15l-6-6-6 6"/>')+'</span>'+
      '<span class="odtoggle">'+ic('<path d="M6 9l6 6 6-6"/>')+'</span></div></div>';
  }

  /*
   * What the shopper wrote, as opposed to odNotesCard which is the internal
   * thread staff add to. Two different audiences, so two different cards --
   * a gift message read as an internal note is how the wrong words end up
   * on a card in the box.
   *
   * customer_note has been on the orders table and in this endpoint's payload
   * since the beginning, but nothing captured it at checkout and nothing
   * rendered it here. Both halves land together.
   */
  function odCustomerNoteCard(o){
    var note = (o.customer_note||'').trim();
    var gift = (o.gift_note||'').trim();
    if(!note && !gift && !o.is_gift) return '';
    var body = '';
    if(o.is_gift){
      body += '<p style="margin:0 0 10px"><span class="odgiftflag">Gift order</span>'+
        (o.gift_fee_aed>0?'<span class="odgiftfee">AED '+o.gift_fee_aed+' charged</span>':'<span class="odgiftfee">no charge</span>')+
        '</p>';
    }
    if(gift){
      body += '<div class="odgiftmsg"><b>Message for the gift card</b><p>'+sesc(gift).replace(/\n/g,'<br>')+'</p></div>';
    }
    if(note){
      body += '<div class="odcustnote"><b>Delivery notes from the customer</b><p>'+sesc(note).replace(/\n/g,'<br>')+'</p></div>';
    }
    return '<div class="odcard">'+odCardHead('Customer note')+'<div class="odcardbody">'+body+'</div></div>';
  }

  function odOverviewAddressesCard(o){
    var c = o.customer||{};
    var b = o.billing_address||{}, s = o.shipping_address||{};
    var addrLines = function(a){
      return [a.line1, a.line2, [a.city,a.emirate].filter(Boolean).join(', ')].filter(Boolean).map(sesc).join('<br>');
    };
    return '<div class="odcard" style="margin-bottom:16px" id="odGeneral">'+
      '<div class="odcols3">'+
      '<div class="odcolcell">'+
        '<div class="odcollabel">GENERAL</div>'+
        '<div class="odfld"><label>Date created</label>'+
        '<div class="odtimegrid"><input class="odinp" id="odDateCreated" value="'+sesc((o.created_at||'').slice(0,10))+'"><input class="odinp" id="odTimeH" value="'+sesc((o.created_at||'').slice(11,13))+'"><input class="odinp" id="odTimeM" value="'+sesc((o.created_at||'').slice(14,16))+'"></div></div>'+
        '<div class="odfld"><label>Status</label>'+seoSel2('odStatusSel', o.status, ORDER_STATUSES.map(function(s){return [s, s.charAt(0).toUpperCase()+s.slice(1)];}))+'</div>'+
        '<div class="odfld" style="margin-bottom:0"><label>Customer'+(c.id?' &middot; <a href="#" id="odCustHist" style="color:#E08A1A;font-weight:600">Order history</a>':'')+'</label>'+
        (c.id ? '<div class="odcustchip"><span>'+sesc(c.name)+'</span></div>' : '<div class="odcustchip"><span style="color:var(--ink-faint)">Guest checkout</span></div>')+
        '</div>'+
      '</div>'+
      '<div class="odcolcell odcolmid">'+
        '<div class="odcollabel">BILLING <a href="#">Edit</a></div>'+
        '<div class="odaddr"><span class="odname">'+sesc(b.name||o.customer&&o.customer.name||'')+'</span><br>'+(addrLines(b)||'\u2014')+'</div>'+
        '<div class="odfld" style="margin-top:14px;margin-bottom:0"><label>Email address</label>'+(o.email?'<a href="mailto:'+sesc(o.email)+'" style="font-size:11.5px">'+sesc(o.email)+'</a>':'\u2014')+'</div>'+
        '<div class="odfld" style="margin-top:10px;margin-bottom:0"><label>Phone</label>'+(o.phone?'<a href="tel:'+sesc(o.phone)+'" style="font-size:11.5px">'+sesc(o.phone)+'</a>':'\u2014')+'</div>'+
      '</div>'+
      '<div class="odcolcell">'+
        '<div class="odcollabel">SHIPPING <span class="odedit" data-odedit="shipping" style="cursor:pointer;color:#E08A1A;font-weight:600">Edit</span></div>'+
        '<div class="odaddr" id="odShipView"><span class="odname">'+sesc(s.name||'')+'</span><br>'+(addrLines(s)||'\u2014')+'</div>'+
        '<div id="odShipEdit" style="display:none;margin-top:10px">'+
          '<textarea class="odinp" id="odShipJson" rows="4" style="font-size:11px;font-family:monospace">'+sesc(JSON.stringify(s||{}, null, 2))+'</textarea>'+
          '<button class="btn sm" id="odShipSave" style="margin-top:8px">Save</button></div>'+
        '<div class="odfld" style="margin-top:14px;margin-bottom:0"><label>Phone</label>'+(o.phone?'<a href="tel:'+sesc(o.phone)+'" style="font-size:11.5px">'+sesc(o.phone)+'</a>':'\u2014')+'</div>'+
      '</div>'+
      '</div></div>';
  }

  function odItemsCard(o){
    var editable = !!o.editable;
    var rows = o.items.map(function(it){
      var qtyCell = editable
        ? '<input class="odinp odqty" data-itemid="'+it.id+'" type="number" min="1" value="'+it.quantity+'" style="width:60px;padding:6px 7px">'
        : '\u00d7 '+it.quantity;
      var priceCell = editable
        ? '<input class="odinp odprice" data-itemid="'+it.id+'" type="number" step="0.01" min="0" value="'+it.unit_price_aed+'" style="width:84px;padding:6px 7px">'
        : 'AED '+it.unit_price_aed;
      var removeCell = editable
        ? '<button class="btn ghost sm oditemdel" data-itemid="'+it.id+'" style="color:var(--sale,#c0392b);padding:4px 9px">Remove</button>'
        : '';
      return '<tr><td style="width:44px"><div style="width:36px;height:36px;border-radius:8px;background:var(--surface-2);overflow:hidden">'+
        (it.image?'<img src="'+sesc(it.image)+'" style="width:100%;height:100%;object-fit:cover">':'')+'</div></td>'+
        '<td><b style="font-size:12.5px">'+sesc(it.name)+'</b><div class="pbrand">'+sesc(it.brand||'')+'</div></td>'+
        '<td>'+priceCell+'</td><td>'+qtyCell+'</td><td><b>AED '+it.total_aed+'</b></td>'+(editable?'<td>'+removeCell+'</td>':'')+'</tr>';
    }).join('');

    var refundedLine = o.refunded_total_aed>0 ? '<div class="between" style="color:var(--sale,#c0392b)"><span>Refunded</span><span>-AED '+o.refunded_total_aed+'</span></div>' : '';
    var vatLine = o.vat ? '<div class="between" style="color:var(--ink-faint);font-size:11.5px;padding-top:4px"><span>'+sesc(o.vat.label)+'</span><span>AED '+o.vat.amount_aed+'</span></div>' : '';

    var addProductBlock = editable
      ? '<div style="margin-top:14px;position:relative"><input class="odinp" id="odAddProductSearch" placeholder="Search products to add\u2026" style="width:280px">'+
        '<div id="odAddProductResults" style="display:none;position:absolute;z-index:20;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.08);width:340px;max-height:280px;overflow:auto;margin-top:4px"></div></div>'
      : '<p style="font-size:12px;color:var(--ink-faint);margin-top:12px;display:flex;align-items:center;gap:6px">'+ic('<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1" stroke-linecap="round"/>')+'This order is no longer editable \u2014 it has already shipped or been closed out.</p>';

    return '<div class="odcard" id="odItems">'+odCardHead('Items')+
      '<div class="pad">'+
      '<div style="overflow:auto"><table><thead><tr><th></th><th>Item</th><th>Price</th><th>Qty</th><th>Total</th>'+(editable?'<th></th>':'')+'</tr></thead><tbody>'+rows+'</tbody></table></div>'+
      addProductBlock+
      (o.shipping_method?'<p style="font-size:12px;color:var(--ink-soft);margin-top:10px">Shipping: '+sesc(o.shipping_method)+'</p>':'')+
      '<div style="margin-top:14px;max-width:280px;margin-left:auto;'+(editable?'margin-right:140px;':'')+'display:flex;flex-direction:column;gap:5px;font-size:13px">'+
      '<div class="between"><span style="color:var(--ink-soft)">Subtotal</span><span>AED '+o.subtotal_aed+'</span></div>'+
      (o.discount_total_aed>0?'<div class="between"><span style="color:var(--ink-soft)">Discount'+(o.coupon_code?' ('+sesc(o.coupon_code)+')':'')+'</span><span>-AED '+o.discount_total_aed+'</span></div>':'')+
      '<div class="between"><span style="color:var(--ink-soft)">Shipping</span><span>AED '+o.shipping_total_aed+'</span></div>'+
      (o.fee_total_aed>0?'<div class="between"><span style="color:var(--ink-soft)">Fees</span><span>AED '+o.fee_total_aed+'</span></div>':'')+
      '<div class="between" style="font-weight:800;font-size:14px;border-top:1px solid var(--border);padding-top:8px"><span>Order total</span><span>AED '+o.total_aed+'</span></div>'+
      vatLine+
      refundedLine+
      '</div>'+
      '<div class="row" style="margin-top:16px;gap:10px;align-items:center">'+
      '<button class="btn ghost sm" id="odRefundToggle">Refund</button>'+
      '<div id="odRefundForm" style="display:none;gap:8px;align-items:center" class="row">'+
        '<input class="odinp" id="odRefundAmt" type="number" step="0.01" placeholder="Amount AED" style="width:120px">'+
        '<input class="odinp" id="odRefundReason" placeholder="Reason (optional)" style="width:200px">'+
        '<button class="btn sm" id="odRefundGo">Confirm refund</button></div></div>'+
      '</div></div>';
  }

  function odNotesCard(o){
    var rows = o.notes.map(function(n){
      return '<div style="padding:10px 0;border-bottom:1px solid var(--border)"><div style="font-size:12.5px">'+sesc(n.content)+'</div>'+
        '<div style="font-size:11px;color:var(--ink-soft);margin-top:3px">'+sesc(n.author||'Admin')+' \u00b7 '+fmtDT(n.created_at)+'</div></div>';
    }).join('');

    return '<div class="odcard" id="odNotes">'+odCardHead('Order notes')+'<div class="pad">'+
      (rows || '<p style="font-size:12.5px;color:var(--ink-soft)">No notes yet.</p>')+
      '<div class="row" style="margin-top:12px;gap:8px"><textarea class="odinp" id="odNoteText" rows="2" placeholder="Add a note for other admins…" style="flex:1"></textarea>'+
      '<button class="btn sm" id="odNoteGo" style="align-self:flex-end">Add</button></div></div></div>';
  }

  function odAttributionCard(o){
    var a = o.attribution||{};
    var na = '<span style="color:var(--ink-faint)">Not tracked yet</span>';
    return '<div class="odcard" style="margin-bottom:14px" id="odAttr">'+odCardHead('Order attribution')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">SOURCE</label><div style="font-size:13px">'+(a.origin?sesc(a.origin):na)+'</div></div>'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">DEVICE TYPE</label><div style="font-size:13px">'+(a.device_type?sesc(a.device_type):na)+'</div></div>'+
      '<div class="odfld" style="margin-bottom:0"><label style="font-weight:700;color:var(--ink-faint)">SESSION PAGE VIEWS</label><div style="font-size:13px">'+(a.session_page_views!=null?a.session_page_views:na)+'</div></div>'+
      '</div></div>';
  }

  function odActionsCard(o){
    var real = (o.actions&&o.actions.real)||[], ph = (o.actions&&o.actions.placeholder)||[];
    var labels = {cancel:'Cancel order', duplicate:'Duplicate order', resend_confirmation:'Resend confirmation email', email_invoice:'Email invoice'};
    var opts = real.concat(ph).map(function(a){ return '<option value="'+a+'">'+(labels[a]||a)+(ph.indexOf(a)>-1?' (not available yet)':'')+'</option>'; }).join('');
    return '<div class="odcard" style="margin-bottom:14px" id="odActions">'+odCardHead('Order actions')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="row" style="gap:8px;margin-bottom:14px"><select class="odinp" id="odActionSel" style="flex:1"><option value="">Choose an action\u2026</option>'+opts+'</select>'+
      '<button class="btn sm" id="odActionGo" style="background:#FFF3E0;color:#B36A0E;border:1.5px solid transparent">'+ic('<path d="M9 6l6 6-6 6"/>')+'</button></div>'+
      '<div class="between"><a href="#" id="odTrash" style="color:var(--sale,#c0392b);font-size:12.5px;font-weight:600;text-decoration:none">Move to trash</a><button class="btn" id="odUpdate" style="background:#E08A1A;color:#fff">Update</button></div></div></div>';
  }

  function odHistoryCard(o){
    var h = o.customer_history||{};
    return '<div class="odcard" style="margin-bottom:14px" id="odHist">'+odCardHead('Customer history')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">TOTAL ORDERS</label><div style="font-size:17px;font-weight:800">'+(h.total_orders||0)+'</div></div>'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">TOTAL REVENUE</label><div style="font-size:17px;font-weight:800">AED '+(h.total_revenue_aed||0)+'</div></div>'+
      '<div class="odfld" style="margin-bottom:0"><label style="font-weight:700;color:var(--ink-faint)">AVERAGE ORDER VALUE</label><div style="font-size:17px;font-weight:800">AED '+(h.average_order_value_aed||0)+'</div></div>'+
      '</div></div>';
  }

  function odInvoiceCard(o){
    var docs = ['Invoice','Packing slip','Delivery note','Shipping Label','Dispatch Label'];
    return '<div class="odcard" id="odInvoice">'+odCardHead('Invoice / Packing')+'<div class="pad" style="padding:18px 20px">'+
      '<div class="odfld"><label style="font-weight:700;color:var(--ink-faint)">INVOICE NUMBER</label><div style="font-size:13px">'+(o.invoice_number?sesc(String(o.invoice_number)):'<span style="color:var(--ink-faint)">Not yet invoiced</span>')+'</div></div>'+
      '<label style="font-size:10.5px;font-weight:700;color:var(--ink-faint)">PRINT / DOWNLOAD</label>'+
      '<div style="margin-top:8px">'+docs.map(function(d){
        return '<div class="between" style="padding:8px 0;border-bottom:1px solid var(--line-2,var(--border))"><span style="font-size:12.5px;font-weight:500">'+d+'</span><button class="btn ghost sm" data-oddoc="'+d+'" style="padding:5px 9px">'+ic('<path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/>')+'</button></div>';
      }).join('')+'</div></div></div>';
  }

  function seoSel2(id, cur, opts){
    return '<select class="inp" id="'+id+'" style="width:100%">'+opts.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===cur?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>';
  }

  function fmtDT(iso){
    if(!iso) return '\u2014';
    var d = new Date(iso);
    if(isNaN(d)) return sesc(iso);
    return d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})+' at '+d.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});
  }

  function wireOrderDetail(o){
    var id = o.id;

    // Collapse/expand — sections start open (Rafi's choice); this just toggles.
    document.querySelectorAll('#content .odtoggle').forEach(function(t){
      t.onclick = function(){
        var card = t.closest('.card');
        var body = card.querySelectorAll(':scope > *:not(.between)');
        var hidden = body[0] && body[0].style.display==='none';
        body.forEach(function(el){ el.style.display = hidden ? '' : 'none'; });
        t.textContent = hidden ? 'Hide' : 'Show';
      };
    });

    document.getElementById('odUpdate').onclick = async function(){
      var status = document.getElementById('odStatusSel').value;
      try{ await api('/admin-api/orders/'+id+'/status',{method:'PUT',body:JSON.stringify({status:status})});
        toast('Order updated'); renderOrderDetail(id);
      }catch(e){ toast('Update failed'); }
    };

    document.getElementById('odTrash').onclick = async function(){
      if(!confirm('Move this order to trash?')) return;
      try{ await api('/admin-api/orders/'+id,{method:'DELETE'}); toast('Order moved to trash'); renderOrders(); }
      catch(e){ toast('Could not trash this order.'); }
    };

    var custHist = document.getElementById('odCustHist');
    if(custHist) custHist.onclick = function(e){ e.preventDefault(); go('customers'); };

    // Shipping address inline edit.
    document.getElementById('odShipEdit') && (function(){
      document.querySelector('[data-odedit="shipping"]').onclick = function(){
        var view=document.getElementById('odShipView'), edit=document.getElementById('odShipEdit');
        var open = edit.style.display==='none'; view.style.display = open?'none':''; edit.style.display = open?'':'none';
      };
      document.getElementById('odShipSave').onclick = async function(){
        var raw = document.getElementById('odShipJson').value;
        var parsed; try{ parsed = JSON.parse(raw); }catch(e){ toast('That is not valid JSON.'); return; }
        try{
          var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/address'),{method:'PUT',credentials:'same-origin',
            headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
            body:JSON.stringify({type:'shipping',address:parsed})});
          if(!r.ok) throw 0;
          toast('Shipping address updated'); renderOrderDetail(id);
        }catch(e){ toast('Could not save the address.'); }
      };
    })();

    // Item editing: quantity, price, remove, add product — only rendered when o.editable.
    if(o.editable){
      document.querySelectorAll('#content .odqty').forEach(function(inp){
        inp.onchange = async function(){
          var qty = parseInt(inp.value, 10);
          if(!qty || qty<1){ toast('Quantity must be at least 1.'); renderOrderDetail(id); return; }
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+inp.dataset.itemid),{method:'PUT',credentials:'same-origin',
              headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
              body:JSON.stringify({quantity:qty})});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not update that item.'); return; }
            toast('Quantity updated'); renderOrderDetail(id);
          }catch(e){ toast('Could not update that item.'); }
        };
      });
      document.querySelectorAll('#content .odprice').forEach(function(inp){
        inp.onchange = async function(){
          var price = parseFloat(inp.value);
          if(price==null || isNaN(price) || price<0){ toast('Enter a valid price.'); renderOrderDetail(id); return; }
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+inp.dataset.itemid),{method:'PUT',credentials:'same-origin',
              headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
              body:JSON.stringify({unit_price_aed:price})});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not update that item.'); return; }
            toast('Price updated'); renderOrderDetail(id);
          }catch(e){ toast('Could not update that item.'); }
        };
      });
      document.querySelectorAll('#content .oditemdel').forEach(function(btn){
        btn.onclick = async function(){
          if(!confirm('Remove this item from the order?')) return;
          try{
            var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items/'+btn.dataset.itemid),{method:'DELETE',credentials:'same-origin',
              headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'}});
            var j = await r.json();
            if(!r.ok || j.ok===false){ toast(j.message||'Could not remove that item.'); return; }
            toast('Item removed'); renderOrderDetail(id);
          }catch(e){ toast('Could not remove that item.'); }
        };
      });

      var searchInp = document.getElementById('odAddProductSearch');
      var resultsBox = document.getElementById('odAddProductResults');
      var searchT;
      if(searchInp){
        searchInp.oninput = function(){
          clearTimeout(searchT);
          var q = searchInp.value.trim();
          if(q.length<2){ resultsBox.style.display='none'; return; }
          searchT = setTimeout(async function(){
            try{
              var data = await api('/admin-api/catalog/products?search='+encodeURIComponent(q)+'&per_page=8');
              var list = data.products||[];
              resultsBox.innerHTML = list.length ? list.map(function(p){
                return '<div class="odaddrow" data-pid="'+p.id+'" style="padding:9px 12px;cursor:pointer;font-size:12.5px;border-bottom:1px solid var(--border)">'+
                  '<b>'+sesc(p.name)+'</b><div style="color:var(--ink-faint);font-size:11px">'+sesc(p.brand||'')+' \u00b7 AED '+(p.sale_price||p.price)+'</div></div>';
              }).join('') : '<div style="padding:12px;color:var(--ink-faint);font-size:12.5px">No products found.</div>';
              resultsBox.style.display='block';
              document.querySelectorAll('#content .odaddrow').forEach(function(row){
                row.onclick = async function(){
                  try{
                    var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/items'),{method:'POST',credentials:'same-origin',
                      headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
                      body:JSON.stringify({product_id:parseInt(row.dataset.pid,10), quantity:1})});
                    var j = await r.json();
                    if(!r.ok || j.ok===false){ toast(j.message||'Could not add that product.'); return; }
                    toast('Product added'); renderOrderDetail(id);
                  }catch(e){ toast('Could not add that product.'); }
                };
              });
            }catch(e){ resultsBox.style.display='none'; }
          }, 300);
        };
        document.addEventListener('click', function(e){
          if(!resultsBox.contains(e.target) && e.target!==searchInp) resultsBox.style.display='none';
        });
      }
    }

    // Refund.
    document.getElementById('odRefundToggle').onclick = function(){
      var f = document.getElementById('odRefundForm');
      f.style.display = f.style.display==='none' ? 'flex' : 'none';
    };
    document.getElementById('odRefundGo').onclick = async function(){
      var amt = parseFloat(document.getElementById('odRefundAmt').value);
      if(!amt || amt<=0){ toast('Enter a refund amount.'); return; }
      var reason = document.getElementById('odRefundReason').value;
      try{
        var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/refund'),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
          body:JSON.stringify({amount_aed:amt, reason:reason})});
        var j = await r.json();
        if(!r.ok || j.ok===false){ toast(j.message||'Could not process that refund.'); return; }
        toast('Refund recorded'); renderOrderDetail(id);
      }catch(e){ toast('Could not process that refund.'); }
    };

    // Add note.
    document.getElementById('odNoteGo').onclick = async function(){
      var content = document.getElementById('odNoteText').value.trim();
      if(!content){ toast('Write a note first.'); return; }
      try{ await api('/admin-api/orders/'+id+'/notes',{method:'POST',body:JSON.stringify({content:content})});
        toast('Note added'); renderOrderDetail(id);
      }catch(e){ toast('Could not save that note.'); }
    };

    // Actions dropdown.
    document.getElementById('odActionGo').onclick = async function(){
      var action = document.getElementById('odActionSel').value;
      if(!action){ toast('Choose an action first.'); return; }
      try{
        var r = await fetch(fixAdminApiUrl('/admin-api/orders/'+id+'/action'),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN'),Accept:'application/json'},
          body:JSON.stringify({action:action})});
        var j = await r.json();
        if(!r.ok || j.ok===false){ toast(j.message||'That action could not be completed.'); return; }
        toast('Done'); renderOrderDetail(id);
      }catch(e){ toast('That action could not be completed.'); }
    };

    // Invoice/Packing placeholders — visible, honest about not being wired yet.
    document.querySelectorAll('#content [data-oddoc]').forEach(function(b){
      b.onclick = function(){ toast(b.dataset.oddoc+' generation is not built yet — this is a placeholder.'); };
    });
  }

  async function openOrder(id){
    var o; try{ o=await api('/admin-api/orders/'+id); }catch(e){ toast('Could not load order'); return; }
    var opts=ORDER_STATUSES.map(function(s){return '<option value="'+s+'"'+(s===o.status?' selected':'')+'>'+s+'</option>';}).join('');
    var c=o.customer||{};
    var addr=[sesc(c.name),sesc(c.email),sesc(c.phone),sesc((c.emirate||'')+(c.address?(' \u00b7 '+c.address):''))].filter(Boolean).join('<br>');
    openModal(
      '<div class="modal-h"><b>Order #'+o.id+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b">'+
      '<div class="row" style="gap:8px;align-items:center;margin-bottom:12px"><span style="font-size:12.5px;color:var(--ink-soft)">Status</span>'+
      '<select class="inp" id="ordStatusSel" style="width:170px">'+opts+'</select>'+
      '<button class="btn sm" id="ordStatusSave">Update</button></div>'+
      '<div class="card pad" style="margin-bottom:12px"><b style="font-size:12.5px">Customer</b><div style="font-size:12.5px;color:var(--ink-2);margin-top:6px;line-height:1.7">'+(addr||'\u2014')+'</div></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Line</th></tr></thead><tbody>'+
      o.items.map(function(it){ return '<tr><td><b style="font-size:12.5px">'+sesc(it.name)+'</b><div class="pbrand">'+sesc((it.brand||''))+'</div></td><td>'+it.qty+'</td><td>AED '+it.unit_aed+'</td><td><b>AED '+it.line_aed+'</b></td></tr>'; }).join('')+
      '</tbody></table></div>'+
      '<div style="margin-top:12px;font-size:13px;display:flex;flex-direction:column;gap:5px">'+
      '<div class="between"><span style="color:var(--ink-soft)">Subtotal</span><span>AED '+o.subtotal_aed+'</span></div>'+
      '<div class="between"><span style="color:var(--ink-soft)">Delivery</span><span>'+(o.delivery_aed?('AED '+o.delivery_aed):'Free')+'</span></div>'+
      (o.cod_fee_aed?('<div class="between"><span style="color:var(--ink-soft)">COD fee</span><span>AED '+o.cod_fee_aed+'</span></div>'):'')+
      '<div class="between" style="font-weight:700;font-size:14px"><span>Total</span><span>AED '+o.total_aed+'</span></div>'+
      '</div></div>');
    var sel=document.getElementById('ordStatusSel');
    document.getElementById('ordStatusSave').onclick=async function(){
      try{ await api('/admin-api/orders/'+id+'/status',{method:'PUT',body:JSON.stringify({status:sel.value})});
        toast('Order status updated'); closeModal(); renderOrders(); if(cur==='dash') hydrateDash();
      }catch(e){ toast('Update failed'); }
    };
  }

  /* ---------- Inventory: real bulk stock save ---------- */
  if(typeof invSave==='function'){
    window.invSave = async function(){
      var changes=[];
      Object.keys(invDraft).forEach(function(k){
        var row=CAT_PRODUCTS[k];
        if(row && invDraft[k]!==row[6]) changes.push({id:row[7], stock:invDraft[k]});
      });
      if(!changes.length){ toast('No changes to save'); return; }
      try{
        await api('/admin-api/inventory',{method:'POST',body:JSON.stringify({changes:changes})});
        Object.keys(invDraft).forEach(function(k){ CAT_PRODUCTS[k][6]=invDraft[k]; });
        var n=changes.length; invDraft={}; catInventory();
        toast('Saved '+n+' product'+(n>1?'s':''));
      }catch(e){ toast('Save failed \u2014 check connection'); }
    };
  }

  /* ---------- Customers screen (new) ---------- */
  var CUST=[];
  async function renderCustomers(){
    try{ var d=await api('/admin-api/customers'); CUST=d.customers||[]; }catch(e){ CUST=[]; }
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Customers</h2><p>Everyone who has placed an order through the storefront.</p></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Customer</th><th>Contact</th><th>Emirate</th><th>Orders</th><th>Spent</th><th>Joined</th></tr></thead><tbody>'+
      (CUST.length? CUST.map(function(c){
        return '<tr><td><div class="row"><span class="pthumb" style="background:'+sesc(tcol(c.name||'KB'))+';width:30px;height:30px;font-size:10px">'+sesc(initials(c.name||'KB'))+'</span><b style="font-size:12.5px">'+sesc((c.name||'Guest'))+'</b></div></td>'+
          '<td><div class="pname">'+sesc((c.email||''))+'</div><div class="pbrand">'+sesc((c.phone||''))+'</div></td>'+
          '<td>'+(c.emirate||'\u2014')+'</td><td>'+c.orders+'</td>'+
          '<td class="price"><b>AED '+c.spent_aed.toLocaleString()+'</b></td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(c.created_at||'').slice(0,10)+'</td></tr>';
      }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">No customers yet.</td></tr>')+
      '</tbody></table></div><div class="pager"><span>'+CUST.length+' customer'+(CUST.length===1?'':'s')+'</span></div></div>';
  }

  /* ---------- Quiz Leads screen (new) ---------- */
  var LEADS=[], leadFilter='all';
  async function renderQuizLeads(){
    try{ var d=await api('/admin-api/quiz-leads'); LEADS=d.leads||[]; }catch(e){ LEADS=[]; }
    var expertN=LEADS.filter(function(l){return l.expert;}).length;
    var list = leadFilter==='expert' ? LEADS.filter(function(l){return l.expert;}) : LEADS;
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Quiz Leads</h2><p>Skin-quiz submissions. Flagged rows requested an expert consultation.</p></div>'+
      '<div class="chips" style="margin:12px 0 14px">'+[['all','All \u00b7 '+LEADS.length],['expert','Expert requests \u00b7 '+expertN]].map(function(c){return '<button class="chip'+(leadFilter===c[0]?' on':'')+'" data-lf="'+c[0]+'">'+c[1]+'</button>';}).join('')+'</div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>Lead</th><th>Skin type</th><th>Concerns</th><th>Recommended</th><th>Expert</th><th>Date</th></tr></thead><tbody>'+
      (list.length? list.map(function(l){
        return '<tr><td><div class="pname">'+sesc((l.name||'Anonymous'))+'</div><div class="pbrand">'+sesc((l.email||l.phone||''))+'</div></td>'+
          '<td>'+sesc((l.skin_type||'\u2014'))+'</td>'+
          '<td>'+(l.concerns&&l.concerns.length? l.concerns.map(function(x){return '<span class="tagchip" style="font-size:10px">'+x+'</span>';}).join(' ') : '\u2014')+'</td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(l.recommended&&l.recommended.length? l.recommended.join(', ') : '\u2014')+'</td>'+
          '<td>'+(l.expert? '<span class="pill amber"><span class="d"></span>Requested</span>':'<span class="pill grey">\u2014</span>')+'</td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(l.created_at||'').slice(0,10)+'</td></tr>';
      }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">No quiz submissions yet.</td></tr>')+
      '</tbody></table></div><div class="pager"><span>Showing '+list.length+' of '+LEADS.length+'</span></div></div>';
    document.querySelectorAll('#content .chip[data-lf]').forEach(function(c){ c.onclick=function(){ leadFilter=c.dataset.lf; renderQuizLeads(); }; });
  }

  /* ---------- Per-product Yoast SEO: live snippet + analysis + persist ---------- */
  var peSeo = {};
  function ySite(){ return (SETTINGS && SETTINGS.seo_site_name) || (SETTINGS && SETTINGS.store_name) || 'K-Beauty Bliss'; }
  function yField(root, prefix){
    var flds=root.querySelectorAll('.fld');
    for(var i=0;i<flds.length;i++){ var l=flds[i].querySelector('label');
      if(l && l.textContent.trim().toLowerCase().indexOf(prefix)===0) return flds[i].querySelector('input,textarea'); }
    return null;
  }
  function yResolve(v){
    return String(v||'').replace(/%%title%%/gi, peCtx.n||'Product').replace(/%%sitename%%/gi, ySite())
      .replace(/%%sep%%/gi, (SETTINGS&&SETTINGS.seo_separator)||'|').replace(/%%page%%/gi, '').replace(/\s+/g,' ').trim();
  }
  function yInsertAtCursor(el, txt){
    var s=el.selectionStart, e=el.selectionEnd;
    if(s==null){ el.value+=(el.value?' ':'')+txt; } else { el.value=el.value.slice(0,s)+txt+el.value.slice(e); el.selectionStart=el.selectionEnd=s+txt.length; }
    el.focus();
  }
  function ySnippet(root){
    var snip=root.querySelector('.seo-snip'); if(!snip) return;
    var t=snip.querySelector('.t'), d=snip.querySelector('.d'), u=snip.querySelector('.u');
    var slug=peSeo.slug||peCtx.slug||'';
    if(t) t.textContent = yResolve(peSeo.seo_title) || ((peCtx.n||'Product')+' | '+ySite());
    if(d) d.textContent = (peSeo.meta_description||'').trim() || ('Shop '+(peCtx.n||'this product')+(peCtx.b?(' by '+peCtx.b):'')+' at '+ySite()+' — authentic Korean skincare, fast UAE delivery.');
    if(u) u.textContent = 'kbeautybliss.com \u203a product \u203a '+slug;
  }
  function yCheck(status, label, msg){
    var ci = status==='good' ? '<div class="ci green">'+ic(I.check)+'</div>'
           : status==='bad'  ? '<div class="ci" style="background:#fde2e2;color:#c0392b">'+ic(ICO.ring)+'</div>'
           : '<div class="ci" style="background:#fdecd2;color:#b7791f">'+ic(ICO.ring)+'</div>';
    return '<div class="check">'+ci+'<b>'+label+'</b><small>'+msg+'</small></div>';
  }
  function yAnalysis(root){
    var box=root.querySelector('.checks'); if(!box) return;
    var kp=(peSeo.focus_keyphrase||'').trim().toLowerCase();
    var title=yResolve(peSeo.seo_title).toLowerCase();
    var meta=(peSeo.meta_description||'').trim();
    var slug=(peSeo.slug||peCtx.slug||'').toLowerCase();
    var out=[];
    if(!kp){ out.push(yCheck('warn','Focus keyphrase','Add a focus keyphrase to run the analysis')); }
    else {
      out.push(yCheck(title.indexOf(kp)>-1?'good':'bad','Keyphrase in title', title.indexOf(kp)>-1?'The focus keyphrase appears in the SEO title':'Add the keyphrase to the SEO title'));
      out.push(yCheck(meta.toLowerCase().indexOf(kp)>-1?'good':'bad','Keyphrase in meta', meta.toLowerCase().indexOf(kp)>-1?'Keyphrase found in the meta description':'Add the keyphrase to the meta description'));
      out.push(yCheck(slug.indexOf(kp.replace(/\s+/g,'-'))>-1?'good':'warn','Keyphrase in slug', slug.indexOf(kp.replace(/\s+/g,'-'))>-1?'Keyphrase is in the URL slug':'Consider adding the keyphrase to the slug'));
    }
    var ml=meta.length;
    out.push(yCheck((ml>=120&&ml<=156)?'good':(ml===0?'warn':(ml>156?'bad':'warn')),'Meta description length', ml+'/156 chars'+(ml>156?' — too long':ml>=120?' — good':ml>0?' — a little short':'')));
    var tl=yResolve(peSeo.seo_title).length;
    out.push(yCheck((tl>0&&tl<=60)?'good':(tl===0?'warn':'bad'),'SEO title length', tl+'/60 chars'+(tl>60?' — too long':tl>0?' — good':'')));
    box.innerHTML=out.join('');
  }
  function enhanceYoast(){
    var root=document.getElementById('yoastBody'); if(!root) return;
    if(yoastTab==='seo'){
      var fk=yField(root,'focus keyphrase'), st=yField(root,'seo title'), sl=yField(root,'slug'), md=yField(root,'meta description');
      // hydrate from saved peSeo, else seed peSeo from the field defaults
      [[fk,'focus_keyphrase'],[st,'seo_title'],[sl,'slug'],[md,'meta_description']].forEach(function(p){
        var el=p[0], k=p[1]; if(!el) return;
        if(peSeo[k]!=null) el.value=peSeo[k]; else peSeo[k]=el.value;
        el.addEventListener('input', function(){ peSeo[k]=el.value; ySnippet(root); yAnalysis(root); });
      });
      // variable chips → insert tokens into the SEO title
      var tokens={'Title':'%%title%%','Page':'%%page%%','Separator':'%%sep%%','Site title':'%%sitename%%'};
      root.querySelectorAll('.tagchips .tagchip').forEach(function(chip){
        chip.addEventListener('mousedown', function(e){ e.preventDefault(); });
        chip.onclick=function(){ if(!st) return; yInsertAtCursor(st, tokens[chip.textContent.trim()]||''); peSeo.seo_title=st.value; ySnippet(root); yAnalysis(root); };
      });
      ySnippet(root); yAnalysis(root);
    } else if(yoastTab==='schema'){
      var sels=root.querySelectorAll('select');
      if(sels[0]){ if(peSeo.schema_page_type) sels[0].value=peSeo.schema_page_type; sels[0].addEventListener('change', function(){ peSeo.schema_page_type=sels[0].value; }); }
      if(sels[1]){ if(peSeo.schema_product) sels[1].value=peSeo.schema_product; sels[1].addEventListener('change', function(){ peSeo.schema_product=sels[1].value; }); }
    } else if(yoastTab!=='read'){ // social
      var ot=yField(root,'facebook'), od=yField(root,'description');
      if(ot){ if(peSeo.og_title!=null) ot.value=peSeo.og_title; else peSeo.og_title=ot.value; ot.addEventListener('input', function(){ peSeo.og_title=ot.value; }); }
      if(od){ if(peSeo.og_description!=null) od.value=peSeo.og_description; od.addEventListener('input', function(){ peSeo.og_description=od.value; }); }
    }
  }
  if(typeof renderYoastBody==='function'){
    var _renderYoastBody = renderYoastBody;
    window.renderYoastBody = function(){ _renderYoastBody(); try{ enhanceYoast(); }catch(e){} };
  }

  /* ---------- Rich-text editor: real Visual/Code + toolbar ---------- */
  function rteClean(s){
    if(s==null) return '';
    return String(s)
      .replace(/\\r\\n/g,'\n').replace(/\\n/g,'\n').replace(/\\t/g,'  ')
      .replace(/\\r/g,'').replace(/\\"/g,'"').replace(/\\\//g,'/');
  }
  function rteWordCount(html){ var d=document.createElement('div'); d.innerHTML=html||''; var t=(d.textContent||'').trim(); return t? t.split(/\s+/).length : 0; }
  function enhanceRTE(){
    var rte=document.querySelector('#content .rte'); if(!rte || rte.dataset.enh) return;
    var ta=rte.querySelector('.rte-area'); if(!ta) return;
    rte.dataset.enh='1';
    ta.value = rteClean(ta.value);
    var vis=document.createElement('div');
    vis.className='rte-visual'; vis.setAttribute('contenteditable','true');
    vis.style.cssText='border:1px solid var(--border);border-top:0;border-radius:0 0 10px 10px;min-height:170px;max-height:440px;overflow:auto;padding:12px 14px;font-size:13px;line-height:1.6;background:#fff;outline:none';
    vis.innerHTML = ta.value || '';
    ta.style.display='none';                 // default to Visual
    ta.parentNode.insertBefore(vis, ta);
    var wc=rte.querySelector('.wcount span');
    function inCode(){ return ta.style.display!=='none'; }
    function updateWC(){ if(wc) wc.textContent='Word count: '+rteWordCount(inCode()?ta.value:vis.innerHTML); }
    // Visual/Code toggle
    var vcBtns=rte.querySelectorAll('.vc button');
    function setMode(code){
      if(code){ ta.value=vis.innerHTML; ta.style.display=''; vis.style.display='none'; }
      else    { vis.innerHTML=ta.value;  vis.style.display=''; ta.style.display='none'; }
      if(vcBtns[0]) vcBtns[0].classList.toggle('on', !code);
      if(vcBtns[1]) vcBtns[1].classList.toggle('on', code);
      updateWC();
    }
    if(vcBtns[0]) vcBtns[0].onclick=function(){ setMode(false); };
    if(vcBtns[1]) vcBtns[1].onclick=function(){ setMode(true); };
    // Toolbar
    rte.querySelectorAll('.rte-bar button').forEach(function(btn){
      var label=(btn.textContent||'').trim();
      btn.addEventListener('mousedown', function(e){ e.preventDefault(); });   // keep the selection
      btn.onclick=function(e){
        e.preventDefault();
        if(inCode()) return;                 // formatting only in Visual mode
        vis.focus();
        if(label==='B') document.execCommand('bold');
        else if(label==='I') document.execCommand('italic');
        else if(label==='U') document.execCommand('underline');
        else if(label.indexOf('\u2022')>-1) document.execCommand('insertUnorderedList');
        else if(label.indexOf('1.')===0) document.execCommand('insertOrderedList');
        else if(label==='\u275d') document.execCommand('formatBlock', false, 'blockquote');
        else if(label==='\ud83d\udd17'){ var u=prompt('Link URL:','https://'); if(u) document.execCommand('createLink', false, u); }
        else if(label.toLowerCase()==='align') document.execCommand('justifyCenter');
        else if(label.toLowerCase().indexOf('paragraph')===0) document.execCommand('formatBlock', false, 'p');
        updateWC();
      };
    });
    vis.addEventListener('input', updateWC);
    ta.addEventListener('input', updateWC);
    updateWC();
    rte._sync=function(){ if(!inCode()) ta.value=vis.innerHTML; };   // used by saveProduct
  }

  /* ---------- Product editor: real save of the core + detail fields ---------- */
  if(typeof openProduct==='function'){
    var _openProduct = openProduct;
    window.openProduct = function(idx){
      peSeo={};
      _openProduct(idx);
      if(idx==null || idx<0) return;                 // new-product create flow: left for a later group
      var row = CAT_PRODUCTS[idx]; if(!row) return;
      var pid = row[7];
      function fldByLabel(prefix){
        var f = Array.prototype.slice.call(document.querySelectorAll('#content #pdBody .fld'));
        for(var i=0;i<f.length;i++){
          var l=f[i].querySelector('label'), inp=f[i].querySelector('input');
          if(l&&inp&&l.textContent.trim().toLowerCase().indexOf(prefix)===0) return inp;
        }
        return null;
      }
      function boxSelect(headerPrefix){
        var boxes=document.querySelectorAll('#content .pe-box');
        for(var i=0;i<boxes.length;i++){
          var h=boxes[i].querySelector('.pe-bh');
          if(h && h.textContent.trim().toLowerCase().indexOf(headerPrefix)===0) return boxes[i].querySelector('.pe-bb select');
        }
        return null;
      }
      function selectedCategory(){
        var cbx=document.querySelector('#content .catlist .catopt .cbx.on');
        if(!cbx) return null;
        var opt=cbx.closest('.catopt'); if(!opt) return null;
        var spans=opt.querySelectorAll('span');
        for(var i=0;i<spans.length;i++){ if(/flex:1/.test(spans[i].getAttribute('style')||'')) return spans[i].textContent.trim(); }
        return null;
      }
      async function saveProduct(){
        var payload={};
        var rte=document.querySelector('#content .rte'); if(rte && rte._sync) rte._sync();   // flush Visual → textarea
        var t=document.querySelector('#content .pe-title'); if(t) payload.name=t.value.trim();
        var rp=fldByLabel('regular price'); if(rp && rp.value!=='') payload.price_aed=parseInt(rp.value,10)||0;
        var sp=fldByLabel('sale price');    if(sp) payload.sale_aed = (sp.value==='') ? null : (parseInt(sp.value,10)||0);
        var skuI=fldByLabel('sku');         if(skuI) payload.sku=skuI.value.trim();          // only if the Inventory tab was opened
        var brandSel=boxSelect('brands');   if(brandSel) payload.brand=brandSel.value;
        var cat=selectedCategory();         if(cat) payload.category=cat;
        var areas=document.querySelectorAll('#content .rte-area');
        if(areas[0]) payload.description=areas[0].value;
        if(areas[1]) payload.short_description=areas[1].value;
        if(peSeo && Object.keys(peSeo).length) payload.seo=peSeo;
        try{
          await api('/admin-api/products/'+pid, {method:'PUT', body:JSON.stringify(payload)});
          if(payload.name!=null) row[0]=payload.name;
          if(payload.brand!=null) row[1]=payload.brand;
          if(payload.sku!=null) row[2]=payload.sku;
          if(payload.category!=null) row[3]=payload.category;
          if(payload.price_aed!=null) row[4]=payload.price_aed;
          if('sale_aed' in payload) row[5]=payload.sale_aed;
          toast('Product saved'); go('catalog');
        }catch(e){ toast('Save failed \u2014 check connection'); }
      }
      Array.prototype.slice.call(document.querySelectorAll('#content button')).forEach(function(b){
        var txt=(b.textContent||'').trim();
        if(txt==='Update' || txt==='Publish') b.onclick=saveProduct;
      });
      // Load the REAL stored descriptions (the editor otherwise shows generated sample text,
      // which would clobber real data on save).
      api('/admin-api/products/'+pid).then(function(full){
        var areas=document.querySelectorAll('#content .rte-area');
        if(areas[0]) areas[0].value = full.description || '';
        if(areas[1]) areas[1].value = full.short_description || '';
        enhanceRTE();
        if(full.seo && typeof full.seo==='object'){ peSeo=full.seo; if(typeof yoastTab!=='undefined' && yoastTab==='seo') window.renderYoastBody(); }
      }).catch(function(){ enhanceRTE(); });
    };
  }

  /* ---------- Reviews moderation (rev-all) ---------- */
  var REV=[], revFilter='all', revSel=new Set(), REV_COUNTS={};
  function revStars(n){ var s=''; for(var i=1;i<=5;i++) s+=(i<=n?'\u2605':'\u2606'); return '<span style="color:#e0a11e;font-size:12px">'+s+'</span>'; }
  function revStatusPill(s){ var m={approved:'green',pending:'amber',rejected:'red'}[s]||'grey'; return '<span class="pill '+m+'"><span class="d"></span>'+s+'</span>'; }
  async function renderReviews(){
    try{ var d=await api('/admin-api/reviews'+(revFilter!=='all'?('?status='+revFilter):'')); REV=d.reviews||[]; REV_COUNTS=d.counts||{}; }catch(e){ REV=[]; REV_COUNTS={}; }
    var chips=[['all','All'],['pending','Pending'],['approved','Approved'],['rejected','Rejected']];
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Reviews</h2><p>Moderate customer reviews. Approved reviews appear on the storefront.</p></div>'+
      '<div class="chips" style="margin:12px 0 14px">'+chips.map(function(c){return '<button class="chip'+(revFilter===c[0]?' on':'')+'" data-rf="'+c[0]+'">'+c[1]+(REV_COUNTS[c[0]]!=null?(' \u00b7 '+REV_COUNTS[c[0]]):'')+'</button>';}).join('')+'</div>'+
      '<div id="revBulk"></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th style="width:30px"></th><th>Product</th><th>Author</th><th>Review</th><th>Status</th><th></th></tr></thead><tbody>'+
      (REV.length? REV.map(function(r){
        return '<tr><td><span class="cbx'+(revSel.has(r.id)?' on':'')+'" data-rsel="'+r.id+'">'+ic(I.check)+'</span></td>'+
          '<td style="font-size:12px">'+sesc(r.product)+'</td>'+
          '<td><div class="pname">'+sesc(r.author)+(r.verified?' <span class="pill green" style="font-size:9px;padding:1px 6px">Verified</span>':'')+'</div>'+revStars(r.rating)+'</td>'+
          '<td style="max-width:320px"><b style="font-size:12.5px">'+sesc((r.title||''))+'</b><div class="pbrand" style="white-space:normal">'+sesc((r.body||'').slice(0,140))+((r.body||'').length>140?'\u2026':'')+'</div>'+(r.reply?('<div style="margin-top:5px;font-size:11.5px;color:var(--accent-strong)">\u21b3 '+r.reply.slice(0,120)+'</div>'):'')+'</td>'+
          '<td>'+revStatusPill(r.status)+'</td>'+
          '<td><div class="row" style="gap:5px">'+
            (r.status!=='approved'?'<button class="btn ghost sm" data-rapp="'+r.id+'">Approve</button>':'')+
            (r.status!=='rejected'?'<button class="btn ghost sm" data-rrej="'+r.id+'">Reject</button>':'')+
            '<button class="btn ghost sm" data-rrep="'+r.id+'">Reply</button></div></td></tr>';
      }).join('') : '<tr><td colspan="6" style="text-align:center;color:var(--ink-soft);padding:34px">No reviews'+(revFilter!=='all'?(' with status \u201c'+revFilter+'\u201d'):'')+'.</td></tr>')+
      '</tbody></table></div><div class="pager"><span>Showing '+REV.length+'</span></div></div>';
    document.querySelectorAll('#content .chip[data-rf]').forEach(function(c){ c.onclick=function(){ revFilter=c.dataset.rf; revSel.clear(); renderReviews(); }; });
    document.querySelectorAll('#content [data-rsel]').forEach(function(c){ c.onclick=function(){ var id=+c.dataset.rsel; revSel.has(id)?revSel.delete(id):revSel.add(id); renderReviews(); }; });
    document.querySelectorAll('#content [data-rapp]').forEach(function(b){ b.onclick=function(){ moderate(+b.dataset.rapp,'approved'); }; });
    document.querySelectorAll('#content [data-rrej]').forEach(function(b){ b.onclick=function(){ moderate(+b.dataset.rrej,'rejected'); }; });
    document.querySelectorAll('#content [data-rrep]').forEach(function(b){ b.onclick=function(){ replyReview(+b.dataset.rrep); }; });
    var bulk=document.getElementById('revBulk');
    if(revSel.size) bulk.innerHTML='<div class="bulkbar"><span class="cbx on" data-rclear>'+ic(I.check)+'</span> '+revSel.size+' selected<div style="flex:1"></div><button class="btn ghost sm" data-rbulk="approve">Approve</button><button class="btn ghost sm" data-rbulk="reject">Reject</button></div>';
    var clr=document.querySelector('#content [data-rclear]'); if(clr) clr.onclick=function(){ revSel.clear(); renderReviews(); };
    document.querySelectorAll('#content [data-rbulk]').forEach(function(b){ b.onclick=function(){ bulkModerate(b.dataset.rbulk); }; });
  }
  async function moderate(id,status){ try{ await api('/admin-api/reviews/'+id,{method:'PUT',body:JSON.stringify({status:status})}); toast('Review '+status); renderReviews(); }catch(e){ toast('Action failed'); } }
  async function bulkModerate(action){ var ids=Array.prototype.slice.call(revSel); if(!ids.length)return; try{ await api('/admin-api/reviews/bulk',{method:'POST',body:JSON.stringify({action:action,ids:ids})}); toast(action+'d '+ids.length); revSel.clear(); renderReviews(); }catch(e){ toast('Bulk action failed'); } }
  function replyReview(id){
    var r=REV.filter(function(x){return x.id===id;})[0]; if(!r)return;
    openModal('<div class="modal-h"><b>Reply to '+sesc(r.author)+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div style="font-size:12.5px;color:var(--ink-soft);margin-bottom:8px">'+revStars(r.rating)+' \u00b7 '+sesc(r.title||'')+'</div>'+
      '<textarea class="inp" id="revReplyTxt" style="width:100%;min-height:96px" placeholder="Public reply shown under the review\u2026">'+sesc((r.reply||''))+'</textarea>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="revReplySave">Save reply</button></div></div>');
    document.getElementById('revReplySave').onclick=async function(){
      var txt=document.getElementById('revReplyTxt').value;
      try{ await api('/admin-api/reviews/'+id,{method:'PUT',body:JSON.stringify({reply:txt})}); toast('Reply saved'); closeModal(); renderReviews(); }catch(e){ toast('Save failed'); }
    };
  }

  /* ---------- Analytics (derived from real orders) ---------- */
  async function renderAnalytics(){
    var a; try{ a=await api('/admin-api/analytics'); }catch(e){ a=null; }
    if(!a){ document.querySelector('#content').innerHTML='<div class="wrap"><div class="page-head"><h2>Analytics</h2><p>Could not load analytics.</p></div></div>'; return; }
    function kpi(label,val,sub){ return '<div class="kpi"><div class="lbl">'+label+'</div><div class="val">'+val+'</div><div class="sub">'+(sub||'')+'</div></div>'; }
    var daily=a.daily||[]; var max=Math.max(1, Math.max.apply(null, daily.map(function(d){return d.revenue_aed;})));
    var n=daily.length||1, bw=100/n;
    var bars=daily.map(function(d,i){ var h=(d.revenue_aed/max)*96; return '<rect x="'+(i*bw+bw*0.15).toFixed(2)+'" y="'+(100-h).toFixed(2)+'" width="'+(bw*0.7).toFixed(2)+'" height="'+h.toFixed(2)+'" rx="0.5" fill="var(--accent)"><title>'+d.date+': AED '+d.revenue_aed.toLocaleString()+'</title></rect>'; }).join('');
    var chart='<svg viewBox="0 0 100 100" preserveAspectRatio="none" style="width:100%;height:170px;display:block;margin-top:10px">'+bars+'</svg>';
    var firstD=(daily[0]||{}).date||'', lastD=(daily[daily.length-1]||{}).date||'';
    var sb=a.status_breakdown||{};
    var statusHtml=Object.keys(sb).map(function(k){ var m={completed:'green',processing:'amber',onhold:'amber',pending:'grey',cancelled:'red',refunded:'red',failed:'red'}[k]||'grey'; return '<span class="pill '+m+'" style="margin:2px"><span class="d"></span>'+k+': '+sb[k]+'</span>'; }).join('');
    var tp=a.top_products||[];
    var rows=tp.length? tp.map(function(p){ return '<tr><td><div class="pname">'+sesc(p.name)+'</div><div class="pbrand">'+sesc((p.brand||''))+'</div></td><td>'+p.units+'</td><td class="price"><b>AED '+p.revenue_aed.toLocaleString()+'</b></td></tr>'; }).join('') : '<tr><td colspan="3" style="text-align:center;color:var(--ink-soft);padding:24px">No sales yet.</td></tr>';
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Analytics</h2><p>Sales performance from real orders. Revenue counts processing, on-hold and completed orders.</p></div>'+
      '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">'+
        kpi('Revenue','AED '+a.revenue_total_aed.toLocaleString(),'all paid orders')+
        kpi('Orders',a.orders_total.toLocaleString(),a.paid_orders+' paid')+
        kpi('Avg order value','AED '+a.aov_aed.toLocaleString(),'per paid order')+
        kpi('Units sold',a.units_sold.toLocaleString(),'line items')+
      '</div>'+
      '<div class="card pad" style="margin-bottom:16px"><div class="between"><b style="font-size:13px">Revenue \u2014 last 14 days</b><span class="pbrand">'+firstD+' \u2192 '+lastD+'</span></div>'+chart+'</div>'+
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Order status</b><div style="margin-top:10px">'+(statusHtml||'<span class="pbrand">No orders yet</span>')+'</div></div>'+
      '<div class="card" style="overflow:auto"><div class="pad" style="padding-bottom:0"><b style="font-size:13px">Top products</b></div><table><thead><tr><th>Product</th><th>Units</th><th>Revenue</th></tr></thead><tbody>'+rows+'</tbody></table></div>'+
      '</div>';
  }

  /* ---------- Users & Roles (real admin accounts) ---------- */
  var ADMINS=[];
  var ROLE_OPTS=[['owner','Owner'],['manager','Manager'],['support','Support'],['editor','Content Editor']];
  function roleBadge(r){ var m={owner:'green',manager:'amber',support:'grey',editor:'grey'}[r]||'grey'; var lbl=(ROLE_OPTS.filter(function(o){return o[0]===r;})[0]||[r,r])[1]; return '<span class="pill '+m+'"><span class="d"></span>'+lbl+'</span>'; }
  function roleSelect(id, sel){ return '<select class="inp" id="'+id+'" style="width:100%">'+ROLE_OPTS.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===sel?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>'; }

  window.renderUsers = async function(){
    try{ var d=await api('/admin-api/users'); ADMINS=d.users||[]; }catch(e){ ADMINS=[]; }
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="between" style="margin-bottom:16px"><div class="page-head" style="margin:0"><h2>Users &amp; Roles</h2><p>Staff accounts and what each can do. Customer accounts live in the Customers module.</p></div>'+
      '<button class="btn" id="usr_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add user</button></div>'+
      '<div class="card" style="overflow:auto"><table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Added</th><th></th></tr></thead><tbody>'+
      (ADMINS.length? ADMINS.map(function(u){
        return '<tr><td><div class="row"><span class="pthumb" style="background:'+sesc(tcol(u.name||u.email))+';width:30px;height:30px;font-size:10px">'+sesc(initials(u.name||u.email))+'</span><b style="font-size:12.5px">'+sesc((u.name||'\u2014'))+(u.is_self?' <span class="pbrand" style="display:inline">(you)</span>':'')+'</b></div></td>'+
          '<td style="font-size:12px">'+sesc(u.email)+'</td>'+
          '<td>'+roleBadge(u.role)+'</td>'+
          '<td style="font-size:11.5px;color:var(--ink-soft)">'+(u.created_at||'').slice(0,10)+'</td>'+
          '<td><div class="row" style="gap:5px"><button class="btn ghost sm" data-uedit="'+u.id+'">Edit</button>'+
          '<button class="btn ghost sm" data-upass="'+u.id+'">Reset password</button>'+
          (u.is_self?'':'<button class="btn ghost sm" data-udel="'+u.id+'">Delete</button>')+'</div></td></tr>';
      }).join('') : '<tr><td colspan="5" style="text-align:center;color:var(--ink-soft);padding:30px">No users.</td></tr>')+
      '</tbody></table></div>'+
      '<div class="sec-title">Roles</div><div class="mod-grid">'+
      ['Owner|Full access to everything','Manager|Catalog, orders, customers, marketing','Support|Orders & customers (read + reply)','Content Editor|Pages, blog, media'].map(function(r){var p=r.split('|');return '<div class="mod"><div class="mic">'+ic(I.shield)+'</div><div><div class="mname">'+p[0]+'</div><div class="mdesc">'+p[1]+'</div></div></div>';}).join('')+
      '</div></div>';
    document.getElementById('usr_add').onclick=addUser;
    document.querySelectorAll('#content [data-uedit]').forEach(function(b){ b.onclick=function(){ editUser(+b.dataset.uedit); }; });
    document.querySelectorAll('#content [data-upass]').forEach(function(b){ b.onclick=function(){ resetUserPassword(+b.dataset.upass); }; });
    document.querySelectorAll('#content [data-udel]').forEach(function(b){ b.onclick=function(){ deleteUser(+b.dataset.udel); }; });
  };

  function addUser(){
    openModal('<div class="modal-h"><b>Add user</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>Name</label><input id="nu_name"></div>'+
      '<div class="fld"><label>Email</label><input id="nu_email" type="email"></div>'+
      '<div class="fld"><label>Temporary password</label><input id="nu_pass" type="text" placeholder="min 8 characters"></div>'+
      '<div class="fld"><label>Role</label>'+roleSelect('nu_role','manager')+'</div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="nu_save">Create user</button></div></div>');
    document.getElementById('nu_save').onclick=async function(){
      var body={ name:sval('nu_name'), email:sval('nu_email'), password:sval('nu_pass'), role:sval('nu_role') };
      if(!body.name||!body.email||body.password.length<8){ toast('Name, email and an 8+ char password are required'); return; }
      try{ await api('/admin-api/users',{method:'POST',body:JSON.stringify(body)}); toast('User created'); closeModal(); renderUsers(); }
      catch(e){ toast('Could not create user (email may already exist)'); }
    };
  }
  function editUser(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Edit '+sesc(u.name||u.email)+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>Name</label><input id="eu_name" value="'+sesc(u.name)+'"></div>'+
      '<div class="fld"><label>Role</label>'+roleSelect('eu_role',u.role)+'</div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="eu_save">Save</button></div></div>');
    document.getElementById('eu_save').onclick=async function(){
      try{ await api('/admin-api/users/'+id,{method:'PUT',body:JSON.stringify({name:sval('eu_name'),role:sval('eu_role')})}); toast('User updated'); closeModal(); renderUsers(); }
      catch(e){ toast('Update failed (cannot demote the only owner)'); }
    };
  }
  function resetUserPassword(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Reset password \u2014 '+sesc(u.name||u.email)+'</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><div class="fld"><label>New password</label><input id="rp_pass" type="text" placeholder="min 8 characters"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" id="rp_save">Set password</button></div></div>');
    document.getElementById('rp_save').onclick=async function(){
      var pw=sval('rp_pass'); if(pw.length<8){ toast('Password must be at least 8 characters'); return; }
      try{ await api('/admin-api/users/'+id,{method:'PUT',body:JSON.stringify({password:pw})}); toast('Password updated'); closeModal(); }
      catch(e){ toast('Could not update password'); }
    };
  }
  async function deleteUser(id){
    var u=ADMINS.filter(function(x){return x.id===id;})[0]; if(!u)return;
    openModal('<div class="modal-h"><b>Delete user</b><button class="x" onclick="closeModal()">\u2715</button></div>'+
      '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">Remove <b>'+sesc((u.name||u.email))+'</b>? This cannot be undone.</p>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn" style="background:var(--danger,#d6455a)" id="du_yes">Delete</button></div></div>');
    document.getElementById('du_yes').onclick=async function(){
      try{ await api('/admin-api/users/'+id,{method:'DELETE'}); toast('User deleted'); closeModal(); renderUsers(); }
      catch(e){ toast('Could not delete (cannot remove yourself or the only owner)'); }
    };
  }

  /* ---------- Store settings (Business Details) + SEO & Meta ---------- */
  var SETTINGS={};
  function sesc(v){ return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
  function sval(id){ var el=document.getElementById(id); return el?el.value:''; }
  function money2aed(k){ var v=SETTINGS[k]; return (v==null||v==='')?'':(parseInt(v,10)/100); }
  async function loadSettings(){ try{ var d=await api('/admin-api/settings'); SETTINGS=d.settings||{}; }catch(e){ SETTINGS={}; } return SETTINGS; }

  /* The currency table, rendered from app/Support/Currencies.php so a symbol or
     a decimal count is never restated here. Curated, not all ~135 Stripe
     currencies — see the comment in that file. */
@endverbatim
  var KBB_CURRENCIES = @json(\App\Support\Currencies::forSelect());
@verbatim
  function curFind(code){ code=String(code||'').toUpperCase(); for(var i=0;i<KBB_CURRENCIES.length;i++){ if(KBB_CURRENCIES[i].code===code) return KBB_CURRENCIES[i]; } return null; }
  function curSymbol(){ var s=SETTINGS.currency_symbol; if(s!=null&&String(s).trim()!=='') return String(s); var c=curFind(SETTINGS.currency||'AED'); return c?c.symbol:''; }
  function curSelect(){
    var cur=String(SETTINGS.currency||'AED').toUpperCase(), seen=false;
    var out=KBB_CURRENCIES.map(function(c){ if(c.code===cur) seen=true; return '<option value="'+sesc(c.code)+'"'+(c.code===cur?' selected':'')+'>'+sesc(c.code+' — '+c.name)+'</option>'; }).join('');
    if(!seen&&cur) out='<option value="'+sesc(cur)+'" selected>'+sesc(cur)+'</option>'+out;
    return '<select class="inp" id="set_currency" style="width:100%">'+out+'</select>';
  }

  async function renderStoreSettings(){
    await loadSettings();
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>Business Details</h2><p>Core store configuration. These values drive the storefront and checkout totals.</p></div>'+
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Store</b>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Store name</label><input id="set_store_name" value="'+sesc(SETTINGS.store_name)+'"></div>'+
      '<div class="fld"><label>Currency</label>'+curSelect()+'</div></div>'+
      '<div class="fld" style="max-width:220px"><label>VAT rate (%)</label><input id="set_vat" type="number" step="0.01" value="'+sesc(SETTINGS.vat_rate)+'"></div></div>'+
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Currency display</b>'+
      '<p style="font-size:11.5px;color:var(--ink-soft);margin:4px 0 12px">How prices are printed everywhere on the storefront. Choosing a currency above fills in its symbol and decimals — you can still override the symbol.</p>'+
      '<div class="g2"><div class="fld"><label>Symbol</label><input id="set_currency_symbol" value="'+sesc(SETTINGS.currency_symbol)+'" placeholder="'+sesc(curSymbol())+'">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">Leave blank to use the selected currency’s own symbol.</p></div>'+
      '<div class="fld"><label>Symbol rendering</label>'+seoSel('set_currency_symbol_render',SETTINGS.currency_symbol_render,[['unicode','Unicode character — correct, may show an empty box'],['svg','Drawn glyph (SVG) — always renders']],'unicode')+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">The dirham sign “⃃” was accepted by Unicode in July 2025 and ships in Unicode 18.0 (September 2026), so most devices have no font glyph for it yet and draw an empty box instead. <b>Unicode</b> is the default because it puts the real character in the page — right for copy-paste, screen readers and search engines. If the empty box bothers you, switch to <b>Drawn glyph</b>: the storefront then draws the symbol itself and it always renders.</p></div></div>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Symbol position</label>'+seoSel('set_currency_position',SETTINGS.currency_position,[['before','Before the number — '+sesc(curSymbol())+'199'],['before_space','Before, with a space — '+sesc(curSymbol())+' 199'],['after','After the number — 199'+sesc(curSymbol())],['after_space','After, with a space — 199 '+sesc(curSymbol())]],'before')+'</div>'+
      '<div class="fld"><label>Decimal places</label><input id="set_currency_decimals" type="number" min="0" max="4" step="1" value="'+sesc(SETTINGS.currency_decimals)+'" placeholder="blank — whole numbers">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">Drives both what is printed and how stored amounts are read back (2 = hundredths, which is how every amount already in the database is stored). Leave blank to keep the current whole-dirham display.</p></div></div></div>'+
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Shipping &amp; COD</b>'+
      '<p style="font-size:11.5px;color:var(--ink-soft);margin:4px 0 12px">All amounts in AED. Free shipping applies when the cart subtotal reaches the threshold.</p>'+
      '<div class="g2"><div class="fld"><label>Free-shipping threshold (AED)</label><input id="set_free_ship" type="number" step="1" value="'+money2aed('free_ship')+'"></div>'+
      '<div class="fld"><label>Flat delivery fee (AED)</label><input id="set_delivery" type="number" step="1" value="'+money2aed('delivery_flat')+'"></div></div>'+
      '<div class="fld" style="max-width:220px"><label>COD fee (AED)</label><input id="set_cod" type="number" step="1" value="'+money2aed('cod_fee')+'"></div></div>'+
      '<div class="row" style="justify-content:flex-end"><button class="btn" id="set_save_biz">Save changes</button></div></div>';
    /* Picking a currency fills in its symbol and decimals; both stay editable. */
    var curSel=document.getElementById('set_currency');
    if(curSel) curSel.onchange=function(){
      var c=curFind(curSel.value); if(!c) return;
      var symEl=document.getElementById('set_currency_symbol'), decEl=document.getElementById('set_currency_decimals');
      if(symEl){ symEl.value=c.symbol; symEl.placeholder=c.symbol; }
      if(decEl) decEl.value=String(c.decimals);
    };
    document.getElementById('set_save_biz').onclick=async function(){
      var payload={
        store_name: sval('set_store_name'), currency: sval('set_currency'), vat_rate: sval('set_vat'),
        currency_symbol: sval('set_currency_symbol'),
        currency_symbol_render: sval('set_currency_symbol_render'),
        currency_position: sval('set_currency_position'),
        currency_decimals: sval('set_currency_decimals'),
        free_ship: String(Math.round((parseFloat(sval('set_free_ship'))||0)*100)),
        delivery_flat: String(Math.round((parseFloat(sval('set_delivery'))||0)*100)),
        cod_fee: String(Math.round((parseFloat(sval('set_cod'))||0)*100))

      };
      try{ await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})}); Object.assign(SETTINGS,payload); toast('Business details saved'); }
      catch(e){ toast('Save failed \u2014 check connection'); }
    };
  }

  function seoSel(id,cur,opts,dflt){ cur=(cur==null||cur==='')?dflt:cur; return '<select class="inp" id="'+id+'" style="width:100%">'+opts.map(function(o){return '<option value="'+o[0]+'"'+(o[0]===cur?' selected':'')+'>'+o[1]+'</option>';}).join('')+'</select>'; }

  /**
   * A real upload widget — click-to-browse (or drag a file onto it) with a
   * live preview — replacing a raw "paste a URL" text field so a
   * non-technical store owner never has to know how to host an image
   * somewhere else first. Falls back to a plain URL input underneath for
   * anyone who already has one.
   */
  function imgUploadField(id,curUrl,label,folder){
    var hasImg = curUrl && curUrl.trim()!=='';
    return '<div class="fld"><label>'+label+'</label>'+
      '<div class="imgup" id="'+id+'_zone" style="border:1.5px dashed var(--border);border-radius:10px;padding:14px;text-align:center;cursor:pointer;position:relative">'+
      '<input type="file" id="'+id+'_file" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml" style="position:absolute;inset:0;opacity:0;cursor:pointer">'+
      '<div id="'+id+'_preview" style="'+(hasImg?'':'display:none')+';margin-bottom:8px"><img src="'+sesc(curUrl)+'" style="max-height:70px;max-width:100%;border-radius:6px;display:'+(hasImg?'block':'none')+';margin:0 auto"></div>'+
      '<div id="'+id+'_prompt" style="font-size:12px;color:var(--ink-soft)">'+(hasImg?'Click to replace':'Click to upload, or drag an image here')+'</div>'+
      '<div id="'+id+'_status" style="font-size:11.5px;color:var(--ink-soft);margin-top:4px"></div>'+
      '</div>'+
      '<input type="hidden" id="'+id+'" value="'+sesc(curUrl)+'">'+
      '<p class="description" style="margin:6px 0 0"><a href="#" id="'+id+'_manual" style="font-size:11.5px">or paste a URL directly</a></p>'+
      '<input id="'+id+'_url" class="inp" style="display:none;margin-top:6px" value="'+sesc(curUrl)+'" placeholder="https://…"></div>';
  }

  function wireImgUpload(id,folder){
    var zone=document.getElementById(id+'_zone'), fileInput=document.getElementById(id+'_file'),
        hidden=document.getElementById(id), preview=document.getElementById(id+'_preview'),
        img=preview?preview.querySelector('img'):null, prompt=document.getElementById(id+'_prompt'),
        status=document.getElementById(id+'_status'), manualLink=document.getElementById(id+'_manual'),
        urlInput=document.getElementById(id+'_url');
    if(!zone) return;

    async function doUpload(file){
      if(!file) return;
      status.textContent='Uploading…';
      try{
        var fd=new FormData(); fd.append('file',file); fd.append('folder',folder||'seo');
        var res=await api('/admin-api/media/upload',{method:'POST',body:fd});
        hidden.value=res.url; urlInput.value=res.url;
        img.src=res.url; img.style.display='block'; preview.style.display='block';
        prompt.textContent='Click to replace'; status.textContent='Uploaded';
        setTimeout(function(){status.textContent='';},1800);
      }catch(e){ status.textContent='Upload failed — check connection'; }
    }

    fileInput.onchange=function(){ doUpload(fileInput.files[0]); };
    zone.ondragover=function(e){ e.preventDefault(); zone.style.borderColor='var(--accent)'; };
    zone.ondragleave=function(){ zone.style.borderColor='var(--border)'; };
    zone.ondrop=function(e){ e.preventDefault(); zone.style.borderColor='var(--border)'; if(e.dataTransfer.files[0]) doUpload(e.dataTransfer.files[0]); };
    manualLink.onclick=function(e){
      e.preventDefault();
      urlInput.style.display = urlInput.style.display==='none' ? 'block' : 'none';
    };
    urlInput.oninput=function(){ hidden.value=urlInput.value; if(urlInput.value){ img.src=urlInput.value; img.style.display='block'; preview.style.display='block'; } };
  }

  let seoTab='settings';

  async function renderSeo(){
    document.querySelector('#content').innerHTML =
      '<div class="wrap"><div class="page-head"><h2>SEO &amp; Meta</h2><p>Site-wide search-engine settings. These render into every storefront page\u2019s &lt;head&gt; and power the sitemap, robots.txt and structured data.</p></div>'+
      '<div class="subtabs"><button class="subtab'+(seoTab==='settings'?' on':'')+'" data-st="settings">Settings</button><button class="subtab'+(seoTab==='redirects'?' on':'')+'" data-st="redirects">Redirects &amp; 404s</button><button class="subtab'+(seoTab==='schema'?' on':'')+'" data-st="schema">Schema Inspector</button><button class="subtab'+(seoTab==='audit'?' on':'')+'" data-st="audit">Catalogue Audit</button></div>'+
      '<div id="seoTabBody"></div></div>';
    $$('#content .subtab').forEach(function(b){ b.onclick=function(){ seoTab=b.dataset.st; renderSeo(); }; });
    if(seoTab==='redirects') return renderSeoRedirects();
    if(seoTab==='schema') return renderSchemaInspector();
    if(seoTab==='audit') return renderCatalogueAudit();
    return renderSeoSettings();
  }

  async function renderSeoSettings(){
    await loadSettings(); var S=SETTINGS;
    var base=(location.origin||'');
    document.getElementById('seoTabBody').innerHTML =

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Search appearance</b>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Site URL (canonical base)</label><input id="seo_site_url" value="'+sesc(S.site_url)+'" placeholder="https://kbeautybliss.com"></div>'+
      '<div class="fld"><label>Site name</label><input id="seo_sitename" value="'+sesc(S.seo_site_name||S.store_name)+'" placeholder="K-Beauty Bliss"></div></div>'+
      '<div class="g2"><div class="fld"><label>Title separator</label><input id="seo_sep" value="'+sesc(S.seo_separator||'|')+'" style="max-width:120px"></div>'+
      '<div class="fld"><label>Title template</label><input id="seo_tpl" value="'+sesc(S.seo_title_template||'{title} {sep} {sitename}')+'" placeholder="{title} {sep} {sitename}"></div></div>'+
      '<div class="fld"><label>Homepage title</label><input id="seo_home_t" value="'+sesc(S.seo_home_title)+'" placeholder="K-Beauty Bliss \u2014 Korean skincare for the UAE"></div>'+
      '<div class="fld"><label>Homepage meta description</label><textarea id="seo_home_d" class="inp" style="width:100%;min-height:60px">'+sesc(S.seo_home_description)+'</textarea></div>'+
      '<div class="fld"><label>Default meta description (fallback)</label><textarea id="seo_def_d" class="inp" style="width:100%;min-height:60px">'+sesc(S.seo_default_description)+'</textarea></div>'+
      '<div class="g2"><div class="fld"><label>Search engines</label>'+seoSel('seo_robots_i',S.robots_index,[['index','Index (allow ranking)'],['noindex','Noindex (hide from search)']],'index')+'</div>'+
      '<div class="fld"><label>Follow links</label>'+seoSel('seo_robots_f',S.robots_follow,[['follow','Follow'],['nofollow','Nofollow']],'follow')+'</div></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Social (Open Graph &amp; Twitter)</b>'+
      '<p class="description" style="margin:4px 0 0">The share image and title/description below apply everywhere a link is shared \u2014 Facebook, LinkedIn, WhatsApp, Pinterest, iMessage \u2014 they all read the same Open Graph tags. Twitter/X alone uses its own separate card format, which is why it gets its own field just below.</p>'+
      '<div class="g2" style="margin-top:12px">'+imgUploadField('seo_og_img',S.og_default_image,'Default share image (1200\u00d7630)','seo')+
      '<div class="fld"><label>Twitter / X handle</label><input id="seo_tw" value="'+sesc(S.twitter_handle)+'" placeholder="@kbeautybliss"></div></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Social profile links</b>'+
      '<p class="description" style="margin:4px 0 0">Linked into your Organization schema below (as <code>sameAs</code>) so Google can confirm these are genuinely your official profiles \u2014 helps your Knowledge Panel and brand search results. Leave any blank you do not have.</p>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Facebook</label><input id="seo_soc_fb" value="'+sesc(S.social_facebook)+'" placeholder="https://facebook.com/kbeautybliss"></div>'+
      '<div class="fld"><label>Instagram</label><input id="seo_soc_ig" value="'+sesc(S.social_instagram)+'" placeholder="https://instagram.com/kbeautybliss"></div></div>'+
      '<div class="g2"><div class="fld"><label>TikTok</label><input id="seo_soc_tt" value="'+sesc(S.social_tiktok)+'" placeholder="https://tiktok.com/@kbeautybliss"></div>'+
      '<div class="fld"><label>Pinterest</label><input id="seo_soc_pin" value="'+sesc(S.social_pinterest)+'" placeholder="https://pinterest.com/kbeautybliss"></div></div>'+
      '<div class="g2"><div class="fld"><label>LinkedIn</label><input id="seo_soc_li" value="'+sesc(S.social_linkedin)+'" placeholder="https://linkedin.com/company/kbeautybliss"></div>'+
      '<div class="fld"><label>YouTube</label><input id="seo_soc_yt" value="'+sesc(S.social_youtube)+'" placeholder="https://youtube.com/@kbeautybliss"></div></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Organization (schema.org)</b>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Organization name</label><input id="seo_org_name" value="'+sesc(S.org_name||S.store_name)+'"></div>'+
      '<div class="fld"><label>Type</label>'+seoSel('seo_org_type',S.org_type,[['Organization','Organization'],['OnlineStore','OnlineStore'],['Store','Store'],['LocalBusiness','LocalBusiness']],'Organization')+'</div></div>'+
      imgUploadField('seo_org_logo',S.org_logo,'Logo','seo')+'</div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Verification &amp; tracking</b>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>Google Search Console</label><input id="seo_gsv" value="'+sesc(S.google_site_verification)+'" placeholder="verification token"></div>'+
      '<div class="fld"><label>Bing Webmaster</label><input id="seo_bing" value="'+sesc(S.bing_site_verification)+'" placeholder="verification token"></div></div>'+
      '<div class="g2"><div class="fld"><label>Pinterest</label><input id="seo_pin" value="'+sesc(S.pinterest_site_verification)+'" placeholder="verification token"></div>'+
      '<div class="fld"><label>Baidu</label><input id="seo_baidu" value="'+sesc(S.baidu_site_verification)+'" placeholder="verification token"></div></div>'+
      '<div class="g2"><div class="fld"><label>Google Analytics ID</label><input id="seo_ga" value="'+sesc(S.ga)+'" placeholder="G-XXXXXXXXXX"></div>'+
      '<div class="fld"><label>Meta (Facebook) Pixel</label><input id="seo_pixel" value="'+sesc(S.meta_pixel)+'" placeholder="123456789012345"></div></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Merchant listing (rich product results)</b>'+
      '<p class="description" style="margin:4px 0 12px">Adds brand, condition, shipping and return policy to every product schema \u2014 what actually unlocks price and star ratings showing directly in Google, and eligibility for AI Shopping. Off by default: wrong shipping or return terms going out to search engines is worse than none at all, so this only turns on once you have confirmed the numbers below are accurate.</p>'+
      '<div class="fld"><label><span class="cbx'+(String(S.enable_merchant)==='1'?' on':'')+'" id="seo_merchant_cbx">'+ic(I.check)+'</span> Enable merchant listing on every product</label></div>'+
      '<div class="g2" style="margin-top:10px"><div class="fld"><label>Condition</label>'+seoSel('seo_merch_cond',S.merchant_condition,[['NewCondition','New'],['UsedCondition','Used'],['RefurbishedCondition','Refurbished']],'NewCondition')+'</div>'+
      '<div class="fld"><label>Ship-to country</label><input id="seo_merch_country" value="'+sesc(S.merchant_ship_country||'AE')+'" maxlength="2" style="max-width:100px;text-transform:uppercase"></div></div>'+
      '<div class="g2"><div class="fld"><label>Shipping cost (AED)</label><input id="seo_merch_cost" type="number" step="0.01" value="'+sesc(S.merchant_ship_cost||'0')+'"></div>'+
      '<div class="fld"><label>Free shipping over (AED, 0 = never)</label><input id="seo_merch_freeover" type="number" step="1" value="'+sesc(S.merchant_ship_free_over||'0')+'"></div></div>'+
      '<div class="fld" style="margin-bottom:0;max-width:220px"><label>Return window (days, 0 = no policy shown)</label><input id="seo_merch_returndays" type="number" value="'+sesc(S.merchant_return_days||'0')+'"></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Search-engine support</b>'+
      '<div class="fld" style="margin-top:12px"><label><span class="cbx'+(String(S.indexnow_on)==='1'?' on':'')+'" id="seo_indexnow_cbx">'+ic(I.check)+'</span> Instant indexing (IndexNow)</label>'+
      '<p class="description" style="margin:6px 0 0">Auto-submits new/updated product and blog URLs to Bing, Yandex, Naver, Seznam &amp; Yep the moment they publish. Google is not part of IndexNow \u2014 it uses your sitemap instead.'+(String(S.indexnow_on)==='1'?(' Key file: <a href="'+base+'/'+sesc(S.indexnow_key||'')+'.txt" target="_blank">'+sesc(S.indexnow_key||'(generated on first use)')+'.txt \u2197</a>'):'')+'</p></div>'+
      '<div class="fld"><label><span class="cbx'+(String(S.llms_enabled)!=='0'?' on':'')+'" id="seo_llms_cbx">'+ic(I.check)+'</span> Publish <a href="'+base+'/llms.txt" target="_blank">/llms.txt</a> for AI crawlers</label></div>'+
      '<div class="fld" style="margin-bottom:0"><label><span class="cbx'+(String(S.crawl_clean)!=='0'?' on':'')+'" id="seo_crawlclean_cbx">'+ic(I.check)+'</span> Crawl-budget cleanup</label>'+
      '<p class="description" style="margin:6px 0 0">Filtered and sorted views of the shop (by brand, price, in-stock, sort order) point their canonical tag back at the clean category URL, so search engines consolidate ranking signals there instead of treating every filter combination as a separate page. Paginated pages keep their own canonical \u2014 only filters and sort collapse, page 2 onward still index normally.</p></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Sitemap &amp; robots</b>'+
      '<div class="row" style="gap:8px;margin:10px 0 12px"><a class="btn ghost sm" href="'+base+'/sitemap.xml" target="_blank">View sitemap.xml</a><a class="btn ghost sm" href="'+base+'/robots.txt" target="_blank">View robots.txt</a></div>'+
      '<div class="fld"><label>XML sitemap</label>'+seoSel('seo_sitemap',S.sitemap_enabled,[['1','Enabled'],['0','Disabled']],'1')+'</div>'+
      '<div class="fld" style="margin:0"><label>robots.txt (leave blank for the smart default)</label><textarea id="seo_robots_txt" class="inp" style="width:100%;min-height:90px;font-family:ui-monospace,monospace;font-size:12px" placeholder="User-agent: *\nAllow: /">'+sesc(S.robots_txt)+'</textarea></div></div>'+

      '<div class="row" style="justify-content:flex-end"><button class="btn" id="set_save_seo">Save SEO settings</button></div>';

    document.getElementById('seo_indexnow_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_llms_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_crawlclean_cbx').onclick=function(){ this.classList.toggle('on'); };
    document.getElementById('seo_merchant_cbx').onclick=function(){ this.classList.toggle('on'); };
    wireImgUpload('seo_og_img','seo');
    wireImgUpload('seo_org_logo','seo');

    document.getElementById('set_save_seo').onclick=async function(){
      var payload={
        site_url:sval('seo_site_url'), seo_site_name:sval('seo_sitename'), seo_separator:sval('seo_sep'), seo_title_template:sval('seo_tpl'),
        seo_home_title:sval('seo_home_t'), seo_home_description:sval('seo_home_d'), seo_default_description:sval('seo_def_d'),
        robots_index:sval('seo_robots_i'), robots_follow:sval('seo_robots_f'),
        og_default_image:sval('seo_og_img'), twitter_handle:sval('seo_tw'),
        social_facebook:sval('seo_soc_fb'), social_instagram:sval('seo_soc_ig'), social_tiktok:sval('seo_soc_tt'),
        social_pinterest:sval('seo_soc_pin'), social_linkedin:sval('seo_soc_li'), social_youtube:sval('seo_soc_yt'),
        org_name:sval('seo_org_name'), org_type:sval('seo_org_type'), org_logo:sval('seo_org_logo'),
        google_site_verification:sval('seo_gsv'), bing_site_verification:sval('seo_bing'),
        pinterest_site_verification:sval('seo_pin'), baidu_site_verification:sval('seo_baidu'),
        ga:sval('seo_ga'), meta_pixel:sval('seo_pixel'),
        sitemap_enabled:sval('seo_sitemap'), robots_txt:sval('seo_robots_txt'),
        indexnow_on:document.getElementById('seo_indexnow_cbx').classList.contains('on')?'1':'0',
        llms_enabled:document.getElementById('seo_llms_cbx').classList.contains('on')?'1':'0',
        crawl_clean:document.getElementById('seo_crawlclean_cbx').classList.contains('on')?'1':'0',
        enable_merchant:document.getElementById('seo_merchant_cbx').classList.contains('on')?'1':'0',
        merchant_condition:sval('seo_merch_cond'), merchant_ship_country:sval('seo_merch_country'),
        merchant_ship_cost:sval('seo_merch_cost'), merchant_ship_free_over:sval('seo_merch_freeover'),
        merchant_return_days:sval('seo_merch_returndays')
      };
      try{ await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})}); Object.assign(SETTINGS,payload); toast('SEO settings saved'); }
      catch(e){ toast('Save failed \u2014 check connection'); }
    };
  }

  /**
   * Redirects created automatically (on a published product/post's slug
   * changing) sit in the same list as ones an admin added by hand — same
   * table, same effect on a visitor's request either way — marked with an
   * "auto" badge only so it is clear where each one came from, not
   * separated into two different screens for what is the same feature.
   */
  async function renderSeoRedirects(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading\u2026</p>';
    var data;
    try{
      var res=await fetch(redirectsApiBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      data=await res.json();
    }catch(e){ body.innerHTML='<p style="padding:24px;color:var(--sale)">Could not load \u2014 '+sesc(e.message)+'</p>'; return; }

    var reds=data.redirects||[], nf=data.not_found||[];

    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Add a redirect</b>'+
      '<p class="description" style="margin:4px 0 0">Best for pages that are actually gone \u2014 a removed product, an old WordPress URL that no longer exists. Redirecting a page that still works and still loads normally is not supported yet.</p>'+
      '<div class="g2" style="margin-top:12px"><div class="fld"><label>From (path on this site)</label><input id="rd_source" placeholder="/old-page/"></div>'+
      '<div class="fld"><label>To (path or full URL)</label><input id="rd_target" placeholder="/new-page/ or https://\u2026"></div></div>'+
      '<div class="row" style="gap:10px;align-items:flex-end"><div class="fld" style="max-width:160px;margin:0"><label>Type</label>'+seoSel('rd_code','301',[['301','301 (permanent)'],['302','302 (temporary)']],'301')+'</div>'+
      '<button class="btn" id="rd_add" style="margin-top:9px">Add redirect</button></div></div>'+

      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Redirects</b> <span style="font-size:11.5px;color:var(--ink-soft)">'+reds.length+'</span>'+
      '<div style="margin-top:12px" id="rd_list">'+(reds.length?reds.map(redirectRow).join(''):'<p style="color:var(--ink-soft);font-size:12.5px">No redirects yet.</p>')+'</div></div>'+

      '<div class="card pad"><b style="font-size:13px">Recent 404s</b> <span style="font-size:11.5px;color:var(--ink-soft)">'+nf.length+' \u2014 broken links people have actually hit, most-hit first</span>'+
      '<div style="margin-top:12px" id="nf_list">'+(nf.length?nf.map(notFoundRow).join(''):'<p style="color:var(--ink-soft);font-size:12.5px">No broken links logged.</p>')+'</div></div>';

    document.getElementById('rd_add').onclick=async function(){
      var source=sval('rd_source').trim(), target=sval('rd_target').trim(), code=sval('rd_code');
      if(!source||!target){ toast('Enter both a from and to path'); return; }
      try{
        var res=await fetch(redirectsApiBase(),{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},
          body:JSON.stringify({source:source,target:target,code:+code})});
        var j=await res.json();
        if(j.ok){ toast('Redirect added'); renderSeoRedirects(); }
        else{ toast(j.message||'Could not add that redirect.'); }
      }catch(e){ toast('Could not save \u2014 check your connection.'); }
    };
    wireRedirectRows();
    wireNotFoundRows();
  }

  function redirectRow(r){
    return '<div class="row" style="gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)">'+
      '<div style="flex:1;min-width:0"><div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(r.source)+'</div>'+
      '<div style="font-size:11px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">\u2192 '+sesc(r.target)+'</div></div>'+
      '<span class="pill '+sesc((r.code===301?'green':'amber'))+'" style="flex:0 0 auto">'+sesc(r.code)+'</span>'+
      (r.auto_created?'<span class="pill" style="flex:0 0 auto;background:var(--surface-2)">auto</span>':'')+
      '<span style="flex:0 0 60px;font-size:11px;color:var(--ink-soft);text-align:right">'+r.hits+' hit'+(r.hits===1?'':'s')+'</span>'+
      '<span class="cbx'+(r.enabled?' on':'')+'" data-rdtoggle="'+r.id+'" style="flex:0 0 auto" title="Enabled">'+ic(I.check)+'</span>'+
      '<button class="btn ghost sm" data-rddel="'+r.id+'" style="flex:0 0 auto">Delete</button></div>';
  }

  function notFoundRow(n){
    return '<div class="row" style="gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)" id="nf_row_'+n.id+'">'+
      '<div style="flex:1;min-width:0"><div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(n.path)+'</div>'+
      (n.referer?'<div style="font-size:10.5px;color:var(--ink-soft);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">from '+sesc(n.referer)+'</div>':'')+'</div>'+
      '<span style="flex:0 0 60px;font-size:11px;color:var(--ink-soft);text-align:right">'+n.hits+' hit'+(n.hits===1?'':'s')+'</span>'+
      '<input id="nf_target_'+n.id+'" class="inp" style="flex:0 0 160px;display:none;font-size:12px" placeholder="/redirect-to/">'+
      '<button class="btn ghost sm" data-nfresolve="'+n.id+'" style="flex:0 0 auto">Resolve</button>'+
      '<button class="btn ghost sm" data-nfdismiss="'+n.id+'" style="flex:0 0 auto">Dismiss</button></div>';
  }

  function wireRedirectRows(){
    $$('#rd_list [data-rdtoggle]').forEach(function(el){ el.onclick=async function(){
      try{
        var res=await fetch(redirectsApiBase()+'/'+el.dataset.rdtoggle+'/toggle',{method:'POST',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        var j=await res.json();
        if(j.ok){ el.classList.toggle('on', j.enabled); }
      }catch(e){ toast('Could not update \u2014 check your connection.'); }
    };});
    $$('#rd_list [data-rddel]').forEach(function(b){ b.onclick=async function(){
      if(!confirm('Delete this redirect?')) return;
      try{
        await fetch(redirectsApiBase()+'/'+b.dataset.rddel,{method:'DELETE',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        toast('Redirect deleted'); renderSeoRedirects();
      }catch(e){ toast('Could not delete \u2014 check your connection.'); }
    };});
  }

  function wireNotFoundRows(){
    $$('#nf_list [data-nfresolve]').forEach(function(b){ b.onclick=async function(){
      var id=b.dataset.nfresolve, input=document.getElementById('nf_target_'+id);
      if(input.style.display==='none'){ input.style.display='inline-block'; input.focus(); return; }
      var target=input.value.trim();
      if(!target){ toast('Enter where this should redirect to'); return; }
      try{
        var res=await fetch(redirectsApiBase()+'/not-found/'+id+'/resolve',{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-XSRF-TOKEN':uToken(),Accept:'application/json'},body:JSON.stringify({target:target,code:301})});
        var j=await res.json();
        if(j.ok){ toast('Redirect created'); renderSeoRedirects(); }
        else{ toast(j.message||'Could not resolve that.'); }
      }catch(e){ toast('Could not save \u2014 check your connection.'); }
    };});
    $$('#nf_list [data-nfdismiss]').forEach(function(b){ b.onclick=async function(){
      try{
        await fetch(redirectsApiBase()+'/not-found/'+b.dataset.nfdismiss,{method:'DELETE',credentials:'same-origin',headers:{'X-XSRF-TOKEN':uToken(),Accept:'application/json'}});
        renderSeoRedirects();
      }catch(e){ toast('Could not dismiss \u2014 check your connection.'); }
    };});
  }

  /**
   * Shows an admin the real JSON-LD a page would actually output — builds
   * the exact same context the real storefront controllers do (see
   * SchemaInspectorApiController), so nothing shown here can drift from
   * what a real page actually ships.
   */
  function renderSchemaInspector(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">Inspect a page\u2019s structured data</b>'+
      '<p class="description" style="margin:4px 0 12px">Shows the exact JSON-LD a page would send to search engines right now \u2014 the same renderer every real page uses, not a preview.</p>'+
      '<div class="row" style="gap:10px;align-items:flex-end"><div class="fld" style="max-width:160px;margin:0"><label>Page type</label>'+seoSel('si_type','product',[['product','Product'],['category','Category'],['shop','Shop (all)'],['home','Homepage']],'product')+'</div>'+
      '<div class="fld" id="si_slug_wrap" style="margin:0;flex:1"><label>Slug</label><input id="si_slug" placeholder="e.g. relief-sun-rice-probiotics-spf50"></div>'+
      '<button class="btn" id="si_go" style="margin-bottom:0">Inspect</button></div></div>'+
      '<div id="si_result"></div>';

    var typeSel=document.getElementById('si_type');
    var slugWrap=document.getElementById('si_slug_wrap');
    function syncSlugVisibility(){ slugWrap.style.display=(typeSel.value==='shop'||typeSel.value==='home')?'none':''; }
    typeSel.onchange=syncSlugVisibility;
    syncSlugVisibility();

    document.getElementById('si_go').onclick=async function(){
      var type=typeSel.value, slug=sval('si_slug').trim();
      var result=document.getElementById('si_result');
      result.innerHTML='<p style="padding:16px;color:var(--ink-soft)">Checking\u2026</p>';
      try{
        var q=new URLSearchParams({type:type, slug:slug});
        var res=await fetch(schemaInspectApiBase()+'?'+q,{credentials:'same-origin',headers:{Accept:'application/json'}});
        var j=await res.json();
        if(!j.ok){ result.innerHTML='<div class="card pad"><p style="color:var(--sale);margin:0">'+sesc(j.message||'Could not inspect that page.')+'</p></div>'; return; }
        var warningsHtml=j.warnings&&j.warnings.length
          ? '<div class="card pad" style="margin-bottom:16px;border-color:var(--amber)"><b style="font-size:13px">Worth a look</b><ul style="margin:8px 0 0;padding-left:20px">'+j.warnings.map(function(w){return '<li style="font-size:12.5px;margin-bottom:4px">'+sesc(w)+'</li>';}).join('')+'</ul></div>'
          : '<div class="card pad" style="margin-bottom:16px"><p style="margin:0;font-size:12.5px;color:var(--ink-soft)">No issues found.</p></div>';
        var nodesHtml=j.nodes.map(function(n){
          return '<div class="card pad" style="margin-bottom:12px"><b style="font-size:12.5px">'+sesc(n['@type']||'?')+'</b>'+
            '<pre style="margin:8px 0 0;font-size:11px;background:var(--surface-2);padding:10px;border-radius:8px;overflow:auto;white-space:pre-wrap">'+sesc(JSON.stringify(n,null,2))+'</pre></div>';
        }).join('');
        result.innerHTML=warningsHtml+nodesHtml;
      }catch(e){ result.innerHTML='<div class="card pad"><p style="color:var(--sale);margin:0">Could not check \u2014 check your connection.</p></div>'; }
    };
  }

  /**
   * Reports, doesn't fix — scans every visible product for the handful of
   * gaps that actually matter (missing meta description, missing image, a
   * short description too thin to build a real fallback from) and shows
   * counts plus the worst offenders. Reads the same `seo` column
   * ProductController now actually renders from, so a product this
   * reports as fixed genuinely is.
   */
  async function renderCatalogueAudit(){
    var body=document.getElementById('seoTabBody');
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Scanning catalogue\u2026</p>';
    var data;
    try{
      var res=await fetch(catalogueAuditApiBase(),{credentials:'same-origin',headers:{Accept:'application/json'}});
      data=await res.json();
    }catch(e){ body.innerHTML='<p style="padding:24px;color:var(--sale)">Could not scan \u2014 '+sesc(e.message)+'</p>'; return; }

    function issueCard(title, desc, key){
      var list=data.issues[key]||[], count=data.counts[key]||0;
      var rows=list.map(function(p){
        return '<div class="row" style="gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12.5px">'+
          '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+sesc(p.name)+'</span>'+
          (p.words!==undefined?'<span style="color:var(--ink-soft)">'+p.words+' words</span>':'')+'</div>';
      }).join('');
      var more=count>list.length?'<p class="description" style="margin:8px 0 0">+ '+(count-list.length)+' more, not shown.</p>':'';
      return '<div class="card pad" style="margin-bottom:16px"><div class="between"><b style="font-size:13px">'+title+'</b><span class="pill '+(count===0?'green':'amber')+'">'+count+'</span></div>'+
        '<p class="description" style="margin:4px 0 12px">'+desc+'</p>'+
        (list.length?rows:'<p style="font-size:12.5px;color:var(--ink-soft)">None \u2014 every visible product has one.</p>')+more+'</div>';
    }

    body.innerHTML =
      '<div class="card pad" style="margin-bottom:16px"><b style="font-size:13px">'+data.total+' visible products scanned</b></div>'+
      issueCard('Missing meta description', 'No per-product SEO description set, and the short description is too thin (under 15 words) to build a real fallback from.', 'no_description')+
      issueCard('Missing image', 'No image at all \u2014 affects search results, social shares, and Product schema.', 'no_image')+
      issueCard('Short description too thin', 'Under 15 words \u2014 not necessarily wrong, but too little for a real fallback SEO description or a useful product page.', 'thin_short_description');
  }

  /* ---------- Catalog → Brands (real CRUD, replacing the preview grid) ----------
     The tab above renders a hard-coded CAT_BRANDS array with "(preview)"
     buttons. This replaces the whole tab: the brand list comes from
     /admin-api/brands, Add/Edit open a real form, and the logo field posts
     through the same /admin-api/media/upload every other image field uses.
     The display-mode select at the top writes `brands_display` through
     /admin-api/settings, which is what the storefront directory reads. */
  var BRANDS=[];
  var BRAND_DISPLAY_OPTS=[
    ['auto','Logo when the brand has one, name otherwise (default)'],
    ['logos','Logos only'],
    ['names','Names only']
  ];

  async function brandWrite(path, method, body){
    var r = await fetch(fixAdminApiUrl('/admin-api/brands'+(path||'')), {
      method: method,
      credentials: 'same-origin',
      headers: {'Accept':'application/json','Content-Type':'application/json','X-XSRF-TOKEN':cookie('XSRF-TOKEN')},
      body: body ? JSON.stringify(body) : undefined
    });
    var j={}; try{ j=await r.json(); }catch(e){}
    if(!r.ok){
      // 422 carries either Laravel's `errors` bag (duplicate slug, bad logo
      // URL) or this controller's own `message` (brand still in use). Both
      // are meant for the operator, so both are shown rather than swallowed
      // into a generic "save failed".
      var msg = j.message || '';
      if(j.errors){ msg = Object.keys(j.errors).map(function(k){ return j.errors[k][0]; }).join(' '); }
      var err = new Error(msg || ('Request failed ('+r.status+')'));
      err.payload = j; err.status = r.status;
      throw err;
    }
    return j;
  }

  window.catBrands = async function(){
    var body=document.getElementById('catBody');
    if(!body) return;
    body.innerHTML='<p style="padding:24px;color:var(--ink-soft)">Loading brands…</p>';
    try{
      var d=await brandWrite('','GET',null); BRANDS=d.brands||[];
    }catch(e){ BRANDS=[]; }
    await loadSettings();
    brandPaint();
  };

  function brandPaint(){
    var body=document.getElementById('catBody');
    if(!body) return;
    var mode=SETTINGS.brands_display||'auto';
    body.innerHTML=
      '<div class="card pad" style="margin-bottom:14px"><b style="font-size:13px">Directory display</b>'+
      '<p style="font-size:11.5px;color:var(--ink-soft);margin:4px 0 12px">How each tile is drawn on the storefront brands page (/korean-skincare-brands/). Brands with no logo always fall back to their name, so “Logos only” can never leave an empty tile.</p>'+
      '<div class="fld" style="max-width:420px;margin:0"><label>Show</label>'+
      '<select class="inp" id="brd_display" style="width:100%">'+BRAND_DISPLAY_OPTS.map(function(o){
        return '<option value="'+o[0]+'"'+(o[0]===mode?' selected':'')+'>'+o[1]+'</option>';
      }).join('')+'</select></div>'+
      '<div class="row" style="justify-content:flex-end;margin-top:12px"><button class="btn" id="brd_display_save">Save display</button></div></div>'+
      '<div class="between" style="margin-bottom:12px"><span class="pill grey">'+BRANDS.length+' brand'+(BRANDS.length===1?'':'s')+'</span>'+
      '<button class="btn sm" id="brd_add">'+ic('<path d="M12 5v14M5 12h14"/>')+' Add brand</button></div>'+
      (BRANDS.length?
        '<div class="mod-grid">'+BRANDS.map(function(b){
          var thumb = b.logo
            ? '<span class="pthumb" style="width:40px;height:40px;background:var(--bg);overflow:hidden"><img src="'+sesc(b.logo)+'" alt="'+sesc(b.name)+'" style="width:100%;height:100%;object-fit:contain"></span>'
            : '<span class="pthumb" style="background:'+sesc(tcol(b.name))+';width:40px;height:40px">'+sesc(initials(b.name))+'</span>';
          return '<div class="mod">'+thumb+
            '<div><div class="mname">'+sesc(b.name)+'</div>'+
            '<div class="mdesc">'+b.products_count+' product'+(b.products_count===1?'':'s')+' · /'+sesc(b.slug)+(b.logo?'':' · no logo')+'</div></div>'+
            '<div class="mod-r"><button class="btn ghost sm" data-bedit="'+b.id+'">Edit</button>'+
            '<button class="btn ghost sm" data-bdel="'+b.id+'">Delete</button></div></div>';
        }).join('')+'</div>'
        : '<p style="padding:24px;color:var(--ink-soft)">No brands yet — add the first one.</p>');

    document.getElementById('brd_display_save').onclick=async function(){
      var payload={brands_display: sval('brd_display')};
      try{
        await api('/admin-api/settings',{method:'PUT',body:JSON.stringify({settings:payload})});
        Object.assign(SETTINGS,payload); toast('Brand display saved');
      }catch(e){ toast('Save failed — check connection'); }
    };
    document.getElementById('brd_add').onclick=function(){ brandEditor(null); };
    document.querySelectorAll('#catBody [data-bedit]').forEach(function(b){
      b.onclick=function(){ brandEditor(BRANDS.filter(function(x){return x.id===+b.dataset.bedit;})[0]); };
    });
    document.querySelectorAll('#catBody [data-bdel]').forEach(function(b){
      b.onclick=function(){ brandDelete(BRANDS.filter(function(x){return x.id===+b.dataset.bdel;})[0]); };
    });
  }

  function brandEditor(brand){
    var isNew=!brand; brand=brand||{name:'',slug:'',logo:'',description:'',position:0};
    openModal('<div class="modal-h"><b>'+(isNew?'Add brand':'Edit brand')+'</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+
      '<div class="fld"><label>Name</label><input id="brd_name" value="'+sesc(brand.name)+'"></div>'+
      '<div class="fld"><label>Slug</label><input id="brd_slug" value="'+sesc(brand.slug)+'" placeholder="left blank, made from the name">'+
      '<p class="description" style="margin:6px 0 0;font-size:11.5px;color:var(--ink-soft)">Used in /korean-skincare-brands/{slug}/ and the shop filter. Lower case, hyphens.</p></div>'+
      imgUploadField('brd_logo', brand.logo||'', 'Logo', 'brands')+
      '<div class="fld"><label>Description</label><textarea id="brd_desc" class="inp" rows="3">'+sesc(brand.description)+'</textarea></div>'+
      '<div class="fld" style="max-width:160px"><label>Position</label><input id="brd_pos" type="number" min="0" value="'+sesc(brand.position||0)+'"></div>'+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" id="brd_save">'+(isNew?'Create brand':'Save brand')+'</button></div></div>');
    wireImgUpload('brd_logo','brands');
    document.getElementById('brd_save').onclick=async function(){
      var payload={
        name: sval('brd_name'), slug: sval('brd_slug'), logo: sval('brd_logo'),
        description: sval('brd_desc'), position: parseInt(sval('brd_pos'),10)||0
      };
      if(!payload.name){ toast('A brand needs a name'); return; }
      try{
        await (isNew ? brandWrite('','POST',payload) : brandWrite('/'+brand.id,'PUT',payload));
        toast(isNew?'Brand created':'Brand saved'); closeModal(); window.catBrands();
      }catch(e){ toast(e.message); }
    };
  }

  async function brandDelete(brand){
    if(!brand) return;
    var warn = brand.products_count>0
      ? '<p style="font-size:13px;color:var(--ink-2)"><b>'+brand.products_count+'</b> product'+(brand.products_count===1?'':'s')+' still belong'+(brand.products_count===1?'s':'')+' to <b>'+sesc(brand.name)+'</b>. Deleting the brand leaves them with no brand — the products themselves are kept.</p>'
      : '<p style="font-size:13px;color:var(--ink-2)">Delete <b>'+sesc(brand.name)+'</b>? This cannot be undone.</p>';
    openModal('<div class="modal-h"><b>Delete brand</b><button class="x" onclick="closeModal()">✕</button></div>'+
      '<div class="modal-b">'+warn+
      '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button class="btn ghost" onclick="closeModal()">Cancel</button>'+
      '<button class="btn" style="background:var(--danger,#d6455a)" id="brd_del_yes">Delete</button></div></div>');
    document.getElementById('brd_del_yes').onclick=async function(){
      try{
        // force is what the operator just confirmed: without it the API
        // refuses to unbrand products behind their back.
        await brandWrite('/'+brand.id+'?force=1','DELETE',null);
        toast('Brand deleted'); closeModal(); window.catBrands();
      }catch(e){ toast(e.message); }
    };
  }

  /* ---------- Route interception: hydrate dash, render new screens ---------- */
  var _go = window.go;
  window.go = function(id){
    if(id==='orders'){ _go(id); return renderOrders(); }
    if(id==='customers'){ _go(id); return renderCustomers(); }
    if(id==='quiz-leads'){ _go(id); return renderQuizLeads(); }
    if(id==='rev-all'){ _go(id); return renderReviews(); }
    if(id==='store-settings'){ _go(id); return renderStoreSettings(); }
    if(id==='seo'){ _go(id); return renderSeo(); }
    if(id==='analytics'){ _go(id); return renderAnalytics(); }
    if(id==='blog'||id==='posts'){ _go(id); return renderPosts(); }
    _go(id);
    if(id==='dash') hydrateDash();
  };

  /* ---------- Sign out (top bar) ---------- */
  function addLogout(){
    var top=document.querySelector('.top');
    if(top && !document.getElementById('kbbSignout')){
      var b=document.createElement('button');
      b.id='kbbSignout'; b.className='iconbtn'; b.title='Sign out';
      b.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>';
      b.onclick=async function(){
        try{ await fetch('/admin/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}
        location.href='/admin/login';
      };
      var chip=top.querySelector('.userchip');
      if(chip) top.insertBefore(b, chip); else top.appendChild(b);
    }
  }

  /* ---------- CSP fallback ----------
     Some sandboxed previews (e.g. Claude's) block inline on* handlers via CSP,
     which stops the catalog's inline onclick="openProduct(n)" from firing. We
     feature-detect that and, only when inline handlers are blocked, delegate the
     key inline actions. In a normal deployment inline handlers work, so this
     never installs and nothing runs twice. */
  (function(){
    var inlineWorks=false;
    try{
      var t=document.createElement('button'); t.setAttribute('onclick','window.__kbbInline=1');
      (document.body||document.documentElement).appendChild(t); t.click();
      inlineWorks=(window.__kbbInline===1); if(t.parentNode) t.parentNode.removeChild(t); try{delete window.__kbbInline;}catch(e){}
    }catch(e){}
    if(inlineWorks) return;
    document.addEventListener('click', function(e){
      var el = e.target && e.target.closest ? e.target.closest('[onclick]') : null; if(!el) return;
      var code = el.getAttribute('onclick')||''; var m;
      if((m=code.match(/openProduct\((-?\d+)\)/)))      { e.preventDefault(); try{ window.openProduct(parseInt(m[1],10)); }catch(x){} return; }
      if((m=code.match(/go\('([^']+)'\)/)))             { e.preventDefault(); try{ window.go(m[1]); }catch(x){} return; }
      if(/closeModal\(\)/.test(code))                   { e.preventDefault(); try{ window.closeModal(); }catch(x){} return; }
      if(/this\.parentElement\.classList\.toggle/.test(code)) { try{ el.parentElement.classList.toggle('col'); }catch(x){} return; }
      if((m=code.match(/toast\('([^']*)'\)/)))          { try{ window.toast(m[1]); }catch(x){} return; }
      try{ (new Function('event', code)).call(el, e); }catch(x){}   // best-effort; may be blocked without 'unsafe-eval'
    }, false);
  })();

  /* ---------- boot ---------- */
  loadCatalog();
  if(cur==='dash') hydrateDash();
  addLogout();
})();
</script>

</body>
</html>

@endverbatim
