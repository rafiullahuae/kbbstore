# Marketing Pixels — the complete guide

Meta, Google (Analytics 4, Ads, Merchant Center) and TikTok, end to end. Checked on 9 October 2026.

Everything is in the admin at **Growth & Marketing → Marketing Pixels**. This file is a copy of the **Guide** tab there.

## What is automatic, and what is a paste

Read this first. One click is not possible for an independent shop on any of the three platforms — this is why, and what the shop does instead.

### What happens by itself

1. Once an ID is saved, the shop prints that platform’s pixel on every page and sends the shopping events: product view, add to cart, checkout and purchase, with the price in AED and the product ids.
2. Once a server token is saved, the shop ALSO sends add to cart, checkout and purchase from its own server, with the same event id as the browser — so ad blockers lose fewer sales and nothing is counted twice.
3. The product feeds update themselves whenever a product changes.

### Why not one click like the Facebook plugin

1. Meta’s WordPress plugin connects with “Meta Business Extension”, a popup that runs under Meta’s own app. That popup needs a private permission (manage_business_extension) that Meta gives only to approved partners. This shop is not a Meta partner, and using Meta’s app id would be pretending to be their plugin.
2. Google and TikTok work the same way: their one-click connectors are for approved platforms (Shopify, WooCommerce). Google’s own Merchant API also needs a Google Cloud project, and an unverified one loses its login every 7 days.
3. So each platform is a short guided wizard: open the exact page, copy one thing, paste it here, press Check. The Check button proves it worked by asking the platform itself.
4. Meta has one real shortcut: “Connect with Facebook” logs in with YOUR OWN Meta app (the one you made for Instagram is reused) and lists your pixels to pick from. That needs no Meta review, because the app only reads your own accounts.

- [Meta: Business Extension requirements](https://developers.facebook.com/docs/facebook-business-extension/fbe/get-started/pixel-capi-onboarding/)

### Where it is

1. Everything is under Growth & Marketing → Marketing Pixels. Tabs: Pixels (the three IDs) · Meta · Google · TikTok · Custom code · Last events · Guide.
2. The module must be ON: Store → Modules → Marketing Pixels. Saving an ID switches it on.

## Meta (Facebook & Instagram)

Pixel, Conversions API, domain verification and the catalog for Advantage+ catalog ads.

### 1. The Pixel ID

1. Open Meta Events Manager. Choose your business (top left).
2. Data sources → your website pixel. No pixel yet? Connect data → Web → Connect → name it → Create. Stop when Meta offers install options: the shop installs it for you.
3. Copy the number under the pixel’s name (15–16 digits).
4. Paste it at Growth & Marketing → Marketing Pixels → Meta → Pixel (dataset) ID → Save.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta → step 2

**How to check:** Meta Pixel Helper in Chrome shows PageView on the shop.

**Common errors:**

- “Pixel not found”: the ID has spaces or is the ad account number — use the dataset ID from Data sources.

- [Open Meta Events Manager](https://business.facebook.com/events_manager2/)

### 2. Conversions API (server events)

1. Same pixel → Settings → Conversions API → Set up manually → Generate access token.
2. Copy the token straight away. Meta does not show it again; if you lose it, generate a new one.
3. Paste it at Growth & Marketing → Marketing Pixels → Meta → Conversions API token → Save. Only the last four characters are shown back.
4. Press Check. Green = the token reaches this pixel.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta → step 3

**How to check:** After a real order: Events Manager → Overview → Purchase shows “Browser · Server”, and “Deduplicated”.

**Common errors:**

- “Invalid OAuth access token” / error 190: the token was revoked or mistyped — generate a new one.
- “Unsupported post request”: the token belongs to a different pixel.

- [Open Meta Events Manager](https://business.facebook.com/events_manager2/)

### 3. Test Events

1. Events Manager → your pixel → Test events → copy the code starting with TEST.
2. Paste it in Test Events code, Save, press Check. A PageView from “Server” appears in Test events.
3. Then CLEAR the code and Save. While it is set, server events only go to Test events.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta → step 4

**How to check:** The test event appears within a minute.

### 4. Domain verification

1. Business Settings → Brand safety → Domains → Add → type your domain without https or www.
2. Choose “Meta-tag verification” and copy the tag.
3. Paste the whole tag (or just the code) at Growth & Marketing → Marketing Pixels → Meta → Domain verification → Save.
4. Back in Meta press Verify. Leave the tag in place afterwards — Meta checks again from time to time.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta → step 5

**How to check:** Status turns to “Verified”. The Live page check here also looks for the tag.

**Common errors:**

- “Meta tag not found”: a page cache may hold the old home page — wait a few minutes and try again.

- [Open Business Settings](https://business.facebook.com/settings/)

### 5. Catalog (for catalog ads)

1. Open Commerce Manager → Catalog → Data sources → Add items → Data feed → Scheduled feed.
2. Paste the Meta feed address shown on the Meta tab (it ends /feeds/meta-catalog.xml). Schedule: daily. Currency AED.
3. Its product ids are the same ids the pixel sends, so Meta can match views and sales to products.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta (feed address)

**How to check:** Commerce Manager → Diagnostics shows no errors after the first fetch. The Feed check here validates every item first.

**Common errors:**

- “Missing brand”: fill in the product’s brand in the product editor.

- [Open Commerce Manager](https://business.facebook.com/commerce/)

### Optional: Connect with Facebook (your own app)

1. This only picks the Pixel ID for you. You still paste the Conversions API token.
2. If you connected Instagram with “Connect with Facebook”, the same app is used and there is nothing to set up.
3. Otherwise: Meta for Developers → My Apps → your app → App settings → Basic: copy App ID and App Secret into the Meta tab.
4. Facebook Login for Business → Settings → Valid OAuth Redirect URIs: add the address shown on the Meta tab (it ends /admin-api/marketing-pixels/meta/callback). Save.
5. Press Connect with Facebook, log in, allow access. One pixel is filled in at once; several show a list to pick from.

**Paste it here:** Growth & Marketing → Marketing Pixels → Meta → Connect with Facebook

**Common errors:**

- “URL blocked”: the redirect address is not in Valid OAuth Redirect URIs, or differs by www/https.
- “No pixels”: the Facebook user is not on the ad account that owns the pixel.

- [Open Meta for Developers](https://developers.facebook.com/apps/)

## Google (Analytics 4, Ads, Merchant Center)

One Google tag carries GA4 and Google Ads. Merchant Center reads the product feed.

### 1. GA4 Measurement ID

1. Google Analytics → Admin → Data collection and modification → Data streams → Web → your stream.
2. Copy the Measurement ID (G-XXXXXXXXXX).
3. Paste it at Growth & Marketing → Marketing Pixels → Google → Measurement ID → Save.
4. New property? Set the time zone to United Arab Emirates and the currency to AED.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 1

**How to check:** Reports → Realtime shows your own visit within a minute.

- [Open Google Analytics](https://analytics.google.com/)
- [Google: find your Measurement ID](https://support.google.com/analytics/answer/12270356)

### 2. Measurement Protocol API secret (server purchase)

1. Same stream page → Measurement Protocol API secrets → Create → nickname → Create.
2. Copy the Secret value. Paste it at Growth & Marketing → Marketing Pixels → Google → API secret → Save.
3. The shop then also sends each purchase from its server, with the same transaction id and the shopper’s GA client id, so GA4 keeps one.
4. If a shopper blocked Google’s cookie, the server does NOT send their purchase — GA4 could not match it and would count it twice. “Last events” says “skipped” for those.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 2

**How to check:** Press Check, then Admin → DebugView: “kbb_connection_test” appears.

**Common errors:**

- Google’s validator does not check the secret itself. If DebugView stays empty, the secret is wrong — create a new one.

- [Google: Measurement Protocol](https://developers.google.com/analytics/devguides/collection/protocol/ga4)

### 3. Google Ads purchase conversion

1. Google Ads → Goals → Conversions → Summary → + New conversion action → Website.
2. Enter the shop address → Scan → “Add a conversion action manually”. Goal: Purchase. Value: “Use different values for each conversion”, default AED. Count: Every.
3. Choose “Use Google tag” / “Install the tag yourself”. Find send_to: 'AW-123456789/AbCdEfGh'.
4. Paste AW-123456789 in Conversion ID and AbCdEfGh in Conversion label → Save.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 3

**How to check:** The conversion action shows “Recording conversions” after the first order (it can take up to a day).

**Common errors:**

- “Tag inactive”: the label is wrong, or the module is off.

- [Open Google Ads conversions](https://ads.google.com/aw/conversions)

### 4. Enhanced conversions

1. In the conversion action → Enhanced conversions → On → “Google tag”.
2. Switch on “Enhanced conversions” at Growth & Marketing → Marketing Pixels → Google → Save.
3. The thank-you page then sends the buyer’s email and phone, normalised and SHA-256 hashed — never readable.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 4

**How to check:** Diagnostics in the conversion action shows enhanced conversions “Recording” after a few days.

- [Google: enhanced conversions with the Google tag](https://support.google.com/google-ads/answer/9888145)

### 5. Merchant Center: verify and claim

1. Merchant Center → Settings → Business info → Website → enter the shop address.
2. Choose “Add an HTML tag” and copy the tag.
3. Paste it at Growth & Marketing → Marketing Pixels → Google → Site verification → Save. (It is the same box as the SEO screen’s.)
4. Back in Merchant Center press Verify, then Claim. Leave the tag in place.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 5

**How to check:** The Website row says “Verified and claimed”.

**Common errors:**

- “Tag not found”: wait a few minutes for page caches and retry.

- [Open Merchant Center](https://merchants.google.com/)

### 6. Merchant Center: product feed

1. Settings → Data sources → Add product source → Add products from a file → Enter a link to your file.
2. Paste the Google feed address from the Google tab (ends /feeds/google-merchant.xml). Fetch: Daily, a quiet hour. Country UAE, language English.
3. The feed lists only published, visible products with a price and a photo, with the sale price, brand, stock and GTIN where you have one (else identifier_exists = no).
4. Switch it on or off at Growth & Marketing → Google Shopping feed.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google (feed address)

**How to check:** Products → Needs attention shows the issues per product after the first fetch. The Feed check here catches missing fields first.

**Common errors:**

- “Image too small”: from 31 January 2027 Google wants photos of at least 500 × 500 pixels.
- “Missing GTIN”: add the barcode in the product editor where you have it.

### 7. Consent Mode v2

1. Off (default): nothing changes. Right for the UAE, where there is no cookie-banner rule like Europe’s.
2. EEA: visitors from Europe, the UK and Switzerland are treated as not consenting (the shop has no banner), so Google measures them without cookies. Use this if you run ads to Europe.

**Paste it here:** Growth & Marketing → Marketing Pixels → Google → step 7

## TikTok

Pixel, Events API and the catalog.

### 1. Pixel ID

1. TikTok Ads Manager → Tools → Events → Web events. No pixel yet? Set up web events → name → Manually set up.
2. Copy the Pixel ID under the pixel’s name.
3. Paste it at Growth & Marketing → Marketing Pixels → TikTok → Pixel ID → Save.

**Paste it here:** Growth & Marketing → Marketing Pixels → TikTok → step 2

**How to check:** TikTok Pixel Helper (Chrome) shows the pixel on the shop.

- [Open TikTok Events Manager](https://ads.tiktok.com/i18n/events_manager)

### 2. Events API token

1. Your pixel → Settings → Events API → Generate Access Token → copy.
2. Paste it at Growth & Marketing → Marketing Pixels → TikTok → Events API token → Save.

**Paste it here:** Growth & Marketing → Marketing Pixels → TikTok → step 3

**Common errors:**

- “Access token invalid” (40001/40105): generate a new token on the same pixel.

### 3. Test events

1. Your pixel → Test events → Server → copy the test code.
2. Paste it, Save, press Check: a ViewContent from the server appears. Then clear the code and Save.

**Paste it here:** Growth & Marketing → Marketing Pixels → TikTok → step 4

**How to check:** Test events lists the event within a minute.

### 4. Catalog

1. TikTok Business Center → Assets → Catalogs → Create (or open yours) → Products → Add products → Data feed.
2. Paste the TikTok feed address from the TikTok tab (ends /feeds/tiktok-catalog.xml). Update: daily. Currency AED.

**Paste it here:** Growth & Marketing → Marketing Pixels → TikTok (feed address)

**How to check:** The catalog shows the products as Approved.

**Common errors:**

- TikTok marks a catalog stale after 7 days without an update — keep the schedule daily.

- [Open TikTok Business Center](https://business.tiktok.com/)

## Custom code (owner only)

For a tag the shop has no built-in support for. Only the shop owner’s account can open this tab.

### The three boxes

1. Head: printed before </head>. For tags that ask to be “in the head”.
2. Body start: right after <body>. For a <noscript> fallback some tags give you.
3. Footer: before </body>. For everything else — this is the safest place.
4. Each box has On/Off, Where (all shop pages / only the thank-you page / all except checkout) and Load.

**Paste it here:** Growth & Marketing → Marketing Pixels → Custom code

### Speed rules (why the shop stays fast)

1. Load “After the page loads” (recommended): the code waits until the page has fully loaded, then runs when the browser is idle. It cannot slow down the page.
2. Load “Immediately”: runs as the page opens. Even then the shop adds async to any script file and loads any stylesheet without blocking. Use only when a vendor insists.
3. Each box holds up to 20 KB. Code using document.write gets a warning: after the page has loaded it would wipe the page, so the shop skips those calls. Ask the vendor for their async snippet.
4. Never on the admin, the owner app, /api or the feeds. Off or empty prints nothing at all.
5. Every save keeps the previous version — “Restore” puts any of the last versions back in one click.

### Examples

1. Microsoft Clarity: Clarity → Settings → Setup → “Install manually” → copy the script → Footer box, After the page loads, All shop pages.
2. Pinterest tag: Pinterest Ads → Conversions → Tag manager → copy the base code → Footer box. For checkout events use the thank-you page only.
3. Snap Pixel: Snapchat Ads Manager → Events Manager → your pixel → Setup → Manual → copy the base code → Footer box.
4. Google Tag Manager is NOT needed for GA4, Google Ads, Meta or TikTok — the shop sends those itself. Adding them again here would count every visit twice.

**Paste it here:** Growth & Marketing → Marketing Pixels → Custom code

- [Microsoft Clarity](https://clarity.microsoft.com/)
- [Pinterest Ads](https://ads.pinterest.com/)
- [Snapchat Ads Manager](https://ads.snapchat.com/)

## Checking and fixing

Where to look when a number looks wrong.

### The Check buttons

1. Each Connect tab has a Check button. Green steps passed; red shows the platform’s own error message.
2. “Live page” fetches your home page as a visitor sees it and looks for each pixel and verification tag.
3. “Feed” builds the feeds and checks every product for the required fields.

**Paste it here:** Growth & Marketing → Marketing Pixels → each tab

### Last events

1. The Last events tab lists the newest server events: platform, event, id, and Sent / Failed / Skipped with the platform’s message.
2. If a platform refuses the token, its server events pause for 10 minutes so the shop never waits on it. Fix the token, and they resume.
3. Server events are sent after the page has gone to the shopper, with a 4-second limit. A platform being down never slows or fails a checkout.

**Paste it here:** Growth & Marketing → Marketing Pixels → Last events

### Numbers that do not match

1. Sales in an ads platform are never exactly the orders: they count only shoppers they can link to an ad.
2. Twice as many purchases as orders: a second copy of the pixel is installed somewhere — remove it from Custom code or from any other tool.
3. Ad blockers stop browser pixels; the server events are what fill that gap.

## The Connect wizards, step by step

### Meta (Facebook & Instagram)

1. **Open Events Manager.** Sign in with the Facebook account that runs your ads. Choose your business at the top left. [Open Meta Events Manager](https://business.facebook.com/events_manager2/)
2. **Copy your Pixel (dataset) ID.** Data sources → click your website pixel (or Connect data → Web → create one). The ID is the 15–16 digit number under its name. Or press “Connect with Facebook” below to pick it from a list.
3. **Create the Conversions API token.** On the same pixel: Settings → scroll to “Conversions API” → “Set up manually” → Generate access token. Copy it at once — Meta shows it only once.
4. **Optional: a Test Events code.** Test events tab → “Confirm your server events” → copy the code that starts with TEST. Paste it, press Check, then CLEAR it once you have seen the event — while it is set, server events go to Test events only.
5. **Verify your domain.** Business Settings → Brand safety → Domains → Add → your shop’s domain → “Meta-tag verification” → copy the whole tag (or just the code). Paste it, save, then press Verify in Meta. [Open Business Settings](https://business.facebook.com/settings/)
6. **Check.** Press Check. Green means the token reaches the pixel; with a test code, Meta also confirms one server event.

### Google (Analytics 4, Ads, Merchant Center)

1. **Copy the GA4 Measurement ID.** Google Analytics → Admin (gear, bottom left) → Data collection and modification → Data streams → your Web stream. The Measurement ID (G-XXXXXXXXXX) is at the top right. [Open Google Analytics](https://analytics.google.com/)
2. **Create a Measurement Protocol API secret.** On that same stream page: Measurement Protocol API secrets → Create → give it a nickname (e.g. “Shop server”) → copy the Secret value. This lets the shop send each purchase from the server as a backup.
3. **Google Ads conversion (optional).** Google Ads → Goals → Conversions → Summary → New conversion action → Website → enter your shop address → “Add a conversion action manually” → category Purchase → value “Use different values for each conversion”. Then “Install the tag yourself”: the event snippet shows send_to: 'AW-123456789/AbCdEf'. Paste AW-123456789 as the ID and AbCdEf as the label. [Open Google Ads conversions](https://ads.google.com/aw/conversions)
4. **Enhanced conversions (optional).** In the same conversion action: Enhanced conversions → turn on → method “Google tag”. Then switch “Enhanced conversions” on here. The shop sends the customer’s email and phone, hashed, from the thank-you page only.
5. **Verify your site for Merchant Center.** Merchant Center → Settings (gear) → Business info → Website → verify with “Add an HTML tag” → copy the tag. Paste it here, save, then press Verify and then Claim in Merchant Center. [Open Merchant Center](https://merchants.google.com/)
6. **Add the product feed.** Merchant Center → Settings → Data sources → Add product source → “Add products from a file” → “Enter a link to your file” → paste the Google feed address shown on this tab → fetch daily → Continue.
7. **Consent Mode.** Leave it Off unless you advertise to Europe or the UK. “EEA” tells Google that visitors there have not consented (this shop has no cookie banner), so Google measures them without cookies.
8. **Check.** Press Check. Google validates the purchase the shop sends and receives one test event — open Admin → DebugView to see “kbb_connection_test” and confirm the secret.

### TikTok

1. **Open TikTok Events Manager.** Sign in to TikTok Ads Manager → Tools → Events → Web events. Create a pixel if you have none: “Set up web events” → name it → choose “Manually set up”. [Open TikTok Events Manager](https://ads.tiktok.com/i18n/events_manager)
2. **Copy the Pixel ID.** Click your pixel. The Pixel ID (about 20 capital letters and digits, e.g. C4ABCDEF…) is under its name.
3. **Generate the Events API token.** Your pixel → Settings → Events API → Generate Access Token. Copy it.
4. **Copy a Test Events code.** Your pixel → Test events → Server events → copy the test code. TikTok needs it to accept a check without counting it as a real visit. Clear it after the check.
5. **Check.** Press Check. Green means TikTok accepted a test ViewContent; it appears in Test events within a minute.

## Addresses this shop serves

- Google Merchant Center feed: `https://<your shop>/feeds/google-merchant.xml`
- Meta catalog feed: `https://<your shop>/feeds/meta-catalog.xml`
- TikTok catalog feed: `https://<your shop>/feeds/tiktok-catalog.xml`
- Connect with Facebook redirect URI: `https://<your shop>/admin-api/marketing-pixels/meta/callback`

The exact addresses for this shop, with Copy buttons, are on each Connect tab.
