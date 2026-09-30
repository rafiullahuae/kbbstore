#!/bin/sh
# Run the suite with the needle survey attached. (Lane PLC)
#
# The ordinary phpunit.xml is NOT touched: a copy of it is generated with the
# bootstrap swapped for tools/plc-needle-scan.php, which requires the real
# bootstrap first and then adds a recording pipe to Pest's toContain.
#
# The generated config lives in storage/ and names every path ABSOLUTELY,
# because phpunit resolves a config's relative paths against the directory the
# config is in, and this one is not in the repository root.
#
#     ./tools/plc-needle-scan.sh
#     php tools/plc-needle-report.php
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
OUT=$APP/storage/plc-logs
CFG=$OUT/plc-needle-phpunit.xml

mkdir -p "$OUT/needles"
rm -f "$OUT"/needles/*.jsonl

# KBB_WP_DB BELONGS ON THIS RUN TOO. It is an ordinary full suite, so it reaches
# the WordPress-exporter harness exactly as `vendor/bin/pest` does, and a run
# without it collides with any other lane's (CLAUDE.md).
#
# ── DERIVED FROM THE WORKTREE, NOT HARDCODED TO THE LANE THAT WROTE THIS ────
#
# This read `: "${KBB_WP_DB:=kbb_wp_plc}"`. The moment the tool merged, ANOTHER
# LANE RAN IT: `php vendor/bin/pest -c /home/user/lane-bg/storage/plc-logs/
# plc-needle-phpunit.xml` was on the process list within the hour, and with that
# default it would have driven kbb_wp_plc -- Lane PLC's database -- from Lane
# BG's worktree, dropping and rebuilding the WordPress harness tables under
# whatever Lane PLC was running. That is precisely the collision CLAUDE.md names,
# shipped inside the tool meant to find false greens.
#
# A shared tool must not carry one lane's name as a default. The worktree
# already knows which lane it is, so the database is named from it and every
# lane that runs this gets its own without being told.
if [ -z "${KBB_WP_DB:-}" ]; then
  KBB_WP_DB=kbb_wp_$(basename "$APP" | sed 's/^lane-//')
fi
export KBB_WP_DB

# Created here rather than left as a step in a comment somebody has to notice:
# the harness DROPS the tables it uses, so a missing database is a failed run
# and a shared one is a failure in somebody else's.
mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$KBB_WP_DB\`;" 2>/dev/null || true
echo "wp harness database: $KBB_WP_DB"

python3 - "$APP" "$CFG" <<'KBBCFGPY'
import sys, re
app, cfg = sys.argv[1], sys.argv[2]
src = open(app + '/phpunit.xml', encoding='utf-8').read()
src = src.replace('bootstrap="tests/bootstrap.php"',
                  'bootstrap="%s/tools/plc-needle-scan.php"' % app)
src = src.replace('<directory>tests/Feature</directory>',
                  '<directory>%s/tests/Feature</directory>' % app)
src = src.replace('<directory>app</directory>',
                  '<directory>%s/app</directory>' % app)
open(cfg, 'w', encoding='utf-8').write(src)
print('wrote ' + cfg)
KBBCFGPY

cd "$APP"
vendor/bin/pest -c "$CFG" --compact "$@" > "$OUT/plc-needle-suite.txt" 2>&1 || true
tail -4 "$OUT/plc-needle-suite.txt" | tr -d '\033' | sed 's/\[[0-9;]*m//g'
echo "rows: $(cat "$OUT"/needles/*.jsonl 2>/dev/null | wc -l)"
