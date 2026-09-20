# Going live on extrabeauty.ae · the short version

The whole move, in order, with nothing in it that can be skipped. No staging
site — you are testing on the live address, which is fine and is what this
runbook assumes.

---

## What moves, and what does not

`extrabeauty.ae` is a **different Hostinger account — a different server.** So
this is a real migration: files and database both.

**It is a copy, not a move.** Nothing here touches the old site. It keeps
serving customers the whole time, and you can test the new one fully before you
send anyone to it. If the new one is wrong, you have lost nothing.

### ⚠ The one thing that cannot be undone

> **Keep `APP_KEY` in `.env` exactly as it is. Do not generate a new one.**

Your Stripe keys and your SMTP password are stored **encrypted** in the database
(`PaymentProvider`, `MailCredential`), and `APP_KEY` is what decrypts them. A new
key does not "log you out" — it makes those rows permanently unreadable, and the
symptom is payments and email silently failing on a shop that otherwise looks
fine.

`install.php` handles this correctly on its own: when it sees a database that
already holds a shop it **refuses to continue** until you paste the original
`APP_KEY` in, and it never generates a new one in that case. So the rule is
really just: have the old key to hand when you run it, and never let anything
run `php artisan key:generate`.

### The five things

| # | what | how it gets there | size |
|---|---|---|---|
| 1 | **database** | phpMyAdmin export → phpMyAdmin import | tens of MB |
| 2 | **application code** | download the repo as a zip from GitHub | ~30 MB |
| 3 | **`.env`** | **written for you by `install.php`** | — |
| 4 | **`vendor/`** | **rebuilt on the new server** — not transferred | — |
| 5 | **web root** | copy from the old account | small, unless you have uploads |

**`vendor/` is the one that would otherwise ruin your day.** It is ~560 MB and
about 15,000 small files. Downloading and re-uploading that over FTP between two
accounts is slow, and a single file arriving truncated gives you a broken site
with no obvious cause and no shell to diagnose it with.

You do not have to. `run-composer.php` in the app folder exists precisely for a
host with no shell — it runs Composer from a **cron job** and builds `vendor/`
from `composer.lock`, so you get byte-correct dependencies for the exact versions
this app is pinned to. It is how this site was first installed.

### ⛔ Do not run `kbb-finish.php`

It is the **fresh-install** script. It runs `db:seed` and creates a new owner
account, and one of its steps is `key:generate`. On a migration that is the
opposite of what you want — you are bringing a real database with real orders.
It is in the folder; leave it alone.

---

## The move, in order

**1 · Apply 2.60.224 on your CURRENT site first.** Store → Core Updates. This
keeps the code and the database in step — the repo zip you are about to download
is at 2.60.224, and applying it now writes the matching row in `update_releases`.
It also gives you the Site address screen you were looking for (§4.0).

**2 · Export the database.** Old account → hPanel → Databases → phpMyAdmin →
select the shop's database → **Export** → Quick → SQL → Go. Save the `.sql` file.
If it is very large, choose **Custom** and set compression to **gzip**.

**3 · Create the database on the new account.** hPanel → Databases → create a
new MySQL database and user. **Write down the database name, user and password** —
they will be different from the old ones, and you type them into the installer at step 9.

**4 · Import.** New account → phpMyAdmin → select the new database → **Import** →
choose your file → Go. If the file is too big for the import limit, re-export it
gzipped.

**5 · Upload the application code.** On GitHub open the branch
`claude/kind-mayer-rpqesv` → **Code ▾ → Download ZIP**. In the new account's File
Manager, create the app folder — e.g.

```
domains/extrabeauty.ae/kbb-app/
```

— upload the zip **into it** and use File Manager's **Extract**. Make sure the
files land directly in `kbb-app/` (`kbb-app/app`, `kbb-app/config`, …) and not
inside an extra `kbbstore-claude-.../` folder; GitHub zips add one, so move the
contents up a level if so.

⚠ **Put this folder OUTSIDE `public_html`.** It holds `.env`. If it sits inside
the web root, anyone can fetch your database password.

**6 · Build `vendor/`.**

  a. Download `composer.phar` from <https://getcomposer.org/download/> and upload
     it into `kbb-app/` beside `composer.json`.
  b. hPanel → Advanced → **Cron Jobs** → add a job that runs **every minute**:

```
php /home/<your-new-user>/domains/extrabeauty.ae/kbb-app/run-composer.php
```

  c. Wait. It takes a few minutes and works in two passes. Watch progress in
     `kbb-app/storage/logs/composer-run.log` — it ends with `DONE: vendor/ created`.
  d. **Delete the cron job** once `vendor/` exists. It is a one-time task.

**7 · The web root.** Copy the contents of the old
`public_html/kbb-upgrade/` into the new account's
`domains/extrabeauty.ae/public_html/` — `index.php`, `install.php`,
`build/`, `favicon.ico`, `kbb-recover.php`, `kbb-doctor.php`, and `wp-content/`
if it is there.

`install.php` is in the repo zip you just extracted, at
`kbb-app/public-web-root/install.php`. Copy it into the web root too — it is the
next step.

If `public_html/kbb-upgrade/wp-content/uploads` exists and is large, that is your
product photographs — see §3, and do not delete the old site until you have dealt
with them.

**8 · Install the SSL certificate** for `extrabeauty.ae` and `www.extrabeauty.ae`.
Before the next step, because the installer asks for your address with `https://`
in it.

**9 · Open `https://extrabeauty.ae/install.php`** and answer five screens.

That is the rest of the job. It writes `.env` for you, points the application at
the right folders, runs every migration with a progress bar, and — because you
imported a database that already holds your shop — **skips the starter content
and keeps your orders**.

Two things it will ask that are worth knowing in advance:

- **The setup key.** It writes a random key into
  `kbb-app/storage/INSTALL-TOKEN.txt` and asks you to paste it back. That proves
  you are the person with File Manager access and not somebody who happened to
  find the page first. Open the file, copy the first line.
- **Your original `APP_KEY`.** Because your database already holds a shop, the
  installer will ask for it and will not continue without it. Copy it from the
  OLD account's `kbb-upgrade-app/.env`, exactly, starting with `base64:`. §0
  explains why this one matters more than anything else on this page.

When it finishes it deletes itself and shows you the address to sign in at. Your
admin URL and password are unchanged — they came across in the database.

**10 · Walk §2.**

You do not need to run migrations by hand, and you do not need to edit
`bootstrap/app.php` — the installer wrote both paths for you (§0.1).

---

Read §0 if you want to know what the installer wrote, or if something looks wrong.

---

## 0. Two things that are not in the admin panel

Everything else here you can do from a screen. These two you cannot, and one of
them can stop the site booting, so do them deliberately.

> **Both of these are now done for you by `install.php` (step 9).** They are kept
> here because they are what it writes, and because if anything ever looks wrong
> these two are the first places to check.

### 0.1 `bootstrap/app.php` — the web root

Line 149 of `kbb-app/bootstrap/app.php` on the **new** account reads, as it came
from the repo:

```php
->usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade');
```

That is the OLD server's path. Change it to the new one:

```php
->usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/<your-new-user>/domains/extrabeauty.ae/public_html');
```

Find `<your-new-user>` in the new account's File Manager — it is in the path bar
at the top, and it is **not** `u815237650`; that is the old account.

⚠ **No update package can ever change this line.** `bootstrap/` is on
`BuildPackage::NEVER_SHIP` and `UpdateGuard`'s forbidden list, deliberately, so
this is a File Manager edit. Copy the file to `app.php.bak` first. Get it wrong
and the site will not boot — and because the updater lives inside the site, the
updater will not boot either. `kbb-recover.php` is then the way back.

### 0.2 `.env`

Written by the installer at step 9. What it puts in, and what matters:
(`APP_KEY` is the original one you pasted in, never a new one.)

```ini
APP_URL=https://extrabeauty.ae
KBB_BASE_PATH=
DB_DATABASE=<new>
DB_USERNAME=<new>
DB_PASSWORD=<new>
```

`APP_URL` is the one that matters most after the key. **Every redirect this shop
writes is built from it** — that changed in 2.60.223 — along with password-reset
links and payment webhooks. It must carry `https://` and no trailing slash.

`KBB_BASE_PATH` must be **empty**. It is `/kbb-upgrade` today and it prefixes
every single route. At a domain root there is no prefix. Leaving it set is the
single most likely cause of "every link is wrong".

---

## 1. Clearing the compiled caches

The ordered steps are above, under **The move, in order**. This section is the
one step you will come back to, because it is the fix for most of what goes
wrong afterwards — and it is safe to repeat at any time.

In File Manager, inside `kbb-app/bootstrap/cache/` on the new account, delete
everything except `.gitignore`:

```
bootstrap/cache/config.php
bootstrap/cache/routes-*.php
bootstrap/cache/services.php
bootstrap/cache/packages.php
```

They are rebuilt automatically on the next page load. **Until they are gone the
old base path is still baked in**, and the symptom is a site that looks moved but
still links to `/kbb-upgrade/` everywhere.

**5 · Load the shop.** `https://extrabeauty.ae`. Then the admin. If the admin
loads, you are past the part that can go badly.

**6 · Walk the checklist in §2.**

---

## 2. What to check, in the order things actually break

| # | check | if it is wrong |
|---|---|---|
| 1 | home page loads over https | §0.1 web root, or the SSL certificate is not issued yet |
| 2 | a product page loads | route cache — §1 |
| 3 | links in the header have **no** `/kbb-upgrade/` | `KBB_BASE_PATH` is not empty |
| 4 | product images appear | see §3 |
| 5 | admin login works | if not: `kbb-recover.php` |
| 6 | add to cart → checkout loads | session cookie domain |
| 7 | place a real test order, card + COD | Stripe keys are live, webhook URL is not |
| 8 | the order confirmation email arrives, and its links point at extrabeauty.ae | `APP_URL` |
| 9 | `https://extrabeauty.ae/robots.txt` and `/sitemap.xml` answer | — |
| 10 | View Source on a product: `<link rel="canonical">` says extrabeauty.ae | `APP_URL` |

**Stripe needs a visit of its own.** The webhook endpoint in your Stripe
dashboard still points at the old address. Payments will appear to work and the
order status will never update. Repoint it to
`https://extrabeauty.ae/<your webhook path>` and send a test event.

---

## 3. Images

Your product images are still hot-linked to the old WordPress server — the
importer copied absolute URLs, which is recorded in the plan as a known state.
They will keep working while the old site is up.

**Do not switch the old site off until you have run Store → Import → Addresses &
pictures → fetch, and then apply.** Two steps, and between them the picture is on
disk while the product still names the old host. That is in the plan as its own
open item and it has not changed.

---

## 4. The old address

### 4.0 "I cannot see Platform → Site address"

Correct — **it does not exist on your site yet.** The screen ships *inside*
package 2.60.224. Until that package is applied there is nothing to see, and no
amount of looking or refreshing will produce it.

To get it:

1. Store → **Core Updates**.
2. Upload `kbb-update-2.60.224.zip`.
3. **Check** → it lists 13 files → **Apply**.
4. Wait for it to finish and report the migrations as run. Two of them ship with
   this package: one adds the four settings, one clears the compiled caches.
5. Reload the admin. **Platform → Site address** is now the fourth row under
   Platform, below Settings.

If it is still missing after step 5, the compiled view cache did not clear —
delete everything inside `kbb-app/bootstrap/cache/` except `.gitignore`
and reload. That is §1, and it is safe to repeat at any time.

### 4.1 Forwarding kbeautybliss.com

You do not need this to go live, and on day one you should leave it alone.

When you are ready to retire `kbeautybliss.com`:

1. Point `kbeautybliss.com` at the same server.
2. Settings → Site address → Main address: `extrabeauty.ae`.
3. Old addresses to forward here: `kbeautybliss.com`.
   The `www` pair is handled for you — you do not type it.
4. Tick **Forward these, permanently**. Save.
5. File a **Change of Address** in Google Search Console. Without it you restart
   your search ranking from zero, which is the thing the 54 redirects in
   2.60.223 exist to protect.
6. **Keep renewing the old domain forever.** Letting it lapse hands your
   backlinks to whoever buys it next.

Only addresses you type into that box are ever forwarded, so a typo there cannot
make `extrabeauty.ae` unreachable. That is deliberate — there is no shell on this
host, and a redirect rule that swallowed the admin panel could not be undone from
inside the admin panel.

**Leave "Keep this install out of Google" switched OFF** on the live shop. It is
for the staging site and the console later.

---

## 5. If it goes wrong

**The site loads but every link is wrong** → `KBB_BASE_PATH` is not empty, or
`bootstrap/cache/` was not cleared. Both are reversible in a minute.

**The site does not boot at all** → almost certainly §0.1. Restore
`bootstrap/app.php` from the `.bak` copy you made.

**Nothing responds and you have no backup of that file** →
`https://extrabeauty.ae/kbb-recover.php?token=…`. It does not boot Laravel, which
is the entire point: it works when the application does not. Set its token before
you need it, not after.

---

## 6. What comes next, and what does not

You are pushing update packages to `extrabeauty.ae` directly for now, and testing
on it. That works and needs nothing new.

Deferred until the app is finished, in this order:

1. **The vendor domain** — landing page with the Purchase button.
2. **`console.<vendor>`** — the super-admin console, licences and releases.
3. **`staging.<vendor>`** — where a release is checked before customers see it.

The one piece of that which should not wait is **Ed25519 package signing**
(`docs/VENDOR-CONSOLE-AND-LICENSING.md` §4): packages have never actually been
signed, and the scheme in the code is symmetric, so it cannot work once anyone
else runs your code. It is entirely inside this repo and changes nothing visible
— better done now, while you are the only install, than retrofitted across fifty.
