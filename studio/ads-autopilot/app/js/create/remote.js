// The Claude-app connector's render jobs (engine/create_wire.go): Claude, in the owner's own Claude app (Max plan),
// calls create_ad_set / revise / save_to_library; the engine asks this tab over SSE (render.request) to draw; this
// tab answers with progress and the result. A job is answered once per tab even if the engine sends it again after
// a reconnect; a job interrupted by a closed tab is sent again by the engine when a tab opens (kept until done).
import { api } from '../api.js';
import { thumb, saveDesign } from './draw.js';

const running = new Set();
export const remoteLog = []; // for checks: {id, op, ms, n}
if (typeof window !== 'undefined') window.__kaaRemote = remoteLog;

export async function onRenderRequest(ev) {
  const j = ev && ev.data;
  if (!j || !j.id || running.has(j.id)) return;
  running.add(j.id);
  const account = ev.account || j.account;
  const t0 = performance.now();
  const post = (body) => api(`/api/render/${encodeURIComponent(j.id)}`, { method: 'POST', account, body }).catch(() => {});
  try {
    const all = await api('/api/brain/x/create', { account });
    const s = (all || []).find((x) => x.id === j.session_id);
    if (!s) throw new Error('no such session ' + j.session_id);
    const designs = (s.designs || []).filter((d) => !j.design_ids || !j.design_ids.length || j.design_ids.includes(d.id));
    const total = designs.length;
    if (j.op === 'preview') {
      const previews = [], quality = [];
      for (let i = 0; i < designs.length; i++) {
        const d = designs[i];
        const f = (d.formats || []).includes('4:5') ? '4:5' : (d.formats || ['1:1'])[0];
        const r = await thumb(d, f, 432);
        previews.push({ design_id: d.id, lang: d.lang, idea: d.idea, format: f, jpeg: r.url.split(',')[1] });
        quality.push({ design_id: d.id, format: f, ok: r.q.ok, score: r.q.score, fails: r.q.fails });
        await post({ progress: { done: i + 1, total } });
      }
      await post({ result: { previews, quality } });
    } else {
      const ids = [], refused = [];
      for (let i = 0; i < designs.length; i++) {
        const r = await saveDesign(designs[i], account);
        ids.push(...r.ids); refused.push(...r.refused);
        await post({ progress: { done: i + 1, total } });
      }
      await post({ result: { creative_ids: ids, refused } });
    }
    remoteLog.push({ id: j.id, op: j.op, ms: Math.round(performance.now() - t0), n: total });
  } catch (e) {
    await post({ result: { error: String(e.message || e) } });
    remoteLog.push({ id: j.id, op: j.op, error: String(e.message || e) });
  } finally {
    running.delete(j.id);
  }
}
