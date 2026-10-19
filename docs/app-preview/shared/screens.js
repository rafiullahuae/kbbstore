'use strict';
/* =========================== SCREENS.HTML ================================
   Every screen of the app as a live, scaled copy of index.html (deep-linked
   with &embed=1), grouped as the jump list is. One device for the whole grid;
   a card opens the screen full size in the app. Loaded after the app's own
   scripts with window.APV_SCREENS set, so it shares the jump list, the strings
   and the device sizes, and draws nothing of the app itself. */
(function screensPage(){
  const GROUPS = ['Home', 'Shop', 'Product', 'Category', 'Brand', 'Search', 'Bag & Checkout', 'Account', 'Content', 'States'];
  const DEVLABEL = {'ip16':'iPhone 16 Pro', 'ipse':'iPhone SE', 'ipad':'iPad mini', 'ipad-land':'iPad mini', 'px9':'Pixel 9', 'flip':'Galaxy Z Flip', 'flip-tabletop':'Galaxy Z Flip', 'fold-cover':'Galaxy Z Fold', 'fold-inner':'Galaxy Z Fold', 'duo':'Surface Duo 2'};
  const DEVSUB = {'ipad-land':'Landscape', 'flip-tabletop':'Tabletop', 'fold-cover':'cover screen', 'fold-inner':'inner screen'};
  const devOf = () => { const m = location.hash.match(/device=([\w-]+)/); return m && DEVKEYS[m[1]] ? m[1] : 'ip16'; };
  /* the device frame's own size, as fit() measures it, so the scaled copy fills its card */
  function frame(k){
    const x = DEVKEYS[k], d = {...DEV[x.dev]};
    if (x.dev === 'fold' && x.folded === false) Object.assign(d, {w:673, h:841});
    if (x.land) Object.assign(d, {w:1133, h:744});
    const fw = d.dual ? d.w * 2 + d.hinge + 32 : d.w + (x.dev === 'ipse' ? 28 : x.dev === 'ipad' ? 44 : 24);
    const fh = d.h + (x.dev === 'ipse' ? 150 : x.dev === 'ipad' ? 44 : d.dual ? 28 : 24) + 8;
    return {fw:fw + 8, fh};
  }
  function draw(){
    const dk = devOf(), J = APV.jump(), F = frame(dk);
    const cw = dk === 'duo' || dk === 'ipad-land' ? 440 : dk === 'ipad' || dk === 'fold-inner' ? 300 : 230;
    const k = cw / F.fw, ch = Math.round(F.fh * k);
    const other = AR ? '../en/screens.html' : '../ar/screens.html';
    const devBtns = Object.keys(DEVKEYS).map(x => `<button data-dev="${x}" class="${x === dk ? 'on' : ''}" aria-pressed="${x === dk}">${X('dev', DEVLABEL[x])}${DEVSUB[x] ? ' · ' + t(DEVSUB[x]) : ''}</button>`).join('');
    let n = 0;
    const cards = GROUPS.map(g => {
      const L = J.filter(j => j.g === g);
      if (!L.length) return '';
      const items = L.flatMap(j => (j.designs ? ['A', 'B', 'C'] : ['A']).map(design => ({j, design})));
      n += items.length;
      return `<section class="sgrp" id="g-${g.replace(/\W+/g, '-').toLowerCase()}"><h2>${esc(t(g))} <small>${t('{n} screens', {n:num(items.length)})}</small></h2><div class="cards">${items.map(({j, design}) => {
        const href = APV.href(j.id, {device:dk, design});
        return `<figure class="card" style="--cw:${cw}px;--ch:${ch}px"><a class="frame" href="index.html${href}" aria-label="${esc(j.title)}"><iframe loading="lazy" tabindex="-1" title="${esc(j.title)}" src="index.html${href}&embed=1" style="width:${F.fw}px;height:${F.fh}px;transform:scale(${k})"></iframe></a><figcaption><a href="index.html${href}">${esc(j.title)}${design !== 'A' ? ' · ' + t('Design') + ' ' + X('dev', design) : ''}</a></figcaption></figure>`;
      }).join('')}</div></section>`;
    }).join('');
    document.getElementById('top').innerHTML = `<div class="row"><span class="ttl">${LOTUS.replace('<svg', '<svg class="lgx"')}${t('Every screen, live')}</span><a class="btn2" href="index.html#/home?device=${dk}">${t('Open the app')}</a><a class="btn2 dark" href="${other}#device=${dk}">${t(AR ? 'Open the English screens' : 'Open the Arabic screens')}</a></div>
      <div class="row devs" role="group" aria-label="${t('Device')}"><span class="lab">${t('Device')}</span>${devBtns}</div>
      <nav class="row jump" aria-label="${t('Jump to')}">${GROUPS.map(g => `<a href="#g-${g.replace(/\W+/g, '-').toLowerCase()}" data-keep="1">${esc(t(g))}</a>`).join('')}</nav>`;
    document.getElementById('intro').innerHTML = `${t('Each preview is the app itself, running. Click one to open it full size, then tap, scroll and type.')} ${t('{n} screens', {n:num(n)})}.`;
    document.getElementById('grid').innerHTML = cards;
    paintChooser(); // the bottom-bar / tablet-menu chooser (shared/header.js): every card follows it
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-dev]'); if (!b) return;
    history.replaceState(null, '', '#device=' + b.dataset.dev); draw();
  });
  document.addEventListener('click', e => {
    const a = e.target.closest('a[data-keep]'); if (!a) return;
    e.preventDefault(); const s = document.querySelector(a.getAttribute('href')); if (s) s.scrollIntoView({behavior:'smooth'});
  });
  window.APV_SCREENS_DRAW = draw;
  document.title = t('Every screen, live') + ' · ' + brandWord();
  draw();
})();
