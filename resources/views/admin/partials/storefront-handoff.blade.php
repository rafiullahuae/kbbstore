{{--
    The storefront admin bar's hand-off into the console. (Lane RA)

    The bar on the shop says "Edit category", "Edit brand" or "Edit product",
    and the owner expects that to open THAT record, not a list he then has to
    search. The links read

        /{admin}?kbb-open=category:12#catalog/categories
        /{admin}?kbb-open=brand:7#catalog/brands
        /{admin}?kbb-open=product:345#catalog/products

    The `#…` half is the console's own deep link (Lane DA) and works on its own:
    without this partial the owner still lands on the right list. This partial
    reads the `kbb-open` half once, after every screen partial has installed
    itself, and opens the record through the hooks those screens already
    expose:

        product   window.peoEdit(id)                     product-editor-screen
        category  window.go('category-tree'), then
                  window.__ctReload() and __ctEditor(id)  category-tree-screen
        brand     window.go('brands-manager'), then
                  window.KbbBrandEditor.open(row)        brands-editor-screen

    Each step is guarded: a hook that is missing leaves the owner on the list
    the hash already opened, which is exactly where he was before. The query
    parameter is removed from the address afterwards (replaceState), so a
    reload does not open the editor a second time.

    INTEGRATOR: include once in resources/views/admin/app.blade.php, AFTER the
    category-tree, brands-editor and product-editor partials -- the last
    @include in that file is the natural place:

        @include('admin.partials.storefront-handoff')
--}}
@verbatim
<script>
(function(){
  'use strict';
  var m = /^(category|brand|product):([0-9]{1,10})$/.exec(
    (function(){ try { return new URLSearchParams(window.location.search).get('kbb-open') || ''; } catch (e) { return ''; } })()
  );
  if (!m) return;
  var kind = m[1], id = Number(m[2]);

  function forget(){
    try {
      var u = new URL(window.location.href);
      u.searchParams.delete('kbb-open');
      history.replaceState(history.state, '', u.pathname + (u.search || '') + u.hash);
    } catch (e) {}
  }

  function api(path){
    // The console lives at /{base}/{admin}; its API at /{base}/admin-api --
    // the same derivation brands-editor-screen's endpoint() uses.
    var base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
    return fetch(base + path, {
      credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
      if (r.ok) return r.json();
      return r.json().catch(function(){ return null; }).then(function(body){
        /* A 404 whose JSON says nothing at all -- Laravel's {"message": ""} --
           is the compiled route table not knowing the path; a controller's own
           404 carries a message or an error, and that is not ours to explain. */
        var silent = r.status === 404 && !(body && (body.message || body.error));
        say(silent
          ? 'The Brands endpoints are not in this server\'s compiled route table yet. Clear the route cache (Platform \u2192 Cache) and reload.'
          : 'The brand could not be opened: ' + ((body && (body.message || body.error)) || ('the server answered ' + r.status)) + '.');
        return null;
      });
    });
  }

  function say(text){
    try { if (typeof window.toast === 'function') window.toast(text, 'bad'); } catch (e) {}
  }

  function open(){
    forget();
    try {
      if (kind === 'product' && typeof window.peoEdit === 'function') {
        window.peoEdit(id);
        return;
      }
      if (kind === 'category' && typeof window.go === 'function' && typeof window.__ctReload === 'function') {
        window.go('category-tree');
        Promise.resolve(window.__ctReload()).then(function(){
          if (typeof window.__ctEditor === 'function') window.__ctEditor(id);
        }).catch(function(){});
        return;
      }
      if (kind === 'brand' && typeof window.go === 'function' && window.KbbBrandEditor) {
        window.go('brands-manager');
        api('/brands').then(function(d){
          var rows = (d && d.brands) || [];
          for (var i = 0; i < rows.length; i++) {
            if (Number(rows[i].id) === id) { window.KbbBrandEditor.open(rows[i]); return; }
          }
        }).catch(function(){});
      }
    } catch (e) { /* the list the hash opened is still on screen */ }
  }

  /* After the console's own deep-link replay, which queues itself from
     DOMContentLoaded with setTimeout(0): one more tick puts this behind it. */
  function arm(){ setTimeout(function(){ setTimeout(open, 0); }, 0); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arm);
  else arm();
})();
</script>
@endverbatim
