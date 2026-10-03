# Emails: build plan (Lane RJ, Phase 1 → Phases 2–5)

This plan is written to be built **after the owner picks a direction (A, B or C) and answers the decisions in §9**. Everything here was checked against the code. The evidence is in `docs/EMAILS-AUDIT.md`, and the pictures are in `docs/rj-email-previews/` (start with `OVERVIEW.png`).

The rules from CLAUDE.md apply to every package:

- Build with `php artisan kbb:package`.
- Any package that adds routes also ships a `clear_caches_*` migration.
- Every change comes with screenshots at 390 and 1280, plus the admin path it lives at.
- Every fix comes with the test that goes red without it.
- Every new admin endpoint gets its own capability and fails closed.
- Nothing marketing-related goes on `/api/*`.

---

## 1. What gets built, in four packages

| Pkg | Name | Contents | Depends on |
|---|---|---|---|
| **E1** | Emails menu + one look for every email | See below | Direction letter (D5) |
| **E2** | Every status, and editable wording | See below | E1 |
| **E3** | Email Marketing core | See below | E1, D3, D4, D9 |
| **E4** | Schedule, reports, polish | See below | E3 |

**E1 — Emails menu and one look for every email**

- Adds the new **Emails** parent menu (Overview, Sending & delivery, Customer emails, Design & branding, Sent mail).
- Moves **Store → Mail** into it; nothing is lost, and the old id redirects.
- Puts all 13 existing emails on the chosen layout, with product pictures in the order emails.
- Fixes B2, B3 and B4.
- Adds a DNS check panel (read-only).
- Adds "Send test" for any email.

**E2 — Every status, and editable wording**

- New emails for **on hold**, **delivered (`completed`)**, **payment failed** and **complete your payment** (`pending`). The last one depends on D2.
- On/off per email.
- A wording editor (subject, preview line, headline, message, blocks) with live preview, in English and Arabic, with "Reset to default".
- New **tracking number and courier** fields on an order (D8).
- A signed **View my order** link (D7).
- The B1 fix (D2).

**E3 — Email Marketing core**

- **Growth & Marketing → Email Marketing**.
- The drag-and-drop **template builder**.
- **Customer groups** (segments) with live counts.
- **Campaigns**: test send, send now, and admin-driven sending.
- **One-click unsubscribe** and suppression.

**E4 — Schedule, reports, polish**

- Scheduled sends through the cron line.
- Click tracking.
- Orders attributed to a campaign.
- Report screen.
- Daily cap.
- Housekeeping that prunes old send rows.

Release cadence: 2 or 3 packages per round, per CLAUDE.md. **E1 and E2 can ship in one round. E3 and E4 go in the next.**

---

## 2. Data model

All tables are new, so the only migrations are `Schema::create`, with no `->after()` (see `MigrationConventionTest`). The orders change is three nullable columns added with `Schema::table`, guarded by `hasColumn`. Every migration is idempotent, because packages are sometimes applied twice.

### 2.1 Transactional (E1/E2)

The **look** is stored as `settings` rows, the same way Store → Mail already does it (`MailSettings::SCHEMA`):

- `email_direction` (`A|B|C`)
- `email_accent`, `email_button`, `email_background` (hex, validated)
- `email_logo_media_id`
- `email_head_font` (a select that stores one of its own options)
- `email_postal_address`
- social links (scheme-checked)

**Wording overrides** go in a new table, `email_templates`:

```
email_templates
  id
  key           string(60)    e.g. order.shipped, order.onhold, account.password_reset  (closed list in code)
  locale        string(8)     en | ar
  subject       string(200)   nullable   single line, CRLF refused
  preheader     string(200)   nullable
  heading       string(200)   nullable
  body          text          nullable   plain text + {tags}; never HTML
  blocks        json          nullable   {"tracker":true,"items":true,"totals":false,...} — closed key list
  enabled       boolean       default true (only for keys that are switchable)
  updated_by    unsignedBigInteger nullable
  timestamps
  unique(key, locale)
```

**A blank field means "use the built-in wording"**, which is what ships today. Applying the package therefore changes no email's words; the existing `InterfaceStrings` stay the defaults. The on/off switches keep their existing module keys (`email_order_shipped`, etc.), and the new statuses get new keys (`email_order_onhold`, `email_order_completed`, `email_order_failed`, `email_order_pending_payment`). `OrderStatusMailPolicy::MODULE_KEYS` and `OrderStatusChanged::WORDING` grow to cover them.

**Orders** (D8) get three new nullable columns: `tracking_number` string(80), `courier` string(60) and `tracking_url` string(255). The URL is scheme-checked (`https` only) before it becomes an `href`. The order detail screen gains the three fields, and the dispatch email prints them only when they are filled in.

### 2.2 Marketing (E3/E4)

```
mkt_templates     id, name(120), subject(200), preheader(200), blocks json, thumbnail_media_id null, created_by, timestamps
mkt_segments      id, name(120), match enum(all|any), rules json, preset bool, created_by, timestamps
mkt_campaigns     id, name(120), template_id null, blocks_snapshot json (frozen at send), subject, preheader,
                  segment_id null, rules_snapshot json, status (draft|scheduled|sending|paused|sent|cancelled|failed),
                  scheduled_at null, started_at null, finished_at null,
                  recipients, sent, failed, skipped, clicks, unsubscribes, orders, revenue_fils  (unsigned ints)
                  created_by, timestamps
mkt_sends         id, campaign_id, email(191), customer_id null, subscriber_id null, token char(40) unique,
                  status (pending|claimed|sent|failed|skipped), claimed_at null, sent_at null, error(300) null,
                  first_click_at null, unique(campaign_id, email), index(campaign_id, status)
mkt_links         id, campaign_id, n (int), url(500)          -- the only URLs a click can redirect to
mkt_clicks        id, send_id, link_id, clicked_at            -- E4
email_suppressions id, email(191) unique, reason (unsubscribe|bounce|complaint|manual), source(40), created_at
```

**Blocks JSON** is a list of `{type, props}`. `type` comes from a closed list:

- `mini_header`, `hero_image`, `heading`, `text`, `button`
- `product_row`, `product_grid`, `coupon`, `image`
- `columns` (2 or 3), `divider`, `spacer`, `social`, `footer`

`footer` is required and always last. Every prop is validated against a per-type schema (lengths, enumerations, ids that must exist). **There is no raw-HTML block.** Text is plain text with three allowed marks: **bold**, *italic* and [link](url). The server renders it with every character escaped. Product and coupon blocks store **ids**, and the name, price, picture and link are read **at send time**, so a sold-out or unpublished product is dropped from the email rather than advertised.

**Unsubscribe:** one unsubscribe writes `email_suppressions` **and** sets the matching `subscribers` row to `unsubscribed`. Marketing excludes `email_suppressions ∪ outbound_optouts ∪ subscribers.status≠subscribed-where-the-ground-is-subscription`.

---

## 3. Who can be emailed (segments)

**One query, reused.** A segment compiles to the same derived aggregate that `CustomersApiController::rowQuery()` builds, so "spent AED 500+" means the same number on Store → Customers and in a campaign. That aggregate is paid orders in `Order::REAL_STATUSES`, spend net of counted refunds, and the last paid order. The stored columns `orders_count`, `total_spent` and `last_order_at` are always 0 (`Customer::UNMAINTAINED_COLUMNS`) and are **never** read. That function is extracted into a shared `CustomerAggregates` query builder (it moves and keeps its tests), not copied.

**Rules** (closed list; each one compiles to a bound WHERE or HAVING):

| Rule | Operators |
|---|---|
| Number of orders | `= > < ≥ ≤ between` |
| Total spent (AED) | same as above |
| Average order (AED) | same as above |
| Last order date | `before / after / within N days / more than N days ago` |
| First order date | same as above |
| Never ordered | yes / no |
| Bought product / brand / category | — |
| Used coupon | — |
| Country / city | — |
| Has an account | yes / no |
| Newsletter status | `subscribed / not` |
| Customer since | — |

**Presets** (seeded with `preset=1`): Never ordered, One order only, Repeat buyers (2+), VIP (AED 1,000+; the owner can edit the number), Lapsed 90 days, Newsletter subscribers.

**People** are the union of `customers` and `subscribers` (confirmed), plus, if D4 is yes, guest buyers by order email. They are de-duplicated on the lower-cased email, and anyone suppressed or opted out is removed. The groups screen shows **"N match · M can be emailed"** together with the reason for the difference.

**Speed:** the count is one aggregate query with an index on `orders(customer_id, status, created_at)` if `EXPLAIN` asks for it. Under `StorefrontQueryBudgetTest`'s discipline there is no N+1, and "See the N" pages through 50 at a time.

---

## 4. Sending pipeline (built for a host with no worker)

The pattern already in the codebase is `CustomerInviter`: runs and items, an AJAX `step()` that claims at most `STEP_MAX` rows or stops after `STEP_SECONDS`, and recovery of stale claims (`CustomerInviter.php:63-88`). The share-pictures button (`ShareImagesApiController`) does the same thing. **Campaigns reuse that design, not a queue.**

1. **Freeze.** On "Send now" or at the scheduled time, the campaign's blocks and rules are snapshotted. The audience is written into `mkt_sends` with `INSERT … SELECT` in slices of 1,000. Each slice is one step and resumable, so building the list for 4,000 people never runs as one long request. `unique(campaign_id, email)` makes repeating a step harmless.
2. **Step** (`CampaignStepper::step($id)`, used by both drivers):
   - Claim up to `min(rate_per_minute, remaining_daily_cap, 25)` rows with a conditional `UPDATE … WHERE status='pending'`.
   - For each row: render (merge tags, unsubscribe token, product data from one batched query), then send through the `kbb` mailer. `MailLog::labelNext('campaign.'.$id)`.
   - Mark the row `sent` or `failed`, with the transport's own words for a failure.
   - Stop at 15 seconds.
   - Rows claimed more than 180 seconds ago go back to `pending`.
3. **Driver A: the admin's open tab** (always works). The Review & send screen calls `POST /admin-api/email-marketing/campaigns/{id}/step` until the campaign is done, showing a progress bar. This is the same as Send account invite.
4. **Driver B: the scheduler** (E4, needs the one cron line). `Schedule::command('kbb:campaigns-step')->everyMinute()->withoutOverlapping(5)` starts scheduled campaigns and continues anything left `sending`. If no tick has been seen for 10 minutes, the screen says plainly: "Scheduled sends need the cron line — Emails → Sending & delivery", and offers Driver A.
5. **Never on a shopper's request.** Unlike back-in-stock and basket reminders, marketing does not run on `OutboundTick`. Hundreds of SMTP conversations must not run on the tail of a customer's page view.

**Rate limits:** messages per minute (default 60), daily cap (default 2,000), and at most 25 per step. The test send is throttled to 6 a minute (as `/mail/test` is today). "Send campaign" is throttled to 10 a minute and needs the recipient count typed back as confirmation.

**Every campaign message carries:**

- `List-Unsubscribe: <https://extrabeauty.ae/email/u/{token}>, <mailto:unsubscribe@extrabeauty.ae?subject={token}>`
- `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
- a visible unsubscribe link and the postal address in the footer
- a text part generated from the same blocks
- a `Precedence: bulk` header
- size kept under **102 KB of HTML**, because Gmail clips anything bigger

**Bounces:** a synchronous SMTP 5xx marks that send `failed`, and a second hard failure for the same address writes a `bounce` suppression. Asynchronous bounces and complaints need the provider's webhook. That is optional in E4, and depends on D1.

---

## 5. Public endpoints (the only ones; none on `/api/*`)

| Route | What | Guard |
|---|---|---|
| `GET /email/u/{token}` | A page with an "Unsubscribe" button, plus "Email preferences" | `token` = HMAC(send_id, email, purpose) checked with `hash_equals`. A forged token and an unknown one get the same generic page (no oracle, as with `QuizSubmission::publicToken`). `throttle:20,1` |
| `POST /email/u/{token}` | RFC 8058 one-click | **The only CSRF-exempt route**, listed by exact path. Idempotent insert-or-ignore. Answers 200 the same way for every token |
| `GET /email/c/{token}/{n}` | Click redirect (E4) | Redirects **only** to `mkt_links.url` row `n` of that send's campaign, so a forged URL is impossible and there is no open redirect. If the token is unknown, it redirects to the shop's home page |
| `GET /my-account/order/{signed}` (D7) | View one order without signing in | Signed for 30 days. Shows that order only, with no account access. Same structure as the existing customer link signer (`CustomerLinkSigner`) |

---

## 6. Admin and capabilities (every one fails closed)

New screens are partials owned by this lane. The integrator wires the `@include` and `require` lines; tests pin **exactly one** include and **exactly one** require (CLAUDE.md, "do not pin that your own work is NOT wired up").

| Capability | Roles | Covers |
|---|---|---|
| `emails.view` | owner, manager | Emails → Overview, Customer emails (read), Sent mail |
| `emails.manage` | owner | Sending & delivery (as `store.settings` is today), Design & branding, wording edits, on/off |
| `emails.test` | owner, manager | Test sends (throttled) |
| `marketing.email.view` | owner, manager | Campaigns, groups, reports (read) |
| `marketing.email.manage` | owner, manager | Build templates and groups, draft campaigns |
| `marketing.email.send` | owner (manager if the owner says so) | Send, schedule, pause or cancel a campaign |
| `marketing.export` (exists) | owner, manager | Download a group as CSV |

Every route gets an explicit rule in `AdminCapabilities` **above** any wildcard that could match it (the `outbound/sweep` lesson, `AdminCapabilities.php:1493-1513`). A test walks every route in the new route files and asserts each one has a rule and that `support` and `editor` get a 403.

**Admin paths, as they will read:**

- `Emails → Overview`
- `Emails → Sending & delivery` (was Store → Mail, top half)
- `Emails → Customer emails` (was Store → Mail → Order status emails), with `→ {email} → Edit`
- `Emails → Design & branding`
- `Emails → Sent mail` (was Store → Mail → Sent mail / Waiting to go out)
- `Growth & Marketing → Email Marketing → Campaigns | Templates | Customer groups | Reports`

Store → Modules keeps the five order-email module rows, now pointing at `Emails → Customer emails`.

---

## 7. Tests owed (per CLAUDE.md §"What every lane owes")

**E1**

- Every email renders in the new layout, both HTML and text, and the text part is non-empty for **all 13** (red without the B2 fix).
- The notifications name the `kbb` mailer (B3 probe inverted).
- City and state are de-duplicated and the country name is printed (B4 probe inverted).
- `StorefrontEnglishUnchangedTest` stays green, because no storefront page changes.
- The old `mail` screen id still opens.

**E2**

- One test per status: `processing`, `onhold`, `shipped`, `completed`, `cancelled`, `failed` and the refund each send exactly one email when switched on and none when off. This covers the bulk path too, which goes through `OrderMailer::statusChanged`.
- The wording override is used, and blank falls back byte-for-byte.
- CRLF is refused in the subject.
- Arabic falls back to the English override, then to the code.
- B1 inverted: a card or hosted order gets **no** confirmation while `pending`, and exactly one when it becomes paid.
- The tracking URL is scheme-checked.

**E3**

- The builder refuses an unknown block type, a missing footer, a `javascript:` URL and a `<script>` in text (it is escaped).
- The segment count matches Store → Customers for the same filters.
- Suppressed, opted-out and pending subscribers are never in an audience.
- Two concurrent `step()` calls never send one address twice.
- A stale claim is recovered.
- One-click POST works without CSRF and is idempotent.
- The unsubscribe token oracle check holds.
- No new route sits under `api/`.
- Capability walk.

**E4**

- Click redirect refuses any URL not in `mkt_links`.
- The schedule fires once.
- Daily cap holds.
- Attribution counts only paid orders within 7 days of a click.

**Every package:** a mutation note per fix ("revert X and this is red"), plus screenshots at 390 and 1280 of each changed email and screen, using the harness in `tools/rj-*`.

---

## 8. Risks, honestly

1. **Deliverability is not a code problem.** Without DKIM and DMARC on extrabeauty.ae, the best-designed email still lands in spam. This is especially likely on Cloudways server mail, which usually sends unsigned mail from a shared-reputation IP. **Marketing should not start until the Gmail "Show original" check reads PASS on all three** (audit §4.3).
2. **No cron means no scheduled campaigns.** Driver A always works, but only while a tab is open. A 4,000-person send at 60 a minute takes about 67 minutes of an open tab. That is acceptable for a test, but the cron line is the real answer.
3. **Consent.** Today the only opted-in list is confirmed subscribers. Mailing past buyers relies on D3; a complaint rate above 0.3% hurts the order emails too, because they share the domain. Mitigation: start with subscribers and repeat buyers, and keep the daily cap.
4. **Open rates are unreliable** (Apple Mail pre-loads images). Clicks and orders are the honest numbers, so opens default to off (D10).
5. **Outlook for Windows** renders with Word. The design uses tables, `bgcolor` and padded-cell buttons, but rounded corners, gradients and some spacing will be plainer there. Nothing on this Linux box can render Outlook; a real send to an Outlook account is the only check.
6. **Gmail clips HTML over 102 KB.** The builder shows a size meter and refuses to send above 95 KB.
7. **Images must be absolute HTTPS URLs on the live web root.** The web root is a different folder from the app (`usePublicPath`, CLAUDE.md). The pictures in the email are 128 px and 600 px variants made with `ImageVariants`; if GD is missing, the original image is used.
8. **Arabic RTL email.** The existing layout already flips (`$kbbMailDir`). The new blocks must be built direction-neutral and photographed in Arabic too.
9. **Volume on a shared server.** SMTP conversations cost CPU and time per message. The per-step budget, daily cap and per-minute rate are the guard rails.
10. **B1 changes behaviour customers already see.** Card, Tabby and Tamara shoppers would get their receipt a minute later, when the payment confirms. Tests that pin today's behaviour (the hosted path "gets the same receipt") will be updated deliberately in E2, not quietly.

---

## 9. Decisions only the owner can make (recommended default first)

| # | Decision | Recommended default |
|---|---|---|
| D1 | How mail leaves | **A DKIM-signing SMTP service** (Cloudways Elastic Email add-on, Brevo or Amazon SES). Server mail stays as the fallback |
| D2 | Card / Tabby / Tamara receipt | **Send only once paid.** If still unpaid after 30 minutes, send "Complete your payment" once |
| D3 | Who may get marketing | **Confirmed subscribers + customers who bought before**, always with unsubscribe. Add an **unticked** "email me offers" box at checkout from now on |
| D4 | Guest buyers (no account) in groups | **Yes**, matched by order email |
| D5 | Look | **A: Blush editorial** (see `directions/`) |
| D6 | New status emails on: on hold, delivered, payment failed | **On** (he asked for every status). Complete-your-payment follows D2 |
| D7 | "View my order" link that works on any phone | **Yes**, signed, 30 days, that one order only |
| D8 | Tracking number + courier on orders | **Yes**, optional fields; the shipped email prints them when they are filled in |
| D9 | Business postal address for the marketing footer | **Needs his text.** There is no default; marketing cannot ship without it |
| D10 | Open tracking pixel | **Off.** Clicks and orders only |
| D11 | Who can press Send on a campaign | **Owner only**, until he says managers may too |
