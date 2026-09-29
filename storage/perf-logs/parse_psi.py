import re,sys,html,json
path=sys.argv[1]
s=open(path,encoding='utf-8',errors='replace').read()
# strip script/style
s=re.sub(r'(?is)<script.*?</script>',' ',s)
s=re.sub(r'(?is)<style.*?</style>',' ',s)
s=re.sub(r'(?is)<noscript.*?</noscript>',' ',s)
# extract all extrabeauty / external resource urls BEFORE tag strip
urls=re.findall(r'https?://[^\s"\'<>()\\]+',s)
from collections import Counter
c=Counter(urls)
# tag strip with block separators
s=re.sub(r'(?i)<(br|/?div|/?p|/?li|/?tr|/?td|/?th|/?h[1-6]|/?span|/?section|/?table)\b[^>]*>','\n',s)
s=re.sub(r'(?s)<[^>]+>',' ',s)
s=html.unescape(s)
lines=[re.sub(r'[ \t\xa0]+',' ',l).strip() for l in s.split('\n')]
lines=[l for l in lines if l]
out=[]
prev=None
for l in lines:
    if l!=prev: out.append(l)
    prev=l
open(sys.argv[2],'w').write('\n'.join(out))
with open(sys.argv[2]+'.urls','w') as f:
    for u,n in c.most_common():
        f.write(f'{n}\t{u}\n')
print(len(out),'lines',len(c),'unique urls')
