#!/usr/bin/env python3
"""Lane RL -- apply the integrator's two wiring lines for Users & Roles.

    python3 tools/rl-wire.py <routes/web.php> <resources/views/admin/app.blade.php>

Edits the two files it is given, in place, and is idempotent. The lane points
it at a throwaway copy (or at its own checkout for the wired suite run, then
`git checkout --` both files); it never commits either file. These are exactly
the lines the lane's report hands the integrator.
"""
import sys

web_path, app_path = sys.argv[1], sys.argv[2]

web = open(web_path).read()
REQ = "        require __DIR__.'/admin-roles.php';   // Platform → Users & Roles → Roles / Members (Lane RL)\n"
if "require __DIR__.'/admin-roles.php';" not in web:
    anchor = "        Route::delete('/users/{id}',         [AdminController::class, 'deleteUser']);\n"
    assert web.count(anchor) == 1, 'users delete route anchor not found exactly once'
    web = web.replace(anchor, anchor + REQ, 1)
open(web_path, 'w').write(web)

app = open(app_path).read()
INC = "@include('admin.partials.admin-roles-screen')"
if INC not in app:
    anchor = "@include('admin.partials.page-editor-screen')\n"
    assert app.count(anchor) == 1, 'page-editor include anchor not found exactly once'
    app = app.replace(anchor, anchor + "\n{{-- LANE RL · Platform → Users & Roles: Members and Roles, editable roles\n     (plan row 53). Rebinds window.renderUsers, the way the line above\n     rebinds renderUserPages; the sidebar row, TITLES entry and go() dispatch\n     entry for 'users' already exist, so this one line is the whole change. --}}\n" + INC + "\n", 1)
PRE = "@include('admin.partials.edit-presence')"
if PRE not in app:
    app = app.replace(INC + "\n", INC + "\n\n{{-- LANE RL · Edit presence: \"X is editing this product\" and Take over, on\n     every record editor (one fetch hook; App\\Support\\EditPresence is the guard). --}}\n" + PRE + "\n", 1)
open(app_path, 'w').write(app)
print('wired')
