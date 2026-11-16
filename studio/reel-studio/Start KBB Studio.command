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
exec "$ENGINE" --dir "$PWD"
