# Going live on extrabeauty.ae · the short version

The whole move, in order, with nothing in it that can be skipped. No staging
site — you are testing on the live address, which is fine and is what this
runbook assumes.

---

## What you actually upload: **nothing**

This is the part worth reading twice, because the obvious assumption is wrong.

Your shop lives in **two folders** on Hostinger, not one:

| | where it is now | size |
|---|---|---|
| **the application** | `domains/easywebsol.com/kbb-upgrade-app/` | large — `vendor/` alone is ~560 MB |
| **the web root** | `domains/easywebsol.com/public_html/kbb-upgrade/` | small — `index.php`, `build/`, the recovery scripts |

Only the **web root** has to be reachable at the new address. The application
folder can stay exactly where it is, forever. And the database does not move at
all — same hosting account, same MySQL.

So there is no upload. `vendor/` never moves, which matters more than it sounds:
you have no shell, so you cannot run `composer install` to rebuild it. Moving it
by FTP and having one file arrive truncated is a broken site with no obvious
cause.

**Two ways to do it. Try A first — it moves no files whatsoever.**

### A · Point the new domain at the folder that already works  ← try this

In hPanel, add `extrabeauty.ae` and, when it asks for the folder / document
root, give it the **existing** one:

```
domains/easywebsol.com/public_html/kbb-upgrade
```

That is the whole move. Nothing is copied, `bootstrap/app.php` does not change,
and if it goes wrong you point the domain back.

### B · If hPanel will not let you choose that folder

Then copy just the **web root** — still not the application:

1. Add `extrabeauty.ae` in hPanel. It creates
   `domains/extrabeauty.ae/public_html/`.
2. In File Manager, copy **everything inside**
   `domains/easywebsol.com/public_html/kbb-upgrade/` into
   `domains/extrabeauty.ae/public_html/`.
   That is `index.php`, `build/`, `favicon.ico`, `kbb-recover.php`,
   `kbb-doctor.php`, and `wp-content/` if it is there.
3. Then, and only in this option, edit `bootstrap/app.php` — §0.1.

⚠ If `wp-content/uploads` is in there it can be large. It is your product
photographs. Copying inside File Manager is fine; do not pull it down and push
it back up over FTP.

Which option you used decides whether you do §0.1. **Option A skips it.**

---

Read §0 next. It is thirty seconds and it is the only part that is hard to undo.

---

## 0. Two things that are not in the admin panel

Everything else here you can do from a screen. These two you cannot, and one of
them can stop the site booting, so do them deliberately.

### 0.1 `bootstrap/app.php` — the web root  *(OPTION B ONLY — skip on Option A)*

Line 149 of `kbb-upgrade-app/bootstrap/app.php` reads:

```php
->usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade');
```

On **Option A you do not touch this** — the folder it names is still the folder
being served, which is exactly why Option A is the easy one.

On **Option B** change the path to the new web root:

```php
->usePublicPath(getenv('KBB_PUBLIC_PATH') ?: '/home/u815237650/domains/extrabeauty.ae/public_html');
```

Check your real username in hPanel's File Manager address bar — `u815237650` is
what the file says today, but confirm rather than trust it.

⚠ **No update package can ever change this line.** `bootstrap/` is on
`BuildPackage::NEVER_SHIP` and `UpdateGuard`'s forbidden list, deliberately, so
this is a hand edit through File Manager. Copy the file to `app.php.bak` before
you touch it. If you get it wrong the site will not boot, and because the
updater lives inside the site, the updater will not boot either — `kbb-recover.php`
is then the way back.

### 0.2 `.env`

```ini
APP_URL=https://extrabeauty.ae
KBB_BASE_PATH=
```

`APP_URL` is the one that matters most. **Every redirect this shop writes is
built from it** — that changed in 2.60.223 — along with password-reset links and
payment webhooks. It must carry `https://` and no trailing slash.

`KBB_BASE_PATH` must be **empty**. It is `/kbb-upgrade` today and it prefixes
every single route. At a domain root there is no prefix. Leaving it set is the
single most likely cause of "every link is wrong".

---

## 1. The order to do it in

**1 · Point the domain.** hPanel → Domains → add `extrabeauty.ae`, folder as per
Option A or B above. Wait for it to resolve before going further — opening
`http://extrabeauty.ae` on your phone's mobile data is enough of a check.

**2 · Issue the SSL certificate.** hPanel → Security → SSL → install for
`extrabeauty.ae`. Make sure it covers **both** `extrabeauty.ae` and
`www.extrabeauty.ae`. Do this *before* step 3: `APP_URL` says `https://`, and a
site pointed at a certificate that does not exist yet shows every visitor a
security warning.

**3 · Make the edits in §0.** `.env` always; `bootstrap/app.php` only on
Option B.

**4 · Delete the compiled caches.** In File Manager, inside
`kbb-upgrade-app/bootstrap/cache/`, delete everything except `.gitignore`:

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
| 1 | home page loads over https | §0.1 web root, or AutoSSL not finished |
| 2 | a product page loads | route cache — §1 step 4 |
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
delete everything inside `kbb-upgrade-app/bootstrap/cache/` except `.gitignore`
and reload. That is the same step as §1.4 and is safe to repeat at any time.

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
