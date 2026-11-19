#!/bin/bash
# Builds the folder the owner copies to his Mac: dist/KBB Studio/ and dist/KBB Studio v<APP_VERSION>.zip
#   in:  the launcher, the engine for Apple silicon + Intel, the app (captions + automation, the Ad Studio), vendor (transformers.js,
#        MediaPipe, mp4 muxer/demuxer, caption fonts), brand kits + country packs, the music/SFX libraries, the model
#        LIST (models/manifest.json) and the README.
#   out: the AI models themselves (downloaded once by the Setup screen on first run), projects, exports, the
#        developer harness pages (app/*/dev), test tools and screenshots.
set -euo pipefail
cd "$(dirname "$0")/.."
NAME="KBB Studio"   # keep in step with app/core/config.js APP_NAME
VER="$(sed -n "s/^export const APP_VERSION = '\([^']*\)'.*/\1/p" app/core/config.js)"
[ -n "$VER" ] || { echo "APP_VERSION not found in app/core/config.js" >&2; exit 1; }
ZIP="$NAME v$VER.zip"   # the folder inside stays "$NAME", so an update is still: replace the folder
./engine/build.sh >/dev/null
rm -rf "dist/$NAME" "dist/$NAME.zip" "dist/$NAME v"*.zip
# no workspaces/ or settings/: the owner's data lives in ~/KBB Studio Data (engine/data.go), never in this folder
mkdir -p "dist/$NAME/engine" "dist/$NAME/models"
cp "Start $NAME.command" README.md "GUIDE - keys and what to send.html" "dist/$NAME/"
chmod +x "dist/$NAME/Start $NAME.command"
cp engine/kbbstudio-mac-arm64 engine/kbbstudio-mac-amd64 "dist/$NAME/engine/"
chmod +x "dist/$NAME/engine/"kbbstudio-mac-*
cp -R app vendor "dist/$NAME/"
# the Ad Studio (app/adstudio: docs/plans/ad-studio/build.py puts it there) with its cut-out file beside it
for f in ad-studio.html ad-studio-cutout.js entry.js; do
  if [ ! -s "dist/$NAME/app/adstudio/$f" ]; then echo "the Ad Studio is missing app/adstudio/$f (run docs/plans/ad-studio/build.py)" >&2; exit 1; fi
done
rm -rf "dist/$NAME/app/captions/dev" "dist/$NAME/app/auto/dev" "dist/$NAME/app/captions/translate/dev" "dist/$NAME/app/sync/dev"
# only the BUILT-IN brand kits and library: a kit made on this machine, its music and saved photos are user data
mkdir -p "dist/$NAME/brands" "dist/$NAME/library"
git ls-files -z brands library | while IFS= read -r -d '' f; do mkdir -p "dist/$NAME/$(dirname "$f")"; cp "$f" "dist/$NAME/$f"; done
rm -rf "dist/$NAME/library/music/user" "dist/$NAME/library/people"
cp models/manifest.json "dist/$NAME/models/"
find "dist/$NAME" -type f -name '.*' -delete   # .DS_Store, .gitignore: never needed, and an update refuses hidden files
# nothing that is not ours to ship: no model weights, no projects, no keys, no user library
if find "dist/$NAME/models" -type f ! -name manifest.json | grep -q .; then echo "model files leaked into the package" >&2; exit 1; fi
for d in workspaces settings library/music/user library/people; do
  if [ -e "dist/$NAME/$d" ]; then echo "user data ($d) leaked into the package" >&2; exit 1; fi
done
# update.json: what Configure → Updates checks before installing this zip (engine/update.go): the version, the notes
# from tools/release-notes.txt ("## $VER" block) and every file's size and sha256. The zip still works the old way too.
python3 -I tools/make-update-manifest.py "dist/$NAME" "$VER" tools/release-notes.txt
(cd dist && zip -qry -X "$ZIP" "$NAME")
du -sh "dist/$NAME" "dist/$ZIP"
