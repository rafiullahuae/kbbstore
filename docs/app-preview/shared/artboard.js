'use strict';
/* =========================== ARTBOARD.HTML ===============================
   Every screen of the app on ONE canvas, in the order a shopper meets them:
   a row per journey, an arrow between steps, each card the app itself running
   (index.html deep-linked with &embed=1, as screens.html does). Zoom with the
   buttons or ctrl/pinch on the wheel; scroll or drag to move. Loaded after the
   app's own scripts with window.APV_SCREENS set, so it draws nothing of the
   app itself. Any screen not named in a journey lands in the last row, so a
   new screen can never go missing from the board. */
(function artboard(){
  const L = AR ? {
    title:'كل الشاشات على لوحة واحدة', lead:'كل بطاقة هي التطبيق نفسه يعمل. اضغطي على أي بطاقة لفتحها بالحجم الكامل. كبّري وصغّري بالأزرار.',
    fit:'ملاءمة', open:'فتح التطبيق', other:'اللوحة الإنجليزية', bar:'الشريط السفلي', screens:'شاشة',
    lanes:['البداية','التصفح والمنتج','الماركات والبحث','الشراء: السلة والدفع','بعد الدفع','الحساب','المحتوى','حالات فارغة وتحميل وأخطاء']
  } : {
    title:'Every screen, one artboard', lead:'Each card is the app itself, running. Click a card to open it full size. Zoom with the buttons; scroll or drag to move around.',
    fit:'Fit', open:'Open the app', other:'Arabic artboard', bar:'Bottom bar', screens:'screens',
    lanes:['Start','Browse & product','Brands & search','Buy: bag & checkout','After Pay now','Account','Content','Empty, loading & error states']
  };
  const LANES = [
    [3, 4, 0, 1],
    [0, 5, 15, 16, 18, 19, 10, 11, 13, 12],
    [6, 22, 23, 24, 25, 26, 27, 28],
    [29, 31, 32, 33, 35, 36, 37, 38, 34],
    [39, 40, 41, 42, 43, 44, 45],
    [47, 48, 49, 50, 51, 52, 46, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63],
    [64, 65, 66, 67, 68, 69, 70, 71],
    []
  ];
  const CW = 260, F = {fw:426, fh:902};          // iPhone 16 Pro frame, as screens.html sizes it
  const k = CW / F.fw, CH = Math.round(F.fh * k);
  let zoom = 1, bb = new URLSearchParams(location.hash.slice(1)).get('bb') || 'D';

  function draw(){
    const J = APV.jump(), byId = new Map(J.map(j => [j.id, j]));
    const used = new Set(LANES.flat());
    LANES[LANES.length - 1] = J.map(j => j.id).filter(id => !used.has(id));
    let n = 0;
    const lanes = LANES.map((ids, li) => {
      const cards = ids.filter(id => byId.has(id)).map((id, i) => {
        const j = byId.get(id), href = APV.href(j.id, {device:'ip16', design:'A'}) + '&bb=' + bb;
        n++;
        return (i ? '<span class="abar" aria-hidden="true">' + (AR ? '←' : '→') + '</span>' : '') +
          `<figure class="abc" style="--cw:${CW}px;--ch:${CH}px"><span class="abn">${num(i + 1)}</span><a class="frame" href="index.html${href}" target="_blank" rel="noopener" aria-label="${esc(j.title)}"><iframe loading="lazy" tabindex="-1" title="${esc(j.title)}" src="index.html${href}&embed=1" style="width:${F.fw}px;height:${F.fh}px;transform:scale(${k})"></iframe></a><figcaption>${esc(j.title)}</figcaption></figure>`;
      }).join('');
      return `<section class="alane"><h2><b>${num(li + 1)}</b>${esc(L.lanes[li])}</h2><div class="arow">${cards}</div></section>`;
    }).join('');
    const other = AR ? '../en/artboard.html' : '../ar/artboard.html';
    document.getElementById('top').innerHTML = `<div class="row"><span class="ttl">${LOTUS.replace('<svg', '<svg class="lgx"')}${L.title}</span>
      <span class="zm"><button data-z="-">−</button><output id="zv">100%</output><button data-z="+">+</button><button data-z="fit">${L.fit}</button></span>
      <span class="zm">${L.bar}: ${BB.map(x => `<button data-bb="${x}" class="${x === bb ? 'on' : ''}">${x}</button>`).join('')}</span>
      <a class="btn2" href="index.html#/home?device=ip16">${L.open}</a><a class="btn2 dark" href="${other}#bb=${bb}">${L.other}</a></div>`;
    document.getElementById('intro').textContent = L.lead + ' ' + num(n) + ' ' + L.screens + '.';
    document.getElementById('sizer').innerHTML = `<div id="canvas">${lanes}</div>`;
    apply();
  }
  function apply(){
    const c = document.getElementById('canvas'); if (!c) return;
    c.style.transform = `scale(${zoom})`;
    const w = document.getElementById('sizer');
    w.style.width = Math.ceil(c.scrollWidth * zoom) + 'px'; w.style.height = Math.ceil(c.scrollHeight * zoom) + 'px';
    document.getElementById('zv').textContent = Math.round(zoom * 100) + '%';
  }
  function fit(){
    const c = document.getElementById('canvas'), b = document.getElementById('board');
    zoom = Math.max(.12, Math.min(1, (b.clientWidth - 24) / c.scrollWidth)); apply();
  }
  document.addEventListener('click', e => {
    const z = e.target.closest('[data-z]');
    if (z) { const v = z.dataset.z; if (v === 'fit') fit(); else { zoom = Math.max(.12, Math.min(1.6, zoom * (v === '+' ? 1.25 : .8))); apply(); } return; }
    const s = e.target.closest('[data-bb]');
    if (s) { bb = s.dataset.bb; history.replaceState(null, '', '#bb=' + bb); draw(); }
  });
  // ctrl + wheel (and trackpad pinch, which arrives as ctrl + wheel) zooms the board, not the page
  document.getElementById('board').addEventListener('wheel', e => {
    if (!e.ctrlKey) return; e.preventDefault();
    zoom = Math.max(.12, Math.min(1.6, zoom * (e.deltaY < 0 ? 1.1 : .9))); apply();
  }, {passive:false});
  // drag on the empty canvas to move around
  (function drag(){
    const b = document.getElementById('board'); let sx, sy, sl, st, on = false;
    b.addEventListener('pointerdown', e => { if (e.target.closest('.abc') || e.pointerType !== 'mouse') return; on = true; sx = e.clientX; sy = e.clientY; sl = b.scrollLeft; st = b.scrollTop; b.classList.add('grab'); });
    addEventListener('pointermove', e => { if (!on) return; b.scrollLeft = sl - (e.clientX - sx); b.scrollTop = st - (e.clientY - sy); });
    addEventListener('pointerup', () => { on = false; b.classList.remove('grab'); });
  })();
  window.APV_SCREENS_DRAW = draw;
  document.title = L.title + ' · ' + brandWord();
  draw();
  requestAnimationFrame(fit);
})();
