"""Lane HL2 (17 October 2026) -- PREVIEW TOOL, nothing ships.

Turns a word set in the shop's own Cairo (resources/fonts/cairo/cairo-arabic.woff2,
weight 700) into an inline-SVG path whose viewBox IS the ink box, so a flex
container centres the ink exactly, on every device, whatever Arabic face that
device has (English pages do not load Cairo, so "ع" there falls back to the
device's face: SF Arabic, Noto Naskh, DejaVu ...).

    PYTHONPATH=<dir with fonttools, brotli, uharfbuzz> python3 tools/hl2-glyphs.py > out.json

fonttools/brotli/uharfbuzz are needed by THIS script only, installed into a
scratch directory; the shop gains no dependency -- the output is a constant.
"""
import io, json, sys
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.transformPen import TransformPen
import uharfbuzz as hb

SRC = sys.argv[1] if len(sys.argv) > 1 else 'resources/fonts/cairo/cairo-arabic.woff2'
font = TTFont(SRC)
font = instantiateVariableFont(font, {'wght': 700})
font.flavor = None
buf = io.BytesIO(); font.save(buf)
blob = buf.getvalue()
gs = font.getGlyphSet()
upem = font['head'].unitsPerEm

out = {}
for text in ['ع', 'العربية']:
    hbf = hb.Font(hb.Face(blob))
    b = hb.Buffer(); b.add_str(text); b.guess_segment_properties()
    hb.shape(hbf, b, {})
    pen = SVGPathPen(gs, lambda v: ('%.0f' % v))
    bp = BoundsPen(gs)
    x = 0
    for info, pos in zip(b.glyph_infos, b.glyph_positions):
        name = font.getGlyphName(info.codepoint)
        # y flipped (SVG grows down); harfbuzz already returns RTL glyphs in visual order
        t = (1, 0, 0, -1, x + pos.x_offset, -pos.y_offset)
        gs[name].draw(TransformPen(pen, t))
        gs[name].draw(TransformPen(bp, t))
        x += pos.x_advance
    xmin, ymin, xmax, ymax = bp.bounds
    out[text] = {'d': pen.getCommands(), 'viewBox': '%d %d %d %d' % (xmin, ymin, xmax - xmin, ymax - ymin),
                 'inkW': xmax - xmin, 'inkH': ymax - ymin, 'upem': upem}
json.dump(out, sys.stdout, ensure_ascii=False)
