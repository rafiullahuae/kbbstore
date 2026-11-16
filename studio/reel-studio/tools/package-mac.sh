#!/bin/bash
# Builds the folder the owner copies to his Mac: dist/KBB Studio/ and dist/KBB Studio.zip
# (engine binaries for Apple silicon + Intel, the app, vendor, brand kits, libraries, model list; no models, no projects).
set -euo pipefail
cd "$(dirname "$0")/.."
NAME="KBB Studio"   # keep in step with app/core/config.js APP_NAME
./engine/build.sh >/dev/null
rm -rf "dist/$NAME" "dist/$NAME.zip"
mkdir -p "dist/$NAME/engine" "dist/$NAME/models" "dist/$NAME/workspaces"
cp "Start $NAME.command" README.md "dist/$NAME/"
chmod +x "dist/$NAME/Start $NAME.command"
cp engine/kbbstudio-mac-arm64 engine/kbbstudio-mac-amd64 "dist/$NAME/engine/"
cp -R app vendor "dist/$NAME/"
for d in brands library; do [ -d "$d" ] && cp -R "$d" "dist/$NAME/" || mkdir -p "dist/$NAME/$d"; done
cp models/manifest.json "dist/$NAME/models/"
(cd dist && zip -qry "$NAME.zip" "$NAME")
du -sh "dist/$NAME" "dist/$NAME.zip"
