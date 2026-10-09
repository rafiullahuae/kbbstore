# Switching the shop to kbeautybliss.com: the 17 steps

**This list is the same list as Platform → Domain switch in the admin, in the
same order, with the same numbers and titles.** A test
(`tests/Feature/DomainSwitchInstallerTest.php`) fails if the two ever differ,
so follow whichever is in front of you. The screen also checks each step for
you (the **Verify** button) and remembers where you are, on any device.

The server stays the same. The domain is registered at Internet.bs, and its DNS
moves off Hostinger. extrabeauty.ae keeps working throughout, and is removed
only at the end (step 17, in 2–4 weeks).

**If anything goes wrong:** Cloudways → Servers → Launch SSH Terminal, in the
app folder run `php artisan kbb:coming-soon off` — the shop shows again on the
next request. Before step 8, undo the DNS by setting the `@` A record at
Internet.bs back to `177.202.242.149` (Hostinger). After step 8, open
https://extrabeauty.ae with your admin path → Platform → Site address → **Use
this address from now on**, and set Main address back to `extrabeauty.ae`.
Full rollback: `docs/DOMAIN-MOVE-KBEAUTYBLISS.md` §F.

Steps marked **(cannot be skipped)** have no "Skip for now" on the screen.
Every other step can be skipped and done later; step 17 lists what was skipped.

## 1. Before you start

- Cloudways → Servers → your server → Backups → **Take backup now**.
- Apply every package up to the current one (Store → Core Updates).
- If WordPress still takes orders, run one last import of orders and customers
  (Store → Import / Export).
- Press **Verify**. It learns this server's public address from where
  extrabeauty.ae points today (the same address as Cloudways → Servers → your
  server → Public IP), runs the readiness check (`kbb:domain-check`: must say
  RISK 0), and runs the payments check (no red). Pictures still on WordPress
  are fetched from here with **Fetch them from WordPress**, while WordPress is
  still online.

## 2. Tell the shop its new name

(cannot be skipped) Press **Save the new name**: Main address
`kbeautybliss.com`, Old addresses `extrabeauty.ae`, Forward permanently
**OFF**. Nothing changes for shoppers. It comes before the DNS change: with the
main address still extrabeauty.ae, kbeautybliss.com would be treated as an
unknown address and hidden from Google.

## 3. Coming Soon page ON for kbeautybliss.com

Press **Turn it on for kbeautybliss.com**. Anyone who reaches kbeautybliss.com
(and www) then sees "Something new is coming"; extrabeauty.ae is untouched.
Signed in, or with the preview link the step shows, you see the real shop.
Change the words in Appearance → Coming Soon page.

## 4. Cloudways: add the domain

Cloudways → Applications → your app → **Domain Management** → add
`kbeautybliss.com` and `www.kbeautybliss.com`. Keep extrabeauty.ae. The shop
cannot see Cloudways: press **Mark as done** (step 7 proves it later).

## 5. Internet.bs: DNS records

(cannot be skipped) Internet.bs → kbeautybliss.com → **DNS Management**:

| Type | Name | Value |
|---|---|---|
| A | `@` | this server's address, as step 1 found it (the screen shows it with a Copy button), TTL 300 |
| CNAME | `www` | `kbeautybliss.com` |
| MX | `@` | `smtp.google.com`, priority 1 |
| TXT | `@` | `v=spf1 include:_spf.google.com ~all` |

Do not create an AAAA record. Then under **Nameservers**, choose Internet.bs's
own DNS nameservers. **Verify** checks the email (MX, SPF) and www records.

## 6. Wait for DNS

(cannot be skipped) A few minutes to 48 hours. **Verify** asks the internet
where kbeautybliss.com and www.kbeautybliss.com point (A and AAAA) and compares
that with this server's address. Green when both point here and there is no
AAAA record.

## 7. Cloudways: SSL certificate

(cannot be skipped) Cloudways → Applications → your app → **SSL Certificate** →
Let's Encrypt → all four names: `kbeautybliss.com`, `www.kbeautybliss.com`,
`extrabeauty.ae`, `www.extrabeauty.ae`. extrabeauty.ae stays on the
certificate while it forwards. **Verify** opens https:// on each name.

## 8. Make kbeautybliss.com the main address

(cannot be skipped) Open https://kbeautybliss.com with your admin path, sign
in, come back to this step and press **Make kbeautybliss.com the main
address**: the shop's own address (APP_URL) and Site URL become
https://kbeautybliss.com and its caches are cleared. It refuses until steps 6
and 7 have verified green, unless you type `CONFIRM`.

## 9. Clear caches

Press **Clear the shop's caches**. Then in Cloudways: Domain Management → make
kbeautybliss.com the **primary domain**; Application Settings → Varnish →
**Purge**. **Verify** opens https://extrabeauty.ae/ and checks the page names
kbeautybliss.com, not a copy saved before the switch.

## 10. Old links in content

**Show what would change** → **Change these links** (Undo is offered). Links in
product descriptions, articles, pages, menus, banners and email templates that
point at extrabeauty.ae are pointed at kbeautybliss.com. **Verify** counts what
is left.

## 11. Payments: Stripe webhook, Apple Pay domain, Tabby, Tamara

Press **Set up Stripe webhook automatically**, **Register Tabby** and
**Register Tamara** (each removes the old extrabeauty.ae registration). Then
Stripe dashboard → Settings → **Payment method domains** → add
`kbeautybliss.com` (Apple Pay / Google Pay). **Verify** runs the payments
check. Still on sandbox keys? Skip it and come back when the live keys are in.

## 12. Callbacks: Stripe Connect and the Meta pixel

The shop cannot read these dashboards: press **Mark as done** when finished.
(The Instagram redirect URI that stood here went with the Instagram API module,
retired in October 2026; Instagram embeds need no Meta app.)

- Only if you use "Connect with Stripe": add
  `https://kbeautybliss.com/admin-api/payments/stripe/connect/callback` to the
  Connect redirect URIs.
- Meta Events Manager → your pixel → Settings → **Traffic permissions**: if an
  allow list is on, add kbeautybliss.com.

## 13. Test orders

Signed in, or on your phone with the Coming Soon preview link, on
https://kbeautybliss.com: card, cash on delivery, Tabby and Tamara orders. Order
emails show kbeautybliss.com links; password reset works; the Arabic shop
works; the phone app installs. Press **Mark as done** when all passed.

## 14. Coming Soon page OFF

Only after step 13 passed. Press **Turn the Coming Soon page off** (or over SSH
`php artisan kbb:coming-soon off`). Everyone now sees the shop on
kbeautybliss.com.

## 15. Forward the old address

Press **Forward extrabeauty.ae to kbeautybliss.com**. Every old link, bookmark
and installed app lands on kbeautybliss.com. The button refuses while the
Coming Soon page is still on, because it would send every extrabeauty.ae
customer to the Coming Soon page. Leave forwarding on for 2–4 weeks. **Verify**
opens https://extrabeauty.ae/ and expects a 301 to kbeautybliss.com.

## 16. Google: Search Console, sitemap, IndexNow

Search Console → the kbeautybliss.com property → Sitemaps → remove the old
WordPress sitemaps, add `https://kbeautybliss.com/sitemap.xml`. If
extrabeauty.ae is a property: Settings → **Change of address** →
kbeautybliss.com. Press **Ping IndexNow** (Bing and others). **Verify** checks
what the shop gives Google (the sitemap's address, nothing hiding it); Search
Console itself cannot be checked from the shop.

## 17. Done, and what to do in 2–4 weeks

The screen lists every skipped step with **Return to it**. In 2–4 weeks:

- Press **Remove extrabeauty.ae completely** (it runs the readiness check
  itself and refuses while anything still depends on extrabeauty.ae).
- Cloudways → Domain Management → remove `extrabeauty.ae` and
  `www.extrabeauty.ae`; SSL Certificate → Let's Encrypt again with only
  `kbeautybliss.com, www.kbeautybliss.com`; Varnish → Purge.
- At extrabeauty.ae's registrar, remove its A record. Stripe dashboard →
  Payment method domains → remove extrabeauty.ae.
- When kbeautybliss.com's nameservers have shown Internet.bs for 2 days, delete
  the domain and site from Hostinger and cancel the plan.

### What each step does while the Coming Soon page is on (kbeautybliss.com only)

| Steps | With Coming Soon ON |
|---|---|
| 5–6 DNS | Unaffected. As DNS spreads, visitors reaching kbeautybliss.com get the Coming Soon page instead of Hostinger. |
| 7 Certificate | Unaffected: everything under `/.well-known/` is always let through for Let's Encrypt. |
| 8 Main address | Unaffected: the admin address is always let through. Once signed in you see the real shop, with a small notice. |
| 9 Caches | Unaffected: the Coming Soon page is sent `no-store`, so no cache holds it. |
| 10 Old links | Unaffected. |
| 11–12 Payments, callbacks | Unaffected: every webhook (`/api/payments/webhook/…`), Stripe Connect and the Instagram callback (`/admin-api/…`) are let through. |
| 13 Test orders | Signed in, or with the preview link: the real shop. Card confirm, the return pages and the webhooks are let through for everyone, so a payment always lands. |
| Meanwhile on extrabeauty.ae | The shop as always. Once step 8 is done, emails to real customers link to kbeautybliss.com, which shows them the Coming Soon page until step 14: keep the gap between 8 and 14 to a few hours. |
| 15 Forwarding | Refused until step 14. |
