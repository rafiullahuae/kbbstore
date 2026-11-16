// "Best quality (Claude)" — HOOK ONLY. Connect Claude (Settings → Connect Claude: paste key → Test → done) does not
// exist in the studio yet; it is planned with Brief → Designs (PLAN.md, round 3). Until it lands this module makes NO
// network request of any kind: isConnected() is false and translateWithClaude() refuses. Offline translation is the
// default and does not depend on this.
//
// The contract the Connect Claude round fills in (so the Translate panel needs no change then):
//   claudeStatus(ctx)              -> { connected: bool, note: string }
//   translateWithClaude(ctx, req)  -> [string]   req = { src, tgt, sentences:[string], glossary:[{term, ar?…}] }
//     sends ONLY the caption sentences and the glossary terms (never media, never customer data), through the
//     engine's permission gate, after asking once per session; the key is stored by the engine, never in projects.

export const CLAUDE_NOTE = 'Connect Claude — coming with Brief → Designs. Offline translation is used meanwhile.';

export function claudeStatus(ctx) {
  const hook = ctx?.claude;                    // provided by the future Connect Claude module (ctx.claude)
  if (hook && typeof hook.translate === 'function' && hook.connected) return { connected: true, note: 'Connected' };
  return { connected: false, note: CLAUDE_NOTE };
}

export async function translateWithClaude(ctx, req) {
  const st = claudeStatus(ctx);
  if (!st.connected) { const e = new Error(CLAUDE_NOTE); e.notConnected = true; throw e; }
  return ctx.claude.translate(req);           // the Connect Claude module owns the request, the permission and the key
}
