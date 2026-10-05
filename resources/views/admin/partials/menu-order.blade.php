{{--
    Appearance → Mega Menu: the column board.                          Lane MO

    resources/js/kbb/admin/menu-order.js, inlined as a classic script so it
    needs no build step and defines window.KBBMenuOrder before the Mega Menu
    screen paints; paintMegaMenu() mounts it in #mgmTree (tools/mo-wire.php
    writes that call and this include). The file is a repository constant,
    never a setting — the only thing this project prints unescaped — and
    MenuOrderModuleTest holds that it cannot close this tag.

    Sizes are the owner's compact layout A (docs/menu-editor-preview): 156px
    columns, 24px rows, a 24x20 number box, 18x20 arrows, 12-12.5px text, an
    8px gap. Motion is transform and opacity only, and none of it under
    prefers-reduced-motion.
--}}
@verbatim
<style>
.mo-wrap{position:relative;min-width:0}
.mo-edge{display:none;position:absolute;top:0;bottom:8px;z-index:4;width:36px}
.mo-edge-l{left:0;background:linear-gradient(90deg,rgba(224,86,123,.14),transparent)}
.mo-edge-r{right:0;background:linear-gradient(270deg,rgba(224,86,123,.14),transparent)}
.mo-dragging .mo-edge{display:block}
.mo-board{max-width:100%;overflow-x:auto;overscroll-behavior-x:contain;padding:2px 0 8px}
.mo-cols{display:flex;align-items:flex-start;gap:8px;width:max-content}
.mo-col{flex:0 0 156px;width:156px;min-width:0;padding:6px;display:flex;flex-direction:column;gap:5px;background:#faf9fc;border:1px solid #f0e4e9;border-top:3px solid var(--mo-hl,#f0e4e9);border-radius:9px}
.mo-body{display:flex;flex-direction:column;gap:5px;min-height:4px}
.mo-grp{background:#fff;border:1px solid #f0e4e9;border-radius:7px;padding:2px 3px 3px;display:flex;flex-direction:column;gap:2px;box-shadow:inset 2px 0 0 var(--mo-hl,transparent)}
.mo-links{display:flex;flex-direction:column;gap:2px;min-height:2px}
.mo-row{display:flex;align-items:center;gap:3px;height:24px;min-width:0;font-size:12px;line-height:1.2;color:#2A2228;user-select:none;-webkit-user-select:none;cursor:grab;border-radius:5px}
.mo-link{padding:0 1px 0 3px;background:#faf9fc;box-shadow:inset 2px 0 0 var(--mo-hl,transparent)}
.mo-head{font-size:12.5px}
.mo-head .mo-name{font-weight:700}
.mo-ghead .mo-name{font-weight:650}
.mo-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;border-radius:4px}
.mo-name:hover{color:#C13E63}
.mo-name:focus-visible{outline:2px solid #E0567B;outline-offset:1px}
.mo-badge{flex:none;font-style:normal;font-size:9px;font-weight:700;line-height:1;background:#15a85a;color:#fff;border-radius:99px;padding:2px 4px}
.mo-num{flex:none;width:24px;height:20px;padding:0;border:1px solid #e6dfe3;border-radius:5px;background:#fff;color:#2A2228;text-align:center;font:inherit;font-size:11px;font-weight:700;font-variant-numeric:tabular-nums;cursor:text}
.mo-ib{flex:none;width:18px;height:20px;padding:0;border:1px solid #e6dfe3;border-radius:5px;background:#fff;color:#5E545A;font-size:10px;line-height:1;display:grid;place-items:center;cursor:pointer}
.mo-ib:hover:not([disabled]){border-color:#E0567B;color:#C13E63}
.mo-ib[disabled]{opacity:.32;cursor:default}
.mo-more{width:13px;border-color:transparent;background:transparent;font-size:12px}
.mo-more[aria-expanded="true"]{color:#C13E63}
.mo-num:focus,.mo-ib:focus-visible,.mo-add:focus-visible,.mo-in:focus,.mo-choice:focus-visible,.mo-pb:focus-visible,.mo-go:focus-visible,.mo-under:focus{outline:2px solid #E0567B;outline-offset:1px}
.mo-add{display:block;width:100%;padding:3px 6px;border:1px dashed #b9dcc4;border-radius:6px;background:#e8f5ec;color:#2f8a4c;font:inherit;font-size:11.5px;font-weight:700;text-align:left;cursor:pointer}
.mo-add-s{padding:1px 5px;font-size:11px;background:transparent;border-color:#d7ecdd}
.mo-addrow{display:flex;flex-direction:column;gap:3px}
.mo-in{display:block;width:100%;min-width:0;height:22px;padding:0 5px;border:1px solid #e6dfe3;border-radius:5px;background:#fff;color:#2A2228;font:inherit;font-size:12px}
.mo-panel{display:flex;flex-wrap:wrap;gap:3px;padding:3px;background:#fff;border:1px solid #f0d3dc;border-radius:6px}
.mo-pb{height:20px;padding:0 7px;border:1px solid #e6dfe3;border-radius:5px;background:#fff;color:#2A2228;font:inherit;font-size:11.5px;font-weight:600;cursor:pointer}
.mo-pb-del{color:#b4443c}
.mo-under{width:100%;height:22px;border:1px solid #e6dfe3;border-radius:5px;background:#fff;font:inherit;font-size:11.5px;color:#2A2228}
.mo-empty{font-size:12.5px;color:#8C828A;margin:4px 0}
.mo-hot{background:#fff5f8;border-color:#f3b3c6}
.mo-no{outline:2px dashed #b4443c;outline-offset:1px;cursor:not-allowed}
.mo-lift{position:relative;z-index:3;opacity:.94;transform:scale(1.03);box-shadow:0 8px 18px -8px rgba(42,34,40,.45);cursor:grabbing}
.mo-dragging,.mo-dragging *{cursor:grabbing}
.mo-holding{position:relative}
.mo-holding::after{content:'';position:absolute;left:4px;right:4px;bottom:0;height:2px;border-radius:2px;background:#E0567B;transform-origin:left;animation:mo-hold .35s linear forwards}
@keyframes mo-hold{from{transform:scaleX(0)}to{transform:scaleX(1)}}
.mo-slide-u{animation:mo-slide-u .14s ease-out}.mo-slide-d{animation:mo-slide-d .14s ease-out}
.mo-slide-l{animation:mo-slide-l .16s ease-out}.mo-slide-r{animation:mo-slide-r .16s ease-out}
@keyframes mo-slide-u{from{transform:translateY(100%)}}@keyframes mo-slide-d{from{transform:translateY(-100%)}}
@keyframes mo-slide-l{from{transform:translateX(100%)}}@keyframes mo-slide-r{from{transform:translateX(-100%)}}
.mo-flash{position:relative}
.mo-flash::after{content:'';position:absolute;inset:0;border-radius:inherit;background:#ffd76a;opacity:0;pointer-events:none;animation:mo-flash .9s ease-out}
@keyframes mo-flash{from{opacity:.6}to{opacity:0}}
.mo-fab{position:fixed;right:22px;bottom:22px;z-index:60;width:52px;height:52px;padding:0;border:0;border-radius:50%;background:#E0567B;color:#fff;font-size:28px;line-height:1;display:grid;place-items:center;cursor:pointer;box-shadow:0 0 0 5px rgba(224,86,123,.2),0 10px 22px -8px rgba(193,62,99,.6);transition:transform .16s ease,opacity .16s ease}
.mo-fab span{display:block;margin-top:-2px;transition:transform .16s ease}
.mo-fab:hover{transform:scale(1.06)}
.mo-fab[aria-expanded="true"] span{transform:rotate(45deg)}
.mo-fab:focus-visible{outline:3px solid #2A2228;outline-offset:5px}
.mo-dragging .mo-fab{opacity:0;transform:scale(.7);pointer-events:none}
.mo-sheet{position:fixed;right:22px;bottom:86px;z-index:61;width:min(300px,calc(100vw - 32px));display:flex;flex-direction:column;gap:6px;padding:10px;background:#fff;border:1px solid #f0e4e9;border-radius:14px;box-shadow:0 18px 40px -16px rgba(42,34,40,.4);animation:mo-pop .16s ease-out;transform-origin:bottom right}
.mo-sheet[hidden]{display:none}
@keyframes mo-pop{from{opacity:0;transform:translateY(8px) scale(.97)}}
.mo-sheet-h{display:flex;align-items:center;justify-content:space-between;font-size:13px}
.mo-choices{display:flex;flex-direction:column;gap:4px}
.mo-choice{display:block;width:100%;padding:7px 10px;border:1px solid #f0e4e9;border-radius:9px;background:#fff;color:#2A2228;font:inherit;font-size:12.5px;font-weight:600;text-align:left;cursor:pointer}
.mo-choice[aria-pressed="true"]{border-color:#E0567B;background:#fff5f8;color:#C13E63}
.mo-sheet-f{display:flex;flex-direction:column;gap:5px}
.mo-sheet-f:empty{display:none}
.mo-sheet-f .mo-in{height:30px;font-size:13px}
.mo-go{height:30px;border:0;border-radius:8px;background:#2A2228;color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.mo-note{margin:0;font-size:12px;color:#8C828A}
@media (prefers-reduced-motion:reduce){
  .mo-flash::after,.mo-sheet,.mo-slide-u,.mo-slide-d,.mo-slide-l,.mo-slide-r{animation:none}
  .mo-fab,.mo-fab span{transition:none}
  .mo-lift{transform:none}
}
</style>
@endverbatim
<script>{!! file_get_contents(resource_path('js/kbb/admin/menu-order.js')) !!}</script>
