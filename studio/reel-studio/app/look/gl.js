// KBB Studio · look · the WebGL2 engine behind colour looks, skin touch-up, background removal and transitions.
// ONE context for the whole app (a detached canvas), programs compiled once, textures/FBOs reused by key. Every call is
// synchronous, so it runs inside core/render.js's synchronous frame (preview AND export draw through it identically).
// Coordinates: texel row 0 = TOP of a picture everywhere (uploads are not flipped; FBO passes keep that orientation);
// only the final pass to the canvas flips, so drawImage(glCanvas) shows the picture the right way up.

const VS = `#version 300 es
in vec2 p; out vec2 uv; uniform float flip;
void main(){ uv = p; gl_Position = vec4(p.x * 2. - 1., flip > .5 ? 1. - p.y * 2. : p.y * 2. - 1., 0., 1.); }`;

const COMMON = `#version 300 es
precision highp float;
in vec2 uv; out vec4 o;
float luma(vec3 c){ return dot(c, vec3(.2126, .7152, .0722)); }
float hash(vec2 p){ p = fract(p * vec2(123.34, 456.21)); p += dot(p, p + 45.32); return fract(p.x * p.y); }
`;

const FS = {
  copy: COMMON + `uniform sampler2D s; uniform vec2 px;
void main(){ o = .25 * (texture(s, uv + px * vec2(-.5,-.5)) + texture(s, uv + px * vec2(.5,-.5)) + texture(s, uv + px * vec2(-.5,.5)) + texture(s, uv + px * vec2(.5,.5))); }`,
  blur: COMMON + `uniform sampler2D s; uniform vec2 dir;
void main(){
  const float w0 = .2270270270, w1 = .3162162162, w2 = .0702702703;
  o = texture(s, uv) * w0 + (texture(s, uv + dir * 1.3846153846) + texture(s, uv - dir * 1.3846153846)) * w1 + (texture(s, uv + dir * 3.2307692308) + texture(s, uv - dir * 3.2307692308)) * w2;
}`,
  look: COMMON + `
uniform sampler2D src, blurS, blurL, seg, face, bgTex;
uniform vec2 res, segTexel; uniform float frame;
uniform int hasSeg, hasFace, bgMode;           // bgMode 0 none · 1 blur · 2 colour · 3 gradient · 4 image · 5 transparent
uniform vec3 mg, mo, wb; uniform float ev, amount;
uniform float Lexp, Lcon, Lsat, Lvib, Ltemp, Ltint, Lfade, Lbw, Lglow, Lvig, Lgrain, Lcurve, Lclar, Lsplit;
uniform vec3 Llift, Lgamma, Lgain, Lsh, Lhi, Lbwmix;
uniform float Ts, Teye, Tunder;
uniform vec3 bgA, bgB; uniform vec2 bgScale, bgOff;
// background removal v2 (look.bg with q >= 2, see bg.js): bgMode 5 = transparent (alpha out); keyMode 0 = the cached
// mask (person or main subject), 1 = chroma key (green / blue screen) computed here per pixel
uniform int q2, keyMode, kDom; uniform vec3 keyCol; uniform float kTol, kSoft, kSpill, eFeather, eGrow, eWrap;
// look.adjust (manual light / colour / detail sliders + Enhance video), see adjust.js for the ranges
uniform int hasAdj, upS, cmpOn; uniform vec3 Again; uniform vec2 srcRes;
uniform float Abri, Acon, Ahi, Ash, Awh, Abl, Asat, Avib, Asharp, Aclar, Avig, Adn, Askin, Alift, cmpU;
float skinOf(vec3 c){                          // soft skin-tone mask (YCbCr), any skin colour
  float y = luma(c), cb = (c.b - y) * .5389 + .5, cr = (c.r - y) * .6350 + .5;
  vec2 d = (vec2(cb, cr) - vec2(.445, .585)) / vec2(.055, .05);
  return exp(-dot(d, d) * .5) * smoothstep(.06, .2, y);
}
vec3 lanczos(vec2 p){                          // Lanczos-2 (4x4 taps) from the source's own pixel grid: upscaling
  vec2 tc = p * srcRes - .5, f = fract(tc), b = (floor(tc) + .5) / srcRes; vec3 acc = vec3(0.); float ws = 0.;
  for (int j = -1; j <= 2; j++) for (int i = -1; i <= 2; i++) {
    vec2 d = vec2(float(i), float(j)) - f;
    vec2 w = vec2(d.x == 0. ? 1. : sin(3.14159265 * d.x) * sin(1.5707963 * d.x) / (1.2337006 * d.x * d.x),
                  d.y == 0. ? 1. : sin(3.14159265 * d.y) * sin(1.5707963 * d.y) / (1.2337006 * d.y * d.y));
    float ww = w.x * w.y; acc += texture(src, b + vec2(float(i), float(j)) / srcRes).rgb * ww; ws += ww;
  }
  return clamp(acc / ws, 0., 1.);
}
vec3 srcAt(vec2 p){ return upS == 1 ? lanczos(p) : texture(src, p).rgb; }
vec3 bilateral(vec2 p, vec3 c0, vec2 px){      // edge-keeping 5x5 denoise; the range kernel widens with the strength
  float sr = mix(.025, .11, Adn), inv = 1. / (2. * sr * sr); vec3 acc = vec3(0.); float ws = 0.;
  for (int j = -2; j <= 2; j++) for (int i = -2; i <= 2; i++) {
    vec3 c = texture(src, p + vec2(float(i), float(j)) * px).rgb, d = c - c0;
    float w = exp(-dot(d, d) * inv - float(i * i + j * j) * .22); acc += c * w; ws += w;
  }
  return mix(c0, acc / ws, clamp(Adn * 1.6, 0., 1.));
}
vec3 adjust(vec3 c, vec3 bl, float skin, vec2 q){
  c *= Again; bl *= Again;
  float L = luma(clamp(bl, 0., 1.));
  // low-light lift (Enhance): opens the shadows, most where the neighbourhood is dark
  c = mix(c, pow(max(c, 0.), vec3(1. / (1. + Alift * 1.4))), 1. - smoothstep(0., .6, L));
  // whites / blacks: the end points of the tone range
  float bp = -Abl * .07, wp = 1. - Awh * .16; c = (c - bp) / (wp - bp);
  // brightness: mid-tones (gamma), the ends stay put
  c = pow(max(c, 0.), vec3(exp2(-Abri * .75)));
  // shadows / highlights: local, from the neighbourhood's brightness (no flat grey look)
  float ws = pow(1. - smoothstep(0., .5, L), 2.), wh = pow(smoothstep(.42, 1., L), 1.5);
  c = Ash > 0. ? mix(c, pow(max(c, 0.), vec3(1. / (1. + Ash * .9))), ws) : c * (1. + Ash * ws * .55);
  c = Ahi < 0. ? mix(c, c * (1. + Ahi * .42), wh) : mix(c, 1. - (1. - clamp(c, 0., 1.)) * (1. - Ahi * .45), wh);
  // contrast about mid-grey
  c = (c - .45) * (1. + Acon * (Acon > 0. ? .65 : .5)) + .45;
  // clarity: local contrast in the mid-tones
  float l = luma(clamp(c, 0., 1.));
  c += (c - bl) * Aclar * .9 * (.35 + 2.6 * l * (1. - l));
  // saturation / vibrance (vibrance lifts the dull colours first); skin-tone protection holds faces back
  l = luma(c); float sat = max(max(c.r, c.g), c.b) - min(min(c.r, c.g), c.b);
  float sk = skin * Askin, satK = 1. + Asat * (Asat > 0. ? 1. - sk * .55 : 1.);
  float vibK = Avib * (1. - clamp(sat * 1.6, 0., 1.)) * (1. - sk * .75);
  c = mix(vec3(l), c, max(0., satK + vibK));
  // vignette (negative darkens the edges, positive lightens them)
  float v = smoothstep(.25, .9, length((q - .5) * vec2(1., 1.15)));
  c *= 1. + Avig * v * (Avig < 0. ? .85 : .5);
  return c;
}
vec3 correct(vec3 c){ return max((c * mg + mo) * wb * exp2(ev), 0.); }
vec3 grade(vec3 c, vec3 bl){
  vec3 c0 = c;
  float k = exp2(Lexp); c *= k; bl *= k;
  c += (c - bl) * Lclar;
  c *= vec3(1. + Ltemp * .12 + Ltint * .04, 1. - Ltint * .08, 1. - Ltemp * .12 + Ltint * .04);
  c = Lgain * (c + Llift * (1. - c));
  c = pow(max(c, 0.), 1. / Lgamma);
  c = (c - .45) * Lcon + .45;
  vec3 cc = clamp(c, 0., 1.); c = mix(c, cc * cc * (3. - 2. * cc), Lcurve);
  float l = luma(clamp(c, 0., 1.));
  c += (Lsh * (1. - smoothstep(0., .55, l)) + Lhi * smoothstep(.45, 1., l)) * Lsplit;
  l = luma(c); float sat = max(max(c.r, c.g), c.b) - min(min(c.r, c.g), c.b);
  c = mix(vec3(l), c, Lsat * (1. + Lvib * (1. - clamp(sat, 0., 1.))));
  c = mix(c, vec3(dot(c, Lbwmix)), Lbw);
  c = Lfade + c * (1. - Lfade);
  vec3 bh = max(bl - .5, 0.) * 2.; c = 1. - (1. - clamp(c, 0., 1.)) * (1. - clamp(bh * Lglow, 0., 1.));
  return mix(c0, c, amount);
}
float segAlpha(vec2 p){
  vec3 cc = texture(src, p).rgb; float ws = 0., a = 0.;
  for (int j = -1; j <= 1; j++) for (int i = -1; i <= 1; i++) {
    vec2 q = p + vec2(float(i), float(j)) * segTexel;
    vec3 cs = texture(src, q).rgb; vec3 d = cs - cc;
    float w = exp(-dot(d, d) * 30.) * ((i == 0 && j == 0) ? 2. : 1.);
    a += texture(seg, q).r * w; ws += w;
  }
  return smoothstep(.32, .68, a / ws);
}
// ── matte v2 ──
// joint-bilateral upsampling of the low-res mask (5x5 mask texels, colour-guided), the threshold moved by Shrink/grow
// and widened by Feather, then local colour-line matting in the edge band: alpha from where the pixel's colour sits
// between the nearby person colour F and background colour B, which recovers hair strands the 256 px mask cannot hold.
// fgShift: the pixel's colour with the old background's share taken out (edge decontamination).
float refineAlpha(vec2 p, out vec3 fgShift){
  fgShift = vec3(0.);
  float c = .5 - eGrow * .3, wd = .12 + eFeather * .45;
  // interior / far background: the 5x5 window is all one side, skip the work
  float m0 = texture(seg, p).r, mA = texture(seg, p + segTexel * vec2(2.5, 2.5)).r, mB = texture(seg, p + segTexel * vec2(-2.5, 2.5)).r,
        mC = texture(seg, p + segTexel * vec2(2.5, -2.5)).r, mD = texture(seg, p + segTexel * vec2(-2.5, -2.5)).r;
  float lo = min(min(min(m0, mA), min(mB, mC)), mD), hi = max(max(max(m0, mA), max(mB, mC)), mD);
  if (lo > .97 || hi < .03) return smoothstep(c - wd, c + wd, m0);
  vec3 cc = texture(src, p).rgb; float ws = 0., a = 0., wf = 0., wb = 0.; vec3 F = vec3(0.), B = vec3(0.);
  for (int j = -2; j <= 2; j++) for (int i = -2; i <= 2; i++) {
    vec2 q = p + vec2(float(i), float(j)) * segTexel;
    float m = texture(seg, q).r; vec3 cs = texture(src, q).rgb, d = cs - cc;
    float sp = exp(-float(i * i + j * j) * .22), w = sp * exp(-dot(d, d) * 20.);
    a += m * w; ws += w;
    float mf = smoothstep(.75, .97, m) * sp, mb = (1. - smoothstep(.03, .25, m)) * sp;
    F += cs * mf; wf += mf; B += cs * mb; wb += mb;
  }
  float alpha = smoothstep(c - wd, c + wd, a / ws);
  if (wf > .25 && wb > .25) {
    F /= wf; B /= wb; vec3 fb = F - B; float L = dot(fb, fb);
    float band = 1. - abs(alpha * 2. - 1.);
    if (L > .004 && band > 0.) {
      float ac = clamp(dot(cc - B, fb) / L, 0., 1.);
      alpha = mix(alpha, ac, smoothstep(.004, .04, L) * band * .85);
    }
    if (alpha > .002 && alpha < .998) {
      vec3 fe = clamp((cc - (1. - alpha) * B) / max(alpha, .05), 0., 1.);
      fe = mix(F, fe, smoothstep(.15, .6, alpha));
      fgShift = fe - cc;
    }
  }
  return alpha;
}
float keyDist(vec3 c){
  float y = luma(c), yk = luma(keyCol);
  vec2 a = vec2((c.b - y) * .5389, (c.r - y) * .635), b = vec2((keyCol.b - yk) * .5389, (keyCol.r - yk) * .635);
  return length(a - b) + abs(y - yk) * .12;
}
float chromaAlpha(vec2 p){
  vec2 t = 1. / srcRes;
  float d = keyDist(texture(src, p).rgb) * .5 + (keyDist(texture(src, p + vec2(t.x, 0.)).rgb) + keyDist(texture(src, p - vec2(t.x, 0.)).rgb)
          + keyDist(texture(src, p + vec2(0., t.y)).rgb) + keyDist(texture(src, p - vec2(0., t.y)).rgb)) * .125;
  float tol = max(.005, kTol - eGrow * .06), soft = kSoft * (1. + eFeather * 3.);
  return smoothstep(tol, tol + soft, d);
}
vec3 despill(vec3 c){
  if (kDom == 1) { float l = max(c.r, c.b); if (c.g > l) c.g = mix(c.g, l, kSpill); }
  else if (kDom == 2) { float l = max(c.r, c.g); if (c.b > l) c.b = mix(c.b, l, kSpill); }
  else { float l = max(c.g, c.b); if (c.r > l) c.r = mix(c.r, l, kSpill); }
  return c;
}
float matteAt(vec2 p){
  if (keyMode == 1) return chromaAlpha(p);
  return smoothstep(.3, .7, texture(seg, p).r);
}
vec3 bgAt(vec2 p){
  if (bgMode == 1) { vec3 b = correct(texture(blurL, p).rgb); return grade(b, b); }
  if (bgMode == 2) return bgA;
  if (bgMode == 3) return mix(bgA, bgB, clamp(p.y + (p.x - .5) * .35, 0., 1.));
  return texture(bgTex, p * bgScale + bgOff).rgb;
}
void main(){
  vec2 px = 1. / res;
  bool cut2 = q2 == 1 && bgMode > 0 && (hasSeg == 1 || keyMode == 1);
  float matte = 1.; vec3 fgShift = vec3(0.);
  if (cut2) matte = keyMode == 1 ? chromaAlpha(uv) : refineAlpha(uv, fgShift);
  vec3 orig = srcAt(uv);
  if (cut2) orig = keyMode == 1 ? despill(orig) : clamp(orig + fgShift, 0., 1.);
  vec3 c = orig;
  vec3 low = texture(blurS, uv).rgb;
  float skin = 0.;
  if (hasAdj == 1) {
    skin = skinOf(orig);
    // denoise and sharpen work on the SOURCE's pixel grid: the same scene detail at every working size, so the small
    // preview monitor (which reads the mipmaps, where the grain is already averaged away) is the export scaled down
    vec2 sp = 1. / srcRes;
    if (Adn > 0.) { c = bilateral(uv, orig, sp); orig = c; }
    if (Asharp > 0.) {                                                   // unsharp mask with noise coring, gentler on skin
      vec3 m4 = .25 * (texture(src, uv + vec2(sp.x, 0.)).rgb + texture(src, uv - vec2(sp.x, 0.)).rgb + texture(src, uv + vec2(0., sp.y)).rgb + texture(src, uv - vec2(0., sp.y)).rgb);
      vec3 m8 = .5 * (m4 + texture(blurS, uv).rgb);
      vec3 d = c - mix(m4, m8, .35); float a = length(d);
      d *= smoothstep(.004 + Adn * .03, .02 + Adn * .06, a);
      c += d * Asharp * 1.6 * (1. - skin * Askin * .6); orig = c;
    }
  }
  if (hasFace == 1 && Ts > 0.) {
    vec4 fm = texture(face, uv);
    float skin = fm.r * Ts, eye = fm.g, under = fm.b;
    vec3 d = orig - low; float edge = exp(-dot(d, d) * 40.);
    c = orig - d * skin * .9 * edge;                                   // smooth the skin's mid detail (edges stay)
    vec3 m4 = .25 * (texture(src, uv + vec2(px.x, 0.)).rgb + texture(src, uv - vec2(px.x, 0.)).rgb + texture(src, uv + vec2(0., px.y)).rgb + texture(src, uv - vec2(0., px.y)).rgb);
    c += (orig - m4) * skin * .25;                                     // ...but give some fine texture (pores) back
    c = mix(c, max(c, low * 1.05 + .012), clamp(under * Tunder * 1.4, 0., 1.)); // soften the under-eye shadow
    c += (orig - low) * eye * Teye * .6 + eye * Teye * .035;             // brighter, crisper eyes
  }
  c = correct(c);
  vec3 bl = correct(low);
  if (hasAdj == 1) { c = adjust(c, bl, skin, uv); bl *= Again; }
  vec3 g = grade(c, bl);
  if (cut2 && bgMode != 5) {
    vec3 bgc = bgAt(uv);
    if (eWrap > 0. && matte > .002) {
      // light wrap: the new background's light spills over the person's edge (a ring of 8 taps, ~1.5 % of the frame)
      vec2 r = vec2(.016 * res.y / res.x, .016) * (1. + eFeather);
      float wide = 0.; vec3 wc = vec3(0.);
      for (int i = 0; i < 8; i++) { float an = float(i) * .785398; vec2 q = uv + vec2(cos(an), sin(an)) * r; wide += matteAt(q); wc += bgAt(q); }
      wide /= 8.; wc /= 8.;
      float wa = eWrap * matte * clamp((1. - wide) * 1.8, 0., 1.);
      g = mix(g, 1. - (1. - clamp(g, 0., 1.)) * (1. - wc), wa * .85);
    }
    g = mix(bgc, g, matte);
  } else if (q2 == 0 && bgMode > 0 && hasSeg == 1) {
    float a = segAlpha(uv);
    vec3 bgc;
    if (bgMode == 1) { vec3 b = correct(texture(blurL, uv).rgb); bgc = grade(b, b); }
    else if (bgMode == 2) bgc = bgA;
    else if (bgMode == 3) bgc = mix(bgA, bgB, clamp(uv.y + (uv.x - .5) * .35, 0., 1.));
    else bgc = texture(bgTex, uv * bgScale + bgOff).rgb;
    g = mix(bgc, g, a);
  }
  float vig = smoothstep(.85, .25, length((uv - .5) * vec2(1., 1.15)));
  g *= mix(1., vig, Lvig * amount);
  g += (hash(floor(uv * res) + frame) - .5) * Lgrain * .09 * amount;
  float oa = cut2 && bgMode == 5 ? matte : 1.;                           // transparent: the matte is the picture's alpha
  if (cmpOn == 1 && uv.x < cmpU) { g = texture(src, uv).rgb; oa = 1.; }  // before / after: the untouched source on the left
  o = vec4(clamp(g, 0., 1.), oa);
}`,
  // two pictures A (outgoing) and B (incoming), p 0..1 across the transition, the cut at p = .5
  trans: COMMON + `
uniform sampler2D A, B, M; uniform float p, seed, hasM; uniform int kind; uniform vec2 res, mc; uniform vec3 col;
vec3 a(vec2 q){ return texture(A, clamp(q, 0., 1.)).rgb; }
vec3 b(vec2 q){ return texture(B, clamp(q, 0., 1.)).rgb; }
float ease(float x){ return x < .5 ? 4. * x * x * x : 1. - pow(-2. * x + 2., 3.) / 2.; }
vec3 side(vec2 q, bool useB){ return useB ? b(q) : a(q); }
void main(){
  vec2 q = uv; vec3 c; float e = ease(p); float peak = 1. - abs(2. * p - 1.);
  if (kind == 0) {                     // whip: both pictures fly sideways with a motion blur that peaks on the cut
    float off = e; vec3 acc = vec3(0.); float bl = .12 * peak;
    for (int i = 0; i < 16; i++) { float t = (float(i) / 15. - .5) * bl; float x = q.x + off + t;
      acc += x < 1. ? a(vec2(x, q.y)) : b(vec2(x - 1., q.y)); }
    c = acc / 16.;
  } else if (kind == 1) {              // zoom-through: punch into A, come out of B, radial blur on the way
    bool useB = p >= .5; float z = useB ? mix(3., 1., ease((p - .5) * 2.)) : mix(1., 3., ease(p * 2.));
    vec3 acc = vec3(0.);
    for (int i = 0; i < 12; i++) { float s = 1. / (z * (1. + float(i) * .018 * peak * 4.)); acc += side(.5 + (q - .5) * s, useB); }
    c = acc / 12.;
  } else if (kind == 2) {              // flash to white (or a colour) on the cut
    c = mix(p < .5 ? a(q) : b(q), col, pow(peak, 1.4));
  } else if (kind == 3) {              // glitch: RGB split, displaced blocks, flicker between the two
    float t = floor(p * 24.); float blk = floor(q.y * 18. + hash(vec2(t, 1.)) * 4.);
    float r = hash(vec2(blk, t + seed)); float dx = (r - .5) * .18 * peak * step(.45, r);
    bool useB = p > .5 ? hash(vec2(t, 7.)) > .15 * peak : hash(vec2(t, 9.)) < .25 * peak;
    vec2 qq = vec2(q.x + dx, q.y); float sp = .015 * peak;
    c = vec3(side(qq + vec2(sp, 0.), useB).r, side(qq, useB).g, side(qq - vec2(sp, 0.), useB).b);
    c *= 1. - .25 * peak * step(.5, fract(q.y * res.y * .25));
  } else if (kind == 4) {              // slide (push): B pushes A out to the left
    float x = q.x + e; vec3 acc = vec3(0.); float bl = .04 * peak;
    for (int i = 0; i < 8; i++) { float xx = x + (float(i) / 7. - .5) * bl; acc += xx < 1. ? a(vec2(xx, q.y)) : b(vec2(xx - 1., q.y)); }
    c = acc / 8.;
  } else if (kind == 5) {              // spin: A spins away and B spins in, with a rotational blur
    bool useB = p >= .5; float ang = useB ? (1. - ease((p - .5) * 2.)) * -3.14159 : ease(p * 2.) * 3.14159;
    float sc = 1. + peak * .6; vec3 acc = vec3(0.);
    for (int i = 0; i < 12; i++) { float aa = ang + (float(i) / 11. - .5) * .5 * peak; vec2 d = (q - .5) * vec2(res.x / res.y, 1.) * sc;
      vec2 r = vec2(d.x * cos(aa) - d.y * sin(aa), d.x * sin(aa) + d.y * cos(aa)) / vec2(res.x / res.y, 1.) + .5;
      acc += side(fract(r), useB); }
    c = acc / 12.;
  } else if (kind == 6) {              // luma fade: B appears through A's darkest areas first
    float l = luma(a(q));
    c = mix(a(q), b(q), smoothstep(l - .12, l + .12, p * 1.24 - .12));
  } else if (kind == 7) {              // film burn: a hot orange burn sweeps over the cut
    float n = hash(floor(q * 40.) + floor(p * 12.)) * .25 + .75 * smoothstep(0., 1., 1. - length(q - vec2(.2 + p * .6, .4)) * 1.4);
    vec3 burn = vec3(1., .55, .15) * n * 2.2 + vec3(1., .9, .6) * pow(n, 6.) * 2.;
    vec3 base = mix(a(q), b(q), smoothstep(.4, .6, p));
    c = 1. - (1. - base) * (1. - clamp(burn * pow(peak, 1.3), 0., 1.));
  } else if (kind == 8) {              // object cover: the subject grows to cover the lens, then reveals B
    float s = p < .5 ? 1. + 7. * pow(p * 2., 2.) : 1. + 7. * pow((1. - p) * 2., 2.);
    vec2 mq = mc + (q - mc) / s;
    float m = hasM > .5 ? texture(M, clamp(mq, 0., 1.)).r : 1. - smoothstep(.1, .5, length((mq - .5) * vec2(1., res.y / res.x)));
    float cov = smoothstep(.35, .65, m) * step(.02, peak);
    vec3 dark = mix(a(mq), b(mq), step(.5, p)) * .35;
    c = mix(p < .5 ? a(q) : b(q), dark, cov);
  } else if (kind == 10) {             // lens blur: A goes out of focus, B comes into focus (bokeh-weighted disc)
    float r = .028 * sin(3.14159 * p); vec2 asp = vec2(res.y / res.x, 1.); float mx = smoothstep(.3, .7, p);
    vec3 sa = mix(a(q), b(q), mx); float n = 1.;
    for (int i = 0; i < 36; i++) { float an = float(i) * 2.39996; float rr = r * sqrt((float(i) + .5) / 36.);
      vec2 d = vec2(cos(an), sin(an)) * rr * asp; vec3 s2 = mix(a(q + d), b(q + d), mx); float w = 1. + luma(s2) * 2.;
      sa += s2 * w; n += w; }
    c = sa / n;
  } else if (kind == 11) {             // dip to white: A fades to white, B (or the logo) fades up from it
    float w = smoothstep(0., .45, p) * (1. - smoothstep(.55, 1., p));
    c = mix(p < .5 ? a(q) : b(q), col, w);
  } else {                             // dissolve
    c = mix(a(q), b(q), e);
  }
  o = vec4(c, 1.);
}`,
};

export const TRANSITION_KINDS = ['whip', 'zoom', 'flash', 'glitch', 'slide', 'spin', 'luma', 'burn', 'cover', 'dissolve', 'lens', 'white'];

class GL {
  constructor() {
    this.canvas = document.createElement('canvas');
    this.canvas.width = 16; this.canvas.height = 16;
    const gl = this.canvas.getContext('webgl2', { premultipliedAlpha: false, preserveDrawingBuffer: true, antialias: false, alpha: true, depth: false, stencil: false });
    if (!gl) throw new Error('WebGL2 is not available in this browser');
    this.gl = gl;
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1);
    this.progs = {}; this.texs = new Map(); this.fbos = new Map();
    const vb = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, vb);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([0, 0, 1, 0, 0, 1, 1, 1]), gl.STATIC_DRAW);
    this.vao = gl.createVertexArray(); gl.bindVertexArray(this.vao);
    gl.enableVertexAttribArray(0); gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
    this.lost = false;
    this.canvas.addEventListener('webglcontextlost', (e) => { e.preventDefault(); this.lost = true; });
  }
  prog(name) {
    if (this.progs[name]) return this.progs[name];
    const gl = this.gl, sh = (t, s) => { const x = gl.createShader(t); gl.shaderSource(x, s); gl.compileShader(x); if (!gl.getShaderParameter(x, gl.COMPILE_STATUS)) throw new Error(name + ': ' + gl.getShaderInfoLog(x)); return x; };
    const p = gl.createProgram();
    gl.attachShader(p, sh(gl.VERTEX_SHADER, VS)); gl.attachShader(p, sh(gl.FRAGMENT_SHADER, FS[name]));
    gl.bindAttribLocation(p, 0, 'p'); gl.linkProgram(p);
    if (!gl.getProgramParameter(p, gl.LINK_STATUS)) throw new Error(name + ': ' + gl.getProgramInfoLog(p));
    const u = {}, n = gl.getProgramParameter(p, gl.ACTIVE_UNIFORMS);
    for (let i = 0; i < n; i++) { const a = gl.getActiveUniform(p, i); u[a.name] = { loc: gl.getUniformLocation(p, a.name), type: a.type }; }
    return (this.progs[name] = { p, u });
  }
  tex(key, filter = 'linear') {
    let t = this.texs.get(key);
    if (!t) {
      const gl = this.gl; t = { t: gl.createTexture(), w: 0, h: 0, src: null };
      gl.bindTexture(gl.TEXTURE_2D, t.t);
      const f = filter === 'nearest' ? gl.NEAREST : gl.LINEAR;
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, f); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, f);
      gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
      this.texs.set(key, t);
    }
    return t;
  }
  /** upload a picture (video element, VideoFrame, ImageBitmap, canvas, image) */
  upload(key, source, w, h, mips = false) {
    const gl = this.gl, t = this.tex(key);
    gl.bindTexture(gl.TEXTURE_2D, t.t);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
    // strong downscales (the preview monitor) read from mipmaps, so detail averages instead of aliasing
    if (mips) gl.generateMipmap(gl.TEXTURE_2D);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, mips ? gl.LINEAR_MIPMAP_LINEAR : gl.LINEAR);
    t.w = w; t.h = h; return t;
  }
  /** upload one byte per pixel (masks) */
  uploadR8(key, data, w, h) {
    const gl = this.gl, t = this.tex(key);
    gl.bindTexture(gl.TEXTURE_2D, t.t);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.R8, w, h, 0, gl.RED, gl.UNSIGNED_BYTE, data);
    t.w = w; t.h = h; return t;
  }
  target(key, w, h) {
    const gl = this.gl; w = Math.max(1, Math.round(w)); h = Math.max(1, Math.round(h));
    let f = this.fbos.get(key);
    if (!f || f.w !== w || f.h !== h) {
      if (!f) f = { fb: gl.createFramebuffer(), tex: this.tex('fbo:' + key) };
      gl.bindTexture(gl.TEXTURE_2D, f.tex.t);
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA8, w, h, 0, gl.RGBA, gl.UNSIGNED_BYTE, null);
      gl.bindFramebuffer(gl.FRAMEBUFFER, f.fb);
      gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, f.tex.t, 0);
      f.w = w; f.h = h; f.tex.w = w; f.tex.h = h;
      this.fbos.set(key, f);
    }
    return f;
  }
  /** run program `name` into target (null = the canvas, flipped right way up) */
  run(name, target, uniforms = {}, textures = {}) {
    const gl = this.gl, P = this.prog(name);
    gl.useProgram(P.p);
    if (target) { gl.bindFramebuffer(gl.FRAMEBUFFER, target.fb); gl.viewport(0, 0, target.w, target.h); }
    else { gl.bindFramebuffer(gl.FRAMEBUFFER, null); gl.viewport(0, 0, this.canvas.width, this.canvas.height); }
    gl.uniform1f(P.u.flip.loc, target ? 0 : 1);
    let unit = 0;
    for (const [k, t] of Object.entries(textures)) {
      if (!P.u[k]) continue;
      gl.activeTexture(gl.TEXTURE0 + unit); gl.bindTexture(gl.TEXTURE_2D, t.t); gl.uniform1i(P.u[k].loc, unit); unit++;
    }
    for (const [k, v] of Object.entries(uniforms)) {
      const u = P.u[k]; if (!u) continue;
      if (u.type === gl.INT || u.type === gl.BOOL) gl.uniform1i(u.loc, v);
      else if (typeof v === 'number') gl.uniform1f(u.loc, v);
      else if (v.length === 2) gl.uniform2f(u.loc, v[0], v[1]);
      else if (v.length === 3) gl.uniform3f(u.loc, v[0], v[1], v[2]);
    }
    gl.bindVertexArray(this.vao);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }
  size(w, h) { if (this.canvas.width !== w || this.canvas.height !== h) { this.canvas.width = w; this.canvas.height = h; } }
}

let G = null, failed = null;
/** the shared GL engine, or null when WebGL2 is missing (effects then simply do not draw) */
export function gl() {
  if (G && !G.lost) return G;
  if (failed) return null;
  try { G = new GL(); return G; } catch (e) { failed = e; console.warn('[look] WebGL2 unavailable:', e.message); return null; }
}

/** blur pyramid of a texture: small (1/4) and large (1/16) gaussian blurs */
export function blurs(g, srcTex, w, h, { large = false } = {}) {
  const w4 = Math.max(2, w / 4), h4 = Math.max(2, h / 4);
  const d1 = g.target('d1', w / 2, h / 2); g.run('copy', d1, { px: [1 / w, 1 / h] }, { s: srcTex });
  const d2 = g.target('d2', w4, h4); g.run('copy', d2, { px: [2 / w, 2 / h] }, { s: d1.tex });
  const b1 = g.target('b1', w4, h4); g.run('blur', b1, { dir: [1 / d2.w, 0] }, { s: d2.tex });
  const bs = g.target('bs', w4, h4); g.run('blur', bs, { dir: [0, 1 / b1.h] }, { s: b1.tex });
  if (!large) return { small: bs.tex, large: bs.tex };
  // the large blur lives on a FIXED grid (96 px on the long side), halving down to it, so the preview monitor and
  // the full-size export blur the background identically
  let cur = bs, i = 0;
  const L = 96 / Math.max(w, h), tw = Math.max(2, Math.round(w * L)), th = Math.max(2, Math.round(h * L));
  while (cur.w > tw * 2 + 1) { const nx = g.target('hv' + (i++ % 2), cur.w / 2, cur.h / 2); g.run('copy', nx, { px: [1 / cur.w, 1 / cur.h] }, { s: cur.tex }); cur = nx; }
  const d3 = g.target('d3', tw, th); g.run('copy', d3, { px: [1 / cur.w, 1 / cur.h] }, { s: cur.tex });
  const l1 = g.target('l1', d3.w, d3.h), l2 = g.target('l2', d3.w, d3.h);
  g.run('blur', l1, { dir: [1 / d3.w, 0] }, { s: d3.tex }); g.run('blur', l2, { dir: [0, 1 / d3.h] }, { s: l1.tex });
  g.run('blur', l1, { dir: [2 / d3.w, 0] }, { s: l2.tex }); g.run('blur', l2, { dir: [0, 2 / d3.h] }, { s: l1.tex });
  return { small: bs.tex, large: l2.tex };
}
