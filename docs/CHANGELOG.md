# Changelog

Versions are the numbers used by the Core Updates screen. Each entry lists the
files it touched, so a diff can be checked against it.

## 2.60.437
**Every category page uses the brand-page header; "Your order" never empty on the
thank-you page.** Apply after .436. No migrations. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "the brand header is okay, and i need that for category pages too ... no more old header style for categories ... a clear switch" | Every category uses the brand-page header, with or without a picture (no picture = the brand page's no-picture look); a picture not on this server falls back to that look, never broken. Appearance -> Site layout -> "Category header (brand design)": first control "Category page header: Brand-page design / Old category header"; the old tabs are hidden while the brand design is on. Per category: Catalog -> Categories -> Edit -> Category header -> Header design |
| "the order details are completely missing ... on thank you page" | Cause: right after a package, page templates are rebuilt by emptying then refilling each file, and Chrome (through the shop app) loads the thank-you page twice 8 ms apart -- the second copy could read an empty template. Templates are now written whole and swapped in at once (reproduced 6 times in 400 before, 0 after) |

Files: see the package's update.json.

## 2.60.436
**The bag is never empty after an unfinished payment, on every payment method.**
Apply after .435. Runs its migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "when ... user cancel the order before completing the payment ... cart must not be empty in any case" + "for tabby also and for other payment methods also" | Card (incl. 3-D Secure), Apple/Google Pay, Tabby and Tamara: on cancel, decline, timeout, provider error, Back, or closing the tab and coming back, the bag comes back by itself with its items, quantities, sets, coupon and gift wrap; stock and coupon are returned; "Your payment wasn't completed — nothing was charged. Your bag is just as you left it." with Try again. A payment that went through always counts as paid. Only the shopper who placed the order gets it back. Arabic wording arrives as drafts (Translation -> Strings) |

Files: see the package's update.json.

## 2.60.435
**The desktop menu shows the page you are on.** Apply after .434. Runs its migrations.
Hard refresh the shop.

| Your request | Now |
|---|---|
| "when i go to any page/category or brand etc from the top menu in desktop, it's not highlighting ... make the nice and super light style" | The top-menu item for the page (or the section it sits in: a sub-category, a product's category, a brand, an article) turns deep pink with the thin underline; one item only; one marker when its panel is open; Arabic too. Appearance -> Header -> Navigation: Show the current page (on), style line / dot / text only, colour. No script, no query, nothing moves |

Files: see the package's update.json.

## 2.60.434
**"SKIN&amp;LAB" reads "SKIN&LAB" everywhere.** Apply after .433. Runs its migrations.
Hard refresh the shop.

| Your request | Now |
|---|---|
| "The '&' mostly coming like this in most of the places. please i need it to fix everywhere" | Imported names were saved HTML-encoded by WordPress and escaped again on the page. Product, brand, category, tag, attribute, menu, review and banner text is decoded once (an undo record is kept; slugs and rich descriptions untouched); future imports store plain text; old orders show the clean name without being changed. Also fixed: brand, category and content page titles were escaped twice or three times in the browser tab and on Google ("SKIN&amp;amp;LAB") |

Files: see the package's update.json.

## 2.60.433
**Category banners, one placing box with the tick, faster ordering, remembered
details, payments check and domain-switch safety.** Apply after .432. Runs its
migrations. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "i need the categories banners, exact same like brand banners" + "if there's banner, then the banner should be picked auto by new design" | Category pages with a picture show the brand-page banner automatically; a new per-category Banner picture wins over the old one; no picture = exactly as before; a picture not on this server = as before, never broken. Appearance -> Site layout -> Category banner (35 controls, "Category header -- as before" to switch back); Catalog -> Categories -> Edit -> Category header -> Banner layout |
| "breakcrums ... goes behind the brand banner" + spacing and padding | Never behind the banner any more; Appearance -> Header -> Breadcrumbs: space above/below plus new inner padding, phone and desktop |
| "i need just once the box ... check icon come and instantly goes to thank you page" + "super quick" | One "Placing your order" box: spinner turns into the tick in the same box (the bank check opens over it); cash 2.8 s -> 1.0 s, card 4.5 s -> 2.4 s to the thank-you page; order emails sent after the shopper is through |
| "the address fields etc should keep the data in user browser ... even without login" | Name, phone, email, country, emirate, area, building and delivery remembered on the shopper's own device (never card, coupon, password, notes); "Remember my details" tick and "Not you? Clear details". Appearance -> Checkout page -> Fields & attention |
| "no any data or settings should be gone after domain switch" + "finalized check for payment gateways" | Platform -> Domain switch -> 1b Payments ready? -> Check everything (Stripe, Tabby, Tamara, COD; read only, no secrets shown). Step 6b fixes old extrabeauty.ae links in content (preview count, undo). Guest baskets, wishlists and recently viewed follow shoppers to the new address; Google still sees a clean 301. Test plan: docs/PAYMENT-TEST-PLAN.md |
| The white bar with the keyboard (Firefox) | /checkout/?kbbdiag=1 now records what sits above the keyboard, so the next fix is exact |

Files: see the package's update.json.

## 2.60.432
**Firefox Place order (the hidden password), coupon in place, product-page tabs and
Continue shopping, breadcrumb, homepage banner text box.** Apply after .431. Runs its
migrations. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "the place order button still not working" (Firefox) -- you found it: a short password Firefox filled into the hidden account box | The hidden account password is switched off while hidden (never autofilled, checked or sent); a hidden box can never block an order; every refusal now says why beside the button you pressed ("Please check: Phone ↑", card still loading, card could not load in this browser). After Back during a card payment the buttons come back alive. Checking page: /checkout/?kbbdiag=1 |
| "use the coupon field, it should not delete all the data ... neither refresh the page" | Coupons apply and remove in place; every typed field, delivery choice and payment method stays; reason under the field in EN/AR; Go/Enter in the coupon box applies it and never places the order |
| Product page: "keep also the tab, more from anua, and more from toner ... third will be continue shoping" | Appearance -> Product page -> You may also like: tabs (brand / most specific category, opens on where the shopper came from), then Continue shopping; best sellers off; "Two separate blocks" option; slider/grid and count per device; no product repeats |
| Breadcrumb "YES agree, do it" | Product breadcrumb uses the same most specific category as the tab (Home › Skincare › Toners › Product); Google's breadcrumb data matches |
| Banner text box, A and D, "make the box outer glow the same" | Appearance -> Banners -> homepage slider -> Text box, and each picture -> Words on this picture (EN/AR). Pastel glow on both. Nothing shows until you write words on a picture |

Files: see the package's update.json.

## 2.60.431
**Place order works in Firefox.** Apply after .430. No migrations. Hard refresh
the checkout once (Ctrl+Shift+R).

| Your request | Now |
|---|---|
| "The place order button is not working at all ... in chrome working, in mozilla browser not working" | Firefox, unlike Chrome, keeps a button's "switched off" state across a reload; a Place order button switched off mid-press came back dead. The buttons now tell Firefox not to keep it, every fresh checkout switches them back on, and the order request carries only this page's security token. Reproduced on 2.60.430 (dead for cash and card), fixed places both |

Files: see the package's update.json.

## 2.60.430
**Email pictures always load; sharper gallery taps; image names Google likes.** Apply
after .429. No migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "the images are failing to load in the emails ... must not failed in any case" | Cause: emails built picture addresses with "/wp-content/uploads/" added twice, so every product anyone had viewed broke (order 56171). Every email picture (orders, campaigns, logo) now points at a file checked to exist on this server, as a JPEG copy every mail app shows (Outlook cannot show WebP), on the shop's https address; a renamed picture resolves to its current file; a missing one shows a neat placeholder. Pictures still only on the old WordPress site stay linked there until Store -> Import -> Addresses & pictures copies them |
| "the gallery images ... gets blured" / "load all gallery sharp pictures in the background" | A tapped photo never shows the stretched thumbnail: the grey loading box (Appearance -> Product styles -> Layout -> Photo loading placeholder), or the 400 copy, then the sharp photo fades in. On 4G, after the page has loaded and is idle, the gallery's photos load quietly one at a time (max 1 MB), stopping the moment a link is touched; never on Save-Data or slower connections. LCP, CLS and page switching unchanged |
| "train our app well by complex conditions" / "as per google liking" | Catalog -> Image SEO names and ALT text tested on 199 real-style titles (sets, 1+1, sizes, SPF, shades, Dr./I'm From/d'Alba brands): sets keep their contents ("cream-mist-spray-set"), number names stay ("345 relief", "snail 96"), sizes and "fl oz"/"PA++++"/"new" are dropped, brand once, ALT never repeats; score marks down keyword stuffing |

Files: see the package's update.json.

## 2.60.429
**Stripe: "We could not reach our card processor" fixed at every cause in our code.**
Apply after .428. No migrations. Hard refresh the admin.

| Your request | Now |
|---|---|
| "the stripe is giving now this error ... please look for it properly deep" | Four of our faults each produced that exact sentence; all fixed with tests. (1) "Add the order number to card statements" sent digits only, which Stripe refuses -- now "KBB* ORDER 10234". (2) A key in the wrong box (pk_ in a secret box, sk_ in a publishable box) was accepted -- the save now refuses it, and a wrong one already saved switches cards off instead of failing at checkout. (3) Test and live keys mixed -- same guard. (4) Another copy of the shop on the same Stripe keys could block an order reference for 24 h -- one retry with a fresh reference. Also: a saved Stripe customer from other keys is replaced. Store -> Payments -> Stripe now shows the reason in plain words in a red box, and names any wrong or empty key box |
| Image SEO | A title longer than 8 words drops its pack size ("6 pairs") before a word of the name ("Jelly") |

Files: see the package's update.json.

## 2.60.428
**Three related-product blocks on the product page.** Apply after .427. Runs its
migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| Product page 3 blocks: block 1 depends on where the shopper came from (category or brand), slider / grid / slider | 1 "You may also like" (slider, tabs "More from {brand}" / "More {category}", opens on the one the shopper came from, brand first otherwise); 2 "Complete your routine" (grid of complementary products, in stock first); 3 "Continue shopping" (slider of recently viewed, then best sellers). On by default; controls at Appearance -> Product page -> You may also like ("One row" with blocks 2 and 3 off is the old page exactly). Product page warm 16.5 -> 14.5 ms, queries unchanged on every page |

Files: see the package's update.json.

## 2.60.427
**Sold-out popup redesign.** Apply after .426. No migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "on the popup, the product image should also come, and black color title, also reduce the font size little bit, and then is soldout in red. also the text, your bag is updated etc. that should be a nice frosted glass type long capsule type box, and with right corner green tick icon, half outside the box." | Each sold-out line shows its picture, the name in black at a slightly smaller size, and "is sold out" in red. After Remove, "Your bag is updated" sits in a frosted-glass capsule with a green tick half outside its right corner (left corner in Arabic). Phone and desktop, EN/AR. No new script and no new request |

Files: see the package's update.json.

## 2.60.426
**Catalog -> Image SEO.** Apply after .425. Runs its migrations. Hard refresh the
admin.

| Your request | Now |
|---|---|
| "rename the products images as per the product title ... single word variation ... ALT tag as bulk ... green tick in media library" + "rating for each image ... like 6/10" | Admin -> Catalog -> Image SEO: Find (search by product, SKU, brand, category; X/10 score in front of every picture URL with the reason; needs-attention sort), Rename files (preview, then Start with progress, Stop/Resume; your word-order variations), ALT text (templates, edit each line, Apply), History with Undo. Media Library shows the score and a green tick. Old addresses (incl. WordPress -600x600 copies and pre-WebP names) forward to the renamed picture in one 301. Guide: docs/IMAGE-SEO-GUIDE.md |

Files: see the package's update.json.

## 2.60.425
**Stripe test orders go through; sold-out popup; payment keys stay saved; Google
Shopping feed, old picture links, image sitemap.** Apply after .424. Runs its
migrations. Hard refresh the shop and the admin. Re-enter your payment keys once.

| Your request | Now |
|---|---|
| "with demo card details, the order is not going through. it gives empty cart error" | Cause: a refused card payment left the basket locked, so every retry said "Your bag is empty". A refusal now reopens the basket at once; retries work; no duplicate orders, no double stock. Stripe's reason is kept in Store -> Payments -> Stripe -> Payment log |
| "if any product is currently out of stock during checkout, it should give me proper popup with remove from the list option" | Popup listing every sold-out line (sets included): Remove and continue (totals refresh in place, details kept) or Go back to cart. All payment methods, EN/AR, phone |
| "i need to re-enter the api every single time" (all gateways) | Saving gateway settings never erases stored keys; a blank key box keeps the saved key. Only "New webhook URL" clears its own secret |
| SEO checklist | Audit in docs/SEO-AUDIT-2026-10.md. Google Shopping feed at /feeds/google-merchant.xml (Growth & Marketing -> Google Shopping feed); old WordPress picture URLs (incl. -600x600 copies) forward to the picture in one 301; image sitemap on |

Files: see the package's update.json.

## 2.60.424
**Homepage opens with one render-blocking stylesheet instead of two.** Apply after
.423. Hard refresh the shop.

| Your request | Now |
|---|---|
| "do as per your recommendations, but make sure there's no compromise on the site speed or pages shifting speed" | When the homepage is the first page of a visit, the small grid stylesheet is printed inside the page instead of being a second blocking request (phone first paint 1.36 s -> 1.27 s; 49 -> 44 KiB render-blocking). Clicks inside the shop and pre-loading download exactly the same files as before; pixels identical; no setting. Splitting the main stylesheet was measured slower and not shipped |

Files: see the package's update.json.

## 2.60.423
**Platform -> Domain switch: the kbeautybliss.com move as buttons.** Apply after
.422. Runs its migration. Hard refresh the admin.

| Your request | Now |
|---|---|
| "i'm non technical, so avoid anything for me to do" + "200% working" | Admin -> Platform -> Domain switch (owner only): readiness check with Fix buttons, set the new name, copy-ready Cloudways and Internet.bs values with "Check DNS now" and "Check certificate", one button to switch the shop's address (+ Site URL + caches), Stripe / Tabby / Tamara re-registration, missing-picture fetch from WordPress, test checklist, forward extrabeauty.ae, and remove extrabeauty.ae (refused until nothing points at it). Every step turns green only on a real check. Tested end to end in a browser, 56/56 at 1280 and 390 |

Files: see the package's update.json.

## 2.60.422
**Stripe settings, like the WordPress Stripe plugin.** Apply after .421. Runs its
migrations. Hard refresh the admin. Every new setting starts at today's
behaviour.

| Your request | Now |
|---|---|
| "for stripe, i need the setting on backend to control the name on statements, hooks, invoice number etc." | Store -> Payments -> Stripe: statement descriptor (checked against Stripe's rules) and card suffix with optional order number; order number prefix shown in Stripe (the shop's own numbers unchanged); payment description template; separate Test and Live keys with a TEST MODE / LIVE badge; "Set up webhook automatically" with last event received and last signature failure (also removes an old-domain webhook); authorise now, capture later (Capture button on the order); Stripe email receipts; a payment log (no card data, no secrets). A test key can no longer take payments in Live mode. Test guide: docs/STRIPE-TEST-GUIDE.md |

Files: see the package's update.json.

## 2.60.421
**Mobile speed: banner slider waits for the page; brand pages no longer
jump.** Apply after .420. Hard refresh the shop.

| Your request | Now |
|---|---|
| "in mobile 5.4 sec ... look for major things ... i really want to reduce that" | The banner slider's first slide change now counts from the page's load, so it no longer changes the screen while the page is still arriving (measured Speed Index on a slow phone 3.4 s -> 2.7 s on the test copy). Phones no longer run the desktop menu-fitting script (the forced reflow from our code). The biggest remaining cause is the 2.6 s the page takes to reach the test phone, which is network, not code |
| Brand page 22 px jump (found while measuring) | The brand page's styles are in the head, so it paints in place: CLS 0.015 -> 0 (laptop), 0.023 -> 0 (phone). Brand header spacing controls unchanged |

Files: see the package's update.json.

## 2.60.420
**Product photos never show their title; grey loading placeholders; instant
gallery taps; Building / Area / Emirate address; old WordPress links
forward.** Apply after .419. Runs its migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "it gives the empty box and title displays there in place of image" | Cause: the site app's background script handed a photo to the page only after saving a copy; when the phone refused the save, the photo failed and the title showed. Now the photo shows first and the copy is saved quietly |
| "loading grey bars" (gallery and product cards) | Grey shimmer behind every gallery and card photo until it paints; no title, no jump. Appearance -> Product styles -> Layout -> Photo loading placeholder (Grey shimmer / Plain grey / None) |
| "switching between the product gallery images, gives clear delays" | The thumbnail's own picture shows at once and the full photo covers it; the next photo is fetched quietly. Slow phone: 1.5 s -> 0.2 s; thumbnails paint at 1.5 s instead of 3.0 s |
| "sometimes the inner pages stuck fully and keep loading" | Picture copies made after a page is sent now use one server worker at a time, and background pre-loads make none (1-3 s -> under 0.02 s per pre-load). SSH checks in docs/PG2-STALLS.md if it ever happens again |
| "Building / Apartment or Villa ... Area / Street ... the Emirates will work as City" + bilingual list | Checkout and My account addresses: Building / Apartment or Villa, Area / Street, Emirate (Arabic — English list, your order), Country. The emirate is the order's city. Oman, Saudi Arabia, Qatar, Bahrain, Kuwait lists too. Appearance -> Checkout page -> Fields & attention -> "Emirate / state as a list". Arabic labels: approve 9 drafts in Store -> Translations. Tabby now receives the delivery address (it was empty) |
| Old WordPress addresses 404 | /sitemap_index.xml, /wp-sitemap.xml, Yoast sitemaps, /feed/, /my-account/lost-password/, /my-account/edit-account/ forward (Store -> SEO & Meta -> Redirects & 404s) |

Files: see the package's update.json.

## 2.60.419
**Desktop mega menu opens on category, shop and product pages.** Apply after
.418. Hard refresh the shop.

| Your request | Now |
|---|---|
| "the DESKTOP mega menu is not showing anything upon mouse hover" | Cause: a leftover rule from the old header in the category/shop and product stylesheets hid every multi-column panel on those pages (the homepage was fine). Removed; the panels open on every page type, checked in a browser at 1280 and 1024 on home, category, shop, brand, product and blog, no errors, no sideways scroll |

Files: see the package's update.json.

## 2.60.418
**Menu links clickable again while a mega panel is open; checkout payment
boxes with official logos; domain-move fixes.** Apply after .417. Hard refresh
the shop and the admin.

| Your request | Now |
|---|---|
| "the page shifting is coming again slightly delayed" | Cause (measured): 2.60.416's invisible hover areas that keep a mega panel open covered the lower part of the neighbouring menu links, so a click there did nothing (11 of 15 neighbour clicks navigated; 2.60.415: 15 of 15) and the link never pre-loaded. Now 15 of 15, every link fully clickable. Server time, pre-loading and page sizes measured unchanged since 2.60.415 |
| "option A, soft tint is fine ... give controls too on backend" | Official Tabby and Tamara logos, Visa/Mastercard, cash-on-delivery icon; each box tinted in its brand colour. Appearance -> Checkout page -> Payment boxes (style, logos, logo height, tint, border, per-method colours, Tamara badge/wordmark; "Today" restores the old boxes exactly) |
| "change the domain ... zero dependency of the old domain" | docs/DOMAIN-MOVE-KBEAUTYBLISS.md runbook; `php artisan kbb:domain-check kbeautybliss.com --old=extrabeauty.ae` (read-only); www of every old address is forwarded too; Tabby re-sync removes the old-domain webhook; admin hints no longer say extrabeauty.ae |

Files: see the package's update.json.

## 2.60.417
**Spotted page from Instagram; blog and articles on the shop header; your
policy pages; checkout footer links; menu underline.** Apply after .416. Runs
its migrations. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "fetch our instagram all posts / videos, and to choose from the list which one need to be shown ... cover image auto ... likes, comments, shares ... @kbeauty.bliss ... on click it will go the original posts" + "if the post is video, it should play on our website" + card design C | /kbeautybliss-spotted/ builds itself from the posts you tick. Appearance -> #KBeautyBliss Spotted -> From Instagram (Refresh from Instagram, filter, search, tick, order, Save selection). Settings -> Spotted page: source Instagram (Manual is the switch back), card style C, videos play on our page ON. Reconnect once at Content -> Instagram to allow share counts; until then shares are hidden. Nightly refresh 04:41 on the scheduler cron |
| "on blog page, and on article page, the main header is not coming correct" | The Journal and every article use the shop's own header, menus, phone menu and footer |
| Shipping & Delivery, Returns, Privacy Policy, FAQs "100% same content" | Your pasted text, word for word, with headings, bullets and spacing; the four run-in FAQ questions split out. Only pages still holding our placeholder were changed. Footer Help column: Shipping & Delivery, Returns Information, Order Tracking, FAQs, Privacy policy, Contact us, About us |
| "remove these links. only in the footer link, replace those two links" | Checkout footer links now read Shipping & Delivery and Returns Information (Appearance -> Footer). The links under the cart totals and Place order are off (switches kept) |
| "the underline should not show as the panel highlight is showing" | Hovering a mega menu item shows only the pointer |

Files: see the package's update.json.

## 2.60.416
**Desktop mega menus fit the site width.** Apply after .415. Hard refresh the shop.

| Your request | Now |
|---|---|
| "The mega menu columns must be adjusted auto as per the site width ... more than 4 columns ... start from the most left ... a nice edge type to show that this mega menu is for this parent item" | No dropdown ever runs past the site edge. Panels with more than 4 columns (All Brands) start at the site's left edge; 2-4 column panels open under their item and slide only as far as needed. Columns and text shrink with the width (down to 130px / 11.5px), then a flat list reflows into fewer, longer columns. A pink edge with a small point under the hovered item. Appearance -> Header -> Navigation: "Fit mega menus to the site width" (ON), "Start from the left beyond" (4 columns), "Smallest column width", "Smallest mega menu text", "Pointer to the parent item" (ON) |

Files: see the package's update.json.

## 2.60.415
**Phone menu style V4 "Two-tone", with full controls.** Apply after .414.
Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "V4 is fine ... full control of font sizes, row height, paddings, upper custom links, panel size, on click sub menu opening animation ... adjust auto as per the user mobile screen size" | A pink top band with search and quick links above the list, on the glass panel. Appearance -> Mobile menu -> Style (V4 / Classic glass), Quick links (up to 8, EN + AR, pick or type the link, colour), Sizes (panel, band, search, quick links, rows, sub-items, headings, arrows), Sub-menu animation (Slide down / Fade / Expand / None, speed, easing). Sizes are set for a 390px phone and scale with the screen; taps never under 44px |

Files: see the package's update.json.

## 2.60.414
**Checkout: floating labels and the laptop totals card; Install App installs
directly on Android; owner app Install card.** Apply after .413. Runs its
migration. Hard refresh the shop and the owner app.

| Your request | Now |
|---|---|
| "the heading it self will show as place holder ... same like we have on Create account page" | Every checkout field and the cart's discount code: icon + the name inside, floating to a small label on tap. Appearance -> Checkout page -> Fields & attention -> "Floating labels on checkout fields" |
| "ON DESKTOP checkout: the summary should have only products, rest ... above the place order button" | Laptops: the summary shows only products; subtotal, delivery, discount, COD fee and total in a card right above Place order. Same tab -> "Desktop: totals above Place order" |
| "if user click from android ... it should give install itself ... i don't want popup" | Android Chrome/Edge: the tap opens the phone's own install box. Elsewhere no popup: the row's own line shows the one step with an arrow. Hidden on Android phones that already have the app. iPhone: Apple allows only Share -> Add to Home Screen. Appearance -> Footer -> App row -> "install help" |
| "for owner app, when login first time, and if app is not installed, it should give immediately install option" | An "Install KBB Owner" card at the top of My store right after sign-in in a browser; Not now = 7 days |

Files: see the package's update.json.

## 2.60.413
**Phone menu opens from the left as a glass panel; Marketing Pixels guides.**
Apply after .412. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "the same dual columns design ... open from left side ... adjust everything as per the user screen size" + "glossy glass type" | Today's menu, unchanged inside, now a frosted-glass panel from the left (right in Arabic), 282-420px wide by screen. Appearance -> Mobile menu -> Panel -> "Menu opens from": Left (glass panel) / Bottom (sheet) |
| "proper guide for each one, along with live urls ... a beautiful popup eye icon" | Growth & Marketing -> Marketing Pixels: an eye button before each ID opens a guide with steps, the ID's shape, the official links, a Test tip and "Paste my ID" |

Files: see the package's update.json.

## 2.60.412
**No zoom when tapping a field on phones; Update App for installed apps.**
Apply after .411. Runs its migrations. Hard refresh the shop and the admin.

| Your request | Now |
|---|---|
| "the screen become zoomed in apple devices ... for all input fields in our whole website" | Every text field is 16px on touch screens (shop, owner app, admin): 529 of 689 measured were smaller. Laptops byte-identical; pinch-zoom still works |
| "if we published any updates in the site app ... display again on the existing users device too with Update App button" | App -> Site App -> App update: write a message, "Publish an update to installed apps". Installed apps show an Update App row once per update; one tap loads the new version. Nothing changes until the first publish |

Files: see the package's update.json.

## 2.60.411
**Inner pages 3-6x faster on the server; pages open instantly on intent.**
Apply after .410. Runs its migration. Hard refresh the shop.

| Your request | Now |
|---|---|
| "inner pages are loading slightly slow, before it was super fast" | The cause: every page re-read the whole settings file thousands of times (3,824 reads for one category page). Now once per page. Measured with live-sized settings: product 309 -> 45 ms, category 308 -> 36 ms, brand 245 -> 33 ms, home 275 -> 46 ms. Pages byte-identical |
| "super blazing speed with super fast shifting layout from one page to another" | Appearance -> Site layout -> Page speed -> "Open pages instantly" (ON): the next page is fetched while the finger is down / the pointer rests; never cart, checkout, account, admin, owner app or anything that changes state; off on Data Saver/2G. Pixels and "Most viewed" count only opened pages. Fade between pages: OFF (measured slower), one switch |

Optional: Content -> Media Library -> "Make phone-sized copies" once.

Files: see the package's update.json.

## 2.60.410
**Cart and checkout: no address picker row, the order summary as one thin
row, Browsed off; the footer button reads "Install App".** Apply after .409.
Runs its migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "turn off the address row completely, from cart and checkout ... bring the manual fields under address section" | Cart: the address row is gone, the checkout row stays. Checkout: address, emirate, city/area, country typed directly under Shipping address, pre-filled for signed-in customers; the emirate still prices delivery |
| "replace the whole summary section to this single thin row ... pink arrow with slight continue animation" | One 50px row: bag icon, Order summary, the total, a pink arrow; tap to open the full summary |
| "turn off the browsed tab ... do not remove any existing functionality" | Off. All three behind switches: Appearance -> Checkout page -> Fields & attention |
| "rename the button from Install > Install App" | "Install App" / «تثبيت التطبيق»; the row tightened on phones so it fits; Arabic headline «حمّلي تطبيقنا» |

Files: see the package's update.json.

## 2.60.409
**Footer "Get the app" row; the category tree from kbeautybliss.com.**
Apply after .408. Runs its migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| App row: frosted glass "downside the big logo", official-colour icons, "orders update, restock alerts & coupons", typing EN/AR lines, WhatsApp hides while it shows | Appearance -> Footer -> Site footer · Desktop / · Mobile -> App row (ON). Hidden inside the installed app and on laptops (switch: show on laptops with a QR). Arabic headline/button drafts: Translation -> Strings |
| "the exact hirarchy which i have at kbeautybliss.com" | Catalog -> Categories -> Copy hierarchy from kbeautybliss.com: dry run, then Apply. Addresses stay short (/collections/oil-cleansers/); nested forms 301 to them |

Files: see the package's update.json.

## 2.60.408
**Mega Menu: add categories, brands, pages, posts and collections with a
tick; a scrollbar at the bottom. "Extra Beauty" becomes "K-Beauty Bliss"
everywhere; test pushes to the phones you pick; sold-out products option;
brand header spacing.** Apply after .407. Runs its migrations. Hard refresh
the admin and the shop.

| Your request | Now |
|---|---|
| "a full list of categories, brands, pages ... to directly click and add to specific menu / columns" and "a horizontal scroll bar ... at the bottom" | Store -> Mega Menu -> "+ Add items": Categories (as a tree), Brands A-Z, Pages, Blog posts, Collections, Custom link; search, tick, choose the menu and column, "Add to column", or drag. Picked items follow a renamed category or brand. A scrollbar fixed under the columns with < > buttons; the Mac back-swipe no longer fires there |
| "exclude the sold out products or show at very end" | Appearance -> Site layout -> Product grid -> Sold-out products (shop default), and Catalog -> Categories / Brands -> Edit (each one). Ships at "Show as usual" |
| "the top notch is covered in iphones, but not in android" | Android keeps the old full-screen layout until the owner app is installed again; the app now says so on such a phone, with the steps |
| "replace the Extra Beauty with K-Beauty Bliss everywhere ... apply this auto" | Runs by itself on apply: product names, descriptions, tabs and picture text, categories, brands, reviews, captions, routines, checkout method names, labels and Arabic spellings. Orders, customers and sent emails stay as they were; extrabeauty.ae and info@ addresses stay until the domain move. Check: Store -> SEO Keywords -> Brand name ("0 to replace") |
| "an option to test to my device ... list of installed devices" | Growth & Marketing -> Push Notifications -> Devices: every installed phone, tick and Send test, result per phone; "This is my phone" and a nickname |
| "spacing controls overall as marked" (brand header) | Appearance -> Site layout -> Brand page -> space above / at the sides of the header, laptop and phone (and per brand). All at today's 22px |

Files: see the package's update.json.

## 2.60.407
**Push notifications for the shop app: Growth & Marketing -> Push
Notifications.** Apply after .406. Runs its migrations. Hard refresh the admin.

| Your request | Now |
|---|---|
| "a dedicated page ... Growth & Marketing > Push Notifications > Campaigns" | Campaigns: title, message, link, live phone preview; send to everyone or by emirate, city, language, phone type, customers / guests and purchase rules, with a live count; test to your phone, schedule, send; report with deliveries, clicks and click rate by emirate |
| "orders updates will be auto" | Automations -> Order updates, ON: the same moments the status emails go out, and only when they do |
| "back in stock ... only once if ... visit that out of stock product" | Automations -> Back in stock, ON: only phones that viewed it sold out, once per phone and product, ever |
| abandoned cart, price drop | Automations -> Basket reminder (after 3 h, one only) and Price drop (10% or more, viewed sold out or hearted), ON |
| "i want to minimal the notifications" | Settings: at most 1 offer a day and 3 a week per phone; quiet hours 22:00-09:00 (order updates are never held) |
| "city, region wise ... no disturbance to the customer" | Where a phone is comes from its orders' delivery address first, then the IP address (free DB-IP city table, refreshed monthly by itself); never a question on the phone. A Sharjah campaign skips Dubai phones |

Needs the server's cron line (the same one Marketing Emails uses). If it is
missing, the Push Notifications screen shows the exact line to add in
Cloudways -> Cron Job Management.

Files: see the package's update.json.

## 2.60.406
**Homepage brand photos load about 12x lighter.** Apply after .405. No
migrations. Hard refresh the shop.

| Your request | Now |
|---|---|
| "the browser loading bar is taking little bit longer to finish" | The Top brands photo cards were sent at full size (Anua 903 KiB into a 158x198 frame). They now use the shop's resized copies: 8,046 KiB -> 677 KiB, all brand photos shown 6.8 s -> 1.1 s on a laptop (measured). The first visit after applying makes the copies; every visit after gets them. |

Files: see the package's update.json.

## 2.60.405
**Every category and brand keeps its own product order; filters and links
to the shop off; the address no longer changes while products load; your
own app icon and favicon; both apps colour the top of the screen; both apps
ask for notifications; Add person on App → Owner App; approved contrast.**
Apply after .404. Runs its migrations. Hard refresh the admin and the shop.
**Then re-save Medicube's order once** in Catalog → Reorder.

| Your request | Now |
|---|---|
| "NO ANY CATEGORY OR BRAND should disturb the sorting of each other" | Catalog → Reorder saves each category and brand separately; every page opens exactly as before |
| Filters "turned off completely on all pages"; Shop all "no where" | Filters column, drawer and buttons not rendered; no links to /shop on category, brand or Super Sale pages. Back: Appearance → Site layout → Product grid |
| "the url must not change, only the more products loads" | The address stays put; Back returns to the same products and scroll. Back: Appearance → Site layout → Loading more products → "Show the page number in the address" |
| "allow me to upload our own icon ... also site favicon" | App → Site App → App icon; App → Owner App → App icon; each with a favicon part and a size guide |
| "extend the background color to the top end" | Owner app: no black band; colour to the top edge. Shop app: status bar in the header colour. Back: App → Owner App → Customise app → Layout → Top of the screen |
| "apps should ask by default about to allow notifications" | Both installed apps ask once on opening (Not now: again after 7 days). App → Owner App → Settings; App → Site App |
| "there should come all users list, and add new too" | App → Owner App → Team: everyone listed, "+ Add person" makes the account and switches the app on |
| Contrast, every row but gold | The approved darker text colours and 24px phone footer taps |

Remove and re-add both apps on your phones to see the new icon and top colour.

Files: see the package's update.json.

## 2.60.404
**The admin menu loads complete at once (and hides); a new App menu with Site
App and Owner App; your website installs as an app; a beautiful 404 page;
and the shop is much lighter and faster.** Apply after .403. Runs its
migrations. Hard refresh the admin and the shop.

| Your request | Now |
|---|---|
| "upon hard refresh some menu items ... keeps missing ... delayed" | The whole left menu arrives with the page: ~6 s → under 1 s on a slow connection; later visits download 82 KB instead of 1.35 MB. Each role sees only what it can open |
| "left panel ... close and open icon" | The panel icon at the far left of the top bar hides and shows the menu, remembered |
| "keep controls under App (Main menu) > Site App / Owner App" | App → Site App and App → Owner App, under Platform |
| "build the app for the site ... one icon to test" | The shop installs to the Home Screen (white KB on pink, "K-Beauty Bliss"); App → Site App switches it on/off. No install button on the site yet, as you said |
| 404 page, option B, all four selectable, full controls | Safety → 404 page: design A/B/C/D (B default), words EN/AR, links, trending, colours, desktop / mobile sizes, live preview |
| "increase the speed ... fix all issues the google specifies" | Right-sized photos everywhere: mobile LCP 4.4–12.1 s → 2.3–3.0 s, pages 1.2–4 MB → 0.17–0.5 MB; first product image loads first; CSS minified; two accessibility fixes |

Install on a phone: Android Chrome ⋮ → Install app; iPhone Safari Share →
Add to Home Screen → Add. Then place one test order each with Tabby, Tamara
and card from inside the installed app.

Files: see the package's update.json.

## 2.60.403
**Menu editor in columns with drag-anywhere; brand pages on the panel design
everywhere; owner-app sales figures fixed with date ranges; your own app
address; search engines kept off every private page; four Reorder arrows;
Store → Analytics draws again.** Apply after .402. Runs its migrations. Hard
refresh the admin, the shop and the owner app.

| Your request / report | Now |
|---|---|
| Menu: "drag n drop ... super annoying", columns, add in each column | Store → Mega Menu: every top-level item is a column; drag any item anywhere (rows slide aside, no thin line), number box, ↑↓ ←→, "+ Add a sub-menu" / "+ Add item" inline, a floating + |
| Brand pages "not loading as per these previews", logo off, name position, read more after two lines, centred | The panel shows on every brand (it skipped brands with a banner); logo off by default; name and description centred; 2 lines + Read more / Read less. Brand page → Edit pencil → Edit brand header → Header layout, and Appearance → Site layout → Brand page |
| Owner app: analytics "not working", date range, Total only, top sellers 7 days / month | Gross/Net/Total now match Store → Analytics exactly; Today / Yesterday / 7 days / This month / Last month; Total only by default; Top performers 7 days \| This month |
| Control the owner app from the admin | Platform → Users & Roles → Owner app → Customise app (branding, fonts, sizes, layout, screens and functions on/off, live preview) |
| "change the back login url for the app owner" | Owner app → The app's secret address → Custom address |
| "sync etc must be OFF for any search engine" | noindex header on the app, admin, admin API, password-reset pages; robots.txt and sitemaps never name either secret address |
| Reorder: "should be 4, 2 grey and 2 red" | Grey: one place; red: top / bottom of the whole list; all wait for Save order |
| (found) Store → Analytics drew nothing | Fixed |

Files: see the package's update.json.

## 2.60.402
**Reorder works on the shop: your order wins, Save is always in view, and
brand pages stay on the brand.** Apply after .401. Runs 1 migration. Hard
refresh after applying.

| Your report | Cause, and now |
|---|---|
| "on front-end the same old sorting is showing ... two products showing on top" | Featured products were sorted BEFORE your Reorder order on the shop, category and Shop-all-brand listings. Now your order comes first everywhere; featured only breaks ties between products you have not ordered |
| "there must be SAVE button. should not apply the order/sorting directly" | Save sat under the whole list and its pager, easy to miss, and a number for another page saved at once. Now a Save bar stays pinned to the bottom while anything is unsaved (Discard / Save order), and a move to another page waits for Save |
| "every brand have shop all etc button ... it should be stick to that brand only" | Shop all {brand} and Popular right now · View all switched off (Appearance → Site layout → Brand page keeps both switches) |

Catalog → Reorder: choose Categories or Brands, reorder, press **Save order**.

Files: see the package's update.json.

## 2.60.401
**Owner app: a header half the height with your store name, and full screen
only when you tap its icon.** Apply after .400. Hard refresh the app.

| Your request | Now |
|---|---|
| "the header bar is too heighted, i need to reduce atleast 50%, make the KBB icon small" | My store header 84px → 44px (and the 22px strip above it is gone); KB logo 64px → 32px |
| "reduce the store name ... keep for now my store name K-Beauty Bliss" | The title reads K-Beauty Bliss at 18px; a long name ends in "…" |
| "click anywhere should not perform any full screen ... beside the refresh icons make a icon of enlarge" | Full screen only from the enlarge/shrink icon beside Sync now; no other tap does it |

Notifications' "Mark all read" is now a ✓ icon, so the title still fits beside
the new icon on small phones.

Files: see the package's update.json.

## 2.60.400
**The owner app: K-Beauty Bliss Owner, design B "Petal", at a secret address,
PIN-unlocked, for the owner and the team members you choose.** Apply after
.399. Runs its migrations (they create the app's address and its push keys).
Hard refresh after applying.

Set it up: Platform → Users & Roles → Owner app. Turn access on for a member,
Set PIN (6–8 digits), copy the app address, open it on the phone, sign in once
with email + PIN, then Add to Home Screen.

| Your request | In the app |
|---|---|
| "accessible only from the owners and team members, with custom PIN" | Access per member, PIN set in the admin, the phone remembered; 5 wrong PINs lock it, escalating to 24 h and then to a Full Admin unlock |
| "NOT SYNCED by google or any search" | Secret address, noindex/nofollow on every response, in no sitemap or link |
| Orders, bulk status, order detail | Today / Yesterday / Earlier, status chips, search, bulk bar; detail with status, mark paid, notes, Tabby/Tamara, call / WhatsApp |
| Products, inventory, quick edits | Price, stock, categories, visibility |
| Customers with purchase history | Lifetime value, orders, spend by month, what they buy |
| Notifications | New orders, status changes, failed payments, low and out of stock; you choose which |
| "auto refresh ... silently ... grey bars ... refresh icon on the top header" | Back within 30 min: real figures at once, refreshed silently. Longer away: grey bars, everything synced at once. Sync now in every header. "Show loading bars after (minutes)" in the Owner app tab |
| Full screen on phones | First tap enters it; the small arrows icon switches it off and on |

Security (Platform → Users & Roles → Owner app → Security): optional own
subdomain, lock-screen notification text Detailed / Generic, Unlock now.

Files: see the package's update.json.

## 2.60.399
**Page header picture: full width, set for desktop and phone apart. Phone is
full width now; desktop as it was. And Pages → Page header in the console
works again.** Apply after .398. Hard refresh after applying.

| Your request | Now |
|---|---|
| "full width option for desktop and mobile in the edit panel" | Page → Edit header → Sizes · desktop / Sizes · phone → Picture width: Normal / Full width (also Pages → Page header) |
| "by default keep full width in mobile, and on desktop normal width" | Phone 390: picture 12–378px → 0–390px, square corners. Desktop: unchanged until you switch it (full = window edge to edge, no sideways scroll) |
| (found while building it) Pages → Page header in the console broke in .396 with "mod.controls is not a function" | Fixed: the editor module keeps its named exports |

Files: see the package's update.json.

## 2.60.398
**Brand header: every control you listed, desktop and phone apart, from the
brand page's Edit pencil.** Apply after .397. Hard refresh after applying.

| Your request | Now: brand page → Edit pencil → Edit brand header → Header layout |
|---|---|
| "logo circle or rectangle to choose from" | Logo shape (both devices) |
| "box spacings" | Laptop: space inside the panel, distance from the banner edge, gap between name and description. Phone: description card padding and its gap below the banner |
| "positioning" | Laptop: panel left / centre / right and top / middle / bottom. Phone: logo-and-name capsule top or bottom × left / centre / right |
| "adjust the height of overal header" | Laptop: header height (the whole header). Phone: banner height (the description card comes below it) |
| "font sizes etc." | Brand name and description size, and logo size, laptop and phone apart |

A Laptop | Phone switch shows one device's controls; the preview redraws as
you drag, with no request. Shop-wide defaults: Appearance → Site layout →
Brand page (Panel · laptop / Panel · phone). Design A is the default: phone
logo 44 → 52px and name 20 → 22px to match the preview you picked.

Files: see the package's update.json.

## 2.60.397
**Catalog → Pagination: turn pagination off for the whole shop, or for any
category, brand or listing page. Off shows every product at once.** Apply
after .396. Runs 1 migration. Hard refresh after applying.

| Your request | Now |
|---|---|
| "option to turn off the pagination function completely for any page, any category or brand" | Catalog → Pagination: one switch for the shop, and Follow / On / Off for each listing page, category and brand (search to find one) |
| "in case of turned off, all the products will show at once" | Off: every product on one page (up to 500), no page links, no load-more. An old /page/2 address goes to the listing in one hop |

Nothing changes until you turn something off: pagination stays on everywhere.

Files: see the package's update.json.

## 2.60.396
**Category pages: an Edit header button on the page. The strip can be
turned off on desktop or on mobile. A long title keeps its dot and count on
its own lines.** Apply after .395. Runs 1 migration. Hard refresh after applying.

| Your request | Now |
|---|---|
| Category: "background image, centeralized title and description text ... controls for desktop and mobile both on the front-end" | Category page → Edit header: name, description, picture (and phone picture), picture position, height, spacing, text look. Desktop and phone set apart with the Laptop / Phone switch |
| "i need the strip display control also, to turn off for desktop / mobile" | Page → Edit header → Strip → On desktop / On mobile, and Pages → Page banners → Strip beneath the picture. Both on until you untick one |
| (seen in the shots) a long title on a phone put the dot and the count on rows of their own | They now sit on the title's first and last lines: 248px → 187px at 390 |

Files: see the package's update.json.

## 2.60.395
**Brand pages: the frosted panel on the banner, logo and name together, the
description below the banner on phones.** Apply after .394. Runs 2 migrations.
Hard refresh after applying.

| Your request | Now (measured) |
|---|---|
| "logo will be on the background image, beside logo, brand name, and downside brand description" | A frosted panel on the banner: logo 72px + name on one row, description beneath (1280) |
| "content should not full width, almost 60% of the page width" | Panel 60% of the header (742px of 1236 at 1280) |
| "in mobile the description will come under banner" | Phone: logo + name in a capsule on the banner, description 12px below it |
| "logo to display in circle or rectangle" | Brand page → Edit pencil → Edit brand header → Header layout → Logo shape |
| "adjust the width and height ... with drag width and height size bar" | Same popup: width, laptop height, phone height, content width, picture position |

Shop-wide defaults: Appearance → Site layout → Brand page.

Files: see the package's update.json.

## 2.60.394
**Custom pages: the strip is off everywhere, turned on per page from the
front-end Edit panel; header and strip drag into either order; no gap under
the site header; space sliders, including above the header.** Apply after
.393. Runs 1 migration. Hard refresh after applying.

| Your request | Now |
|---|---|
| "turned off the strip by default on all pages" | Off on every page. Your banners are kept, only unassigned |
| "allow to turn ON on any page from the edit panel on the front-end" | Page → Edit header → Strip → Show the strip on this page (and which strip, if you have several) |
| "sort header area and strip. by drag n drop up down" | Edit header → Order: drag the grip (mouse or finger), or the up / down arrows |
| "remove any space between header area and main site header" | 26px → 0 at 390, 51px → 0 at 1280 |
| "option to control to spacings" / "uper space also of header area" | Edit header → Sizes: Space above the header (0), Space between header and strip (0), Space below the header (22 / 12) — laptop and phone apart, 0–80px |

Files: see the package's update.json.

## 2.60.393
**Phone category pages: no Filters button, 1 / 2 column buttons, smaller sort,
the header picture covers the header, less space under it.** Apply after .392.
Runs 1 migration. Hard refresh after applying.

| Your request | Now (measured at 390) |
|---|---|
| "turn off the filters for now" | Filters button gone on phones. Appearance → Site layout → Product grid → Filters button · phone |
| "make number of columns to select 1 or 2, max" | 1 / 2 buttons beside Sort. … → Column buttons (1 or 2) · phone |
| "reduce the capsule size of sort" | 44px → 34px, rounded (text stays 16px so iPhones do not zoom) |
| "the background image on mobile should cover the whole area by middle and center" | The picture fills the header, centred. Appearance → Site layout → Category header → Show the whole picture on phones (now off) |
| "reduce spacing between header area and sort etc row" | 44px → 22px |

Files: see the package's update.json.

## 2.60.392
**Edit header: "Choose" opens the picture picker right under the button.**
Apply after .391. Hard refresh after applying.

| Your request | Now |
|---|---|
| "these two upload functions are still not wotking" (Picture / Phone picture → Choose) | The Media Library opened at the top of the panel, out of sight above the button. It now opens under the button you pressed, scrolled into view; Upload new and picking from the library both set the picture |

Files: see the package's update.json.

## 2.60.391
**Uploads keep your file name (only the extension changes); the cart/checkout
WhatsApp tab floats with no side space.** Apply after .390. Hard refresh after
applying.

| Your request | Now |
|---|---|
| "i don't want to change the file name at all ... only the file extension need to be changed" | `Anua PDRN Glow Set-Banner.jpg` is saved as `anua-pdrn-glow-set-banner.webp` (lower case, spaces become hyphens). A name already in use gets -2. Pictures uploaded before keep their old names, because pages already point at them |
| "i don't want a dedicated left side space ... the support vatical bar will float on the left side ... on mobile checckout page too" | The tab floats over the left edge on the cart and the checkout; nothing moves. Appearance → WhatsApp button → Cart & checkout · phone → Make room beside the tab (off) brings the space back |

Files: see the package's update.json.

## 2.60.390
**Filters panel on phones: tap outside to close, × always visible, width
control; tap highlight on small icons only; "&amp;" in category and brand names
fixed.** Apply after .389. Runs 2 migrations (name repair, cache clear). Hard
refresh after applying.

| Your request | Now |
|---|---|
| "filters panel width control" | Appearance → Site layout → Product grid → Filters drawer width · phone (60–100%) and Filters drawer · never wider than (260–600px). Ships at today's 300px |
| "when clicked on empty area, the filter panel must ... hide auto" | A light backdrop beside the open panel closes it; Esc too; the page behind no longer scrolls |
| "cross icon must not scroll up with the panel" | The FILTERS × row stays pinned at the top while the list scrolls |
| "the click tap is giving some background color ... good only for small things like icons" | Only icon buttons respond. Appearance → Site layout → Press feedback → Which controls respond (Small icons only / Every button and row) |
| (his screenshot) "Hydration &amp;amp; Glow" | Category and brand names imported with "&amp;amp;" are repaired; imports store them correctly from now on |

Files: see the package's update.json.

## 2.60.389
**Cart and checkout on a phone: the WhatsApp button becomes a slim left-edge
"24/7 Support" tab.** Apply after .388. Runs 2 migrations (cache clear, Arabic
label draft). Hard refresh after applying.

| Your request | Now |
|---|---|
| "on cart and checkout mobile pages ... whatsapp floating to move to the left side ... stiky type vertical bar ... 24/7 Support + whatsapp animated icon ... size dragger ... gradient changing, light colors ... text black and icon green" | A 26px tab on the left edge, vertically centred, green pulsing icon, "24/7 Support" in black on a slowly drifting light gradient. The page moves aside so nothing is covered. Appearance → WhatsApp button → Cart & checkout · phone (on/off, size 22–44px with live preview, position, label, Blush/Sky/Lilac/Lemon or your own two light colours, animation). Laptop and every other page unchanged |

Files: see the package's update.json.

## 2.60.388
**WebP on every upload; Super Sale in the old site's order; page header
controls with front-end editing; homepage brand images.** Apply after .387.
Runs its migrations. Hard refresh after applying.

| Your request | Now |
|---|---|
| "it's not converting auto ... still jpg" / "from anywhere i upload any image, it should be must converted to webp" | Every upload (Media page, brands, categories, banners, products) ends as WebP and is listed under its .webp name; each upload says "Saved as WebP · 113 KB → 41 KB" or exactly why not. Captions of pictures already converted are corrected |
| "same squence and sorting of products as in original site https://kbeautybliss.com/super-sale/" | Pages → Page banners → Super Sale products → "Copy the order from kbeautybliss.com/super-sale/" (or `php artisan kbb:super-sale-order`). Only /super-sale/ changes |
| "full control of super sale page header ... hide title ... image ... hide the all products button, count ... edit the inner page header from the front-end" | Pages → Page header (all custom pages or one page, laptop and phone apart), and an "Edit header" button on the page itself. Super Sale ships without the dot, count and All products button |
| "include in the strip for desktop only 'Free skincare consultation'" | Added, laptop only; every strip item has "Shows on" (Pages → Page banners → Banners) |
| "upload custom image for each brand ... mobile ... logo + image or only image or only text. keep by default only text ... desktop image + name, no logo" | Appearance → Homepage content → Top brands → Edit content → Brands tab (an image per brand) and Layout tab (Look · laptop, Look · phone) |

Files: see the package's update.json.

## 2.60.387
**Brand banner covers the phone header; automatic WebP on upload and in
bulk.** Apply after .386. Runs the WebP migrations. Hard refresh after applying.

| Your request | Now |
|---|---|
| "in mobile brand page ... the background image should cover the whole header area, instead of repeating" | The brand banner fills the phone header edge to edge (346×190 at 390, was an 85px strip with a blurred copy around it). Appearance → Site layout → Brand page → Picture covers the header on phones (on). Categories unchanged |
| "whenever we upload jpg or png images ... conver auto into webp" | Uploads in the Media Library become WebP when smaller (upright, transparency kept, location data stripped, max 2400px wide, oversized files refused). Content → Media Library → WebP images |
| "look for jpg ... convert auto in bulk, and also replace them where the images are actually used" | Same screen, or over SSH: `php artisan kbb:webp --plan -v`, then `php artisan kbb:webp`. Every use is repointed; orders, customers and reviews never touched. Originals kept until "Remove originals" (`--remove-originals`); Undo (`--restore`) until then |

Files: see the package's update.json.

## 2.60.386
**K-Beauty Bliss everywhere + brand search; Fonts & size on every homepage
section; product description columns and videos; Super Sale campaign page and
custom page banners.** Apply after .385. Runs 6 migrations (the brand rename,
5 cache clears). Hard refresh after applying.

| Your request | Now |
|---|---|
| "replace the word Extra Beauty > K-Beauty Bliss everywhere" | Stored settings, page/menu/email/marketing text and SEO boxes rewritten (orders, customers, reviews, product names and legal entity names untouched). Store → SEO Keywords → Brand name shows a dry-run and a Replace button for anything typed later |
| "fight for this keyword … kbeauty / k beauty / k-beauty" | Home title "K-Beauty Bliss — Korean Skincare & K-Beauty Store in UAE", brand JSON-LD with alternate spellings, one natural k-beauty phrase per page in SEO Keywords (after one Sync). Switches on Store → SEO Keywords → Brand name |
| "another tab on every edit popup … Fonts & Size" | Appearance → Homepage content → a section → Edit content → Fonts & size: heading font, letter case, sizes and spacing per device, live sample. Site-wide body/heading font at Appearance → Site layout → Fonts. 29 self-hosted fonts; a page loads only the ones it uses |
| "the html blocks … headings … should come in line … 3 columns in mobile" | Ingredient headings on one line (14px laptop, 13.6px phone); three columns stay side by side on a phone (block 303px tall, was 1300). Switch: Store → Modules → This app only → HTML Block columns on phones |
| "custom section of 2 videos … not showing at all" | Video addresses in a description play as players, side by side, no autoplay. Switch: Store → Modules → This app only → Videos in product descriptions |
| "/super-sale … same products sorting … ready this category 'Super Sale'" | /super-sale/ lists the Super Sale category in its arranged order (Catalog → Catalog → Reorder). Source: Pages → Page banners → Super Sale products |
| "custom banner including image and thin strip … for custom pages" | Pages → Page banners → Banners (desktop/phone picture, link, strip items, colours, sizes) and → Where they show. /super-sale/ ships with the strip; upload the picture to show it |

After applying: Store → SEO Keywords → Sync → Run once. Before the domain
cutover, over SSH: `php artisan kbb:fetch-description-videos --plan`, then
without `--plan`.

Files: see the package's update.json.

## 2.60.385
**Centred homepage headings without the dot; buttons on #KBeautyBliss Spotted
and Under AED 54.** Apply after .384. Runs 1 migration (sets the Under AED 54
button on a shop that saved the old empty default, then clears caches). Hard
refresh after applying.

| Your request | Now |
|---|---|
| "remove this dot, due to this heading is not center aligned in mobile" | No dot beside the centred headings (Big savings bundles, Spotted); they sit exactly on the centre line (0px off at 390 and 1280). Left-aligned headings keep theirs |
| "i need the button also on this section" (Spotted) | "See every #KBeautyBliss look" right of the heading on a laptop, under the pictures on a phone. Appearance → #KBeautyBliss Spotted → Button tab → Button place · laptop |
| "on this section too on homepage" (Under AED 54) | "Shop all under AED 54" → /everything-under-54-aed, right of the centred heading on a laptop, under the products on a phone. Appearance → Homepage content → Under AED 54 → Button text / Button link (empty text: no button) |

Files: see the package's update.json.

## 2.60.384
**Closing a Homepage content editor no longer leaves the page dimmed.** Apply
after .383. Runs 1 migration (a cache clear).

| Your request | Now |
|---|---|
| "upon closing the popup without clicking on save button ... the page still fade and i can't do anything further except refresh" | The backdrop disappears on ×, Close, Esc and a click outside; the page is usable straight away |

Files (2): see the package's update.json.

## 2.60.383
**No giant search icon on a hard refresh of the admin.** Apply after .382.
Runs 1 migration (a cache clear).

| Your request | Now |
|---|---|
| "on hard refresh the admin panel, a giant search icon appears and instantly fixed" | The sidebar search's styles load before its markup and its icons carry their own size; the icon is 16×16 from the first paint |

Files (2): see the package's update.json.

## 2.60.382
**One page for the whole homepage, product source picker, phone strips, carousel
controls, blog cards without meta, Spotted 6-image grid.** Apply after .381.
Runs 6 migrations (2 grid-section columns, 2 Arabic seeds, 2 cache clears).
Hard refresh after applying.

| Your request | Now |
|---|---|
| "a proper detailed popup with all controls, including data queries to choose brand, category or mixed categories, or manual section with search … every section edit on homepage content … Edit content button" | Appearance → Homepage content → **All sections**: every section in order with Laptop/Phone switches and Edit content (popup on a laptop, full screen on a phone; Content · Products · Layout · Style · Show & frame). Products: Automatic (brands, mixed categories, sort, in stock, how many) or Manual (search, drag to order, up to 24). Same homepage cost with 3 or 40 products |
| "ONLY FOR MOBILE … the top bar strip … and below the main banner … that countries strip … also give controls" | Pink top strip "1-3 Days Delivery all over UAE – Free Delivery over AED 199" (Homepage content → Top strip) and the UAE / Korea strip under the banner (Header → Flag bar), phones only |
| "give this option on any carousel products section" | Cards in view · phone (2.3) / laptop and Arrows · phone / laptop on every carousel section |
| "turn off the 6 min read, tag on blog section on homepage" | Homepage blog cards: Reading time and Category tag off (Homepage content → Blog); /blog/ unchanged |
| "#KBEAUTYBLISS Spotted … 6 static images … I will upload each card image manually" | 3×2 grid with 6 placeholder cards; Appearance → #KBeautyBliss Spotted → Homepage grid: picture, link (default the Spotted page), alt, reorder. The section now honours its Laptop/Phone switches and order |

Files (42): see the package's update.json.

## 2.60.381
**Footer in four pages with previews; sold-out label; compact reviews box;
brand tint; 2.3 cards on the product page; admin search on top.** Apply after
.380. Runs 4 migrations (2 cache clears, 1 Arabic draft, 1 footer setting).
Hard refresh after applying.

| Your request | Now |
|---|---|
| "I want total 4 pages in footer. Desktop, Mobile. Cart-Checkout Footer for Desktop and Mobile … previews on each page" | Appearance → Footer: Site footer · Desktop / · Mobile, Cart & Checkout footer · Desktop / · Mobile — each with only its controls, tagged Desktop + mobile / Desktop only / Phone only, a live preview of the real footer, Save this page, Discard, Reset this page |
| "remove the effect from the very last row of the footer. animation not from the logo" | The bottom bar's moving shine is off (Bottom bar → "Effect on the last row"); the logo and big name keep theirs |
| "the sold out product should have proper sold out label" | "Sold out" pill on the photo + a grey "Sold out" button (still opens the product); in-stock products with options untouched. Appearance → Product styles → Card content → Sold-out label |
| "The review header box i need less heighted, almost less to half" | 156 → 79px on a phone, 156 → 87px on a laptop, same layout. Store → Reviews → Review Settings → Compact summary |
| "The brand name should have light background color like capsule with less border radius" | #FCE8EE tint, 5px corners, 22px tall. Appearance → Product page → Type · Buy column |
| "on product page, i want the same 2.3 cards" | You may also like: 2.3 cards on a phone, arrows off; laptop unchanged. Appearance → Product page → You may also like |
| "the search results on backend coming under the pages" | The admin search results now open above every screen |

Files (35): see the package's update.json.

## 2.60.380
**Prices line up with or without stars; Price and Cut price sizes on the
Product grid page.** Apply after .379. Runs 1 migration (a cache clear).
Hard refresh after applying (the stylesheet changed).

| Your request | Now |
|---|---|
| "i want the price row must be same position, even if reviews are there or not. i need semetry" | An unreviewed card keeps the stars' space, so its price sits exactly where a reviewed card's does — measured 19px apart before in the bundles carousel (every stacking card design, phone and laptop), one line after. Showcase unchanged; nothing reserved when stars are switched off |
| "give control to set the pricing font size, and cut price also. on the same product grid backend setting page … desktop and mobile" | Appearance → Product grid → **Price text size**: Price (phone / desktop — the same setting as Product styles) and Cut price (phone / desktop, new, 12px default, changes nothing until moved), live in the preview |

Files (9): see the package's update.json.

## 2.60.379
**Cart Tracking: a 1px line between columns.** Apply after .378. Runs 1
migration (a cache clear).

| Your request | Now |
|---|---|
| "i need columns line to 1px to differentiate the columns" | Growth & Marketing → Cart Tracking: a 1px light line (#dfe4ef) between every column, header included, from 761px wide up. Phone cards unchanged |

Files (2): see the package's update.json.

## 2.60.378
**Cart Tracking rows are shaded alternately.** Apply after .377. Runs 1
migration (a cache clear).

| Your request | Now |
|---|---|
| "each row should slight background color to differentiate from each other … use alternative light shade colors" | Growth & Marketing → Cart Tracking: every other row is light blue-grey (#eff3fa), hover and selected rows a step darker; on phones the cards alternate the same way |

Files (2): see the package's update.json.

## 2.60.377
**Cart Tracking, Users & Roles with live edit locks, admin menu search, the
floating WhatsApp button, the compact brand header with logo ring, and SEO
Keywords.** Apply after .376. No plugin change. **Runs 16 migrations** (4 new
tables for keywords, 3 for carts and blocks, roles + edit locks, 2 brand
columns, 2 Arabic seeds, 5 cache clears). Hard refresh after applying. The
existing `schedule:run` cron also prunes old cart events nightly.

| Your request | Now |
|---|---|
| Cart tracking: every cart, countries, products, Bot column, block/unblock IP ranges, 7 days / all time, most added / removed, cart history, the user's cart and order | Growth & Marketing → **Cart Tracking**: Carts · Added products · Removed products · Blocked · Settings. Block an IP or its range from any cart; blocked visitors get a polite refusal at cart, checkout and forms (whole shop optional). Admin, payment webhooks and search engines are never blocked. Owner and Store Manager |
| Proper user roles: Store Manager, SEO Manager, Sub Admin, Full Admin, Inventory Manager…, editable, custom roles, per-person changes | Platform → **Users & Roles** → Members · Roles. 8 presets, 17 sections, custom names and per-person grants/revokes. Every existing account keeps exactly today's access (checked rule by rule) |
| "if any user is editing … notify … take control … real time, super light" | Opening a record someone has open shows "Sara is editing this product · since 2 min" with View only / Take over. The other person is told within ~12s, keeps their text, and their save is refused (409) — nothing is overwritten. Beats every 15s only while the tab is visible |
| A search at the top of the admin menu that finds anything and highlights it | Top of the left menu (and the phone drawer), Ctrl/Cmd+K or "/". 1,941 entries — every screen, tab and setting; the target glows for ~1s. No server request while typing |
| Floating WhatsApp button, design G with faces, capsule "Available 24/7", size from 30px, four-side positions, the welcome lines as the message sent to us | Appearance → **WhatsApp button**: Design · Position · Message · Capsule & bubble, live preview. Ships ON (G, 60px, bottom-right; bottom-left on /ar/), lifts above the phone Add-to-cart bar. 3 KB gzipped per page, 0 queries |
| Brand page: logo + name in one row, description below, same on mobile; logo upload; ring in the brand's colour from the logo | Compact header (Appearance → Site layout → Brand page → Brand header style; Classic brings back the old one). Logo field in the brand page's Edit pill; ring colour taken from the logo, override in Catalog → Brands → Edit → Ring colour. Phone hero 258px → 154px |
| SEO module: collect ranked keywords, mix and match per page, hidden keywords everywhere, sync from the backend, Google-friendly | Store → **SEO Keywords**: Overview · Sources · Keyword bank · Pages · Sync (dry run, batches, undo). Four layers per page, one primary per page, in JSON-LD `keywords` and a ≤10-term meta keywords tag — never as hidden body text (Google penalises that). Suggested titles/descriptions apply in one click. Sources: the catalogue, site search, Google suggestions (UAE, en/ar), Search Console (optional). Nothing on the shop changes until the first sync |

Files (107): see the package's update.json.

## 2.60.376
**The menu keeps its text size, every email in Send a test, Tabby / Tamara / card
in the failed and refund emails, the brand page, and the spam-folder check.**
Apply after .375. No plugin change. Runs 2 migrations (11 Arabic drafts, a cache
clear). Hard refresh after applying.

| Your request | Now |
|---|---|
| "give control to set menu font size upon expanding … keep the font size control but the space will auto adjust as per the number of parents items" | New: Appearance → Header → Navigation → **How it fills the row**, shipped at "Spread the items (keep my text size)". Your 12 items stay at Text size (14px); the room goes into the gaps — 10px at 1280, 32px at 1920. "Bigger text" is the 2.60.375 behaviour |
| "i need here all the emails, pending order, and in failed order, the reason … tabby … tamara, Card payment" + "also for refund" | Emails → Sending & delivery → Send a test lists all 22 emails. Payment failed: "Your Tabby / Tamara / card payment did not go through" with the reason for that method. Refund: "back to your Tabby account / Tamara account / the card you paid with". Unknown method: the general wording |
| "for Brand Page, remove the Shop all button, and keep Name, along with description … remove also popular right now, and view all … full results without any pagination" | Name + description (the one typed on the brand page now shows — it was dropped when the brand had no header picture), no Shop all, no Popular right now / View all, every product on one page (to 500). Old ?paged=2 links 301 to the brand page. Switches: Appearance → Site layout → **Brand page** |
| "emails are sending fine. but all are going to spam" | The cause is DNS: kbeautybliss.com has no DKIM and no DMARC (steps sent). In the shop: Emails → Domain check now flags SPF when mail actually leaves through this server (server mail chosen, or Google Workspace chosen but unfinished), and Google is greeted as kbeautybliss.com instead of extrabeauty.ae |

Files (19): see the package's update.json.

## 2.60.375
**The desktop menu fills its row.** Apply after .374. No plugin change. Runs 1
migration (a cache clear). Hard refresh after applying.

| Your request | Now |
|---|---|
| "the top desktop menu items font size should auto adjust if empty area there … enlarge, and if more items, then reduce … only if the menu has at least 9 items. less than that it will display as it is" | With 9 or more top items the row fills edge to edge on one line: your 12 items at 1280 go from 13px with 166px empty to 15.25px with 0 empty; 16.8px at 1440; 18px (the largest) at 1600 and 1920, with the remaining room shared evenly. More items shrink to fit (16 items: 11px, one row). Fewer than 9: unchanged, pixel for pixel. Arabic fills the same way. The Super Sale pill and the ▾ arrows grow with the text; the dropdowns still open whole on screen. No layout shift (CLS 0). Controls: Appearance → Header → Navigation → Fit the menu to the row (on) · Fit it from (9 items) · Smallest text size (10px) · Largest text size (18px) |

Files (9): see the package's update.json.

## 2.60.374
**The email builder, Marketing Emails, the card spacing that now really applies,
and a lighter banner.** Apply after .373. No plugin change. **Runs 14
migrations** (email templates; the marketing tables, ready templates and preset
groups; Arabic drafts; cache clears). Hard refresh after applying.

| Your request | Now |
|---|---|
| "two emails modules … Email (order status etc, NO marketing) … both will have builder and pre-ready templates" | **Emails → Customer emails**: every email (21) with its on/off, subject, last sent/edited, Edit and Send test. **Edit** is the builder: drag the sections (or ↑/↓), hide/show, add text/image/button/coupon/products/divider/spacer, wording in English and العربية, live desktop/phone preview, Reset to default, Send test. Nothing changes in any email until you edit it |
| "Marketing Emails under Growth & Marketing … pre-marketing templates for new arrivals, this week special, best sellers etc … editable easily" | **Growth & Marketing → Marketing Emails**: Campaigns · Templates · Customer groups · Reports. 12 ready templates (New arrivals, This week's special, Best sellers, Under AED 54, Super Sale, Bundles & sets, Brand spotlight, We miss you, Brand fans, Autumn glow, Welcome, Skincare tips) whose products fill themselves when the email is sent; Duplicate to edit. Builder with drag-and-drop blocks, preview, undo, and a size meter. Groups: customers and subscribers separate; spent, orders, emirate, dates, brands bought; live count. Send now or schedule; only Owner and Administrator can send; one-click unsubscribe in every email; reports with clicks, unsubscribes, orders and revenue |
| "proper tabs … for all emails pages" | Every Emails page is in tabs, All mail settings included (Sending method · Mail server · Sender & alerts · Footer · More settings · Status emails · Send a test · Waiting to go out · Sent mail). Settings unchanged |
| "the spacing between the elements inside grid card is absolutely not applying … not padding … only one card preview" | Fixed on every card design: above the price and price → Add to cart are real space now (the pink capsule keeps its size), measured exact at 13/24/20px. Appearance → Product grid previews one card at shop size |
| (speed) | The desktop banner downloads a right-sized picture: homepage desktop 530 → 316 KB, first picture shown 0.70 → 0.53 s. Product photos carry their sizes; cards look identical (pixel-checked) |

**Scheduled campaigns need one cron line** (Cloudways → Application → Cron Job
Management), shown on the Marketing Emails screen: `* * * * * cd
/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app && php artisan
schedule:run >> /dev/null 2>&1`. "Send now" works without it.

Also: a database built from nothing now migrates on MySQL (the media index read
a column before it existed); the live shop was never affected.

Files (94): see the package's update.json.

## 2.60.373
**Footer v2, one heading size on the homepage, About us Read more, phone
carousels that peek.** Apply after .372. No plugin change. Runs 4 migrations
(Arabic drafts, cache clears, and moving saved phone-carousel values to the new
defaults). Hard refresh after applying.

| Your request | Now |
|---|---|
| Phone footer: no top logo; socials, description and support text centred; third column Account | Done, phones only. Account = My account, My orders, Wishlist, Addresses (a guest is sent to sign in and back). The old Discover links moved, not dropped: About us → Help, #KBeautyBliss and Journal → Shop |
| No green; our pink/red/orange/grey only, shading within that range | Strip drifts #E0567B → #C13E63 → #E23A4E → #D9603B (white text ≥ 3.6:1 on every stop); the big name drifts light pink, rose, coral, peach, warm grey. A test refuses any other hue |
| Shiny bar across the bottom, left→right, right→left in Arabic | On the bottom bar by default; can move to the big name or off. CSS only; off for reduced motion |
| Control for the complete footer, desktop and mobile | Appearance → Footer → Site footer · link columns / · colours & effects / · layout desktop / · layout mobile (show/hide 13 parts, alignment, spacing, sizes) |
| Section headings and descriptions the same size, desktop and mobile | Every homepage section: heading 34px laptop / 24px phone, description 16px both (the Best Sellers style you ticked). Appearance → Homepage content → Section headings |
| Remove "8 sets" | Off; switch on Appearance → Homepage content → Big savings bundles |
| About us: faded Read more, desktop and mobile | The product page's own clamp and toggle; full text stays in the page for Google. Appearance → Homepage content → About us |
| Shorter brand boxes on phones | 74 → 54px. Appearance → Homepage content → Brands → Tile height · phone |
| Phone carousels showing 2.2 / 2.3 / 2.5, arrows off on phones | 2.3 cards in view and no arrows by default, for Big savings bundles and #KBeautyBliss Spotted; measured at 360/390/414 |

Also: switched-off homepage sections are no longer built (cold homepage 57 → 33
queries); the "Signature" preset now means your current homepage, so pressing it
can no longer bring the old sections back. Lighthouse: mobile 99 / desktop 100,
CLS 0, before and after.

Files (24): see the package's update.json.

## 2.60.372
**The new homepage, the new footer, #KBeautyBliss Spotted, and every email in the
approved design.** Apply after .371. No plugin change. **Runs 12 migrations**
(new tables for order emails, browser copies and Spotted posts; Arabic drafts;
cache clears). After applying, hard refresh (Cmd/Ctrl + Shift + R).

| Your request | Now |
|---|---|
| Homepage: "only these sections … don't include anything from our existing homepage except banner" | Banner, then Big savings bundles, Best-Selling Korean Skincare in the UAE (4×2 / 2×3), Shop Top Korean Beauty Brands (photo cards, logos on phones, no product count), #KBeautyBliss — Seen on Instagram, Trending K-Beauty This Week (8 / 6), Korean Skincare Tips & Guides (3 / 2), K-Beauty Under AED 54 (10 at 5 per row / 6), the two-column feature (Sunscreens, Best sellers), About K-Beauty Bliss UAE (your four paragraphs). Every old section is switched off, not deleted — Appearance → Homepage. Controls: Appearance → Homepage content → Best Sellers / Brands / Trending / Blog / Under AED 54 / Two-column feature / About us. Product cards are the live card, unchanged. One H1, page width = window at 390 and 1280 |
| Footer: design C, WhatsApp green, "24/7 available", no "return" anywhere, shorter, big name at the bottom | On every page. 457px desktop / 541px phone (497 / 602 with both addresses filled). Appearance → Footer → Site footer · design / help strip / Visit us & name. "Previous" brings the old footer back |
| #KBeautyBliss Spotted: manual Instagram carousel + /kbeautybliss-spotted | Appearance → #KBeautyBliss Spotted: add posts (picture, Instagram link, handle, caption, optional product, optional heart count), tick Homepage and/or Spotted page, drag the order. The homepage section and the page show nothing until a post is ticked; the page is kept out of Google and the sitemap while empty |
| Emails: look A, Outfit, emoji titles, thin trust rows under the total, footer with addresses + terms/privacy, View in browser | Every email (19) now drawn by the approved design; side-by-side with the previews in docs/em-emails/. New menu **Emails**: Overview · Sending & delivery (this server, or Google Workspace) · Design & branding (logo, colours, fonts, footer: Dubai and Korea addresses) · Sent mail · All mail settings |
| Pending orders: "Complete your order" at 30 min and 24 h; no receipt before payment; feedback 3 h after Delivered | On. Only unpaid orders from the last 3 days are reminded (never old ones, never imported or COD orders), at most 10 per run. On-hold email off with a manual send: Orders → an order → Order actions → Send on-hold email. Each email on/off: Store → Modules → Order emails |

Files: 157 (see the package's update.json).

## 2.60.371
**Product grid: space inside each card.** Apply after .370. No plugin change. No
migration.

| Your report | Now |
|---|---|
| "spacing controls for grid card, spacing between image, title, pricing, rating, add to cart … please give me on the grid page setting as marked" | **Appearance → Product grid → Space inside each card**, under the Spacing box you marked: six gaps, each with a Phone and a Desktop slider — Inside the card, Photo → first line, Brand → name (new), Name → stars (new), Above the price, Price → Add to cart. The same settings as Appearance → Product styles → Spacing & type (one setting, shown in both places). The preview on the right follows the sliders. Every product grid on the shop uses them. Measured on the real /shop card after saving: photo → name 16→24px, name → price 38→48px, price → Add to cart 12→20px on a desktop; nothing moves until a slider does; page width = window at 390 and 1280 |

Built from `release/2.60.371` = 2.60.370's tree (`171c960`) plus the card-spacing
commit only. The order emails merged on the main branch (Lane RL) are **held**:
the real emails do not yet match the approved previews (9d6dea4), and they ship
with the emails lane's kit port.

Files (2): app/Services/ProductStyles.php, resources/views/admin/app.blade.php.

## 2.60.370
**Homepage: Big savings bundles as a carousel.** Apply after .369. No plugin
change. Runs one migration (a cache clear).

| Your report | Now |
|---|---|
| "i need the bundle section to be carousel with proper beautiful arrows, give controls of everything for desktop mobile both. center the heading, redesign the All Sets button beautifully" | The row is now a swipeable carousel with two round arrows set at the middle of the photos, a centred heading and subtitle, and an All sets pill button (pink, with a round arrow) on the right of the heading on desktop. Measured: 4 cards per view at 1280 (289px each), 1440 (328px) and 1000 (222px); 2 per view at 390 (175px); one arrow press moves one full view. The page is never wider than the window (1000/1280/1440/390 all equal). The product cards inside are byte-identical |
| "i want this button in mobile at bottom of carsousel, give controls of spacing etc." | On phones the button sits centred under the carousel. Controls at **Appearance → Homepage content → Big savings bundles**: heading, subtitle, button text and link; desktop and mobile separately for layout (carousel/grid), cards per view, arrows on/off, button place (top/bottom/off), side padding, gap under the heading and gap above the button; heading alignment; auto-slide (off/3/5/7/10 s) |

Files (10): app/Http/Controllers/Admin/HomepageApiController.php, app/Services/HomepageContent.php, app/Support/HomeBundles.php, database/migrations/2027_07_24_000300_clear_caches_home_bundles_carousel.php, public/build/assets/kbb-B1Py2VEu.css, public/build/manifest.json, resources/css/kbb/kbb.css, resources/views/admin/partials/homepage-content-screen.blade.php, resources/views/partials/home/grid.blade.php, resources/views/store/home.blade.php.

## 2.60.369
**Desktop: no sideways scroll from the menu bar.** Apply after .368. No plugin
change. No migration.

| Your report | Now |
|---|---|
| "in DESKTOP above 1000px width, there's right side horizontal scroll space coming" | Cause, from your browser console: page 1425 / window 1414, the closed two-column dropdown of the last menu item (Skincare) sticking out past the window. The menu bar now clips sideways at the window's edges, and up to 1599px the last two menus (Brands, Skincare) open leftward from their own edge so they show whole. Measured with a menu of your shape: page = window at 1000, 1100, 1280, 1414, 1440, 1650 and 1920 (it was +247 / +122 / +30 / +12 at 1100–1440). From 1600px up the menus open as before; phones unaffected |

Files (4): public/build/assets/kbb-BHzpBMhn.css, public/build/assets/kbb-LgMeNG0s.css, public/build/manifest.json, resources/css/kbb/kbb.css.

## 2.60.368
**Buy these together: no giant tick on tap, and every tap ticks or unticks.**
Apply after .367. No plugin change. No migration.

| Your report | Now |
|---|---|
| "the buy together selection is giving a weird giant tick icon upon click, and it's not selecting and un-selecing. it works rarely" | The tap feedback dimmed the invisible checkbox over each picture to 72%, so the phone drew its own picture-sized blue tick. Now the phone's own drawing is off, the press can't show it, and every quick tap is a tap (no double-tap zoom). Measured at 390: 0.72 → 0 while pressed; 20 taps, 20 ticks/unticks. The picture and the circle still tick and untick; nothing else changes |

Files (3): public/build/assets/kbb-product-CCjmxexD.css, public/build/manifest.json, resources/css/kbb/kbb-product.css.

## 2.60.367
**Admin: "Make all share pictures now".** Apply after .366. No plugin change.
Runs one migration (a cache clear that rebuilds the route table).

| Your report | Now |
|---|---|
| `kbb:share-images` over SSH: "0 made … 17 already up to date, 770 skipped" (could not write) | Appearance → Product page → Share · Link preview card → "Make all share pictures now". The website itself (which may write to the share-picture folder; the SSH master login may not) makes every product's picture, 25 at a time, and shows "Done: N made, N already up to date, N skipped — N of N". It writes picture files only. Its own permission, shareimages.make |

Files (6): app/Http/Controllers/Admin/ShareImagesApiController.php, app/Support/AdminCapabilities.php, database/migrations/2027_07_24_000200_clear_caches_share_images_button.php, resources/views/admin/partials/product-trust-share-screen.blade.php, routes/share-images-admin.php, routes/web.php.

## 2.60.366
**Product page: Buy these together can sit in the right column on laptops.**
Apply after .365. No plugin change. Runs one migration (a cache clear).

| Your report | Now |
|---|---|
| "ONLY IN DESKTOP: allow me option to bring the buy together section to the right collumn, by drag n drop." | Appearance → Product page → Desktop sections: drag "Buy these together" into the Buy column list at any position (or "Move to right column" / "Move below the columns"); arrows and arrow keys too. Compact 4-across in the column, measured 1000–1920 with no sideways scroll. Gap above it: Layout → Spacing · Buy column → "Space above Buy these together · right column" (20px). Phones unchanged (0 differing pixels at 390). Nothing moves until you drag it |

Files (9): app/Http/Controllers/Admin/ProductPageApiController.php, app/Services/ProductDesktopSections.php, app/Services/ProductLayout.php, database/migrations/2027_07_24_000100_clear_caches_buy_together_right_column.php, public/build/assets/kbb-product-0n-Q6kzh.css, public/build/manifest.json, resources/css/kbb/kbb-product.css, resources/views/admin/partials/product-desktop-sections-screen.blade.php, resources/views/store/product.blade.php.

## 2.60.365
**Share: the link preview card, template C, with every part of it in the
admin.** Apply after .364. No plugin change. Runs two migrations (Arabic drafts
for the card's wording, a cache clear).

| Your report | Now |
|---|---|
| "icon point is fine. but give options to chooose from backend and control everything." | A shared product shows the picture, the product name, "🚚 Express delivery all over UAE & Gulf · ✅ 100% original products from the brand · 💳 Accepts Tabby & Tamara" and extrabeauty.ae; the message reads "See what I’ve found on K-Beauty Bliss 💖" then the link. Appearance → Product page → Share · Link preview card: on/off, card title (name / name – price / name \| shop), point style (icons / ticks / none), each point's on/off, icon and text, the separator, the domain at the end, the message and which tiles send it, and the picture shape (wide / square) |

Product pages only: og:/twitter: title and description. The page <title> and
Google's description are unchanged. The picture stays wide (1200×630) until
Square is picked; after picking it, run `php artisan kbb:share-images` once.

Files (11): app/Http/Controllers/Store/ProductController.php, app/Services/ProductTrustShare.php, app/Services/Translation/ArabicInterfaceDrafts.php, app/Services/Translation/InterfaceStrings.php, app/Support/ProductShare.php, app/Support/Seo.php, app/Support/ShareCard.php, app/Support/ShareImage.php, database/migrations/2027_07_23_000200_seed_share_card_arabic_drafts.php, database/migrations/2027_07_23_000300_clear_caches_share_card.php, resources/views/admin/partials/product-trust-share-screen.blade.php.

## 2.60.364
**Product page: no blank gap from missing description pictures, the rating
bar hidden by default, and the picture on a first WhatsApp share.** Apply after
.363. No plugin change. Runs one migration (a cache clear). After applying, run
`php artisan kbb:share-images` once to make every product's share picture.

| Your report | Now |
|---|---|
| "i have zero space under the tabs, but still i'm getting giant space in desktop" | A description picture of ours whose file is not on the server (imported to /wp-content/uploads but never copied) is left out of the page instead of holding a blank box. Measured 439px → 2px above "Benefits:" at 1280 and 390. Copying wp-content/uploads across brings those pictures back |
| "give option to hide unhide the rating bar line, keep off by default in desktop and mobile both" | Appearance → Product page → Mobile sections → Price row → "Show the bar after the rating · phone" / "· laptop", both OFF |
| "when share the product, it picks short description and title. but no image" | WhatsApp's own first fetch of a product link now gets the JPEG share picture (it was given the WebP original, which WhatsApp drops) |

Files (9): app/Http/Controllers/Store/ProductController.php, app/Services/ProductMobileSections.php, app/Support/RichText.php, app/Support/ShareImage.php, database/migrations/2027_07_23_000100_clear_caches_product_page_364.php, public/build/assets/kbb-product-Dqsh9uUA.css, public/build/manifest.json, resources/css/kbb/kbb-product.css, resources/views/admin/partials/product-mobile-sections-screen.blade.php.

## 2.60.363
**Product page: Desktop switches that work, Tabby & Tamara on laptops, the buy
column in your own order on laptops, and the details block's headings and
spacing.** Apply after .362. No plugin change. Runs two migrations (the tab gap
per device, a cache clear).

| Your report | Now |
|---|---|
| "there is control but i turned it off, still it's showing bundle section on desktop" | Sections → Options / bundles → Desktop off hides them at every laptop width (881–1608px measured 0px); it had never reached a simple product's bundles |
| "i want tabby tamara section in desktop also with controls" | On laptops under the short description, ON as asked; Desktop sections → "Tabby & Tamara", and its laptop spacing |
| "controls for changing positions of the sections on desktop too" | Desktop sections → Buy column: drag the 11 buy-column blocks; gaps follow your spacing sliders |
| "need spacing beteen the tab heading and content ... hide ... THE DETAILS and section heading ... keep hide by default" | Separate phone and laptop "Space under the detail tab row" (lifted to at least 18px); the headings hidden by default with phone/laptop switches; leading blank lines in a tab body skipped |
| "give control to hide unhide any section on desktop too" | Every Desktop sections row has an on/off, the same setting as the Sections tab where both exist |

## 2.60.362
**Buy these together: the total and the buy-together discount are OFF until
you switch them on.** Apply after .361. No plugin change. Runs one migration (a
cache clear).

| Your request | Now |
|---|---|
| "buy together pricing and discount row, i want to hide on desktop and mobile both by default, if hide, then no any discount will be picked ... lock that discount section" | "Show the total and buy-together discount", OFF: no row on any device, no bundle discount in cart, checkout, order, email, invoice or Tabby/Tamara; the tiers, coupon switch and example are locked (your values kept). ON: as before, with "Show on phones" / "Show on laptops" to hide the row on one device |

Admin: Appearance → Product page → Buy these together → Discount for buying
together. The row also needs "Show the total above the button" (first card) on.

Files (8): app/Services/BuyTogetherPricing.php, app/Services/BuyTogetherSettings.php,
database/migrations/2027_07_22_000500_clear_caches_buy_together_discount_switch.php,
resources/css/kbb/kbb-product.css,
resources/views/admin/partials/product-buy-together-screen.blade.php,
resources/views/partials/fbt.blade.php, public/build (manifest + product css).

## 2.60.361
**Buy these together: "You're saving" counts the buy-together discount only,
on one row with the total.** Apply after .360. No plugin change. Runs one
migration (a cache clear).

| Your report | Now |
|---|---|
| "the you're saving calculate only the discounted price which is set for this buy together section only!" | The saving is this section's discount alone (it also counted each product's own sale before); the struck total is the products' own prices, so struck − total = saving. With no tier set: no struck total, no pill |
| "the discount and price line should come in one row" | The saving pill and the total share one row: measured at 320, 360, 390, 1280 and 1440 |

Files (7): resources/views/partials/fbt.blade.php, resources/js/kbb/fbt.js,
resources/css/kbb/kbb-product.css,
database/migrations/2027_07_18_000980_clear_caches_buy_together_saving.php,
public/build (manifest, app bundle, product css).

## 2.60.360
**Buy these together: four on a phone with a half-card hint, prices inside
their boxes, the total and "You're saving" line, discounts for 3 / 4 / 5+ and
coupons on top.** Apply after .359. No plugin change. Runs three migrations
(the bundle columns on carts and orders, the Arabic wording, a cache clear).

| Your request | Now |
|---|---|
| "the text is going out the boxes ... prices inside the box must be auto adjusted" | Price scaled to its card, struck price wraps under it; no overflow 320-1440px |
| "4 products on the screen, and 5th one can be hidden ... slightly animate and display the half of the 5th" | A swipeable row; one gentle peek at half the 5th when it scrolls into view (none under reduced motion, Arabic mirrored) |
| "the button has little light bottom shadow" | Soft pink shadow under the outlined button |
| "Total: should be on right side ... cut price ... you're saving 'AED amount'" | Total on the right with the struck total and a green "You're saving AED x" pill, live |
| "discount upon 5 products purchse, 4 products and 3 ... if any product removed from the cart, the other products prices will become normal" | Tiers 0-50% (ship at 0 -- set yours); priced on the server in cart, drawer, checkout, order; any removal ends the group's discount |
| "the coupon can be apply ... option to include exclude the coupon" | Coupon after the bundle discount; "Coupons also apply to buy-together products" (on) |

Admin: Appearance → Product page → Buy these together → Discount for buying
together. The admin order screen shows the bundle discount and the coupon as
two rows.

## 2.60.359
**Desktop sections: put the product page's blocks in your own order on
laptops.** Apply after .358. No plugin change. Runs one migration (a cache
clear).

| Your request | Now |
|---|---|
| "give option to change position in desktop also fo the sections" | Appearance → Product page → Desktop sections: drag (or ↑/↓) Buy these together, Product details, Reviews and You may also like into any order on laptops; the laptop preview follows as you drag. The picture and buy column stay at the top. Nothing moves until you drag (measured identical at 1000/1280/1440); phones keep their own Mobile sections order |

Files (9): app/Http/Controllers/Admin/ProductPageApiController.php,
app/Services/ProductDesktopSections.php,
database/migrations/2027_07_20_000100_clear_caches_product_desktop_sections.php,
resources/css/kbb/kbb-product.css,
resources/views/admin/partials/product-desktop-sections-screen.blade.php,
resources/views/admin/partials/product-mobile-sections-screen.blade.php,
resources/views/store/product.blade.php, public/build (manifest + product css).

## 2.60.358
**Sort works; the product count beside Filters is hidden; the whole category
banner on phones.** Apply after .357. No plugin change. Runs one migration (a
cache clear).

| Your report | Now |
|---|---|
| "the selection of low to high etc, is absolutely not working" | The dropdown's script crashed on every choice ("URL is not a constructor"); it now reloads in the chosen order. The server's sorting was always right |
| "turn hide by default the products count beside the filter button" | Hidden. Appearance → Site layout → Product grid → "Show the product count beside Filters" brings it back |
| "the background banner image in mobile should display full, not any cut from left or right" | On phones the header takes the picture's own shape: 46% of a 4:1 banner's width shown before at 390, 100% now, words still on it; extra room is the same picture blurred. Appearance → Site layout → Category header → "Show the whole picture on phones" (on). The description also wraps inside the box on phones |

Files (9): app/Services/SiteLayout.php, app/Support/TitleHeader.php,
database/migrations/2027_07_18_000950_clear_caches_listing_fixes.php,
resources/css/kbb/kbb-title-header.css,
resources/views/admin/partials/site-layout-screen.blade.php,
resources/views/components/kbb-title-header.blade.php,
resources/views/store/shop.blade.php, public/build (manifest + title-header css).

## 2.60.357
**C · Ripple on every button and icon (no more grey tap box), and every tab of
Appearance → Product page in view.** Apply after .356. No plugin change. Runs
one migration (a cache clear).

| Your report | Now |
|---|---|
| "when u click on any button or icon. it leaves gray square / rectangle box ... set C · Ripple by default, and give other as options" | No grey box; a pink wave spreads from the middle of whatever is pressed (white on pink buttons). Off / A / B / C / D / E in Appearance → Site layout → Press feedback, with a Try it box. Nothing moves at rest: 28 controls measured identical |
| "i can not see any tab of this name at the top of the Appearance → Product page → Buy these together" | The tab was 13th in a row that scrolled sideways with no scrollbar; the row now wraps, so all 13 tabs are in view |

Files (11): app/Services/SiteLayout.php, resources/css/kbb/kbb.css,
resources/js/kbb/app.js, resources/js/kbb/press.js,
resources/views/admin/app.blade.php,
resources/views/admin/partials/site-layout-screen.blade.php,
resources/views/layouts/store.blade.php,
database/migrations/2027_07_18_000900_clear_caches_press_feedback.php,
public/build (manifest, app bundle, kbb.css bundle).

## 2.60.356
**"Buy these together" on the product page, switched on.** Apply after .355.
No plugin change. Runs four migrations (the product-views table, the switch,
the Arabic wording, a cache clear).

| Your request | Now |
|---|---|
| "one new section called Buy these together ... same design ... as on the cart page Recommended for you" | The cart rail's own card, same size (measured 71.88px at 390, 116.08px at 1280), with "+" between |
| "empty circle at the corner ... by default checked (filled green) with check ... user can un-check" | Green tick circle on every product, ticked by default; tap to clear |
| "Buy 4 items together ... number will count as per the user selection ... all those will add to the cart" | The outlined pink button counts what is ticked and adds them all in one go; the cart panel opens |
| "first product will be the same as on the product page ... sunscreen -> moisturizer, toners, cleansing oils, face masks ... random, or best seller or best visits" | First is the product itself; then one from each paired category; Best sellers / Random / Most viewed / Newest / Top rated |
| "the product image will also work same as the check circle ... product title will go to the product page" | Tapping the picture ticks and unticks it; the name is the link |

Admin: Appearance → Product page → Buy these together (on/off, phones,
laptops, how many, how chosen, sold-out, same brand, total, heading, category
pairs). Store → Modules now points there.

Files (31): see the commit; the section's services (BuyTogether,
BuyTogetherPairs, BuyTogetherSettings, ProductViews), CartController,
ProductController, ProductViewController, ProductPageApiController,
EcommerceApiController, Category, Product, ModuleRegistry,
ProductMobileSections, ProductSections, InterfaceStrings, ArabicInterfaceDrafts,
four migrations, kbb-product.css, cart.js, fbt.js, admin/app.blade.php, the
admin tab, partials/fbt.blade.php, routes/buy-together.php, routes/web.php,
public/build.

## 2.60.355
**Load more on slow internet with no grey cards; the homepage banner flush
under the header, never cut, with a height control and a Single image type.**
Apply after .354. No plugin change. Runs two migrations (three banner columns,
then a cache clear).

| Your report | Now |
|---|---|
| "on slow internet it keeps displaying the grey loading stuff ... pre load upon page open" | The next products are fetched quietly as soon as the page has loaded, and their pictures warmed, so reaching the end shows them at once. Slow 3G: grey cards ~0.7 s -> none; next 12 in 782-849 ms -> 30-116 ms |
| "remove any space between header and banner" | The banner's 8px top padding is gone: header and banner meet at 0px, phone and laptop |
| "image should adjust auto with the screen without cutting" | Pictures are shown whole at their own shape by default (Auto); Fill the frame is still a choice |
| "for slider images, there should be height control of the overall banner" | Banner height on a computer / on a phone: a maximum; at it the picture is shown whole and centred |
| "i need here option single image ... the height will be as per the image height itself" | New type: Single image -- one picture, full width, its own height, its own phone picture |

Admin: Appearance → Banners → (a set) → Banner type → What this set is, and →
Size & fit. A "New single image" button on the Banners list.

Files (13): app/Http/Controllers/Admin/BannerApiController.php,
app/Models/BannerCard.php, app/Models/BannerSet.php, app/Services/Banners.php,
database/migrations/2027_07_18_000300_banner_single_image_and_slider_height.php,
database/migrations/2027_07_18_000310_clear_caches_banner_single_image.php,
resources/js/kbb/listing-load.js,
resources/views/admin/partials/banners-screen.blade.php,
resources/views/partials/home/single-banner.blade.php,
resources/views/partials/home/slider-banner.blade.php,
resources/views/store/home.blade.php, public/build (manifest + app bundle).

## 2.60.354
**Quick view OFF on every page; the admin top bar and the ✎ Edit pencil on
category and brand pages (you only).** Apply after .353. No plugin change.
Runs three migrations: one new brand column, the Quick view switch, and two
cache clears.

| Your report | Now |
|---|---|
| "turn off the quick view option by default ... by default off this function everywhere on the site-frontend. on every page" | OFF on every page, in the code itself and on your shop's stored switch. Store → Modules → Quick view brings it back, and when it is on it now sits in the middle of the picture (it was at the top, half cut off, on 29 of 33 card designs) |
| "site thin top bar, will show only for administrators ... administrator name, along with logout button ... hide / unhide arrow" | A 32px dark bar on every shop page, only for you: Edit this category/brand/product, Dashboard, Orders (today's count), Products, Customers, Appearance, Clear cache; your name, Log out, and an arrow that tucks it away (remembered). Shoppers' pages are byte-for-byte unchanged |
| "pencil icon + edit minimal button ... beautiful popup ... drag n drop image upload ... save ... update the page without being refreshed and close the popup auto" | ✎ Edit on every category and brand header: banner drag-and-drop with progress, title, line under it, description, phone crop, live preview; Save updates the header in place and closes. Owner only for now -- the capability is ready for the user-roles module |

Files (28): app/Http/Controllers/Admin/AdminAuthController.php,
app/Http/Controllers/Admin/CategoriesApiController.php,
app/Http/Controllers/Admin/PageController.php,
app/Http/Controllers/Admin/StorefrontAdminController.php,
app/Http/Controllers/Store/QuickViewController.php,
app/Http/Controllers/Store/ShopController.php, app/Services/ModuleRegistry.php,
app/Support/AdminCapabilities.php, app/Support/StorefrontAdminHint.php,
app/Support/TitleHeaderInput.php, three migrations
(2027_07_16_000000_add_header_description_to_brands,
2027_07_16_000200_clear_caches_storefront_admin_layer,
2027_07_17_000100_quick_view_off), resources/css/kbb/kbb.css,
resources/js/kbb/admin-hint.js, resources/js/kbb/admin/storefront-admin.{js,css},
resources/js/kbb/app.js, resources/views/admin/app.blade.php,
resources/views/admin/partials/storefront-handoff.blade.php,
resources/views/components/product-card.blade.php,
resources/views/layouts/store.blade.php, routes/storefront-admin.php,
routes/web.php, public/build (manifest + 3 built files).

## 2.60.353
**A different light box on every visit for categories with no banner, and a
shorter share sheet: picture and title in one row.** Apply after .352. No plugin
change. Runs one migration (switches the random light box on, then clears the
caches).

| Your report | Now |
|---|---|
| "options to use random layout on random categories, where we didin't upload the background image yet. so on each page load, it will give random colored background" | ON. A category or brand page with no banner of its own and no box chosen for it gets a light box picked at random on every load, from the colours ticked in Appearance → Site layout → Category header → "A different light box on every visit". A category you gave a box or a banner keeps it |
| "The share popup is too heighted. i want to have picture + title in same row" | The product card is one row: a 96px square of the product's photograph, then the name (three lines at most) and the price, struck price included when on sale. Card 248px → 118px; the sheet 510px → 381px tall on a 390px phone |

Files (8): app/Services/SiteLayout.php, app/Support/TitleHeader.php,
database/migrations/2027_07_16_000100_random_light_box_on.php,
resources/views/admin/partials/site-layout-screen.blade.php,
resources/views/partials/product/share-sheet.blade.php,
resources/css/kbb/kbb-pdp-trust.css, public/build/manifest.json,
public/build/assets/kbb-pdp-trust-Dax_IKaE.css.

## 2.60.352
**Hotfix: on phones, a product with many gallery pictures made the page
scroll sideways; and in Safari the category header's description was cut off
on one line.** Apply after .351. No plugin change.

| Your report | Now |
|---|---|
| "the product image and gallery going outside the screen and creating a giant weird side spacing" (phones) | The gallery is exactly the screen's width again; its thumbnails scroll in their own row. Measured with ten pictures at 360, 390, 430 and 768 px: no sideways scroll. Laptop unchanged |
| "Find your favorite products in our wide range Moisturizers cat" (Safari) | The description is cut at its line count with a method every browser measures the same way |

Files (2): resources/css/kbb/kbb-product.css, resources/css/kbb/kbb-title-header.css.

## 2.60.351
**Category header designs as clickable pictures with a live preview, set
separately for phone and laptop, fine-tuned after choosing, and per category
with an Upload banner button.** Apply after .350. No plugin change. Nothing on
the shop moves until you choose something.

| # | Your report | Now | Where |
|---|---|---|---|
| 49 | "i can not see any designs on backend" — the option sheet's designs to choose from, with a live preview, for mobile too, editable after choosing | Tiles A–F, 1–5 (on a picture and on the box), where the words sit, alignment, text colour; Phone / Laptop switch; live preview with "Preview with" any of your categories; fine-tuning per design with "Back to defaults" | Appearance → Site layout → Category header; Category header · sizes & spacing |
| 49 | Upload banners per category | Live preview of the category; Upload banner; recommended 2400×600; phone crop left / centre / right; the category's own design per device | Catalog → Categories → Edit → Category header |

Files (10): app/Http/Controllers/Admin/CategoriesApiController.php, app/Services/SiteLayout.php, app/Support/TitleHeader.php, app/Support/TitleHeaderSplitCss.php, database/migrations/2027_07_15_001000_clear_caches_category_header_devices.php, resources/css/kbb/kbb-title-header.css, resources/views/admin/partials/category-tree-screen.blade.php, resources/views/admin/partials/media-picker.blade.php, resources/views/admin/partials/site-layout-screen.blade.php, resources/views/admin/partials/title-header-kit.blade.php.

## 2.60.350
**Category headers say the category's name (no more "hide"); the phone
product page as sections you order and switch; the price row under the title
on desktop; Tabby & Tamara cards; and an Amazon-style share icon whose links
carry the picture.** Apply after .349. No plugin change.
**After applying, run once:** `php artisan kbb:share-images` (makes the share
pictures for every product now instead of on each product's first view).

| # | Your report | Now | Where |
|---|---|---|---|
| 44 | Every category header read "hide"; wanted the name, 2 lines of description, or "Find your favorite products in our wide range <category> category.", bottom-left | The imported "hide" is cleared and never imported again; name + 2 lines; the generic line when there is no description; words at the bottom; the header is exactly the height you set (a stray 52px top and bottom is gone) | Appearance → Site layout → Category header / Category header · sizes & spacing |
| 46 | Phone product page as sections, drag & drop order, on/off, spacing; price row with rating (no count); Tabby & Tamara; Buy together off; "100% authentic" rows and "THE DETAILS" gone on phones. Desktop: price under the title, rating beside it | All of it, in your order, 18px even spacing with a slider and per-section overrides | Appearance → Product page → Mobile sections |
| 47 | Share row removed; share icon beside the title; Amazon-style panel with the picture, title and apps; shares must carry the picture | Done; link previews now use a JPEG copy of the product picture that WhatsApp accepts; "More" sends the picture itself on phones | Appearance → Product page → Share |

Files (38): app/Console/Commands/MakeShareImages.php, app/Http/Controllers/Admin/MediaLibraryApiController.php, app/Http/Controllers/Admin/ProductPageApiController.php, app/Http/Controllers/Store/ProductController.php, app/Services/ProductLayout.php, app/Services/ProductMobileSections.php, app/Services/ProductSections.php, app/Services/ProductTrustShare.php, app/Services/SiteLayout.php, app/Services/Translation/ArabicInterfaceDrafts.php, app/Services/Translation/InterfaceStrings.php, app/Support/ImageVariants.php, app/Support/PaymentMarkArt.php, app/Support/ProductShare.php, app/Support/Seo.php, app/Support/ShareImage.php, app/Support/TitleHeader.php, app/Support/TrustShareIcons.php, database/migrations/2027_07_15_000000_clear_caches_product_mobile_sections.php, database/migrations/2027_07_15_000100_seed_product_mobile_sections_arabic_drafts.php, database/migrations/2027_07_15_000500_ship_share_sheet_platform_set.php, database/migrations/2027_07_15_000600_seed_product_share_sheet_arabic_drafts.php, database/migrations/2027_07_15_000700_clear_caches_product_share_sheet.php, database/migrations/2027_07_15_002000_category_header_name_and_generic_line.php, resources/css/kbb/kbb-pdp-trust.css, resources/css/kbb/kbb-product.css, resources/css/kbb/kbb-title-header.css, resources/js/kbb/pdp-trust.js, resources/views/admin/app.blade.php, resources/views/admin/partials/product-mobile-sections-screen.blade.php, resources/views/admin/partials/product-trust-share-screen.blade.php, resources/views/components/kbb-title-header.blade.php, resources/views/partials/product/paylater.blade.php, resources/views/partials/product/share-bar.blade.php, resources/views/partials/product/share-button.blade.php, resources/views/partials/product/share-sheet.blade.php, resources/views/partials/product/trust-share-stack.blade.php, resources/views/store/product.blade.php.

## 2.60.349
**Category header: the category name as title unless you set your own,
left-aligned (right in Arabic), a light icon box for categories with no
picture, and every design choice in the admin.** Apply after .348. No plugin
change.

| # | Your report | Now | Where |
|---|---|---|---|
| 44 | Title from the category name unless a custom title / description is entered; centre / left options with left the default (right in Arabic); a light box with beauty-product icons when there is no picture; per-category picture, title size, description; section height and padding; a shadow so the title never merges into the picture; multiple options | All of it. Box styles A–F, readability options 1–5 (shipped: A and 1 Soft shadow), alignment Start / Centre / End, sizes and spacing for phone and laptop, live preview | Appearance → Site layout → Category header, and Category header · sizes & spacing; per category: Catalog → Categories → Edit → Category header |

Brand pages, /shop/, search and ?filter_brands= are unchanged.

Files (12): app/Http/Controllers/Admin/CategoriesApiController.php, app/Http/Controllers/Store/ShopController.php, app/Models/Category.php, app/Services/SiteLayout.php, app/Support/TitleHeader.php, database/migrations/2027_07_14_000100_add_header_style_to_categories.php, database/migrations/2027_07_14_000200_clear_caches_category_header_options.php, resources/css/kbb/kbb-title-header-icons.svg, resources/css/kbb/kbb-title-header.css, resources/views/admin/partials/category-tree-screen.blade.php, resources/views/admin/partials/site-layout-screen.blade.php, resources/views/components/kbb-title-header.blade.php.

## 2.60.348
**Brand names on cart and checkout rows per device, the Authenticity × on the
box corner, and the share bar as coloured icons on one line.** Apply after
.347. No plugin change.

| # | Your report | Now | Where |
|---|---|---|---|
| 42 | Show / hide brand names on the cart page, desktop and mobile separately — desktop ON, mobile OFF; same on checkout rows | Cart: brand hidden on phones, shown on desktop. Checkout rows (which had no brand line) show it on desktop, not on phones | Appearance → Cart page → Product rows · spacing and size / Product rows · phone; Appearance → Checkout page → Desktop · Product rows / Mobile · Product rows → "Show the brand name" |
| 43 | The × should sit half outside the box on its corner, a plain circle, no ring | A solid red circle with a white ×, centred on the box's top corner | Automatic |
| 45 | Share icons only, no filled circles; SHARE and icons on one line | Coloured icons, no circles; never wraps (icons shrink a little on very narrow phones) | Appearance → Product page → Trust · Share bar → Style |

Files (7): app/Services/CartPage.php, app/Services/CheckoutPage.php, app/Services/ProductTrustShare.php, database/migrations/2027_07_14_000000_clear_caches_cart_checkout_brand.php, resources/css/kbb/kbb-checkout.css, resources/css/kbb/kbb-pdp-trust.css, resources/views/partials/checkout/summary-items.blade.php.

## 2.60.347
**Product page: the FAST DELIVERY box, "Authenticity Guaranteed" that slides
open, and a colourful share bar whose links carry the product's picture and
description.** Apply after .346. No plugin change.

| # | Your report | Now | Where |
|---|---|---|---|
| 41 | Yellow delivery box above Add to cart | "Express 1-3 Days Delivery All over UAE / Free Delivery over AED 199" (the figure is the checkout's own threshold) beside a drawn FAST DELIVERY truck; upload your own picture to replace it | Appearance → Product page → Trust · Delivery box |
| 41 | "Authenticity Guaranteed" with an info icon that slides open, green tick, small red × | Under Add to cart, desktop and phone; Escape closes too | Appearance → Product page → Trust · Authenticity |
| 41 | A nicer, colourful share bar; each link must carry the URL, description, image | WhatsApp, Facebook, X, Pinterest, LinkedIn, Telegram, Email, Copy link (+ the phone's own share sheet). WhatsApp/Telegram send name, price, short description and link; Pinterest the picture; links tagged for analytics (switch) | Appearance → Product page → Trust · Share bar |
| 41 | Spacing above and below these | 12 sliders, phone and laptop | Appearance → Product page → Trust · Spacing |

Also: link previews (Facebook, WhatsApp, LinkedIn) now carry the picture's
size and description, and a product name with "&" no longer shows as
"&amp;amp;" in the browser tab and link previews.

Files (22): app/Http/Controllers/Admin/ProductPageApiController.php, app/Http/Controllers/Store/ProductController.php, app/Services/ProductTrustShare.php, app/Services/Translation/ArabicInterfaceDrafts.php, app/Services/Translation/InterfaceStrings.php, app/Support/ImageVariants.php, app/Support/ProductShare.php, app/Support/Seo.php, app/Support/TrustShareIcons.php, database/migrations/2027_07_13_000000_clear_caches_product_trust_share.php, database/migrations/2027_07_13_000100_seed_product_trust_share_arabic_drafts.php, resources/css/kbb/kbb-pdp-trust.css, resources/js/kbb/pdp-trust.js, resources/views/admin/app.blade.php, resources/views/admin/partials/product-trust-share-screen.blade.php, resources/views/partials/product/authenticity.blade.php, resources/views/partials/product/delivery-box.blade.php, resources/views/partials/product/share-bar.blade.php, resources/views/partials/product/trust-share-assets.blade.php, resources/views/partials/product/trust-share-stack.blade.php, resources/views/store/product.blade.php, vite.config.js.

## 2.60.346
**Orders page you can edit, guest account invites, grids without badges that
load on scroll, "You may also like" as a carousel, the old shop's category
banners, a fixed mobile product page, and an instant search panel.** Apply
after .345. **Plugin: KBB Store Exporter 1.11.0** (category banners) — only
needed when you export again.

| # | Your report | Now | Where |
|---|---|---|---|
| 35 | Order page: address and customer could not be edited; history went to all customers; no clear "paid" box; Capture box; no manual payment | Edit billing / shipping / customer in a box; "Order history" pop-up of that customer's orders; big green "Paid with …" panel; Capture removed; "Mark as paid" with a payment ID | Store → Orders → an order |
| 37 | (found on your imported orders) uncollected COD read "still refundable" | Imported COD orders not yet collected offer nothing to refund | Automatic |
| 27 | Send guests an account invite with an editable email | Tick guests or "Select all" → Send account invite… → edit the email → send. Each gets a one-time "Set your password" link (7 days) | Store → Customers |
| 28 | NEW / discount tags on grid cards | Off on every product card | Appearance → Product styles → Card content |
| 29 | Pagination on categories and brands | Load more on scroll, 12 at a time | Appearance → Site layout → Loading more products |
| 30 | Grid hover on phones | Off on phones and tablets | Appearance → Product styles → Layout |
| 31 | Inner grid spacing and fonts | 21 controls, phone and desktop; nothing moves until you change one | Appearance → Product styles → Spacing & type |
| 32 | "You may also like" as a slider | Carousel of 12: same brand + same category mixed, sold out hidden; manual picks per product | Appearance → Product page → You may also like; Catalog → Products → edit → You may also like |
| 33 | Category banner, title and description from the old site | Drawn as the category page header once you export with plugin 1.11.0 and import Categories + Brands | Appearance → Site layout → Category header |
| 34 | Links in descriptions still going to kbeautybliss.com | Re-pointed to this shop on every import; one press fixes what is already imported | Store → Store Import / Export → Addresses & pictures → Links to the old site |
| 36 | Catalog table needed a horizontal scroll; columns; mobile layout | Fits the screen with View/Edit pinned; "Customize columns"; on phones 2 cards a row, one swiping tool row | Catalog → Products |
| 38 | Trending box under search opened after a delay | Opens on the tap (854 ms → 10 ms measured) | Automatic |
| 39 | /shop/?filter_brands=… read "Shop all" | Headed with the brand's name; generic line removed | Automatic |
| 40 | Mobile product page: short description hidden, thumbnails over the photo, top space, white discount badge | Description shows; thumbnails under the photo; no top gap on phones; green badge; spacing sliders with live preview | Appearance → Product page → Photo & badge, Spacing · Buy column |

Files (91): app/Http/Controllers/Admin/AdminOrderController.php, app/Http/Controllers/Admin/AlsoLikeApiController.php, app/Http/Controllers/Admin/CustomerInvitesController.php, app/Http/Controllers/Admin/CustomersApiController.php, app/Http/Controllers/Admin/OldSiteLinksApiController.php, app/Http/Controllers/Admin/OrderDetailController.php, app/Http/Controllers/Admin/ProductEditorApiController.php, app/Http/Controllers/Admin/ProductPageApiController.php, app/Http/Controllers/Store/AccountInviteController.php, app/Http/Controllers/Store/BrandController.php, app/Http/Controllers/Store/ProductController.php, app/Http/Controllers/Store/ShopController.php, app/Mail/CustomerAccountInvite.php, app/Models/Customer.php, app/Models/Product.php, app/Services/AlsoLikeRail.php, app/Services/AlsoLikeSettings.php, app/Services/CustomerInvites/CustomerInviter.php, app/Services/CustomerInvites/InviteTemplate.php, app/Services/Import/DocumentMediaRewrite.php, app/Services/Import/Entities/BrandImporter.php, app/Services/Import/Entities/CategoryImporter.php, app/Services/Import/Entities/ContentBlockImporter.php, app/Services/Import/Entities/PostImporter.php, app/Services/Import/Entities/ProductImporter.php, app/Services/Import/ImportRunner.php, app/Services/Import/MediaAudit.php, app/Services/Import/MediaRewrite.php, app/Services/Import/OldSiteLinks.php, app/Services/Orders/ManualPayment.php, app/Services/Payments/PaymentRefunder.php, app/Services/ProductLayout.php, app/Services/ProductStyles.php, app/Services/SiteLayout.php, app/Services/Translation/ArabicInterfaceDrafts.php, app/Services/Translation/InterfaceStrings.php, app/Support/AdminCapabilities.php, app/Support/AlsoLikePicks.php, app/Support/ListingBatch.php, app/Support/MediaUsage.php, app/Support/MediaUsageWriter.php, app/Support/OrderAddress.php, app/Support/OrderCustomerHistory.php, app/Support/OrderPaymentPanel.php, app/Support/RichText.php, app/Support/TitleHeader.php, database/migrations/2027_07_11_000000_clear_caches_catalog_table_fit.php, database/migrations/2027_07_11_000200_add_also_like_to_products.php, database/migrations/2027_07_11_000250_seed_also_like_arabic_drafts.php, database/migrations/2027_07_11_000300_clear_caches_also_like.php, database/migrations/2027_07_11_000400_clear_caches_order_detail_payment.php, database/migrations/2027_07_12_000000_add_customer_invites.php, database/migrations/2027_07_12_000000_clear_caches_product_top_controls.php, database/migrations/2027_07_12_000100_add_title_header_to_categories_and_brands.php, database/migrations/2027_07_12_000100_clear_caches_customer_invites.php, database/migrations/2027_07_12_000200_create_old_link_rewrites_table.php, database/migrations/2027_07_12_000200_seed_customer_invite_arabic_drafts.php, database/migrations/2027_07_12_000300_clear_caches_lane_pr_grid_cards.php, database/migrations/2027_07_12_000300_seed_title_header_arabic_drafts.php, database/migrations/2027_07_12_000400_clear_caches_title_header_and_old_links.php, resources/css/kbb/kbb-product.css, resources/css/kbb/kbb-title-header.css, resources/css/kbb/kbb.css, resources/js/kbb/app.js, resources/js/kbb/reviews.js, resources/js/kbb/search.js, resources/js/kbb/ymal.js, resources/views/admin/app.blade.php, resources/views/admin/partials/customer-invites.blade.php, resources/views/admin/partials/product-editor-screen.blade.php, resources/views/admin/partials/unfinished-drafts.blade.php, resources/views/components/kbb-title-header.blade.php, resources/views/components/product-card.blade.php, resources/views/components/product-grid.blade.php, resources/views/emails/customer-invite-text.blade.php, resources/views/emails/customer-invite.blade.php, resources/views/partials/header.blade.php, resources/views/partials/listing-batch.blade.php, resources/views/partials/product-gallery.blade.php, resources/views/partials/shop-appearance-css.blade.php, resources/views/partials/you-may-also-like.blade.php, resources/views/store/account/welcome.blade.php, resources/views/store/brands.blade.php, resources/views/store/product.blade.php, resources/views/store/shop.blade.php, routes/auth-customer.php, routes/customers-admin.php, routes/order-detail-admin.php, routes/product-editor-admin.php, routes/urls-media-admin.php, routes/web.php.

## 2.60.345
**No more "Leave without saving?" — an Unfinished list instead; imports keep
pictures on this server; tab links open the right tab.** Apply after .344.
No plugin change.

| # | Your report | Now | Where |
|---|---|---|---|
| 19 | Leaving a page gave a popup; unsaved work was lost on most screens | Leaving never asks. What you typed is kept, and the top bar shows "Unfinished (N)": Open brings you back with it, Discard asks first. 24 screens and the product editor (you approved it) | Top bar, every admin page |
| 24 | Every picture loaded from kbeautybliss.com | After you pressed "Bring these across" they load from this shop; from now on an import also re-points every picture whose file is already here, so a re-import can no longer send them back | Automatic |
| 23 | (found while building 19) links to a tab, e.g. Catalog → Reorder, opened the first tab | They open the tab they name | — |
| 25 | Review photos showed as broken frames | A photo that does not load is removed from its card, the count follows, and a card left with none leaves "With Photos". Run Pictures & live progress to bring the photos themselves across | Product page reviews |

The "turn this product into a set?" question in the product editor stays: it
is a decision, not a leave warning.

Files: app/Services/Import/ImportRunner.php, resources/js/kbb/reviews.js, public/build/*, resources/views/admin/app.blade.php,
resources/views/admin/partials/{unfinished-drafts,product-editor-screen,reset-guard,banners-screen,grid-sections-screen,set-appearance-screen,cart-panel-screen,cart-page-screen,checkout-page-screen,page-wash-screen,slim-footer-screen,site-layout-screen,security-screen,ugc-appearance-screen,review-settings-screen,review-badges-screen}.blade.php,
database/migrations/2027_07_10_000000_clear_caches_unfinished_drafts.php.

## 2.60.344
**Rey blocks on product pages, search that follows every word, sets first in
search, Growth → Search Terms, the set price button, Visit, and "Are you
sure?" before every reset.** Apply after .343. Ships with **WordPress plugin
1.10.0** (Products must be exported once more).

| # | Your report | Now | Where |
|---|---|---|---|
| 13 | `[rey_global_section id="…"]` printed as text | The block is exported (plugin 1.10.0), imported and drawn in place: heading, then image + title + text items, 3 across on desktop, stacked on phones. Pictures are brought across. A missing block shows nothing | Content → HTML Blocks (edit one, every product using it changes) |
| 14 | Nine Remove buttons on the import screen | One "Remove all files" (uploaded files only, never imported data) | Store → Store Import / Export → 1 · Your exports |
| 16 | "Use this total" did not fill Price and Sale price (it reset the discount to 0) | "Apply to Price and Sale price": Price = bought-separately total, Sale = set price; the shop shows the struck price, the badge and "You save" | Catalog → Product editor → a set → What is in the box |
| 17 | A Visit button | Visit opens the live page; "Not live yet" when it is not published | Product editor top bar; Catalog → Products, beside Edit |
| 18 | Resets happened by mistake | Every Reset / Restore / Revert / Back to defaults asks "Are you sure?" (No is the default) | Whole admin |
| 20 | Search stopped after 2–3 words and showed unrelated products | Every word, any order; nothing unrelated is added | Search box and results page |
| 21 | Sets never in search | A set at #1 for its brand (different one each search, or best seller), or the set you choose per brand | Store → Site Search → Sets in search |
| 22 | See what people search | Ranked terms by day / week / month / year, with change, and what found nothing | Growth & Marketing → Search Terms |

Defaults that moved because you asked: "Sets first" ON. Searches are now
counted only once they settle (no "med" on the way to "medicube"), and a
search that found nothing is counted too.

Files: app/Http/Controllers/Admin/{BlocksApiController,CatalogProductsApiController,ImportApiController,ProductEditorApiController,SearchTermsApiController,SiteSearchApiController}.php,
app/Http/Controllers/Store/{SearchController,ShopController}.php, app/Models/Block.php,
app/Services/{HeaderSettings,SearchInsights,SearchTermsReport}.php,
app/Services/Import/{DocumentMediaRewrite,ElementorToHtml,ImportRunner,MediaAudit}.php,
app/Services/Import/Entities/{ContentBlockImporter,ProductImporter}.php,
app/Services/ImportConsole/ImportWorkspace.php,
app/Support/{AdminCapabilities,GlobalSections,ProductTabs,RichText,SearchSetChoices,SearchTerms}.php,
resources/css/kbb/kbb-product.css, resources/js/kbb/search.js, public/build/*,
resources/views/admin/app.blade.php, resources/views/admin/partials/{html-blocks-screen,product-editor-screen,reset-guard,search-terms-screen}.blade.php,
resources/views/store/product.blade.php, routes/web.php, and migrations
2027_07_08_000000 … 2027_07_09_000000 (six clear-caches, one adds block import columns).

## 2.60.343
**Turn any product into a set.** Apply after .342. Works with WordPress
plugin **1.9.1** (1.9.1 only stops a second installed copy from crashing
activation; the export is the same as 1.9.0).

**Where:** Catalog → Product editor → open the product → Basics → Product type
→ "Set — made of other products", then add the products in the box and Save.

- Choosing Set asks once and says what stays: price, sale and its dates,
  pictures, description, tabs, SEO, reviews and the web address.
- A converted set is the same as a set built as a set: same box on the product
  page, same pricing rules, same stock (selling one takes one of each product
  in the box), and it shows under Catalog → Products → Sets.
- **Re-importing from WordPress no longer turns it back** into a plain product,
  and does not overwrite the price of a set priced by a rule.
- Refused, with the reason named: a product that is already inside another set
  (it would change that set's price), and a product with options/variants.
- The set's Stock card explains that its own count is a second limit.

Files: `app/Http/Controllers/Admin/ProductEditorApiController.php`,
`app/Services/Import/Entities/ProductImporter.php`,
`resources/views/admin/partials/product-editor-screen.blade.php`,
`database/migrations/2027_07_07_000000_clear_caches_convert_to_set.php`.

## 2.60.342
**After-import fixes, part two: flag bar off, breadcrumb controls, Filters and
Sort on one row, a working pager with load-on-scroll, and an animated tick on
Add to bag.** Apply after .341. No plugin change.

| # | Your report | Now | Where the control is |
|---|---|---|---|
| 2 | Turn the flag bar off | Off on phones and desktop. Switch either back on any time | Appearance → Header → Flag bar |
| 4 | Breadcrumb controls, default OFF | Off on both by default; a separate on/off and spacing above/below for phone and for desktop. When it is off, the heading keeps 18px of air under the header | Appearance → Header → Breadcrumbs |
| 6 | Filters and Sort did not fit on one row on small phones | One row down to 320px wide; text and padding shrink with the screen, no script | — |
| 7 | Giant pagination arrows; want load-on-scroll / load all | Arrows fixed (normal size, mirror in Arabic). New choice: Arrows, Load more on scroll (12 / 15 / 20 / your own number), or Load all, using the grey loading placeholders | Appearance → Site layout → Loading more products |
| 8 | Animated tick instead of "Added to bag" | A grey tick turns green and is gone in under half a second; the cart panel still opens. Text pill and None are one click away | Appearance → Cart panel → Behaviour → When something is added |

Defaults that moved because you asked: flag bar off (both), breadcrumbs off
(both), add-to-cart feedback = Animated tick. "How more products load" ships at
Arrows, as today, until you pick.

Files: `app/Http/Controllers/Store/CartController.php`,
`app/Http/Controllers/Store/CollectionController.php`,
`app/Http/Controllers/Store/ShopController.php`, `app/Services/CartPanel.php`,
`app/Services/HeaderSettings.php`, `app/Services/SiteLayout.php`,
`app/Services/Translation/ArabicInterfaceDrafts.php`,
`app/Services/Translation/InterfaceStrings.php`, `app/Support/ListingBatch.php`,
`resources/css/kbb/kbb-shop.css`, `resources/css/kbb/kbb.css`,
`resources/js/kbb/{app,cart,listing-load,toast}.js`, `public/build/*`,
`resources/views/admin/partials/site-layout-screen.blade.php`,
`resources/views/layouts/store.blade.php`,
`resources/views/partials/{breadcrumb-css,drawers,listing-batch,listing-pager}.blade.php`,
`resources/views/store/{collection,post,shop}.blade.php`,
`database/migrations/2027_07_06_000000_flag_bar_ships_off.php`,
`database/migrations/2027_07_06_000100_clear_caches_lane_pib.php`,
`database/migrations/2027_07_06_000200_seed_listing_pager_arabic_drafts.php`.

## 2.60.341
**After-import fixes: search prices, descriptions, product tabs, Read less, and
the reviews your old shop actually showed.** Apply after .340. Ships with
**WordPress plugin 1.9.0**.

---

| # | Your report | Now |
|---|---|---|
| 1 | Search "Popular right now" showed price code | Shows "AED 349" like everywhere else |
| 3 | Descriptions ran together; `<div>` showed in the short description | Paragraphs, headings, numbered lines and lists as on the old site; tags render, never print. Unsafe code (scripts, click handlers) is removed |
| 5 | "Major Ingredients" and other tabs were missing | Imported as the product's own tabs (needs plugin 1.9.0 + Products re-export) |
| 9 | "Read less" left you far down the page | Brings you back to the top of the text, just under the header |
| 11a | Review "helpful" likes were not imported | Imported |
| 12 | Reviews shown on the old site (Dream Code Reviews) never came across, incl. copies on sibling products | Exported by plugin 1.9.0 and imported; synced copies of WooCommerce reviews are not doubled |

Your existing products' descriptions are fixed by this package alone -- no
re-import. Tabs and the Dream Code reviews need the new plugin and one
re-export of **Products** and **Reviews** (steps in the message).

Files: `app/Http/Controllers/Store/SearchController.php`,
`app/Support/RichText.php`, `app/Support/ProductTabs.php`,
`app/Support/ProductSeo.php`, `app/Services/Import/Entities/ProductImporter.php`,
`app/Services/Import/Entities/ReviewImporter.php`,
`resources/views/store/product.blade.php`,
`resources/views/partials/quick-view.blade.php`, `resources/js/kbb/tabs.js`,
`resources/css/kbb/kbb-product.css`, `public/build/*`,
`database/migrations/2027_07_06_000000_clear_caches_imported_descriptions.php`,
`database/migrations/2027_07_06_000100_add_product_tab_import_key.php`.

## 2.60.340
**The clean-up has a button.** Apply after .339.

You could not find "Clean up before the migration" because nothing linked to it
-- the page existed since .336 and no menu or button led there. It is now the
first card on **Store → Store Import / Export**: *Before you import -- clean up
this shop* → **Open the clean-up**.

`CleanupIsReachableFromImportTest` fails if the card is ever missing or drawn
twice.

Files: `resources/views/admin/app.blade.php`,
`database/migrations/2027_07_05_000000_clear_caches_cleanup_button.php`.

## 2.60.339
**Remove everything that did not come from WordPress, from one screen.** Apply
after .338, **before** you import.

---

You asked to remove all the products, categories, brands and the rest, and to
keep your pages, menus, banners and videos.

**Store → Import → Clean up before the migration** now lists, with names and
counts, before anything is removed:

| | on your shop now |
|---|---|
| Test orders (#10004, #10006) | 2 |
| Demo products + every other product not from WooCommerce | 25 |
| Categories not from WordPress | 6 |
| Brands not from WordPress | 8 |
| Reviews not from WordPress | 58 |
| Customers not from WordPress | 3 |

Tick what you want gone, type **DELETE**, press the button. If anything changed
since the list was drawn, it refuses and removes nothing.

**Never listed, never touched:** your pages, menus, homepage banners, videos,
and anything imported from WooCommerce or WordPress -- including a product that
a real imported order bought.

Your menus link by web address, so menu entries like *Toners* work again the
moment the import brings your real Toners category.

Also fixed before it could reach you: on MySQL -- your server's database -- the
product delete would have failed with error 1093 and removed nothing. Caught by
running the test on MySQL, not SQLite.

Files: `app/Services/Maintenance/PreMigrationCleanup.php`,
`resources/views/admin/cleanup.blade.php`,
`database/migrations/2027_07_04_000000_clear_caches_cleanup_everything.php`.

## 2.60.338
**The import, rehearsed before you run it.** Apply after .337.

---

Every part of the move from WordPress was **run, not read**: an export at your
shop's real size (671 products, 4,159 orders, 10,582 order lines, 3,713
customers, 2,514 reviews), a fake copy of the old site serving real pictures,
and the import **killed on purpose** the way a shared host kills long jobs.
It found real defects. All are fixed, each with a test that fails without it.

### AN IMPORT THAT GETS KILLED NOW RESUMES CORRECTLY

Before: killed partway through **categories**, 9 categories landed at the top
level with the wrong address and the menu items built from them pointed at the
wrong pages. Killed partway through **reviews**, 32 products showed **0 stars**
on their cards while their pages listed the reviews. Every count matched and the
report said "verified" -- nothing would have told you.

After: killed at 7 different points (once three times in a row) and resumed,
the result is **identical** to an uninterrupted import every time -- every
table, every total, every link.

### PICTURES

The pass that copies pictures off the old site before it is switched off:

| | before | after |
|---|---|---|
| left pointing at the old site, unexplained | 18 of 29 | **0** |
| requests the shop could be made to send to internal addresses | 5 | **0** |
| full size, 2,907 files (779 MB) | -- | **241 s, every file identical** |
| re-run after it finishes | -- | **0.29 s, nothing downloaded twice** |

A picture that genuinely could not be fetched now shows the shop's own
placeholder instead of a broken-image frame, and is listed by name so you can
replace it. Your customers' **review photographs** come across with the rest.

New command for the picture pass: `php artisan kbb:import-media-fetch`.

### PRICES

A **negative price** in the export used to import -- and the basket would have
paid the shopper. It is now refused and named in the import report. A **0.00**
free sample still imports.

### REVIEW STARS ON PHONES

A reviewer name that is one long word ("Anonymous", which imported reviews
carry) pushed the stars **29px into the next card**. They now take their own
line when they do not fit; every card that fitted looks exactly as before.

### ALSO

- The MySQL test run -- your server's database -- is green. Its three failures
  were in the tests, not the shop; each was checked against the live schema.
- No new setting, no admin screen changed.

Files: `app/Services/Import/ImportRunner.php`,
`app/Services/Import/Entities/{EntityImporter,CategoryImporter,ReviewImporter,ProductImporter,VariationImporter}.php`,
`app/Services/Import/{MediaSideloader,MediaAudit,MediaRewrite,DocumentMediaRewrite}.php`,
`app/Support/LostPictures.php`, `app/Console/Commands/ImportMediaFetch.php`,
`app/Http/Controllers/Store/ProductController.php`,
`resources/views/components/product-card.blade.php`,
`resources/views/admin/media-progress.blade.php`,
`resources/css/kbb/sorina-reviews.css`,
`database/migrations/2027_07_03_000000_clear_caches_import_rehearsed.php`.

## 2.60.337
**The phone menu opens in Safari and Opera.** Apply after .336.

---

### THE MENU, AND WHY ONLY SAFARI AND OPERA

**Root cause, proved in a browser rather than guessed.** When a visitor reaches
the shop at `http://extrabeauty.ae` — which Safari and Opera still do when you
type the address, while Chrome quietly tries `https://` first — the page asked
for its menu script and its font at **`https://`** extrabeauty.ae. To a browser
those are two different sites. A menu script of this kind (`type="module"`) and
a web font are only loaded across sites when the server says so, and it does
not, so **both were blocked and the menu never started.** Opera's *"connection
is not secure"* is the same fact seen from the address bar: the page itself had
arrived over `http`.

**The fix:** the shop now asks for its scripts, styles and fonts by path
(`/build/assets/…`) instead of by full address, so they always come from
whichever address the page itself came from — `http` or `https`, with or without
`www`. Measured on an emulated iPhone, page on one address and assets on the
other:

| | menu opens | blocked |
|---|---|---|
| before | **no** | the font, the menu script |
| after | **yes** | nothing |

### ONE FAILING STEP CAN NO LONGER TAKE THE MENU WITH IT

The shop starts 21 small pieces when a page opens — search, cart, menu, product
gallery and so on — one after another. Until now, if any one of them threw an
error, **every piece after it never started.** The menu is the 9th. Each piece
now starts on its own; a failure is written to the browser console by name and
the rest of the page carries on.

### PHONES: FEWER FULL-SCREEN LAYERS BEHIND THE PAGE

On touch screens the page's background wash is now painted once on the page
itself instead of on three fixed full-screen layers. Measured in Chrome: fewer
layers composited, no repaint while scrolling.

**Said plainly: this is a risk reduction, not a proven fix for the "giant box"
you saw after clearing the cache.** It could not be reproduced here. It removes
the most likely cause; please check it on your phone after applying.

### IMPORT: REVIEW PHOTOGRAPHS AND LARGE FILES

- **Review photographs now travel with the export** (WordPress plugin 1.8.0 —
  see below). Before, a review's photos were named in `reviews.csv` but missing
  from `media.csv`, the list of files the new shop downloads from the old one, so
  they would have been lost when the old site was switched off.
- **Store → Import:** an export file larger than your server accepts in one
  request is now uploaded in pieces automatically. Your files fit today (10 MB
  limit, largest file about 2.2 MB), so you will not see this unless that
  changes.

**The plugin is not inside this package and cannot be** — the updater refuses
WordPress files. Install the separate `kbb-exporter-1.8.0.zip` on the
WordPress site: **Plugins → Add New → Upload Plugin**. WordPress then shows
version **1.8.0**.

---

### STILL OPEN

- **The "page not found" on a first visit from a new device.** This is the shop
  being reached over `http`; it is fixed at the server, not in a package —
  Cloudways → your application → **SSL Certificate** → HTTPS redirection.
  Waiting on your screenshot of that page to confirm the certificate covers both
  `extrabeauty.ae` and `www.extrabeauty.ae` before you switch it on.

Files: `app/Providers/AppServiceProvider.php`, `resources/js/kbb/app.js`,
`resources/css/kbb/kbb.css`, `public/build/*`, `app/Http/Controllers/Admin/ImportPartsController.php`,
`app/Services/ImportConsole/UploadParts.php`, `app/Services/Import/MediaAudit.php`,
`app/Services/Import/MediaRewrite.php`, `app/Support/AdminCapabilities.php`,
`routes/import-parts-admin.php`, `routes/web.php`, `resources/views/admin/app.blade.php`,
`resources/views/admin/partials/import-parts-screen.blade.php`,
`database/migrations/2027_07_02_000000_clear_caches_import_parts.php`.

## 2.60.336
**Your product-page preview, your gallery thumbnails, and the migration checked
end to end.** Apply after .335.

---

### ABOUT .335 AND YOUR SERVER

.335 fixed a database step in .330 that MySQL rejects. **Your shop was not
affected** — you checked with `migrate:status` and every step reads *Ran*. The
broken query only runs on a shop that had not yet chosen a homepage banner set,
and yours had. The fix still matters for any fresh install, which is why it
shipped.

The real lesson is the one underneath it: the MySQL test run that exists to
catch exactly this had been broken, so nothing was watching. It works again,
and switching it back on surfaced three more MySQL-only faults.

### THE PRODUCT PAGE PREVIEW, PHONE AND LAPTOP TOGETHER

`Appearance → Product page` now shows a **live preview beside the controls, on
every tab** — **laptop (1280) and phone (390) side by side, never a toggle**, both
always on screen, each with a **Full size ↗** link. It updates as you drag, before
you save.

It frames **your real product page**, not a mock-up. Proved by measurement:

| moved | laptop frame | phone frame |
|---|---|---|
| Product name · phone, 19 → 28px | **unchanged** | **19 → 28px** |
| Product name · laptop, 30 → 44px | **30 → 44px** | **unchanged** |
| Space between sections, 34 → 90px | **34 → 90px** | **34 → 90px** |

> **A first attempt that was wrong, and how it was caught.** The panel first
> framed the five *design drafts* rather than the real page. It looked perfect —
> two frames with a handsome product page in them — and **29 of your 30 controls
> moved nothing**, because those drafts are built from entirely separate styling.
> The screenshots caught it; the code looked fine.

### YOUR GALLERY THUMBNAILS, SO YOU CAN SEE THEM WORK

The strip under the product photograph now shows **five pictures** on every demo
product — Front, Texture, Ingredients, On skin, Box — and clicking one changes the
main photograph.

| | phone | desktop |
|---|---|---|
| thumbnails | 5, 56 × 56 | 5, 66 × 66 |
| strip lines up with the photograph | yes | yes |
| clicking the 4th changes the main picture | yes | yes |

**They are drawn on your server, not shipped in the package** — 23 KB per
product, 563 KB for the whole catalogue. **They never touch your catalogue
data.** The first approach put them on the products themselves, and that would
have pushed 120 placeholder pictures into your Media Library, your image
sitemap and your Google product data. So they live as files the page tops up
from, only on demo products with no pictures of their own.

Give a demo product a real photo and the placeholders stand aside. Delete one
in **Content → Media Library** and it is gone.

### BEFORE YOU MIGRATE FROM WORDPRESS

The whole path was exercised rather than read — the plugin's real code against a
WordPress-shaped database, then 18 deliberately broken inputs through the importer.

**It behaves well, and its instinct is to refuse rather than guess:** a price
written `1.234,50` is refused as *ambiguous* instead of being read as one value or
the other; a review with no rating is refused rather than published as five stars
nobody gave; a file cut off mid-upload is refused by name with the rows before it
kept. Money is exact to the fil, dates exact through the Dubai-to-UTC conversion.

**Two things needing your attention before you start:**

1. **Upload size is fine.** Your server accepts 10 MB per request (you checked:
   `post_max_size = 10M`), and your largest export file is about 2.2 MB.
2. **Review photographs** are being made to travel with the export properly —
   that is the next package, not this one.

**Also fixed in the plugin:** WooCommerce 8.2+ was labelling it *"incompatible
with High-Performance Order Storage"* on the very screen you would check before
trusting it with your orders — it is compatible, and now says so. And if
WooCommerce is missing it now tells you at the top of the screen instead of dying
halfway through a run.

### A CLEAN-UP TOOL, WHICH SHOWS YOU FIRST

**Store → Import → "Clean up before the migration".** It lists what it would
remove with counts and sizes, and **refuses to run unless those counts still
match** what it showed you. You have to type DELETE.

It reaches the demo products, demo reviews, the applied update packages (which
nothing has ever pruned) and old logs. Four guards stand between it and anything
real — and **a demo product that has actually been bought is held back**, because
sold is sold.

Proven on a real server: 24 demo products removed; an imported product, a
hand-typed one and a bought demo product all **survived**; orders, order lines and
customers untouched.

### THE BRAND NAME IS A LINK, AND THE VAT LINE IS OFF

The brand above the product title now links to that brand's page — which already
existed, with its own copy, logo and product grid, and is already in your sitemap.
Your product page was the one place that named a brand and linked nowhere.

**"Inclusive of 5% VAT" is off.** Put it back at **Appearance → Product page →
Sections → VAT line**.

---

### STILL UNPROVEN — SAID PLAINLY RATHER THAN GLOSSED

Before you move your real shop, these are **not** verified:

- **Nothing has been run against your actual data.** Unknown until your export
  exists: how many orders carry unusable dates (all refused), whether any product
  has a duplicate code.
- **No import was killed mid-run.** The design is right and resuming is covered by
  tests, but the hard rehearsal was not done.
- **The pictures were not exercised at all** this round.
- **A negative product price imports silently.** Found, deliberately not patched
  at the end of a round.
- **The plugin has no delivery mechanism** — it has to be zipped and sent by hand,
  and nothing reminds anyone to do it.

---

## 2.60.335
**Hotfix, one file.** A database step shipped in .330 used a query MySQL rejects
(`ERROR 3065`). It only runs on a shop that has not yet chosen a homepage banner
set, so it did not affect this shop — `migrate:status` reads *Ran* for every step
— but on a fresh install it would stop the update and strand every step after
it. Rewritten with `whereExists` instead of `join` + `distinct`, verified on a
real MySQL 8 server.

---

## 2.60.334
**Your homepage, your product-page controls, and the demo tabs.** Apply after .333.

---

### THE HOMEPAGE PANELS ARE GONE, AND SECTIONS CAN RUN FULL WIDTH

The white card behind every section is off. Each section can be **normal**,
**full width** (edge to edge, text kept off the glass) or **bleed** (no gutter at
all), capped at **1920px** and fluid below it.

Measured on your real homepage, before → after:

| | 320 | 390 | 1280 | 1440 | 1920 | 2200 |
|---|---|---|---|---|---|---|
| first section width | 320→**320** | 390→**390** | 1256→**1280** | 1416→**1440** | 1680→**1920** | **1920** |
| banner width | 296→**320** | 366→**390** | 1203→**1280** | 1358→**1440** | 1622→**1920** | **1920** |
| banner left edge | 12→**0** | 12→**0** | 39→**0** | 41→**0** | 149→**0** | 140 *(centred)* |
| sideways scrollbar | none at any width, including 2200 |

**The cap holds:** at 2200px the page stops at 1920 and centres.

**The banner is square and flat.** Radius 18→0, shadow off, and the "etc." also
cost it 33px of padding it was sitting under. Every one is a switch you can put
back at **Appearance → Homepage → *section*** and **Appearance → Banners**.

Two rows deliberately get no controls — the **Delivery strip** and **Promo
ticker** live inside the hero band, so a width setting there would do nothing.

> **A defect found on the way, which you would have hit and not understood.**
> Section settings were being remembered under a key that ignored which setting
> it was. Two sections without a grid style shared one entry, so whichever loaded
> first decided the width **for all of them** — your whole page bleeding, or your
> banner never leaving its card, with no error and no pattern. Also: applying a
> homepage preset was throwing away every panel you had switched back on.

### PRODUCT PAGE → LAYOUT: THIRTY CONTROLS

`Appearance → Product page` now has a tab strip. Your seventeen on/off switches
sit behind **Sections**; four new tabs sit beside them:

| tab | controls | examples |
|---|---|---|
| **Spacing · Page** | 5 | space between sections (34px), gap between detail tabs (22px) |
| **Spacing · Buy column** | 8 | above the name (8px), name to price (16px), above the trust lines (22px) |
| **Type · Buy column** | 12 | name on phone (19px) and laptop (30px), price (22px), VAT line (11px) |
| **Type · Sections & tabs** | 5 | section headings (22px), tab labels (13.5px), tab line spacing (1.7) |

There is a **Reset layout to defaults** on the strip.

**Nothing moves until you move it.** Every field ships at the number your page
already draws, and the page emits **no styling block at all** until you change
one. Proved by measuring the same page before and after — eleven boxes and
seventeen groups of computed styles at 390 and 1280: **0 differences**. Saving
twelve values and deleting them again also returns **0 differences**.

### THE DETAIL TABS FINALLY HAVE SOMETHING IN THEM

Every product showed **one** tab. None of the demo products carried
`Ingredients` or `How to use`, and the tab row drops an empty one — so you have
never seen the feature work.

| | before | after |
|---|---|---|
| tab row | `Description` | `Description · Ingredients · How to use` |
| clicking the third | — | opens it, exactly one panel visible |

**Your real products cannot be touched by this.** It fills a column only where it
is blank, and only on a row carrying **three** demo marks at once: no WooCommerce
id, a `DEMO-` code, and the seeder's exact wording. An imported product fails the
first check before the others are asked.

### "UAE's Authentic K-Beauty Store" IS OFF THE DESKTOP STRIP

The words go above 900px; the two flags stay. **Phones are byte-identical** —
compared as images, not by eye. Put it back at **Appearance → Header → Flag bar →
Show the wording on desktop**.

> **Two things about this you should decide on, because I do not think it looks
> right and will not pretend otherwise.** The words were the only thing holding
> the two flags apart, so on a wide screen they now sit together in the middle of
> an otherwise empty strip. Three layouts were tried and photographed; centred is
> the best of them and none is handsome at 1920. Your two honest options are to
> put the wording back, or to turn the whole strip off on desktop at **Appearance
> → Header**.
>
> And the words leave the screen but **stay in the page source**. Your shop sends
> identical pages to every device on purpose so they cache properly, so the text
> cannot be removed for desktop only without also removing it from phones. It is
> hidden from screen readers either way.

---

## 2.60.333
**You could not choose a grid design. Fixed.** Apply after .332.

---

### THE GRID STYLE PICKER WAS OPENING OFF THE SCREEN

`Appearance → Homepage → Grid style` opened its panel of designs **leftwards**,
so most of it landed outside the settings panel and the rest went **behind your
left menu**. There was no way to reach the designs.

Measured on the console at 1280, before and after:

| | before | now |
|---|---|---|
| panel's left edge | **120.6** | **377.5** |
| your left menu ends at | 248 | 248 |
| what is painted at the panel's own top corner | **the menu** | **the panel** |
| designs you can see and click | cut off | **32 of 32** |

**Two faults, and the second only mattered because of the first.** The panel is
470px wide and was pinned to the *right* edge of a button that sits near the
left of the row — so it grew in the one direction with no room. The section list
then clipped whatever escaped, because it was trimming its own rounded corners.

It now opens to the right, where the space is. The rounded corners are kept by
rounding the first and last row instead, so nothing is lost.

---

## 2.60.332
**Three things you pointed at, and one you had not seen yet.** Apply after .331.

---

### THE PHOTO NO LONGER HANGS OFF THE LEFT OF YOUR PAGE

You drew a line down the screenshot through your logo and "Product details" —
the picture started **22px to the left of it**. Measured at 1920, 1440 and 1280:
exactly 22px every time, so it was built in rather than a screen-size accident.

Now the picture, its thumbnails and the rest of the page share **one left edge**.

> **On a phone it still runs to both screen edges, and that is on purpose.** That
> is the Ledger look you picked and praised, and a phone has no "content edge"
> for it to cross. Say the word and it comes in there too.

### THE QUANTITY BOX IS LEVEL WITH ADD TO CART

You marked both edges. They were off by two different amounts at once:

| | before | now |
|---|---|---|
| Add to cart | 54px tall | 54px |
| Quantity box | **56px**, sitting **6px lower** | 54px, **level** |

**The 6px was not the product page's fault.** The cart drawer defines its own
little quantity pill with a 6px top margin, and does it in a way that leaks onto
every page in the shop — including your product page. It had been doing that for
as long as both files existed. The other 2px was the stepper counting its own
border and the button having none.

Identical on desktop and mobile, so one fix covered both.

### "FREQUENTLY BOUGHT TOGETHER" HAD NO STYLING AT ALL

You did not report this one. That block was never given a stylesheet when the
shop moved off WordPress — the original plugin supplied its own and it was never
carried across. The result, measured at 320px:

| | before | now |
|---|---|---|
| row heights | **18, 81, 156 and 210px** | **one height** |

Four different heights in one strip, thumbnails floating out of place and the
tick box, the name and the price running together as one paragraph.

### AND "APPLY THIS EVERYWHERE" NOW MEANS EVERYWHERE

When you asked for equal card heights everywhere, **eight** pages were checked.
There are **fourteen**. The seven that were missed were missed for an honest
reason: five of them are empty or hidden until something switches them on, and an
empty page measures as "no problem here".

Now measured at 320, 390 and 1280, all fourteen:

home · shop · category · brand · search · related products · **new in** ·
**best sellers** · **super sale** · **everything under 54 AED** · **by concern** ·
**routines** · **wishlist** · cart and checkout (no product grid, correctly)

**One card height on every one of them, at every width — and Arabic measures
identically to English throughout.**

---

### ABOUT THE WISHLIST HEART

It was never removed. It is in the code, it is built to sit beside Add to cart,
and the only commit that ever touched it is the original one.

**You cannot see it because the wishlist itself is switched off.** Turn it on at
**Catalogue → Wishlist** and it appears. Measured with it on: your card heights
do not change at all — the button simply narrows to make room.

One thing to know before you switch it on: **on a phone the heart sits on the
corner of the photo, not beside the button.** You asked for beside the button on
both, so that is still owed.

---

## 2.60.331
**Your bundle rows were advertising the wrong discount.** Apply after .330.

---

### THE BADGE WAS CLAIMING A SAVING THAT WAS NOT THERE

On a product with bundles, pressing **2-pack** changed the big price to AED 140
and left everything around it alone. So the page read:

> ~~AED 99~~ **AED 140** **−25%**

next to a row that read **AED 149 / AED 140 / Save 6%**.

The **−25%** was the single unit's discount, still sitting there. On a 2-pack
discounted **6%** it is a **false claim about money**, on the screen where the
customer is deciding. Every row on the page, before and after:

| pressed | the row says | the price block said | now says |
|---|---|---|---|
| 1 unit | AED 74 | ~~99~~ 74 −25% | unchanged |
| 2-pack | ~~149~~ 140 · Save 6% | ~~**99**~~ 140 **−25%** | ~~149~~ 140 **−6%** |
| 3-pack | ~~223~~ 198 · Best value | ~~**99**~~ 198 **−25%** | ~~223~~ 198 **−11%** |
| 50ml | ~~99~~ 79 · Save 20% | **AED 79**, no discount shown | ~~99~~ 79 **−20%** |
| 100ml | ~~169~~ 127 · Best value | **AED 127**, no discount shown | ~~169~~ 127 **−25%** |

The last two are the other half: on a product with **sizes**, the block showed
the price and said nothing about the saving at all, because a product with
variations carries no price of its own for the shop to mark down.

### THE OBVIOUS FIX WOULD HAVE BROKEN THE VIEW THE PAGE OPENS ON

Worth saying because it is not obvious. The "1 unit" row has no saving of its
own — one unit at the sale price is just the sale price — so copying each row's
figures straight into the block would have **removed "AED 99 / −25%" from the
default view**, which is the product's real markdown and the number the page is
mostly about. The two struck figures are different true things: **AED 99** is
what one cost before the sale, **AED 149** is what two cost without the bundle
deal. The block now shows whichever the pressed row actually means.

### WHAT DID NOT CHANGE, AND WAS CHECKED RATHER THAN ASSUMED

- **The bar that follows you down the page** already tracked the price
  correctly and carries no discount badge by design. It was measured first,
  found correct, and deliberately **left unable** to grow one — otherwise this
  fix would have handed it the same bug while curing it above.
- **Your Google listing did not move.** The price Google reads is written into
  the page before the buy buttons exist. Measured pressing the 2-pack: the block
  is **byte-identical, 5,424 bytes both times**. Nothing a customer clicks can
  change what search engines are told.
- **Arabic**: the percentage is composed on the server so it reads correctly
  right-to-left, rather than being glued together in the browser where it would
  not.

> **One thing found and deliberately not changed:** a product with sizes opens
> showing a **range** — "AED 69 – AED 127" — while the first size is already
> highlighted below it. Pressing anything resolves it. Making the first view
> agree would change what *every* product with sizes says before anyone touches
> it, and that is your call, not a bug fix's.

**This package carries JavaScript.** It must be applied as a whole; the price
block is half template and half script and neither half works alone.

---

## 2.60.330
**The big one: your shop looks different.** New typeface, new product page, new
homepage banner, new product card, new background. Apply after .329.

---

### THE TYPEFACE IS OUTFIT

Everywhere, and it cost you **less** than Poppins did. Outfit is a variable font,
so where Poppins needed five files your shop now loads **one** — 39,272 bytes
across five files down to **32,292 in one**, and **one request where there were
four**. The speed work from .325 is not undone; it is slightly better.

Your header, every heading, every button, your card heights and — the one that
mattered — **your checkout's form fields are identical to the hundredth of a
pixel**. Arabic is untouched; it uses a different face and always did.

Two things did move and are not being hidden: the home search box is 3px shorter
and the footer 7–10px, because those take their height from the text rather than
a fixed number.

### THE PRODUCT PAGE IS LEDGER

The design you picked, on mobile and desktop, and **the page does not end there** —
frequently-bought-together, the tabs, your reviews, the related grid and the
footer all follow it exactly as you asked.

The photograph runs to both edges with the thumbnails floating on it, hairlines
replace the boxes, and **the tab row now shows at every width** instead of
collapsing into an accordion on phones.

**And the "big bold font" is fixed — half of it by Outfit, before anyone touched
it.** What you saw was 21px at weight 600, which wrapped to **three lines** in the
old face. In Outfit the same settings wrap to **two**. The weight is now 500, the
size 19px, and your longest product name went from five lines to four.

### THE HOMEPAGE BANNER IS PICTURES, AND THE FLAG STRIP IS UNDER IT

Simple image banners, swipeable both ways, with **no arrows or dots when there is
only one picture**. 1920 × 550 on desktop, 500 × 600 on phones.

**Each slide now takes two pictures** — the wide one and a phone one — with both
sizes printed on the buttons at **Appearance → Banners**. A slide with no phone
picture says so.

And when a slide has no phone picture, your shop **makes the phone crop itself**,
on the server. A phone downloads **80 KB instead of 458 KB** for the same picture,
looking identical — the three quarters that get cropped away never leave the
server.

> Two fixes came out of this that you would have seen: a stray divider line under
> your header on every phone homepage, and your banner's preload sitting 20 KB
> into the page instead of at the top — which decides how fast your largest image
> arrives.

### THE PRODUCT CARD: NAME, RATING, PRICE, BUTTON

Brand and category lines are **off**, as you asked. They are still switches at
**Appearance → Product styles → Card content** if you want either back.

**And the equal heights are now genuinely equal.** They were not before. A card
stretches to match the others *in its own row*, and nothing lined it up with the
row above — so on your real catalogue a category page had **three different
heights on desktop and five on a phone**, and `/shop` at 320px had **seven**. Now
one height per page, at 320, 390 and 1280, everywhere a product grid appears.

Two things found in the pictures rather than the code: your **sale price was being
cut off at 320px** (the shot read "AED 2…"), and one card treatment ran the sale
price **under the button** on 5 of 12 tiles.

Your shop page also got **87ms faster** — the card was reading its settings 696
times on a 24-tile page.

### THE BACKGROUND IS CORNER LIGHT

The drawing is gone, the tiling with it. A light four-colour wash running corner
to corner, shifting slowly over five minutes, and stopping completely on a phone
set to reduce motion. If you ever want the leaves back, they are a switch at
**Appearance → Page background**.

> **Worth knowing before you look:** your own white panels cover **100% of the home
> page's first screen**. So the background colours about **18% of /shop/, 16% of a
> product page and 3% of the home page**. That is not a fault in the colour — it is
> what sits on top of it.

---

### WHAT MOVED, AND WHAT DID NOT

Five defaults moved and every one of them is something you asked for: the
typeface, the product page, the banner, the card's two lines, the background.
Everything you did not ask about is byte-identical.

**This package runs migrations and needs them** — one adds the phone picture
column, one makes the phone crops for the slides you already have, and one clears
the compiled templates. Without them the shop applies the package and looks
unchanged.

---

## 2.60.329
**Two more admin buttons that took your screen away, and a cache that answered
for the wrong shop.** Apply after .328.

---

### ▲ TWO MORE PLACES THE CONSOLE VANISHED ON AN EXPIRED SESSION

.326 fixed nine of these. Widening the guard that watches for them found two
more, and neither lives in the file the earlier sweeps read — which is why three
passes over the main console never saw them.

**Reviews → Reviews.io → Export** replaced your whole screen with a blank
`404 NOT FOUND`. It shows the screen's own banner now, with everything still on
it.

**Instagram → Connect**, when the browser blocks the pop-up. The screen has a
deliberate fallback for that case — follow the link in this tab instead — and on
a dead session that fallback took the console with it. It asks first now.

That fallback is also the one place in this work where the answer had to be the
*opposite* of everywhere else: the screen's own note says a blocked pop-up must
never become a button that does nothing, so this one **fails open**. If the shop
cannot tell whether your session is alive, it goes anyway.

### ▲ A CACHED VALUE COULD ANSWER FOR A SHOP IT HAD NEVER BELONGED TO

The shop remembers a handful of settings in files on disk rather than asking the
database each time — sensibly, since the database read costs about ten times what
the file read does. What it did not do was keep one shop's answers apart from
another's. Two different databases on one machine both got the same admin
address, left behind by neither of them.

On your live server there is one shop, so this has not bitten you. It bites
anywhere a second copy runs beside the first — a staging site, a test restore,
the machine this work is built on — and the symptom is a setting that is
correct in the database and wrong on the screen.

Each shop now gets its own drawer, with no change to any of the forty places in
the code that clear those settings. Clearing the cache by hand still clears all
of them.

---

### AND ONE THING THE TESTS COULD NOT SEE

The explanation a shopper gets when a set takes the last of something they also
added loose — the fix from .325, for the problem you reported — was covered by a
test that checked the **whole page** for the sentence. The cart drawer is drawn
into every page and carries that same sentence, so the test passed whether the
cart page said anything or not.

Removing the explanation entirely: **15 passed, 0 failed.** The checkout had the
same hole. Nothing about your shop changed here — what changed is that removing
it now fails loudly.

---

### WHAT MOVED, AND WHAT DID NOT

No setting, no screen, no default, no new control.

> **This package does run a migration**, and it is only a cache clear — but it is
> the half without which the rest of the package does nothing. Two of the files
> in it are screens your server keeps a compiled copy of, and one is a
> configuration file it may also have compiled. Applied without the clear, the
> two buttons above would go on doing exactly what they do today and **nothing
> would error**, which is the way this kind of change fails silently.

---

## 2.60.328
**Two order defects, and the first one is not a rare race — it is ordinary cash
on delivery.** Apply after .327.

---

### ▲ A CANCELLED ORDER COULD SHOW A RECEIPT AND SEND A CONFIRMATION

When a cash-on-delivery order is placed, the shop moves it from *waiting to be
paid for* to *processing*, and then tells the checkout what happened. It was
saying **"placed"** whatever happened.

The code's own comment says only an order still waiting to be paid for is moved
on — and the answer to that question was thrown away. So for an order that had
already been cancelled, the move correctly declined, the checkout was told the
order was placed, **the receipt was shown, the confirmation email went out, and
the stock stayed claimed.**

Cash on delivery is most of this shop's orders, so this is the one to apply for.
It is not a race and it does not need unlucky timing — it needs an order that is
not in the state the shop assumed.

### ▲ THE SAME DEFECT .327 FIXED, IN THE METHOD .327'S CODE WAS COPIED FROM

`cardAbandoned()` — the path a card payment takes when the shopper walks away —
has carried the identical fault the whole time, and it is where the code fixed in
.327 was modelled on. It asked whether the order had been paid, and then put the
basket back **regardless of the answer**.

On the shop: a shopper whose card payment confirms in the moment they are leaving
the checkout ends up holding a live basket of goods **they have already been
charged for**, with the stock never released because the order never moved.

Found by sweeping the whole application for the shape rather than by waiting for
it to happen twice: *a guarded write whose guard's answer is discarded*. Five
places do that; two were wrong, both in the checkout, and both are fixed. The
third is correct and now says so at the line. A sixth cannot be added without the
suite noticing.

---

### AND A LARGE NUMBER YOU MAY WANT TO KNOW

None of this changes your shop, but it is the reason the two above were findable.
The test suite was measured for assertions that pass for a reason unrelated to
the thing they are testing. Of **140** that check for a sentence a shopper is
shown, **114 could not see their own subject.**

Two of them, proved by actually breaking the shop:

- Take the **name off every product card on the site** — every card, no name at
  all — and the suite reported **21 passed, 0 failed**.
- Stop emitting a **page description on every page** — none, anywhere — and it
  reported **28 passed**.

Both are things you would notice within a minute. 26 are repaired and the rest
are assigned. Nothing about your shop changed; what changed is that the suite can
now see those two.

---

### WHAT MOVED, AND WHAT DID NOT

No setting, no screen, no default, no new control, and no migration. Two order
paths that were reporting success they had not earned now report the truth.

---

## 2.60.327
**One fix, and it is to something .326 shipped an hour earlier.** Apply after
.326.

> Nothing else in this package. It is one defect, found by the lane that wrote
> the feature, in the feature it had just written.

---

### ▲ A SHOPPER COULD HAVE KEPT A BASKET THEY HAD ALREADY PAID FOR

The **Put my basket back** button that arrived in .326 asked twice whether the
order had been paid — and the second question, the one asked with the row locked,
had its answer thrown away. So the order correctly refused to move, and the
basket was handed back anyway.

What that is on the shop: a shopper standing on the return page when the
provider's confirmation lands, who then presses the button. They end up holding a
live basket of goods **they have already been charged for**, and the stock those
goods were holding is never released, because the order never moved.

It needs the payment to confirm inside the few seconds the shopper is looking at
that page. That is narrow — and narrow is not rare when a payment provider is
sending the confirmation.

**The obvious fix would have been wrong**, which is why this is a package of its
own rather than a line in a bigger one. Simply refusing whenever that second
check comes back empty breaks the ordinary case: a shopper whose payment the
provider *failed* gets the same empty answer, and they are owed their basket
back. The question is now asked of the locked row itself — *is this order over,
and did it take no money?* — and the two cases are each other's proof: make it
refuse the other way and the provider-failed case goes red while the race stays
green.

The reverse order of the same two events was already right and is now pinned: a
payment that is **reversed** leaves a failed order still carrying its payment
date, and no button is offered over money that has moved.

---

### WHAT MOVED, AND WHAT DID NOT

Nothing else. No setting, no screen, no default, no new control. If you have not
yet applied .326, apply **.325 → .326 → .327** in that order and nothing on your
shop was ever exposed to this.

---

## 2.60.326
**Five fixes to things that were quietly wrong, and one of them means your shop
has never rendered a font weight it asks for 57 times.** Apply after .325.

> Your four design decisions are still waiting and none of them is in this
> package. Nothing ships on any of them until you pick.

---

### ▲ SIGN OUT DID NOT SIGN YOU OUT

The button posted to a fixed `/admin/logout` address — and your app answers 404
at that address the moment the admin path is moved, which is the entire point of
the setting. Measured on a real shop:

    POST /admin/logout    → 404   the failure was swallowed silently
    GET  /admin/login     → 404   a blank page
    GET  /admin-api/stats → 200   ▲ you were still signed in

So pressing Sign out gave you a blank page and left your session alive. Now it
signs out of the console you are actually standing in, lands you on the real
login page, and the session is genuinely dead afterwards.

### ▲ YOUR EXPORT BUTTONS TOOK THE WHOLE CONSOLE AWAY WHEN THE SESSION HAD DIED

Last package closed a hole where any stranger with the right tool could learn
your secret admin address. The cost, which arrived with it: five export buttons
and the four order-document buttons navigate the browser directly, so with an
expired session they landed on a blank `404 NOT FOUND` — and for the exports,
**the console screen you were on was gone.**

Measured, before and after, with the session cookie actually dropped:

| Orders → Export, session dead | console still there? | what it said |
|---|---|---|
| **before** | **no** — a blank 404 | nothing |
| **after** | **yes** — sidebar and list intact | "Your session has ended" + **Sign in again** |

The order-document buttons opened a tab and said **nothing at all**; they tell
you now. Ten places in the console were changed, including two nobody had on any
list: the **Stripe Connect** and **Instagram** pop-ups.

### ▲ THE SHOP HAS NEVER RENDERED FONT WEIGHT 500, ANYWHERE

Your stylesheets ask for medium weight in **57 rules**, and **106 visible things
across eight pages** — prices, filter chips, navigation links — have been
rendering as ordinary regular text instead.

The reason it was missed: browsers fake **bold** when a weight is missing, so it
is easy to assume they fake medium too. They do not. With regular and semi-bold
loaded and medium absent, anything asking for medium silently gets regular.
Measured: a medium heading rendered **497.20px wide against 497.20px for
regular** — the same to the hundredth of a pixel — and 505.00px once the real
face was there.

Poppins Medium now ships with the shop. It costs nothing to load: the file is
**smaller** than the regular and semi-bold you already serve, and it is not on
the critical path, so last package's speed win is untouched. The one page whose
text actually shifts is the home page, by **9 pixels of height on a phone**.

### ▲ A SET PLUS THE SAME PRODUCT ON A SHARED SHELF GAVE THE WRONG ADVICE

Where a set and a loose line draw from the same stock, the shop was checking them
separately and saying *"Hydrating Serum **is sold out**. Please **remove it**"*
when the truth was *"**Only 1** is left. Please **reduce the quantity**"*. A
shopper told to remove a line removes it, and you lose the sale of the unit you
had. It now counts against the shelf the units actually leave — and it got
**cheaper**, not dearer: six lines went from 19 database statements to 4.

### ▲ YOUR BRAND COLOUR HAD NEVER REACHED FIVE OF YOUR PAGES

The journal, an article, the review wall, the skin quiz and the app page each
build their own page head and never received your accent setting — so all five
hard-coded the original pink. The skin quiz used it **twelve times**. Your site
width was missing from three of them. If you had ever changed your brand colour,
those five pages ignored you.

Fixed, at **zero bytes changed** on a shop that has not moved the colour. The
journal and an article also carry the shop's real background now instead of
plain white, for **1.1 KB** and no extra request.

---

### THE ABANDONED BASKET COMES BACK

A shopper who gets as far as Tabby or Tamara and does not finish used to return
to an **empty basket**. There is a **Put my basket back** button now, and three
separate things that stopped it being reachable are fixed: it vanished on a
reload, it appeared in places where pressing it would be refused, and returning
to that address a second time destroyed the only handle on the basket.

`/cart/` also now shows error messages at all, which it never did — one of your
own redirects has been sending a message there that nothing drew.

---

### WHAT MOVED, AND WHAT DID NOT

**No shipped default changes in this package.** Everything above is either a fix
to something that was broken or a setting at the value the page already had.
The one visible difference on the storefront is text that asks for medium weight
finally getting it.

### STILL WAITING ON YOU

1. **The product page** — five designs. Catalog → Product page → Design previews
2. **The product card** — A is live; B, C and D are one setting away
3. **The picture slider's look** — four. Appearance → Banners → (the set) → Look
4. **The site background** — four. Appearance → Page background

And two server-side jobs no package can do: cache headers and HSTS, and
registering the domain with Apple Pay in Stripe.

> **One new thing for your list.** The review block on **every product page**
> asks for two fonts this shop has never loaded, so those titles render in
> Georgia and always have. Fixing it means either adding two font families or
> restyling the block in your own type — both change a page that currently
> works, so it is your call and not mine.

---

## 2.60.325
**The biggest one yet: thirteen lanes, and three of them are fixes to things
that were quietly broken on the live shop.** Apply after .324.

> **Three things are waiting on a decision from you**, and none of them changes
> anything until you make it: the product page, the slider's look and the site
> background. All three are previews you can open. See **What is waiting on you**
> at the end.

---

### ▲ THE SHOP WAS HANDING YOUR SECRET ADMIN ADDRESS TO STRANGERS

You found this on the order-tracking page: the login link went to the
administration login, which is only for you. It was not that link. **Every
logged-out shopper who clicked anything behind an account — Your orders, the
address book, an order page — was redirected to `/<your admin path>/login`**, and
that address then sat in their address bar, their browser history and the
referrer of their next click.

One line in the application's start-up file set the guest redirect for the
**whole shop** while its comment claimed it was for the back office. It now
decides by which door was knocked on: an admin page sends you to the admin login,
everything else sends you to **/my-account/**. Where you were trying to go still
survives the sign-in.

Checked against a preview: `/my-account/orders` logged out was `302 → /mr-cool/login`
and is now `302 → /my-account`. The back office is unchanged, before and after.
A sweep of **all 52 storefront pages** requested logged out now fails the build if
the admin address appears in any page or any header.

> **Not closed yet, and you should know:** 398 admin endpoints still sit at fixed
> public addresses (`/admin-api/…`) and still name the admin login to anyone who
> asks for one with curl. That is the same leak through a second door and it is
> being worked now.

### ▲ A SET PLUS THE SAME PRODUCT LOOSE MADE THE ORDER UNPLACEABLE

Exactly what you reported. If a set in the basket contains a product and the same
product is also in the basket on its own, and only one is on the shelf, the shop
asked for two and refused itself: *"Only 1 of … is left"*, and no order.

Now, **while you are looking at the basket** — never at payment time — the loose
line is taken out and the set stays, which is the rule you asked for. The totals,
the item count and the free-delivery bar all follow, because they are read off the
basket afterwards. Measured: **(2 items) / AED 289 / 2 rows → (1 item) / AED 199 /
1 row**, and the order places.

A sentence says why, on the cart page, at the checkout, and in the toast when you
press Add to bag. A basket with no set in it costs **zero extra queries**.

### ▲ THE CART'S SET ROW WAS CLIPPING ITS OWN NAME AND STEPPER

Not the controls — the layout. The squeezed cart gives every row a fixed height
with `overflow:hidden`, and a set row is taller than an ordinary one, so it was
cut off equally at both ends. And the tiny "what's inside" popup was being clipped
by the items list, which is why it looked like it was not opening.

Both fixed. **And the set-row controls now apply to set rows only** — they were
moving every row on the cart page, which is what you reported. Two independent
sets of controls now, and neither can reach the other's rows:

- **Appearance → Cart page → Product rows** — ordinary rows
- **Appearance → Cart page → Set rows** — set rows only

Thirteen sliders on the Set screen that were drawn but wired to nothing now work.

---

### THE PRODUCT CARD YOU SENT, ON THE WHOLE SHOP

**This is a shipped default change and it is the one thing in this package that
moves the shop on its own** — you asked for it in as many words. The card matches
your screenshot: bordered white card, square photograph, brand line, two-line name,
rating row, struck price with the sale price in pink, and a full-width uppercase
ADD TO CART with the heart beside it.

It is on **every grid on the site except the cart and the checkout** — home, shop,
category, brand, search, product page. Every card in a row is the same height
whatever the name length: measured, all twelve tiles 454px at 1280 and 392px at
390, including a product called *Toner* and one called *Ultra Hydrating Ceramide
Barrier Repair Night Cream With Panthenol and Squalane 100ml*.

Three other treatments are one setting away — **Appearance → Product grids → Card
style**: Compact (tighter, no outline), Row (price beside the button) and Airy
(more air, outlined button).

### A PICTURE SLIDER, AS A SECOND BANNER TYPE

Pictures only, one at a time, with arrows and bars — the banner type you asked
for. **Appearance → Banners → (the set) → Kind: Slider.**

It stops for real: autoplay pauses while the pointer is over it, while anything in
it has keyboard focus, under reduced-motion, while the tab is hidden, and when the
pause button is pressed. It works with no JavaScript at all (it is a swipeable
rail), and it does not fight a downward swipe.

Four looks to choose from; you have not picked one yet, so it ships on **On the
picture**, which changes nothing until you add a slider.

### A REUSABLE PRODUCT GRID SECTION

What you asked for: *"prepare a proper grid section with all controls and it can
be used anywhere, and can edit that specific grid section"*. **Appearance → Grid
sections.** Build one, name it, choose its products, its heading, its card style
and its column counts, publish it, and order it against the other homepage
sections. Use it as many times as you like with different products.

Two presets to start from: **Big savings bundles** and **BEST SELLERS**. Nothing
appears on the homepage until you build one and publish it.

### THE FLAG STRIP

A thin bar above the header: **UAE flag · "UAE's Authentic K-Beauty Store" ·
Korea flag**. On for phones, **off for desktop**, exactly as you asked. It ships
**off** — turn it on at **Appearance → Header → Flag strip**. Every colour,
the height, the wording and both flags are controls.

### THE PLACE ORDER BUTTON NOW SHOWS YOU WHAT IS HAPPENING

Exactly what you asked for. Press it and the page freezes behind a blur with a
filling ring and *"Placing your order…"*; when the shop answers you get a tick,
and then your order-received page. **Press to tick, measured on a real order:
160ms on a phone, 100ms on a laptop.**

**Tabby and Tamara**, as you asked me to work out: they get *"Taking you to
Tamara…"* on the way out, and the tick is waiting on the way back — **but only
once the shop has actually confirmed the payment.** A payment that has not been
confirmed never gets a tick. If the provider has not answered yet, you get an
honest *"confirming your payment"* card that gives up by itself rather than
spinning for ever.

**COD, card, Apple Pay and Google Pay** all behave the way you asked. The one
deliberate exception: while the Apple Pay or Google Pay **sheet** is up there is
no overlay, because that sheet is the browser's own and we cannot layer on it —
the overlay goes up the moment the sheet is finished and there is a real wait.

Every way it can go wrong takes the overlay back down and says what happened, in
your shop's language: a refusal shows the gateway's own sentence; a page that has
been open too long says **nothing has been charged**; a dropped connection says
so. And after 45 seconds with no answer at all it says your order **may already
have been placed** and to check your email — it does not invite you to press
again, because the request had already gone.

Pressing twice cannot place two orders: the button is disabled in the same tick
as the press, before anything leaves the browser.

**There is no setting for this**, and that is deliberate — you asked for the
behaviour, not for a switch.

> **One thing I did not fix, and you should know about it:** a shopper who
> abandons at Tabby or Tamara comes back to an **empty basket**. That is not new
> — the basket is marked converted when the order is written — but nothing puts
> it back. Undoing it means releasing the stock and the coupon, which is the same
> code another lane is in this round, so it has been named precisely and handed
> on rather than half-done. They land on the basket with the real reason and the
> provider's name on it.

### A COLOUR WASH FOR THE WHOLE SITE — TO LOOK AT FIRST

You asked to see it before deciding, so **the preview is the shop itself**:
**Appearance → Page background** opens on a Preview tab that frames your real
home page, shop, a real product, the cart and the journal with the wash on them,
at phone and desktop width. Four treatments to look at: **Cream drift**,
**Blossom**, **Mint morning** and **Cool header**.

It ships **off**, and the screen writes nothing until you press something.

Reading stays at least as easy as it is today — every one of the **15,876**
colours the four treatments can reach was checked against your text colours, and
every contrast figure comes out **at or above** its present value. One candidate
palette was thrown away for failing that, not shipped.

It costs no layout work and no JavaScript at all, and under a phone's
"reduce motion" setting it does not move at all.

> **Two things found on the way, about the background you have now:**
> your shop is **not white** — the home page and cart are pink (`#FDEFF3` under a
> botanical pattern) while **/shop/ and product pages are plain white**, because
> two stylesheets overrule the first. Switching the wash on removes that
> inconsistency. And five pages — the journal, an article, the review wall, the
> skin quiz and the app page — are built as standalone documents, so **your brand
> colour and site width have never reached them either.** Reported, not fixed.

---

### THE ADMIN SIDEBAR STOPS MAKING YOU WAIT

Opening the console, the sidebar rows were arriving at **98.9%** of a 3.4 MB
document — so on a real connection there were seconds where the menu was there and
half of it did nothing. The twenty-one rows that arrived last are now written into
the page by the server, and the dashboard asks for its numbers at byte 189,561
instead of byte 1,337,459.

**Measured on a throttled load: a usable sidebar at 16.0s → 3.5s**, with no
half-built state in between. A row clicked during the load no longer strands the
console.

### PAGE SPEED: THE FONTS WERE THE PROBLEM

Your PageSpeed reports named a **4,369 ms critical path**, and all of it was the
two Google font origins. Poppins and Cairo are served by your own shop now.

|  | before | after |
|---|---:|---:|
| Mobile | 76 | **86** |
| Desktop | 88 | **99** |

Banner pictures are also delivered at the size the frame actually asks for, on
**both** banner types. Measured on a 1600×900 banner picture: a phone downloads
**43 KB where it used to download 113 KB**, a 61% saving. That saving is a phone
saving and is stated that way — above 800 pixels wide there is no smaller copy to
choose, so a laptop correctly takes the original.

Accessibility is stuck at 96 on 108 contrast failures. Fixing those means
darkening the brand pink across the shop, which is your call and not mine.

> **Still yours to do on the server** (nothing in a package can do these):
> cache headers and HSTS, and registering the domain with Apple Pay in Stripe.
> `docs/PERF-PAGESPEED.md §5` is the walkthrough.

### A CANCELLED ORDER CAN FINALLY RELEASE THE BUYER'S HELD MONEY

**Orders → (the order) → Items → Release the hold.** Until now nothing in this
shop could: the endpoint was live and had no button, so a cancelled Tabby or
Tamara order left the buyer's payment plan alive at the provider for up to 180
days.

### GATEWAY WEBHOOKS HAVE A SCREEN

**Store → Payments → Tamara → Webhook & limits → Register the webhook.** The
Tamara and Tabby admin endpoints existed and had no caller, so an order that
Tamara approved after the shopper closed the tab sat at *pending* for ever,
holding its stock and its coupon.

---

### WHAT IS WAITING ON YOU

Three things are built, photographed and **switched off**, waiting for a letter:

1. **The product page** — five whole designs, five mobile and five desktop.
   **Catalog → Product page → Design previews.**
2. **The slider's look** — four, all the same markup.
   **Appearance → Banners → (the set) → Look.**
3. **The site background** — four treatments, previewed on your own pages.
   **Appearance → Page background.**

Pick one of each and they ship in the next package.

### WHAT MOVED, AND WHAT DID NOT

The product card is the **only** shipped default that changes. Everything else in
this package is either a fix to something that was broken, a new screen with
nothing on it yet, or a setting at the value the page already had — so applying it
moves nothing until you move a slider.

---

## 2.60.324
**The Set screen is rebuilt, Stripe finally speaks your shop's language, and two
admin buttons that did nothing now work.** Apply after .323.

### APPEARANCE → SET IS NO LONGER ONE LONG PAGE

Controls on the left in eight named sections, **the preview pinned on the right**,
and it moves **as you drag** — not on save. Measured on the real screen:

| | before | after |
|---|---:|---:|
| laptop, 1280 | 6,747px tall | **2,405px** |
| wide, 1680 | 5,764px | **2,131px** |
| phone, 390 | 13,534px | **4,144px** |

Controls on screen at once: **132 → 25**. On a narrow screen it stacks to one
column and the preview goes first.

Most controls update the preview with no request at all, because the shop is
driven by the same values the preview is. Two sliders dragged through their full
range: **zero requests**.

**The cart preview draws a set row next to an ordinary row**, so you can watch
the set row move while the ordinary one stays put.

### ▲ TWO THINGS ABOUT THAT SCREEN THAT WERE QUIETLY WRONG

**The width buttons were lying.** "Desktop · 1280" inside a narrow preview column
was resolving at about 430px and drawing the **phone** layout — so sizing desktop
was sizing against mobile rules.

**The preview had no text direction**, so the Arabic rendering could never be
previewed. There is an English / العربية pair on it now.

### ▲ STRIPE WAS NEVER TOLD WHAT LANGUAGE YOUR SHOP IS IN

Both the card fields and the Apple Pay / Google Pay buttons followed the
**shopper's browser** language, not your shop's. An Arabic customer read four
translated Arabic sentences from you wrapped around a Stripe decline message in
English — at the moment their payment had just failed.

Now set from the shop's own language, on every Stripe object, mapped onto a
language Stripe publishes rather than passed raw.

### ▲ YOUR PRINTED INVOICE WOULD HAVE PRINTED THE BARCODE BACKWARDS

The invoice takes its direction from the language but sat on a left-to-right
stylesheet, so the day you switch Arabic on it would have rendered half mirrored.
The barcode was the dangerous part: it is laid out as a row, and a row follows the
document's direction — **an Arabic invoice would have printed the bars in reverse
order and no scanner would have read it.** Fixed and verified by actually
printing the sheets, not by reading the code.

The free-delivery bar's glow was also on the wrong end in Arabic — measured 149px
away from the bar at half progress, 332px at full.

### TWO ADMIN BUTTONS THAT DID NOTHING

**Orders → Move to trash…** and **Store → Import → Stop**. Both were drawn, both
took a click, neither was connected to anything. Their neighbours in the same bar
were all connected; these two were missed. The code behind both already existed.

### THE SET ROW CONTROLS REACH SET ROWS ONLY

You caught this one: `Appearance → Set` was moving the padding of **every** row in
the basket. Now a set's row and no other row, and the controls are relabelled to
say so.

### THE TWO PRICES ON A DISCOUNTED ROW

Cut price grey and small, charged price no longer underlined, space between them —
on the checkout's Browsed tab and in Quick view.

### ALSO

New checks that enumerate rather than name: every route file mounted exactly once,
every admin partial included once, every admin address resolving to a real route,
every button connected to something. That is what found the two dead buttons, and
it is what stops the next one shipping.

### FILES

The Set appearance screen and its preview, `App\Services\SetAppearanceLiveMap`
(new), the Stripe locale support and both checkout partials, the invoice and cart
stylesheets, `admin/app.blade.php`, the import checkpoint, and one cache-clearing
migration.

## 2.60.323
**Two screens that never worked, an email that was laid out backwards, and
thirteen sliders that did nothing.** Apply after .322.

### ▲ "WHAT HAS BEEN IMPORTED" HAS NEVER ONCE OPENED

There is a link in your admin, on the Import screen, to the record of what each
import run did. **Clicking it gives a 404, and always has.** The screen was
built, the code behind it was written, and the single line that mounts its
three addresses was never added — no version of this shop has ever had it.

Fixed. `Store → Store Import / Export` now opens it, and the CSV download works.

### ▲ ARABIC ORDER EMAILS WERE LAID OUT LEFT-TO-RIGHT

Every order confirmation sent to an Arabic customer was **written in Arabic and
laid out in English direction**, in every mail client. The email template has no
`<html>` element by design, so the place the rest of the shop declares its text
direction simply did not exist there.

Fixed with a direction *attribute* rather than a stylesheet rule, because
Outlook renders mail through Word and ignores much of the CSS. English mail is
byte-identical.

### THIRTEEN SET CONTROLS THAT SAVED AND DID NOTHING

`Appearance → Set` shipped last package with 157 controls. **Thirteen of them
moved nothing.** The box's new drawing was written as fixed numbers on top of
the rules that read your sliders, and the later rule wins — so photo size,
corner radius, row padding, the gap, the hairline switch and the phone line
spacing all saved correctly and were then ignored by the page.

Every rule now reads its control. **157 → 198 controls, 13 cards**, with two new
ones: `Appearance → Set → Desktop → Set list — the panel and the hang` and its
Mobile twin. The overhang cannot be flattened by any combination of sliders.

### ▲ AND THE SET BOX WAS BROKEN ON EVERY ARABIC SET PAGE

The panel's padding was written in a form whose last value means *left* in every
language, while the hanging photographs and the footing use direction-aware
ones. In English they agreed; in Arabic they did not — **the footing's rule and
all three money figures sat outside the pink panel**, and the photographs hung
16px instead of 10.

### THE TWO PRICES ON A DISCOUNTED ROW

*"the cut price should be grey and small, and actual price don't need to be
underline etc. and give little bit spacing between both prices"*

Done — on the checkout's **Browsed** tab where you saw it, and in **Quick view**,
which had the same three faults hidden behind a rule that matched nothing.

### ALSO

The Arabic **routine pages** had a reset link on the wrong end of its row. Five
missing translations, four of them the wallet failure messages — what a shopper
reads when a payment fails, *"nothing has been charged, your basket is safe"* —
which an Arabic shopper was being shown in English at exactly that moment.

And the import reconciliation sentence no longer names files that are not in the
export you just ran. On cutover night, when you run a small delta after a full
import, it would have told you a field was skipped from a file that was not even
in it.

### FILES

`routes/web.php` (the import-history line), the email layout and its direction,
`App\Services\SetAppearance` and the set panel's rules, the browsed and
quick-view price styling with the rebuilt stylesheets, `ImportChain`, the Arabic
strings, and one cache-clearing migration.

## 2.60.322
**A declined payment no longer empties the shopper's bag.** Apply this one.

### WHAT WAS WRONG, AND FOR HOW LONG

Two addresses your checkout has been reporting to since 17 September did not
exist. The file that declares them shipped with its wiring instructions written
in its own header, and the one line that mounts it was never added — so
`POST /checkout/card/paid` and `POST /checkout/card/abandon` have answered
**405, no such route** for twelve days, on the **typed card form** as much as on
the new wallets.

**No money was ever at risk.** Your Stripe webhook banks every payment and has
been doing that job alone, unnoticed, the whole time. Every successful payment
completed correctly, which is exactly why nobody saw this.

**What broke is what happens when a payment FAILS.** The browser reports an
abandoned payment to `/checkout/card/abandon`, inside a `try/catch` that
silently swallowed the 405. So:

- the basket was never put back to **active** — the shopper was told the payment
  failed and handed an **empty bag**, with nothing to retry;
- the stock was never returned to the shelf — the units stayed held against an
  order that had been given up on;
- the payment intent was never cancelled — a **confirmable** intent stayed live
  at Stripe.

### HOW IT WAS FOUND

By writing tests for the failing payment rather than the successful one: they
called those two endpoints expecting 200 and got 405. Nothing a working checkout
does ever touches that path, which is how it hid for twelve days.

### ALSO IN THIS PACKAGE

**The wallet money path now has tests, where it had none.** Fifteen of them: the
payment intent is re-read **at Stripe** before an order is marked paid and the
figure banked is Stripe's, never the browser's; a mismatched amount is refused
*and recorded*; a double tap makes **one** order and **one** intent; a declined
wallet cancels the intent **before** the stock goes back; and a refund on a
wallet-funded order goes against the payment intent, which is the only
identifier a wallet payment and a card payment share.

**Your Apple Pay setup steps**, with the exact SSH commands for your server's
layout, are in `docs/WALLETS-APPLE-GOOGLE-PAY.md`.

### FILES

`routes/web.php` (one require line), `tests/Feature/WalletMoneyPathTest.php`
(new), the wallet documentation, and the Arabic checkout screenshots.

## 2.60.321
**Apple Pay and Google Pay actually take money now** — and a lot else. This is a
big one: 48 commits and seven lanes' work in a single package.

### ▲ APPLE PAY AND GOOGLE PAY WERE DECORATION UNTIL THIS PACKAGE

The badges were in your footer, your cart and your product pages, and the shop
**could not take either payment**. There was no Apple Pay and no Google Pay
anywhere in the payment code; the one line that mattered told Stripe *cards
only*. So a shopper saw the logos and had no way to use them.

They are now real payments on your existing Stripe setup — the same intent, the
same capture, the same refund, the same ledger. The button draws the Apple Pay
sheet on Safari, the Google Pay sheet on Chrome, and **nothing at all** on a
browser that can do neither, so nobody is ever shown a button that cannot work.

**WHAT YOU HAVE TO DO — Apple Pay needs two steps from you.** Google Pay needs
neither, only HTTPS and your live keys.

1. In the **Stripe dashboard**, register `extrabeauty.ae` for Apple Pay
   (Settings → Payment methods → Apple Pay → Add a new domain).
2. Apple then checks a file on your site. The route that serves it ships in this
   package and answers **404 until Stripe holds the document** — that is correct
   and expected, not a fault.

Until you do both, the Apple Pay sheet will not appear. Google Pay is unaffected.

### APPEARANCE → SET → DESKTOP / MOBILE

*"I need the full controls of everything like spacing, fonts, elements turn on
off etc etc. every single details. for mobile and desktop both separate tabs."*

**157 controls**, in two tabs, with a Save bar that says how many changes are
waiting and a Discard that puts them back. Every dimension has its own value per
breakpoint — the phone's never falls back to the laptop's, because the shipped
sheet already differs between the two. Everything that is not a measurement —
the switches, the weights, the colours — is shared and appears once.

**Nothing moves until you move a slider.** Every control ships at the value your
shop already renders.

### THE "WHAT IS IN THIS SET" BOX IS REDRAWN

*"want nice light background box type and inside a squeezed products list"* —
and, of the three drawings, *"ok the hanging photos style is fine."*

A blush panel with **no row rules at all** and the photographs pulled OUT of it:
each square hangs past the panel's edge, white-ringed and shadowed, so the list
reads as a column of objects on a field rather than rows in a box.

Squeezed, measured on the shop: the photograph 40 → 34, row padding 6 → 3, row
height **52.8 → 42.0** and an eight-member block **364 → 328** on desktop. On a
phone the row's height is set by the *words*, not the photograph — half your
product names wrap at that width — so the phone squeeze comes from line spacing
instead, and the panel's side padding is deliberately narrow because a wider one
flipped two rows onto a second line and made the block **taller** than the list
it was squeezing.

On Arabic the photographs hang off the right-hand edge, from the same rule.

### CATALOG → PRODUCT TABS → GLOBAL TABS → WHERE IT SHOWS

A global tab no longer has to be on every product. Point it at **specific
products, whole categories, brands, every set, or everything** — and a product
matching several rules shows each tab once.

**Every tab you have already written stays on every product** until you narrow
it yourself.

### A SET NOW SORTS AT THE PRICE IT CHARGES

Sorting, price filtering and the price bands treated a set as its raw price
rather than the price on its tile, so a set could sort into the wrong band or
drop out of a filter it belonged in. Fixed on both engines.

### THE PRODUCT PAGE

The main image is **square in any case**, and the gallery thumbnail strip is
there on a set exactly as on any other product — it always was; the earlier
previews were shot on a test set carrying a single image, which is why the strip
appeared to be missing.

Three proposed page layouts were built, looked at and **deleted** rather than
shipped: read side by side they were not three choices.

### ALSO

Seven backlog items, two newsletter boxes that answered nobody, a dropped CSS
class the census mis-reported, a column offered on a screen that could not use
it, and the homepage watch list unblocked.

### FILES

`App\Services\SetAppearance` and the Appearance → Set screen, its route file and
capability; the Apple Pay / Google Pay gateway work on `StripeGateway`, two route
files and `AppleDomainController`; product-tab audience targeting with its
migration; the set price rule in SQL beside the PHP; the set contents panel's
drawing; and two cache-clearing migrations, which is what makes the new routes
answer at all.

## 2.60.320
**Your WordPress navigation menu now imports.** It was the last thing on the
import list still done by hand.

### WHAT YOU DO

1. **Upload the exporter plugin 1.7.0 to WordPress** (Plugins → Add New → Upload
   Plugin). It is attached beside this package.
2. On the export screen, **tick Navigation**.
3. On this shop, **Store → Store Import / Export → Import** now has two more
   steps: **Navigation menus** and **Navigation items**.

### ▲ THE IMPORTED MENU ARRIVES SWITCHED OFF

On purpose. It lands in the picker on **Store → Modules → Mega Menu**, and it
appears on the site only when you tick Desktop / Mobile / Footer under
**⚙ Menu settings**.

That is so a rehearsal import cannot replace your live header as a side effect —
and so that once you *have* switched it on, running the file again to pick up a
change cannot take it back down.

**The menu you typed by hand is never touched.** Imported rows are matched on
their WordPress id, and the rows your own screens create have none — so no part
of this can select, edit or delete them. Nor does a second import duplicate
anything: it matches and updates, it does not add again.

### WHAT HAPPENS TO AN ITEM POINTING AT SOMETHING THAT DID NOT IMPORT

Your menu may point at a WordPress **page**, and this shop imports articles but
not pages. Rather than dropping that row — losing something you wrote, with no
way back after cutover — or keeping it as a link that 404s in the header of
every page, it is **kept and parked**: the row is there with its label, its
position and its place in the tree, and **no address**.

- It is **on the Mega Menu screen**, so nothing is lost.
- It is **not on the shop**, so nothing is broken.
- **It fixes itself the moment you give it an address** — type one on that
  screen and it appears. No re-import, nothing to remember.

Anything parked is listed by name after the import, with what it was pointing at.

### WHAT IMPORTS CLEANLY

Category items land on their full nested address (`/collections/skincare/face-
cleansers/`, not one level short), brand items on `/brands/…`, product items on
`/product/…`, article items on `/blog/…`, and custom links on themselves —
including "open in a new tab".

Items where you never typed a label over WordPress's default come across with the
right words: WordPress stores nothing in that case and lets the theme print the
target's name, so the **exporter** fills it in. Most of a real menu is that shape.

### ALSO FIXED

A test that failed about **one run in five** for reasons nobody had pinned down —
the export screen was being started twice, so it packed twice. Measured 3, 4, 3,
3, 3 before; six runs out of six correct after.

### FILES

`app/Services/Import/Entities/MenuImporter.php` and `MenuItemImporter.php` (new),
`ImportRunner`, `NavigationService`, one migration, and the exporter plugin at
1.7.0.

## 2.60.319
**A big one.** Five rounds of work land together: the video loop, review photos,
your own product tabs, a set's real price everywhere, and twenty admin controls
that had never done anything.

### ▲ THE SET PRICE WAS WRONG IN THE BASKET, AND THE BASKET WAS THE ONE THAT MATTERED

A set priced by a rule showed the right figure on its own page and **charged a
higher one in the basket**. Measured on two sets: page AED 166.50 and AED 145.00,
basket took **AED 180.00 and AED 160.00** — **AED 28.50 too much on two lines**.
Your API feed quoted the wrong figure too.

Fixed. A set now costs one number on the shop grid, its own page, the basket, the
checkout and the feed. Nothing about a placed order moves.

*Still to do, and said plainly:* sorting by "price, low to high" and the price
filter still use the typed figure, so a rule-priced set can sit later in the list
than its real price deserves. It is never sorted **cheaper** than it is.

### THE VIDEO LOOP IS ONE SECOND

You asked for it and it is done — and the measurement says the length was never
the problem:

| | bytes fetched |
|---|---|
| a clip **with** its loop file | **53 KB** |
| the same clip **without** one | **3,232 KB** |

**60× — for the same one second of picture.** Every clip on your shop is the
second row. So the thing that will actually fix the loading you feel is the loop
files getting cut, not the length. The one-second cut does make each file 3.3×
smaller once they exist (101 KB → 31 KB).

Already-cut 2.5-second loops are **not** re-cut automatically — they work, they
are just longer than you asked for. `php artisan ugc:cut-covers --recut-teasers`
does it once, by hand.

**A tile now has three honest states:** a loader while it loads, the play button
once it is ready, neither while it plays. A clip that fails gets its play button
back rather than leaving you watching a spinner for ever. The loader is the same
frosted disc the play button uses, with a turning rim — not a grey shimmer, which
would be a smear over a photograph.

### REVIEW PHOTOGRAPHS: 3,308 KB → 39 KB

Your product pages were serving **full phone-camera photographs** for the little
review thumbnails. The batch that makes phone-sized copies had never looked at
review photos at all — it reported "0 remaining" over a wall of originals.

**The admin got faster too:** Media Library 7,798 KB → **1,219 KB**.

**Gallery thumbnails are square now**, cropped rather than letterboxed, so a tall
photo and a wide one give the same tile.

▲ **After applying: Content → Media Library → "Make phone-sized copies."** The
backlog will be bigger than before, because it is finally honest.

### YOUR OWN PRODUCT TABS

**Catalog → Product tabs** — new.

- **Global tabs** appear on every product: *Shipping & returns*, *How we
  authenticate*, whatever you like.
- **A tab for one product only.**
- **Hide a tab on one product** — a gift set has no ingredients list, a device
  needs no patch-test advice.
- **Re-word a tab on one product** and keep the shop's version everywhere else.
- Everything sorts on **one order**, so your tab can sit before Ingredients or
  after How to use.
- Arabic title and body for each, like every other field.

Description, Ingredients and How to use stay where they are — each product still
writes those in the product editor — but they now take the same hide, re-word and
re-order as any tab you add.

**Nothing appears until you write a tab.**

### THE PRODUCT PAGE

- **The set's contents list is squeezed** — no per-product prices, smaller
  pictures, tighter rows. Saves **84–90px** with three products, **133–143px**
  with twelve, and brings Add to cart up the page by as much again.
- **The short description now sits below the list** on a set.
- **The gap between the short description and the options is fixed.** That was a
  real bug: a rule in one stylesheet had been cancelling a rule in another since
  before this project started, so the gap was **zero pixels** on every product
  page. Exactly the screenshot you sent.

### TWENTY CONTROLS THAT DID NOTHING

**Appearance → Product styles** offered twenty settings that had never moved one
pixel of your shop. Fifteen are now wired and **five are removed**, because each
was a second answer to a question another screen already owns.

Seven menu-icon animations under **Appearance → Mobile menu** went the same way —
**Appearance → Header → Icon** is the real control and always was.

▲ **A value you had stored against one of those dead controls is cleared**, on
purpose. It was never a preference — it was a slider you moved, watched do
nothing, and forgot. Leaving it would have applied it to a live page the moment
this package landed.

### AND WHEN AN UPLOAD FAILS, IT NOW SAYS WHY

Uploading to the Media Library used to say *"check folder permissions"* whatever
went wrong — a full disk, a read-only folder, a misconfigured temp directory, all
six words. Worse, a real failure produced a bare **"Server Error"**. It now names
the actual fault and quotes the server's own words.

Six admin screens also could not tell you that a **route cache** was the problem
— the one fault that follows a package needing its cache cleared.

## 2.60.318
**Your two decisions, both applied.** Both change how the shop behaves, so read
the two ▲ lines before you apply this.

### 1. SELLING A SET NOW TAKES ITS PRODUCTS OFF THE SHELF

You said: *"if the product sold inside set or individual, the stock should be
minus in any case."*

**Catalog → Sets → Stock** now ships set to **"Also take each product in the box
off its own stock"**. Sell a box containing one Heartleaf Toner and the toner's
own stock drops by one, exactly as if somebody had bought it on its own.

A basket holding *both* the box and a loose toner takes that shelf down **once,
for the total** — not twice.

**▲ The surprise to expect.** Nothing is counted backwards; past orders are not
re-applied. But the **next** order for a set whose products are already low will
be **refused** where it would have gone through before. That is correct — the box
cannot be packed — and it is also the thing that will look like a fault the first
time it happens, so: it is not a fault.

If you ever want the old behaviour back, that screen still offers it, and once
you choose it nothing will ever put this back over the top of your choice.

### 2. A SET NO LONGER GETS THE BULK DISCOUNT

You said: *"no there's no bulk discount for sets products."*

The *2-pack / 3-pack* offers already stopped appearing on a set's page. The
**basket** was still quietly applying them. It does not now — a set is charged
its own price however many are bought.

**▲ What this does to a basket somebody already has.** If a customer is sitting
with three of a set in their basket right now, the price goes **up** to the set's
own price the next time that line is touched. That is the price your set's page
has been showing since the offers were removed, so the basket now agrees with
what the customer was told — but it is a price rising, which is worth knowing
before it happens.

### FILES

`app/Services/StockSetRule.php`, `app/Services/CartService.php`, one migration,
and three test files.

## 2.60.317
**Your two clips were not playing because the four demo clips were using up all
four slots.** Found, proved in a browser, fixed.

### WHAT WAS HAPPENING

The rail plays **four clips at once** — that is the setting *Most clips moving at
once*, and four is sensible. But it chose which four **in the order the clips
were added**, and the demo placeholders were added before you uploaded anything.

So: demos first, all four slots gone, and your real clips sat showing a still
picture with a play button. Exactly the screenshot you sent.

Measured on a rail built to match yours, playback sampled every second:

```
BEFORE   the four demos          playing
         your two clips          NOT playing
AFTER    two demos               playing
         YOUR TWO CLIPS          playing, looping 2.33 → 0.68 → 1.69
```

**A second fault was hiding the first.** The play button was hidden the moment a
clip was *mounted*, not when it actually started — so a tile that never played
could still look like it had. That is why this was checked twice before and
reported as working.

The rail now picks its four by **your real clips first**, then whichever tiles are
most on screen. A clip whose file fails to load also **gives its slot back** —
before, it held one for as long as the page was open.

### ▲ READ THIS SCREEN FIRST AFTER APPLYING

**Content → Shoppable video → Appearance → Motion** now tells you, clip by clip,
what your shop will actually do:

> 1 Glass skin (Demo) — will move
> 2 Salon day (Demo) — will move
> 3 SPF that never stings (Demo) — waiting for slot
> 4 Double cleanse (Demo) — waiting for slot
> 5 anua mist spray — **will move**
> 6 bright underarms — **will move**

It also says how many of the moving clips are demo footage and where to remove
them, names the setting that caps it, and names the two things a *shopper's own
phone* can switch off that no shop can override.

**If that panel lists your two clips as "will move", this is finished.** And if
you remove the demo content, all four slots go to your own clips.

### THE THINGS YOU ASKED FOR IN THE POPUP

- **Previous and next arrows.** Outside the video on a desktop, on its edge on a
  phone where there is no outside. Arrow keys work. Mirrored in Arabic. At the
  first and last clip the arrow is **visibly greyed rather than silently wrapping
  round** — you arranged the order, so you should be able to tell when you have
  reached the end.
- **A square product thumbnail.** It was 138×46 — a wide letterbox. It is 46×46,
  which gives the product name **91 more pixels**: *"Fresh That Lasts Deodorant"*
  now fits on one line instead of two.

### A CLIP WHOSE VIDEO HAS VANISHED SAYS SO

Two of your clips point at video files that are no longer on the server. They
used to report *"ffmpeg could not read a poster frame"* — blaming the video
software for a missing file. The clips screen now shows **"Video file is gone"**
on that clip, in red.

### FILES

`resources/views/ugc/assets.blade.php`, `ugc/rail.blade.php`,
`app/Services/Ugc/RailPlayback.php` and `ClipFile.php` (new), `Tile.php`,
`UgcTranscoder`, `CutUgcCovers`, the two UGC admin screens, and one
cache-clearing migration.

## 2.60.316
**One product card, everywhere.** Square pictures, five across on a desktop, two
on a phone, and the filter sidebar folded away until you want it.

### ▲ THIS CHANGES HOW YOUR SHOP LOOKS. READ THIS BIT.

Your shop was drawing **five different product cards** in five places — the shop
listing, category pages, the homepage rails, the brand page and *You may also
like* — each with its own column count and its own styling. That is why the
category page never matched the Super Sale page however often it was asked to.

They are one card now. It is the card you picked.

**Three defaults move, and all three are things you asked for:**

1. **Five columns on a desktop**, two on a phone. It was four.
2. **Square pictures.** A tall bottle and a wide box now produce the same square
   tile, so every card in a row lines up.
3. **The filter sidebar starts hidden** on the shop and on every category page.
   A **Show filters** button sits where the sidebar was; open it once and it
   stays open as you move between pages.

The 2/3/4 column switcher could not even reach the new default. It offers
**2/3/4/5** now.

### THE SMALLER THINGS YOU ASKED FOR

- **A long product name no longer makes its card taller.** Measured: a one-word
  name and a 90-character name sit in the same row at **366px each** on a
  desktop and **302px each** on a phone.
- **No rating row on a product nobody has reviewed.** It used to draw five empty
  stars and "(0)". The space is still held, so the heights still match.

### ALSO FIXED WHILE IN THERE

**Appearance → Product styles** shows a sample card so you can see a skin before
choosing it. That sample had drifted and was showing you a card shape your shop
no longer draws — a slightly non-square picture and a different name layout. It
matches the real card again.

### WHAT DID NOT CHANGE

The **brand directory** still has its own tiles — those list brands, not
products: no price, no rating, nothing to add to a basket. Converting them would
have been change for its own sake.

Arabic was checked by looking at it, not just by measuring: the card mirrors
correctly, badges and heart included.

### FILES

`resources/views/components/product-card.blade.php`,
`components/product-grid.blade.php`, `partials/home/grid.blade.php`,
`store/shop.blade.php`, `store/product.blade.php`, `resources/css/kbb/*`,
`admin/app.blade.php`, and the rebuilt asset bundle.

## 2.60.315
**▲ THE 2.5-SECOND LOOPS WILL NOW ACTUALLY GET CUT.** This is the fix for the
clips that never got their loop — and it is our bug, not your server's.

### WHAT WAS WRONG

The scheduled job that cuts covers and loops picked up **only clips with no
cover**. But the cover and the loop are two separate operations, and the loop is
the one that fails — it encodes 2.5 seconds of video, where the cover just grabs
a single frame. So:

- **Minute 1** — no cover, so the clip is picked up. Cover made. Loop fails.
- **Minute 2** — the clip has a cover now, so it is skipped.
- **Every minute after that** — skipped.

**One attempt at the loop, ever**, from a schedule that runs sixty times an hour
for exactly that purpose.

Seen on your own shop: a cover written at 20:34, no loop file beside it, and
nothing new in the half hour after that.

The job now picks up a clip that is missing **either** file. A clip that has both
is still skipped, so it is still safe to run over and over.

### ▲ WHAT YOU DO

**Nothing but apply this.** Within a minute of the next scheduled run, every clip
you have already uploaded gets its 2.5-second loop. You do not re-upload
anything.

Without the loop file the rail still plays — it loops the *whole* video instead.
That works, and it costs a customer on a phone about **11 MB per clip** where the
loop file is **97 KB**. With four clips playing at once, that is the difference
between half a megabyte and forty.

### AND WHEN IT STILL CANNOT

Until now, every failure produced the same sentence — *"ffmpeg could not read a
poster frame out of that clip"* — whether the real cause was a folder it could not
write to, a missing codec, a time limit or a file that had been deleted. The
program doing the work prints a precise reason every time, and the shop was
throwing it away.

It keeps it now. Run this over SSH and it tells you the actual reason:

```
php artisan ugc:cut-covers --limit=5 -v
```

That turns a support conversation into one line.

### WHAT THIS WAS NOT

Checked on your server rather than assumed, so it never has to be checked again:
ffmpeg is a full build with H.264; your clip files are intact and decode without
a single error; and the loop command itself, run by hand, produced a 107 KB file
in under a second.

### FILES

`app/Console/Commands/CutUgcCovers.php`, `app/Services/UgcTranscoder.php`, and
two test files.

## 2.60.314
**A set no longer offers "buy 3 and save" — and the box contents take that spot
instead.** Exactly as you marked up.

### WHAT MOVED

On a set's page, the **1 unit / 2-pack bundle / 3-pack bundle** block is gone,
and **What is in this set** now sits in that space — in the buy column, right
above *In stock · ready to ship* and the Add to cart button. It is no longer a
section you have to scroll down to find.

It is a **list**, one product per row: picture, brand, name, how many, price.
Underneath: *Bought separately AED 325 · Set price AED 269 · You save AED 56*.

A member that is not published is still listed but not clickable, so the page
never sends a customer to a page that is not there. A set with no price set says
nothing about saving rather than claiming a saving of zero.

**Nothing changes on an ordinary product.** It keeps its bundle offers exactly as
before — measured to the pixel, the same page in every respect.

### ON A PHONE

Designed for it rather than left to reflow. The picture shrinks, the row tightens,
and the quantity and price share one line instead of stacking — which in the
narrowest column on the shop saves 16px on every row.

**A box with more than six products shows five and folds the rest** behind *Show
all*. Twelve rows would put 700px between the price and the Add to cart button,
which on a phone means the button is off the screen. Folding brings it back up by
**524px**. The count and the totals always cover every product, folded or not.

### WHERE IT IS IN THE ADMIN

**Catalog → Product editor → Brand** gains one line of help on a set: a box
usually holds more than one brand, so *No brand* is the normal answer and the set
page simply does not print a brand line. Brand was already optional on every
product type — nothing changed, it just now says so.

**No new setting and no new screen.**

### ▲ ONE THING FOR YOU TO DECIDE

**A set bought at quantity 3 still gets the old bulk discount in the basket.**
The shop no longer *advertises* a bundle on a set, but the basket still *applies*
one. Changing that alters what a customer in mid-checkout is charged, so it is
not something to switch on quietly with a package. Say the word and it goes in
its own patch with a before and after.

### FILES

`app/Services/BundleService.php`, `resources/views/store/product.blade.php`,
`resources/views/partials/set-contents-panel.blade.php`,
`resources/views/partials/set-contents-row.blade.php` (new),
`app/Services/Translation/InterfaceStrings.php`,
`admin/partials/product-editor-screen.blade.php`, and one cache-clearing
migration.

## 2.60.313
**The shoppable-video popup is now the video.** No bands, product boxes on the
picture, credit on the picture. And the clips screen finally tells you the truth
about why your covers are not being cut — **which is not what it has been
telling you.**

### ▲ THE 2.5-SECOND CLIP: YOUR SERVER HAS ffmpeg. PHP IS NOT ALLOWED TO RUN IT.

The screen has been showing you *"No ffmpeg here — you choose the cover"*. **That
is wrong, and it has been wrong on the first screen of this feature since it
shipped.** `ffmpeg` is installed on your server. What is blocked is PHP's
permission to start any program at all (`proc_open` is switched off in your
PHP pool, which is a normal hardening default on managed hosting).

So you have been told to install something you already have.

**The fix is one line, and it is yours to paste — no support round trip.**

> Cloudways → **Application Settings → Cron Job Management → Add New Cron →
> Advanced**, and add:
>
> ```
> * * * * * cd /path/to/your/application && php artisan schedule:run >> /dev/null 2>&1
> ```

The command line does not have that restriction, so from a cron job the shop can
run ffmpeg perfectly well. Within a minute of adding it, **every clip you have
already uploaded** gets its cover and its 2.5-second loop — you do not re-upload
anything.

The clips screen now says exactly this, in place of the old sentence, and only on
a server where the cut genuinely cannot happen.

### THE POPUP

Tap a clip and the **video fills the frame**. Previously the frame was sized to
your phone screen while the picture was fitted inside it, so they were different
rectangles — and everything else is positioned against the *frame*:

- on a phone there was a **75px band above and below**, the creator's name sat up
  on the top band, and **76 of the product row's 163px hung below the video**;
- on a desktop there was a **35px band each side** and the product row overhung
  the picture at both ends.

Now: **no band on any edge**, at any width. The product boxes sit **on the
video, along its bottom edge, to the pixel**. The creator's name sits **on the
video**. Nothing else is in the popup. It works the same in Arabic, mirrored.

Escape closes it, Tab stays inside it, and focus returns to the clip you tapped.

### WHY THERE IS STILL A LOOP FILE AT ALL

We measured dropping it and just looping the first 2.5 seconds of the full
video. On a **slower** connection that costs **10.9 MB per clip** — more than the
whole video — because the loop keeps re-fetching what the phone has thrown away.
The 2.5-second file is **97 KB**. With four clips playing at once, that is the
difference between ~400 KB and ~40 MB of a customer's data for a section they
have not even tapped.

### ▲ TWO THINGS FOR YOU TO DECIDE

1. **The video's own play/pause bar overlaps the product boxes.** It is switched
   on at **Appearance → Shoppable video → Motion → "Show the player's own
   controls"**. Turn it off, or tell us to lift the product row clear of it.
2. **On a phone there is still dimmed space above and below a tall clip.** That
   is the **page showing through**, not a black band — the video itself now
   reaches every edge of its frame. Filling the whole screen would mean cropping
   about 18% off the sides of every clip, which cuts faces off. TikTok and
   Instagram do it the way it is now. Say the word if you want it cropped.

### FILES

`resources/views/ugc/assets.blade.php`, `ugc/rail.blade.php`,
`app/Services/UgcTranscoder.php`, `app/Http/Controllers/Admin/UgcVideoController.php`,
`admin/partials/ugc-library-screen.blade.php`, `docs/SERVER-PROC-OPEN.md`, and one
cache-clearing migration.

## 2.60.312
**▲ READ THIS ONE BEFORE THE IMPORT, NOT AFTER.** Two things your WordPress site
is carrying can only be collected **while WordPress is still running**, and one
of them cannot be recovered once it is switched off.

### 1. OLD PRODUCT AND ARTICLE ADDRESSES

Every time you have renamed a product or an article on WordPress, WordPress
quietly kept the **old address** and has been forwarding it ever since. A product
you renamed in 2021 still opens today — Google holds that address, and customers
have it bookmarked.

You have never noticed this, because WordPress does it silently and for free.

**Switch WordPress off without collecting those addresses and every one of them
becomes a dead page on day one** — and the list only ever existed inside the
database you turned off. It cannot be rebuilt afterwards from anything.

The exporter now collects them and this shop forwards them properly with a
permanent redirect, the kind that moves your Google ranking across rather than
starting again. Measured on a test export: three renamed items, all three
landing on the right page.

### 2. REVIEW PHOTOGRAPHS

The photographs customers attached to their reviews were not being imported at
all. Everything to *show* them has been built for months — the product page
draws them with a "+n" chip, the review wall filters on them — and on an
imported shop there was simply nothing to draw, because the only thing that had
ever written a review photo was somebody uploading to the new site.

Now imported, with each address checked before the shop will display it.

### ▲ WHAT YOU MUST DO

**Re-upload the exporter plugin to WordPress — it is version 1.6.0.** The
plugin zip is attached alongside this package. An export taken with the old
version carries neither the old addresses nor the photographs, and there is no
way to add them later.

### STILL DONE BY HAND: THE MENU

Your WordPress navigation menu is **not** imported — you retype it in
**Appearance → Header → Mega Menu**. That was always true; what changed is that
the export report now says so in those words. It used to file your menu under
*"post types, which are either another file's job or WordPress's own
machinery"* — which told you your header was a cache. It was not true, and you
were reading it on the one screen that is supposed to tell you what is being
left behind.

### AND WHAT IMPORTS CLEANLY

Verified field by field on a test export: barcodes, brands, tags, nested
categories with their images, product variations with their sizes and prices,
which variant each past order sold, customers with their addresses, coupons,
refunds, and the SEO title, description and share image from Yoast. **Money is
exact to the fil at every step** — nothing rounds.

### FILES

`wordpress-plugin/kbb-exporter` (1.6.0), `ReviewImporter`, `RedirectMap`,
`kbb:import-redirects`, `docs/WP-EXPORT-CONTRACT.md`,
`docs/IE-IMPORT-READINESS.md`, `docs/IMPORT-RUNBOOK.md`.

## 2.60.311
**A Set now says what is in the box on every document the shop produces** — and
on both admin screens that list an order's items.

### THE EMAILS AND THE PRINTED SHEETS

A customer who bought a set used to get one anonymous line — *"Glow Set × 1"* —
and no way to see what they had actually bought. The member list now appears on:

- the **order confirmation**, the **status update**, the **new-order alert** and
  the **refund note**, in both the pretty and the plain-text versions;
- the **emailed invoice** — the document a customer keeps, forwards and files,
  and the only one that was still silent;
- the **printed invoice**, the **packing slip** and the **delivery note**;
- the **abandoned-basket reminder**.

**The packing slip matters most.** A set that does not list its members there is
a mis-picked order — whoever packs the box had nothing telling them what goes
in it.

### THE TWO ADMIN SCREENS

**Store → Orders → (an order)** and the **quick-view popup** that opens when you
click an order in the list both show the box contents under the set's line now.
The quick-view one was a second, separate gap: the two screens are built by
different code with differently-named fields, so fixing one did not fix the
other.

**Store → Orders → New Order** shows it on the receipt after you place an order.

### WHAT AN OLD ORDER SHOWS

What the customer actually bought. Every document reads the box that was
**recorded with the order**, not the set as it is today — so an order still
prints correctly after you have changed that set, or deleted it entirely.

The one exception is deliberate: the **abandoned-basket reminder** describes the
basket *as it is now*, because that message is written at the moment it is sent
and chasing somebody about something they have already removed is worse than not
chasing them at all.

### ▲ ONE THING FOR YOU TO DECIDE

The packing slip's **ITEMS** count and its **PICKED** tick are per order line, so
a set is **one tick** even though the box holds several things. The member list
now tells the picker what to put in. Whether each member should earn its own
tick is a change to how your staff work, so it is not something to alter without
asking.

### BEHIND THE SCENES

The admin console is about 20,000 lines of JavaScript and nothing checked that it
parsed. A single missing bracket there is a **blank admin screen** — with the
fix only reachable through the screen that is blank. It is checked now, on every
test run.

### FILES

`emails/order-invoice.blade.php` and its text twin, `emails/cart-recovery.*`,
`app/Services/CartRecovery.php`, `AdminController`, `AdminOrderController`,
`admin/app.blade.php`, `admin/partials/manual-order-screen.blade.php`, plus
`tools/blade-js-check.php` and four test files.

## 2.60.310
**▲ THE SHOP'S ADDRESSES CHANGE.** Category, brand and journal pages move to new
URLs. Every old address forwards to the new one, so nothing you or Google have
bookmarked breaks — but this is the biggest change in this series and it is
worth reading before you apply it.

### THE NEW ADDRESSES

| Was | Is now |
|---|---|
| `/product-category/toners/` | **`/collections/skincare/toners/`** |
| a filter on `/shop/` | **`/brands/` and `/brands/round-lab/`** |
| `/skincare-guide/` | **`/blog/`** |
| an article at the site root | **`/blog/the-article/`** |
| `/product/dokdo-toner/` | **unchanged** |

Plural throughout — `collections`, `brands`, `blog` — which is what Google's own
documentation and every large store spell.

**The product address does not move**, on purpose. It already matches what the
WordPress site serves, so moving it would buy a redirect on every product page
and nothing else.

### NOTHING BREAKS, AND IT IS ONE HOP

Every retired address forwards with a **301** — the permanent kind, which is what
tells Google to move the ranking across rather than treat the new page as a
duplicate. Measured in a real browser, following the chain:

```
/product-category/toners/            301 → /collections/skincare/toners/   1 hop
/toners/                             301 → /collections/skincare/toners/   1 hop
/korean-skincare-brands/round-lab/   301 → /brands/round-lab/              1 hop
/skincare-guide/how-to-layer/        301 → /blog/how-to-layer/             1 hop
```

**One hop, not two.** The old category address goes straight to the full nested
path rather than bouncing through a halfway address — two hops is a real cost on
a phone and Google counts them.

An old address that names nothing — a category you deleted, an article that never
existed — gives a proper **404**, not a redirect to the shop's front page. That
matters: forwarding everything would turn the whole old namespace into an endless
supply of pages that look fine to a crawler and are empty to a reader.

### BRAND PAGES ARE REAL PAGES NOW

A brand used to be a *filter* on the shop listing, which told Google the brand
page did not exist. **`/brands/round-lab/` is a page.** The filterable listing is
still there and is unchanged — it is what the mega menu, the shop's facets and
the brand page's own *Shop all* button use.

### WHAT YOU WILL SEE IN THE ADMIN

Nothing new to set. The screens that *print* an address now print the new one:
**Catalog → Categories** (the path under each row, the slug help, the merge and
delete warnings), **Catalog → Brands** (the address column and warnings),
**Store → Pages** (the Category and Journal rows), **Store → Health** (both
probes), and **Store → SEO & Meta → Row preview**.

The **Mega Menu** URL box used to suggest `/product-category/cleansers/` as the
example of what to type. It suggests `/collections/cleansers/` now — the old one
was quietly teaching you to author menu rows that pay a redirect on every page.

### WHEN THE IMPORT RUNS

Old WordPress addresses that this shop cannot work out for itself — a category
whose flat address is unusual, an article whose slug changed on the way in, a
brand archive the old site served — get a stored forwarding row. Addresses the
shop already forwards by itself do **not** get a row, because a stored row does
not follow a later rename and would go on pointing at a path that has since
become a 404.

### ▲ WHAT TO DO AFTER APPLYING

1. Open **Store → Health** and check the Category and Journal probes are green.
2. In Google Search Console, submit the sitemap again. It lists only the new
   addresses.
3. Expect Search Console to show the old URLs as *"Page with redirect"* for a few
   weeks. That is the correct and healthy state, not an error.

### FILES

`app/Support/UrlScheme.php` (new), `CategoryArchiveController`, `BrandController`,
`PageController`, `Brand::url()`, `CategoryPath`, `RedirectMap`, `Seo`, the
sitemap, the category/brand/pages/health admin screens, and three migrations.

## 2.60.309
**The Set is now a product type, on the product page itself.** You no longer go
to a separate screen to build one.

### WHERE IT IS

**Catalog → Product editor → Basics → Product type.** Pick **"Set — made of
other products"** and one new panel appears: **What is in the box**.

Everything else on that page is the page you already know — the SEO block, the
gallery, the categories, the brand, the images, visibility, sorting and the
Arabic fields. None of it is a second copy; it is the same machinery a simple
product uses, which is exactly why the Set was moved here.

**Catalog → Sets** is still there and is now a **list** — for finding your sets,
and for the one setting that belongs to all of them rather than to any one of
them (below). *New set* and *Edit* both open the product editor.

**Catalog → Products** now has a **Sets** chip beside Featured, and a blue
**Set** pill on each set's row, so you can see what you are looking at without
pressing anything.

### THE PRICE CAN BE A RULE INSTEAD OF A NUMBER

You asked for this: *"if I reduce the price of any product, it should also take
effect on the set price."* It does now.

**How the price is decided** offers three answers:

- **A fixed price** — you type it, it stays.
- **A percentage off the parts total.**
- **An amount off the parts total.**

With either discount, the set's price is **worked out fresh every time it is
read**. Drop a member's price by AED 20 and the set drops by AED 20 with nothing
to press and nothing to re-save.

There is also a **Use this total** button that takes the parts total straight
into the price box, and three tiles that always show you *Bought separately ·
Set price · Saving* in real money as you type.

**An order that has already been placed does not move.** The price is frozen
into the basket and the order when the customer agrees to it, so a set you
reprice tonight does not change what somebody was charged this morning.

### WHAT IS IN THE BOX

Drag the members into the order you want (the ↑ ↓ arrows still work for anyone
who prefers them), set a quantity on each, and search the catalogue to add more.
A set cannot contain another set.

On the shop, the set's own product page gains a section — **The set → What is in
this set** — listing every member with its brand, quantity and price. A member
that is not published is listed but not linked, so the page never sends a
shopper to a page that is not there.

### ▲ ONE SETTING YOU MAY WANT TO CHANGE, AND IT SHIPS AT TODAY'S BEHAVIOUR

**Catalog → Sets → Stock · When a set is sold.** Two answers:

- **Take it off the set's own stock only** — what the shop does today, and what
  this ships as, so applying this package changes nothing.
- **Also take each product in the box off its own stock** — what most shops
  want, and what you should probably switch to once you have thought about it.

It is off because a stock rule that changes itself under a live shop is the kind
of surprise this project does not ship. **This one is yours to decide.**

### MEASURED

A set's page costs 10 database queries with 3 members and **10 with 12** — flat,
however big the box. An ordinary product page is unchanged at 7. Nothing on the
storefront moved for a product that is not a set.

### FILES

`app/Support/SetPricing.php`, `app/Services/StockSetRule.php`,
`app/Http/Controllers/Admin/ProductEditorApiController.php`,
`resources/views/admin/partials/product-editor-screen.blade.php`,
`resources/views/partials/set-contents-panel.blade.php`, two migrations, and the
Sets screen reduced to a list.

## 2.60.308
A web address that came from the old WordPress site is now **checked before the
shop puts it on a page**. Nothing you can see changes.

### WHAT THIS IS ABOUT

Four places took an address straight out of the database and printed it into the
page: a **menu row's link**, a **menu row's highlight colour**, a **review
photograph**, and the **social profiles the shop declares to Google**.

The shop escapes text before printing it, which handles quotes and angle
brackets. It does not handle an address whose *scheme* is the problem —
`javascript:` contains no character an escaper touches, so it arrived in the link
exactly as it was written and the browser would run it on click.

None of these can be typed in by a shopper, and none can be typed in by you: the
Mega Menu screen already rejects a colour that is not a colour, and a review
photograph is uploaded by the shop itself. **They come from the import.** The
WordPress database is not one this shop authored, and this had to land before the
products and menus come across rather than after.

### WHAT CHANGES ON YOUR SHOP

Nothing, on any value your shop can currently hold. A link, a colour, a
photograph and a social profile that are ordinary are printed exactly as before,
byte for byte — that is pinned by a test, because a security fix that quietly
rewrites a working link is a worse outcome than the hole.

What changes is what happens to a bad one after the import:

- a link the browser should not follow points at the **shop's home page**, and
  the menu row is still drawn with its own label;
- a colour that is not a colour draws the row **plain**;
- a review photograph the shop cannot serve is **not drawn**, and the photo count
  on the review matches what you see;
- a social profile that is not a page is **left out** of what the shop tells
  Google about itself.

An imported photograph still sitting on the old host keeps working. This checks
the *scheme*, not the host — blocking the old host would empty the review section
on the day of the import.

### WHERE IT IS IN THE ADMIN

Nowhere new. There is no switch and no setting. **Appearance → Header → Mega
Menu** and **Appearance → Footer → Social links** behave exactly as they did.

### FILES

`app/Support/SafeUrl.php` (new), `app/Services/NavigationService.php`,
`app/Support/Seo.php`, `resources/views/partials/reviews.blade.php`,
`resources/views/partials/footer.blade.php`, plus two test files and the
screenshot tooling.

## 2.60.307
The banner editor now has a **Save button**, a background, button colours and a
choice of where the title sits — and two settings that had no control at all.

### A SAVE BUTTON, AND NOTHING SAVES BEHIND YOUR BACK

Every control used to write the moment you touched it, and re-draw the screen
underneath you. You asked for a Save button so you could make several edits
first, and that is what it is now:

- edits are held until you press **Save**, and the footer counts them ("Save 5
  changes");
- **Discard changes** puts everything back;
- leaving the screen, opening another set or closing the tab **asks first**;
- a failed save **keeps** your edits rather than losing them;
- **the live preview still updates as you type**, from what you have typed —
  nothing is written to the database until you press Save.

Adding, duplicating and deleting a card still act immediately, because those
create and destroy rows, and the screen says so.

### SPEED WAS ALREADY THERE — NOW YOU CAN FIND IT

It existed as a millisecond box among nine other numeric boxes. The controls are
in five named groups now, **Speed is the first one**, the slider runs the way you
expect (**right is faster** — it used to be backwards, because the stored value
is a duration), and it reads *"Medium — 4.0s per card, so 24s for one full loop
of 6 cards."*

### NEW CONTROLS

- **Behind the row** — no background, a colour, or a picture.
- **The button** — its colour, its text colour, and its colour when the mouse is
  over it. Each has a *"Use the shop's own colour"* switch.
- **Where the words sit** — below the picture, or **on** it, with a soft dark
  gradient so the words stay readable over a light photo or a dark one. The card
  is exactly the same size either way.
- **Showing / Hidden per card** — a card can be taken down for a week without
  deleting it and losing the picture, the words and the link. **There was no way
  to do that before.**
- **Order** on each set, which decides the order of the list and the homepage
  picker.

### ▲ ONE THING ON THE SHOP CHANGES, AND ONLY IF YOU HAVE THE BANNER SWITCHED ON

**The card button's label was dark ink on the shop, not white.** A shop-wide rule
outranked the section's own colour, so the white it has declared since the day it
shipped never actually applied — and the admin preview, which does not load that
stylesheet, always showed white. So the screen has been showing you something the
shop was not doing.

It is white now. It had to be fixed rather than frozen, because the new
button-text control reads that same rule and would otherwise have done nothing.

Also fixed: the arrows were drawn over a row that was scrolling by itself, which
the screen has always said they would not be; and the admin preview drew the
wrong pink, so a colour was being chosen against the wrong background.

### Files

`app/Services/Banners.php`, `app/Models/BannerSet.php`,
`app/Http/Controllers/Admin/BannerApiController.php`, `routes/banners-admin.php`,
`resources/views/partials/home/cards-banner.blade.php`,
`resources/views/admin/partials/banners-screen.blade.php`, two migrations, and
their tests.

## 2.60.306
A security fix on the search panel, and one helper for every image address that
becomes CSS. **Apply this one — it matters more than its size suggests.**

### ▲ THE SEARCH SUGGESTION PANEL COULD BE MADE TO RUN SOMEBODY ELSE'S CODE

The panel that drops down when a shopper types built its rows out of four values
and escaped **none** of them: the product link, the product image, the group
heading and the "view all" link.

A double quote in any one of them closes the attribute it sits in, and the next
thing the browser reads is a new attribute — an event handler. On the panel
every shopper opens.

**Nothing was exploitable while you type your own product names and image
paths.** What changes that is the import: it is about to write thousands of
these from a WordPress database this shop did not author. That is why this is
fixed now rather than noted.

A normal address comes through **unchanged**, so no picture that draws today
stops drawing. `javascript:` addresses and addresses pointing at somebody else's
host are refused outright.

### AND THE SMALLER VERSION OF THE SAME THING, IN TEN FILES

Thirteen places on the shop build a CSS background out of an image address. They
now go through one helper that escapes for CSS before the HTML escaping already
there.

**Honest about the size of it:** eleven of the thirteen were never reachable —
they escape twice by accident of how they are built, which was measured rather
than assumed, on a real page with a hostile address in the basket. One was dead
code. **The one that was live is the size/shade swatch on the product page**,
and it was counted: a hostile option image fired one request to an outside
address before, and none after.

It is worth doing anyway, because those eleven are safe by an accident that one
settings change would undo for all of them at once.

### Files

`resources/js/kbb/search.js`, `app/Support/CssUrl.php`, ten storefront Blade
files, the rebuilt `public/build`, and their tests.

## 2.60.305
Two new things you asked for: **Sets** under Catalog, and the **cards banner**
under Appearance. Both ship switched off and neither moves anything until you
turn it on.

### CATALOG → SETS

Build a set by choosing products. It gets its own price, category, description
and images, and it publishes and displays **like any other product** — its own
address, its own place in the sitemap and the search index.

In the basket it draws as your **fanned stack**: the members' pictures as
overlapping circles under the name, with **no label on any thumbnail**, and a
**"What's inside"** button that opens a tiny popup listing the product names.
Cart panel, cart page and checkout summary all draw it, and so do the order
email, the invoice and your customer's own order page.

**An order remembers what was in the box.** The member list is written onto the
order when it is placed, so changing a set later does not rewrite what an old
order says was sold. That is the part worth knowing: every document reads that
record, not today's set.

A shop with no sets pays nothing for this — no extra query anywhere.

**One question for you:** selling a set does **not** currently take one of each
member off the shelf; the set has its own stock like any other product. Do you
want it to? (It would also mean a set going out of stock the moment any one
member does.)

### APPEARANCE → BANNERS

Named banner sets, each holding cards with an image, one or two lines beneath it
and a small button. Pick which set the homepage shows, how many cards are across,
how fast it scrolls, the corner radius, the shadow, the shape of the card, and
whether the bottom text shows at all.

**Four full cards on a desktop with the fifth and sixth sliced, and a half-cut
card on a phone**, exactly as you asked. Every card in a row is the same size
whatever is in it, and with the bottom text off the card stays the same size
rather than leaving a gap.

**There is no JavaScript in it at all.** The scroll is one CSS animation, so the
browser runs it on its own compositor and it costs nothing to keep going. The
card width is a single calculation, which is why it adjusts from a large screen
to a small one without any code watching the window.

Reduced motion stops it **dead** — not slowed — and leaves it scrollable by hand.
The first image loads eagerly with its size declared so nothing jumps; every
other one waits.

Costs the homepage **one** extra query when it is on, and that number does not
move whether the set has 3 cards or 24.

### Files

`app/Models/{BannerSet,BannerCard,ProductSetItem}.php`,
`app/Support/{SetContents,SetEagerLoad,SetDesign}.php`, `app/Services/Banners.php`,
two admin controllers, two routes files, `resources/views/partials/set-row.blade.php`,
`resources/views/partials/home/cards-banner.blade.php`, two admin screens, the
five basket and document surfaces, `routes/web.php`,
`resources/views/admin/app.blade.php`, five migrations, and their tests.

## 2.60.304
The rail's auto-loop stops stuttering, and you can re-cut a cover whenever you
like.

### THE AUTO-LOOP HUNG BECAUSE IT WAS ASKING FOR THE SAME REWIND OVER AND OVER

A clip with no separate teaser file loops by being rewound while it plays — and
that is every clip on your host, because ffmpeg cannot be started from the web
server there.

The rewind was hung on `timeupdate`, which fires about four times a second **and
keeps firing while a seek is still running.** A seek back to zero in a long clip
is not instant, so the handler ran again, saw a position still past the mark, and
asked for the rewind a second and a third time. Every ask is another seek, they
queue, and up to four tiles were doing it at once. That is the stutter.

One rewind at a time now, and on Firefox and Safari it uses the cheap seek that
lands on the nearest keyframe instead of decoding forward to an exact frame.

**The real answer is still a teaser file** — a clip that has one loops natively
and never seeks at all. `php artisan ugc:cut-covers` cuts them over SSH.

### RE-CUT THE COVER, WHENEVER YOU WANT

`Content → All clips → a clip → Video & cover`, in the cover box:
**"Re-cut the cover from the video"**.

The cover was taken automatically when an upload finished and only then, so there
was no way back from a frame that caught a blink, from a video swapped through
the Media Library, or from a clip that arrived before this shop could cut
anything. It is the same cutter and the same progress bar as the automatic one,
and it appears only on a clip that has a video to cut from.

### AND THE 2.5 SECOND LOOP COLUMN

Fixed in **2.60.303** and verified in the real screen this round by driving it in
a browser: the `<video>` mounts, sits exactly on its box, and its position
advances. If that column still shows a still on your shop, **2.60.303 has not
been applied yet** — this package contains it either way.

### Files

`resources/views/ugc/assets.blade.php`,
`resources/views/admin/partials/ugc-library-screen.blade.php`,
`tools/m1-router.php`, `tools/ugcloop-preview.sh`, `tools/ugcloop-seed.php`,
`tools/ugcloop-check.cjs`, and their tests.

## 2.60.303
Tamara now captures a shipped order by itself, the cart drawer's two buttons are
the same height, a partial capture no longer inflates what can be refunded, a
settings screen stops drawing one control twice, and you can edit a homepage
section by clicking it in the picture.

### ▲ TAMARA AUTO-CAPTURE IS ON — YOU ASKED FOR THIS ONE

You said yes, so it is on: **an hourly check captures any Tamara order that has
reached Shipped or Completed and has not been captured yet.** An order that
ships and is never captured is one Tamara voids after about 180 days, and you
are never paid for the parcel you sent.

Orders touched in the **last 30 minutes are left alone**, so marking the wrong
order shipped can still be undone.

Nothing else was turned on. A `processing` order is never captured — the goods
have not gone. And a Tamara order already reads as `processing` the moment the
authorisation is confirmed, which is the other half of what you asked for and
was already true.

**Before it first fires, look at what it would take.** Over SSH:

    php artisan payments:tamara-capture --dry

It lists the orders and calls Tamara about none of them.

**To stop it:** `Store → Payments → Tamara → Settings`, set *"Capture
automatically when an order ships"* to Off. That sticks — nothing turns it back
on.

### ▲ A PARTIAL CAPTURE MADE MORE MONEY REFUNDABLE THAN YOU RECEIVED

When a provider had captured only part of an order, this shop recorded the
**whole order total** as captured — because nothing in the gateway interface
could say what was actually taken. That figure is the ceiling a refund is
measured against.

On a 300.00 order captured at 120.00, a **300.00 refund was accepted**: 180.00
of your own money returned to a buyer who never paid it.

Tabby, Tamara and Stripe now each report the real figure, read from their own
response. This can only ever **lower** a recorded capture, never raise one, so
no existing order becomes more refundable when you apply this.

### THE CART DRAWER'S TWO BUTTONS WERE 6px APART

`Cart` was 44px and `Checkout` was 50px — but only on the shop and category
pages, because that page's stylesheet sets a fixed height on both and a fixed
height beats the Cart panel's own control. Measured before and after at 1280 and
390. They match now, and the **Mobile → Button height** slider finally moves them
on those pages too.

### THE PRODUCT PAGE SETTINGS DREW ONE CONTROL TWICE

`Store → Ecommerce → Product page` had **two "Rating display" dropdowns over one
setting.** Change one, press Save, and the other kept showing the old value
until you reloaded — so the screen disagreed with itself about whether a product
page shows a rating at all. Shipping since 2.60.41; found by enrolling the last
four settings screens in the guard that looks for exactly this.

### EDIT A HOMEPAGE SECTION BY CLICKING IT

`Appearance → Homepage content → Live preview`. Click a section in the picture —
or pick it from the row of names — and its own controls open beneath, drawn from
the same schema every other settings screen uses. Changes show without a reload.

**Nothing moves until you move it.** No new setting, no changed default; Save
posts to the writer it has always used.

### ALSO

- The clip editor's **"2.5 second loop"** column showed the cover and never the
  clip. The player was working the whole time — the video was laid out *below*
  the box that clips it. It plays now.
- The **WordPress exporter plugin** said 1.0.0 through twelve rounds of changes,
  so you could not tell which build was on your site and an export could not say
  which build produced it. It is **1.5.0**, with a changelog. **Re-zip and
  re-upload it before your next export** — three product fields only exist in
  that build.
- Two test guards were green on both engines and meaningless on one, so an N+1
  in the cart and a redirect check on warm pages were not being guarded at all.
  Neither was hiding a fault on the shop.

### Files

`app/Services/Payments/**` (SettlementResult, PaymentCapturer, the four
gateways), `app/Support/{ReviewSettings,CacheSettings,ReviewBadgeSettings}.php`,
`app/Services/Mail/MailSettings.php`,
`app/Http/Controllers/Admin/{EcommerceApiController,HomepageApiController}.php`,
`app/Services/HomepageSections.php`,
`resources/views/admin/partials/{homepage-content-screen,ugc-library-screen}.blade.php`,
`resources/css/kbb/kbb.css`, `routes/console.php`, `routes/web.php`,
`routes/homepage-live-admin.php`, `wordpress-plugin/kbb-exporter/**`,
two migrations, and their tests.

## 2.60.302
A review filed against the wrong customer, Tamara auto-capture ready for your
decision, and the MySQL suite green for the first time.

### ▲ A REVIEW COULD BE FILED AGAINST THE WRONG PERSON

The review importer matched customers with `LOWER(email)`. Measured directly
against your MySQL version: the default collation is **accent-insensitive as
well as case-insensitive**, so `jose@example.com` and `JOSÉ@Example.com` matched
each other.

**What that meant:** a review written by one customer attached to a *different*
customer — whose name the product page then prints under an opinion they never
wrote. It matters now because you are about to import.

The comparison is done in PHP now, where accents are accents.

**Related, and deliberately NOT changed:** the checkout has the same over-match,
and there it is **load-bearing** — the customer email column is unique under
that collation, so the two spellings cannot both exist. Narrowing it there would
turn a wrong-customer link into a **failure at checkout**. That one is a
database-collation decision and it is yours, not something to fix quietly.

### ▲ TAMARA AUTO-CAPTURE — BUILT, AND SWITCHED OFF

If an order ships and is never captured, **you are never paid** — Tamara voids
the hold after about 180 days.

The machinery now exists and **does nothing until you turn it on**. Applying
this package captures nothing and does not contact Tamara once.

**See exactly what it would do, before deciding**, over SSH:

```
php artisan payments:tamara-capture --dry
```

That lists the orders that would be captured and the total — and it works
*while the switch is still off*. On a test shop it printed:

```
1 order(s) would be captured:
  TC-PREVIEW-1   shipped   249.00 AED   authorised 2026-09-18 22:53:14
Total that would be captured: 249.00 AED
```

**The switch:** `Store → Payments → Tamara → How this shop uses it → "Capture
automatically when an order ships"`.

**The question for you:** should capture follow fulfilment automatically? On
means the shop takes the money about 30 minutes after an order reaches
Shipped/Completed, instead of you pressing Capture on each one.

### ▲ THE TEST SUITE NOW PASSES ON MYSQL, WHICH IS WHAT YOUR SHOP RUNS

Seven tests had been failing there while passing on the engine used for
day-to-day checks. **None of the seven was a fault on your shop** — each was a
test checking the database driver rather than the behaviour.

That is worth saying because the same mistake pointing the other way passes
*silently*, and one was found doing exactly that: a check meant to catch a slow
page was comparing **nothing with nothing** and reporting success.

### ▲ THE MEDIA LIBRARY NOW SAYS WHAT IS REALLY IN IT

It read *"Every file uploaded through the admin…"*. Two of the things that land
there are not admin uploads at all: **photos your customers attach to reviews**,
and anything the Instagram sync downloads.

That matters because the Media Library is where files get **deleted** from — if
you believe the grid holds only your own uploads, you have no reason to expect
that deleting an unfamiliar row takes a live customer review photo with it.

### ▲ FOUND, REPORTED, NOT FIXED

**A partial capture reads as fully refundable.** If a Tamara order was partly
captured somewhere other than this shop, the shop records the *whole* order
total as captured — and that figure is the ceiling a refund is measured against.
The fix changes an interface four payment gateways implement, so it is named
here rather than half-done.

**No setting added that does anything, no default moved.**

## 2.60.301
Your imported product images will now reach the Media Library — and a refund
the shop could have offered on money it never took.

### ▲ WITHOUT THIS, YOUR IMPORTED IMAGES WOULD HAVE BEEN INVISIBLE

You are about to bring your WooCommerce catalogue across. On the previous
version, **none of the pictures that arrived would ever have appeared in
Content → Media Library** — and pressing *Rescan folder* would not have helped.

Three separate reasons, all now fixed:

1. The importer writes fetched pictures into `wp-content/uploads/…`; the
   catalogue scan only ever looked in `uploads/`.
2. Even with the scan widened, the function that records a file **refused any
   path that did not start with `uploads/`** — so it would still have recorded
   nothing.
3. Nothing recorded a picture **at the moment it was fetched**. It now does, so
   there is nothing to press.

**And the scan was quietly lying about its own limit.** It stops at 20,000
files; on a folder of 21,000 it produced exactly 20,000 rows **and said
nothing**, and pressing Rescan again added none of the missing 1,000 because
every file it could still see already had a row. It now tells you when it has
stopped short, and counts the limit **per folder** — so your imported pictures
cannot eat the allowance your own uploads need.

**Measured on a real folder**, not estimated: 21,000 files scanned in 0.9
seconds, 0.2 seconds on a re-scan. It only ever runs when you press Rescan;
nothing on the shop got slower.

### ▲ REVIEW PHOTOS AND INSTAGRAM PICTURES APPEAR WITHOUT A RESCAN

Both only reached the library if you happened to press Rescan. They register as
they arrive now.

The Instagram half also **forgets properly**, which matters more than it
sounds: every refresh replaces pictures and drops old posts, so recording
without forgetting would have left a dead row behind each time — a broken
thumbnail no screen could clear, on a server with no database access.
Registering alone would have been worse than doing nothing.

### ▲ AND DELETING A PICTURE NOW REALLY DELETES IT

A consequence of the above, caught before it reached you. Deleting a media row
only removed the file from disk if its path began `uploads/` — on the reasoning
that anything else lived in the *old* shop's folder, which this app cannot see.
That was true until the importer started copying files in. Deleting an imported
picture would have left the bytes on your disk with nothing pointing at them,
unreachable from every screen, on a host with no shell.

### ▲ A REFUND THE SHOP COULD HAVE OFFERED ON MONEY IT NEVER TOOK

If a payment hold was **released** rather than captured, Store → Orders still
offered the full amount as refundable — because the check looked at "was it
paid" and "was it captured" and never at "was it released".

It is not just a failed button: the refund console and **two customer emails**
treat that figure as the truth, so a shopper could have been told a refund was
on its way for money that never left his card. Captured money is unaffected —
that path runs first and still wins, which is pinned by its own test.

**No setting added, no default moved.**

## 2.60.300
The Instagram preview and a popup connector, and a field your variable products
would have lost on the migration.

### ▲ CONTENT → INSTAGRAM NOW HAS A PREVIEW

There was none at all before — you saved and went to look at the shop. It now
draws the section at the settings **currently on screen**, redrawing as you move
a slider rather than on save. All five layouts, all four profile styles, gap,
corners, heading, counts, caption and the play badge.

It is a drawing, not a live embed — rendering the real storefront would cost a
fetch per keystroke. Where you have posts stored it draws **your** posts; where
you have none it draws placeholders and says so rather than an empty box. It
also states honestly where the drawing differs from the shop: the caption sits
open here and is hover-only on a real tile.

There is a **Phone / Desktop** switch beside it, and it works by the preview box
asking its own width — nothing measures anything.

### ▲ "CONFIGURE NOW" OPENS A POPUP

Press it and Instagram opens in a small window. Log in, and the window **closes
itself** and the screen updates — without leaving the page you were on.

**If your browser blocks popups, nothing breaks**: the button falls back to the
old behaviour, navigating in the same tab exactly as before. Both paths were
driven in a real browser before shipping.

**On security, which matters for a login window:** the page ignores any message
that does not come from your own shop's address, and the message itself carries
only the word "done" — never a token. The screen then asks **your server** what
the state is, so a forged message tells it nothing. The app secret never reaches
your browser at all; it is returned as a yes/no, never as a value.

### ▲ A FIELD YOUR VARIABLE PRODUCTS WOULD HAVE LOST

`default_attributes` — **"Default Form Values"** on the Variations tab, which
decides *which size a variable product's page opens on*.

It was worse than simply missing. The exporter was **already fetching it** on
every batch and then emitting no column for it — so anybody checking what the
migration covered would have seen the field on the list and stopped looking.

**What it would have cost you:** every variable product would open on no size,
so a shopper has to choose before "Add to basket" means anything — on every
visit — where your old shop had chosen for him.

It is now exported and reported. **Whether it earns a column is your call** —
the import report now tells you how many products actually set one. If it is a
handful, it is not worth a column; if it is every variable product you have,
your shoppers have all just been given an extra click.

### ▲ AND A TEST THAT WAS CHECKING NOTHING

The "imports the same file twice and changes nothing" test compared five
numbers, and two of them were **zero before and zero after** — its fixture never
created the rows it was counting. `0 === 0` is identical and proves nothing. The
fixture is fixed and each count must now be non-empty *before* it is compared.

**No setting added, no default moved.**

## 2.60.299
The stuck cover bar fixed, Save closes the popup, and the Cart panel gets
Desktop and Mobile control sets.

### ▲ "SAVING THE COVER" NO LONGER STICKS AT 80%

Your screenshot: the cut worked, the Poster row showed **134 KB**, and the bar
sat at **80% · Saving the cover** underneath it for good.

**That was my oversight.** The All-clips screen got the finishing step and the
section popup did not — the same feature written twice and finished once. The
bar was set to 80%, the file was handed off, and nothing ever moved it again.

It now finishes when the cover is **actually adopted onto the clip** — a real
event, not a timer — so 100% means the cover is genuinely there. It says
**Cover set**, holds for a moment so you can read it, and goes. A failure
clears it too; a bar stuck at 80% under a refusal is the same bug in a
different colour.

### ▲ SAVE NOW CLOSES THE POPUP

**Saving every tab together already worked** and was never the gap: one press
writes Details, Source and Placement in one go, then the Products tab, and the
Files tab saves on upload. What Save then did was *re-open* the same dialog on
the row it had just saved — which reads as nothing having happened.

It closes now, and the section list behind it refreshes so the row you just
edited is up to date. The close happens **after** both writes finish, never
between them — closing early would have turned a cosmetic annoyance into lost
edits.

### ▲ APPEARANCE → CART PANEL: DESKTOP AND MOBILE

**Where:** `Appearance → Cart panel`, now six tabs — **Desktop · Mobile ·
Content · Behaviour · Wording · Colour**.

Everything you asked for on the mobile cart panel now has its own value,
separate from the desktop one: row spacing, padding, font sizes, the quantity
buttons, **both** crosses (the ✕ on each line and the panel's own close), the
tab strip, and the Cart/Checkout buttons — their gap, padding, corners, label
size, and whether they sit side by side or stacked.

**25 new controls, every one shipping at the value your panel renders today.**
Applying this moves nothing until you move a slider.

**The point of the whole thing, measured:** squeezing every mobile control
changed **nothing at all** on the desktop panel — a programmatic diff of the two
sets of measurements returns *no differences*, and the two Desktop-tab
screenshots are byte-for-byte identical.

**The 44px tap targets.** Four controls (the line ✕, the close button, the tab
strip, the buttons) sit at 44px because that is the smallest box a finger hits
reliably. You asked to shrink them, so **the sliders go below 44 and nothing
stops you** — a warm line simply appears under that one slider saying taps get
less reliable, and names the number you have chosen. It never silently clamps.

**One thing needing your decision:** on the **shop and category pages only**,
the drawer's Checkout button is held at a fixed 50px and Cart at 44px by that
page's own stylesheet, so the button-height control does not govern there — and
those two buttons have been 6px different in height all along. Everywhere else
the control works. The fix is one line, but it changes a button that works today
on a page you did not ask about. **Should those two be the same height on the
shop page?**

**No setting added beyond the 25 above, no default moved.**

## 2.60.298
Three columns side by side, a real progress bar on the cover cut, and the same
cut button inside the section popup.

### ▲ THE VIDEO, THE COVER AND THE LOOP, SIDE BY SIDE

**Where:** Content → Shoppable video → open a clip → step 2 (Video & cover).

The 2.5-second loop used to sit full-width underneath. It is now the third
column beside the video and the cover, so the whole of step 2 is one row.

Three columns only where three columns fit: one on a phone, two from 900px with
the loop across the bottom, and three from 1440px — measured at **404px each**,
which keeps every preview legible. Below that the loop would be squeezed to
~290px, narrower than the tile it is showing you.

### ▲ A REAL PROGRESS BAR ON THE COVER CUT

Taking a frame has no percentage of its own — a video decoder does not report
one — so a bar driven by a timer would be a **fiction**. It is driven by four
real browser events instead, each meaning that piece of work genuinely finished:

| | |
|---|---|
| **4%** | Opening the video |
| **22%** | Reading the video |
| **44%** | Finding the frame at 0.6s |
| **62%** | Taking the frame |
| **80%** | Saving the cover |
| **100%** | Cover set |

**Observed live, not assumed:** sampling the screen every 50ms during a real cut
caught `4% · Opening the video` → `62% · Taking the frame` → `100% · Cover set`.

**It now holds at 100% for two seconds before it goes.** The first version was
cleared by the page reload, so on a short clip the whole thing finished in about
300ms and you would have seen nothing at all — I could not catch it in
automation either. A bar that vanishes the instant it completes has told nobody
anything.

### ▲ THE SAME CUT BUTTON INSIDE THE SECTION POPUP

**Where:** Content → Shoppable video → Sections → open a section → click a clip
→ **Files** tab, under **Poster**.

You add a video to a section, the popup opens on Files, and there was no way to
get a cover from there — you had to go to All clips. That was an oversight, not
a decision: both screens edit the same clips through the same endpoints, so a
cover you can take on one and not the other is a trap.

It is the same button, the same 0.6-second frame and the same progress bar, and
it goes through the same Media Library path — so the file is registered exactly
as a picture you chose by hand, and checked the same way by the server.

### ▲ AND A LEAK THAT WAS FILLING YOUR TEST MACHINE

Not something you see on the shop, but worth recording: the test suite was
leaving temporary files in shared storage and never deleting them — **1,625
files and 11.6 GB** from one week, some 60 MB apiece. This project's own notes
warn that a full disk here produces database errors that look exactly like a
transaction bug, so it was not merely untidy. Fixed at source; a short run now
leaves **zero** files behind where it used to leave 36.

**No setting added, no default moved.**

## 2.60.297
**Apply this one. It replaces 2.60.292 through .296, and it fixes the error you
just sent me — which was my mistake, not your server's.**

### ▲ WHAT WENT WRONG, PLAINLY

Your All clips screen said:

> The ffmpeg check failed — Error: Call to undefined method
> `App\Services\UgcTranscoder::blocker()`

That is the real cause of everything since, and it is a **packaging error I
made**, not a fault on your server.

The method `blocker()` was added in **2.60.291**. Every package I have sent you
since — .292, .293, .294, .295, .296 — was built as a *difference* from 2.60.291,
because I assumed you had applied it. So each of those shipped the **new**
controller that calls `blocker()`, and **none of them shipped the file that
contains it**.

If 2.60.291 never applied on your server — or applied only partly — you ended up
with new code calling a method your copy of the older file does not have. Every
load of that screen failed on it. That is the 500 from two days ago, and it is
the amber note you are reading now.

**A package should never depend on you having applied the one before it.** This
one does not: it carries the complete current version of every Shoppable-video
and Media-library file, so it lands correctly whatever state your server is in.

### ▲ HOW YOU KNOW IT WORKED

Open **Content → Shoppable video → All clips**. The amber "these parts of the
screen could not be worked out" note should be **gone entirely**.

If any note remains, send me the line in it — it will now name a different
cause, and that is useful rather than annoying.

### ▲ THE ONE GOOD THING ABOUT THIS

You could read that error at all because of 2.60.294, which stopped one failed
check from taking the whole library down and made it print what actually broke.
Before that, this was a bare "Server Error" — and I spent two rounds guessing at
it. The screen told us in one line.

### ▲ AND THE COVER IS NOW TAKEN AUTOMATICALLY — nothing to press

You saw the panel that said *"This server cannot cut a cover — your browser
can"* and answered: **"i need the permanent solution ... super reliable."**

You were right, and the wording was not the problem. A panel whose first line is
your server's limitation reads as a fault report however it ends, and a button
underneath it puts the work back on you every single time — for something that
takes the browser under a second.

**So it just happens now.** You upload a video; the moment the upload lands, the
browser takes the frame at 0.6 seconds and sets it as the cover. There is
nothing to press and nothing to read. The panel now says so instead of
apologising, and keeps a quiet **Take the cover again** button for re-cutting and
for clips uploaded before this version.

**It only runs when the server did not manage one.** A host that can run ffmpeg
has already cut a better cover — and the teaser with it — by the time this
would fire, and overwriting that with a browser frame would be a downgrade.

**Proven before shipping:** on a preview booted with no ffmpeg at all, a video
was uploaded and **nothing else was touched** — a real JPEG cover appeared on
the clip by itself.

The reason your host cannot do it server-side is still printed, in smaller grey
text at the bottom of that panel, for when you want it.

### ▲ EVERYTHING FROM .292 TO .296 IS INCLUDED

Nothing is lost by skipping straight to this one:

- **Your browser cuts the cover** (.296) — no ffmpeg, no `proc_open`, no cron.
- **The Media Library opens as a centred popup again** (.296).
- **A clip can be published without a cover or credits**, warnings kept (.296).
- **`php artisan ugc:cut-covers`** and the automatic cron route (.292, .295).
- **A failed check can no longer empty your clip library** (.294).
- **Errors name their own cause** instead of one blank sentence (.293).
- **The Upload-a-new-video control** on section pages (.293).
- **The product search** fixed on MySQL, which is what your shop runs (.293).

**No setting added, no default moved.**

### Files
The complete current state of every file touched since 2.60.290, including
`app/Services/UgcTranscoder.php` — the one that was missing.

## 2.60.296
The permanent fix: your browser cuts the cover. The Media Library opens as a
popup again. And a clip can go live without a cover or credits.

### ▲ THE COVER NOW COMES FROM YOUR BROWSER — permanently, on any host

You asked for a permanent fix, and you were right that the last two were not
one: re-enabling `proc_open` needs your host to allow it, and the cron route
needs a cron line. Both are server admin, and on both the screen still says
"this server cannot cut", because in that moment it cannot.

**Your browser already decoded the video.** It has to — it plays it back on
that very screen, and your shop plays it to shoppers. So the frame is already
on your computer, and the browser can hand it straight to the cover.

**It needs nothing. No ffmpeg, no `proc_open`, no cron, no setting.** It will
keep working on any host you ever move to.

**Where:** Content → Shoppable video → open a clip → step 2 (Video & cover).
Where it used to say *"Nothing can be cut on this server"* there is now a green
**Cut the cover from the video** button. Press it and the cover appears.

It takes the frame at **0.6 seconds in** — the same instant this shop's own
cutter uses, so a cover taken here and one taken by ffmpeg are the same picture.
It also joins the Media Library like any other image.

**Proven before shipping**, on a preview deliberately booted with no ffmpeg at
all: upload, press, and a real 720×1280 JPEG lands on the clip.

**What it does not do:** the teaser. A 2.5-second re-encoded video is not
something a browser should be building, and it is not needed — a teaser is a
bandwidth saving, never a requirement. The cover was the thing that blocked you.

### ▲ THE MEDIA LIBRARY OPENED AT THE BOTTOM OF THE PAGE — fixed

*"the manual option is opening the media downside, not in popup as normal"* —
and that was my bug, from the round that added drag-and-drop everywhere.

The picker lets you drop a file anywhere on its dark backdrop. To do that it
registered the backdrop as a drop zone — and the shared drop-zone helper styles
whatever it is given, because normally it is given a small dashed box. Those
styles overrode the backdrop's own full-screen positioning, so the dialog fell
out of the middle of the screen and landed at the bottom of the page.

It was quietly breaking something else too: the highlight that should appear
while you drag a file over the dialog had stopped working entirely.

Both fixed. The dialog is centred over a dimmed page again, and dragging a file
onto it lights up properly.

### ▲ A CLIP CAN NOW BE PUBLISHED WITHOUT A COVER OR CREDITS

**This is a deliberate change to how the shop behaves**, asked for in as many
words, so it is called out here rather than buried.

Before: no cover **or** no recorded permission meant Publish was refused.
Now: only **one** thing is required — the video itself. A clip with nothing to
play is an empty box, and no warning fixes that.

The other two are now **warnings that stay on the screen**, in amber, separate
from the red "the save was refused". They do not go away when you publish.

**Both halves changed, and that mattered.** The shop's own query *also* required
a cover. Had I only changed the admin, you would have pressed Publish, seen
"Published", and the rail would have silently never shown the clip. A refusal
you can see beats a success that is not true.

**The page does not jump.** The tile's space was never reserved from the cover
file — it comes from the video's own dimensions — so a cover-less tile holds
exactly the same space. You see an empty box for the instant before the video
paints instead of a still.

### ▲ ONE THING I DID NOT CHANGE, ON PURPOSE

If a creator has been asked and **refused** permission, that clip still cannot
be published. "Haven't got round to recording it" and "they said no" are
different, and only the first is yours to wave through — the cost of the second
lands on somebody who is not your shop.

I want to be straight that this was not caution on my part: my first attempt
removed the permission check entirely, and **one of this project's own tests
caught it** before it got anywhere near you.

**No setting added. Everything above is included from 2.60.292 onward.**

### Files
- `resources/views/admin/partials/upload-kit.blade.php` — the browser cutter.
- `resources/views/admin/partials/ugc-library-screen.blade.php` — the button,
  and the warnings panel.
- `resources/views/admin/partials/media-picker.blade.php` — the popup fix.
- `app/Models/UgcVideo.php` — blockers split from warnings; the shop query.
- `app/Services/Ugc/Tile.php`, `resources/views/ugc/rail.blade.php` — a tile
  without a cover.
- `app/Http/Controllers/Admin/UgcVideoController.php`, `UgcSectionController.php`
  — the warnings travel with the clip.

## 2.60.295
The poster and teaser now cut themselves, about a minute after you upload —
with no change to your server.

### ▲ FIRST: "No ffmpeg here" is NOT what your server says

That line was in a picture I sent you, and it was **my own simulated failure** —
I forced it to show you what the new error note looks like. It is not a reading
of your shop, and I should have labelled it. Sorry.

**Your server has ffmpeg.** The proof is your own error log: it got as far as
`new Process`, and the code only reaches that line *after* it has found the
ffmpeg binary on disk. If ffmpeg were missing it would have stopped one step
earlier with a different message.

Your actual blocker is the one 2.60.291 named: **PHP-FPM — the PHP that serves
your website — is not allowed to start programs** (`proc_open` is switched off).
That is a hardening setting, it is common, and it is not a fault in your shop.

### ▲ SO THE COVERS CUT THEMSELVES NOW

The command line PHP on your server is **not** blocked — your own
`php -i` output said `disable_functions => no value`. So the work just has to
happen there instead, and from this release the application arranges that
itself.

**You add one cron line, once:**

```
* * * * * cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app && php artisan schedule:run >> /dev/null 2>&1
```

Cloudways: Application Settings → **Cron Job Management** → Add New Cron →
**Advanced**, and paste that. `docs/SERVER-PROC-OPEN.md` §3b has the walkthrough.

Then, with nothing else to do ever again:

1. You upload a clip. It saves. The cover cannot be cut in the browser — same
   as now.
2. **Within about a minute** the scheduled run finds it, cuts the poster and the
   2.5-second teaser, and records the length and dimensions.
3. Reload the clip. The cover is there.

**Proven end to end before shipping**, not just tested: a clip with a real video
and no cover, then nothing typed but `php artisan schedule:run` — poster,
teaser, `5000ms`, `720×1280`, all recorded.

**One cron line only.** Do not add a second one for the cutting command itself;
the schedule is decided inside the application and a second line would do the
work twice.

**If you never add the cron line, nothing breaks.** You just run
`php artisan ugc:cut-covers` yourself when it suits you, exactly as in 2.60.292.

**An honest limit:** the transcode is real work on a machine you share with the
shop. Each run takes at most 20 clips and holds a lock so two runs cannot
overlap — so after a bulk import the covers arrive over several minutes rather
than all at once. That is deliberate.

### ▲ AND ONE THING THAT WOULD HAVE ANNOYED YOU BY WEDNESDAY

A scheduled command that fails sends mail. Run every minute on a server that
cannot cut, that is **1,440 emails a day** — and the reliable result of that is
a filter that also hides the failure you needed to see. So when the schedule
runs it, "this machine cannot cut" is treated as *nothing to do*, not as a
failure. Typing the command yourself still tells you loudly, because otherwise
you would sit waiting for covers that were never coming.

**No setting added, no default moved.**

### Files
- `routes/console.php` — the schedule (the file was empty; already wired).
- `app/Console/Commands/CutUgcCovers.php` — the `--unattended` flag.
- `docs/SERVER-PROC-OPEN.md` — §3b, the cron route, placed before the two
  php.ini routes because it is easier than both.
- Everything in 2.60.294, .293 and .292 below is included.

## 2.60.294
The All clips page, fixed so that one dead check can no longer take your whole
library down with it.

### ▲ WHY "ALL CLIPS" WAS 500ing — the actual cause, not the message

2.60.293 made the screen report the status instead of a vague sentence, and it
did its job: it came back **HTTP 500 · "Server Error"**. That told me where to
look, and the answer was in the shape of the code rather than in any one line.

When that page loads it asks your server four separate things:

1. **Your clips** — the point of the page.
2. Can ffmpeg be started here?
3. What will PHP really accept for an upload?
4. What do the empty Arabic boxes look like?

Only the first one matters. The other three are **decoration**: a line of
helper text, a size in the upload box, two blank inputs. But all four were built
in a single expression — so if any one of them failed, **you lost everything**,
including every clip, behind a generic error with no cause.

Questions 2 and 3 are the ones that poke at the machine: four absolute paths on
disk, `proc_open`, PHP ini values. On a hardened host — and yours is hardened,
that is why `proc_open` is off — those are exactly the calls that can be refused.

**So now each of those checks runs on its own and cannot bring the page down.**
Your clips load. If a check dies, the screen says which one and quotes the
server's own words, in a calm amber note under the library rather than instead
of it. And a clip that cannot be read is **named**, not silently dropped — a
clip that quietly disappears is one you go hunting for on the shop.

The safe default is used when a check fails: ffmpeg is reported as *absent*,
so the screen offers you the "choose your own cover" path instead of promising a
cut that cannot happen.

**Honest note:** I still cannot see your server, so I cannot tell you which of
the checks was throwing. What I can tell you is that it no longer matters — the
page works either way, and if one is still failing it will now print the
exception on screen. If you see that amber note, send me the line in it.

This is the same rule 2.60.291 applied one layer down — *a failed transcode can
no longer fail an upload* — applied one layer up: **a failed check can no longer
fail the library.**

**No setting added, no default moved.**

### Files
- `app/Http/Controllers/Admin/UgcVideoController.php` — each optional block
  through a probe that cannot throw; per-clip errors named rather than fatal.
- `resources/views/admin/partials/ugc-library-screen.blade.php` — the amber
  note under the library.
- `tests/Feature/UgcLibrarySurvivesAProbeTest.php` (repo only).
- Everything in 2.60.293 and 2.60.292 below is included.

## 2.60.293
The Add-video button on a section page, a clips screen that says what went
wrong, and a product search that behaved differently on your server than in
the tests.

### ▲ "UPLOAD A NEW VIDEO" ON THE SECTION PAGE — where you drew the red box

It is in the top right of **Add from the library**, and it has been in the code
since 2.60.291 — which means your server is serving an older *compiled* copy of
that screen. Applying this package ships the screen again **and** clears the
compiled-view cache, so the button appears.

**Where:** Content → Shoppable video → Sections → open a section → top right of
"Add from the library".

Drop a video on it or press it. One upload and the clip exists, is in that
section, is in the Clips tab and is in the Media Library — with the live
progress bar and a playable preview of the file **your shop** is now serving.

### ▲ WHY "ALL CLIPS" GAVE YOU A SENTENCE AND NOTHING ELSE

Your screenshot said **"The video library could not be read."** That is all it
said — and that is all it *could* say. Both Shoppable video screens printed that
one sentence for every failure that was not one of five specific ones. The HTTP
status was sitting on the error the whole time and the code threw it away one
line later.

So a crashed server, an expired login and a dropped connection all looked
identical. **I could not diagnose your report from it, and neither could you.**
That is now fixed. Three cases it could never name before:

- **Your login quietly expiring.** The console is drawn once when you sign in
  and then lives in the tab; every screen after that fetches its data in the
  background. So when the session lapses you are *not* sent back to the login
  page — the console keeps drawing and the **data** is refused. It now says:
  *"Your admin session has expired — nothing is wrong with this screen or your
  clips. Reload the page and sign in again."* This is the most likely cause of
  what you saw, and it would also explain why things worked again after a
  refresh.
- **A real server error.** It now prints the status, the server's own sentence,
  and the command that shows the reason: `tail -n 40 storage/logs/laravel.log`.
- **No connection at all.** Named, rather than blamed on the shop.

Anything else still carries its number, so the next odd failure arrives already
half-diagnosed instead of costing a round trip.

**Honest note:** this does not by itself fix whatever your server was doing — I
cannot see it from here. It makes the screen *tell you*, which is the thing that
was missing. Load All clips after applying this and it will name the cause.

### ▲ A SEARCH THAT FOUND DIFFERENT PRODUCTS ON YOUR SERVER THAN IN THE TESTS

Found by running the suite against MySQL — which is what your shop runs, and
which the normal test lane never touches.

The product search in the clip editor protected itself from wildcards using a
backslash. That is correct on MySQL and **means nothing at all on SQLite**. The
practical effect: searching for a product with a `%` in its name — you have one,
`Peach Niacinamide 30% Serum` — worked on your shop and found nothing under the
tests. The test that was supposed to cover it asserted "finds nothing", so it
passed on the engine you do not use and failed on the one you do, while proving
nothing either way.

Both search paths now use the helper this project already had for exactly this.
The test creates its own products and checks the one with a percent sign is
found and the one without is not.

**Also named, not fixed:** four other admin screens (coupons, coupon usage,
catalogue reorder, product search) carry the same pattern. They belong to other
lanes; their searches have the same engine-dependent behaviour.

**No setting added, no default moved.**

### Files
- `resources/views/admin/partials/ugc-library-screen.blade.php` — the error
  branches; re-shipped so the compiled copy is replaced.
- `resources/views/admin/partials/ugc-sections-screen.blade.php` — same, and
  this is the file that carries the Upload-a-new-video control.
- `app/Http/Controllers/Admin/UgcVideoController.php` — both search sites
  through `SearchTerms`.
- `database/migrations/2027_03_19_000000_clear_caches_ugc_cut_covers.php` —
  clears the compiled views and code.
- `app/Console/Commands/CutUgcCovers.php`, `app/Services/UgcDerivedFiles.php`,
  `app/Services/UgcClipIntake.php` — carried forward from 2.60.292 below.
- `tests/Feature/UgcErrorsNameTheirCauseTest.php`, `UgcAdminScreenTest.php`
  (repo only).

## 2.60.292
The covers your server could not cut from the browser, cut from the command
line instead.

### ▲ YOUR CLIPS CAN HAVE COVERS TODAY — no server change needed

2.60.291 explained why the upload said "Server Error": your PHP-FPM pool has
`proc_open` switched off, so the shop cannot start ffmpeg to cut the cover and
the teaser. That fix stopped the failure from taking your upload down with it.
It did not give you the covers.

Then your own `php -i` over SSH answered:

> `disable_functions => no value => no value`

**That is the CLI, and the CLI is not blocked.** The web process and the command
line on Cloudways run two different PHP configurations, and only the web one is
hardened. ffmpeg is installed and runnable; only the browser's PHP is forbidden
to start it.

So there is a door that is already open, and this release walks through it:

```
cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app
php artisan ugc:cut-covers
```

That cuts the cover and the 2.5-second teaser for every clip that has a video
and no cover, oldest first. Run it after uploading; run it twice if you like —
the second run finds nothing to do, because it only touches clips with no cover.

**Options**

| | |
|---|---|
| `--dry-run` | Lists what it would cut and changes nothing. |
| `--limit=50` | How many clips in one run (default 50, max 500). |
| `--id=12 --id=15` | Only these clips. |
| `--force` | Re-cut clips that already have a cover, replacing it. |

If your host ever blocks the CLI too, the command says so **before** it touches
anything, in one sentence, rather than failing fifty times in a row.

**If it says every clip is missing, read the path it prints.** The command runs
under a different PHP than your shop does, and the two can disagree about where
the web root is. When *every* clip comes back missing it now prints the exact
directory it looked in, so you can compare it against your web root instead of
going to look for uploads that were never lost. (`bootstrap/public-path.php` is
the fix if they differ — it is a file, so both PHPs read the same value.)

`docs/SERVER-PROC-OPEN.md` is still the guide to switching `proc_open` back on
for the web process, if you would rather have covers cut at upload time. This
command is the route that needs no support ticket.

### ▲ A BUG THIS FOUND ON THE WAY IN

Writing the command would have been the **fourth** copy of the same sixteen
lines that record a cut cover — and the three that already existed had drifted
apart. The copy used by the clip-intake path never released the file it was
replacing, so re-cutting a cover there left the **old** poster pinned in the
Media Library permanently, un-deletable, for every clip that was ever re-cut.

All four now go through one writer. The drift is fixed as a consequence, not as
a patch on top of it.

**No setting added, no default moved.** Nothing on the shop changes until you
run the command.

### Files
- `app/Console/Commands/CutUgcCovers.php` — new: the command.
- `app/Services/UgcDerivedFiles.php` — new: the one writer for the six derive
  columns, with the orphan-tracking callback the upload path needs.
- `app/Services/UgcClipIntake.php` — through the writer; gains the missing
  release.
- `app/Http/Controllers/Admin/UgcVideoController.php` — both apply-blocks
  through the writer.
- `tests/Feature/CutUgcCoversCommandTest.php` — 6 cases (repo only).

## 2.60.291
The upload error explained and fixed, instant step switching, a product search
that answers, and every upload now joins the Media Library.

### ▲ WHY THE UPLOAD SAID "SERVER ERROR" — and why your video was there anyway

Your 8.4 MB clip **uploaded correctly every single time**. The file was written,
the clip was saved, and then the app tried to cut the cover and the teaser from
it — and *that* step crashed, taking the whole response down with it.

So you were told:

> Server Error — 8.4 MB, nothing on the clip was changed.

**That was false**, and it is the part that cost you. Something *was* changed:
your video was already in. Which is exactly why a refresh showed it sitting
there as a draft.

**The cause:** PHP on your server has `proc_open` switched off — a common
hardening setting. The cover-cutting needs it to start ffmpeg. The safety net
that should have caught the failure was written one line too low to see it.

Three things change:

- **A failed cut can no longer fail an upload.** Your video saves and serves; you
  get told the cover could not be cut here.
- **No message may say "nothing was changed" unless nothing was.**
- **Your orphans are cleaned up.** Every failed attempt left a file on the server
  that nothing would ever delete. You made several.

The screen also stops promising a cover it cannot cut: ffmpeg *is* installed on
your server, so the app saw the file and assumed it could run it. It now tells
"no ffmpeg" apart from "ffmpeg is here but PHP may not start it" — different
problems, different fixes. **Enable `proc_open` and covers cut themselves again.**

### Steps switch instantly now

Moving forward a step used to make **four** server round trips, one after
another, before it would draw anything. On a connection like yours:

| | before | after |
|---|---|---|
| Details → Video & cover | 1170 ms | **57 ms** |
| Video & cover → Credit | 1185 ms | **31 ms** |
| Credit → Products | 1137 ms | **32 ms** |
| Products → Publish | 1135 ms | **24 ms** |

The video also stopped being thrown away and rebuilt on every step change, which
is what restarted the loop preview and re-fetched the clip.

**Your typing is safe.** It survives a step change with no saving at all, and if
you reload or close the tab, the page offers to restore what you had typed — it
never silently applies it over what the server has.

### The product search answers you now

Typing four characters used to show **nothing at all** for about a second, and
then nothing for ever if there were no matches. Now: *Searching…*, then either
the matches, or **"Nothing published matches that"** — and if the product exists
but is a draft, it says so and where to publish it.

**The ten newest products are listed before you type anything.**

Also fixed: the search box lost your cursor on every keystroke that returned a
result, which is why adding a second product felt broken.

### Every upload joins the Media Library

Clips and loop files never reached it. They do now — and the ones already on your
server are catalogued when this update is applied. Videos show as a film-strip
tile with a **Show: Everything / Pictures / Videos** filter, and play in the
detail panel.

### ▲ AND THE LIBRARY COULD DELETE A LIVE CLIP'S COVER

Deleting a picture checks whether products, brands or categories are using it —
but never checked shoppable video. So it has been offering to delete the cover of
a published clip, and doing it, leaving the video pointing at nothing. Now
refused, naming the clip using it.

### Upload a new video straight from a section

**Content → Shoppable video → Sections → open a section → "Add from the library",
top right.** Drop a video there and it becomes a draft clip, added to that
section, in the Clips tab and in the Media Library — without going to the Clips
tab first.

## 2.60.290
Every upload in the console: a bar that tells slow from stuck, and drag-and-drop
everywhere.

### ▲ WHY THE BAR "STUCK AT 72%" — it was not stuck, and it was not the size

Measured, not guessed. **The browser's progress event does not tick.** It fires
when the network buffer drains, in roughly 1.6 MB strides, and **between those
events the bar is exactly still**:

| your upload speed | how long the bar sits motionless |
|---|---|
| 600 KB/s | 2.6 seconds |
| **300 KB/s (yours)** | **5.6 seconds** |
| 100 KB/s | 16.8 seconds |

A bar frozen for five and a half seconds, over and over, **is** "stuck at 72%".
Nothing was wrong with your server: no proxy, no timeout. Your 8.4 MB file plus
its packaging measures 289 **bytes** of overhead — it would need 1.6 MB to
breach your limit.

**And the panel was lying to you twice.**

"All of it has arrived. The server is checking the file" was shown **up to 49
seconds before the server had finished receiving anything**. The browser had
handed the bytes to the operating system; the upload was still in flight.

Worse, the "stalled" warning fired after 20 seconds — **less than the 16.8
seconds a healthy 100 KB/s upload legitimately sits still**. On any connection
below about 82 KB/s it accused a perfectly good upload of dying and invited you
to cancel it.

**What you get now:** a real speed and a time remaining —
*"6 MB of 8.4 MB · 297 KB/s · about 8 seconds left to send"* — and a stall
warning worked out from your own connection, so slow is never called stuck.

At your exact frame it reads 71%, 297 KB/s, 8 seconds left. It was 8 seconds
from finishing.

### Drag and drop, on every uploader in the console

One uploader now serves them all, so they cannot drift apart again:

- **Shoppable video → Sections → a clip → Files** — the screen you photographed.
  All three rows are drop targets, with a live bar. The "Up to 64MB" line is gone.
- **Choose an image** (the picker every screen uses) — drop zone, a real limit, Stop.
- **Products → Edit** — main image, gallery and share image, each with its bar in
  the card you clicked rather than the one next door.
- **Reviews → Import** — drop zone and progress.

Dropping several photos into a gallery uploads them one at a time, each with its
own bar and its own Stop, and **a file that fails stays on screen with its
reason** instead of the whole panel emptying.

### ▲ A FILE DROPPED NEXT TO A BOX USED TO THROW AWAY YOUR WORK

Let a picture go two pixels outside a drop zone — on the toolbar, in the gap
between two cards — and the **browser** took over and opened the file, replacing
the page. A half-filled product form went with it, unsaved.

Every gap in the console now refuses that. Dragging to reorder still works.

### Also

- The gallery tiles used to light up green when you dragged a photo over them,
  promising a drop, and then do nothing.
- Pressing Stop aborted the upload but left the screen greyed out until you
  reloaded.
- A 3 MB photo in the picker came back as "The file field must be a file."

## 2.60.289
Your 8.4 MB video, why it was refused, and two ways an order could be shipped
and never paid for.

### ▲ THE VIDEO UPLOAD: IT WAS NEVER YOUR FILE

You uploaded an 8.4 MB clip, the bar reached 100%, and the screen said **"That
file was not accepted."** Your file was fine. **PHP on the server threw the whole
upload away**, and the screen had no idea — it was advertising "up to 64 MB", a
number this app has never checked it could honour.

There are two separate limits and your file was over both:

- `upload_max_filesize` — the biggest single file. On the machine this was built
  on it is **2 MB**.
- `post_max_size` — the biggest whole request. **8 MB**. Over this, PHP discards
  the entire upload *before the app is even asked*, which is why every byte sends
  and then it fails.

**What changed.** Step 2 now reads your server and advertises the number it will
really take. If your file is too big it says so **before sending it**, names both
figures, and says which one is stopping you:

> That file is 8.4 MB and the most that can be uploaded here is 2 MB. It was not
> sent, so nothing on the clip was changed. The limit is PHP on this server and
> not Shoppable video, which allows 64 MB for a clip: upload_max_filesize is 2M.
> Raise it on the server and this box will take the bigger file.

**To actually upload big clips, raise the limits on your server** — on Cloudways,
Application Settings. Applying this package prints your server's real numbers in
the update log, and step 2 shows them too.

### The upload bar itself

A clock (how long it has been sending, and how long the server has been thinking
since), a **Cancel**, and a **Try again** that appears only where pressing it
could actually work — never on a file the server will refuse again.

Also fixed: every upload handler used the clip you had *open*, not the one the
file was sent to. Pressing Back mid-upload locked the screen; opening a different
clip was worse, showing a green "arrived whole" panel for a file that landed on
another row.

### ▲ DUPLICATING AN ORDER COPIED THE ORIGINAL'S PAYMENT

Duplicate an order that had been captured and the copy arrived **already marked
paid**, with no Capture button — and pressing Capture answered "already
captured". You pack it, you ship it, **you are never paid**.

The same copy was **refundable for money it never took**. A full refund on it was
accepted. On cash on delivery that is an instruction to hand AED 250 in cash to
someone who never paid a fil.

### ▲ A RELEASED AUTHORISATION COULD STILL BE CAPTURED

Release the hold on a cancelled order, then revive that order, and the screen
offered **"Capture AED 250"** with a red countdown on a window that no longer
exists — four inches above the order's own note saying the authorisation had been
released. Pressing it reached the provider and came back blaming *them*.

Both are refused now, before anything is claimed and without a network call. And
the panel says what actually happened — that the authorisation was released, and
when — instead of "this order has not been authorised", which was never true.

### Error messages stop wearing a green tick

Following on from last release: **41 more** failure messages across the console
were still drawing the green success tick, including **"Could not process that
refund"** and **"Could not capture that payment"** — which anyone scanning reads
as done, right before the goods go out.

### Faster

The order detail screen dropped from 9 database reads to 7, and from 11 to 8 on
an uncaptured order, by working out two figures once instead of five times.
Measured flat at 1, 2, 5, 10 and 20 rows — no N+1 anywhere on the order path.

## 2.60.288
Two columns everywhere you were scrolling, and three controls that were lying to
you.

### Payments: keys on the left, settings on the right

`Store → Ecommerce → Payments`. Every row used to be a full-width label with a
520px box under it, so on a wide screen the right two thirds of every single row
was empty — and Tamara's twelve fields ran down one column with the API tokens
mixed in among the basket limits and the product exclusions.

Now: **① Keys from <provider>** on the left, counting itself as you fill it in
("0 of 4 filled in"), and **② How this shop uses it** on the right. Tamara's card
is **748px shorter** at desktop width with nothing removed. One column on a
phone. Cash on delivery gets a single section that says why it has no keys,
rather than an empty box next to a full one.

### ▲ Every error message in the console has been showing a green tick

You sent a screenshot of one: **"✓ That file was not accepted."** That was not
that screen's bug. The toast drew a tick for *every* message it has ever been
given, and painted it green — so "Could not save" looked exactly like "Saved",
on every screen, including Payments and the capture and refund buttons on an
order.

Failures are a **red triangle** now, and they stay up nearly twice as long,
because a failure is the one message you have to read rather than notice.

### ▲ The header's "Content width" slider does nothing, and now says so

`Appearance → Header → Bar → Content width` is only read when **"Header follows
the site width" is OFF** — and that switch ships on. So dragging it did nothing,
you got "Saved", and the header did not move. Its help text was blank.

It is greyed out now, with the reason under it and the path to the switch that
governs it. Faded rather than hidden: it is still the right control once that
switch is off.

**The header does already follow the site width** — measured at 1200px against a
1200px page at three different screen sizes. If it is not following on your shop,
you are on a package older than 2.60.284.

### The clip editor: two columns, and the cut offered where the upload ends

`Content → Shoppable video → All clips`. Every step is two columns now — title
beside caption, video beside cover, who-made-it beside permission, tagged
products beside the search.

The upload bar shows **the bytes as well as the percentage**, then a real second
stage — *"All of it has arrived. The server is checking the file and cutting what
it can."* — fired the moment the last byte leaves rather than at 99%. Both
endings now **stay on screen**; the panel used to vanish the instant the request
landed, which looks exactly like a stalled upload.

And the cover-and-teaser cut is its own section directly under the two file
boxes, marked **JUST UPLOADED**, instead of a button hidden at the bottom of the
cover panel. It reads your server before it speaks: where the shop can cut, it
says the cover and teaser **are** cut and offers to do it again; where it cannot,
it says so plainly and tells you the one thing left to do by hand.

## 2.60.287
Tamara and Tabby, Instagram, the page editor, the add-a-clip flow — and eleven
admin screens that finally have a web address.

### ▲ TWO PAYMENT GATEWAYS, AND WHAT YOU HAVE TO DO BEFORE EITHER TAKES MONEY

**Tamara** and **Tabby** are both built, both at `Store → Ecommerce → Payments`.
Neither is configured and neither is switched on — every credential box arrives
empty, which is how this ships.

**Nothing here has spoken to a real Tamara or Tabby server.** Outbound network
access is blocked on the machine this was built on, so every call is driven
against a recorded fake. The code is complete and tested; the handshake is not
proven. Before you take a real order through either, do one sandbox order end to
end: paste sandbox keys, Save, press **Register webhook**, press **Refresh
limits**, place one order, then cancel it and press **Release authorisation**.
Then do it once more with a capture and a partial refund. If a field name on
their side differs from what we expect, that sequence is where it shows up, and
it shows up on a test order rather than on a customer's.

**The gap Tamara had that this closes.** Every approval arrived by callback and
nothing else. A callback that never arrived left the order `pending` for ever —
holding stock and a coupon use — while the buyer had a live payment plan and
believed they had bought something. There is now a **Check for missed approvals**
button on the Tamara panel, and a command behind it, that asks Tamara directly
about orders in that state. It can also recover orders where the Tamara id was
never saved, which nothing could reach before.

**Releasing a hold.** Both gateways can now release an authorisation nobody
captured — the button sits on the order screen beside Capture and Refund. It
matters because a cancelled BNPL order leaves the customer's credit committed for
weeks: the shop has cancelled, restocked and refunded the coupon, and their
Tamara or Tabby account still shows an active plan for an order that no longer
exists. The button is only offered once the sale is off; it is refused on an
order the shop still intends to ship, which is the expensive mistake in the other
direction.

**Tabby needed one thing Stripe and Tamara do not.** Tabby takes no callback URL
from a dashboard field — it is registered through their API or it does not exist.
Until that registration is made, Tabby authorises money and then goes silent. The
button for it is on the Tabby panel.

### Instagram Profile — `Content → Instagram`

Your recent posts as a grid on the shop, with the profile box, likes and
comments. Five layouts, four profile styles, and a shortcode
(`[kbb_instagram layout="strip" limit="6"]`) to put it anywhere. **Configure now**
does the whole handshake with Instagram: it takes you there, takes permission,
comes back and fills in your avatar and follower count.

The six steps you must do at Meta's end are printed on the screen with ticks read
from what is actually saved — and the two we cannot observe from here are marked
with a dashed "?" rather than an empty tick that would imply we had looked.

**Thumbnails are copied to your own shop.** Instagram's picture addresses expire,
so a page pointing at one is a page that breaks on somebody else's clock. Tapping
a tile opens the post on Instagram; there is an opt-in in-page player that uses
Instagram's own embed.

**Ships off.** The module is off, the homepage rows draw nothing until you
configure them, and neither emits a single byte of empty band in the meantime.

### The video rail on the homepage

`Appearance → Homepage` has a **Video rail** row now. Choose which section it
shows at `Content → Shoppable video → Appearance → Homepage`, or use the
shortcode. You have two bands making a similar promise if you keep
`#KBeautyBliss spotted` as well — that is your call, and the screen says so where
you pick the section rather than deciding it for you.

### Adding a clip: five steps, and three things the screen was getting wrong

`Content → Shoppable video → All clips → Add a clip` is five numbered steps now,
with drag-and-drop, and it previews the video after upload from any source.

Three fixes worth naming, because each one was actively misleading:

- **A missing cover said "No video yet".** If your 64MB video uploaded perfectly
  but had no cover picture, the screen told you the video was missing. The
  obvious next move is to upload it again and watch nothing change.
- **The loop preview ignored your setting.** If you had moved the loop length to
  4 seconds, the preview still rewound at 2.5 and the words under it still said
  2.5 seconds.
- **Drag to reorder** the tagged products, which decides which product the tile
  shows. The arrows stay, for the keyboard.

### `Pages → User pages → Edit`

The seven content pages — FAQs, Delivery, Terms and the rest — are editable:
title and body in both languages, status, and the whole Search-engines panel with
the live Google preview.

**It will not let you create or delete one, and that is deliberate.** Those seven
pages are seven fixed addresses in the code. A page created at any other address
is a row nothing on the shop can reach — you already have **four** such pages from
Demo Pages, published and 404ing, and this screen is the first thing that tells
you so. It marks them **"No address on the shop"** and counts them at the top.

### Eleven admin screens now have a web address

A link to `Shoppable video`, `Instagram`, `Security`, `Cache`, `Build my
routine`, `Site layout`, `Footer`, `Cart page` or `Checkout page` used to open
the **Dashboard** — no error, no wrong heading, just the dashboard. Clicking the
sidebar row always worked, so nothing looked broken until you tried to send
somebody a link. All eleven now open where they say they do, by link or by click.

### Under the floor

- `docs/SEO-PREVIEWS.html` stopped changing on every test run, so a change to it
  now means a verdict actually moved. The SEO matrix reads **67 verified, 0
  missing** over the same 88 rows, up from 63 and 4.
- The release-an-authorisation endpoint is one file for every gateway rather than
  one per gateway. Two lanes built the same endpoint in the same round and both
  registered it; only the last would have survived, silently.

## 2.60.286
The video screens: no more flashing, a real search, and the section page in steps.

### The flashing is gone, and it was my fault

Switching a tab in the clip editor flashed, and saving flashed every tab one
after another. The dialog was being rebuilt from scratch on every repaint of the
screen behind it — so it re-ran its opening animation, re-fetched the poster and
lost its place. A tab click did that once; a save did it about seven times.

The dialog is now independent of the screen. Measured with a DOM observer: five
tab switches cause **zero** rebuilds, and so does a save. Saving also no longer
throws you back to the Details tab.

### Add from the library: searchable, and a third of the height

There is a search box that matches a clip's title **or** its creator handle, and
it filters as you type without asking the server. Rows went from tall cards to
55px lines, so roughly three clips fit where one did, and the list scrolls
inside its own box instead of pushing the page down.

### The section page is four numbered steps

**1 Name it · 2 Decide how it looks and who sees it · 3 Put clips in it ·
4 Put it on the shop.**

The shortcode — the one thing that actually puts a rail on your shop — was a
small grey chip beside the title. It is step 4 now, at a size you can read, with
a Copy button, and it warns you when the section is still a draft and the
shortcode would render nothing.

### ▲ AND THE 2–3 SECOND LOOP ALREADY WORKED — the screen was lying

The Files tab offered a "2–3 second loop" upload and said that without it the
tile shows a still picture. **That was false.** With no loop file at all, the
rail plays the full video and loops its first 2–3 seconds by itself. Verified in
a browser: the clips were stripped of their loop files, and the tiles went on
looping inside the first 2.5 seconds.

So you never have to make a second video, from any source. The badge that read
**"Poster only"** in amber — which looked like a fault — now reads **"Loops from
full video"** and is neutral, and the upload slot says plainly that a cut-down
file only saves your shopper bytes and that nothing is missing without one.

Files: resources/views/admin/partials/ugc-sections-screen.blade.php.

A cache-clear migration ships with it, as with every change to a console screen.

## 2.60.285
▲ APPLY THIS ONE. Two things you hit, and one of them has never worked.

### You could not add a section, and nobody ever could

The Sections and Appearance screens signed their saves with a security token
this admin does not issue — they looked for it in a tag the page has never had,
found nothing, and sent an empty one. The server refused every save with a
419 and the screen printed "That section could not be saved.", which describes
the symptom and hides the cause.

This was not a regression from a recent package. That path was wrong from the
day it was written; the demo sections existed only because they are written
straight into the database. Uploading a clip worked, because the library screen
signs correctly — which is why the fault looked like it was about sections.

Fixed to the token the rest of the console has always used. Verified: creating a
section now answers 201 and the section appears in the list.

### The demo data was importable and invisible

The Demo Content screen keeps its own list of cards, separate from the list of
things the importer can actually create. The Videos type was added to the second
and not the first, so you opened the page, saw nine cards, and reasonably
concluded it did not exist.

**It is there now: Safety -> Demo Content -> Demo Shoppable Video.** Two
sections, six clips, products from your own catalogue tagged on them.

### And the redesign you asked for in the same breath

The clip editor is no longer one long scroll. It is a tabbed dialog —
**Details · Source · Files · Products · Placement** — with the clip's poster and
title fixed at the top and **Save video** fixed at the bottom, so it is always
reachable. It is 820x435 on a desktop where it used to be a full-height column.

The browser's grey **"Choose File / No file chosen"** buttons are gone. Each file
is a row with its own icon, its size, and a Replace or Change button; the real
file input is still underneath, so the keyboard and screen readers work exactly
as before.

Everything is rebuilt on the console's own design tokens — the same radii,
shadows, easing and colour scale the rest of the panel uses — instead of the
hard-coded greys it had. That is most of why it looked a decade older than the
screens around it. Focus rings, hover states and a blurred backdrop come with
that, and the whole thing respects "reduce motion".

Files: resources/views/admin/partials/ugc-sections-screen.blade.php,
ugc-appearance-screen.blade.php, resources/views/admin/app.blade.php.

NO MIGRATION IS NEEDED BEYOND THE ONE IN 2.60.283 — but if you have not applied
.283 yet, apply it first: its migration is what drops the compiled copies of
these screens.

## 2.60.284
Two things this package STOPS, and both were already live.

### ▲ A FREE DELIVERY PROMISE YOU NEVER MADE

Switching on Store -> SEO & Meta -> Merchant listing published, on every product
page, a shipping rate of **0.00 AED to the whole UAE** — from a box you had
never filled in. The rate was read as "the value, or zero", and a blank value is
dropped before it gets there, so zero is what Google was handed.

You were one click from it. Your answer on returns is "we don't offer returns",
the code to publish that refusal now exists, and the ONLY way to publish it is
that same switch. The screen made it worse: the shipping box arrived holding 0,
and emptying it FAILED THE SAVE, because the validation refused a blank.

Three things had to change together and all three are here: the box accepts a
blank, ships blank, and says "Leave blank until you know"; and nothing about
delivery is published unless there is really a number in it. A typed 0 still
means free delivery, because somebody may mean that.

**So this is now safe to do, and it is what your answers add up to:**
Store -> SEO & Meta -> Settings -> Rich product results — switch the merchant
listing ON, set Returns policy to "We do not accept returns", and LEAVE THE
SHIPPING COST BLANK. That publishes your returns position on its own, with no
delivery claim beside it.

### ▲ THE HEADER SWITCH IN 2.60.282 DID NOTHING

"Header follows the site width" shipped two packages ago, reported itself as on,
and moved nothing. It set the header's width variable in one place while the
header itself set the same variable on its own tag on every request — and the
nearer one wins. The header stayed 1280 while the page went to 1680.

Fixed at the writer that actually wins, and the switch now ships ON, because you
asked for it in as many words. The header is now exactly as wide as the site:
matching at 1024 through 1680, and both stop at 1680 on wider screens.

**And it keeps ONE ROW by shrinking itself.** The menu's type and spacing taper
as the screen narrows — 13px down to about 10.75px at 1024 — instead of wrapping
onto a second line. One row at every desktop width, and nothing scrolls
sideways. The mobile header is untouched: 188,055 rendered properties were
compared across seven phone and tablet widths, and not one rendered box moved.

### ALL EIGHT CONCERN PAGES ARE ENABLED — and nothing is published yet

/concern/acne/ was the only one switched on. All eight are now, each with its own
English heading and intro. **Enabling them publishes nothing**: a concern page
only exists once at least three products are tagged for it, so today all eight
addresses 404 and the sitemap carries none of them.

That is deliberate, and it changes what you have to do: you no longer need a
package to publish a concern page. Tag three products at **Catalog -> Build my
routine** and that page exists. Tag them for eight concerns and you have eight.

### THE BUSINESS IS DESCRIBED AS ONLINE

You said you have no physical shop and operate only online, so the business type
now ships as "Online store". No address, phone or emirate is invented, and the
audit no longer asks you for an address you deliberately have not given.

On being open 24/7: schema.org's opening-hours field describes a door, and there
is no way to say "orderable at any hour" for a business without premises. The
shop used to let you type hours and then silently publish nothing; it now tells
you so on the audit screen instead.

Files: app/Support/Seo.php, app/Support/SeoAudit.php, app/Services/HeaderSettings.php,
app/Services/SiteLayout.php, app/Services/Seo/SeoSettings.php,
app/Support/ConcernCollections.php, app/Http/Controllers/Admin/AdminController.php,
app/Services/Translation/InterfaceStrings.php, resources/css/kbb/kbb.css,
resources/js/kbb/nav-fit.js, resources/views/admin/app.blade.php, and two
clear_caches migrations.

THOSE MIGRATIONS ARE NOT OPTIONAL. One drops the compiled admin screen; the other
drops a stale compiled class whose constructor signature changed, which would
otherwise be built with the wrong arguments.

## 2.60.283
Shoppable video, made findable: one sidebar row instead of three, and demo data
so the screens can be understood by looking at them.

NOTHING ABOUT THE SHOP CHANGES. This is entirely a change to where things sit in
the admin, plus a new Demo Content type you have to import on purpose.

### ONE ROW, THREE TABS

  Content -> Shoppable video          <- the only row now; opens all your sections
    * Sections      every section, each with its shortcode, and New section
    * All clips     the whole clip library
    * Appearance    what a rail looks like

There used to be three rows for this one feature -- Content -> Shoppable video
for the clips, Content -> Video sections for the rails, and Appearance -> Video
rail for the look. Three doors, and nothing said which was the way in. Every
link and bookmark you already have still works.

The flow is the one you asked for: the row opens the list of all sections; click
Open on a section and you get its videos in the order they appear on the shop;
click Edit on a video and everything about that clip opens in a popup -- title,
caption, where it came from, its files, the products on it, and when it shows.

### DEMO DATA: Safety -> Demo Content -> Videos

Two sections, six clips, with real products from your own catalogue tagged on
them, and one clip deliberately placed in BOTH sections -- because a clip can
belong to several rails at once, and that is the hardest thing to guess from an
empty screen.

The clips are published and carry a real video file, so a rail built from them
genuinely renders and loops rather than showing a still. The footage is an
abstract gradient this application generates -- deliberately not something that
looks like real creator footage, because a demo that did would be a photograph
of a person this shop has no permission from, which is the exact thing the
permission gate exists to prevent.

Remove clears every row AND both media files. Nothing is left behind.

To see a rail on the shop you still need two deliberate steps, unchanged: switch
the module on at Store -> Modules -> Shoppable video, and paste a section's
shortcode onto a page.

Files: resources/views/admin/partials/ugc-sections-screen.blade.php,
ugc-library-screen.blade.php, ugc-appearance-screen.blade.php,
app/Http/Controllers/Admin/DemoContentController.php,
app/Support/UgcDemoMedia.php, app/Services/ModuleRegistry.php, and
database/migrations/2027_02_20_000000_clear_caches_ugc_one_front_door.php.

THAT MIGRATION IS NOT OPTIONAL. Three admin screens changed and compiled screens
are cached by path, so without it the console still draws three rows, two of
which open screens with no tab strip -- from a package that reported success.

## 2.60.282
Two lanes: the site is 1680px wide now, and the shoppable-video rail has a
storefront.

### ONE NUMBER FOR HOW WIDE THE SITE IS — and this one CHANGES the shop

This is the one setting in months that ships at a NEW value rather than the old
one, because it was asked for in as many words. Every other setting in this
package ships at the value the page already had.

  Appearance -> Site layout -> Page width -> Site width          1680px
  Appearance -> Site layout -> Page width -> Side gutter         22px
  Appearance -> Site layout -> Page width -> Header follows the site width  off
  Appearance -> Site layout -> Product grid -> Smallest card     260px
  Appearance -> Site layout -> Product grid -> Smallest card, shop listing  220px
  Appearance -> Site layout -> Product grid -> Never fewer than / Never more than
  Appearance -> Site layout -> Product grid -> Gap between cards
  Appearance -> Site layout -> Product grid -> Or pin an exact count  Automatic

Below both tabs is a table of all seventeen screen sizes showing the container
width and both column counts, recalculated as you drag a slider, with 1680
marked. It is arithmetic, not measurement.

WHAT YOU WILL SEE. On a 1680px screen the product grid goes from four columns to
FIVE -- on the homepage rails, /shop, related products and brand pages. At 1536
and above, likewise. A phone stays at two columns everywhere, and 390px and
1280px are unchanged, so nothing you look at day to day moves.

WHY IT NEEDED A LANE. The shop carried SIX different page widths (1400, 1352,
1240, 1180, 1160, 1080) with a dead duplicate rule 1,456 lines above its live
twin, and the column count was decided in SIX places -- one of them a complete
second copy of the grid stylesheet inside kbb.css, which was the copy actually
winning on every page. They disagreed with each other: at 1180px the homepage
drew three columns and /shop drew four, on the same screen, at the same moment.
Ten media queries are gone; there is now one rule that derives the count from
the space available.

AND TWO SETTINGS THAT HAVE NEVER WORKED are now named honestly: Appearance ->
Product styles' Columns-tablet, Gap, Card roundness and Image shape have never
moved a storefront pixel, because the code that emits them is called from the
admin screen and nowhere else. Not fixed here -- it is a small round of its own.

A BUG FIXED ON THE WAY: every product page scrolled sideways by 8px on a 320px
phone. It does not now.

Cart, checkout and the slim footer are deliberately NOT on the new width. All
three already have their own width sliders, and all three are pages asking for
money.

### THE SHOPPABLE-VIDEO RAIL — the storefront half. Ships OFF.

  Store -> Modules -> Shoppable video                  the master switch, OFF
  Content -> Video sections                            make a section, order its
                                                       clips, copy its shortcode
  Content -> Video sections -> Open -> Edit            the per-video popup
  Appearance -> Video rail -> Layout                   tiles across on a phone,
                                                       tile width, gap, radius
  Appearance -> Video rail -> Motion                   the loop and its length,
                                                       how many play at once,
                                                       autoplay on open, sound
  Appearance -> Video rail -> What a tile shows        rating bar, caption,
                                                       handle, count, old price
  Appearance -> Video rail -> Likes

Drop a rail anywhere -- a page, a product, an article -- with the shortcode
[kbb_videos section="..."], which each section shows you. A clip can appear in
several rails at once.

THE RATING BAR IS INSIDE THE PRODUCT BOX and costs the card ZERO height: it sits
on the end of the brand line, the only row with room. It is type only, with no
pill or badge behind it, because the contrast arithmetic decided it -- the amber
in the proposal is about 1.7:1 on white and fails outright, while the numbers
shipped are 15.5:1, 7.3:1 and 4.0:1. One star rather than five, because five at
a size that fits are 38-45px of 8-9px glyphs and read as texture next to a brand
name.

THE 2.5-SECOND LOOP RUNS ON EVERY TILE, and that is a consequence of this module
embedding nothing: a clip a shopper can open is always one this shop serves.
Instagram and TikTok URLs are for CREDIT -- upload the file and enter the source
URL, and you get the loop AND the attribution. A genuine embed would be a still
photograph in the rail that opens their player, with their autoplay rules.

Mobile columns: a peeking 1.x, one, one-and-a-peek, two, 2.3, three, or a
two-column grid. Every one measured with no sideways scroll at 390px and 1280px.

MATCHED AGAINST THE PROPOSED DESIGN BY MEASUREMENT, not by eye: both pages in
the same browser at the same width, 21 elements and 45 CSS properties each. Eight
differences, and every one is the rating bar that was asked for -- except two
that are one sixty-fourth of a pixel, from the Arabic money formatter.

Files: the two lanes' CSS and Blade, routes/site-layout-admin.php,
routes/ugc.php, four migrations (two of them cache clears, which are NOT
optional -- new route files and eleven changed screens are cached by path).

## 2.60.281
The SEO back office: see what Google will print BEFORE you save, and one screen
that says what is still waiting on you.

WHAT CHANGES ON A SCREEN YOU ALREADY USE: a sixth tab appears on Store -> SEO &
Meta, and a Google result appears above the SEO boxes on four editors. No control
was added, removed, renamed or moved, and clicking SEO & Meta still opens the
Settings tab it opened yesterday.

A LIVE GOOGLE RESULT, WHERE YOU ARE EDITING

  Store -> SEO & Meta -> Settings -> Search appearance   (the homepage)
  Catalog -> Categories -> the category -> above "SEO title"
  Catalog -> Brands -> the brand -> above "SEO title"
  Content -> Blog Posts -> the article -> Search engines -> above "Page title"

  (Products already had one. Pages still cannot have one, because there is no
  page editor to put it in -- see below.)

  This matters because what is in the box is NOT what Google receives, and four
  rules sit in between that no screen showed you: an EMPTY title box publishes
  the row's own name through the title template, so the site name gets appended;
  a FILLED one is the whole title and the site name is NOT appended; %%title%%
  is substituted on the server and a token the engine is not handed is DELETED
  -- the defect that once published the site name alone on 671 product pages;
  and an empty description falls through the row's own description, then the
  default description, then to nothing.

  The preview asks the SERVER what would be published rather than working it out
  again in the browser. That is deliberate: a second copy of those rules would be
  a fifth dialect of them, and the lane's own test caught the second copy getting
  a category archive wrong before it shipped. For a box left EMPTY the answer is
  read off the live page itself.

STORE -> SEO & META -> OVERVIEW

  Every row is computed and DISAPPEARS when the work is done -- nothing here
  nags for ever. It reports, in three bands ordered by what each costs you:

  - The whole shop hidden from Google, in a band of its own and above everything
    else. One select, no other symptom, and while it is true every other line on
    the screen is a reading of a switched-off machine.
  - Concern-page tagging progress, per concern ("0 of 3 tagged").
  - A business type that says you have a shopfront with no address behind it, or
    map coordinates filled in under a type that cannot publish them.
  - FAQ markup in whichever of its two wrong states you are in: switched off
    while your pages ARE written as questions (with the count it would publish),
    or switched on while no page is. Neither has any symptom on the shop.
  - Six settings whose absence has a real consequence, each saying what it is.
  - The audit's own findings, ranked, with ten "Take me there" buttons. A
    finding with a count of zero is one green line instead of the twelfth
    identical card saying "None" -- which is how the one that is NOT fine was
    getting missed.

Files: app/Http/Controllers/Admin/SeoPreviewApiController.php,
app/Http/Controllers/Admin/SeoTasksApiController.php, routes/seo-back-office.php,
resources/views/admin/partials/seo-back-office.blade.php and the three editor
partials, app/Support/AdminCapabilities.php, app/Support/SeoAudit.php,
app/Support/Seo.php, app/Services/Seo/FaqSchema.php,
resources/views/admin/app.blade.php, routes/web.php, and
database/migrations/2027_02_10_000000_clear_caches_seo_back_office.php.

THAT MIGRATION IS NOT OPTIONAL. A new route file does nothing until the compiled
route table is dropped -- without it all four previews 404 and the Overview says
it cannot read the shop -- and five changed screens are cached by path, so a
stale copy is a console with no Overview tab and no preview anywhere, from a
package that reported success.

KNOWN, AND NOT FIXED IN THIS PACKAGE: content pages carry SEO fields and are
scanned by the audit, and there is still no page editor in this console, so a
finding against a page is not actionable anywhere. The page editor is Phase 11.

## 2.60.280
Six SEO features that were finished, tested, merged -- and emitting nothing.

Two lanes each reported that their tests are green EITHER WAY, because the other
half of every change they made sits in a file the lane does not own. Nothing was
broken and nothing was missing; the wire was. This package is that wire, plus the
test that goes red if any of it comes loose.

WHAT THE SHOP DOES DIFFERENTLY AFTER APPLYING THIS: nothing, until somebody moves
one of the two new controls. Every setting here ships at the value the page
already has.

TWO NEW CONTROLS

  Store -> SEO & Meta -> Settings -> Google Merchant -> "Returns policy"

    The owner's answer to the returns question was "at the moment we don't offer
    returns", and there was no value anywhere in this application that could SAY
    it. The return-window box at 0 published no return policy at all -- which is
    SILENCE, not a refusal, and Google reads the two differently. Three options:
    Not stated (shipped) / We do not accept returns / We accept returns within
    the window below. The days box below it is now only read by the third.

  Store -> SEO & Meta -> Settings -> Sitemap & robots -> "FAQ markup on content pages"

    Publishes the questions and answers on a page written as questions, so an
    answer engine can read them as pairs. A heading counts as a question only
    when it ends in a question mark -- no page slug is named anywhere in the
    code, so it works on the FAQ page and on any future page written the same
    way, and does nothing on the ones that are not. Ships OFF, because turning it
    on adds a schema.org node to seven pages Search Console has already fetched.
    Google no longer shows an FAQ drop-down in results; the value now is the
    answer engines.

FOUR THINGS THAT CHANGE MARKUP, none of them a setting

  - An Article now carries `url` and `dateModified`. dateModified is on Google's
    recommended list and posts.updated_at was already on the row.
  - A per-row canonical override is scheme-checked before it becomes an href. A
    canonical of `javascript:alert(1)` was published verbatim in
    <link rel="canonical">, og:url, Product.url and every hreflang href, on
    products, categories, brands, posts and pages alike. SEVERITY IS LOW AND IS
    STATED AS LOW: a canonical href is not navigable and every value is escaped
    with ENT_QUOTES, so it could not break out of the attribute. It is fixed
    because the rule in this project's own notes says a URL from a setting is
    scheme-checked before it becomes an href. Such a value now becomes an address
    on this site instead of the operator's string.
  - /sitemap.xml builds its hreflang alternates through the same choke point the
    page's canonical uses. With Arabic addresses switched on it would otherwise
    advertise one address under /ar while the page declared another -- a cluster
    that disagrees with itself, which Google drops. Byte-identical today.
  - An internal link is answered in the reader's own spelling, saving an Arabic
    shopper one redirect hop per product card once Arabic addresses are on. It
    sits inside the existing English fast path, so an English page does not pay
    for it at all.

AND THE ARABIC ADDRESS REWRITER IS MOUNTED -- after the redirect checker, not
before. Prepending it would have made the retrofit redirect and the slug rewrite
bounce a visitor between two addresses for ever. It returns immediately while
Arabic addresses are off, which is how this ships.

Files: app/Support/Seo.php, app/Support/Url.php,
app/Http/Controllers/Store/PageController.php,
app/Http/Controllers/Store/SeoFilesController.php,
app/Http/Controllers/Admin/AdminController.php,
app/Providers/AppServiceProvider.php, resources/views/admin/app.blade.php,
tests/Feature/SeoIntegratorWiringTest.php,
tests/browser/seo-integrator-wiring.mjs, docs/seo-wiring-shots/.

Plus everything Lanes S5 and S6 merged ahead of it: the full 81-item SEO research
matrix driven item by item (docs/SEO-PREVIEWS.html, generated by the suite so it
cannot drift), a content page's own SEO fields finally being READ, a noindexed
page no longer submitted in the sitemap, the Arabic-address machinery built and
measured and shipped OFF, and a redirect written for an Arabic address that used
to 404 for every real visitor because the path was never urldecoded.

## 2.60.279
Shoppable video gets its storage side: Content -> Shoppable video. It ships OFF
and adds nothing to any page until the rail is built.

WHAT THE OWNER DOES PER VIDEO, and the screen ASKS THE SERVER which world he is
in before he uploads anything.

  With ffmpeg on the box -- HE UPLOADS ONE FILE. New video, a title, choose the
  MP4. The poster frame and a 2.5-second silent 360x640 loop are cut from it on
  that same request. Then the creator's handle and link, Permission -> granted,
  search and add the products in the order he wants, Status -> published, Save.

  Without ffmpeg -- one file and one picture. The same steps, plus choosing a
  poster from the Media Library.

He is never asked for a second video either way. `which ffmpeg` in Servers ->
Launch SSH Terminal is ten seconds and settles it; if it is absent but
installable anywhere on the box, KBB_FFMPEG in .env points at it.

A VIDEO IS THREE FILES, which the previous round's measurement decided: the full
clip at 720x1280 for the player, a 2.5s 360x640 teaser for the rail, and a
poster. `poster_only` is a FIRST-CLASS PUBLISHABLE STATE rather than an error --
the teaser is deliberately absent from the publish gate, and adding it reddens
two tests. The poster IS required, because the tile reserves its box from the
poster's own width and height, read with getimagesize(), so layout shift stays at
zero even on a box with no ffprobe.

▲ THE UPLOAD IS THE MOST DANGEROUS INPUT THIS SHOP TAKES, and it is checked in
five steps, in this order: (1) size BEFORE the file is opened -- a refusal, not a
truncation; (2) finfo over the bytes on disk, never the claimed type; (3) our own
magic-byte read, with ISO-BMFF's major brand checked; (4) the two readers must
AGREE on the same stored extension; (5) a last look for a PHP open tag in the
first 512 bytes. Then a GENERATED filename with the extension the bytes earned.

A PHP script named clip.mp4 is refused with nothing written to disk, and the
owner is told: "That file is text/x-php and a clip has to be MP4 or WebM. The
check reads the file itself, not its name -- renaming it will not help." Also
refused and pinned: a real MP4 with <?php stuffed into its header, an Ogg file
finfo is perfectly happy with, a QuickTime-branded ftyp, an 11-byte "JPEG", a
64 MB+1 clip, and a name carrying ../ and a shell metacharacter.

Files land in public/uploads/ugc/ and NOT the storage symlink, which this
zip-deployed app never creates -- the reviews module paid for that lesson once.

▲ AND THE POSTER HAS NO FILE INPUT AT ALL. The shop's own guard caught the first
draft: the Media Library must be offered for any media upload in the back office.
It is now the poster's only way in, the picture is COPIED into /uploads/ugc/
rather than referenced (a poster is deleted with its video) and re-checked byte
by byte.

Its own capabilities, ugc.view and ugc.manage, rather than reusing content.manage
-- an operator who may write an article is not thereby someone who may publish a
customer's face on the shop, and the rights fields on this screen are why. Writes
above reads, first match wins.

SHIPS OFF, asserted harder than a picture can: five storefront pages render
byte-for-byte identically with the table empty and with a published video in it
-- granted rights, a poster, a clip, three tagged products. Zero <video>
elements, no mention of the clip anywhere in the HTML. No ModuleRegistry row,
deliberately: the master switch belongs beside a storefront section that does not
exist yet, and the framework guard fails a `live` row with no reader. No public
/api endpoint either -- the allowlist is written and pinned so the rail round has
one to reach for, but an unauthenticated endpoint shipped ahead of its consumer
is a surface nobody is watching.

FIFTY MUTATIONS RUN, FORTY-THREE RED AND SEVEN GREEN, and the green ones earned
the round: four were redundant-by-design and are now documented as second locks
rather than presented as the guard; two did not reproduce a defect at all and
were replaced with ones that do. The green run found four real test gaps and ONE
REAL BUG THE REFACTOR HAD INTRODUCED -- getSize() read AFTER move() throws "stat
failed", a 500 on a SUCCESSFUL upload.

Also in this package: the storefront's own product card and grid now carry a
declaration of the relations they read, the cost, and every caller -- the
component-contract work from the previous round.

Files: taken from the built zip, every one diffed byte for byte against the repo.

## 2.60.278
A mistyped address on Store -> Mail is refused out loud instead of silently kept.
The homepage stops linking its category tiles at an address that redirects.

A MISTYPED REPLY-TO SAVED "SUCCESSFULLY" AND KEPT THE OLD VALUE. Driven
first-hand before anything was changed: posting "not an address" answered
HTTP 200 {"ok":true,"configured":true,"missing":[]}, the stored value was
unchanged, and the screen said "Mail settings saved" while repainting the box
with the address that was already there. The only evidence was the box quietly
reverting, which reads as a redraw rather than a refusal.

▲ AND THE OBVIOUS FIX WOULD HAVE MOVED THE SILENCE RATHER THAN CLOSING IT. The
proposal was one line: add mail_reply_to to the controller's $checks list. But
$checks is Laravel's `email` rule and the writer is filter_var
FILTER_VALIDATE_EMAIL, and over a 22-address corpus SIX spellings pass the rule
and are refused by the writer -- a@b, a@example, a@127.0.0.1, a quoted local
part, and two with non-ASCII. So mail_merchant_address had the IDENTICAL silent
drop for all six, despite being the field that was treated as covered BECAUSE it
is on that list. The class is fixed rather than the instance.

MailSettings::save() now returns the refusal map, which is the hand-off
PayShipRules and BuildMyRoutine already make and which this class was the only
one of the four callers not to make. Store -> Mail answers 422 naming the field:
"Reply-To address" is not a valid value and was NOT saved -- what was stored
before is unchanged. Everything else on this screen was saved. The console
already renders that shape, so the admin bundle did not change. Nothing stored
moved for any value accepted today, and the refusal is reported rather than made
atomic -- atomic would stop the siblings of a refused key being written, which is
what happens today.

Admin path: Store -> Mail -> Other settings -> Reply-To address, and the same
refusal now also reaches Store -> Mail -> Who the message comes from -> New-order
alerts to.

THE HOMEPAGE CATEGORY TILES LINKED AT AN ADDRESS THAT 301s. HomeController
selected id, name and slug for the tiles. `path` was not among them, so
Category::url() fell through to buildPath(); `parent_id` was not among them
either, so the walk found no parent, ISSUED NO QUERY, and stopped at the leaf. A
category nested two deep therefore got a tile linking to a URL that answers 301
to the real one -- on a link the homepage printed itself.

The same omission caused the speed and the wrongness, which is why no query count
ever showed it: an instrumented run of the whole suite found 1,821 of the
application's 2,024 ancestry walks coming from this ONE loop, every one of them
answering nothing.

Nothing on the shop as shipped was affected -- every seeded category is a root.
It bites a nested tree, which is exactly what the WooCommerce import produces
("nested to four levels"), so it would have arrived WITH the migration.

`'path'` added to the select. Zero extra queries: the tiles still cost the same
number of category statements with a nested tree as with a flat one, which is
pinned, so a later edit that fixes an href by making the loop walk for real fails
on it.

AND THE CATEGORY WALK NEEDS NO MIGRATION, which was the open question. categories
.path already IS the materialised column, recomputed on every structural write by
five call sites, and read first by both Category::url() and CategoryPath::
canonicalPath(); buildPath() is the fallback. Measured on a real archive at five
depths: 4 statements flat when `path` is set, 4/5/6/7/8 when it is NULL -- and
once per page, not three times, because the first walk loads the chain onto the
instance the other two call sites get. Production nests four deep, so the worst
real case is three extra single-row primary-key reads on one page, only on a row
whose path has not been recomputed. A recursive CTE would buy 3 queries on a page
that is already 4.

▲ AND THE ORDER-EMAIL N+1 DID NOT EXIST. The brief that sent a lane after it
misread the lazy-load census: it counts relation READS, not queries, and a lazy
hasMany is ONE query returning every row. Measured at 1, 2, 5 and 10 lines: 3
statements for the presenter and 6 for a whole send, FLAT, before and after; an
order email makes zero `products` queries at all. What changed is that the
presenter now says loadMissing('items') out loud, so it no longer depends on its
five callers having remembered, and it survives preventLazyLoading(). The lazy
recorder printed Order::$items => 2 before and (none) after. The guard asserts
slope AND total, because a mutation showed slope alone has a hole: a constant
extra query leaves the slope at 0.00 and only the total catches it.

Files: taken from the built zip, every one diffed byte for byte against the repo.

## 2.60.277
You can see the homepage before you publish it. The mail password is unreadable
by construction. And the recommended rail stops fetching a whole product row per
card.

APPEARANCE -> HOMEPAGE -> PREVIEW, between Layouts and Sections. Desktop 1280 and
Mobile 390, drawn from the arrangement currently on screen, saving nothing.

Most of this was already built and the lane said so rather than rebuilding it:
the 17-section registry in template order, the device flags, the grid skins, the
ordering (a shop on the template's own order emits no style element and no class
at all), and HomepageContent has been on ModuleSchema for several rounds. The gap
was never the controls. BOTH SCREENS PUBLISHED STRAIGHT TO THE LIVE SHOP and
neither could show you the page -- the only way to see what an arrow or a device
switch did was to press Save, on the live shop, and open the storefront in
another tab.

Three properties pinned rather than promised: a preview of the configuration the
shop is already on is BYTE-IDENTICAL to GET /; a preview of an UNSAVED
arrangement equals what the shop serves once it is saved, proved by two requests
through two code paths; and it writes nothing -- the settings row is untouched,
no cache key is evicted, and the reader handed to the renderer THROWS on save().

▲ TWO OF ITS ELEVEN MUTATIONS WERE LIVE DEFECTS RATHER THAN CONFIRMATIONS. A
stored null has always meant SHOWN, and the obvious migration turns it into
(bool) null -- 34 rows went dark in the corpus. And the preview first wrote its
stylesheet tags against http://localhost, because Url::to('/') is root-relative:
three ERR_CONNECTION_REFUSED and the homepage drawn in Times New Roman. Invisible
to a suite whose APP_URL and request host are both localhost, so its test names a
host of its own.

▲ ONE THING TO KNOW BEFORE TRUSTING IT: the preview renders in the admin's own
session, so the storefront header draws the signed-in account panel. It is what
the operator sees in the next tab, not what a stranger gets, and it is always
English. Both are named for a second round rather than quietly left.

The shop did not move: / fetched with and without the change is 88,667 bytes both
times, differing by the CSRF field alone.

STORE -> MAIL ONTO THE SHARED SCHEMA, and the headline is a NEGATIVE RESULT
established before a line of the migration existed: NOTHING LEAKS TODAY. A real
password was planted through the screen's own save, the transport pointed at a
host that refuses so a genuine SMTP failure was produced, and its literal,
URL-encoded and base64 spellings looked for across ALL 178 parameterless GET
routes with an owner session, plus all(), lastTest(), the settings table,
Setting::map(), mail_deliveries, laravel.log, the failing test-send's own result,
and the credential column past the model's cast. Not found anywhere. The test
kept from it has no list to extend, so the next endpoint added is walked on the
day it is added.

The `secret` type is enforced by a TYPE SIGNATURE rather than a convention:
SecretStore is put() + has() and NO GETTER, and ModuleSchema::write() types its
parameter as that interface -- so the schema physically cannot read a credential
back. read() omits the key entirely, fields() emits it with no value and no
default, and cast() THROWS on a secret, which is what stops a future normaliser
swallowing the "-" sentinel that means "forget the password".

▲ AND A MUTATION THAT PASSED IS WHAT FOUND THE REAL RISK. Dropping
canonicalTransport() from save() left both smtp and log falling back to the
server transport, with the shop STILL SENDING and no error anywhere -- exactly
the failure the previous round declined this screen over. It passed first time
because the test posted the canonical key while the console posts the sentence;
the test now posts what the screen posts. A delivery proof runs a real message
through each transport and reads back the transport class the mail manager built
and the row the shop's own log wrote: identical on both revisions.

No control was added, removed, renamed or moved on Store -> Mail, and all four
screenshot pairs are byte-identical.

THE RECOMMENDED RAIL STOPS FETCHING A WHOLE PRODUCT ROW PER CARD. Appearance ->
Cart page -> Recommended rail ran one `select * from brands` per card -- 11, 12,
15, 20 statements for 1, 2, 5 and 10 cards, with the cap at 24 -- and the
`select *` was the other half that no statement count sees: a longText
description plus three json columns fetched for every card, to draw a name and a
price. Now flat at 11.

Latent on this shop today: the rail renders only on the squeeze layout, and
layout ships as classic with no products picked. It fires the moment both change.

AND TEN OTHER PAGES WERE MEASURED AND ARE FLAT -- /shop, category, brand and
concern pages, the product page across variations AND gallery images AND reviews,
the Journal index, an article's body images, the drawer, and /ar/shop -- each
varied at 1, 2, 5 and 10 with every fixture PROVEN to render what it varied
before a count was compared.

▲ TWO MUTATIONS CAME BACK GREEN, AND THAT IS WHAT FOUND TWO BROKEN TESTS rather
than review. Dropping the column list passed because /cart issues three products
statements and the matcher kept the wrong one. Dropping brand_id passed because
the case asserted the page contained the brand's initials -- and initials() caps
at the first letter of each word, so for a one-word brand it was asserting that an
HTML page contains the letter "B".

A measurement trap now written down: A FRESH FIXTURE PER SIZE CAN MEASURE NOTHING
AT ALL. Rebuilding the cart per size left the route-cached controller answering
out of a service bound to the row just deleted, so the page rendered an EMPTY
basket -- 7 statements, no rail, and a perfectly flat line across every size. It
looks exactly like a page that is already fine. Three of ten fixtures rendered
zero of what they varied on the first attempt.

Files: taken from the built zip, every one diffed byte for byte against the repo.

## 2.60.276
A setting was being printed unescaped on the homepage. Settings stop being
trusted on the way out of the table. The cart stops paying a query per line.
And the suite is green on MySQL for the first time.

▲ AN UNESCAPED SETTING ON THE HOMEPAGE, and it was found while driving
something else. store/home.blade.php builds the promo ticker from three chips and
prints them through {!! !!}. The two beside it are escaped -- e($homeDeliveryText)
and a constant carrying its own <b> -- and the third, `home_ticker`, went in RAW.
A value of `<img src=x onerror=...>` rendered into the homepage verbatim, measured
in a rendered response. Reachable from Appearance -> Homepage content -> Other
wording AND from any import path, which is the worse half: an imported value never
passes a human.

That is CLAUDE.md rule 5 word for word -- "Anything printed unescaped is a
constant, never a setting." Fixed with e() AT THE PRINT SITE rather than a strip
in the cast, because escaping belongs where the string is printed and holds for a
row that never went through a screen. e() over ordinary wording is the identity
and the shipped default is no chip, so nothing moved.

SETTINGS ARE RE-DERIVED ON READ, not trusted. Two parts of this codebase held
opposite positions on whether a stored value is trusted on the way out, and the
disagreement was settled by PLANTING ROWS the way an import, a hand-edit or a
restored backup does, then reading them back through each module's own public
reader. Before:

    BuildMyRoutine.steps_mode   select  planted evil-not-an-option  -> verbatim
    BuildMyRoutine.offer_scope  select  planted <script>alert(1)</script> -> verbatim
    BuildMyRoutine.offer_coupon text    planted 9,000 chars (cap 5,000) -> 9,000
    PayShipRules.cod_min        money   planted -50000              -> -50000
    PayShipRules.cod_max        money   planted '12.50'             -> 12

The first two are rule 5's own sentence failing -- "a select stores one of its own
options or the default". The last is castInt()'s documented "hundredfold error
that reads back as a plausible number", refused on the way in and reintroduced on
the way out. A guarantee that holds only for values that arrived the expected way
is a habit, not a boundary. Proved idempotent through the REAL write() and read()
rather than cast() twice in memory, because the tables stringify and that is
exactly where such a claim breaks: a shop whose values came from its own screens
is byte-identical, and only rows no screen could have produced move. A read-side
refusal returns the declared default, never null, because a reader has no
rejection channel and a page must render.

THE LAST THREE SETTINGS CLASSES are on the schema -- Store -> Reviews -> Review
Settings, Reviews -> Badge Themes, Reviews -> Rating Capsule, Platform -> Cache.
345 recorded calls replayed byte-identically, NOT ONE exemption. And round 1's
corpus could never have caught the stated blocker: its boolean inputs do not
include the literal string 'null', which is the entire third dialect.

▲ AND THERE WERE TWO NEW DIALECTS, NOT ONE. ReviewBadgeSettings::colour() is a
third HEX dialect nobody had written down: it requires the '#', EXPANDS #abc to
#AABBCC, and upper-cases. Folding it into the existing repair arm looks free --
identical colour to a browser -- and breaks activeTheme() two hundred lines below,
which decides a shop's badge preset by STRING-COMPARING the stored six digits. A
shop on Classic would have read back as "Custom" while the badge drew identically.

THE CART STOPS PAYING A QUERY PER LINE. /cart issued 10, 11, 12, 14 and 17
statements for 1, 2, 3, 5 and 8 variant lines -- one product_variant_attribute_value
join per line, singular `= ?` not `in (...)`, because ProductVariant::label()
reads a relation CartController::loadCart() did not eager-load. It is 10 FLAT
after: slope 1.0 to 0.0, from one entry spelled character for character as the
checkout already spells it.

Why nothing caught it: StorefrontQueryBudgetTest's cart fixture puts no
product_variant_id on any line, so label() short-circuits on the null and the
relation is never touched -- AND A BUDGET CAPS A TOTAL WITHOUT SAYING HOW IT
GROWS. Nothing a shopper sees moved, proved rather than asserted: /cart from a
four-line basket with a two-axis variation is byte-identical before and after
apart from the CSRF token.

BOTH ENGINES GREEN, WHICH HAS NOT BEEN TRUE BEFORE. Tests run on SQLite and the
live shop runs MySQL, and nine cases failed only on MySQL. All nine were tests
asserting the ENGINE rather than the property; none was a defect in the shop:

  - carts.token is char(36) and a FIXTURE wrote 40 characters. SQLite's grammar
    compiles string(), char() and uuid() all to the bare word `varchar`, so the
    width never reaches the table and the over-long write is silently accepted.
    Every production writer writes a UUID, exactly 36. No basket was ever lost.
    That had to be ESTABLISHED rather than assumed, because widening the column
    would have been the obvious and wrong fix.
  - One counted `"addresses"` in the query log; MySQL quotes with backticks.
  - One pinned a statement TOTAL, and Schema::hasColumn() is one
    information_schema select on MySQL against two pragmas on SQLite -- so the
    number pinned the driver. It now counts work statements and schema probes
    separately, which is strictly stronger.
  - posts.seo is a native JSON column on MySQL 8, which normalises object key
    order on write, and toBe() is assertSame.
  - One scanned the payload for the database credentials -- and on SQLite both
    are empty, so the loop continued and THE SCAN NEVER RAN. Nothing leaked; the
    single hit is the shop's own name inside its own asset filename.

The standing check outlives the round: the widths do not exist on SQLite to be
read, so a checked-in fingerprint of all 287 char/varchar columns across 75 tables
is watched over every statement every Feature test issues. The engine that knows
the widths verifies the map on the MySQL job, in both directions; the engine that
does not know them uses it. Measured cost 338.80s with against 342.64s without,
inside the run-to-run noise, zero false positives across 5,877 cases. Suite-wide
on purpose, because the defect it was written for was IN A FIXTURE -- which no
endpoint-shaped guard would ever have been pointed at.

Also: describe() called castBool() with no mode, so it described a stored 'off' as
"off" for the ten modules whose cast reads it as TRUE.

Files: taken from the built zip, every one diffed byte for byte against the repo.

## 2.60.275
A markdown on a variable product stops being invisible to the whole shop. Four
more modules onto the settings schema, with the rules that are not policy kept as
rules. And the owner can delete the export folder -- that half travels as a
WordPress plugin, not in this package.

A MARKDOWN NOBODY COULD SEE. A variable product with a live variation markdown
showed NO Sale badge, NO struck price, and never appeared under "On sale", while
its own per-option chips already read "Save 25%". The shop knew about the
markdown one level down and could not say it about the product.

The shop was not disagreeing with itself here -- it was uniformly, silently
wrong. That is why nothing caught it: the instrument this codebase uses for
pricing defects is "do two surfaces disagree", and none did.

Measured at the parent revision against a real product (options AED 120 and
AED 190, marked down to AED 90 and AED 140, window open): price cell
"AED 90 - AED 140", badge cell EMPTY, and /shop?sale=1 listed 6 products without
it. The product page's AggregateOffer was already right -- lowPrice and highPrice
come from the variations -- with one hole: priceValidUntil is emitted only when
isOnSale(), so a genuinely marked-down variable product told Google nothing about
when the price stops applying.

"A PARENT HAS NO COMPARE-AT PRICE" IS TRUE OF THE ROW AND FALSE OF THE PRODUCT.
The figure a variation's sale_price is a markdown FROM is that variation's own
price. Every surface collapses the product to one number, the from-price
MIN(charged), so the figure to compare it against is MIN(regular) -- literally
what the tile printed the day before the markdown started. NOT "the regular price
of whichever option is cheapest now": on options at (regular 100, sale 40) and
(regular 50, no sale) that rule advertises 60% off a saving of 20%. There is a
test for exactly that pair.

NOTHING CAN LOSE A BADGE, by case analysis rather than by hope: where `price` is
non-null the expression is identical, and where it is null the old test was
effectivePrice() < 0, always false. Additive on both the PHP and the SQL side.

▲ THREE STRIKETHROUGHS WOULD HAVE PRINTED "AED 0". Turning isOnSale() true
switches on three <del> sites that all read the NULL parent column -- the product
page, the quick-view modal and the checkout "you were looking at" strip. Measured
before fixing them, the product page rendered "AED 90 - AED 140 AED 0 -25%".

▲ AND A FOURTH, FOUND BY WORKING THROUGH THE CONSEQUENCES RATHER THAN BY A
FAILURE. The cart's struck order value contributed (int) null -- zero -- for a
variable line, so the before-price came out UNDERSTATED: a saving smaller than
the one being given, on the screen where somebody decides to pay. On a basket of
one simple product marked AED 200->50 beside the AED 190 option of a product
marked to AED 140, the row printed AED 200 where the honest figure is AED 340.

▲ MONEY, AND THIS IS THE ONE TO READ TWICE. A coupon with `exclude_sale_items`
now EXCLUDES these products. That closes a stacking hole rather than opening one
-- the discount was previously stacking on top of a variation markdown -- but it
is a real change to what a coupon pays out.

NO PAGE GAINED A STATEMENT: /shop 5 -> 5, /shop?sale=1 5 -> 5, the variable
product page 12 -> 12, a simple on-sale product page 8 -> 8.

AND THE DERIVED PRICE STOPS GOING STALE, latently rather than live. No queued
job, console command or importer reaches effectivePrice() today -- the importer
writes the columns and never reads a derived figure back -- so nothing is writing
a wrong price into an order line or a feed. That is the argument for fixing the
mechanism rather than telling a caller: the caller that would need telling does
not exist to be told. The scoped binding stays (un-scoping it in the console
reintroduces the N+1); invalidation happens at the write, on Eloquent
saved/deleted.

FOUR MORE MODULES ONTO THE SETTINGS SCHEMA -- CartPage, CheckoutPage,
SecurityModule, HomepageContent -- and the job was the SORT, not the migration.
Every cast() split into policy, which moves onto the shared cast and is declared,
and real rules, which survive as their own validators:

  - Store -> Checkout page -> Trust & reviews: the review wording may carry NO
    DIGIT of its own, and an over-length value is REFUSED to the shipped wording
    rather than truncated. That second half is exactly what a shared `max` would
    have broken silently, because the shared text arm CUTS.
  - Appearance -> Cart page -> Summary & trust: Express delivery charge and
    Service fee CLAMP at zero rather than refusing.
  - The recommended rail's cap now follows MAX_REC, where it had agreed with the
    shared default of 24 by coincidence.

The new channel REPLACES the type arm, because the template check must see the
untruncated value -- so it is the boundary for that field, and the two arms rule
5 names by hand are closed to it: a rule declared on a colour or a select throws.
SecurityModule had nothing in the second pile at all: three arms, all shared,
which is the shared cast's own argument measured rather than asserted.

APPEARANCE -> MOBILE MENU JOINS THE FRAMEWORK GUARD. Its groups lived inline in
its controller, so it was the one module outside the check that catches a module
storing a setting with NO CONTROL TO WRITE IT. The mutation proves it: an orphan
field now says "mobile_menu stores these with no control to write them:
m2_orphan" -- a sentence that could not have been said about that module before.

RULE 1: not one new exemption, and no control added, removed, renamed or moved.
Cart page, Checkout page and Security answer all 4,653 recorded casts
byte-identically; control counts per tab are identical at 390 and 1280 (Cart page
104, Checkout page 104, Security 18, Homepage content 32, Mobile menu 26); and
nine of the ten screenshots are byte-identical by md5, the tenth differing only
because the audit trail above the settings gained a "Signed in" row between runs.

▲ NOT IN THIS PACKAGE: THE EXPORT-FOLDER DELETE. WordPress admin -> Tools -> KBB
Export -> "Delete the export from this server". wordpress-plugin/ is on
BuildPackage::NEVER_SHIP and outside UpdateGuard's allow-list, both asserted per
path, so it reaches the OLD kbeautybliss.com WordPress as a plugin zip and
nothing about this shop changes. What it fixes: the re-scan that decides whether
the delete worked carried the same depth ceiling as the delete itself, and where
either gave up it returned ZEROS -- which read as "nothing there". Reproduced
against real files: ok true, remaining [], "Every export file is gone from this
server", with a customers.csv of WordPress password hashes still on disk.

Files: taken from the built zip, and every one diffed byte for byte against the
repo before shipping.

## 2.60.274
Phase 3 closed: one settings schema the module screens read. An imported article
stops losing its photograph. And a colour the shop never actually used.

A COLOUR THAT SAVED, REDREW, AND CHANGED NOTHING. Color::isValidHex() accepts a
hex WITH OR WITHOUT the leading '#'. Four modules tested a value with it and then
stored strtoupper($value) unchanged -- so typing e23a4e stored E23A4E, which is
not a CSS colour. SectionDividers::cssVariables() emitted --dv-col:E23A4E;
CartPanel wrote its accent into a background:. The browser drops the declaration
entirely: the value saves, the admin redraws with it, and THE SHOP DOES NOT
CHANGE. Sixteen fields across Appearance -> Cart panel, Mobile Header, Section
dividers and Content -> Newsletter. ProductLabels hit this once, diagnosed it in
a comment that is still in that file, and fixed ITS OWN COPY -- the other four
never got the fix, because there were four more copies of the same three lines.

PHASE 3, AND NEITHER BLOCKER WAS VISIBLE FROM READING. ModuleSchema already
existed with three modules on it. What stopped the other fourteen was that the
schema COULD NOT EXPRESS four controls the console actually draws -- normalise()
throws on an unknown type and TYPES was missing tags, ids, skin and sections, so
ModuleSchema::normalise(HeaderSettings::SCHEMA) was a fatal error; the header
claimed the vocabulary had been counted off the existing schemas and it had been
counted off the two already migrated. And the seventeen modules did not merely
DESCRIBE their settings differently, THEY ANSWERED DIFFERENTLY.

That second finding is the design. Every module's own cast() was driven over an
adversarial corpus -- 4,653 calls, recorded BEFORE anything was touched -- and
behaviour splits on SIX AXES with no two modules alike: text cap
(40/60/120/160/240), what an emptied box means, fall back vs refuse, clamp vs
refuse, two hex dialects, two boolean dialects. A single shared cast would have
re-cased every stored colour on three screens, started accepting #abc where it is
refused, and flipped "off" from true to false on ten. So POLICY IS DECLARED PER
MODULE, NOT DEFAULTED, and the defaults are the strict end. The last two axes
were found by measurement: a first draft folded colour and bool together and 322
answers moved.

RULE 1, PROVED RATHER THAN ASSERTED. 4,621 of the 4,653 recorded casts came back
byte-identical. The 32 that moved are exactly those sixteen colour fields on the
two inputs written without a '#', and a second test requires each exempted call
to have stored something no browser accepts and now to store the same digits as a
valid colour -- so the exemption cannot widen. ModuleScreenPayloadTest replays
all fourteen module endpoints (482 fields) and CAUGHT A REAL MISTAKE: passing
validation overrides() into the render call put GridSkins and the homepage-section
registry into `options`, where two screens had always had null. Control counts are
identical on every migrated screen; only Ecommerce -> Checkout moves, 14 -> 16,
for the two new controls.

THE PERFORMANCE MODULE ROW now reads "Already applied to every page - nothing to
switch" instead of drawing an inert switch beside "Not ported yet". A switch for
work that is unconditional is a control that lies.

ADDRESS AUTOCOMPLETE IS BUILT AS FAR AS IT HONESTLY CAN BE, and it ships OFF.
Store -> Ecommerce -> Checkout -> Address autocomplete. The owner decision is a
PRIVACY one and it is asked in the admin rather than buried in a doc: "Address
autocomplete suggests a full address as your shopper types - but to do it, every
character they type into the address box is sent to Google, before they place the
order and even if they never do. Do you want that on your checkout: yes or no?"
It ships "Not decided yet - nothing is sent", which behaves as no, and it is the
FIRST control in the card, ABOVE the key, because pasting a key and agreeing to
share a customer's half-typed home address are different acts. Three independent
locks - switch, key, consent - and with any one shut the checkout is
byte-identical and nothing leaves the browser, asserted by fetching the page
rather than by reading a comment. Still needs the owner: his answer, and a real
Places key with billing. Worth trialling against UAE addresses specifically first
- Places' coverage of villa numbers and area names like "Al Reem Island" is thin.

AN IMPORTED ARTICLE STOPS LOSING ITS PHOTOGRAPH, and the defect was three tags
rather than one. libxml's HTML parser is HTML4 and its void set is exactly
HTML4's, so source, track and embed are all mis-read as CONTAINERS: everything
after one of them, to the close of its parent, is parsed as its CHILD.
RichText::DROP_WHOLE then removed that subtree. <source> before <img> is the only
valid ordering inside a <picture>, so every such block arrived as nothing and the
article lost the photograph silently -- and an <embed> or a <track> anywhere in a
body did the same to every paragraph after it.

The fix is a third disposal, DROP_TAG_KEEP_CHILDREN: the tag and every attribute
on it still go, and the children the parser misfiled underneath are promoted and
then sanitised like any other node. ALLOWED WAS NOT TOUCHED, so nothing new can
be printed -- the only test a change to a sanitiser has to pass. The rule
separating the two lists is not "hostile or not" but DOES THIS ELEMENT LEGALLY
HAVE CHILDREN: script and style are real containers whose child text IS the
payload, and they stay. Pre-normalising with a regex was rejected on the merits:
it means pattern-matching untrusted HTML to decide what the parser then sees,
which is the denylist that file exists to refuse. The new branch is checked
BEFORE DROP_WHOLE so a later "source is a media tag, put it back on the drop
list" tidy-up cannot restore the loss. Tested, not asserted: script, style, svg
onload, iframe and form/input filed under a <source> all come back as nothing,
and a promoted <img> has onerror and srcset stripped and javascript: refused.

▲ AND THE ARTICLE PAGE HAD NO RULE FOR A BODY PHOTOGRAPH AT ALL. A plain <img> --
one RichText::clean() passes through byte-identically before and after the
sanitiser change, so a pre-existing defect and not a consequence of it -- took the
page's scrollWidth to 1220 at a 390px viewport and 1500 at 1280: a sideways
scrollbar on every article carrying a picture, at every width. Invisible until now
because `posts` was empty on a fresh shop and the import that fills it was itself
removing every <picture> before it reached the column. Both halves changed in one
release, so the first article to arrive with a photograph would have been the
first to overflow. .abody img{max-width:100%;height:auto} -- and height:auto is
half the rule, because WordPress writes width= and height= attributes and
constraining the width alone against a fixed height squashes the picture instead
of scaling it.

Files: app/Services/ModuleSchema.php, app/Services/NewsletterSettings.php,
app/Services/ProductLabels.php, app/Services/ProductStyles.php,
app/Services/SectionDividers.php, app/Services/SlimFooter.php,
app/Support/AddressAutocomplete.php, app/Support/RichText.php,
database/seeders/ModuleSeeder.php,
database/migrations/2027_01_05_000000_clear_caches_address_autocomplete.php,
database/migrations/2027_01_05_000001_align_address_autocomplete_module_toggle.php,
resources/views/admin/app.blade.php,
resources/views/partials/checkout/address-autocomplete.blade.php,
resources/views/store/checkout.blade.php, resources/views/store/post.blade.php
(plus whatever else the built zip lists -- taken from the zip, not from this note,
and every file diffed byte for byte against the repo before shipping)

## 2.60.273
A variable product stops costing AED nothing on every surface at once, and the
list of articles this shop cannot serve becomes a screen.

A VARIABLE PRODUCT WAS TELLING GOOGLE, THE SORT, THE FILTER AND THE FEED IT WAS
FREE -- FIVE READERS, NOT THE FOUR ON RECORD. WooCommerce keeps no price on a
variable parent; the money is on the variations. So products.price is genuinely
NULL for one, and PHP and SQL turned that same NULL into two different wrong
answers, which is why the count was low:

  1. Product::effectivePrice() answered 0 fils.
  2. CollectionSchema::from() published "price":"0.00" to Google on the listing
     JSON-LD of every /shop, category and brand page -- while the product page
     beside it published a correct AggregateOffer from the same variations. Two
     documents on one site contradicting each other about money.
  3. The price SORT ordered on the raw column. NULL sorts FIRST ascending on both
     SQLite and MySQL, so "Price: low to high" opened with every variable product
     in the shop, ahead of genuinely cheap stock. Measured: a parent whose options
     run AED 120-190 sorted ahead of an AED 30 toner.
  4. The price FACET bucketed on it.
  5. Product::toApi() published price: null on the unauthenticated feed.

AND THE FACET WAS WORSE THAN RECORDED. The note said variable products "file
cheapest-first" in the price filter. They do not -- THEY VANISH, because every
comparison against NULL is NULL, which is not true. Touching the price filter
made every variable product disappear from the shop: that same parent was absent
from "Under AED 54", "AED 54 - 150", "AED 150 - 300" AND "AED 300+". Measured: 17
products in "AED 54 - 150" before, 18 after.

DERIVED, NOT BACKFILLED, AND THE CHOICE WAS FORCED RATHER THAN PREFERRED.
VariantPricing::range() answers ONLY for a parent whose price is NULL, so writing
a figure into that column would turn every range on the shop ("AED 120 - AED
190") back into a single number -- it would have broken the very renderer the fix
reuses. Deriving needs no schema change, no ProductImporter change at all, and
cannot go stale. One SQL definition in two shapes:
EffectivePrice::variantChargedSql() is ProductVariant::effectivePrice() in SQL,
grouped over a join for the tile's range and COALESCEd inside a correlated MIN
for the sort and facet, so the two cannot drift apart -- which is the
disagreement this area has already paid for twice.

QUERY COST STAYS FLAT, pinned by a test: a 24-variable-parent grid costs exactly
what a 1-parent grid costs. /shop/ 4 -> 4; ?orderby=plow 4 -> 3; ?price=54-150
3 -> 4, the extra query hydrating the 18 rows that now match where zero matched
before. Wall ~20ms both.

AND /api/products WAS THE LAST SURFACE. INDEX_COLUMNS did not select `type`, and
VariantPricing fails closed -- it declines to derive a price for a row whose
shape it cannot confirm rather than guessing from a narrowed SELECT -- so the
feed answered null while the tile printed a range. `type` is now selected and is
NOT PUBLISHED: toApi() is the allowlist and does not carry it. Asserted on the
detail route as well as the index, because there the whole row is loaded and only
the allowlist stands between the model and the response. Cost measured: one extra
statement when the page holds any variable product, and the same one statement
for twenty-four as for one.

RULE 1: NO BUTTON IS REMOVED. The tile, the product-page headline and the
Add-to-cart gate (isDirectlyBuyable()) were all already correct from an earlier
round, and CartService::add() already threw VariantRequired. The before and after
tile screenshots are pixel-identical. What moves is which products appear in a
price bucket and in what order the cheapest-first list opens.

THE ARTICLES THIS SHOP CANNOT SERVE, at Store -> Import -> "Articles this shop
cannot serve" -> Open the list. Articles live at the site root and RESERVED_SLUGS
owns the first segment, so a live article slugged about, wishlist or feed is an
indexed URL this app can never answer. The report existed and read posts.csv
through the importer's OWN PostImporter::address() rather than a second copy of
RESERVED_SLUGS, writing nothing -- but nothing could reach it: the owner had to
know an admin-api URL and read a JSON body to see a list he has to work through
by hand, one article at a time, before the old site is switched off. Three groups
kept apart because the action differs, every row with the title, the WordPress
slug, the live URL, the address it wanted here, and what to do. Opening it writes
nothing.

▲ AND IT WAS TELLING HIM TO WRITE THE 301 FROM THE WRONG ADDRESS. The report
offered /{slug}/ and its own comment called it "spelled the way the old site
published it". It is COMPUTED, on the premise that articles live at the site root
-- true of this shop, true of the old one only if its permalink structure is
/%postname%/. On /blog/%postname%/ the row said /about/ while Google holds
https://kbeautybliss.com/blog/about/, so the redirect he was told to write would
have pointed at an address nobody ever requested, silently, on the one part of a
migration that cannot be redone once the old site is switched off. The indexed
URL is now READ from permalinks.csv -- WordPress's own get_permalink(), already
accepted as a companion file -- matched by id then slug, DROPPED unless http(s)
with a host because the page turns it into an href, and left EMPTY with the file
to upload named rather than guessed. `wanted` and `indexed_at` stay separate
columns; the CSV column is appended so nothing already read moves position.

The page is server-rendered and standalone, for the reason the other two
standalone admin pages give: no asset build step exists in this project, and a
page read when a migration is going wrong must not depend on the console bundle.
Measured at 390 and 1280: scrollWidth exactly 390 and 1280, no element
overflowing its box. It first read 543 at 390px with no bounding rect over 391 --
one unbreakable percent-encoded Arabic permalink inside a correctly sized
paragraph, fixed in CSS. Under 720px the table becomes labelled cards by media
query alone; nothing measures layout in JavaScript.

WHAT WAS ALREADY BUILT AND WAS NOT REBUILT: MediaRewrite already carried
posts.cover, and DocumentMediaRewrite already rewrote <img src> and <a href>
inside posts.body as a DOCUMENT, idempotently, refusing to re-point anything
whose file is not already under the web root.

▲ FOUND, PINNED AND NOT YET FIXED: a <picture> block loses its <img> entirely on
import. libxml's HTML parser is HTML4 and does not know <source> is a void
element, so the <img> after it is parsed as its CHILD and RichText::DROP_WHOLE
removes the subtree with it -- and <source> before <img> is the only valid
ordering, so such a block arrives as nothing and the article silently loses the
photograph. In hand for the next round.

Files: app/Http/Controllers/Admin/ArticleAddressesPageController.php,
app/Http/Controllers/Api/ProductController.php, app/Models/Product.php,
app/Services/Import/DocumentMediaRewrite.php,
app/Services/Import/ReservedArticleReport.php, app/Services/VariantPricing.php,
app/Support/EffectivePrice.php, resources/views/admin/app.blade.php,
resources/views/admin/article-addresses.blade.php,
routes/import-articles-page.php, routes/web.php,
database/migrations/2027_01_03_000000_clear_caches_article_addresses_page.php

Taken from the built zip and every file diffed byte for byte against the repo
before shipping.

## 2.60.272
The Journal can be written to, bulk tagging on Build my routine, and a redirect
that no longer throws a shopper onto the old domain mid-checkout.

THE JOURNAL EDITOR, at Content -> Blog Posts -> New article (and Edit on any
row). The screen was read-only by its own comment and `posts` could only be
filled by an import, so the permalink structure built in 2.60.217 was finished
and serving nothing anybody here could write to. New article, Edit, the Arabic
boxes beside every field, and an address checked against the storefront's OWN
ROUTES as you type -- not against a second copy of RESERVED_SLUGS, so a segment
reserved tomorrow moves the warning with it.

Deliberately NOT built, so nobody fills them in quietly: no delete route (taking
an article down is status: draft, which keeps the row and therefore the address
-- a delete frees a slug Google is holding); no slug field on save (moving an
address is Store -> SEO & Meta -> Redirects, which writes the 301); and no
canonical or og:image override, those two being the SEO Audit screen's own list
of unsafe overrides.

Applying this creates no article. The screen is empty until he writes one.

BULK TAGGING, MEASURED RATHER THAN ASSERTED. Catalog -> Build my routine -> any
step tab -> "Select all N on this page", then "Use all N for <step>" or "Tag all
N for a concern". A 40-product session over the four worksheet terms centella,
niacinamide, salicylic and heartleaf, driven in a browser against a 65-product
catalogue: 124 presses on the screen as it shipped, 12 with the bulk bar, both
paths finishing with the same 40 products tagged (sensitivity 20, dark-spots 10,
acne 10, verified in the database). Presses counted identically on both paths --
a click 1, a native select 2, a typed term 1.

The 124 is not clumsiness. The per-row concern chips are DISABLED until a product
has a step, so the concern job forces the step job: 2 presses for the step select
plus 1 for the chip, 40 times, plus 4 searches. The 12 is three presses per term
however many rows come back.

SELECT ALL SELECTS THE VISIBLE PAGE, not the search and not the catalogue. He can
see 25 rows; a control that writes to 300 he has not seen is one press from a
mis-tag he cannot inspect. The endpoint takes explicit ids and NO QUERY PARAMETER
OF ANY KIND, so the dangerous scope is unreachable rather than merely unoffered.
The checkbox carries the row count and the line beside it names the other pages.

UNDO IS A BAR, NOT A TOAST, AND IT RESTORES PER-ROW STATE. "Add sensitivity to
twelve" does not invert to "remove sensitivity from twelve" when three already
carried it -- that strips a tag set on another day. Every response carries each
row's prior value AS THE SERVER READ IT, and undo replays those through the same
endpoint, so there is one writer and one validation path. It is a stack, it
survives searching, paging and tab switches, and it lasts until the screen is
reloaded, which the bar says in words.

A CONCERN DOES NOT REQUIRE A STEP. The worksheet is organised by CONCERN while
the screen is organised by STEP -- "centella" returns a cleanser, two toners and
a cream. ConcernCollections::query() selects on routine_concerns and never reads
routine_role, and coverage() counts the same way, so tagging a stepless row is
correct; the bar reports how many it did rather than hiding it. The per-row chips
and their disabled state are unchanged.

▲ A DEFECT THIS ROUND SHIPPED AND CAUGHT BY DRIVING IT. The bulk chip flips to a
REMOVE when every picked row already carries the concern, and it was first drawn
"✓ Redness & sensitivity" -- which reads as a STATUS and is a BUTTON THAT UNTAGS.
The real session walked into it: centella also matched the heartleaf group, so by
the fourth term all ten already carried the tag, one press removed it from all
ten, and the session ended with 10 sensitivity products instead of 20 with
nothing on screen saying so. It now reads "✓ Remove Redness & sensitivity", with
the consequence in the title attribute, a test and a mutation.

QUERY COST: GROUPED, NOT PER ROW. One SELECT, then one UPDATE per distinct value
written -- 5 queries for 40 rows into one step, against 44 for a save-per-row
loop. Chunked at 200 ids a statement.

Its own capability rule, catalog.manage, written ABOVE the reads: a read rule
reached first would let an editor holding catalog.view alone retag the catalogue
25 rows at a time. Verified fails-closed. No modal confirm, deliberately -- the
blast radius is one visible page, the button names the count and the target
before it is pressed, and undo is one press.

IN-BAND AND OUT-OF-BAND URLS, SPLIT. Url::redirect() built every absolute URL on
APP_URL, which is one setting doing two jobs that want opposite answers.
Measured with APP_URL still on the old domain: GET https://new-shop.test/checkout
answered 302 Location: https://old-shop.test/cart/. The shopper is on the new
host, their session cookie is scoped to the new host, and they have just been
thrown onto a domain that cannot see their basket -- on the way to paying. Once
the old domain stops resolving it is a dead end.

A redirect Location, and a link on the page being served, is IN-BAND: the visitor
is already on this host, so answering with it is correct and harmless, and a
forged Host: redirects the forger to their own domain and nobody else's. An
email, a webhook callback, a canonical or a sitemap entry is OUT-OF-BAND: built
on APP_URL, never on a header a visitor chose, because a Host: written into a
password-reset email is account takeover. Url::external() is byte-for-byte what
redirect() used to return, so every caller moved onto it emits exactly what it
emitted before, on a shop served from the address it is configured with and on
one that is not.

That the in-band argument holds was CHECKED rather than assumed: CacheHeaders
ships switched off; when on it returns early on any non-200, so a 302 keeps
Symfony's "no-cache, private"; its storefront knob cannot reach "public" at any
value; and /cart, /checkout and /my-account are no-store before the status test
is reached. There is no path by which a 302 leaves here cacheable by a shared
cache.

▲ TWO CALL SITES THE PREVIOUS ROUND'S AUDIT MISSED. CanonicalHost::targetFor()
is the INVERSE of the original bug -- it is only ever reached on an alias host,
so building on the request would have answered the alias with a Location back on
the alias, and the forwarding that class exists to perform would have silently
stopped for exactly those paths that also have a redirects row. And
PaymentsApiController::webhookUrl(), which registers an address at a payment
provider and is out-of-band by definition. A SECOND MAIL DEFECT was found in the
same sweep: QuizController emitted a root-relative link into the plan email, the
same shape as the one already fixed in cart-recovery. A suite-wide invariant test
now holds every mail template to it.

▲ AND THE SHAPE THE AUDIT PROPOSED WOULD HAVE BEEN INVISIBLE TO THE SUITE.
SiteUrl::origin() refuses a bound request under runningInConsole(), which is true
under PHPUnit as well as artisan -- so the un-threaded call is right in
production and always answers APP_URL in a test. The request is now a parameter,
threaded at CheckoutController, CheckRedirects and the 404 handler, so the
behaviour each relies on is the behaviour its own test exercises. Measured:
inside a feature request to http://new-shop.test, SiteUrl::origin() returns
http://localhost and SiteUrl::origin($request) returns http://new-shop.test.

Files: app/Http/Controllers/Admin/PaymentsApiController.php,
app/Http/Controllers/Admin/PostEditorApiController.php,
app/Http/Controllers/Admin/RoutinesApiController.php,
app/Http/Controllers/Api/QuizController.php,
app/Http/Controllers/Store/CheckoutController.php,
app/Http/Controllers/Store/PageController.php,
app/Http/Controllers/Store/SeoFilesController.php,
app/Http/Middleware/CanonicalHost.php, app/Http/Middleware/CheckRedirects.php,
app/Notifications/CustomerEmailVerification.php,
app/Notifications/CustomerPasswordReset.php, app/Providers/AppServiceProvider.php,
app/Services/Mail/EmailBranding.php, app/Services/Mail/OrderEmailPresenter.php,
app/Services/NewsletterList.php, app/Services/OutboundSender.php,
app/Services/Payments/GatewayPreflight.php,
app/Services/Payments/Gateways/RemoteGateway.php,
app/Services/Payments/Gateways/TamaraGateway.php,
app/Services/Payments/StripeConnect.php, app/Support/AdminCapabilities.php,
app/Support/OutboundOptOut.php, app/Support/Url.php,
database/migrations/2026_12_28_000000_clear_caches_journal_editor.php,
database/migrations/2027_01_02_000000_clear_caches_routine_bulk_tagging.php,
resources/views/admin/app.blade.php,
resources/views/admin/partials/post-editor-screen.blade.php,
resources/views/admin/partials/routines-screen.blade.php,
resources/views/emails/cart-recovery.blade.php,
routes/build-my-routine-admin.php, routes/post-editor-admin.php, routes/web.php

Thirty-two files, taken from the built zip rather than from a lane's report, and
every one diffed byte for byte against the repo before shipping -- the check
2.60.102-.106 exist to enforce.

## 2.60.271
The routine search, actually fixed — and the previous diagnosis was wrong.

▲ 2.60.270's note implied the search fix was the step-scope change. IT WAS NOT.
Reproduced against the shipped 2.60.268 screen in a browser rather than reasoned
about: typing "centella" fired NO REQUEST AT ALL and left the previous 25 rows
on screen. The box was bound to `onchange`, which on a text input fires on blur
or Enter and nothing else. The scope narrowing fixed in 2.60.270 did not exist
on that screen, so it cannot have been the cause. Enter also fired the request
TWICE, because two handlers both answered it.

AND A SECOND, INDEPENDENT CAUSE, WHICH THIS PROJECT SHIPPED. 2.60.269 added
`ingredients` to the search's WHERE clause. That column is created by a
migration guarded with Schema::hasColumn — so on a server where the migration
never ran, EVERY SEARCH IS A 500 while browsing without a term works perfectly.
That is the exact shape of the report. It now probes for the column and falls
back to the name-and-SKU search, saying so on screen rather than narrowing
silently.

QUICK AND REAL-TIME, measured on a 681-product catalogue: debounce 250ms, chosen
because a fast typist's inter-keystroke gap is 120-180ms and a control stops
feeling responsive past ~300ms. "centella" (8 keystrokes) is one request and
65ms; "niacinamide" (11 keystrokes) is one request and 38ms. The stale-response
race is proved in a browser, not argued: holding the reply to `q=cer` so it
lands after `q=ceramide`, the guards paint 166 results; with both removed, 339
stale results appear under a label reading "339 products match ceramide".

DEMO DATA FOR ROUTINES at Catalog -> Build my routine -> Settings -> Demo data,
and also at Store -> Demo Content. Five products, one per step, removable from
either screen. THE DEMO ROWS CARRY NO CONCERNS AT ALL, and that one decision is
what makes them safe: an empty concern list already means "suits any routine",
and ConcernCollections selects explicit tags only — so a demo row can never
reach the threshold that publishes /concern/acne/, never enters the sitemap and
is never offered by the quiz, BY CONSTRUCTION rather than by an exclusion rule
somebody has to remember in six places. Every row is named "Demo — …" and a
banner appears on every tab while any exist, saying whether shoppers can see
them.

THE ON/OFF SWITCH is on the same screen, writing the same setting as Store ->
Modules -> Build my routine. It deliberately does NOT post to the modules
endpoint: that rebuilds `module_devices` from only the keys it is handed, so a
one-module post from here would silently reset every other module's device
setting. It carries `store.settings` rather than a catalogue capability, because
everything else on the screen decides which product fills which step and this
one puts two pages on the internet.

WHAT IT DOES NOT TURN OFF is now on the card in the owner's words:
/concern/{slug}/ is deliberately outside the switch and keeps answering either
way, because those are ordinary shop pages people find in Google and a switch
here should not take them down.

▲ AND /_design-check IS DELETED — a URL that answered 200 now answers 404.
Nothing linked to it. Its own comment read "Temporary: the Phase 1 design check.
Delete when Phase 2 lands"; this shop is at Phase 20. Measured before removing
it: 200 to anybody, `<meta name="robots" content="index, follow">`, a
self-canonical, the theme directory named in its markup, eight real products
rendered — and absent from both robots.txt and the sitemap. A developer page
actively asking to be indexed, on a shop whose own research lists crawl hygiene
as something it BEATS the competitor on.

THE SITEMAP STOPPED KEEPING ITS OWN COPIES of the curated listings and the
content pages; both now come from the router, the way the concern block beside
them already did. A second bug fell out of it: the content-page URL was built as
'/' . slug . '/', assuming the slug IS the address. It need not be — a page
routed at /about-us with ->defaults('slug','about') would have been served while
the sitemap advertised /about/, a 404. Proved inert: same database, old
implementation against new, 10,586 bytes and 67 URLs both ways, diff identical.

Five migrations, all cache clears.

## 2.60.270
Catalog -> Build my routine is now eight tabs, and the import stopped asking
eleven questions whose answer was always the same.

THE ROUTINE SCREEN WAS ONE 693-LINE PAGE and the owner had to scroll past all of
it to reach the only job that matters. It is now one flat strip:
Cleanser · Toner · Treatment · Moisturiser · SPF · Concern pages · Wording ·
Settings, copied from Appearance -> Checkout page because that is the pattern he
already uses daily. He chose the two things that would otherwise have been
guesses: LOCKED MEANS DONE, NEVER DISABLED — nothing is gated on anything else
and he works in any order — and every section gets a tab rather than staying
stacked below. From a cold shop he is tagging in two clicks: the strip names the
next step and the empty body carries the button that fills it.

THE TAB LABEL CARRIES TWO FIGURES BECAUSE THEY ANSWER TWO QUESTIONS. The COUNT
is products a shopper can actually be shown — published, visible, in stock — so
four tagged products that are all out of stock read 0, which is what the
storefront can draw on. The TICK is "no routine I am showing still draws this
step empty": four toners all tagged for acne leave seven routines short, and a
count-based tick would have called that finished. A routine he has hidden is not
counted, because a step it cannot fill is not a gap a shopper can reach.

AND THE SEARCH ON A STEP TAB LOOKS AT THE WHOLE CATALOGUE, NOT THE STEP.
Narrowed to the open step it returns nothing on an empty step — which is every
step on this shop today — and the owner would have concluded the search was
broken on the first word off the worksheet. Measured: "centella" on the empty
SPF tab returned 0 rows before and 14 after.

ONE CARD WAS CONSOLIDATED AND IT IS SAID HERE RATHER THAN LEFT TO BE NOTICED:
"What each step can draw from" is gone, because its five role chips ARE the five
step tabs now — same numbers, one card earlier, where he is about to click. Its
other two options, "Untagged only" and "Every product", survive as a scope
select on every step tab. Nothing else was removed, and a test names all
fourteen behaviour hooks the old page had and fails if one goes missing.

THE IMPORT STOPPED ASKING ABOUT THINGS IT HAD ALREADY SOLVED. 2.60.269 taught
the shop to derive the fifteen legacy category redirects; the importer had not
learned, so it still proposed writing all of them and asked eleven identical
questions, each ending "worth leaving alone if it does not" — where the answer
is always leave it alone. On a fully imported tree: rows to write 38 -> 8,
questions 11 -> 0.

AND A WRITTEN ROW IS WORSE THAN REDUNDANT, which is why this is a defect and not
tidying. The shop's answer is derived, so it follows the category when it is
renamed or re-parented. A stored row does not — and the row WINS, because the
redirects table is read before the router. Measured: write the row, rename the
parent, and a one-hop redirect becomes a two-hop chain; rename the leaf too and
it becomes a 301 onto a 404. The same reasoning that said 2.60.269 must not SEED
those rows says the import must not WRITE them.

Idempotency proved the strict way: first run writes 8, second writes ZERO — not
"writes the same 8", which would pass on a build that recomputed every
destination. Byte-identical table, no doubled path segments, and no article body
edited.

TWO SENTENCES ON THE IMPORT SCREEN STOPPED BEING FALSE. A product tag or
attribute term used to read "nothing in this shop carries {type} id {id} —
either it was never imported…", which is untrue: the term WAS imported; what
this shop has no equivalent for is a tag ARCHIVE, by design. And the screen's
headline note still described proposals as "questions rather than discards",
which is exactly what this round reversed, above numbers that had changed
underneath it.

IN-CONTENT LINKS ARE STILL NOT REWRITTEN, and the case against got stronger
rather than weaker: a body linking to /toners/ already lands in one hop with no
row and no rewrite, a derived redirect follows the category while a rewritten
body freezes today's nesting into the owner's prose, and a body is the one
artefact here that cannot be recomputed.

No new route, no new endpoint, no new capability, no migration.

## 2.60.269
FIFTEEN ADDRESSES GOOGLE HAS INDEXED STOPPED BEING 404s.

The old shop served its category archives FLAT at the site root — /toners/,
/sunscreens/, /skincare-sets/ — because that WooCommerce install had
`category_base` set to the empty string. That is the old site's own exported
setting, not an inference. This shop serves them nested, at
/product-category/skincare/toners/. The cutover forwards the old domain
PRESERVING the path, so every one of those fifteen has been landing on a 404 at
the end of a 301 — its ranking dropped rather than inherited.

Measured before: all fifteen 404. The `redirects` table ships ten rows and
every one is a journal article; there was never a category row.

Measured after, against a running server: all fifteen answer 301 to the correct
NESTED path, `curl -L` reports num_redirects=1 and a final 200, and the landing
page's own <link rel="canonical"> matches the Location byte for byte. The
slashless spelling lands too.

ARABIC WORKS, ON ALL FIFTEEN. /ar/toners/ 301s to
/ar/product-category/skincare/toners/ and answers 200 with <html lang="ar"> and
an Arabic canonical — one hop, the prefix kept exactly once. The middleware
order was MEASURED on the live global stack rather than reasoned about:
CanonicalHost, then SetLocaleFromPath, then CheckRedirects, so /ar is stripped
BEFORE the redirect check and one list of paths serves both languages. That
order is now pinned by reflecting on the real stack, so a future prepend that
inverted it fails loudly instead of silently 404ing every Arabic old address.

THE ROWS ARE DERIVED PER REQUEST, NOT SEEDED, and that is the design point
rather than an implementation detail. On a fresh database the only categories
are six seeded placeholders, so a migration resolving /cleansing-oils/ at apply
time would bake in a destination the real import then contradicts —
permanently, invisibly, and on a shop whose owner cannot easily inspect the
table. Deriving is null until the category arrives and correct the moment it
does, with nothing to apply. A stored row still wins if one exists.

AND THE AUDIT CARD WAS ADVERTISING THE WRONG FIX. Shipped yesterday in
2.60.266, it named the FLAT form as the destination — which is a second 301 for
a nested category and a 404 for a missing one. Corrected: addresses the shop
now lands are no longer reported at all, and the card reads 15 before this
package and 0 after.

Ruled out with evidence rather than left undone: /category/ and /tag/ prefixes
(that install never used them — the same empty `category_base`), brand archives
(the exporter records an empty permalink per brand, WordPress served none),
products, articles and pages (same addresses on both sites), and feeds
(301ing a feed reader onto an HTML page is worse than the 404). Attribute
archives, product tags, paginated archives and attachment pages are deferred to
the next round with the reason stated. `?p=123` is structurally impossible for
any redirect row — the query string is not part of the path the router matches
— and needs an .htaccess rule the owner has to add.

No new route, no new setting, no new admin control, no migration. Zero queries
added to a warm storefront page: the check is an in-memory list of fifteen
literals and answers before the table is consulted.

## 2.60.268
The quiz stops being a dead end, and the tagging job becomes visible.

THE QUIZ ANSWERED EVERY SHOPPER WITH THE SAME ONE LINK. Measured on a default
shop before this: /skin-quiz answers 200 in 46,136 bytes, `window.KBB_ROUTINES`
is ABSENT from that document, `QuizRoutineLink::map()` returns null, /routines
is 404, and the results screen offers exactly ONE distinct URL — `/shop/`. A
shopper names up to three concerns and gets three chips, three routine shapes
holding no products, and four buttons to the whole catalogue. The concern
reached a chip and a row in `quiz_leads` and went nowhere else.

The hand-off itself had been built some time ago and the master plan still
carried it as outstanding — but ticking that box alone would have recorded the
opposite of the truth, because every URL it can produce is a `/routines/`
address and those sit behind `build_my_routine`, which ships OFF. So the
hand-off existed and could never fire on a shipped shop.

THE RUNG THAT WAS MISSING is `/concern/{slug}/`, the other page per concern and
the one NOT behind that switch: it publishes itself the day a concern has copy
and three live tagged products. The quiz now emits that table separately and
falls back to it, and the plan email gets the same middle rung instead of
dropping from a routine straight to `/shop/`. Separate rather than merged,
because "Build my acne routine" over a collection URL describes a page the
shopper is not about to see.

THE TAGGING JOB IS SMALLER. Catalog -> Build my routine searched `name` and
`sku` only, while `products.ingredients` has existed since October — so most of
the sensitivity signal (`fragrance-free`, `centella`) was unreachable from the
one screen the owner is meant to tag from. It now searches ingredients too and
shows a snippet when that is what matched, at the same 2 queries.

AND THE JOB IS NOW VISIBLE. Catalog -> Build my routine -> "Concern landing
pages — N of 8 live": per concern, live tagged products against the floor, how
many more are needed, whether copy exists, and the address once live. It ships
reading 0 of 8. It counts what the PAGE counts and not what a ROUTINE counts —
an untagged product suits every routine and no concern page, so the routine
tally would have reported 400 products for acne over an address that still
404s.

AN N+1 FOUND WHILE MEASURING: `BuildMyRoutine::coverage()` ran
`Routine::overrides()` inside its per-concern loop — eight identical SELECTs
over one table that cannot change between them, invisible to any budget because
it scales with the CONCERN list rather than the catalogue. 10 queries to 3.

NOTHING MOVES ON APPLYING THIS. `ENABLED` is still `['acne']`, the floor is
still 3, nothing in the repo is tagged, so the concern list is empty, the quiz
emits no second table, and the shipped page contains neither
`window.KBB_CONCERN_PAGES` nor the string `/concern/`. The English pin moved
for the quiz page and the diff was read rather than waved through: every changed
byte is inside the inline <script>, and with script blocks stripped the two
documents are identical at 15,349 bytes on both sides.

THE REMAINING BLOCKER, stated on the screen rather than left to be wondered
about: only `acne` has page copy. The other seven need a string in the code, so
a concern can sit at 5 tagged products against a floor of 3 and still be dark.
The countdown row now says so. Both SEO lanes refused to generate that copy and
were right to.

No new route, no new endpoint, no new capability, no migration: the countdown
rides GET /admin-api/routines and the search rides GET /admin-api/routine-
products, both already behind `catalog.view`.

## 2.60.267
Ed25519 package signing, ENFORCING NOTHING — and a fix for the page that was
supposed to save you when the admin console breaks.

▲ /admin/updates?fallback=1 HAS ANSWERED 500 SINCE THE UPDATER WAS BUILT.
routes/web.php called `UpdateController::index()` with no argument on a method
typed `index(Request $request)` — an ArgumentCountError present since the
baseline commit, 2.60.41. That page is the standalone fallback that exists
PRECISELY for the case where the admin bundle will not load, so the one day
anybody would ever open it is the one day it could not help them. Nobody found
it because nobody loads a fallback page until they need it, and on that day the
conclusion is that the server is dead rather than the page. Found by a lane
photographing a banner on it. One line.

SIGNING SHIPS PERMISSIVE, WITH AN EMPTY TRUSTED-KEY LIST. A signature that is
present is verified; a package with no signature installs exactly as it does
today; the Core Updates header still reads "unsigned packages accepted", byte
for byte. Every package this project has ever built carries `"signature": ""`,
so the same packages install before and after. The one behaviour change for any
package that exists today is that one carrying a signature this server CANNOT
check is refused rather than ignored, and no real package carries one.

ON HOLD AT THE OWNER'S REQUEST. He asked for unsigned patches for now, so step
6 of the rollout — the step that makes a signature mandatory — is not to be
taken. `KBB-Master-Plan.md` Phase 14 records the hold; the lane is freed.

WHY THE ROLLOUT IS SIX STEPS AND WHY THEY MAY NOT BE MERGED
(docs/PACKAGE-SIGNING.md §1). The package that teaches a shop to REQUIRE a
signature is applied by the verifier that came BEFORE it. So a shop must be
able to check a signature before it can be told to demand one, and must have
been SEEN to check one before that demand is safe. That is the identical
new-code/old-data/no-way-in shape that bricked this shop's updater earlier the
same day; the sequence exists so it cannot recur.

AND SIGNING WOULD NOT HAVE CAUGHT THAT OUTAGE. Said plainly because the
opposite is easy to assume: a signature proves ORIGIN, never CORRECTNESS. All
five packages behind it would have been signed by this key, verified, and
applied — they were built by the wrong script, not by the wrong person.
`checkMigrationsAreDeclared()` and `ClassDependencyScan`, both shipped in
2.60.266, are what stand between the shop and that fault. What signing adds is
that a package which did not come from the build machine cannot be applied at
all: the difference between a mistake and an attack.

THE KEY NEVER TOUCHES THIS REPOSITORY OR A SHOP. Private half lives at
~/.config/kbb/package-signing.key on the build machine, 0600; the code REFUSES
a key inside the repository, a group- or world-readable key, and a symlink. A
`.key` file is not an allowed package extension and a home directory is not an
allowed prefix, so it cannot reach a shop even if it were committed. The public
half is a LIST in config/kbb.php, so a key rotates by trusting both for one
release.

THE EMERGENCY HATCH IS A FILE, storage/app/kbb-accept-unsigned, not a setting —
because `.env` is not read at all while the config cache exists (the trap that
made KBB_NOINDEX read false for its whole life), because a database setting
needs the app to boot, and because `storage/` is on UpdateGuard's forbidden
list, so no package can create it to weaken a shop or delete it to strand one.
It relaxes "must be signed" and never "if signed, must be genuine": a forged
package is still refused with the hatch on.

One migration, a cache clear: config/kbb.php gains two keys and is
config-cached, and the /updates route closure changed.

## 2.60.266
Three lanes, and a security item that this package CANNOT fix.

▲ DELETE public_html/kbb-doctor.php OVER SSH. TODAY. It is reachable right now
with a token that is committed to this repository. Unlike kbb-recover.php, which
refuses to run while its token is the placeholder, the doctor has no such check
-- it simply compares the request's token against the constant, so THE
PLACEHOLDER IS THE PASSWORD, and the file's own header prints the complete URL
with it in plain text. Anyone who has seen this repository can read the error
log and its stack traces, enumerate every table, list the web root and clear the
caches. No package can repair this: BuildPackage excludes public-web-root/ and
UpdateGuard forbids those files outright, both deliberately, so that a bad
update cannot damage its own escape route. It has to be `rm`.

THE HEALTH CHECK NOW LOOKS AT THE SHOP, and this is the one deliberate rule-1
departure in the package. `/_kbb-health` ran `SELECT 1` and returned JSON; it
never rendered a page. That is why 2.60.260 -- which removed a class every
product tile resolves out of the container -- passed its own post-update check
and was KEPT, with the home page, /shop, every category, every brand and every
product page answering 500 behind it. It now renders the home page and the first
visible product page and asserts four things: status exactly 200; a body ending
in `</html>` (the case a status code cannot reach, because a fatal mid-render
flushes the buffer with 200 already sent); a length floor; and that the product
page names its own slug -- which is DATA read from the database a moment
earlier, never copy, so a lane stays free to rewrite every visible string but
cannot ship a page that no longer knows which product it is.

It changes which updates are KEPT: a package that leaves either page unable to
render is now rolled back automatically. `KBB_HEALTH_DEEP=false` in .env is the
escape hatch for the one case where that is wrong, a shop already broken for an
unrelated reason that cannot otherwise install its own repair. An empty
catalogue still passes, and one unrenderable product row cannot brick updates
for ever -- it tries the first three visible products and passes if any renders.

AND A PACKAGE CAN NO LONGER INSTALL CODE WHOSE CLASSES IT DOES NOT CARRY. Every
PHP and Blade file in a package is tokenised, and each `App\` class it names
must be in the package or already on the server. That is exactly what 2.60.260
did: three templates resolving App\Services\VariantPricing, with the class
itself in a package that had not been applied. It uses PHP's own tokeniser, so a
name in a comment or a string is not a reference, and it never calls
class_exists(), because a verifier must not execute what it is verifying.

A SAMPLE ORDER, at Safety -> Demo Content -> Sample order. The owner has had no
orders on this shop all round and has twice been asked to open a document he
could not reach. Choose English or Arabic, press the button, and get an order
worth looking at -- three lines, one a variable product with an option, an
address, delivery, payment, AED 542 -- with direct links to the invoice, packing
slip, delivery note and dispatch label, and a button to delete it again. An
Arabic order shows the split this round built: invoice and delivery note in
Arabic, packing slip and dispatch label in the operator's English.

It is marked three ways, each failing safe for a different question: a
demo_seed_log row written INSIDE the order's own transaction, so there is no
instant where the order exists and the thing excluding it does not; `origin =
'sample'` for the places that may not issue a query, such as the mailer and the
document banner; and the order number SAMPLE-0001, which every screen, document
and export prints by construction. No email, through six separate guards and a
backstop. No stock. No invoice number. Its own owner-only capability.

TWO FIGURES WERE ALREADY WRONG and are fixed here rather than buried: Store ->
Orders' revenue tiles had no demo exclusion at all, so a demo order made the
Dashboard and the very next screen disagree; and the Orders CSV export computed
`is_demo` and discarded it, now the last column so no existing column moves.

FOUND AND NOT FIXED: the COD drawer in Payments -> Reconciliation has no demo
exclusion, so Demo Content's own eight seeded COD orders already inflate it.
Pre-existing. The sample order avoids it by using a card gateway and a test pins
that, so a later edit cannot break it silently.

A NEW SEO AUDIT FINDING, Store -> SEO & Meta -> SEO Audit, last card, advisory.
Fifteen legacy category addresses are indexed today, the cutover forwards the
old domain preserving the path, and this app 404s those flat paths by
construction -- so each one lands on a 404 at the end of a 301 and drops its
ranking instead of passing it on. Nothing could see it, because a scan of the
indexable surface cannot reach addresses the shop refuses to serve.

## 2.60.265
2.60.264 plus the guard that stops its root cause recurring, rebuilt as one
package. Apply this instead of .264.

THE SERVER NOW REFUSES A PACKAGE THAT CARRIES MIGRATIONS WITHOUT DECLARING
THEM. `UpdateRunner` runs migrations only when `update.json` says
`"migrations": true` -- `hasMigrations()` reads that key and never looks at the
files. 2.60.259 through .263 were built by a hand-written script that omitted
it, so eight migration files were copied to the live server and none ran, while
every package reported "applied". That is what left the server holding an
UpdateRunner which writes `update_releases.manifest` and no such column, which
is what bricked the updater.

The same shape had already happened once, with `orders.is_gift` in 2.60.85, and
`PackageMigrationFlagTest` has warned about it since. A test in the repository
cannot stop a builder that never runs it, so the check now lives in
`UpdatePackage::verify()`, on the server, where every package passes however it
was built. It refuses rather than inferring the flag: a silent correction would
let a broken builder keep shipping and nobody would learn the builder is wrong.

CONTENTS: everything from 2.60.259, .260, .261, .262 and .264, written whole
against 2.60.258, so the order the earlier ones were applied in no longer
matters.

## 2.60.264
THE UPDATER COULD NOT APPLY ANYTHING AT ALL, and the cause was three
defects stacked on each other. Every package, down to an 18 KB one carrying
two files, answered a bare "Server Error".

1 · THE PACKAGES WERE BUILT WRONG, and this one is the root cause. 2.60.259
through .263 were built by a hand-written script instead of
`php artisan kbb:package`, and it left out the `migrations` key. UpdateRunner
reads that key and nothing else -- `hasMigrations()` never looks at the files
-- so all eight migration files were copied to the server and NONE of them
ran, while every package still reported "applied". `PackageMigrationFlagTest`
has warned about exactly this since the is_gift incident; the warning was in
the repository and the builder that ignored it was not.

2 · SO THE SERVER HELD THE CODE AND NOT THE COLUMN. 2.60.260 installed an
UpdateRunner that writes `update_releases.manifest` and, because of 1, did not
add the column. Every apply after that hit it.

3 · AND ONE SWALLOWED EXCEPTION SPREAD TO EVERY WRITE AFTER IT.
`recordManifest()` wrote with `$release->update()` inside a try/catch whose
docblock claimed that made it incapable of failing an update. Eloquent's
`update()` is `fill()` then `save()`: `fill()` puts `manifest` on the model
FIRST, and only then does the save throw. The catch swallowed the throw and
left the attribute on the model, dirty -- so every later `save()` re-sent it:
the `backup_id` write, the `status` write, then `rollback()`'s status write,
and finally the `['status' => 'failed']` inside `rollback()`'s own catch, which
is the third throw and the one nothing catches. It escaped `apply()`, escaped
the controller, and became the 500. The guard did not contain the failure, it
seeded it.

WHAT CHANGED. Every write to `update_releases` now goes through one private
writer that cannot dirty the model (query builder, no model state) and cannot
throw (logged, never raised) -- because by the time a rollback writes its
status the files are already restored, and losing the row AND showing a bare
500 is far worse than losing the row. `recordManifest()` also checks the column
exists before writing, so the ordinary window between a package's files landing
and its migrations running costs nothing. And the exit code of
`Artisan::call('migrate')` is read: a migration that fails now fails the
update, rolls the files back and puts the migrator's own output on the screen,
instead of reporting success over a broken schema.

NOTE ON APPLYING THIS ONE. The updater cannot repair itself -- `recordManifest`
runs at the top of `apply()`, before any file is copied -- so the column has to
exist before this package will go on. One statement, in the host's database
tool:

    ALTER TABLE `update_releases` ADD COLUMN `manifest` LONGTEXT NULL;

After that this package applies normally, its eight migrations run for the
first time, and the `add_manifest_to_update_releases` migration finds the
column already there and returns without touching it.

CONTENTS. Everything from 2.60.259, .260, .261 and .262 as well, written whole
against 2.60.258, so the order the earlier packages were applied in no longer
matters. Built with `php artisan kbb:package --since=`, which is the only
builder this project should ever use again.

## 2.60.263
NO CODE CHANGE. Two files out of 2.60.262, shipped alone because the host
would not apply the whole thing.

WHY IT IS SPLIT. 2.60.260 (26 files) applied. 2.60.259 (56 files) left its row
at `running` three times, and 2.60.262 (84 files) answered a plain "Server
Error" from a screen whose endpoint returns JSON on every outcome including a
rollback -- so the response was not the application's at all, and the PHP worker
was killed by the host before it could write one. Applying copies every file,
snapshots each one first, dumps the database when migrations are present and
then makes an HTTP request to itself; somewhere between 26 and 56 files that
exceeds this host's request limit.

WHAT THESE TWO FILES ARE. The whole of the live breakage and nothing else:

  - `app/Services/VariantPricing.php`, which does not exist on a server that
    skipped .259, and which `product-card.blade.php`, `product-grid.blade.php`
    and `store/product.blade.php` all resolve out of the container.
  - `app/Services/Invoices/InvoiceDocument.php`, which is where the
    `nameForCustomer` key the invoice and delivery-note sheets read is
    produced.

NEITHER NEEDS A MIGRATION OR A NEW DEPENDENCY. Every class the two import
exists on the server at 2.60.258, checked one by one, and InvoiceDocument reads
`name_localised` as `?? ''`, so it is correct before
`order_lines_snapshot_the_customers_language` has run and correct after.

## 2.60.262
NO CODE CHANGE. This is 2.60.259, .260 and .261 rebuilt as ONE cumulative
package against 2.60.258, because the three were applied out of order on the
live host and a file ships whole.

WHY IT WAS NEEDED. .260 was applied before .259, and .259 never recorded a
completion. Three files that landed then referenced things only .259 ships:

  - `App\Services\VariantPricing` is NEW in .259 and does not exist on a server
    that skipped it. `product-card.blade.php`, `product-grid.blade.php` and
    `store/product.blade.php` -- all three shipped by .260 -- resolve it out of
    the container to print a variable product's price range. Every page that
    draws a product tile, and every product page, throws
    `Class "App\Services\VariantPricing" not found`.
  - `nameForCustomer` is the array key `InvoiceDocument::items()` gained in
    .259. `sheet-invoice.blade.php` and `sheet-delivery-note.blade.php`, both
    shipped by .261, read it. Against .258's InvoiceDocument the key is absent,
    so every invoice and delivery note throws.

THE HEALTH CHECK DID NOT CATCH EITHER, and that is the defect underneath the
mistake rather than the mistake itself. `/_kbb-health` runs `SELECT 1` and
returns JSON. It never renders a storefront page, so a package that breaks the
home page, the shop, every category, every brand and every product page answers
`{"ok":true}` and is kept. An update that takes the shop down is exactly what
that check exists to refuse, and it cannot see it.

WHAT THIS PACKAGE DOES. Every file changed between 2.60.258 and 2.60.261,
written whole. It does not matter which of the three landed, which landed
partly, or in what order: applying this produces the 2.60.261 tree exactly.
Laravel's migrator skips the migrations that already ran, so the eight
migrations here are safe whatever state the table is in.

## 2.60.261
Two changes, both visible on the shop, both fixing something rather than
preferring something.

▲ THE NAV BAR DRAGGED THE WHOLE SHOP SIDEWAYS, and this is a RULE 1 EXCEPTION
that will change at least five pages the day it is applied. `.mbar .wrap` was
`flex-wrap:nowrap` with `overflow-x:visible`: flex items shrink only to their
min-content width, and past that floor the bar stopped shrinking and pushed the
PAGE wider, because nothing clipped it, scrolled it or wrapped it. Measured on
a sixteen-entry menu, identically on /, /shop/, /new-in/, /best-sellers/ and
/super-sale/: `document.documentElement.scrollWidth` 1459 against a 1280
viewport, +339 at 1120, +435 at 1024. The mobile nav does not take over until
~1000px, so there was a band where the desktop bar took the storefront with it.

NEITHER OBVIOUS ANSWER WORKED, and both were built and measured before one was
picked. `overflow-x:auto` contains the page and leaves the bar one row -- but a
scroll container cannot keep `overflow-y:visible`, the other axis computes to
auto with it, and the mega panels are `position:absolute` children INSIDE the
bar, 319.2px tall under a 45.5px bar: 1px of 319.2 visible, 0.3%, at every
width. Hovering "Brands" drew the underline and no panel. `flex-wrap:wrap`
alone put the SHIPPED twelve-entry menu on THREE rows, 93px against 45.5px, at
every width from 1024 to 1920.

The reason was a two-part defect in nav-fit.js that had been cancelling itself
out. It asked `scrollWidth > clientWidth`; `scrollWidth` is CLAMPED to
`clientWidth`, so that can never be true, and `clientWidth` includes the bar's
padding while flex wraps against the CONTENT box. On the shipped menu at 1280,
with wrapping suspended for the reading: scrollWidth 1280 vs clientWidth 1280 --
"it fits" -- while items and gaps came to 1242.3 against a content box of 1236.
Six pixels over, invisible because they bled into the 22px gutter, and under
wrapping those six pixels cost a whole row.

ON A MENU THAT ALREADY FITS, NOTHING MOVES: bar height 45.5px before and after
at every width, one row before and after, no vertical shift. The only change is
`--nav-scale` 0.936 to 0.919 at 1280 -- 12.17px to 11.95px, a fifth of a pixel,
and it is the correct size. At 390 the bar is hidden and the before and after
screenshots are byte-identical.

HOW MANY TOP-LEVEL ENTRIES, and how long their labels, is still the owner's
question at Appearance -> Header. This only decides what happens when the menu
is too wide: a nav bar that handles its own overflow, rather than a shop that
scrolls sideways.

THE DELIVERY NOTE now prints in the customer's language. Before this, an Arabic
customer's handover sheet was byte-for-byte the English customer's --
`lang="en"`, "Delivery Note", "Rice Daily Moisturizing Toner 150ml". It now
reads `lang="ar"`, "إشعار التسليم" and the Arabic product names. THE PACKING
SLIP IS UNCHANGED and stays in the operator's language, deliberately: the
distinction is not whether a document leaves the building -- the dispatch label
leaves too, on the outside of the box, and a courier reads it -- but who reads
it. A packing slip is a picking list read at the bench. The delivery note goes
IN THE PARCEL and is opened by the person who ordered.

A third file the same argument required: `BulkDocumentController::sheets()`
decided whether to enter the order's language by asking
`allocatesInvoiceNumbers()`, which was right only while the invoice was the one
customer-facing document. Left alone, a batch of twenty would have printed
Arabic product names inside English headings, and nothing would have failed.

No migration: no route and no new setting.

## 2.60.260
Two lanes: the variable-product basket, and the content-security policy in
report-only.

THE DEFECT THIS EXISTS FOR: a shopper could put a variable product in the
basket for AED 0. Measured on a running server before the fix --
`POST /api/cart/add {"product_id":27}` answered 200 with
`cart_items.unit_price = 0` and no variant. `CartService::add()` now refuses
it, and there were FOUR doors into it, not the two the tile suggested:

  - Store\CartController::add()          the basket
  - Store\CheckoutController::browsedAdd()  ON THE CHECKOUT PAGE. `browsed()`
      lists the viewed-products cookie with no filter on type and `browsedAdd()`
      passes no variant by construction, so a free line was one press away on
      the page where people pay.
  - Services\ManualOrderBuilder            an operator's draft order, which
      would have been a 500. It now names the product that needs an option.
  - anything written next month             the service throws.

▲ RULE 1 EXCEPTION, SAID OUT LOUD. Tiles in the SKINNED grid that carry an
"Add to cart" button today will lose it in two cases, and both are defects
rather than preferences:

  - a VARIABLE product, which is the AED 0 above. It now reads "View product".
  - a SOLD-OUT product. The skinned grid offered Add to cart on out-of-stock
    items and the server answered "That product is sold out." Found by the byte
    pin, not by anybody looking.

`components/product-card.blade.php` -- the tile everywhere else on the shop --
has always shown "View product" for both. This makes the two agree. The whole
tile is already a link to the product page, so nothing became unreachable, and
the button box measures the same before and after at 390 and 1280: the label
swap moves no layout. No new string, no new CSS.

THE PRODUCT PAGE headline printed AED 0 for a variable product, and this was
not a flicker: pdp.js writes the real figure from a CLICK listener only, so the
page stood at AED 0 -- with an option already highlighted reading AED 35
directly beneath it -- until the shopper tapped something. It now draws the
same price range the tiles print.

NEW, AND IT SHIPS OFF: `Content-Security-Policy-Report-Only`, with violations
on Store -> Security -> Content security policy. The enforcing header does not
occur anywhere under app/ and a test proves it by stripping comments and
searching every PHP file. Measured in Chromium, every violation the shop
produces today is INLINE SCRIPT OR INLINE STYLE -- not one external host was
refused, so the allowlist is already right. The home page posts 158 reports in
one view. Enforcing today would take the shop apart (24 inline `<script>`, 124
`on*` handlers, 29 `<style>`, 210 `style=""` across 95 views), which is why
this round buys the measurement rather than the enforcement.

The policy deliberately does NOT cover the admin console: that page is one
1.1 MB document of inline script and style, and a storefront policy on it would
post thousands of violations from the screen the owner reads violations on.

The violation endpoint is public by necessity and is bounded five ways:
60/minute, a 16 KB body cap, four allowlisted fields, shape checks that cut the
URI to scheme/host/path so a per-request query string cannot defeat the
collapse, and a row ceiling that is scoped to policy rows -- so a flood cannot
push the owner's own security trail out of the table. It answers 204 to
everything and echoes nothing.

Also: Store -> Security's verdict used to read "687 requests refused as too
many" the first time the policy was switched on -- every one of them the
owner's own visitors being shed by the throttle and recorded as a rate-limit
trip. A shed report is now its own event: still counted, because reports really
were lost and an absent violation must not read as "does not happen", but out
of the trips list and out of the verdict.

Three migrations, two of them cache clears.

## 2.60.259
Six lanes, merged with no conflicts, and ONE package for the reason 2.60.258
gives: `routes/web.php` carries both route changes in this round and a file
ships whole, so splitting by lane would mean hand-editing a partial version of
it -- which is how 2.60.102-.106 were withdrawn.

NOTHING ON THE SHOP MOVES ON APPLYING THIS, with two named exceptions below.
Every new setting ships at the value the page already had, and the one new
storefront address answers 404 until the owner fills it.

THE TWO BEHAVIOUR CHANGES TO WATCH:

1. `/sitemap.xml`, `/robots.txt` and `/llms.txt` now leave with
   `Cache-Control: public, max-age=3600, s-maxage=3600` and NO `Set-Cookie`.
   Measured before: `no-cache, private` plus two cookies on each, which made
   them uncacheable by anything in front of this host. They keep
   `X-Robots-Tag: noindex` on staging -- the five classes dropped are the
   cookie and session ones, deliberately not the whole `web` group.
2. `/product-category/<leaf>/` now 301s to the canonical NESTED path. It always
   301'd; it named a different address as canonical than the one the page
   itself declares, which is a self-contradiction a crawler reads.

FIXES THAT WERE LIVE DEFECTS: brand, category and article pages published
`<title>KBB</title>` -- Yoast's shipped template is `%%title%% %%sep%%
%%sitename%%` and the renderer deleted the token rather than resolving it, so
the page name was simply absent (the product half of this shipped in .258, the
other three callers are here); a redirect row could point at itself, at all
three writers; the Arabic cart badge sat stretched across the bag icon because
an inline `style` attribute was setting `right` while the stylesheet set
`inset-inline-end`, and an inline declaration beats an author rule of any
specificity; a variable product's tile printed AED 0 instead of its price
range; article `<a href>` links to the old host's uploads were never migrated,
so a thumbnail's full-size image broke on the day that host went dark; the
share image and organisation logo held in `settings` were invisible to the
media audit entirely, so "remote: 0" could be reached with the shop's own
WhatsApp preview still hot-linked.

NEW CONTROLS, each shipping at today's value:
  Appearance -> Checkout page -> Mobile . Header       Header padding -- sides
  Appearance -> Footer -> On a phone                   Padding inside the top / bottom
  Appearance -> Footer -> Shape & size                 Padding inside the top / bottom

NEW SCREENS AND CARDS:
  Store -> Import -> Addresses & pictures -> Old addresses
      "What this needs you to decide" -- the ask bucket became answerable.
      An answer is to a QUESTION, not an address: approving /x/ -> /a/ is not
      approval of /x/ -> /b/, and a re-parented category makes the answer stale
      and asks again naming both. A destination that 404s wins the question
      whatever the source's verdict, so a bulk yes cannot write a 301 to a 404.
  Store -> Business Details -> Business -> "Where the shop is"
      Address and opening hours for the Organization node. Half an address is
      never published -- street AND city AND a recognised country, or nothing.
      `postalCode` is NOT required: the UAE does not use one for street
      addresses.
  Store -> SEO & Meta -> SEO Audit -> "Product image with no alt text"
      Advisory, so it cannot take the headline from a finding that breaks a
      page. A product with no photograph is not reported -- there is no alt to
      write for a shot that does not exist.

NEW ADDRESS: `/concern/{slug}/`. It 404s, and stays 404, until the owner has
written the page's copy AND tagged at least three live products for that
concern under Catalog -> Build my routine. The sitemap asks the same class the
router asks, so it can never advertise an address the site refuses. `concern`
is reserved against the root-level article slug for the same reason `routines`
is: otherwise an article published there would be reachable today and would
silently stop being reachable on the day the third product was tagged.

ORDER LINES now snapshot the customer's language beside the operator's.
`order_items.name` keeps its exact meaning and value; a new nullable
`name_localised` is read by the invoice, both invoice emails and /my-account,
while the packing slip and delivery note keep the operator's. The hook returns
before touching anything while one language is live, so this ships inert.

Five migrations, four of them cache clears -- `route:cache` compiled the route
table as it was, so a route change that ships without one lands and is never
read.

RTL: the Arabic homepage hero now travels the way Arabic is read. The sign
comes from `document.documentElement.dir` in the slider itself; both CSS rules
that had tried to do it are deleted with the fix, because keeping them would
cancel it and restore the blank hero. Measured at 390px: slide 2 used to queue
at 378..744 -- the RIGHT, exactly as in English -- and now queues at -354..12.

Also: the built bundle stopped renaming itself on every unrelated edit.
`app.js` imported `kbb.css` while kbb.css was also its own Vite entry, and
Rollup folds a dependency into the chunk hash, so a stylesheet edit renamed the
JavaScript. `app-DSE-434-.js` and `app-iyd4z9xL.js` are byte-identical -- same
45,971 bytes, same md5 -- which is what a renamed-for-nothing bundle looks
like. Every stale name is a file the server keeps forever.

## 2.60.258
Seven lanes, merged. ONE package and not three, for a reason worth stating:
`routes/web.php` mounts all three new route files and `AppServiceProvider.php`
carries both the security listener and the redirect middleware registration. A
file ships whole, so splitting by lane would mean hand-editing partial versions
of those two -- which is exactly how 2.60.102-.106 were withdrawn.

THE ONE BEHAVIOUR CHANGE TO WATCH: the redirects table now fires BEFORE the
router. `CheckRedirects` was written as middleware and never registered, so a
row for an address the shop already answered could never fire -- five years of
old URLs silently serving the wrong page. Measured: /shop/ and /product/tx-serum/
went 200 to 301. Existing rows were fetched before and after and diffed
byte-identical. A row pointing a live page at itself is refused, not looped.

FIXES THAT WERE LIVE DEFECTS: a stored-XSS hole on Content -> Translations
(Arabic rich text published with no allowlist -- four script dialogs fired on
/ar/product, now zero); every imported product would have published the SAME
page title, because Yoast's default template is `%%title%% %%sep%% %%sitename%%`
and the renderer deleted the token instead of resolving it; the homepage slider
dot rail sat half off the screen at 390px, in ENGLISH; the discount badge printed
on top of the wishlist heart in Arabic; the Arabic hero went blank after one
click; a redirect destination was never scheme-checked though it goes straight
into a Location header.

NEW: Store -> Security (an administrative audit trail and a report that blocks
nothing), Store -> SEO & Meta -> SEO Audit (which found 24 products sharing one
meta description on its first run), an "Articles at addresses the shop owns"
report, and a real delete for the export folder holding shopper password hashes.

The Journal was uncounted by the media audit entirely -- a migration could have
reported zero remote references with every article picture still hot-linked.

The WordPress exporter changes are NOT in this zip: UpdateGuard refuses that
prefix. The plugin ships through Plugins -> Add New as it always has.

## 2.60.257
The third and last cause of the notch beside the phone logo, found by the owner
in DevTools: `kbb.css:1612` carries a bare `.logo{flex:1;text-align:center}`
inside its own 900px media query -- the site header's mobile centring, which the
checkout's logo inherited. It is also why two rounds of measuring said "fixed":
`flex:1` stretches the ELEMENT and `text-align:center` moves the GLYPHS inside
it, so the box's left edge stayed correct. Measured at 390: box left 20, first
letter 47.2. After: letters at 20, and at 140 at 1280 -- both equal to the
page's own edge.

The back-to-top arrow was knocked off centre by a rule shipped in 2.60.255: the
ruled-rows shape treated it as a row. Button centre x 355 y 2312.6 against glyph
centre x 348.5 y 2316.1; now the same point. It also waits until the page has
been scrolled, via a sentinel and an observer rather than a scroll handler.

And both presets now move only the tab in front of you, on the checkout screen
and on the footer screen -- a preset that reaches past the screen changes numbers
nobody can see.
`css/kbb/kbb-checkout.css`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`admin/partials/slim-footer-screen.blade.php`, `Services/CheckoutPage.php`,
`2026_12_11_000000_clear_caches_logo_arrow_and_per_tab_presets.php`

## 2.60.256
The floating Place order bar now waits for an order that can actually be placed:
it asks `form.querySelector(':invalid')` -- the same test the button itself uses
-- plus the address, delivery and payment, which constraint validation cannot
see because the chosen address lands in hidden inputs and a hidden input is
never `:invalid`. Driven at 390px: hidden on an empty form, hidden with the
fields filled but no address, shown once the address is chosen, hidden again the
moment the in-page button scrolls back or a field is cleared.

The notch beside the phone logo had a SECOND cause, found by sweeping 36
combinations rather than reasoning about it: `--cop-headpadx` is a control
separate from the page's `--cop-padx` and defaults to the same 20 only by
coincidence. In "lined up with the page" mode the header now takes the page's
padding -- 0 of 36 combinations misaligned, against several before.

And the footer lines up with the page: `width_mode` defaults to `page` and takes
the checkout's own inherited `--cop-d-max`, so the two cannot drift. The two
policy links move under the wordmark, in the markup rather than with `order` --
flexbox cannot put one sibling inside another's column.
`Services/SlimFooter.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `partials/slim-footer.blade.php`,
`admin/partials/checkout-page-screen.blade.php`,
`2026_12_10_000000_clear_caches_float_gate_and_footer_align.php`

## 2.60.255
The checkout footer wears the site header's own wordmark. It was drawing
`brand`, a flat text box shipping "K-BEAUTY BLISS" in capitals; it now reads
Appearance -> Header's Wordmark, Accent word and two colours, live, on every
render -- so "K-Beauty" in the header's ink and "Bliss" in the header's accent,
and renaming the shop stays one edit in one place. Not a second colour box: two
copies drift, and the one that drifts is whichever nobody looks at. The two
colours are compared against the HEADER's defaults, so a shop that has touched
neither screen still renders a footer with no style attribute, and they are safe
in a declaration because HeaderSettings::cast() answers a `colour` row with a
six-digit hex or the shipped default. `The wordmark` -> `The typed brand name`
restores the old box.
It also gains the spacing controls it was missing: space above the bar (a margin
and not padding, so the page's own ground shows through it rather than the bar's
tone), padding inside each ruled row and a floor under every row -- the rows used
to derive their padding from the block gap, so the only way to open them was to
open every gap in the bar at once -- plus "Squeeze the bar" and "Back to
defaults". Every one ships at what the bar was already doing; measured, 55px on
a desktop and 187.8px on a phone before and after.
`Services/SlimFooter.php`, `partials/slim-footer.blade.php`,
`Admin/SlimFooterApiController.php`, `admin/partials/slim-footer-screen.blade.php`,
`2026_12_09_000000_clear_caches_footer_wordmark_and_spacing.php`

## 2.60.254
Three selects that were rendering as sliders, the notch beside the phone logo,
the reviews line's wording, and the footer's own shape on a phone.

`checkout-page-screen.blade.php` handled `bool` and returned an
`<input type="range">` for everything else, so `ph_tone` and `ph_weight`
(2.60.252) and `m_float` (2.60.253) each drew as a slider with no scale showing
a value it could not represent, and saved as `NaN`. The screen now draws
`select` and `text`, and the handler branches on the schema's type rather than
on the DOM element's.

`.co-head .in` centres a band narrower than the window. Measured at 390px with
the mobile header width at 280: the band ran 55…335, the logo started at 75 and
the page's own "Back to shop" started at 20, while the badge overflowed to 370
— the right edge flush, the left notched. The phone now takes the page's own
edges by default; the class restores the centring.

"Go back to cart" gets its own tab, because the controls shipped under ten
spacing sliders and could not be found. The reviews line gets its wording back
without getting its figures back: `{rating}` and `{count}` are substituted from
approved reviews and a template carrying any other digit is refused. And the
footer gets a phone shape of its own — ruled rows on a phone, spread-to-both-
edges on a desktop — with the WhatsApp mark in WhatsApp green.
`Services/CheckoutPage.php`, `Services/SlimFooter.php`,
`css/kbb/kbb-checkout.css`, `partials/checkout/reassurance.blade.php`,
`partials/slim-footer.blade.php`, `admin/partials/checkout-page-screen.blade.php`,
`2026_12_08_000000_clear_caches_checkout_selects_and_footer_phone.php`

## 2.60.253
The band above the checkout header, and two controls under it. `kbb.css` carries
a bare `section{padding:52px 0}` and `.kbb-checkout` IS a `<section>`, so every
checkout inherited 52px of page background above the secure-checkout bar and
52px of nothing below the last block — measured in Chromium, `.co-head`'s own
top read 52 at 1280 AND at 390. The section now declares its own padding, from
two controls that ship at 0. "Go back to cart" gains a size, an arrow size and a
corner radius per surface, plus a tap height on mobile that starts at the 44px
Lane BM measured and raised it to. And the phone-only Place order bar now waits
for the in-page button to leave the viewport and goes the moment it returns —
IntersectionObserver, not a scroll handler, because the button moves as an
address is chosen. Two defaults change the page on purpose, both asked for: the
band is removed, and the floating bar is drawn where it was not.
`Services/CheckoutPage.php`, `css/kbb/kbb-checkout.css`,
`store/checkout.blade.php`, `2026_12_07_000000_clear_caches_checkout_shell_and_float.php`

## 2.60.107
Corrects 2.60.102–.106. Three files had been edited against a stale base — my
working copy of the server was the 2.60.71 snapshot with the 2.60.98 package
overlaid, and files changed in 2.60.72–.74 are not in that package. Applying
.102 or later would have reverted the 2.60.74 seoCtx closure fix (500 on every
product page), the Quick view button and its module gate, and the quick-view
modal shell. All three rebuilt on the correct base with the .102/.103 changes
reapplied.
`Store/ProductController.php`, `components/product-card.blade.php`,
`layouts/store.blade.php`

## 2.60.106
Reviews. The admin Reviews screen read product_slug, author, body and likes —
none of which is a column on `reviews`; the real ones are product_id,
author_name, content and helpful, so every review rendered blank. Both API
review endpoints keyed on the same phantom column and had never worked: the
read raised SQLSTATE 42S22, the write failed at the insert. GET /api/reviews
returned whole models with no status filter, exposing author_email and ip plus
unmoderated rows; now approved-only, named columns, capped, with author_email
and ip added to the model's $hidden. Posts endpoints given named columns.
`AdminController.php`, `Api/ProductController.php`, `Api/ReviewController.php`,
`Api/PostController.php`, `Models/Review.php`

## 2.60.105
POST /api/checkout/session — the one public endpoint that creates an order.
No visibility filter, so hidden products could be bought; no stock check, so
out-of-stock items were sold; `sale_price ?? price` ignored sale_starts_at and
sale_ends_at, so expired sales kept charging the sale price and scheduled ones
sold early; and 'brand' stored the belongsTo relation instead of its name.
`Api/CheckoutController.php`

## 2.60.104
Security headers were absent from every /api response — SecurityHeaders is
appended to the web group, and api routes do not inherit it. robots.txt let
crawlers into /cart, /my-account, /my-wishlist, /wishlist, /track-my-order. The
web-root deletion migration now uses public_path(), which bootstrap/app.php
sets to the real directory.
`routes/api.php`, `SeoFilesController.php`, `2026_09_13_150000_*.php`

## 2.60.103
og:image, twitter:image and the schema image were relative paths — Url::media()
returns root-relative, which Facebook, WhatsApp and X drop silently and Google
reports as invalid. Absolutised. priceValidUntil added to every Offer.
`Support/Seo.php`, `Store/ProductController.php`

## 2.60.102
AddToCart was missing from every pixel, so the funnel jumped ViewContent ->
InitiateCheckout. Added for Meta, GA4 and TikTok as one delegated listener,
with price from effectivePrice() on the button.
`MarketingPixels.php`, `layouts/store.blade.php`, `product-card.blade.php`,
`product-grid.blade.php`, `partials/home/grid.blade.php`, `quick-view.blade.php`

## 2.60.101
Deletes the four unauthenticated web-root scripts (kbb-patch-file, kbb-fix-now,
kbb-unstick, kbb-check-schema) via a migration, since the web root is outside
the paths the update system writes to. doctor and recover are kept — both are
token-checked.
`2026_09_13_150000_remove_ungated_webroot_scripts.php`

## 2.60.100
Eighteen further unescaped sites across every admin screen, found by sweeping
rather than by looking where the last bug was: review title and body, the reply
modal, Quiz Leads, Subscribers and their three modals, Customers, order line-item
brands, dashboard top-product brands, the redirects code pill. Two flagged sites
left alone after reading them — a search filter comparing text, and an HTTP
status in an Error string. Also folds in 2.60.99.
`admin/app.blade.php`

## 2.60.99
Core Updates reported "Version 1.0.0" on a server at 2.60.98. Three call sites
read config('kbb.version') -> env('KBB_VERSION'), never set here. New
InstalledVersion reads the newest 'applied' row from update_releases. Not
cosmetic: UpdatePackage compares requires_version against the same value, so any
package declaring a prerequisite above 1.0.0 would be refused by a server that
already exceeded it — which is why no package here has ever set one.
`InstalledVersion.php`, `UpdatePackage.php`, `UpdateRunner.php`,
`UpdateController.php`, `UpdateApiController.php`

## 2.60.98
Stored XSS in the admin console. Seven places concatenated customer-supplied
strings into innerHTML unescaped: the customer name on the dashboard feed, the
orders table, the order detail address block and line items, the review author
in the reviews table and reply modal, and top-product names. An order name or a
review author is whatever an anonymous visitor typed, and it executed with the
admin's session on the origin where /admin-api answers. All now pass through
sesc(), which the file already defined and used in ~90 other places.
`admin/app.blade.php`

## 2.60.97
SVG uploads could carry script. MediaUploadController allowed svg, which is XML
and can hold <script>, on* handlers, javascript: URLs, foreignObject and entity
declarations. Written to the public web root, that is stored XSS on this origin.
Eight markers now checked and rejected by name; content is entity-decoded first
and read as text, never parsed. Bitmaps unaffected.
`MediaUploadController.php`

## 2.60.96
The review captcha was bypassable. /reviews/submit had a honeypot, a captcha and
a 5/hour limit; POST /api/products/{slug}/reviews reached the same table with
none of them, and routes/api.php had no throttling at all. Controller-level
limit and honeypot added, sharing one key with the storefront form, plus
throttles on all four public POSTs.
`routes/api.php`, `Api/ProductController.php`

## 2.60.95
Public API was leaking. GET /api/settings returned the entire settings table
unauthenticated, including admin_path and indexnow_key; now allowlisted to
eleven presentational keys. /api/products/{slug} and /api/posts/{slug} had no
visibility filter, serving drafts and hidden products by slug. /api/products
filtered on status 'active', which products never use, so it returned nothing —
the bug that hid the missing access rules.
`Api/SettingController.php`, `Api/ProductController.php`, `Api/PostController.php`

## 2.60.94
The sitemap contained zero products. It filtered status 'active'; products use
'publish'. is_visible and deleted_at were unchecked too. Categories were listed
under /category/{slug}, which has never been a route. Product URLs lacked the
trailing slash and so pointed at redirects.
`SeoFilesController.php`

## 2.60.93
The journal was published at one URL and served at another. Routed at /blog and
/post/{slug}; four places published /skincare-guide/ and nothing matched it.
Canonical tags, breadcrumb schema and IndexNow all submitted /post/{slug}, which
the site never linked. /skincare-guide/ and /skincare-guide/{slug}/ are now the
real routes with 301s from the old pair.
`routes/web.php`, `PageController.php`, `AppServiceProvider.php`,
`blog.blade.php`, `post.blade.php`, `admin/app.blade.php`, clear_caches migration

## 2.60.92
Every brand URL was a 404. The homepage linked /brands/ twice, MenuDemo built
/korean-skincare-brands/ and /brand/{slug}/, and none had a route. /brands/ is
now a real A-Z index; the other two 301 to it and to the filtered listing.
`BrandController.php`, `brands.blade.php`, `routes/web.php`, clear_caches migration

## 2.60.91
Module statuses corrected. `product_sorting` and `seo_engine` were marked
`todo` and are both built; verified before changing.
`app/Services/ModuleRegistry.php`

## 2.60.90
Autocomplete now expands synonyms like the results page has since 2.60.77.
Per-order `gift_fee` column records what wrapping actually cost, rather than
recomputing it from the current setting. `AdminPathService` cache leak closed.
`SearchController.php`, `CheckoutController.php`, `AdminOrderController.php`,
`Order.php`, `AdminPathService.php`, `admin/app.blade.php`,
`2026_09_13_120000_add_gift_fee_to_orders.php`

## 2.60.89
Gift settings never saved: `gift_enabled` and `gift_fee` were absent from the
settings endpoint's allowlist, which skips unknown keys and returns ok anyway.
Separately, that endpoint wrote with `Setting::updateOrCreate()` and cleared
neither settings cache, so every value saved from Business Details reached the
database and was then ignored by the storefront.
`AdminController.php`, `admin/app.blade.php`

## 2.60.88
Gift setting given one source of truth: a migration seeds the row, and nothing
guesses a default in two places any more.
`2026_09_13_110000_seed_gift_settings.php`, `admin/app.blade.php`,
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`

## 2.60.87
Gift tick was hidden (default off), the checkbox sat on the text baseline
(`.form-row label{display:block}` outranked the rule meant to override it), and
the summary row showed at 0 (`.sumrow{display:flex}` outranks `[hidden]`).
`checkout.blade.php`, `order-block.blade.php`, `CheckoutController.php`,
`admin/app.blade.php`

## 2.60.86
Checkout 500. `...gift@if (...)` — Blade's directive pattern starts `\B@`, so a
directive whose `@` follows a word character is never compiled, while its
partner is. The compiled view held `endif;` with no `if:`.
`checkout.blade.php`

## 2.60.85
Cache-clear migration for the new route (stale `bootstrap/cache/routes-*.php`
overrides `routes/web.php`), and the Gift wrapping tab made scope-independent —
it read `SETTINGS` from another script scope, which killed the whole Delivery &
Shipping screen.
`2026_09_13_100000_clear_caches_2_60_85.php`, `admin/app.blade.php`

## 2.60.84
Gift settings moved to Store → Delivery & Shipping as a third tab; the card
added to Business Details in 2.60.82 removed.
`admin/app.blade.php`

## 2.60.83
Admin sidebar clipped: Store has 20 entries, `.nav-sub` opened to 560px with
`overflow:hidden`, so Business Details, Customers, Quiz Leads and Content &
Pages were unreachable.
`admin/app.blade.php`

## 2.60.82
Gift wrapping as a priced, merchant-controlled option. Fee read from settings,
never from the request; choice held in the session so every totals path agrees.
`CheckoutController.php`, `checkout.blade.php`, `order-block.blade.php`,
`routes/web.php`, `admin/app.blade.php`

## 2.60.79–.81
Checkout layout. The account, notes and gift blocks had been inserted inside
`<div class="row2">`, a two-column grid, so they became grid children and split
Email from Phone. Moved below the grid; notes and gift then moved into the
Delivery section; checkbox metrics matched to the existing WhatsApp row.
`checkout.blade.php`

## 2.60.78
Gift notes end to end, and `customer_note` — on the orders table since the
original schema, returned by the admin API, never captured and never rendered.
`2026_09_13_090000_add_gift_and_order_notes.php`, `Order.php`,
`AdminOrderController.php`, `CheckoutController.php`, `checkout.blade.php`,
`admin/app.blade.php`

## 2.60.76–.77
Guest checkout → account creation. The password is only ever written into a
blank, never over an existing one, and `legacy_password` counts as set, so the
3,712 imported customers cannot be trampled. Search synonyms via
`App\Support\SearchTerms`, with over-broad entries removed and a four-character
floor on expansion.
`SearchTerms.php`, `ShopController.php`, `CheckoutController.php`,
`checkout.blade.php`

## 2.60.75
Admin Catalog 500'd selecting a `category` column that has never existed on
`products` — categories and brands are foreign keys. Also removed an N+1 of
roughly 1,300 queries per screen load.
`AdminController.php`

## 2.60.74
Every product page 500'd. `ProductController::show()` builds `$summary` and the
`seoCtx` closure imported only `$product`.
`ProductController.php`

## 2.60.73
Module switches for quick view and the address book, registered in
`ModuleRegistry` with per-device visibility. Off means gone: no button, no
shell, endpoints 404.

## 2.60.72
Account address book (list, add, edit, delete, default per type) and product
quick view. No schema change — the `addresses` table and its model already
existed; the page was a 12-line stub that printed "No addresses saved yet"
without ever querying anything.
