# Switching the shop to kbeautybliss.com: step-by-step checklist

The server stays the same. The domain is registered at Internet.bs, and its DNS
moves off Hostinger. extrabeauty.ae is removed at the end. Do the steps in
order, and finish each one before starting the next.

Undo, at any point before Part G: at Internet.bs, set the `@` A record back to
`177.202.242.149`.

## A. Prepare the shop (nothing visible changes)
1. Apply every patch up to **2.60.422** (Store → Core Updates).
2. SSH into the app folder and run
   `php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae`.
   The output must say **RISK 0** before you go on. If it lists pictures still
   on WordPress, copy them across first with Store → Import → Addresses & pictures,
   while WordPress is still online.
3. If WordPress still takes orders, run one last import of orders and customers.
   Then **Platform → Domain switch → 1b Payments ready? → Check everything**:
   no red. Test each gateway as `docs/PAYMENT-TEST-PLAN.md` says.
4. Go to **Platform → Site address**:
   - Main address: `kbeautybliss.com`
   - Old addresses: `extrabeauty.ae`
   - Forward permanently: **OFF**

   Save. extrabeauty.ae keeps working exactly as before.
5. In **Cloudways → Servers → your server**, check that the Public IP is
   `134.209.147.13`. That is the address extrabeauty.ae resolves to today.
6. In **Cloudways → Applications → your app → Domain Management**, add
   `kbeautybliss.com` and `www.kbeautybliss.com`. Keep extrabeauty.ae for now.

6a. **Turn the Coming Soon page on — before the DNS change.**
   **Appearance → Coming Soon page**: Show it **On**, Which address
   **Only this address: kbeautybliss.com** (www is included), check the words
   in the preview, **Save**. The status line must read
   *"ON — kbeautybliss.com (and www.kbeautybliss.com) shows the Coming Soon
   page; extrabeauty.ae shows the shop."* Then press **Copy** under *Secret
   preview link* and send it to your phone (it lasts 24 hours; **New link**
   makes another and stops the old one).
   From now on, anyone who reaches kbeautybliss.com sees "Something new is
   coming" (HTTP 503, not indexed, never cached); extrabeauty.ae is untouched.
   You see the real shop there when you are signed in (a small dark notice says
   so) or after opening the preview link on that phone.

   Locked out, or it misbehaves? Over SSH in the app folder:
   `php artisan kbb:coming-soon off` — the shop shows again on the next request.

## B. DNS at Internet.bs
7. Go to **Internet.bs → kbeautybliss.com → DNS Management** and create these records:

   | Type | Name | Value |
   |---|---|---|
   | A | `@` | `134.209.147.13`, TTL 300 |
   | CNAME | `www` | `kbeautybliss.com` |
   | MX | `@` | `smtp.google.com`, priority 1 |
   | TXT | `@` | `v=spf1 include:_spf.google.com ~all` |

   Do not create an AAAA record.
8. Under **Nameservers**, switch to Internet.bs's own DNS nameservers.
9. Wait until `dig +short kbeautybliss.com @8.8.8.8` returns `134.209.147.13`.
   This takes anywhere from a few minutes to 48 hours.

## C. Certificate and address
10. Go to **Cloudways → SSL Certificate → Let's Encrypt** and enter all four names:
    `kbeautybliss.com`, `www.kbeautybliss.com`, `extrabeauty.ae`, `www.extrabeauty.ae`.
    extrabeauty.ae stays on the certificate during the forwarding period in Part F.
11. Open `https://kbeautybliss.com/<admin path>` and sign in. Then go to
    **Platform → Site address** and press **Use this address from now on**.
12. Go to **Store → SEO & Meta → Settings → Search appearance**, set **Site URL**
    to `https://kbeautybliss.com`, and save.
13. In Cloudways, make kbeautybliss.com the **primary domain** and press
    **Purge Varnish**. Then in the shop, go to **Platform → Cache** and press
    **Clear everything**.
14. **Platform → Domain switch → 6b Old links in the shop's text → Show what
    would change → Change these links.** (Undo is offered.) Then run
    `kbb:domain-check` again. The output must say **RISK 0**.

## D. Payments and apps (extrabeauty.ae must still be listed under Old addresses)
15. **Stripe:** go to Store → Payments → Stripe and press **Set up webhook
    automatically**. This also deletes the old extrabeauty.ae webhook. Then in
    the Stripe dashboard, add `kbeautybliss.com` under Payment method domains.
16. **Tabby:** press **Register / re-sync**. This also removes the old webhook.
17. **Tamara:** press **Remove**, then **Register**.
18. **Instagram:** add the callback address shown on Content → Instagram to the Meta app.
19. Upload `wp-content/uploads` from your WordPress backup to Cloudways at
    `public_html/wp-content/uploads/`. This keeps old picture links working.

## E. Test
20. Press **1b Payments ready? → Check everything** again: every webhook must
    read green, "the main address". Place test orders: card, cash on delivery,
    Tabby and Tamara. Then check
    each of these:
    - order emails show kbeautybliss.com links
    - password reset works
    - the Arabic shop works
    - Instagram connects
    - the phone app installs from kbeautybliss.com

20a. **Turn the Coming Soon page off** — only after every test above passed:
    **Appearance → Coming Soon page** → Show it **Off** → **Save** (or
    `php artisan kbb:coming-soon off`). The status line reads *"OFF — every
    address shows the shop."* Open https://kbeautybliss.com in a private window:
    the shop, no notice. Nothing needs purging — the Coming Soon page is never
    cached — but if anything looks stale, Cloudways → Varnish → **Purge**.
    Do this **before** step 21: with forwarding on and Coming Soon still on,
    extrabeauty.ae visitors would be sent into the Coming Soon page (the
    screen warns you if that is ever the case).

### What each step does while the Coming Soon page is on (kbeautybliss.com only)

| Step | With Coming Soon ON |
|---|---|
| 7–9 DNS | `dig` is unaffected. As DNS spreads, visitors reaching kbeautybliss.com get the Coming Soon page instead of Hostinger. |
| 10 Certificate | Unaffected. Let's Encrypt checks `/.well-known/acme-challenge/…`; everything under `/.well-known/` is always let through. |
| 11 Sign in, "Use this address" | Unaffected: the admin address is always let through. Once signed in you see the real shop on kbeautybliss.com, with the notice. |
| 12 Site URL, 13 Varnish purge / Clear everything | Unaffected. The page is sent `no-store`, so no cache holds it. |
| 14 Old links, `kbb:domain-check` | Unaffected. The wizard's certificate check reads `robots.txt`, which answers (`Disallow: /` while on). |
| 15–18 Stripe, Tabby, Tamara, Instagram | Unaffected: every webhook (`/api/payments/webhook/…`), Stripe Connect and the Instagram callback (`/admin-api/…`) are let through. |
| 19 Uploads | Unaffected: picture and file addresses are let through. |
| 20 Test orders | Signed in, or with the preview link: the real shop. Card confirm (`/checkout/card/…`), the return pages (`/checkout/success`, `/checkout/pending`) and the webhooks are let through for everyone, so a payment always lands. Password-reset, verify and unsubscribe links work for anyone. The phone app's manifest is let through, so it installs. |
| Meanwhile on extrabeauty.ae | The shop as always. **Once step 11 is done**, emails sent to real customers link to kbeautybliss.com: order and account links there show the Coming Soon page to a customer who is not signed in, until 20a. Keep the gap between 11 and 20a to a few hours. |
| 21 Forwarding | Turn Coming Soon off first (20a). |

## F. Move visitors over gently (this is what keeps anything from breaking)
21. Go to **Platform → Site address** and set **Forward permanently: ON**.
    Every old extrabeauty.ae link, bookmark and installed app now lands on
    kbeautybliss.com.
22. Leave this on for **2–4 weeks**. That gives customers with the old app and
    old links time to move across.

## G. Remove extrabeauty.ae completely
23. Run `kbb:domain-check` one more time. The output must say **RISK 0**.
24. Go to **Platform → Site address**, clear **Old addresses**, and save.
    Also go to **Platform → Users & Roles → Owner app → Security → Own host**
    and clear it if it names extrabeauty.ae.
25. In **Cloudways → Domain Management**, remove `extrabeauty.ae` and
    `www.extrabeauty.ae`. Reissue Let's Encrypt for `kbeautybliss.com` and
    `www.kbeautybliss.com` only. Press **Purge Varnish**. In the shop, go to
    **Platform → Cache** and press **Clear everything**.
26. At extrabeauty.ae's registrar, remove its A record.
27. In the Stripe dashboard, remove `extrabeauty.ae` from Payment method domains.

## H. Hostinger
28. Wait until `dig +short NS kbeautybliss.com @8.8.8.8` has shown Internet.bs
    for 2 days. Then delete the domain and the site from Hostinger and cancel
    the plan.
