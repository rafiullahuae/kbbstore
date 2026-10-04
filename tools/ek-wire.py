"""Lane EK: apply the integrator's wiring lines to a COPY of the app (argv[1]).

Exactly the lines the lane report hands the integrator, so the screenshots are
of the console as it will be once wired. The worktree's own files are never
touched: the copy's hard links are broken first (os.remove + write)."""
import os, sys

app = sys.argv[1]

def patch(rel, pairs):
    path = os.path.join(app, rel)
    s = open(path, encoding='utf-8').read()
    for old, new in pairs:
        if s.count(old) != 1:
            raise SystemExit(f'{rel}: anchor not found exactly once: {old[:70]}')
        s = s.replace(old, new, 1)
    os.remove(path)
    open(path, 'w', encoding='utf-8').write(s)

patch('routes/web.php', [
    ("require __DIR__.'/emails-admin.php';",
     "require __DIR__.'/emails-admin.php';\n        require __DIR__.'/emails-templates-admin.php';"),
])

ICON = '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'
patch('resources/views/admin/app.blade.php', [
    ("@include('admin.partials.emails-screens')",
     "@include('admin.partials.emails-screens')\n@include('admin.partials.emails-templates-screens')"),
    ("['emails-sending','Sending & delivery','<path d=\"m22 2-7 20-4-9-9-4z\"/><path d=\"M22 2 11 13\"/>']",
     "['emails-sending','Sending & delivery','<path d=\"m22 2-7 20-4-9-9-4z\"/><path d=\"M22 2 11 13\"/>'],['emails-customer','Customer emails','" + ICON + "']"),
    ("'emails-sending':['Emails','Sending & delivery']",
     "'emails-sending':['Emails','Sending & delivery'],'emails-customer':['Emails','Customer emails'],'emails-edit':['Emails','Customer emails']"),
    ("'emails','emails-sending','emails-branding','emails-sent'",
     "'emails','emails-sending','emails-branding','emails-sent','emails-customer','emails-edit'"),
])
print('wired')
