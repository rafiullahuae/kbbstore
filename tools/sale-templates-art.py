"""The Super Sale banner's occasion-template art, as CSS data-URI constants.  (Lane SOT)

Prints the PHP constants App\\Support\\SaleTemplates carries (FIREWORKS,
CONFETTI, CITY_A/B/C, LAYERS). The drawings are the approved ones of
docs/plans/sale-occasions/themes_extra.py and themes_uae2.py, re-written with
whole-number coordinates and shared paths: the same pictures, fewer bytes.
Run:  python3 -I tools/sale-templates-art.py
"""
import math
import urllib.parse


def uri(svg):
    svg = svg.replace('"', "'")
    return 'url("data:image/svg+xml,' + urllib.parse.quote(svg, safe=" =:/,;'") + '")'


def r(v):
    return str(int(round(v)))


# ---------------------------------------------------------------- New Year
def burst(cx, cy, rad, color, n=14):
    rays, dots = '', ''
    for i in range(n):
        a = 2 * math.pi * i / n
        x1, y1 = cx + math.cos(a) * rad * .35, cy + math.sin(a) * rad * .35
        x2, y2 = cx + math.cos(a) * rad, cy + math.sin(a) * rad
        rays += 'M' + r(x1) + ' ' + r(y1) + 'L' + r(x2) + ' ' + r(y2)
        dots += 'M' + r(x2) + ' ' + r(y2) + 'h0'
    return '<path stroke="%s" stroke-width="2.4" d="%s"/><path stroke="%s" stroke-width="5.2" d="%s"/>' % (color, rays, color, dots)


FIREWORKS = uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 300"><g fill="none" stroke-linecap="round">'
                + burst(120, 80, 60, '#F5C969') + burst(330, 60, 44, '#ff8fc4') + burst(470, 150, 70, '#F5C969', 18) + burst(240, 210, 36, '#9fd8ff')
                + '</g></svg>')

CONFETTI = uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200">'
               '<rect x="20" y="30" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(30 25 32)"/>'
               '<rect x="110" y="70" width="10" height="4" rx="2" fill="#ec4f86" transform="rotate(-25 115 72)"/>'
               '<rect x="200" y="20" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(60 205 22)"/>'
               '<rect x="260" y="120" width="10" height="4" rx="2" fill="#f7a6c4" transform="rotate(15 265 122)"/>'
               '<rect x="60" y="150" width="10" height="4" rx="2" fill="#E2B04A" transform="rotate(-50 65 152)"/>'
               '<circle cx="160" cy="140" r="3" fill="#E2B04A"/><circle cx="40" cy="95" r="2.5" fill="#ec4f86"/><circle cx="235" cy="75" r="2.5" fill="#E2B04A"/>'
               '</svg>')


# ------------------------------------------------------------ National Day
def skyline(back, front, hole):
    b = ''
    for x, h, w in [(20, 70, 26), (50, 110, 22), (95, 85, 30), (140, 130, 20), (175, 95, 28), (300, 120, 24), (330, 90, 30), (420, 140, 22),
                    (455, 100, 26), (560, 125, 24), (595, 95, 30), (700, 150, 22), (735, 110, 26), (850, 130, 24), (880, 100, 30), (940, 120, 26), (975, 80, 25)]:
        b += 'M%d %dh%dv%dh-%dz' % (x, 240 - h, w, h, w)
    f = ''
    f += 'M30 200h150v40H30z'                                           # Sheikh Zayed Grand Mosque: hall,
    f += 'M70 200a35 35 0 0 1 70 0zM103 166v-10l2-4 2 4v10z'            # the great dome,
    f += 'M44 205a14 14 0 0 1 28 0zM138 205a14 14 0 0 1 28 0z'          # two small domes,
    for mx in (34, 172):                                                # and the minarets
        f += 'M%d 140h7v100h-7zM%d 140l3.5-12 3.5 12z' % (mx, mx)
    f += 'M205 240V110h60v130h-12V122h-36v118z'                         # Dubai Frame
    f += 'M290 240V95l16-25v170zM312 240V115l14-21v146z'                # Emirates Towers
    f += 'M470 240v-40h8v-40h6v-40h5V82h4V48h3V10l2-10 2 10v38h3v34h4v38h5v40h6v40h8v40z'  # Burj Khalifa
    f += 'M370 240q35-18 70 0zM371 190a34 30 0 1 0 68 0a34 30 0 1 0-68 0z'   # Museum of the Future
    f += 'M760 240V70l6-10q76 60 70 180zM756 50h4v190h-4z'              # Burj Al Arab
    f += 'M880 240V120q12-20 24-8v128zM910 240V140q10-16 20-6v106z'     # curved towers
    f += 'M612 240l28-90 28 90h-8l-20-70-20 70z'                        # Ain Dubai's legs
    f += 'M560 70h3v170h-3z'                                            # the flag's pole
    spokes = ''
    for i in range(12):
        a = 2 * math.pi * i / 12
        spokes += 'M640 150L' + r(640 + 52 * math.cos(a)) + ' ' + r(150 + 52 * math.sin(a))

    def band(y0, y1):
        f1 = lambda v: ('%.1f' % v).rstrip('0').rstrip('.')
        top = ''.join('L%s %s' % (r(578 + i * 3.5), f1(y0 + 3 * math.sin(i / 15 * 2 * math.pi))) for i in range(16))
        bot = ''.join('L%s %s' % (r(578 + i * 3.5), f1(y1 + 3 * math.sin(i / 15 * 2 * math.pi))) for i in reversed(range(16)))
        return 'M578 %s%s%sZ' % (f1(y0), top, bot)
    flag = ('<path fill="#E31B23" d="M563 72h15v40h-15z"/>'
            + '<path fill="#00732F" d="%s"/><path fill="#fff" stroke="#0002" stroke-width=".4" d="%s"/><path fill="#000" d="%s"/>'
            % (band(72, 85.3), band(85.3, 98.6), band(98.6, 112)))
    return ('<path fill="%s" d="%s"/><path fill="%s" d="%s"/>' % (back, b, front, f)
            + '<ellipse cx="408" cy="188" rx="17" ry="14" fill="%s"/>' % hole
            + '<g fill="none" stroke="%s"><circle cx="640" cy="150" r="52" stroke-width="3"/><path stroke-width="1.4" d="%s"/></g>' % (front, spokes)
            + flag)


def sky(inner):
    return uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 240" preserveAspectRatio="xMidYMax meet">' + inner + '</svg>')


CITY_A = sky(skyline('#c9d6dc', '#5d6f78', '#eef5f7'))
CITY_B = sky(skyline('#e9a77c', '#3a2633', '#f6c49a'))
CITY_C = sky(skyline('rgba(255,255,255,.35)', '#fff', '#00732F'))


def wave(y, amp, ph, k=2.2):
    pts = []
    for i in range(0, 51):
        x = i * 20
        pts.append('%d %s' % (x, r(y + 46 * (1 - i / 50) ** 1.6 + amp * math.sin(i / 50 * 2 * math.pi * k + ph))))
    return 'M' + 'L'.join(pts) + 'L1000 120H0z'


layers = ''
for y, amp, ph, col in ((10, 7, 0.0, '#00732F'), (40, 6, 1.3, '#fff'), (72, 5, 2.6, '#000')):
    d = wave(y, amp, ph)
    layers += '<path fill="#0000002e" transform="translate(0 4)" d="%s"/><path fill="%s" d="%s"/>' % (d, col, d)
LAYERS = uri('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 120" preserveAspectRatio="none">' + layers + '</svg>')

if __name__ == '__main__':
    for name in ('FIREWORKS', 'CONFETTI', 'CITY_A', 'CITY_B', 'CITY_C', 'LAYERS'):
        v = globals()[name]
        assert '\\' not in v and '"' not in v[5:-2]
        print("    public const %s = '%s';\n" % (name, v.replace("'", "\\'")))
