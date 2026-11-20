import { t, tt } from '../i18n.js';
import { esc, aed, num, pct, verdictChip, ago, txt, tip } from '../ui.js';

let filter = 'all';
const FORMAT = { single_image: ['Image', 'صورة'], single_video: ['Video', 'فيديو'], carousel: ['Carousel', 'دوّار'], collection: ['Collection', 'مجموعة'], catalog: ['Catalog', 'كتالوج'], flexible: ['Flexible', 'مرن'], click_to_whatsapp: ['WhatsApp', 'واتساب'], reels_stories: ['Reels', 'ريلز'] };

export function render(S, el) {
  const st = S.state;
  const show = (lang) => filter === 'all' || filter === lang;
  const m = (x) => x.metrics7 || {};
  const cells = (x) => `<td class="n">${aed(m(x).spend)}</td><td class="n">${num(m(x).purchases)}</td><td class="n">${aed(m(x).cpa)}</td><td class="n">${num(m(x).roas, 2)}</td><td class="n">${aed(m(x).cpc, 2)}</td><td class="n">${pct(m(x).ctr)}</td><td class="n">${num(m(x).frequency, 1)}</td>`;
  let rows = '';
  let nAr = 0, nEn = 0;
  for (const c of st.tree || []) {
    rows += `<tr class="lvl0 ${c.status !== 'ACTIVE' ? 'paused' : ''}"><td>${esc(c.name)} <span class="muted small">${esc(c.objective)}</span></td><td>${esc(c.status)}</td><td>${c.daily_budget_aed ? aed(c.daily_budget_aed) : '—'}</td>${cells(c)}<td>${verdictChip(c.verdict)}</td><td class="why">${esc(c.verdict?.why || '')}</td></tr>`;
    for (const s of c.adsets) {
      if (!show(s.lang)) continue;
      rows += `<tr class="lvl1 ${s.status !== 'ACTIVE' ? 'paused' : ''}"><td>${esc(s.name)} <span class="chip">${esc((s.lang || '').toUpperCase())}</span> <span class="muted small" data-tip="${esc(tt('Meta learning stage: needs ~50 results in a week to exit learning.', 'مرحلة التعلّم في ميتا: تحتاج نحو ٥٠ نتيجة أسبوعياً.'))}">${esc(s.learning_stage)}</span></td><td>${esc(s.status)}</td><td>${aed(s.daily_budget_aed)}</td>${cells(s)}<td>${verdictChip(s.verdict)}</td><td class="why">${esc(s.verdict?.why || '')}</td></tr>`;
      for (const a of s.ads) {
        if (a.lang === 'ar') nAr++; else nEn++;
        const f = FORMAT[a.format] || [a.format || '', a.format || ''];
        rows += `<tr class="lvl2 ${a.status !== 'ACTIVE' ? 'paused' : ''}"><td>${txt(a.name)} <span class="chip">${esc(tt(f[0], f[1]))}</span></td><td>${esc(a.effective_status || a.status)}</td><td></td>${cells(a)}<td>${verdictChip(a.verdict)}</td>
          <td class="why">${esc(a.verdict?.why || '')}${a.last_action ? `<br><span class="muted">${esc(tt('Last action', 'آخر إجراء'))}: ${esc(ago(a.last_action.at))}</span>` : ''}</td></tr>`;
      }
    }
  }
  const pr = st.proposals || [];
  el.innerHTML = `<div class="page">
  <div class="spread"><div class="seg">${[['all', 'All'], ['ar', 'Arabic'], ['en', 'English']].map(([id, l]) => `<button type="button" data-f="${id}" class="${filter === id ? 'on' : ''}">${esc(t(l))}</button>`).join('')}</div>
    <span class="muted small">${nAr} ${esc(t('Arabic'))} · ${nEn} ${esc(t('English'))} · ${esc(tt('metrics: last 7 days', 'المقاييس: آخر ٧ أيام'))} ${tip(tt('Verdicts come from Bayesian statistics on settled data (the last 2 days are excluded while purchases are still being attributed).', 'الأحكام من إحصاء بايزي على بيانات مكتملة (يُستبعد آخر يومين).'))}</span></div>
  <div class="card" style="padding:0;overflow:auto"><table><thead><tr><th>${esc(tt('Campaign / ad set / ad', 'الحملة / المجموعة / الإعلان'))}</th><th>${esc(t('Status'))}</th><th>${esc(tt('Budget/day', 'الميزانية/يوم'))}</th><th class="n">${esc(t('Spend'))}</th><th class="n">${esc(t('Orders'))}</th><th class="n">${esc(t('CPA'))}</th><th class="n">${esc(t('ROAS'))}</th><th class="n">${esc(t('CPC'))}</th><th class="n">${esc(t('CTR'))}</th><th class="n">${esc(tt('Freq.', 'التكرار'))}</th><th>${esc(tt('Verdict', 'الحكم'))}</th><th>${esc(t('Why'))}</th></tr></thead>
  <tbody>${rows || `<tr><td colspan="12" class="empty">${esc(tt('No campaigns yet. Once limits are set, the autopilot creates one Advantage+ sales campaign.', 'لا توجد حملات بعد. بعد تحديد الحدود ينشئ الطيار الآلي حملة مبيعات Advantage+.'))}</td></tr>`}</tbody></table></div>
  <div class="card"><header><h2>${esc(t('Proposals'))} <span class="muted small">(${pr.length})</span></h2><span class="pill ${esc(st.autopilot.mode)}">${esc(st.autopilot.mode === 'auto' ? tt('executed inside your limits', 'تُنفَّذ ضمن حدودك') : tt('logged only (shadow/off)', 'تُسجَّل فقط'))}</span></header>
  <div class="list">${pr.map((p) => `<div><span class="chip">${esc(p.kind)}</span><span><b>${esc(p.target)}</b> ${p.params && p.params.daily_budget_aed ? `→ ${aed(p.params.daily_budget_aed)}/${esc(tt('day', 'يوم'))}` : ''} ${p.params && p.params.format ? `<span class="chip">${esc(p.params.format)}</span>` : ''}<br><span class="ink2">${esc(p.why)}</span> <span class="muted small">· ${esc(tt('confidence', 'الثقة'))} ${pct(p.confidence, 0)}</span></span></div>`).join('') || `<div class="muted">${esc(tt('Nothing to change right now.', 'لا شيء يحتاج تغييراً الآن.'))}</div>`}</div></div>
  </div>`;
  el.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { filter = b.dataset.f; render(S, el); }; });
}
