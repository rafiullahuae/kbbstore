#!/bin/bash
# A PATCH for Settings → Updates: only what changed since the version the owner has, like the website's packages.
#
#   tools/package-patch.sh <since> [--to <folder>] [--notes <file>] [--out <dir>]
#
#   <since>  the version the patch applies to, as one of:
#              - the full zip he has ("dist/releases/KBB Ads Autopilot v0.3.0.zip"), or its unpacked folder
#              - a version ("0.3.0"): its zip from dist/releases/ (package-mac.sh keeps every one it builds there)
#              - a git ref (a commit or tag the full zip was built from): that tree is packaged again here
#   --to     an already built full folder to patch to (default: tools/package-mac.sh builds the current tree, which
#            also leaves the matching full zip in dist/)
#   --notes  release notes (default tools/release-notes.txt: the "## <new version>" block)
#   --out    where the zip goes (default dist/)
#
# Out: "KBB Ads Autopilot patch <from> to <to>.zip" (plain ASCII: a browser did not hand a file named with "→" to the page in the check) holding "KBB Ads Autopilot/" + only the new or changed files +
# update.json (format 2, kind patch, from, files, removed, result). The engine installs it ONLY onto exactly <from>;
# on any other version it says which full zip to use instead. Full zips keep working as before.
set -euo pipefail
cd "$(dirname "$0")/.."
HERE="$PWD"
NAME="KBB Ads Autopilot"
SINCE="${1:-}"; [ -n "$SINCE" ] || { sed -n '2,17p' "$0"; exit 2; }
shift
TO=""; NOTES="$HERE/tools/release-notes.txt"; OUT="$HERE/dist"
while [ $# -gt 0 ]; do
  case "$1" in
    --to) TO="$(cd "$2" && pwd)"; shift 2 ;;
    --notes) NOTES="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"; shift 2 ;;
    --out) mkdir -p "$2"; OUT="$(cd "$2" && pwd)"; shift 2 ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done
T="$(mktemp -d "${TMPDIR:-/tmp}/kaa-patch.XXXXXX")"
trap 'rm -rf "$T"' EXIT

# ── the version he has ──
unzip_folder() { # zip → the "KBB Ads Autopilot" folder inside it
  mkdir -p "$T/from" && unzip -q "$1" -d "$T/from"
  [ -f "$T/from/$NAME/VERSION" ] || { echo "$1 is not a $NAME package" >&2; exit 1; }
  FROM="$T/from/$NAME"
}
if [ -f "$SINCE" ]; then
  unzip_folder "$SINCE"
elif [ -d "$SINCE" ] && [ -f "$SINCE/VERSION" ]; then
  FROM="$(cd "$SINCE" && pwd)"
elif [[ "$SINCE" =~ ^[0-9]{1,4}(\.[0-9]{1,4}){1,3}$ ]] && [ -f "dist/releases/$NAME v$SINCE.zip" ]; then
  unzip_folder "dist/releases/$NAME v$SINCE.zip"
elif git rev-parse --verify -q "$SINCE^{commit}" >/dev/null; then
  PREFIX="$(git rev-parse --show-prefix)"
  mkdir -p "$T/src"
  git archive "$SINCE" "$PREFIX" | tar -x -C "$T/src"
  (cd "$T/src/$PREFIX" && bash tools/package-mac.sh >/dev/null)
  FROM="$T/src/$PREFIX/dist/$NAME"
else
  echo "Cannot find version $SINCE: pass the full zip the owner has, its folder, or the git ref it was built from." >&2
  echo "(package-mac.sh keeps every full zip it builds in dist/releases/.)" >&2
  exit 1
fi

# ── the version to patch to ──
if [ -z "$TO" ]; then
  bash tools/package-mac.sh >/dev/null
  TO="$HERE/dist/$NAME"
fi
VF="$(tr -d '[:space:]' < "$FROM/VERSION")"; VT="$(tr -d '[:space:]' < "$TO/VERSION")"

# ── the patch ──
python3 -I "$HERE/tools/engine-make-update-manifest.py" --patch "$FROM" "$TO" "$T/patch/$NAME" "$NOTES"
ZIP="$NAME patch $VF to $VT.zip"
rm -f "$OUT/$ZIP"
(cd "$T/patch" && zip -qry -X "$OUT/$ZIP" "$NAME")
# the same self-checks as a full package: nothing of the owner's, no key-shaped strings
for bad in secrets.json audit.jsonl accounts; do
  if find "$T/patch/$NAME" -name "$bad" | grep -q .; then echo "user data ($bad) leaked into the patch" >&2; exit 1; fi
done
if grep -rIl -E 'EAA[A-Za-z0-9]{20,}|sk-ant-[A-Za-z0-9_-]{8,}' "$T/patch/$NAME" >/dev/null 2>&1; then echo "a key-shaped string is in the patch" >&2; exit 1; fi
du -h "$OUT/$ZIP" | sed "s|$OUT/||"
