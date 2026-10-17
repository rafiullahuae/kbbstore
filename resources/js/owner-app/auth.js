/*
 * The PIN pad (Petal screen 01) and first sign-in on a phone.
 *
 * The PIN lives in a local variable for the length of one request and is
 * written nowhere. "Saved in the browser" is the phone itself being
 * remembered, by an HttpOnly cookie this script cannot read.
 *
 * The pad unlocks as the last digit lands, like a phone's own lock screen:
 * the server tells an ENROLLED device how many digits its member's PIN has.
 * A PIN is 6–8 digits (MIN_PIN); a new one is never shorter.
 */
import { S, esc, api, $, $$, ic, logo, toast } from './core.js';
import { fsButton } from './fs.js';
import { bioOn, bioAvailable, bioPrepare, bioUnlock, bioClear } from './passkey.js';

const MIN_PIN = 6;
const LET = ['', 'ABC', 'DEF', 'GHI', 'JKL', 'MNO', 'PQRS', 'TUV', 'WXYZ'];
/* Face ID / fingerprint asks by itself once per launch (Lane BIO); after that, only from its button. */
let bioAsked = false;

export function renderPin(root, info, done, toEnrol) {
  // The member's own length when the server says (a PIN set before the
  // 6-digit minimum still unlocks until it is changed); otherwise the minimum.
  const auto = !!info.pin_length;
  const len = auto ? Math.min(8, Math.max(4, +info.pin_length)) : MIN_PIN;
  let pin = '', sending = false;

  root.className = 'app pinapp';
  root.innerHTML = fsButton() + '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p>'
    + '<h2 class="pin-h">' + (info.name ? 'Hi ' + esc(info.name) + ', enter your PIN' : 'Enter your PIN') + '</h2>'
    + '<div class="dots" aria-label="PIN digits entered" data-dots>' + '<i></i>'.repeat(len) + '</div>'
    + '<p class="pin-msg" aria-live="polite" data-msg>Locks after ' + esc(info.idle_hours || S.idle) + ' hours away</p>'
    + '<div class="pad">' + [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n, i) => '<button type="button" data-k="' + n + '">' + n + '<small>' + LET[i] + '</small></button>').join('')
    + '<button type="button" class="nk" data-help>Help</button><button type="button" data-k="0">0<small></small></button>'
    + '<button type="button" class="nk" data-k="del" aria-label="Delete digit">' + ic('del') + '</button></div>'
    + (auto ? '' : '<button type="button" class="btn pri" data-go>Unlock</button>')
    + '<button type="button" class="link bio" data-bio hidden>' + ic('finger') + 'Use Face ID / fingerprint</button>'
    + '<button type="button" class="link" data-email>Use email instead</button></div>';

  const dots = $('[data-dots]', root), msg = $('[data-msg]', root);
  const paintDots = () => $$('i', dots).forEach((x, i) => x.classList.toggle('f', i < pin.length));

  async function submit() {
    if (sending || pin.length < (auto ? len : MIN_PIN)) return;
    sending = true;
    // Typing the PIN wins: the phone's own prompt is withdrawn.
    if (bioBusy && bioBusy.abort) bioBusy.abort();
    const p = pin;
    const r = await api('POST', 'unlock', { pin: p });
    sending = false;
    if (r.ok) { dots.classList.add('ok'); msg.textContent = 'Unlocked'; msg.classList.remove('bad'); done(r.data); return; }
    pin = ''; paintDots();
    if (r.data.code === 'no_device') { toEnrol(r.data.message); return; }
    msg.textContent = r.data.message || 'Wrong PIN.';
    msg.classList.add('bad');
    dots.classList.remove('shake');
    requestAnimationFrame(() => requestAnimationFrame(() => dots.classList.add('shake')));
  }

  function key(k) {
    if (sending) return;
    if (k === 'del') pin = pin.slice(0, -1);
    else if (pin.length < 8) pin += k;
    paintDots();
    if (auto && pin.length === len) submit();
  }

  root.querySelector('.pad').addEventListener('click', (e) => {
    const b = e.target.closest('[data-k]');
    if (b) key(b.getAttribute('data-k'));
    else if (e.target.closest('[data-help]')) toast('Forgot your PIN? The owner can set a new one in Users & Roles → Owner app.');
  });
  const go = $('[data-go]', root);
  if (go) go.addEventListener('click', submit);
  $('[data-email]', root).addEventListener('click', () => toEnrol(null));
  const onKey = (e) => {
    if (!root.querySelector('.pad')) { document.removeEventListener('keydown', onKey); return; }
    if (/^\d$/.test(e.key)) key(e.key);
    else if (e.key === 'Backspace') key('del');
    else if (e.key === 'Enter') submit();
  };
  document.addEventListener('keydown', onKey);

  // Face ID / fingerprint (Lane BIO): only on a phone that has it switched on.
  // Any failure or cancel is the PIN pad, as it was.
  const bio = $('[data-bio]', root);
  let bioBusy = null;
  async function viaBio() {
    if (bioBusy || sending) return;
    bioBusy = window.AbortController ? new AbortController() : {};
    const r = await bioUnlock(bioBusy.signal);
    bioBusy = null;
    if (!root.contains(bio)) return;
    if (r) { dots.classList.add('ok'); msg.textContent = 'Unlocked'; msg.classList.remove('bad'); done(r.data); return; }
    if (!bioOn()) bio.hidden = true;
    msg.textContent = 'Or enter your PIN';
    msg.classList.remove('bad');
  }
  if (bioOn()) {
    bioAvailable().then((ok) => {
      if (!ok || !root.contains(bio)) return;
      bio.hidden = false;
      bio.addEventListener('click', viaBio);
      if (!bioAsked) { bioAsked = true; viaBio(); } else bioPrepare();
    });
  }
}

export function renderEnrol(root, done, note) {
  root.className = 'app pinapp';
  root.innerHTML = fsButton() + '<div class="pin">' + logo() + '<p class="kick">K-Beauty Bliss · Owner</p>'
    + '<h2 class="pin-h">Sign in on this phone</h2>'
    + '<form class="enrol" novalidate data-enrol>'
    + (note ? '<p class="alert" role="alert">' + esc(note) + '</p>' : '')
    + '<label class="fld"><span>Email of your admin account</span><span class="inp"><input type="email" name="email" autocomplete="username" inputmode="email" maxlength="190" required></span></label>'
    + '<label class="fld"><span>PIN</span><span class="inp"><input type="password" name="pin" inputmode="numeric" pattern="[0-9]*" autocomplete="current-password" minlength="6" maxlength="8" required></span></label>'
    + '<label class="fld"><span>Name this phone (optional)</span><span class="inp"><input type="text" name="device_name" maxlength="60" placeholder="e.g. Rafi’s iPhone"></span></label>'
    + '<p class="alert" role="alert" data-err hidden></p>'
    + '<button type="submit" class="btn pri wide">Sign in</button>'
    + '<p class="pin-msg">After this, your PIN alone opens the app on this phone.</p></form></div>';

  const f = $('[data-enrol]', root);
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = f.querySelector('button[type=submit]'), err = $('[data-err]', f);
    btn.classList.add('busy');
    const body = { email: f.email.value, pin: f.pin.value, device_name: f.device_name.value };
    f.pin.value = '';
    const r = await api('POST', 'enrol', body);
    btn.classList.remove('busy');
    // A new sign-in is a new device: Face ID / fingerprint is off until switched on here.
    if (r.ok) { bioClear(); done(r.data); return; }
    err.hidden = false;
    err.textContent = r.data.message || 'That did not work.';
  });
}
