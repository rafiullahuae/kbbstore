# KBB Studio · placeholder worker figures -- drawn for KBB Studio (original work, free to use).
#
# Real worker photos cannot be bundled (licences), so every Work Abroad design ships with a clean, faceless flat
# illustration of a worker in the right uniform, half-body, 400 x 500 (4:5), standing on the bottom edge. A figure is
# composed from parts: the top (shirt, coverall, hi-vis vest, chef jacket, scrubs, blazer, ...), the headwear (hard hat,
# cap, peaked cap, toque, scrub cap, straw hat, ear defenders, hair net, none), face gear (safety glasses), chest
# items (stethoscope, lanyard, badge, radio, tape measure, tool belt) and the arms (crossed, steering wheel, a book,
# a clipboard, a tablet, a towel). Colours come from the occupation: u = uniform, t = trim / second, p = PPE / hat.
#
#   figure(fam_defaults | occupation overrides, colours) -> SVG text (no script, no external reference)
import re

SKIN, SKIN_D, HAIR = '#C58C63', '#A9714D', '#2A2320'
INK = '#22262B'


def shade(h, k):
    """k < 0 darker, k > 0 lighter"""
    n = int(h[1:7], 16)
    c = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
    c = [round(x + (255 - x) * k) if k > 0 else round(x * (1 + k)) for x in c]
    return '#%02X%02X%02X' % tuple(max(0, min(255, x)) for x in c)


def lum(h):
    n = int(h[1:7], 16)
    return (((n >> 16) & 255) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 255000


def mirror(d):
    """mirror an absolute-coordinate path (M/L/C/Q/Z, x y pairs) around x = 200"""
    out, toks = [], re.findall(r'[MLCQZ]|-?\d+(?:\.\d+)?', d)
    i, xy = 0, 0
    for t in toks:
        if t.isalpha():
            out.append(t); xy = 0
        else:
            v = float(t)
            out.append('%g' % (400 - v if xy % 2 == 0 else v)); xy += 1
    return ' '.join(out)


BODY = 'M52 500 C52 360 72 270 140 240 Q170 228 200 228 Q230 228 260 240 C328 270 348 360 348 500 Z'
BODY_SH = 'M262 241 C328 270 348 360 348 500 L296 500 C304 400 294 300 262 241 Z'


def top(kind, u, t, p):
    ud, ul = shade(u, -.22), shade(u, .18)
    o = ['<path d="%s" fill="%s"/>' % (BODY, u), '<path d="%s" fill="%s" opacity=".55"/>' % (BODY_SH, ud)]
    collar = lambda c: ('<path d="M168 230 L200 266 L180 292 L148 242 Z" fill="%s"/><path d="%s" fill="%s"/>' % (c, mirror('M168 230 L200 266 L180 292 L148 242 Z'), c))
    if kind in ('shirt', 'security', 'apron', 'bib'):
        o.append('<path d="M200 262 V500" stroke="%s" stroke-width="3"/>' % ud)
        o += ['<circle cx="200" cy="%d" r="4.5" fill="%s"/>' % (y, ud) for y in (300, 345, 390)]
        o.append(collar(ul))
        if kind == 'shirt':
            o.append('<path d="M228 318 h44 v40 q-22 8 -44 0 z" fill="none" stroke="%s" stroke-width="3"/>' % ud)
        if kind == 'security':
            for x in (128, 228):
                o.append('<path d="M%d 312 h44 v44 q-22 8 -44 0 z" fill="%s"/><path d="M%d 312 h44 v14 h-44 z" fill="%s"/>' % (x, ud, x - 2, shade(u, -.35)))
            for side in ('M112 250 L158 236 L160 252 L116 268 Z',):
                o.append('<path d="%s" fill="%s"/><path d="%s" fill="%s"/>' % (side, t, mirror(side), t))
            o.append('<path d="M192 262 h16 l5 14 -6 70 -7 10 -7 -10 -6 -70 z" fill="%s"/>' % shade(t, -.25))
        if kind == 'apron':
            o.append('<path d="M150 236 L170 300 M250 236 L230 300" stroke="%s" stroke-width="7"/>' % t)
            o.append('<path d="M146 300 h108 l14 200 h-136 z" fill="%s"/><path d="M180 352 h40 v34 h-40z" fill="none" stroke="%s" stroke-width="3"/>' % (t, shade(t, -.2)))
        if kind == 'bib':
            o.append('<path d="M138 246 L160 318 M262 246 L240 318" stroke="%s" stroke-width="16" stroke-linecap="round"/>' % t)
            o.append('<path d="M150 312 h100 l8 188 h-116 z" fill="%s"/><circle cx="160" cy="324" r="6" fill="#D9D3C7"/><circle cx="240" cy="324" r="6" fill="#D9D3C7"/>' % t)
            o.append('<path d="M176 350 h48 v40 h-48z" fill="none" stroke="%s" stroke-width="3"/>' % shade(t, -.25))
    elif kind == 'coverall':
        o.append(collar(ud))
        o.append('<path d="M200 258 V500" stroke="#B8C0C8" stroke-width="5"/>')
        o.append('<path d="M124 312 h46 v42 q-23 8 -46 0 z" fill="%s"/><path d="M132 300 v24" stroke="%s" stroke-width="5" stroke-linecap="round"/>' % (ud, t))
        o.append('<path d="M228 312 h46 v6 h-46z" fill="%s"/>' % t)
    elif kind == 'vest':
        o.append(collar(ul))
        o.append('<path d="M200 262 V500" stroke="%s" stroke-width="3"/>' % ud)
        lv = 'M126 246 Q150 236 168 238 L186 332 L186 500 L66 500 C68 380 86 292 126 246 Z'
        for d in (lv, mirror(lv)):
            o.append('<path d="%s" fill="%s"/>' % (d, t))
        st = '#EEF2F4'
        for y in (392, 440):
            o.append('<path d="M70 %d h116 v16 h-116z M214 %d h116 v16 h-116z" fill="%s"/>' % (y, y, st))
        o.append('<path d="M%s" fill="%s" opacity=".35"/>' % ('300 500 C306 400 296 300 262 241 L272 240 C330 276 346 360 346 500 Z', shade(t, -.3)))
    elif kind == 'chef':
        o.append('<path d="M228 262 V500" stroke="%s" stroke-width="3"/>' % shade(u, -.12))
        for x in (176, 248):
            o += ['<circle cx="%d" cy="%d" r="6" fill="%s"/>' % (x, y, t) for y in (300, 350, 400, 450)]
        o.append('<path d="M160 232 Q200 250 240 232 L244 254 Q200 274 156 254 Z" fill="%s"/>' % shade(u, -.1))
        o.append('<path d="M184 254 L216 254 L200 284 Z" fill="%s"/>' % t)
    elif kind == 'scrubs':
        o.append('<path d="M164 230 L200 306 L236 230 Q200 240 164 230 Z" fill="%s"/>' % SKIN_D)
        o.append('<path d="M164 230 L200 306 L236 230" fill="none" stroke="%s" stroke-width="9" stroke-linejoin="round"/>' % t)
        o.append('<path d="M124 322 h48 v42 q-24 8 -48 0 z" fill="none" stroke="%s" stroke-width="3"/><path d="M134 310 v24" stroke="%s" stroke-width="5" stroke-linecap="round"/>' % (ud, INK))
    elif kind == 'blazer':
        o.append('<path d="M162 232 L200 340 L238 232 Q200 244 162 232 Z" fill="#F3F5F7"/>')
        o.append('<path d="M192 250 h16 l5 14 -6 74 -7 10 -7 -10 -6 -74 z" fill="%s"/>' % t)
        lp = 'M160 232 L200 342 L176 300 L150 296 L170 262 L150 240 Z'
        for d in (lp, mirror(lp)):
            o.append('<path d="%s" fill="%s"/>' % (d, ud))
        o.append('<path d="M236 330 h40 v6 h-40z" fill="%s"/>' % ud)
    elif kind == 'cardigan':
        o.append('<path d="M164 232 L200 330 L236 232 Q200 244 164 232 Z" fill="%s"/>' % t)
        o.append('<path d="M180 236 L200 262 L220 236" fill="none" stroke="%s" stroke-width="5"/>' % shade(t, .5))
        o.append('<path d="M164 232 L200 330 V500 M236 232 L200 330" fill="none" stroke="%s" stroke-width="5"/>' % ud)
        o += ['<circle cx="190" cy="%d" r="5" fill="%s"/>' % (y, ud) for y in (360, 410, 460)]
    elif kind == 'polo':
        pc = 'M168 230 L196 252 L188 270 L150 244 Z'
        for d in (pc, mirror(pc)):
            o.append('<path d="%s" fill="%s"/>' % (d, ud))
        o.append('<path d="M200 248 V296" stroke="%s" stroke-width="3"/><circle cx="200" cy="266" r="4" fill="%s"/><circle cx="200" cy="286" r="4" fill="%s"/>' % (ud, ud, ud))
        o.append('<rect x="232" y="304" width="38" height="24" rx="4" fill="%s"/>' % t)
    return o


def head(hat, hair, p, glasses):
    pd = shade(p, -.22)
    o = ['<path d="M172 172 h56 v56 q-28 18 -56 0 z" fill="%s"/>' % SKIN_D,
         '<circle cx="151" cy="128" r="12" fill="%s"/><circle cx="249" cy="128" r="12" fill="%s"/>' % (SKIN_D, SKIN_D),
         '<ellipse cx="200" cy="122" rx="50" ry="58" fill="%s"/>' % SKIN,
         '<path d="M222 70 C252 86 256 150 230 176 C246 150 248 104 222 70 Z" fill="%s" opacity=".55"/>' % SKIN_D]
    short = '<path d="M149 122 C142 74 172 56 202 56 C236 56 258 78 251 122 C246 98 232 86 214 88 C192 92 168 94 151 122 Z" fill="%s"/>' % HAIR
    if hat in ('none', 'earmuffs', 'hairnet') or hat == '':
        if hair == 'bun':
            o.append('<circle cx="200" cy="52" r="22" fill="%s"/>' % HAIR)
        if hair == 'long':
            o.append('<path d="M146 120 C140 180 150 214 168 226 L170 150 Z M254 120 C260 180 250 214 232 226 L230 150 Z" fill="%s"/>' % HAIR)
        o.append(short)
    if hat == 'hardhat':
        o.append('<path d="M140 112 C140 56 170 36 200 36 C230 36 260 56 260 112 Z" fill="%s"/>' % p)
        o.append('<path d="M194 38 h12 v74 h-12z" fill="%s"/>' % pd)
        o.append('<path d="M124 106 h152 q8 0 8 8 v6 h-168 v-6 q0 -8 8 -8 z" fill="%s"/>' % pd)
        o.append('<path d="M150 70 C160 52 176 44 190 42" fill="none" stroke="#FFFFFF" stroke-width="6" stroke-linecap="round" opacity=".35"/>')
    elif hat == 'cap':
        o.append('<path d="M146 110 C146 62 172 48 200 48 C228 48 254 62 254 110 Z" fill="%s"/>' % p)
        o.append('<path d="M138 104 Q200 92 268 106 L278 120 Q200 108 130 118 Z" fill="%s"/><circle cx="200" cy="50" r="5" fill="%s"/>' % (pd, pd))
    elif hat == 'peaked':
        o.append('<path d="M144 104 L134 70 Q200 34 266 70 L256 104 Z" fill="%s"/>' % p)
        o.append('<path d="M144 96 h112 v16 h-112z" fill="%s"/>' % INK)
        o.append('<path d="M142 110 Q200 124 258 110 L264 120 Q200 140 136 120 Z" fill="#15181C"/>')
        o.append('<circle cx="200" cy="80" r="11" fill="#D4A62A"/><circle cx="200" cy="80" r="5" fill="#F1D27A"/>')
    elif hat == 'toque':
        o.append('<path d="M150 94 C124 70 138 28 170 34 C178 6 222 6 230 34 C262 28 276 70 250 94 Z" fill="%s"/>' % shade(p, .2))
        o.append('<path d="M178 40 C182 60 182 76 178 92 M222 40 C218 60 218 76 222 92" fill="none" stroke="%s" stroke-width="3"/>' % pd)
        o.append('<path d="M150 90 h100 v28 h-100z" fill="%s"/>' % p)
    elif hat in ('skullcap', 'scrubcap'):
        o.append('<path d="M147 106 C147 58 176 48 200 48 C224 48 253 58 253 106 Q200 92 147 106 Z" fill="%s"/>' % p)
        if hat == 'scrubcap':
            o.append('<path d="M150 100 Q200 88 250 100" fill="none" stroke="%s" stroke-width="5"/>' % pd)
    elif hat == 'wide':
        o.append('<path d="M152 100 C152 56 172 46 200 46 C228 46 248 56 248 100 Z" fill="%s"/>' % p)
        o.append('<path d="M152 84 h96 v14 h-96z" fill="%s"/>' % pd)
        o.append('<ellipse cx="200" cy="102" rx="106" ry="17" fill="%s"/>' % p)
        o.append('<ellipse cx="200" cy="98" rx="60" ry="6" fill="%s" opacity=".5"/>' % pd)
    elif hat == 'earmuffs':
        o.append('<path d="M150 124 C146 52 254 52 250 124" fill="none" stroke="%s" stroke-width="10"/>' % pd)
        o.append('<ellipse cx="146" cy="130" rx="17" ry="24" fill="%s"/><ellipse cx="254" cy="130" rx="17" ry="24" fill="%s"/>' % (p, p))
    elif hat == 'hairnet':
        o.append('<path d="M144 116 C140 62 172 46 200 46 C228 46 260 62 256 116 Q200 98 144 116 Z" fill="%s" opacity=".7"/>' % p)
        o.append('<path d="M160 64 L240 112 M180 52 L254 98 M240 64 L160 112 M220 52 L146 98" stroke="#FFFFFF" stroke-width="1.6" opacity=".6"/>')
    if glasses:
        o.append('<rect x="154" y="106" width="92" height="24" rx="11" fill="#7FB3D5" opacity=".6" stroke="%s" stroke-width="4"/>' % INK)
    return o


def chest(items, u, t, p):
    o = []
    for it in items:
        if it == 'stethoscope':
            o.append('<path d="M164 234 C146 282 156 330 196 344 M236 234 C254 282 250 324 214 348" fill="none" stroke="%s" stroke-width="7" stroke-linecap="round"/>' % INK)
            o.append('<circle cx="206" cy="352" r="13" fill="#C9D1D9" stroke="%s" stroke-width="4"/>' % INK)
        elif it == 'lanyard':
            o.append('<path d="M174 236 L196 324 M226 236 L204 324" stroke="%s" stroke-width="6"/>' % p)
            o.append('<rect x="180" y="322" width="40" height="52" rx="5" fill="#FFFFFF" stroke="%s" stroke-width="3"/><rect x="180" y="322" width="40" height="13" rx="4" fill="%s"/><circle cx="200" cy="350" r="7" fill="#C7CDD3"/>' % (shade(p, -.2), p))
        elif it == 'badge':
            o.append('<path d="M150 296 l14 6 v14 q0 12 -14 18 q-14 -6 -14 -18 v-14 z" fill="#D4A62A" stroke="#9C7614" stroke-width="2"/>')
        elif it == 'radio':
            o.append('<rect x="248" y="262" width="22" height="40" rx="4" fill="%s"/><path d="M262 262 v-18" stroke="%s" stroke-width="5" stroke-linecap="round"/>' % (INK, INK))
        elif it == 'tape':
            o.append('<path d="M168 234 C156 300 158 380 162 500 M232 234 C244 300 242 380 238 500" fill="none" stroke="#E8C547" stroke-width="13"/>')
            o.append('<path d="M156 290 h10 M158 330 h10 M158 370 h10 M234 290 h10 M234 330 h10 M234 370 h10" stroke="#5A4A12" stroke-width="2"/>')
        elif it == 'toolbelt':
            o.append('<path d="M58 462 h284 v22 h-284z" fill="#5A3E2B"/><rect x="186" y="460" width="28" height="26" rx="3" fill="none" stroke="#C9A24A" stroke-width="4"/>')
            o.append('<path d="M80 472 h56 l-4 28 h-48z M264 472 h56 l-4 28 h-48z" fill="#7A5638"/><path d="M96 474 v-26 M300 474 l10 -30" stroke="#8A8F96" stroke-width="7" stroke-linecap="round"/>')
        elif it == 'pen':
            o.append('<path d="M244 306 v26" stroke="%s" stroke-width="5" stroke-linecap="round"/>' % INK)
        elif it == 'mask':
            o.append('<path d="M156 138 Q200 128 244 138 L238 170 Q200 186 162 170 Z" fill="#EAF2F6" stroke="#B9C7D0" stroke-width="2"/><path d="M164 150 Q200 144 236 150 M166 160 Q200 154 234 160" stroke="#B9C7D0" stroke-width="2" fill="none"/>')
    return o


def arms(kind, u, p, sleeves, gloves):
    ud = shade(u, -.22)
    hand = gloves or SKIN
    sl = SKIN if sleeves == 'rolled' else u
    o = []
    if kind in ('crossed', 'book', 'clipboard', 'tablet', 'towel'):
        if kind == 'book':
            o.append('<g transform="rotate(-8 214 330)"><rect x="160" y="262" width="110" height="140" rx="6" fill="%s"/><rect x="164" y="266" width="10" height="132" fill="%s"/><path d="M186 296 h66 M186 312 h50" stroke="#FFFFFF" stroke-width="5" opacity=".8"/></g>' % (p, shade(p, -.3)))
        if kind == 'clipboard':
            o.append('<g transform="rotate(-6 214 330)"><rect x="162" y="258" width="104" height="144" rx="8" fill="#8B6A47"/><rect x="172" y="276" width="84" height="118" rx="3" fill="#FFFFFF"/><rect x="194" y="250" width="40" height="18" rx="5" fill="#9AA3AD"/><path d="M184 300 h60 M184 318 h60 M184 336 h40" stroke="%s" stroke-width="5"/></g>' % shade(p, -.1))
        if kind == 'tablet':
            o.append('<g transform="rotate(-6 214 330)"><rect x="160" y="266" width="112" height="140" rx="10" fill="%s"/><rect x="168" y="274" width="96" height="124" rx="4" fill="%s"/><path d="M180 300 h56 M180 318 h40" stroke="#FFFFFF" stroke-width="5" opacity=".75"/></g>' % (INK, p))
        # lower forearm (left elbow -> right hand), then the upper one (right elbow -> left hand)
        o.append('<g transform="rotate(-5 200 404)"><rect x="70" y="382" width="246" height="68" rx="34" fill="%s" opacity=".45"/></g>' % shade(u, -.55))
        o.append('<g transform="rotate(-5 200 404)"><rect x="76" y="372" width="236" height="64" rx="32" fill="%s"/><rect x="76" y="420" width="236" height="16" rx="8" fill="%s" opacity=".55"/></g>' % (sl, ud if sleeves != 'rolled' else SKIN_D))
        o.append('<ellipse cx="312" cy="394" rx="28" ry="24" fill="%s"/>' % hand)
        o.append('<g transform="rotate(6 200 430)"><rect x="86" y="408" width="244" height="68" rx="34" fill="%s" opacity=".45"/></g>' % shade(u, -.55))
        o.append('<g transform="rotate(6 200 430)"><rect x="92" y="398" width="232" height="64" rx="32" fill="%s"/><rect x="92" y="446" width="232" height="16" rx="8" fill="%s" opacity=".55"/></g>' % (sl, ud if sleeves != 'rolled' else SKIN_D))
        o.append('<ellipse cx="90" cy="420" rx="28" ry="24" fill="%s"/>' % hand)
        if sleeves == 'rolled':
            o.append('<path d="M60 378 h34 v66 h-34z M306 404 h34 v66 h-34z" fill="%s"/>' % u)
        if kind == 'towel':
            o.append('<path d="M232 414 h52 v74 l-26 -10 -26 10 z" fill="#FFFFFF" stroke="#D5DADF" stroke-width="2"/>')
    elif kind == 'wheel':
        for d in ('M92 330 L132 432', 'M308 330 L268 432'):
            o.append('<path d="%s" stroke="%s" stroke-width="58" stroke-linecap="round"/>' % (d, sl))
        o.append('<circle cx="200" cy="498" r="104" fill="none" stroke="%s" stroke-width="20"/>' % INK)
        o.append('<path d="M110 452 Q200 420 290 452 M200 440 V500" stroke="%s" stroke-width="16" fill="none"/>' % INK)
        o.append('<circle cx="200" cy="470" r="22" fill="#3A4048"/>')
        o.append('<ellipse cx="128" cy="440" rx="26" ry="24" fill="%s"/><ellipse cx="272" cy="440" rx="26" ry="24" fill="%s"/>' % (hand, hand))
    return o


def figure(o, u, t, p):
    """o: {top, hat, hair, glasses, chest:[...], arms, sleeves, gloves}"""
    parts = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 500">']
    parts += head(o.get('hat', 'none'), o.get('hair', 'short'), p, o.get('glasses'))[:1]          # the neck goes under the top
    parts += top(o.get('top', 'shirt'), u, t, p)
    parts += head(o.get('hat', 'none'), o.get('hair', 'short'), p, o.get('glasses'))[1:]
    parts += chest([c for c in o.get('chest', []) if c != 'toolbelt'], u, t, p)
    g = o.get('gloves')
    parts += arms(o.get('arms', 'crossed'), u, p, o.get('sleeves', 'long'), {'p': p, 't': t, 'dark': '#3B3F45', 'tan': '#C9A27A', 'blue': '#4A90C8', 'white': '#F4F6F8'}.get(g) if g else None)
    parts += chest([c for c in o.get('chest', []) if c == 'toolbelt'], u, t, p)
    parts.append('</svg>')
    return ''.join(parts)
