#!/usr/bin/env python3
"""Lane MK -- pull the first campaign message out of the preview's LOG mailer.

    python3 tools/mk-sent-email.py <laravel.log> <out dir>

Writes <out>/sent-email-headers.txt (the envelope: To, Subject,
List-Unsubscribe, List-Unsubscribe-Post, Precedence), sent-email-text.txt
(the text part) and sent-email.html (the HTML part), exactly as the mailer
handed them over -- the proof that a campaign really went out with its
unsubscribe headers and both parts.
"""
import email, os, re, sys
from email import policy

log, out = sys.argv[1], sys.argv[2]
raw = open(log, encoding='utf-8').read()
chunks = re.split(r'^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] \w+\.DEBUG: ', raw, flags=re.M)
msg_raw = next(c for c in chunks if 'List-Unsubscribe-Post' in c)
msg = email.message_from_string(msg_raw, policy=policy.default)
os.makedirs(out, exist_ok=True)
with open(os.path.join(out, 'sent-email-headers.txt'), 'w') as f:
    for h in ['From', 'To', 'Subject', 'List-Unsubscribe', 'List-Unsubscribe-Post', 'Precedence', 'Content-Type']:
        f.write('%s: %s\n' % (h, msg[h]))
text = msg.get_body(preferencelist=('plain',)).get_content()
html = msg.get_body(preferencelist=('html',)).get_content()
open(os.path.join(out, 'sent-email-text.txt'), 'w').write(text)
open(os.path.join(out, 'sent-email.html'), 'w').write(html)
print('to', msg['To'], '| html', len(html.encode()), 'bytes | text', len(text.encode()), 'bytes')
