#!/bin/bash
# Double-click to start KBB Ads Autopilot. Keep this window open while it works; closing the browser tab stops it.
# While the Mac is closed, Meta's own rules and the account spending limit keep the ads safe (docs/META-SETUP.md).
cd "$(dirname "$0")" || exit 1
case "$(uname -m)" in
  arm64) ENGINE="engine/kaa-mac-arm64" ;;   # Apple silicon (M1, M2, M3 ...)
  *)     ENGINE="engine/kaa-mac-amd64" ;;   # Intel Macs
esac
# Your settings, history, creatives and the audit log live in "KBB Ads Autopilot Data" in your home folder, and your
# keys in the macOS Keychain -- NOT in this folder: an update replaces this folder and touches none of them.
DATA="$HOME/KBB Ads Autopilot Data"
PREV=".update/previous"
MARK=".update/health.json"   # left by Settings → Updates: the new version has not proved itself yet

# put_back: the version kept in .update/previous goes back in place (shell only: works whatever version it is)
put_back() {
  local UNDO=".update/undo-$$" OK=1 U
  mkdir -p "$UNDO" || return 1
  while IFS= read -r U; do
    case "$U" in ""|/*|*..*|.*) continue ;; esac
    case "$U" in app|docs|engine/kaa-mac-*|VERSION|update.json|*.md|*.command|*.txt) ;; *) continue ;; esac
    case "$U" in */*/*) continue ;; esac
    if [ -e "$U" ] || [ -L "$U" ]; then mkdir -p "$UNDO/$(dirname "$U")" && mv "$U" "$UNDO/$U" || OK=0; fi
    if [ -e "$PREV/$U" ]; then mkdir -p "$(dirname "$U")" && mv "$PREV/$U" "$U" || OK=0; fi
  done < "$PREV/units.txt"
  [ "$OK" = 1 ] || return 1
  rm -rf "$PREV" "$UNDO"
}

TRIES=0
while :; do
  if [ ! -f "$ENGINE" ]; then
    echo "Cannot find $ENGINE — is the KBB Ads Autopilot folder complete?"
    read -r -p "Press Return to close." _
    exit 1
  fi
  chmod +x "$ENGINE" 2>/dev/null
  # The folder was downloaded, so macOS marks the engine as "from the internet"; you already approved this folder by
  # opening this file with right-click > Open, so clear the mark on our own engine only.
  xattr -d com.apple.quarantine "$ENGINE" 2>/dev/null
  # Not "exec": after an update the engine restarts itself in this same window, and if the new one cannot start at
  # all, this script is still here to put the version you had before back.
  STARTED=$SECONDS
  "$ENGINE" --dir "$PWD" --data "$DATA"
  CODE=$?
  [ "$CODE" -ne 0 ] && [ $((SECONDS - STARTED)) -lt 60 ] && [ -f "$PREV/units.txt" ] || exit "$CODE"
  NEW="$(tr -d '[:space:]' < VERSION 2>/dev/null)"
  OLD="$(sed -n 's/.*"version": *"\([0-9.]*\)".*/\1/p' "$PREV/info.json" 2>/dev/null | head -n 1)"
  # 1. Just updated and the new version never proved itself: put the one before back by itself, and start it.
  if [ -f "$MARK" ] && grep -q '"auto": *true' "$MARK" && [ "$TRIES" -eq 0 ]; then
    echo ""
    echo "Version $NEW did not start (code $CODE). Putting version $OLD back by itself…"
    if put_back; then
      rm -f "$MARK"
      printf '{"kind":"auto-rollback","from":"%s","to":"%s","ok":false,"reason":"Version %s did not start on this Mac (code %s), so version %s was put back by itself.","at":"%s","seen":false}\n' \
        "$NEW" "$OLD" "$NEW" "$CODE" "$OLD" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > .update/last.json
      echo "Done: version $OLD is back. Starting it…"
      TRIES=1
      continue
    fi
    echo "Some files could not be put back. Nothing is lost: the newer files are in the .update folder."
    read -r -p "Press Return to close." _
    exit "$CODE"
  fi
  # 2. Otherwise ask: it was updated recently, the version before is still here.
  echo ""
  echo "KBB Ads Autopilot did not start (code $CODE). It was updated recently."
  read -r -p "Type B and press Return to go back to the version you had before (anything else closes): " ANSWER
  if [ "$ANSWER" = "B" ] || [ "$ANSWER" = "b" ]; then
    if put_back; then
      rm -f "$MARK"
      echo "Done: the version you had before is back. Double-click \"Start KBB Ads Autopilot\" again."
    else
      echo "Some files could not be put back. Nothing is lost: the newer files are in the .update folder."
    fi
    read -r -p "Press Return to close." _
  fi
  exit "$CODE"
done
