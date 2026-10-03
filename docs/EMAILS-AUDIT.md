# Emails: audit (Lane RJ, Phase 1)

**What was asked:** check that every email works, show the templates, check delivery to the inbox and that every order status is covered, then plan an "Emails" menu and Email Marketing.
**Scope of this lane:** audit, previews and plan only. No code, setting, route, migration or template that ships has been changed.
**Repo state audited:** `claude/kind-mayer-rpqesv` at `cc216ae` (release 2.60.367).

The pictures referred to below are in `docs/rj-email-previews/`. To review everything in one scroll, open **`OVERVIEW.png`**.

---

## 0. The short version

1. **Every email the shop has today renders and sends.** I ran 34 mail test files (394 tests) and all passed (`storage/rj-logs/mailtests.out`, not committed). I also rendered all 13 messages through the real classes; none failed and no template variable was missing (`docs/rj-email-previews/before/index.json`).
2. **Only two order statuses send their own email: `shipped` and `cancelled`.** The order confirmation goes out when an order is placed. The refund email follows the money, not the status. `pending`, `processing`, `onhold`, `completed`, `failed` and `draft` send nothing (§2). There is **no "delivered" email**, and there is **no tracking number field** for a "shipped" email to print.
3. **There are two visual families.** The six order emails (confirmation, alert, shipped, cancelled, refund, invoice) are branded. The other seven are plain black on white with no logo: basket reminder, back in stock, account invite, newsletter confirm, quiz plan, password reset and verify email. Order emails have **no product pictures**.
4. **Mail is sent through "this server's mail" by default** (PHP `mail()` → the host's mail program), or through SMTP if the owner picks it. Everything is sent synchronously and nothing is queued. The schedule needs a cron line that the repo cannot prove is installed (§3).
5. **Deliverability:** the From, Reply-To, Message-ID and text part are in good shape. What decides inbox or spam now is **SPF, DKIM and DMARC on extrabeauty.ae**, which the repo cannot see (§4). The checklist for the owner is in §4.3.
6. **Marketing:** a consent list exists (`subscribers`, double opt-in). Order and spend figures can be worked out correctly per customer. There is **no marketing-consent field on customers or at checkout** (§5).
7. **Bugs found: five.** Each one is proven by a probe in `tests/Support/rj-probes/RjEmailAuditProbesTest.php`, and all five probes pass (§6). The one that matters most: **a card, Tabby or Tamara order gets "we are packing it with care" before the shopper has paid.**

---

## 1. Every email the shop sends

"On/off" means the owner can switch the email off today. "Wording" means the owner can edit what it says.

| # | Email (class) | Trigger | To | Subject | Template | Text part | On/off today | Wording editable today |
|---|---|---|---|---|---|---|---|---|
| 1 | Order confirmation (`app/Mail/OrderConfirmation.php`) | Order placed: `CheckoutController.php:894` → `OrderMailer::placed()` (`OrderMailer.php:129`, send at `:160`). Re-send: Store → Orders → order → **Resend confirmation email** (`AdminOrderController.php:70`, `OrderMailer.php:195`) | Customer | `Your {store} order {number}` (`OrderConfirmation.php:29`) | `emails/order-confirmation(.blade/-text)` | Yes | Yes: module `email_order_confirmation` (`OrderMailer.php:73`, `ModuleRegistry.php:435`), under **Store → Modules** | No. English is in code (`InterfaceStrings.php:2250-2262`). Arabic can be edited under Translation → Strings |
| 2 | New-order alert (`NewOrderAlert`) | Same moment (`OrderMailer.php:173`) | `mail_merchant_address`, otherwise the From address (`OrderMailer.php:440`) | `New order {number} — {total}` | `emails/new-order-alert` | Yes | Yes: `email_merchant_new_order` (`OrderMailer.php:78`) | No |
| 3 | Order shipped (`OrderStatusChanged`, `shipped`) | `orders.status` becomes `shipped`. Comes from the observer (`OrderMailObserver.php:50-60`) and from bulk status changes (`OrderMailer.php:362`) | Customer | `Your {store} order {number} is on its way` | `emails/order-status` | Yes | Yes: `email_order_shipped`, under **Store → Mail → Order status emails**. Each order can also override it | Partly: **Store → Mail → "Dispatch email: how long delivery takes AFTER DISPATCH"** (`MailSettings.php`, `mail_shipped_timing_note`) |
| 4 | Order cancelled (`OrderStatusChanged`, `cancelled`) | Status becomes `cancelled` | Customer | `Your {store} order {number} has been cancelled` | `emails/order-status` | Yes | Yes: `email_order_cancelled`, same screen | Partly: **Store → Mail → "Cancelled orders: what you tell a paid customer…"** |
| 5 | Refund sent (`OrderRefunded`) | A `refunds` row settles as `succeeded` (`OrderMailObserver.php:128`, `:181`). It does **not** fire when someone types `refunded` as the status | Customer | `Refund sent for your {store} order {number}` | `emails/order-refunded` | Yes | Yes: `email_order_refunded` (`OrderMailer.php:93`), under Store → Modules | No |
| 6 | Invoice (`OrderInvoice`) | Manual only: Store → Orders → order → **Email invoice** (`AdminOrderController.php:70`, `OrderMailer.php:279`) | Customer | `Invoice {ref} for your {store} order {number}` | `emails/order-invoice` + PDF | Yes | Manual action only | No |
| 7 | Back in stock (`BackInStockAlert`) | Outbound sweep that runs at the end of a page request (`OutboundTick.php:124` every 300 s, budget 10 per sweep; `OutboundSender.php:94-106`) | Shopper who asked | Owner-written | `emails/back-in-stock` (**not branded**) | Yes | Module `back_in_stock`, **off** by default (`ModuleRegistry.php:603`) | Yes: under Store → Ecommerce → Product page |
| 8 | Basket reminder (`CartRecoveryReminder`) | Same sweep (`OutboundSender.php:145-167`) | Shopper who asked | Owner-written | `emails/cart-recovery` (**not branded**) | Yes | Module `abandoned_cart`, **off** by default (`ModuleRegistry.php:602`) | Yes: under Store → Ecommerce → Cart |
| 9 | Account invite (`CustomerAccountInvite`) | Store → Customers → **Send account invite**. Sent in AJAX batches (`CustomerInviter.php:415-426`) | Imported customer | Owner-editable, default `Your {shop_name} account is ready` | `emails/customer-invite` (**not branded**) | Yes | Manual only | Yes: in the invite screen (`InviteTemplate.php:70`) |
| 10 | Newsletter confirm (`NewsletterConfirmation`) | Homepage sign-up (`SubscribeController.php:154-156`) | Subscriber | `Please confirm your {store} subscription` | `emails/newsletter-confirm` (**not branded**) | Yes | Follows the newsletter module | No |
| 11 | Quiz plan (`QuizPlanEmail`) | Skin quiz submitted with an email (`Api/QuizController.php:425-428`) | Shopper | `Your {store} skin quiz plan` | `emails/quiz-plan` (**not branded**) | Yes | Follows the quiz | No |
| 12 | Password reset (`Notifications/CustomerPasswordReset`) | Forgot password (`PasswordResetController.php:120`, `Customer.php:184-186`) | Customer | `Reset your K Beauty Bliss password`. **The name is hard-coded** (`CustomerPasswordReset.php:47`) | `store/account/mail/password-reset` (**not branded**) | **No** (B2) | No, and it should not be switchable | No |
| 13 | Verify email (`Notifications/CustomerEmailVerification`) | Account sign-up / resend (`Customer.php:246-248`) | Customer | `Confirm your email address`. **No store name** | `store/account/mail/verify-email` (**not branded**) | **No** (B2) | No | No |
| — | Test message (`MailTester.php:193-203`) | Store → Mail → **Send test message** (`routes/mail-admin.php`, throttled to 6 a minute) | Address typed in | `Test message from your store` | raw text | Text only | — | — |

Today's renders are in `docs/rj-email-previews/before/` (`*.html` holds the HTML part, `*.txt` the text part, `*.headers.txt` the real headers). Screenshots are in `before/shots/` at 390 and 600, plus `01-order-confirmation-1280.png`.

---

## 2. Order statuses: the real values and what each one sends

**The status vocabulary the code defines** (`orders.status` is a free-form string: `create_kbb_schema.php:399-401`):
`draft, pending, processing, onhold, shipped, completed, cancelled, refunded, failed`

Sources: `AdminController.php:3556` (the validation rule), `OrderStatusMailPolicy.php:66` (`STATUSES`), `OrdersApiController.php:117` (`KNOWN_STATUSES`). Of these, `Order::REAL_STATUSES` (`Order.php:22`) counts `processing, onhold, shipped, completed` as revenue. A bulk change can set any status except `draft`, `refunded` and `failed` (`OrdersApiController.php:130`). Imported WooCommerce orders may also carry `on-hold` and custom values such as `wc-tamara-p-failed` (`OrderImporter.php:65`, `PlacementState.php:83`).

| Owner's word | Real value | Email today | Why it is silent (`OrderStatusMailPolicy.php:103`) |
|---|---|---|---|
| (unpaid) | `pending` | none | "the confirmation email covers it". But see B1: for card, Tabby and Tamara the confirmation is sent **while** the order is `pending` |
| processing | `processing` | none for the status change itself. The **order confirmation** is sent when the order is placed | Avoids sending a second email in the same second |
| hold | `onhold` | **none** | "internal bookkeeping" |
| shipped | `shipped` ✅ exists | **Order shipped** (`OrderStatusChanged::WORDING['shipped']`, `OrderStatusChanged.php:60-74`) | — (on by default) |
| completed / delivered | `completed` | **none** | "the dispatch email is what the customer is waiting for". There is **no `delivered` status**: `completed` is the closest |
| cancelled | `cancelled` | **Order cancelled** | — (on by default) |
| refunded | `refunded` | none for the status. **Refund sent** goes out when a refund actually settles, including partial refunds | Avoids sending two emails for one refund |
| failed | `failed` | **none** | "the shopper is already looking at the error". That is not true when a hosted payment is abandoned later |
| — | `draft` | none | Never placed |

Of the statuses the owner named, all exist except **"delivered"** (the closest is `completed`) and **"hold"** (the real value is `onhold`). "Shipped" does exist as its own status.

**Today, only `shipped` and `cancelled` can be switched on.** `OrderStatusMailPolicy::MODULE_KEYS` (`:89`) lists only those two. Every other status shows a disabled tick box with the reason next to it (see the admin-before screenshot `docs/rj-email-previews/admin-before/store-mail-1280.png`).

---

## 3. How mail is actually sent

**Transports in code** (`MailSettings.php:189-198`, `MailConfigurator.php:265-310`):

- `server` (the default). This is **"Use this server's mail"**: PHP `mail()` passed to the host's mail program (MTA) by `ServerMailTransport` (`ServerMailTransport.php:14-50`). The envelope sender is set with `-f{From}` (`:133-134`), which matters for SPF.
- `smtp`, using host, port, SSL/TLS, username and an encrypted password. `local_domain` is the APP_URL host (`MailConfigurator.php:308`). If the SMTP form is half filled in, sending **falls back to server mail** (`:282`).
- `log`. Nothing is sent; this is only for testing.

**Default mailer:** `config/mail.php:29` reads `env('MAIL_MAILER', 'kbb')`. Order, outbound, invite, quiz and newsletter mail always name the `kbb` mailer. **The two account notifications do not** (see B3), so they follow `MAIL_MAILER` if `.env` sets it.

**Admin screen today:** **Store → Mail** (`resources/views/admin/app.blade.php:12654-12720`, `paintMail()`). It has these sections:

- How email leaves this store
- Mail server (SMTP)
- Who the message comes from (From address, From name, New-order alerts to)
- What customers see at the foot (support email, WhatsApp, Instagram, signature)
- Other settings (the cancelled-order money note, the dispatch timing note, Reply-To)
- **Order status emails** (on/off per status)
- **Send a test**
- **Waiting to go out**
- **Sent mail**, the delivery log, stored in `mail_deliveries` (bodies are never stored)

The settings are saved as `settings` rows. The password is kept in the encrypted vault.

**Synchronous or queued:** synchronous. No Mailable implements `ShouldQueue` (`app/Mail/OrderMail.php:16-28`), and `NothingHereOutlivesOneRequestTest` pins this. Order mail sends inside the request that placed or updated the order. Every failure is caught and logged, so a mail error never fails a checkout (`OrderMailer.php:566-633`).

**Worker / cron:** no queue worker is expected. The outbound reminders run at the end of normal page requests (`OutboundTick.php`, every 300 s). A scheduler exists (`routes/console.php:58` cover cutting every minute, `:96` Tamara capture every hour) and needs **one cron line**:
`* * * * * cd …/kbb-app && php artisan schedule:run`
To add it on Cloudways: **Application Settings → Cron Job Management** (`docs/SERVER-PROC-OPEN.md` §3b).

**What the repo cannot tell, and how the owner can check each one:**

| Unknown | How the owner checks |
|---|---|
| Is the live shop on Server mail or SMTP, and which From address? | **Store → Mail → "How this store sends email"** and **"From address"** |
| Does mail actually leave? | **Store → Mail → Send a test → "Send test message"** to a Gmail address. Then, in Gmail, open the message → ⋮ → **Show original** and read the **SPF / DKIM / DMARC** lines |
| Did a given customer's email go? | **Store → Mail → Sent mail** (set "Show" to "Everything") |
| Is `MAIL_MAILER` set in `.env`? (It changes where password-reset mail goes; see B3.) | SSH: `grep MAIL_ .env` in the app root. If nothing is printed, all mail uses the same `kbb` mailer, which is the safe state |
| Is the schedule cron installed? | Cloudways → Application Settings → Cron Job Management, or SSH `crontab -l` |
| Does Cloudways' server allow `mail()`? | The test send answers this. If it fails, the message names `disable_functions` (`ServerMailTransport.php:96-100`) |

Cloudways servers are generally not set up to deliver outbound mail well, and Cloudways recommends its SMTP add-on or an outside SMTP service. That is general knowledge, not something verified on this server. **The test send in Store → Mail is the real check.**

---

## 4. Deliverability

### 4.1 What the messages carry (measured from the renders)

`docs/rj-email-previews/before/*.headers.txt`, with `mail_from_address=hello@extrabeauty.ae` set in the preview:

| Item | State | Evidence |
|---|---|---|
| From | The owner's address. If left blank, it becomes `no-reply@{APP_URL host}`, so it is always on the shop's own domain | `MailSettings.php:668-690` (`fromAddress()`) |
| Reply-To | Set only when the owner fills it in. It is applied to **all** messages, including the merchant alert | `MailSettings.php:700`, `MailConfigurator.php:118-126` |
| Message-ID | `<…@extrabeauty.ae>`, taken from the From domain (Symfony `Message::generateMessageId`) | `01-order-confirmation.headers.txt` |
| Text part | Present on 11 of 13 messages. **Missing on password reset and verify email** | `before/index.json`, probe B2 |
| List-Unsubscribe | On back in stock, basket reminder and newsletter confirm. **No `List-Unsubscribe-Post`**, and the URL points to a page with a form (GET), which is not RFC 8058 one-click | `BackInStockAlert.php:91-97`, probe B5 |
| Images | None in any email except the optional logo. That is good for spam scoring but plain for shoppers | `before/index.json` (`"images": 0`) |
| Links | Only to the shop, wa.me and instagram.com. No link shorteners, no third-party trackers | `before/index.json` |
| Envelope sender (Return-Path) | Set with `-f{From}` on server mail, so SPF is checked against the From domain | `ServerMailTransport.php:133-134` |

**Nothing in the code would hurt inbox placement today.** The risk is the domain and the server, which comes next.

### 4.2 What decides inbox vs spam, and what is unknowable here

I **cannot see the DNS for extrabeauty.ae** or the Cloudways server's IP reputation. Nothing below claims what the domain currently has.

When the shop sends through **server mail** on Cloudways, mail leaves from the server's own IP:

- **SPF** must authorise that IP.
- **DKIM** signing usually does not happen at all on a plain server MTA.

Without DKIM, Gmail and Yahoo treat mail with suspicion, and a DMARC `p=quarantine` or `p=reject` would make it fail. This is the most common reason for "my order emails go to spam".

**Recommendation (owner decision D1):** send through a proper SMTP service that signs DKIM for extrabeauty.ae, using the existing SMTP option. Options: Cloudways' Elastic Email add-on, Google Workspace, Brevo, Amazon SES or Postmark. No code change is needed for transactional mail.

### 4.3 Checklist for the owner (extrabeauty.ae)

Do these in order, at whoever hosts the DNS for extrabeauty.ae (Cloudflare, the registrar, etc.):

1. **Pick the sender.** Choose the SMTP service. Enter its host, port, username and password in **Store → Mail** (later **Emails → Sending & delivery**). Set the From address to a real mailbox on extrabeauty.ae, for example `hello@extrabeauty.ae`, and the Reply-To to a mailbox someone reads.
2. **SPF.** Add exactly **one** TXT record on `extrabeauty.ae` that starts `v=spf1`. It must include the provider's `include:` value. If you keep server mail, it must also include the server's IP (`ip4:x.x.x.x`). End it with `~all`. Never publish two SPF records.
3. **DKIM.** In the provider's dashboard, add domain `extrabeauty.ae`. The provider gives one to three CNAME or TXT records like `xxxx._domainkey`. Add them, then press "verify" in the provider's dashboard.
4. **DMARC.** Add a TXT record on `_dmarc.extrabeauty.ae`: `v=DMARC1; p=none; rua=mailto:dmarc@extrabeauty.ae`. Watch the reports for 2–4 weeks, then move to `p=quarantine`.
5. **MX.** Make sure `care@` and `hello@` really receive mail, because replies go there.
6. **Test.** Press **Send test message** to a Gmail address. Open it → ⋮ → **Show original**. All three lines must read **PASS**: SPF, DKIM and DMARC.
7. **Reverse DNS (PTR).** This applies only if you stay on server mail. Ask Cloudways to set it for the server IP.

### 4.4 Bulk-sender rules (these apply to the marketing half only)

Since February 2024, Gmail and Yahoo require the following from anyone sending more than about 5,000 messages a day to their users. The 5,000 is their stated threshold. Below it, the same items still decide whether mail lands in the inbox.

- SPF **and** DKIM, with DMARC published and aligned to the From domain.
- **One-click unsubscribe** (`List-Unsubscribe` plus `List-Unsubscribe-Post: List-Unsubscribe=One-Click`), honoured within 2 days, and a visible unsubscribe link in the body.
- **Spam-complaint rate below 0.3%** (target below 0.1%) in Google Postmaster Tools.

Two more requirements are general good practice and the norm under most countries' laws, including UAE practice: send only to people who opted in or bought before, and print the sender's identity and a postal address.

Today's code meets none of the one-click part (B5). The plan builds it before the first campaign.

---

## 5. Customer data available for groups (segments)

**`customers`** (`create_kbb_schema.php:198-226`, plus later migrations) has these columns:

- id, wp_user_id, name, first_name, last_name, **email (unique)**, email_verified_at, password / legacy_password, phone
- **`whatsapp_optin`**, notes, created_at, deleted_at
- `invited_at`, `invite_count`, `invite_accepted_at` (`2027_07_12_000000_add_customer_invites.php`)
- stripe ids

**Do not use `orders_count`, `total_spent` or `last_order_at`.** They are deliberately unmaintained and are always 0 (`Customer.php:63`, `UNMAINTAINED_COLUMNS`). Using them would make every customer look like "never ordered".

**Correct aggregates already exist:** `CustomersApiController::rowQuery()` (`CustomersApiController.php:565-640`) works out per customer, in one statement:

- `paid_orders` (orders in `REAL_STATUSES`)
- `spend_fils` (net of counted refunds)
- `paid_last_at`
- `all_orders`
- last cart activity, and city / country

It also has preset chips: `ordered, never, repeat, account, guest, invited, verified, unverified` (`:104`, `:849-866`), and filters for spend min/max, country, city and dates (`:539-545`). **Segments must reuse this query** so that "spent AED 500+" in a campaign means the same as on Store → Customers.

**Caveat:** guest checkouts with no `customer_id` are left out of these aggregates (`whereNotNull('orders.customer_id')`, `:615`). How many orders that is cannot be known from the repo; the plan has a query for it. A guest who bought but never got a customers row would be missing from every group (owner decision D4).

**Consent data that exists:**

| Source | What it records | Use for marketing? |
|---|---|---|
| `subscribers` (`2026_09_01_000000_create_subscribers.php`) | email, source, `status` (pending / subscribed / unsubscribed), `confirmed_at`. Double opt-in, with older rows grandfathered (`2026_11_09_000001_newsletter_double_optin.php`) | **Yes.** `NewsletterList::marketable()` = subscribed + confirmed (`NewsletterList.php:126-131`). Unsubscribe links last 10 years (`:102`) |
| `outbound_optouts` (`2026_11_10_000002_create_outbound_optouts.php`) | Emails that opted out of back-in-stock and basket reminders | Must be **respected** (treated as "do not market") |
| `customers.whatsapp_optin`, `orders.whatsapp_optin` | WhatsApp only | No, this is not email consent |
| Checkout / account | **No email-marketing tick box exists** | — |
| `quiz_submissions.consent` | Consent to the quiz follow-up only; the quiz email promises "not added to any list" | **No** |

What this means: today the only clean marketing list is the **confirmed subscribers**. Mailing existing customers who never subscribed relies on the "customers who bought before" ground. Whether that ground is acceptable for this shop is **owner decision D3**; I am not making a legal claim here. Whatever is decided, every campaign must carry a working unsubscribe link, and an unsubscribe must cover all marketing.

---

## 6. Bugs found (each one proven)

**Probe file:** `tests/Support/rj-probes/RjEmailAuditProbesTest.php`. It sits outside `tests/Feature`, so the default suite does not run it. Each case **passes because the defect is present**. Run it with:
`KBB_WP_DB=kbb_wp_rj vendor/bin/pest tests/Support/rj-probes/RjEmailAuditProbesTest.php` → 5 passed.

| # | Defect | Shop impact | Proof |
|---|---|---|---|
| **B1** | **The order confirmation is sent before payment** for card, Tabby and Tamara. `placed()` runs once the gateway has *started* (`CheckoutController.php:894`), while the order is `pending`. A later move to `processing` sends nothing, and a later `failed` sends nothing. | A shopper who abandons the card form or Tabby still holds "Your order is in and we are packing it with care" for an order that was never paid. The comment at `:875-893` says this is deliberate. It is a behaviour choice that is wrong for the customer, so it is **owner decision D2**, not a silent fix. | Probe B1: a Stripe order placed with the intent open has `status=pending` and `paid_at=null`, and `OrderConfirmation` was sent |
| **B2** | Password reset and verify email are **HTML-only** with no text part, and they use an unbranded layout | They score slightly worse with spam filters and look unlike every other email | Probe B2, plus `before/index.json` (`has_text_part: false`) and `before/shots/12-password-reset-390.png` |
| **B3** | Account notifications follow `MAIL_MAILER` rather than the `kbb` mailer that Store → Mail configures and tests | If `.env` sets `MAIL_MAILER` (for example `smtp` with an empty host, as `env.staging.txt:45` does), order mail works but **password reset fails**. The test send would still say "all fine", and its body claims password reset "can be switched on" (`MailTester.php:195-198`) | Probe B3: `MailMessage->mailer === null` |
| **B4** | The address prints **"Dubai Dubai"** (city + state, even when they are the same word) and **"AE"** instead of the country name | Looks careless on every order email | Probe B4. Visible in `before/shots/01-order-confirmation-390.png` |
| **B5** | No **one-click unsubscribe** (`List-Unsubscribe-Post`), and the header URL is a GET page with a form | Fine for today's reminders. **Blocks bulk marketing** under Gmail and Yahoo rules | Probe B5 |

**Smaller findings (no probe needed; each can be seen in the file):**

- The password-reset subject hard-codes "K Beauty Bliss" (`CustomerPasswordReset.php:47`). The verify-email subject has no store name.
- The Store → Mail banner text says "Set **Send using** to `smtp`" (`app.blade.php:12659`), but the field is labelled "How this store sends email" and its options are sentences.
- The "View / Track your order" button opens only in the browser that placed the order (`CheckoutController.php:1356-1377`). The email says so itself, but on a phone, where most email is read, the button usually shows "not found".
- Seven emails have no branding and no logo (§1).

**Not bugs, checked:** I found no broken template and no missing variable. All 13 messages render, and 394 mail tests pass. My cart-recovery fixture first left out `unit_price`; the real `CartRecovery::basket()` supplies it (`CartRecovery.php:592`).

---

## 7. Files this lane added

**Harness** (none of it ships):

- `tools/rj-preview.sh`, `tools/rj-seed.php`
- `tools/rj-render.sh`, `tools/rj-render-emails.php`
- `tools/rj-email-kit.cjs`, `tools/rj-build-after.cjs`
- `tools/rj-shots.cjs`, `tools/rj-admin-before.cjs`, `tools/rj-admin-mockups.cjs`
- `tools/rj-overview.cjs`, `tools/rj-all.sh` (`sh tools/rj-all.sh` regenerates everything)

**Probes:** `tests/Support/rj-probes/RjEmailAuditProbesTest.php`

**Previews** (`docs/rj-email-previews/`):

- `before/`: today's 13 emails, as HTML, text and headers, plus shots at 390 and 600. The order confirmation is also shot at 1280.
- `directions/`: A, B and C versions of the order confirmation, shot at 390 and 600. A is also shot at 1280 and in dark mode.
- `after/`: 17 proposed emails in direction A, one per real status plus all the others, shot at 390 and 600.
- `marketing/`: 2 sample campaigns built from builder blocks.
- `admin-before/`: today's Store → Mail, Growth → Newsletter and the sidebar.
- `admin/`: 11 proposed admin screens at 1280 and 390, the proposed sidebar on desktop and on a phone, the static HTML for each, and `admin-base.css`.
- `OVERVIEW.html` and `OVERVIEW.png`.
