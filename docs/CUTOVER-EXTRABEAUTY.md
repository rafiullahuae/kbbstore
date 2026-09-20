# Going live on extrabeauty.ae · the short version

The whole move, in order, with nothing in it that can be skipped. No staging
site — you are testing on the live address, which is fine and is what this
runbook assumes.

Read §0 first. It is thirty seconds and it is the only part that is hard to
undo.

---

## 0. Two things that are not in the admin panel

Everything else here you can do from a screen. These two you cannot, and one of
them can stop the site booting, so do them deliberately.

### 0.1 `bootstrap/app.php` — the web root

The last line of that file is:

```php
->usePublicPath('/home/.../public_html/kbb-upgrade')
```

It must point at whatever folder the new domain actually serves. On cPanel that
is usually `/home/<user>/public_html` for a main domain, or
`/home/<user>/extrabeauty.ae` if you added it as an addon domain — the **Document
Root** column in cPanel's Domains screen tells you exactly.

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

**1 · Point the domain.** In cPanel, add `extrabeauty.ae` and set its document
root. Wait for it to resolve before going further — `ping extrabeauty.ae` from
your phone is enough.

**2 · Issue the SSL certificate.** cPanel → SSL/TLS Status → Run AutoSSL. Make
sure it covers **both** `extrabeauty.ae` and `www.extrabeauty.ae`. Do this
*before* step 3: `APP_URL` says `https://`, and a site that redirects to a
certificate that does not exist yet shows every visitor a security warning.

**3 · Make the two edits in §0.** Web root first, then `.env`.

**4 · Delete the compiled caches.** In File Manager, delete everything inside
`bootstrap/cache/` except `.gitignore`:

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

Package 2.60.224 adds **Settings → Site address**. You do not need it to go live,
and on day one you should leave it alone.

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
