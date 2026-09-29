{{--
    Orders -> (an order) -> Items -> "Release the hold". (Lane OD.)

    ── WHAT THIS IS, AND WHY IT IS A PARTIAL AND NOT A SCREEN ───────────────

    A cancelled BNPL order never released the buyer's authorisation, because
    the button did not exist:

        grep -rn -F "'/void'" resources/views/admin/ resources/js/   ->  nothing

    routes/payments-void.php is mounted and registers two paths behind
    PaymentVoidController -- GET "can this hold be released, and why not" and
    POST "release it" -- both capability-mapped to `orders.money`, both tested,
    and neither called by one line of this console. Its sibling
    POST /admin-api/orders/{id}/capture is called from app.blade.php: one
    missing control sitting directly beside a working one.

    What the absence costs is not abstract. A cancelled Tamara order leaves the
    buyer's instalment plan LIVE at Tamara for up to 180 days. The shop has
    cancelled, restocked and released the coupon; the shopper's credit is still
    committed against an order this shop has abandoned, and they are asked for
    the first instalment.

    This is NOT a screen. It adds no sidebar row, claims no `go()` id, wraps
    nothing, and has no breadcrumb -- the control belongs on the order the
    operator is already looking at, next to Capture and Refund, which is where
    every other money action on this shop lives. app.blade.php draws that
    screen and CLAUDE.md forbids a lane editing it, so the panel is appended
    from the OUTSIDE, the way tamara-connection-screen.blade.php appends its
    button to Store -> Payments.

    ── WHY A MutationObserver AND NOT A ONE-SHOT ───────────────────────────

    renderOrderDetail() rewrites the whole of #content from the endpoint after
    every save, every status change, every added note and every item edit. A
    one-shot injection after navigation would survive until the operator's
    first save and then quietly vanish -- a worse failure than never appearing,
    because it appears to have worked. The observer is coalesced to one check
    per animation frame, and the check itself is two selector lookups that stop
    at the first miss on every screen that is not an order.

    Adding the panel is idempotent: it looks for its own id first, so the
    observer firing on its own insertion does nothing. Nothing existing is
    moved, rewritten or measured -- one block is inserted before the refund row
    inside the Items card, and if the observer never fires the order screen is
    byte-for-byte what it is today.

    ── WHICH ORDER, AND WHY THE SERVER DECIDES THAT TOO ────────────────────

    renderOrderDetail() is module-scoped inside app.blade.php's second IIFE and
    is not on window, and the order screen it draws carries the order id in NO
    attribute anywhere. The only id available from outside is the one on the
    control that opened the screen -- [data-olview] in the orders list,
    [data-reconorder] in the reconciliation report -- which this file remembers
    with one passive capture-phase listener that preventDefaults nothing and
    stops nothing.

    A remembered id is a browser's claim, and this panel spends money. So it is
    never trusted: GET /admin-api/orders/{id}/void answers, among other things,
    the ORDER NUMBER that id really names, and the panel is not drawn at all
    unless that number matches the "Order #..." heading the operator can see.
    Mismatch draws nothing. There is no path on which a release button appears
    over an order the operator is not looking at.

    ── DESTRUCTIVE AND IRREVERSIBLE ────────────────────────────────────────

    Nothing recreates a released authorisation -- the shopper would have to go
    through the provider's checkout again, and they have gone. So:

      * the button is offered only where the server says `voidable`, which is
        exactly the set of conditions PaymentVoider::void() accepts, so the
        screen can never offer a button that is then refused;
      * where it is not offered, the server's own one-line reason is shown
        instead, because "there is no button" is the question this panel exists
        to answer;
      * pressing it opens a confirmation that states what happens IN THE
        BUYER'S TERMS, in the server's words and with the server's amount --
        the browser assembles no figure and posts none;
      * the confirm button disables itself and this file holds an in-flight
        flag, and behind both PaymentVoider claims `voided_at` with one
        conditional UPDATE before it calls the provider, so a double fire
        releases once and the second is answered `already_voided`.

    NO AMOUNT AND NO REFERENCE IS EVER POSTED. The body is `{}`; the route
    takes an id and PaymentVoidController ignores the body entirely.

    ── MONEY ───────────────────────────────────────────────────────────────

    Every figure printed here is the string the server formatted from integer
    fils (Money::amount(..., 2)). Nothing below parses one into a number,
    multiplies one, or rounds one.

    ── LAYOUT ──────────────────────────────────────────────────────────────

    No element-measuring API is called anywhere below -- two tests in this
    suite forbid them by name. The panel sizes with flex-wrap and clamp(), and
    every flex child that can hold something long carries min-width:0, because
    a flex item's default min-width is `auto`.

    Every class is prefixed orh- and appears nowhere else in the console, and
    this file claims no bare data- attribute: app.blade.php binds around a
    dozen delegated listeners to `document` itself, each claiming a bare
    attribute name, and a click on any element carrying one is handled by that
    listener whichever screen it belongs to.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<style>
.orh-panel{margin-top:14px;border:1px solid var(--border,#e6e6e6);border-radius:10px;padding:12px 13px;
           font-size:12.5px;line-height:1.55;min-width:0}
.orh-panel b{font-weight:700}
.orh-panel p{margin:0}
.orh-panel p + p{margin-top:7px}
.orh-head{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;margin-bottom:6px;min-width:0}
.orh-head > *{min-width:0}
.orh-title{font-size:13px;font-weight:700;color:var(--ink,#1f2937)}
.orh-amt{font-size:12.5px;font-weight:700;color:var(--ink,#1f2937);overflow-wrap:anywhere}
.orh-why{color:var(--ink-faint,#9aa0a6)}
.orh-soft{color:var(--ink-soft,#6b7280)}
.orh-open{border-color:#f0c9a0;background:rgba(180,83,9,.06)}
.orh-done{border-color:var(--border,#e6e6e6)}
.orh-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:11px;min-width:0}
.orh-row > *{min-width:0}
.orh-confirm{margin-top:11px;border:1px solid #e3a9a9;border-radius:9px;padding:11px 12px;
             background:rgba(192,57,43,.05);min-width:0}
.orh-confirm p{margin:0 0 9px;font-size:12.5px;line-height:1.6;max-width:70ch;overflow-wrap:anywhere}
.orh-warn{color:#b91c1c;font-weight:700}
@media (max-width:640px){
  .orh-panel{padding:11px}
  .orh-row .btn{flex:1 1 auto}
}
</style>

<script>
(function(){
  'use strict';

  /* ------------------------------------------------------------------ state

     Everything drawn below came out of a response a moment ago. Nothing is
     derived, remembered across a repaint, or optimistically patched after a
     click: the release response carries the server's own state and that is
     what is redrawn, so this panel can never claim a release the server does
     not have. */
  var orderId = null;      // the id of the order whose screen was opened
  var loading = false;     // a GET is in flight
  var busy = false;        // a release is in flight
  var confirming = false;  // the confirmation strip is showing
  var state = null;        // the last GET/POST body
  var said = null;         // {tone, text} -- what the last release answered

  var PANEL_ID = 'orh-panel';

  /* The one answer that cannot change while an order is on screen: whether its
     gateway holds a releasable authorisation at all. `voidable` DOES change --
     cancelling the order is exactly what makes a hold releasable, and that
     repaints the screen -- so only the structural "no" is remembered, and only
     for as long as the same order is open. Without it every repaint of every
     cash-on-delivery order would ask the server again. */
  var unsupported = null;

  /* The console's own fixAdminApiUrl() is module-scoped in app.blade.php and
     not on window, so this repeats its one expression. It turns a rooted
     '/admin-api/...' path into one that survives a deployment served under a
     sub-path (KBB_BASE_PATH), which the live shop is not but staging is. */
  function apiUrl(path){
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + path;
  }

  /* A FULL literal, and the '/1/' is replaced rather than the path being
     assembled from a base and a suffix. AdminConsoleControlsAreLiveTest reads
     '/admin-api/...' literals out of this file and resolves each against the
     REAL router -- a path built as base + id + suffix is invisible to it, and
     the defect that guard exists for is a console calling a path no route
     answers. Written this way, the exact shape this panel calls is checked. */
  var VOID_PATH = '/admin-api/orders/1/void';

  function voidUrl(id){
    return apiUrl(VOID_PATH.replace('/1/', '/' + String(id) + '/'));
  }

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function when(iso){
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d)) return String(iso);
    return d.toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'})
         + ' at ' + d.toLocaleTimeString('en-GB', {hour:'2-digit', minute:'2-digit'});
  }

  function note(msg, tone){
    try { if (typeof window.toast === 'function') window.toast(msg, tone); } catch (e) {}
  }

  /*
   * A 4xx here is an ANSWER, not a transport failure.
   *
   * PaymentVoidController answers 422 with the operator's own sentence for the
   * refusals this shop made on its own ("this order is processing", "the money
   * has been captured") and 502 for a provider that could not be reached, and
   * the two want different next actions. app.blade.php's api() throws away the
   * body and leaves the caller with a status code, so this resolves with the
   * status and the parsed body and the caller decides.
   */
  async function call(method, url){
    var opts = {method: method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};

    if (method !== 'GET') {
      opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');
      opts.headers['Content-Type'] = 'application/json';
      // No amount, no reference, no order id. The route carries the id and the
      // controller ignores the body entirely.
      opts.body = '{}';
    }

    var r = await fetch(url, opts);
    var body = null;
    try { body = await r.json(); } catch (e) { body = null; }

    return {status: r.status, ok: r.ok, body: body || {}};
  }

  /* ------------------------------------------------- which order is on screen

     The order screen carries its id in no attribute, so the id comes from the
     control that opened it. Passive: no preventDefault, no stopPropagation, no
     state of app.blade.php's is touched. Capture phase only so that a handler
     which replaces #content before bubbling cannot lose the click. */
  function rememberOrderId(ev){
    var el = ev.target && ev.target.closest
      ? ev.target.closest('[data-olview],[data-reconorder]')
      : null;

    if (!el) return;

    var raw = el.getAttribute('data-olview') || el.getAttribute('data-reconorder') || '';

    if (!/^[0-9]+$/.test(raw)) return;

    if (raw !== orderId) {
      // A different order: nothing carried over from the last one.
      orderId = raw;
      state = null;
      said = null;
      confirming = false;
      unsupported = null;
    }
  }

  /* The heading the operator can actually see, e.g. "Order #KBB-1042". */
  function headingOrderNumber(){
    var h = document.querySelector('#content .page-head h2');
    if (!h) return null;
    var text = (h.textContent || '').trim();
    var at = text.indexOf('#');

    return at === -1 ? null : text.slice(at + 1).trim();
  }

  /* --------------------------------------------------------------- rendering */

  /*
   * The panel's OUTER element is created once, in the DOM, and only its inner
   * markup is redrawn. So `id="orh-panel"` is written nowhere as a literal:
   * four states of one panel spelling the same id four times is what
   * AdminNavAndIdsTest calls a collision, and it is right to -- a static
   * reader cannot tell four exclusive renders from four elements.
   */
  function toneClass(){
    var v = state || {};

    if (v.voided) return 'orh-panel orh-done';

    return v.voidable ? 'orh-panel orh-open' : 'orh-panel';
  }

  function bodyHtml(){
    var v = state || {};
    var c = v.confirm || {};
    var money = esc(c.currency || 'AED') + ' ' + esc(c.amount || '');

    if (v.voided) {
      return '<div class="orh-head"><span class="orh-title">Authorisation released</span>'
        + '<span class="orh-amt">' + money + '</span></div>'
        + '<p class="orh-soft">'
        + (c.holder ? esc(c.holder) + ' is no longer holding this buyer&#39;s credit for this order. ' : '')
        + (v.voided_at ? 'Released on ' + esc(when(v.voided_at)) + '. ' : '')
        + (v.void_ref ? 'Cancellation reference ' + esc(v.void_ref) + '. ' : '')
        /* Only when this panel is NOT reporting a release it has just made.
           The note PaymentVoider writes lands on the order at the same moment,
           and the Order notes card a few inches below was drawn before it --
           so saying "the notes carry the same record" beside a card reading
           "No notes yet" would be this panel contradicting the screen it is
           sitting on. The line below says what is actually true instead. */
        + (said ? '' : 'The order notes below carry the same record.')
        + '</p>'
        + saidHtml();
    }

    if (!v.voidable) {
      return '<div class="orh-head"><span class="orh-title">Hold on the buyer&#39;s credit</span>'
        + '<span class="orh-amt">' + money + '</span></div>'
        + '<p class="orh-why">' + esc(v.why_not || 'This hold cannot be released.') + '</p>'
        + saidHtml();
    }

    if (confirming) {
      return '<div class="orh-head"><span class="orh-title">Release the hold</span>'
        + '<span class="orh-amt">' + money + '</span></div>'
        + '<div class="orh-confirm">'
        + '<p><span class="orh-warn">This cannot be undone.</span> ' + esc(c.consequence || '') + '</p>'
        + '<p class="orh-soft">Releasing ' + money + ' held against order '
        + esc(c.order_number || '') + '. Nothing is refunded and nothing is captured &mdash; '
        + 'this gives back the right to take the money, which was never taken.</p>'
        + '<div class="orh-row">'
        + '<button class="btn sm" id="orh-go"' + (busy ? ' disabled' : '') + '>'
        + (busy ? 'Releasing&hellip;' : 'Yes, release ' + money) + '</button>'
        + '<button class="btn ghost sm" id="orh-cancel"' + (busy ? ' disabled' : '') + '>Keep the hold</button>'
        + '</div></div>'
        + saidHtml();
    }

    return '<div class="orh-head"><span class="orh-title">Hold on the buyer&#39;s credit</span>'
      + '<span class="orh-amt">' + money + '</span></div>'
      + '<p class="orh-soft">This order is off, and '
      + esc(c.holder || 'the payment provider') + ' is still holding '
      + money + ' of the buyer&#39;s credit against it. Releasing it gives that back.</p>'
      + '<div class="orh-row"><button class="btn ghost sm" id="orh-open">Release the hold&hellip;</button></div>'
      + saidHtml();
  }

  function saidHtml(){
    if (!said) return '';

    return '<p class="' + (said.tone === 'bad' ? 'orh-warn' : 'orh-soft') + '">' + esc(said.text) + '</p>'
      + (said.tone === 'good'
          ? '<p class="orh-soft">The rest of this screen &mdash; the capture box above, the refundable '
            + 'figure and the order notes below &mdash; was drawn before the release and still reads as '
            + 'it did. Reopen the order to redraw it; the release itself is recorded either way.</p>'
          : '');
  }

  /* Redraw this panel's own contents, and nothing else's. */
  function paint(){
    var panel = document.getElementById(PANEL_ID);
    if (!panel) return;

    panel.className = toneClass();
    panel.innerHTML = bodyHtml();
    bind();
  }

  function bind(){
    var open = document.getElementById('orh-open');
    if (open) open.onclick = function(){ confirming = true; paint(); };

    var cancel = document.getElementById('orh-cancel');
    if (cancel) cancel.onclick = function(){ if (!busy) { confirming = false; paint(); } };

    var go = document.getElementById('orh-go');
    if (go) go.onclick = release;
  }

  /* ----------------------------------------------------------- the release */
  async function release(){
    if (busy) return;

    var id = orderId;
    var expected = state && state.confirm ? String(state.confirm.order_number || '') : '';

    /*
     * The id is re-checked against the heading at the moment of the press, not
     * only at the moment the panel was drawn. Between the two the operator may
     * have opened another order -- and the panel this one belongs to would have
     * been thrown away with the repaint, but a press that raced the repaint
     * must not land on the wrong order. Nothing is posted if they disagree.
     */
    if (!id || expected === '' || headingOrderNumber() !== expected) {
      confirming = false;
      said = {tone: 'bad', text: 'This panel is no longer showing the order on screen. Nothing was released; reopen the order and try again.'};
      paint();

      return;
    }

    busy = true;
    paint();

    var res;

    try {
      res = await call('POST', voidUrl(id));
    } catch (e) {
      busy = false;
      said = {tone: 'bad', text: 'The release could not be sent. Nothing was released — check the order notes before trying again.'};
      note('Could not release that hold.', 'bad');
      paint();

      return;
    }

    busy = false;
    confirming = false;

    var body = res.body || {};

    if (res.ok && body.ok !== false) {
      // The server's own state, not an optimistic patch.
      if (body.void) state = Object.assign({}, state, body.void);
      said = {tone: 'good', text: body.message || 'The authorisation has been released.'};
      note(body.message || 'Hold released');
      paint();

      return;
    }

    /*
     * A 404 on this path almost always means the package shipped without its
     * clear_caches migration having run, so the compiled route table on the
     * host does not know it. Said plainly, because on THIS panel an unexplained
     * failure reads as "the provider refused", which sends the owner to Tamara
     * to ask about something that never left the building.
     */
    said = {
      tone: 'bad',
      text: res.status === 404
        ? 'This server\'s compiled route table does not know the release endpoint yet. Clear the route cache (Platform → Cache, or php artisan route:clear) and reload. Nothing was released.'
        : (body.message || 'The hold was not released.')
    };

    note(said.text, 'bad');
    paint();
  }

  /* ------------------------------------------------------------- injection */

  function itemsPad(){
    return document.querySelector('#content #odItems .pad');
  }

  function insert(pad){
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.className = toneClass();
    panel.innerHTML = bodyHtml();

    /* Before the refund row, so the three money actions read in the order they
       happen: capture, release, refund. Appended to the card if that row is not
       there, rather than not appearing at all. */
    var toggle = pad.querySelector('#odRefundToggle');
    var row = toggle && toggle.closest ? toggle.closest('.row') : null;

    if (row && row.parentNode === pad) pad.insertBefore(panel, row);
    else pad.appendChild(panel);

    bind();
  }

  async function inject(){
    if (loading) return;

    var pad = itemsPad();

    // Not an order screen, or this panel is already on it.
    if (!pad || document.getElementById(PANEL_ID)) return;

    var id = orderId;
    var heading = headingOrderNumber();

    // No id to ask about, or no order heading to check one against.
    if (!id || heading === null) return;

    // Already asked, for this order, and the answer was "nothing is held".
    if (unsupported === id) return;

    loading = true;

    var res;

    try {
      res = await call('GET', voidUrl(id));
    } catch (e) {
      loading = false;

      return;                       // the order screen is exactly as it was
    }

    loading = false;

    if (!res.ok || !res.body || !res.body.confirm) return;

    /*
     * THE CHECK THAT MAKES THE REMEMBERED ID SAFE. The server has just said
     * which order that id really is. If it is not the order whose heading is on
     * screen, no panel is drawn at all -- silently, because there is nothing
     * for the operator to do about it and a warning on an order screen about a
     * control they cannot see is noise.
     */
    if (String(res.body.confirm.order_number || '') !== heading) return;

    /*
     * A gateway that holds no releasable authorisation gets no panel: COD,
     * Stripe and every imported WooCommerce order are not holding anything, and
     * a box on all of them saying so is noise on the majority of orders. The
     * REASON is shown whenever the gateway does hold authorisations and this
     * particular order cannot be released -- which is the case an operator
     * actually asks about.
     */
    if (!res.body.supported) {
      unsupported = id;

      return;
    }

    // The screen may have moved on while the read was in flight.
    if (orderId !== id || !itemsPad() || document.getElementById(PANEL_ID)) return;
    if (headingOrderNumber() !== heading) return;

    /*
     * A FRESH READ MEANS THE SCREEN WAS REDRAWN, so the last release's message
     * is stale by definition: it ends with "the rest of this screen was drawn
     * before the release", and after a repaint that sentence is false. Cleared
     * here and nowhere else — the release itself does not re-inject, so the
     * message survives exactly as long as the screen it is describing.
     */
    said = null;
    state = res.body;
    insert(itemsPad());
  }

  /* COALESCED, because the observer is on #content with subtree:true and some
     screens mutate it in bursts. One check per animation frame rather than one
     per mutation, and the check itself stops at the first miss on every screen
     that is not an order. Nothing is measured and nothing is laid out. */
  var pending = false;

  function watch(){
    var host = document.querySelector('#content');
    if (!host || typeof window.MutationObserver !== 'function') return;

    var schedule = function(){
      if (pending) return;
      pending = true;
      var run = function(){ pending = false; inject(); };
      if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(run);
      else window.setTimeout(run, 16);
    };

    new window.MutationObserver(schedule).observe(host, {childList: true, subtree: true});

    inject();
  }

  function start(){
    document.addEventListener('click', rememberOrderId, true);
    watch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
</script>
@endverbatim
