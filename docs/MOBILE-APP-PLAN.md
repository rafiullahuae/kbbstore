# K-Beauty Bliss mobile app — the plan (iOS + Android)

Agreed with the owner on 15 October 2026. Status: **Phase 1 (system design + design previews) started 18 October 2026** — the
Arabic version of the shop comes first (the app reads the same translations), then
Phase 1 (design previews) of this plan.

The owner's two rules, in his words, which override anything below:

> "no i dont' want to let go anywhere from the app. no any other browser etc.
> everything must i need inside our app."

> "i need the arabic version in site and app both."

## 1. Technology

**React Native + Expo (TypeScript)** — one codebase, real native apps for iPhone
and Android.

| Option | Verdict |
|---|---|
| Website wrapped in an app (Capacitor / webview) | Rejected: Apple rejects "a website in a frame" (App Store guideline 4.2), and it would feel like the website |
| Two native apps (Swift + Kotlin) | Rejected: two codebases to build and maintain |
| **React Native + Expo** | **Chosen**: native screens, lists, gestures and payment sheets; Arabic right-to-left; push; small updates without a store review (EAS Update) |

## 2. Nothing leaves the app

| Area | Inside the app |
|---|---|
| Browse, search, product, reviews, wishlist, bag | Native screens |
| **Checkout** | Native screens: bag → address (saved addresses, emirate/area) → delivery → payment → order placed |
| **Card** | Stripe React Native SDK: card form in the app; 3-D Secure in the app |
| **Apple Pay / Google Pay** | The phone's own payment sheet over the app (Face ID / fingerprint) |
| **Tabby** | Tabby mobile SDK — their approval screen opens *inside our app* (required by Tabby), then back to "Order placed" |
| **Tamara** | Tamara mobile SDK — same as Tabby |
| **Cash on delivery** | Native screen; UAE only, as on the site (`GatewayCountries`) |
| Sign in, register, forgot password | Native; the reset email link opens the app (universal / app links) |
| Order emails "View order" | Open the order in the app |
| Policies, Terms, Privacy, FAQ, About, blog | App screens; text from the admin |
| Delete account | Native (Apple requires it for any app with sign-up) |
| Help | In-app contact form → the existing Contact inquiries screen |
| WhatsApp | **Decided 18 Oct 2026:** no floating button anywhere in the app. A WhatsApp button lives on the in-app **Contact** page only (it opens the WhatsApp app, the one place the app hands off, on the shopper's tap) |

## 3. How the app connects to the website

The website stays the single source of truth: products, prices, stock, orders,
admin, translations. The app is another way in.

- **`/app-api/v1/…`** on the website, shipped as normal patches:
  - read: home sections, categories, concerns, brands, product lists (filters,
    sort, paging), product detail (gallery, tabs, reviews), search, blog, pages;
  - customer: sign in / register / reset, profile, addresses, wishlist, bag,
    coupons, orders + tracking, reorder, reviews, delete account;
  - **checkout**: quote (shipping, VAT, coupon), create order, start payment,
    confirm payment — app-only endpoints that **reuse** the existing gateway
    classes and the same order / stock / email rules, so an app order is an
    ordinary order in the admin (emails, invoices, reports).
- **The web checkout and payment code are not touched.** Before launch: the
  whole payment suite (`Tamara*`, `Tabby*`, `Stripe*`, `Wallet*`, `Checkout*`,
  `Order*`, `Payment*`) plus one real order per method from the app — the
  payment lock in CLAUDE.md applies.
- **Sign-in tokens**: the app gets its own token, kept in the phone's
  Keychain / Keystore; optional Face ID / fingerprint; the admin can sign out a
  device. (New on the site: ask before adding any package.)
- **Security as the site**: allowlisted fields only (CLAUDE.md: `/api/*` is
  unauthenticated — the app API follows the same rule), rate limits, the
  Firewall's blocked IPs stay blocked, prices and stock always from the server.
- **Language**: the app asks the API in English or Arabic; the API answers from
  the same `TranslationStore` the site uses. One translation, both places.
- **Admin**: **App → Mobile app** — app home sections (reusing Homepage
  sections), minimum app version ("please update"), push campaigns, on/off.
- **Links open the app**: `apple-app-site-association` and `assetlinks.json`
  served by the site (like the Apple Pay domain file route).

## 4. Where things live

| What | Where |
|---|---|
| App source | New private repo, e.g. `rafiullahuae/kbbstore-app` (separate from the website) |
| Builds | Expo EAS cloud builds (no Mac needed) |
| Small updates | EAS Update (no store review for JS-only changes) |
| Store | App Store Connect (TestFlight) · Google Play Console (internal testing) |
| Server side | `/app-api`, payment endpoints, the two link files — this repo, normal patches |
| Signing keys | Held by EAS under the owner's Expo account, never in code |

Accounts (company name preferred): Apple Developer US$99/yr (organisation needs a
D-U-N-S number — start early), Google Play Console US$25 once, Expo (free to start).
Payments setup: Apple Pay merchant certificate for the app uploaded to Stripe;
Google Pay enabled in the Google Pay & Wallet console; Tabby and Tamara asked to
enable mobile SDK access for the app's bundle ID.

## 5. UI / UX

Brand as the site: pink #C13E63, Outfit + Cairo (Arabic), pastel backgrounds, the
lotus logo. English and Arabic (full right-to-left mirror) from day one.

- **Tab bar**: Home · Shop · Search · Bag (count) · Account.
- **Home**: banner, Shop by skin concern chips, Trending, Best sellers, brands,
  offers — the same sections the owner edits on the site.
- **Shop**: categories → grid, sort, filters (brand, price, concern), endless scroll.
- **Product**: swipe gallery + pinch zoom; price with Tabby / Tamara instalments;
  tabs Description · Ingredients · How to Use · Shipping · Returns; reviews with
  photos; sticky Add to bag; bought together.
- **Search**: instant results, recent / popular, barcode scan.
- **Bag / checkout**: coupon, free-delivery bar, native checkout (§2).
- **Account**: orders with live status, reorder, addresses, wishlist, language,
  notifications, delete account.
- **Native touches**: haptics on add-to-bag, pull-to-refresh, smooth transitions,
  offline screen, image caching; skin quiz and routines as native screens.
- **Push**: order confirmed / shipped / delivered, back in stock, price drop on
  wishlist, bag reminder, admin campaigns — each switchable by the customer.
- **Accessibility**: large text, VoiceOver / TalkBack labels, contrast.

## 6. Phases

0. **Arabic version of the shop** (in progress first — see the Arabic plan).
1. **Design previews** of every screen, checkout included, English + Arabic,
   iPhone and Android sizes — the owner chooses.
2. **Website side**: app API, tokens, checkout + payment endpoints, link files —
   patches with tests; no storefront page changes.
3. **App v1**: browse, product, search, bag, native checkout with all five payment
   methods, account, orders, push. TestFlight + Android internal testing, real
   test orders.
4. **Store launch**: listings, screenshots, privacy policy, Apple privacy label,
   Google data-safety form, App Tracking Transparency if Meta / TikTok pixels run
   in the app.
5. **v2**: loyalty points, routines, personalised home, app-only offers.

## Decisions, 18 October 2026

- **WhatsApp:** on the Contact page only, never floating (see §2).
- **Foldable and dual-screen phones are first-class:** Galaxy Z Fold / Z Flip,
  Pixel Fold, Microsoft Surface Duo (two screens and a hinge), OnePlus Open,
  plus tablets. Every screen has a layout for compact, medium and expanded
  widths, folded and unfolded, a "tabletop" half-fold, and the hinge never cuts
  through content. (There is no foldable iPhone today; iPad sizes are covered by
  the same layouts.)
- **The owner's bar:** "no bugs or styling issues on any screen ... proper
  planning and system design ... don't start in the air ... everything super
  perfect with advanced technology." So Phase 1 is TWO deliverables, before
  any app code: the system design (`docs/MOBILE-APP-SYSTEM-DESIGN.md`) and the
  design previews (`docs/app-preview/`).

## Open decisions for the owner

1. Developer accounts: company (D-U-N-S) or personal?
2. Store name: "K-Beauty Bliss"?
