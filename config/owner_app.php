<?php

/*
 * The owner app (Lane MAC; read here by Lane SEC).
 *
 * KBB_OWNER_APP_PATH pins the app's secret address from .env, overriding the
 * `owner_app_path` setting. It is read HERE and nowhere else: once
 * `php artisan config:cache` has run, env() outside config/ returns null, and
 * an env-pinned address read with env() would silently fall back to the
 * settings row — a different address from the one the owner wrote down.
 */
return [
    'path' => trim((string) env('KBB_OWNER_APP_PATH', ''), '/'),
];
