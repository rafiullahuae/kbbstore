#!/bin/bash
# Double-click to start KBB Studio. Keep this window open while you work; closing the browser tab stops it.
cd "$(dirname "$0")" || exit 1
case "$(uname -m)" in
  arm64) ENGINE="engine/kbbstudio-mac-arm64" ;;   # Apple silicon (M1, M2, M3 ...)
  *)     ENGINE="engine/kbbstudio-mac-amd64" ;;   # Intel Macs
esac
if [ ! -f "$ENGINE" ]; then
  echo "Cannot find $ENGINE — is the KBB Studio folder complete?"
  read -r -p "Press Return to close." _
  exit 1
fi
chmod +x "$ENGINE" 2>/dev/null
# The folder was downloaded, so macOS marks the engine as "from the internet"; you already approved this folder
# by opening this file with right-click > Open, so clear the mark on our own engine only.
xattr -d com.apple.quarantine "$ENGINE" 2>/dev/null
# Your models, companies, projects, keys, music, photos and brand kits live in "KBB Studio Data" in your home folder,
# NOT in this folder: an update replaces this folder and touches none of them. On the first start anything of yours
# still in here (and the models of an older KBB Studio folder in Downloads, Desktop, Documents ...) is moved there.
#
# Not "exec": after an update from Configure → Updates the engine restarts itself in this same window, and if the new
# one cannot start at all, this script is still here to offer the version you had before (.update/previous).
STARTED=$SECONDS
"$ENGINE" --dir "$PWD" --data "$HOME/KBB Studio Data"
CODE=$?
PREV=".update/previous"
if [ "$CODE" -ne 0 ] && [ $((SECONDS - STARTED)) -lt 60 ] && [ -f "$PREV/units.txt" ]; then
  echo ""
  echo "KBB Studio did not start (code $CODE). It was updated recently."
  read -r -p "Type B and press Return to go back to the version you had before (anything else closes): " ANSWER
  if [ "$ANSWER" = "B" ] || [ "$ANSWER" = "b" ]; then
    UNDO=".update/undo-$$"
    mkdir -p "$UNDO" || exit 1
    OK=1
    while IFS= read -r U; do
      case "$U" in ""|/*|*..*|.*) continue ;; esac
      case "$U" in app|vendor|brands|library|engine/kbbstudio-mac-*|models/manifest.json|update.json|*.md|*.html|*.command|*.txt) ;; *) continue ;; esac
      case "$U" in */*/*) continue ;; esac
      if [ -e "$U" ] || [ -L "$U" ]; then mkdir -p "$UNDO/$(dirname "$U")" && mv "$U" "$UNDO/$U" || OK=0; fi
      if [ -e "$PREV/$U" ]; then mkdir -p "$(dirname "$U")" && mv "$PREV/$U" "$U" || OK=0; fi
    done < "$PREV/units.txt"
    if [ "$OK" = 1 ]; then
      rm -rf "$PREV" "$UNDO"
      echo "Done: the version you had before is back. Double-click \"Start KBB Studio\" again."
    else
      echo "Some files could not be put back. Nothing is lost: the newer files are in the .update folder."
    fi
    read -r -p "Press Return to close." _
  fi
fi
exit "$CODE"
