{{--
    Reviews - the sidebar queue badge (Lane CG).

    Pulled into resources/views/admin/app.blade.php at the very end, after that
    file closes its raw block, so this runs once buildNav() has drawn the
    sidebar.

    WHAT THIS REPLACED. app.blade.php's NAV const carried the All Reviews row as

        ['rev-all','All Reviews','<path .../>','3']

    and navItemHTML() prints a fourth element as a count chip, with buildNav()
    summing the numeric ones into the group's own badge. The 3 was a literal.
    Seeded with the demo content this repo ships, the sidebar said "3" beside
    All Reviews and "3" beside Reviews while twelve reviews were waiting for a
    decision, and it said 3 on a shop with none waiting at all.

    A number in a menu is a promise that it is the number. This one had never
    been read from anything. The literal is gone from NAV and this file puts the
    real figure there: reviews.status = 'pending', counted by the same endpoint
    the All Reviews screen itself uses, so the chip and the "Waiting" chip on
    that screen can never disagree.

    NO NAV ENTRY IS ADDED HERE and no screen is claimed. This file defines no
    addNavEntry(), touches no route, and wraps window.go only to re-read the
    count after the owner has been moderating.

    IT IS ALLOWED TO FAIL QUIETLY. If the endpoint is not mounted, or the role
    reaching it does not hold reviews.view, no badge is drawn — which is what
    the sidebar looked like before the literal was added, and is honest. It
    never prints a guess.

    NOTHING BELOW THIS COMMENT MAY NAME BLADE'S RAW-BLOCK DIRECTIVES, and
    neither may this comment. Blade pairs the first such opening directive it
    finds anywhere in the file -- inside a comment included -- with the next
    closing one, so writing the word in prose swallows everything between them
    and the whole docblock is served to the browser as visible text.
--}}
@verbatim
<script>
(function(){
  'use strict';

  var BASE = window.location.pathname.replace(/\/+$/, '');
  var REFRESH_MS = 15000;   // a floor on how often go() may re-ask
  var last = 0;
  var inFlight = false;

  function cookie(n){
    var m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }

  /* The console lives one segment below the site root (its path is the secret
     admin path), so the API prefix is this page's path with its last segment
     removed. Identical to the sibling review screens rather than re-derived. */
  function apiBase(){
    return BASE.replace(/\/[^\/]*$/, '') + '/admin-api';
  }

  /* per_page=1 because the rows are not wanted — only counts.pending, which
     ReviewsApiController computes over the whole filtered set regardless of the
     page size. One row comes back instead of twenty-five. */
  async function fetchPending(){
    var r = await fetch(apiBase() + '/reviews/list?per_page=1', {
      credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN')}
    });

    if (!r.ok) throw new Error('reviews/list -> ' + r.status);

    var body = await r.json();
    var n = body && body.counts ? body.counts.pending : null;

    return typeof n === 'number' ? n : null;
  }

  /* One chip on the row, one on the group header. Both are written with
     textContent once they exist, so a count can never be markup. */
  function paint(n){
    var row = document.querySelector('#nav [data-go="rev-all"]');
    if (!row) return;

    var chip = row.querySelector('.cnt');

    if (n > 0) {
      if (!chip) {
        row.insertAdjacentHTML('beforeend', '<span class="cnt"></span>');
        chip = row.querySelector('.cnt');
      }
      chip.textContent = String(n);
      chip.title = n === 1 ? '1 review waiting for a decision' : n + ' reviews waiting for a decision';
    } else if (chip) {
      chip.remove();
    }

    var group = row.closest('.nav-group');
    if (!group) return;

    var header = group.querySelector('.nav-gh');
    if (!header) return;

    var badge = header.querySelector('.gh-badge');

    if (n > 0) {
      if (!badge) {
        /* Before the chevron, which is where buildNav() puts it — appended
           instead, it lands on the wrong side of the arrow. */
        var chev = header.querySelector('.chev');
        if (chev) chev.insertAdjacentHTML('beforebegin', '<span class="gh-badge"></span>');
        else header.insertAdjacentHTML('beforeend', '<span class="gh-badge"></span>');
        badge = header.querySelector('.gh-badge');
      }
      if (badge) badge.textContent = String(n);
    } else if (badge) {
      badge.remove();
    }
  }

  async function refresh(force){
    var now = Date.now();
    if (inFlight) return;
    if (!force && now - last < REFRESH_MS) return;

    inFlight = true;
    last = now;

    try {
      var n = await fetchPending();
      if (n !== null) paint(n);
    } catch (e) {
      /* Quiet on purpose. A badge is not worth a toast, and a wrong badge is
         worse than none — see the note at the top of this file. */
    } finally {
      inFlight = false;
    }
  }

  /* Re-read after the owner has been moderating. go() is the only moment worth
     hooking: approving a review is what changes this number, and leaving the
     screen is when the sidebar is looked at again. Throttled, so clicking
     around the console is not one request per click. */
  var previousGo = window.go;

  window.go = function(){
    var out = previousGo.apply(this, arguments);
    setTimeout(function(){ refresh(false); }, 0);
    return out;
  };

  function boot(){ refresh(true); }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
</script>
@endverbatim
