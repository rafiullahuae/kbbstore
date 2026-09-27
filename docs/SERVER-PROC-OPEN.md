# `proc_open`, ffmpeg, and why a cover will not cut

Companion to `docs/UPLOAD-LIMITS.md`. Same server, same SSH session, **different
kind of setting** — and the difference is the whole reason this file exists.

## 1. The symptom, and what it is not

Uploading a video to Content → Shoppable video answered **500** with the body
`Server Error`, while the clip itself appeared after a refresh as a draft with
**"No cover yet"**.

That is not a size limit and not a broken file. The video is written and the row
is saved *before* the cover and teaser are cut; the cut then fails, and — before
2.60.291 — took the whole response down with it. The upload had already
succeeded every time.

**Root cause.** `Symfony\Component\Process\Process::__construct()` opens with:

```php
if (!\function_exists('proc_open')) {
    throw new LogicException('The Process class relies on proc_open, ...');
}
```

Many managed hosts put `proc_open` in `disable_functions` as a hardening
default. `UgcTranscoder` built its process *outside* the `try` that guarded it,
so nothing caught that. Reproduced exactly:

```sh
php -d disable_functions=proc_open -r 'require "vendor/autoload.php";
  new Symfony\Component\Process\Process(["/bin/true"]);'
# PHP Fatal error: Uncaught Symfony\...\LogicException: The Process class
# relies on proc_open, which is not available on your PHP installation.
```

▲ **ffmpeg being installed does not help.** It is a real file, so the binary
check passed and the screen promised a cover the box could never cut. Since
2.60.291 the app asks the second question too and says which of the two it is.

## 2. Confirm it on this shop before changing anything

**Without SSH** — Content → Shoppable video → All clips → a clip → step 2. The
cover panel names the reason:

- *"This server has no ffmpeg"* — a different problem; this file will not help.
- *"ffmpeg is installed but PHP may not start it"* — **that is this file.**

**With SSH — read the LOG, not `php -i`.** This was got wrong once already: a
bare `php -i | grep disable_functions` over SSH answered "no value", which is
the **CLI** PHP and says nothing about the PHP-FPM process that serves the site.
The two routinely differ on managed hosts, and taking the CLI answer for the
web answer is the same trap §6 records for verification.

The decisive reading is the exception the shop actually threw:

```sh
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
grep -nE 'ugc-upload|proc_open|UgcTranscoder|Symfony.*Process' storage/logs/laravel.log | tail -20
```

- `LogicException: The Process class relies on proc_open` → it is this file.
- **Anything else → it is NOT this file, and the rest of these steps are wasted
  effort.** Bring the line back; the class name names the real cause.

From 2.60.291 the same answer is one line, with no guessing at the cause:

```sh
grep '\[ugc-upload\]' storage/logs/laravel.log | tail -1
```

whose `can_spawn`, `ffmpeg` and `exception` keys settle it together.

If you want the FPM ini directly rather than the log, find the file the **FPM**
SAPI loads rather than assuming a path:

```sh
grep -rn '^[[:space:]]*disable_functions' /etc/php/*/fpm/php.ini /etc/php/*/fpm/conf.d/ 2>/dev/null
```

## 3. ▲ `.user.ini` CANNOT fix this. Do not spend an afternoon on it.

`upload_max_filesize` and `post_max_size` are `PHP_INI_PERDIR`, which is why a
`.user.ini` in the web root moves them (UPLOAD-LIMITS.md §3, route 2).

**`disable_functions` is `PHP_INI_SYSTEM`.** It is settable only in `php.ini` or
in an FPM pool, never by `.user.ini`, never by `.htaccess`, never at runtime.
Proven rather than recalled:

```sh
php -r '$r = @ini_set("disable_functions", ""); var_dump($r);'
# bool(false)   <- refused
```

A package cannot ship the fix either: `UpdateGuard::ALLOWED_PREFIXES` is `app/`,
`config/`, `database/migrations/`, `database/seeders/`, `resources/`, `routes/`,
`public/build/`. No php.ini, no pool file, no `.user.ini` at the app root.

**This is a server change. It has to be made on the server.**

## 3b. ▲ THE EASIEST ROUTE OF ALL — one cron line, and no PHP change at all

**Read this before routes 1 and 2.** Neither of them is necessary if all you
want is the cover and the teaser.

The reason is the fact §2 established: `proc_open` is switched off in the
**PHP-FPM pool** that serves the shop, and it is switched **on** in the
**command line** PHP on the very same machine. So a cover the browser cannot
cut can be cut by a scheduled command, and nothing on the server has to change.

From 2.60.295 the application schedules that itself. All it needs is Laravel's
single scheduler entry, which is one line and drives every future scheduled
task as well:

```
* * * * * cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app && php artisan schedule:run >> /dev/null 2>&1
```

**Where to put it on Cloudways:** Application Settings → **Cron Job Management**
→ Add New Cron → **Advanced**, and paste the line above. Basic mode's dropdowns
cannot express `* * * * *` with a `cd` in front of it.

What then happens, with no further action:

1. You upload a clip. It saves, and the cover cannot be cut in the request —
   exactly as now.
2. Within a minute the scheduled run finds the clip, cuts the poster and the
   2.5-second teaser, and records the duration and dimensions.
3. Reload the clip and the cover is there.

**Do not add a second cron line for `ugc:cut-covers` itself.** The schedule is
decided inside PHP; a second entry would run the work twice.

**If you never add the cron line**, nothing breaks — you just cut the covers
yourself with `php artisan ugc:cut-covers` when it suits you, or upload a
poster image by hand.

**One honest limit:** this is a *shared* machine and the transcode is real work.
The scheduled run takes at most 20 clips a minute and holds a lock so two runs
cannot overlap. After a bulk import of hundreds of clips the covers therefore
arrive over several minutes rather than all at once. That is deliberate.

---

## 4. Route 1 — Application Settings → PHP-FPM Settings (per application)

**Applications → (your app) → Application Settings → PHP-FPM Settings.** That
box takes pool directives, and a pool `php_admin_value` CAN set a
`PHP_INI_SYSTEM` directive.

Set `disable_functions` to the existing list **with `proc_open` removed** —
read the current value first (§2) and copy it, rather than blanking it. Blanking
it re-enables everything the host disabled on purpose:

```
php_admin_value[disable_functions] = "<the current list, minus proc_open>"
```

Save, and let Cloudways restart the pool.

⚠ Cloudways moves these labels between releases. If "PHP-FPM Settings" is not
where this says, look for the PHP settings on the same Application Settings
page.

## 5. Route 2 — the FPM `php.ini`, over SSH

Find the file that is actually loaded by the **FPM** SAPI rather than assuming a
path — versions and layouts differ between Cloudways stacks:

```sh
ls /etc/php/*/fpm/php.ini
grep -rn '^disable_functions' /etc/php/*/fpm/php.ini /etc/php/*/fpm/conf.d/ 2>/dev/null
```

Edit the line found, remove `proc_open` from the comma-separated list, keep the
rest, then restart the pool (Cloudways: **Servers → Manage Services → PHP FPM →
Restart**, or the panel's Application restart).

## 6. Verify — and verify the right process

`php -i` over SSH reads the **CLI** ini and can disagree with PHP-FPM by a wide
margin. The authoritative check is the app:

1. Reload Content → Shoppable video → All clips → a clip → step 2.
2. The "ffmpeg is installed but PHP may not start it" note should be gone.
3. Upload a video. The cover and the 2.5-second loop cut themselves on the
   upload request.

Nothing needs re-uploading for clips already there: step 2 has a **Cut the cover
and teaser from the video** button that runs the same code on demand.

## 7. If the host will not allow it

Nothing breaks, and the shop stays fully usable. Since 2.60.291:

- the upload succeeds and the video serves;
- the screen says the cut is not possible here rather than failing;
- a **cover is chosen by hand** from the Media Library, which is the only file
  actually required to publish;
- the 2–3 second loop needs no second file — the tile loops the start of the
  full video, so a missing teaser costs the shopper bytes, not the feature.
