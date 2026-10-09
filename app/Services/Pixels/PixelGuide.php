<?php

declare(strict_types=1);

namespace App\Services\Pixels;

/**
 * The Guide tab and the Connect wizards' steps, as data. (Lane MP)
 *
 * ONE source for three readers: the admin Guide tab, the numbered steps on
 * each Connect tab, and docs/MARKETING-PIXELS-GUIDE.md (written from this
 * class by tools/mp-guide-md.php, so the two cannot drift). Every link is a
 * constant on a platform's own host; MarketingPixelsConnectTest holds them to
 * an allowlist, https, and opens-in-a-new-tab.
 *
 * Plain English for the owner: short sentences, numbered steps, and the
 * exact place in this admin to paste each thing.
 */
final class PixelGuide
{
    public const CHECKED = '9 October 2026';

    /** Where everything sits in this admin. */
    public const HERE = 'Growth & Marketing → Marketing Pixels';

    /**
     * Wizard steps per platform. Each step: title, text, an optional link
     * [label, url] and the fields it fills (keys the Connect API saves).
     */
    public const WIZARD = [
        'meta' => [
            'title' => 'Meta (Facebook & Instagram)',
            'steps' => [
                ['Open Events Manager', 'Sign in with the Facebook account that runs your ads. Choose your business at the top left.', ['Open Meta Events Manager', 'https://business.facebook.com/events_manager2/'], []],
                ['Copy your Pixel (dataset) ID', 'Data sources → click your website pixel (or Connect data → Web → create one). The ID is the 15–16 digit number under its name. Or press “Connect with Facebook” below to pick it from a list.', null, ['meta_id']],
                ['Create the Conversions API token', 'On the same pixel: Settings → scroll to “Conversions API” → “Set up manually” → Generate access token. Copy it at once — Meta shows it only once.', null, ['meta_capi_token']],
                ['Optional: a Test Events code', 'Test events tab → “Confirm your server events” → copy the code that starts with TEST. Paste it, press Check, then CLEAR it once you have seen the event — while it is set, server events go to Test events only.', null, ['meta_test_code']],
                ['Verify your domain', 'Business Settings → Brand safety → Domains → Add → your shop’s domain → “Meta-tag verification” → copy the whole tag (or just the code). Paste it, save, then press Verify in Meta.', ['Open Business Settings', 'https://business.facebook.com/settings/'], ['facebook_domain_verification']],
                ['Check', 'Press Check. Green means the token reaches the pixel; with a test code, Meta also confirms one server event.', null, []],
            ],
        ],
        'google' => [
            'title' => 'Google (Analytics 4, Ads, Merchant Center)',
            'steps' => [
                ['Copy the GA4 Measurement ID', 'Google Analytics → Admin (gear, bottom left) → Data collection and modification → Data streams → your Web stream. The Measurement ID (G-XXXXXXXXXX) is at the top right.', ['Open Google Analytics', 'https://analytics.google.com/'], ['ga4_id']],
                ['Create a Measurement Protocol API secret', 'On that same stream page: Measurement Protocol API secrets → Create → give it a nickname (e.g. “Shop server”) → copy the Secret value. This lets the shop send each purchase from the server as a backup.', null, ['ga4_api_secret']],
                ['Google Ads conversion (optional)', 'Google Ads → Goals → Conversions → Summary → New conversion action → Website → enter your shop address → “Add a conversion action manually” → category Purchase → value “Use different values for each conversion”. Then “Install the tag yourself”: the event snippet shows send_to: \'AW-123456789/AbCdEf\'. Paste AW-123456789 as the ID and AbCdEf as the label.', ['Open Google Ads conversions', 'https://ads.google.com/aw/conversions'], ['ads_id', 'ads_label', 'ads_ec']],
                ['Enhanced conversions (optional)', 'In the same conversion action: Enhanced conversions → turn on → method “Google tag”. Then switch “Enhanced conversions” on here. The shop sends the customer’s email and phone, hashed, from the thank-you page only.', null, []],
                ['Verify your site for Merchant Center', 'Merchant Center → Settings (gear) → Business info → Website → verify with “Add an HTML tag” → copy the tag. Paste it here, save, then press Verify and then Claim in Merchant Center.', ['Open Merchant Center', 'https://merchants.google.com/'], ['google_site_verification']],
                ['Add the product feed', 'Merchant Center → Settings → Data sources → Add product source → “Add products from a file” → “Enter a link to your file” → paste the Google feed address shown on this tab → fetch daily → Continue.', null, []],
                ['Consent Mode', 'Leave it Off unless you advertise to Europe or the UK. “EEA” tells Google that visitors there have not consented (this shop has no cookie banner), so Google measures them without cookies.', null, ['consent_mode']],
                ['Check', 'Press Check. Google validates the purchase the shop sends and receives one test event — open Admin → DebugView to see “kbb_connection_test” and confirm the secret.', null, []],
            ],
        ],
        'tiktok' => [
            'title' => 'TikTok',
            'steps' => [
                ['Open TikTok Events Manager', 'Sign in to TikTok Ads Manager → Tools → Events → Web events. Create a pixel if you have none: “Set up web events” → name it → choose “Manually set up”.', ['Open TikTok Events Manager', 'https://ads.tiktok.com/i18n/events_manager'], []],
                ['Copy the Pixel ID', 'Click your pixel. The Pixel ID (about 20 capital letters and digits, e.g. C4ABCDEF…) is under its name.', null, ['tiktok_id']],
                ['Generate the Events API token', 'Your pixel → Settings → Events API → Generate Access Token. Copy it.', null, ['tiktok_token']],
                ['Copy a Test Events code', 'Your pixel → Test events → Server events → copy the test code. TikTok needs it to accept a check without counting it as a real visit. Clear it after the check.', null, ['tiktok_test_code']],
                ['Check', 'Press Check. Green means TikTok accepted a test ViewContent; it appears in Test events within a minute.', null, []],
            ],
        ],
    ];

    /**
     * The Guide tab. Each section: title, intro, then blocks of
     * [heading, steps[], links[[label,url]], where-to-paste, how-to-verify, errors[]].
     */
    public static function sections(): array
    {
        return [
            ['id' => 'overview', 'title' => 'What is automatic, and what is a paste', 'intro' => 'Read this first. One click is not possible for an independent shop on any of the three platforms — this is why, and what the shop does instead.', 'blocks' => [
                ['What happens by itself', [
                    'Once an ID is saved, the shop prints that platform’s pixel on every page and sends the shopping events: product view, add to cart, checkout and purchase, with the price in AED and the product ids.',
                    'Once a server token is saved, the shop ALSO sends add to cart, checkout and purchase from its own server, with the same event id as the browser — so ad blockers lose fewer sales and nothing is counted twice.',
                    'The product feeds update themselves whenever a product changes.',
                ], [], null, null, []],
                ['Why not one click like the Facebook plugin', [
                    'Meta’s WordPress plugin connects with “Meta Business Extension”, a popup that runs under Meta’s own app. That popup needs a private permission (manage_business_extension) that Meta gives only to approved partners. This shop is not a Meta partner, and using Meta’s app id would be pretending to be their plugin.',
                    'Google and TikTok work the same way: their one-click connectors are for approved platforms (Shopify, WooCommerce). Google’s own Merchant API also needs a Google Cloud project, and an unverified one loses its login every 7 days.',
                    'So each platform is a short guided wizard: open the exact page, copy one thing, paste it here, press Check. The Check button proves it worked by asking the platform itself.',
                    'Meta has one real shortcut: “Connect with Facebook” logs in with YOUR OWN Meta app (the one you made for Instagram is reused) and lists your pixels to pick from. That needs no Meta review, because the app only reads your own accounts.',
                ], [['Meta: Business Extension requirements', 'https://developers.facebook.com/docs/facebook-business-extension/fbe/get-started/pixel-capi-onboarding/']], null, null, []],
                ['Where it is', [
                    'Everything is under ' . self::HERE . '. Tabs: Pixels (the three IDs) · Meta · Google · TikTok · Custom code · Last events · Guide.',
                    'The module must be ON: Store → Modules → Marketing Pixels. Saving an ID switches it on.',
                ], [], null, null, []],
            ]],
            ['id' => 'meta', 'title' => 'Meta (Facebook & Instagram)', 'intro' => 'Pixel, Conversions API, domain verification and the catalog for Advantage+ catalog ads.', 'blocks' => [
                ['1. The Pixel ID', [
                    'Open Meta Events Manager. Choose your business (top left).',
                    'Data sources → your website pixel. No pixel yet? Connect data → Web → Connect → name it → Create. Stop when Meta offers install options: the shop installs it for you.',
                    'Copy the number under the pixel’s name (15–16 digits).',
                    'Paste it at ' . self::HERE . ' → Meta → Pixel (dataset) ID → Save.',
                ], [['Open Meta Events Manager', 'https://business.facebook.com/events_manager2/']], self::HERE . ' → Meta → step 2', 'Meta Pixel Helper in Chrome shows PageView on the shop.', ['“Pixel not found”: the ID has spaces or is the ad account number — use the dataset ID from Data sources.']],
                ['2. Conversions API (server events)', [
                    'Same pixel → Settings → Conversions API → Set up manually → Generate access token.',
                    'Copy the token straight away. Meta does not show it again; if you lose it, generate a new one.',
                    'Paste it at ' . self::HERE . ' → Meta → Conversions API token → Save. Only the last four characters are shown back.',
                    'Press Check. Green = the token reaches this pixel.',
                ], [['Open Meta Events Manager', 'https://business.facebook.com/events_manager2/']], self::HERE . ' → Meta → step 3', 'After a real order: Events Manager → Overview → Purchase shows “Browser · Server”, and “Deduplicated”.', ['“Invalid OAuth access token” / error 190: the token was revoked or mistyped — generate a new one.', '“Unsupported post request”: the token belongs to a different pixel.']],
                ['3. Test Events', [
                    'Events Manager → your pixel → Test events → copy the code starting with TEST.',
                    'Paste it in Test Events code, Save, press Check. A PageView from “Server” appears in Test events.',
                    'Then CLEAR the code and Save. While it is set, server events only go to Test events.',
                ], [], self::HERE . ' → Meta → step 4', 'The test event appears within a minute.', []],
                ['4. Domain verification', [
                    'Business Settings → Brand safety → Domains → Add → type your domain without https or www.',
                    'Choose “Meta-tag verification” and copy the tag.',
                    'Paste the whole tag (or just the code) at ' . self::HERE . ' → Meta → Domain verification → Save.',
                    'Back in Meta press Verify. Leave the tag in place afterwards — Meta checks again from time to time.',
                ], [['Open Business Settings', 'https://business.facebook.com/settings/']], self::HERE . ' → Meta → step 5', 'Status turns to “Verified”. The Live page check here also looks for the tag.', ['“Meta tag not found”: a page cache may hold the old home page — wait a few minutes and try again.']],
                ['5. Catalog (for catalog ads)', [
                    'Open Commerce Manager → Catalog → Data sources → Add items → Data feed → Scheduled feed.',
                    'Paste the Meta feed address shown on the Meta tab (it ends /feeds/meta-catalog.xml). Schedule: daily. Currency AED.',
                    'Its product ids are the same ids the pixel sends, so Meta can match views and sales to products.',
                ], [['Open Commerce Manager', 'https://business.facebook.com/commerce/']], self::HERE . ' → Meta (feed address)', 'Commerce Manager → Diagnostics shows no errors after the first fetch. The Feed check here validates every item first.', ['“Missing brand”: fill in the product’s brand in the product editor.']],
                ['Optional: Connect with Facebook (your own app)', [
                    'This only picks the Pixel ID for you. You still paste the Conversions API token.',
                    'If you connected Instagram with “Connect with Facebook”, the same app is used and there is nothing to set up.',
                    'Otherwise: Meta for Developers → My Apps → your app → App settings → Basic: copy App ID and App Secret into the Meta tab.',
                    'Facebook Login for Business → Settings → Valid OAuth Redirect URIs: add the address shown on the Meta tab (it ends /admin-api/marketing-pixels/meta/callback). Save.',
                    'Press Connect with Facebook, log in, allow access. One pixel is filled in at once; several show a list to pick from.',
                ], [['Open Meta for Developers', 'https://developers.facebook.com/apps/']], self::HERE . ' → Meta → Connect with Facebook', null, ['“URL blocked”: the redirect address is not in Valid OAuth Redirect URIs, or differs by www/https.', '“No pixels”: the Facebook user is not on the ad account that owns the pixel.']],
            ]],
            ['id' => 'google', 'title' => 'Google (Analytics 4, Ads, Merchant Center)', 'intro' => 'One Google tag carries GA4 and Google Ads. Merchant Center reads the product feed.', 'blocks' => [
                ['1. GA4 Measurement ID', [
                    'Google Analytics → Admin → Data collection and modification → Data streams → Web → your stream.',
                    'Copy the Measurement ID (G-XXXXXXXXXX).',
                    'Paste it at ' . self::HERE . ' → Google → Measurement ID → Save.',
                    'New property? Set the time zone to United Arab Emirates and the currency to AED.',
                ], [['Open Google Analytics', 'https://analytics.google.com/'], ['Google: find your Measurement ID', 'https://support.google.com/analytics/answer/12270356']], self::HERE . ' → Google → step 1', 'Reports → Realtime shows your own visit within a minute.', []],
                ['2. Measurement Protocol API secret (server purchase)', [
                    'Same stream page → Measurement Protocol API secrets → Create → nickname → Create.',
                    'Copy the Secret value. Paste it at ' . self::HERE . ' → Google → API secret → Save.',
                    'The shop then also sends each purchase from its server, with the same transaction id and the shopper’s GA client id, so GA4 keeps one.',
                    'If a shopper blocked Google’s cookie, the server does NOT send their purchase — GA4 could not match it and would count it twice. “Last events” says “skipped” for those.',
                ], [['Google: Measurement Protocol', 'https://developers.google.com/analytics/devguides/collection/protocol/ga4']], self::HERE . ' → Google → step 2', 'Press Check, then Admin → DebugView: “kbb_connection_test” appears.', ['Google’s validator does not check the secret itself. If DebugView stays empty, the secret is wrong — create a new one.']],
                ['3. Google Ads purchase conversion', [
                    'Google Ads → Goals → Conversions → Summary → + New conversion action → Website.',
                    'Enter the shop address → Scan → “Add a conversion action manually”. Goal: Purchase. Value: “Use different values for each conversion”, default AED. Count: Every.',
                    'Choose “Use Google tag” / “Install the tag yourself”. Find send_to: \'AW-123456789/AbCdEfGh\'.',
                    'Paste AW-123456789 in Conversion ID and AbCdEfGh in Conversion label → Save.',
                ], [['Open Google Ads conversions', 'https://ads.google.com/aw/conversions']], self::HERE . ' → Google → step 3', 'The conversion action shows “Recording conversions” after the first order (it can take up to a day).', ['“Tag inactive”: the label is wrong, or the module is off.']],
                ['4. Enhanced conversions', [
                    'In the conversion action → Enhanced conversions → On → “Google tag”.',
                    'Switch on “Enhanced conversions” at ' . self::HERE . ' → Google → Save.',
                    'The thank-you page then sends the buyer’s email and phone, normalised and SHA-256 hashed — never readable.',
                ], [['Google: enhanced conversions with the Google tag', 'https://support.google.com/google-ads/answer/9888145']], self::HERE . ' → Google → step 4', 'Diagnostics in the conversion action shows enhanced conversions “Recording” after a few days.', []],
                ['5. Merchant Center: verify and claim', [
                    'Merchant Center → Settings → Business info → Website → enter the shop address.',
                    'Choose “Add an HTML tag” and copy the tag.',
                    'Paste it at ' . self::HERE . ' → Google → Site verification → Save. (It is the same box as the SEO screen’s.)',
                    'Back in Merchant Center press Verify, then Claim. Leave the tag in place.',
                ], [['Open Merchant Center', 'https://merchants.google.com/']], self::HERE . ' → Google → step 5', 'The Website row says “Verified and claimed”.', ['“Tag not found”: wait a few minutes for page caches and retry.']],
                ['6. Merchant Center: product feed', [
                    'Settings → Data sources → Add product source → Add products from a file → Enter a link to your file.',
                    'Paste the Google feed address from the Google tab (ends /feeds/google-merchant.xml). Fetch: Daily, a quiet hour. Country UAE, language English.',
                    'The feed lists only published, visible products with a price and a photo, with the sale price, brand, stock and GTIN where you have one (else identifier_exists = no).',
                    'Switch it on or off at Growth & Marketing → Google Shopping feed.',
                ], [], self::HERE . ' → Google (feed address)', 'Products → Needs attention shows the issues per product after the first fetch. The Feed check here catches missing fields first.', ['“Image too small”: from 31 January 2027 Google wants photos of at least 500 × 500 pixels.', '“Missing GTIN”: add the barcode in the product editor where you have it.']],
                ['7. Consent Mode v2', [
                    'Off (default): nothing changes. Right for the UAE, where there is no cookie-banner rule like Europe’s.',
                    'EEA: visitors from Europe, the UK and Switzerland are treated as not consenting (the shop has no banner), so Google measures them without cookies. Use this if you run ads to Europe.',
                ], [], self::HERE . ' → Google → step 7', null, []],
            ]],
            ['id' => 'tiktok', 'title' => 'TikTok', 'intro' => 'Pixel, Events API and the catalog.', 'blocks' => [
                ['1. Pixel ID', [
                    'TikTok Ads Manager → Tools → Events → Web events. No pixel yet? Set up web events → name → Manually set up.',
                    'Copy the Pixel ID under the pixel’s name.',
                    'Paste it at ' . self::HERE . ' → TikTok → Pixel ID → Save.',
                ], [['Open TikTok Events Manager', 'https://ads.tiktok.com/i18n/events_manager']], self::HERE . ' → TikTok → step 2', 'TikTok Pixel Helper (Chrome) shows the pixel on the shop.', []],
                ['2. Events API token', [
                    'Your pixel → Settings → Events API → Generate Access Token → copy.',
                    'Paste it at ' . self::HERE . ' → TikTok → Events API token → Save.',
                ], [], self::HERE . ' → TikTok → step 3', null, ['“Access token invalid” (40001/40105): generate a new token on the same pixel.']],
                ['3. Test events', [
                    'Your pixel → Test events → Server → copy the test code.',
                    'Paste it, Save, press Check: a ViewContent from the server appears. Then clear the code and Save.',
                ], [], self::HERE . ' → TikTok → step 4', 'Test events lists the event within a minute.', []],
                ['4. Catalog', [
                    'TikTok Business Center → Assets → Catalogs → Create (or open yours) → Products → Add products → Data feed.',
                    'Paste the TikTok feed address from the TikTok tab (ends /feeds/tiktok-catalog.xml). Update: daily. Currency AED.',
                ], [['Open TikTok Business Center', 'https://business.tiktok.com/']], self::HERE . ' → TikTok (feed address)', 'The catalog shows the products as Approved.', ['TikTok marks a catalog stale after 7 days without an update — keep the schedule daily.']],
            ]],
            ['id' => 'custom', 'title' => 'Custom code (owner only)', 'intro' => 'For a tag the shop has no built-in support for. Only the shop owner’s account can open this tab.', 'blocks' => [
                ['The three boxes', [
                    'Head: printed before </head>. For tags that ask to be “in the head”.',
                    'Body start: right after <body>. For a <noscript> fallback some tags give you.',
                    'Footer: before </body>. For everything else — this is the safest place.',
                    'Each box has On/Off, Where (all shop pages / only the thank-you page / all except checkout) and Load.',
                ], [], self::HERE . ' → Custom code', null, []],
                ['Speed rules (why the shop stays fast)', [
                    'Load “After the page loads” (recommended): the code waits until the page has fully loaded, then runs when the browser is idle. It cannot slow down the page.',
                    'Load “Immediately”: runs as the page opens. Even then the shop adds async to any script file and loads any stylesheet without blocking. Use only when a vendor insists.',
                    'Each box holds up to 20 KB. Code using document.write gets a warning: after the page has loaded it would wipe the page, so the shop skips those calls. Ask the vendor for their async snippet.',
                    'Never on the admin, the owner app, /api or the feeds. Off or empty prints nothing at all.',
                    'Every save keeps the previous version — “Restore” puts any of the last versions back in one click.',
                ], [], null, null, []],
                ['Examples', [
                    'Microsoft Clarity: Clarity → Settings → Setup → “Install manually” → copy the script → Footer box, After the page loads, All shop pages.',
                    'Pinterest tag: Pinterest Ads → Conversions → Tag manager → copy the base code → Footer box. For checkout events use the thank-you page only.',
                    'Snap Pixel: Snapchat Ads Manager → Events Manager → your pixel → Setup → Manual → copy the base code → Footer box.',
                    'Google Tag Manager is NOT needed for GA4, Google Ads, Meta or TikTok — the shop sends those itself. Adding them again here would count every visit twice.',
                ], [['Microsoft Clarity', 'https://clarity.microsoft.com/'], ['Pinterest Ads', 'https://ads.pinterest.com/'], ['Snapchat Ads Manager', 'https://ads.snapchat.com/']], self::HERE . ' → Custom code', null, []],
            ]],
            ['id' => 'trouble', 'title' => 'Checking and fixing', 'intro' => 'Where to look when a number looks wrong.', 'blocks' => [
                ['The Check buttons', [
                    'Each Connect tab has a Check button. Green steps passed; red shows the platform’s own error message.',
                    '“Live page” fetches your home page as a visitor sees it and looks for each pixel and verification tag.',
                    '“Feed” builds the feeds and checks every product for the required fields.',
                ], [], self::HERE . ' → each tab', null, []],
                ['Last events', [
                    'The Last events tab lists the newest server events: platform, event, id, and Sent / Failed / Skipped with the platform’s message.',
                    'If a platform refuses the token, its server events pause for 10 minutes so the shop never waits on it. Fix the token, and they resume.',
                    'Server events are sent after the page has gone to the shopper, with a 4-second limit. A platform being down never slows or fails a checkout.',
                ], [], self::HERE . ' → Last events', null, []],
                ['Numbers that do not match', [
                    'Sales in an ads platform are never exactly the orders: they count only shoppers they can link to an ad.',
                    'Twice as many purchases as orders: a second copy of the pixel is installed somewhere — remove it from Custom code or from any other tool.',
                    'Ad blockers stop browser pixels; the server events are what fill that gap.',
                ], [], null, null, []],
            ]],
        ];
    }

    /** @return list<string> every URL in the wizards and the guide. */
    public static function urls(): array
    {
        $out = [];

        foreach (self::WIZARD as $platform) {
            foreach ($platform['steps'] as $step) {
                if ($step[2] !== null) {
                    $out[] = $step[2][1];
                }
            }
        }

        foreach (self::sections() as $section) {
            foreach ($section['blocks'] as $block) {
                foreach ($block[2] as $link) {
                    $out[] = $link[1];
                }
            }
        }

        return array_values(array_unique($out));
    }
}
