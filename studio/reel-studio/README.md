# KBB Studio

Your reels editor. It runs on your Mac, completely offline. Nothing goes on the internet unless the studio
asks you first and you say yes (only for the one-time download of the captions model).

## Start it

1. Copy the **KBB Studio** folder anywhere on your Mac (Documents is fine).
2. **First time only:** right-click **Start KBB Studio.command** → **Open** → **Open**.
   (macOS asks once because the studio is not from the App Store. After that a normal double-click works.)
3. A small Terminal window opens and the studio opens in your browser. **Google Chrome is recommended**
   (fastest export, iPhone HEVC videos). Safari 17 or newer also works.
4. Keep the Terminal window open while you work. When you close the studio's browser tab, the studio stops by
   itself after a few seconds. You can also press **Stop** (top right in the studio).

## Where your things are

| Folder | What is in it |
|---|---|
| `workspaces/<company>/projects/` | your saved projects, one folder each (the videos you add are copied in) |
| `workspaces/<company>/exports/` | finished MP4s |
| `workspaces/<company>/brand/` | the company's logo, intro/outro |
| `library/music`, `library/sfx` | music and sound effects (drop your own tracks here too) |
| `brands/` | the built-in brand kits |
| `models/` | the captions model, downloaded once when you allow it |

Each company (KBB, Planet Careers, any you add with **+ New company**) has its own projects, exports and brand.

## Keyboard

Space play/pause · J K L back/stop/forward · S split · Delete remove · Shift+Delete ripple delete ·
⌘Z / ⇧⌘Z undo/redo · ⌘C ⌘V ⌘D copy/paste/duplicate · ⌘G / ⇧⌘G group/ungroup · M marker ·
← → one frame · + − timeline zoom · ? all shortcuts.

## For developers

* `engine/` — Go 1.24, no dependencies. `engine/build.sh` builds `kbbstudio-mac-arm64`, `kbbstudio-mac-amd64`
  (and a Linux one for tests). The launcher picks the Mac binary by `uname -m`.
* `app/` — vanilla ES modules, no build step. `app/core/` is the contract every module codes to; `app/editor/`
  the editor. Captions (`app/captions/`) and automation (`app/auto/`) register themselves through
  `app/core/modules.js`.
* `vendor/` — pinned third-party code: mp4-muxer 5.2.2 (MIT), mp4box.js 0.5.2 (BSD-3-Clause). Licences beside them.
* `tools/` — headless Chromium checks (`node tools/check-r1.cjs`), test-clip maker, Mac folder packager.
* The app name lives in one place: `app/core/config.js` `APP_NAME` (and `appName` in `engine/main.go`).
