#!/bin/sh
# Verify a Core Updates package before it goes to the owner. ONE command, so a
# new session cannot skip a step:
#
#   sh tools/verify-package.sh storage/app/packages/kbb-update-2.60.486.zip <since-commit> [<ref>]
#
# <since-commit> is the same ref given to `php artisan kbb:package --since=`.
# <ref> is the commit the package was built from (default HEAD -- the normal
# case, run straight after building). Pass the release commit to re-check an
# older package: docs/patch-reference/ does exactly that.
#
# It checks, and prints PASS or FAIL for each:
#   1. update.json has all six keys (name, version, requires_php, notes, files,
#      migrations, signature) and its version matches VERSION;
#   2. every file in the zip is byte-identical to the committed tree at <ref>;
#   3. every shippable file changed since <since> is IN the zip (packages
#      2.60.102-.106 were built from a stale tree and 500'd every product page:
#      never trust a file list);
#   4. "migrations" is true exactly when the zip carries a migration (a false
#      flag means the migrations are copied and NEVER RUN);
#   5. App\Services\Update\UpdatePackage::verify() accepts it -- the same check
#      the shop runs on upload.
#
# Exit code 0 only when all five pass. Written for the 11 October 2026 handover.
set -e
ZIP=$1
SINCE=$2
REF=${3:-HEAD}
[ -n "$ZIP" ] && [ -n "$SINCE" ] || { echo "usage: sh tools/verify-package.sh <zip> <since-commit> [<ref>]" >&2; exit 2; }
[ -f "$ZIP" ] || { echo "no such zip: $ZIP" >&2; exit 2; }
APP=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d "$APP/storage/framework/testing/verify-package.XXXXXX")
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK/pkg"

python3 - "$APP" "$WORK/pkg" "$SINCE" "$REF" <<'PY'
import json, os, subprocess, sys, filecmp
app, pkg, since, ref = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
def at_ref(path):
    r = subprocess.run(['git', '-C', app, 'show', f'{ref}:{path}'], capture_output=True)
    return r.stdout if r.returncode == 0 else None
ok = True
def report(good, what):
    global ok
    ok = ok and good
    print(('PASS  ' if good else 'FAIL  ') + what)

m = json.load(open(os.path.join(pkg, 'update.json')))
keys = {'name', 'version', 'requires_php', 'notes', 'files', 'migrations', 'signature'}
report(keys <= set(m), 'update.json keys: ' + ', '.join(sorted(m)))
ver = (at_ref('VERSION') or b'').decode().strip()
report(m.get('version') == ver, f"version {m.get('version')} matches VERSION {ver} at {ref}")

shipped = []
for root, _, files in os.walk(os.path.join(pkg, 'files')):
    for f in files:
        shipped.append(os.path.relpath(os.path.join(root, f), os.path.join(pkg, 'files')))
bad = [p for p in shipped if at_ref(p) != open(os.path.join(pkg, 'files', p), 'rb').read()]
report(not bad, f"{len(shipped)} files byte-identical to the tree at {ref}" + (': DIFFER ' + ', '.join(bad) if bad else ''))

changed = subprocess.run(['git', '-C', app, 'diff', '--name-only', '--diff-filter=AM', since, ref], capture_output=True, text=True, check=True).stdout.split()
ship_prefixes = ('app/', 'bootstrap/', 'config/', 'database/', 'resources/', 'routes/', 'public/', 'lang/')
missing = [p for p in changed if p.startswith(ship_prefixes) and p not in shipped]
report(not missing, 'every shippable file changed since ' + since + ' is in the zip' + (': MISSING ' + ', '.join(missing) if missing else ''))

has_mig = any(p.startswith('database/migrations/') for p in shipped)
report(bool(m.get('migrations')) == has_mig, f"migrations flag {m.get('migrations')} and zip carries migrations: {has_mig}")
sys.exit(0 if ok else 1)
PY

# The shop's own check, against an empty scratch database (verify() only asks
# whether update_releases exists).
touch "$WORK/empty.sqlite"
RESULT=$(cd "$APP" && DB_DATABASE="$WORK/empty.sqlite" php artisan tinker --execute="echo (new \App\Services\Update\UpdatePackage('$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")', '$WORK/stage', app(\App\Services\Update\UpdateGuard::class)))->verify() ? 'TRUE' : 'FALSE';" 2>&1 | tail -1)
if [ "$RESULT" = "TRUE" ]; then echo "PASS  UpdatePackage::verify() accepts it"; else echo "FAIL  UpdatePackage::verify(): $RESULT"; exit 1; fi
echo "ALL CHECKS PASSED — safe to hand to the owner."
