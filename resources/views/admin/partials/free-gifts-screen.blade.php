{{--
    Growth & Marketing → Free gifts.                          Lane FG, redesigned by Lane FG2

    Spend bands and the gifts each gives: the cart panel, the cart page and the
    checkout summary show them in the owner's "02 · Mint outline" box, and the
    order stores them at AED 0. Five tabs:

      Overview          the Mode (never refused: Lane FG3), "Things to check"
                        (each item opens the place that fixes it), four numbers
                        from the last 30 days (GET free-gifts/stats), every
                        range at a glance.
      Ranges & gifts    a row of range tabs (Lane FG3); only the selected
                        range's editor shows: "AED [from] – [to]", wording
                        EN | AR, dates, the gift list (photo, stock, −/+
                        stepper, remove with undo), the picker, a live mini
                        preview of the shop's box, and its OWN Save.
      Gift products     every gift and gift-only product: stock, ranges using
                        it, the Gift-only switch, and "New gift-only product".
      Display           what counts, countries, gift prices, the texts EN | AR
                        beside a live preview of the box.
      Homepage section  words, background (style, three colours, strength) and the on/off switch.

    EVERY EDIT IS A DRAFT until its own Save (Lane FG3: no global save bar).
    Each range keeps its draft while the owner moves between range tabs, and
    its tab carries a dot until it is saved; its Save sends that range alone
    and the toast names it. Display and Homepage section each have their own
    Save too. The Mode, Gift only, delete and create act at once (each is one
    deliberate click with its own toast). The selected range tab is
    remembered in localStorage (wrapped in try/catch; a blocked store just
    opens the first range).

    Pulled into resources/views/admin/app.blade.php beside Push Notifications,
    so window.go and toast() exist. It wraps window.go for one id, 'freegifts'.

    LIGHT: GET free-gifts and GET free-gifts/stats when the screen opens. The
    picker asks once when it opens, then searches AS YOU TYPE -- at most once
    per 300 ms of typing, only for 2+ characters, never on Enter, and every
    new question aborts the one before it (AbortController), so a slower
    earlier answer can never overwrite a newer one. A filter is one request
    per click. Brands are asked
    for only when the create form opens. The previews and the overlap/gap check
    are built here from data the page already has: no request. No polling, no
    timer left running (the one debounce timer is cleared when the picker
    closes and when the console leaves this screen).

    GIFT TYPES AND CRITERIA (Lane FG4, the owner 22 Oct). Internal only: the
    shop and the orders never see them. Each range has three steppers --
    Face masks / Mini products / Full size -- and its gifts sit in a box per
    type (plus Untagged, only when there is one), each box counting its UNITS
    against its criterion ("2 of 3"). A gift picked from a box goes into the
    product's remembered type, else that box, else the picker asks. The
    moment counts and criteria differ an amber notice says so per type, the
    range tab carries the warning mark and Overview lists it -- a WARNING
    ONLY: Save, Testing and Live are never blocked; the steppers are the fix.
    "Use for wording" fills EN | AR from the criteria on a click, never by
    itself. tally()/criteriaGaps()/wordingFor() here mirror FreeGifts::
    tally()/criteriaGaps()/criteriaWording(), which FreeGiftsKindsTest runs
    against this file in node.

    SAFE: a late answer never paints over another screen -- every repaint goes
    through render()/paint*(), which check ours() first, the same guard as
    marketing-emails-screens.blade.php. Every value from the server is set with
    textContent or as an attribute; toast() writes innerHTML, so say() escapes.
--}}
@verbatim
<style>
.fg-wrap{display:grid;gap:14px;min-width:0;grid-template-columns:minmax(0,1fr);max-width:1180px;padding-bottom:8px}
.fg-wrap>*{min-width:0}
.fg-wrap [hidden]{display:none!important}
.fg-tabs{display:flex;gap:2px;overflow-x:auto;border-bottom:1px solid var(--border);scrollbar-width:none}
.fg-tab{font:inherit;font-size:13.5px;padding:10px 12px;border:0;background:none;color:var(--ink-soft);border-bottom:2px solid transparent;cursor:pointer;margin-bottom:-1px;white-space:nowrap;display:inline-flex;gap:6px;align-items:center}
.fg-tab[aria-selected="true"]{color:var(--ink);font-weight:650;border-bottom-color:var(--accent)}
.fg-tab:focus-visible,.fg-btn-i:focus-visible,.fg-rtab:focus-visible,.fg-res:focus-visible,.fg-sw:focus-visible,.fg-step button:focus-visible,.fg-link:focus-visible{outline:2px solid var(--ink);outline-offset:2px;border-radius:8px}
.fg-tab .fg-dot,.fg-rtab .fg-rt-dirty{width:8px;height:8px;border-radius:50%;background:#2f6fde;flex:none}
.fg-card{padding:18px;min-width:0}
.fg-h{font-weight:700;font-size:15px;margin:0;letter-spacing:-.01em}
.fg-h2{font-weight:650;font-size:13px;margin:0 0 8px;color:var(--ink-2)}
.fg-sub{color:var(--ink-soft);font-size:12.5px;line-height:1.55;margin:4px 0 0;max-width:80ch}
.fg-pk-tip{display:flex;align-items:center;justify-content:space-between;gap:8px 12px;flex-wrap:wrap}
.fg-usedb{border:1.5px solid #2e8f62;background:#fff;color:#1f6e4a;border-radius:999px;padding:6px 14px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
.fg-usedb:hover{background:#f1f9f4}
.fg-usedb.on{background:#2e8f62;color:#fff}
.fg-usedb:focus-visible{outline:2px solid #2e8f62;outline-offset:2px}
.fg-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;min-width:0}
.fg-between{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;min-width:0}
.fg-g2{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))}
.fg-field{display:grid;gap:5px;min-width:0;align-content:start}
.fg-field>span{font-size:12px;font-weight:600;color:var(--ink-2)}
.fg-field small{font-size:11.5px;color:var(--ink-faint);line-height:1.45}
.fg-in{font:inherit;font-size:13px;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--ink);min-width:0;width:100%;box-sizing:border-box}
.fg-in:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.fg-in[disabled]{opacity:.6}
.fg-in.bad{border-color:#d9534a}
select.fg-in{padding-inline-end:28px}
.fg-amt{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
.fg-amt .fg-in{width:104px}
.fg-amt b{font-size:13px;color:var(--ink-soft);font-weight:600}
.fg-chk{display:inline-flex;align-items:center;gap:7px;font-size:13px;cursor:pointer}
.fg-chk input{width:16px;height:16px;accent-color:var(--accent);margin:0}
.fg-sw{display:inline-flex;align-items:center;gap:10px;cursor:pointer;font:inherit;font-size:13px;font-weight:600;background:none;border:0;padding:0;color:inherit;text-align:start}
.fg-sw i{position:relative;width:40px;height:22px;border-radius:999px;background:#cfd7e2;flex:none;transition:background .15s}
.fg-sw i::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
.fg-sw[aria-checked="true"] i{background:var(--accent)}
.fg-sw[aria-checked="true"] i::after{transform:translateX(18px)}
.fg-sw[disabled]{opacity:.5;cursor:not-allowed}
.fg-sw.big{font-size:14.5px}
.fg-sw.big i{width:48px;height:27px}
.fg-sw.big i::after{width:21px;height:21px}
.fg-sw.big[aria-checked="true"] i::after{transform:translateX(21px)}
.fg-note{border:1px solid #f0c6c2;background:var(--red-soft);color:#b8362d;border-radius:11px;padding:10px 12px;font-size:12.5px;line-height:1.5}
.fg-btn-i{display:inline-grid;place-items:center;width:32px;height:32px;border-radius:9px;border:1px solid transparent;background:none;color:var(--ink-soft);cursor:pointer;flex:none;padding:0}
.fg-btn-i:hover{background:var(--surface-2);color:var(--ink)}
.fg-btn-i svg{width:16px;height:16px}
.fg-btn-i.danger:hover{background:var(--red-soft);color:#b8362d}
.fg-link{background:none;border:0;padding:0;font:inherit;font-size:12.5px;font-weight:650;color:var(--accent-ink);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.fg-ph{display:grid;place-items:center;background:var(--surface-3);color:var(--ink-faint);font-size:10px;font-weight:800;letter-spacing:.02em}
.fg-th{width:40px;height:40px;border-radius:9px;border:1px solid var(--border);object-fit:contain;background:#fff;flex:none;display:block;box-sizing:border-box}
.fg-th.sm{width:30px;height:30px;border-radius:7px}
.fg-th.fg-ph{display:grid}
.fg-thumbs{display:flex;gap:5px;flex-wrap:wrap;align-items:center;min-width:0}
.fg-tw{position:relative;flex:none}
.fg-tw b{position:absolute;top:-5px;inset-inline-end:-6px;background:#2e8f62;color:#fff;font-size:9.5px;font-weight:800;border-radius:999px;min-width:17px;height:17px;padding:0 4px;box-sizing:border-box;display:grid;place-items:center;line-height:1}
.fg-more{font-size:11.5px;color:var(--ink-soft);font-weight:600}
.fg-pills{display:flex;gap:5px;flex-wrap:wrap}
/* Overview */
.fg-status{display:grid;gap:16px;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr)}
.fg-state{display:grid;gap:10px;align-content:start}
.fg-state p{margin:0;font-size:13px;color:var(--ink-soft);line-height:1.55}
.fg-checks{list-style:none;margin:0;padding:0;display:grid;gap:7px}
.fg-checks li{display:flex;gap:9px;align-items:flex-start;font-size:13px;line-height:1.45;padding:9px 11px;border:1px solid var(--border);border-radius:11px;background:var(--surface)}
.fg-checks li>span:nth-child(2){flex:1;min-width:0;overflow-wrap:anywhere}
.fg-checks li.lv-alert{border-color:#f0c6c2;background:var(--red-soft)}
.fg-checks li.lv-warn{border-color:#f0d199;background:var(--amber-soft)}
.fg-kpis{display:grid;gap:12px;grid-template-columns:repeat(4,minmax(0,1fr))}
.fg-kpis .kpi .val{overflow-wrap:anywhere}
.fg-kpis .kpi .val.txt{font-size:15px;line-height:1.3;letter-spacing:0}
.fg-kpis ul{margin:6px 0 0;padding:0;list-style:none;font-size:11.5px;color:var(--ink-soft);display:grid;gap:2px}
.fg-sum{display:grid;gap:8px}
.fg-sumr{display:grid;grid-template-columns:150px minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface);cursor:pointer;font:inherit;color:inherit;text-align:start;width:100%}
.fg-sumr:hover{border-color:#d2d8e6;background:var(--surface-2)}
.fg-amtl{font-weight:750;font-size:13.5px;white-space:nowrap}
.fg-words{font-size:12.5px;color:var(--ink-2);overflow-wrap:anywhere}
/* Mode and the Testing list */
.fg-modes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.fg-mode{display:grid;gap:2px;text-align:start;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--ink);font:inherit;cursor:pointer}
.fg-mode b{font-size:14px}
.fg-mode small{font-size:11.5px;color:var(--ink-soft);line-height:1.35}
.fg-mode[aria-checked="true"]{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 2px var(--accent-soft)}
.fg-mode.m-testing[aria-checked="true"]{border-color:#e0a23a;background:var(--amber-soft);box-shadow:0 0 0 2px var(--amber-soft)}
.fg-mode.m-off[aria-checked="true"]{border-color:var(--ink-faint);background:var(--surface-2);box-shadow:none}
.fg-mode[disabled]{opacity:.5;cursor:not-allowed}
.fg-mode:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
.fg-banner{padding:11px 14px;border-radius:12px;background:var(--amber-soft);border:1px solid #f0d199;color:#7a4d06;font-size:13px}
.fg-iplist{list-style:none;margin:0;padding:0;display:grid;gap:6px}
.fg-iplist li{display:flex;gap:8px;align-items:center;padding:5px 6px 5px 11px;border:1px solid var(--border);border-radius:10px;background:var(--surface);min-width:0}
.fg-iplist code{font-size:12.5px;font-weight:650;overflow-wrap:anywhere}
.fg-iplab{flex:1;min-width:0;font-size:12.5px;color:var(--ink-soft);overflow-wrap:anywhere}
.fg-ipadd{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr) auto;gap:8px;margin-top:8px}
@media(max-width:700px){.fg-modes{grid-template-columns:minmax(0,1fr)}.fg-ipadd{grid-template-columns:minmax(0,1fr)}}
/* Ranges */
.fg-rgs{display:grid;gap:8px}
.fg-rg{border:1px solid var(--border);border-radius:13px;background:var(--surface);min-width:0}
.fg-rg.open{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.fg-rg-top{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;padding:11px 16px;border-bottom:1px solid var(--border)}
.fg-rg-top .fg-words{flex:1;min-width:0}
/* Range tabs (Lane FG3): one per range, scrolling sideways on a phone. */
.fg-rtabs{display:flex;gap:6px;overflow-x:auto;padding:3px 3px 8px;margin:12px -3px 0;scrollbar-width:thin;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain}
.fg-rtab{flex:none;display:inline-flex;align-items:center;gap:7px;font:inherit;font-size:13px;font-weight:650;padding:8px 13px;border:1px solid var(--border);border-radius:999px;background:var(--surface);color:var(--ink);cursor:pointer;white-space:nowrap}
.fg-rtab:hover{background:var(--surface-2)}
.fg-rtab[aria-selected="true"]{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 2px var(--accent-soft)}
.fg-rtab.off{color:var(--ink-faint);border-style:dashed;font-weight:550}
.fg-rt-off{font-size:10px;font-weight:750;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-faint)}
.fg-rt-warn{display:inline-grid;place-items:center;width:17px;height:17px;border-radius:50%;background:#d9534a;color:#fff;font-size:11px;font-weight:800;line-height:1;flex:none}
.fg-rtab.add{border-style:dashed;color:var(--accent-ink);background:none}
.fg-rtab.add svg{width:14px;height:14px}
.fg-rg-b{padding:16px;display:grid;gap:16px;grid-template-columns:minmax(0,1fr) 360px;align-items:start}
.fg-ed{display:grid;gap:16px;min-width:0}
.fg-sec{display:grid;gap:8px;min-width:0}
.fg-msgs{display:grid;gap:5px}
.fg-msg{font-size:12.5px;line-height:1.45;display:flex;gap:7px;align-items:flex-start}
.fg-msg i{font-style:normal;font-weight:800;flex:none}
.fg-msg.err{color:#b8362d}.fg-msg.warn{color:#9a6512}.fg-msg.ok{color:var(--accent-ink)}
.fg-gl{display:grid;gap:6px}
.fg-gi{display:grid;grid-template-columns:40px minmax(0,1fr) auto auto;gap:10px;align-items:center;padding:7px 8px;border:1px solid var(--border);border-radius:11px;background:var(--surface)}
.fg-gi-n{min-width:0}
.fg-gi-n b{display:block;font-size:13px;font-weight:600;overflow-wrap:anywhere;line-height:1.3}
.fg-gi-n small{display:block;font-size:10.5px;color:var(--ink-faint);text-transform:uppercase;letter-spacing:.04em}
.fg-gi-n .pill{margin-top:3px}
.fg-gi.out{border-color:#f0c6c2;background:var(--red-soft)}
.fg-gi.low{border-color:#f0d199;background:var(--amber-soft)}
.fg-undo{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-size:12.5px;color:var(--ink-soft);padding:8px 11px;border:1px dashed var(--border);border-radius:11px}
.fg-step{display:inline-flex;align-items:center;border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--surface)}
.fg-step button{width:30px;height:32px;border:0;background:none;font:inherit;font-size:16px;line-height:1;color:var(--ink-2);cursor:pointer;padding:0}
.fg-step button:hover{background:var(--surface-2)}
.fg-step button[disabled]{opacity:.35;cursor:default}
.fg-step input{width:38px;height:32px;border:0;border-inline:1px solid var(--border);text-align:center;font:inherit;font-size:13px;font-weight:650;background:none;color:var(--ink);padding:0;-moz-appearance:textfield;appearance:textfield}
.fg-step input::-webkit-outer-spin-button,.fg-step input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.fg-empty{font-size:12.5px;color:var(--ink-soft);padding:14px;border:1px dashed var(--border);border-radius:11px;text-align:center}
.fg-confirm{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid #f0c6c2;background:var(--red-soft);border-radius:11px;font-size:13px;color:#8f2b23}
.fg-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;border-top:1px solid var(--border);padding-top:12px}
.fg-pvcol{display:grid;gap:10px;position:sticky;top:8px;min-width:0}
.fg-pvcol .seg{align-self:start;justify-self:start}
.fg-pvlab{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);margin:2px 0 -4px}
.fg-pvbg{background:#fbf7f8;border-radius:12px;padding:4px 12px 12px;border:1px solid var(--border)}
/* Gift types and criteria (Lane FG4): three calm, tinted boxes in one column. */
.fg-wrap{--fk-mask-bg:#fdf3f6;--fk-mask-bd:#f2d3dd;--fk-mask-ink:#a8395c;--fk-mini-bg:#f4f2fd;--fk-mini-bd:#dcd5f6;--fk-mini-ink:#5b46b8;--fk-full-bg:#fdf7ea;--fk-full-bd:#f0dcae;--fk-full-ink:#8f5a07;--fk-none-bg:var(--surface-2);--fk-none-bd:var(--border);--fk-none-ink:var(--ink-soft);--fk-ok:#1d7a4c;--fk-short:#9a6512;--fk-over:#b8362d}
:root[data-theme="midnight"] .fg-wrap{--fk-mask-bg:#2c1a22;--fk-mask-bd:#5a2f40;--fk-mask-ink:#f3a3bd;--fk-mini-bg:#1f1b38;--fk-mini-bd:#3f3672;--fk-mini-ink:#b9abf7;--fk-full-bg:#2c2312;--fk-full-bd:#5c4720;--fk-full-ink:#f0c47a;--fk-ok:#7fe3a8;--fk-short:#f0b86a;--fk-over:#f59289}
.fg-crit{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2)}
.fg-crit-t{font-size:12px;font-weight:700;color:var(--ink-2);flex-basis:100%;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline;justify-content:space-between}
.fg-crit-t small{font-weight:500;color:var(--ink-faint);font-size:11.5px}
.fg-crit-i{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:var(--ink-2)}
.fg-crit-i .fg-kd{width:9px;height:9px;border-radius:50%;flex:none}
.fg-crit-i .fg-step input{width:34px}
.fg-crit-sum{font-size:13px;font-weight:750;white-space:nowrap}
.fg-crit .btn{margin-inline-start:auto}
.fg-kd.k-mask{background:var(--fk-mask-ink)}.fg-kd.k-mini{background:var(--fk-mini-ink)}.fg-kd.k-full{background:var(--fk-full-ink)}.fg-kd.k-none{background:var(--ink-faint)}
.fg-boxes{display:grid;gap:10px}
.fg-box{border:1px solid var(--kbd);border-radius:13px;background:var(--kbg);padding:9px;display:grid;gap:6px;min-width:0}
.fg-box.k-mask{--kbg:var(--fk-mask-bg);--kbd:var(--fk-mask-bd);--kink:var(--fk-mask-ink)}
.fg-box.k-mini{--kbg:var(--fk-mini-bg);--kbd:var(--fk-mini-bd);--kink:var(--fk-mini-ink)}
.fg-box.k-full{--kbg:var(--fk-full-bg);--kbd:var(--fk-full-bd);--kink:var(--fk-full-ink)}
.fg-box.k-none{--kbg:var(--fk-none-bg);--kbd:var(--fk-none-bd);--kink:var(--fk-none-ink);border-style:dashed}
.fg-box-h{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;padding:1px 3px}
.fg-box-h h4{margin:0;font-size:13px;font-weight:700;color:var(--kink);display:inline-flex;align-items:center;gap:7px;flex:1;min-width:0}
.fg-box-h h4 svg{width:17px;height:17px;flex:none}
.fg-cnt{font-size:11.5px;font-weight:750;padding:2px 9px;border-radius:999px;background:var(--surface);border:1px solid var(--border);color:var(--ink-soft);white-space:nowrap}
.fg-cnt.ok{color:var(--fk-ok);border-color:currentColor}
.fg-cnt.short{color:var(--fk-short);border-color:currentColor}
.fg-cnt.over{color:var(--fk-over);border-color:currentColor}
.fg-box .fg-gi{background:var(--surface)}
.fg-box .fg-gi.out{background:var(--red-soft)}
.fg-box .fg-gi.low{background:var(--amber-soft)}
.fg-box-add{font:inherit;font-size:12.5px;font-weight:650;color:var(--kink);background:none;border:1px dashed var(--kbd);border-radius:10px;padding:7px 10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;width:100%}
.fg-box-add:hover{background:var(--surface)}
.fg-box-add svg{width:14px;height:14px}
.fg-box-add:focus-visible,.fg-kinds button:focus-visible,.fg-ask button:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
.fg-box-e{font-size:12px;color:var(--ink-soft);padding:2px 4px}
.fg-kinds{display:inline-flex;gap:3px;flex-wrap:wrap}
/* Main / Backup (23 October): the backups sit under the main gifts in each type box, dashed. */
.fg-bk{margin-top:10px;padding:10px;border:1px dashed var(--kbd);border-radius:12px;background:rgba(255,255,255,.55);display:grid;gap:8px}
.fg-bk-h{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;font-size:12.5px}
.fg-bk-h b{color:var(--kink)}
.fg-bk-h small{color:var(--ink-soft)}
.fg-bk .fg-gi{opacity:.92}
.fg-box-add.bk{border-style:dotted}
.fg-bk-q{font-size:12px;color:var(--ink-soft);white-space:nowrap}
.fg-roles{display:inline-flex;border:1px solid var(--border);border-radius:999px;padding:1px;background:var(--surface)}
.fg-roles button{font:inherit;font-size:11px;font-weight:650;padding:2px 9px;border:0;border-radius:999px;background:none;color:var(--ink-soft);cursor:pointer;line-height:1.5}
.fg-roles button[aria-checked="true"]{background:var(--ink);color:#fff}
.fg-roles button:focus-visible{outline:2px solid var(--ink);outline-offset:2px}
.fg-auto{border:1px solid var(--border);border-radius:12px;padding:10px 12px;background:var(--surface);display:grid;gap:6px}
.fg-auto-h{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap}
.fg-auto-h small{color:var(--ink-soft)}
.fg-auto-s{display:grid;gap:2px;font-size:15px;font-weight:650}
.fg-auto-n{color:var(--ink-soft)}
.fg-auto-n.short{color:#9a5b0a}
.fg-kinds button{font:inherit;font-size:11px;font-weight:650;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--ink-soft);cursor:pointer;line-height:1.5}
.fg-kinds button[aria-checked="true"].k-mask{background:var(--fk-mask-bg);border-color:var(--fk-mask-ink);color:var(--fk-mask-ink)}
.fg-kinds button[aria-checked="true"].k-mini{background:var(--fk-mini-bg);border-color:var(--fk-mini-ink);color:var(--fk-mini-ink)}
.fg-kinds button[aria-checked="true"].k-full{background:var(--fk-full-bg);border-color:var(--fk-full-ink);color:var(--fk-full-ink)}
.fg-kinds button[disabled]{cursor:default;opacity:.6}
.fg-gi-n .fg-kinds{margin-top:5px}
.fg-kp{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:1px 8px;border-radius:999px;border:1px solid var(--kbd);background:var(--kbg);color:var(--kink)}
.fg-kp.k-mask{--kbg:var(--fk-mask-bg);--kbd:var(--fk-mask-bd);--kink:var(--fk-mask-ink)}
.fg-kp.k-mini{--kbg:var(--fk-mini-bg);--kbd:var(--fk-mini-bd);--kink:var(--fk-mini-ink)}
.fg-kp.k-full{--kbg:var(--fk-full-bg);--kbd:var(--fk-full-bd);--kink:var(--fk-full-ink)}
.fg-cnote{border:1px solid #f0d199;background:var(--amber-soft);color:#7a4d06;border-radius:12px;padding:9px 12px;display:grid;gap:4px;font-size:12.5px;line-height:1.45}
:root[data-theme="midnight"] .fg-cnote{color:#f0c47a;border-color:#5c4720}
.fg-cnote b{font-size:12.5px}
.fg-cnote ul{margin:0;padding-inline-start:18px;display:grid;gap:2px}
.fg-tot{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center;font-size:12.5px;font-weight:650;padding:8px 12px;border-radius:11px;border:1px solid var(--border);background:var(--surface)}
.fg-tot.ok{border-color:var(--fk-ok);color:var(--fk-ok);background:var(--accent-soft)}
.fg-tot.short{border-color:#f0d199;color:var(--fk-short);background:var(--amber-soft)}
.fg-tot.none{color:var(--ink-soft)}
.fg-ask{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:7px 10px;margin-top:-2px;border:1px solid var(--accent);border-radius:11px;background:var(--accent-soft);font-size:12.5px;font-weight:600}
.fg-ask button{font:inherit;font-size:12px;font-weight:650;padding:4px 10px;border-radius:999px;border:1px solid var(--kbd);background:var(--kbg);color:var(--kink);cursor:pointer}
.fg-ask button.k-mask{--kbg:var(--fk-mask-bg);--kbd:var(--fk-mask-bd);--kink:var(--fk-mask-ink)}
.fg-ask button.k-mini{--kbg:var(--fk-mini-bg);--kbd:var(--fk-mini-bd);--kink:var(--fk-mini-ink)}
.fg-ask button.k-full{--kbg:var(--fk-full-bg);--kbd:var(--fk-full-bd);--kink:var(--fk-full-ink)}
.fg-ask button.k-none{--kbg:var(--surface);--kbd:var(--border);--kink:var(--ink-soft)}
.fg-pk-rows{display:grid;gap:8px}
.fg-pk-rows .fg-row{gap:6px 10px}
.fg-pk-lab{font-size:11.5px;font-weight:700;color:var(--ink-soft);min-width:96px}
/* Picker */
.fg-modal{max-width:680px;display:flex;flex-direction:column;max-height:min(88vh,760px)}
.fg-modal .modal-b{padding:14px 18px;overflow-y:auto;flex:1;min-height:0}
.fg-modal .modal-h{padding:14px 18px}
.fg-pk-top{display:grid;gap:10px;padding:12px 18px;border-bottom:1px solid var(--border)}
.fg-pk-top .seg{justify-self:start}
.fg-res-l{display:grid;gap:6px}
.fg-res{display:grid;grid-template-columns:40px minmax(0,1fr) auto;gap:10px;align-items:center;font:inherit;text-align:start;padding:7px 9px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:inherit;cursor:pointer;width:100%}
.fg-res:hover{border-color:#d2d8e6;background:var(--surface-2)}
.fg-res[aria-pressed="true"]{border-color:var(--accent);background:var(--accent-soft)}
.fg-res[disabled]{opacity:.55;cursor:not-allowed}
.fg-res small{display:block;font-size:10.5px;color:var(--ink-faint);text-transform:uppercase;letter-spacing:.04em}
.fg-res b{display:block;font-size:13px;font-weight:600;overflow-wrap:anywhere;line-height:1.3}
.fg-res .fg-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:3px;font-size:12px;color:var(--ink-soft)}
.fg-state-b{font-size:12px;font-weight:700;white-space:nowrap;color:var(--accent-ink)}
.fg-pk-foot{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1px solid var(--border);flex-wrap:wrap}
/* Products */
.fg-pt{display:grid;gap:0}
.fg-pr{display:grid;grid-template-columns:40px minmax(0,2fr) minmax(0,1fr) minmax(0,1.5fr) 130px 80px;gap:12px;align-items:center;padding:10px 4px;border-bottom:1px solid var(--border-2,var(--border))}
.fg-pr.hd{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);padding-top:4px}
.fg-pr b{font-size:13px;font-weight:600;overflow-wrap:anywhere;display:block}
.fg-pr small{font-size:11.5px;color:var(--ink-soft)}
.fg-create{border:1px solid var(--accent);border-radius:13px;padding:16px;display:grid;gap:14px;background:var(--surface)}
.fg-photo{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.fg-photo .fg-th{width:56px;height:56px}
/* Display */
.fg-disp{display:grid;gap:14px;grid-template-columns:minmax(0,1fr) 380px;align-items:start}
.fg-tx{display:grid;gap:0}
.fg-txr{display:grid;grid-template-columns:150px minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-2,var(--border))}
.fg-txr>span{font-size:12.5px;font-weight:600;color:var(--ink-2)}
.fg-txr>span small{display:block;font-weight:400;color:var(--ink-faint);font-size:11px}
.fg-txr.hd{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-faint);padding-top:0}
.fg-look{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
.fg-look .fg-in[type=number]{max-width:110px}
.fg-grp{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-faint);margin:14px 0 2px}
/* Each part's own Save (Lane FG3) */
.fg-save{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;border-top:1px solid var(--border);padding-top:12px;margin-top:14px}
.fg-save .fg-sub{margin:0}
.fg-save .fg-row{gap:8px;margin-inline-start:auto}
.fg-pk-st{font-size:12px;color:var(--ink-soft);min-height:16px}
/* The shop's box, "02 · Mint outline" -- the values of
   partials/free-gifts/box.blade.php, scoped here so nothing in the console
   restyles it and it restyles nothing in the console. */
.fgp{margin:14px 0 2px;padding:2px 11px 9px;border:1.5px solid #2e8f62;border-radius:12px;background:#fff;color:#2a2228;min-width:0;max-width:345px;font-family:inherit}
.fgp legend{display:flex;align-items:center;gap:7px;padding:0 7px;margin-inline-start:-1px;background:#fbf7f8;color:#2e8f62;font-size:14.5px;font-weight:700;line-height:1.45}
.fgp legend svg{width:16px;height:16px;flex:none}
.fgp-w{font-size:12.5px;font-weight:600;margin-top:3px;color:#2a2228;overflow-wrap:anywhere}
.fgp-cnt{font-weight:500;color:#756c74}
.fgp-th{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}
.fgp-t{position:relative;display:block;width:var(--fg-pr,36px);height:var(--fg-pr,36px);border-radius:8px;background:#fff;border:1px solid rgba(42,34,40,.08);flex:none;box-sizing:border-box}
.fgp-t img{display:block;width:100%;height:100%;object-fit:contain;border-radius:8px}
.fgp-ph{display:grid;place-items:center;width:100%;height:100%;font-size:10.5px;font-weight:800;color:#8a7d86;background:repeating-linear-gradient(135deg,#faf5f7 0 6px,#f3ebef 6px 12px);border-radius:8px}
.fgp-t b{position:absolute;top:-6px;inset-inline-end:-6px;background:#2e8f62;color:#fff;font-size:9.5px;font-weight:800;border-radius:999px;min-width:17px;height:17px;padding:0 4px;box-sizing:border-box;display:grid;place-items:center;line-height:1}
.fgp-tg{color:#2e8f62;font-size:11.5px;font-weight:700;margin-inline-start:4px;align-self:center}
.fgp-list{margin-top:7px;border-top:1px dashed #2e8f62;padding-top:5px;display:grid;gap:var(--fg-rg,3px)}
.fgp-sep .fgp-lr+.fgp-lr{border-top:1px solid #e4eee9;padding-top:var(--fg-rg,3px)}
.fgp-lr{display:flex;align-items:center;gap:8px}
.fgp-lr .fgp-t{width:var(--fg-pl,28px);height:var(--fg-pl,28px);border-radius:7px}
.fgp-lr .fgp-t img,.fgp-lr .fgp-ph{border-radius:7px}
.fgp-ln{flex:1;min-width:0;font-size:var(--fg-ts,12px);line-height:1.25;color:#2a2228}
.fgp-nm{display:block}
.fgp-clamp2 .fgp-nm{max-height:2.5em;overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.fgp-fade .fgp-list{grid-template-columns:minmax(0,1fr)}
.fgp-fade .fgp-nm{white-space:nowrap;overflow:hidden;-webkit-mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(90deg,#000 calc(100% - 28px),transparent)}
.fgp-fade .fgp-nm[dir=rtl]{-webkit-mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent);mask-image:linear-gradient(270deg,#000 calc(100% - 28px),transparent)}
.fgp-fade[dir=rtl] .fgp-nm[dir=ltr]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:right}
.fgp-fade[dir=ltr] .fgp-nm[dir=rtl]{-webkit-mask-image:none;mask-image:none;text-overflow:ellipsis;text-align:left}
.fgp-nobrand .fgp-ln small{display:none}
.fgp-ln small{display:block;font-size:10px;color:#756c74;text-transform:uppercase;letter-spacing:.04em}
.fgp-ln em{font-style:normal;font-weight:700}
.fgp-lv{display:flex;gap:6px;align-items:baseline;font-size:11px;font-weight:800;color:#2e8f62;white-space:nowrap;margin-inline-start:calc(var(--fg-fg,16px) - 8px)}
.fgp-lv s{color:#756c74;font-weight:500}
.fgp-hide{display:block;margin-top:6px;color:#2e8f62;font-size:11.5px;font-weight:700}
.fgp-none{font-size:12px;color:#756c74;padding:10px 0}
@media(max-width:1100px){
  .fg-rg-b{grid-template-columns:minmax(0,1fr)}
  .fg-pvcol{position:static}
  .fg-disp{grid-template-columns:minmax(0,1fr)}
}
@media(max-width:900px){
  .fg-status{grid-template-columns:minmax(0,1fr)}
  .fg-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
  .fg-card{padding:14px}
  .fg-rg-top{padding:10px 12px}
  .fg-rtab{padding:7px 11px;font-size:12.5px}
  .fg-rg-b{padding:12px}
  .fg-sumr{grid-template-columns:minmax(0,1fr);gap:6px}
  .fg-gi{grid-template-columns:40px minmax(0,1fr) auto;row-gap:8px}
  .fg-gi .fg-step{grid-column:2}
  .fg-gi .fg-btn-i{grid-column:3;grid-row:1}
  .fg-pr{grid-template-columns:40px minmax(0,1fr) auto;row-gap:6px}
  .fg-pr.hd{display:none}
  .fg-pr>.c-brand{display:none}
  .fg-pr>.c-stock,.fg-pr>.c-used,.fg-pr>.c-gift{grid-column:2/4}
  .fg-pr>.c-edit{grid-column:3;grid-row:1}
  .fg-txr{grid-template-columns:minmax(0,1fr)}
  .fg-txr.hd{display:none}
  .fg-kpis .kpi{padding:13px}
  .fg-kpis .kpi .val{font-size:20px}
  .fg-res{grid-template-columns:40px minmax(0,1fr)}
  .fg-res .fg-state-b{grid-column:2}
  .fg-crit{padding:9px 10px;gap:8px 10px}
  .fg-crit .btn{margin-inline-start:0}
  .fg-box{padding:7px}
  .fg-pk-lab{min-width:0;flex-basis:100%}
}
</style>

<script>
(function () {
  'use strict';

  var SCREEN = 'freegifts';
  var GROUP = 'Growth & Marketing';
  var TITLE = 'Free gifts';
  var TABS = [['overview', 'Overview'], ['ranges', 'Ranges & gifts'], ['products', 'Gift products'], ['display', 'Display'], ['home', 'Homepage section']];
  /* [key, label, help]; grouped as the shopper meets them. */
  var TEXT_GROUPS = [
    ['The box', [['title', 'Box title', ''], ['view_all', 'Open button', ''], ['hide', 'Close button', ''], ['free', 'On each gift', '']]],
    ['Gift count after the wording', [['count_one', '1 gift', ''], ['count_two', '2 gifts', 'Keep :count in English'], ['count_few', '3 to 10 gifts', 'Keep :count'], ['count_many', '11 gifts or more', 'Keep :count']]],
    ['Elsewhere', [['line', 'Checkout summary line', 'Keep :count'], ['tag', 'Emails, invoice, packing slip', ''], ['availability', 'Basket reminder email, under the gifts', ''], ['basket_cta', 'Basket reminder email, last line in the gift box', ''], ['email_added', 'Paid order emails: gift box heading', ''], ['email_added_note', 'Paid order emails: line under the gifts', '']]]
  ];
  var HOME_FIELDS = [['title', 'Title'], ['sub', 'Subtitle'], ['btn', 'Button'], ['note', 'Line under the gifts (empty = hidden)']];
  var MAX_QTY = 50;
  /* "Gift box look" (Lane FG5): FreeGifts::LOOK, ::LOOK_TITLES and
     ::LOOK_RANGES, written out because this script is @verbatim
     (FreeGiftsLookTest pins them equal), normalised here exactly as
     FreeGifts::lookOf() does, so the preview never shows a value the save
     would not keep. */
  var LOOK = {"brand":true,"titles":"clamp2","free_gap":16,"title_px":12,"photo_list":28,"photo_row":36,"separator":false,"row_gap":3}, LOOK_TITLES = ["clamp2","fade","wrap"], LOOK_RANGES = {"free_gap":[0,40],"title_px":[11,16],"photo_list":[22,48],"photo_row":[22,48],"row_gap":[0,16]};
  /* FreeGifts::textDir(): a fading title fades at its own end. */
  function textDir(s) { return /^[^\p{L}]*[\p{Script=Arabic}\p{Script=Hebrew}]/u.test(String(s || '')) ? 'rtl' : 'ltr'; }
  function lookOf(v) {
    v = v && typeof v === 'object' ? v : {};
    var o = { brand: typeof v.brand === 'boolean' ? v.brand : LOOK.brand, separator: typeof v.separator === 'boolean' ? v.separator : LOOK.separator,
      titles: LOOK_TITLES.indexOf(v.titles) >= 0 ? v.titles : LOOK.titles };
    Object.keys(LOOK_RANGES).forEach(function (k) {
      var n = typeof v[k] === 'number' ? v[k] : (typeof v[k] === 'string' && v[k].trim() !== '' ? Number(v[k]) : NaN);
      o[k] = isFinite(n) ? Math.max(LOOK_RANGES[k][0], Math.min(LOOK_RANGES[k][1], Math.round(n))) : LOOK[k];
    });
    return o;
  }
  var GIFT_SVG = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';
  var ICONS = {
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>',
    x: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
  };

  var st = {
    tab: 'overview', data: null, stats: null, banner: null, busy: false, seq: 0,
    sel: null,       // the selected range tab: an id, 'new', or null (= remembered, else the first)
    drafts: {},      // range id -> its edited copy (only once touched)
    cfg: null,       // Display + Homepage section, edited copy (null = untouched)
    errors: {},      // range id (or 'cfg') -> the server's refusal
    confirm: null,   // range id waiting on "Delete?"
    removed: {},     // range id -> { g, at } for the inline Undo
    picker: null,    // the open "Add gift" panel
    pvLang: 'en', pvRange: null,
    tester: { ip: '', label: '', err: null },
    create: { open: false, name: '', brand_id: '', stock: '', price: '', image: '', thumb: '', brands: null, busy: false, err: null, made: null }
  };
  var live = [];     // { node, fn } repainted in place while typing
  var LS_RANGE = 'kbb.freegifts.range';
  function remember(id) { try { window.localStorage.setItem(LS_RANGE, String(id)); } catch (e) {} }
  function recalled() { try { return window.localStorage.getItem(LS_RANGE); } catch (e) { return null; } }

  /* ------------------------------------------------------------ helpers */
  function cookie(n) { var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)'); return m ? decodeURIComponent(m.pop()) : ''; }
  function root() { return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, ''); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* toast() writes innerHTML; every message can carry the owner's wording. */
  function say(m, kind) { try { window.toast(esc(m), kind); } catch (e) {} }
  function el(tag, attrs, kids) {
    var x = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v == null || v === false) return;
      if (k === 'text') x.textContent = v; else if (k === 'class') x.className = v;
      else if (k === 'value') x.value = v;
      else if (k === 'html') x.innerHTML = v; /* constants only: the ICONS above */
      else if (k.indexOf('on') === 0) x.addEventListener(k.slice(2), v); else x.setAttribute(k, v === true ? '' : String(v));
    });
    (kids || []).forEach(function (c) { if (c != null && c !== false) x.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return x;
  }
  function ours() { var c = document.querySelector('#content'); return !!c && (!c.firstChild || !!c.querySelector('[data-screen="' + SCREEN + '"]')); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function fmt(n) { return Number(n).toLocaleString('en-US'); }
  function amount(min, max) { return 'AED ' + fmt(min) + (max == null ? '+' : ' – ' + fmt(max)); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function niceDate(s) { if (!s) return ''; var d = new Date(s + 'T00:00:00'); return isNaN(d) ? s : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
  function can() { return !!(st.data && st.data.can_manage); }
  function low() { return (st.data && st.data.low_stock) || 20; }

  /* ------------------------------------------- gift types and criteria (FG4)
     Internal only. [key, box title, short, one, many, icon]. The same table,
     counts and words as FreeGifts::KIND_NAMES / tally() / criteriaGaps() /
     criteriaWording(); FreeGiftsKindsTest runs these in node against PHP. */
  var KINDS = [
    ['mask', 'Face masks', 'Masks', 'face mask', 'face masks', '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3c-4.4 0-7 2.6-7 7.2 0 5 3.2 10.8 7 10.8s7-5.8 7-10.8C19 5.6 16.4 3 12 3z"/><path d="M8.5 10.5h2M13.5 10.5h2M10 16c1.2.8 2.8.8 4 0"/></svg>'],
    ['mini', 'Mini products', 'Minis', 'mini product', 'mini products', '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 3h4v3h-4zM9 6h6l1 3v10a2 2 0 0 1-2 2h-4a2 2 0 0 1-2-2V9z"/><path d="M8 13h8"/></svg>'],
    ['full', 'Full size products', 'Full size', 'full size product', 'full size products', '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 2h6v3H9zM7 5h10l1 3v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V8z"/><path d="M6 11h12M6 17h12"/></svg>']
  ];
  var KIND_KEYS = ['mask', 'mini', 'full'];
  var MAX_NEED = 50;
  function kindRow(k) { return KINDS.filter(function (r) { return r[0] === k; })[0] || null; }
  function kindOf(v) { return KIND_KEYS.indexOf(v) >= 0 ? v : null; }
  /* A range's criteria, or null when none is set (all 0 counts nothing). */
  function needsOf(x) {
    var n = x && x.needs;
    if (!n) return null;
    var total = KIND_KEYS.reduce(function (a, k) { return a + (Number(n[k]) || 0); }, 0);
    return total > 0 ? n : null;
  }
  /* UNITS per type: 2x of one mask is 2 masks. */
  function tally(gifts) {
    var out = { mask: 0, mini: 0, full: 0, none: 0 };
    // Main gifts only: a backup is a reserve, not part of the count.
    (gifts || []).forEach(function (g) { if (g.role !== 'backup') out[kindOf(g.kind) || 'none'] += Math.max(0, Number(g.quantity) || 0); });
    return out;
  }
  function criteriaGaps(needs, have) {
    if (!needs) return [];
    var out = [];
    KINDS.forEach(function (k) {
      var need = Number(needs[k[0]]) || 0, got = have[k[0]] || 0;
      if (got < need) out.push({ kind: k[0], have: got, need: need, text: k[1] + ': ' + got + ' of ' + need + ' chosen — add ' + (need - got) + ' more.' });
      else if (got > need) out.push({ kind: k[0], have: got, need: need, text: k[1] + ': ' + got + ' chosen, criteria says ' + need + '.' });
    });
    if (have.none > 0) out.push({ kind: 'none', have: have.none, need: 0, text: (have.none === 1 ? '1 gift is' : have.none + ' gifts are') + ' untagged, so not counted — set its type.' });
    return out;
  }
  var WORDS = {
    mask: ['Mask', 'Masks', ['قناع واحد', 'قناعان', ':n أقنعة', ':n قناعاً']],
    mini: ['Mini Product', 'Mini Products', ['منتج صغير واحد', 'منتجان صغيران', ':n منتجات صغيرة', ':n منتجاً صغيراً']],
    full: ['Full Size Product', 'Full Size Products', ['منتج بالحجم الكامل', 'منتجان بالحجم الكامل', ':n منتجات بالحجم الكامل', ':n منتجاً بالحجم الكامل']]
  };
  function wordingFor(needs) {
    var en = [], ar = [];
    KIND_KEYS.forEach(function (k) {
      var n = Math.max(0, Math.floor(Number(needs && needs[k]) || 0)), w = WORDS[k];
      if (!n) return;
      en.push(n + ' ' + (n === 1 ? w[0] : w[1]));
      ar.push(w[2][n === 1 ? 0 : n === 2 ? 1 : n <= 10 ? 2 : 3].split(':n').join(String(n)));
    });
    return { en: en.join(' + '), ar: ar.join(' + ') };
  }
  /* What a shopper gets right now: FreeGifts::linesFor(), in the browser, from
     the stock the screen already has (no request). With counts: each type's
     main gifts in order, then its backups, most stock first. Without: the main
     gifts as listed. */
  function availOf(p) {
    if (!p || p.status !== 'publish' || p.type === 'variable' || !p.in_stock) return 0;
    return p.stock === null ? Infinity : Math.max(0, p.stock);
  }
  function simulate(x) {
    var out = [], needs = needsOf(x), gifts = x.gifts || [];
    function add(g, q, backup) { out.push({ product: g.product, product_id: g.product_id, quantity: q, kind: kindOf(g.kind), backup: backup }); }
    if (!needs) {
      gifts.forEach(function (g) { var q = Math.min(g.quantity, availOf(g.product)); if (g.role !== 'backup' && q > 0) add(g, q, false); });
      return out;
    }
    gifts.forEach(function (g) { var q = Math.min(g.quantity, availOf(g.product)); if (g.role !== 'backup' && !kindOf(g.kind) && q > 0) add(g, q, false); });
    KIND_KEYS.forEach(function (k) {
      var left = Number(needs[k]) || 0;
      gifts.forEach(function (g) {
        if (left < 1 || g.role === 'backup' || kindOf(g.kind) !== k) return;
        var q = Math.min(g.quantity, availOf(g.product), left);
        if (q > 0) { add(g, q, false); left -= q; }
      });
      if (left < 1) return;
      gifts.filter(function (g) { return g.role === 'backup' && kindOf(g.kind) === k; })
        .map(function (g, i) { return { g: g, i: i, a: availOf(g.product) }; })
        .sort(function (a, b) { return b.a - a.a || a.i - b.i; })
        .forEach(function (b) { if (left < 1) return; var q = Math.min(b.a, left); if (q > 0) { add(b.g, q, true); left -= q; } });
    });
    return out;
  }
  function givenNeeds(lines) {
    var have = { mask: 0, mini: 0, full: 0 };
    lines.forEach(function (l) { if (l.kind && have[l.kind] != null) have[l.kind] += l.quantity; });
    return have;
  }
  function kindPill(k) { var r = kindRow(k); return r ? el('span', { class: 'fg-kp k-' + k, title: 'Gift type (internal only)' }, [el('span', { class: 'fg-kd k-' + k }), r[2]]) : null; }

  async function api(method, path, body, signal) {
    var opts = { method: method, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, credentials: 'same-origin' };
    if (signal) opts.signal = signal;
    if (body instanceof FormData) opts.body = body;
    else if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    var r = await fetch(root() + (path.charAt(0) === '!' ? path.slice(1) : '/admin-api/free-gifts' + path), opts);
    var payload = null;
    try { payload = await r.json(); } catch (e) { payload = null; }
    if (!r.ok) { var err = new Error('free-gifts ' + r.status); err.status = r.status; err.body = payload; throw err; }
    return payload;
  }
  function explain(e, fallback) {
    if (e && e.status === 404 && !(e.body && e.body.error)) return 'The Free gifts endpoints are not in this server\'s compiled route table yet. Clear the route cache and reload.';
    if (e && e.status === 403) return 'Your role cannot do this. An owner or a manager can.';
    if (e && e.body && e.body.errors) { var k = Object.keys(e.body.errors)[0]; return e.body.errors[k][0]; }
    return (e && e.body && (e.body.error || e.body.message)) ? (e.body.error || e.body.message) : fallback;
  }

  /* ------------------------------------------------------------- the screen */
  var previousGo = window.go;
  window.go = function (id) {
    if (id !== SCREEN) { closePicker(true); return previousGo.apply(this, arguments); }
    document.querySelectorAll('.side .nav-item').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-go') === SCREEN); });
    var group = document.querySelector('#nav .nav-group[data-sec="' + GROUP + '"]');
    if (group) group.classList.add('open');
    var crumb = document.querySelector('#crumb'), title = document.querySelector('#ptitle');
    if (crumb) crumb.textContent = GROUP;
    if (title) title.textContent = TITLE;
    var side = document.querySelector('#side');
    if (side) side.classList.remove('open');
    var c = document.querySelector('#content');
    if (c) c.textContent = '';
    st.drafts = {}; st.cfg = null; st.errors = {}; st.confirm = null; st.removed = {}; st.picker = null; st.stats = null; st.sel = null;
    render();
    run(function () { return api('GET', ''); }, 'Free gifts could not be loaded.');
    loadStats();
    return undefined;
  };

  function loadStats() {
    api('GET', '/stats').then(function (s) { st.stats = s; if (st.tab === 'overview') render(); })
      .catch(function () { st.stats = { failed: true }; if (st.tab === 'overview') render(); });
  }

  /* One write (or the first load): the payload replaces what the screen holds;
     an answer that is no longer the latest one is dropped. */
  async function run(fn, fallback, done) {
    var mine = ++st.seq;
    st.busy = true; st.banner = null; render();
    try {
      var d = await fn();
      if (mine !== st.seq) return;
      if (d && d.config) st.data = d;
      if (done) done(d);
    } catch (e) { if (mine === st.seq) { st.banner = explain(e, fallback); say(st.banner, 'bad'); } }
    if (mine === st.seq) { st.busy = false; render(); }
  }

  /* ------------------------------------------------------------ the model */
  function ranges() { return st.data ? st.data.ranges.slice().sort(function (a, b) { return a.min - b.min || a.id - b.id; }) : []; }
  function rangeById(id) { return st.data ? st.data.ranges.filter(function (r) { return r.id === id; })[0] || null : null; }
  function origOf(r) { return { min: r.min, max: r.max, label_en: r.label_en, label_ar: r.label_ar, enabled: r.enabled, starts_on: r.starts_on, ends_on: r.ends_on, needs: r.needs ? clone(r.needs) : null, gifts: clone(r.gifts) }; }
  /* What a range reads as now: its draft when touched, otherwise the server's. */
  function view(r) { return st.drafts[r.id] || origOf(r); }
  function draft(id) {
    if (!st.drafts[id]) { var r = rangeById(id); if (r) st.drafts[id] = origOf(r); }
    return st.drafts[id];
  }
  function norm(x) { return JSON.stringify([x.min, x.max, (x.label_en || '').trim(), (x.label_ar || '').trim(), !!x.enabled, x.starts_on || null, x.ends_on || null, x.gifts.map(function (g) { return [g.product_id, g.quantity, kindOf(g.kind), g.role === 'backup' ? 'backup' : 'primary']; }), needsOf(x) ? KIND_KEYS.map(function (k) { return Number(x.needs[k]) || 0; }) : null]); }
  function dirtyRanges() {
    return Object.keys(st.drafts).filter(function (k) {
      if (k === 'new') return true;
      var r = rangeById(Number(k));
      return !r || norm(st.drafts[k]) !== norm(origOf(r));
    });
  }
  function isDirty(k) { return dirtyRanges().indexOf(String(k)) >= 0; }
  function dispNorm(c) { return JSON.stringify([c.show_value, c.countries, c.basis, c.texts, lookOf(c.look)]); }
  function displayDirty() { return !!(st.cfg && st.data && dispNorm(st.cfg) !== dispNorm(st.data.config)); }
  function homeDirty() { return !!(st.cfg && st.data && JSON.stringify(st.cfg.home) !== JSON.stringify(st.data.config.home)); }
  function cfgDirty() { return displayDirty() || homeDirty(); }
  function cfg() { return st.cfg || (st.data && st.data.config); }
  function editCfg() { if (!st.cfg) st.cfg = clone(st.data.config); return st.cfg; }

  function stockOf(p, qty) {
    if (!p) return { level: 'out', cls: 'red', text: 'Missing' };
    if (p.status !== 'publish') return { level: 'out', cls: 'red', text: 'Not published' };
    if (p.type === 'variable') return { level: 'out', cls: 'red', text: 'Has options' };
    if (!p.in_stock) return { level: 'out', cls: 'red', text: 'Out of stock' };
    if (p.stock !== null && qty && p.stock < qty) return { level: 'low', cls: 'amber', text: 'Only ' + p.stock + ' left' };
    if (p.stock !== null && p.stock <= low()) return { level: 'low', cls: 'amber', text: 'Low · ' + p.stock + ' left' };
    /* (Lane FGB) A product whose stock is not tracked never runs out: giving it
       takes nothing off any count, so a number shown elsewhere stays as it was
       (the owner, 24 October: "the mixsoon should out of stock with this order,
       but it was still showing 2 pcs"). Say so in amber rather than a green
       "In stock" that reads as counted. Turn on stock tracking in the product
       editor to have gifts counted down. */
    if (p.stock === null) return { level: 'in', cls: 'amber', text: 'Stock not tracked · never runs out' };
    return { level: 'in', cls: 'green', text: 'In stock · ' + fmt(p.stock) };
  }
  function pill(cls, text) { return el('span', { class: 'pill ' + cls, text: text }); }
  /* The range editor's badge: an unavailable gift says, in so many words,
     that the shop skips it until it is back (Lane FG3). */
  function giftBadge(p, qty) {
    var s = stockOf(p, qty);
    return s.level === 'out' ? { level: 'out', cls: 'red', text: s.text + ' — skipped until restocked' } : s;
  }
  /* What a range's tab shows: red "!" when the range has no gifts or a gift
     the shop cannot give now; dimmed when off; a dot when unsaved. */
  function tabFlags(id, x) {
    var out = x.gifts.filter(function (g) { return stockOf(g.product, g.quantity).level === 'out'; }).length;
    var crit = criteriaGaps(needsOf(x), tally(x.gifts)).map(function (c) { return c.text; }).join(' ');
    var warn = [!x.gifts.length ? 'No gifts yet: this range gives nothing.' : out ? plural(out, 'gift is', 'gifts are') + ' missing or out of stock — skipped until restocked.' : '', crit].filter(Boolean).join(' ');
    return { warn: warn, off: !x.enabled, dirty: id === 'new' || isDirty(id) };
  }

  /* The same walk as FreeGiftsController::gaps() and ::overlaps(), over the
     ranges as they read NOW (drafts included), so a message appears as the
     owner types. The server stays the authority on save. */
  function bands() {
    var list = ranges().map(function (r) { var v = view(r); return { id: r.id, min: v.min, max: v.max, enabled: v.enabled }; });
    if (st.drafts['new']) list.push({ id: 'new', min: st.drafts['new'].min, max: st.drafts['new'].max, enabled: st.drafts['new'].enabled });
    return list.filter(function (b) { return typeof b.min === 'number' && !isNaN(b.min); });
  }
  function overlaps(aMin, aMax, bMin, bMax) {
    var aEnd = aMax == null ? Infinity : aMax + 1, bEnd = bMax == null ? Infinity : bMax + 1;
    return aMin < bEnd && bMin < aEnd;
  }
  function gaps() {
    var on = bands().filter(function (b) { return b.enabled && (b.max == null || b.max > b.min); }).sort(function (a, b) { return a.min - b.min; });
    var out = [], reach = null;
    for (var i = 0; i < on.length; i++) {
      if (reach !== null && on[i].min > reach + 1) out.push({ from: reach + 1, to: on[i].min - 1 });
      if (on[i].max == null) break;
      reach = reach === null ? on[i].max : Math.max(reach, on[i].max);
    }
    return out;
  }
  /* [{ level: err|warn, text }] for one range as it reads now. */
  function problems(id, x) {
    var out = [];
    if (typeof x.min !== 'number' || isNaN(x.min) || x.min < 0) out.push({ level: 'err', text: 'Enter the amount the range starts at.' });
    else {
      if (x.max != null && x.max < x.min) out.push({ level: 'warn', text: 'The top amount is below the start; it saves as ' + amount(x.max, x.min) + '.' });
      bands().forEach(function (b) {
        if (String(b.id) === String(id)) return;
        var lo = x.max != null && x.max < x.min ? x.max : x.min, hi = x.max != null && x.max < x.min ? x.min : x.max;
        if (overlaps(lo, hi, b.min, b.max)) out.push({ level: 'warn', text: 'Shares amounts with ' + amount(b.min, b.max) + '. A basket in both gets the higher range.' });
      });
      if (x.enabled) gaps().forEach(function (g) {
        if (g.to + 1 === x.min || (x.max != null && g.from - 1 === x.max)) out.push({ level: 'warn', text: 'Gap: ' + amount(g.from, g.to) + ' gives nothing.' });
      });
    }
    if (!(x.label_en || '').trim() || !(x.label_ar || '').trim()) out.push({ level: 'err', text: 'The wording is needed in English and in Arabic.' });
    if (x.starts_on && x.ends_on && x.ends_on < x.starts_on) out.push({ level: 'err', text: 'The end date is before the start date.' });
    if (x.enabled && !x.gifts.length) out.push({ level: 'warn', text: 'On, with no gifts: this range gives nothing until a gift is added.' });
    x.gifts.forEach(function (g) {
      var s = stockOf(g.product, g.quantity);
      if (s.level === 'out') out.push({ level: 'warn', text: (g.product ? g.product.name : 'A gift') + ': ' + s.text.toLowerCase() + ' — skipped until restocked. The rest of this range is still given.' });
      else if (g.product && g.product.stock !== null && g.product.stock < g.quantity) out.push({ level: 'warn', text: g.product.name + ': only ' + g.product.stock + ' left for ' + g.quantity + ' per order; the shop gives what there is.' });
    });
    return out;
  }
  function status(r, x) {
    var p = [], t = today(), stock = x.gifts.map(function (g) { return stockOf(g.product, g.quantity).level; });
    p.push(x.enabled ? pill('green', 'On') : pill('grey', 'Off'));
    if (x.starts_on && x.starts_on > t) p.push(pill('blue', 'Starts ' + niceDate(x.starts_on)));
    else if (x.ends_on && x.ends_on < t) p.push(pill('grey', 'Ended ' + niceDate(x.ends_on)));
    else if (x.ends_on) p.push(pill('blue', 'Until ' + niceDate(x.ends_on)));
    if (!x.gifts.length) p.push(pill('red', 'No gifts'));
    if (stock.indexOf('out') >= 0) p.push(pill('red', 'Out of stock'));
    else if (stock.indexOf('low') >= 0) p.push(pill('amber', 'Low stock'));
    if (needsOf(x)) { var cg = criteriaGaps(needsOf(x), tally(x.gifts)).length; p.push(cg ? pill('amber', 'Criteria: ' + plural(cg, 'thing', 'things') + ' to check') : pill('green', 'Matches criteria')); }
    if (problems(r.id, x).some(function (m) { return m.level === 'err'; })) p.push(pill('red', 'Needs a fix'));
    if (r.id === 'new' || isDirty(r.id)) p.push(pill('blue', 'Unsaved'));
    return p;
  }

  /* ----------------------------------------------------------- the pictures */
  function initials(p) { var s = (p && (p.brand || p.name)) || '?'; return s.slice(0, 2).toUpperCase(); }
  function thumb(p, cls) {
    cls = 'fg-th' + (cls ? ' ' + cls : '');
    return p && p.thumb ? el('img', { class: cls, src: p.thumb, alt: '', width: 40, height: 40, loading: 'lazy', decoding: 'async' })
      : el('span', { class: cls + ' fg-ph', 'aria-hidden': 'true', text: initials(p) });
  }
  function thumbs(gifts, max) {
    var kids = gifts.slice(0, max).map(function (g) {
      return el('span', { class: 'fg-tw', title: (g.product ? g.product.name : 'Product #' + g.product_id) + (g.quantity > 1 ? ' ×' + g.quantity : '') }, [thumb(g.product, 'sm'), g.quantity > 1 ? el('b', { text: '×' + g.quantity }) : null]);
    });
    if (gifts.length > max) kids.push(el('span', { class: 'fg-more', text: '+' + (gifts.length - max) }));
    if (!gifts.length) kids.push(el('span', { class: 'fg-more', text: 'No gifts yet' }));
    return el('span', { class: 'fg-thumbs' }, kids);
  }

  /* The shop's box for a range, built here from what the page holds: the
     gifts the shop would give (a sold-out, unpublished or optioned product is
     skipped; a short one gives what there is), the band's wording and the
     Display texts, in either language. Mirrors partials/free-gifts/box. */
  function countText(n, lang, c) {
    var form = n === 1 ? 'count_one' : n === 2 ? 'count_two' : n <= 10 ? 'count_few' : 'count_many';
    return (c.texts[form + '_' + lang] || '').split(':count').join(String(n));
  }
  function shopRows(gifts) {
    return gifts.filter(function (g) { return stockOf(g.product).level !== 'out'; }).map(function (g) {
      var q = g.quantity; if (g.product.stock !== null) q = Math.min(q, g.product.stock);
      return { p: g.product, q: q };
    }).filter(function (r) { return r.q > 0; });
  }
  function pvTile(p, size, q, row) {
    var t = el('span', { class: 'fgp-t' }, [p.thumb ? el('img', { src: p.thumb, alt: '', width: size, height: size, loading: 'lazy' }) : el('span', { class: 'fgp-ph', text: initials(p) })]);
    if (q > 1 && row) t.appendChild(el('b', { text: '×' + q }));
    return t;
  }
  function previewBox(gifts, words, lang, opened) {
    var c = cfg(), T = function (k) { return c.texts[k + '_' + lang] || ''; }, rows = shopRows(gifts);
    var lk = lookOf(c.look);
    var box = el('fieldset', { class: 'fgp' + (lk.brand ? '' : ' fgp-nobrand') + ({ clamp2: ' fgp-clamp2', fade: ' fgp-fade', wrap: '' })[lk.titles] + (lk.separator ? ' fgp-sep' : ''), dir: lang === 'ar' ? 'rtl' : 'ltr', lang: lang, 'aria-label': 'Preview',
      style: '--fg-ts:' + lk.title_px + 'px;--fg-pl:' + lk.photo_list + 'px;--fg-pr:' + lk.photo_row + 'px;--fg-fg:' + lk.free_gap + 'px;--fg-rg:' + lk.row_gap + 'px' });
    var lg = el('legend'); lg.innerHTML = GIFT_SVG; lg.appendChild(el('span', { text: T('title') })); box.appendChild(lg);
    if (!rows.length) { box.appendChild(el('div', { class: 'fgp-none', text: 'Nothing shows: this range has no gift the shop can give right now.' })); return box; }
    var units = rows.reduce(function (a, r) { return a + r.q; }, 0);
    box.appendChild(el('div', { class: 'fgp-w' }, (words || '').trim() ? [words.trim() + ' ', el('span', { class: 'fgp-cnt', text: '· ' + countText(units, lang, c) })] : [el('span', { class: 'fgp-cnt', text: countText(units, lang, c) })]));
    if (!opened) {
      box.appendChild(el('div', { class: 'fgp-th' }, rows.map(function (r) { return pvTile(r.p, lk.photo_row, r.q, true); }).concat([el('span', { class: 'fgp-tg', text: T('view_all') })])));
    } else {
      box.appendChild(el('div', { class: 'fgp-list' }, rows.map(function (r) {
        return el('div', { class: 'fgp-lr' }, [pvTile(r.p, lk.photo_list, r.q, false), el('span', { class: 'fgp-ln' }, [r.p.brand ? el('small', { text: r.p.brand }) : null, el('span', { class: 'fgp-nm', dir: lk.titles === 'fade' ? textDir(r.p.name) : null }, [r.q > 1 ? el('em', { text: r.q + '× ' }) : null, r.p.name])]),
          el('span', { class: 'fgp-lv' }, [c.show_value && r.p.price ? el('s', { text: r.p.price }) : null, T('free')])]);
      })));
      box.appendChild(el('span', { class: 'fgp-hide', text: T('hide') }));
    }
    return box;
  }
  function langSeg(onchange) {
    return el('div', { class: 'seg', role: 'group', 'aria-label': 'Preview language' }, [['en', 'English'], ['ar', 'العربية']].map(function (l) {
      return el('button', { type: 'button', class: st.pvLang === l[0] ? 'on' : '', 'aria-pressed': String(st.pvLang === l[0]), text: l[1], onclick: function () { st.pvLang = l[0]; onchange(); } });
    }));
  }
  function previewPair(getGifts, getWords) {
    var holder = el('div', { class: 'fg-pvcol' });
    function paint() {
      holder.textContent = '';
      var gifts = getGifts(), words = getWords(st.pvLang);
      holder.appendChild(el('div', { class: 'fg-between' }, [el('h4', { class: 'fg-h2', style: 'margin:0', text: 'What the shopper sees' }), langSeg(function () { paint(); })]));
      holder.appendChild(el('div', { class: 'fg-pvlab', text: 'Closed' }));
      holder.appendChild(el('div', { class: 'fg-pvbg' }, [previewBox(gifts, words, st.pvLang, false)]));
      holder.appendChild(el('div', { class: 'fg-pvlab', text: 'After "View all"' }));
      holder.appendChild(el('div', { class: 'fg-pvbg' }, [previewBox(gifts, words, st.pvLang, true)]));
    }
    paint();
    live.push({ node: holder, fn: paint });
    return holder;
  }

  /* ----------------------------------------------------------------- render */
  function sw(on, label, onclick, disabled, big) {
    return el('button', { type: 'button', class: 'fg-sw' + (big ? ' big' : ''), role: 'switch', 'aria-checked': String(!!on), onclick: onclick, disabled: disabled }, [el('i'), el('span', { text: label })]);
  }
  function field(label, input, help) { return el('label', { class: 'fg-field' }, [el('span', { text: label }), input, help ? el('small', { text: help }) : null]); }
  function card(title, sub, kids, extra) {
    return el('section', { class: 'card fg-card' + (extra ? ' ' + extra : '') }, [title ? el('h3', { class: 'fg-h', text: title }) : null, sub ? el('p', { class: 'fg-sub', text: sub }) : null].concat(kids || []));
  }
  function repaint() { live.forEach(function (l) { if (l.node.isConnected) l.fn(); }); }

  function render() {
    var c = document.querySelector('#content');
    if (!c || !ours()) return;
    var focusKey = document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-fk');
    live = [];
    c.textContent = '';
    var wrap = el('div', { class: 'fg-wrap', 'data-screen': SCREEN });
    c.appendChild(wrap);
    var tl = el('div', { class: 'fg-tabs', role: 'tablist', 'aria-label': 'Free gifts' });
    function paintTop() {
      tl.textContent = '';
      TABS.forEach(function (t) {
        var dirty = (t[0] === 'ranges' && dirtyRanges().length) || (t[0] === 'display' && displayDirty()) || (t[0] === 'home' && homeDirty());
        tl.appendChild(el('button', { type: 'button', class: 'fg-tab', role: 'tab', id: 'fg-tab-' + t[0], 'data-fk': 'tab-' + t[0], 'aria-selected': String(st.tab === t[0]), tabindex: st.tab === t[0] ? '0' : '-1', onclick: function () { setTab(t[0]); } },
          [t[1], dirty ? el('span', { class: 'fg-dot', title: 'Unsaved changes' }) : null]));
      });
    }
    paintTop();
    live.push({ node: tl, fn: function () { if (!tl.contains(document.activeElement)) paintTop(); } });
    tl.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var i = TABS.map(function (t) { return t[0]; }).indexOf(st.tab) + (e.key === 'ArrowRight' ? 1 : -1);
      setTab(TABS[(i + TABS.length) % TABS.length][0]);
      var b = document.getElementById('fg-tab-' + st.tab); if (b) b.focus();
    });
    wrap.appendChild(tl);
    if (st.banner) wrap.appendChild(el('div', { class: 'fg-note', role: 'alert', text: st.banner }));
    if (!st.data) { wrap.appendChild(card(null, st.busy ? 'Loading…' : 'Nothing to show.')); return; }
    var panel = el('div', { role: 'tabpanel', 'aria-labelledby': 'fg-tab-' + st.tab, class: 'fg-wrap', style: 'padding:0' });
    wrap.appendChild(panel);
    ({ overview: renderOverview, ranges: renderRanges, products: renderProducts, display: renderDisplay, home: renderHome })[st.tab](panel);
    if (st.picker) { wrap.appendChild(pickerModal()); paintResults(); }
    if (focusKey) { var f = wrap.querySelector('[data-fk="' + focusKey + '"]'); if (f) f.focus(); }
  }
  function setTab(t) { st.tab = t; st.confirm = null; render(); }

  /* ------------------------------------------------------- the own Saves */
  function rangeBody(x) {
    // With counts the wording IS the counts (the sentence is automatic); without, his own words.
    var auto = needsOf(x) ? wordingFor(x.needs) : null;
    return { min: x.min, max: x.max, label_en: auto && auto.en ? auto.en : (x.label_en || '').trim(), label_ar: auto && auto.ar ? auto.ar : (x.label_ar || '').trim(), enabled: !!x.enabled, starts_on: x.starts_on || null, ends_on: x.ends_on || null, gifts: x.gifts.map(function (g) { return { product_id: g.product_id, quantity: g.quantity, kind: kindOf(g.kind), role: g.role === 'backup' ? 'backup' : 'primary' }; }), needs: needsOf(x) ? { mask: Number(x.needs.mask) || 0, mini: Number(x.needs.mini) || 0, full: Number(x.needs.full) || 0 } : null };
  }
  function settingsBody(c) {
    var t = {};
    TEXT_GROUPS.forEach(function (g) { g[1].forEach(function (k) { t[k[0] + '_en'] = c.texts[k[0] + '_en']; t[k[0] + '_ar'] = c.texts[k[0] + '_ar']; }); });
    return { show_value: !!c.show_value, countries: c.countries, basis: c.basis, texts: t, home: c.home, look: lookOf(c.look) };
  }
  function rangeName(x) { var w = (x.label_en || '').trim(); return amount(x.min, x.max) + (w ? ' (' + w + ')' : ''); }
  /* One range, alone: PUT (or POST for the new one) of that range and nothing
     else, then a toast naming it. A refusal keeps its draft and says why. */
  async function saveRange(k) {
    var x = st.drafts[k];
    if (st.busy || !x) return;
    var name = rangeName(x);
    st.busy = true; st.banner = null; repaint();
    try {
      var d = await api(k === 'new' ? 'POST' : 'PUT', k === 'new' ? '/ranges' : '/ranges/' + k, rangeBody(x));
      delete st.drafts[k]; delete st.errors[k]; delete st.removed[k];
      st.data = d;
      if (k === 'new') { var made = d.ranges.filter(function (r) { return r.min === x.min; })[0]; selectRange(made ? made.id : null); }
      say('Saved ' + name + '.');
      loadStats();
    } catch (e) { st.errors[k] = explain(e, 'Not saved.'); say('Not saved: ' + name + '. ' + st.errors[k], 'bad'); }
    st.busy = false;
    render();
  }
  function discardRange(k) {
    var x = st.drafts[k], name = x && k !== 'new' ? rangeName(x) : '';
    delete st.drafts[k]; delete st.errors[k]; delete st.removed[k]; st.confirm = null;
    if (k === 'new') selectRange(null);
    say(k === 'new' ? 'Removed the new range.' : 'Changes to ' + name + ' discarded.');
    render();
  }
  /* Display and Homepage section share one settings row on the server, so
     each Save sends its own part with the OTHER part as the server has it,
     and keeps the other part's unsaved edits afterwards. */
  async function saveCfg(part) {
    if (st.busy || !st.cfg) return;
    var o = st.data.config, c = st.cfg;
    var body = part === 'home' ? Object.assign(clone(o), { home: clone(c.home) }) : Object.assign(clone(c), { home: clone(o.home) });
    st.busy = true; repaint();
    try {
      var d = await api('POST', '/settings', settingsBody(body));
      var keep = st.cfg, next = clone(d.config);
      if (part === 'home') { next.show_value = keep.show_value; next.countries = keep.countries; next.basis = keep.basis; next.texts = clone(keep.texts); next.look = clone(lookOf(keep.look)); } else next.home = clone(keep.home);
      st.data = d; st.cfg = next; delete st.errors[part];
      if (!cfgDirty()) st.cfg = null;
      say(part === 'home' ? 'Saved the homepage section.' : 'Saved the display settings and texts.');
    } catch (e) { st.errors[part] = explain(e, 'Not saved.'); say('Not saved: ' + st.errors[part], 'bad'); }
    st.busy = false;
    render();
  }
  function discardCfg(part) {
    if (!st.cfg) return;
    var o = st.data.config;
    if (part === 'home') st.cfg.home = clone(o.home);
    else { st.cfg.show_value = o.show_value; st.cfg.countries = o.countries; st.cfg.basis = o.basis; st.cfg.texts = clone(o.texts); st.cfg.look = clone(lookOf(o.look)); }
    delete st.errors[part];
    if (!cfgDirty()) st.cfg = null;
    say(part === 'home' ? 'Homepage section changes discarded.' : 'Display changes discarded.');
    render();
  }
  function cfgSaveRow(part) {
    if (!can()) return null;
    var holder = el('div', { class: 'fg-save', role: 'region', 'aria-label': part === 'home' ? 'Save the homepage section' : 'Save display' });
    function paint() {
      var dirty = part === 'home' ? homeDirty() : displayDirty();
      holder.textContent = '';
      holder.appendChild(el('span', { class: 'fg-sub', text: dirty ? 'Unsaved changes' : 'Everything here is saved.' }));
      holder.appendChild(el('div', { class: 'fg-row' }, [
        dirty ? el('button', { type: 'button', class: 'btn ghost sm', text: 'Discard', disabled: st.busy, onclick: function () { discardCfg(part); } }) : null,
        el('button', { type: 'button', class: 'btn sm', 'data-fk': 'save-' + part, text: st.busy && dirty ? 'Saving…' : 'Save', disabled: st.busy || !dirty, onclick: function () { saveCfg(part); } })
      ]));
    }
    paint();
    live.push({ node: holder, fn: paint });
    return holder;
  }

  /* -------------------------------------------------------------- overview */
  function goRange(id) {
    st.tab = 'ranges'; selectRange(id); render();
    var b = document.querySelector('[data-fk="rt-' + id + '"]'); if (b) { b.focus(); b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
  }
  /* The Mode (Off / Testing / Live) and the Testing list. Each is one
     deliberate click that saves at once, with its own toast. Testing and Live
     are ALWAYS selectable (Lane FG3): "Things to check" warns, it never
     refuses. Testing with nobody on the list puts the owner's own address on
     it (the server does it and answers added_ip), so the click still works. */
  var MODES = [['off', 'Off', 'Nobody sees free gifts.'], ['testing', 'Testing', 'Only the IPs listed here see them.'], ['live', 'Live', 'Every shopper sees them.']];
  function modeControl(d) {
    var mode = d.config.mode || (d.config.on ? 'live' : 'off');
    var g = el('div', { class: 'fg-modes', role: 'radiogroup', 'aria-label': 'Mode' }, MODES.map(function (m) {
      return el('button', { type: 'button', role: 'radio', class: 'fg-mode m-' + m[0], 'aria-checked': String(mode === m[0]), 'data-fk': 'mode-' + m[0], /* (Lane PD) The owner, 23 October: each of the three asks Yes / No first
           in the admin's "Are you sure?" box (partials/reset-guard) -- one
           stray tap here turns gifts on or off for every shopper. The current
           mode does not ask: clicking it does nothing. */
        'data-kbb-sure': mode === m[0] ? null : m[2] + ' Switch free gifts to ' + m[1] + '?', tabindex: mode === m[0] ? '0' : '-1',
        disabled: !can() || st.busy, onclick: function () {
          if (mode === m[0]) return;
          run(function () { return api('POST', '/switch', { mode: m[0] }); }, 'Mode not changed.', function (res) {
            var n = ((res && res.config && res.config.testers) || []).length;
            say(m[0] === 'live' ? 'Free gifts are Live: every shopper sees them.'
              : m[0] === 'testing' ? (res && res.added_ip ? 'Free gifts are in Testing. Your IP ' + res.added_ip + ' was added to the Testing list, so you see them; nobody else does.' : 'Free gifts are in Testing: only ' + plural(n, 'IP', 'IPs') + ' on the list see them.')
              : 'Free gifts are Off: nobody sees them.');
            loadStats();
          });
        } }, [el('b', { text: m[1] }), el('small', { text: m[2] })]);
    }));
    g.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
      var b = Array.prototype.slice.call(g.querySelectorAll('button:not([disabled])')), i = b.indexOf(document.activeElement);
      if (i < 0 || b.length < 2) return;
      e.preventDefault(); b[(i + ((e.key === 'ArrowRight' || e.key === 'ArrowDown') ? 1 : b.length - 1)) % b.length].focus();
    });
    return g;
  }
  function saveTesters(list, msg) {
    st.tester.err = null;
    run(function () { return api('POST', '/testers', { testers: list }); }, 'The IP list was not saved.', function () { st.tester.ip = ''; st.tester.label = ''; say(msg); });
  }
  function testersCard(d) {
    var testers = d.config.testers || [], t = st.tester, ro = !can();
    var ip = el('input', { class: 'fg-in', type: 'text', value: t.ip, placeholder: '203.0.113.7 or 2001:db8::1', maxlength: 45, 'aria-label': 'IP address', 'data-fk': 'tester-ip', disabled: ro, style: 'font-family:ui-monospace,monospace' });
    var label = el('input', { class: 'fg-in', type: 'text', value: t.label, placeholder: 'Label (optional)', maxlength: 60, 'aria-label': 'Label for this IP', 'data-fk': 'tester-label', disabled: ro });
    ip.addEventListener('input', function () { t.ip = ip.value; });
    label.addEventListener('input', function () { t.label = label.value; });
    function add(addr, lab) {
      addr = (addr || '').trim();
      if (!addr) { t.err = 'Type an IP address.'; render(); return; }
      if (testers.some(function (x) { return x.ip === addr; })) { t.err = addr + ' is already on the list.'; render(); return; }
      if (testers.length >= 50) { t.err = 'The list holds at most 50 IPs.'; render(); return; }
      saveTesters(testers.concat([{ ip: addr, label: (lab || '').trim() }]), 'Added ' + addr + ' to the Testing list.');
    }
    ip.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(t.ip, t.label); } });
    var mine = d.my_ip, mineListed = testers.some(function (x) { return x.ip === mine; });
    return el('div', { class: 'fg-testers' }, [
      el('h4', { class: 'fg-h2', text: 'IPs that can see it in Testing' }),
      el('p', { class: 'fg-sub', style: 'margin:0 0 8px', text: 'In Testing only these addresses see the gifts: in the cart, at checkout, on the order and in its emails. Everyone else sees the shop exactly as when it is Off. Up to 50.' }),
      testers.length ? el('ul', { class: 'fg-iplist' }, testers.map(function (x, i) {
        return el('li', {}, [el('code', { text: x.ip }), el('span', { class: 'fg-iplab', text: x.label }), x.ip === mine ? pill('blue', 'You') : null,
          ro ? null : el('button', { type: 'button', class: 'fg-btn-i danger', 'aria-label': 'Remove ' + x.ip, title: 'Remove', html: ICONS.trash, onclick: function () {
            saveTesters(testers.filter(function (_, j) { return j !== i; }), 'Removed ' + x.ip + ' from the Testing list.');
          } })]);
      })) : el('div', { class: 'fg-empty', text: 'No IPs yet.' }),
      ro ? null : el('div', { class: 'fg-ipadd' }, [ip, label, el('button', { type: 'button', class: 'btn ghost sm', text: 'Add IP', disabled: st.busy, onclick: function () { add(t.ip, t.label); } })]),
      ro || !mine ? null : el('div', { class: 'fg-row', style: 'margin-top:8px' }, [
        el('button', { type: 'button', class: 'btn ghost sm', disabled: st.busy || mineListed, 'data-fk': 'tester-mine', onclick: function () { add(mine, 'My device'); } }, [mineListed ? 'Your IP is on the list' : 'Add my current IP']),
        el('span', { class: 'fg-sub', style: 'margin:0' }, ['The server sees you as ', el('code', { text: mine })])
      ]),
      t.err ? el('div', { class: 'fg-msg err', role: 'alert', style: 'margin-top:6px' }, [el('i', { text: '!' }), el('span', { text: t.err })]) : null
    ]);
  }
  function renderOverview(wrap) {
    var d = st.data, mode = d.config.mode || (d.config.on ? 'live' : 'off');
    var items = d.checklist, n = (d.config.testers || []).length;
    if (mode === 'testing') wrap.appendChild(el('div', { class: 'fg-banner', role: 'status' }, [el('b', { text: 'Testing: ' }), 'only ' + plural(n, 'IP sees', 'IPs see') + ' free gifts. Every other shopper sees the shop as if it were Off.']));
    wrap.appendChild(el('section', { class: 'card fg-card' }, [el('div', { class: 'fg-status' }, [
      el('div', { class: 'fg-state' }, [
        el('h3', { class: 'fg-h', text: 'Free gifts: ' + (mode === 'live' ? 'Live' : mode === 'testing' ? 'Testing' : 'Off') }),
        modeControl(d),
        el('p', { text: 'Spend a set amount and the order gets free gifts, shown in the cart panel, on the cart page and in the checkout summary, and kept on the order at AED 0. One range at a time, never added together. What counts: the products\' subtotal ' + (d.config.basis === 'after_discounts' ? 'after the coupon' : 'before the coupon') + ', without delivery, gift wrapping or the gifts. ' + (d.config.countries === 'AE' ? 'UAE deliveries only.' : 'Every country the shop delivers to.') }),
        el('div', {}, [el('button', { type: 'button', class: 'fg-link', text: 'Change what counts and where', onclick: function () { setTab('display'); } })])
      ]),
      el('div', { class: 'fg-state' }, [testersCard(d)])
    ])]));
    wrap.appendChild(card('Things to check', items.length ? 'Nothing here stops Testing or Live. A gift that is missing or out of stock is skipped until it is back, and the rest of its range is still given.' : null, [el('ul', { class: 'fg-checks', style: 'margin-top:10px' }, (items.length ? items : [{ level: 'ok', text: 'All clear: every range that is on has its gifts, and they are in stock.' }]).map(function (i) {
      var fix = i.level === 'ok' ? null : el('button', { type: 'button', class: 'fg-link', text: i.range ? 'Open range' : 'Add a range', onclick: function () { if (i.range) goRange(i.range); else { setTab('ranges'); if (can()) addRange(); } } });
      return el('li', { class: 'lv-' + i.level }, [pill(i.level === 'alert' ? 'red' : i.level === 'warn' ? 'amber' : 'green', i.level === 'ok' ? 'All clear' : 'Check'), el('span', { text: i.text }), fix]);
    }))]));

    var s = st.stats, L = low();
    function kpi(label, val, sub, kids, txt) { return el('div', { class: 'kpi' }, [el('div', { class: 'lbl', text: label }), el('div', { class: 'val' + (txt ? ' txt' : ''), text: val }), el('div', { class: 'sub', text: sub })].concat(kids || [])); }
    var dash = s && !s.failed ? null : (s && s.failed ? '—' : '…');
    var alerts = s && s.stock_alerts ? s.stock_alerts : [];
    wrap.appendChild(el('div', { class: 'fg-kpis', 'aria-live': 'polite' }, [
      kpi('Orders with gifts', dash || fmt(s.orders), 'Last ' + ((s && s.days) || 30) + ' days, paid orders'),
      kpi('Gift units given', dash || fmt(s.units), 'Last ' + ((s && s.days) || 30) + ' days'),
      kpi('Most-given gift', dash || (s.top ? s.top.name : 'None yet'), dash ? '' : (s.top ? plural(s.top.units, 'unit', 'units') + ' given' : 'No gift has gone out yet'), null, true),
      kpi('Stock alerts', dash || String(alerts.length), 'Gifts with ' + L + ' units or fewer, or out of stock', alerts.length ? [el('ul', {}, alerts.slice(0, 4).map(function (a) { return el('li', {}, [pill(a.level === 'out' ? 'red' : 'amber', a.level === 'out' ? 'Out' : a.stock + ' left'), ' ' + a.name]); }).concat(alerts.length > 4 ? [el('li', { text: '+' + (alerts.length - 4) + ' more on Gift products' })] : []))] : null)
    ]));

    var list = ranges();
    wrap.appendChild(card('Ranges at a glance', list.length ? 'Click a range to edit it.' : null, [list.length ? el('div', { class: 'fg-sum', style: 'margin-top:12px' }, list.map(function (r) {
      var x = view(r);
      return el('button', { type: 'button', class: 'fg-sumr', 'aria-label': 'Edit ' + amount(x.min, x.max), onclick: function () { goRange(r.id); } }, [
        el('span', { class: 'fg-amtl', text: amount(x.min, x.max) }),
        el('span', { class: 'fg-row', style: 'gap:6px 12px' }, [el('span', { class: 'fg-words', text: x.label_en || '—' }), thumbs(x.gifts, 8)]),
        el('span', { class: 'fg-pills' }, status(r, x))
      ]);
    })) : el('div', { class: 'fg-empty', style: 'margin-top:12px' }, ['No ranges yet. ', can() ? el('button', { type: 'button', class: 'fg-link', text: 'Add the first range', onclick: function () { setTab('ranges'); addRange(); } }) : null])]));
  }

  /* ---------------------------------------------------------------- ranges */
  function nextFree() { return bands().reduce(function (a, b) { return Math.max(a, b.max == null ? b.min + 1 : b.max + 1); }, 0); }
  /* The selected range tab: the one asked for, else the one remembered from
     last time, else the first. 'new' only while a new range is a draft. */
  function selected() {
    var list = ranges(), has = function (v) { return v != null && list.some(function (r) { return String(r.id) === String(v); }); };
    if (st.sel === 'new' && st.drafts['new']) return 'new';
    if (has(st.sel)) return Number(st.sel);
    var saved = recalled();
    if (has(saved)) return Number(saved);
    return list.length ? list[0].id : (st.drafts['new'] ? 'new' : null);
  }
  function selectRange(id) { st.sel = id; st.confirm = null; if (id != null && id !== 'new') remember(id); }
  function addRange(from) {
    if (!st.drafts['new']) {
      var base = from ? clone(from) : { label_en: '', label_ar: '', gifts: [], starts_on: null, ends_on: null };
      st.drafts['new'] = { min: nextFree(), max: null, label_en: base.label_en, label_ar: base.label_ar, enabled: false, starts_on: base.starts_on, ends_on: base.ends_on, needs: base.needs ? clone(base.needs) : null, gifts: base.gifts };
    }
    selectRange('new');
    render();
    var f = document.querySelector('[data-fk="min-new"]'); if (f) f.focus();
  }
  function renderRanges(wrap) {
    var list = ranges(), sel = selected();
    st.sel = sel;
    var ids = list.map(function (r) { return r.id; });
    if (st.drafts['new']) ids.push('new');
    var strip = el('div', { class: 'fg-rtabs', role: 'tablist', 'aria-label': 'Ranges' });
    function paintStrip() {
      if (strip.contains(document.activeElement)) return;
      strip.textContent = '';
      ids.forEach(function (id) {
        var r = id === 'new' ? null : rangeById(id), x = id === 'new' ? st.drafts['new'] : view(r), f = tabFlags(id, x), on = String(id) === String(sel);
        var label = id === 'new' ? (typeof x.min === 'number' && !isNaN(x.min) ? 'New · ' + amount(x.min, x.max) : 'New range') : amount(x.min, x.max);
        var tip = [f.off ? 'Off' : null, f.warn || null, f.dirty ? 'Unsaved changes' : null].filter(Boolean).join(' · ');
        strip.appendChild(el('button', { type: 'button', role: 'tab', class: 'fg-rtab' + (f.off ? ' off' : ''), id: 'fg-rt-' + id, 'data-fk': 'rt-' + id, 'aria-selected': String(on), 'aria-controls': 'fg-rp', tabindex: on ? '0' : '-1', title: tip || null,
          onclick: function () { if (on) return; selectRange(id); render(); var b = document.querySelector('[data-fk="rt-' + id + '"]'); if (b) { b.focus(); b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } } }, [
          el('span', { class: 'fg-rt-l', text: label }),
          f.off ? el('span', { class: 'fg-rt-off', text: 'Off' }) : null,
          f.warn ? el('span', { class: 'fg-rt-warn', role: 'img', 'aria-label': f.warn, text: '!' }) : null,
          f.dirty ? el('span', { class: 'fg-rt-dirty', role: 'img', 'aria-label': 'Unsaved changes' }) : null
        ]));
      });
      if (can() && !st.drafts['new']) strip.appendChild(el('button', { type: 'button', class: 'fg-rtab add', 'data-fk': 'rt-add', onclick: function () { addRange(); } }, [el('span', { html: ICONS.plus, style: 'display:inline-flex' }), 'Add range']));
    }
    paintStrip();
    live.push({ node: strip, fn: paintStrip });
    strip.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var i = ids.map(String).indexOf(String(sel));
      if (i < 0 || ids.length < 2) return;
      e.preventDefault();
      var to = ids[(i + (e.key === 'ArrowRight' ? 1 : ids.length - 1)) % ids.length];
      selectRange(to); render();
      var b = document.querySelector('[data-fk="rt-' + to + '"]'); if (b) { b.focus(); b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
    });
    wrap.appendChild(el('section', { class: 'card fg-card' }, [
      el('h3', { class: 'fg-h', text: 'Ranges & gifts' }),
      el('p', { class: 'fg-sub', text: 'Whole dirhams: "AED 100 – 299" covers AED 100.00 to AED 299.99. You set the amounts and gaps freely; if two ranges share an amount, the basket gets the higher range. Each gift is 1 to ' + MAX_QTY + ' of a product; a sold-out gift is skipped until it is back and the rest of the range is still given. Each range saves on its own.' }),
      strip
    ]));
    if (sel == null) { wrap.appendChild(el('div', { class: 'fg-empty' }, ['No ranges yet. ', can() ? el('button', { type: 'button', class: 'fg-link', text: 'Add the first range', onclick: function () { addRange(); } }) : null])); return; }
    var r = sel === 'new' ? { id: 'new' } : rangeById(sel);
    var head = el('div', { class: 'fg-rg-top' });
    function paintHead() {
      var x = sel === 'new' ? st.drafts['new'] : view(r);
      head.textContent = '';
      head.appendChild(el('span', { class: 'fg-amtl', text: sel === 'new' ? 'New range' : amount(x.min, x.max) }));
      head.appendChild(el('span', { class: 'fg-words', text: (x.label_en || '').trim() || 'No wording yet' }));
      head.appendChild(el('span', { class: 'fg-pills' }, status(r, x)));
    }
    paintHead();
    live.push({ node: head, fn: paintHead });
    wrap.appendChild(el('section', { class: 'fg-rg open', role: 'tabpanel', id: 'fg-rp', 'aria-labelledby': 'fg-rt-' + sel }, [head, rangeBodyEl(sel, r)]));
  }
  function rangeBodyEl(id, r) {
    var ro = !can(), x = ro ? view(r) : (id === 'new' ? st.drafts['new'] : draft(id)), key = String(id);
    function changed() { repaint(); }
    function num(prop, label) {
      var i = el('input', { class: 'fg-in', type: 'number', inputmode: 'numeric', min: 0, step: 1, value: x[prop] == null ? '' : x[prop], disabled: ro || (prop === 'max' && x.max == null), 'aria-label': label, 'data-fk': prop + '-' + id });
      i.addEventListener('input', function () { x[prop] = i.value === '' ? (prop === 'max' ? null : NaN) : Math.floor(Number(i.value)); changed(); });
      return i;
    }
    var words = {};
    function txt(prop, label, rtl) {
      var i = el('input', { class: 'fg-in', type: 'text', value: x[prop] || '', maxlength: 160, dir: rtl ? 'rtl' : null, lang: rtl ? 'ar' : null, disabled: ro, 'data-fk': prop + '-' + id });
      i.addEventListener('input', function () { x[prop] = i.value; changed(); });
      words[prop] = i;
      return field(label, i);
    }
    function date(prop, label) {
      var i = el('input', { class: 'fg-in', type: 'date', value: x[prop] || '', disabled: ro, 'data-fk': prop + '-' + id });
      i.addEventListener('input', function () { x[prop] = i.value || null; changed(); });
      return field(label, i);
    }
    var maxIn = num('max', 'Up to (AED)');
    var above = el('input', { type: 'checkbox', checked: x.max == null, disabled: ro, 'data-fk': 'above-' + id });
    above.addEventListener('change', function () {
      if (above.checked) { x.max = null; maxIn.value = ''; maxIn.disabled = true; }
      else { x.max = (typeof x.min === 'number' && !isNaN(x.min) ? x.min : 0) + 199; maxIn.value = x.max; maxIn.disabled = false; maxIn.focus(); }
      changed();
    });
    var msgs = el('div', { class: 'fg-msgs', 'aria-live': 'polite' });
    function paintMsgs() {
      msgs.textContent = '';
      problems(id, x).forEach(function (m) { msgs.appendChild(el('div', { class: 'fg-msg ' + m.level }, [el('i', { text: m.level === 'err' ? '!' : '·' }), el('span', { text: m.text })])); });
      if (st.errors[key]) msgs.appendChild(el('div', { class: 'fg-msg err', role: 'alert' }, [el('i', { text: '!' }), el('span', { text: 'The server said: ' + st.errors[key] })]));
    }
    paintMsgs();
    live.push({ node: msgs, fn: paintMsgs });

    var wordsInputs = el('div', { class: 'fg-g2' }, [txt('label_en', 'Wording (English)'), txt('label_ar', 'Wording (Arabic)', true)]);
    var autoWords = el('div', { class: 'fg-auto', role: 'status', 'aria-live': 'polite' });
    var wordsRow = el('div', {}, [autoWords, wordsInputs]);
    /* With counts, the sentence writes itself from what a shopper actually gets
       right now (main gifts, topped up from backups); the boxes stay for a range
       with no counts. Homepage and box read the same. */
    function paintWords() {
      var n = needsOf(x);
      wordsInputs.hidden = !!n;
      autoWords.hidden = !n;
      autoWords.textContent = '';
      if (!n) return;
      var lines = simulate(x), w = wordingFor(givenNeeds(lines)), want = wordingFor(n), units = lines.reduce(function (a, l) { return a + l.quantity; }, 0);
      autoWords.appendChild(el('div', { class: 'fg-auto-h' }, [el('b', { text: 'Sentence on the shop' }), el('small', { text: 'Automatic, from the counts above' })]));
      autoWords.appendChild(el('div', { class: 'fg-auto-s' }, [el('span', { text: w.en || '—' }), el('span', { dir: 'rtl', lang: 'ar', text: w.ar || '—' })]));
      var backups = lines.filter(function (l) { return l.backup; });
      var note = w.en !== want.en
        ? 'Right now a shopper gets ' + plural(units, 'gift', 'gifts') + ', fewer than the counts: some gifts are out of stock with no backup left.'
        : backups.length
          ? 'Right now ' + backups.map(function (l) { return l.quantity + ' × ' + (l.product ? l.product.name : 'a backup'); }).join(', ') + ' ' + (backups.length === 1 ? 'comes' : 'come') + ' from the backups.'
          : 'Right now every gift comes from the main list.';
      autoWords.appendChild(el('small', { class: 'fg-auto-n' + (w.en !== want.en ? ' short' : ''), text: note }));
    }
    paintWords();
    live.push({ node: autoWords, fn: paintWords });
    var crit = criteriaEl(id, x, ro, words, changed);
    var giftList = giftBoxes(id, key, x, ro);

    var foot = el('div', { class: 'fg-foot' });
    if (!ro) {
      if (st.confirm === id) {
        foot = el('div', { class: 'fg-confirm', role: 'alertdialog', 'aria-label': 'Confirm delete' }, [
          el('span', { text: 'Delete ' + amount(r.min, r.max) + ' and its ' + plural(r.gifts.length, 'gift', 'gifts') + '? The shop stops giving them at once.' }),
          el('span', { class: 'fg-row' }, [
            el('button', { type: 'button', class: 'btn ghost sm', text: 'Keep it', 'data-fk': 'keep-' + id, onclick: function () { st.confirm = null; render(); } }),
            el('button', { type: 'button', class: 'btn danger sm', text: 'Delete range', onclick: function () {
              var label = amount(r.min, r.max) + ' (' + r.label_en + ')';
              st.confirm = null;
              run(function () { return api('DELETE', '/ranges/' + id); }, 'Not deleted.', function () { delete st.drafts[id]; delete st.removed[id]; delete st.errors[id]; selectRange(null); say('Deleted ' + label + '.'); loadStats(); });
            } })
          ])
        ]);
      } else {
        foot.appendChild(el('div', { class: 'fg-row' }, id === 'new' ? [
          el('button', { type: 'button', class: 'btn ghost sm', text: 'Remove this new range', onclick: function () { discardRange('new'); } })
        ] : [
          el('button', { type: 'button', class: 'btn ghost sm', text: 'Duplicate', title: 'A new range with this wording and these gifts', onclick: function () { if (st.drafts['new']) { say('Save or remove the new range first.', 'bad'); return; } addRange(x); } }),
          el('button', { type: 'button', class: 'btn ghost sm', style: 'color:#b8362d', text: 'Delete…', onclick: function () { st.confirm = id; render(); var k = document.querySelector('[data-fk="keep-' + id + '"]'); if (k) k.focus(); } })
        ]));
        /* This range's OWN Save: it sends this range alone (Lane FG3). */
        var saveBox = el('div', { class: 'fg-row', role: 'region', 'aria-label': 'Save this range' });
        var paintSave = function () {
          var dirty = id === 'new' || isDirty(key);
          saveBox.textContent = '';
          saveBox.appendChild(el('span', { class: 'fg-sub', style: 'margin:0', text: dirty ? 'Unsaved changes' : 'Saved' }));
          if (dirty && id !== 'new') saveBox.appendChild(el('button', { type: 'button', class: 'btn ghost sm', text: 'Discard', disabled: st.busy, onclick: function () { discardRange(key); } }));
          saveBox.appendChild(el('button', { type: 'button', class: 'btn sm', 'data-fk': 'save-' + id, text: st.busy && dirty ? 'Saving…' : 'Save range', disabled: st.busy || !dirty, onclick: function () { saveRange(key); } }));
        };
        paintSave();
        live.push({ node: saveBox, fn: paintSave });
        foot.appendChild(saveBox);
      }
    }

    return el('div', { class: 'fg-rg-b', id: 'fg-rg-b-' + id }, [
      el('div', { class: 'fg-ed' }, [
        el('div', { class: 'fg-between', style: 'align-items:flex-end' }, [
          el('div', { class: 'fg-sec' }, [el('span', { class: 'fg-h2', style: 'margin:0', id: 'fg-amt-' + id, text: 'Basket amount' }), el('div', { class: 'fg-amt', role: 'group', 'aria-labelledby': 'fg-amt-' + id }, [el('b', { text: 'AED' }), num('min', 'From (AED)'), el('b', { text: '–' }), maxIn, el('label', { class: 'fg-chk' }, [above, 'and above'])])]),
          sw(x.enabled, x.enabled ? 'On' : 'Off', function () { x.enabled = !x.enabled; this.setAttribute('aria-checked', String(x.enabled)); this.lastChild.textContent = x.enabled ? 'On' : 'Off'; changed(); }, ro)
        ]),
        crit,
        msgs,
        wordsRow,
        el('div', { class: 'fg-g2' }, [date('starts_on', 'Starts (optional)'), date('ends_on', 'Ends (optional)')]),
        el('div', { class: 'fg-sec' }, [
          el('div', { class: 'fg-between' }, [el('span', { class: 'fg-h2', style: 'margin:0', text: 'Gifts' }),
            ro ? null : el('button', { type: 'button', class: 'btn ghost sm', 'data-fk': 'add-' + id, title: 'Each pick goes to its own type box, or you choose one', onclick: function () { openPicker(id, null); } }, [el('span', { html: ICONS.plus, style: 'display:inline-flex;width:15px;height:15px' }), 'Add gift'])]),
          giftList
        ]),
        foot
      ]),
      previewPair(function () { return simulate(x); }, function (lang) {
        if (needsOf(x)) { var w = wordingFor(givenNeeds(simulate(x))); return lang === 'ar' ? w.ar : w.en; }
        return lang === 'ar' ? (x.label_ar || x.label_en) : x.label_en;
      })
    ]);
  }
  /* The criteria (Lane FG4): three steppers, their total, and "Use for
     wording", which fills EN | AR from them on a click and never by itself. */
  function criteriaEl(id, x, ro, words, changed) {
    var sum = el('span', { class: 'fg-crit-sum', 'aria-live': 'polite' });
    var use = el('button', { type: 'button', class: 'btn ghost sm', 'data-fk': 'usewords-' + id, title: 'Fill the English and Arabic wording from these counts', disabled: true, onclick: function () {
      var w = wordingFor(x.needs);
      if (!w.en) return;
      x.label_en = w.en; x.label_ar = w.ar;
      if (words.label_en) words.label_en.value = w.en;
      if (words.label_ar) words.label_ar.value = w.ar;
      changed();
      say('Wording set to "' + w.en + '". "Save range" keeps it.');
    } }, ['Use for wording']);
    function paintSum() {
      var n = needsOf(x), total = n ? KIND_KEYS.reduce(function (a, k) { return a + (Number(n[k]) || 0); }, 0) : 0;
      sum.textContent = '= ' + plural(total, 'gift', 'gifts');
      var w = n ? wordingFor(n) : null;
      use.disabled = ro || !w || (w.en === (x.label_en || '').trim() && w.ar === (x.label_ar || '').trim());
    }
    function step(k) {
      var r = kindRow(k), label = r[1];
      var val = function () { return x.needs ? (Number(x.needs[k]) || 0) : 0; };
      var q = el('input', { type: 'number', min: 0, max: MAX_NEED, value: val(), disabled: ro, inputmode: 'numeric', 'aria-label': label + ' needed', 'data-fk': 'need-' + k + '-' + id });
      var minus = el('button', { type: 'button', text: '−', 'aria-label': 'One fewer ' + r[3] + ' needed', disabled: ro || val() <= 0 });
      var plus = el('button', { type: 'button', text: '+', 'aria-label': 'One more ' + r[3] + ' needed', disabled: ro || val() >= MAX_NEED });
      function set(n) {
        if (!x.needs) x.needs = { mask: 0, mini: 0, full: 0 };
        x.needs[k] = Math.max(0, Math.min(MAX_NEED, Math.floor(Number(n)) || 0));
        q.value = x.needs[k]; minus.disabled = x.needs[k] <= 0; plus.disabled = x.needs[k] >= MAX_NEED;
        changed();
      }
      minus.addEventListener('click', function () { set(val() - 1); });
      plus.addEventListener('click', function () { set(val() + 1); });
      q.addEventListener('change', function () { set(q.value); });
      return el('span', { class: 'fg-crit-i' }, [el('span', { class: 'fg-kd k-' + k, 'aria-hidden': 'true' }), el('span', { text: label }), el('span', { class: 'fg-step' }, [minus, q, plus])]);
    }
    var box = el('div', { class: 'fg-crit', role: 'group', 'aria-label': 'Criteria for this range' }, [
      el('div', { class: 'fg-crit-t' }, ['Criteria', el('small', { text: 'Internal only — shoppers never see the types.' })]),
      step('mask'), step('mini'), step('full'), sum
    ]);
    paintSum();
    live.push({ node: sum, fn: paintSum });
    return box;
  }
  /* The gifts as three tinted boxes in one column, plus Untagged only when
     there is one. Each box counts its UNITS against its criterion. */
  function giftBoxes(id, key, x, ro) {
    var holder = el('div', { class: 'fg-boxes' });
    var undo = st.removed[key];
    var undoKind = undo ? (kindOf(undo.g.kind) || 'none') : null;
    var note = el('div', { class: 'fg-cnote', role: 'status', 'aria-live': 'polite' });
    function paintNote() {
      var gaps = criteriaGaps(needsOf(x), tally(x.gifts));
      note.textContent = '';
      note.hidden = !gaps.length;
      if (!gaps.length) return;
      note.appendChild(el('b', { text: 'Not as the criteria say — a warning only: Save, Testing and Live still work.' }));
      note.appendChild(el('ul', {}, gaps.map(function (g) { return el('li', { text: g.text }); })));
    }
    paintNote();
    live.push({ node: note, fn: paintNote });
    holder.appendChild(note);
    var rows = KINDS.slice();
    var hasNone = x.gifts.some(function (g) { return !kindOf(g.kind); }) || undoKind === 'none';
    if (hasNone) rows.push(['none', 'Untagged', 'Untagged', 'untagged gift', 'untagged gifts', '']);
    rows.forEach(function (k) {
      var kk = k[0];
      var cnt = el('span', { class: 'fg-cnt' });
      function paintCnt() {
        var have = tally(x.gifts)[kk], n = needsOf(x);
        if (kk === 'none' || !n) { cnt.className = 'fg-cnt'; cnt.textContent = plural(have, 'unit', 'units'); cnt.title = kk === 'none' ? 'Not counted until a type is set' : 'No criteria set for this range'; return; }
        var need = Number(n[kk]) || 0;
        cnt.className = 'fg-cnt ' + (have === need ? 'ok' : have < need ? 'short' : 'over');
        cnt.textContent = have + ' of ' + need + (have === need ? ' ✓' : '');
        cnt.title = have === need ? 'Matches the criteria' : have < need ? (need - have) + ' short' : (have - need) + ' over';
      }
      paintCnt();
      live.push({ node: cnt, fn: paintCnt });
      var box = el('section', { class: 'fg-box k-' + kk, 'aria-label': k[1] });
      var h = el('h4', { html: k[5] });
      h.appendChild(el('span', { text: k[1] }));
      box.appendChild(el('div', { class: 'fg-box-h' }, [h, cnt]));
      var any = false, backs = 0;
      x.gifts.forEach(function (g, gi) {
        if ((kindOf(g.kind) || 'none') !== kk || g.role === 'backup') return;
        any = true;
        box.appendChild(giftRow(x, g, gi, ro));
      });
      if (undo && undoKind === kk) box.appendChild(undoRow(key, x));
      if (!any && !(undo && undoKind === kk)) box.appendChild(el('div', { class: 'fg-box-e', text: 'None yet.' }));
      if (!ro && kk !== 'none') box.appendChild(el('button', { type: 'button', class: 'fg-box-add', 'data-fk': 'add-' + kk + '-' + id, 'aria-label': 'Add ' + k[4] + ' to this range', onclick: function () { openPicker(id, kk, 'primary'); } }, [el('span', { html: ICONS.plus, style: 'display:inline-flex' }), 'Add ' + k[4]]));
      /* Backups (the owner, 23 October): used only when the main gifts above
         run out, the one with the most stock first, as many as needed. */
      if (kk !== 'none') {
        var bk = el('div', { class: 'fg-bk' }, [el('div', { class: 'fg-bk-h' }, [el('b', { text: 'Backup' }), el('small', { text: 'Used only if a main gift runs out · most stock first' })])]);
        x.gifts.forEach(function (g, gi) {
          if ((kindOf(g.kind) || 'none') !== kk || g.role !== 'backup') return;
          backs++;
          bk.appendChild(giftRow(x, g, gi, ro));
        });
        if (!backs) bk.appendChild(el('div', { class: 'fg-box-e', text: 'No backup yet.' }));
        if (!ro) bk.appendChild(el('button', { type: 'button', class: 'fg-box-add bk', 'data-fk': 'addbk-' + kk + '-' + id, 'aria-label': 'Add a backup ' + k[3] + ' to this range', onclick: function () { openPicker(id, kk, 'backup'); } }, [el('span', { html: ICONS.plus, style: 'display:inline-flex' }), 'Add backup']));
        box.appendChild(bk);
      }
      holder.appendChild(box);
    });
    var tot = el('div', { class: 'fg-tot', role: 'status' });
    function paintTot() {
      var have = tally(x.gifts), n = needsOf(x), units = have.mask + have.mini + have.full + have.none;
      tot.textContent = '';
      if (!n) { tot.className = 'fg-tot none'; tot.appendChild(el('span', { text: 'Total: ' + plural(units, 'gift', 'gifts') + ' · No criteria set — use the steppers above to have each box counted.' })); return; }
      var need = KIND_KEYS.reduce(function (a, k) { return a + (Number(n[k]) || 0); }, 0), counted = have.mask + have.mini + have.full;
      var bits = [];
      KINDS.forEach(function (k) { var d = have[k[0]] - (Number(n[k[0]]) || 0); if (d) bits.push(k[2] + ': ' + Math.abs(d) + (d < 0 ? ' short' : ' over')); });
      if (have.none) bits.push(have.none + ' untagged');
      tot.className = 'fg-tot ' + (bits.length ? 'short' : 'ok');
      tot.appendChild(el('span', { text: 'Total: ' + counted + ' of ' + plural(need, 'gift', 'gifts') }));
      tot.appendChild(el('span', { text: bits.length ? '· ' + bits.join(' · ') : '· Matches the criteria ✓' }));
    }
    paintTot();
    live.push({ node: tot, fn: paintTot });
    holder.appendChild(tot);
    return holder;
  }
  /* Move a gift to another box: its row, and its product's memory here
     (the server remembers it per product on Save). */
  function kindPicker(x, g, ro, name) {
    var grp = el('span', { class: 'fg-kinds', role: 'radiogroup', 'aria-label': 'Type of ' + name });
    KINDS.forEach(function (k) {
      var on = kindOf(g.kind) === k[0];
      grp.appendChild(el('button', { type: 'button', role: 'radio', class: 'k-' + k[0], 'aria-checked': String(on), 'data-fk': 'k-' + g.product_id + '-' + k[0], disabled: ro, title: on ? 'In ' + k[1] : 'Move to ' + k[1], text: k[2], onclick: function () {
        if (on) return;
        g.kind = k[0];
        if (g.product) g.product.gift_kind = k[0];
        render();
        var f = document.querySelector('[data-fk="k-' + g.product_id + '-' + k[0] + '"]'); if (f) f.focus();
      } }));
    });
    return grp;
  }
  function giftRow(x, g, gi, ro) {
    var bkRow = g.role === 'backup';
    var name = g.product ? g.product.name : 'Product #' + g.product_id, s = giftBadge(g.product, bkRow ? 1 : g.quantity);
    var badge = pill(s.cls, s.text);
    var q = el('input', { type: 'number', min: 1, max: MAX_QTY, value: g.quantity, disabled: ro, inputmode: 'numeric', 'aria-label': 'Quantity of ' + name, 'data-fk': 'q-' + g.product_id });
    var minus = el('button', { type: 'button', text: '−', 'aria-label': 'One fewer ' + name, disabled: ro || g.quantity <= 1 });
    var plus = el('button', { type: 'button', text: '+', 'aria-label': 'One more ' + name, disabled: ro || g.quantity >= MAX_QTY });
    function set(n) {
      g.quantity = Math.max(1, Math.min(MAX_QTY, Math.floor(Number(n)) || 1));
      q.value = g.quantity; minus.disabled = g.quantity <= 1; plus.disabled = g.quantity >= MAX_QTY;
      var s2 = giftBadge(g.product, g.quantity); badge.className = 'pill ' + s2.cls; badge.textContent = s2.text;
      row.className = 'fg-gi' + (s2.level === 'in' ? '' : ' ' + s2.level);
      repaint();
    }
    minus.addEventListener('click', function () { set(g.quantity - 1); });
    plus.addEventListener('click', function () { set(g.quantity + 1); });
    q.addEventListener('change', function () { set(q.value); });
    var row = el('div', { class: 'fg-gi' + (s.level === 'in' ? '' : ' ' + s.level) }, [
      thumb(g.product),
      el('div', { class: 'fg-gi-n' }, [g.product && g.product.brand ? el('small', { text: g.product.brand }) : null, el('b', { text: name }), badge, el('div', { class: 'fg-row', style: 'gap:6px;flex-wrap:wrap' }, [kindPicker(x, g, ro, name), kindOf(g.kind) ? roleSwitch(g, ro, name) : null])]),
      bkRow ? el('span', { class: 'fg-bk-q', text: 'As needed' }) : el('div', { class: 'fg-step' }, [minus, q, plus]),
      ro ? null : el('button', { type: 'button', class: 'fg-btn-i danger', 'aria-label': 'Remove ' + name, title: 'Remove', html: ICONS.trash, onclick: function () {
        var key = Object.keys(st.drafts).filter(function (k) { return st.drafts[k] === x; })[0];
        st.removed[key] = { g: x.gifts.splice(gi, 1)[0], at: gi };
        render();
        var u = document.querySelector('[data-fk="undo-' + key + '"]'); if (u) u.focus();
      } })
    ]);
    return row;
  }
  /* Main or backup, one tap; the row moves to its section at once. */
  function roleSwitch(g, ro, name) {
    var grp = el('span', { class: 'fg-roles', role: 'radiogroup', 'aria-label': 'Main or backup: ' + name });
    [['primary', 'Main'], ['backup', 'Backup']].forEach(function (r) {
      var on = (g.role === 'backup' ? 'backup' : 'primary') === r[0];
      grp.appendChild(el('button', { type: 'button', role: 'radio', 'aria-checked': String(on), disabled: ro, 'data-fk': 'role-' + g.product_id + '-' + r[0], text: r[1], onclick: function () {
        if (on) return;
        g.role = r[0];
        render();
        var f = document.querySelector('[data-fk="role-' + g.product_id + '-' + r[0] + '"]'); if (f) f.focus();
      } }));
    });
    return grp;
  }
  function undoRow(key, x) {
    var u = st.removed[key];
    return el('div', { class: 'fg-undo', role: 'status' }, [el('span', { text: 'Removed ' + (u.g.product ? u.g.product.name : 'a gift') + '.' }),
      el('button', { type: 'button', class: 'fg-link', text: 'Undo', 'data-fk': 'undo-' + key, onclick: function () {
        if (!x.gifts.some(function (g) { return g.product_id === u.g.product_id; })) x.gifts.splice(Math.min(u.at, x.gifts.length), 0, u.g);
        delete st.removed[key]; render();
      } })]);
  }

  /* ---------------------------------------------------------------- picker */
  function pickerTarget() { var p = st.picker; return p ? (p.range === 'new' ? st.drafts['new'] : draft(p.range)) : null; }
  /* `box` (Lane FG4): the box the picker was opened from, or null. A pick
     goes to its product's remembered type, else this box, else the row asks. */
  function openPicker(id, box, role) {
    st.picker = { range: id, box: kindOf(box), role: role === 'backup' ? 'backup' : 'primary', kind: 'all', asking: null, from: box ? (role === 'backup' ? 'addbk-' : 'add-') + box + '-' + id : 'add-' + id, q: '', filter: 'all', rows: null, initial: null, loading: false, seq: 0, timer: null, ctl: null, err: null, added: 0 };
    delete st.removed[String(id)];
    render();
    var f = document.querySelector('[data-fk="pk-q"]'); if (f) f.focus();
    fetchPicker('');
  }
  function closePicker(silent) {
    var p = st.picker;
    if (!p) return;
    var x = pickerTarget();
    clearTimeout(p.timer); stopSearch(p);
    st.picker = null;
    if (silent) return;
    render();
    var b = document.querySelector('[data-fk="' + p.from + '"]'); if (b) b.focus();
    if (p.added > 0 && x) say(plural(p.added, 'gift', 'gifts') + ' added to ' + (p.range === 'new' ? 'the new range' : amount(x.min, x.max)) + '. "Save range" keeps them.');
  }
  /* INSTANT SEARCH, the only search path on this screen (Lane FG3): results
     come while typing -- one request per 300 ms of typing at most, none under
     2 characters, nothing on Enter, no Search button. Each new question
     aborts the one in flight (AbortController) and the seq check drops any
     answer that is not the latest, so a slower earlier answer can never
     overwrite a newer one. */
  function stopSearch(p) { p.seq++; if (p.ctl) { try { p.ctl.abort(); } catch (e) {} p.ctl = null; } p.loading = false; }
  function typed(q) {
    var p = st.picker; if (!p) return;
    p.q = q; clearTimeout(p.timer);
    if (q.trim().length === 0) { stopSearch(p); p.rows = p.initial; p.err = null; paintResults(); return; }
    if (q.trim().length < 2) { stopSearch(p); p.rows = null; p.err = 'Type at least 2 letters to search.'; paintResults(); return; }
    p.loading = true; p.err = null; paintResults();
    p.timer = setTimeout(function () { fetchPicker(q.trim()); }, 300);
  }
  function fetchPicker(q) {
    var p = st.picker; if (!p) return;
    stopSearch(p);
    var mine = p.seq, ctl = typeof AbortController === 'function' ? new AbortController() : null;
    p.ctl = ctl; p.loading = true; p.err = null; paintResults();
    api('GET', '/products?q=' + encodeURIComponent(q) + '&filter=' + encodeURIComponent(p.filter) + '&kind=' + encodeURIComponent(p.kind), undefined, ctl ? ctl.signal : undefined).then(function (d) {
      if (st.picker !== p || mine !== p.seq) return;
      p.ctl = null; p.rows = d.products; if (q === '') p.initial = d.products; p.loading = false; paintResults();
    }).catch(function (e) {
      if (st.picker !== p || mine !== p.seq || (e && e.name === 'AbortError')) return;
      p.ctl = null; p.loading = false; p.err = explain(e, 'Search failed.'); paintResults();
    });
  }
  var resultsNode = null, pkFootNode = null, pkStNode = null;
  function paintResults() {
    if (!ours() || !resultsNode || !resultsNode.isConnected) return;
    var p = st.picker, x = pickerTarget();
    if (!p || !x) return;
    /* Repainting removes the button that was just pressed; give focus back to
       its replacement, or Escape and Tab would start from the page body. */
    var had = document.activeElement && resultsNode.contains(document.activeElement) ? document.activeElement.getAttribute('data-fk') : null;
    resultsNode.textContent = '';
    resultsNode.setAttribute('aria-busy', String(p.loading));
    if (pkStNode && !(had && had.indexOf('ask-') === 0)) pkStNode.textContent = p.loading ? 'Searching…' : (p.rows && p.q.trim().length >= 2 ? plural(p.rows.length, 'match', 'matches') + (p.rows.length >= 20 ? ' (first 20; keep typing to narrow)' : '') : '');
    if (p.err) resultsNode.appendChild(el('p', { class: 'fg-sub', text: p.err }));
    else if (!p.loading && p.rows && !p.rows.length) resultsNode.appendChild(el('div', { class: 'fg-empty', text: p.filter === 'used' && p.q.trim().length < 2 ? 'No product has been used as a gift yet. Pick one with the search, and it appears here from then on.' : 'No product matches' + (p.filter !== 'all' || p.kind !== 'all' ? ' this filter' : '') + '.' }));
    (p.err ? [] : (p.rows || [])).forEach(function (pr) {
      var mine = x.gifts.filter(function (g) { return g.product_id === pr.id; })[0], taken = !!mine, bad = pr.type === 'variable', s = stockOf(pr);
      var into = taken ? kindRow(kindOf(mine.kind)) : null;
      resultsNode.appendChild(el('button', { type: 'button', class: 'fg-res', 'data-fk': 'res-' + pr.id, 'aria-pressed': String(taken), disabled: bad, title: bad ? 'Has shades or sizes: pick a simple product' : null, onclick: function () {
        var i = x.gifts.indexOf(mine);
        if (i >= 0) { x.gifts.splice(i, 1); p.added--; p.asking = null; paintResults(); repaint(); return; }
        var kind = kindOf(pr.gift_kind) || p.box;
        if (!kind) { p.asking = p.asking === pr.id ? null : pr.id; paintResults(); var a = resultsNode.querySelector('[data-fk="ask-' + pr.id + '-mask"]'); if (a) a.focus(); return; }
        pick(pr, kind);
      } }, [
        thumb(pr),
        el('span', {}, [pr.brand ? el('small', { text: pr.brand }) : null, el('b', { text: pr.name }), el('span', { class: 'fg-meta' }, [pr.gift_only ? pill('blue', 'Gift only') : el('span', { text: pr.price }), pill(s.cls, s.text), kindPill(kindOf(pr.gift_kind))])]),
        el('span', { class: 'fg-state-b', text: bad ? 'Has options' : taken ? '✓ Added' + (into ? ' · ' + into[2] : '') : '+ Add' })
      ]));
      /* No remembered type and no box to go to: a tiny chooser, right here. */
      if (p.asking === pr.id && !taken) resultsNode.appendChild(el('div', { class: 'fg-ask', role: 'group', 'aria-label': 'Which box for ' + pr.name }, [el('span', { text: 'Which box?' })].concat(KINDS.map(function (k) {
        return el('button', { type: 'button', class: 'k-' + k[0], 'data-fk': 'ask-' + pr.id + '-' + k[0], text: k[1], onclick: function () { pick(pr, k[0]); } });
      }), [el('button', { type: 'button', class: 'k-none', 'data-fk': 'ask-' + pr.id + '-none', text: 'Untagged', onclick: function () { pick(pr, null); } })])));
    });
    function pick(pr, kind) {
      if (kind && !pr.gift_kind) pr.gift_kind = kind;
      x.gifts.push({ product_id: pr.id, quantity: 1, product: pr, kind: kind, role: kind && p.role === 'backup' ? 'backup' : 'primary' });
      p.added++; p.asking = null;
      var r = kindRow(kind);
      paintResults(); repaint();
      if (pkStNode) pkStNode.textContent = 'Added "' + pr.name + '" to ' + (r ? r[1] : 'Untagged') + (kind && p.role === 'backup' ? ' · Backup' : '') + '.';
      var back = resultsNode.querySelector('[data-fk="res-' + pr.id + '"]'); if (back) back.focus();
    }
    if (had) { var back = resultsNode.querySelector('[data-fk="' + had + '"]'); if (back) back.focus(); }
    if (pkFootNode) {
      var t = tally(x.gifts), n = needsOf(x);
      pkFootNode.textContent = KINDS.map(function (k) { return k[2] + ' ' + t[k[0]] + (n ? '/' + (Number(n[k[0]]) || 0) : ''); }).join(' · ') + (t.none ? ' · Untagged ' + t.none : '');
    }
  }
  function pickerModal() {
    var p = st.picker, x = pickerTarget();
    var q = el('input', { class: 'fg-in', type: 'search', value: p.q, placeholder: 'Search by name or SKU', 'aria-label': 'Search products', autocomplete: 'off', 'data-fk': 'pk-q' });
    q.addEventListener('input', function () { typed(q.value); });
    /* Enter submits nothing and reloads nothing: the results are already coming. */
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    var seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Show' }, [['all', 'All'], ['gift', 'Gift-only'], ['instock', 'In stock']].map(function (f) {
      return el('button', { type: 'button', class: p.filter === f[0] ? 'on' : '', 'aria-pressed': String(p.filter === f[0]), text: f[1], onclick: function () {
        if (p.filter === f[0]) return;
        p.filter = f[0]; p.initial = null;
        if (usedBtn) { usedBtn.className = 'fg-usedb'; usedBtn.setAttribute('aria-pressed', 'false'); }
        seg.querySelectorAll('button').forEach(function (b) { var on = b.textContent === f[1]; b.className = on ? 'on' : ''; b.setAttribute('aria-pressed', String(on)); });
        clearTimeout(p.timer); fetchPicker(p.q.trim().length >= 2 ? p.q.trim() : '');
      } });
    }));
    /* "Products used in gifts" (the owner, 22 Oct): one tap lists every product
       used as a gift in any range, so a gift is picked again without searching.
       Typing still searches -- inside that list. Tap again for everything. */
    var usedBtn = el('button', { type: 'button', class: 'fg-usedb' + (p.filter === 'used' ? ' on' : ''), 'aria-pressed': String(p.filter === 'used'), 'data-fk': 'pk-used', text: '★ Products used in gifts', onclick: function () {
      p.filter = p.filter === 'used' ? 'all' : 'used'; p.initial = null;
      usedBtn.className = 'fg-usedb' + (p.filter === 'used' ? ' on' : ''); usedBtn.setAttribute('aria-pressed', String(p.filter === 'used'));
      seg.querySelectorAll('button').forEach(function (b) { var on = p.filter === 'all' && b.textContent === 'All'; b.className = on ? 'on' : ''; b.setAttribute('aria-pressed', String(on)); });
      clearTimeout(p.timer); fetchPicker(p.q.trim().length >= 2 ? p.q.trim() : '');
    } });
    resultsNode = el('div', { class: 'fg-res-l', 'aria-live': 'polite' });
    pkFootNode = el('span', { class: 'fg-sub', style: 'margin:0' });
    pkStNode = el('div', { class: 'fg-pk-st', role: 'status', 'aria-live': 'polite', 'data-fk': 'pk-st' });
    var boxRow = kindRow(p.box);
    var title = 'Add ' + (boxRow ? boxRow[4] : 'gifts') + ' to ' + (x ? (st.picker.range === 'new' ? 'the new range' : amount(x.min, x.max)) : 'the range');
    /* Where a pick with no remembered type goes, and the type filter (Lane FG4). */
    var into = el('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'New picks go to' }, KINDS.map(function (k) { return [k[0], k[1]]; }).concat([[null, 'Ask me']]).map(function (o) {
      return el('button', { type: 'button', role: 'radio', class: p.box === o[0] ? 'on' : '', 'aria-checked': String(p.box === o[0]), 'data-fk': 'pk-into-' + (o[0] || 'ask'), text: o[1], onclick: function () {
        p.box = o[0]; p.asking = null;
        into.querySelectorAll('button').forEach(function (b) { var on = b.textContent === o[1]; b.className = on ? 'on' : ''; b.setAttribute('aria-checked', String(on)); });
        paintResults();
      } });
    }));
    var kseg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Type' }, [['all', 'All'], ['mask', 'Masks'], ['mini', 'Minis'], ['full', 'Full size'], ['none', 'Untagged']].map(function (f) {
      return el('button', { type: 'button', class: p.kind === f[0] ? 'on' : '', 'aria-pressed': String(p.kind === f[0]), 'data-fk': 'pk-kind-' + f[0], text: f[1], onclick: function () {
        if (p.kind === f[0]) return;
        p.kind = f[0]; p.initial = null;
        kseg.querySelectorAll('button').forEach(function (b) { var on = b.textContent === f[1]; b.className = on ? 'on' : ''; b.setAttribute('aria-pressed', String(on)); });
        clearTimeout(p.timer); fetchPicker(p.q.trim().length >= 2 ? p.q.trim() : '');
      } });
    }));
    var dlg = el('div', { class: 'modal fg-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'fg-pk-t' }, [
      el('div', { class: 'modal-h' }, [el('b', { id: 'fg-pk-t', text: title }), el('button', { type: 'button', class: 'x fg-btn-i', 'aria-label': 'Close', html: ICONS.x, onclick: function () { closePicker(); } })]),
      el('div', { class: 'fg-pk-top' }, [q, el('div', { class: 'fg-pk-rows' }, [
        el('div', { class: 'fg-row' }, [el('span', { class: 'fg-pk-lab', text: 'New picks go to' }), into]),
        el('div', { class: 'fg-row' }, [el('span', { class: 'fg-pk-lab', text: 'Type' }), kseg]),
        el('div', { class: 'fg-row' }, [el('span', { class: 'fg-pk-lab', text: 'Show' }), seg])
      ]), el('div', { class: 'fg-pk-tip' }, [el('p', { class: 'fg-sub', style: 'margin:0', text: 'A product tagged before goes to its own box. Types are internal only.' }), usedBtn]), pkStNode]),
      el('div', { class: 'modal-b' }, [resultsNode]),
      el('div', { class: 'fg-pk-foot' }, [pkFootNode, el('button', { type: 'button', class: 'btn sm', text: 'Done', onclick: function () { closePicker(); } })])
    ]);
    var bg = el('div', { class: 'modal-bg on', onclick: function (e) { if (e.target === bg) closePicker(); } }, [dlg]);
    bg.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); closePicker(); return; }
      if (e.key !== 'Tab') return;
      var f = dlg.querySelectorAll('button:not([disabled]),input'); if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    });
    return bg;
  }

  /* -------------------------------------------------------------- products */
  function renderProducts(wrap) {
    var d = st.data, byId = {};
    d.ranges.forEach(function (r) { byId[r.id] = r; });
    var head = el('div', { class: 'fg-between' }, [
      el('div', {}, [el('h3', { class: 'fg-h', text: 'Gift products' }), el('p', { class: 'fg-sub', text: '"Gift only" hides a product from the shop, listings, search, the sitemap, feeds and /api, and it cannot be added to the bag; it can still be a gift. Switching it off puts the product back on sale. Low stock: ' + low() + ' units or fewer.' })]),
      can() && !st.create.open ? el('button', { type: 'button', class: 'btn sm', 'data-fk': 'new-gift', onclick: function () { st.create.open = true; st.create.err = null; render(); loadBrands(); var f = document.querySelector('[data-fk="c-name"]'); if (f) f.focus(); } }, [el('span', { html: ICONS.plus, style: 'display:inline-flex;width:16px;height:16px' }), 'New gift-only product']) : null
    ]);
    var kids = [head];
    if (st.create.made) kids.push(el('div', { class: 'fg-msg ok', role: 'status', style: 'margin-top:10px' }, [el('i', { text: '✓' }), el('span', { text: 'Created "' + st.create.made.name + '". Pick it in a range with "Add gift", or ' }), typeof window.peoEdit === 'function' ? el('button', { type: 'button', class: 'fg-link', text: 'edit it in the product editor', onclick: function () { window.peoEdit(st.create.made.id); } }) : null]));
    if (st.create.open && can()) kids.push(el('div', { style: 'margin-top:14px' }, [createForm()]));
    var rows = [el('div', { class: 'fg-pr hd', 'aria-hidden': 'true' }, [el('span'), el('span', { text: 'Product' }), el('span', { text: 'Brand' }), el('span', { text: 'Used in' }), el('span', { text: 'Gift only' }), el('span')])];
    d.gift_products.forEach(function (p) {
      var s = stockOf(p);
      rows.push(el('div', { class: 'fg-pr', role: 'listitem' }, [
        thumb(p),
        el('div', {}, [el('b', { text: p.name }), el('span', { class: 'fg-row', style: 'gap:6px;margin-top:3px' }, [pill(s.cls, s.text), kindPill(kindOf(p.gift_kind))])]),
        el('small', { class: 'c-brand', text: p.brand || '—' }),
        el('div', { class: 'c-used fg-pills' }, p.used_in.length ? p.used_in.slice().sort(function (a, b) { return ((byId[a] || {}).min || 0) - ((byId[b] || {}).min || 0); }).map(function (id) { var r = byId[id]; return r ? el('button', { type: 'button', class: 'pill grey', style: 'border:0;cursor:pointer;font:inherit;font-size:11px;font-weight:600', text: r.label, title: 'Open this range', onclick: function () { goRange(id); } }) : null; }) : [el('small', { text: 'Not in a range' })]),
        el('div', { class: 'c-gift' }, [sw(p.gift_only, p.gift_only ? 'Gift only' : 'On sale', function () {
          var to = !p.gift_only;
          run(function () { return api('POST', '/products/' + p.id + '/gift-only', { on: to }); }, 'Not changed.', function () { say('"' + p.name + '" is ' + (to ? 'gift only: hidden from the shop.' : 'back on sale.')); });
        }, !can() || st.busy)]),
        el('div', { class: 'c-edit' }, [typeof window.peoEdit === 'function' ? el('button', { type: 'button', class: 'btn ghost sm', text: 'Edit', 'aria-label': 'Edit ' + p.name + ' in the product editor', onclick: function () { window.peoEdit(p.id); } }) : null])
      ]));
    });
    if (!d.gift_products.length) rows.push(el('div', { class: 'fg-empty', style: 'margin-top:8px', text: 'No gift products yet.' }));
    kids.push(el('div', { class: 'fg-pt', role: 'list', 'aria-label': 'Gift products', style: 'margin-top:14px' }, rows));
    wrap.appendChild(el('section', { class: 'card fg-card' }, kids));
  }
  function loadBrands() {
    if (st.create.brands) return;
    api('GET', '/brands').then(function (d) { st.create.brands = d.brands; if (st.tab === 'products' && st.create.open) { var s = document.querySelector('[data-fk="c-brand"]'); if (s && ours()) fillBrands(s); } }).catch(function () { st.create.brands = []; });
  }
  function fillBrands(s) {
    var c = st.create;
    s.textContent = '';
    s.appendChild(el('option', { value: '', text: c.brands ? 'No brand' : 'Loading brands…' }));
    (c.brands || []).forEach(function (b) { s.appendChild(el('option', { value: String(b.id), text: b.name, selected: String(b.id) === c.brand_id })); });
  }
  function createForm() {
    var c = st.create;
    function inp(prop, type, extra) {
      var i = el('input', Object.assign({ class: 'fg-in', type: type, value: c[prop], 'data-fk': 'c-' + prop }, extra || {}));
      i.addEventListener('input', function () { c[prop] = i.value; });
      return i;
    }
    var brand = el('select', { class: 'fg-in', 'data-fk': 'c-brand' });
    fillBrands(brand);
    brand.addEventListener('change', function () { c.brand_id = brand.value; });
    var file = el('input', { type: 'file', accept: 'image/*', 'aria-label': 'Photo', style: 'max-width:100%;font-size:12px' });
    var photoStatus = el('span', { class: 'fg-sub', style: 'margin:0' });
    var photo = el('div', { class: 'fg-photo' }, [c.thumb ? el('img', { class: 'fg-th', src: c.thumb, alt: '' }) : el('span', { class: 'fg-th fg-ph', 'aria-hidden': 'true', text: 'IMG' }), file, photoStatus,
      c.image ? el('button', { type: 'button', class: 'fg-link', text: 'Remove photo', onclick: function () { c.image = ''; c.thumb = ''; render(); } }) : null]);
    file.addEventListener('change', function () {
      var f = file.files && file.files[0]; if (!f) return;
      var fd = new FormData(); fd.append('file', f); fd.append('folder', 'products');
      photoStatus.textContent = 'Uploading…';
      api('POST', '!/admin-api/media/upload', fd).then(function (res) {
        c.image = res.url || ''; c.thumb = res.url || '';
        if (ours() && st.tab === 'products') render();
      }).catch(function (e) { photoStatus.textContent = e && e.status === 403 ? 'Your role cannot upload pictures; add the photo in the product editor afterwards.' : explain(e, 'Upload failed.'); });
    });
    return el('div', { class: 'fg-create', role: 'group', 'aria-label': 'New gift-only product' }, [
      el('div', { class: 'fg-between' }, [el('h4', { class: 'fg-h2', style: 'margin:0', text: 'New gift-only product' }), el('button', { type: 'button', class: 'fg-btn-i', 'aria-label': 'Close the form', html: ICONS.x, onclick: function () { c.open = false; render(); } })]),
      el('div', { class: 'fg-g2' }, [field('Name', inp('name', 'text', { maxlength: 160, placeholder: 'e.g. Mugwort Calming Clay Mask Mini', required: true })), field('Brand', brand)]),
      el('div', { class: 'fg-field' }, [el('span', { text: 'Photo' }), photo]),
      el('div', { class: 'fg-g2' }, [
        field('Stock', inp('stock', 'number', { min: 0, step: 1, inputmode: 'numeric', placeholder: 'Empty: not counted' }), 'Gifts are skipped on the shop when this reaches 0.'),
        field('Price (AED)', inp('price', 'number', { min: 0, step: '0.01', inputmode: 'decimal', placeholder: '0.00' }), 'Shown struck through only if Display → "Show gift prices" is on.')
      ]),
      c.err ? el('div', { class: 'fg-msg err', role: 'alert' }, [el('i', { text: '!' }), el('span', { text: c.err })]) : null,
      el('div', { class: 'fg-row' }, [
        el('button', { type: 'button', class: 'btn sm', text: c.busy ? 'Creating…' : 'Create gift-only product', disabled: c.busy, onclick: function () {
          if (c.name.trim().length < 2) { c.err = 'Give it a name (2 letters or more).'; render(); var f = document.querySelector('[data-fk="c-name"]'); if (f) f.focus(); return; }
          c.busy = true; c.err = null; render();
          api('POST', '/products', { name: c.name.trim(), brand_id: c.brand_id ? Number(c.brand_id) : null, stock: c.stock === '' ? null : Number(c.stock), price: c.price === '' ? null : Number(c.price), image: c.image || null }).then(function (d) {
            st.data = d;
            st.create = { open: false, name: '', brand_id: '', stock: '', price: '', image: '', thumb: '', brands: c.brands, busy: false, err: null, made: d.created };
            say('Created "' + d.created.name + '" as a gift-only product.');
            render();
          }).catch(function (e) { c.busy = false; c.err = explain(e, 'Not created.'); render(); });
        } }),
        el('button', { type: 'button', class: 'btn ghost sm', text: 'Cancel', onclick: function () { c.open = false; render(); } })
      ])
    ]);
  }

  /* --------------------------------------------------------------- display */
  function renderDisplay(wrap) {
    var ro = !can(), c = cfg();
    function sel(prop, opts, label) {
      var s = el('select', { class: 'fg-in', disabled: ro, 'data-fk': 'd-' + prop }, opts.map(function (o) { return el('option', { value: o[0], text: o[1], selected: c[prop] === o[0] }); }));
      s.addEventListener('change', function () { editCfg()[prop] = s.value; c = cfg(); repaint(); });
      return field(label, s);
    }
    function t(key, lang, label) {
      var i = el('input', { class: 'fg-in', type: 'text', value: c.texts[key + '_' + lang], dir: lang === 'ar' ? 'rtl' : null, lang: lang, disabled: ro, maxlength: 160, 'aria-label': label + (lang === 'ar' ? ' (Arabic)' : ' (English)'), 'data-fk': 't-' + key + lang });
      i.addEventListener('input', function () {
        editCfg().texts[key + '_' + lang] = i.value; c = cfg();
        var bad = (key === 'line' && i.value.indexOf(':count') < 0) || !i.value.trim();
        i.classList.toggle('bad', bad);
        repaint();
      });
      return i;
    }
    /* "Gift box look" (Lane FG5). Every change goes into the Display draft
       through lookOf() -- the server's own normalising -- and repaints the
       preview; nothing is sent until Save. */
    function lookCard() {
      var lk = lookOf(c.look);
      function set(k, v) { var e = editCfg(), n = lookOf(e.look); n[k] = v; e.look = lookOf(n); c = cfg(); repaint(); }
      function num(k, label, help) {
        var r = LOOK_RANGES[k];
        var i = el('input', { class: 'fg-in', type: 'number', min: r[0], max: r[1], step: 1, inputmode: 'numeric', value: String(lk[k]), disabled: ro, 'data-fk': 'lk-' + k, 'aria-label': label });
        i.addEventListener('input', function () { if (i.value.trim() !== '' && isFinite(Number(i.value))) set(k, Number(i.value)); });
        i.addEventListener('change', function () { i.value = String(lookOf(cfg().look)[k]); });
        return field(label + ' (' + r[0] + '–' + r[1] + ' px)', i, help);
      }
      var titles = el('select', { class: 'fg-in', disabled: ro, 'data-fk': 'lk-titles' }, [['clamp2', 'Up to 2 lines, then …'], ['fade', 'One line, fades at the end'], ['wrap', 'Wrap fully']].map(function (o) {
        return el('option', { value: o[0], text: o[1], selected: lk.titles === o[0] });
      }));
      titles.addEventListener('change', function () { set('titles', titles.value); });
      return card('Gift box look', 'How each gift reads in the opened list. The preview follows every change.', [
        el('div', { class: 'fg-row', style: 'margin-top:12px;gap:18px;flex-wrap:wrap' }, [
          sw(lk.brand, 'Show brand names', function () { var v = !lookOf(cfg().look).brand; set('brand', v); this.setAttribute('aria-checked', String(v)); }, ro),
          sw(lk.separator, 'Separator line between gifts', function () { var v = !lookOf(cfg().look).separator; set('separator', v); this.setAttribute('aria-checked', String(v)); }, ro)
        ]),
        el('div', { class: 'fg-look' }, [
          field('Long titles', titles, 'Up to 2 lines keeps the box short. "Fades" keeps one line and fades its end: the left end for an Arabic name in Arabic; a name in the other language ends in "…".'),
          num('free_gap', 'Space before FREE', 'A long title never runs into FREE. 16 as asked; it was 8.'),
          num('title_px', 'Title font size', 'The gift\'s name. 12 is the shop\'s size today.'),
          num('row_gap', 'Space between gifts', '3 today. With the separator on, the line sits in the middle of it.'),
          num('photo_list', 'Photo size, opened list', '28 today.'),
          num('photo_row', 'Photo size, closed row', '36 today: the row of photos before "View all".')
        ])
      ]);
    }
    var left = el('div', { class: 'fg-wrap', style: 'padding:0' }, [
      card('What counts and where', null, [el('div', { class: 'fg-g2', style: 'margin-top:12px' }, [
        sel('basis', [['after_discounts', 'Products after the coupon (as asked)'], ['before_discounts', 'Products before the coupon']], 'What counts toward a range'),
        sel('countries', [['all', 'Every country the shop delivers to'], ['AE', 'United Arab Emirates only']], 'Countries')
      ]), el('p', { class: 'fg-sub', text: 'Delivery, gift wrapping and the gifts themselves never count.' }),
        el('div', { class: 'fg-row', style: 'margin-top:14px' }, [sw(c.show_value, 'Show gift prices', function () { var e = editCfg(); e.show_value = !e.show_value; c = cfg(); this.setAttribute('aria-checked', String(e.show_value)); repaint(); }, ro)]),
        el('p', { class: 'fg-sub', text: 'Off, as asked. On, each gift in the opened list shows its price struck through before "FREE".' })]),
      lookCard(),
      card('Texts', 'English and Arabic side by side. The preview follows as you type.', [el('div', { class: 'fg-tx', style: 'margin-top:8px' }, [el('div', { class: 'fg-txr hd', 'aria-hidden': 'true' }, [el('span', { text: 'Where' }), el('span', { text: 'English' }), el('span', { text: 'Arabic' })])].concat(
        [].concat.apply([], TEXT_GROUPS.map(function (g) {
          return [el('div', { class: 'fg-grp', text: g[0] })].concat(g[1].map(function (k) { return el('div', { class: 'fg-txr' }, [el('span', {}, [k[1], k[2] ? el('small', { text: k[2] }) : null]), t(k[0], 'en', k[1]), t(k[0], 'ar', k[1])]); }));
        }))
      ))].concat(st.errors.display ? [el('div', { class: 'fg-msg err', role: 'alert', style: 'margin-top:10px' }, [el('i', { text: '!' }), el('span', { text: st.errors.display })])] : [], [cfgSaveRow('display')]))
    ]);
    var list = ranges().filter(function (r) { return r.gifts.length; });
    if (st.pvRange == null || !list.some(function (r) { return r.id === st.pvRange; })) st.pvRange = list.length ? list[Math.min(2, list.length - 1)].id : null;
    var pick = el('select', { class: 'fg-in', 'aria-label': 'Preview with the range', 'data-fk': 'pv-range' }, list.map(function (r) { return el('option', { value: String(r.id), text: r.label + ' · ' + r.label_en, selected: r.id === st.pvRange }); }));
    pick.addEventListener('change', function () { st.pvRange = Number(pick.value); repaint(); });
    var target = function () { return rangeById(st.pvRange); };
    var right = card(null, null, [list.length ? pick : el('p', { class: 'fg-sub', text: 'Add gifts to a range to see the box.' }),
      list.length ? previewPair(function () { var r = target(); return r ? view(r).gifts : []; }, function (lang) { var r = target(); if (!r) return ''; var v = view(r); return lang === 'ar' ? (v.label_ar || v.label_en) : v.label_en; }) : null], 'fg-pvcol');
    right.style.display = 'grid'; right.style.gap = '10px';
    wrap.appendChild(el('div', { class: 'fg-disp' }, [left, right]));
  }

  /* ------------------------------------------------------------------ home */
  function renderHome(wrap) {
    var ro = !can(), h = cfg().home;
    function t(key, lang, label) {
      var i = el('input', { class: 'fg-in', type: 'text', value: h[key + '_' + lang], dir: lang === 'ar' ? 'rtl' : null, lang: lang, disabled: ro, maxlength: 160, 'aria-label': label + (lang === 'ar' ? ' (Arabic)' : ' (English)'), 'data-fk': 'h-' + key + lang });
      i.addEventListener('input', function () { editCfg().home[key + '_' + lang] = i.value; repaint(); });
      return i;
    }
    /* (Lane FGH) The background: a style, three colours and how strong they
       are. The swatch is the section's own gradient, washed the way the server
       washes it (FreeGifts::washed), so what is seen here is what ships. */
    var BG = [['flow', 'Moving gradient — the colours drift slowly'], ['still', 'Still gradient'], ['white', 'Plain white'], ['none', 'None — the page’s own background']];
    function wash(c, t) { return '#' + [1, 3, 5].map(function (i) { var v = Math.round(255 - (255 - parseInt(c.substr(i, 2), 16)) * t / 100); return ('0' + v.toString(16)).slice(-2); }).join(''); }
    function swatchCss() { var b = cfg().home, t = b.strength; return b.bg === 'white' ? 'background:#fff' : b.bg === 'none' ? 'background:repeating-conic-gradient(#f3f3f3 0 25%,#fff 0 50%) 0 0/16px 16px' : 'background:linear-gradient(115deg,' + [b.c1, b.c2, b.c3, b.c1].map(function (c) { return wash(c, t); }).join(',') + ')'; }
    var swatch = el('div', { 'aria-hidden': 'true', style: 'height:56px;border-radius:12px;border:1px solid var(--line,#e5e7eb);margin-top:10px;' + swatchCss() });
    function paintSwatch() { swatch.setAttribute('style', 'height:56px;border-radius:12px;border:1px solid var(--line,#e5e7eb);margin-top:10px;' + swatchCss()); }
    var bgSel = el('select', { class: 'fg-in', 'aria-label': 'Background', 'data-fk': 'h-bg', disabled: ro }, BG.map(function (o) { var x = el('option', { value: o[0], text: o[1] }); if (h.bg === o[0]) x.selected = true; return x; }));
    bgSel.addEventListener('change', function () { editCfg().home.bg = bgSel.value; paintSwatch(); repaint(); });
    function colour(key, label) {
      var i = el('input', { type: 'color', value: h[key], 'aria-label': label, 'data-fk': 'h-' + key, disabled: ro, style: 'width:44px;height:36px;padding:0;border:1px solid var(--line,#e5e7eb);border-radius:8px;background:none' });
      i.addEventListener('input', function () { editCfg().home[key] = i.value.toUpperCase(); paintSwatch(); repaint(); });
      return el('label', { style: 'display:inline-flex;align-items:center;gap:6px;font-size:12.5px' }, [i, label]);
    }
    var str = el('input', { type: 'range', min: 5, max: 60, step: 1, value: h.strength, 'aria-label': 'Strength', 'data-fk': 'h-strength', disabled: ro });
    var strV = el('b', { text: h.strength + '%' });
    str.addEventListener('input', function () { editCfg().home.strength = +str.value; strV.textContent = str.value + '%'; paintSwatch(); repaint(); });
    /* (Lane FGH) Width on a computer, as a share of the site width; tablets and phones are always full. */
    var wid = el('input', { type: 'range', min: 40, max: 100, step: 5, value: h.width, 'aria-label': 'Width on a computer', 'data-fk': 'h-width', disabled: ro });
    var widV = el('b', { text: h.width + '%' });
    wid.addEventListener('input', function () { editCfg().home.width = +wid.value; widV.textContent = wid.value + '%'; repaint(); });
    wrap.appendChild(card('Homepage section', 'Shows on the homepage under Shop by Concern while this is on and a range is published. Move it up or down, or hide it on desktop or mobile, in Appearance → Homepage (the “Free gifts” row).', [
      el('div', { class: 'fg-row', style: 'margin-top:14px' }, [sw(h.on, 'Show on the homepage', function () { var e = editCfg(); e.home.on = !e.home.on; this.setAttribute('aria-checked', String(e.home.on)); repaint(); }, ro)]),
      el('div', { style: 'margin-top:16px;display:grid;gap:10px' }, [
        el('b', { text: 'Background', style: 'font-size:13px' }),
        bgSel,
        el('div', { style: 'display:flex;gap:14px;flex-wrap:wrap;align-items:center' }, [colour('c1', 'Colour 1'), colour('c2', 'Colour 2'), colour('c3', 'Colour 3')]),
        el('label', { style: 'display:flex;gap:10px;align-items:center;font-size:12.5px' }, ['Strength (lighter ← → stronger)', str, strV]),
        swatch,
        el('b', { text: 'Button link', style: 'font-size:13px;margin-top:6px' }),
        (function () { var u = el('input', { class: 'fg-in', type: 'text', value: h.url, maxlength: 500, placeholder: '/super-sale/', 'aria-label': 'Button link', 'data-fk': 'h-url', disabled: ro, dir: 'ltr' }); u.addEventListener('input', function () { editCfg().home.url = u.value; repaint(); }); return el('label', { style: 'display:grid;gap:4px;font-size:12.5px' }, [u, el('span', { text: 'A page on this shop (/super-sale/) or a full https:// address. Anything else goes to /super-sale/.', style: 'color:var(--ink-soft,#6b7280)' })]); })(),
        el('b', { text: 'Width', style: 'font-size:13px;margin-top:6px' }),
        el('label', { style: 'display:flex;gap:10px;align-items:center;font-size:12.5px;flex-wrap:wrap' }, ['On a computer, share of the site width', wid, widV, el('span', { text: '100% = as wide as the other sections. Tablets and phones always use the full width.', style: 'color:var(--ink-soft,#6b7280)' })]),
      ]),
      el('div', { class: 'fg-tx', style: 'margin-top:12px' }, [el('div', { class: 'fg-txr hd', 'aria-hidden': 'true' }, [el('span', { text: 'Where' }), el('span', { text: 'English' }), el('span', { text: 'Arabic' })])].concat(HOME_FIELDS.map(function (f) {
        return el('div', { class: 'fg-txr' }, [el('span', { text: f[1] }), t(f[0], 'en', f[1]), t(f[0], 'ar', f[1])]);
      })))
    ].concat(st.errors.home ? [el('div', { class: 'fg-msg err', role: 'alert', style: 'margin-top:10px' }, [el('i', { text: '!' }), el('span', { text: st.errors.home })])] : [], [cfgSaveRow('home')])));
  }

  /* The sidebar row is AdminNav's static `late` entry; this only returns it
     when the console's own nav is drawn without it. */
  function addNavEntry() {
    if (typeof window.kbbAddNavEntry !== 'function') return;
    window.kbbAddNavEntry({
      screen: SCREEN,
      label: 'Free gifts',
      icon: '<path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7"/><path d="M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/>',
      group: GROUP,
      after: ['push', 'searchterms', 'pixels']
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addNavEntry); else addNavEntry();
})();
</script>
@endverbatim
