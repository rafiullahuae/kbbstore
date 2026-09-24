# Moving this shop to another domain — everything that moves with it

`docs/CUTOVER-EXTRABEAUTY.md` is the runbook for one specific move. This is the
general list, for a shop that is now a product and will be installed and moved
by people who did not write it.

`APP_URL` is the one everybody thinks of, and the banner on **Platform → Site
address** now offers to write it for you. **It is not the only one, and the rest
of this page is the reason a move looks fine for three weeks and then does not.**

The three columns that matter are not "hard" and "easy". They are:

| kind | meaning |
|---|---|
| **derived** | the shop works this out for itself. Nothing to do. |
| **here** | a field in this admin. You change it, once, and it takes effect immediately. |
| **third party** | it lives in somebody else's dashboard. **Nothing in this shop can change it, and nothing in this shop can tell you it is wrong.** |

The third group is the whole reason this page exists. Every one of them fails
silently and late.

---

## Derived — nothing to do

| what | where it comes from |
|---|---|
| Internal links on every page | `Support\Url::to()`, a root-relative path. The browser resolves it against whatever host it is on, so they are right on any domain, immediately. |
| The base path (`/shop`, `/kbb-upgrade`) | `Request::getBaseUrl()`, which is the front controller's real location. `KBB_BASE_PATH` overrides it and should be empty at a domain root. |
| Asset URLs (CSS, JavaScript) | Vite, built from the request root. Correct on any host by construction. |
| The `www` / non-`www` counterpart of your main address | Derived from it — see `App\Support\SiteHost`. You never type it. |

---

## Here — change it in this admin

| what | where | what breaks until you do |
|---|---|---|
| **`APP_URL`** | **Platform → Site address**, the banner, one button | Order and password-reset emails, the sitemap, canonical tags and payment callbacks all name the old domain. This is the big one. |
| **Main address** (`canonical_host`) | Platform → Site address | The old-domain forwarding points somewhere wrong, and the "keep out of Google" inference misreads which host is which. |
| **Old addresses to forward here** | Platform → Site address | The domain you have just left keeps serving a second, competing copy of the shop. |
| **Site URL** (`site_url`) | Store → Settings → Site URL | `sitemap.xml`, `robots.txt`, canonical tags, Open Graph images and IndexNow all prefer this over `APP_URL`, so a stale one outranks a fixed `APP_URL`. Check it even if the banner says everything is fine. |
| **Session cookie name and path** | `.env` — `SESSION_COOKIE`, `SESSION_PATH`, `SESSION_DOMAIN` | Only if two apps share the new hostname (the console and the shop on one domain). Symptom: "randomly logged out". |
| **`KBB_BASE_PATH`** | `.env` | Empty at a domain root. Left set, it prefixes every route and *every link on the site is wrong*. `install.php` now derives it from the address you installed at, so a fresh install gets this right by itself. |
| **Product image URLs** | Store → Import → Addresses & pictures | Only if images are still hot-linked to the old WordPress host. Fetch, then apply. |

---

## Third party — nobody here can do it for you

**Set a reminder. Every one of these looks like nothing is wrong.**

| what | where | how it fails |
|---|---|---|
| **Stripe webhook endpoint** | Stripe dashboard → Developers → Webhooks | Payments succeed and **order status never updates**. The shopper is charged and the shop does not know. This is the worst one on the page. |
| **Tabby / Tamara webhook and return URLs** | each provider's merchant dashboard | Same shape: the customer pays and comes back to a dead address. |
| **Stripe Connect redirect URI** | Stripe dashboard → Connect settings | Onboarding a vendor fails at the last step with an OAuth error naming the old domain. |
| **Change of address** | Google Search Console | Without it you restart your search ranking from zero, and the redirects that protect your ranking cannot do it alone. |
| **Adding the new domain as a property** | Search Console, Bing Webmaster | Your verification *tokens* survive — they are settings and the meta tags are served on any host — but the new domain is not a property until you add it, so nothing is reported and nothing can be submitted. |
| **IndexNow key file** | served from the web root of the **new** domain | Pings are rejected. Silent — IndexNow never throws and the shop never reports a failure. |
| **SPF / DKIM / DMARC** | your DNS, at the new domain | Order confirmations go to spam. Nothing in the shop can see this. |
| **Mail `From` domain** | your sending provider | Ditto, one layer up: a `From` on a domain the provider does not own fails authentication. |
| **The old domain's registration** | your registrar | **Keep renewing it forever.** Letting it lapse hands every backlink you have to whoever buys it next. |
| **SSL certificate** | the new host | Must cover the apex *and* `www`, before you switch the address over — the installer asks for an `https://` address and should get one. |

---

## The order to do it in

1. Certificate on the new domain, apex and `www`.
2. Install or move the files and the database (`docs/CUTOVER-EXTRABEAUTY.md`).
3. Open the shop on the new domain and sign in. **Platform → Site address**
   shows the banner. Press **Use this address from now on**.
4. On the same screen: main address, and the old domain in the forward list.
   Do not switch forwarding on yet.
5. Store → Settings → **Site URL**.
6. Place a real test order, card and cash on delivery. Check the confirmation
   email arrives and that its links say the new domain.
7. Repoint every webhook in the third-party table. Send a test event from each.
8. Only now switch the forwarding on, and file the Change of Address.
9. Keep the old domain renewed.

---

## Why the shop does not just do step 3 for itself

Because a `Host:` header is chosen by whoever sent the request. If this
application wrote that header into `.env`, or built an email link from it, then
any visitor could point this shop's password-reset emails at a domain they own —
which is account takeover, not a cosmetic bug.

So the shop separates two things that look alike:

- **In-band** — a redirect, a link on the page you are looking at. The host of
  the request is correct here, because you are already on it.
- **Out-of-band** — an email, a webhook callback, a canonical tag, a sitemap
  entry. The reader is not the person who made the request, so the host must be
  one the **owner** confirmed.

Your click on that button, in a signed-in admin session, is the confirmation.
A header is not. `App\Support\SiteUrl` carries the full argument.
