<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The English wording of every interface string, as code.
 *
 * ── WHY THIS IS A PHP CLASS AND NOT lang/en/store.php ───────────────────────
 *
 * Laravel's conventional home for these is lang/. On this host that directory
 * cannot be shipped to: App\Services\Update\UpdateGuard::ALLOWED_PREFIXES is
 * app/, config/, database/migrations/, database/seeders/, resources/, routes/
 * and public/build/, and `lang/` is on none of them — an update package
 * containing lang/en/store.php is REJECTED by the updater before a single file
 * is written. So the English source of truth lives under app/, where a package
 * can reach it, and the file loader is left in place underneath for anything
 * the framework itself supplies (validation messages, pagination).
 *
 * ── WHAT BELONGS HERE AND WHAT DOES NOT ─────────────────────────────────────
 *
 * Here: the fixed wording of the interface — buttons, headings, empty states,
 * the sentences in transactional emails and on the printed documents. Finite,
 * keyed, and the same for every shopper.
 *
 * NOT here: anything in the catalogue. A product name is a row, not a key, and
 * lives in the translations table against its own id. See
 * App\Support\HasTranslations.
 *
 * NOT here either: anything the owner types into an admin setting — the
 * header's support label, the cart panel's wording, the mobile menu's heading,
 * the trust claims. Those are rows in `settings`, they already change without a
 * release, and giving them a second English source here would mean two places
 * to correct and one of them silently wrong. They are listed as the deliberate
 * exclusions in tests/Feature/StorefrontStringsAreKeyedTest.php.
 *
 * NOT here either: the back office. Store → anything is one operator on a
 * screen that is not indexed and not customer-facing, and the owner has
 * deferred it to its own phase. The machinery does not exclude it — an 'admin'
 * group would drop straight in — but nothing here is written for it.
 *
 * ── HOW A KEY IS NAMED ──────────────────────────────────────────────────────
 *
 * group.place.meaning — 'store.cart.empty_heading', never
 * 'store.your_cart_is_empty'. A key named after its English wording has to be
 * renamed the first time the English changes, and renaming a key orphans every
 * Arabic translation already typed against it. The FIRST segment is the Laravel
 * translation group, which is what the loader is asked for, so a page costs one
 * array lookup and never a table scan. Lowercase throughout, because
 * TranslationStore::normaliseKey() lowercases every key on the way into the
 * database and a key that differs only in case would be the same row.
 *
 * Chrome shared by every page is keyed by the COMPONENT it lives in
 * ('store.header.*', 'store.footer.*'), not by each page that draws it. A page
 * key repeated on twenty pages is twenty Arabic translations of one word.
 *
 * ── COUNTS AND PLACEHOLDERS ─────────────────────────────────────────────────
 *
 * A string with a number in it goes through trans_choice() with a named
 * placeholder, never through concatenation. Arabic has SIX plural forms to
 * English's two, and `$n . ' items'` cannot express any of them — the number
 * has to sit INSIDE the translated string, where Arabic can put it where Arabic
 * puts it. Every such string below carries its forms separated by `|`.
 *
 * A string that wraps a link or a bold amount takes it as a :placeholder and is
 * rendered with {!! !!}, for the same reason: a sentence split into "before the
 * link" and "after the link" cannot be reordered, and Arabic reorders.
 *
 * ── WHAT IS NEVER TRANSLATED ────────────────────────────────────────────────
 *
 * SKU, coupon code, order number, currency code, price, slug — the same
 * boundary App\Support\HasTranslations enforces per model with $translatable.
 * Brand names are not translated either (Tabby, Tamara, Visa, Instagram): they
 * are proper nouns and a translated one is a different company.
 */
final class InterfaceStrings
{
    /**
     * group => key => English.
     *
     * Split into one method per area of the shop rather than written as one
     * literal, because the set is a few hundred strings and a reviewer looking
     * for the cart's wording should not have to scroll past the invoices.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return [
            'store' => array_merge(
                self::storeChrome(),
                self::storeAccountPanel(),
                self::storeCatalogue(),
                self::storeCart(),
                self::storeContent(),
                self::storeHome(),
                self::storeReviews(),
                self::storeProduct(),
                self::storeCheckout(),
                self::storeOrderReceived(),
                self::storeBrands(),
                self::storeJournal(),
                self::storeReviewWall(),
                self::storeAccount(),
                self::storeOrders(),
                self::storeAddresses(),
                self::storeTrack(),
                self::storeQuiz(),
                self::storeRoutines(),
                self::storeUgc(),
                self::storeInstagram(),
                self::storeNewsletter(),
                self::storeMarketingUnsubscribe(),
                self::storeWhatsApp(),
                self::storeBlocked(),
                self::storeSiteAppPush(),
                self::storePushMessages(),
                self::storeJs(),
            ),
            'email' => array_merge(
                self::emailMessages(),
                self::emailText(),
                self::emailKit(),
                self::emailMarketing(),
            ),
            'invoice' => array_merge(
                self::invoiceDocuments(),
            ),
        ];
    }

    /**
     * The chrome every page draws: header, footer, the announcement strip,
     * the mobile menu and the bottom tab bar.
     *
     * Keyed by component rather than by page. "Wishlist" appears in the header,
     * the mobile menu and the account panel; three page-scoped keys would be
     * three Arabic translations of one word and three chances for them to
     * disagree.
     *
     * @return array<string, string>
     */
    private static function storeChrome(): array
    {
        return [
            'breadcrumb.home' => 'Home',
            'header.search_label' => 'Search',
            'header.suggestions_label' => 'Suggestions',
            'header.account_label' => 'Account',
            'header.wishlist_label' => 'Wishlist',
            'header.cart_label' => 'Cart',
            'header.trending_heading' => 'TRENDING',
            /*
             * ── THE FLAG BAR'S THREE STRINGS — Lane FB ──────────────────────
             *
             * `flagbar.text` is the line the strip SHIPS with. It is here, and
             * not a default string in HeaderSettings::SCHEMA, because the
             * shipped wording is this app's and has to be translatable — an
             * Arabic shopper reading an English reassurance about authenticity
             * is the one reader this strip exists for. Appearance → Header →
             * Flag bar → Wording overrides it, and an override is the owner's
             * own words in whatever language he types them; the schema note
             * says so where he reads it.
             *
             * The two flag names are not decoration. A flag beside the words
             * "UAE's Authentic K-Beauty Store" is carrying part of the claim,
             * so it is an image with a name rather than aria-hidden furniture.
             */
            'flagbar.text' => "UAE's Authentic K-Beauty Store",
            'flagbar.uae' => 'Flag of the United Arab Emirates',
            'flagbar.korea' => 'Flag of South Korea',
            // Lane HC: the homepage Top strip's shipped line, in two halves so
            // each can be dropped when it is not true for this shopper.
            'topstrip.text' => '1-3 Days Delivery all over UAE',
            'topstrip.free_over' => 'Free Delivery over :amount',
            'footer.tagline' => 'Authentic Korean beauty, curated for the UAE.',
            'footer.whatsapp_cta' => 'Chat on WhatsApp',
            'footer.shop_heading' => 'Shop',
            'footer.link_all_products' => 'All products',
            'footer.link_new_in' => 'New in',
            'footer.link_best_sellers' => 'Best sellers',
            'footer.link_super_sale' => 'Super sale',
            'footer.care_heading' => 'Customer Care',
            'footer.link_track_order' => 'Track my order',
            'footer.link_delivery' => 'Shipping & Delivery',
            'footer.link_returns' => 'Returns Information',
            // The Help column's link to /my-account/orders/, under the old shop's
            // own name for it (Lane TP).
            'footer.link_order_tracking' => 'Order Tracking',
            'footer.link_faqs' => 'FAQs',
            'footer.link_contact' => 'Contact us',
            'footer.account_heading' => 'My Account',
            'footer.link_my_account' => 'My Account',
            'footer.link_orders' => 'Orders',
            'footer.link_address' => 'Shipping Address',
            // The year and the shop's name are placeholders rather than text either
            // side of them: Arabic puts the year where Arabic puts the year.
            // Visa, Mastercard, Tabby, Tamara and Apple Pay are company names and are
            // not here; 'COD' is an English abbreviation and is.
            'footer.copyright' => '© :year :store UAE',
            'footer.pay_cod' => 'COD',
            // The new site footer (Lane HB, master plan row 55): the WhatsApp help
            // strip, the Discover and Visit us columns, the offers form and the
            // two policy links. (It once carried no word "return" — "we don't offer
            // returns so don't include any return word" — until the owner asked for
            // Returns Information back in the Help column, Lane TP.)
            'footer.help_headline' => 'Find your perfect K-beauty match',
            'footer.help_chip' => '24/7 available',
            'footer.help_sub' => 'Ask us anything about your skin, a product or your order — we reply on WhatsApp.',
            // The footer's app row and its install sheets (Lane FB). Apple, Android
            // and iPad are product names but keyed, so the Arabic shop can spell them.
            'footer.app_title' => 'Get the K-Beauty Bliss app',
            'footer.app_button' => 'Install App',
            'footer.app_ic_apple' => 'iPhone',
            'footer.app_ic_android' => 'Android',
            'footer.app_ic_ipad' => 'iPad',
            'footer.app_close' => 'Close',
            'footer.app_ios_title' => 'Add it to your Home Screen',
            'footer.app_ios_1' => 'Tap Share in Safari. On iOS 26 it is inside the ••• button; on iPad, at the top right.',
            'footer.app_ios_2' => 'Choose “Add to Home Screen”, then tap Add.',
            'footer.app_and_title' => 'Install the app',
            'footer.app_and_1' => 'Tap ⋮ in your browser.',
            'footer.app_and_2' => 'Choose “Install app” or “Add to Home screen”.',
            'footer.app_inapp_title' => 'Open this page in your browser first',
            'footer.app_inapp_1' => 'Tap ••• or ⋮ at the top of the screen.',
            'footer.app_inapp_2' => 'Choose “Open in browser” (Safari or Chrome), then tap Install again.',
            // The inline hints that replace the sheets (Lane IN): the row's own line.
            'footer.app_hint_and' => 'Tap ⋮ in your browser, then “Install app”.',
            'footer.app_hint_sam' => 'Tap ≡ below, then “Add page to” → Home screen.',
            'footer.app_hint_ios' => 'Tap Share ⬆︎ below, then “Add to Home Screen”.',
            'footer.app_hint_ios26' => 'Tap ••• below, then Share ⬆︎ → “Add to Home Screen”.',
            'footer.app_hint_top' => 'Tap Share ⬆︎ at the top, then “Add to Home Screen”.',
            'footer.app_hint_inapp' => 'Open this page in Safari or Chrome to install.',
            'footer.app_qr_title' => 'Scan with your phone camera',
            'footer.app_qr_1' => 'The shop opens on your phone — tap Install there.',
            // App → Site App → App update (Lane UA): the row inside the installed app.
            'footer.app_update_button' => 'Update App',
            'footer.app_update_line' => 'The newest version, one tap away.',
            'footer.app_update_ios' => 'To see the new icon: remove the app and add it again.',
            'footer.help_heading' => 'Help',
            'footer.discover_heading' => 'Discover',
            'footer.visit_heading' => 'Visit us',
            'footer.visit_dubai' => 'Dubai, UAE',
            'footer.visit_korea' => 'Korea',
            'footer.link_brands' => 'Brands',
            'footer.link_about' => 'About us',
            'footer.link_journal' => 'Journal',
            'footer.link_spotted' => '#KBeautyBliss',
            'footer.link_privacy' => 'Privacy policy',
            'footer.link_terms' => 'Terms',
            'footer.follow_label' => 'Follow us',
            'footer.news_placeholder' => 'Your email for offers',
            'footer.news_label' => 'Your email',
            'footer.news_button' => 'Join',
            // The third column, "Account" (Lane HF, the owner on 4 October: "on
            // third column will be Account and related links to access their
            // account, orders etc."). Sentence case, like the other footer links.
            'footer.account_title' => 'Account',
            'footer.link_account_home' => 'My account',
            'footer.link_my_orders' => 'My orders',
            'footer.link_wishlist' => 'Wishlist',
            'footer.link_addresses' => 'Addresses',
            // The shop's free-delivery line, printed in three places: the announcement
            // strip, the home page's delivery band and the home page's ticker. One key,
            // because it is one sentence; the amount arrives already formatted (and,
            // in two of the three, already wrapped in its <b>) so that Arabic can put
            // the figure where Arabic puts it.
            'delivery.free_over' => 'Free delivery over :amount',
            'announcement.pay_later' => 'Pay later with Tabby & Tamara',
            'mobile_menu.open_label' => 'Menu',
            'mobile_menu.nav_label' => 'Menu',
            'mobile_menu.close_label' => 'Close',
            'mobile_menu.search_placeholder' => 'Search skincare, brands…',
            'mobile_menu.hint' => 'Tap a section to open it',
            'mobile_menu.no_matches' => 'Nothing matches that.',
            'mobile_menu.link_account' => 'My account',
            'mobile_menu.link_orders' => 'Orders',
            'mobile_menu.link_sign_in' => 'Sign in',
            'mobile_menu.link_register' => 'Create an account',
            'mobile_menu.link_wishlist' => 'Wishlist',
            'tabbar.label' => 'Quick navigation',
            'tabbar.home' => 'Home',
            'tabbar.shop' => 'Shop',
            'tabbar.quiz' => 'Quiz',
            'tabbar.saved' => 'Saved',
            'tabbar.bag' => 'Bag',
            'language.switch' => 'Language',
            'language.english' => 'English',
            'language.arabic' => 'العربية',
            'breadcrumb.shop' => 'Shop',
            'breadcrumb.brands' => 'Brands',
            'breadcrumb.label' => 'Breadcrumb',
        ];
    }

    /**
     * The account panel that drops from the header icon — signed in and
     * signed out.
     *
     * @return array<string, string>
     */
    private static function storeAccountPanel(): array
    {
        return [
            'account_panel.default_name' => 'My account',
            'account_panel.link_account' => 'My account',
            'account_panel.link_orders' => 'Orders',
            'account_panel.link_wishlist' => 'Wishlist',
            'account_panel.link_addresses' => 'Addresses',
            'account_panel.link_track' => 'Track my order',
            'account_panel.sign_out' => 'Sign out',
            'account_panel.tab_sign_in' => 'Sign in',
            'account_panel.tab_register' => 'Create account',
            'account_panel.field_name' => 'Name',
            'account_panel.field_email' => 'Email',
            'account_panel.field_password' => 'Password',
            'account_panel.field_password_confirm' => 'Confirm password',
            'account_panel.stay_signed_in' => 'Stay signed in',
            'account_panel.forgotten' => 'Forgotten?',
            'account_panel.sign_in_button' => 'Sign in',
            'account_panel.register_button' => 'Create account',
            // One sentence with two links in it, not four fragments concatenated
            // around two <a> tags. Arabic puts the links where the Arabic sentence
            // puts them.
            'account_panel.terms_notice' => 'By creating an account you agree to our :terms and :privacy.',
            'account_panel.terms_link' => 'terms',
            'account_panel.privacy_link' => 'privacy policy',
        ];
    }

    /**
     * The shop archive, the collection pages, the product card and the grid.
     *
     * @return array<string, string>
     */
    private static function storeCatalogue(): array
    {
        return [
            'shop.page_title' => ':title · K-Beauty Bliss',
            'shop.eyebrow' => 'K-Beauty · Skincare',
            'shop.filters_heading' => 'Filters',
            'shop.filters_hide' => 'Hide',
            'shop.filters_show' => 'Show filters',
            'shop.facet_category' => 'Category',
            'shop.facet_brand' => 'Brand',
            'shop.facet_price' => 'Price',
            'shop.facet_offers' => 'Offers',
            'shop.facet_on_sale_only' => 'On sale only',
            'shop.facet_in_stock_only' => 'In stock only',
            // The count is formatted before it arrives (it carries its own <b>), so
            // the number passed to trans_choice picks the form and :formatted is what
            // is printed. Arabic supplies six forms here; English has two.
            'shop.product_count' => ':formatted product|:formatted products',
            'shop.columns_option' => ':count column|:count columns',
            'shop.sort_label' => 'Sort',
            // The listing pager and "Load more on scroll" (Lane PI-B).
            'shop.pages_label' => 'Pages',
            'shop.page_prev' => 'Previous page',
            'shop.page_next' => 'Next page',
            'shop.loading_more' => 'Loading more products…',
            'shop.clear_all' => 'Clear all',
            'shop.empty_heading' => 'No products match those filters',
            'shop.empty_body' => 'Try removing a filter or clearing all.',

            /*
             * THE SORT SELECT AND THE PRICE BANDS — Lane FB.
             *
             * Their English source is App\Support\Facets::SORTS and ::BUCKETS,
             * which stay as they are: a const cannot call __(), BUCKETS carries
             * the filter's own min/max beside each label, and both are compared
             * BY KEY everywhere ('plow', 'u54'), never by the label — which is
             * what makes translating the label safe at all.
             *
             * The key here is the constant's array key, so the two cannot be
             * matched up by eye and get it wrong. ShopPhpLabelsAreKeyedTest
             * fails if an English source here ever stops matching the constant.
             *
             * The figures in the price bands are left exactly as the constant
             * writes them. They are the band's own definition rather than a
             * claim that can go stale, and money DISPLAY is being changed under
             * this lane by another — see the note in the report.
             */
            'shop.sort_featured' => 'Featured',
            'shop.sort_popularity' => 'Best selling',
            'shop.sort_plow' => 'Price: low to high',
            'shop.sort_phigh' => 'Price: high to low',
            'shop.sort_rating' => 'Top rated',
            'shop.sort_date' => 'Newest',
            'shop.sort_name' => 'Name A–Z',
            'shop.price_u54' => 'Under AED 54',
            'shop.price_54_150' => 'AED 54 – 150',
            'shop.price_150_300' => 'AED 150 – 300',
            'shop.price_300p' => 'AED 300+',

            // The chips above the grid, which name the filter the shopper just
            // applied and are the control for removing it.
            'shop.chip_on_sale' => 'On sale',
            'shop.chip_in_stock' => 'In stock',

            // ShopController::heading() — the listing's own title, subtitle and
            // the crumb after Home. A category supplies its own name and
            // description from the catalogue; these are the fallbacks and the
            // unfiltered /shop/ case.
            'shop.title_all' => 'Shop all',
            'shop.title_search' => 'Search: :term',
            'shop.sub_default' => 'Authentic Korean skincare, curated for the UAE.',
            'shop.sub_search' => 'Results across products and brands.',
            'shop.crumb_category' => 'Category',
            'shop.crumb_search' => 'Search',
            // The imported category title header (Lane PT): the toggle under a
            // long category description. components/kbb-title-header.
            'shop.header_more' => 'Read more',
            'shop.header_less' => 'Read less',

            /*
             * THE SKIN QUIZ'S INLINE SCRIPT — Lane FB.
             *
             * Prefixed quiz.js_ rather than js_, so FrontEndStrings::forLocale()
             * does NOT pick them up: that table ships to every page in the shop,
             * and these are strings only /skin-quiz says. The quiz emits its own
             * window.KBB_T through FrontEndStrings::forPrefix() — see the note
             * there for the whole argument.
             *
             * WHAT IS DELIBERATELY ABSENT. Every value the shopper PICKS — the
             * skin types, concerns, ages, routine depths, budgets and allergens.
             * Those strings are compared (state.concerns.includes('Hydration'),
             * /Oily|Combination/.test(state.skin)) and are POSTed to /api/quiz as
             * the lead's answers. Translating them breaks recommend() and writes
             * Arabic answers into a table the owner reads in English. They need
             * label and value separated first, which is a change with a
             * persistence contract attached.
             *
             * The routine titles and step names below are the DISPLAY copies.
             * The English in the script stays the canonical value and is what
             * the payload carries.
             */
            'quiz.js_about_a_minute' => '~1 min',
            'quiz.js_allergy_placeholder' => 'Anything else we should avoid? (optional)',
            'quiz.js_avoiding' => 'Avoiding for you:',
            'quiz.js_back' => '‹ Back',
            'quiz.js_backend_preview' => 'What your store saves (backend preview)',
            /*
             * The way out of the quiz when the routine module is OFF — Lane Q.
             * /concern/{slug}/ is not behind that switch; it exists once the
             * owner has tagged enough products on Catalog -> Build my routine.
             * The concern in both strings is the shopper's own English answer,
             * for the same reason the routine pair below keeps it English.
             *
             * "picked by us, not by a filter" is the one claim the page makes
             * and it is true of the page it links to: /concern/ lists products
             * an operator tagged by hand, not a keyword match.
             */
            'quiz.js_concern_link_cta' => 'Shop :concern →',
            'quiz.js_concern_link_lead' => 'Everything this shop stocks for :concern, in one place — picked by us, not by a filter.',
            'quiz.js_continue' => 'Continue',
            'quiz.js_err_email' => 'Enter a valid email',
            'quiz.js_err_name' => 'Please enter your name',
            'quiz.js_err_phone' => 'Enter a valid phone',
            'quiz.js_expert_body' => 'Send your quiz to a K-Beauty Bliss skin expert — we\'ll review your routine and message you on WhatsApp.',
            'quiz.js_expert_heading' => 'Want a human to check it?',
            'quiz.js_expert_placeholder' => 'Optional note (allergies, pregnancy, current products…)',
            'quiz.js_expert_reach' => 'An expert will reach out on :phone.',
            'quiz.js_expert_send' => 'Send request to skin expert',
            'quiz.js_expert_sent' => 'Request sent — talk soon!',
            'quiz.js_eye_about' => 'About you',
            'quiz.js_eye_almost' => 'Almost there',
            'quiz.js_eye_goals' => 'Your goals',
            'quiz.js_eye_results' => 'Your results',
            'quiz.js_eye_routine' => 'Your routine',
            'quiz.js_eye_safety' => 'Safety check',
            'quiz.js_eye_skin' => 'Your skin',
            'quiz.js_label_email' => 'Email',
            'quiz.js_label_name' => 'Name',
            'quiz.js_label_phone' => 'Phone (WhatsApp)',
            'quiz.js_last_step' => 'Last step',
            'quiz.js_perk_expert' => 'Free expert help',
            'quiz.js_perk_minute' => '~1 minute',
            'quiz.js_perk_routines' => 'Personalised routines',
            'quiz.js_ph_name' => 'e.g. Fatima',
            'quiz.js_q_age' => 'Your age range?',
            'quiz.js_q_allergy' => 'Any allergies or sensitivities?',
            'quiz.js_q_budget' => 'Your budget vibe?',
            'quiz.js_q_contact' => 'Where do we send your routine?',
            'quiz.js_q_depth' => 'How many steps feel right?',
            'quiz.js_q_goals' => 'What do you want to work on?',
            'quiz.js_q_skin' => 'What\'s your skin type?',
            'quiz.js_results_sub' => 'Built for your skin & the UAE climate · emailed to :email',
            'quiz.js_results_title' => ':name, here\'s your glow plan',
            'quiz.js_retake' => '↺ Retake the quiz',
            'quiz.js_routine_boosters_desc' => 'Extra steps for your top concerns.',
            'quiz.js_routine_boosters_tag' => 'Add-ons',
            'quiz.js_routine_boosters_title' => 'Targeted Boosters',
            'quiz.js_routine_essentials_desc' => 'The core steps for 90% of your goals.',
            'quiz.js_routine_essentials_tag' => 'Start here',
            'quiz.js_routine_essentials_title' => 'Everyday Essentials',
            'quiz.js_routine_glass_desc' => 'The full layering routine, in order.',
            'quiz.js_routine_glass_tag' => 'Best results',
            'quiz.js_routine_glass_title' => 'Glass-Skin Ritual',
            /*
             * The hand-off to Build my routine — Lane FT. Rendered only when
             * that module is on; the quiz emits no table and no element when it
             * is off, so these two are inert on the shipped shop.
             *
             * :concern IS NOT TRANSLATED and must not be. It is the shopper's
             * own answer — a VALUE, compared in recommend() and stored as the
             * lead's concern — which is why the note above this block keeps the
             * whole CONCERNS array in English. The routine page prints the
             * translated name of the same concern at the far end, through
             * RoutineConcerns::labelKey().
             *
             * THE LEAD STATES THE GAP RATHER THAN HIDING IT. Lane FM's page
             * shows a step it stocks nothing for as empty; saying so here is
             * the difference between a link and a promise, and this page has
             * already made its one promise — the expert box, two elements
             * below, says a human will make contact.
             */
            'quiz.js_routine_link_cta' => 'Build my :concern routine →',
            'quiz.js_routine_link_lead' => 'A :concern routine, step by step, from what this shop stocks. Steps it stocks nothing for are shown empty rather than filled with a guess.',
            'quiz.js_see_routine' => 'See my routine →',
            'quiz.js_shop_steps' => 'Shop these steps',
            'quiz.js_start_cta' => 'Start the quiz →',
            'quiz.js_start_eye' => '1-minute skin quiz',
            'quiz.js_start_sub' => 'A few quick questions → a personalised Korean routine, matched to your concerns and the UAE climate.',
            'quiz.js_start_title' => 'Find your glow.',
            'quiz.js_step_barrier_desc' => 'A calming, repairing layer when skin feels reactive.',
            'quiz.js_step_barrier_name' => 'Barrier',
            'quiz.js_step_boost_desc' => 'A second active for your next concern.',
            'quiz.js_step_boost_name' => 'Boost',
            'quiz.js_step_cleanse_desc' => 'Lift off sunscreen, sweat and the day.',
            'quiz.js_step_cleanse_name' => 'Cleanse',
            'quiz.js_step_cleanse_oil_desc' => 'An oil or balm to break down SPF, then a gentle wash.',
            'quiz.js_step_cleanse_oil_name' => 'Cleanse (oil first)',
            'quiz.js_step_count' => ':count steps',
            'quiz.js_step_eye_desc' => 'A lighter formula for the thinner skin around the eye.',
            'quiz.js_step_eye_name' => 'Eye',
            'quiz.js_step_mask_desc' => 'A mask once or twice a week, not daily.',
            'quiz.js_step_mask_name' => 'Weekly',
            'quiz.js_step_moisturise_desc' => 'Seal the water in so the actives are tolerated.',
            'quiz.js_step_moisturise_name' => 'Moisturise',
            'quiz.js_step_of' => 'Step :n / :total',
            'quiz.js_step_protect_desc' => 'Sunscreen every morning — the UAE sun is the whole game.',
            'quiz.js_step_protect_name' => 'Protect',
            'quiz.js_step_rich_desc' => 'A heavier cream for dry or mature skin.',
            'quiz.js_step_rich_name' => 'Moisturise (richer)',
            'quiz.js_step_tone_desc' => 'Rebalance and soften before anything active.',
            'quiz.js_step_tone_name' => 'Tone',
            'quiz.js_step_treat_desc' => 'The active step for your main concern.',
            'quiz.js_step_treat_name' => 'Treat',
            'quiz.js_sub_age' => 'Helps us pick the right actives.',
            'quiz.js_sub_allergy' => 'So we steer clear of ingredients that don\'t agree with you. Optional — pick any that apply.',
            'quiz.js_sub_budget' => 'So picks feel right for you.',
            'quiz.js_sub_contact' => 'We\'ll save your results & email your plan. No spam, ever.',
            'quiz.js_sub_depth' => 'We\'ll size it to your life.',
            'quiz.js_sub_goals' => 'Choose up to 3.',
            'quiz.js_sub_skin' => 'Pick what sounds most like you.',
            'quiz.js_tap_continue' => 'Tap to continue',
            'quiz.js_toast_expert_sent' => 'Sent to a skin expert ✓',
            'quiz.js_toast_max_three' => 'Pick up to 3 concerns',
            'quiz.js_your_plan' => 'Your plan ✨',
            'collection.page_title' => ':title · K-Beauty Bliss',
            'collection.product_count' => ':formatted product|:formatted products',
            'collection.all_products' => 'All products',
            'collection.empty' => 'Nothing here just yet. :link.',
            'collection.empty_link' => 'Browse the full range',

            /*
             * THE FOUR CURATED LISTINGS — Store\CollectionController::COLLECTIONS.
             *
             * /new-in/, /best-sellers/, /super-sale/ and /under-54/ are the
             * header links, and every one of them drew an Arabic page under an
             * English heading and an English sentence beneath it.
             *
             * THE CONSTANT STAYS AS IT IS, for the reason Facets::SORTS does: a
             * const cannot call __(), and COLLECTIONS carries the SELECTION MODE
             * ('newest', 'popular', 'on_sale', 'budget') in the same row as the
             * wording. The mode is what show() switches on; the title and intro
             * are never compared against anything. The key here is built from
             * the collection's own URL key, so the two cannot be paired up by
             * eye and got wrong, and CollectionPhpLabelsAreKeyedTest fails if an
             * English source here ever stops matching the constant.
             *
             * THE FIGURE IN 'title_under_54' IS LEFT EXACTLY AS THE CONSTANT
             * WRITES IT. It is not a price this shop quotes, it is the name of
             * the listing, and the band it names is the 5400 fils ceiling in
             * show()'s 'budget' arm. The two are coupled and neither is this
             * lane's to move — money display is being changed under this lane by
             * another.
             */
            'collection.title_new_in' => 'New In',
            'collection.intro_new_in' => 'The latest Korean skincare to land, newest first.',
            'collection.title_best_sellers' => 'Best Sellers',
            'collection.title_super_sale' => 'Super Sale',
            'collection.intro_super_sale' => 'Every product currently reduced.',
            'collection.title_under_54' => 'Everything under AED 54',
            'collection.intro_under_54' => 'Small joys, gently priced.',

            /*
             * ── CONCERN-LED COLLECTIONS — Lane S ────────────────────────────
             *
             * /concern/acne/. docs/SEO-BUILD-PLAN.md ranks these first of
             * everything in the SEO plan: "korean skincare for acne" is a query
             * with buying intent and "new in" is not, and a concern page is the
             * one thing the competitor demonstrably does that this shop does
             * not.
             *
             * ONE CONCERN HAS COPY, DELIBERATELY. App\Support\
             * ConcernCollections::ENABLED lists it, and a concern with no copy
             * has no page at all — see that class's header for why a thin
             * concern page is worse than none. The other seven slugs are valid
             * RoutineConcerns and every one of them is a 404 until somebody
             * writes its sentences here.
             *
             * THE COPY IS WRITTEN TO BE READ, not assembled from the slug.
             * "Acne & blemishes" is the back-office label — it is how an
             * operator ticks a box — and it is not a heading a shopper arrives
             * on. The intro names what the shelf actually contains and makes no
             * medical claim: this is a shop, the products are cosmetics, and
             * "clears acne" is a sentence a skincare retailer must not print.
             * It says what the ingredients are known for and leaves it there.
             */
            'concern.title_acne' => 'Korean skincare for acne-prone skin',
            'concern.intro_acne' => 'Gentle, barrier-first routines for skin that breaks out. Salicylic acid and BHA to keep pores clear, centella and madecassoside to calm what is already angry, and lightweight hydration that will not sit heavily on congested skin. Every product here is one we stock and have tagged for blemish-prone skin — start with one new step at a time.',

            /*
             * ── THE OTHER SEVEN — Lane S8, and the owner asked for all of them
             *
             * His words: "tell the SEO land to finish the missing items in the
             * document. i don't want to miss or skip anything." The seven slugs
             * below were valid RoutineConcerns with no sentences, which is the
             * one thing that kept them from having a page. They have sentences
             * now, and ConcernCollections::ENABLED lists all eight.
             *
             * ENABLING A CONCERN PUBLISHES NOTHING BY ITSELF, which is why this
             * is safe to ship ahead of the owner's tagging and is the reason
             * MIN_PRODUCTS exists. A concern page needs copy AND at least
             * MIN_PRODUCTS live, in-stock products tagged for it; nothing is
             * tagged today, so all eight are 404 and all eight are absent from
             * the sitemap. ConcernCollectionsTest measures exactly that.
             *
             * ── WHAT THESE ARE WRITTEN AGAINST ─────────────────────────────
             *
             * docs/SEO-CONCERN-COPY.md carries Lane S2's owner-facing drafts for
             * `sensitivity`, `acne` and `dark-spots`. Those drafts are 200-225
             * words and carry [SQUARE BRACKET] product placeholders and named
             * brands. NEITHER SHIPS HERE, and the reason is not taste:
             *
             *   - A placeholder in a translation value is PRINTED. The shopper
             *     reads "[A CENTELLA AMPOULE OR TONER]" on the page. There is
             *     nothing in this application that resolves a bracket.
             *   - A brand named in the intro is a brand the page promises to
             *     carry. Which brands land on a concern page is decided by the
             *     owner's tagging, months from now, and S2's own note says
             *     naming a brand the page does not stock is worse than naming
             *     none.
             *
             * So the substance of the drafts is kept — the ingredients, and the
             * honest limit each one ends on — and the parts that depend on a
             * catalogue nobody has tagged yet are left out. See
             * docs/SEO-CONCERN-COPY.md §"What shipped, and what did not".
             *
             * ── THE RULES EVERY ONE OF THESE FOLLOWS ───────────────────────
             *
             * NO MEDICAL CLAIM. Not one sentence says a product will clear, fade,
             * cure or repair anything. Every claim is of the form "this is what
             * the ingredient is for", which is a statement about the ingredient
             * rather than a promise about the shopper. This shop sells cosmetics,
             * and "clears acne" is a sentence a skincare retailer must not print
             * — a liability before it is an SEO problem.
             *
             * NO PRODUCT NAME AND NO BRAND NAME, for the reason above.
             *
             * ONE PARAGRAPH, because that is the shape the template renders.
             * store/collection.blade.php prints an <h1> and one intro block; a
             * draft with H2s in it would need a template change, and that would
             * move the four curated listings that already work (rule 1).
             *
             * THE TITLE IS A SENTENCE AIMED AT A SEARCH RESULT, not the
             * back-office label. "Pores & oil" is how an operator ticks a box.
             * "Korean skincare for large pores and oily skin" is what somebody
             * types. RoutineConcerns::labelKey() still supplies the short label
             * for the card eyebrow, so there is no third wording anywhere.
             *
             * EACH ONE ENDS ON AN HONEST LIMIT. It is the house voice — see
             * quiz.js_routine_link_lead, which says steps the shop stocks
             * nothing for "are shown empty rather than filled with a guess" —
             * and it is the sentence that makes the page worth reading rather
             * than worth skimming.
             */
            'concern.title_hydration' => 'Korean skincare for dehydrated skin',
            'concern.intro_hydration' => 'Dehydrated is not the same as dry, and in this climate it is the more common of the two: skin that is oily by lunchtime and tight by evening is usually short of water rather than short of oil. Korean routines answer that with layers rather than with one heavy cream — hyaluronic acid and glycerin to draw water in, panthenol and beta-glucan to hold it there, and a light occlusive on top so the day’s air conditioning does not take it straight back out. Everything here is a product we stock and have tagged for hydration. If your skin drinks a toner and still feels tight an hour later, the missing step is usually the last one, not the first.',

            'concern.title_dark_spots' => 'Korean skincare for dark spots and uneven tone',
            'concern.intro_dark_spots' => 'Uneven tone is the slowest thing in skincare to shift and the easiest to undo. A mark left by a spot, a patch of sun damage, a shadow that was not there last year — all of it moves on a timescale of months. Niacinamide is the everyday ingredient here, gentle enough for morning and night; vitamin C is the one most brightening routines are built around; alpha arbutin, tranexamic acid and kojic acid are the more targeted options, aimed at particular marks rather than at overall dullness. Everything here is a product we stock and have tagged for tone. The part nobody enjoys hearing: sunscreen is the treatment. Without daily SPF every serum on this page is a holding action, and six weeks is not long enough to judge any of them.',

            'concern.title_ageing' => 'Korean skincare for fine lines and firmness',
            'concern.intro_ageing' => 'Fine lines show up first where skin is thinnest and moves most, and they look deeper on a dehydrated face than on a well-hydrated one — which is why so many Korean routines start here with moisture rather than with an active. Retinal and retinol are the long-game ingredients; peptides and niacinamide sit alongside them and are far easier to tolerate; ceramides and squalane are the support act that keeps a retinoid usable at all. Everything here is a product we stock and have tagged for fine lines. Two honest notes. Start a retinoid twice a week, not nightly, and expect the first month to be worse before it is better. And nothing on this page works without sunscreen — UV is the single largest cause of what it is trying to address.',

            'concern.title_sensitivity' => 'Korean skincare for sensitive, easily-irritated skin',
            'concern.intro_sensitivity' => 'Reactive skin is not a skin type you grow out of. It is usually a barrier that has been asked to do too much — too many actives at once, too hot a cleanse, or simply a year of moving between 45°C outside and dry air conditioning inside. Korean skincare is unusually good at this, because calming ingredients are the tradition rather than the specialist corner: centella asiatica, or cica, and its refined form madecassoside; heartleaf, which does similar work with a lighter feel; panthenol and ceramides on the repair side. Everything here is a product we stock and have tagged for sensitivity. One honest note. If your skin is reacting right now, the useful move is usually to take products away rather than add one — cleanse, moisturise, sunscreen, nothing else, until the stinging has stopped.',

            'concern.title_pores' => 'Korean skincare for large pores and oily skin',
            'concern.intro_pores' => 'Pore size is largely inherited and no product closes a pore, so the honest aim of this shelf is a pore that is clear and skin that is not shining by noon. Salicylic acid — BHA on most labels — is oil-soluble, which is why it works inside a pore rather than on top of it; niacinamide is the everyday ingredient for oil balance; clay and gentle enzyme exfoliants handle the texture; a low-pH cleanser keeps the whole thing from tipping over into stripped. Everything here is a product we stock and have tagged for pores and oil. The counter-intuitive part is real: skin that has been stripped makes more oil, not less, so the fix for a shiny face is rarely a stronger cleanser.',

            'concern.title_dullness' => 'Korean skincare for dull skin and glow',
            'concern.intro_dullness' => 'Dullness is usually two things at once — a layer of dead surface cells that scatters light, and skin that is short of water underneath it. Both are fixable and neither needs anything aggressive. Gentle acids, PHA and lactic acid ahead of anything stronger, deal with the surface; vitamin C and niacinamide work on tone; a hydrating essence or a sleeping mask is what actually produces the look people mean by glow, because light reflects off a well-hydrated surface and not off a dry one. Everything here is a product we stock and have tagged for dullness. If you take one thing from this page: over-exfoliating is the most common way to make dull skin duller, and twice a week is plenty.',

            'concern.title_sun' => 'Korean sunscreen and daily sun protection',
            'concern.intro_sun' => 'The UAE sun is the whole game. Sunscreen is the one step that does more for tone, texture and fine lines than everything else on this site put together, and it is the step most routines skip because most sunscreens are unpleasant to wear. Korean formulations are where that changed — light chemical filters that sink in without a white cast, hybrid and mineral options for skin that objects to the chemical ones, and finishes from dewy to genuinely matte. Everything here is a product we stock and have tagged for sun protection. What matters more than which one you buy: SPF 50 or higher, wear it every morning including indoors near a window, and reapply if you have been outside. A sunscreen you will actually put on beats a better one you will not.',

            /*
             * /best-sellers/ HAS NO INTRO IN THE CONSTANT: it is the one page
             * whose sentence is a MEASUREMENT, chosen by App\Support\RepeatPurchase
             * from the order history — "customers keep coming back" only if some
             * of them did. Both wordings are keyed, and intro() picks between
             * the keys exactly as it picked between the constants.
             */
            'collection.intro_best_sellers_measured' => 'The products our customers keep coming back for.',
            'collection.intro_best_sellers_by_units' => 'Our best sellers, by the number of units sold.',
            'product_card.badge_new' => 'New',
            // The discount badge. ':percent% OFF', not '-' . $off . '% OFF' — the
            // sign, the number and the word were three pieces of PHP string
            // concatenation, which is three things a translator cannot reorder.
            'product_card.label_off' => '-:percent% OFF',
            'product_card.label_bestseller' => 'Bestseller',
            'product_card.quick_view' => 'Quick view',
            'product_card.save_label' => 'Save',
            'product_card.add_to_cart' => 'Add to cart',
            'product_card.view_product' => 'View product',
            // (Lane PX) The pill on a sold-out product's photo and its button.
            'product_card.sold_out' => 'Sold out',
            // '1.2k' — the abbreviation is a word, and it is not 'k' in Arabic.
            'product_card.count_thousands' => ':countk',
            /*
             * A variable product's price span, e.g. "AED 35 – AED 60".
             *
             * KEYED RATHER THAN CONCATENATED for the ordinary reason — an
             * Arabic tile lays the two amounts out right to left and only a
             * whole string can say so — and because the DASH IS PART OF THE
             * WORDING. It is an en dash with hairline spaces around it, which
             * is the typographic convention for a range and is not a hyphen;
             * built by concatenation it would become whatever the next person
             * typed. Both amounts arrive already wrapped by Money::format(),
             * so this is rendered with {!! !!}.
             */
            'product_card.price_range' => ':low – :high',
            /*
             * ── AND THE REUSABLE GRID SECTION'S BUTTON READS THIS ONE — Lane GS
             *
             * It is the fallback under a product grid when the owner has
             * switched the "View all" button on and left its text box empty,
             * which is the same sentence in the same place as the two callers
             * above it — so it is the SAME KEY, not a second one.
             *
             * A second key was written first, and ArabicInterfaceDraftsTest
             * refused it twice in one run: once on the draft count and once on
             * "it never labels two controls on one screen with the same
             * Arabic", because both would have carried عرض الكل. Two keys for
             * one sentence is two things for a translator to keep in step and
             * two ways for the shop to disagree with itself.
             *
             * Everything ELSE that section prints — the heading, the
             * sub-heading, the eyebrow and the button's own text — is typed
             * into Appearance → Grid sections and is the OWNER'S, which is why
             * none of it is here: a second English source for a value the owner
             * types is one of the two silently wrong.
             */
            'product_grid.view_all' => 'View all',
            'quick_view.dialog_label' => 'Quick view',
            'quick_view.close_label' => 'Close',
            'quick_view.loading' => 'Loading…',
            'quick_view.load_failed' => 'Sorry — that product could not be loaded.',
        ];
    }

    /**
     * The cart page, the mini-cart drawer and the checkout's slim header.
     *
     * @return array<string, string>
     */
    private static function storeCart(): array
    {
        return [
            'cart.page_title' => 'Cart · K-Beauty Bliss',
            'cart.continue_shopping' => '← Continue shopping',
            'cart.heading' => 'Your Bag',
            // Six forms in Arabic, two in English. Never $n . ' items'.
            'cart.item_count' => ':count item|:count items',
            'cart.empty_heading' => 'Your bag is empty',
            'cart.empty_body' => 'Discover authentic K-Beauty to start your glow.',
            'cart.empty_cta' => 'Start shopping',
            // Both the amount and the words 'free delivery' arrive wrapped in their
            // own <b>, so the whole sentence is one translatable unit.
            'cart.free_delivery_away' => 'You\'re :amount away from :free_delivery',
            'cart.free_delivery_phrase' => 'free delivery',
            'cart.free_delivery_unlocked' => 'You\'ve unlocked free delivery!',
            'cart.decrease_quantity' => 'Decrease quantity',
            // The Recommended rail's carousel arrows, desktop only. Names, not
            // labels: nothing on screen prints these -- they are what a screen
            // reader announces for a button whose whole content is an icon.
            'cart.recommended_prev' => 'Previous recommended products',
            'cart.recommended_next' => 'More recommended products',
            'cart.increase_quantity' => 'Increase quantity',
            'cart.remove_item' => 'Remove',
            'cart.summary_heading' => 'Order Summary',
            'cart.subtotal' => 'Subtotal',
            'cart.remove_coupon' => 'Remove',
            'cart.coupon_placeholder' => 'Discount code',
            'cart.coupon_apply' => 'Apply',
            'cart.total' => 'Total',
            'cart.delivery_at_checkout' => 'Delivery calculated at checkout',
            'cart.checkout_cta' => 'Proceed to checkout →',
            'cart.continue_shopping_link' => 'or continue shopping',
            'cart_drawer.browsed_sold_out' => 'Sold out',
            'cart_drawer.browsed_in_bag' => 'In your bag — add another',
            'cart_drawer.browsed_add' => 'Add to cart',
            /*
             * The Set row (Lane SET). Two keys, read by exactly one file --
             * resources/views/partials/set-row.blade.php, which is the single
             * partial all seven surfaces draw a set through. Keyed here rather
             * than typed into the partial because they are customer-facing
             * sentences and this file is the English source of truth for those;
             * StorefrontStringsAreKeyedTest is what enforces that.
             *
             * `set.contents` is a COUNT, so it is trans_choice() with a named
             * placeholder: six forms in Arabic, two in English, never
             * $n . ' items'.
             *
             * `set.saving` is the sum of the members' own prices minus the set
             * price, computed in integer fils by App\Support\SetContents and
             * formatted by Money::plain() before it gets here. It is printed
             * only when it is positive -- a set priced above its parts is a
             * pricing mistake to correct, not a negative saving to advertise.
             */
            /*
             * ONE JAR, CLAIMED TWICE. (Lane SEC)
             *
             * When a basket holds a set AND a product that is inside it, and
             * the shelf cannot cover both, the loose line goes and the set
             * stays -- the owner's decision, in as many words. These are what
             * the shopper reads when that happens, on the cart page and on the
             * checkout, and they are the whole of the explanation: what left,
             * which set is keeping it, and why.
             *
             * `:product` and `:set` are product names out of the database, so
             * both are printed ESCAPED. Neither key carries markup, which is
             * what the parity case in ArabicInterfaceDraftsTest requires of the
             * Arabic beside it.
             */
            'cart.set_took_the_last_one' => ':product has been taken out of your bag: the last of it is inside the :set you are buying, so it cannot be bought separately as well.',
            'cart.set_took_some' => ':product has been reduced to :left in your bag: the rest of it is inside the :set you are buying, and there is not enough stock for both.',
            'set.contents' => 'In this set · :count item|In this set · :count items',
            'set.saving' => 'You save :amount',
            /*
             * The opener on the fanned-stack row. It is the button's visible
             * text AND the popup's aria-label, so a screen-reader user hears the
             * same words the button carries rather than a second phrasing of
             * them.
             */
            'set.whats_inside' => 'What\'s inside',
            /*
             * The cross that closes the tiny popup on a touch device.  (Lane SA)
             *
             * The owner: *"on mobile on-click with corner red cross icon inside
             * the circle to close the tiny popup."*
             *
             * It is the button's ACCESSIBLE NAME and nothing else — the button
             * draws an SVG cross and carries no visible text — so this string is
             * read aloud and never seen. That is exactly why it is keyed here
             * rather than typed into the partial: a name only a screen reader
             * hears is the one an English literal would survive in unnoticed,
             * and it has to be translated like every other sentence on this row.
             *
             * It is NOT shown on a pointer device at all: the stylesheet removes
             * the button inside `(hover:hover) and (pointer:fine)`, where the
             * way out is to move the pointer away.
             */
            'set.close' => 'Close',
            /*
             * ── THE SET'S OWN PRODUCT PAGE (Lane SP) ────────────────────────
             *
             * Three keys, read by exactly one file --
             * resources/views/partials/set-contents-panel.blade.php, the panel
             * that names a set's contents on /product/{set-slug}/. A shopper
             * who lands there from Google used to see a price and no contents.
             *
             * `set.contents` above is REUSED for the count line rather than a
             * fourth key of its own: it is the same sentence about the same
             * number, and two keys saying "In this set - 4 items" is two
             * translations to keep in step for nothing.
             *
             * `set.page_separately` labels the members' own prices added up --
             * what the box would cost bought one at a time. It is the figure
             * the saving is measured FROM, so the two words have to agree with
             * `set.saving` above; they are deliberately next to it.
             */
            'set.page_eyebrow' => 'The set',
            'set.page_heading' => 'What is in this set',
            'set.page_separately' => 'Bought separately',
            'set.page_set_price' => 'Set price',
            /*
             * ── THE LONG BOX'S DISCLOSURE (Lane SF) ─────────────────────────
             *
             * The contents list now sits in the BUY COLUMN, in the space the
             * quantity-bundle strip used to occupy — which is above the stock
             * line and the Add to cart button. A twelve-member box drawn in
             * full there is seven hundred pixels between the price and the
             * button, and on a phone that is the button off the bottom of the
             * screen. So the list shows the first few and folds the rest into
             * a <details>.
             *
             * BOTH WORDS ARE KEYED because both are printed: <summary> carries
             * the two labels and CSS swaps them on `details[open]`, which is
             * how a disclosure changes its own word with no script at all.
             *
             * trans_choice, with the count INSIDE the string: Arabic has six
             * plural forms to English's two and ':count more' concatenated
             * outside the translation cannot express any of them.
             */
            /*
             * THE COUNT BESIDE THE LABEL, and it is not `set.contents`.
             *
             * The list's heading line is the same `.opt-label` the
             * quantity-bundle strip used: a label, then a note. `set.contents`
             * -- "In this set · 4 items" -- was written for a paragraph
             * STANDING ALONE under a heading, and beside a label reading "What
             * is in this set" it says "in this set" twice in one line. Seen on
             * the rendered page, not in the source.
             *
             * Nor `cart.item_count`, which is the same two English words: it
             * belongs to the cart, and the rule this file opens with is that a
             * key is named for the component it lives in. Borrowing it would
             * make rewording the basket reword the product page.
             *
             * The number counts PHYSICAL ITEMS, not rows -- a box of three
             * products with two of one is four items -- which is why it is
             * worth printing at all when the rows are right there to count.
             */
            'set.count_note' => ':count item|:count items',
            'set.show_all' => 'Show :count more product|Show :count more products',
            'set.show_fewer' => 'Show fewer',
            'checkout.secure_badge' => 'Secure checkout',
        ];
    }

    /**
     * The editable content pages and the wishlist.
     *
     * @return array<string, string>
     */
    private static function storeContent(): array
    {
        return [
            'page.page_title' => ':title · K-Beauty Bliss',
            'page.last_updated' => 'Last updated :date',
            // The contact page's cards, icons and inquiry form (Lane CT).
            'contact.reach_heading' => 'Get in touch',
            'contact.reach_intro' => 'Choose the way that suits you best.',
            'contact.wa_title' => 'WhatsApp',
            'contact.wa_note' => 'Chat with our beauty team',
            'contact.wa_action' => 'Chat on WhatsApp',
            'contact.phone_title' => 'Call us',
            'contact.phone_note' => 'Speak to us directly',
            'contact.phone_action' => 'Call now',
            'contact.email_title' => 'Email',
            'contact.email_note' => 'Write to us any time',
            'contact.email_action' => 'Send an email',
            'contact.hours_title' => 'Opening hours',
            'contact.follow_title' => 'Follow us',
            'contact.follow_note' => 'New arrivals, routines and K-beauty tips.',
            'contact.form_title' => 'Send us a message',
            'contact.form_intro' => 'Fill in the form and we will reply by email.',
            'contact.label_name' => 'Your name',
            'contact.label_email' => 'Email address',
            'contact.label_phone' => 'Phone',
            'contact.optional' => 'optional',
            'contact.label_topic' => 'Topic',
            'contact.topic_choose' => 'Choose a topic',
            'contact.label_message' => 'Message',
            'contact.submit' => 'Send message',
            'contact.privacy' => 'We use your details only to answer this message.',
            'contact.hp_label' => 'Leave this field empty',
            'contact.sent_title' => 'Thank you!',
            'contact.sent' => 'Your message has been sent. We will get back to you soon.',
            'contact.err_summary' => 'Please check the fields marked below.',
            'contact.err_name' => 'Please enter your name.',
            'contact.err_email' => 'Please enter a valid email address.',
            'contact.err_phone' => 'Please enter a valid phone number, or leave it empty.',
            'contact.err_topic' => 'Please choose a topic.',
            'contact.err_message' => 'Please write a message of at least 10 characters.',
            'contact.err_message_long' => 'Please keep your message under 5,000 characters.',
            'contact.err_fast' => 'That was quick! Please check your message and press Send again.',
            'contact.err_limit' => 'You have sent several messages in a short time. Please try again later, or reach us on WhatsApp.',
            'contact.topic_order' => 'Order question',
            'contact.topic_advice' => 'Product advice',
            'contact.topic_wholesale' => 'Wholesale',
            'contact.topic_other' => 'Other',
            'wishlist.breadcrumb_home' => 'Home',
            'wishlist.breadcrumb_current' => 'Wishlist',
            'wishlist.title' => 'Wishlist',
            'wishlist.saved_count' => ':count saved',
            'wishlist.subtitle' => 'Everything you have saved, newest first.',
            'wishlist.keep_browsing' => 'Keep browsing',
            'wishlist.empty_title' => 'Nothing saved yet',
            'wishlist.empty_body' => 'Tap the heart on any product to keep it here for later.',
            'wishlist.empty_cta' => 'Start browsing',
            'wishlist.grid_label' => 'Saved',
            'wishlist.page_title' => 'Wishlist · K-Beauty Bliss',
        ];
    }

    /**
     * The home page, section by section, in the order the page draws them.
     *
     * The banner slides, the newsletter block and the three trust claims are NOT
     * here: every one of them is already an admin setting, and a second English
     * source for a value the owner types would be one of the two silently
     * wrong.
     *
     * @return array<string, string>
     */
    private static function storeHome(): array
    {
        return [
            /*
             * ── THE CARDS BANNER'S TWO STRINGS — Lane BN ────────────────────
             *
             * The dots under the cards banner. They are the only words that
             * section prints which are not an operator's own: the heading, the
             * body, the alt text and the button label are all typed into
             * Appearance -> Banners -> Cards banner and are the owner's, which
             * is why they are NOT here — the note above this method's siblings
             * gives the rule ("a second English source for a value the owner
             * types would be one of the two silently wrong").
             */
            'home.cards_banner_nav' => 'Banner cards',
            'home.cards_banner_go' => 'Go to card :n',
            'home.slider_previous' => 'Previous',
            'home.slider_next' => 'Next',
            /*
             * The picture slider — Lane BN2. Every one of these is an
             * ACCESSIBLE NAME rather than something drawn on the page: the
             * arrows and the bars are icon-only buttons and these are what a
             * screen reader says for them, and `banner_slider_live` is what the
             * live region announces when a shopper moves the slider himself.
             *
             * "Picture" and not "slide" on purpose. The owner's words were "only
             * images slider", the control carries no text layer at all, and
             * "slide 3 of 5" is presentation jargon for a thing a shopper thinks
             * of as a picture.
             */
            'home.banner_slider_label' => 'Picture slider',
            'home.banner_slider_prev' => 'Previous picture',
            'home.banner_slider_next' => 'Next picture',
            'home.banner_slider_bars' => 'Choose a picture',
            'home.banner_slider_go' => 'Show picture :n',
            'home.banner_slider_slide' => 'Picture :n of :total',
            'home.banner_slider_live' => 'Picture :n of :total',
            'home.banner_slider_pause' => 'Pause the slideshow',
            'home.banner_slider_play' => 'Play the slideshow',
            'home.category_product_count' => ':count product|:count products',
            'home.bundles_heading' => 'Big savings bundles',
            'home.bundles_count' => ':count set|:count sets',
            'home.bundles_subtitle' => 'Complete routines, priced below the sum of their parts.',
            'home.bundles_link' => 'All sets',
            'home.bundles_grid_label' => 'Skincare sets',
            'home.recommended_heading' => 'Recommended for you',
            'home.recommended_badge' => 'Updated daily',
            'home.recommended_subtitle' => 'Handpicked K-beauty essentials for glowing skin.',
            'home.recommended_link' => 'Shop more',
            'home.recommended_grid_label' => 'Recommended',
            'home.routine_heading' => 'Build your routine',
            'home.routine_steps' => ':count step|:count steps',
            'home.routine_link' => 'Routine guide',
            'home.routine_add_all' => 'Add the whole routine · :amount',
            'home.quiz_kicker' => 'Two minutes · free',
            // The <br> is part of the string, not markup wrapped around two halves of
            // it: the line break falls in a different place in Arabic, and only a
            // translator can say where. Rendered with {!! !!} for that reason.
            'home.quiz_heading' => 'Not sure where<br>to start?',
            'home.quiz_body' => 'Answer five questions and we will build a routine from what we actually stock — with the reasoning behind every pick.',
            'home.quiz_stat_matched' => 'products matched',
            'home.quiz_stat_questions' => 'quick questions',
            'home.quiz_stat_cost' => 'cost, no signup',
            'home.quiz_step' => 'Question :current of :total',
            'home.quiz_question' => 'How does your skin usually feel by mid-afternoon?',
            'home.quiz_option_oily' => 'Shiny all over',
            'home.quiz_skin_oily' => 'Oily',
            'home.quiz_option_dry' => 'Tight or flaky',
            'home.quiz_skin_dry' => 'Dry',
            'home.quiz_option_combo' => 'Oily T-zone, dry cheeks',
            'home.quiz_skin_combo' => 'Combination',
            'home.quiz_option_sensitive' => 'Red or stinging',
            'home.quiz_skin_sensitive' => 'Sensitive',
            'home.quiz_option_normal' => 'Comfortable, no change',
            'home.quiz_skin_normal' => 'Normal',
            'home.quiz_hint' => 'Pick the closest one',
            'home.quiz_continue' => 'Continue →',
            'home.brands_heading' => 'Top brands',
            'home.brands_count' => ':count brand|:count brands',
            'home.brands_link' => 'All brands',
            'home.spotted_heading' => '#KBeautyBliss spotted',
            'home.spotted_badge' => 'Shoppable',
            'home.spotted_subtitle' => 'Real routines from our community.',
            'home.spotted_link' => 'Discover more',
            // #KBeautyBliss Spotted (Lane HB, master plan row 55 item 4): the
            // homepage carousel and the /kbeautybliss-spotted/ page. The owner's
            // own wording can replace each on Appearance → #KBeautyBliss Spotted;
            // these are what an empty box prints.
            'spotted.home_heading' => '#KBeautyBliss — Seen on Instagram',
            'spotted.home_sub' => 'Real unboxings, shelfies and glow-ups from our K-beauty community across the UAE.',
            'spotted.button' => 'See every #KBeautyBliss look',
            'spotted.prev' => 'Previous posts',
            'spotted.next' => 'More posts',
            'spotted.likes' => 'likes',
            'spotted.new_tab' => '(opens Instagram in a new tab)',
            'spotted.page_h1' => '#KBeautyBliss Spotted',
            'spotted.page_intro' => 'Our community’s real K-beauty moments — routines, unboxings and results from across the UAE. Tap a photo to see the post on Instagram or to shop what is in it.',
            'spotted.seo_title' => '#KBeautyBliss Spotted — K-Beauty Routines from Our UAE Community',
            'spotted.seo_desc' => 'Real Korean skincare routines, unboxings and results shared by K-Beauty Bliss customers across the UAE. See their posts and shop the products they love.',
            'spotted.breadcrumb_label' => 'Breadcrumb',
            'spotted.empty' => 'New posts are on their way — check back soon.',
            // (Lane HS) The homepage's static grid: its heading, in the owner's
            // own casing from his reference, and each picture's description
            // until he types one.
            'spotted.grid_heading' => '#KBEAUTYBLISS Spotted',
            'spotted.grid_photo' => '#KBeautyBliss Spotted photo :n',
            // (Lane SG) The Instagram cards on /kbeautybliss-spotted/: the
            // counts' spoken names, the type badges, and the video player.
            'spotted.comments' => 'comments',
            'spotted.shares' => 'shares',
            'spotted.views' => 'views',
            'spotted.reel' => 'Reel',
            'spotted.album' => 'Album',
            'spotted.ig_alt' => 'Instagram post by @:handle',
            'spotted.ig_alt_video' => 'Instagram reel by @:handle',
            'spotted.play' => 'Play the video here',
            'spotted.close' => 'Close',
            'spotted.view_on_ig' => 'View on Instagram',
            'spotted.video_dialog' => 'Instagram video',
            'home.bestsellers_heading' => 'Best sellers',
            'home.bestsellers_badge' => 'This month',
            'home.bestsellers_subtitle' => 'The products customers keep coming back for.',
            'home.bestsellers_link' => 'Shop more',
            'home.bestsellers_grid_label' => 'Best sellers',
            'home.flash_heading' => 'Flash sale · up to 50% off',
            'home.flash_badge' => 'While stocks last',
            'home.flash_subtitle' => 'Deep cuts, limited stock.',
            'home.flash_link' => 'See all',
            'home.flash_grid_label' => 'Flash sale',
            'home.journal_heading' => 'Skincare guide',
            'home.journal_badge' => 'Journal',
            'home.journal_subtitle' => 'Read before you buy.',
            'home.journal_link' => 'All articles',
            'home.read_minutes' => ':count min read|:count min read',
            'home.about_heading' => 'About K-Beauty Bliss',
            // The DEFAULT of the `about_text` setting, which is why it is here: with
            // nothing saved this is what a shopper reads, so it is interface text
            // until the owner replaces it.
            'home.about_body' => 'We are passionate about bringing the best of Korean beauty to skincare enthusiasts across the UAE. Every item is curated to meet the highest standards of quality and effectiveness.',
            'home.about_stat_products' => 'products stocked',
            'home.about_stat_brands' => 'Korean brands',
            'home.about_stat_reviews' => 'verified reviews',
            'home.count_thousands_plus' => ':countk+',
            'home.about_link' => 'Our story',
            'home.reviews_heading' => 'What our customers say',
            'home.reviews_average' => ':rating average',
            'home.reviews_subtitle' => ':formatted verified review from real orders.|:formatted verified reviews from real orders.',
            'home.reviews_count' => ':formatted review|:formatted reviews',
            'home.reviews_link' => 'Read all',
            'home.trust_delivery_title' => 'Delivery',
            'home.trust_free_over' => 'Free over :amount',
            'home.trust_payments_title' => 'Secure payments',
            'home.trust_payments_text' => 'Card, Tabby, Tamara and COD',
            'home.trust_support_text' => 'WhatsApp :phone',
        ];
    }

    /**
     * The review cards and the review wall, which the home page and
     * /reviews/ both draw.
     *
     * @return array<string, string>
     */
    private static function storeReviews(): array
    {
        return [
            'reviews.filter_all' => 'All',
            'reviews.filter_photos' => 'With photos',
            'reviews.filter_helpful' => 'Most helpful',
            'reviews.verified_badge' => '✓ Verified',
            'reviews.eyebrow' => 'Loved by you',
            'reviews.heading' => 'Customer Reviews',
            'reviews.short_heading' => 'Reviews',
            'reviews.review_count' => ':count review|:count reviews',
            'reviews.write_button' => '✎ Write a Review',
            'reviews.write_link' => 'Write a review',
            'reviews.filter_with_photos' => 'With Photos',
            'reviews.reply_from' => 'Reply from K-Beauty Bliss',
            'reviews.load_more' => 'Load more reviews',
            'reviews.close_label' => 'Close',
            'reviews.form_heading' => 'Share your experience ♡',
            'reviews.field_rating' => 'Your rating',
            'reviews.field_name' => 'Name',
            'reviews.field_name_placeholder' => 'First name',
            'reviews.field_email' => 'Email',
            'reviews.field_email_note' => '(not shown)',
            'reviews.field_email_placeholder' => 'you@email.com',
            'reviews.field_title' => 'Title',
            'reviews.field_title_placeholder' => 'Sum it up ✨',
            'reviews.field_review' => 'Your review',
            'reviews.field_review_placeholder' => 'Tell us what you loved…',
            'reviews.field_photos' => 'Add photos',
            'reviews.field_photos_hint' => '(optional, up to :count)|(optional, up to :count)',
            'reviews.photos_cta' => '📷 Tap to add photos',
            'reviews.field_captcha' => 'Quick check:',
            'reviews.field_captcha_placeholder' => 'Answer',
            'reviews.submit' => 'Submit Review',
            'reviews.empty' => 'No reviews yet — be the first to review this product.',
            'reviews.loved_by' => 'Loved by :formatted shopper|Loved by :formatted shoppers',
            'reviews.review_count_formatted' => ':formatted review|:formatted reviews',
            'reviews.time_ago' => ':time ago',
        ];
    }

    /**
     * The product page: the buy box, the stock line, the dispatch cutoff, the
     * details tabs and the frequently-bought-together strip.
     *
     * The three trust chips beside the buy button are NOT here. Every one of
     * them is already the owner's own words (App\Support\TrustClaims and
     * App\Support\DeliveryLine) and a second English source would be a second
     * place to correct them.
     *
     * @return array<string, string>
     */
    private static function storeProduct(): array
    {
        return [
            // The DEFAULT of the `review_badge_label` setting. `{n}` is that setting's
            // own token, substituted by the page, not a Laravel placeholder.
            'product.review_badge_label' => '{n} reviews',
            'product.sold_thousands' => ':countk+ sold',
            'product.choose_option' => 'Choose your option',
            // The label for a variant that has none of its own. The number is a
            // placeholder, not 'Option ' . ($n + 1).
            'product.option_fallback' => 'Option :number',
            'product.sold_out_tag' => 'Sold out',
            'product.save_percent' => 'Save :percent%',
            'product.bundles_note' => 'Save more with bundles',
            'product.stock_sold_out' => 'Sold out — check back soon',
            // The count is inside the sentence because Arabic decides where a number
            // goes in a sentence and how the noun after it inflects. English has one
            // form for every value above one, so both sides of the pipe are the same
            // here; Arabic will have six.
            'product.stock_low' => 'Only :count left · order soon|Only :count left · order soon',
            'product.stock_in' => 'In stock · ready to ship',
            // Both the countdown and the date arrive wrapped in their own <b>, so the
            // sentence stays one unit. The <b id="cutoff"> is what pdp.js ticks.
            'product.cutoff_delivery' => 'Order within :remaining for delivery by :date',
            'product.cutoff_dispatch' => 'Order within :remaining to ship on :ship',
            'product.buy_now' => 'Buy it now',
            'product.trust_pay_later' => 'Tabby & Tamara',
            'product.details_eyebrow' => 'The details',
            'product.details_heading' => 'Product details',
            /*
             * Lane QA. `share_open` names the share button in the title row
             * (Lane QB's sheet is what it opens). The two pay-later lines are
             * the DEFAULTS of settings on Appearance → Product page → Mobile
             * sections, read through ProductMobileSections::text() the way
             * ProductTrustShare::text() reads its own: the key prints while
             * the setting is still this English. "up to" — the owner typed
             * "upto".
             */
            'product.share_open' => 'Share',
            'product.paylater_tabby' => 'Split your purchase into monthly payments',
            'product.paylater_tamara' => 'Installments up to 6 months, no late fees!',
            'product.read_more' => 'Read more ↓',
            /*
             * THE THREE TAB HEADINGS, which were English literals inside
             * Store\ProductController::tabs() and so were missed by the
             * conversion — a heading is an interface string wherever it is
             * built. The BODIES under them are catalogue content and are read
             * with t() against the product's own row; these three words are the
             * same on every product page and are keyed.
             */
            'product.tab_description' => 'Description',
            'product.tab_ingredients' => 'Ingredients',
            'product.tab_how_to_use' => 'How to use',
            'product.related_eyebrow' => 'Complete your routine',
            'product.related_heading' => 'You may also like',
            // The carousel's two arrows (Lane PS). Read by a screen reader only.
            'product.related_prev' => 'Previous products',
            'product.related_next' => 'More products',
            // The product page's blocks (Lane RP; Lane RP2; Lane BC): the tab
            // and block names carry the brand's and the category's own
            // (translated) names; "Best sellers" is the best-seller block's
            // heading and Continue shopping's line before anything is viewed.
            'product.recs_tab_brand' => 'More from :brand',
            'product.recs_tab_category' => 'More :category',
            'product.recs_tabs_label' => 'Show more from',
            'product.recs_more_eyebrow' => 'More like this',
            'product.recs_recent_heading' => 'Continue shopping',
            'product.recs_recent_eyebrow' => 'Recently viewed',
            'product.recs_best_eyebrow' => 'Best sellers',
            'product.save_to_wishlist' => 'Save to wishlist',
            // The DEFAULT of the `fbt_title` setting.
            'fbt.title' => 'Complete your routine',
            'fbt.total' => 'Total: :amount',
            'fbt.add_selected' => 'Add selected to cart',
            /*
             * "Buy these together" (Lane RB), which replaced the block above in
             * its slot. `heading` is what an empty Heading box prints. The three
             * `button*` strings are the button's words for 2+, 1 and 0 ticked
             * products; the page prints all three into data attributes so the
             * script counts in the page's own language. The rest are what the
             * one-request add answers with.
             */
            'buy_together.heading' => 'Buy these together',
            'buy_together.button' => 'Buy :count items together',
            'buy_together.button_one' => 'Add 1 item to cart',
            'buy_together.button_none' => 'Tick at least one product',
            'buy_together.total' => 'Total:',
            'buy_together.sold_out' => 'Sold out',
            'buy_together.added' => ':count items added to your bag',
            'buy_together.added_one' => 'Added to bag',
            'buy_together.gone' => 'One of these is no longer available.',
            'buy_together.option_gone' => 'That option of :name is no longer available.',
            'buy_together.choose_option' => 'Choose an option for :name first.',
            'buy_together.sold_out_named' => ':name is sold out.',
            'buy_together.not_added' => ':name could not be added.',
            /*
             * Lane RE — the bundle discount. `saving` sits in front of the
             * amount in a green pill under the total; `bundle_badge` is the
             * small tag on a bundled line in the cart, the drawer and the
             * checkout; `bundle_row` is the summary row the coupon's own row
             * sits under, so the two discounts are told apart.
             */
            'buy_together.saving' => "You're saving",
            'buy_together.bundle_badge' => 'Bought together · :percent% off',
            'buy_together.bundle_row' => 'Buy-together discount',
            'quick_view.in_stock' => 'In stock',
            'quick_view.out_of_stock' => 'Out of stock',
            'quick_view.view_full' => 'View full details',
            'notify_me.email_label' => 'Your email address',
            'notify_me.email_placeholder' => 'you@example.com',
            'notify_me.submit' => 'Email me',
            'cart_reminder.email_label' => 'Your email address',
            'cart_reminder.email_placeholder' => 'you@example.com',
            'cart_reminder.submit' => 'Remind me',
            'product.gallery_front' => 'Front',
            /*
             * Lane PW — the delivery box, "Authenticity Guaranteed" and the share
             * bar. The first five are the DEFAULTS of settings on Appearance →
             * Product page → Trust ·, read through ProductTrustShare::text(): while
             * a setting still holds exactly this English the page prints the key,
             * so /ar gets the reviewed Arabic; once the owner types his own words
             * his words print everywhere. `{free_from}` is that setting's own
             * token (the free-delivery figure), not a Laravel placeholder.
             */
            'product.pts_del_line1' => 'Express 1-3 Days Delivery All over UAE',
            'product.pts_del_line2' => 'Free Delivery over {free_from}',
            'product.pts_auth_label' => 'Authenticity Guaranteed',
            'product.pts_auth_text' => \App\Services\ProductTrustShare::AUTH_TEXT,
            'product.pts_share_label' => 'Share',
            'product.pts_close' => 'Close',
            'product.pts_copy' => 'Copy link',
            'product.pts_copied' => 'Link copied',
            'product.pts_copy_fail' => 'Copy this link:',
            'product.pts_more' => 'More ways to share',
            // The network's own name is a proper noun and is not translated.
            'product.pts_share_on' => 'Share on :network',
            /*
             * Lane QB — the share sheet the title's share icon opens. The
             * heading is the DEFAULT of Appearance → Product page → Share →
             * "Sheet heading" (read through ProductTrustShare::text()); the
             * four tile names are the tiles that are not a company's name;
             * `pts_share_btn` is the share icon's accessible name, for Lane
             * QA's button.
             */
            'product.pts_sheet_heading' => 'Share this product with friends',
            // 2.60.365 — the link preview card's shipped wording (ProductTrustShare::text()).
            'product.pts_card_p1' => 'Express delivery all over UAE & Gulf',
            'product.pts_card_p2' => '100% original products from the brand',
            'product.pts_card_p3' => 'Accepts Tabby & Tamara',
            'product.pts_card_msg' => 'See what I’ve found on K-Beauty Bliss 💖',
            'product.pts_share_btn' => 'Share this product',
            'product.pts_tile_messages' => 'Messages',
            'product.pts_tile_email' => 'Email',
            'product.pts_tile_copy' => 'Copy',
            'product.pts_tile_more' => 'More',
            'notify_me.privacy' => 'We will email you once, when this product is back. Your address is used for that and nothing else — it is not added to our mailing list, and every message has an unsubscribe link.',
            'cart_reminder.privacy' => 'We will store your email address with this basket so we can remind you about it. If you place your order, we stop. Every reminder has an unsubscribe link, and using it stops these emails for good.',
        ];
    }

    /**
     * The checkout, its four steps, the order block and the mobile bag strip.
     *
     * The legal notice above Place order, the reassurance chips and the coupon
     * hint are NOT here: each is already the owner's own wording
     * (App\Support\CheckoutLegalNotice, App\Support\TrustClaims) with no
     * default to translate.
     *
     * @return array<string, string>
     */
    private static function storeCheckout(): array
    {
        return [
            'checkout.page_title' => 'Checkout · K-Beauty Bliss',
            'checkout.back_to_shop' => '← Back to shop',
            'checkout.heading' => 'Checkout',
            'checkout.lead' => 'Almost glowing — just a few details.',
            'checkout.back_to_cart' => 'Go back to cart',
            'checkout.coupon_prompt' => 'Have a discount code?',
            'checkout.coupon_placeholder' => 'Enter promo code',
            'checkout.coupon_apply' => 'Apply',
            // Lane CP: the line under the coupon box, applied in place. The
            // nine refusals are CouponService's own sentences, word for word,
            // keyed by CouponService::REASONS so Arabic can say them too.
            'checkout.coupon_applied' => 'Coupon :code applied — you save :amount',
            'checkout.coupon_applied_plain' => 'Coupon :code applied',
            'checkout.coupon_removed' => 'Coupon removed',
            'checkout.coupon_remove' => 'Remove',
            'checkout.coupon_err_invalid' => 'That code is not valid.',
            'checkout.coupon_err_not_started' => 'That code is not active yet.',
            'checkout.coupon_err_expired' => 'That code has expired.',
            'checkout.coupon_err_used_up' => 'That code has been fully redeemed.',
            'checkout.coupon_err_minimum' => 'Your basket does not meet the minimum for that code.',
            'checkout.coupon_err_maximum' => 'That code does not apply to a basket this size.',
            'checkout.coupon_err_account' => 'That code is not available on this account.',
            'checkout.coupon_err_already_used' => 'You have already used that code.',
            'checkout.coupon_err_no_items' => 'That code does not apply to anything in your basket.',
            'checkout.step_contact' => 'Contact',
            'checkout.step_shipping' => 'Shipping address',
            'checkout.step_delivery' => 'Delivery',
            'checkout.step_payment' => 'Payment',
            'checkout.field_email' => 'Email address',
            'checkout.field_email_placeholder' => 'you@email.com',
            'checkout.field_phone' => 'Phone',
            'checkout.field_phone_placeholder' => '+971 5x xxx xxxx',
            'checkout.field_full_name' => 'Full name',
            'checkout.field_full_name_placeholder' => 'First and last name',
            'checkout.field_first_name' => 'First name',
            'checkout.field_last_name' => 'Last name',
            'checkout.field_address' => 'Address',
            'checkout.field_address_placeholder' => 'Street, building / villa no.',
            'checkout.field_state' => 'Emirate',
            // Lane AD: the label follows the country while Appearance -> Checkout
            // page -> Fields & attention -> "Emirate / state as a list" is on
            // (App\Support\AddressRegions). `field_state` stays the UAE's.
            'checkout.field_state_governorate' => 'Governorate',
            'checkout.field_state_region' => 'Region',
            'checkout.field_state_municipality' => 'Municipality',
            'checkout.field_state_other' => 'Town / city',
            'checkout.field_state_select' => 'Select',
            // And the two boxes around it, renamed by the owner on 7 October:
            // "The address field should call it, Building / Apartment or Villa
            // and the City/ Area will be Area / Street and the Emirates will
            // work as City."
            'checkout.field_building' => 'Building / Apartment or Villa',
            'checkout.field_building_placeholder' => 'Building name, apartment or villa no.',
            'checkout.field_area_street' => 'Area / Street',
            'checkout.field_area_street_placeholder' => 'e.g. Al Barsha 1, Street 12',
            'checkout.field_city' => 'City / area',
            'checkout.field_city_placeholder' => 'e.g. Al Reem Island',
            'checkout.field_country' => 'Country',
            'checkout.country_detected' => 'Detected',
            'checkout.field_notes' => 'Delivery notes',
            'checkout.field_notes_placeholder' => 'Delivery instructions, a landmark, a preferred time',
            'checkout.optional_note' => '(optional)',

            /*
             * The `inline_validation` module's wording — Lane FI.
             *
             * Four sentences, one per validate-* class the checkout's markup
             * already carries, and they are HERE rather than in the partial
             * that prints them because StorefrontStringsAreKeyedTest walks the
             * directory: a sentence typed into a Blade file is English only,
             * whatever the shop's language is.
             *
             * They are rendered by the SERVER into a JSON island and read from
             * there by the script, not looked up through resources/js/kbb/i18n.js.
             * That is deliberate: CLAUDE.md records that asset builds on this
             * project are manual, so a key added here reaches the shop with the
             * package while a key the bundle would have to know about does not.
             *
             * Each says what to DO, not what is wrong. "This field is required"
             * describes the form's problem; "Please fill this in" describes the
             * shopper's next action, and it is the shopper reading it.
             */
            'checkout.validate_required' => 'Please fill this in.',
            'checkout.validate_email' => 'Please enter an email address, like you@email.com.',
            'checkout.validate_state' => 'Please choose one from the list.',
            'checkout.validate_phone' => 'Please enter a phone number, or leave this empty.',
            'checkout.create_account' => 'Create an account for faster checkout next time',
            'checkout.password_placeholder' => 'Choose a password (8 characters or more)',
            /*
             * THE BOX RECORDS `whatsapp_optin`, which is why this sentence
             * names WhatsApp as well as email rather than email alone.
             *
             * The owner asked for "Send order updates & new offers via email".
             * The checkbox is `billing_kbb_whatsapp` and CheckoutController
             * writes it to the order's and the customer's `whatsapp_optin`
             * column; promising email only, while storing consent for a
             * channel the sentence never mentioned, is the kind of mismatch
             * that is invisible until somebody asks what was agreed to. The
             * wording covers both channels, which is what the box is actually
             * for. Storing the two consents separately is a column and a
             * migration, and is worth doing if the shop wants to mail offers
             * to people who declined WhatsApp.
             */
            'checkout.whatsapp_optin' => 'Send me order updates and new offers — on WhatsApp and by email.',
            // One sentence with the country name inside it. It used to be three lines
            // of template with the name interpolated between them, which is the shape
            // no translator can reorder — and this is the one place on the checkout
            // where the country is named to the shopper.
            'checkout.unserved_country' => 'We do not deliver to :country yet. Choose another country above, or contact us and we will see what we can do.',
            'checkout.delivery_loading' => 'Loading delivery options…',
            'checkout.gift_option' => 'This order is a gift',
            'checkout.gift_note_placeholder' => 'Your message, printed on the gift card',
            // The remaining count is its own <span> because the script rewrites only
            // that element. It is passed as :remaining, not as trans_choice's :count,
            // because choice() overwrites :count with the raw number and the markup
            // would be thrown away.
            'checkout.gift_characters_left' => ':remaining characters left|:remaining characters left',
            'checkout.or_pay_with' => 'or pay with',
            'checkout.no_payment_method' => 'No payment method is available for this order total. Please contact us and we will take your order directly.',
            // The card form on this page — see partials/checkout/stripe-card.
            //
            // The one line above the fields, with a padlock beside it. It
            // replaced both a three-sentence paragraph (StripeGateway::
            // description(), which now returns null for the same reason) and the
            // smaller note that used to sit under the fields, at the owner's
            // request: "make the field more nice and clear". The ampersand is
            // his wording, and it is a literal rather than an entity because
            // everything in this file is escaped on the way out.
            'checkout.card_secure_line' => '100% secure & encrypted — Use any card',
            // Above each of the three boxes. Drawn uppercase by the partial's
            // own letter-spaced rule rather than shouted here, so each Arabic
            // translation is an ordinary phrase.
            'checkout.card_number_label' => 'Card number',
            'checkout.card_expiry_label' => 'Expiration date',
            'checkout.card_cvc_label' => 'Security code',
            // The tick beneath the fields. Shown only to somebody who has an
            // account or is making one in this same checkout — see the note in
            // partials/checkout/stripe-card.
            'checkout.card_save' => 'Save this card for future purchases.',
            'checkout.card_return_to_basket' => 'Cancel this payment and return to your basket',
            'checkout.card_not_ready' => 'The card form is still loading. Please wait a moment and try again.',
            'checkout.card_generic_error' => 'We could not take that card. Please check the details or try another card.',
            'checkout.card_working' => 'Confirming your payment…',
            /*
             * The express wallet row — Apple Pay and Google Pay. Read by
             * resources/views/partials/checkout/express-wallets.blade.php, the
             * same way the card keys above are read by stripe-elements: through
             * @json(__()) into the inline script, because the storefront's
             * built JavaScript cannot be rebuilt on this host.
             *
             * "Apple Pay" and "Google Pay" themselves are NOT here. They are
             * company names — a translated one is a different company — and the
             * buttons are drawn by Stripe, which writes and localises its own
             * wording for both sheets.
             */
            'checkout.wallet_details_first' => 'Please fill in your contact and delivery details above first, then tap again.',
            'checkout.wallet_total_moved' => 'Your order total changed while the payment sheet was open, so nothing has been charged. Your basket is safe — please check the total and try again.',
            'checkout.wallet_failed' => 'That payment did not go through and nothing has been charged. Your basket is safe — please try again or pay by card below.',
            'checkout.wallet_working' => 'Confirming your payment…',
            /*
             * Lane CO: the sold-out dialog Place order opens instead of red
             * text, and the sentence for a basket that is genuinely gone.
             * Rendered by CheckoutController (soldOutAnswer(), bagGone()) and
             * sent in the JSON answer, so the script translates nothing.
             */
            'checkout.bag_gone' => 'The basket on this page is no longer available — it may have been ordered or changed in another tab.',
            'checkout.bag_gone_ordered' => 'This basket has already been ordered (order :number).',
            'checkout.bag_gone_view_order' => 'View your order',
            'checkout.bag_gone_view_cart' => 'View your basket',
            'checkout.so_title' => 'Sorry — sold out',
            'checkout.so_intro' => 'These sold out while you were checking out:',
            'checkout.so_line_gone' => ':name is sold out',
            'checkout.so_line_short' => 'Only :left left of :name — your bag will keep :left',
            'checkout.so_remove' => 'Remove and continue',
            'checkout.so_working' => 'Updating your bag…',
            'checkout.so_failed' => 'Could not update your bag — please try again.',
            'checkout.so_empty' => 'Your bag is now empty.',
            'checkout.so_shop' => 'Continue shopping',
            'checkout.so_done' => 'Your bag is updated. Please check the total and place your order.',
            'checkout.tab_summary' => 'Order summary',
            'checkout.tab_browsed' => 'Browsed',
            'checkout.browsed_heading' => 'Recently browsed — add in one tap',
            'checkout.browsed_add' => 'Add',
            'checkout.browsed_add_label' => 'Add :product to cart',
            'checkout.browsed_empty' => 'Everything you\'ve looked at is already in your bag.',
            'checkout.remove_item_label' => 'Remove :product',
            'checkout.view_summary_open' => 'View full summary ▾',
            // The other half of the toggle above, flipped by resources/js/kbb/checkout.js.
            'checkout.view_summary_close' => 'Hide full summary ▴',
            'checkout.subtotal' => 'Subtotal',
            'checkout.delivery' => 'Delivery',
            'checkout.free' => 'Free',
            'checkout.gift_wrapping' => 'Gift wrapping',
            'checkout.cod_fee' => 'Cash-on-delivery fee',
            'checkout.total' => 'Total',
            'checkout.place_order' => 'Place order',
            'checkout.freeship_done' => 'Congratulations! You\'ve unlocked free delivery',
            'checkout.thumbs_your_bag' => 'Your bag',
            'checkout.thumbs_your_order' => 'Your order',
            'checkout.thumbs_youre_ordering' => 'You\'re ordering',
            'checkout.ssl_secure' => 'SSL secure',
            'checkout.arrives_in' => 'Arrives in :eta',
            // The fallback label on the discount row when the order carries no coupon
            // CODE. A code itself is an identifier and is never translated.
            'checkout.discount' => 'Discount',
            'checkout.payment_fee' => ':method fee',

            /*
             * THE PLACE-ORDER OVERLAY (Lane PLC).
             *
             * Every one of these is said to a shopper who has just pressed the
             * button that spends their money, so none of them may be cheerful
             * about a thing that has not happened. `placing_done` is the only
             * one that claims an order exists and it is shown only after the
             * server has said so.
             */
            'checkout.placing_label' => 'Placing your order',
            'checkout.placing_title' => 'Placing your order…',
            'checkout.placing_note' => 'Please keep this page open.',
            // :provider is the payment method's own title, read off the option
            // the shopper chose. A provider's name is not translated.
            'checkout.placing_leaving' => 'Taking you to :provider…',
            'checkout.placing_leaving_note' => 'Approve your payment there and you will come straight back.',
            'checkout.placing_done' => 'Order placed',
            'checkout.placing_failed' => 'We could not place your order. Please try again.',
            'checkout.placing_offline' => 'We could not reach the shop. Check your connection and try again.',
            'checkout.placing_expired' => 'This page has been open a long time. Please refresh it and try again — nothing has been charged.',
            // Said when the request was sent and no answer ever came back. It
            // must NOT invite a retry: the order may well have been placed, and
            // a second press is the one mistake that cannot be undone.
            'checkout.placing_no_answer' => 'We did not hear back from the shop. Your order may already have been placed — please check your email before trying again.',
            // The way out when a redirect gateway did not take the browser
            // anywhere. The provider's own address, offered as a link.
            'checkout.placing_redirect_stuck' => 'We could not open :provider automatically.',
            'checkout.placing_redirect_link' => 'Continue to :provider',
            // Lane PO hotfix: what a Place order press that cannot go ahead says,
            // beside the button and under the field -- never a native bubble.
            'checkout.place_check_field' => 'Please check: :field ↑',
            'checkout.place_card_unavailable' => 'Card payment could not load in this browser. Please choose another payment method.',
            'checkout.field_missing' => 'Please fill in :field.',
            'checkout.field_bad_email' => 'Please enter a valid email address.',
            'checkout.field_choose' => 'Please choose your :field.',
            'checkout.field_bad_value' => 'Please check this field.',
            // Lane PO: the details kept in this browser, and the way to forget them.
            'checkout.remember_me' => 'Remember my details on this device',
            'checkout.remember_clear' => 'Not you? Clear details',

            /*
             * THE RETURN FROM AN INSTALMENT PROVIDER WITHOUT A PAYMENT.
             *
             * Neither sentence guesses. Tabby and Tamara send a declined
             * shopper and a shopper who pressed Back to the same two addresses
             * and neither carries a reason, so "your payment was declined" to
             * somebody who simply changed their mind is an accusation. What is
             * certainly true is said instead, including that nothing has been
             * charged, which is the question they will actually have.
             */
            'checkout.return_not_completed' => 'Your payment was not completed, so your order has not been placed. Nothing has been charged.',
            'checkout.return_not_completed_at' => 'Your payment was not completed at :provider, so your order has not been placed. Nothing has been charged.',

            /*
             * PUTTING THE BASKET BACK. The button says what it does and does
             * not promise what it cannot: "Put my basket back" is a request,
             * and restore_done is the only sentence that claims anything
             * happened — it is shown after the write, not before it.
             */
            'checkout.restore_basket' => 'Put my basket back',
            'checkout.restore_done' => 'Your basket is back. The order that did not complete has been cancelled and nothing was charged.',
            'checkout.restore_gone' => 'There is nothing to put back. Your basket is as you left it.',

            /*
             * (Lane BK) THE BASKET COMES BACK BY ITSELF after a payment that did
             * not finish, and this is what the shopper reads above it. Calm, and
             * only what is true: nothing was charged, and the bag is theirs.
             * return_merged when they had started a new basket in between.
             */
            'checkout.return_restored' => 'Your payment wasn’t completed — nothing was charged. Your bag is just as you left it.',
            'checkout.return_merged' => 'Your payment wasn’t completed — nothing was charged. We’ve put those items back in your bag, alongside what you added since.',
            'checkout.return_try_again' => 'Try again',
        ];
    }

    /**
     * The order-received page and its summary.
     *
     * The order number, the currency and the payment method's own label are NOT
     * translated: the first two are identifiers and the third is the gateway's
     * name as the gateway gives it.
     *
     * @return array<string, string>
     */
    private static function storeOrderReceived(): array
    {
        return [
            'order_received.page_title' => 'Order received · K-Beauty Bliss',
            'order_received.header_badge' => 'Order received',
            'order_received.thank_you' => 'Thank you',
            // The order number arrives already wrapped in its <b>. It was
            // 'Your order <b>#' . $number . '</b> is confirmed. …' spread across one
            // line of template; as one string it can be reordered.
            'order_received.lead' => 'Your order :number is confirmed. A copy is on its way to :email.',
            'order_received.fact_order_number' => 'Order number',
            'order_received.fact_total_paid' => 'Total paid',
            'order_received.fact_total_to_pay' => 'Total to pay',
            // (Lane SW) A card payment Stripe told the browser had gone through,
            // before the shop has recorded it. Never "paid", never "to pay".
            'order_received.fact_total_confirming' => 'Confirming payment',
            'order_received.fact_payment' => 'Payment',
            'order_received.fact_delivery' => 'Delivery',
            'order_received.next_heading' => 'What next',
            'order_received.act_orders' => 'Your orders',
            'order_received.act_orders_note' => 'Every order on your account, including this one',
            'order_received.act_sign_in' => 'Sign in to your account',
            'order_received.act_sign_in_note' => 'Your account is ready — use :email',
            'order_received.act_track' => 'Track your order',
            'order_received.act_track_note' => 'Order #:number — we will ask for your email to confirm it is you',
            'order_received.act_home' => 'Go to home',
            'order_received.act_home_note' => 'Back to the shop front',
            'order_received.account_heading' => 'Finish your account',
            'order_received.account_username' => ':email is your username',
            'order_received.account_prompt' => 'Set a password to finish.',
            'order_received.account_password_label' => 'Set a password',
            'order_received.account_password_placeholder' => 'Password (8+ characters)',
            'order_received.account_save' => 'Save',
            'order_received.summary_heading' => 'Your order',
            'order_received.address_heading' => 'Delivering to',
            'order_received.address_unknown' => 'We will confirm your delivery address by email.',
            'order_received.your_note' => 'Your note',
            'order_received.gift_wrapped' => 'Gift wrapped 🎁',
            'order_received.gift_note' => '“:note” — printed on the gift card.',
            'order_received.gift_no_note' => 'Your order is wrapped as a gift.',
            'order_received.not_found_heading' => 'Order not found',
            'order_received.not_found_body' => 'We could not find that order. If you have just placed one, the confirmation email has a link that will open it.',
            'order_received.not_found_track' => 'Track an order with your email →',
            'order_received.show_more' => 'Show :count more item|Show :count more items',
            'order_received.show_fewer' => 'Show fewer items',

            /*
             * THE RETURN LEG OF THE PLACE-ORDER OVERLAY (Lane PLC).
             *
             * Shown over this page for a moment when a shopper arrives from a
             * payment. Which one they get is App\Services\Checkout\
             * PlacementState's answer for their own order, never the address
             * they arrived at.
             */
            'order_received.placed_label' => 'Order placed',
            'order_received.placed_title' => 'Order placed',
            'order_received.confirming_title' => 'Confirming your payment…',
            'order_received.confirming_note' => 'This usually takes a few seconds.',
            // After the one refresh, and the last word this overlay says on the
            // subject: the order details underneath are real and the shopper
            // should read them rather than watch a spinner.
            'order_received.confirming_slow' => 'Your payment is still being confirmed. Your order details are below, and we will email you as soon as it is through.',
        ];
    }

    /**
     * The brand directory and one brand's landing page.
     *
     * Brand NAMES are never translated: a brand is a proper noun and a
     * translated one is a different company.
     *
     * @return array<string, string>
     */
    private static function storeBrands(): array
    {
        return [
            'brands.all_heading' => 'All brands',
            // TWO counts in one sentence, and the framework can only choose a plural
            // form from one of them. The form is picked by the brand total, which is
            // the subject; :stocked is interpolated. A translator who needs the second
            // number to inflect differently has to write around it — which is a real
            // limit of trans_choice, recorded here rather than hidden.
            'brands.all_subtitle' => ':total brand, :stocked with products in the shop right now.|:total brands, :stocked with products in the shop right now.',
            'brands.none_yet' => 'No brands have been added yet.',
            'brands.shop_all' => 'Shop all :brand',
            'brands.brand_empty' => 'Nothing from this brand is in the shop right now.',
            'brands.popular_heading' => 'Popular right now',
            'brands.card_count' => ':count product|:count products',
            'brands.card_coming_soon' => 'Coming soon',
            // Lane BR4: the brand Panel's description, cut at two lines.
            'brands.read_more' => 'Read more',
            'brands.read_less' => 'Read less',
        ];
    }

    /**
     * The Journal index and one article. Both render their own chrome rather
     * than the store layout, which is why the navigation labels are here.
     *
     * @return array<string, string>
     */
    private static function storeJournal(): array
    {
        return [
            'journal.nav_shop' => 'Shop',
            'journal.nav_quiz' => 'Skin Quiz',
            'journal.nav_journal' => 'Journal',
            'journal.eyebrow' => 'The Glow Journal',
            'journal.heading' => 'Skincare tips & the K-beauty edit',
            'journal.subtitle' => 'Honest guides on routines, ingredients and sun care — written for the UAE.',
            'journal.read_more' => 'Read more →',
            'journal.empty' => 'No articles yet — check back soon.',
            'journal.footer_line' => '© K-Beauty Bliss · Authentic Korean beauty in the UAE',
            'journal.more_heading' => 'More from the Journal',
            // The &larr; stays in the template rather than in the string, because the
            // source spelled it as an HTML entity and a translation value put through
            // {{ }} would render it as the literal characters '&amp;larr;'. An arrow is
            // a glyph, not a word — the same reason the worked example leaves the
            // wishlist heart alone.
            'journal.back_to_index' => 'Back to the Journal',
        ];
    }

    /**
     * The shop-wide review wall at /reviews/.
     *
     * @return array<string, string>
     */
    private static function storeReviewWall(): array
    {
        return [
            'review_wall.eyebrow' => 'Customer reviews',
            'review_wall.heading' => 'What customers have said',
            'review_wall.subtitle' => 'Every review below was left by a customer of this shop and published after moderation.',
            'review_wall.stars_label' => ':count out of 5 stars|:count out of 5 stars',
            'review_wall.empty_heading' => 'No reviews yet',
            'review_wall.empty_body' => 'Nobody has reviewed this shop yet. When customers do, their reviews appear here exactly as they were written — we do not publish reviews we did not receive.',
            'review_wall.empty_cta' => 'Browse the shop',
            'review_wall.based_on' => 'Based on :formatted approved review|Based on :formatted approved reviews',
            'review_wall.showing' => 'Showing :count',
            'review_wall.showing_so_far' => 'Showing :count so far',
            'review_wall.no_match' => 'No reviews match this filter. :link.',
            'review_wall.no_match_link' => 'Show all reviews',
            'review_wall.anonymous' => 'Anonymous',
            // 'on <a…>Product</a>'. The preposition and the link were two pieces of
            // template; in Arabic the word order is not the same.
            'review_wall.on_product' => 'on :product',
            'review_wall.photo_alt' => 'Review photo',
            'review_wall.found_helpful' => '👍 :formatted found this helpful|👍 :formatted found this helpful',
            // One paragraph, three inserts. It used to be five lines of template with
            // the bold runs and the link written into the middle of the sentence.
            'review_wall.unbuilt_note' => ':lead reviews are left on the page of the product you bought — open it and use “Write a review” there. A shop-wide review form, review photos browsing and helpful-voting from this page are :not_built: nothing is wrong with your store and nothing is missing from it, these pieces simply do not exist here yet. :link.',
            'review_wall.unbuilt_lead' => 'Writing a review:',
            'review_wall.unbuilt_emphasis' => 'not built',
            'review_wall.unbuilt_link' => 'Find a product to review',
            'review_wall.too_few_count' => ':formatted approved review so far.|:formatted approved reviews so far.',
            'review_wall.too_few_body' => 'An average is not shown until there are :minimum — a handful of reviews is not a shop-wide score, and printing one would say more than we know. Each review below is shown in full.',
        ];
    }

    /**
     * The account area: sign in, register, password reset, the dashboard, the
     * order list and one order, the address book and order tracking.
     *
     * @return array<string, string>
     */
    private static function storeAccount(): array
    {
        return [
            'account.dashboard_title' => 'My account',
            'account.card_orders' => 'Orders',
            'account.card_orders_note' => 'Everything you have ordered',
            'account.card_wishlist' => 'Wishlist',
            'account.card_wishlist_note' => 'Saved for later',
            'account.card_addresses' => 'Addresses',
            'account.card_addresses_note' => 'Where we deliver',
            'account.card_track' => 'Track an order',
            'account.card_track_note' => 'Where your parcel is',
            'account.recent_orders' => 'Recent orders',
            'account.orders_empty' => 'Nothing here yet. :link.',
            'account.orders_empty_link' => 'Start shopping',
            'account.see_all_orders' => 'See all orders',
            'account.sign_in_title' => 'Sign in',
            'account.register_title' => 'Create account',
            'account.sign_in_heading' => 'Welcome back',
            'account.register_heading' => 'Create your account',
            'account.sign_in_lead' => 'Sign in to see your orders and wishlist.',
            'account.register_lead' => 'It takes about a minute.',
            'account.forgot_link' => 'Forgot password?',
            'account.new_here' => 'New here? :link',
            'account.new_here_link' => 'Create an account',
            'account.have_account' => 'Already have an account? :link',
            'account.password_hint' => 'Eight characters or more, with a mix of letters and numbers.',
            'account.terms_notice' => 'By continuing you agree to our :terms and :privacy.',
            'account.aside_heading' => 'Why an account',
            'account.aside_point_orders' => 'Your orders and their status in one place',
            'account.aside_point_checkout' => 'Checkout without retyping your address',
            'account.aside_point_wishlist' => 'Your wishlist kept across devices',
            'account.aside_point_restocks' => 'Early access to restocks',
            // A hard-coded threshold in a template, reported to the integrator rather
            // than changed here: it is a claim about shipping, not an interface
            // string, and it belongs with the other free-delivery figures that already
            // come from ShippingService.
            'account.aside_free_delivery' => 'Free delivery on orders over :amount.',
            'account.forgot_title' => 'Reset password',
            'account.forgot_heading' => 'Reset your password',
            'account.forgot_lead' => 'Enter your email and we will send you a link to set a new one.',
            'account.forgot_submit' => 'Send the link',
            'account.back_to_sign_in' => 'Back to sign in',
            'account.reset_title' => 'Set a new password',
            'account.reset_new_link' => 'Request a new link',
            'account.reset_lead' => 'Choose something you have not used here before. At least 8 characters.',
            'account.reset_legacy_note' => 'Note: your original K Beauty Bliss password will stop working once you save this.',
            'account.reset_field_password' => 'New password',
            'account.reset_field_confirm' => 'Confirm new password',
            'account.reset_submit' => 'Save new password',
            'account.reset_signed_out_note' => 'Signing in everywhere else will be ended, so you will need to sign in again on your other devices.',
            'account.show_password' => 'Show password',
            // The page an account invite links to (Store -> Customers -> Send
            // account invite, Lane PQ). The password fields reuse reset_field_*.
            'account.welcome_title' => 'Set your password',
            'account.welcome_lead' => 'Your :shop account is ready. Choose a password to finish setting it up. At least 8 characters.',
            'account.welcome_for' => 'Account: :email',
            'account.welcome_submit' => 'Save password and sign in',
            'account.welcome_invalid' => 'That link is no longer valid. Account links work once and expire after a few days. You can still set a password with a new link.',
        ];
    }

    /**
     * The order list, one order, and the order statuses.
     *
     * The ORDER NUMBER is never translated — it is an identifier, and
     * App\Support\HasTranslations refuses it on every model. What is
     * translated here is the word in front of it.
     *
     * @return array<string, string>
     */
    private static function storeOrders(): array
    {
        return [
            'orders.page_title' => 'Orders · K-Beauty Bliss',
            'orders.heading' => 'Orders',
            'orders.subtitle' => 'Everything you have ordered.',
            'orders.order_number' => 'Order #:number',
            'orders.detail_title' => 'Order #:number · K-Beauty Bliss',
            'orders.all_orders' => 'All orders',
            'orders.placed_on' => 'Placed :date',
            'orders.what_you_ordered' => 'What you ordered',
            'orders.delivery_and_payment' => 'Delivery & payment',
            'orders.pager_label' => 'Orders pages',
            'orders.pager_newer' => 'Newer',
            'orders.pager_older' => 'Older',
            'orders.pager_page' => 'Page :current of :total',
            // The wording is exactly what ucfirst(str_replace('-', ' ', $status))
            // produced, so the English page did not move. 'Onhold' is the
            // unhyphenated spelling this shop's data actually carries.
            'order_status.pending' => 'Pending',
            'order_status.processing' => 'Processing',
            'order_status.paid' => 'Paid',
            'order_status.onhold' => 'Onhold',
            'order_status.on-hold' => 'On hold',
            'order_status.completed' => 'Completed',
            'order_status.shipped' => 'Shipped',
            'order_status.cancelled' => 'Cancelled',
            'order_status.refunded' => 'Refunded',
            'order_status.failed' => 'Failed',
        ];
    }

    /**
     * The address book.
     *
     * @return array<string, string>
     */
    private static function storeAddresses(): array
    {
        return [
            'addresses.page_title' => 'Addresses',
            'addresses.heading' => 'Addresses',
            'addresses.subtitle' => 'Where we deliver. Your default is used first at checkout.',
            'addresses.empty' => 'No addresses saved yet. Add one below and it will be offered at checkout.',
            'addresses.badge_default' => 'Default',
            'addresses.edit' => 'Edit',
            'addresses.make_default' => 'Make default',
            'addresses.delete' => 'Delete',
            'addresses.delete_confirm' => 'Delete this address?',
            'addresses.form_heading_add' => 'Add an address',
            'addresses.form_heading_edit' => 'Edit address',
            'addresses.field_type' => 'Type',
            'addresses.field_first_name' => 'First name *',
            'addresses.field_last_name' => 'Last name',
            'addresses.field_company' => 'Company',
            'addresses.field_line1' => 'Address line 1 *',
            'addresses.field_line2' => 'Address line 2',
            'addresses.field_city' => 'City *',
            'addresses.field_state' => 'Emirate / State',
            'addresses.field_postcode' => 'Postcode',
            'addresses.field_country' => 'Country *',
            'addresses.field_phone' => 'Phone',
            'addresses.use_as_default' => 'Use as my default for this type',
            'addresses.save_changes' => 'Save changes',
            'addresses.add_address' => 'Add address',
            'addresses.cancel' => 'Cancel',
        ];
    }

    /**
     * Order tracking and email confirmation.
     *
     * @return array<string, string>
     */
    private static function storeTrack(): array
    {
        return [
            // Lane RL — "Complete your order", the page the reminder emails open.
            'order_pay.page_title' => 'Complete your order',
            'order_pay.heading' => 'Complete your order',
            'order_pay.lead' => 'Order :number is saved but not paid yet. Choose how to pay and you are done.',
            'order_pay.not_found' => 'This link has expired or is not valid. Find your order with its number and your email address, or message us and we will help.',
            'order_pay.find_order' => 'Find my order',
            'order_pay.your_items' => 'Your items',
            'order_pay.qty' => 'Qty :qty',
            'order_pay.total' => 'Total to pay',
            'order_pay.how_to_pay' => 'How would you like to pay?',
            'order_pay.pay_button' => 'Pay and complete my order',
            'order_pay.working' => 'Working…',
            'order_pay.no_methods' => 'No way to pay online is available for this order right now. Message us and we will help you finish it.',
            'order_pay.method_unavailable' => 'That way to pay is not available for this order. Please choose another.',
            'order_pay.cannot_reopen' => 'Some items in this order are no longer available, so it cannot be completed here. Message us and we will help.',
            'order_pay.start_failed' => 'We could not start that payment. Please try again or choose another way to pay.',
            'order_pay.needs_javascript' => 'Paying by card needs JavaScript switched on in your browser. Please turn it on, or choose another way to pay.',
            'order_pay.generic_error' => 'Something went wrong. Please try again.',
            'track.page_title' => 'Track my order · K-Beauty Bliss',
            'track.heading' => 'Track my order',
            'track.lead' => 'Your order number is in your confirmation email. We ask for the email as well, so only you can see where your parcel is.',
            'track.submit' => 'Find my order',
            // A count AND a link in one sentence. The count picks the plural form; the
            // link is a placeholder. It used to be four pieces of template either side
            // of a ternary that chose 'minute' or 'minutes'.
            'track.too_many_tries' => 'Too many tries. Wait :count minute and try again — or sign in to :link, where no number is needed.|Too many tries. Wait :count minutes and try again — or sign in to :link, where no number is needed.',
            'track.too_many_tries_link' => 'your orders',
            'track.not_found' => 'We couldn\'t find an order matching that number and email. Double-check both and try again.',
            'verify.notice_title' => 'Confirm your email',
            'verify.already_done' => 'Your address is confirmed. There is nothing to do here.',
            'verify.will_send' => 'We will send a link to :email. Open it and the address is confirmed.',
            'verify.back_to_account' => 'Back to your account',
            'verify.result_ok_title' => 'Email confirmed',
            'verify.result_bad_title' => 'Confirmation link',
            'verify.result_bad_heading' => 'That link did not work',
            'verify.go_to_account' => 'Go to your account',
            'track.row_placed' => 'Placed',
            'track.row_completed' => 'Completed',
            'track.signed_up' => 'Signed up with us? :link has the full receipt.',
            'track.signed_up_link' => 'Your orders',
        ];
    }

    /**
     * The two strings /skin-quiz renders on the SERVER.
     *
     * The quiz itself is a self-contained script inside that template — every
     * question, option, product and button is built by JavaScript from data
     * literals in the page, and the page's own banner says it is a front-end
     * preview. Those ~110 strings are NOT converted here and are reported as
     * such: they need the front-end dictionary this lane adds for the shipped
     * scripts plus the owner's decision about the invented catalogue they
     * quote, and translating invented copy is work thrown away.
     *
     * @return array<string, string>
     */
    private static function storeQuiz(): array
    {
        return [
            'quiz.preview_flag' => 'PREVIEW · front-end only',
            'quiz.brand_heading' => 'K-Beauty Bliss :suffix',
            'quiz.brand_suffix' => '· Skin Quiz',
        ];
    }

    /**
     * The double opt-in pages and the stock-alert / basket-reminder opt-out.
     *
     * @return array<string, string>
     */
    /**
     * Phase 10 — Build my routine (Lane FM). /routines and /routines/{concern}.
     *
     * TWO VOCABULARIES MEET HERE AND ONLY ONE OF THEM IS TRANSLATED.
     *
     * `concern_*` are the shopper-facing names of the eight concerns in
     * App\Support\RoutineConcerns, whose English is copied character for
     * character from the skin quiz's own CONCERNS array. The quiz's copies stay
     * English on purpose — the note above storeJs() and
     * StorefrontStringsAreKeyedTest's exclusion for skin-quiz.blade.php both say
     * why: recommend() COMPARES those strings and /api/quiz stores them as the
     * lead's answers, so an Arabic shopper must file a lead the owner can read
     * beside the others. These are different: they are a HEADING on a page, read
     * and never compared, and the slug is what anything machine-readable
     * carries. Translating one and not the other is correct, and the two are
     * pinned together by QuizAndRoutinesShareOneConcernListTest.
     *
     * `role_*` are the five steps. Their keys come from
     * App\Support\RoutineRoles::ORDER, so adding a sixth role is one line
     * there and two keys here.
     *
     * THE OFFER STRIP HAS NO NUMBER OF ITS OWN. Every figure in `offer_*` is a
     * placeholder filled from a real row in `coupons`. There is no string here
     * that states a saving, because this shop has no saving to state until
     * somebody creates the coupon — which is the whole lesson of the "15% bundle
     * saving" the previous lane deleted from the quiz.
     */
    /**
     * Shoppable video — the rail and its player (Phase 20, Lane V3).
     *
     * Short, because R3 is a design with almost no words in it: a play label, an
     * Add, a rating read aloud, and the player's own chrome. Everything else a
     * shopper reads on a tile is CONTENT — the creator's caption, the product's
     * name and its price — and none of that belongs in an interface string.
     *
     * `ugc.add` is 'Add' and NOT product_card.add_to_cart, which is 'Add to
     * cart'. R3's button is 9px inside a 158px card beside a struck price and a
     * discount pill; "Add to cart" does not fit it, and the existing key is the
     * right words for a card that has room. Two keys because they are two
     * strings, not because one was forgotten.
     *
     * `ugc.rating_aria` is the WHOLE bar's label. The bar draws a numeral and one
     * star glyph, and a star character read aloud is noise, so the glyph is
     * aria-hidden and this sentence carries both the score and the count —
     * including on the narrow tile where a container query hides the count
     * visually. Nothing is lost to a screen reader that a sighted shopper can see.
     *
     * @return array<string, string>
     */
    private static function storeUgc(): array
    {
        return [
            'ugc.play' => 'Play this video',
            'ugc.pause' => 'Pause',
            'ugc.add' => 'Add',
            'ugc.rating_aria' => 'Rated :rating out of 5 from :count reviews',
            'ugc.close' => 'Close',
            'ugc.products_heading' => 'In this video',
            'ugc.view_original' => 'See the original post',
            'ugc.like' => 'Like this video',
            'ugc.liked' => 'You liked this',
            'ugc.likes_label' => 'likes',
            'ugc.comments_label' => 'comments',
            'ugc.views_label' => 'views',
            'ugc.next' => 'Next video',
            'ugc.previous' => 'Previous video',
        ];
    }

    /**
     * Instagram Profile — the section (Phase 21, Lane IG).
     *
     * FIVE KEYS, and the shortness is the point: everything else on that section
     * is CONTENT. The caption is the caption Instagram has, the username is a
     * handle, and a handle is not translated any more than the wordmark is — so
     * neither goes through here. What is left is the four words this shop puts
     * around them and one aria-label.
     *
     * ── WHY `likes` AND `comments` ARE NOT HERE ─────────────────────────────
     *
     * `store.ugc.likes_label` and `store.ugc.comments_label` already exist and
     * already say exactly these two words, for the shoppable-video rail's own
     * screen-reader text. A second pair spelled `instagram.*` would be two keys
     * holding one string, which is two things for a translator to keep in step and
     * one of them to get wrong. The Instagram section calls the existing pair.
     *
     * ── AND `followers` / `posts` ARE SEPARATE KEYS, NOT ONE SENTENCE ────────
     *
     * The profile box draws them as "12,345 followers · 148 posts", and the
     * temptation is one key with two placeholders. It is refused because either
     * number may be ABSENT — Instagram omits a count it will not give us, and
     * docs/UGC-ENGAGEMENT.md's rule is that the element is then not drawn at all
     * rather than drawn with a zero in it. A single sentence cannot have half of
     * itself removed, so the shop would have to print "0 posts" to keep the
     * grammar, which is the exact lie that rule exists to prevent. Two keys, each
     * drawn only when there is a number for it.
     *
     * Pluralisation is Laravel's `|` through trans_choice(), and the number is
     * passed a SECOND time as `:formatted` so the printed figure is grouped
     * ("12,345") while the choice is made on the raw integer — the shape
     * `store.reviews.review_count_formatted` already established here.
     *
     * @return array<string, string>
     */
    private static function storeInstagram(): array
    {
        return [
            'instagram.followers' => ':formatted follower|:formatted followers',
            'instagram.posts' => ':formatted post|:formatted posts',
            'instagram.follow' => 'Follow',
            // The alt text on a tile whose caption is empty. A picture with no
            // caption still needs a name a screen reader can read.
            'instagram.post_alt' => 'Instagram post',
            // The tile's own label, used when the caption is empty so the link is
            // never announced as just a URL.
            'instagram.open_post' => 'Open this post on Instagram',
        ];
    }

    private static function storeRoutines(): array
    {
        return [
            'routines.breadcrumb' => 'Build my routine',
            'routines.heading' => 'Build my routine',
            'routines.lead' => 'Pick what you want to work on. Every step is something this shop has in stock, and you can swap any of them.',
            'routines.empty_heading' => 'No routines yet',
            'routines.empty_lead' => 'Nothing in the shop has been matched to a routine step yet. Browse the shop in the meantime — everything in it is real and in stock.',
            'routines.browse_shop' => 'Browse the shop',
            'routines.open' => 'See this routine',
            'routines.back' => 'All routines',
            'routines.steps_count' => ':count step|:count steps',
            'routines.step_number' => 'Step :number',
            'routines.title_for' => ':concern routine',
            'routines.blurb_for' => 'A Korean routine for :concern, in the order it goes on.',
            'routines.total_label' => 'Total for the :count step shown|Total for the :count steps shown',
            'routines.total_note' => 'The prices of the products chosen above. Nothing is added or taken off.',
            'routines.swap_open' => 'Swap this step',
            'routines.swap_none' => 'Nothing else in stock for this step.',
            'routines.reset' => 'Start this routine over',
            'routines.gap_heading' => 'Not stocked yet',
            'routines.gap_lead' => 'This shop has nothing matched to this step at the moment, so the step is shown empty rather than filled with a guess.',
            'routines.offer_percent' => 'Use code :code for :percent% off.',
            'routines.offer_amount' => 'Use code :code for :amount off.',
            'routines.offer_shipping' => 'Use code :code for free delivery.',
            'routines.offer_minimum' => 'On orders of :amount or more.',
            'routines.offer_expires' => 'Ends :date.',
            'routines.concern_hydration' => 'Hydration',
            'routines.concern_dark-spots' => 'Dark spots & tone',
            'routines.concern_acne' => 'Acne & blemishes',
            'routines.concern_ageing' => 'Fine lines & aging',
            'routines.concern_sensitivity' => 'Redness & sensitivity',
            'routines.concern_pores' => 'Pores & oil',
            'routines.concern_dullness' => 'Dullness & glow',
            'routines.concern_sun' => 'Sun protection',
            'routines.role_cleanse' => 'Cleanse',
            'routines.role_cleanse_help' => 'Lift off sunscreen, sweat and the day.',
            'routines.role_tone' => 'Tone',
            'routines.role_tone_help' => 'Rebalance and soften before anything active.',
            'routines.role_treat' => 'Treat',
            'routines.role_treat_help' => 'The active step for what you came in for.',
            'routines.role_moisturise' => 'Moisturise',
            'routines.role_moisturise_help' => 'Seal the water in so the actives are tolerated.',
            'routines.role_protect' => 'Protect',
            'routines.role_protect_help' => 'Sunscreen every morning — the UAE sun is the whole game.',
        ];
    }

    private static function storeNewsletter(): array
    {
        return [
            'newsletter.confirm_title' => 'Confirm your subscription',
            'newsletter.confirm_heading' => 'One more press',
            'newsletter.confirm_lead' => 'Confirm that you would like emails from us at this address.',
            'newsletter.confirm_button' => 'Yes, subscribe me',
            'newsletter.confirm_note' => 'If you did not ask for this, close this page. Nothing is added unless you press the button.',
            'newsletter.unsub_title' => 'Unsubscribe',
            'newsletter.unsub_lead' => 'Stop sending marketing emails to this address?',
            'newsletter.unsub_button' => 'Yes, unsubscribe me',
            'newsletter.done_subscribed_title' => 'You are subscribed',
            'newsletter.done_subscribed_heading' => 'You are on the list',
            'newsletter.done_subscribed_lead' => 'Thank you — that is confirmed. You will hear from us when there is something worth an email.',
            'newsletter.done_subscribed_note' => 'Every email we send carries an unsubscribe link, and it will always work.',
            'newsletter.done_unsub_title' => 'Unsubscribed',
            'newsletter.done_unsub_lead' => 'Done. This address has been taken off our marketing list and we will not add it back unless you ask us to.',
            'newsletter.done_unsub_note' => 'Order confirmations and delivery updates are not marketing and will still reach you.',
            'newsletter.bad_link_title' => 'That link did not work',
            'newsletter.bad_link_lead' => 'It may have expired, or it may have been copied incompletely — links wrap badly in some email programs.',
            'newsletter.bad_link_note' => 'Try opening it again straight from the email. If it still does not work, sign up once more from the homepage and we will send a fresh one.',
            'mail_prefs.title' => 'Email preferences',
            'mail_prefs.lead' => 'Stop sending stock alerts and basket reminders to this address?',
            'mail_prefs.button' => 'Yes, stop these emails',
            'mail_prefs.done_title' => 'Stopped',
            'mail_prefs.done_lead' => 'Done. We will not send stock alerts or basket reminders to this address again, and any that were already waiting have been cancelled.',
            'mail_prefs.done_note' => 'Order confirmations, delivery updates and receipts are not affected — they are how you find out what is happening to something you paid for.',
            'mail_prefs.bad_link_note' => 'Try opening it again straight from the email. If it still does not work, reply to any message from us and we will take the address off by hand.',
            'newsletter.unsub_note' => 'Order confirmations, delivery updates and receipts are not marketing and will still be sent. They are how you find out what is happening to something you paid for.',
            'mail_prefs.confirm_note' => 'This stops both back-in-stock alerts and basket reminders, for good, at this address. Order confirmations, delivery updates and receipts are not affected — they are how you find out what is happening to something you paid for.',
        ];
    }

    /**
     * The messages the shop's JAVASCRIPT builds — toasts, a toggle's two
     * labels, the placeholder dropped into a slot while a fetch is in flight.
     *
     * These are the only keys sent to the browser, and only on a page that is
     * not in the default language: App\Services\Translation\FrontEndStrings
     * collects the `js.` prefix and
     * resources/views/partials/js-strings.blade.php emits it. Everything else
     * on this list is rendered server-side by __() and has no business being in
     * a table the browser downloads.
     *
     * EVERY ONE IS ALSO WRITTEN AT ITS CALL SITE, in resources/js/kbb/*.js, as
     * the second argument to t(). That is not duplication for its own sake: the
     * bundle is built off-server and is routinely older than this file, so a key
     * added here reaches the shop only when somebody next runs the build — and
     * until they do, the shipped bundle keeps saying the English it was built
     * with rather than printing a key. The two must be kept in step, and
     * tests/Feature/StorefrontStringsAreKeyedTest.php checks that they are.
     *
     * PLURALS ARE THE ONE THING THIS CANNOT DO. trans_choice runs in PHP and
     * these strings are chosen in the browser, where the count is, so a form is
     * not selected — `js.fbt_added` carries one wording that has to read
     * correctly for any count. It is the only counted string on the front end,
     * and it is recorded here rather than papered over.
     *
     * @return array<string, string>
     */
    private static function storeJs(): array
    {
        return [
            // store/blog's tag filter. The chip a shopper reads; the value it
            // filters by is `data-tag` and is never this string — see that
            // page's script for what happened when the two were the same thing.
            'js.blog_tag_all' => 'All',
            'js.generic_error' => 'Something went wrong — please try again.',
            'js.no_connection' => 'No connection — please try again.',
            'js.session_expired' => 'Your session expired — please reload the page.',
            'js.added' => 'Added',
            'js.add_failed' => 'Could not add that just now — please try again.',
            'js.add_failed_short' => 'Could not add that.',
            'js.bag_failed' => 'Could not update your bag — please try again.',
            'js.coupon_failed' => 'Could not apply that code — please try again.',
            'js.coupon_rejected' => 'That code could not be applied.',
            'js.coupon_slow_down' => 'Too many tries — please wait a minute and try again.',
            'js.delivery_failed' => 'Could not update delivery for that country.',
            'js.delivery_retry' => 'Could not update delivery for that country — please try again.',
            'js.variant_sold_out' => 'That option is sold out.',
            'js.variant_sold_out_named' => ':name is sold out — please choose another option.',
            'js.fbt_pick_one' => 'Select at least one product.',
            // One wording for every count — see the note at the top of this section.
            'js.fbt_added' => ':count items added ✓',
            'js.fbt_failed' => 'Could not add those — please try again.',
            'js.wishlist_saved' => 'Saved to your wishlist',
            'js.wishlist_removed' => 'Removed from your wishlist',
            'js.wishlist_failed' => 'Could not update your wishlist.',
            'js.wishlist_retry' => 'Could not update your wishlist — please try again.',
            'js.subscribed' => 'You are on the list ✓',
            'js.subscribe_failed' => 'Could not sign you up — please try again.',
            'js.already_helpful' => 'You already marked this helpful.',
            'js.review_failed' => 'Could not submit — please check your connection and try again.',
            'js.close_search' => 'Close search',
            'js.read_less' => 'Read less ↑',
            'js.hide_password' => 'Hide password',
            'js.password_common' => 'Too common',
            'js.password_simple' => 'Too simple',
            'js.password_weak' => 'Weak',
            'js.password_fair' => 'Fair',
            'js.password_good' => 'Good',
            'js.password_strong' => 'Strong',
        ];
    }

    /**
     * The transactional mail.
     *
     * @return array<string, string>
     */
    private static function emailMessages(): array
    {
        return [
            'order_status.order_label' => 'Order',
            'order_status.view_order' => 'View your order',
            'greeting.hello' => 'Hello,',
            'greeting.hello_named' => 'Hello :name,',
            'common.paste_link' => 'Or paste this into your browser:',
            // The account invite (Lane PQ). The body is the owner's; these are
            // the button and the line saying why the message arrived.
            'customer_invite.button' => 'Set your password',
            'customer_invite.why' => 'You are receiving this because you placed an order with :shop using this email address. If that was not you, ignore this email: nothing happens unless the link is used.',
            'common.unsubscribe' => 'Unsubscribe',
            'layout.masthead_tagline' => 'Authentic K-Beauty, curated for you',
            'layout.support_heading' => 'We are here if you need us',
            'layout.support_body' => 'A real person answers. Ask us anything — a question about your order, or about what to use it with.',
            // The two address headings in the email small print (Lane RK, E1).
            'layout.address_dubai' => 'Dubai',
            'layout.address_korea' => 'Korea',
            'items.col_item' => 'Item',
            'items.col_qty' => 'Qty',
            'items.col_total' => 'Total',
            'items.sku' => 'SKU :sku',
            'items.each' => 'each',

            /*
             * THE MONEY BREAKDOWN'S ROW LABELS — Lane FB.
             *
             * ONE KEY SET, TWO CALLERS, AND THAT IS THE POINT. These labels are
             * built in Services\Mail\OrderEmailPresenter::totals() for the four
             * emails, and AGAIN, row for row, in Services\Invoices\InvoiceDocument::totals()
             * for the emailed invoice and the printed one. The two are separate
             * copies of the same decision — the customer has the receipt in
             * their inbox and the invoice beside it, and the two may not
             * disagree about what a line is called. So both call the SAME keys
             * rather than each getting its own: a translator who renames
             * "Delivery" renames it on both documents or on neither.
             * OrderPaperworkLabelsAreKeyedTest holds the two callers to this set.
             *
             * THE `email` GROUP RATHER THAN `invoice`, although an invoice uses
             * them, because the receipt is where they are decided and a key that
             * lives in two groups is two rows to translate.
             *
             * THE COUPON CODE, THE PAYMENT METHOD AND THE VAT RATE ARE
             * PLACEHOLDERS, never concatenation. A code is an identifier and is
             * never translated; a rate is a figure. Arabic puts a qualifier on
             * the other side of its noun, and ':method fee' can express that
             * where $method . ' fee' cannot.
             *
             * NO FIGURE'S WIDTH IS DECIDED HERE. Every amount beside these
             * labels is still rendered by the presenter at the receipt's own
             * precision — see OrderEmailPresenter::ledgerWidth().
             */
            'totals.subtotal' => 'Subtotal',
            'totals.discount' => 'Discount',
            'totals.discount_coupon' => 'Discount (:code)',
            // Lane RE: the "Buy these together" bundle, its own row above the coupon's.
            'totals.bundle' => 'Buy-together discount',
            'totals.delivery' => 'Delivery',
            'totals.gift_wrapping' => 'Gift wrapping',
            'totals.payment_fee' => ':method fee',
            'totals.vat' => 'VAT',
            'totals.vat_at_rate' => 'VAT at :rate%',
            'totals.total' => 'Total',

            /*
             * THE DISPATCH AND CANCELLATION EMAILS' HEADING AND BODY — the
             * wording in Mail\OrderStatusChanged::WORDING.
             *
             * THE CONSTANT STAYS, and it stays as the English source: handles()
             * asks it which statuses are worth an email at all, three test files
             * assert against it by name, and bodyFor() hands WORDING['shipped'][2]
             * back UNTOUCHED while the owner's timing box is blank — a property
             * DispatchEmailTimingTest pins by identity. content() looks the
             * display wording up by key instead, and
             * OrderPaperworkLabelsAreKeyedTest fails the day the English here and
             * the English there stop being the same sentence.
             *
             * THE SUBJECT IS NOT HERE. WORDING[0] carries numbered sprintf
             * placeholders that SettingsBlankAndSupportIdentityTest pins, and a
             * subject line is a separate decision from the body — it is named in
             * the report rather than swept in beside these.
             */
            /*
             * THE SUBJECT LINES — Lane FJ.
             *
             * The headings and bodies below were keyed and the subjects were
             * not, so an Arabic customer got an Arabic email under an English
             * subject line: the half of the message that shows in the inbox,
             * and the only half some of them read.
             *
             * TWO NAMED PLACEHOLDERS, NOT sprintf's NUMBERED ONES. The constant
             * in OrderStatusChanged::WORDING keeps `%1$s` / `%2$s` because it
             * is a PHP constant and cannot call __(); here they are `:store`
             * and `:number`, which is what every other string in this file uses
             * and what the Translation console shows the owner. Named is also
             * the property that matters most for a subject — a translator who
             * puts the order number first writes :number first and the sentence
             * is still correct, where a positional %s in the wrong slot
             * produces "Your KBB-10427 order Aisha Beauty Co is on its way":
             * grammatical, plausible, and wrong.
             *
             * A SUBJECT IS NOT A BODY. Both are short on purpose — an inbox
             * shows roughly the first sixty characters and the store's own name
             * is inside that budget — and both must keep both placeholders. A
             * subject that lost :number is a dispatch notice that does not say
             * which order, which is the whole content of the line.
             * OrderPaperworkLabelsAreKeyedTest pins the length and both
             * placeholders for every locale that has one published.
             */
            'order_status.shipped_subject' => 'Your :store order :number is on its way 🚚💨',
            'order_status.cancelled_subject' => 'Your :store order :number has been cancelled',
            'order_status.shipped_heading' => 'Your order is on its way 🚚💨',
            'order_status.shipped_body' => 'Your order has left us and is with the courier. Delivery in the UAE normally takes one to three working days from dispatch.',
            'order_status.cancelled_heading' => 'Your order has been cancelled',
            'order_status.cancelled_body' => 'This order has been cancelled and nothing further will be sent.',

            /*
             * LANE RL — THE REST OF THE STATUS EMAILS, THE TRACKING LINE AND THE
             * "COMPLETE YOUR ORDER" REMINDERS. Subjects/headings/bodies mirror
             * OrderStatusChanged::WORDING (OrderStatusEmailsTest holds the two
             * Englishes equal). Wording from Lane RJ's approved previews,
             * trimmed to what the shop's own rows can vouch for. Arabic falls
             * back to these until translated (Translation → Strings).
             */
            'order_status.processing_subject' => 'Your :store order :number is being prepared',
            'order_status.processing_heading' => 'We are preparing your order',
            'order_status.processing_body' => 'Your order is confirmed and we are getting it ready. We will email you again when it is on its way.',
            'order_status.onhold_subject' => 'Order on hold',
            'order_status.onhold_heading' => 'We have paused your order',
            'order_status.onhold_body' => 'Nothing is wrong with your items — we just need to confirm one detail before we can send it.',
            'order_status.onhold_reply' => 'Reply to this email or message us on WhatsApp and we will carry on right away.',
            'order_status.onhold_need' => 'What we need:',
            'order_status.completed_subject' => 'Delivered ✨',
            'order_status.completed_heading' => 'Enjoy your new routine ✨',
            'order_status.completed_body' => 'Your order is complete. Open it, try it, and enjoy the little extras we tucked in.',
            'order_status.refunded_subject' => 'Your :store order :number has been refunded',
            'order_status.refunded_heading' => 'Your order has been refunded',
            'order_status.refunded_body' => 'This order has been refunded. If the money went back to a card or payment account, your bank can take a few working days to show it.',
            'order_status.failed_subject' => 'Payment did not go through',
            'order_status.failed_heading' => 'The payment did not go through 😔',
            'order_status.failed_body' => 'Nothing was charged and the order is not confirmed. Your order is saved — try again or choose another way to pay.',
            // 2.60.376: the failed email names the method (App\Support\PaymentMethodWords).
            'order_status.failed_subject_method' => 'Your :method payment did not go through',
            'order_status.failed_heading_method' => 'Your :method payment did not go through 😔',
            'order_status.failed_body_card' => 'Your card payment was not approved or was not finished, so nothing was charged and the order is not confirmed. Your order is saved — try the card again, use another card, or choose another way to pay.',
            'order_status.failed_body_tabby' => 'Tabby did not approve the payment, or it was not finished, so nothing was charged and the order is not confirmed. Your order is saved — try Tabby again or choose another way to pay.',
            'order_status.failed_body_tamara' => 'Tamara did not approve the payment, or it was not finished, so nothing was charged and the order is not confirmed. Your order is saved — try Tamara again or choose another way to pay.',
            'payment_method.card' => 'card',
            'payment_method.tabby' => 'Tabby',
            'payment_method.tamara' => 'Tamara',
            'order_status.tracking_number' => 'Your tracking number is your order number: :number.',
            'order_status.tracking_where' => 'Follow it on our website — whatever we set (Shipped, Delivered) shows there straight away.',
            'order_status.track_button' => 'Track your order',
            'order_status.track_note' => 'Opens on any phone or computer — no sign-in needed. The page always shows the latest status.',
            'reminder.first_subject' => 'Complete your order 🛍️',
            'reminder.first_heading' => 'You are one step away 🛍️',
            'reminder.first_body' => 'We saved your order, but the payment was not completed, so it is not confirmed yet. Everything is below — finish in one tap.',
            'reminder.first_closing' => 'Already paid? Ignore this — your confirmation is on its way.',
            'reminder.second_subject' => 'Your order is still waiting ⏳',
            'reminder.second_heading' => 'Your order is still waiting for you ⏳',
            'reminder.second_body' => 'Order :number is saved but not paid, so we cannot send it yet. Popular items sell out quickly, so we cannot promise they will still be in stock later.',
            'reminder.second_closing' => 'This is the last reminder about this order.',
            'reminder.not_paid' => 'Not paid yet',
            'reminder.button' => 'Complete your order',
            // The owner, 3 October: "we need to include and focus on the fast
            // delivery, 100% original products from the brand and Free random
            // samples with order." Both reminders carry these three lines.
            'reminder.why_fast' => 'Fast delivery',
            'reminder.why_fast_note' => '1–3 days, all over the UAE',
            'reminder.why_original' => '100% original',
            'reminder.why_original_note' => 'straight from the brand',
            'reminder.why_samples' => 'Free samples',
            'reminder.why_samples_note' => 'random K-beauty samples in every order',
            // The feedback request, 3 hours after the Delivered email.
            'feedback.subject' => 'How is your glow? 💌',
            'feedback.subject_named' => 'How is your glow, :name? 💌',
            'feedback.heading' => 'How is your glow? 💌',
            'feedback.heading_named' => 'How is your glow, :name? 💌',
            'feedback.body' => 'By now your order has arrived. One tap per product tells us — and other shoppers in the UAE — what is worth it.',
            'feedback.items_heading' => 'Review what you bought',
            'feedback.button' => 'Write a review',
            'feedback.button_note' => 'Each button opens that product\'s page at its reviews.',
            'feedback.closing' => 'Something not right? Reply to this email or WhatsApp us — a real person will sort it out.',
            'reminder.button_note' => 'Opens your saved order on any device. Prefer another way to pay? Message us on WhatsApp and we will help.',

            /*
             * THE REST OF THE DISPATCH BODY. bodyFor() picks one of three by
             * where the parcel is going, and keying only the default would have
             * sent a Gulf customer an Arabic heading over an English paragraph —
             * worse than the English email they get today, not better.
             *
             * 'shipped_dispatched' is the half true of every destination and is
             * also what an order with no country recorded gets on its own.
             */
            'order_status.shipped_dispatched' => 'Your order has left us and is with the courier.',
            'order_status.shipped_abroad' => 'Your order has left us and is with the courier. Deliveries outside the UAE take longer than local ones and also wait on customs clearance in your country, so please allow a few extra days.',

            /*
             * AND THE CANCELLATION'S MONEY SENTENCE — the three cases set out
             * above OrderStatusChanged::CANCELLED_REFUND_NOTE.
             *
             * :amount is a placeholder rather than sprintf's %s so that the
             * figure can sit where Arabic puts it. The AMOUNT ITSELF IS NOT
             * TOUCHED: it arrives already rendered by OrderEmailPresenter::plain()
             * at the receipt's own precision, and this only decides the sentence
             * around it.
             *
             * `mail_cancelled_refund_note` is NOT keyed and must not be. It is
             * the owner's own typed sentence from Store → Mail, it ships blank,
             * and a second English source for a settings row is one place to
             * correct it and one place silently wrong — the exclusion
             * InterfaceStrings' header describes.
             */
            'order_status.cancelled_refunded' => 'A refund of :amount has been recorded against it.',
            'order_status.cancelled_nothing_taken' => 'Our records show no payment taken on this order, so there is nothing to refund.',
            'order_status.cancelled_unrefunded' => 'Our records show :amount paid on this order and no refund recorded against it yet.',
            'delivery.address_heading' => 'Delivery address',
            'delivery.method_heading' => 'Delivery method',
            'delivery.payment_heading' => 'Payment method',
            'delivery.gift_heading' => 'Gift message',
            'delivery.note_heading' => 'Order note',
            'delivery.not_recorded' => 'Not recorded',
            'confirmation.subject' => 'Your :store order :number 🎉',
            'confirmation.greeting' => 'Thank you! 🎉',
            'confirmation.greeting_named' => 'Thank you, :name! 🎉',
            // Lane RL: the approved preview's lead, for an order whose payment is in.
            // Cash on delivery has paid nothing yet and keeps the lead below.
            'confirmation.lead_paid' => 'Your payment is in and your order is confirmed. We are packing it with care — keep this email, it is your receipt.',
            'confirmation.lead' => 'Your order is in and we are packing it with care. Everything you chose is listed below, exactly as it was when you ordered — keep this email, it is your receipt.',
            'confirmation.placed_on' => 'placed :date',
            'confirmation.track_button' => 'Track your order',
            'confirmation.sign_in_link' => 'sign in to your account',
            'confirmation.device_note' => 'That link opens on the device you ordered from. Anywhere else, :link and your orders are all listed there under :number.',
            'order_status.device_note' => 'That link opens on the device you ordered from. Anywhere else, :link and look for :number.',
            'refunded.heading_sent' => 'Your refund is on its way',
            'refunded.heading_approved' => 'Your refund has been approved',
            'refunded.sent_body' => 'We have sent :amount back to the payment method you used for order :number.',
            // 2.60.376: the refund names where the money went.
            'refunded.sent_body_card' => 'We have sent :amount back to the card you paid with for order :number.',
            'refunded.sent_body_tabby' => 'We have sent :amount back to your Tabby account for order :number.',
            'refunded.sent_body_tamara' => 'We have sent :amount back to your Tamara account for order :number.',
            'refunded.approved_body' => 'We have approved a refund of :amount on order :number.',
            'refunded.partial_note' => 'This is a partial refund — the rest of the order is unaffected.',
            'refunded.statement_note' => 'Refunds usually appear on a card statement within five to ten working days, depending on your bank.',
            'refunded.manual_note' => 'You paid by :method, which we cannot refund automatically, so we will arrange the money with you directly. If you have not heard from us, reply to this message and we will sort it out.',
            'refunded.row_refunded' => 'Refunded',
            'refunded.row_order_total' => 'Order total',
            'refunded.row_paid_by' => 'Paid by',
            'refunded.chase_note' => 'If the money has not reached you in ten working days, reply to this message with order number :number and we will chase it.',
            'alert.heading' => 'A new order has come in.',
            'alert.items_heading' => 'Items to pick',
            'alert.next_step' => 'Open Orders in your store admin and search for :number to pick, pack and mark it dispatched.',
            'back_in_stock.why' => 'You asked to be told when this product came back in stock. This is that one message — we will not email you about it again unless you ask us to.',
            'back_in_stock.unsubscribe_prompt' => 'Never want email like this?',
            'cart_recovery.view_basket' => 'View your basket',
            'cart_recovery.why' => 'You asked us to remind you about this basket when you left your email address with us. If you have since placed your order, thank you — please ignore this.',
            'cart_recovery.unsubscribe_prompt' => 'Don\'t want these reminders?',
            'cart_recovery.unsubscribe_tail' => 'and we will stop, for good.',

            /*
             * THE SKIN QUIZ'S PLAN EMAIL — Lane FT.
             *
             * The quiz's contact step has always said "We'll save your results &
             * email your plan. No spam, ever." and the shop sent nothing. These
             * are the words that make the sentence true, and they are keyed
             * rather than written into App\Mail\QuizPlanEmail for the reason the
             * whole quiz is keyed: an Arabic shopper who answered an Arabic
             * quiz must not be sent an English plan.
             *
             * NOT ONE OF THEM STATES A PRODUCT, A PRICE OR A SAVING. The page
             * this email describes shows the SHAPE of a routine — Lane FB
             * deleted seventeen invented products, their prices and a 15%
             * bundle discount from it — and an email is the last place to put
             * any of that back, because it is kept, forwarded and quoted at the
             * shop later.
             *
             * `steps_note` is the load-bearing one: an ordered list in an inbox
             * reads like a basket somebody picked out, and this says plainly
             * that it is not one.
             *
             * `why` carries the "no spam, ever" promise in writing. It says one
             * message and no list, which is exactly what this flow does — see
             * QuizPlanEmail's header on why there is no unsubscribe link to go
             * with it.
             */
            'quiz_plan.subject' => 'Your :store skin quiz plan',
            'quiz_plan.greeting' => 'Here is your plan ✨',
            'quiz_plan.greeting_named' => 'Here is your plan, :name ✨',
            'quiz_plan.lead' => 'You filled in our skin quiz and asked us to email your routine. Here it is — the steps, in the order they go on.',
            'quiz_plan.skin_type' => 'Skin type',
            'quiz_plan.concerns' => 'Working on',
            'quiz_plan.steps_note' => 'These are steps, not products. Nothing has been chosen, reserved or charged — pick what suits you in the shop, where the prices are.',
            'quiz_plan.steps_note_text' => 'These are steps, not products. Nothing has been chosen, reserved or charged - pick what suits you in the shop, where the prices are.',
            'quiz_plan.shop_button' => 'Browse the shop',
            'quiz_plan.routine_button' => 'Build my routine',
            /*
             * The middle rung of the plan email's three-way button — Lane Q.
             * Used when the routine module is off but the shopper's concern has
             * a /concern/{slug}/ page. No concern name in it: the subject line
             * note above this class keeps a shopper's answer out of anything a
             * mail server logs, and a button label is the same kind of surface.
             */
            'quiz_plan.concern_button' => 'Shop for your concern',
            'quiz_plan.why' => 'You are getting this because this address was typed into the skin quiz on our website, and the form said we would email the plan. This is that one message — the address has not been added to any list.',
            'quiz_plan.why_text' => 'You are getting this because this address was typed into the skin quiz on our website, and the form said we would email the plan. This is that one message - the address has not been added to any list.',

            'newsletter.our' => 'our',
            'newsletter.somebody_asked' => 'Somebody — we hope it was you — asked for :store emails to be sent to this address.',
            'newsletter.not_yet' => ':emphasis Press the button below and you will be.',
            'newsletter.not_yet_emphasis' => 'You are not on the list yet.',
            'newsletter.link_expiry' => 'The link works for :count day.|The link works for :count days.',
            'newsletter.do_nothing' => 'If it was not you, do nothing. Without that press we will not add this address, and you will not hear from us again.',
            'newsletter.unsubscribe_prompt' => 'Never want email from us at this address?',
            'invoice.reference' => 'Invoice :reference',
            'invoice.order' => 'Order :number',
            'invoice.issued' => 'Issued :date',
            'invoice.ordered' => 'Ordered :date',
            'invoice.trn' => 'TRN :trn',
            'invoice.bill_to' => 'Bill to',
            'invoice.deliver_to' => 'Deliver to',
            'invoice.same_as_billing' => 'Same as the billing address',
            'invoice.col_unit' => 'Unit',
            'invoice.col_amount' => 'Amount',
            'invoice.paid_by' => 'Paid by :method',
            'invoice.paid_by_on' => 'Paid by :method on :date',
            'invoice.payment_method' => 'Payment method :method',
            'back_in_stock.why_text' => 'You asked to be told when this product came back in stock. This is that one message - we will not email you about it again unless you ask us to.',
            'cart_recovery.why_text' => 'You asked us to remind you about this basket when you left your email address with us. If you have since placed your order, thank you - please ignore this.',
            'newsletter.somebody_asked_text' => 'Somebody -- we hope it was you -- asked for :store emails to be sent to this address.',
            'newsletter.not_yet_text' => 'You are not on the list yet. Open the link below and you will be.',
            // The fallback sign-off on the two account emails, which are sent before
            // any order exists and do not go through EmailBranding's signature.
            'common.sign_off' => '— K Beauty Bliss',
            'verify.lead' => 'Please confirm this is your email address so we can send you order updates.',
            'verify.button' => 'Confirm my email',
            'verify.expiry' => 'The link expires in :count hour. Confirming does not change your password and does not sign you in.|The link expires in :count hours. Confirming does not change your password and does not sign you in.',
            'verify.not_you' => 'If you did not create an account with us, you can ignore this message.',
            'reset.lead' => 'Somebody asked to reset the password on your K Beauty Bliss account. If that was you, open the link below and choose a new one.',
            'reset.button' => 'Set a new password',
            'reset.expiry' => 'The link can be used once, and expires in :count minute.|The link can be used once, and expires in :count minutes.',
            'reset.retires_old' => 'Once you set a new password, your previous one — including the password you used on our old website — will stop working, and you will be signed out on any other device.',
            'reset.not_you' => 'If you did not ask for this, you can ignore this message. Your password has not changed and nobody has been given access to your account.',
            'layout.footer_customer' => 'You are receiving this because an order was placed with :store using this email address.',
            'layout.footer_merchant' => 'This is your store\'s new-order alert. It goes to the address set under Store → Mail, and you can switch it off under Store → Modules → Order emails.',
        ];
    }

    /**
     * The approved email design's own words (Lane EM): the kit's header,
     * help box, tracker, footer and each email's eyebrow, preheader and
     * "why you got this" line — docs/rj-email-previews/ at 9d6dea4, the owner's
     * "i want 100% same stuff as in previews". The sentences each email was
     * already sending (email.confirmation.*, email.order_status.*,
     * email.reminder.*, …) are reused, not duplicated here.
     *
     * @return array<string, string>
     */
    private static function emailKit(): array
    {
        return [
            'kit.topbar' => 'Authentic K-beauty, curated for you',
            'kit.nav_shop' => 'Shop',
            'kit.nav_track' => 'Track order',
            'kit.nav_account' => 'My account',
            'kit.footer_terms' => 'Terms & conditions',
            'kit.footer_privacy' => 'Privacy policy',
            'kit.footer_tagline' => 'Authentic Korean skincare, delivered across the UAE',
            'kit.preferences' => 'Email preferences',
            'kit.copyright' => '© :year :store',
            'kit.view_in_browser' => 'View this email in your browser',
            'kit.help_heading' => 'Questions? A real person answers.',
            'kit.help_body' => 'About your order, or about what to use it with — just ask.',
            'kit.chip_order' => 'Order',
            'kit.chip_placed' => 'Placed',
            'kit.chip_total' => 'Total',
            'kit.qty' => 'Qty :count',
            'kit.coupon_label' => 'Your code',
            'kit.stars' => ':count star|:count stars',
            'kit.step_placed' => 'Placed',
            'kit.step_confirmed' => 'Confirmed',
            'kit.step_shipped' => 'Shipped',
            'kit.step_delivered' => 'Delivered',
            'kit.step_cancelled' => 'Cancelled',
            'kit.step_not_paid' => 'Not paid',
            'kit.your_items' => 'Your items',
            'kit.free' => 'Free',
            'kit.total_to_pay' => 'Total to pay',
            'kit.paid_with' => 'Paid with :method',
            'kit.delivering_to' => 'Delivering to',
            'kit.payment' => 'Payment',
            'kit.ship_to' => 'Ship to',
            'kit.customer' => 'Customer',
            'kit.item_count' => ':count item|:count items',
            'kit.eyebrow_confirmed' => 'Order confirmed',
            'kit.eyebrow_processing' => 'Being prepared',
            'kit.eyebrow_onhold' => 'On hold',
            'kit.eyebrow_shipped' => 'Shipped',
            'kit.eyebrow_delivered' => 'Delivered',
            'kit.eyebrow_cancelled' => 'Cancelled',
            'kit.eyebrow_refunded' => 'Refunded',
            'kit.eyebrow_failed' => 'Payment unsuccessful',
            'kit.eyebrow_reminder_first' => 'Order not complete',
            'kit.eyebrow_reminder_second' => 'Last reminder',
            'kit.eyebrow_refund_sent' => 'Refund sent',
            'kit.eyebrow_alert' => 'New order',
            'kit.eyebrow_feedback' => 'Your opinion matters',
            'kit.eyebrow_stock' => 'Back in stock',
            'kit.eyebrow_basket' => 'Still thinking?',
            'kit.eyebrow_invite' => 'New website',
            'kit.eyebrow_newsletter' => 'One last step',
            'kit.eyebrow_quiz' => 'Your skin quiz plan',
            'kit.eyebrow_security' => 'Account security',
            'kit.eyebrow_verify' => 'Almost there',
            'kit.shop_again' => 'Shop again',
            'kit.reply_whatsapp' => 'Reply on WhatsApp',
            'kit.signoff_thanks' => 'Thank you for choosing us,',
            'kit.pre_confirmed' => 'Order :number is confirmed — thank you.',
            'kit.pre_status' => 'An update on your order :number.',
            'kit.pre_reminder_first' => 'Your order :number is saved — one step left to confirm it.',
            'kit.pre_reminder_second' => 'Last reminder: order :number is saved but not confirmed.',
            'kit.pre_feedback' => 'Tap a star for each product.',
            'kit.pre_refunded' => 'We have sent :amount back for order :number.',
            'kit.pre_alert' => 'New order :number — :total.',
            'kit.pre_stock' => ':product is back in stock.',
            'kit.pre_basket' => 'Your basket is saved — pick up where you left off.',
            'kit.pre_invite' => 'Set a password to see your orders and check out faster.',
            'kit.pre_newsletter' => 'One tap to confirm — then you are on the list.',
            'kit.pre_quiz' => 'Your skin routine, step by step, from the skin quiz.',
            'kit.pre_reset' => 'Use this link to choose a new password.',
            'kit.pre_verify' => 'Confirm this address so we can send your receipts here.',
            'kit.why_order' => 'You are receiving this because an order was placed at :site with this email address.',
            'kit.why_alert' => 'Sent to the store’s new-order address. Customers never see this message.',
            'kit.why_stock' => 'You asked to be told when this product came back in stock.',
            'kit.why_basket' => 'You left your email address on the basket page and asked for a reminder.',
            'kit.why_newsletter' => 'This address was typed into the newsletter form on :site.',
            'kit.why_quiz' => 'This address was typed into the skin quiz. It has not been added to any list.',
            'kit.why_reset' => 'Sent because a password reset was requested for this address.',
            'kit.why_verify' => 'Sent because this address was used to create an account at :site.',
            'kit.invoice_title' => 'Your invoice',
            'kit.invoice_lead' => 'Invoice :reference for order :number.',
            'kit.invoice_lead_noref' => 'The invoice for order :number.',
            'kit.stock_title' => 'It is back — and you asked first',
            'kit.stock_cta' => 'Shop it now',
            'kit.stock_once' => 'This is the one message you asked for — we will not email you about this product again.',
            'kit.basket_title' => 'Your basket is waiting for you',
            'kit.basket_button' => 'Return to my basket',
            'kit.invite_title' => 'Your account is ready',
            'kit.newsletter_title' => 'Confirm your subscription',
            'kit.quiz_title' => 'Your skin plan',
            'kit.reset_title' => 'Reset your password',
            'kit.verify_title' => 'Confirm your email address',
            'feedback.stars_note' => 'Each star opens that product’s page at its reviews.',
            'feedback.share_heading' => 'Share your routine 📸',
            'feedback.share_body' => 'post your shelfie and tag :handle.',
            'kit.howto_heading' => 'How to use them together:',
            'kit.howto_tail' => ', in this order.',
        ];
    }

    /**
     * The plain-text twin of every email.
     *
     * SOME OF THESE REPEAT THE HTML WORDING AND STILL HAVE THEIR OWN KEY,
     * because the two parts are not always the same sentence: the text parts
     * write '-' where the HTML writes an em dash, spell out 'Unsubscribe here:'
     * where the HTML has a link, and carry headings in capitals. A shared key
     * would force one of the two to change wording the day the other was
     * translated.
     *
     * The CAPITALS are applied by the template with mb_strtoupper(), not stored
     * here: Arabic has no letter case, so a stored upper-case string would be a
     * lie in one of the two languages. Same reason the line WRAPPING is done by
     * wordwrap() around the translated string rather than baked into it.
     *
     * @return array<string, string>
     */
    private static function emailText(): array
    {
        return [
            'text.order_line' => 'Order :number',
            'text.item_line' => 'QTY :quantity  ·  :unit each  ·  line total :line',
            'text.totals_heading' => 'Totals',
            'text.items_heading' => 'Items',
            'text.from_heading' => 'From',
            'text.delivery_method' => 'Delivery method: :method',
            'text.payment_method' => 'Payment method: :method',
            'text.thank_you' => 'Thank you',
            'text.thank_you_named' => 'Thank you, :name',
            'text.device_note_confirmation' => 'That link opens on the device you ordered from. Anywhere else, sign in to your account and your orders are all listed there under :number:',
            'text.device_note_status' => 'That link opens on the device you ordered from. Anywhere else, sign in to your account and look for :number:',
            'text.alert_heading' => 'A new order has come in',
            'text.customer' => 'Customer: :email',
            'text.refunded_row' => 'Refunded: :amount',
            'text.order_total_row' => 'Order total: :amount',
            'text.paid_by_row' => 'Paid by: :method',
            'text.your_basket' => 'Your basket:',
            'text.view_your_basket' => 'View your basket:',
            'text.unsubscribe_here_like_this' => 'Never want email like this? Unsubscribe here:',
            'text.unsubscribe_here_reminders' => 'Don\'t want these reminders? Unsubscribe here and we will stop, for good:',
            'text.unsubscribe_here_from_us' => 'Never want email from us at this address? Unsubscribe here:',
        ];
    }

    /**
     * The four printed documents: the invoice, the packing slip, the delivery
     * note and the dispatch label.
     *
     * The order number, the invoice reference, the TRN, the currency code and
     * every SKU are identifiers and are never translated. The document TYPE on
     * the invoice is the owner's own `invoice_doctype` setting and is not here
     * either.
     *
     * @return array<string, string>
     */
    private static function invoiceDocuments(): array
    {
        return [
            'document.print_hint' => 'Print, or choose “Save as PDF” in the print dialog.',
            'document.print_button' => 'Print',
            'doc.invoice' => 'Invoice',
            'doc.packing_slip' => 'Packing slip',
            'doc.delivery_note' => 'Delivery note',
            'doc.dispatch_label' => 'Dispatch label',

            /*
             * The bulk document — many orders on one printable page.
             *
             * OPERATOR CHROME, EVERY ONE OF THEM. The sheets inside a bulk
             * document are drawn by the same partials the single documents use
             * and carry the same keys; these are the tab name, the on-screen
             * sheet counter and the two refusal sentences, all read by the
             * person standing at the printer. That is why they are counted and
             * not concatenated: an Arabic operator gets "٣ من ١٢" from one
             * string rather than from three pieces glued together in English
             * word order.
             */
            'bulk.title.invoice' => 'Invoices',
            'bulk.title.packing-slip' => 'Packing slips',
            'bulk.title.delivery-note' => 'Delivery notes',
            'bulk.title.dispatch-label' => 'Dispatch labels',
            'bulk.subject' => ':count orders',
            'bulk.sheet_of' => 'Sheet :n of :total',
            'bulk.missing_headline' => ':count of the orders you picked could not be found',
            'bulk.missing_body' => 'Nothing is printed for them and the rest are below. Order ids: :ids. They were most likely deleted for good after this list was loaded.',
            'bulk.refused_title' => 'Nothing was printed',
            'bulk.refused_close' => 'Close this tab',
            'invoice.stamp_paid' => 'Paid',
            'invoice.label_payment' => 'Payment',
            'invoice.label_delivery' => 'Delivery',
            'invoice.label_phone' => 'Phone',
            'invoice.label_currency' => 'Currency',
            'invoice.col_unit_price' => 'Unit price',
            'packing.doctype' => 'Packing Slip',
            'packing.stamp_gift' => 'Gift',
            'packing.ordered_by' => 'Ordered by',
            'packing.label_items' => 'Items',
            'packing.label_status' => 'Status',
            // The COLUMN HEADING is translated; the SKU underneath it never is.
            'packing.col_sku' => 'SKU',
            'packing.col_picked' => 'Picked',
            'packing.gift_message' => 'Gift message — write this on the card',
            'packing.customer_note' => 'Note from the customer',
            'packing.footer' => 'No prices are shown on this sheet. It is safe to put in the parcel, including for a gift.',
            'delivery_note.doctype' => 'Delivery Note',
            'delivery_note.delivered_to' => 'Delivered to',
            'delivery_note.col_quantity' => 'Quantity',
            'delivery_note.received_by' => 'Received by',
            'delivery_note.print_name' => 'Print name',
            'delivery_note.signature' => 'Signature',
            'delivery_note.on_delivery' => 'On delivery',
            'delivery_note.date' => 'Date',
            'delivery_note.date_format' => 'Day / month / year',
            'delivery_note.footer' => 'This is a delivery note and not a receipt: no prices are shown on it, and no payment is requested by it. The invoice for this order is issued separately.',
            'label.tel' => 'Tel :phone',
            'label.strip_order' => 'Order',
            'label.strip_items' => 'Items',
            'label.strip_service' => 'Service',
            'label.cod_collect' => 'Cash on delivery — collect',
            'document.barcode_label' => 'Barcode of :value',
            'label.from' => 'From: :name',
        ];
    }

    /**
     * Marketing Emails — the campaign unsubscribe page (Lane MK). The page at
     * /email/u/{token}; the bad-link words are the newsletter's own.
     *
     * @return array<string, string>
     */
    /**
     * The floating WhatsApp button (Lane WA), on every storefront page.
     *
     * The first four are the STANDARD wording behind the owner's boxes on
     * Appearance -> WhatsApp button: his English box is printed as typed on the
     * English shop, and on the Arabic shop an empty Arabic box falls back to
     * these keys -- so this is where the Arabic is reviewed and approved. The
     * last two have no box at all: the link's accessible name (the button is
     * an icon) and the bubble's close control.
     *
     * @return array<string, string>
     */
    private static function storeWhatsApp(): array
    {
        return [
            'whatsapp.welcome' => 'Hi there 👋 Welcome to K-Beauty Bliss',
            'whatsapp.support' => 'Need help choosing? Our beauty team is on WhatsApp, 24/7.',
            'whatsapp.capsule_title' => 'Chat with us',
            'whatsapp.capsule_note' => 'Available 24/7',
            'whatsapp.open_label' => 'Chat with us on WhatsApp',
            'whatsapp.close_label' => 'Close',
            // Lane WS: the side tab on the phone cart and checkout.
            'whatsapp.tab_label' => '24/7 Support',
        ];
    }

    private static function storeMarketingUnsubscribe(): array
    {
        return [
            'mkt_unsub.title' => 'Unsubscribe',
            'mkt_unsub.lead' => 'Stop sending our offers and news to this address?',
            'mkt_unsub.button' => 'Yes, unsubscribe me',
            'mkt_unsub.keeps' => 'Order confirmations, delivery updates and receipts still arrive — they are about something you bought, not marketing.',
            'mkt_unsub.done_title' => 'You are unsubscribed',
            'mkt_unsub.done_lead' => 'Done. We will not send offers or news to this address again.',
            'mkt_unsub.bad_note' => 'Try opening it again straight from the email. If it still does not work, reply to any message from us and we will take the address off by hand.',
        ];
    }

    /**
     * The refusal a blocked address or a bot sees (Lane CT) — the standalone
     * page errors/kbb-blocked and the JSON the cart drawer shows. Never names
     * the visitor's address; :code is a block reference the owner can look up.
     *
     * @return array<string, string>
     */
    /**
     * Lane NT — the installed shop app's own "Allow notifications" sheet
     * (resources/site-app/site-app.js), fetched from GET /api/site-app/push
     * only when the app runs from the Home Screen. Says nothing about what is
     * sent: that is still the owner's decision.
     *
     * @return array<string, string>
     */
    private static function storeSiteAppPush(): array
    {
        return [
            'site_app.push_title' => 'Turn on notifications?',
            'site_app.push_body' => 'Allow notifications to hear from K-Beauty Bliss on this phone. You can turn them off any time in your phone\'s settings.',
            'site_app.push_allow' => 'Allow notifications',
            'site_app.push_later' => 'Not now',
        ];
    }

    /**
     * Lane PN — what the shop app's automatic notifications say (Growth &
     * Marketing → Push Notifications → Automations), rendered in each phone's
     * language when queued. :order is the shopper's own order number, the only
     * personal detail a push carries; :product a product name; :price a price.
     * Text only: the worker shows it with showNotification(), never as markup.
     *
     * @return array<string, string>
     */
    private static function storePushMessages(): array
    {
        return [
            'push.order_processing_title' => 'Order :order is being prepared',
            'push.order_processing_body' => 'We are getting your K-Beauty Bliss order ready.',
            'push.order_onhold_title' => 'Order :order is on hold',
            'push.order_onhold_body' => 'Tap to see what we need to carry on.',
            'push.order_shipped_title' => 'Order :order is on its way',
            'push.order_shipped_body' => 'It is with the courier now. Tap to follow it.',
            'push.order_completed_title' => 'Order :order has been delivered',
            'push.order_completed_body' => 'We hope you love it. Tap to see your order.',
            'push.order_cancelled_title' => 'Order :order has been cancelled',
            'push.order_cancelled_body' => 'Tap to see the details.',
            'push.order_refunded_title' => 'Order :order has been refunded',
            'push.order_refunded_body' => 'Your refund is on its way back to you.',
            'push.order_failed_title' => 'Payment for order :order did not go through',
            'push.order_failed_body' => 'Tap to try again.',
            'push.stock_title' => 'Back in stock',
            'push.stock_body' => ':product is back. Get it before it goes again.',
            'push.cart_title' => 'You left something in your bag',
            'push.cart_body' => 'Your picks are waiting. Tap to finish your order.',
            'push.price_title' => 'Price drop',
            'push.price_body' => ':product is now :price.',
        ];
    }

    private static function storeBlocked(): array
    {
        return [
            'blocked.title' => 'We can\'t take orders from this connection',
            'blocked.lead' => 'Orders, the cart and forms are switched off for the network you are using right now.',
            'blocked.bot_title' => 'This looks like an automated request',
            'blocked.bot_lead' => 'The cart and checkout only answer a web browser. Please open the shop in Chrome, Safari, Firefox or Edge.',
            'blocked.contact' => 'If you are a customer and this is a mistake, contact us and quote the reference below — we will sort it out.',
            'blocked.reference' => 'Reference: :code',
            'blocked.home' => 'Back to the shop',
            'blocked.json' => 'Orders from this connection are switched off. If this is a mistake, please contact us.',
            'blocked.bot_json' => 'The cart only answers a web browser. Please use Chrome, Safari, Firefox or Edge.',
        ];
    }

    /**
     * Marketing Emails — the words every campaign carries that are not the
     * owner's own (Lane MK): the footer's why-line for each list, the text
     * part's unsubscribe line, and a coupon's end date. Everything else in a
     * campaign is the owner's, typed in the builder.
     *
     * @return array<string, string>
     */
    private static function emailMarketing(): array
    {
        return [
            'mkt.why_customers' => 'You are receiving this because you bought from :store before.',
            'mkt.why_subscribers' => 'You are receiving this because you subscribed to :store emails.',
            'mkt.why_account' => 'You are receiving this because you have an account with :store.',
            'mkt.text_unsubscribe' => 'To stop these emails, open:',
            'mkt.coupon_ends' => 'Ends :date',
        ];
    }

    /**
     * One Laravel translation group's English strings.
     *
     * @return array<string, string>
     */
    public static function group(string $group): array
    {
        return self::all()[$group] ?? [];
    }

    /**
     * Every key, fully qualified, with its English text.
     *
     * This is what the admin editing screen lists and what the character-count
     * estimate measures: the complete, finite set of interface strings that
     * exist to be translated.
     *
     * @return array<string, string> 'store.wishlist.title' => 'Wishlist'
     */
    public static function flat(): array
    {
        $out = [];

        foreach (self::all() as $group => $strings) {
            foreach ($strings as $key => $english) {
                $out[$group . '.' . $key] = $english;
            }
        }

        return $out;
    }

    /** The English source for one fully-qualified key, or null. */
    public static function english(string $key): ?string
    {
        return self::flat()[TranslationStore::normaliseKey($key)] ?? null;
    }
}
