#!/usr/bin/env python3
"""Lane PY: convert an Android VectorDrawable (the format the providers ship
their logos in, inside their own SDKs) to a plain SVG, path data untouched.

    python3 -I tools/pay-vd2svg.py <in.xml> <out.svg> [--drop-first-fill] [--title T]

Only what the two logo files use is supported: <vector>, <group>,
<clip-path>, <path> with android:fillColor, strokeColor/strokeWidth (#RGB/#RRGGBB/#AARRGGBB),
android:fillAlpha, and aapt:attr gradients (linear + radial). Gradients are
written userSpaceOnUse, which is what VectorDrawable coordinates are.

--drop-first-fill removes the first filled <path> (the badge ground), which
is how a wordmark-only variant is derived from the badge WITHOUT redrawing a
single outline. Reads the XML with ElementTree (no entity expansion of note,
no network, no code from the input is executed).
"""
import sys
import xml.etree.ElementTree as ET

A = '{http://schemas.android.com/apk/res/android}'
AAPT = '{http://schemas.android.com/aapt}'


def colour(v):
    v = v.strip()
    if not v.startswith('#'):
        return v, None
    h = v[1:]
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    if len(h) == 8:
        a = int(h[:2], 16) / 255
        return '#' + h[2:].upper(), (None if a >= 0.999 else round(a, 3))
    return '#' + h.upper(), None


def main():
    src, out = sys.argv[1], sys.argv[2]
    drop_first = '--drop-first-fill' in sys.argv
    title = sys.argv[sys.argv.index('--title') + 1] if '--title' in sys.argv else None
    root = ET.parse(src).getroot()
    vw, vh = root.get(A + 'viewportWidth'), root.get(A + 'viewportHeight')
    defs, body = [], []
    gid = [0]
    dropped = [False]

    def gradient(g):
        gid[0] += 1
        i = 'g%d' % gid[0]
        stops = []
        for it in g.findall('item'):
            c, a = colour(it.get(A + 'color'))
            op = '' if a is None else ' stop-opacity="%s"' % a
            stops.append('<stop offset="%s" stop-color="%s"%s/>' % (it.get(A + 'offset'), c, op))
        if g.get(A + 'type') == 'radial':
            defs.append('<radialGradient id="%s" gradientUnits="userSpaceOnUse" cx="%s" cy="%s" r="%s">%s</radialGradient>' % (
                i, g.get(A + 'centerX'), g.get(A + 'centerY'), g.get(A + 'gradientRadius'), ''.join(stops)))
        else:
            defs.append('<linearGradient id="%s" gradientUnits="userSpaceOnUse" x1="%s" y1="%s" x2="%s" y2="%s">%s</linearGradient>' % (
                i, g.get(A + 'startX'), g.get(A + 'startY'), g.get(A + 'endX'), g.get(A + 'endY'), ''.join(stops)))
        return 'url(#%s)' % i

    def walk(el, out_list):
        for ch in el:
            if ch.tag == 'group':
                inner = []
                clip = ch.find('clip-path')
                attr = ''
                if clip is not None:
                    gid[0] += 1
                    cid = 'c%d' % gid[0]
                    defs.append('<clipPath id="%s"><path d="%s"/></clipPath>' % (cid, clip.get(A + 'pathData')))
                    attr = ' clip-path="url(#%s)"' % cid
                walk(ch, inner)
                out_list.append('<g%s>%s</g>' % (attr, ''.join(inner)))
            elif ch.tag == 'path':
                if drop_first and not dropped[0]:
                    dropped[0] = True
                    continue
                fill = ch.get(A + 'fillColor')
                op = None
                if fill is None:
                    for at in ch.findall(AAPT + 'attr'):
                        if at.get('name') == 'android:fillColor':
                            fill = gradient(at.find('gradient'))
                else:
                    fill, op = colour(fill)
                fa = ch.get(A + 'fillAlpha')
                if fa is not None:
                    op = round(float(fa) * (op if op is not None else 1), 3)
                rule = ch.get(A + 'fillType')
                stroke = ch.get(A + 'strokeColor')
                sattr = ''
                if stroke is not None:
                    sc, sa = colour(stroke)
                    sattr = ' stroke="%s" stroke-width="%s"' % (sc, ch.get(A + 'strokeWidth') or '1') + (
                        ' stroke-opacity="%s"' % sa if sa is not None else '')
                extra = sattr + (' fill-opacity="%s"' % op if op is not None else '') + (
                    ' fill-rule="evenodd"' if rule == 'evenOdd' else '')
                out_list.append('<path fill="%s"%s d="%s"/>' % (fill or '#000000', extra, ch.get(A + 'pathData')))

    walk(root, body)
    t = '<title>%s</title>' % title if title else ''
    svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" width="%s" height="%s">%s%s%s</svg>\n' % (
        vw, vh, vw, vh, t, ('<defs>%s</defs>' % ''.join(defs)) if defs else '', ''.join(body))
    with open(out, 'w') as f:
        f.write(svg)
    print(out, len(svg), 'bytes')


main()
