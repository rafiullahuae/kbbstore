# Lane P2 — the three upload screens, before and after

Taken in Chromium at **1280×900** and **390×844** by
`tests/browser/lane-p2-upload-screens.mjs`, against a local `php -S` whose PHP
reads `upload_max_filesize = 2M` inside `post_max_size = 8M` — the same two
values as the live box, which is the whole point of the run.

`before-report.json` and `after-report.json` carry every measurement the shots
are evidence for. The broken image placeholders in some frames are the harness:
serving through `public-web-root/index.php` means static files are not served,
so an uploaded `<img src>` 404s. That is the router, not the screen.

## Where each screen is in the admin

| shots | screen |
|---|---|
| `*-picker-*` | the shared **Choose an image** dialog — `window.kbbPickMedia`, opened from Catalog → Products → Edit → Main image → **Choose**, and from every other image field in the console |
| `*-editor-*` | **Catalog → Products → Edit** → *Main image* / *Gallery* / *Search appearance → Share image* |
| `*-importer-*` | **Reviews → Review Import / Export → Import** |

## What to look at

| file | what it shows |
|---|---|
| `before-*-1-picker-resting` | the dialog with **no drop target at all**. Dropping a photograph on it did nothing — and outside a zone the browser navigates away to the file, taking whatever is behind the dialog with it |
| `after-*-1-picker-resting` | the dashed zone, and the ceiling read off this server: *up to 2 MB each — this server's own upload_max_filesize (2M)* |
| `after-*-2-picker-drag-over` | the **whole dialog** lit as the target, not just the box |
| `after-*-3-picker-after-drop` | two files dropped: the good one is in the library, the 3.0 MB one is refused **before sending** with the size and the directive |
| `after-*-3b-picker-in-flight` | throttled to 50 KB/s — a live bar at 16 %, the percentage, and **Stop** |
| `after-*-3c-picker-stopped` | what Stop leaves behind |
| `before-*-4-editor-resting` | one 209×70 dashed box in the gallery card, and no size anywhere on the screen |
| `after-*-4-editor-resting` | the main image card is a drop target, with the ceiling under it |
| `before-*-5-editor-share-image` | the share image: a button, a hidden input, **no drop target and no progress at all** — its upload called the uploader with no callback, so `xhr.upload.onprogress` was never attached |
| `after-*-5-editor-share-image` | a real zone, the ceiling, and the same reporting queue as the other two |
| `before-*-6-editor-gallery-drag-over` | nothing lights up |
| `after-*-6-editor-gallery-drag-over` | the whole gallery **card** lights up |
| `after-*-8b-editor-queue-in-flight` | three files: one sending at 19 % with a bar and a Stop, two `Waiting…`, and **Stop the rest** above them |
| `after-*-8c-editor-queue-stopped` | all three `Stopped` — and the screen carries on, which is the bug the throttled run found |
| `before-*-11-importer-after-drop` | a dropped CSV is **silently lost**; the next press answers *"Choose a CSV file first."* |
| `after-*-11-importer-after-drop` | the dropped file is the chosen one, named under the box |
| `after-*-13b-importer-in-flight` | a 1.5 MB CSV at 22 % with a Stop |
| `before-*-14-importer-oversized` | Laravel's own 413: **"The POST data is too large."** — no size, no ceiling, no directive |
| `after-*-14-importer-oversized` | *"That file is 9.0 MB. This server accepts 2 MB (upload_max_filesize = 2M). Split the export, or raise that limit on the server."* — and it never left the machine |

## Overflow, both widths, all three screens

`documentElement.scrollWidth` equals `clientWidth` and `#content.scrollWidth`
equals `#content.clientWidth` in every frame of both runs — 1280/1280 with
#content at 1032/1032, and 390/390 with #content at 390/390. Nothing this lane
added scrolls sideways, before or after.

## Reproducing

```bash
php artisan migrate --force && php artisan db:seed --force
export KBB_PUBLIC_PATH=$PWD/public-web-root SESSION_DRIVER=file
php -S 127.0.0.1:8972 -t public-web-root public-web-root/index.php &

KBB_P2_BASE=http://127.0.0.1:8972 \
KBB_P2_EMAIL=owner@example.com KBB_P2_PASSWORD=secret-secret \
KBB_P2_FIXTURES=/tmp/p2-fixtures KBB_P2_OUT=/tmp/p2-shots KBB_P2_TAG=after \
KBB_P2_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
node tests/browser/lane-p2-upload-screens.mjs
```

The fixtures are real PNG and CSV bytes at sizes chosen against this box's ini,
so each one exercises a different failure mode — see the header of
`tests/browser/lane-p2-upload-screens.mjs` for the sizes and how to build them.
