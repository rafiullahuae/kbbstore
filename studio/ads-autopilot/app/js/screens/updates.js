// Settings → Updates (the owner, 20 Nov 2026: "also build me the patch upload system in the auto pilot ads system
// too. must be super reliable."). Engine side: engine/update.go + update_flow.go.
//
// Choose or drop "KBB Ads Autopilot v….zip" (a full version) or "KBB Ads Autopilot patch A→B.zip" (only what
// changed) → the file goes to the engine in 8 MB chunks, each checked against its own SHA-256; a dropped connection
// resumes at the byte the engine has (also after a reload: choose the same file again) → the engine checks every
// file and stages the result → a review card says what will change, and NOTHING changes until Install → the install
// runs as a list of steps (verify, pause the autopilot, swap, restart, reconnect, health check) → the page reloads into
// the new version: "Updated to vX" and "Roll back to vY". If the new version fails its health check it is rolled
// back by itself and this page says so.
//
// The screen keeps its state in this module (U), so a re-render of Settings (pushed events) never loses an upload or
// an install in progress. No polling outlives the wait after an install; no layout measuring.
import { tt, getLang } from '../i18n.js';
import { esc, confirmBox, toast } from '../ui.js';
import { api, apiStream, getToken } from '../api.js';

const NOTE = 'kaa.upd.done', UPKEY = 'kaa.upd.upload';
const STEPS = [['verify', 'Check every file again', 'فحص كل ملف مرة أخرى'], ['stage', 'Pause the autopilot', 'إيقاف الطيار الآلي مؤقتاً'],
  ['swap', 'Put the new version in place', 'وضع النسخة الجديدة'], ['restart', 'Restart the engine', 'إعادة تشغيل المحرك'],
  ['reconnect', 'Reconnect this page', 'إعادة اتصال الصفحة'], ['health', 'Health check', 'فحص السلامة']];
const CODE_AR = {
  notzip: 'هذا ليس ملف zip، أو أنه تالف. استخدمي ملف «KBB Ads Autopilot v….zip» كما وصلك.',
  notupdate: 'هذا الملف ليس تحديثاً لـ KBB Ads Autopilot.',
  tampered: 'هذا الملف تغيّر أو تلف بعد تجهيزه. حمّليه مرة أخرى.',
  older: 'هذه نسخة أقدم من التي لديك. للرجوع استخدمي «الرجوع إلى النسخة السابقة».',
  same: 'هذه النسخة مثبّتة بالفعل.',
  wrongfrom: 'هذا التحديث الجزئي مخصص لنسخة أخرى غير نسختك. استخدمي ملف النسخة الكاملة.',
  outside: 'الملف يحاول الكتابة خارج مجلد التطبيق، فرُفض.',
  incomplete: 'الملف ناقص. حمّليه مرة أخرى.',
  damaged: 'معلومات التحديث داخل الملف تالفة.',
  space: 'لا توجد مساحة كافية على هذا الجهاز.',
};

// the screen's state: survives re-renders of Settings
const U = { st: null, phase: 'idle', file: null, up: null, pend: null, err: null, steps: {}, msg: '', bad: false, note: null, busy: false };

const fmtB = (n) => (n >= 1e9 ? `${(n / 1e9).toFixed(1)} GB` : n >= 1e6 ? `${(n / 1e6).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1e3))} KB`);
const when = (iso) => { try { return new Date(iso).toLocaleString(getLang() === 'ar' ? 'ar-AE' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }); } catch { return iso || ''; } };
const ver = (v) => `<bdi dir="ltr">${esc(v || '?')}</bdi>`;
const arrow = () => (getLang() === 'ar' ? '←' : '→');
const pair = (a, b) => `${ver(a)} ${arrow()} ${ver(b)}`;
let root = null;
const $ = (sel) => root && root.querySelector(sel);

export async function mountUpdates(el) {
  el.innerHTML = '<div id="upd-root"></div>';
  root = el.querySelector('#upd-root');
  wire();
  if (!U.note) { try { U.note = JSON.parse(sessionStorage.getItem(NOTE) || 'null'); sessionStorage.removeItem(NOTE); } catch {} }
  paint();
  if (U.phase === 'install') return; // the wait after Install owns the status reads
  try { U.st = await api('/api/update', { noAccount: true }); } catch (e) { U.msg = e.message; U.bad = true; }
  if (U.st && U.st.pending && U.phase === 'idle') { U.pend = U.st.pending; U.phase = 'review'; }
  paint();
}

// ── paint: everything from U ──
function paint() {
  if (!root || !root.isConnected) return;
  const st = U.st || {};
  const can = st.canUpdate !== false;
  root.innerHTML = [banner(st), current(st, can), U.phase === 'upload' ? uploading() : '', U.phase === 'refused' ? refused(st) : '',
    U.phase === 'review' && U.pend ? review(U.pend) : '', U.phase === 'install' ? installing() : '',
    U.phase !== 'install' && st.previous ? rollback(st) : '', pastUpdates(st),
    `<p class="small muted upd-foot">${esc(tt('Updates come only from the file you choose. Every file is checked against its fingerprint before anything changes, a damaged, changed or older file is refused, and it never looks for updates online.', 'التحديثات تأتي فقط من الملف الذي تختارينه. يُفحص كل ملف مقابل بصمته قبل أي تغيير، ويُرفض الملف التالف أو المعدَّل أو الأقدم، ولا يبحث عن تحديثات على الإنترنت.'))}</p>`].join('');
}

function banner(st) {
  const n = U.note, l = st.last;
  let kind = '', text = '';
  if (n && n.kind === 'auto-rollback' || l && !l.seen && l.kind === 'auto-rollback') {
    const x = l && l.kind === 'auto-rollback' ? l : { from: n.from, to: n.to };
    kind = 'warn';
    text = `<b>${esc(tt('Rolled back by itself', 'أُعيدت النسخة السابقة تلقائياً'))}</b> ${tt(`Version ${ver(x.from)} did not pass its health check, so version ${ver(x.to)} was put back exactly as it was.`, `النسخة ${ver(x.from)} لم تنجح في فحص السلامة، فأُعيدت النسخة ${ver(x.to)} كما كانت تماماً.`)}${l && l.reason ? `<br><span class="small" dir="ltr">${esc(l.reason)}</span>` : ''}`;
  } else if (l && !l.seen && l.kind === 'interrupted') {
    kind = 'warn';
    text = `<b>${esc(tt('An install was interrupted', 'توقّف تثبيت قبل اكتماله'))}</b> ${esc(tt('The Mac stopped in the middle of an install. Nothing was lost: the version you had was put back exactly as it was.', 'توقّف الجهاز أثناء التثبيت. لم يضع شيء: أُعيدت نسختك كما كانت تماماً.'))}`;
  } else if (n && n.kind === 'rollback' || !n && l && !l.seen && l.ok && l.kind === 'rollback') {
    kind = 'ok';
    text = `<b>✓ ${tt(`Back on version ${ver(st.version || n.to)}.`, `عدتِ إلى النسخة ${ver(st.version || n.to)}.`)}</b> ${esc(tt('Your ad accounts, keys, history and creatives are as you left them.', 'حساباتك الإعلانية ومفاتيحك وسجلك وإعلاناتك كما تركتِها.'))}`;
  } else if (n || l && !l.seen && l.ok) {
    kind = 'ok';
    text = `<b>✓ ${tt(`Updated to version ${ver(st.version || (n && n.to))}.`, `تم التحديث إلى النسخة ${ver(st.version || (n && n.to))}.`)}</b> ${esc(tt('It passed its health check. Your ad accounts, keys, history and creatives are as you left them.', 'نجحت في فحص السلامة. حساباتك الإعلانية ومفاتيحك وسجلك وإعلاناتك كما تركتِها.'))}`;
  } else if (l && !l.seen && !l.ok && l.reason) {
    kind = 'danger';
    text = `<b>${esc(tt('The last update needs a look', 'آخر تحديث يحتاج مراجعة'))}</b> <span dir="ltr">${esc(l.reason)}</span>`;
  }
  if (!kind) return '';
  return `<div class="alert ${kind === 'ok' ? 'good' : kind} upd-banner" id="upd-done" role="status"><div>${text}</div><button type="button" class="ghost small" data-u="ack">${esc(tt('OK', 'حسناً'))}</button></div>`;
}

function current(st, can) {
  const paused = st.paused ? `<span class="pill">${esc(tt('Autopilot paused while updating', 'الطيار الآلي متوقف مؤقتاً أثناء التحديث'))}</span>` : '';
  const up = st.upload && U.phase !== 'upload' ? `<p class="small muted" id="upd-resume">${esc(tt(`An upload of “${st.upload.name}” stopped at ${Math.floor(100 * st.upload.received / st.upload.size)}%. Choose the same file again to continue where it stopped.`, `توقّف رفع «${st.upload.name}» عند ${Math.floor(100 * st.upload.received / st.upload.size)}٪. اختاري الملف نفسه مرة أخرى للمتابعة من حيث توقّف.`))}</p>` : '';
  const off = !can || U.phase === 'install' || U.phase === 'upload';
  return `<div class="card upd-now"><header><h2>${esc(tt('Updates', 'التحديثات'))}</h2>${paused}</header>
    <div class="upd-ver-row"><div><div class="small muted">${esc(tt('You have', 'نسختك'))}</div><div class="upd-ver" id="upd-version">KBB Ads Autopilot ${ver(st.version || '…')}</div></div>
      <div class="upd-meta small"><span>${esc(tt('Engine API level', 'مستوى واجهة المحرك'))} <b id="upd-api">${esc(st.apiLevel ?? '…')}</b></span>${st.dataDir ? `<span>${esc(tt('Your data', 'بياناتك'))}: <bdi dir="ltr">${esc(st.dataDir)}</bdi></span>` : ''}</div></div>
    ${can ? `<label class="drop upd-drop" id="upd-drop" data-u="drop"><span class="upd-ic" aria-hidden="true">⬆</span><b>${esc(tt('Drop the update file here', 'اسحبي ملف التحديث إلى هنا'))}</b>
      <span class="small">${tt('“KBB Ads Autopilot v….zip” (a full version) or a patch “KBB Ads Autopilot patch 0.3.0 to 0.3.1.zip”. Nothing changes until you press Install.', '«KBB Ads Autopilot v….zip» (نسخة كاملة) أو تحديث جزئي «KBB Ads Autopilot patch 0.3.0 to 0.3.1.zip». لا يتغيّر شيء حتى تضغطي «تثبيت».')}</span>
      <span class="row upd-pick"><span class="btn-like${off ? ' off' : ''}" id="upd-pick">${esc(tt('Choose update file…', 'اختاري ملف التحديث…'))}</span></span>
      <input type="file" id="upd-file" accept=".zip,application/zip" hidden ${off ? 'disabled' : ''} aria-label="${esc(tt('Update file (zip)', 'ملف التحديث (zip)'))}"></label>${up}`
      : `<p class="alert warn small">${esc(st.why || tt('Updating from here is not available.', 'التحديث من هنا غير متاح.'))}</p>`}
    ${U.msg && U.phase === 'idle' ? `<p class="small ${U.bad ? 'bad-t' : 'muted'}" role="status">${esc(U.msg)}</p>` : ''}</div>`;
}

function uploading() {
  const u = U.up || { received: 0, size: 1 };
  const p = Math.floor((100 * u.received) / Math.max(1, u.size));
  return `<div class="card" id="upd-progress"><header><h2>${esc(u.checking ? tt('Checking every file…', 'فحص كل ملف…') : tt('Uploading', 'جارٍ الرفع'))}</h2><span class="sub"><bdi dir="ltr">${esc(u.name || '')}</bdi></span></header>
    <div class="meter upd-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${p}"><i style="width:${p}%"></i></div>
    <p class="small muted" id="upd-pct">${esc(u.checking ? tt('Every file is compared with its fingerprint and staged beside the app. Nothing in the app folder changes yet.', 'يُقارن كل ملف ببصمته ويُجهَّز بجانب التطبيق. لا يتغيّر شيء في مجلد التطبيق بعد.') : tt(`${fmtB(u.received)} of ${fmtB(u.size)} (${p}%)`, `${fmtB(u.received)} من ${fmtB(u.size)} (${p}٪)`))}</p>
    ${u.note ? `<p class="alert info small" id="upd-resumed">${esc(u.note)}</p>` : ''}</div>`;
}

function refused(st) {
  const e = U.err || {};
  const ar = getLang() === 'ar' && CODE_AR[e.code];
  return `<div class="card upd-refused" id="upd-refused"><header><h2>✗ ${esc(tt('Not installed', 'لم يُثبَّت'))}</h2></header>
    ${ar ? `<p>${esc(ar)}</p><p class="small muted" dir="ltr">${esc(e.msg)}</p>` : `<p>${esc(e.msg)}</p>`}
    <p class="small muted">${tt(`Nothing was changed. You are still on version ${ver(st.version)}.`, `لم يتغيّر شيء. ما زلتِ على النسخة ${ver(st.version)}.`)}</p></div>`;
}

const CHECK = {
  app: () => tt('Made for KBB Ads Autopilot', 'مخصص لـ KBB Ads Autopilot'),
  hashes: (c) => tt(`Every file matches its fingerprint (SHA-256): ${c.n} files`, `كل ملف يطابق بصمته (SHA-256): ${c.n} ملف`),
  listed: () => tt('Every file is listed, nothing extra', 'كل ملف مُدرج، ولا شيء زائد'),
  paths: () => tt('Nothing outside the app folder; no hidden, absolute or “..” paths', 'لا شيء خارج مجلد التطبيق؛ لا مسارات مخفية أو مطلقة أو «..»'),
  newer: (c) => tt(`Newer than your version (${c.v})`, `أحدث من نسختك (${c.v})`),
  from: (c) => tt(`Made for exactly your version (${c.v})`, `مخصص لنسختك تماماً (${c.v})`),
  result: (c) => tt(`The result checked file by file: all ${c.n} files of the new version`, `النتيجة مفحوصة ملفاً ملفاً: كل ${c.n} ملف من النسخة الجديدة`),
  min: (c) => tt(`Minimum version satisfied (${c.v})`, `الحد الأدنى للنسخة متحقق (${c.v})`),
  size: (c) => tt(`Within the size limits (${fmtB(c.n || 0)} unpacked)`, `ضمن حدود الحجم (${fmtB(c.n || 0)})`),
  space: () => tt('Enough free space on this Mac', 'مساحة كافية على هذا الجهاز'),
  signature: () => tt('Signature: not used yet; every file’s fingerprint is checked instead', 'التوقيع: غير مستخدم بعد؛ تُفحص بصمة كل ملف بدلاً منه'),
};

function review(p) {
  const st = U.st || {};
  const isPatch = p.kind === 'patch';
  const notes = (p.notes || []).length ? `<ul class="upd-notes">${p.notes.map((n) => `<li>${esc(n)}</li>`).join('')}</ul>` : `<p class="small muted">${esc(tt('No notes came with this update.', 'لم تأتِ ملاحظات مع هذا التحديث.'))}</p>`;
  const stat = (n, en, ar, id) => `<div class="upd-stat" id="${id}"><b>${n}</b><span>${esc(tt(en, ar))}</span></div>`;
  return `<div class="card upd-card" id="upd-card" data-kind="${esc(p.kind)}"><header><h2>${esc(tt('Ready to install', 'جاهز للتثبيت'))}</h2><span class="chip ${isPatch ? 'acc' : ''}">${esc(isPatch ? tt('Patch: only what changed', 'تحديث جزئي: ما تغيّر فقط') : tt('Full version', 'نسخة كاملة'))}</span></header>
    <div class="upd-arrow">${ver(p.from)} <span aria-hidden="true">${arrow()}</span> <span class="to">${ver(p.to)}</span></div>
    <p class="small muted"><bdi dir="ltr">${esc(p.name || '')}</bdi> · ${esc(fmtB(p.size))}${p.date ? ' · ' + esc(tt('made', 'بتاريخ')) + ' ' + esc(p.date) : ''}</p>
    <div class="upd-stats">${stat(p.added, 'files added', 'ملفات جديدة', 'upd-added')}${stat(p.changed, 'files changed', 'ملفات متغيّرة', 'upd-changed')}${stat(p.removed, 'files removed', 'ملفات محذوفة', 'upd-removed')}${stat(esc(fmtB(p.writes || 0)), 'to write', 'للكتابة', 'upd-writes')}</div>
    <p class="alert ${p.engine ? 'info' : ''} small" id="upd-engine">${esc(p.engine ? tt('The engine changes: it restarts by itself during the install (about 5 seconds); this page reconnects by itself.', 'المحرك يتغيّر: يُعاد تشغيله تلقائياً أثناء التثبيت (نحو ٥ ثوانٍ)؛ وتعيد هذه الصفحة الاتصال تلقائياً.') : tt('The engine does not change. It still restarts for a second to check the new version, and this page reconnects by itself.', 'المحرك لا يتغيّر. يُعاد تشغيله لثانية ليفحص النسخة الجديدة، وتعيد هذه الصفحة الاتصال تلقائياً.'))}</p>
    <div class="grid g2 upd-cols"><div><h3>${esc(tt('What’s new', 'ما الجديد'))}</h3>${notes}</div>
      <div><h3>${esc(tt('Checks passed', 'الفحوص الناجحة'))}</h3><ul class="upd-checks" id="upd-checks">${(p.checks || []).map((c) => `<li class="${c.id === 'signature' ? 'info' : 'ok'}" data-check="${esc(c.id)}"><span aria-hidden="true">${c.id === 'signature' ? 'ⓘ' : '✓'}</span>${esc((CHECK[c.id] || (() => c.id))(c))}</li>`).join('')}</ul></div></div>
    <p class="small muted">${tt(`Your data folder and the keys in the Keychain are never touched. Version ${ver(p.from)} is kept, so you can roll back. The autopilot pauses during the install and resumes by itself.`, `مجلد بياناتك والمفاتيح في سلسلة المفاتيح لا تُمس أبداً. تُحفظ النسخة ${ver(p.from)} لتتمكني من الرجوع. يتوقف الطيار الآلي مؤقتاً أثناء التثبيت ويعود تلقائياً.`)}</p>
    <div class="row"><button type="button" id="upd-install" data-u="install">${esc(tt(`Install ${p.to}`, `تثبيت ${p.to}`))}</button><button type="button" class="ghost" id="upd-cancel" data-u="cancel">${esc(tt('Cancel', 'إلغاء'))}</button></div>
    </div>`;
}

function installing() {
  const icon = { run: '◌', done: '✓', fail: '✗' };
  return `<div class="card" id="upd-steps-card"><header><h2>${esc(U.steps.title || tt('Installing', 'جارٍ التثبيت'))}</h2><span class="sub">${esc(tt('Keep this window open', 'أبقي هذه النافذة مفتوحة'))}</span></header>
    <ol class="upd-steps" id="upd-steps">${STEPS.map(([k, en, ar]) => { const s = U.steps[k] || ''; return `<li class="${s}" data-step="${k}"><span class="si" aria-hidden="true">${icon[s] || '○'}</span>${esc(tt(en, ar))}</li>`; }).join('')}</ol>
    ${U.msg ? `<p class="small ${U.bad ? 'bad-t' : 'muted'}" id="upd-msg" role="status">${esc(U.msg)}</p>` : ''}</div>`;
}

function rollback(st) {
  const p = st.previous;
  return `<div class="card" id="upd-back"><header><h2>${esc(tt('Something wrong after an update?', 'هل هناك مشكلة بعد التحديث؟'))}</h2></header>
    <p class="small muted">${tt(`Version ${ver(p.version)} is kept on this Mac (since ${esc(when(p.at))}) until the next update. Rolling back puts it back exactly as it was, and restarts.`, `النسخة ${ver(p.version)} محفوظة على هذا الجهاز (منذ ${esc(when(p.at))}) حتى التحديث التالي. الرجوع يعيدها كما كانت تماماً ثم يعيد التشغيل.`)}</p>
    <div class="row"><button type="button" class="ghost" id="upd-rollback" data-u="rollback" ${U.phase === 'upload' ? 'disabled' : ''}>↩ ${esc(tt(`Roll back to ${p.version}`, `الرجوع إلى ${p.version}`))}</button></div></div>`;
}

function pastUpdates(st) {
  const h = st.history || [];
  if (!h.length) return '';
  const label = (x) => ({ update: tt('Updated', 'تحديث'), patch: tt('Patched', 'تحديث جزئي'), rollback: tt('Rolled back', 'رجوع'), 'auto-rollback': tt('Rolled back by itself', 'رجوع تلقائي') })[x.kind] || x.kind;
  return `<div class="card" id="upd-history"><header><h2>${esc(tt('Past updates', 'التحديثات السابقة'))}</h2></header><ul class="upd-hist">${h.slice(0, 10).map((x) => `<li><span class="muted">${esc(when(x.at))}</span><span class="chip ${x.kind === 'auto-rollback' ? 'warn' : ''}">${esc(label(x))}</span><span>${pair(x.from, x.to)}</span>${x.reason ? `<span class="small muted" dir="ltr">${esc(x.reason)}</span>` : ''}</li>`).join('')}</ul></div>`;
}

// ── events (delegated: the markup is repainted from U) ──
function wire() {
  root.addEventListener('click', async (e) => {
    const b = e.target instanceof Element && e.target.closest('[data-u]');
    if (!b) return;
    const what = b.dataset.u;
    if (what === 'drop') return; // the <label> opens the file chooser itself
    e.preventDefault();
    if (what === 'ack') { U.note = null; if (U.st && U.st.last) U.st.last.seen = true; paint(); api('/api/update/ack', { method: 'POST', noAccount: true }).catch(() => {}); }
    if (what === 'cancel') { await api('/api/update/cancel', { method: 'POST', body: {}, noAccount: true }).catch(() => {}); U.pend = null; U.phase = 'idle'; U.msg = tt('Cancelled. Nothing was changed.', 'أُلغي. لم يتغيّر شيء.'); U.bad = false; paint(); }
    if (what === 'install' && U.pend) run('install', U.pend);
    if (what === 'rollback' && U.st && U.st.previous) {
      const v = U.st.previous.version;
      if (await confirmBox(tt(`Roll back to ${v}?`, `الرجوع إلى ${v}؟`), tt(`KBB Ads Autopilot goes back to version ${v} exactly as it was and restarts. Your ad accounts, keys, history and creatives stay as they are. You can come back to ${U.st.version} the same way.`, `يعود KBB Ads Autopilot إلى النسخة ${v} كما كانت تماماً ثم يُعاد تشغيله. حساباتك ومفاتيحك وسجلك وإعلاناتك تبقى كما هي. ويمكنك العودة إلى ${U.st.version} بالطريقة نفسها.`), tt(`Roll back to ${v}`, `الرجوع إلى ${v}`))) run('rollback', { to: v, from: U.st.version });
    }
  });
  root.addEventListener('change', (e) => { if (e.target.id === 'upd-file' && e.target.files[0]) take(e.target.files[0]); });
  root.addEventListener('dragover', (e) => { const d = e.target instanceof Element && e.target.closest('#upd-drop'); if (d) { e.preventDefault(); d.classList.add('over'); } });
  root.addEventListener('dragleave', (e) => { const d = e.target instanceof Element && e.target.closest('#upd-drop'); if (d) d.classList.remove('over'); });
  root.addEventListener('drop', (e) => { const d = e.target instanceof Element && e.target.closest('#upd-drop'); if (d) { e.preventDefault(); d.classList.remove('over'); if (e.dataTransfer.files[0]) take(e.dataTransfer.files[0]); } });
}

// ── upload: chunked, each chunk with its own sha256, resumable ──
const hex = (buf) => [...new Uint8Array(buf)].map((x) => x.toString(16).padStart(2, '0')).join('');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
function putChunk(id, offset, buf, sha, onProgress) {
  return new Promise((res) => {
    const x = new XMLHttpRequest();
    x.open('PUT', `/api/update/upload/chunk?id=${encodeURIComponent(id)}&offset=${offset}`);
    x.setRequestHeader('X-KAA', getToken());
    x.setRequestHeader('X-Chunk-SHA256', sha);
    x.upload.onprogress = (e) => onProgress(e.loaded);
    x.onload = () => { let j = {}; try { j = JSON.parse(x.responseText); } catch {} res({ status: x.status, j }); };
    x.onerror = x.onabort = x.ontimeout = () => res({ status: 0, j: {} });
    x.send(buf);
  });
}

async function take(file) {
  if (U.phase === 'install' || U.phase === 'upload') return;
  if (!/\.zip$/i.test(file.name)) { U.phase = 'refused'; U.err = { msg: tt('That is not a zip file. Choose the “KBB Ads Autopilot v….zip” or the patch zip you received.', 'هذا ليس ملف zip. اختاري ملف «KBB Ads Autopilot v….zip» أو ملف التحديث الجزئي الذي وصلك.'), code: 'notzip' }; paint(); return; }
  U.file = file; U.pend = null; U.err = null; U.msg = ''; U.phase = 'upload';
  U.up = { name: file.name, size: file.size, received: 0, note: '' };
  paint();
  const key = `${file.name}|${file.size}|${file.lastModified}`;
  let resume = '';
  try { const s = JSON.parse(sessionStorage.getItem(UPKEY) || 'null'); if (s && s.key === key) resume = s.id; } catch {}
  try {
    let b = await api('/api/update/upload/begin', { method: 'POST', body: { name: file.name, size: file.size, resume }, noAccount: true });
    try { sessionStorage.setItem(UPKEY, JSON.stringify({ key, id: b.id })); } catch {}
    let off = b.received, fails = 0;
    if (off > 0) U.up.note = tt(`Continuing where it stopped (${Math.floor((100 * off) / file.size)}%).`, `متابعة من حيث توقّف (${Math.floor((100 * off) / file.size)}٪).`);
    U.up.received = off; paint();
    while (off < file.size) {
      const buf = await file.slice(off, Math.min(file.size, off + b.chunk)).arrayBuffer();
      const sha = hex(await crypto.subtle.digest('SHA-256', buf));
      const r = await putChunk(b.id, off, buf, sha, (n) => { U.up.received = off + n; paintProgress(); });
      if (r.status === 200) { off = r.j.received; fails = 0; U.up.received = off; paintProgress(); continue; }
      if (r.status === 409 && typeof r.j.received === 'number') { off = r.j.received; continue; } // the engine says where it is
      if (r.status === 404) throw Object.assign(new Error(r.j.error || 'gone'), { code: '' });
      if (r.status !== 0 && r.status !== 422 && r.status < 500) throw new Error(r.j.error || `HTTP ${r.status}`);
      // a dropped connection (or a chunk that arrived damaged): ask the engine what it has, continue from there
      if (++fails > 10) throw new Error(tt('The connection to the engine keeps dropping. Choose the same file again to continue where it stopped.', 'الاتصال بالمحرك ينقطع باستمرار. اختاري الملف نفسه مرة أخرى للمتابعة من حيث توقّف.'));
      await sleep(Math.min(8000, 500 * 2 ** (fails - 1)));
      try { const s = await api(`/api/update/upload/status?id=${encodeURIComponent(b.id)}`, { noAccount: true }); off = s.received; } catch { continue; }
      U.up.note = tt(`The connection dropped; continuing at ${fmtB(off)} (${Math.floor((100 * off) / file.size)}%), not from the start.`, `انقطع الاتصال؛ المتابعة من ${fmtB(off)} (${Math.floor((100 * off) / file.size)}٪)، وليس من البداية.`);
      U.up.received = off; U.up.resumes = (U.up.resumes || 0) + 1; paint();
    }
    U.up.checking = true; paint();
    const p = await api('/api/update/upload/finish', { method: 'POST', body: { id: b.id }, noAccount: true });
    try { sessionStorage.removeItem(UPKEY); } catch {}
    U.pend = p; U.phase = 'review';
  } catch (e) {
    try { if (e.status === 422 || e.status === 404) sessionStorage.removeItem(UPKEY); } catch {}
    U.phase = 'refused'; U.err = { msg: e.message, code: e.code || '' };
  }
  paint();
  U.st = await api('/api/update', { noAccount: true }).catch(() => U.st);
  paint();
  if (U.phase === 'review') $('#upd-install')?.focus();
}

// progress only: the bar and the numbers, not the whole screen
function paintProgress() {
  const u = U.up, bar = $('#upd-progress .meter > i'), txt = $('#upd-pct');
  if (!bar || !u) return;
  const p = Math.floor((100 * u.received) / Math.max(1, u.size));
  bar.style.width = p + '%';
  bar.parentElement.setAttribute('aria-valuenow', p);
  txt.textContent = tt(`${fmtB(u.received)} of ${fmtB(u.size)} (${p}%)`, `${fmtB(u.received)} من ${fmtB(u.size)} (${p}٪)`);
}

// ── install / roll back: the steps as they happen, then the wait for the new engine ──
async function run(kind, p) {
  const started = Date.now();
  U.phase = 'install'; U.steps = { title: kind === 'rollback' ? tt(`Rolling back to ${p.to}`, `الرجوع إلى ${p.to}`) : tt(`Installing ${p.to}`, `تثبيت ${p.to}`) };
  U.msg = ''; U.bad = false; paint();
  let last = null;
  try {
    last = await apiStream(kind === 'rollback' ? '/api/update/rollback' : '/api/update/install', kind === 'rollback' ? {} : { id: p.id }, (l) => {
      if (l.step) { U.steps[l.step] = l.state; paint(); }
    });
  } catch (e) { last = { ok: false, error: e.message }; }
  if (!last || !last.ok) {
    U.bad = true; U.msg = (last && last.error) || tt('The engine stopped answering.', 'توقف المحرك عن الاستجابة.');
    for (const [k] of STEPS) if (U.steps[k] === 'run') U.steps[k] = 'fail';
    U.steps.title = tt('Not installed', 'لم يُثبَّت');
    paint();
    U.st = await api('/api/update', { noAccount: true }).catch(() => U.st);
    U.phase = kind === 'rollback' ? 'idle' : 'refused';
    U.err = { msg: U.msg, code: last && last.code };
    U.pend = null;
    paint();
    return;
  }
  U.steps.restart = 'run';
  U.msg = tt(`Restarting KBB Ads Autopilot ${last.to}…`, `إعادة تشغيل KBB Ads Autopilot ${last.to}…`);
  paint();
  await waitForEngine(last.kind === 'rollback' ? 'rollback' : 'update', last.from, last.to, started);
}

// waitForEngine: every engine start has its own token, so "the token changed" is "the new engine answers" -- asked of
// /api/info, which needs none. Then the event stream must say hello (the page is reconnected), then the engine's own
// health check must pass. A failed one is rolled back by the engine; this page follows it and says so. Bounded.
async function waitForEngine(kind, from, to, started) {
  const old = getToken();
  const t0 = Date.now();
  let tok = old, seenNew = false;
  const fail = (m) => { U.bad = true; U.msg = m; for (const [k] of STEPS) if (U.steps[k] === 'run') U.steps[k] = 'fail'; paint(); };
  while (Date.now() - t0 < 150000) {
    await sleep(600);
    let info = null;
    try { const r = await fetch('/api/info', { cache: 'no-store' }); if (r.ok) info = await r.json(); } catch {}
    if (!info || info.token === old) continue;
    if (!seenNew) { // the new engine answers: restart done; the event stream must say hello
      seenNew = true;
      U.steps.restart = 'done'; U.steps.reconnect = 'run'; paint();
      const hello = await new Promise((res) => {
        const es = new EventSource(`/api/events?t=${encodeURIComponent(info.token)}`);
        const tm = setTimeout(() => { es.close(); res(false); }, 10000);
        es.addEventListener('hello', () => { clearTimeout(tm); es.close(); res(true); });
      });
      U.steps.reconnect = hello ? 'done' : 'fail';
      U.steps.health = 'run';
      U.msg = tt(`Version ${to} is checking itself (its API, and that the app loads)…`, `النسخة ${to} تفحص نفسها (واجهتها، وأن التطبيق يعمل)…`);
      paint();
    }
    tok = info.token;
    let st = null;
    try { const r = await fetch('/api/update', { cache: 'no-store', headers: { 'X-KAA': tok } }); if (r.ok) st = await r.json(); } catch {}
    if (!st) continue;
    const l = st.last;
    const rolled = l && l.kind === 'auto-rollback' && Date.parse(l.at) >= started - 2000;
    if (rolled) {
      U.steps.health = 'fail';
      U.msg = tt(`Version ${l.from} did not pass its health check, so version ${l.to} is being put back by itself…`, `النسخة ${l.from} لم تنجح في فحص السلامة، فتُعاد النسخة ${l.to} تلقائياً…`);
      U.bad = true; paint();
      // done once the version put back is the one answering: no health check of its own (the launcher put it back),
      // or its own check passed -- never the failing engine in the moment before it hands over
      if (st.version === l.to && (!st.health || (st.health.kind === 'auto-rollback' && st.health.state === 'ok'))) return done({ kind: 'auto-rollback', from: l.from, to: l.to });
      continue;
    }
    if (st.health && st.health.state === 'checking') continue;
    if (st.version === to && (!st.health || st.health.state === 'ok')) {
      U.steps.health = 'done'; U.bad = false;
      U.msg = kind === 'rollback' ? tt(`Back on ${to}.`, `عدتِ إلى ${to}.`) : tt(`Updated to ${to}. Opening it…`, `تم التحديث إلى ${to}. جارٍ فتحه…`);
      paint();
      return done({ kind, from, to });
    }
    if (st.health && st.health.state === 'failed') return fail(st.health.reason || tt('The health check failed.', 'فشل فحص السلامة.'));
  }
  fail(tt('KBB Ads Autopilot did not come back by itself. Close its Terminal window, then double-click “Start KBB Ads Autopilot”: it puts the version before back if the new one cannot start.', 'لم يعد KBB Ads Autopilot تلقائياً. أغلقي نافذة Terminal ثم انقري مرتين على «Start KBB Ads Autopilot»: سيعيد النسخة السابقة إن لم تعمل الجديدة.'));
}

async function done(note) {
  try { sessionStorage.setItem(NOTE, JSON.stringify({ ...note, at: Date.now() })); } catch {}
  await sleep(900); // the finished step list stays on screen for a moment
  window.history.replaceState(null, '', '#/settings?tab=updates'); // no hashchange: a re-render now would take the note
  location.reload();
}

// app start: an automatic roll back the owner has not seen yet (the launcher may have done it with no page open)
export async function startupNote() {
  try {
    const st = await api('/api/update', { noAccount: true });
    const l = st.last;
    if (l && !l.seen && (l.kind === 'auto-rollback' || l.kind === 'interrupted')) toast(l.kind === 'interrupted' ? tt('An update was interrupted and undone. See Settings → Updates.', 'توقّف تحديث وأُعيدت نسختك. انظري الإعدادات ← التحديثات.') : tt(`Version ${l.from} did not pass its health check, so ${l.to} was put back by itself. See Settings → Updates.`, `النسخة ${l.from} لم تنجح في فحص السلامة، فأُعيدت ${l.to} تلقائياً. انظري الإعدادات ← التحديثات.`));
  } catch {}
}
