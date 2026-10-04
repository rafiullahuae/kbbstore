#!/usr/bin/env python3
"""Lane MK -- apply the integrator's wiring lines for Marketing Emails to a COPY.

    python3 tools/mk-wire.py <web.php> <app.blade.php>

Edits the two files it is given, in place. It is only ever pointed at the
copies inside a preview's shadow tree (tools/mk-preview.sh); the lane never
edits routes/web.php or resources/views/admin/app.blade.php itself (CLAUDE.md).
The six edits are exactly the lines the lane's report hands the integrator, so
the screenshots and the wired suite run are of the shop as it will be.
Idempotent: a second run changes nothing.
"""
import sys

web_path, app_path = sys.argv[1], sys.argv[2]

web = open(web_path).read()
ADMIN = "        require __DIR__.'/marketing-emails-admin.php';"
if ADMIN not in web:
    anchor = "        require __DIR__.'/emails-admin.php';\n"
    assert anchor in web, 'emails-admin require not found'
    web = web.replace(anchor, anchor + "\n        // Growth & Marketing → Marketing Emails (Lane MK, packages E3 + E4).\n" + ADMIN + "\n", 1)
PUBLIC = "require __DIR__.'/marketing-public.php';"
if PUBLIC not in web:
    anchor = "require __DIR__.'/mail-kit.php';\n"
    assert anchor in web, 'mail-kit require not found'
    web = web.replace(anchor, anchor + "\n// Marketing Emails' public end (Lane MK): unsubscribe page, RFC 8058 one-click,\n// the click redirect and the shipped email pictures. Above the catch-all.\n" + PUBLIC + "\n", 1)
open(web_path, 'w').write(web)

app = open(app_path).read()
NAV = "['mkt-email','Marketing Emails',"
if NAV not in app:
    anchor = "{sec:'Growth & Marketing',items:[['newsletter'"
    assert anchor in app, 'Growth & Marketing NAV not found'
    app = app.replace(anchor, "{sec:'Growth & Marketing',items:[['mkt-email','Marketing Emails','<path d=\"M3 6h18v12H3z\"/><path d=\"m3 7 9 6 9-6\"/><path d=\"M17 3.5l1 1.8 2 .4-1.4 1.4.3 2-1.9-.9-1.9.9.3-2L14 5.7l2-.4z\"/>','new'],['newsletter'", 1)
TITLE = "'mkt-email':['Growth & Marketing','Marketing Emails']"
if TITLE not in app:
    anchor = "'searchterms':['Growth & Marketing','Search Terms']};"
    assert anchor in app, 'TITLES end not found'
    app = app.replace(anchor, "'searchterms':['Growth & Marketing','Search Terms']," + TITLE + "};", 1)
LATE = "'spotted','mkt-email']);"
if LATE not in app:
    anchor = "'emails-sent','spotted']);"
    assert anchor in app, 'LATE_RENDERED end not found'
    app = app.replace(anchor, LATE, 1)
INC = "@include('admin.partials.marketing-emails-screens')"
if INC not in app:
    anchor = "@include('admin.partials.emails-screens')\n"
    assert anchor in app, 'emails-screens include not found'
    app = app.replace(anchor, anchor + "{{-- Growth & Marketing → Marketing Emails (Lane MK, E3 + E4): Campaigns,\n     Templates, Customer groups, Reports, the builder and Review & send. Wraps\n     window.go for 'mkt-email'; its row is declared in NAV above. --}}\n" + INC + "\n", 1)
open(app_path, 'w').write(app)
print('wired')
