{{--
    The contact page's own rules (Lane CT): one inline <style>, pushed to the
    head only by store/page.blade.php on /contact-us/, so no other page gains a
    byte and no page gains a request. Every rule is under .ctc. Colours and
    radii are kbb.css's own tokens (--pink, --ink, --line, --r-m ...); the
    WhatsApp tint is the header support chip's (#E8F7EE / #1F9D55). Sizes are
    clamp()/grid, nothing is measured by script.
--}}
<style>
.ctc{max-width:1080px;margin:0 auto;font-family:var(--sans)}
.ctc-top{margin:6px auto 30px}
/* Inside the 78ch article, under its title: wider than the text column, never wider than the page (60px = both gutters and a scrollbar). Symmetric, so the same in RTL. */
.policy>.ctc-top{--ctc-w:max(100%,min(1080px,100vw - 60px));width:var(--ctc-w);margin-inline:calc((100% - var(--ctc-w)) / 2);margin-bottom:34px}
.ctc-h{font-size:clamp(22px,2.3vw,30px);font-weight:800;letter-spacing:-.02em;color:var(--ink);margin:0 0 6px;text-align:center}
.ctc-sub{color:var(--muted);font-size:15px;margin:0 0 20px;text-align:center}
.ctc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.ctc-card{position:relative;display:grid;grid-template-columns:52px minmax(0,1fr);gap:4px 16px;align-content:start;padding:22px;background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:var(--sh-s);color:var(--ink);text-decoration:none;transition:transform .2s var(--ease),box-shadow .2s var(--ease),border-color .2s}
.ctc-card:hover{transform:translateY(-2px);box-shadow:var(--sh-m);border-color:rgba(198,57,95,.35)}
.ctc-card:focus-visible{outline:3px solid rgba(198,57,95,.45);outline-offset:3px}
.ctc-ic{grid-row:1/4;width:52px;height:52px;border-radius:16px;display:grid;place-items:center;background:var(--pink-soft);color:var(--pink)}
.ctc-ic svg{width:26px;height:26px}
.ctc-card[data-ct=wa] .ctc-ic{background:#E8F7EE;color:#1F9D55}
.ctc-card[data-ct=email] .ctc-ic{background:#FFF1E4;color:#B0782A}
.ctc-card h3{font-size:17px;font-weight:700;margin:2px 0 0;letter-spacing:-.01em}
.ctc-note{font-size:13.5px;color:var(--muted);margin:0}
.ctc-val{font-size:15px;font-weight:600;margin:6px 0 0;overflow-wrap:anywhere;unicode-bidi:isolate}
.ctc-go{grid-column:1/-1;margin-top:14px;display:flex;align-items:center;justify-content:center;gap:8px;height:44px;border-radius:999px;background:var(--pink-soft);color:var(--pink-ink);font-weight:700;font-size:14.5px}
.ctc-go::after{content:"\2192"}
[dir=rtl] .ctc-go::after{content:"\2190"}
.ctc-card[data-ct=wa] .ctc-go{background:#1F9D55;color:#fff}
.ctc-card:hover .ctc-go{filter:brightness(.97)}
.ctc-low{display:grid;gap:20px;margin:30px auto 8px;align-items:start}
.ctc-low.has-form{grid-template-columns:minmax(0,1fr)}
@media (min-width:900px){.ctc-low.has-form.has-side{grid-template-columns:minmax(0,1.65fr) minmax(0,1fr)}}
.ctc-panel{background:#fff;border:1px solid var(--line);border-radius:24px;box-shadow:var(--sh-s);padding:clamp(18px,3vw,30px)}
.ctc-formwrap{scroll-margin-top:90px}
.ctc-formwrap .ctc-h,.ctc-formwrap .ctc-sub{text-align:start}
.ctc-side{display:grid;gap:16px}
.ctc-side h2{font-size:17px;font-weight:700;margin:0 0 6px;color:var(--ink)}
.ctc-side p{font-size:13.5px;color:var(--muted);margin:0 0 14px}
.ctc-soc{display:flex;flex-wrap:wrap;gap:10px}
.ctc-soc a{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;background:var(--pink-soft);color:var(--pink);transition:background .2s,color .2s,transform .2s var(--ease)}
.ctc-soc a:hover{background:var(--pink);color:#fff;transform:translateY(-2px)}
.ctc-soc svg{width:21px;height:21px}
.ctc-hours{list-style:none;margin:0;padding:0;font-size:14.5px;color:var(--ink-2)}
.ctc-hours li{padding:7px 0;border-top:1px solid var(--line-2);unicode-bidi:isolate}
.ctc-hours li:first-child{border-top:0}
.ctc-status{border-radius:14px;padding:14px 16px;margin:0 0 18px;font-size:14.5px;line-height:1.5}
.ctc-status:focus{outline:none}
.ctc-status.ok{background:#E8F7EE;border:1px solid #BFE6CF;color:#14532D}
.ctc-status.bad{background:#FFF0F2;border:1px solid #F7C5CF;color:#9F1239}
.ctc-status strong{display:block;font-size:16px;margin-bottom:2px}
.ctc-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}
@media (min-width:600px){.ctc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ctc-f.wide{grid-column:1/-1}}
.ctc-f label{display:block;font-size:13.5px;font-weight:600;color:var(--ink);margin:0 0 6px}
.ctc-f label small{font-weight:400;color:var(--muted)}
.ctc-f input,.ctc-f select,.ctc-f textarea{display:block;width:100%;box-sizing:border-box;font:inherit;font-size:16px;color:var(--ink);background:#fff;border:1px solid rgba(42,34,40,.18);border-radius:12px;padding:0 14px;height:48px;transition:border-color .15s,box-shadow .15s}
.ctc-f textarea{height:auto;min-height:150px;padding:12px 14px;resize:vertical;line-height:1.55}
.ctc-f input:focus,.ctc-f select:focus,.ctc-f textarea:focus{outline:none;border-color:var(--pink);box-shadow:0 0 0 3px rgba(198,57,95,.16)}
.ctc-f.is-bad input,.ctc-f.is-bad select,.ctc-f.is-bad textarea{border-color:#B0182F;background:#FFFAFB}
.ctc-err{color:#B0182F;font-size:13px;margin:6px 0 0}
.ctc-hp{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap}
.ctc-send{margin-top:20px;width:100%;height:52px;border:0;border-radius:999px;background:linear-gradient(135deg,var(--pink) 0%,var(--pink-deep) 100%);color:#fff;font:inherit;font-size:16px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px -12px rgba(198,57,95,.7);transition:transform .2s var(--ease),box-shadow .2s}
.ctc-send:hover{transform:translateY(-1px);box-shadow:0 14px 28px -12px rgba(198,57,95,.8)}
.ctc-send:focus-visible{outline:3px solid rgba(198,57,95,.45);outline-offset:3px}
@media (min-width:600px){.ctc-send{width:auto;padding:0 36px}}
.ctc-priv{font-size:12.5px;color:var(--muted);margin:12px 0 0}
@media (max-width:599px){.ctc-card{padding:18px;grid-template-columns:46px minmax(0,1fr);border-radius:18px}.ctc-ic{width:46px;height:46px;border-radius:14px}.ctc-go{margin-top:12px}.ctc-top{margin-bottom:24px}}
@media (prefers-reduced-motion:reduce){.ctc-card,.ctc-soc a,.ctc-send{transition:none}.ctc-card:hover,.ctc-soc a:hover,.ctc-send:hover{transform:none}}
</style>
