#!/usr/bin/env python3
"""
Apply the nine console edits to a SCRATCH tree.                     (Lane SEC)

    python3 tools/sec-apply-blocks.py
    ...run the guards, take the screenshots...
    git checkout -- resources/views/admin/app.blade.php

── WHAT THIS IS FOR, AND WHAT IT IS NOT ────────────────────────────────────

It is NOT part of the package and it is not how the edits ship.
docs/SEC-ADMIN-APP-BLOCKS.md is the record the integrator applies, by hand,
with its anchors and replacements quoted in full. This file and that document
carry the SAME strings, and `--check` proves it.

It exists because a lane that cannot edit resources/views/admin/app.blade.php
still owes an answer to "and does it go green afterwards?" -- and because the
pictures the owner asked for are pictures of the FIXED console, which does not
exist until these are applied.

It asserts each anchor occurs EXACTLY ONCE before it touches anything, so a
console another lane has since edited stops it rather than silently producing a
half-applied file.

▲ IT EDITS A FILE THIS LANE MAY NOT SHIP. Revert it the moment you are done --
`git checkout -- resources/views/admin/app.blade.php` -- and check `git status`
before committing.
"""

import re
import sys

APP = 'resources/views/admin/app.blade.php'
DOC = 'docs/SEC-ADMIN-APP-BLOCKS.md'

# ---------------------------------------------------------------------------
# Block 1 - the gate itself, in front of api(), which is where fixAdminApiUrl()
# and cookie() already live. Declared in the same IIFE every one of the nine
# call sites is in, so nothing is hung on `window`.
# ---------------------------------------------------------------------------

GATE = r'''  /* ══════════════════════════════════════════════════════════════════════════
     THE DOWNLOAD GATE                                             (Lane SEC)
     ══════════════════════════════════════════════════════════════════════════

     Eleven addresses in this console are reached by NAVIGATING the browser at
     them rather than by fetch: the four CSV exports, the bulk-documents page,
     the four order documents, and the two OAuth /start legs. An admin-api
     address that does not carry the secret admin path answers a signed-out
     browser with a plain 404, because the 302 it used to answer with named
     `admin_path` in a Location header to anyone who typed the prefix.

     So a navigation on a dead session used to land on the admin login, where
     the owner signed back in, and now lands on a blank 404. For the four
     `window.location.href` sites that takes THE WHOLE CONSOLE away with it and
     loses the list he was standing on -- strictly worse than what it replaced,
     and the reason this exists.

     ── SO THE BUTTON ASKS FIRST ──────────────────────────────────────────────

     `?probe=1` on the download's OWN address. App\Support\ExportProbe answers
     `{"ok":true}` and nothing else, as the first statement of the action, so no
     query is run and no OAuth state is minted -- and it passes through that
     action's own capability, because AdminCapabilities matches on the route's
     URI and a query string is not part of it. This therefore answers "may THIS
     operator run THIS download", which a shared liveness endpoint could not.
     `/admin-api/health` was considered and rejected: `throttle:6,1` would
     refuse the fourth export in a minute.

     ── AND NOT A BLOB, WHICH IS MEASURED AND NOT ASSERTED ────────────────────

     The obvious repair is to fetch the file through api() and hand over a blob
     URL. The call sites' own comment refuses it -- "the file lands in Downloads
     instead of in memory" -- and all four CSV exports return a StreamedResponse.
     Measured on this tree: at the live shop's 3,025 products the catalogue
     export is 619,968 bytes and 645 ms, and the probe that guards it is 11
     BYTES and 35 ms. The orders export measures 153 bytes per order, which is
     the one that grows without bound as the shop takes orders.

     SO THE HONEST ANSWER IS THAT 0.59 MB IS NOT RUINOUS, and the case for the
     probe does not rest on memory. It rests on CLAUDE.md rule 1: a blob is a
     different behaviour from a streamed download, the comment at every call
     site promises the streamed one, and 35 ms buys leaving a working thing
     exactly as it is.
     ══════════════════════════════════════════════════════════════════════════ */

  /* Where the sign-in page is. Derived off window.location.pathname, the way
     fixAdminApiUrl() already derives the api base -- the console is served AT
     the secret admin path, so the browser is standing on it already and reading
     it back discloses nothing. NEVER from a setting, and never interpolated
     into markup: it is assigned to location.href and nowhere else. */
  function kbbAdminLoginUrl(){
    return window.location.pathname.replace(/\/+$/,'') + '/login';
  }

  /* Ask, and classify. NEVER THROWS, so no caller needs a try. */
  async function kbbProbeDownload(url){
    var r;
    try{
      r = await fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'probe=1',
        {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
    }catch(e){
      /* The request never reached a server. Reported as ITSELF and not as a
         dead session: "sign in again" is the wrong remedy for a dropped
         connection, and it is the one sentence that would send the owner to
         re-type his password over his own wifi. */
      return {verdict:'unreachable', status:0};
    }
    if(r.ok)                                 return {verdict:'ok',        status:r.status};
    if(r.status === 401 || r.status === 419)  return {verdict:'signedout', status:r.status};
    if(r.status === 403)                     return {verdict:'forbidden', status:r.status};
    return {verdict:'other', status:r.status};
  }

  /* What the console says, WITH THE CONSOLE STILL ON SCREEN. Every string here
     is a constant. `opened` is true for the window.open sites, where the tab is
     already up and the sentence has to be about the tab rather than about a
     download that never started. */
  function kbbSayDownloadRefused(answer, opened){
    if(answer.verdict === 'signedout'){
      openModal('<div class="modal-h"><b>Your session has ended</b>' +
        '<button class="x" onclick="closeModal()">✕</button></div>' +
        '<div class="modal-b"><p style="font-size:13px;color:var(--ink-2)">' +
        (opened
          ? 'You are signed out, so that document could not be opened.'
          : 'You are signed out, so the download was not started — nothing was sent.') +
        '</p>' +
        '<p style="font-size:12.5px;color:var(--ink-soft);margin-top:8px">' +
        'Sign in again and this screen comes back exactly as it is, with your ' +
        'filters and ticks where you left them.</p>' +
        '<div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px">' +
        '<button class="btn ghost" onclick="closeModal()">Stay here</button>' +
        '<button class="btn" id="kbbSessionSignIn">Sign in again</button></div></div>');
      var go = document.getElementById('kbbSessionSignIn');
      if(go) go.onclick = function(){ window.location.href = kbbAdminLoginUrl(); };
      return;
    }
    if(answer.verdict === 'forbidden'){
      toast('Your account is not allowed to download this. Nothing was sent.', 'bad');
      return;
    }
    if(answer.verdict === 'unreachable'){
      toast('Could not reach the server, so the download was not started.', 'bad');
      return;
    }
    toast('The server refused that download (' + answer.status + '). Nothing was sent.', 'bad');
  }

  /* AWAIT THIS BEFORE a window.location.href. Nothing is navigated unless the
     answer is yes, which is what keeps the console on the screen. */
  async function kbbDownloadOk(url){
    var answer = await kbbProbeDownload(url);
    if(answer.verdict === 'ok') return true;
    kbbSayDownloadRefused(answer, false);
    return false;
  }

  /* AND THIS AFTER a window.open, which is the shape that cannot wait: a popup
     opened from an async continuation is blocked by the browser, so the tab has
     to be opened inside the click and the question asked behind it. `win` is
     the handle when there is one, and then the dead window is closed rather
     than left for the owner to find; window.open with 'noopener' returns none,
     so the four order documents are told after the fact and no more. */
  function kbbTellIfDownloadRefused(url, win){
    kbbProbeDownload(url).then(function(answer){
      if(answer.verdict === 'ok') return;
      if(win){ try{ win.close(); }catch(e){} }
      kbbSayDownloadRefused(answer, true);
    });
  }

'''

BLOCKS = []

BLOCKS.append(('1 · the gate', "  async function api(url, opts){", GATE + "  async function api(url, opts){"))

# ---------------------------------------------------------------------------
# Blocks 2-5 - the four window.location.href sites. THE URGENT HALF: these do
# not fail into a dead tab, they navigate the whole console away. The handler
# becomes async and awaits the gate; the comment that explains why this is a
# navigation and not a fetch is kept word for word.
# ---------------------------------------------------------------------------

for label, params, path in [
    ('2 · Orders export',    'olParams',  '/admin-api/orders-export'),
    ('3 · Customers export', 'cuParams',  '/admin-api/customers/export'),
    ('5 · Catalogue export', 'cpParams',  '/admin-api/catalog-products-export'),
]:
    anchor = (
        "    if(exportBtn) exportBtn.onclick = function(){\n"
        "      /* A normal navigation, not a fetch: the browser carries the same admin\n"
        "         session cookie, the server refuses anyone without it, and the file\n"
        "         lands in Downloads instead of in memory. */\n"
        "      var qs = %s(true);\n"
        "      window.location.href = fixAdminApiUrl('%s') + (qs ? '?' + qs : '');\n"
        "    };" % (params, path)
    )
    replacement = (
        "    if(exportBtn) exportBtn.onclick = async function(){\n"
        "      /* A normal navigation, not a fetch: the browser carries the same admin\n"
        "         session cookie, the server refuses anyone without it, and the file\n"
        "         lands in Downloads instead of in memory.\n"
        "\n"
        "         AND THE GATE IS AWAITED IN FRONT OF IT. (Lane SEC) This line is a\n"
        "         navigation of the WHOLE CONSOLE, so a dead session took the screen\n"
        "         away and lost the filters and ticks on it. kbbDownloadOk() asks the\n"
        "         export itself first and says so on the console instead. */\n"
        "      var qs = %s(true);\n"
        "      var url = fixAdminApiUrl('%s') + (qs ? '?' + qs : '');\n"
        "      if(!(await kbbDownloadOk(url))) return;\n"
        "      window.location.href = url;\n"
        "    };" % (params, path)
    )
    BLOCKS.append((label, anchor, replacement))

# Block 4 - Reviews. Its handler has no comment and builds the query inline, so
# it is its own block rather than a fourth pass of the loop above.
BLOCKS.append((
    '4 · Reviews export',
    "    if(exp) exp.onclick = function(){\n"
    "      window.location.href = fixAdminApiUrl('/admin-api/reviews/export?' + rvParams(true));\n"
    "    };",
    "    if(exp) exp.onclick = async function(){\n"
    "      /* A navigation of the WHOLE CONSOLE, gated first. (Lane SEC) The other\n"
    "         four exports carry a comment saying why this is a navigation and not a\n"
    "         fetch; this one never did, so it is said here: the browser carries the\n"
    "         admin session cookie, the response is streamed, and the file lands in\n"
    "         Downloads instead of in the tab's memory. */\n"
    "      var url = fixAdminApiUrl('/admin-api/reviews/export?' + rvParams(true));\n"
    "      if(!(await kbbDownloadOk(url))) return;\n"
    "      window.location.href = url;\n"
    "    };",
))

# ---------------------------------------------------------------------------
# Block 6 - the bulk documents. window.open MUST stay inside the click, which
# the function's own comment says, so the tab opens first and the question is
# asked behind it. 'noopener' means there is no handle to close.
# ---------------------------------------------------------------------------

BLOCKS.append((
    '6 · Bulk order documents',
    "    window.open(\n"
    "      fixAdminApiUrl('/admin-api/orders-bulk-documents') +\n"
    "        '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(','),\n"
    "      '_blank',\n"
    "      'noopener'\n"
    "    );",
    "    /* Gated AFTER THE FACT, and that is the honest shape here. (Lane SEC)\n"
    "       The comment above is the constraint: window.open has to happen inside\n"
    "       the click, so nothing can be awaited in front of it. The tab opens,\n"
    "       and the console -- which is still on the screen behind it -- is told\n"
    "       whether it was ever going to work. 'noopener' returns no handle, so\n"
    "       the dead tab cannot be closed from here and the modal says so. */\n"
    "    var url = fixAdminApiUrl('/admin-api/orders-bulk-documents') +\n"
    "      '?type=' + encodeURIComponent(type) + '&ids=' + ids.join(',');\n"
    "\n"
    "    window.open(url, '_blank', 'noopener');\n"
    "    kbbTellIfDownloadRefused(url);",
))

# ---------------------------------------------------------------------------
# Block 7 - the four order documents. THE ADDRESS IS NEVER WRITTEN IN THIS
# FILE: it arrives on the order-detail payload from Admin\InvoiceController::
# invoiceUrl() and friends, which is why no scan of the console found these
# four and why ServerBuiltAdminUrlsTest reads the server instead.
# ---------------------------------------------------------------------------

BLOCKS.append((
    '7 · The four order documents',
    "        if (url) { window.open(url, '_blank', 'noopener'); return; }",
    "        /* Gated after the fact, same constraint as the bulk documents above:\n"
    "           the popup has to be opened inside the click. (Lane SEC) THE ADDRESS\n"
    "           IS NOT WRITTEN IN THIS FILE -- it arrives on the payload from\n"
    "           Admin\\InvoiceController::invoiceUrl() and its three siblings, which\n"
    "           is why no scan of the console found these four and why\n"
    "           ServerBuiltAdminUrlsTest reads the SERVER for them instead. */\n"
    "        if (url) {\n"
    "          window.open(url, '_blank', 'noopener');\n"
    "          kbbTellIfDownloadRefused(url);\n"
    "          return;\n"
    "        }",
))

# ---------------------------------------------------------------------------
# Block 8 - the Stripe Connect popup. A TENTH NAVIGATION, and the only one that
# keeps a window handle, so the dead popup is closed rather than left up.
# ---------------------------------------------------------------------------

BLOCKS.append((
    '8 · Stripe Connect popup',
    "    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }",
    "    try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }\n"
    "\n"
    "    /* A TENTH NAVIGATION, and the one that can be cleaned up. (Lane SEC)\n"
    "       This address is admin-guarded, so an expired session shows a blank 404\n"
    "       in the popup with nothing said on the console behind it. Unlike the\n"
    "       order documents this one KEEPS THE HANDLE, so the dead window is\n"
    "       closed rather than left for the owner to find. Asked after the open\n"
    "       for the reason the comment above gives: a round trip in front of\n"
    "       window.open is what the popup blocker is looking for. */\n"
    "    if(w) kbbTellIfDownloadRefused(url, w);",
))

# ---------------------------------------------------------------------------
# Block 9 - TASK 2. The Catalog list answers EVERY failure with "the routes may
# not be wired into routes/web.php yet", including a 401, so an expired session
# sends the owner to Store -> Cache. product-editor-screen.blade.php:1026 has
# had the remedy for a while; this is the same one line.
# ---------------------------------------------------------------------------

BLOCKS.append((
    '9 · The Catalog list blames route wiring for a 401',
    "    }catch(e){\n"
    "      CP.err = e && e.message ? e.message : 'unknown error';\n"
    "      body.innerHTML = '<div class=\"card pad\"><p style=\"color:var(--sale,#c0392b);font-size:13px\">' +\n"
    "        'The product list could not be loaded — ' + sesc(CP.err) + '</p>' +\n"
    "        '<p style=\"font-size:12.5px;color:var(--ink-soft);margin-top:8px\">If this is a fresh deployment, the ' +\n"
    "        'Catalog → Products routes may not be wired into routes/web.php yet.</p></div>';\n"
    "      return;\n"
    "    }",
    "    }catch(e){\n"
    "      /* SAY WHICH FAILURE IT WAS. (Lane SEC) This screen answered every\n"
    "         refusal with \"the routes may not be wired into routes/web.php yet\",\n"
    "         which for an EXPIRED SESSION sends the owner to Store -> Cache to\n"
    "         clear a route cache that is perfectly fine, while the one thing that\n"
    "         would fix it -- signing in again -- is never mentioned. The route\n"
    "         sentence is kept for the fault it was written for, and is now shown\n"
    "         only for that fault. Worded the way\n"
    "         admin/partials/product-editor-screen.blade.php already words it. */\n"
    "      CP.err = e && e.message ? e.message : 'unknown error';\n"
    "\n"
    "      if(e && (e.status === 401 || e.status === 419)){\n"
    "        body.innerHTML = '<div class=\"card pad\"><p style=\"color:var(--sale,#c0392b);font-size:13px\">' +\n"
    "          'Your session has ended, so this list could not be loaded.</p>' +\n"
    "          '<p style=\"font-size:12.5px;color:var(--ink-soft);margin-top:8px\">' +\n"
    "          'Sign in again and it loads as it did.</p></div>';\n"
    "        return;\n"
    "      }\n"
    "\n"
    "      body.innerHTML = '<div class=\"card pad\"><p style=\"color:var(--sale,#c0392b);font-size:13px\">' +\n"
    "        'The product list could not be loaded — ' + sesc(CP.err) + '</p>' +\n"
    "        (e && e.status === 404\n"
    "          ? '<p style=\"font-size:12.5px;color:var(--ink-soft);margin-top:8px\">If this is a fresh deployment, the ' +\n"
    "            'Catalog → Products routes may not be wired into routes/web.php yet.</p>'\n"
    "          : '') + '</div>';\n"
    "      return;\n"
    "    }",
))

# ---------------------------------------------------------------------------
# Block 10 - SIGN OUT DID NOT SIGN ANYBODY OUT. Found while measuring the gate,
# same shape as the nine, and the worst of them: both addresses were literals,
# so on any shop whose admin path has been moved -- which is the whole point of
# the setting, and a control the owner has on Store -> Core Updates -- the POST
# 404s, the catch swallows it, the navigation lands on a second 404, and the
# session is STILL ALIVE. Measured on a preview at /sec-console.
# ---------------------------------------------------------------------------

BLOCKS.append((
    '10 · Sign out did not sign anybody out',
    "      b.onclick=async function(){\n"
    "        try{ await fetch('/admin/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}\n"
    "        location.href='/admin/login';\n"
    "      };",
    "      b.onclick=async function(){\n"
    "        /* THE ADMIN PATH IS NOT ALWAYS \"admin\". (Lane SEC) Both addresses\n"
    "           below were literals, and routes/web.php answers `/admin/{any?}`\n"
    "           with abort(404) the moment the owner moves his admin path -- which\n"
    "           is the point of the setting, and a control he has on Store -> Core\n"
    "           Updates. So this button posted to a 404, SWALLOWED IT, and then\n"
    "           navigated to a second 404.\n"
    "\n"
    "           MEASURED, on a preview whose admin lives at /sec-console:\n"
    "           POST /admin/logout -> 404, GET /admin/login -> 404, and\n"
    "           /admin-api/stats answered 200 immediately afterwards. He pressed\n"
    "           Sign out, was shown a blank 404, AND WAS STILL SIGNED IN -- on a\n"
    "           shared machine that is the whole of the damage.\n"
    "\n"
    "           Derived off window.location.pathname, the way fixAdminApiUrl()\n"
    "           already derives the api base: this console is served AT the admin\n"
    "           path, so the browser is standing on it and nothing is disclosed by\n"
    "           reading it back. Never from a setting. */\n"
    "        var base=window.location.pathname.replace(/\\/+$/,'');\n"
    "        try{ await fetch(base+'/logout',{method:'POST',headers:{'X-XSRF-TOKEN':cookie('XSRF-TOKEN')},credentials:'same-origin'}); }catch(e){}\n"
    "        location.href=base+'/login';\n"
    "      };",
))


def verify(source):
    bad = []
    for label, anchor, _ in BLOCKS:
        n = source.count(anchor)
        if n != 1:
            bad.append('block %s: anchor occurs %d times, expected exactly 1' % (label, n))
    return bad


def main():
    source = open(APP, encoding='utf-8').read()

    bad = verify(source)
    if bad:
        print('REFUSING TO TOUCH ANYTHING:')
        for b in bad:
            print('  ' + b)
        sys.exit(1)

    if '--check' in sys.argv:
        # Every anchor and replacement must appear in the handover document
        # VERBATIM, or the integrator is applying something else.
        doc = open(DOC, encoding='utf-8').read()
        missing = []
        for label, anchor, replacement in BLOCKS:
            if anchor not in doc:
                missing.append('block %s: ANCHOR not in %s' % (label, DOC))
            if replacement not in doc:
                missing.append('block %s: REPLACEMENT not in %s' % (label, DOC))
        if missing:
            print('\n'.join(missing))
            sys.exit(1)
        print('all %d anchors occur exactly once, and %s quotes every block verbatim' % (len(BLOCKS), DOC))
        return

    for label, anchor, replacement in BLOCKS:
        source = source.replace(anchor, replacement, 1)

    open(APP, 'w', encoding='utf-8').write(source)
    print('applied %d blocks to %s' % (len(BLOCKS), APP))
    print('REVERT WITH: git checkout -- ' + APP)


if __name__ == '__main__':
    main()
