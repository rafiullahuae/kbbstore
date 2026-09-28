#!/bin/sh
# Create a lane worktree that can actually run its own tests.
#
#   sh tools/lane-worktree.sh <lane> [base-branch]
#
# ── WHY THIS EXISTS, AND IT IS NOT TIDINESS ─────────────────────────────────
#
# On 28 September 2026 three lanes were given worktrees whose `vendor/` was a
# SYMLINK to the main checkout's, to save 569 MB each on a disk this project
# has already been burned by. It is the wrong saving, and the failure is
# silent in the worst way:
#
#   - Composer's autoloader computes `$baseDir` from the REALPATH of
#     `vendor/composer/`, so inside the worktree `App\…` resolved to
#     /home/user/kbbstore/app and `Tests\…` to its tests. **A lane's own code
#     was never loaded.** A lane could edit a file, watch its suite go green,
#     and have tested the other checkout.
#   - Pest derives `rootPath` the same way, so `uses(TestCase::class)->in('Feature')`
#     in tests/Pest.php never matched the lane's files and TestCase was never
#     applied. Every test ran with `app()` returning a bare Container.
#
# Measured, in two of the three lanes independently: a plain `vendor/bin/pest`
# reported ~6,650 failures, all of them "A facade root has not been set" or
# "Call to a member function connection() on null" — which reads like the lane
# has catastrophically broken the application and is nothing of the kind. Both
# lanes spent real time proving it was the harness. The third worked around it
# with two scratch flags and reported its suite as suspect.
#
# So: a real directory, copied. `cp -a` of ~570 MB takes a few seconds and the
# disk is the cheaper thing to spend. `vendor/` is gitignored, so nothing about
# it reaches a commit.
#
# node_modules IS symlinked, deliberately: nothing resolves an application path
# through it, and `npx vite build` is the integrator's step anyway.
set -e

LANE=$1
BASE=${2:-claude/kind-mayer-rpqesv}

if [ -z "$LANE" ]; then
	echo "usage: sh tools/lane-worktree.sh <lane> [base-branch]" >&2
	exit 2
fi

APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=/home/user/lane-$LANE

if [ -e "$DIR" ]; then
	echo "$DIR already exists — remove it first (git worktree remove --force $DIR)" >&2
	exit 1
fi

git -C "$APP" worktree add -b "lane/$LANE" "$DIR" "$BASE"

# Commit as Claude, not as the owner. Five lane commits once reached the
# integrator authored rite2rafi2@gmail.com, which GitHub shows as Unverified on
# every one of them, and the only fix is history rewriting. Getting it right
# here costs nothing.
git -C "$DIR" config user.email noreply@anthropic.com
git -C "$DIR" config user.name Claude

cp "$APP/.env" "$DIR/.env"
ln -sfn "$APP/node_modules" "$DIR/node_modules"

# The whole point of this script. Never a symlink — read the header.
cp -a "$APP/vendor" "$DIR/vendor"

cat <<INFO

lane-$LANE is ready at $DIR, on branch lane/$LANE off $BASE.

  vendor/        real directory ($(du -sh "$DIR/vendor" 2>/dev/null | cut -f1)), so the lane tests its OWN code
  node_modules/  symlink, which is safe
  git identity   Claude <noreply@anthropic.com>

Its databases, which it must use so it does not tear down another lane's:

  mysql -u root -e "CREATE DATABASE IF NOT EXISTS kbb_wp_$LANE;"
  KBB_WP_DB=kbb_wp_$LANE vendor/bin/pest --compact

  mysql -u root -e "CREATE DATABASE IF NOT EXISTS kbb_$LANE;"
  KBB_WP_DB=kbb_wp_$LANE KBB_TEST_DB=kbb_$LANE vendor/bin/pest -c phpunit-mysql.xml

When its branch is merged: git worktree remove --force $DIR  (the branch is kept,
and ~14 GB of finished worktrees once filled this disk and read as a
transaction bug — CLAUDE.md carries that one.)
INFO
