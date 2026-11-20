# Connect Meta — setup guide (works WITHOUT a verified business)

Shown in the app under **Settings → Connections → Meta**. You do this once. The app never asks for your Facebook
password, and keys are kept in your Mac's Keychain, never in a file or in chat.

**Your business is not verified — that is fine.** Meta needs Business Verification only for apps used by *other*
businesses. An app that only you (a person with a role on the app) use, on your own ad accounts, gets every permission
it needs ("Standard access") without verification. Source: Meta, *Business Verification* and *Access Levels* pages
(see `docs/META-FACTS.md` §12).

What an unverified business may notice: Meta's **Limited** API tier (the default for every new app) allows fewer API
calls per hour. The app is built for that: it paces itself and spreads its work, so you will not see errors from it.

---

## Step 1 — Create your own Meta app (5 minutes, both paths need it)

1. Go to **developers.facebook.com** → log in with the Facebook account that manages the ads → **My Apps** →
   **Create App**. (If asked, register as a developer first: accept the terms, confirm your phone.)
2. **Use case:** choose **"Create & manage ads with Marketing API"**. It adds `ads_management`, `ads_read`,
   `business_management`, `pages_read_engagement`, `pages_show_list` — exactly what the app needs. Optional and
   useful: `catalog_management` (catalog ads) and `page_manage_ads`.
3. **Business portfolio:** pick your business (K-Beauty Bliss). An unverified portfolio is allowed here.
4. Finish. On the app's **Settings → Basic** page copy the **App ID** and, with **Show**, the **App Secret**.
   You will paste both into the app (the secret goes to the Keychain).

## Step 2 — A token. Choose ONE path

### Path A (best): a system-user token — does not expire

1. Open **business.facebook.com/settings** (Business Settings) → **Users → System users** → **Add**.
   Name it `KBB Ads Autopilot`, role **Admin** (or Employee). Your app tier allows 1 system user + 1 admin system user.
2. Select the system user → **Assign assets** (Add assets):
   - **Ad accounts:** every ad account the app should see or run → **Manage ad account** (full control).
   - **Pages:** your Facebook Page → at least **Create ads** (advertise).
   - **Apps:** your app from Step 1 → **Manage app**.
   - **Datasets / pixels** and **Catalogs** (if you use catalog ads): give access too.
   - **Instagram accounts** and **WhatsApp accounts**: give access if you will run Instagram-Direct or WhatsApp ads.
3. Still on the system user → **Generate new token** → choose your app → tick **ads_management**, **ads_read**,
   **business_management** (and **catalog_management** if you use catalog ads; **pages_read_engagement**,
   **pages_show_list**) → **Generate token** → copy it (Meta shows it once).
4. In KBB Ads Autopilot → **Settings → Connections → Meta**: way = **System user**, paste the token, App ID and
   App Secret → **Save & test**.

If Business Settings will not let you create a system user or generate its token (some unverified portfolios are
held back by Meta), use Path B — it works with no verification at all.

### Path B: your own user token (≈ 60 days, the app renews and reminds you)

1. Go to **developers.facebook.com/tools/explorer** (Graph API Explorer).
2. Top right: **Meta App** = your app from Step 1; **User or Page** = **User Token**.
3. **Permissions:** add `ads_management`, `ads_read`, `business_management`, `pages_show_list`,
   `pages_read_engagement` (and `catalog_management` for catalog ads) → **Generate Access Token** → allow in the
   pop-up → copy the token.
4. In KBB Ads Autopilot → **Settings → Connections → Meta**: way = **My user token**, paste the token, the
   **App ID** and **App Secret** → **Save & test**. Within the same hour (the Explorer token is short-lived) the app
   exchanges it for a **long-lived 60-day token** (Meta's own `fb_exchange_token` call) and shows its **expiry date**.
5. The app warns you **7 days before** it expires and, from 10 days before, tries to extend it every day. If Meta does
   not extend it, repeat steps 1–4 (two minutes). **If it ever lapses, your ads stay protected**: the spending limit
   and Meta's own rules that the app installed keep running on Meta's side; the app simply stops changing anything
   until a new token is pasted.

## Step 3 — Choose the ad accounts and link their assets

After **Save & test** the app lists every ad account the token can see (your business's own, client accounts, and a
personal ad account if you have one) under **Settings → Connections → Meta → Ad accounts**. For each account you want
automated: switch **Automate** on, set its **Limits** (daily, monthly — no limits = nothing launches), and link:

| What | Needed for | Where to find the id |
|---|---|---|
| **Ad account id** (`act_…`) | everything | Listed automatically. By hand: Ads Manager URL `act=1234…`, or Business Settings → Accounts → Ad accounts. |
| **Facebook Page id** | every ad | Business Settings → Accounts → Pages (the id under the name), or the Page → About → Page transparency. |
| **Pixel / dataset id** | sales optimisation, retargeting audiences | Events Manager → Data sources → your pixel → Settings → **Dataset ID**. |
| **Instagram account id** | ads shown as your Instagram, click-to-Instagram-Direct, Instagram engagement audiences | Business Settings → Accounts → Instagram accounts (connect it to the Page first). |
| **Catalog id** | Advantage+ catalog ads | Commerce Manager → your catalog → Settings (Catalog ID). |
| **WhatsApp number** | click-to-WhatsApp | Your WhatsApp Business number, linked to the Page first: Page → Settings → **Linked accounts → WhatsApp**. Type it as +9715XXXXXXXX. |

The app reads each linked id live and shows it green (with its name) or red (with Meta's reason).

## What needs what (so nothing is a surprise)

- **Business Verification:** not needed for anything the app does on your own accounts. Needed only to let *other*
  businesses use your app, which this app never does.
- **Full access tier** (more API calls): needs App Review (500 calls in 15 days, <15% errors). Not needed; the app
  works within Limited access.
- **Collection ads:** the app runs them only on an Instant Experience you made once in Ads Manager (it then needs the
  Instant Experience id). Building Instant Experiences through the API is not something the app does.
- **Frequency caps:** Meta allows them only on Reach / ThruPlay ad sets, not on sales ad sets.

## What protects the ads while the Mac is closed

Installed by the app on Meta for each automated account, kept up to date on every launch and change, named `KAA: …`:

1. **Account spending limit** = what was already spent + what is left of this month's limit. When reached, Meta stops
   every ad in the account. Never removed, never raised above your limit, and a lower limit you set yourself in Ads
   Manager is never raised.
2. **KAA: no-purchase ad set / ad** — every 30 min: an ad set or ad of the app's that spent 3× your target CPA in 7
   days with no purchase is paused.
3. **KAA: high-CPA ad set** — every 30 min: spend and cost per purchase both above 3× target CPA → paused.
4. **KAA: daily limit** — in real time: an app campaign that spends its share of your daily limit today is paused.

Your own campaigns (not named `KAA:`) are never touched by the app or by these rules.
