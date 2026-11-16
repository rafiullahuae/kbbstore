#!/bin/bash
# Builds the folder the owner copies to his Mac: dist/KBB Studio/ and dist/KBB Studio.zip
#   in:  the launcher, the engine for Apple silicon + Intel, the app (captions + automation), vendor (transformers.js,
#        MediaPipe, mp4 muxer/demuxer, caption fonts), brand kits + country packs, the music/SFX libraries, the model
#        LIST (models/manifest.json) and the README.
#   out: the AI models themselves (downloaded once by the Setup screen on first run), projects, exports, the
#        developer harness pages (app/*/dev), test tools and screenshots.
set -euo pipefail
cd "$(dirname "$0")/.."
NAME="KBB Studio"   # keep in step with app/core/config.js APP_NAME
./engine/build.sh >/dev/null
rm -rf "dist/$NAME" "dist/$NAME.zip"
mkdir -p "dist/$NAME/engine" "dist/$NAME/models" "dist/$NAME/workspaces" "dist/$NAME/library/music/user"
cp "Start $NAME.command" README.md "dist/$NAME/"
chmod +x "dist/$NAME/Start $NAME.command"
cp engine/kbbstudio-mac-arm64 engine/kbbstudio-mac-amd64 "dist/$NAME/engine/"
chmod +x "dist/$NAME/engine/"kbbstudio-mac-*
cp -R app vendor brands "dist/$NAME/"
rm -rf "dist/$NAME/app/captions/dev" "dist/$NAME/app/auto/dev" "dist/$NAME/app/captions/translate/dev" "dist/$NAME/app/sync/dev"
cp -R library/. "dist/$NAME/library/"
rm -f "dist/$NAME/library/music/user/library.json"
cp models/manifest.json "dist/$NAME/models/"
find "dist/$NAME" -name '.DS_Store' -delete
# nothing that is not ours to ship: no model weights, no projects
if find "dist/$NAME/models" -type f ! -name manifest.json | grep -q .; then echo "model files leaked into the package" >&2; exit 1; fi
(cd dist && zip -qry -X "$NAME.zip" "$NAME")
du -sh "dist/$NAME" "dist/$NAME.zip"
