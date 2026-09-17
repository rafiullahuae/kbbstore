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
                self::storeNewsletter(),
                self::storeJs(),
            ),
            'email' => array_merge(
                self::emailMessages(),
                self::emailText(),
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
            'shop.clear_all' => 'Clear all',
            'shop.empty_heading' => 'No products match those filters',
            'shop.empty_body' => 'Try removing a filter or clearing all.',
            'collection.page_title' => ':title · K-Beauty Bliss',
            'collection.product_count' => ':formatted product|:formatted products',
            'collection.all_products' => 'All products',
            'collection.empty' => 'Nothing here just yet. :link.',
            'collection.empty_link' => 'Browse the full range',
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
            // '1.2k' — the abbreviation is a word, and it is not 'k' in Arabic.
            'product_card.count_thousands' => ':countk',
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
            'home.slider_previous' => 'Previous',
            'home.slider_next' => 'Next',
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
            'product.read_more' => 'Read more ↓',
            'product.related_eyebrow' => 'Complete your routine',
            'product.related_heading' => 'You may also like',
            'product.save_to_wishlist' => 'Save to wishlist',
            // The DEFAULT of the `fbt_title` setting.
            'fbt.title' => 'Complete your routine',
            'fbt.total' => 'Total: :amount',
            'fbt.add_selected' => 'Add selected to cart',
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
            'checkout.field_city' => 'City / area',
            'checkout.field_city_placeholder' => 'e.g. Al Reem Island',
            'checkout.field_country' => 'Country',
            'checkout.country_detected' => 'Detected',
            'checkout.field_notes' => 'Delivery notes',
            'checkout.field_notes_placeholder' => 'Delivery instructions, a landmark, a preferred time',
            'checkout.optional_note' => '(optional)',
            'checkout.create_account' => 'Create an account for faster checkout next time',
            'checkout.password_placeholder' => 'Choose a password (8 characters or more)',
            'checkout.whatsapp_optin' => 'Send order updates on WhatsApp — confirmation, dispatch & delivery alerts.',
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
            'account.aside_free_delivery' => 'Free delivery on orders over د.إ150.',
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
            'js.generic_error' => 'Something went wrong — please try again.',
            'js.no_connection' => 'No connection — please try again.',
            'js.session_expired' => 'Your session expired — please reload the page.',
            'js.added' => 'Added',
            'js.add_failed' => 'Could not add that just now — please try again.',
            'js.add_failed_short' => 'Could not add that.',
            'js.bag_failed' => 'Could not update your bag — please try again.',
            'js.coupon_failed' => 'Could not apply that code — please try again.',
            'js.coupon_rejected' => 'That code could not be applied.',
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
            'common.unsubscribe' => 'Unsubscribe',
            'layout.masthead_tagline' => 'Authentic K-Beauty, curated for you',
            'layout.support_heading' => 'We are here if you need us',
            'layout.support_body' => 'A real person answers. Ask us anything — a question about your order, or about what to use it with.',
            'items.col_item' => 'Item',
            'items.col_qty' => 'Qty',
            'items.col_total' => 'Total',
            'items.sku' => 'SKU :sku',
            'items.each' => 'each',
            'delivery.address_heading' => 'Delivery address',
            'delivery.method_heading' => 'Delivery method',
            'delivery.payment_heading' => 'Payment method',
            'delivery.gift_heading' => 'Gift message',
            'delivery.note_heading' => 'Order note',
            'delivery.not_recorded' => 'Not recorded',
            'confirmation.greeting' => 'Thank you ✨',
            'confirmation.greeting_named' => 'Thank you, :name ✨',
            'confirmation.lead' => 'Your order is in and we are packing it with care. Everything you chose is listed below, exactly as it was when you ordered — keep this email, it is your receipt.',
            'confirmation.placed_on' => 'placed :date',
            'confirmation.track_button' => 'Track your order',
            'confirmation.sign_in_link' => 'sign in to your account',
            'confirmation.device_note' => 'That link opens on the device you ordered from. Anywhere else, :link and your orders are all listed there under :number.',
            'order_status.device_note' => 'That link opens on the device you ordered from. Anywhere else, :link and look for :number.',
            'refunded.heading_sent' => 'Your refund is on its way',
            'refunded.heading_approved' => 'Your refund has been approved',
            'refunded.sent_body' => 'We have sent :amount back to the payment method you used for order :number.',
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
