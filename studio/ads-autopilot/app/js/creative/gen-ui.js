// Settings -> Connections -> Generators: AI image/video keys (Keychain via
// the engine), the monthly generation budget, and the local product cut-out
// model (download once, size + licence + SHA-256 shown). Also the cost-first
// generation flow the Creatives screen uses.
import { tt } from '../i18n.js';
import { esc, toast, failCard } from '../ui.js';
import { api } from '../api.js';
import { segStatus, segDownload } from './cutout.js';

const mb = (b) => `${(b / 1e6).toFixed(0)} MB`;

export async function mountGenerators(el) {
  if (!el) return;
  el.innerHTML = '<div class="sk" style="height:220px"></div>';
  let gerr = null;
  const [g, st] = await Promise.all([api('/api/brain/x/gen/settings', { noAccount: true }).catch((e) => { gerr = e; return null; }), segStatus(true)]);
  if (!el.isConnected) return;
  if (!g) return failCard(el, tt('Generators and cut-out (advanced)', 'المولّدات والقصّ (متقدم)'), gerr, () => mountGenerators(el));
  paint(el, g, st);
}

function paint(el, g, st) {
  const models = (st && st.models) || [];
  const have = (st && st.have) || {};
  const jobs = (st && st.jobs) || {};
  const asset = (id) => ((st && st.assets) || []).find((a) => a.id === id) || {};
  const running = Object.values(jobs).some((j) => j.phase === 'downloading');
  let pref = ''; try { pref = localStorage.getItem('kaa.seg') || ''; } catch {}
  el.innerHTML = `<div class="card" data-gen><header><h2>${esc(tt('Product cut-out model', 'نموذج قصّ المنتج'))}</h2><span class="sub">${esc(tt('Runs on this Mac; product photos never leave it. Downloaded once, checked against a pinned SHA-256.', 'يعمل على جهازك ولا تغادره الصور. يُنزَّل مرة واحدة ويُتحقق منه ببصمة SHA-256.'))}</span></header>
    <table><tbody>${models.map((m) => { const a = asset(m.asset), j = jobs[m.asset] || jobs['ort-wasm']; const ok = have[m.asset] && have['ort-wasm'];
      return `<tr><td><b>${esc(m.name)}</b> <span class="chip">${esc(m.licence)}</span>${m.webgpu ? ` <span class="chip">${esc(tt('GPU', 'معالج الرسوم'))}</span>` : ''}<br><span class="small muted">${esc(m.note)} · ${mb((a.bytes || 0) + (have['ort-wasm'] ? 0 : asset('ort-wasm').bytes || 0))} · SHA-256 ${esc((a.sha256 || '').slice(0, 12))}…</span></td>
      <td style="text-align:end">${ok ? `<span class="chip acc">${esc(tt('Installed', 'مثبّت'))}</span>` : j && j.phase === 'downloading' ? `<span class="small">${Math.round((j.pct || 0) * 100)}%</span>` : j && j.phase === 'failed' ? `<span class="small" style="color:var(--danger)">${esc(j.error || 'failed')}</span> <button type="button" class="small" data-dl="${esc(m.id)}">${esc(tt('Retry', 'إعادة'))}</button>` : `<button type="button" class="small" data-dl="${esc(m.id)}">${esc(tt('Download', 'تنزيل'))}</button>`}</td></tr>`; }).join('')}
    <tr><td colspan="2"><label class="f"><span>${esc(tt('Use', 'الاستخدام'))}</span><select id="seg-pref"><option value="">${esc(tt('Automatic (best available)', 'تلقائي (الأفضل المتاح)'))}</option>${models.map((m) => `<option value="${esc(m.id)}" ${pref === m.id ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}<option value="off" ${pref === 'off' ? 'selected' : ''}>${esc(tt('Off (simple cut-out)', 'إيقاف (قصّ بسيط)'))}</option></select></label></td></tr></tbody></table></div>
  <div class="card" data-gen><header><h2>${esc(tt('AI generators', 'مولّدات الذكاء الاصطناعي'))}</h2><span class="sub">${esc(tt('Backgrounds and moving scenes only: your real product photo is always placed on top, never redrawn by AI. The price is shown before every run.', 'خلفيات ومشاهد متحركة فقط: تُوضع صورة منتجكِ الحقيقية فوقها دائماً ولا يعيد الذكاء الاصطناعي رسمها. يظهر السعر قبل كل تشغيل.'))}</span></header>
    ${g ? `<div class="row" style="margin-bottom:10px"><label class="f"><span>${esc(tt('Monthly budget (USD)', 'الميزانية الشهرية (دولار)'))}</span><input id="gen-budget" type="number" min="0" step="5" value="${esc(g.budget_usd)}" style="width:120px"></label>
      <span class="small muted" style="align-self:flex-end">${esc(tt('Spent this month', 'المصروف هذا الشهر'))}: $${Number(g.spent_usd).toFixed(2)} · ${esc(tt('0 turns generation off', '٠ يوقف التوليد'))}</span>
      <label class="f"><span>${esc(tt('Images', 'الصور'))}</span><select id="gen-img">${g.providers.filter((p) => p.kind === 'image').map((p) => `<option value="${esc(p.id)}" ${g.image === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
      <label class="f"><span>${esc(tt('Video', 'الفيديو'))}</span><select id="gen-vid">${g.providers.filter((p) => p.kind === 'video').map((p) => `<option value="${esc(p.id)}" ${g.video === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
      <button type="button" id="gen-save" style="align-self:flex-end">${esc(tt('Save', 'حفظ'))}</button></div>
    <table><thead><tr><th>${esc(tt('Provider', 'المزوّد'))}</th><th>${esc(tt('Price', 'السعر'))}</th><th>${esc(tt('Key', 'المفتاح'))}</th></tr></thead><tbody>${g.providers.map((p) => `<tr><td><b>${esc(p.name)}</b> <span class="chip">${esc(p.kind === 'image' ? tt('image', 'صورة') : tt('video', 'فيديو'))}</span><br><span class="small muted">${esc(p.model)} · ${esc(p.terms)}</span></td>
      <td class="small">$${Number(p.price_usd).toFixed(3)} / ${esc(p.unit === 'second' ? tt('second', 'ثانية') : tt('image', 'صورة'))}<br><span class="muted">${esc(p.price_note)}</span></td>
      <td><div class="row"><input type="password" autocomplete="off" data-key="${esc(p.id)}" placeholder="${p.key && p.key.set ? '••••' + esc(p.key.last4) : esc(tt('paste key', 'ألصقي المفتاح'))}" style="width:150px"><button type="button" class="small" data-savekey="${esc(p.id)}">${esc(tt('Save', 'حفظ'))}</button></div></td></tr>`).join('')}</tbody></table>` : `<p class="muted">${esc(tt('The engine did not answer.', 'المحرك لم يستجب.'))}</p>`}</div>`;
  el.querySelectorAll('[data-dl]').forEach((b) => { b.onclick = async () => { try { await segDownload(b.dataset.dl); toast(tt('Downloading in the background…', 'جارٍ التنزيل في الخلفية…')); watch(el, g); } catch (e) { toast(e.message); } }; });
  const sp = el.querySelector('#seg-pref');
  if (sp) sp.onchange = () => { try { localStorage.setItem('kaa.seg', sp.value); } catch {} toast(tt('Saved. New designs use it.', 'تم الحفظ.')); };
  const sv = el.querySelector('#gen-save');
  if (sv) sv.onclick = async () => { try { const r = await api('/api/brain/x/gen/settings', { method: 'PUT', noAccount: true, body: { budget_usd: +el.querySelector('#gen-budget').value || 0, image: el.querySelector('#gen-img').value, video: el.querySelector('#gen-vid').value } }); paint(el, r, st); toast(tt('Saved.', 'تم الحفظ.')); } catch (e) { toast(e.message); } };
  el.querySelectorAll('[data-savekey]').forEach((b) => { b.onclick = async () => { const i = el.querySelector(`[data-key="${CSS.escape(b.dataset.savekey)}"]`); try { const r = await api('/api/brain/x/gen/keys', { method: 'PUT', noAccount: true, body: { provider: b.dataset.savekey, key: i.value } }); i.value = ''; paint(el, r, st); toast(tt('Key saved in the Keychain.', 'حُفظ المفتاح في سلسلة المفاتيح.')); } catch (e) { toast(e.message); } }; });
  if (running) watch(el, g);
}

// progress while (and only while) a download runs, and only while the card is on screen
let watching = false;
function watch(el, g) {
  if (watching) return;
  watching = true;
  const tick = async () => {
    if (!el.isConnected) { watching = false; return; }
    const st = await segStatus(true);
    if (!el.isConnected) { watching = false; return; }
    paint(el, g, st);
    if (st && Object.values(st.jobs || {}).some((j) => j.phase === 'downloading')) setTimeout(tick, 1000);
    else watching = false;
  };
  setTimeout(tick, 800);
}

// ---------- cost-first generation (Creatives screen) ----------
// Returns {url, kind, id, usd} or null if the owner declined / not possible.
export async function generateBackground(design, format, kind, palette, ask) {
  const g = await api('/api/brain/x/gen/settings', { noAccount: true });
  const provider = kind === 'video' ? g.video : g.image;
  const req = { family: design.template, angle: design.angle, product: design.product_title || design.badge || '', category: design.category || '', bullets: design.bullets || [], palette: palette || [], format, setting: design.setting || 'auto', people: design.people || 'none' };
  const pr = await api('/api/brain/x/gen/prompt', { method: 'POST', noAccount: true, body: req });
  const seconds = kind === 'video' ? 8 : 0;
  const est = await api('/api/brain/x/gen/estimate', { method: 'POST', noAccount: true, body: { provider, seconds } });
  const prompt = await ask({ provider: g.providers.find((p) => p.id === provider), est, prompt: kind === 'video' ? pr.motion : pr.prompt });
  if (!prompt) return null;
  if (kind === 'image') {
    const f = await api('/api/brain/x/gen/image', { method: 'POST', noAccount: true, body: { provider, prompt, format, confirm_usd: est.usd } });
    return { url: f.url, kind: 'image', id: f.id, usd: f.usd };
  }
  // video: a still first if the provider animates stills (and none was made yet)
  let imageId = design.bg && design.bg.kind === 'image' ? design.bg.id : '';
  const j0 = await api('/api/brain/x/gen/video', { method: 'POST', noAccount: true, body: { provider, prompt, format, seconds, image_id: imageId, confirm_usd: est.usd } });
  for (let i = 0; i < 240; i++) { // up to 20 min, then give up (no endless loop)
    await new Promise((ok) => setTimeout(ok, 5000));
    const j = await api(`/api/brain/x/gen/jobs/${j0.id}`, { noAccount: true });
    if (j.state === 'done') return { url: j.file.url, kind: 'video', id: j.file.id, usd: j.usd };
    if (j.state === 'failed') throw new Error(j.error);
  }
  throw new Error(tt('The video is taking too long; check Activity later.', 'الفيديو يستغرق وقتاً طويلاً.'));
}
