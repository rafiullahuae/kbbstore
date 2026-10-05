// Lane NF — 404 page previews. `node docs/nf-preview/build.mjs` writes
// option-{a,b,c,d}.html and option-{a,b,c,d}-ar.html next to this file, plus
// index.html, which frames each one at 390px and 1280px. Everything is inline:
// the only files a page loads are the two woff2 faces copied from
// resources/fonts/, so the preview makes no external request.
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/* ── shared copy ─────────────────────────────────────────────────────────── */
const T = {
  en: {
    search: 'Search serums, brands…', go: 'Search', home: 'Take me home',
    shop: 'Shop all', sale: 'Super Sale', best: 'Best sellers', wa: 'WhatsApp us',
    trend: 'Trending now', trendSub: 'Loved this week',
    hd: 'store header · menu · cart — unchanged', ft: 'store footer — unchanged',
    products: [
      ['Snail Mucin Essence', 'AED 69'], ['Rice Sunscreen SPF50+', 'AED 59'],
      ['Centella Calming Toner', 'AED 74'], ['Berry Lip Sleeping Mask', 'AED 45'],
    ],
  },
  ar: {
    search: 'ابحثي عن منتج أو ماركة…', go: 'بحث', home: 'خذيني إلى الرئيسية',
    shop: 'تسوّقي الكل', sale: 'التخفيضات الكبرى', best: 'الأكثر مبيعًا', wa: 'راسلينا على واتساب',
    trend: 'الأكثر رواجًا الآن', trendSub: 'المفضّلة هذا الأسبوع',
    hd: 'رأس المتجر · القائمة · السلة — دون تغيير', ft: 'تذييل المتجر — دون تغيير',
    products: [
      ['إيسنس الحلزون', '69 د.إ'], ['واقي شمس بالأرز <bdi>SPF50+</bdi>', '59 د.إ'],
      ['تونر سنتيلا المهدّئ', '74 د.إ'], ['ماسك الشفاه الليلي بالتوت', '45 د.إ'],
    ],
  },
};

/* ── the four illustrations ──────────────────────────────────────────────── */
const sparkle = (x, y, s, c, d) =>
  `<g transform="translate(${x} ${y}) scale(${s})"><path class="tw" style="animation-delay:${d}s" d="M0-10C1-3 3-1 10 0C3 1 1 3 0 10C-1 3-3 1-10 0C-3-1-1-3 0-10Z" fill="${c}"/></g>`;

const ART = {
  /* A — a rose-gold compact, its mirror cracked straight through a surprised face. */
  a: (l) => `<svg class="art art-a" viewBox="0 0 320 380" role="img" aria-label="${l === 'ar' ? 'مرآة مكياج متشققة' : 'A cracked compact mirror'}">
<defs>
<linearGradient id="aG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F8E4CC"/><stop offset=".42" stop-color="#D7A47B"/><stop offset=".68" stop-color="#F4D8BA"/><stop offset="1" stop-color="#B07A55"/></linearGradient>
<radialGradient id="aM" cx=".36" cy=".3" r=".85"><stop offset="0" stop-color="#FFFFFF"/><stop offset=".5" stop-color="#F8E8EF"/><stop offset="1" stop-color="#E2C3D2"/></radialGradient>
<radialGradient id="aP" cx=".42" cy=".38" r=".75"><stop offset="0" stop-color="#FAD9DC"/><stop offset="1" stop-color="#E9A9B5"/></radialGradient>
<clipPath id="aC"><circle cx="160" cy="128" r="86"/></clipPath>
<clipPath id="a1"><path d="M0 0H320V214L236 196 214 176 184 132 166 138 144 100 128 108 94 60 0 60Z"/></clipPath>
<clipPath id="a2"><path d="M0 60H94L128 108 144 100 166 138 184 132 214 176 236 196 320 214V380H0Z"/></clipPath>
<g id="aF">
<circle cx="160" cy="128" r="86" fill="url(#aM)"/>
<path d="M106 150C100 92 128 62 162 62S222 92 216 150C214 176 208 196 202 214H120C112 196 107 176 106 150Z" fill="#4A2F38"/>
<path d="M147 186H175V214H147Z" fill="#EFC3AE"/>
<path d="M100 236C108 214 132 206 161 206S214 214 222 236Z" fill="#F4A3BA"/>
<ellipse cx="161" cy="138" rx="41" ry="49" fill="#FCE2D5"/>
<path d="M119 132C118 98 138 82 162 82C188 82 205 100 204 130C194 113 178 104 160 105C150 116 136 126 119 132Z" fill="#4A2F38"/>
<path d="M136 114Q144 106 153 111M170 111Q179 106 187 114" stroke="#4A2F38" stroke-width="2.6" fill="none" stroke-linecap="round"/>
<circle cx="145" cy="134" r="7.2" fill="#2A2228"/><circle cx="177" cy="134" r="7.2" fill="#2A2228"/>
<circle cx="147.6" cy="131.4" r="2.4" fill="#fff"/><circle cx="179.6" cy="131.4" r="2.4" fill="#fff"/>
<path d="M138.5 128.5L134 125M184 128.5L188.5 125" stroke="#2A2228" stroke-width="2" stroke-linecap="round"/>
<ellipse cx="135" cy="154" rx="9" ry="5" fill="#F393AC" opacity=".5"/><ellipse cx="187" cy="154" rx="9" ry="5" fill="#F393AC" opacity=".5"/>
<ellipse cx="161" cy="166" rx="6" ry="8" fill="#C4446A"/><ellipse cx="161" cy="169.5" rx="3.6" ry="3.4" fill="#F27C99"/>
</g>
</defs>
<ellipse cx="160" cy="356" rx="112" ry="10" fill="#000" opacity=".22"/>
<g class="a-float">
<ellipse cx="160" cy="304" rx="118" ry="52" fill="#9E6B49"/>
<ellipse cx="160" cy="296" rx="118" ry="52" fill="url(#aG)"/>
<ellipse cx="160" cy="296" rx="94" ry="39" fill="#C38D66"/>
<ellipse cx="160" cy="298" rx="90" ry="36" fill="url(#aP)"/>
<text x="160" y="610" transform="matrix(1 0 0 .5 0 0)" text-anchor="middle" font-family="Outfit,sans-serif" font-weight="800" font-size="56" fill="#FBE6E8" opacity=".9">404</text>
<text x="160" y="607" transform="matrix(1 0 0 .5 0 0)" text-anchor="middle" font-family="Outfit,sans-serif" font-weight="800" font-size="56" fill="#DE95A5">404</text>
<path d="M232 286l9-6 3 9zM88 300l7-3 1 7z" fill="#F7EAF0" opacity=".9"/>
<rect x="144" y="222" width="32" height="26" rx="6" fill="url(#aG)"/>
<circle cx="160" cy="128" r="104" fill="url(#aG)"/>
<circle cx="160" cy="128" r="95" fill="#B98059"/>
<circle cx="160" cy="128" r="91" fill="#F2D2B4"/>
<g clip-path="url(#aC)">
<g clip-path="url(#a1)"><use href="#aF"/></g>
<g clip-path="url(#a2)"><g class="a-shard"><use href="#aF" transform="translate(3 5) rotate(2 160 128)"/></g></g>
<path d="M94 60L128 108 144 100 166 138 184 132 214 176 236 196M144 100L150 76M166 138L142 152 130 178M214 176L228 158M184 132L204 120" stroke="#A9839A" stroke-width="3.4" fill="none" opacity=".35" stroke-linejoin="round"/>
<path d="M94 60L128 108 144 100 166 138 184 132 214 176 236 196M144 100L150 76M166 138L142 152 130 178M214 176L228 158M184 132L204 120" stroke="#fff" stroke-width="1.8" fill="none" stroke-linejoin="round"/>
<path d="M92 92C110 66 132 54 156 50L170 50C140 60 116 78 100 104Z" fill="#fff" opacity=".45"/>
</g>
${sparkle(36, 52, 1.2, '#F4D8BA', 0)}${sparkle(286, 70, .9, '#F4D8BA', .7)}${sparkle(276, 232, 1.4, '#FFE3EC', 1.4)}${sparkle(30, 230, .8, '#FFE3EC', 2.1)}${sparkle(262, 18, .6, '#fff', 1.1)}
</g>
</svg>`,

  /* B — a sheet mask sliding off a cute face; one wink, one wobbly smile, an "aah!". */
  b: (l) => `<svg class="art art-b" viewBox="0 0 360 380" role="img" aria-label="${l === 'ar' ? 'قناع ورقي ينزلق عن وجه لطيف' : 'A sheet mask slipping off a cute face'}">
<defs>
<radialGradient id="bS" cx=".42" cy=".36" r=".75"><stop offset="0" stop-color="#FFEDE3"/><stop offset="1" stop-color="#F5CFBC"/></radialGradient>
<linearGradient id="bM" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#EEF3F6"/></linearGradient>
<clipPath id="bC"><circle cx="180" cy="190" r="170"/></clipPath>
</defs>
<circle cx="180" cy="190" r="170" fill="#FCE0E8"/>
<circle cx="56" cy="96" r="20" fill="#D6F0E2"/><circle cx="318" cy="292" r="13" fill="#E6DAF8"/><circle cx="314" cy="86" r="7" fill="#fff"/>
<g clip-path="url(#bC)">
<circle cx="180" cy="64" r="32" fill="#3E2A31"/>
<path d="M86 196C82 114 126 70 180 70S278 114 274 196C272 252 262 304 250 340H110C98 304 88 252 86 196Z" fill="#3E2A31"/>
<path d="M66 380C74 322 118 298 180 298S286 322 294 380Z" fill="#F6B3C6"/>
<path d="M150 300L180 346 210 300" fill="none" stroke="#fff" stroke-width="7" opacity=".75" stroke-linecap="round"/>
<path d="M156 258H204V302C190 312 170 312 156 302Z" fill="#EFC0AA"/>
</g>
<ellipse cx="101" cy="192" rx="12" ry="18" fill="#F3CAB6"/><ellipse cx="259" cy="192" rx="12" ry="18" fill="#F3CAB6"/>
<ellipse cx="180" cy="186" rx="80" ry="92" fill="url(#bS)"/>
<path d="M102 150C112 96 150 78 180 78S248 96 258 150" fill="none" stroke="#F48FAD" stroke-width="20" stroke-linecap="round"/>
<g transform="translate(236 96) rotate(24)"><ellipse cx="-17" cy="-3" rx="18" ry="11" transform="rotate(-18)" fill="#E0567B"/><ellipse cx="17" cy="-3" rx="18" ry="11" transform="rotate(18)" fill="#E0567B"/><circle r="7.5" fill="#C13E63"/></g>
<path d="M138 150Q150 142 162 148M198 148Q210 142 222 150" stroke="#3E2A31" stroke-width="3" fill="none" stroke-linecap="round"/>
<circle cx="150" cy="172" r="9" fill="#2A2228"/><circle cx="153.5" cy="168.5" r="3.2" fill="#fff"/><circle cx="146.5" cy="175" r="1.5" fill="#fff"/>
<path d="M140 165L135 160M143 162L140 156" stroke="#2A2228" stroke-width="2" stroke-linecap="round"/>
<path d="M198 174Q210 164 222 174" stroke="#2A2228" stroke-width="3.2" fill="none" stroke-linecap="round"/>
<path d="M222 174L227 170" stroke="#2A2228" stroke-width="2" stroke-linecap="round"/>
<ellipse cx="128" cy="196" rx="12" ry="6.5" fill="#F393AC" opacity=".5"/><ellipse cx="232" cy="196" rx="12" ry="6.5" fill="#F393AC" opacity=".5"/>
<g transform="translate(-124 24)"><g class="b-drop"><path d="M246 118C246 118 238 128 238 133A8 8 0 0 0 254 133C254 128 246 118 246 118Z" fill="#BFE3F5"/><path d="M243 131A3 3 0 0 0 246 135" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round"/></g></g>
<path d="M160 232q3.5-5 7 0t7 0 7 0 7 0" stroke="#C4446A" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
<g class="b-mask">
<g transform="rotate(13 196 268)">
<path fill="url(#bM)" stroke="#E7E3EA" stroke-width="1.5" fill-rule="evenodd" d="M196 186C240 186 270 218 270 262C270 312 236 350 196 350S122 312 122 262C122 218 152 186 196 186ZM150 236A16 9 0 1 0 182 236A16 9 0 1 0 150 236ZM210 236A16 9 0 1 0 242 236A16 9 0 1 0 210 236ZM176 304A20 9 0 1 0 216 304A20 9 0 1 0 176 304Z"/>
<path d="M190 250L192 268L202 268" stroke="#E7E3EA" stroke-width="2" fill="none" stroke-linecap="round"/>
<path d="M122 262C122 232 134 210 152 198C140 216 138 236 146 252C136 254 128 258 122 262Z" fill="#E6EEF2"/>
</g>
</g>
<g class="b-drip"><path d="M214 352C214 352 209 359 209 362A5 5 0 0 0 219 362C219 359 214 352 214 352Z" fill="#CFE8F2"/></g>
<g class="b-bubble"><path d="M262 34H330A14 14 0 0 1 344 48V70A14 14 0 0 1 330 84H300L286 98 288 84H262A14 14 0 0 1 248 70V48A14 14 0 0 1 262 34Z" fill="#fff"/><text x="296" y="${l === 'ar' ? 67 : 66}" text-anchor="middle" font-family="${l === 'ar' ? 'Cairo,' : ''}Outfit,sans-serif" font-weight="800" font-size="${l === 'ar' ? 21 : 20}" fill="#E0567B">${l === 'ar' ? 'آه!' : 'aah!'}</text></g>
${sparkle(62, 300, 1.1, '#fff', 0)}${sparkle(300, 150, .8, '#F48FAD', .9)}${sparkle(40, 170, .7, '#F48FAD', 1.8)}
</svg>`,

  /* C — a lipstick that fell over and wrote "404" on its way down; a serum spilled beside it. */
  c: (l) => `<svg class="art art-c" viewBox="0 0 720 340" role="img" aria-label="${l === 'ar' ? 'أحمر شفاه سقط ورسم 404' : 'A fallen lipstick that has smeared the number 404'}">
<defs>
<linearGradient id="cG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F6DDBF"/><stop offset=".5" stop-color="#CF9C6F"/><stop offset="1" stop-color="#F1D2AF"/></linearGradient>
<linearGradient id="cL" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E6DDFB"/><stop offset="1" stop-color="#C6B3EE"/></linearGradient>
</defs>
<g fill="none" stroke-linecap="round" stroke-linejoin="round">
<g stroke="#B92E57" stroke-width="40" opacity=".55" transform="translate(5 6)">
<path class="cd cd1" pathLength="1" d="M146 46L62 190H196M158 118V270"/>
<path class="cd cd2" pathLength="1" d="M352 50C292 50 266 106 266 160S292 270 352 270 438 216 438 160 412 50 352 50Z"/>
<path class="cd cd3" pathLength="1" d="M584 46L500 190H634M596 118V270"/>
</g>
<g stroke="#D93F69" stroke-width="40">
<path class="cd cd1" pathLength="1" d="M146 46L62 190H196M158 118V270"/>
<path class="cd cd2" pathLength="1" d="M352 50C292 50 266 106 266 160S292 270 352 270 438 216 438 160 412 50 352 50Z"/>
<path class="cd cd3" pathLength="1" d="M584 46L500 190H634M596 118V270"/>
</g>
<g stroke="#F58AA6" stroke-width="5" opacity=".8">
<path class="ch cd1" pathLength="1" d="M140 50L64 182H186M152 124V262"/>
<path class="ch cd2" pathLength="1" d="M346 58C300 58 276 106 276 156"/>
<path class="ch cd3" pathLength="1" d="M578 50L502 182H624M590 124V262"/>
</g>
</g>
<g class="c-tube" transform="translate(616 300) rotate(-24)">
<path d="M-6-14L-40-13C-50-12-54-4-50 4L-40 14H-6Z" fill="#C2335C"/>
<path d="M-40-13C-50-12-54-4-50 4" stroke="#F58AA6" stroke-width="3" fill="none" stroke-linecap="round"/>
<rect x="-6" y="-17" width="30" height="34" rx="3" fill="url(#cG)"/>
<rect x="24" y="-20" width="74" height="40" rx="6" fill="#5A2945"/>
<rect x="44" y="-20" width="8" height="40" fill="url(#cG)"/>
<rect x="30" y="-16" width="62" height="5" rx="2.5" fill="#fff" opacity=".18"/>
</g>
<g transform="translate(40 300) rotate(9)"><rect x="0" y="-18" width="70" height="36" rx="7" fill="#5A2945"/><rect x="58" y="-18" width="12" height="36" rx="4" fill="url(#cG)"/><rect x="6" y="-13" width="46" height="5" rx="2.5" fill="#fff" opacity=".18"/></g>
<g transform="translate(470 296) rotate(-78)">
<rect x="-18" y="-38" width="36" height="58" rx="10" fill="url(#cL)" opacity=".92"/>
<rect x="-10" y="-50" width="20" height="14" rx="3" fill="#fff"/>
<rect x="-12" y="-74" width="24" height="26" rx="12" fill="#E2B08A"/>
<rect x="-12" y="-14" width="24" height="18" rx="3" fill="#fff" opacity=".7"/>
</g>
<path d="M500 314C484 302 452 304 430 312C414 318 418 330 440 330C462 330 476 326 500 314Z" fill="#D5C4F3" opacity=".85"/>
<circle class="c-drop" cx="420" cy="322" r="5" fill="#D5C4F3"/><circle cx="404" cy="326" r="3" fill="#D5C4F3"/>
${sparkle(232, 34, 1.3, '#fff', 0)}${sparkle(466, 52, 1, '#fff', .8)}${sparkle(684, 120, 1.1, '#fff', 1.6)}${sparkle(28, 120, .8, '#F58AA6', 2.2)}
</svg>`,

  /* D — a pretty skincare shelf with one empty, dashed space and a swinging "404" tag. */
  d: (l) => `<svg class="art art-d" viewBox="0 0 560 360" role="img" aria-label="${l === 'ar' ? 'رف عناية بالبشرة ينقصه منتج' : 'A skincare shelf with one product missing'}">
<path d="M80 276V236A200 200 0 0 1 480 236V276Z" fill="#FCE3EB"/>
<path d="M106 276V236A174 174 0 0 1 454 236V276Z" fill="#FFF1F5"/>
<ellipse cx="280" cy="300" rx="236" ry="10" fill="#C79A7A" opacity=".2"/>
<rect x="34" y="268" width="492" height="16" rx="8" fill="#EDD3BC"/>
<rect x="40" y="280" width="480" height="9" rx="4.5" fill="#D9B596"/>
<g>
<rect x="70" y="138" width="50" height="130" rx="14" fill="#CDEADC"/>
<rect x="81" y="108" width="28" height="34" rx="7" fill="#fff"/>
<rect x="78" y="178" width="34" height="54" rx="5" fill="#fff" opacity=".85"/>
<rect x="85" y="190" width="20" height="3" rx="1.5" fill="#9CCDB5"/><rect x="85" y="198" width="14" height="3" rx="1.5" fill="#9CCDB5"/>
<rect x="76" y="146" width="7" height="100" rx="3.5" fill="#fff" opacity=".45"/>
</g>
<g>
<rect x="134" y="212" width="82" height="56" rx="14" fill="#F7C6D4"/>
<rect x="130" y="196" width="90" height="22" rx="9" fill="#E3B77F"/>
<rect x="136" y="199" width="78" height="5" rx="2.5" fill="#fff" opacity=".35"/>
<circle cx="175" cy="242" r="11" fill="#fff" opacity=".8"/>
</g>
<g class="d-gap">
<path d="M246 268V196C246 184 254 176 266 176H284C296 176 304 184 304 196V268Z" fill="#E0567B" fill-opacity=".05" stroke="#E0567B" stroke-width="2.4" stroke-dasharray="7 6"/>
<path d="M264 176V156H286V176M262 156C262 140 288 140 288 156" fill="none" stroke="#E0567B" stroke-width="2.4" stroke-dasharray="6 5"/>
</g>
<circle cx="275" cy="86" r="4" fill="#C79A7A"/>
<g class="d-tag"><path d="M275 86L275 112" stroke="#C79A7A" stroke-width="1.5"/>
<rect x="246" y="110" width="58" height="30" rx="7" fill="#fff" stroke="#F4B3C5" stroke-width="1.5"/>
<text x="275" y="131" text-anchor="middle" font-family="Outfit,sans-serif" font-weight="800" font-size="17" fill="#E0567B">404</text></g>
<g>
<rect x="328" y="246" width="30" height="22" rx="5" fill="#fff"/>
<path d="M324 246L320 162H366L362 246Z" fill="#DCC7EE"/>
<rect x="316" y="150" width="54" height="15" rx="3" fill="#CDB4E4"/>
<rect x="331" y="186" width="24" height="32" rx="4" fill="#fff" opacity=".7"/>
</g>
<g>
<rect x="386" y="176" width="54" height="92" rx="13" fill="#FAD6C0"/>
<rect x="404" y="160" width="18" height="20" rx="3" fill="#fff"/>
<rect x="396" y="148" width="38" height="13" rx="5" fill="#fff"/>
<rect x="430" y="150" width="20" height="7" rx="3" fill="#fff"/>
<rect x="394" y="186" width="7" height="70" rx="3.5" fill="#fff" opacity=".45"/>
</g>
<g class="d-sway">
<path d="M490 268C474 268 466 254 470 238C472 226 480 222 480 210H500C500 222 508 226 510 238C514 254 506 268 490 268Z" fill="#fff" stroke="#EAD3DC" stroke-width="1.5"/>
<path d="M486 212C482 180 466 160 456 140M492 212C494 170 498 140 500 110M496 212C506 186 520 172 532 160" stroke="#8FC4A2" stroke-width="2.6" fill="none" stroke-linecap="round"/>
<ellipse cx="470" cy="176" rx="9" ry="5" transform="rotate(-40 470 176)" fill="#A8D5B8"/><ellipse cx="510" cy="186" rx="9" ry="5" transform="rotate(30 510 186)" fill="#A8D5B8"/>
<g transform="translate(456 138)" fill="#F49AB2"><circle cx="0" cy="-10" r="8"/><circle cx="9.5" cy="-3" r="8"/><circle cx="6" cy="8" r="8"/><circle cx="-6" cy="8" r="8"/><circle cx="-9.5" cy="-3" r="8"/><circle r="5" fill="#EFAF6B"/></g>
<g transform="translate(500 106)" fill="#F7C6D4"><circle cx="0" cy="-11" r="9"/><circle cx="10.5" cy="-3.4" r="9"/><circle cx="6.5" cy="9" r="9"/><circle cx="-6.5" cy="9" r="9"/><circle cx="-10.5" cy="-3.4" r="9"/><circle r="5.5" fill="#EFAF6B"/></g>
<g transform="translate(533 158)" fill="#E9B8F0"><circle cx="0" cy="-8" r="6.5"/><circle cx="7.6" cy="-2.5" r="6.5"/><circle cx="4.7" cy="6.5" r="6.5"/><circle cx="-4.7" cy="6.5" r="6.5"/><circle cx="-7.6" cy="-2.5" r="6.5"/><circle r="4" fill="#EFAF6B"/></g>
</g>
<ellipse class="d-petal" cx="498" cy="122" rx="5" ry="3" fill="#F7C6D4"/>
${sparkle(316, 116, 1, '#F49AB2', 0)}${sparkle(230, 162, .7, '#E3B77F', 1.2)}${sparkle(60, 86, .9, '#F7C6D4', 2)}
</svg>`,
};

/* ── per-option copy ─────────────────────────────────────────────────────── */
const OPT = {
  a: {
    name: 'Midnight vanity — the cracked compact',
    en: { k: 'Error 404 · page not found', h: 'Oops, this page cracked <em>under pressure.</em>', s: 'Mirror, mirror — the page you wanted has slipped out of view. Your reflection, though? Still flawless. Let’s find you something worth the glow.' },
    ar: { k: 'خطأ 404 · الصفحة غير موجودة', h: 'عذرًا، هذه الصفحة تشقّقت <em>تحت الضغط.</em>', s: 'مرآتي يا مرآتي… الصفحة التي تبحثين عنها اختفت. أمّا انعكاسك؟ فما زال مثاليًا. دعينا نجد لكِ ما يليق بتألّقك.' },
  },
  b: {
    name: 'The slipping sheet mask',
    en: { k: 'Error 404 · page not found', h: 'Aah, you shouldn’t be here… <em>but you look gorgeous.</em>', s: 'This page slipped off like a sheet mask after twenty minutes. You’re far too beautiful to be lost — let us take you to the right place.' },
    ar: { k: 'خطأ 404 · الصفحة غير موجودة', h: 'آه، لا يُفترض أن تكوني هنا… <em>لكنكِ تبدين رائعة.</em>', s: 'انزلقت هذه الصفحة مثل قناع الوجه بعد عشرين دقيقة. أنتِ أجمل من أن تضيعي — دعينا نأخذكِ إلى المكان الصحيح.' },
  },
  c: {
    name: 'Lipstick smudge — the 404 written in lipstick',
    en: { k: 'Error 404 · page not found', h: 'Oh no — <em>a little smudge.</em>', s: 'This page wiped right off, but your look didn’t. Touch up with a quick search, or let us take you somewhere gorgeous.' },
    ar: { k: 'خطأ 404 · الصفحة غير موجودة', h: 'أوه — <em>لطخة صغيرة!</em>', s: 'هذه الصفحة انمسحت تمامًا، لكن إطلالتك لم تتأثر. ابحثي بسرعة عمّا تريدين، أو دعينا نأخذكِ إلى مكان رائع.' },
  },
  d: {
    name: 'The shelf with one product missing',
    en: { k: 'Error 404 · page not found', h: 'You’re too beautiful <em>to be lost.</em>', s: 'The page you’re looking for isn’t on our shelf anymore. Let us take you home — everything you love is right this way.' },
    ar: { k: 'خطأ 404 · الصفحة غير موجودة', h: 'أنتِ أجمل <em>من أن تضيعي.</em>', s: 'الصفحة التي تبحثين عنها لم تعد على رفّنا. دعينا نعيدكِ إلى الرئيسية — كل ما تحبينه من هنا.' },
  },
};

/* ── icons (stroke, 20px) ────────────────────────────────────────────────── */
const I = {
  search: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
  home: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/></svg>',
  shop: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1 12H6z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
  sale: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/></svg>',
  best: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>',
  wa: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2z"/></svg>',
};

const bottle = (c1, c2, kind) => ({
  0: `<svg viewBox="0 0 120 120" aria-hidden="true"><rect x="44" y="20" width="32" height="18" rx="5" fill="#fff"/><rect x="36" y="36" width="48" height="66" rx="14" fill="${c1}"/><rect x="44" y="52" width="32" height="30" rx="4" fill="#fff" opacity=".8"/><rect x="41" y="42" width="6" height="52" rx="3" fill="#fff" opacity=".4"/></svg>`,
  1: `<svg viewBox="0 0 120 120" aria-hidden="true"><path d="M42 98L38 34H82L78 98Z" fill="${c1}"/><rect x="36" y="24" width="48" height="12" rx="3" fill="${c2}"/><rect x="46" y="98" width="28" height="12" rx="3" fill="#fff"/><rect x="48" y="52" width="24" height="26" rx="4" fill="#fff" opacity=".75"/></svg>`,
  2: `<svg viewBox="0 0 120 120" aria-hidden="true"><rect x="50" y="14" width="20" height="22" rx="10" fill="${c2}"/><rect x="52" y="32" width="16" height="10" rx="2" fill="#fff"/><rect x="38" y="42" width="44" height="62" rx="12" fill="${c1}"/><rect x="45" y="58" width="30" height="28" rx="4" fill="#fff" opacity=".75"/></svg>`,
  3: `<svg viewBox="0 0 120 120" aria-hidden="true"><rect x="28" y="56" width="64" height="42" rx="12" fill="${c1}"/><rect x="24" y="44" width="72" height="16" rx="7" fill="${c2}"/><circle cx="60" cy="78" r="9" fill="#fff" opacity=".8"/></svg>`,
}[kind]);
const CARDS = [['#CDEADC', '#fff', 0, '#EAF6EF'], ['#FAD6C0', '#E3B77F', 1, '#FFF1E8'], ['#DCC7EE', '#E2B08A', 2, '#F4EEFC'], ['#F7C6D4', '#C13E63', 3, '#FFF0F4']];

/* ── the page ────────────────────────────────────────────────────────────── */
const CSS = `
@font-face{font-family:'Outfit';src:url('outfit-latin.woff2') format('woff2');font-weight:100 900;font-display:swap}
@font-face{font-family:'Cairo';src:url('cairo-arabic.woff2') format('woff2');font-weight:200 1000;font-display:swap;unicode-range:U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0898-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC}
:root{--bg:#fff;--cream:#FFF8F5;--pink-soft:#FFF0F4;--blush:#FCE0E8;--pink:#E0567B;--pink-deep:#C13E63;--pink-ink:#A82F53;--ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--line:rgba(42,34,40,.10);--sale:#E23A4E;--gold:#BE8E2E;--green:#2E9E6B;--r-s:10px;--r-m:16px;--r-l:24px;--sh-s:0 1px 2px rgba(42,34,40,.05),0 4px 14px rgba(42,34,40,.06);--sh-m:0 14px 40px -18px rgba(42,34,40,.28);--sans:"Outfit",system-ui,-apple-system,Segoe UI,Roboto,sans-serif;--ease:cubic-bezier(.22,.61,.36,1);--g:16px}
@media (min-width:768px){:root{--g:22px}}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font:15px/1.6 var(--sans);color:var(--ink);background:var(--bg)}
:lang(ar) body,body:lang(ar){font-family:"Cairo",var(--sans)}
a{color:inherit;text-decoration:none}
.mock{display:flex;align-items:center;gap:12px;height:62px;padding:0 var(--g);background:#fff;border-bottom:1px solid var(--line);font-size:11px;color:var(--muted);letter-spacing:.02em}
.mock b{font-size:17px;color:var(--pink-deep);font-weight:800;letter-spacing:-.01em;white-space:nowrap}
.mock span{margin-inline-start:auto;text-align:end;line-height:1.3}
.mock.ft{height:auto;min-height:120px;background:#2A2228;color:rgba(255,255,255,.55);border:0;justify-content:center}
.mock.ft span{margin:0;text-align:center}
.nf{--fg:var(--ink);--fg2:var(--ink-2);--accent:var(--pink);overflow:hidden}
.hero{position:relative;padding:28px var(--g) 36px;display:grid;gap:22px;align-items:center;justify-items:center;text-align:center}
.art{display:block;width:100%;height:auto}
.copy{max-width:560px;display:grid;gap:14px;justify-items:center}
.k{font-size:11.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--accent)}
:lang(ar) .k{letter-spacing:0;font-size:13px}
h1{font-size:clamp(28px,7.4vw,50px);line-height:1.08;font-weight:800;letter-spacing:-.025em;color:var(--fg);text-wrap:balance}
h1 em{font-style:normal;color:var(--accent);display:inline}
:lang(ar) h1{letter-spacing:0;line-height:1.3}
.s{font-size:15.5px;color:var(--fg2);max-width:480px;text-wrap:pretty}
.ar-line{font-family:"Cairo",var(--sans);font-size:15px;color:var(--fg2);border-top:1px dashed var(--line);padding-top:10px;width:100%}
.ar-line b{display:block;font-size:17px;color:var(--fg)}
.sf{display:flex;width:100%;max-width:460px;height:52px;background:#fff;border:1.5px solid var(--line);border-radius:99px;padding:4px;box-shadow:var(--sh-s);margin-top:4px}
.sf:focus-within{border-color:var(--pink)}
.sf svg{flex:none;align-self:center;margin-inline:12px 6px;color:var(--muted)}
.sf input{flex:1;min-width:0;border:0;outline:0;background:none;font:inherit;font-size:15px;color:var(--ink)}
.sf button{flex:none;border:0;border-radius:99px;background:var(--ink);color:#fff;font:inherit;font-weight:700;font-size:14px;padding:0 20px;cursor:pointer}
.cta{display:inline-flex;align-items:center;gap:8px;height:48px;padding:0 26px;border-radius:99px;background:var(--pink);color:#fff;font-weight:700;font-size:15px;box-shadow:0 10px 26px -12px rgba(224,86,123,.8);transition:transform .2s var(--ease),background .2s}
.cta:hover{background:var(--pink-deep);transform:translateY(-1px)}
.links{display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;max-width:460px}
.links a{display:flex;align-items:center;justify-content:center;gap:7px;height:44px;padding:0 14px;border-radius:99px;background:#fff;border:1px solid var(--line);font-size:13.5px;font-weight:600;color:var(--ink);white-space:nowrap;transition:border-color .2s,transform .2s var(--ease)}
.links a:hover{border-color:var(--pink);transform:translateY(-1px)}
.links a.sale{color:var(--sale)}
.links a.wa{color:#1F8F55}
.trend{padding:30px var(--g) 40px;background:var(--cream)}
.trend-h{display:flex;align-items:baseline;justify-content:space-between;gap:12px;max-width:1180px;margin:0 auto 14px}
.trend-h h2{font-size:20px;font-weight:800;letter-spacing:-.01em}
.trend-h span{font-size:12.5px;color:var(--muted)}
.cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:1180px;margin:0 auto}
.card{background:#fff;border:1px solid var(--line);border-radius:var(--r-m);overflow:hidden}
.card .ph{aspect-ratio:5/4;display:grid;place-items:center}
.card .ph svg{width:56%;height:auto}
.card .t{padding:10px 12px 14px;display:grid;gap:3px}
.card .t i{font-style:normal;font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.card .t b{font-size:13.5px;font-weight:600;line-height:1.3;color:var(--ink)}
.card .t u{text-decoration:none;font-weight:800;color:var(--pink-deep);font-size:14px}
@media (min-width:900px){
  .hero{padding:56px var(--g) 64px}
  .links{display:flex;flex-wrap:wrap;justify-content:center;max-width:none;width:auto}
  .cards{grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;max-width:1040px}
  .trend-h{max-width:1040px}
}
/* motion — every keyframe is decorative, so reduced motion turns them all off */
.tw{transform-box:fill-box;transform-origin:center;animation:tw 3.2s ease-in-out infinite}
@keyframes tw{0%,100%{opacity:.35;transform:scale(.6)}50%{opacity:1;transform:scale(1)}}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}.tw{opacity:1}}

/* ── A · midnight vanity ── */
.nf-a .hero{background:radial-gradient(120% 80% at 50% 0%,#5A3349 0%,#2E1D29 58%,#1F151C 100%);--fg:#FFF8F5;--fg2:rgba(255,248,245,.78);--accent:#F2C9A6}
.nf-a .hero::before{content:"";position:absolute;inset:0;background:radial-gradient(40% 30% at 50% 30%,rgba(242,201,166,.18),transparent 70%);pointer-events:none}
.nf-a .art{max-width:min(76vw,300px);position:relative}
.nf-a h1 em{color:#F7B6C8}
.nf-a .ar-line{border-color:rgba(255,255,255,.18)}
.nf-a .ar-line b{color:#FFF8F5}
.nf-a .sf{border-color:transparent}
.nf-a .links a{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#FFF8F5}
.nf-a .links a.sale{color:#FF9BAA}.nf-a .links a.wa{color:#8EE0B4}
.nf-a .links a:hover{border-color:#F2C9A6}
.nf-a .cta{background:linear-gradient(135deg,#F2C9A6,#E39A7B);color:#2E1D29;box-shadow:0 10px 30px -12px rgba(242,201,166,.7)}
.a-float{animation:afl 7s ease-in-out infinite}
@keyframes afl{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
.a-shard{animation:ash 6s ease-in-out infinite}
@keyframes ash{0%,90%,100%{transform:translate(0,0)}93%{transform:translate(1px,-1px)}96%{transform:translate(-1px,1px)}}
@media (min-width:900px){.nf-a .art{max-width:290px}.nf-a .hero{padding-top:44px;gap:18px}}

/* ── B · sheet mask ── */
.nf-b{background:linear-gradient(160deg,#FFF0F4 0%,#FFF8F5 55%,#F1FAF5 100%)}
.nf-b .hero{background:transparent}
.nf-b .art{max-width:min(80vw,320px)}
.nf-b h1 em{display:block;font-family:inherit;color:var(--pink)}
.b-mask{animation:bm 6s var(--ease) infinite;transform-origin:196px 200px}
@keyframes bm{0%,100%{transform:translateY(0) rotate(0)}50%{transform:translateY(5px) rotate(1.5deg)}}
.b-drop{animation:bd 3s ease-in-out infinite}
@keyframes bd{0%,100%{transform:translateY(0)}50%{transform:translateY(3px)}}
.b-drip{animation:bdr 3s ease-in infinite}
@keyframes bdr{0%{transform:translateY(-8px);opacity:0}30%{opacity:1}100%{transform:translateY(14px);opacity:0}}
.b-bubble{transform-box:fill-box;transform-origin:20% 100%;animation:bb 4s var(--ease) infinite}
@keyframes bb{0%,100%{transform:scale(1)}8%{transform:scale(1.08)}16%{transform:scale(1)}}
@media (min-width:900px){
  .nf-b .hero{grid-template-columns:minmax(0,1fr) minmax(0,1fr);max-width:1180px;margin:0 auto;text-align:start;justify-items:start;gap:56px}
  .nf-b .art{max-width:440px;justify-self:end}
  .nf-b .copy{justify-items:start}
  .nf-b .links{justify-content:flex-start}
  .nf-b .trend{background:rgba(255,255,255,.6)}
}

/* ── C · lipstick smudge ── */
.nf-c .hero{background:radial-gradient(60% 50% at 15% 10%,rgba(255,214,196,.9),transparent 70%),radial-gradient(60% 60% at 90% 30%,rgba(229,214,252,.9),transparent 70%),linear-gradient(135deg,#FFEDE3 0%,#FDE4EE 50%,#F0E8FD 100%)}
.nf-c .art{max-width:min(92vw,760px)}
@media (min-width:900px){.nf-c .art{max-width:620px}.nf-c .hero{gap:14px}}
.cd{stroke-dasharray:1;stroke-dashoffset:0;animation:cd 1.1s var(--ease) both}
.ch{stroke-dasharray:.3 .06 .5 .14;animation:chf .5s ease 1.4s both}
@keyframes chf{from{opacity:0}to{opacity:1}}
.cd.cd2{animation-delay:.45s}.cd.cd3{animation-delay:.9s}
@keyframes cd{from{stroke-dashoffset:1}to{stroke-dashoffset:0}}
.c-tube{animation:ct .6s var(--ease) 1.6s both}
@keyframes ct{from{opacity:0}to{opacity:1}}
.c-drop{animation:cdr 2.8s ease-in-out infinite}
@keyframes cdr{0%,100%{transform:translateX(0)}50%{transform:translateX(-4px)}}
.nf-c .links a{background:rgba(255,255,255,.75);backdrop-filter:blur(6px)}
.nf-c h1{font-size:clamp(30px,8vw,56px)}
.nf-c h1 em{display:block}

/* ── D · the shelf ── */
.nf-d{background:var(--cream)}
.nf-d .hero{background:transparent}
.nf-d .art{max-width:min(92vw,520px)}
.nf-d .copy{background:#fff;border-radius:var(--r-l);padding:24px 18px;box-shadow:var(--sh-m);width:100%}
.d-gap{animation:dg 2.6s ease-in-out infinite}
@keyframes dg{0%,100%{opacity:1}50%{opacity:.45}}
.d-tag{transform-box:view-box;transform-origin:275px 86px;animation:dt 3.4s ease-in-out infinite}
@keyframes dt{0%,100%{transform:rotate(-6deg)}50%{transform:rotate(6deg)}}
.d-sway{transform-box:view-box;transform-origin:490px 268px;animation:ds 6s ease-in-out infinite}
@keyframes ds{0%,100%{transform:rotate(-1.2deg)}50%{transform:rotate(1.2deg)}}
.d-petal{animation:dp 7s ease-in infinite}
@keyframes dp{0%{transform:translate(0,0) rotate(0);opacity:0}10%{opacity:1}100%{transform:translate(-60px,150px) rotate(220deg);opacity:0}}
.nf-d .trend{background:#fff}
@media (min-width:900px){
  .nf-d .hero{grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);max-width:1180px;margin:0 auto;gap:48px;text-align:start}
  .nf-d .copy{order:-1;justify-items:start;padding:40px 40px 36px}
  .nf-d .links{display:grid;grid-template-columns:1fr 1fr;width:100%;max-width:460px}
  .nf-d .art{max-width:600px}
}
`;

function page(key, lang) {
  const t = T[lang], o = OPT[key][lang], rtl = lang === 'ar';
  const other = OPT[key][rtl ? 'en' : 'ar'];
  const plain = (s) => s.replace(/<\/?em>/g, '');
  const arLine = rtl ? '' : `<p class="ar-line" lang="ar" dir="rtl"><b>${plain(OPT[key].ar.h)}</b>${OPT[key].ar.s}</p>`;
  const cards = t.products.map(([n, p], i) => {
    const [c1, c2, k, bg] = CARDS[i];
    return `<a class="card" href="#"><div class="ph" style="background:${bg}">${bottle(c1, c2, k)}</div><div class="t"><i>${rtl ? 'منتج تجريبي' : 'Sample'}</i><b>${n}</b><u>${p}</u></div></a>`;
  }).join('');
  return `<!doctype html>
<html lang="${lang}" dir="${rtl ? 'rtl' : 'ltr'}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>${rtl ? 'الصفحة غير موجودة' : 'Page not found'} · ${key.toUpperCase()}</title>
<style>${CSS}</style>
</head>
<body>
<div class="mock"><b>K-Beauty Bliss</b><span>${t.hd}</span></div>
<main class="nf nf-${key}">
<section class="hero">
${ART[key](lang)}
<div class="copy">
<p class="k">${o.k}</p>
<h1>${o.h}</h1>
<p class="s">${o.s}</p>
${arLine}
<a class="cta" href="/">${I.home}${t.home}</a>
<form class="sf" action="/shop/" method="get" role="search">${I.search}<input type="search" name="s" placeholder="${t.search}" aria-label="${t.search}"><button type="submit">${t.go}</button></form>
<nav class="links" aria-label="${rtl ? 'روابط مفيدة' : 'Helpful links'}">
<a href="/shop/">${I.shop}${t.shop}</a><a class="sale" href="/super-sale/">${I.sale}${t.sale}</a><a href="/best-sellers/">${I.best}${t.best}</a><a class="wa" href="#">${I.wa}${t.wa}</a>
</nav>
</div>
</section>
<section class="trend"><div class="trend-h"><h2>${t.trend}</h2><span>${t.trendSub}</span></div><div class="cards">${cards}</div></section>
</main>
<div class="mock ft"><span>${t.ft}</span></div>
</body>
</html>
`;
}

for (const key of Object.keys(OPT)) {
  writeFileSync(join(here, `option-${key}.html`), page(key, 'en'));
  writeFileSync(join(here, `option-${key}-ar.html`), page(key, 'ar'));
}

/* ── index: every option framed at 390 (EN + AR) and 1280 ────────────────── */
const NOTE = {
  a: 'Dark plum “midnight vanity” hero, rose-gold compact; the mirror is split by a crack and the lower shard of her surprised face sits a few pixels off. 404 is pressed into the powder. Motion: compact floats, sparkles twinkle, the shard trembles.',
  b: 'Blush split layout. A cute face in a K-beauty bow headband; her sheet mask has slid down so its eye holes sit on her cheeks and her wobbly “broken” smile peeks through one of them. One wink, a sweat drop, an “aah!” bubble. Motion: mask sways, a drop of essence drips, bubble pops.',
  c: 'Full-bleed peach→pink→lilac gradient. A fallen lipstick has written a giant “404” — it draws itself on load (once, 2s) — with the cap rolled away and a serum spilled beside it.',
  d: 'Cream page, white copy card beside a pastel shelf under an arch: toner, cream jar, tube, pump, a vase of flowers — and one dashed, empty space with a swinging “404” tag. Motion: gap pulses, tag swings, flowers sway, a petal falls.',
};
const index = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>404 page options</title>
<style>
@font-face{font-family:'Outfit';src:url('outfit-latin.woff2') format('woff2');font-weight:100 900;font-display:swap}
:root{--ink:#2A2228;--ink-2:#5E545A;--muted:#8C828A;--pink:#E0567B;--pink-deep:#C13E63;--cream:#FFF8F5;--line:rgba(42,34,40,.10);--bg:#FBF6F4}
*{box-sizing:border-box;margin:0;padding:0}
body{font:15px/1.6 "Outfit",system-ui,sans-serif;color:var(--ink);background:var(--bg);padding:28px 16px 60px}
.wrap{max-width:1320px;margin:0 auto}
header h1{font-size:clamp(26px,5vw,40px);line-height:1.1;letter-spacing:-.02em;font-weight:800}
header p{color:var(--ink-2);max-width:820px;margin-top:10px}
.rec{margin:22px 0 8px;padding:18px 20px;border-radius:18px;background:#fff;border:2px solid var(--pink);max-width:900px}
.rec b{color:var(--pink-deep)}
section{margin-top:44px;padding-top:28px;border-top:1px solid var(--line)}
section h2{font-size:24px;font-weight:800;letter-spacing:-.01em;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
section h2 .l{display:inline-grid;place-items:center;width:40px;height:40px;border-radius:12px;background:var(--ink);color:#fff}
section h2 .tag{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--pink);color:#fff;border-radius:99px;padding:4px 12px}
section > p{color:var(--ink-2);max-width:900px;margin:8px 0 16px}
.row{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap}
figure{display:grid;gap:6px}
figcaption{font-size:12px;color:var(--muted);font-weight:600}
figcaption a{color:var(--pink-deep)}
.fr{--s:.72;width:calc(var(--w) * var(--s) * 1px);height:calc(var(--h) * var(--s) * 1px);overflow:hidden;border-radius:18px;background:#fff;box-shadow:0 14px 40px -18px rgba(42,34,40,.35);border:1px solid var(--line)}
.fr iframe{width:calc(var(--w) * 1px);height:calc(var(--h) * 1px);border:0;transform:scale(var(--s));transform-origin:0 0;display:block}
.ph{--w:390;--h:844}
.dt{--w:1280;--h:800;--s:.5}
@media (max-width:700px){.ph{--s:.42}.dt{--s:.27}.row{gap:10px}}
.foot{margin-top:40px;color:var(--ink-2);font-size:14px;max-width:900px}
.foot li{margin:6px 0 0 18px}
</style></head><body><div class="wrap">
<header><h1>Your 404 page — four options</h1>
<p>Every page that does not exist on the shop will show one of these instead of the plain “404 | Not Found”. Each one sits inside your real header, menu, cart and footer (shown here as grey placeholders). Each frame is live: hover the buttons, and the motion is the real motion. Pick a letter.</p></header>
<div class="rec"><b>Recommended: B — the slipping sheet mask.</b> It is the only one that says both of your lines word for word (“Aah, you shouldn’t be here… but you look gorgeous”), its face is the “broken face” you described without being sad or scary, and it is the most K-beauty of the four (sheet mask + bow headband). It also stays light: the whole illustration is about 4.6 KB of inline SVG, and on a 390px phone the headline and the “Take me home” button are on the first screen. Runner-up: A, if you want a darker, more luxurious look.</div>
${['a', 'b', 'c', 'd'].map((k) => `<section><h2><span class="l">${k.toUpperCase()}</span>${OPT[k].name}${k === 'b' ? '<span class="tag">Recommended</span>' : ''}</h2>
<p>${NOTE[k]}</p>
<div class="row">
<figure><div class="fr ph"><iframe loading="lazy" src="option-${k}.html" title="Option ${k.toUpperCase()} at 390px, English"></iframe></div><figcaption>Phone 390 · English · <a href="shots/${k}-en-390.png">screenshot</a></figcaption></figure>
<figure><div class="fr ph"><iframe loading="lazy" src="option-${k}-ar.html" title="Option ${k.toUpperCase()} at 390px, Arabic"></iframe></div><figcaption>Phone 390 · العربية · <a href="shots/${k}-ar-390.png">screenshot</a></figcaption></figure>
<figure><div class="fr dt"><iframe loading="lazy" src="option-${k}.html" title="Option ${k.toUpperCase()} at 1280px"></iframe></div><figcaption>Desktop 1280 · <a href="option-${k}.html">open full page</a> · <a href="shots/${k}-en-1280.png">screenshot</a> · <a href="shots/${k}-ar-1280.png">Arabic</a></figcaption></figure>
</div></section>`).join('\n')}
<div class="foot"><b>Same on all four</b><ul>
<li>A “Take me home” button, a search box (it searches the shop exactly like the header search), and four links: Shop all, Super Sale, Best sellers, WhatsApp us.</li>
<li>“Trending now” shows four of your real best sellers on the live page (the cards here are samples). Cost: one database query, cached for an hour, so almost every 404 costs no query at all. It can be switched off.</li>
<li>The page answers with a real 404 status and tells Google not to index it, so a broken link never becomes a thin “page” in search results.</li>
<li>All motion stops for visitors whose phone asks for reduced motion. No new JavaScript, no images to download — the drawing is part of the page.</li>
</ul></div>
</div></body></html>
`;
writeFileSync(join(here, 'index.html'), index);
console.log('built', Object.keys(OPT).length * 2, 'pages');
