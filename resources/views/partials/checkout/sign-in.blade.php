{{--
    The checkout's "Sign in" window (Lane CO). The owner, 9 October:

      "I need a Sign in text on the right side of the Contact block title ...
       upon click it will open a nice on screen popup with the quick login
       form, without create account option in that popup. forgot password can
       be there, but with a instant switch form between login and forgot form
       ... upon successful login, the popup need to close automatically with a
       nice closing effect ... or display a green tick and close the popup."

    Rendered only for a guest, and only while Appearance -> Checkout page ->
    Coupon, express & sign in -> "Sign in beside the Contact heading" is on.

    A NATIVE <dialog>, opened with showModal(): the browser traps focus inside
    it, makes the page behind it inert, closes it on Esc and gives it a
    backdrop -- none of which this file has to imitate. OUTSIDE the checkout
    <form>, because a form inside a form is not a form.

    No request until the shopper submits. Both endpoints (routes/checkout-sign-
    in.php) run the account pages' own sign-in and reset code; the JSON they
    answer with is an explicit allowlist of field values plus the new CSRF
    token. The script is in the view for the reason partials/checkout/express-
    wallets gives at length: a package carries a view on its own.

    Without the script, or in a browser with no <dialog>, the link is a plain
    link to /my-account/, which is the full sign-in page.
--}}
@php
    /* The page's own field shape: floating labels while Appearance -> Checkout
       page -> Fields & attention -> "Floating labels" is on, a label above the
       box while it is off -- so the window never looks unlike the form behind it. */
    $kbbSiFloat = app(\App\Services\CheckoutPage::class)->floatLabels();
@endphp
<dialog class="co-si" id="kbbSignIn" aria-labelledby="kbbSiT1">
    <div class="co-si-in">
        <button type="button" class="co-si-x" data-si-close aria-label="{{ __('store.checkout.signin_close') }}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        <div data-si-pane="login">
            <h2 class="co-si-h" id="kbbSiT1">{{ __('store.checkout.signin_title') }}</h2>
            <p class="co-si-lead">{{ __('store.checkout.signin_lead') }}</p>
            <form data-si-form="login" action="{{ \App\Support\Url::to('/checkout/sign-in') }}" method="post">
                <p class="form-row">@if ($kbbSiFloat)<span class="fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-mail')</span><input type="email" class="input-text" name="email" id="kbbSiEmail" autocomplete="username" inputmode="email" maxlength="254" required placeholder=" "><label for="kbbSiEmail">{{ __('store.checkout.signin_email') }}</label></span>@else<label for="kbbSiEmail">{{ __('store.checkout.signin_email') }}</label><span class="woocommerce-input-wrapper"><input type="email" class="input-text" name="email" id="kbbSiEmail" autocomplete="username" inputmode="email" maxlength="254" required></span>@endif</p>
                <p class="form-row">@if ($kbbSiFloat)<span class="fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-lock')</span><input type="password" class="input-text" name="password" id="kbbSiPw" autocomplete="current-password" maxlength="200" required placeholder=" "><label for="kbbSiPw">{{ __('store.checkout.signin_password') }}</label></span>@else<label for="kbbSiPw">{{ __('store.checkout.signin_password') }}</label><span class="woocommerce-input-wrapper"><input type="password" class="input-text" name="password" id="kbbSiPw" autocomplete="current-password" maxlength="200" required></span>@endif</p>
                <p class="co-si-err" data-si-err role="alert" hidden></p>
                <button type="submit" class="co-si-go">{{ __('store.checkout.signin_submit') }}</button>
                <p class="co-si-foot"><button type="button" class="co-si-link" data-si-to="forgot">{{ __('store.checkout.signin_forgot') }}</button></p>
            </form>
        </div>
        <div data-si-pane="forgot" hidden>
            <h2 class="co-si-h" id="kbbSiT2">{{ __('store.checkout.signin_forgot_title') }}</h2>
            <form data-si-form="forgot" action="{{ \App\Support\Url::to('/checkout/sign-in/forgot') }}" method="post">
                <p class="co-si-lead">{{ __('store.checkout.signin_forgot_lead') }}</p>
                <p class="form-row">@if ($kbbSiFloat)<span class="fld kbb-fl ico"><span class="lead" aria-hidden="true">@include('partials.icon-mail')</span><input type="email" class="input-text" name="email" id="kbbSiFEmail" autocomplete="username" inputmode="email" maxlength="254" required placeholder=" "><label for="kbbSiFEmail">{{ __('store.checkout.signin_email') }}</label></span>@else<label for="kbbSiFEmail">{{ __('store.checkout.signin_email') }}</label><span class="woocommerce-input-wrapper"><input type="email" class="input-text" name="email" id="kbbSiFEmail" autocomplete="username" inputmode="email" maxlength="254" required></span>@endif</p>
                <p class="co-si-err" data-si-err role="alert" hidden></p>
                <button type="submit" class="co-si-go">{{ __('store.checkout.signin_forgot_submit') }}</button>
            </form>
            <p class="co-si-sent" data-si-sent role="status" hidden></p>
            <p class="co-si-foot"><button type="button" class="co-si-link" data-si-to="login">{{ __('store.checkout.signin_back') }}</button></p>
        </div>
        <div class="co-si-done" data-si-pane="done" hidden>
            <svg viewBox="0 0 52 52" width="64" height="64" aria-hidden="true"><circle cx="26" cy="26" r="24" fill="none" stroke="#2E9E6B" stroke-width="3"/><path d="M15 27l7 7 15-16" fill="none" stroke="#2E9E6B" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <p class="co-si-h" id="kbbSiT3" role="status">{{ __('store.checkout.signin_done') }}</p>
        </div>
        <p class="co-si-full" data-si-full hidden><a href="{{ \App\Support\Url::to('/my-account/') }}">{{ __('store.checkout.signin_full_page') }}</a></p>
    </div>
</dialog>
<script>
(function () {
  'use strict';

  var D = document.getElementById('kbbSignIn');
  var LINK = document.querySelector('[data-kbb-signin]');
  var FORM = document.getElementById('kbbCheckoutForm');

  {{-- No <dialog> support, or no form: the link stays a link to the full page. --}}
  if (!D || !LINK || !FORM || typeof D.showModal !== 'function') return;

  var FAILED = @json(__('store.checkout.signin_failed'));
  var busy = false;

  function $(sel, root) { return (root || D).querySelector(sel); }

  function pane(name) {
    D.querySelectorAll('[data-si-pane]').forEach(function (p) {
      p.hidden = p.getAttribute('data-si-pane') !== name;
    });
    var title = $('[data-si-pane="' + name + '"] .co-si-h');
    if (title) D.setAttribute('aria-labelledby', title.id);
    $('[data-si-full]').hidden = true;
    var first = $('[data-si-pane="' + name + '"] input');
    if (first && !first.closest('[hidden]')) { first.focus(); return; }
    {{-- No box to type in (the tick): focus stays inside the dialog. --}}
    if (title) { title.tabIndex = -1; title.focus(); }
  }

  function say(form, message) {
    var box = $('[data-si-err]', form);
    box.textContent = message || '';
    box.hidden = !message;
  }

  {{-- A short fade and scale, then the real close. The timer is the length of
     the CSS transition and runs once per close. --}}
  function shut() {
    if (!D.open || D.classList.contains('is-out')) return;
    D.classList.add('is-out');
    setTimeout(function () { D.close(); D.classList.remove('is-out'); }, 180);
  }

  LINK.addEventListener('click', function (event) {
    event.preventDefault();
    var login = $('[data-si-form="login"]');
    say(login, '');
    {{-- What the shopper already typed into the checkout's email box. --}}
    var typed = FORM.querySelector('#billing_email');
    if (typed && typed.value && !$('#kbbSiEmail').value) $('#kbbSiEmail').value = typed.value;
    D.showModal();
    pane('login');
    if ($('#kbbSiEmail').value) $('#kbbSiPw').focus();
  });

  {{-- Esc: the same animated close instead of the instant one. --}}
  D.addEventListener('cancel', function (event) { event.preventDefault(); if (!busy) shut(); });
  {{-- A press on the backdrop lands on the <dialog> itself; the card is .co-si-in. --}}
  D.addEventListener('click', function (event) {
    if (event.target === D && !busy) { shut(); return; }
    var to = event.target.closest('[data-si-to]');
    if (to) {
      var from = to.getAttribute('data-si-to') === 'forgot' ? '#kbbSiEmail' : '#kbbSiFEmail';
      var into = from === '#kbbSiEmail' ? '#kbbSiFEmail' : '#kbbSiEmail';
      if ($(from).value && !$(into).value) $(into).value = $(from).value;
      {{-- The forgot pane opens on its form again, not on the last answer. --}}
      $('[data-si-form="forgot"]').hidden = false;
      $('[data-si-sent]').hidden = true;
      say($('[data-si-form="forgot"]'), '');
      say($('[data-si-form="login"]'), '');
      pane(to.getAttribute('data-si-to'));
      return;
    }
    if (event.target.closest('[data-si-close]') && !busy) shut();
  });

  {{-- The page's token is the one regenerate() just retired: every copy of it
     is replaced, or Place order answers 419. --}}
  function adopt(token) {
    if (typeof token !== 'string' || token === '') return;
    window.KBB = window.KBB || {};
    window.KBB.csrf = token;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
    document.querySelectorAll('input[name="_token"]').forEach(function (input) { input.value = token; });
  }

  {{-- The account's values into the checkout's boxes. Only names the server
     sent, only boxes the shopper can see, never a blank (the server sends
     none), and a select only to one of its own options. Then the events the
     boxes already answer to, so labels lift, validation reruns and the
     emirate / country re-price delivery -- once. --}}
  function fill(fields) {
    var changed = [];
    Object.keys(fields || {}).forEach(function (name) {
      if (!/^billing_[a-z0-9_]+$/.test(name)) return;
      var el = FORM.querySelector('[name="' + name + '"]');
      var value = String(fields[name]);
      if (!el || el.type === 'hidden' || el.disabled || value === '' || el.value === value) return;
      if (el.tagName === 'SELECT' && !Array.prototype.some.call(el.options, function (o) { return o.value === value && !o.disabled; })) return;
      el.value = value;
      changed.push(el);
    });
    changed.forEach(function (el) { el.dispatchEvent(new Event('input', { bubbles: true })); });
    var priced = null;
    changed.forEach(function (el) {
      if (el.id === 'billing_state' || el.id === 'billing_country') { priced = priced && priced.id === 'billing_state' ? priced : el; return; }
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    {{-- #billing_state hands its change to the country, which re-prices. --}}
    if (priced) priced.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function signedIn(data) {
    adopt(data.csrf);
    LINK.remove();
    {{-- A signed-in checkout has no "Create an account" row. --}}
    var acct = document.getElementById('create_account');
    if (acct && acct.checked) { acct.checked = false; acct.dispatchEvent(new Event('change', { bubbles: true })); }
    var row = document.getElementById('create_account_field');
    if (row) row.remove();
    pane('done');
    if (data.reload) { setTimeout(function () { window.location.reload(); }, 800); return; }
    fill(data.fields);
    setTimeout(shut, 800);
  }

  D.querySelectorAll('[data-si-form]').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (busy) return;
      busy = true;
      var kind = form.getAttribute('data-si-form');
      var btn = $('[type="submit"]', form);
      btn.setAttribute('aria-busy', 'true');
      btn.setAttribute('aria-disabled', 'true');
      say(form, '');
      var body = new FormData(form);
      if (kind === 'login') {
        var country = FORM.querySelector('#billing_country');
        if (country) body.append('country', country.value);
      }
      var fallback = false;
      try {
        var response = await fetch(form.getAttribute('action'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || '', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
          body: body
        });
        var data = null;
        try { data = await response.json(); } catch (e) { data = null; }
        if (response.ok && data && data.ok) {
          if (kind === 'login') { signedIn(data); return; }
          form.hidden = true;
          var sent = $('[data-si-sent]');
          sent.textContent = data.message || '';
          sent.hidden = false;
          return;
        }
        var first = null;
        if (data && data.errors) { for (var k in data.errors) { if (data.errors[k] && data.errors[k][0]) { first = data.errors[k][0]; break; } } }
        if (!first && response.status === 429 && data && data.message) first = data.message;
        fallback = !first;
        say(form, first || FAILED);
        if (kind === 'login') { $('#kbbSiPw').value = ''; $('#kbbSiPw').focus(); }
      } catch (e) {
        fallback = true;
        say(form, FAILED);
      } finally {
        busy = false;
        btn.removeAttribute('aria-busy');
        btn.removeAttribute('aria-disabled');
        if (fallback) $('[data-si-full]').hidden = false;
      }
    });
  });
})();
</script>
