// "Best quality (Claude)" — through Connect Claude (app/settings/claude.js, ⚙ Configure → Connect Claude). The key never
// comes here: the engine holds it and makes the call; the first use each session asks him (what is sent: the caption
// sentences and the glossary words, nothing else). Off unless a key is saved. Offline translation stays the default,
// and whenever Claude is not set up, not allowed or fails, the caller falls back to it silently (service.js).

import { claudeStatus as settingsStatus, claudeRun } from '../../settings/claude.js';

export const CLAUDE_NOTE = 'Best quality (Claude) — add your key in ⚙ Configure → Connect Claude to use it. Offline translation is used meanwhile.';

/** { connected, note } — connected = a key is saved in the engine (a test-only ctx.claude stub also counts). */
export async function claudeStatus(ctx) {
  if (ctx?.claude && typeof ctx.claude.translate === 'function') return { connected: !!ctx.claude.connected, note: 'Connected' };
  try {
    const st = await settingsStatus();
    return st?.set ? { connected: true, note: `Claude${st.model ? ' · ' + st.model : ''} — sends only the caption sentences and glossary words` } : { connected: false, note: CLAUDE_NOTE };
  } catch { return { connected: false, note: CLAUDE_NOTE }; }
}

/** req = { src, tgt, sentences:[string], glossary:[{term,…}] } -> [string] (one per sentence). Throws when not usable. */
export async function translateWithClaude(ctx, req) {
  if (ctx?.claude?.translate) return ctx.claude.translate(req);
  const st = await claudeStatus(ctx);
  if (!st.connected) { const e = new Error(CLAUDE_NOTE); e.notConnected = true; throw e; }
  const r = await claudeRun({ task: 'translate', lines: req.sentences, from: req.src, to: req.tgt,
    glossary: (req.glossary || []).map((g) => (g[req.tgt] ? `${g.term} = ${g[req.tgt]}` : g.term)).slice(0, 200) });
  const lines = r?.result?.lines;
  if (!Array.isArray(lines) || lines.length !== req.sentences.length) throw new Error('Claude returned a different number of lines');
  return lines.map((x) => String(x ?? ''));
}
