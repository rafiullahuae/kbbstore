# Reshooting the picture slider — Lane BN2

Everything in `docs/lane-bn2-shots/` was made with these two scripts, the
preview router in `tests/browser/preview-router.php`, and the two harnesses
`tests/browser/lane-bn2-slider.mjs` and `tests/browser/lane-bn2-admin.mjs`.

```bash
# 1. a database and a web root of this shoot's own
export DB_CONNECTION=sqlite DB_DATABASE=$PWD/storage/bn2-logs/preview.sqlite
export APP_ENV=local APP_DEBUG=true SESSION_DRIVER=file CACHE_STORE=file
export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export KBB_PUBLIC_PATH=$PWD/public-web-root APP_URL=http://127.0.0.1:8731
mkdir -p storage/bn2-logs && : > "$DB_DATABASE"
php artisan migrate --force
cp -al public/build public-web-root/build      # the web root is NOT public/

# 2. five pictures and one published slider set, chosen for the homepage
php tools/bn2-shots/seed.php inset

# 3. the preview
php -S 127.0.0.1:8731 -t public-web-root tests/browser/preview-router.php &

# 4. one treatment at a time
for st in inset outside veil corner; do
  php tools/bn2-shots/style.php $st
  KBB_BN2_BASE=http://127.0.0.1:8731 KBB_BN2_STYLE=$st \
  KBB_BN2_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
  KBB_BN2_SHOTS=$PWD/docs/lane-bn2-shots node tests/browser/lane-bn2-slider.mjs
done

# 5. the behaviour probe: arrows, bars, keyboard, autoplay, hover, the pause
#    button, a mouse swipe, a touch swipe, a downward touch drag, and reduced
#    motion. Add KBB_BN2_PATH=/ar for the Arabic run.
KBB_BN2_PROBE=1 ... node tests/browser/lane-bn2-slider.mjs

# 6. the admin screen. KBB_BN2_EMAIL / KBB_BN2_PASSWORD are an AdminUser with
#    role=owner created against the same database.
node tests/browser/lane-bn2-admin.mjs
```

Two things that cost time and are not obvious:

- **`php tools/bn2-shots/style.php <look> ar` publishes the Arabic drafts.**
  `ArabicInterfaceDrafts` is a SOURCE, not a seed: a fresh database has none of
  its rows, so `/ar` renders the English interface for the whole shop and the
  Arabic screenshot proves nothing.
- **`php artisan cache:clear` after that publish.** `TranslationStore` caches
  its map and a mass `update()` bypasses the model hook that would have evicted
  it, so the next request still believes the shop is English.
