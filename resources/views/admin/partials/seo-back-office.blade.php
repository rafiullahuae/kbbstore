{{--
    The SEO back office. (Lane S7 — "the backend super friendly to control all
    the seo stuff")

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once the console's own script has
    defined window.go and toast(). It registers NO sidebar entry and wraps
    window.go for NOTHING: everything here hangs off Store → SEO & Meta, a
    screen that already exists, so the whole of the change to that file is one
    include plus one subtab.

    ── THE TWO THINGS IN HERE ───────────────────────────────────────────────

    1. window.kbbSeoPreview() — "What will Google see for this page?", asked
       from the box the owner is typing into.

       Four screens in this console collect an SEO title and an SEO description
       and show NO CONSEQUENCE. Products are the exception: the product editor
       has had a live snippet since it was built, and its own comment records
       why — the box and the tag are not the same string, and a counter on the
       box told the operator "0 / 60" under a title Google receives at 40-odd
       characters. Categories, brands and articles never got that fix. They have
       a 255-character box, a 500-character box, and nothing.

       This is that snippet, once, for any of them. It is deliberately NOT a
       copy of the product editor's: that one lives inside its own editor's
       closure and reads that editor's model. This takes selectors.

    2. window.kbbSeoOverview() — "Is my SEO healthy?" and "What do I still have
       to do?", on one screen, which is the first tab of Store → SEO & Meta.

    ── WHY THE PREVIEW ASKS THE SERVER ──────────────────────────────────────

    Because four rules sit between the box and the tag and none of them can be
    reproduced here honestly:

      · an empty title box publishes the row's own name through
        `seo_title_template`, which appends " | K-Beauty Bliss" on this store;
      · a filled one is the WHOLE title and the site name is NOT appended;
      · a `%%title%%` or `{sitename}` token inside a typed title is substituted
        server-side, and a token the engine is not handed is DELETED — the
        defect that published the site name alone on 671 product pages;
      · an empty description box falls through the row's own description, then
        `seo_default_description`, then to nothing at all.

    Writing those four in JavaScript would be a FIFTH DIALECT of rules this
    project has already paid for four times over. So this sends what is in the
    boxes to POST /admin-api/seo-preview and draws what comes back, and
    Admin\SeoPreviewApiController answers with App\Support\Seo — the class every
    storefront page renders its own head with. SeoRowPreviewTest fetches the
    real page and requires the two to agree byte for byte.

    ── RULE 5, AT THE PRINT SITE ────────────────────────────────────────────

    A preview of a title IS operator-supplied content being drawn in the admin,
    and this file draws two of them plus product, category and brand names out
    of the database. Every one of those goes into the page with `.textContent`,
    and there is deliberately NO HELPER IN THIS FILE THAT TAKES MARKUP — escaping
    is a property of WHERE a string is printed and cannot be delegated to whoever
    wrote the value. SeoBackOfficeWiringTest reads this file and requires that
    none of the markup-writing DOM properties appears in it at all, which is why
    this paragraph does not name them. A lane found a live unescaped
    setting on the storefront homepage this week (`{!! $chip !!}` around
    `home_ticker`); this does not add the second one.

    The snippet's grey address line is drawn as TEXT and never as an `href`.
    `site_url` is an operator setting, and the project notes require a URL from
    a setting to be scheme-checked before it becomes a link — the cheaper and
    stricter answer is for it never to become one. The address line under a real
    Google result is not a link either.

    ── RULE 1 ───────────────────────────────────────────────────────────────

    Not one setting is added, renamed, moved or given a new default by this
    file, and no field changes what it stores. Everything here READS. The
    Overview is a report; the preview is a picture of a value somebody else
    saves. SeoBackOfficePayloadTest pins the payload of every screen this draws
    on, recorded off the parent revision before a line of it was written.

    ── ARABIC AND RTL ───────────────────────────────────────────────────────

    Every rule below sizes and aligns with LOGICAL properties —
    `padding-inline`, `margin-inline-start`, `border-inline-start`,
    `text-align:start` — so the whole of it mirrors when `dir="rtl"` is on the
    document and nothing has to be written twice. The one place a physical
    direction is correct is the little "↗" and the band accent, and both are
    logical too.
--}}

@verbatim
<style>
/* ---- Lane S7 · the SEO back office ------------------------------------------
   Prefixed .s7- throughout and never reaching outside it: several lanes are in
   the console at once. The two things it borrows rather than restates are
   `.seo-snip` (the Google result, already in this file since the product
   editor) and `.btn`, so a button here is the button everywhere else. */
.s7-wrap{display:grid;gap:16px;min-width:0}
.s7-wrap > *{min-width:0}
.s7-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);
         box-shadow:var(--sh-s);padding:18px;min-width:0}
.s7-h{font-size:13.5px;font-weight:650;margin:0 0 4px}
.s7-sub{font-size:12px;line-height:1.5;color:var(--ink-soft);max-width:78ch;margin:0}
.s7-verdict{font-size:15px;font-weight:600;line-height:1.4;margin:0 0 8px}
.s7-chips{display:flex;flex-wrap:wrap;gap:6px;min-width:0;margin:10px 0 0}
.s7-band{display:grid;gap:11px;min-width:0}
.s7-band + .s7-band{margin-top:20px;padding-top:18px;border-top:1px solid var(--border)}
.s7-band-h{font-size:12.5px;font-weight:650;line-height:1.4}
/* The accent is INLINE-START, so it is on the left in English and on the right
   in Arabic without a second rule. */
.s7-item{border:1px solid var(--border);border-inline-start:3px solid var(--ink-faint);
         border-radius:var(--r-xs);padding:12px 13px;min-width:0;display:grid;gap:6px}
.s7-item.is-now{border-inline-start-color:var(--sale)}
.s7-item.is-next{border-inline-start-color:var(--amber,#b7791f)}
.s7-item.is-stop{border-inline-start-color:var(--sale);background:rgba(200,30,60,.04)}
.s7-item-t{display:flex;flex-wrap:wrap;gap:8px;align-items:baseline;justify-content:space-between;min-width:0}
.s7-item-t b{font-size:12.5px;font-weight:650;flex:1 1 180px;min-width:0;text-align:start}
.s7-cost{font-size:12px;line-height:1.55;color:var(--ink-2);max-width:74ch;text-align:start}
.s7-why{font-size:11.5px;line-height:1.5;color:var(--ink-soft);max-width:74ch;text-align:start}
.s7-where{font-size:11.5px;color:var(--ink-soft);text-align:start}
.s7-where b{color:var(--ink-2);font-weight:600}
.s7-acts{display:flex;flex-wrap:wrap;gap:8px;min-width:0;margin-top:2px}
.s7-samples{display:grid;gap:0;min-width:0;margin-top:4px}
.s7-row{display:flex;flex-wrap:wrap;gap:9px;align-items:baseline;padding:6px 0;
        border-top:1px solid var(--border-2,var(--border));font-size:12px;min-width:0}
.s7-row .nm{flex:1 1 140px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:start}
.s7-row .dt,.s7-row .ur{color:var(--ink-soft);flex:0 1 auto;min-width:0;
        overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.s7-row .ur{font-size:11px}
.s7-clear{font-size:12px;line-height:1.6;color:var(--ink-soft);max-width:78ch;text-align:start}
.s7-more{font-size:11.5px;color:var(--ink-soft);margin:6px 0 0}
.s7-empty{font-size:12.5px;color:var(--ink-soft);margin:0}

/* ---- the preview, used on four screens -------------------------------------
   It has to sit inside .sm-field (SEO & Meta), .ct-fld (categories), .bz-fld
   (brands) and .pj-f (articles), none of which it may restyle, so every rule
   here is scoped to .s7-prev and nothing above it is touched. */
.s7-prev{display:grid;gap:7px;min-width:0;margin:0 0 4px}
.s7-prev-h{font-size:11.5px;font-weight:650;color:var(--ink-soft);
           letter-spacing:.03em;text-transform:uppercase;text-align:start}
.s7-prev .seo-snip{min-width:0;overflow:hidden}
/* Wrapping, not ellipsis. A Google result wraps, and a title cut off with "…"
   at the card edge is the one thing this preview must not do: the whole point
   is to show the operator the length. */
.s7-prev .seo-snip .u,
.s7-prev .seo-snip .t,
.s7-prev .seo-snip .d{overflow-wrap:anywhere;text-align:start}
.s7-prev-m{display:flex;flex-wrap:wrap;gap:6px 12px;min-width:0}
.s7-count{font-size:11px;color:var(--ink-soft);white-space:nowrap}
.s7-count.is-warn{color:var(--amber,#b7791f)}
.s7-count.is-bad{color:var(--sale)}
.s7-prev-note{font-size:11.5px;line-height:1.5;color:var(--ink-soft);max-width:66ch;text-align:start}
@media (max-width:640px){ .s7-card{padding:14px} }
</style>

<script>
(function () {
  'use strict';

  /* ======================================================================
     1 · SHARED PLUMBING
     ====================================================================== */

  function cookie(n) {
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The admin-api base, derived the way every other screen in this console
     derives it: strip the last path segment off the current admin URL. The
     admin path is configurable (AdminPathService), so a literal '/admin' here
     would be a screen that works on this install and 404s on the next. */
  function apiBase() {
    return window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  async function apiGet(path) {
    var r = await fetch(apiBase() + path, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    var j = {};
    try { j = await r.json(); } catch (e) {}
    /* A 403 from EnforceAdminCapability arrives as JSON with a message, and it
       is shown in the words the server chose: "you do not have this capability"
       and "the scan broke" are different problems and an operator has to be
       able to tell them apart. Same reasoning as the SEO Audit tab. */
    if (!r.ok) throw new Error(j && j.message ? j.message : ('Request failed (' + r.status + ')'));
    return j;
  }

  async function apiPost(path, body) {
    var r = await fetch(apiBase() + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': cookie('XSRF-TOKEN')
      },
      body: JSON.stringify(body || {})
    });
    var j = {};
    try { j = await r.json(); } catch (e) {}
    if (!r.ok) throw new Error(j && j.message ? j.message : ('Request failed (' + r.status + ')'));
    return j;
  }

  /* One element, its text set as TEXT. Every value drawn by this file goes
     through here or through .textContent directly — see the header note on
     rule 5. There is deliberately no helper in this file that takes markup, and
     SeoBackOfficeWiringTest reads this file and requires that none appears. */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = String(text);
    return n;
  }

  /* ======================================================================
     2 · THE PREVIEW  ·  window.kbbSeoPreview
     ======================================================================

     opts:
       mount        Element, or a selector string resolved at call time. The
                    preview replaces its contents.
       kind         'home' | 'category' | 'brand' | 'article' — one of
                    Admin\SeoPreviewApiController::KINDS. The server REFUSES an
                    unknown one rather than defaulting, because each kind has
                    different rules about whether the site name is appended and
                    a guessed kind draws a plausible preview of a page that does
                    not exist.
       id           the row's id, or null while it is being created.
       title        selector for the SEO title box.
       description  selector for the SEO description box.
       name         selector for the row's own Name box — only read while
                    creating, when there is no stored row to take it from.
       slug         selector for the address box, same.
       fallback     selector for the row's OWN description box — the category
                    description, the brand description, the article's excerpt.
                    It is what an empty SEO description box publishes, and it is
                    a box on the same form, so reading it out of the table would
                    show the operator last week's answer while he rewrites it in
                    front of him.

     Returns { refresh, destroy }. `refresh` is safe to call at any time; the
     editors re-render their panels, so a caller that has repainted calls
     kbbSeoPreview again rather than holding on to the handle.

     ── WHY IT IS DEBOUNCED AND SEQUENCED ──────────────────────────────────

     It is wired to `input`, so a typist generates a request per keystroke
     without the 300ms gate. And responses can land out of order — a long title
     rendered slowly, followed by a short one rendered fast, would leave the
     LONG one on screen under the short box. `seq` is the guard: a response that
     is not the newest is dropped. Both are ordinary, and both are the reason a
     preview drawn straight from `fetch().then()` eventually shows the wrong
     thing and nobody can reproduce it.  */
  window.kbbSeoPreview = function (opts) {
    opts = opts || {};

    var mount = typeof opts.mount === 'string' ? document.querySelector(opts.mount) : opts.mount;
    if (!mount) return { refresh: function () {}, destroy: function () {} };

    var seq = 0, timer = null, listening = [];

    var wrap = el('div', 's7-prev');
    wrap.appendChild(el('div', 's7-prev-h', 'What Google will show'));

    var snip = el('div', 'seo-snip');
    var uEl = el('div', 'u');
    var tEl = el('div', 't');
    var dEl = el('div', 'd');
    snip.appendChild(uEl); snip.appendChild(tEl); snip.appendChild(dEl);
    wrap.appendChild(snip);

    var meters = el('div', 's7-prev-m');
    var tCount = el('span', 's7-count');
    var dCount = el('span', 's7-count');
    meters.appendChild(tCount); meters.appendChild(dCount);
    wrap.appendChild(meters);

    var note = el('div', 's7-prev-note');
    wrap.appendChild(note);

    mount.textContent = '';
    mount.appendChild(wrap);

    function value(selector) {
      if (!selector) return '';
      var node = document.querySelector(selector);
      return node && node.value != null ? String(node.value) : '';
    }

    function meter(node, label, length, max) {
      node.textContent = label + ' ' + length + ' / ' + max;
      node.className = 's7-count'
        + (length > max ? ' is-bad' : (length > 0 && length < Math.round(max * 0.5) ? ' is-warn' : ''));
    }

    async function draw() {
      var mine = ++seq;

      var payload = {
        kind: opts.kind,
        id: opts.id == null ? null : opts.id,
        title: value(opts.title),
        description: value(opts.description),
        name: value(opts.name),
        slug: value(opts.slug),
        fallback: value(opts.fallback)
      };

      var answer;
      try {
        answer = await apiPost('/seo-preview', payload);
      } catch (e) {
        if (mine !== seq) return;
        /* A preview that cannot be drawn says so. It does NOT fall back to
           showing the box's raw contents, which is the defect this whole
           component exists to fix — an operator shown his own typing under a
           heading that says "what Google will show" has been told something
           false, and would have no way to know. */
        tEl.textContent = '';
        dEl.textContent = '';
        uEl.textContent = '';
        note.textContent = 'Could not draw the preview — ' + e.message;
        return;
      }

      if (mine !== seq) return;

      uEl.textContent = answer.url || '';
      tEl.textContent = answer.title || '';
      dEl.textContent = answer.description || '';

      meter(tCount, 'Title', answer.title_length | 0, answer.title_max | 0);
      meter(dCount, 'Description', answer.description_length | 0, answer.description_max | 0);

      /* The second question a preview answers, and the one a picture alone
         cannot: is this MY wording, or is it what the shop wrote because the
         box is empty? The two look identical in a snippet and are completely
         different facts about the page. */
      var lines = [];

      /* `published_known` is FALSE when a box is empty and there is no page to
         read it off — a row being created, or an article still in draft. The
         preview then shows the best the title engine can do, and says so, rather
         than presenting a near-miss as the finished tag. */
      if (!answer.published_known) {
        lines.push('This page does not exist yet, so the empty boxes cannot be resolved exactly — save it and the real wording appears here.');
      }
      if (!answer.title_is_yours) {
        lines.push('The title box is empty, so this is the wording the shop writes for you.');
      }
      if (!answer.description_is_yours) {
        lines.push((answer.description || '') === ''
          ? 'No description is published for this page at all — Google will write the line under the link itself.'
          : 'The description box is empty, so this is the page’s own wording, or your default description.');
      }
      if ((answer.title_length | 0) > (answer.title_max | 0)) {
        lines.push('The title is longer than Google prints, so the end of it will be cut off.');
      }
      if (!lines.length) {
        lines.push('This is your wording, and this is what Google will print.');
      }

      note.textContent = lines.join(' ');
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(draw, 300);
    }

    [opts.title, opts.description, opts.name, opts.slug, opts.fallback].forEach(function (selector) {
      if (!selector) return;
      var node = document.querySelector(selector);
      if (!node) return;
      node.addEventListener('input', schedule);
      listening.push([node, schedule]);
    });

    draw();

    return {
      refresh: draw,
      destroy: function () {
        clearTimeout(timer);
        seq++;
        listening.forEach(function (pair) { pair[0].removeEventListener('input', pair[1]); });
        listening = [];
      }
    };
  };

  /* ======================================================================
     3 · THE OVERVIEW  ·  window.kbbSeoOverview
     ======================================================================

     Drawn into #seoTabBody, which renderSeo() in the console owns — the same
     contract the other five SEO subtabs have. One line in that function
     dispatches here, and that plus the subtab button is the whole of this
     lane's change to the five tabs that were already there.  */

  /* The urgency words, in the order the to-do list prints them, with the
     heading each band gets. `stop` is its own band and not the top of `do`
     because the two things in it — the whole shop set to noindex, the sitemap
     switched off — make everything else on the screen worthless while they are
     true, and a list that merely puts them first invites reading past them. */
  var URGENCY = [
    ['stop', 'Stop and read this'],
    /* NOT "Waiting on you", which is the CARD's own heading two lines above it —
       the first run drew "What is waiting on you" and then "Waiting on you"
       under it, which reads like a mistake. Caught in the screenshot, which is
       what rule 2 is for. */
    ['do', 'Do these first'],
    ['later', 'Worth doing, not urgent']
  ];

  function fixButton(item) {
    if (!item.go) return null;
    var b = el('button', 'btn ghost sm', 'Take me there');
    b.addEventListener('click', function () {
      /* window.go, not a link. Nine partials in this console wrap window.go
         after boot, so `?go=<id>` is read before some of those wrappers exist
         and silently draws the Dashboard instead — which is why
         tests/browser/lane-m2-module-screens.mjs loads /admin and calls go()
         from the page. A button in a live console has no such problem: by the
         time it can be clicked, every wrapper is installed. */
      try { if (typeof window.go === 'function') window.go(item.go); } catch (e) {}
    });
    return b;
  }

  function whereLine(item) {
    var line = el('div', 's7-where');
    line.appendChild(el('span', null, 'Where: '));
    line.appendChild(el('b', null, item.where || ''));
    return line;
  }

  function taskCard(item) {
    var card = el('div', 's7-item is-' + (item.urgency || 'later'));

    var head = el('div', 's7-item-t');
    head.appendChild(el('b', null, item.title || ''));
    card.appendChild(head);

    card.appendChild(el('div', 's7-cost', item.why || ''));
    if (item.detail) card.appendChild(el('div', 's7-why', item.detail));
    card.appendChild(whereLine(item));

    var button = fixButton(item);
    if (button) {
      var acts = el('div', 's7-acts');
      acts.appendChild(button);
      card.appendChild(acts);
    }

    return card;
  }

  function findingCard(item) {
    var card = el('div', 's7-item is-' + (item.band || 'later'));

    var head = el('div', 's7-item-t');
    head.appendChild(el('b', null, item.label || item.key || ''));
    head.appendChild(el('span', 'pill amber', String(item.count | 0)));
    card.appendChild(head);

    card.appendChild(el('div', 's7-cost', item.cost || ''));
    if (item.why) card.appendChild(el('div', 's7-why', item.why));
    card.appendChild(whereLine(item));

    var button = fixButton(item);
    if (button) {
      var acts = el('div', 's7-acts');
      acts.appendChild(button);
      card.appendChild(acts);
    }

    var samples = item.samples || [];
    if (samples.length) {
      var list = el('div', 's7-samples');
      samples.forEach(function (s) {
        var row = el('div', 's7-row');
        row.appendChild(el('span', 'pill', s.kind || ''));
        row.appendChild(el('span', 'nm', s.name || s.url || ''));
        if (s.detail) row.appendChild(el('span', 'dt', s.detail));
        row.appendChild(el('span', 'ur', s.url || ''));
        list.appendChild(row);
      });
      card.appendChild(list);

      if ((item.count | 0) > samples.length) {
        card.appendChild(el('p', 's7-more', '+ ' + ((item.count | 0) - samples.length) + ' more, not shown.'));
      }
    }

    return card;
  }

  window.kbbSeoOverview = async function () {
    var body = document.getElementById('seoTabBody');
    if (!body) return;

    body.textContent = '';
    body.appendChild(el('p', 's7-empty', 'Reading the shop…'));

    var data;
    try {
      data = await apiGet('/seo-tasks');
    } catch (e) {
      body.textContent = '';
      var bad = el('div', 's7-card');
      bad.appendChild(el('p', 's7-h', 'Could not read the shop'));
      bad.appendChild(el('p', 's7-sub', e.message));
      body.appendChild(bad);
      return;
    }

    var health = data.health || {};
    var tasks = data.tasks || [];

    body.textContent = '';
    var wrap = el('div', 's7-wrap');

    /* ---- the headline ------------------------------------------------------
       The audit's own verdict line, passed through untouched. It already names
       the biggest finding and its count rather than printing a score, and its
       own comment says why: "a score is a number nobody knows what to do with
       and '37 products have no description' is a morning's work with an obvious
       beginning". Rewriting it here would be a second opinion about one scan. */
    var top = el('div', 's7-card');
    top.appendChild(el('p', 's7-verdict', health.verdict || ''));
    top.appendChild(el('p', 's7-sub',
      'Everything on this tab is read from the shop as it is right now. Nothing here changes a setting — '
      + 'each item says where to go and what it costs you to leave it.'));

    var chips = el('div', 's7-chips');
    Object.keys(health.scanned || {}).forEach(function (k) {
      chips.appendChild(el('span', 'pill', k + ' ' + ((health.scanned[k]) | 0)));
    });
    if (chips.childNodes.length) top.appendChild(chips);

    top.appendChild(el('p', 's7-sub',
      'Pages you have told Google to skip are left out on purpose — a page you asked it to ignore is not a page with a problem.'));
    wrap.appendChild(top);

    /* ---- what is waiting on him ------------------------------------------
       FIRST, above the audit, and that order is the point. The audit reports
       work on rows that already exist; this reports features that are built and
       idle because nobody has told him they are waiting. A concern page that
       404s until three products are tagged has no symptom at all — it is not a
       broken page, it is an absent one, and an audit cannot see it. */
    var todo = el('div', 's7-card');
    todo.appendChild(el('p', 's7-h', 'What is waiting on you'));
    todo.appendChild(el('p', 's7-sub',
      'Things this shop has already built and cannot finish without you. Each one disappears from this list the moment it is done.'));

    if (!tasks.length) {
      todo.appendChild(el('p', 's7-clear', 'Nothing. Every setting these features need is filled in.'));
    } else {
      URGENCY.forEach(function (pair) {
        var rows = tasks.filter(function (t) { return (t.urgency || 'later') === pair[0]; });
        if (!rows.length) return;

        var band = el('div', 's7-band');
        band.appendChild(el('div', 's7-band-h', pair[1]));
        rows.forEach(function (t) { band.appendChild(taskCard(t)); });
        todo.appendChild(band);
      });
    }
    wrap.appendChild(todo);

    /* ---- the audit, ranked ------------------------------------------------ */
    var audit = el('div', 's7-card');
    audit.appendChild(el('p', 's7-h', 'What is wrong on the pages you already have'));
    audit.appendChild(el('p', 's7-sub',
      'The same scan as the SEO Audit tab, sorted by what each one costs you rather than by the order the checks were written. '
      + 'Open that tab for the full list including every check that came back clean.'));

    var drew = false;

    (health.bands || []).forEach(function (band) {
      var items = band.items || [];
      if (!items.length) return;

      drew = true;
      var group = el('div', 's7-band');
      group.appendChild(el('div', 's7-band-h', band.heading || ''));
      items.forEach(function (item) {
        item.band = band.band;
        group.appendChild(findingCard(item));
      });
      audit.appendChild(group);
    });

    if (!drew) {
      audit.appendChild(el('p', 's7-clear',
        'Nothing. Every check came back clean on every product, category, brand, article and page the shop publishes.'));
    }

    var clear = health.clear || [];
    if (clear.length) {
      audit.appendChild(el('p', 's7-clear', 'Clean, with nothing to do: ' + clear.join(' · ') + '.'));
    }

    wrap.appendChild(audit);
    body.appendChild(wrap);
  };
})();
</script>
@endverbatim
