"""Lane PW: build docs/pw-preview/index.html from tools/pwa-preview.tpl.html.

The two homepage backdrops (storage/pw-logs/bd-*-nowa.jpg, written by
tools/pwa-backdrops.cjs against tools/pwa-shop-preview.sh) are inlined as data URIs so
the preview is one self-contained file the owner can open anywhere.
"""
import base64, pathlib
root = pathlib.Path(__file__).resolve().parent.parent
tpl = (root / 'tools/pwa-preview.tpl.html').read_text()
for key, name in (('__BD_PHONE__', 'bd-phone-nowa.jpg'), ('__BD_TABLET__', 'bd-tablet-nowa.jpg')):
    data = base64.b64encode((root / 'storage/pw-logs' / name).read_bytes()).decode()
    tpl = tpl.replace(key, 'data:image/jpeg;base64,' + data)
out = root / 'docs/pw-preview/index.html'
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(tpl)
print(out, out.stat().st_size, 'bytes')
