# Lane SBC: the Shop by Concern section's own bytes on a homepage (raw and
# gzip), from a saved page or the preview URL.
#   python3 tools/sbc-bytes.py http://127.0.0.1:9620/
import gzip
import sys
import urllib.request

src = sys.argv[1]
html = urllib.request.urlopen(src).read().decode('utf-8') if src.startswith('http') else open(src, encoding='utf-8').read()
page = html.encode()
if 'id="sbc"' not in html:
    print('page', len(page), 'gz', len(gzip.compress(page)), 'section: none')
    sys.exit(0)
i = html.index('<style>\n.kbb-home .sec.sbc')
j = html.index('</script>', html.index('id="sbc"')) + 9
sec = html[i:j].encode()
print('page', len(page), 'gz', len(gzip.compress(page)), '| section', len(sec), 'gz', len(gzip.compress(sec)))
