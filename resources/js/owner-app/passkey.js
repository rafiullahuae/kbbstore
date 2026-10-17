/*
 * Face ID / fingerprint (Lane BIO) — the phone's own lock, as well as the PIN.
 *
 * The owner: "after login with PIN, user can select auto login with default
 * mobile security method."
 *
 * WebAuthn with the PLATFORM authenticator: the key is made and kept by the
 * phone (Secure Enclave / Android keystore), unlocked by its own Face ID,
 * fingerprint or passcode, and never leaves it. The server keeps the public
 * half for this one enrolled device (owner_app_passkeys) and checks every
 * signature (App\Services\OwnerApp\Passkeys).
 *
 * The PIN is never replaced. Any failure or cancel leaves the PIN pad exactly
 * as it was. Two conveniences in localStorage, and only these:
 *   oa.bio  '1' while this phone has it on (a hint for drawing the lock
 *           screen; the server is the truth, and a refusal clears it)
 *   oa.bo   when the "Unlock with Face ID / fingerprint?" popup was shown:
 *           it is offered once, ever, on this phone
 * No timers, no polling: one request for a challenge, one for the answer.
 */
import { S, $, api, sheet, toast, store, logo, ic } from './core.js';

const b2u = (buf) => btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const u2b = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));

let avail = null;

/** Does this phone have Face ID / fingerprint (a user-verifying platform authenticator)? Asked once. */
export function bioAvailable() {
  if (avail) return avail;
  const P = window.PublicKeyCredential;
  avail = (P && typeof P.isUserVerifyingPlatformAuthenticatorAvailable === 'function' && window.isSecureContext)
    ? P.isUserVerifyingPlatformAuthenticatorAvailable().then((x) => x === true, () => false)
    : Promise.resolve(false);
  return avail;
}

export const bioOn = () => store.get('oa.bio') === '1';
export const bioClear = () => store.set('oa.bio', '0');

/** The unlock challenge, fetched ahead so a tap can go straight to the phone's prompt. */
let pending = null;

export function bioPrepare() {
  pending = api('POST', 'passkey/start', {}).then((r) => {
    if (!r.ok) { if (r.status === 422) bioClear(); return null; }
    return { at: Date.now(), pk: r.data.publicKey };
  }, () => null);
  return pending;
}

/**
 * Ask the phone, send the answer (`signal` withdraws the prompt when the PIN is typed). Resolves the unlock answer ({ok, data}),
 * or null when the phone said no (cancelled, no match) or the server refused.
 */
export async function bioUnlock(signal) {
  let o = pending ? await pending : null;
  pending = null;
  if (!o || Date.now() - o.at > 90000) o = await bioPrepare().then((x) => { pending = null; return x; });
  if (!o) return null;
  const pk = Object.assign({}, o.pk, {
    challenge: u2b(o.pk.challenge),
    allowCredentials: (o.pk.allowCredentials || []).map((c) => Object.assign({}, c, { id: u2b(c.id) })),
  });
  let cred;
  try { cred = await navigator.credentials.get(signal ? { publicKey: pk, signal } : { publicKey: pk }); } catch (e) { return null; }
  if (!cred || !cred.response || (signal && signal.aborted)) return null;
  const a = cred.response;
  const r = await api('POST', 'passkey/unlock', {
    id: b2u(cred.rawId), clientDataJSON: b2u(a.clientDataJSON), authenticatorData: b2u(a.authenticatorData), signature: b2u(a.signature),
  });
  return r.ok ? r : null;
}

/*
 * Registration options, fetched just BEFORE the tap that uses them (the sheet
 * opening, a finger landing on the switch): Safari lets a page ask for Face
 * ID only close to a tap, so the tap must go straight to the phone's prompt.
 */
let preNew = null;
export function bioPrepareNew() {
  if (!preNew) preNew = api('POST', 'passkey/new', {}).catch(() => ({ ok: false }));
}

/** Behind the PIN: make this phone's credential. Resolves true when the server kept it. */
export async function bioEnable() {
  try {
    bioPrepareNew();
    const r = await preNew;
    preNew = null;
    if (!r.ok) return false;
    const o = r.data.publicKey;
    const pk = Object.assign({}, o, { challenge: u2b(o.challenge), user: Object.assign({}, o.user, { id: u2b(o.user.id) }) });
    const cred = await navigator.credentials.create({ publicKey: pk });
    if (!cred || !cred.response) return false;
    const s = await api('POST', 'passkey/save', { id: b2u(cred.rawId), clientDataJSON: b2u(cred.response.clientDataJSON), attestationObject: b2u(cred.response.attestationObject) });
    if (s.ok) store.set('oa.bio', '1');
    return s.ok;
  } catch (e) {
    preNew = null;
    return false;   // cancelled, or the phone refused: the PIN is unchanged
  }
}

export async function bioDisable() {
  bioClear();
  try { await api('POST', 'passkey/off', {}); } catch (e) { /* the hint is gone either way */ }
}

/**
 * After a good PIN: offer it ONCE on this phone, if the phone can and it is
 * not on already. Closing the sheet any way counts as "Not now", for good;
 * the switch on More is always there.
 */
export async function offerBio() {
  if (bioOn() || store.get('oa.bo') || !(await bioAvailable()) || S.stage !== 'app') return false;
  store.set('oa.bo', Date.now());
  bioPrepareNew();
  sheet('', '<div class="a2-head">' + logo() + '<div><b>Unlock with Face ID / fingerprint?</b><small>Open the app with this phone’s own lock instead of typing your PIN. Your PIN still works.</small></div></div>'
    + '<div class="btns"><button class="btn sec" type="button" data-close>Not now</button><button class="btn pri" type="button" data-bio-yes>' + ic('finger') + ' Enable</button></div>',
  (panel, close) => {
    panel.setAttribute('aria-label', 'Use Face ID / fingerprint');
    $('[data-bio-yes]', panel).addEventListener('click', () => {
      close();
      bioEnable().then((ok) => toast(ok ? 'Face ID / fingerprint on for this phone' : 'Could not turn it on. Your PIN still works.', !ok));
    });
  }, 'a2');
  return true;
}
