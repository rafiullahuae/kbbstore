#!/bin/bash
# Builds the folder the owner copies to his Mac: dist/KBB Ads Autopilot/ and dist/KBB Ads Autopilot v<VERSION>.zip
#   in:  the launcher, the engine for Apple silicon + Intel, the app (app/), VERSION, README.md, docs/META-SETUP.md
#   out: the engine source, tests, check tools, build outputs for Linux -- and never the data folder (keys, history,
#        audit logs live in ~/KBB Ads Autopilot Data, not in this folder)
# The version lives in ONE place: the VERSION file (build.sh stamps it into the engine; update.json carries it).
# Ported from KBB Studio's tools/package-mac.sh; separate app, separate zip, separate data folder.
set -euo pipefail
cd "$(dirname "$0")/.."
NAME="KBB Ads Autopilot"
VER="$(tr -d '[:space:]' < VERSION)"
[[ "$VER" =~ ^[0-9]{1,4}(\.[0-9]{1,4}){1,3}$ ]] || { echo "VERSION '$VER' is not like 0.1.0" >&2; exit 1; }
[ -s app/index.html ] || { echo "app/index.html is missing (LANE BRAIN's app/)" >&2; exit 1; }
ZIP="$NAME v$VER.zip"   # the folder inside stays "$NAME", so an update is still: replace the folder
./engine/build.sh >/dev/null
rm -rf "dist/$NAME" "dist/$NAME v"*.zip "dist/$NAME patch "*.zip
mkdir -p "dist/$NAME/engine" "dist/$NAME/docs"
cp "Start $NAME.command" VERSION "dist/$NAME/"
[ -f README.md ] && cp README.md "dist/$NAME/"
cp docs/META-SETUP.md "dist/$NAME/docs/"
chmod +x "dist/$NAME/Start $NAME.command"
cp engine/kaa-mac-arm64 engine/kaa-mac-amd64 "dist/$NAME/engine/"
chmod +x "dist/$NAME/engine/"kaa-mac-*
cp -R app "dist/$NAME/"
find "dist/$NAME" -type f -name '.*' -delete   # .DS_Store, .gitignore: never needed, and an update refuses hidden files
# nothing that is not ours to ship
for bad in secrets.json audit.jsonl accounts; do
  if find "dist/$NAME" -name "$bad" | grep -q .; then echo "user data ($bad) leaked into the package" >&2; exit 1; fi
done
if grep -rIl -E 'EAA[A-Za-z0-9]{20,}|sk-ant-[A-Za-z0-9_-]{8,}' "dist/$NAME" >/dev/null 2>&1; then echo "a key-shaped string is in the package" >&2; exit 1; fi
# update.json: what Settings → Updates checks before installing this zip (engine/update.go): the version, the notes
# from tools/release-notes.txt ("## $VER" block) and every file's size and sha256.
python3 -I tools/engine-make-update-manifest.py "dist/$NAME" "$VER" tools/release-notes.txt
(cd dist && zip -qry -X "$ZIP" "$NAME")
# every full zip built is kept: tools/package-patch.sh <version> makes a patch from the one the owner has
mkdir -p dist/releases && cp "dist/$ZIP" "dist/releases/$ZIP"
du -sh "dist/$NAME" "dist/$ZIP"
