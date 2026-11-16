// KBB Studio · Connect Claude, app side. The KEY NEVER COMES HERE: the engine keeps it in settings/claude.json (0600) and
// makes every call itself (engine/settings.go). The app only sees {set, hint:'…ABCD', model}.
//
// For other modules (e.g. the translation lane's "Best quality (Claude)"):
//   import { claudeStatus, claudeRun } from '../settings/claude.js';
//   const st = await claudeStatus();                      // {set, hint, model, models, connected, lastTest}
//   const r = await claudeRun({ task: 'translate', lines, from: 'en', to: 'ar', glossary }, ask?);
//                                                         // -> {ok, model, ms, result:{lines:[...]}}
//   Tasks: 'test' · 'brief' {brief, catalogue, company, industry} · 'translate' {lines, from, to, glossary}.
// The first call each session asks the owner (what is sent, to api.anthropic.com); "Not now" -> an Error with .denied.
import { engine } from '../core/engine.js';

export const CLAUDE_URL = 'https://api.anthropic.com/v1/messages';
const token = () => document.querySelector('meta[name="rs-token"]')?.content || '';
async function api(path, body) {
  const r = await fetch(path, { method: body ? 'POST' : 'GET', cache: 'no-store', headers: { 'X-RS-Token': token(), 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { const e = new Error(j.error || r.status + ' ' + r.statusText); e.status = r.status; throw e; }
  return j;
}
export const claudeStatus = () => api('/api/claude').catch(() => ({ set: false }));
export const claudeSave = (b) => api('/api/claude', b);
export const claudeDisconnect = async () => { allowed = false; return api('/api/claude', { disconnect: true }); };

let allowed = false;
const WHAT = { test: 'one tiny test message (“Reply with: ok”)', brief: 'the brief text you typed and the names of the catalogue items it may need — nothing else', translate: 'the caption lines to translate and your glossary words — nothing else' };
/** default question: a small dialog in the page (the module may pass its own ask(what) -> Promise<bool>) */
export function defaultAsk(what) {
  return new Promise((res) => {
    const back = document.createElement('div'); back.className = 'modal-back';
    back.innerHTML = '<div class="modal" role="dialog" aria-modal="true" aria-label="Use Claude?"><div class="modal-head"><h2>Use Claude for this?</h2></div><div class="modal-body"><p></p><p class="muted">Sent to api.anthropic.com with your key, over an encrypted connection. Nothing is sent before you press Allow; this question is asked once per session. Your files, photos, videos and customer data are never sent.</p></div><div class="modal-foot"><button class="btn" data-v="0">Not now</button><button class="btn primary" data-v="1">Allow for this session</button></div></div>';
    back.querySelector('p').textContent = 'What is sent: ' + what + '.';
    back.addEventListener('click', (e) => { const v = e.target?.dataset?.v; if (v != null) { back.remove(); res(v === '1'); } });
    document.body.appendChild(back);
    back.querySelector('.primary').focus();
  });
}
export async function claudeRun(input, ask = defaultAsk) {
  if (!allowed) {
    const ok = await (ask || defaultAsk)(WHAT[input.task] || 'the request');
    if (!ok) { const e = new Error('Not allowed'); e.denied = true; throw e; }
    await api('/api/permission', { url: CLAUDE_URL, scope: 'exact', what: 'Claude: ' + input.task });
    engine.grants?.add('exact ' + CLAUDE_URL);
    allowed = true;
  }
  return api('/api/claude/run', input);
}
export const claudeAllowedThisSession = () => allowed;
