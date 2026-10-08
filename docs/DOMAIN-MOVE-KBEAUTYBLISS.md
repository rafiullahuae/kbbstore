# Moving the shop from extrabeauty.ae to kbeautybliss.com

The owner, 6 October 2026: *"I'm going to change the domain for my app, please
prepare a complete setup process, must not break anything in any case. i want to
connect the original domain to this app. make sure nothing should be break, i
want zero dependency of the old domain (extrabeauty.ae) if i change the app to
new domain. don't assume on anything."*

This is the runbook for that move, in the order you do it. Every step says where
it is (admin path, SSH command, or which outside website). Anything that could
not be checked from the code is marked **CHECK:** — it is a question for you or
a thing to look at, never a guess written as a fact.

**What does not change:** the server, the files, the database, the admin
password, `APP_KEY`, the web root folder. The app finds its web root by folder
path, not by domain name (`bootstrap/app.php` reads `KBB_PUBLIC_PATH` or
`bootstrap/public-path.php`), so nothing on disk moves.

**What changes:** where `kbeautybliss.com` points (DNS), the certificate, five
settings in this admin, and the addresses registered at Stripe, Tabby, Tamara,
Meta and Google.

**One tool to measure it, before and after:**

```bash
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae
```

Read-only — it writes nothing and fetches nothing. It checks the settings below
and reads every text column of every table for addresses on either domain.
**RISK** lines break on the switch (or keep you depending on extrabeauty.ae);
**TODO** lines are switch-day settings. It exits 0 only when RISK is 0. Add
`--csv=storage/app/domain-check.csv` for a spreadsheet of every finding.

---

## A · Before the day (a week to a day ahead)

### A1 · Answer these first — the plan depends on them

Run these on the Cloudways SSH terminal (Servers → your server → Launch SSH
Terminal) and keep the output:

```bash
dig +short NS  kbeautybliss.com     # who runs the DNS
dig +short MX  kbeautybliss.com     # who runs info@ email
dig +short A   kbeautybliss.com     # the WordPress server's IP — write it down, it is the rollback
dig +short AAAA kbeautybliss.com    # IPv6 — if anything comes back, see B3
dig +short TXT kbeautybliss.com     # SPF and verification records
dig +short CNAME www.kbeautybliss.com ; dig +short A www.kbeautybliss.com
dig +short CAA kbeautybliss.com     # if this lists issuers, letsencrypt.org must be one of them
```

(If `dig` is missing, `host -t NS kbeautybliss.com` does the same.)

| # | Question | Why it matters |
|---|---|---|
| Q1 | **Where is the DNS for kbeautybliss.com managed?** (the `NS` answer: the registrar, Cloudflare, or the WordPress host) | That is where you make the change on the day. **If the DNS is hosted by the WordPress hosting company, do not cancel that hosting** until the DNS has been moved somewhere you control — cancelling it deletes the DNS, and with it your email. |
| Q2 | **Where is info@kbeautybliss.com hosted?** (the `MX` answer: Google Workspace, Microsoft 365, Zoho, or the WordPress host's own mail) | Email must survive. Only the `@` and `www` web records change; MX does not. But see Q3. |
| Q3 | **Does any MX record point at `kbeautybliss.com` itself** (`MX 0 kbeautybliss.com`), or at `mail.kbeautybliss.com` that is an A record to the WordPress server? | Common on cPanel hosts. If MX points at the bare domain, changing the bare domain's A record to Cloudways **sends your email to Cloudways and it is lost**. Fix first: create `mail.kbeautybliss.com` A → the current mail server IP, change MX to `mail.kbeautybliss.com`, wait a day. If MX is Google/Microsoft/Zoho, nothing to do. |
| Q4 | **Does the SPF record (`v=spf1 …`) use `a`** or `mx`? | `a` means "the server the website is on may send mail". After the switch that is Cloudways, not the old host. If the old host sends your info@ mail, replace `a` with `ip4:<old server IP>`. |
| Q5 | **Is Cloudflare (orange cloud) in front of kbeautybliss.com?** | Then: SSL mode must be **Full (strict)**, and Let's Encrypt on Cloudways may need the record grey-clouded while the certificate is issued. **CHECK** with Cloudways. |
| Q6 | **Is WordPress still taking orders or customer sign-ups today?** When was the last import into this shop? | If yes, a final import (A4) is needed, and WooCommerce should stop taking orders for the switch hour. |
| Q7 | **Does Google Merchant Center, Meta Commerce, or any marketplace read a product feed from kbeautybliss.com?** (a WooCommerce feed plugin, usually a URL under `/wp-content/uploads/…` or `/?feed=…`) | That feed disappears with WordPress, and **this shop has no product-feed URL** (searched: no feed route exists). The catalogue there stops updating. Tell me before the day if one exists. |
| Q8 | **Which email sender does this shop use?** Emails → Sending & delivery | If the From address is empty, the shop sends as `no-reply@<APP_URL domain>` (`MailSettings.php:788`) — that becomes `no-reply@kbeautybliss.com` on the day, and the SPF/DKIM of kbeautybliss.com must allow the sender, or order emails land in spam. Simplest: type an explicit From you already send from (e.g. info@kbeautybliss.com via Google Workspace) and send a test now. |
| Q9 | **Is extrabeauty.ae verified in Google Search Console?** Is kbeautybliss.com a **Domain property** (DNS TXT) or a **URL-prefix property** (HTML tag/file)? | See A6. |
| Q10 | **Any other names on kbeautybliss.com** (blog., shop., cdn., an app, a QR code printed with a deep link)? | Leave their DNS records untouched. Tell me if any of them pointed at WordPress. |
| Q11 | **Is the owner app on its own host** (Platform → Users & Roles → Owner app → Security → Own host)? | If it is `owner.extrabeauty.ae`, it moves to `owner.kbeautybliss.com` (needs a DNS record) or is cleared. Every phone signs in again either way. |

### A1b · The owner's answers (7 October 2026)

Measured by the owner over SSH:

| | value | meaning |
|---|---|---|
| Registrar | **Internet.bs** | renews the domain, controls the nameservers |
| Nameservers | `orbit.dns-parking.com`, `horizon.dns-parking.com` | **Hostinger DNS** — the records are edited in the Hostinger account |
| `@` A | `177.202.242.149` | old WordPress at Hostinger — **the rollback value** |
| `@` AAAA | `2a02:4780:67:35:9db0:736d:3781:4b41` | WordPress at Hostinger over IPv6 — **delete on the day** |
| MX | `1 smtp.google.com.` | **Google Workspace** mail, independent of the website |
| TXT | `v=spf1 include:_spf.google.com ~all` | Google only — add the shop's sender if it is not Google (Q8) |
| CAA | none | Let's Encrypt may issue |
| `www` | CNAME `kbeautybliss.com.` | follows `@`; no change |

So on the day, in **Hostinger → Domains → kbeautybliss.com → DNS**: edit the `@`
A record to the Cloudways IP and delete the `@` AAAA record. Nothing at Internet.bs.

**Do not cancel the Hostinger plan** after the switch: the DNS zone, including
Google Workspace's MX, lives in that account. Move DNS off Hostinger later, as
its own step (Cloudflare suggested): copy every record (MX, SPF,
`google._domainkey`, `_dmarc`, verifications) first, verify, then change the
nameservers at Internet.bs.

**The owner will delete the domain from Hostinger, so DNS moves to Internet.bs,
in two steps 48 h apart.** (1) Recreate every record in Internet.bs → DNS
Management with `@` A still `177.202.242.149` (TTL 300), `www` CNAME,
MX `1 smtp.google.com`, the SPF TXT, and every record found under
`google._domainkey`, `_dmarc` and any other name in the Hostinger panel — no
AAAA. Switch the nameservers at Internet.bs to Internet.bs's own. Wait ≥ 48 h;
verify NS, MX and a test email to info@. (2) On switch day change only the
`@` A record at Internet.bs to the Cloudways IP. Delete the domain from
Hostinger only after (2) has run cleanly for a few days.

### A2 · Export the whole DNS zone, and lower the TTL

At the DNS host from Q1: **export / screenshot every record** of kbeautybliss.com
(A, AAAA, CNAME, MX, TXT, SRV, CAA). This is your rollback and your proof of what
was there.

**One day before:** set the TTL of the `@` and `www` records to **300 seconds**
(5 minutes). Leave every other record's TTL alone. This makes the switch — and a
rollback — take minutes instead of hours.

### A3 · Bring every picture and video across while WordPress still answers

After the DNS change, `https://kbeautybliss.com/wp-content/uploads/…` is served
by THIS server, from `public_html/wp-content/uploads/`. A file that is there keeps
working at the same address; a file that is not there 404s, and **WordPress is no
longer reachable to fetch it from**. So this must finish before B3.

**Do it in this order — the order matters.** The video fetcher refuses to fetch
from a host it believes is this shop, and it counts the *main address* as this
shop (`DescriptionVideoFetcher.php:320`). Once B1 sets the main address to
kbeautybliss.com, it will no longer fetch from it.

```bash
php artisan kbb:import-media --verdict=remote                     # what is still on WordPress
php artisan kbb:import-media-fetch --host=kbeautybliss.com        # copy them (repeat until nothing is left)
php artisan kbb:import-media-rewrite --host=kbeautybliss.com      # look
php artisan kbb:import-media-rewrite --host=kbeautybliss.com --write
php artisan kbb:fetch-description-videos --plan                   # videos named in descriptions
php artisan kbb:fetch-description-videos
```

The same pictures step is in the admin: Store → Store Import / Export →
Addresses & pictures → Pictures → **Bring these across**.

Any one-click "copy from kbeautybliss.com" tools you still want (Catalog →
Categories → Copy hierarchy; the Super Sale order copy) must also be used now:
after the switch they read this shop, not WordPress.

**Proof:** `php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae`
must show **no RISK line ending "picture/video addresses on kbeautybliss.com"**.
Links to kbeautybliss.com *pages* are INFO, not RISK — after the switch this shop
answers them (old category, brand, product and article addresses are forwarded;
see E3).

### A4 · Final WordPress import (only if Q6 = yes)

Orders, customers and reviews placed on WordPress since the last import. On
kbeautybliss.com: Tools → KBB Export → tick only what changed (customers before
orders — the screen enforces the order) → download each group's zip. Then here:
Store → Store Import / Export, upload them in the order the export screen lists,
or over SSH:

```bash
php artisan kbb:import --dir=storage/app/woo-final --dry-run --rejects=storage/app/rejects.csv
php artisan kbb:import --dir=storage/app/woo-final
```

`docs/IMPORT-RUNBOOK.md` §1 and §9b have the details. Delete the export folder
from WordPress afterwards (it holds password hashes).

For the switch hour, stop WordPress taking orders (a WooCommerce maintenance or
catalogue-mode plugin). **CHECK:** which plugin, if any, is installed there.

### A5 · Nothing left that names extrabeauty.ae

`php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae` lists every
stored link, picture address, email address or mention of extrabeauty.ae —
menus, banners, redirect rows, pages, settings (a robots.txt override, for
example), blocks, products. Edit each one to a relative address (`/collections/…`)
or to kbeautybliss.com. Get **RISK 0** before the day.

Rows in "a record of the past" (orders, sent mail, logs) are listed as INFO
only: they are history and need nothing.

### A6 · Keep Search Console (and Bing, Pinterest, Meta) verified

**This is easy to miss.** If kbeautybliss.com is verified in Search Console by an
HTML tag or an HTML file that WordPress serves (Yoast or a plugin, or a
`google….html` file in the WordPress root), that verification **disappears on the
day** — WordPress is no longer serving it — and Google will eventually drop your
access to the property's history.

- Open kbeautybliss.com in a browser → View Source → search for `verification`
  and `msvalidate` and `p:domain_verify` and `facebook-domain-verification`.
  Write down every one.
- Best: verify kbeautybliss.com as a **Domain property** by DNS TXT record (Search
  Console → Add property → Domain). DNS records are not affected by the switch.
- Or: paste the Google token in Store → SEO & Meta → Settings → Verification &
  tracking → Google Search Console. **CHECK:** that field holds one Google token;
  if extrabeauty.ae's token is already in it, use the DNS method for
  kbeautybliss.com instead.
- Bing, Pinterest: same screen, their own fields. Meta (facebook-domain-
  verification): this shop has no field for it — use Meta Business Settings →
  Brand safety → Domains → DNS TXT. **CHECK:** whether kbeautybliss.com is already
  verified there.

### A7 · Backups

- Cloudways → Servers → your server → Backups → **Take backup now**.
- WordPress: a full backup (files + database) downloaded to your computer.
- `cp .env .env.before-domain-move` in the app folder (SSH).

### A8 · Decide what happens to WordPress

Recommended: **leave the WordPress hosting running, unchanged and unreachable by
name, for 30 days** — it is the rollback (F). Do not try to keep it public on a
subdomain: WordPress redirects every request to its own configured address
(kbeautybliss.com), so `old.kbeautybliss.com` would bounce visitors straight back
to this shop. Keep the downloaded backup permanently. Cancel the hosting only
after Q1/Q2 confirm the DNS and email do not live there.

---

## B · The switch (pick a quiet hour; about one hour)

### B1 · Tell this shop its new name — BEFORE touching DNS

Platform → Site address:

- **Main address:** `kbeautybliss.com`
- **Old addresses to forward here:** `extrabeauty.ae` (one line; `www.extrabeauty.ae`
  is added automatically — the line "Forwarding now:" under the box should list
  `extrabeauty.ae, www.extrabeauty.ae, www.kbeautybliss.com`)
- **Forward these, permanently:** **OFF** for now
- **Keep this install out of Google:** OFF

Save. **Why first:** with the main address still extrabeauty.ae, the moment
kbeautybliss.com points here this shop treats it as an unknown host and answers
**noindex** on every page (measured; pinned in
`tests/Feature/DomainMoveKbeautyblissTest.php`). And if the main address is
extrabeauty.ae with kbeautybliss.com in the old-addresses box and forwarding on —
what `CUTOVER-EXTRABEAUTY.md` §4.1 told you to do for the *opposite* move —
kbeautybliss.com would forward visitors **to extrabeauty.ae**.

**This step changes nothing on extrabeauty.ae** (same test): it keeps serving
normally, indexable, canonical tags still on extrabeauty.ae. Check: open
https://extrabeauty.ae — loads as before.

### B1b · Coming Soon page on kbeautybliss.com (Lane CS)

**Appearance → Coming Soon page** → On, *Only this address: kbeautybliss.com*,
Save — before B3. kbeautybliss.com then answers every visitor with a 503
"Something new is coming" page (`Retry-After: 3600`, `X-Robots-Tag: noindex`,
`Cache-Control: no-store, private`) while extrabeauty.ae is untouched; a signed-in
admin or the secret preview link sees the real shop there. Always let through:
the admin and owner-app addresses, `/admin-api/*`, `/api/*` (all payment
webhooks), `/checkout/card/*`, `/checkout/success`, `/checkout/pending`,
`/checkout/restore-basket`, `/.well-known/*` (Let's Encrypt, Apple Pay), `/up`,
`/_kbb-health`, `/import-chain/*`, file addresses, the web manifest and the
links customers' emails carry (reset, verify, unsubscribe, view-in-browser).
`robots.txt` answers `Disallow: /` there; the sitemap is hidden. Turn it off
after C's test orders pass, before E1. Emergency, over SSH:
`php artisan kbb:coming-soon off`. Step-by-step behaviour:
`KBEAUTYBLISS-SWITCH-CHECKLIST.md`, steps 6a and 20a.

**Varnish.** The page is a 503 and `no-store, private`; Varnish's built-in
`vcl_backend_response` caches neither (and Cloudways keys its cache on the Host
header, so extrabeauty.ae's cached pages are never served for kbeautybliss.com).
**CHECK** on the day, after B3: `curl -sI https://kbeautybliss.com/ | grep -iE
"^HTTP|x-kbb-coming-soon|cache-control|age:"` — expect `503`,
`x-kbb-coming-soon: 1`, `no-store, private`, and `age: 0` on a second request.

### B2 · Add the domains in Cloudways

Cloudways → Applications → this app → **Domain Management**: add
`kbeautybliss.com` and `www.kbeautybliss.com` as additional domains. Keep
extrabeauty.ae and www.extrabeauty.ae. **CHECK:** Cloudways' current wording of
this screen ("Primary domain" / "Additional domains" / "Aliases").

### B3 · Change the DNS (at the host from Q1)

| record | change to | note |
|---|---|---|
| `@` (kbeautybliss.com) **A** | the Cloudways server IP | `134.209.147.13` per `CUTOVER-EXTRABEAUTY.md` §7 (measured 1 Oct). **CHECK** in Cloudways → Servers → your server → Public IP. |
| `@` **AAAA** | **delete it**, if one exists | Otherwise IPv6 visitors keep reaching WordPress. extrabeauty.ae has none. |
| `www` | **CNAME → `kbeautybliss.com`** (or an A record to the same IP) | |
| **MX, every TXT (SPF, DKIM `…_domainkey`, DMARC `_dmarc`, google-/facebook- verification), `mail`, `autodiscover`, SRV, other subdomains** | **do not touch** | Email and verifications live here. |

Then wait until it resolves, from the server itself:

```bash
dig +short kbeautybliss.com @8.8.8.8          # must print the Cloudways IP
dig +short www.kbeautybliss.com @8.8.8.8
```

### B4 · Certificate

Cloudways → Applications → this app → **SSL Certificate** → Let's Encrypt. Enter
**all four names**: `kbeautybliss.com`, `www.kbeautybliss.com`, `extrabeauty.ae`,
`www.extrabeauty.ae`. **Installing a certificate that leaves out extrabeauty.ae
breaks https on extrabeauty.ae** — and with it the forwarding of every old link.
(Today's certificate covers `*.extrabeauty.ae` and `extrabeauty.ae`; **CHECK**
whether Cloudways keeps a wildcard alongside new names, or whether you list the
names explicitly.) Leave "HTTPS Redirection" as it is — the http→https redirect
lives in `public_html/.htaccess` and works for any domain name.

```bash
curl -sI https://kbeautybliss.com/robots.txt | head -3      # HTTP/2 200, no certificate error
curl -sI https://extrabeauty.ae/robots.txt   | head -3      # still 200
curl -sI http://kbeautybliss.com/            | grep -i location   # https://kbeautybliss.com/
```

Between B3 and B4 (minutes) a visitor to https://kbeautybliss.com sees a
certificate warning — which is why this is done at a quiet hour.

### B5 · Point APP_URL at the new domain

Only now — the updater and the "check" buttons fetch `APP_URL` from the server
itself (`UpdateRunner.php:350`), so it must already resolve here.

Open **https://kbeautybliss.com/<your admin path>** and sign in (a new domain is a
new browser cookie — you sign in again; so will customers). Platform → Site
address shows a banner offering this address → **Use this address from now on**.

Or over SSH: edit `.env` so the line reads exactly `APP_URL=https://kbeautybliss.com`
(https, no trailing slash, no folder), keep `KBB_BASE_PATH=` empty, then:

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan config:cache && php artisan route:cache     # put the server back in its warmed state
php artisan about | grep -i "url"                        # must say https://kbeautybliss.com
```

**CHECK:** `grep -n SESSION_DOMAIN .env` — it must be absent or empty. If it says
extrabeauty.ae, nobody can log in or keep a cart on kbeautybliss.com.

### B6 · Site URL

Store → SEO & Meta → Settings → Search appearance → **Site URL (canonical base)**
= `https://kbeautybliss.com` → Save. The sitemap, robots.txt, canonical tags and
share images follow this field, not APP_URL, when it is filled in.

### B7 · Primary domain, caches

- Cloudways → Domain Management → make **kbeautybliss.com the primary domain**.
- Cloudways → Application Settings → Varnish → **Purge**.
- Platform → Cache → **Clear everything** (the SSH commands in B5 already did the compiled half).

### B8 · Measure

```bash
php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae
```

Expect **RISK 0**, every configuration line OK, and one TODO: *Forward these,
permanently: off*. Then walk C and do D's payment steps (D1–D3) **before** C's
card/Tabby/Tamara test orders.

---

## C · Straight after: the test checklist

On a phone and a laptop, on **https://kbeautybliss.com**:

| # | test | what right looks like |
|---|---|---|
| 1 | Home, a category, a brand, a product, an article, a page | load; View Source: `<link rel="canonical" href="https://kbeautybliss.com/…">`, `<meta name="robots" content="index, follow">` |
| 2 | `curl -sI https://kbeautybliss.com/ \| grep -i x-robots` | **nothing** (no noindex) |
| 3 | https://kbeautybliss.com/robots.txt | ends `Sitemap: https://kbeautybliss.com/sitemap.xml` |
| 4 | https://kbeautybliss.com/sitemap.xml | every `<loc>` starts https://kbeautybliss.com |
| 5 | Arabic: https://kbeautybliss.com/ar/ | loads, Arabic canonical on kbeautybliss.com |
| 6 | Old WordPress addresses: `/product-category/<a category>/`, `/<a category>/` (flat), `/brand/<a brand>/`, `/<an article slug>/`, `/product/<a product>/` | 301 to the new address, or 200 for products |
| 7 | https://www.kbeautybliss.com/cart/ | 301 → https://kbeautybliss.com/cart/ |
| 8 | Customer: register, log out, log in, **forgot password** (the email's link must say kbeautybliss.com and work) | |
| 9 | Add to cart → checkout → **cash on delivery** order | order confirmation email arrives; its links say kbeautybliss.com |
| 10 | **Card** order (after D1) | order turns paid by itself within a minute (that is the webhook) |
| 11 | **Tabby** and **Tamara** orders (after D2, D3) | you come back to kbeautybliss.com, order turns paid |
| 12 | Apple Pay / Google Pay buttons (after D1's domain step) | shown on Safari / Chrome |
| 13 | Admin: Emails → Sending & delivery → send a test | arrives, not in spam; header `From` as chosen in Q8 |
| 14 | Content → Instagram | still connected (the token is not tied to the domain) |
| 15 | Shop app: on a phone, open kbeautybliss.com → Add to Home Screen → allow notifications; Growth & Marketing → Push Notifications → send a test to it | arrives; tapping opens kbeautybliss.com |
| 16 | Owner app: open https://kbeautybliss.com/<owner app path> on your phone, sign in, install, allow notifications | an order notification arrives |

---

## D · Third-party dashboards (nothing in this shop can change these)

### D1 · Stripe

- **Webhook.** Stripe Dashboard → Developers → Webhooks → open the endpoint whose
  URL starts `https://extrabeauty.ae/api/payments/webhook/stripe/` → **Update
  details** → change **only the domain** to `kbeautybliss.com`, keep the rest of
  the address exactly → Save → **Send test event** → 200. Editing the URL keeps
  the endpoint's signing secret, so nothing changes in this shop. (If the shop
  created the endpoint itself — Store → Payments → Stripe, connected with a key —
  pressing connect again creates a new endpoint on the new address and stores its
  secret (`StripeConnect.php:900`); then delete the extrabeauty.ae one in Stripe.)
  The exact address the shop expects is shown on Store → Payments → Stripe.
- **Apple Pay / Google Pay domain.** Stripe → Settings → Payments → **Payment
  method domains** → Add `kbeautybliss.com`. This shop already serves the
  verification file at `/.well-known/apple-developer-merchantid-domain-association`
  on any domain. Remove extrabeauty.ae there only after E is done.
- **Stripe Connect redirect URI** — only if you use Connect OAuth (a platform
  client id is set): **CHECK** the platform's Connect settings list
  `https://kbeautybliss.com/admin-api/payments/stripe/connect/callback`.

### D2 · Tabby

Store → Gateway webhooks → Tabby → **Register / re-sync Tabby’s webhooks**. With extrabeauty.ae in the
old-addresses box (B1), this now registers `https://kbeautybliss.com/api/payments/
webhook/tabby/…` **and deletes the one still calling extrabeauty.ae** (fixed in
this release — before, the old one stayed registered for ever). The card should
read "Registered and pointing at this shop". **CHECK** with Tabby whether their
promo widget or merchant account has a list of allowed domains to add
kbeautybliss.com to.

### D3 · Tamara

Store → Gateway webhooks → Tamara → **Remove the registration…** → Yes → then
**Register the webhook**. Both buttons are needed: "Re-check registration" alone
keeps the old extrabeauty.ae registration (`TamaraGateway.php:1471` returns the
stored id without re-registering). **CHECK** with Tamara whether their widget
needs kbeautybliss.com allowed.

### D4 · Instagram (Meta)

developers.facebook.com → your app → Instagram → API setup with Instagram
business login → Business login settings → **OAuth redirect URIs** → add the
address shown on Content → Instagram (it will read
`https://kbeautybliss.com/admin-api/instagram/callback`). Keep the old one until
a reconnect has worked. You only need to reconnect if Instagram asks.

### D5 · Meta pixel

Events Manager → your pixel → Settings → **Traffic permissions**: if an allow
list is on, add kbeautybliss.com. Business Settings → Brand safety → Domains →
kbeautybliss.com verified (A6). **CHECK** both.

### D6 · Google

- **Search Console, kbeautybliss.com property:** Sitemaps → remove the old
  WordPress sitemaps (`sitemap_index.xml`, `wp-sitemap.xml`) → add
  `https://kbeautybliss.com/sitemap.xml`. URL Inspection on the home page and two
  products → Request indexing.
- **Change of Address from extrabeauty.ae → kbeautybliss.com**: only after E1
  (forwarding on), and only if extrabeauty.ae is a verified property (Q9) — it
  was public and indexable since late September, so it probably has pages in
  Google. extrabeauty.ae property → Settings → Change of address → kbeautybliss.com.
- **Merchant Center:** Business info → website → kbeautybliss.com (re-claim it).
  Feeds: see Q7.
- **Analytics (GA4):** Admin → Data streams → the web stream → update the URL.
  **CHECK** for hostname filters in reports or GTM triggers that name extrabeauty.ae.
- **Bing Webmaster:** add kbeautybliss.com if missing, submit the sitemap; its
  Site Move tool for extrabeauty.ae → kbeautybliss.com. IndexNow needs nothing —
  the key file is served on any domain.

---

## E · Forwarding extrabeauty.ae, and what "zero dependency" means

### E1 · Switch the forwarding on (after C passes)

Platform → Site address → tick **Forward these, permanently** → Save. Purge
Varnish. Then:

```bash
curl -sI https://extrabeauty.ae/cart/            | grep -i location   # https://kbeautybliss.com/cart/
curl -sI "https://www.extrabeauty.ae/product/<a product>/?x=1" | grep -i location   # same path and query on kbeautybliss.com
curl -sI https://www.kbeautybliss.com/           | grep -i location   # https://kbeautybliss.com/
```

Every GET and HEAD on extrabeauty.ae and www.extrabeauty.ae answers **301 to the
same path and query on kbeautybliss.com**, in one hop where the redirects table
also has a row for the path (`CanonicalHost.php:188`). **CHECK:** that the
`Location` starts `https://` — this middleware copies the scheme of the request
as PHP sees it (`CanonicalHost.php:213`); if Cloudways' proxy makes PHP see
`http`, the first hop is to `http://kbeautybliss.com/…` and the `.htaccess`
rule adds a second hop to https. It still lands; tell me if you see it.

**POST is never forwarded** (`CanonicalHost.php:132`) — a form or a payment
callback arriving on extrabeauty.ae is served where it lands, so a shopper who
was mid-checkout at the switch, or a provider still holding the old webhook
address, is not lost.

### E2 · What "zero dependency" means in practice

After A3, A5, D and E1:

- **Nothing on kbeautybliss.com loads from extrabeauty.ae** — no picture, script,
  link, email address or webhook. `kbb:domain-check` proves it: RISK 0, and no
  TODO line naming extrabeauty.ae.
- **extrabeauty.ae only forwards.** It stays pointed at this server (DNS
  unchanged) so the 301s can be answered; it serves no page of its own.
- **Old emails keep working.** Order, password-reset and invite links already
  sent say extrabeauty.ae; the 301 keeps the path and query, and this shop's own
  link signatures do not include the domain, so they still open.
- **Keep extrabeauty.ae renewed.** At least two years; forever is cheaper than
  what lapsing costs — whoever buys it next inherits every backlink, every old
  email link, and any webhook address you forgot.

Things that can only fade, not be switched:

- **Shop app installed from extrabeauty.ae.** An installed web app and its
  notifications belong to the address it was installed from. Those phones keep
  receiving notifications, and tapping one opens extrabeauty.ae and forwards to
  kbeautybliss.com — **as long as extrabeauty.ae keeps pointing here** (the tap
  is logged by a POST to extrabeauty.ae, which is served, not forwarded). To move
  a customer fully they open kbeautybliss.com and add it to the home screen again.
  The server does not record which address a subscription came from, so there is
  nothing to clean up by hand; dead ones are dropped when the push service
  reports them gone.
- **Owner app phones.** Sign in again on kbeautybliss.com and reinstall; then
  revoke the old devices in Platform → Users & Roles → Owner app.
- **Logged-in customers.** Cookies belong to a domain; everybody is signed out
  once. Deliberately not carried: a login in a URL is a login that can be
  stolen from a URL. A signed-in customer's basket belongs to their account,
  so it is back the moment they sign in on kbeautybliss.com.
- **Guest baskets, wishlists, recently viewed — CARRIED (Lane DS).** While
  forwarding is on, a shopper opening a page on extrabeauty.ae with a guest
  basket (or wishlist / recently viewed) is sent across with a one-time,
  2-minute, signed token; kbeautybliss.com restores the cookies and
  immediately redirects to the clean address. Search engines, assets and
  cookie-less visits get exactly the 301 they always got. See
  "Appendix · nothing lost in the switch" below.

### E3 · Old WordPress addresses — what this shop answers (measured)

Requested through the real kernel on kbeautybliss.com after the switch:

| family | answer |
|---|---|
| `/product/{slug}/` | 200 (same scheme) |
| `/product-category/{path}/`, flat `/{category}/` | 301 → `/collections/{path}/` |
| `/brand/{slug}/`, `/korean-skincare-brands/{slug}/` | 301 → `/brands/{slug}/` |
| `/{article}/`, `/skincare-guide/{article}/` | 301 → `/blog/{article}/` |
| `/shop/`, `/cart/`, `/checkout/`, `/my-account/` | served |
| `/shop/page/2/` | 301 → `/shop/?paged=2` |
| `/?p=123`, `/?product=x`, `/?s=…` | 200 home or search (the query is ignored) |
| `/wp-content/uploads/…` | the file, if A3 copied it; 404 if not |
| **`/sitemap_index.xml`, `/wp-sitemap.xml`, `/product-sitemap.xml` (any Yoast `*-sitemap.xml`)** | **404** |
| **`/feed/`, `/comments/feed/`, `/product/x/feed/`** | **404** |
| **`/my-account/lost-password/`, `/my-account/edit-account/`** | **404** (WooCommerce's names; this shop uses `/my-account/forgot`) |
| **`/checkout/order-received/{id}/?key=…`** (links in old WooCommerce emails) | **404** |
| `/product-tag/…`, `/author/…`, `/category/…`, `/tag/…`, `/page/2/`, `/compare/`, `/pa_brands/…` | 404 |
| `/wp-admin/`, `/wp-login.php`, `/xmlrpc.php`, `/wp-json/` | 404 — correct; nothing to log in to |

What to do: in Store → SEO & Meta → **Redirects & 404s**, add these rows (one
each — a row matches one exact address):

| from | to |
|---|---|
| `/sitemap_index.xml` | `/sitemap.xml` |
| `/wp-sitemap.xml` | `/sitemap.xml` |
| `/product-sitemap.xml`, `/product_cat-sitemap.xml`, `/post-sitemap.xml`, `/page-sitemap.xml` | `/sitemap.xml` |
| `/feed/` | `/skincare-guide/` |
| `/my-account/lost-password/` | `/my-account/forgot` |
| `/my-account/edit-account/` | `/my-account/` |

Then, for two weeks, look at the 404 list on the same screen every few days: it
records every missing address shoppers and Google actually ask for, so the next
rows to add are the ones that matter, not guesses. **CHECK:** whether the old site
used product tags or `/compare/` at all before adding rows for them.

---

## F · Rollback

**Minutes, if done the same day (TTL 300 from A2):**

1. Platform → Site address → **Forward these, permanently: OFF** first (so
   nothing bounces), Main address back to `extrabeauty.ae`, old addresses: empty.
2. `APP_URL=https://extrabeauty.ae` in `.env` (or `cp .env.before-domain-move .env`),
   then `php artisan config:clear && php artisan config:cache`.
3. Store → SEO & Meta → Settings → Site URL back to `https://extrabeauty.ae`.
4. DNS: `@` A (and AAAA, if you deleted one) back to the WordPress IP from A1;
   `www` back to what A2's export says.
5. Stripe endpoint URL back to extrabeauty.ae; Tabby **Register / re-sync** and Tamara
   remove + register again from https://extrabeauty.ae.

**What cannot be rolled back:**

- Orders, customers and reviews placed on this shop during the window are not in
  WordPress (and vice versa). Note their numbers.
- Emails sent during the window carry kbeautybliss.com links; after a rollback
  those addresses go to WordPress, where `/collections/…` and `/blog/…` do not
  exist.
- Google may already have recrawled pages; a same-day rollback is invisible in
  practice, a rollback after a week is a second move.
- Customers are signed out a second time.
- Media copied in A3 and links edited in A5 stay improved — they work on both.

---

## Appendix · what the code was checked for (file:line, 6 October 2026)

For the integrator and whoever moves the next domain.

**Fixed in this change (each with a red-without-it test in
`tests/Feature/DomainMoveKbeautyblissTest.php`):**

1. `SiteHost::aliases()` forwards the **www twin of every typed old address**.
   Before, typing `extrabeauty.ae` left `www.extrabeauty.ae` (a CNAME to the apex
   on the live DNS) serving the full shop with noindex — measured 200 + noindex.
2. `TabbyGateway::staleHooks()` treats this shop's webhook path on a typed old
   address as stale, so Re-register deletes it. Before, the extrabeauty.ae hook
   stayed registered with Tabby for ever.
3. `php artisan kbb:domain-check` (`app/Services/DomainMove/DomainReadiness.php`):
   read-only readiness report, proven to issue only SELECTs.
4. Admin hints that told the owner to type extrabeauty.ae are now domain-neutral
   or derived from the address the admin is open on (Site address errors,
   Owner app own host, Stripe Apple Pay help, WhatsApp share help, SEO Keywords
   property example); the Site address banner's "Site URL" path now names the
   real screen.

**Checked and safe as they are:**

- `DescriptionVideos::OLD_HOSTS` (`DescriptionVideos.php:60`) is an *allow-list*
  of hosts whose video addresses may be drawn as a player. Listing our own new
  domain there rewrites nothing and strips nothing; `local()` prefers the copy on
  disk. Kept.
- `OldSiteLinks::OLD_HOSTS = ['kbeautybliss.com']` (`OldSiteLinks.php:99`) is an
  admin tool that turns absolute kbeautybliss.com links into this shop's relative
  ones. After the switch that is still correct — a relative link works on any
  domain. It never runs on a page.
- Payment return and webhook URLs are built at request time from `APP_URL`
  (`Url::external`, `RemoteGateway.php:267`, `TabbyGateway.php` `ourWebhookUrl`,
  `TamaraGateway.php` `webhookUrl`), never stored.
- Emails, the sitemap, canonical tags, IndexNow, Open Graph: `APP_URL` / Site URL
  at send/render time. Push notification links are root-relative
  (`PushSender.php:117`) and resolved by the phone against the app's own address.
- Customer link signatures (`CustomerLinkSigner`) and unsubscribe tokens contain
  no host. No Laravel signed URLs are used.
- Content-Security-Policy (report-only) uses `'self'`, no host names. No CORS
  config. `SESSION_DOMAIN` is not written by the installer.
- `bootstrap/app.php` web root is a folder path, not a domain.
- Import tools that read WordPress by name and stop being useful after the switch
  (not broken, just pointing at this shop): `HierarchySource` (`config/kbb.php:154`,
  override with `KBB_HIERARCHY_SOURCE_HOST`), the Super Sale order copy, the media
  fetchers (`MediaSideloader.php:356` skips own hosts; `DescriptionVideoFetcher.php:320`
  also counts the main address as own — hence A3 before B1).

**Not changed, for the integrator** (`resources/views/admin/app.blade.php`, which
lanes do not edit): the Site address screen's placeholders still read
`extrabeauty.ae` (main) and `kbeautybliss.com` (old addresses) — after the move
the second one suggests typing the new main address as an old one (harmless:
`aliases()` drops the main address, but confusing). Its help line "The www /
non-www pair of your main address is handled automatically" could now say "of
your main address and of each old address".

---

## Appendix · nothing lost in the switch (Lane DS, 8 October 2026)

The owner: *"also make sure, no any data or settings should be gone after
domain switch."* Audited in the code, not assumed.

### Server side — follows the main address by itself

Same server, same database, same `APP_KEY`: every row, setting, upload,
img-cache file, order, customer, encrypted payment key (`payment_providers.config`
is `encrypted:array` under `APP_KEY`), password hash and push key survives
untouched. Nothing is keyed by host:

| item | how it is built | after the switch |
|---|---|---|
| cache keys | `CACHE_PREFIX` from `APP_NAME`; no key contains a host (grep `getHost()` in app/) | unchanged; step 6 clears caches anyway |
| settings | one global `settings` table, no per-host rows | unchanged |
| `SiteHost` | main / old addresses are settings | step 2 sets them |
| emails, canonical, hreflang, sitemap, robots, IndexNow, Open Graph | `APP_URL` / Site URL at render or send time | follows step 6 |
| payment return URLs, Tamara IPN URL | `Url::external()` at request time | follows step 6 |
| Stripe / Tabby / Tamara **webhooks** | registered at the provider | step 7's buttons; step 1b proves it |
| Apple Pay domain | registered at Stripe | **you**: Stripe → Payment method domains (step 1b says if missing) |
| Instagram callback / Stripe Connect redirect | built from `APP_URL`, registered at Meta / Stripe | **you**: Meta app redirect URI (step 7 shows the value); Connect only if used |
| owner app "Own host" | a setting | step 11 clears it if on the old domain |
| PWA manifest, service worker | relative `start_url` / `scope` | per origin by nature (see below) |
| customer link signatures, unsubscribe tokens | no host inside | old email links keep working through the 301 |
| absolute `https://extrabeauty.ae/…` in content | stored text | **step 6b** rewrites them (preview, undo) |
| web-root files (robots.txt, .htaccess, …) | on disk, served before the shop | `kbb:domain-check` now reads them (RISK / TODO) |
| `ASSET_URL` | `.env` | `kbb:domain-check` now flags it if on the old domain |

### Content naming the old domain — step 6b

Platform → Domain switch → **6b · Old links in the shop's text**: *Show what
would change* (counts and before/after samples per place) → *Change these N
links* (only after step 6, confirms the exact count it showed) → *Undo* (puts
every cell back unless it was edited since). Content tables only — products,
articles, pages, blocks, menus, banners, email and marketing templates,
redirect targets, translations, media, settings. Never orders, payments,
payment providers, customers or sent mail; never a settings key about
payments, security or the addresses themselves; never an email address or the
bare name in a sentence. The undo record is `domain_content_rewrites`.

### Browser side — per domain by nature

| state | where it lives | carried? |
|---|---|---|
| guest basket | `kbb_cart` cookie (encrypted) | **yes**, by the handoff |
| wishlist | `kbb_wishlist` cookie | **yes**, merged |
| recently viewed | `kbb_viewed` cookie | **yes**, merged |
| customer login | session cookie | **no**, on purpose; sign in again; their basket follows the account |
| admin login | session cookie | no; sign in again |
| consent banner | — | the shop has none |
| currency / language | AED only; language is in the path (`/ar/`) | the 301 keeps the path |
| timezone hint `kbb_tz`, filter memory `kbb_filters`, review votes, video likes | cookies | no — rebuilt on the next visit, nothing lost that matters |
| WhatsApp button "closed", "frequently bought" seen | localStorage | no — cosmetic |
| "remember my details" (if Lane PO ships it in localStorage) | localStorage | no — a server redirect cannot read localStorage |
| installed shop app (PWA) and its push subscription | the origin it was installed from | **no**: keeps working through the forward (taps open extrabeauty.ae → 301); reinstall from kbeautybliss.com. Covered by the 2–4 week forward |
| owner app on phones | its own origin | no: sign in and reinstall (checklist step 24) |

### The handoff, and why the 301 stays clean for Google

`CanonicalHost` → `DomainHandoff::forward()`. On an old address with
forwarding ON:

- **Everything except a shopper's page navigation carrying a basket cookie**
  gets the unchanged `301` to the same path and query. Googlebot and Bingbot
  crawl without cookies, so they always get it; a bot User-Agent, `HEAD`,
  any `Accept` that is not HTML, `Sec-Fetch-Dest` other than `document`, a
  prefetch, a path with a file extension and `/api/` get it too.
- **A shopper's GET of a page with a guest basket / wishlist / recently viewed**
  gets `302`, `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`,
  to the same address plus `?kbb_handoff=<token>`. 302 and not 301 because a
  301 is cacheable: a browser or Varnish keeping it would replay a one-time
  token. No crawler ever receives this hop, so the permanent-move signal is
  exactly as strong as before.
- **The token**: `base64url(JSON).base64url(HMAC-SHA256)`, key derived from
  `APP_KEY`; payload = target host, expiry (120 s), 128-bit nonce, the basket's
  numeric id (never its token), wishlist and viewed ids. Only active **guest**
  baskets with at least one line.
- **On kbeautybliss.com** a request with `kbb_handoff` is never rendered: a
  `302` (no-store, no-referrer, noindex) to the same address without it. Only
  if the MAC is right, it has not expired, the host matches and the nonce has
  never been spent (atomic `Cache::add`) are the cookies set. An existing
  basket on the new domain is never replaced. Forged, expired, replayed or
  wrong-host tokens get the same clean redirect and nothing else.
- **Cost**: on every normal page, one array lookup; no query, no settings read.
  The issue side runs only on requests already being forwarded.
- **Limit**: if a cache in front (Varnish) served a stored 301 to a shopper,
  that shopper simply arrives without the basket — the state before this change.

Tests: `tests/Feature/DomainHandoffTest.php`, `ContentRewriteTest.php`,
`PaymentsReadinessTest.php`.
