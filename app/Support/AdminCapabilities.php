<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * The admin permissions map. One file, two tables, no database.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS REPLACES
 * ---------------------------------------------------------------------------
 *
 * `admin_users.role` used to be decorative. AdminController validated it
 * against a four-value vocabulary, the Users screen rendered it, a last-owner
 * guard stopped the final owner being demoted — and nothing else in the
 * application ever read it. `auth:admin` was the entire authorization model, so
 * every back-office account was an owner in all but the label. A `support`
 * account could PUT its own row to role=owner, open the payment gateway
 * credentials and download the whole customer list. tests/Feature/
 * AdminRoleEnforcementTest.php pinned each of those reaches as they stood.
 *
 * ---------------------------------------------------------------------------
 * WHY A MAP AND NOT AN RBAC SYSTEM
 * ---------------------------------------------------------------------------
 *
 * There are four roles, they are a fixed vocabulary in AdminController's own
 * validator, and nothing in the product asks for per-account permission
 * editing. Tables, a pivot and a policy class per model would all have to be
 * migrated onto the live shop through the updater alone, and would still answer
 * the same question this array answers. When roles genuinely need to be
 * editable, this file is the thing that grows a database backing — until then it is the whole
 * feature, readable in one screen, and diffable in a package.
 *
 * ▲ THAT DAY CAME (Lane RL, plan row 53). Roles are now editable from Platform
 * → Users & Roles, and App\Support\AdminRoles is the database backing. This
 * file did not change shape for it: CAPABILITIES is still the list of every
 * capability and the CODE DEFAULT of the four original roles (which are now
 * the presets Full Admin, Store Manager, Customer Support and Content Editor,
 * and follow this map for as long as nobody edits them), RULES is still the
 * whole route map, and `owner` still short-circuits. What an account actually
 * holds is AdminRoles::can(), which every check in the console now asks.
 *
 * ---------------------------------------------------------------------------
 * HOW IT FAILS
 * ---------------------------------------------------------------------------
 *
 * CLOSED. `for()` returns null for any route it does not recognise and
 * EnforceAdminCapability turns a null into a 403 for everyone who is not an
 * owner. A route added tomorrow in a lane that never read this file is
 * owner-only until somebody maps it — the opposite of CouponService::
 * withRules(), which failed open and shipped that way.
 *
 * ---------------------------------------------------------------------------
 * HOW THE OWNER STAYS IN
 * ---------------------------------------------------------------------------
 *
 * This is the constraint that outranks every other one here: the host has no
 * shell, so an admin nobody can reach cannot be repaired. Four things stand
 * between this file and that outcome.
 *
 *   1. `owner` short-circuits. EnforceAdminCapability returns before it ever
 *      consults this map when the signed-in role is owner, so a typo in RULES
 *      can lock out a manager, never an owner.
 *   2. `admin_users.role` is NOT NULL DEFAULT 'owner' (0001_01_01_000003), so
 *      every account that predates this change — including every account on the
 *      live site right now — is an owner and loses nothing on the day the
 *      package applies.
 *   3. AdminController's last-owner guard refuses to demote or delete the final
 *      owner, so "at least one owner exists" is an invariant of the table, not
 *      a hope.
 *   4. Login and logout are registered OUTSIDE the auth:admin group, so nothing
 *      in here can make the login form or the logout button unreachable.
 */
final class AdminCapabilities
{
    /** The vocabulary AdminController validates against. */
    public const ROLES = ['owner', 'manager', 'support', 'editor'];

    /**
     * Legacy spellings.
     *
     * 0001_01_01_000003's own comment says the column is "owner|manager|staff",
     * while AdminController has validated "owner|manager|support|editor" for as
     * long as it has existed. Nothing ever reconciled the two, so a row reading
     * 'staff' is possible on a long-lived install. It is mapped rather than
     * denied because an unmapped role reaches nothing at all, and silently
     * locking a real staff account out of the console is a support call nobody
     * would connect back to this package.
     *
     * Anything NOT in ROLES and not listed here gets no capability whatsoever.
     * That is deliberate: an unrecognised role is a closed door, not an open one.
     */
    public const ROLE_ALIASES = ['staff' => 'support'];

    /**
     * capability => the roles that hold it.
     *
     * `owner` is listed in every row as documentation. The code does not rely
     * on it — see the short-circuit in EnforceAdminCapability — but a reader
     * scanning this column should be able to answer "who can do this" without
     * knowing that.
     *
     * The shape of the four roles as mapped here:
     *
     *   owner    everything, always.
     *   manager  runs the shop: orders, money, customers, catalogue, content,
     *            marketing, shipping. Not site configuration, not gateway
     *            credentials, not accounts, not updates.
     *   support  answers customers: reads orders and customers, adds notes,
     *            moves an order's status, moderates reviews. Touches no money,
     *            deletes nothing, exports nothing.
     *   editor   works on the storefront: catalogue and content and the review
     *            furniture. Sees no customer, no order and no money.
     */
    public const CAPABILITIES = [
        // The console shell itself. Every role has it, or the role cannot log
        // in to anywhere at all.
        'admin.access' => ['owner', 'manager', 'support', 'editor'],
        'dashboard.view' => ['owner', 'manager', 'support', 'editor'],

        // Revenue, AOV and the daily series. Commercially sensitive in a way
        // the order count on the dashboard is not.
        'analytics.view' => ['owner', 'manager'],

        // Analytics (Lane AN): its two settings -- tracking on/off and the
        // owner's own addresses to leave out. Reading the dashboard is
        // analytics.view above.
        'analytics.manage' => ['owner', 'manager'],

        // Growth & Marketing -> Search Terms: what shoppers typed, ranked. Its
        // own capability so it can be granted apart from revenue figures.
        'search_terms.view' => ['owner', 'manager'],

        // Growth & Marketing -> Cart Tracking (Lane CT). Every cart with the
        // shopper's IP address, country and browser, and the shop's block
        // list. `view` reads (and exports) it; `block` changes anything:
        // block / unblock, bulk actions, deleting carts, the settings —
        // including "Ask bots to leave" and what a blocked address may do.
        // Owner and manager, the owner's own call: "we received a lot of fake
        // COD orders, so we need to block those users".
        'carttracking.view' => ['owner', 'manager'],
        'carttracking.block' => ['owner', 'manager'],
        // Store -> SEO Keywords (Lane KW). Reading the keyword bank and each
        // page's keywords is a manager's job as much as the owner's; changing
        // what the storefront publishes -- a sync, an undo, a hand edit, the
        // Search Console key -- is the owner's alone.
        'seo_keywords.view' => ['owner', 'manager'],
        'seo_keywords.sync' => ['owner'],
        // Store -> SEO Keywords -> Brand (Lane BR). Seeing where the old name
        // "Extra Beauty" is still stored is a manager's job too; replacing it
        // across the shop's stored text, and the brand switches, are the owner's.
        'seo_brand.view' => ['owner', 'manager'],
        'seo_brand.manage' => ['owner'],

        // Orders. Split four ways because reading an order, editing one,
        // moving money and destroying one are genuinely different acts.
        'orders.view' => ['owner', 'manager', 'support'],
        'orders.manage' => ['owner', 'manager', 'support'],
        'orders.money' => ['owner', 'manager'],
        'orders.delete' => ['owner', 'manager'],
        'orders.export' => ['owner', 'manager'],
        /*
         * (Lane PU) Store -> Orders -> (an order). Three, because they are
         * three different acts and the owner asked for each separately:
         *
         *   orders.edit      Billing / Shipping -> Edit: correcting the
         *                    address, email and phone on an order. Support
         *                    keeps it -- fixing the address a customer phoned
         *                    in IS answering customers, and support could
         *                    already reach this endpoint under orders.manage.
         *   orders.customer  pointing the order at a different customer
         *                    account. Not support: it puts the order, with its
         *                    addresses, in another person's My Account, and its
         *                    picker searches the whole customer list.
         *   orders.payment   "Mark as paid": writing that money arrived.
         *                    Owner and manager only, the same people who hold
         *                    orders.money -- support "touches no money".
         */
        'orders.edit' => ['owner', 'manager', 'support'],
        'orders.customer' => ['owner', 'manager'],
        'orders.payment' => ['owner', 'manager'],
        /*
         * Safety -> Demo Content -> Sample order. OWNER ALONE, and a line of
         * its own rather than a reuse of `data.import` beside the rest of Demo
         * Content, because this is the only endpoint in the back office that
         * WRITES A ROW INTO `orders` without a customer having placed one. That
         * table is where this shop's money is counted from, and the row it
         * writes is deliberately eligible for revenue -- kept out of it by one
         * row in `demo_seed_log`. Widening `data.import` one day, to let a
         * support account run a catalogue import say, must not hand out the
         * ability to write into the orders table as a side effect nobody was
         * looking at.
         */
        'orders.sample' => ['owner'],
        'invoices.view' => ['owner', 'manager', 'support'],

        // Customers. An invoice carries one person's address; the export
        // carries everybody's, which is why it is a capability of its own.
        'customers.view' => ['owner', 'manager', 'support'],
        'customers.manage' => ['owner', 'manager'],
        'customers.export' => ['owner', 'manager'],
        // Store -> Customers -> Send account invite (Lane PQ). Emails every
        // guest the shop has in the shop's own name, so it is not something a
        // support account can do just because it can read the list.
        'customers.invite' => ['owner', 'manager'],

        // Catalogue and storefront content.
        'catalog.view' => ['owner', 'manager', 'editor'],
        'catalog.manage' => ['owner', 'manager', 'editor'],
        'catalog.export' => ['owner', 'manager', 'editor'],

        /*
         * Catalog -> Sets (Lane SET, routes/sets-admin.php). A Set is a
         * `products` row with type='set' plus the product_set_items pivot: the
         * screen creates a product, prices it, categorises it and publishes it.
         *
         * TWO, AND NEITHER IS catalog.manage, although all three hold the same
         * three roles as this ships. That is the whole point of per-capability
         * gating: granting somebody the Sets screen must not hand them the
         * product editor, the category tree and the brands editor -- and the
         * day catalog.manage is narrowed, which is a reasonable thing to want,
         * this must not narrow with it from another file with nothing to
         * notice.
         *
         * SPLIT IN TWO for the reason reviews.view is split from
         * reviews.manage: reading which sets exist is not the same act as
         * repricing and republishing six products at once. `sets.manage` is the
         * half that writes to `products`.
         */
        'sets.view' => ['owner', 'manager', 'editor'],
        'sets.manage' => ['owner', 'manager', 'editor'],

        /*
         * Catalog -> Sets -> Stock (Lane SP, routes/sp-set-stock-admin.php).
         * The one switch that decides whether selling a set takes one of each
         * member off its own shelf. It ships at today's behaviour and moves
         * nothing until somebody moves it.
         *
         * A THIRD CAPABILITY AND NOT `sets.manage`, and NARROWER THAN IT -- no
         * editor. Creating and repricing sets is a catalogue act; deciding that
         * selling one empties three other shelves is an INVENTORY act, and it
         * can oversell the shop or refuse sales it could have filled without
         * anything on the Sets screen looking different. That is the same
         * argument `orders.money` is separated from `orders.manage` by.
         */
        'sets.stock' => ['owner', 'manager'],

        /*
         * Catalog -> Product tabs (Lane PT, routes/product-tabs-admin.php).
         * Tabs the owner writes himself: global ones that appear on every
         * product, and ones that exist on a single product.
         *
         * TWO NEW CAPABILITIES AND NOT `catalog.manage`, which would have been
         * the lazy answer and is wrong in BOTH directions. A global tab's body
         * is operator-authored HTML printed on EVERY product page in the shop,
         * so granting it to everyone who may correct a product's name is too
         * wide; and requiring it would mean the person who writes the shipping
         * copy must also be allowed to change prices, which is too narrow.
         *
         * SPLIT IN TWO for the reason reviews.view is split from
         * reviews.moderate: reading what a product's tabs say is not the same
         * act as rewriting the paragraph that appears on seven hundred pages.
         * `editor` holds both, because this is storefront copy and that is what
         * the editor role is for.
         */
        'producttabs.view' => ['owner', 'manager', 'editor'],
        'producttabs.manage' => ['owner', 'manager', 'editor'],

        'content.manage' => ['owner', 'manager', 'editor'],

        // Content -> Media Library -> WebP images (Lane WP). Its own capability
        // and NOT content.manage: the bulk run rewrites image addresses across
        // the catalogue, pages and settings, and "Remove originals" deletes
        // files. Owner and manager; an editor uploads (and gets WebP) but does
        // not run the shop-wide conversion.
        'media.optimize' => ['owner', 'manager'],

        // Catalog -> Image SEO (Lane IR). Its own capability and NOT
        // catalog.manage or content.manage: a run renames files on disk and
        // rewrites their addresses across the catalogue, pages and settings.
        // Owner and manager, like the WebP run it is built beside.
        'media.image_seo' => ['owner', 'manager'],

        /*
         * Content -> Shoppable video (Lane V2, Phase 20). The UGC library: the
         * clips, who made them, whether they said yes, and which products are
         * tagged on each.
         *
         * TWO CAPABILITIES AND NEITHER IS content.manage, although all three
         * hold the same three roles as this ships. docs/UGC-VIDEO-PLAN.md §7
         * asks for a capability of its own by name, and the reason is the point
         * of having any of them: granting somebody the video library must not
         * hand them the media library, the mega menu, the homepage and every
         * other thing content.manage reaches — and the day somebody narrows
         * content.manage, which is a reasonable thing to want, this must not
         * narrow with it from another file with nothing to notice.
         *
         * SPLIT IN TWO for the reason reviews.view is split from
         * reviews.manage: reading which clips are live, and replacing the file
         * a clip serves, are different acts. `ugc.manage` is the one that moves
         * up to 64 MB onto the web root and, where ffmpeg exists, runs two
         * transcodes on the request.
         *
         * `rights_evidence` — a creator's DM, an email, a signed release — is
         * behind `ugc.view`, and is never returned by any public endpoint: see
         * UgcVideo::toApi(), which is an allowlist rather than a model.
         */
        'ugc.view' => ['owner', 'manager', 'editor'],
        'ugc.manage' => ['owner', 'manager', 'editor'],

        // (Lane IGR) `instagram.view` and `instagram.manage` (Content → Instagram,
        // the API module and its "Connect with Facebook" page) were retired with
        // that module at the owner's request, 9 October 2026.

        /*
         * ── Appearance → Banners → Cards banner (Lane BN, routes/banners-admin.php)
         *
         * TWO, AND NEITHER REUSES content.manage. A banner set decides what the
         * FRONT PAGE of the shop shows, which is a narrower and louder thing than
         * the media library or the mega menu that capability also covers — and a
         * capability that covers everything is one nobody can grant carefully.
         *
         * `editor` IS on the manage half here, where it is deliberately not on
         * the retired instagram.manage, and the difference is the point of splitting them at
         * all: this grant is "may lay out the homepage's banner row", which is the
         * storefront work that role exists for. It holds no credential, reaches no
         * third party and can disconnect nothing.
         *
         * `support` is on neither. A support account answers customers; it has no
         * reason to be able to read, let alone rewrite, what the homepage shows.
         */
        'banners.view' => ['owner', 'manager', 'editor'],
        'banners.manage' => ['owner', 'manager', 'editor'],

        /*
         * ── Appearance → Grid sections (Lane GS, routes/grid-sections-admin.php)
         *
         * The owner's reusable product-grid section, as many instances as he
         * builds. TWO, and the same three roles as `banners.*` above, for the
         * same reason and with the same argument: an instance decides what the
         * FRONT PAGE shows, which is narrower and louder than the media library
         * `content.manage` also covers, and laying out the homepage is the work
         * the `editor` role exists for.
         *
         * `support` is on neither half. A support account answers customers.
         *
         * ▲ `gridsections.view` IS NOT A HARMLESS GRANT, and it is worth saying
         * why it is still only a view. The screen's list endpoint returns the
         * shop's brands and categories as option sets and its picker searches
         * the catalogue by name — published catalogue data, all of it, and
         * nothing that is not already on /shop. What it does NOT return is a
         * product row: the picker's allowlist is five columns wide and is
         * asserted by GridSectionApiSurfaceTest, because a bare ->get() here
         * would hand an editor `wc_id`, `sku` and `total_sales` the way the
         * defects CLAUDE.md lists did.
         */
        'gridsections.view' => ['owner', 'manager', 'editor'],
        'gridsections.manage' => ['owner', 'manager', 'editor'],

        // Appearance → Homepage content → All sections (Lane HC,
        // routes/homepage-hub-admin.php). Both READ: the section list, and the
        // catalogue search behind a manual list and the source preview. Every
        // save goes to the endpoint that owns the value, under its own
        // capability, so neither of these can write anything.
        'homepagehub.view' => ['owner', 'manager', 'editor'],
        'homepagehub.search' => ['owner', 'manager', 'editor'],
        // Lane FS: a section's Fonts & size tab — the hub's one WRITE. The
        // same three roles content.manage carries, because it is the same
        // kind of change as the words beside it: how a homepage section looks.
        'homepagehub.type' => ['owner', 'manager', 'editor'],

        // Writing an article into the Journal (Lane J). The same three roles
        // content.manage carries, and its own capability for the reason the
        // three below give: an article is published at the SITE ROOT of this
        // shop, /{slug}/, which is a stronger thing to hand out than the mega
        // menu and the media library. Narrowing who may publish under the
        // shop's own name must not have to narrow those with it.
        //
        // READING the list of articles stays on content.manage: GET
        // admin-api/posts is a table of titles, and an editor who may not write
        // one can still be shown what exists.
        'posts.manage' => ['owner', 'manager', 'editor'],

        // Rewriting a content page — the privacy policy, the terms, the returns
        // policy, the FAQ (Lane S9). The same three roles content.manage
        // carries, and its own capability for the same reason posts.manage has
        // one: these seven pages are what the footer links to from every page of
        // the shop, their bodies are printed UNESCAPED
        // (resources/views/store/page.blade.php), and who may rewrite the terms
        // of sale is a decision worth being able to narrow without narrowing the
        // mega menu and the media library with it.
        //
        // READING the list of pages stays on content.manage: GET
        // admin-api/pages/* is a table of titles and addresses, and someone who
        // may not rewrite the returns policy can still be shown that it exists.
        'pages.manage' => ['owner', 'manager', 'editor'],

        // The cart page's own appearance — row density, the recommended rail
        // and which products fill it, the summary wording and the two docked
        // bars. Storefront appearance, so the same three roles as
        // content.manage; its own capability so it can be moved on its own.
        // The RULES entry below carries the full argument.
        'cartpage.manage' => ['owner', 'manager', 'editor'],

        // (2.60.367) "Make all share pictures now": writes the link-preview
        // JPEGs under public/img-cache/share and nothing else. The same three
        // roles as the Product page screen it sits on; its own capability so
        // it can be narrowed on its own.
        'shareimages.make' => ['owner', 'manager', 'editor'],

        // The set box's own appearance — the fanned member circles, the
        // "What's inside" popup and the saving, on the cart drawer, the cart
        // page, the checkout summary, the browsed rail, an order's detail page
        // and the buy column of a set's product page, with a separate value per
        // breakpoint for every measurement. Storefront appearance again, so the
        // same three roles; its own capability for the same reason as the line
        // above, which is that narrowing one must not silently narrow the other
        // from a different file.                                     (Lane SA)
        'setappearance.manage' => ['owner', 'manager', 'editor'],

        // The checkout page's spacing — the two column widths, the page
        // padding and the padding inside each of the four numbered sections,
        // stored separately for desktop and for mobile. Storefront appearance
        // again, and its own capability for the same reason as the line above:
        // narrowing one must not silently narrow the other.
        'checkoutpage.manage' => ['owner', 'manager', 'editor'],

        // The slim bar at the foot of the cart page and the checkout: its
        // words, its shape, and which of those two pages draws it. Storefront
        // appearance again, and its own capability for the same reason as the
        // two above.
        'slimfooter.manage' => ['owner', 'manager', 'editor'],

        // Appearance → Footer's live preview (Lane FT): renders one footer page
        // from unsaved values and writes nothing. The same three roles as the
        // screen it serves, and its own name so narrowing either never
        // silently narrows the other.
        'footer.preview' => ['owner', 'manager', 'editor'],

        // Appearance → #KBeautyBliss Spotted (Lane HB, routes/spotted-admin.php):
        // the hand-picked Instagram posts on the homepage carousel and on
        // /kbeautybliss-spotted/, and the section's look. Storefront appearance,
        // so the same three roles as the footer above; its own capability so
        // narrowing one never narrows the other.
        'spotted.manage' => ['owner', 'manager', 'editor'],

        // (Lane IGR) `spotted.instagram` (Lane SG's synced-post picker) went with
        // the Instagram API module; the Spotted page draws the Instagram embeds.

        // Content → Instagram embeds (Lane IGE, routes/ig-embeds-admin.php):
        // the pasted post and reel addresses drawn with Instagram's own embed,
        // and the section's look. Storefront content, so the same three roles
        // as Spotted; its own capability so narrowing it narrows nothing else.
        'igembeds.manage' => ['owner', 'manager', 'editor'],

        // The site width, the side gutter and the product column count: one
        // screen, nine numbers, and every one of them printed into a stylesheet
        // on every page of the shop. Storefront appearance again, and its own
        // capability for the same reason as the three above. (Lane W1)
        'sitelayout.manage' => ['owner', 'manager', 'editor'],

        // The page background: the palette, its strength, how far and how fast
        // it travels, and which pages carry it. Storefront appearance again,
        // and its own capability for the same reason as the four above -- this
        // one writes colours that are printed into a gradient on every page of
        // the shop, and it also decides whether the wash is ON AT ALL, which is
        // a switch the owner has not yet said yes to. (Lane BG)
        'pagewash.manage' => ['owner', 'manager', 'editor'],

        // The floating WhatsApp button: its design, size, position, link and
        // wording, printed on every page of the shop -- and the link becomes
        // an href there. Storefront appearance, so the same three roles as the
        // five above, and its own capability for the same reason. (Lane WA)
        'wabutton.manage' => ['owner', 'manager', 'editor'],

        // Appearance -> Coming Soon page (Lane CS): one switch that hides the
        // whole shop on an address (or on every address) behind a 503 page,
        // and the secret link that lets someone past it. A shop-wide
        // visibility decision, not an appearance tweak, so owner only like
        // platform.domain_switch; hand it to a custom role deliberately.
        'comingsoon.manage' => ['owner'],

        // App -> Site App (Lane PW): whether the shop is an installable Home
        // Screen app at all -- the manifest, and a service worker on every
        // shopper's phone -- and the app's name. Its own capability, owner and
        // manager: switching a worker on or off for every visitor is a platform
        // decision, not an appearance tweak, so editors do not get it by default.
        'siteapp.manage' => ['owner', 'manager'],

        // Pages → Page banners: the promo picture and strip on the custom pages
        // (/super-sale/ and the content pages), which page shows which, and
        // which products /super-sale/ lists. Storefront content printed on
        // public pages, a link that becomes an href, and the campaign's product
        // list -- so its own capability, the same three roles. (Lane SS)
        'pagebanners.manage' => ['owner', 'manager', 'editor'],

        // Pages → Page header: the title block of the custom pages — which of
        // its parts show, in what order, and its picture — and the storefront's
        // "Edit header" panel that saves the same thing. Printed on public
        // pages, so its own capability, the same three roles. (Lane PH)
        'pageheader.manage' => ['owner', 'manager', 'editor'],

        // The category page's "Edit header" panel on the shop: the title
        // header's name, description, pictures, sizes and spacing, and the
        // switch to a custom header area with its own banner and strip. Printed
        // on public pages, so its own capability, the same three roles. (Lane CH)
        'categoryheader.manage' => ['owner', 'manager', 'editor'],

        // Catalog → Pagination: whether /shop/, a category, a brand or a
        // listing page pages at all, or shows every product at once. Changes
        // what every listing on the shop draws, so its own capability, the
        // same three roles as Site layout's "How more products load". (Lane PG)
        'pagination.manage' => ['owner', 'manager', 'editor'],

        // Safety → 404 page: which of the four 404 designs the shop shows, its
        // words in both languages, its links and its per-device sizes. Every
        // shopper who mistypes an address sees it, so its own capability; the
        // two roles that run the shop, not the editor. (Lane NF)
        'notfoundpage.manage' => ['owner', 'manager'],

        // Store → Inquiries: the contact page's messages (Lane CT). Reading
        // them is a support job, as a customer record is, so support reads and
        // marks them read; deleting one and the contact page's own settings
        // (cards, form, recipient, topics) are the two roles that run the
        // shop. The editor holds neither: a message carries a stranger's
        // email and phone.
        'inquiries.view' => ['owner', 'manager', 'support'],
        'inquiries.manage' => ['owner', 'manager'],

        /*
         * THE STOREFRONT'S OWN ADMIN LAYER (Lane RA): the thin bar across the
         * top of every shop page and the pencil on a category or brand header.
         *
         * OWNER ONLY, because he said so in as many words: "only administrator
         * for now, later we will create a user-roles module, and i can select
         * this option to allow for our specific store managers ... but now for
         * me only." Two capabilities rather than one so that, when that module
         * exists, he can hand a manager the bar without the pencil or the
         * pencil without the bar. Until then granting either is one word in the
         * array below -- 'manager' -- and nothing else changes: the endpoints
         * already ask for these by name and the context answer is already
         * built per capability.
         *
         *   storefront.adminbar    see the bar (links, name, log out)
         *   storefront.quick_edit  the pencil, its preview and its save
         */
        'storefront.adminbar' => ['owner'],
        'storefront.quick_edit' => ['owner'],

        // Reviews. The export is separated from the rest of the screen because
        // the review rows carry author_email and the reviewer's IP.
        'reviews.view' => ['owner', 'manager', 'support', 'editor'],
        'reviews.moderate' => ['owner', 'manager', 'support'],
        'reviews.manage' => ['owner', 'manager', 'editor'],
        'reviews.export' => ['owner', 'manager'],

        // Growth & Marketing. The newsletter export is a subscriber list.
        'marketing.view' => ['owner', 'manager'],
        'marketing.manage' => ['owner', 'manager'],
        'marketing.export' => ['owner', 'manager'],
        /*
         * Growth & Marketing -> Marketing Emails (Lane MK, docs/EMAILS-PLAN.md
         * §6). Three, because reading a report, building an email and pressing
         * Send are three different acts:
         *
         *   marketing.email.view    Campaigns, Templates, Customer groups and
         *                           Reports, read; the live group count.
         *   marketing.email.manage  build templates and groups, draft
         *                           campaigns, the builder's preview, test sends.
         *   marketing.email.send    send, schedule, pause, resume or cancel a
         *                           campaign, step it (Driver A), and the
         *                           sending limits. OWNER AND MANAGER: the
         *                           owner's D11, "campaign Send for Owner and
         *                           Administrator" (row 53 maps Administrator
         *                           to manager until the roles module names it).
         *
         * Support and editor hold none of them. Downloading a group as CSV is
         * the existing marketing.export, because it is the same act as the
         * newsletter export: taking the shop's list away.
         */
        'marketing.email.view' => ['owner', 'manager'],
        'marketing.email.manage' => ['owner', 'manager'],
        'marketing.email.send' => ['owner', 'manager'],

        /*
         * Growth & Marketing -> Push Notifications (Lane PN). Two, and the
         * same owners as marketing.email.send ("Owner and Administrator",
         * Administrator = manager): push.view reads the campaigns, reports,
         * analytics and the live count; push.send writes a campaign, sends,
         * schedules, cancels, tests, and changes the automations and the
         * rules. Support, editor and the Marketing Manager preset hold neither.
         */
        'push.view' => ['owner', 'manager'],
        'push.send' => ['owner', 'manager'],

        /*
         * Growth & Marketing -> Google Shopping feed (Lane SEO): read the feed's
         * address and contents, and switch the public feed on or off. The feed
         * itself is public by design (Merchant Center fetches it); this guards
         * only the switch. Same owners as the other marketing tools.
         */
        'marketing.feed' => ['owner', 'manager'],

        /*
         * Growth & Marketing -> Marketing Pixels (Lane MP).
         *
         *   marketing.pixels.connect  the Meta / Google / TikTok Connect tabs:
         *                             paste and replace platform tokens (shown
         *                             back masked), run the Check buttons, the
         *                             Connect-with-Facebook login, and read the
         *                             Last events panel. Owner and manager, the
         *                             same people who run the marketing.
         *   marketing.customcode      the Custom code tab: raw script printed on
         *                             every shop page. OWNER ONLY — the one place
         *                             owner-supplied HTML reaches the shop, so no
         *                             preset below Full Admin carries it.
         */
        'marketing.pixels.connect' => ['owner', 'manager'],
        'marketing.customcode' => ['owner'],

        // Delivery rates and the pay/ship rules: a manager's job, not a
        // configuration change.
        'store.shipping' => ['owner', 'manager'],

        // Everything below here is the owner's alone, and each for its own
        // reason rather than out of caution.
        //
        //   store.settings      currency, modules, mail transport, the admin
        //                       path itself — the levers that can take the
        //                       storefront down or redirect its mail.
        //   payments.manage     encrypted Stripe/Tabby/Tamara credentials.
        //   users.manage        the hole this whole file exists to close.
        //   updates.manage      applies and rolls back code on a host with no
        //                       shell; the most dangerous button in the admin.
        //   system.diagnostics  the raw error log, the route table, the schema
        //                       and the site-wide cart debug view.
        //   data.import         rewrites customers, orders and products from an
        //                       uploaded CSV.
        //   cache.manage        clears the compiled config, routes and views
        //                       of a running shop, and decides what every
        //                       visitor's browser is allowed to keep.
        //   security.view       the administrative audit trail, the failed
        //                       sign-ins and the rate-limit trips -- every row
        //                       of which names an operator, their role and an
        //                       IP address. Its own capability and NOT
        //                       system.diagnostics, although both are
        //                       owner-only as this ships: the day somebody
        //                       widens diagnostics to a manager, which is a
        //                       reasonable thing to want, this must not widen
        //                       with it in a different file with nothing to
        //                       notice.
        //   security.integrity  makes the server hash every file an update
        //                       package installed and compare each one with the
        //                       hash that package declared. Its own capability
        //                       and NOT security.view, although both are
        //                       owner-only as this ships: reading a report that
        //                       is already written and asking a shared plan for
        //                       a few thousand file reads on demand are
        //                       different acts with different costs, and the
        //                       day a manager may read this screen -- which is
        //                       a reasonable thing to want -- that must not
        //                       hand them the button as well. It reads files
        //                       and writes rows; it restores nothing.
        'store.settings' => ['owner'],
        /*
         * Writing APP_URL into .env, which repoints every link this shop sends
         * out of band -- password resets, order emails, the sitemap, and the
         * webhook URLs registered at Stripe, Tabby and Tamara.
         *
         * ITS OWN CAPABILITY RATHER THAN store.settings, per CLAUDE.md rule 5,
         * and because the blast radius is different in kind: a wrong currency
         * is visible on the next page load, a wrong APP_URL is invisible until
         * somebody's password-reset link lands on a domain the shop no longer
         * owns. Owner-only, like every other capability in this block.
         *
         * Lane N argued this route should stay UNMAPPED on the grounds that an
         * unmapped route is the strictest setting. That is true of the runtime
         * -- for() returns null and EnforceAdminCapability refuses every role
         * but the owner -- but it is not true of the test that guards this map,
         * which pins the deny-by-default property against a route registered
         * inside itself precisely so that real routes cannot rely on it. Its
         * cited precedent was wrong too: admin-api/site-address beside this one
         * IS mapped, to store.settings.
         */
        'platform.site_url' => ['owner'],
        /*
         * Platform -> Domain switch (Lane DW): the step-by-step move to a new
         * domain. Its buttons reach APP_URL, the main and old addresses, the
         * caches, the payment webhooks, the picture fetcher and the owner app's
         * own address, so it is owner-only here AND its controller refuses
         * anyone who is not a Full Admin even when a custom role is handed this
         * key -- otherwise this one tick would be a side door to six others.
         */
        'platform.domain_switch' => ['owner'],
        /*
         * Platform -> Domain switch -> Payments ready? (Lane DS). READ-ONLY
         * calls to Stripe, Tabby and Tamara with the stored keys; no secret is
         * returned. Its own key so it can be handed to whoever tests payments
         * without handing them the switch.
         */
        'payments.check' => ['owner'],
        'cache.manage' => ['owner'],
        'security.view' => ['owner'],
        'security.integrity' => ['owner'],
        /*
         * Store -> Security -> Firewall (Lane FW). Two keys, both owner-only:
         * reading what the firewall saw, and changing what it refuses. NOT
         * security.view: the day a manager may read the audit trail, that must
         * not hand them the switch that turns the shop's bot protection off.
         */
        'firewall.view' => ['owner'],
        'firewall.manage' => ['owner'],
        'payments.manage' => ['owner'],
        // Lane SR -- see the RULES entries for admin-api/payments/stripe/webhook.
        'payments.stripe_webhook' => ['owner'],
        'payments.log' => ['owner'],
        'users.manage' => ['owner'],
        /*
         * Platform -> Users & Roles -> Roles (Lane RL): create, rename, re-tick,
         * restore and delete roles. ITS OWN CAPABILITY AND NOT users.manage,
         * because deciding what a role may do and deciding who is on it are two
         * acts the owner may want to hand to two people. Owner-only, like the
         * account list it sits beside; and even a holder can never tick a box
         * they do not hold themselves (AdminRoles::refusal()).
         */
        'roles.manage' => ['owner'],
        /*
         * Platform -> Users & Roles -> Owner app (Lane MAC): who may open the
         * PIN-unlocked phone app, their PIN, their phones, and the app's secret
         * address. Its own capability, owner-only, failing closed: handing out
         * a PIN is handing out a way in.
         */
        'ownerapp.manage' => ['owner'],
        /*
         * Edit presence (Lane RL): "X is editing this product" and Take over.
         * presence.view is the heartbeat itself -- every role that can sign in
         * holds it, because seeing that somebody else has a record open gives
         * nothing away and protects the work of both. presence.takeover is
         * owner-only in the map; the Sub Admin preset adds it
         * (AdminRoles::SUB_ADMIN_EXTRA). A save is refused while somebody else
         * holds the record whatever either person holds (App\Support\EditPresence).
         */
        'presence.view' => ['owner', 'manager', 'support', 'editor'],
        'presence.takeover' => ['owner'],
        'updates.manage' => ['owner'],
        'system.diagnostics' => ['owner'],
        'data.import' => ['owner'],
        /*
         * Store -> SEO -> Overview and the SEO audit (Lane RL). Split out of
         * system.diagnostics so an SEO Manager can be given the audit without
         * the raw error log. OWNER-ONLY AS IT SHIPS, exactly what the two routes
         * were before, so no existing account gains or loses anything.
         */
        'seo.audit' => ['owner'],
        /*
         * Store -> Import -> "Clean up before the migration". OWNER ALONE, and
         * a capability of its own rather than a reuse of `data.import` beside
         * it, because the import endpoints ADD rows to the shop and these two
         * REMOVE them. A role trusted to load the owner's catalogue in is not
         * thereby a role trusted to delete part of it. (Lane IE)
         */
        'data.cleanup' => ['owner'],
        /*
         * Store -> Import -> Addresses & pictures -> "Links to the old site"
         * (Lane PT). OWNER ALONE, and its own line rather than `data.import`
         * beside it: the import loads rows in, and this rewrites the shop's own
         * copy -- every description, set, article and block that links to the
         * old site -- in one press. Fails closed for every other role.
         */
        'data.old_links' => ['owner'],
        /*
         * Emails (Lane RK, package E1) — the owner's new parent menu.
         *
         * OWNER ALONE, both of them, because that is what mail has always been
         * here: admin-api/mail and admin-api/mail/** map to store.settings,
         * which is owner-only, and the Emails screens edit the same rows and
         * press the same test-send. Widening them to a manager would hand the
         * transport, the From address and an endpoint that makes the server
         * send mail to a caller-chosen address to a role that never had them.
         *
         * TWO and not one, and NOT store.settings, so that the day the owner
         * lets a manager READ the overview — a reasonable thing to want, it
         * holds no secret and changes nothing — that does not also hand over
         * the transport, and so that widening store.settings for currency does
         * not silently widen mail with it.
         *
         *   emails.view    GET admin-api/emails/overview
         *   emails.manage  every other admin-api/emails route: the sending
         *                  settings, the Google app password, the test-send
         *                  and the contact details printed in every email.
         */
        // Lane EK (plan §6, set by the integrator 4 Oct): owner AND manager may
        // read Overview, Customer emails and Sent mail; changing is the owner's.
        'emails.view' => ['owner', 'manager'],
        'emails.manage' => ['owner'],
        /*
         * Lane EK, docs/EMAILS-PLAN.md §6: test sends of any customer email
         * to the signed-in admin's own address (throttled). Owner and manager.
         */
        'emails.test' => ['owner', 'manager'],
    ];

    /**
     * Console pages, keyed by route name.
     *
     * These are matched by NAME and not by URI on purpose: their paths carry
     * the configurable admin path (AdminPathService::current()), so the URI is
     * 'admin/updates' on one install and 'kbb-7f2a/updates' on the next. The
     * names are stable across both.
     *
     * admin.login, admin.login.post and admin.logout are absent because they
     * are registered outside the auth:admin group and this map never sees them.
     */
    public const ROUTE_NAMES = [
        'admin' => 'admin.access',
        'kbb.health.log' => 'system.diagnostics',
        'kbb.route.check' => 'system.diagnostics',
        'admin.updates' => 'updates.manage',
        'admin.updates.upload' => 'updates.manage',
        'admin.updates.apply' => 'updates.manage',
        'admin.updates.cancel' => 'updates.manage',
        'admin.updates.rollback' => 'updates.manage',
        'admin.updates.download' => 'updates.manage',
        'admin.path.update' => 'store.settings',
    ];

    /**
     * Everything else, matched on method + route URI. [method, pattern, capability].
     *
     * Method is 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' or '*'. HEAD is
     * normalised to GET before matching.
     *
     * Pattern is matched against the ROUTE's uri (so '{id}' is still literally
     * '{id}'), with two wildcards:
     *
     *     *   matches within one segment and never crosses a slash
     *     **  matches one or more whole segments
     *
     * The single-segment '*' is what keeps this list from being a minefield of
     * ordering. 'admin-api/orders/*' cannot swallow 'admin-api/orders/{id}/
     * refund' however the two are ordered, which is the same class of mistake
     * that put /admin-api/reviews/settings behind an untyped PUT /reviews/{id}
     * and made a whole route file dead code.
     *
     * Order still decides between two patterns that both genuinely match — the
     * export routes below sit above their sibling '{id}' reads for exactly that
     * reason, and AdminCapabilityMapTest pins each of those pairs by name.
     *
     * FIRST MATCH WINS. No match at all means null, which means owner-only.
     */
    public const RULES = [
        // -------------------------------------------------- back-office accounts
        // The self-promotion hole. A support account PUTting its own row to
        // role=owner is the single reach this whole layer was built for.
        ['*', 'admin-api/users', 'users.manage'],
        ['*', 'admin-api/users/*', 'users.manage'],

        /*
         * Platform -> Users & Roles (Lane RL, routes/admin-roles.php). The
         * Members tab is the account list, so it is users.manage like the two
         * lines above; everything else under /roles is roles.manage. MEMBERS
         * FIRST: 'admin-api/roles/*' would otherwise match
         * 'admin-api/roles/members' and hand the account list out on the roles
         * capability.
         */
        ['*', 'admin-api/roles/members', 'users.manage'],
        ['*', 'admin-api/roles/members/*', 'users.manage'],
        ['*', 'admin-api/roles', 'roles.manage'],
        ['*', 'admin-api/roles/*', 'roles.manage'],
        ['*', 'admin-api/roles/*/restore', 'roles.manage'],
        ['*', 'admin-api/owner-app', 'ownerapp.manage'],
        ['*', 'admin-api/owner-app/**', 'ownerapp.manage'],
        // Edit presence (Lane RL). TAKE FIRST: 'admin-api/presence/*' would match it.
        ['POST', 'admin-api/presence/take', 'presence.takeover'],
        ['POST', 'admin-api/presence/beat', 'presence.view'],
        ['POST', 'admin-api/presence/release', 'presence.view'],

        // ------------------------------------------------------ gateways & money
        ['*', 'admin-api/payments', 'payments.manage'],
        /*
         * The preflight check reads gateway configuration and echoes the exact
         * request start() would send. The credentials are redacted, but which
         * boxes are filled, which host the mode resolves to and the shape of
         * the outgoing call are still the payment setup -- so it sits under the
         * same capability as the screen that edits it, not under a looser one.
         */
        ['GET', 'admin-api/payments/preflight', 'payments.manage'],
        ['GET', 'admin-api/payments/preflight/*', 'payments.manage'],
        /*
         * Reconciliation reads the gateways' books and names this shop's orders,
         * references and amounts. Same capability as the screen it lives on.
         * `acknowledge` is its only write and it moves no money -- it records
         * that a human looked, which is why who and when are stored: it hides a
         * money discrepancy from the default view, and anything that hides one
         * has to say who hid it. Written down rather than left to fall through
         * to the owner-only default, so the intent is on the record.
         */
        ['*', 'admin-api/payments/reconcile', 'payments.manage'],
        ['*', 'admin-api/payments/reconcile/**', 'payments.manage'],

        /*
         * Stripe connect / disconnect — routes/payments-connect.php.
         *
         * The same capability as the screen that edits the keys by hand,
         * because these do the same thing with fewer keystrokes: one of them
         * takes a live secret key in a request body and another takes the shop
         * off its card processor and deletes a webhook endpoint inside the
         * owner's Stripe account.
         *
         * WRITES BEFORE READS, and specifically before the '/connect/*' read
         * below it. 'admin-api/payments/stripe/connect' is the POST and
         * 'admin-api/payments/stripe/connect/*' would not match it — but
         * 'admin-api/payments/stripe/connect/application' IS a POST that the
         * GET wildcard would match, and a wildcard read rule listed first would
         * hand a write endpoint out on a read capability. That is the shape of
         * the quiz-leads and coupons/manage mistakes further down this file,
         * and it is written out rather than relied on because both of those
         * were found by a test rather than by a reader.
         */
        ['POST', 'admin-api/payments/stripe/connect', 'payments.manage'],
        ['POST', 'admin-api/payments/stripe/connect/application', 'payments.manage'],
        ['POST', 'admin-api/payments/stripe/disconnect', 'payments.manage'],
        ['GET', 'admin-api/payments/stripe/connect/*', 'payments.manage'],
        /*
         * Lane SR: Store -> Payments -> Stripe's status block, its "Set up
         * webhook automatically" button and its payment log. THEIR OWN
         * capabilities, per CLAUDE.md rule 5, owner-only as this ships:
         *
         *   payments.stripe_webhook  reads the status block and REGISTERS an
         *                            endpoint in the owner's Stripe account with
         *                            the stored secret key -- a write at Stripe.
         *   payments.log             reads the payment log: order numbers,
         *                            PaymentIntent ids, Stripe's error codes.
         *                            Never a key or a card number, but still
         *                            not something to hand out with a read of
         *                            the order list.
         *
         * Neither is folded into payments.manage, so the day a manager may read
         * the log to help troubleshoot, he does not also gain the button that
         * rewrites the shop's webhook registration.
         */
        ['GET', 'admin-api/payments/stripe/webhook', 'payments.stripe_webhook'],
        ['POST', 'admin-api/payments/stripe/webhook/setup', 'payments.stripe_webhook'],
        ['GET', 'admin-api/payments/stripe/log', 'payments.log'],
        /*
         * Tabby webhook registration — routes/payments-tabby.php.
         *
         * `payments.manage`, the same capability as the screen that stores the
         * keys, because it does the same kind of thing: it reads the Tabby
         * credentials and the POST writes a public callback address into the
         * merchant's Tabby account. Tabby, unlike Stripe and Tamara, has no
         * dashboard field for that address — it is registered through their API
         * or it does not exist, so this pair is what makes Tabby able to tell
         * this shop that a payment succeeded.
         *
         * Not folded into the 'admin-api/payments' rule above it: that rule
         * matches the exact path and nothing under it, which is deliberate —
         * a wildcard on 'admin-api/payments/*' would have quietly adopted every
         * future endpoint anybody hangs off that prefix onto whatever capability
         * was convenient the day it was written.
         */
        ['GET', 'admin-api/payments/tabby/webhooks', 'payments.manage'],
        ['POST', 'admin-api/payments/tabby/webhooks', 'payments.manage'],
        /*
         * Tamara's two provider-side settings — routes/payments-tamara.php.
         *
         * `payments.manage`, the same capability as the screen that edits the
         * keys by hand, on the reasoning already written out for the Stripe
         * connect routes above: these do the same thing with fewer keystrokes.
         * Registering the webhook changes what Tamara sends this shop, removing
         * it silently stops the shop hearing about declines, and the limits
         * refresh rewrites which baskets are offered BNPL at all.
         *
         * BOTH VERBS ON THE WEBHOOK PATH ARE COVERED BY THE '*' METHOD, and the
         * DELETE is the reason to say so: a rule written 'POST' only would leave
         * the DELETE unmapped, which is owner-only at runtime and therefore not
         * a hole — but it would be a hole the day somebody widens
         * payments.manage to a manager, in a different file, with nothing to
         * notice. The same argument the security.view / security.integrity split
         * is made on.
         *
         * 'admin-api/payments/tamara' does NOT match 'admin-api/payments/tamara/
         * webhook' — the single-segment '*' never crosses a slash and a literal
         * pattern matches nothing beyond itself — so both lines are needed and
         * neither can swallow the other whichever order they are read in.
         */
        ['*', 'admin-api/payments/tamara', 'payments.manage'],
        ['*', 'admin-api/payments/tamara/*', 'payments.manage'],
        ['GET', 'admin-api/orders/*/settlement', 'orders.money'],
        ['POST', 'admin-api/orders/*/capture', 'orders.money'],
        ['POST', 'admin-api/orders/*/refund', 'orders.money'],
        /*
         * Releasing a BNPL authorisation on a cancelled order —
         * routes/payments-void.php, App\Services\Payments\VoidsAuthorisation.
         *
         * GATEWAY-AGNOSTIC, WHICH IS WHY THE PATH NAMES NO PROVIDER. Two lanes
         * built this endpoint in the same round, one for Tamara and one for
         * Tabby, and it is the same endpoint: PaymentVoider asks the registry
         * whether the order's gateway implements VoidsAuthorisation. Both rules
         * below therefore cover both providers and anything that implements it
         * next.
         *
         * `orders.money`, WITH the capture and the refund, and deliberately not
         * a capability of its own. It is the third verb of one act: capture takes
         * the money, refund gives back money that was taken, and this gives back
         * the right to take it. Whoever may do two of those three may do the
         * third — an operator trusted to refund a customer in full is trusted to
         * stop them being billed for an order the shop cancelled, and it is the
         * LESS dangerous of the two by a wide margin.
         *
         * Splitting it would create exactly the shape the note at the head of
         * RULES warns about: an operator who can capture but not refund, or here
         * cancel an order but not release the plan behind it — which leaves the
         * customer worse off than if neither button existed, because the order
         * now looks settled from this end.
         *
         * THE READ IS ON THE SAME CAPABILITY, not on `orders.view`, matching the
         * settlement read three lines up: whether a hold is open, what it is
         * worth and why the button is refused is the money picture, not the
         * order's contents.
         */
        ['GET', 'admin-api/orders/*/void', 'orders.money'],
        ['POST', 'admin-api/orders/*/void', 'orders.money'],

        // ------------------------------------------------------------- core updates
        ['*', 'admin-api/updates', 'updates.manage'],
        ['*', 'admin-api/updates/**', 'updates.manage'],

        // -------------------------------------------------------- site configuration
        /*
         * Which address is the shop's real one, which old ones forward to it,
         * and whether this install is in Google at all. `store.settings` is
         * already owner-only and this is site configuration in the plainest
         * sense, so it needs no capability of its own.
         *
         * Mapped rather than left to the closed-by-default fallback, because
         * AdminCapabilityMapTest requires every admin route to be named here --
         * "owner-only because nothing maps it" and "owner-only because somebody
         * decided so" are the same 403 and a very different piece of evidence.
         */
        ['*', 'admin-api/site-url', 'platform.site_url'],
        ['*', 'admin-api/site-url/**', 'platform.site_url'],
        // Platform -> Domain switch -> Payments ready? (Lane DS): read-only checks
        // against Stripe, Tabby and Tamara. Its own key, ABOVE the wildcard below.
        ['POST', 'admin-api/domain-switch/payments-check', 'payments.check'],
        // Platform -> Domain switch (Lane DW). Both lines: `/**` does not match the bare prefix.
        ['*', 'admin-api/domain-switch', 'platform.domain_switch'],
        ['*', 'admin-api/domain-switch/**', 'platform.domain_switch'],
        ['*', 'admin-api/site-address', 'store.settings'],
        ['*', 'admin-api/site-address/**', 'store.settings'],

        /*
         * Platform -> Cache. A capability of its own, and NOT store.settings
         * although both are owner-only as this ships.
         *
         * What these do is not what a settings screen does: /cache/clear drops
         * the compiled config, routes and views out from under a shop that is
         * serving requests, and the switch under /cache changes the
         * Cache-Control header on every page a visitor is sent. Mapping them
         * onto store.settings would mean the day somebody widens that
         * capability to a manager -- which is a reasonable thing to want, it is
         * currency and mail transport -- this widens with it, silently, in a
         * different file, with nothing to notice.
         *
         * A '*' rule and not a GET/POST pair: three of the four routes write,
         * and there is no reading half here that a narrower role should reach
         * without the writing half. The '/**' line is a SIBLING of the exact
         * one above it and neither can shadow the other, but both are needed --
         * 'admin-api/cache' does not match 'admin-api/cache/clear'.
         */
        ['*', 'admin-api/cache', 'cache.manage'],
        ['*', 'admin-api/cache/**', 'cache.manage'],

        ['*', 'admin-api/settings', 'store.settings'],
        /*
         * The SEO result preview — routes/seo-back-office.php, Lane S7.
         *
         * THE SAME CAPABILITY AS THE ENDPOINT ABOVE IT, and that pairing is the
         * argument. `admin-api/settings` is what SAVES every value this
         * previews; a role that may change a published title may see what that
         * title will look like, and a role that may not, may not. Looser would
         * hand out the shop's resolved titles and descriptions without the
         * screen that edits them; tighter would mean the Save button worked and
         * the preview beside it did not, on the same screen, for the same
         * person.
         *
         * It is a POST because the candidate values travel in the body — it is
         * shown BEFORE Save, so the values are not in the table yet. It writes
         * nothing: see Admin\SeoPreviewApiController, and SeoRowPreviewTest,
         * which asserts the stored `seo` blob is byte-identical afterwards.
         */
        ['POST', 'admin-api/seo-preview', 'store.settings'],
        ['*', 'admin-api/ecommerce', 'store.settings'],
        ['*', 'admin-api/modules', 'store.settings'],
        ['*', 'admin-api/mail', 'store.settings'],
        ['*', 'admin-api/mail/**', 'store.settings'],
        /*
         * Emails (Lane RK). The one read the overview makes FIRST, because
         * RULES is first-match-wins and the wildcard under it would otherwise
         * claim it; then everything else under the prefix, reads and writes
         * alike. There is no reading half of the sending or branding screens a
         * narrower role should reach without the writing half.
         */
        ['GET', 'admin-api/emails/overview', 'emails.view'],
        /*
         * Lane EK, ABOVE the emails wildcard (first match wins), plan §6:
         * reading Customer emails and an email's editor is emails.view (owner
         * and manager); a test send to yourself is emails.test; switching,
         * saving and resetting are emails.manage (owner). The editor's live
         * preview is Design & branding's endpoint and stays emails.manage.
         */
        ['GET', 'admin-api/emails/customer', 'emails.view'],
        ['POST', 'admin-api/emails/templates/*/test', 'emails.test'],
        ['GET', 'admin-api/emails/templates/*', 'emails.view'],
        ['*', 'admin-api/emails/customer/**', 'emails.manage'],
        ['*', 'admin-api/emails/templates/**', 'emails.manage'],
        ['*', 'admin-api/emails', 'emails.manage'],
        ['*', 'admin-api/emails/**', 'emails.manage'],
        ['*', 'admin-api/shipping', 'store.shipping'],
        ['*', 'admin-api/extended-delivery', 'store.shipping'],
        ['*', 'admin-api/pay-ship-rules', 'store.shipping'],

        // ------------------------------------------------------------- diagnostics
        /*
         * Store -> Security (Lane C). The audit trail and the report over it.
         *
         * A `*` rule covering both verbs, which is what CLAUDE.md's
         * write-before-read ordering asks for: there is no reading half here
         * that a narrower role should reach without the writing half, and the
         * write is only the screen's own switches. The '/**' line is a SIBLING
         * of the exact one above it and neither can shadow the other -- both
         * are needed, because 'admin-api/security' does not match
         * 'admin-api/security/anything' and this screen will grow.
         *
         * Mapped although the route file is not required from routes/web.php
         * yet: the integrator wires it, and a rule that lands before the route
         * is the harmless order of the two.
         */
        /*
         * ▲ ABOVE THE WILDCARD, and that placement is the whole rule.
         * forPath() is first-match-wins, so this exact line is what
         * `admin-api/security/integrity` resolves to; move it below the
         * '/**' line and the button silently becomes a security.view endpoint
         * again, which is the shape of the quiz-leads and coupons/manage
         * mistakes further down this file. Writes before reads, as CLAUDE.md
         * has it — and this is the only endpoint under this prefix that does
         * work rather than reads a row.
         */
        ['POST', 'admin-api/security/integrity', 'security.integrity'],

        /*
         * Lane FW. ABOVE the security wildcard for the same reason as the
         * line before: first match wins, and below it every firewall endpoint
         * -- the mode switch included -- would resolve to security.view.
         */
        ['GET', 'admin-api/security/firewall', 'firewall.view'],
        ['GET', 'admin-api/security/firewall/**', 'firewall.view'],
        ['*', 'admin-api/security/firewall', 'firewall.manage'],
        ['*', 'admin-api/security/firewall/**', 'firewall.manage'],

        ['*', 'admin-api/security', 'security.view'],
        ['*', 'admin-api/security/**', 'security.view'],

        ['GET', 'admin-api/schema-inspect', 'system.diagnostics'],
        ['GET', 'admin-api/catalogue-audit', 'system.diagnostics'],
        /*
         * The SEO audit — routes/seo-audit-admin.php.
         *
         * The same capability as catalogue-audit one row up, because it is the
         * same screen's bigger sibling and it reveals strictly more: not just
         * which products lack a description, but which titles collide across
         * the whole catalogue and which pages carry a canonical pointing at
         * another host. Read together that is an inventory of where this shop
         * is weakest in search, which is exactly what a competitor would want
         * and what an editor account has no need for.
         *
         * ▲ (Lane RL) Now `seo.audit`, still owner-only by default: the same
         * people reach it, but it can be handed to an SEO Manager without the
         * error log that system.diagnostics also opens.
         */
        ['GET', 'admin-api/seo-audit', 'seo.audit'],
        /*
         * The SEO Overview — routes/seo-back-office.php, Lane S7.
         *
         * The same capability as the audit one row up, because it IS the audit
         * plus a rank: it calls SeoAudit::run() and re-presents every finding,
         * so anything the audit reveals this reveals as well. It adds a second
         * reading of the same kind — which built features are still waiting on
         * the owner, which is a list of where this shop is unfinished.
         *
         * A read. Nothing under this prefix writes, so there is no write rule
         * that has to sort above it.
         */
        ['GET', 'admin-api/seo-tasks', 'seo.audit'],
        /*
         * The storefront health check — routes/health-admin.php.
         *
         * Diagnostics and not dashboard.view, though the card that runs it
         * sits on the dashboard, because of what a FAILURE says: the exception
         * message and the application file and line it came from. That is the
         * same thing the raw error log gives, one page at a time, and the log
         * is owner-only two rows up. A support account that cannot read the log
         * must not be handed the interesting lines out of it.
         *
         * The console asks, is told no in the words EnforceAdminCapability
         * returns, and prints them. It does not guess.
         */
        ['GET', 'admin-api/health', 'system.diagnostics'],
        // Not under /admin-api: the cart debug view lives in the storefront's
        // own prefix and was given auth:admin after it was found returning the
        // five most recently active carts site-wide.
        ['GET', 'api/cart/debug', 'system.diagnostics'],

        // --------------------------------------------------- import & demo content
        /*
         * Uploading an export the server will not take in one request --
         * routes/import-parts-admin.php. `admin-api/import/**` below matches
         * these paths already; they are named ANYWAY, above it, because
         * CLAUDE.md rule 5 asks that a new admin endpoint's capability be a
         * decision somebody made rather than one it fell into, and all three
         * write bytes to this server's disk.
         *
         * `data.import` and not a capability of their own: they ARE
         * POST /admin-api/import/upload, cut into three requests because PHP's
         * `upload_max_filesize` refuses the whole file before any route runs.
         * An account that may put a 1 MB brands.csv on this server but not a
         * 3 MB orders.zip is a distinction nobody wants to administer, and one
         * an operator would grant in the same breath.
         */
        ['*', 'admin-api/import/part', 'data.import'],
        ['*', 'admin-api/import/part/**', 'data.import'],
        ['*', 'admin-api/import/**', 'data.import'],
        /*
         * Store -> Import -> "Addresses & pictures" (Lane GB). `data.import`
         * and not a capability of its own: these endpoints are the migration,
         * reached from the Import screen, and the two that write are as heavy
         * as anything under `import/**` -- one writes the redirect rows that
         * move every visitor landing on an address this shop does not serve,
         * the other rewrites every image path in the catalogue. Anyone trusted
         * to run the import is trusted with these; anyone not, is not.
         *
         * A `*` rule covering both verbs, which is what CLAUDE.md's
         * write-before-read ordering asks for -- there is no reading half here
         * that a narrower role should reach without the writing half.
         */
        // ABOVE the wildcard below, or it would never be reached: first match
        // wins. Its own capability -- see `data.old_links`. (Lane PT)
        ['*', 'admin-api/urls-media/old-links', 'data.old_links'],
        ['*', 'admin-api/urls-media/**', 'data.import'],
        ['*', 'admin-api/demo-content', 'data.import'],
        ['*', 'admin-api/demo-content/**', 'data.import'],
        /*
         * The pre-migration cleanup -- routes/cleanup-admin.php. Its own
         * capability, for the reason given beside `data.cleanup` above. Both
         * lines are needed: the bare prefix is not matched by the `/**` form.
         */
        ['*', 'admin-api/cleanup', 'data.cleanup'],
        ['*', 'admin-api/cleanup/**', 'data.cleanup'],
        /*
         * The sample order -- routes/sample-order-admin.php. Sits with Demo
         * Content because that is the screen it is on, and carries its OWN
         * capability for the reason given beside `orders.sample` above.
         *
         * A `*` rule covering all three verbs, which is what CLAUDE.md's
         * write-before-read ordering asks for: the GET reports whether a sample
         * order exists and where its documents are, and there is no narrower
         * role that should reach that without the POST and the DELETE beside
         * it -- they are one card on one screen.
         *
         * The '/**' line is a SIBLING of the exact one above it and neither can
         * shadow the other: 'admin-api/sample-order' does not match
         * 'admin-api/sample-order/anything'. Both are needed, and the second is
         * what makes a fourth endpoint added to that route file later fail
         * CLOSED against a capability rather than fall through to the unmapped
         * default.
         *
         * Mapped although the route file is not required from routes/web.php
         * yet: the integrator wires it, and a rule that lands before the route
         * is the harmless order of the two.
         */
        ['*', 'admin-api/sample-order', 'orders.sample'],
        ['*', 'admin-api/sample-order/**', 'orders.sample'],

        // ------------------------------------------------------------------ orders
        ['GET', 'admin-api/orders/*/invoice', 'invoices.view'],
        ['GET', 'admin-api/orders/*/packing-slip', 'invoices.view'],
        // The delivery note and the dispatch label carry no money, but they do
        // carry a customer's name, street address and phone number laid out for
        // printing — which is the same reason the two above are mapped and not
        // left to the unmapped-route default.
        ['GET', 'admin-api/orders/*/delivery-note', 'invoices.view'],
        ['GET', 'admin-api/orders/*/shipping-label', 'invoices.view'],
        /*
         * The same four documents, a hundred orders at a time (Lane GC). Same
         * capability, because it is the same information — and a SIBLING of
         * 'admin-api/orders', not a child, so neither the exact 'admin-api/
         * orders' read rule below nor the single-segment 'admin-api/orders/*'
         * wildcard above can claim it. An unmapped admin route is owner-only,
         * which would have left the shop's own packing staff unable to print.
         */
        ['GET', 'admin-api/orders-bulk-documents', 'invoices.view'],
        ['GET', 'admin-api/orders-export', 'orders.export'],
        ['POST', 'admin-api/orders-bulk-delete', 'orders.delete'],
        ['POST', 'admin-api/orders-bulk-restore', 'orders.delete'],
        ['POST', 'admin-api/orders/*/restore', 'orders.delete'],
        ['DELETE', 'admin-api/orders/*', 'orders.delete'],
        // Editing the lines of a placed order changes what it is owed.
        ['POST', 'admin-api/orders/*/items', 'orders.money'],
        ['PUT', 'admin-api/orders/*/items/*', 'orders.money'],
        ['DELETE', 'admin-api/orders/*/items/*', 'orders.money'],
        ['*', 'admin-api/manual-orders', 'orders.money'],
        ['*', 'admin-api/manual-orders/**', 'orders.money'],
        ['POST', 'admin-api/orders-bulk-status', 'orders.manage'],
        ['POST', 'admin-api/orders/*/notes', 'orders.manage'],
        ['POST', 'admin-api/orders/*/action', 'orders.manage'],
        // (Lane PU) the Billing / Shipping editor, the "Mark as paid" modal,
        // the customer change and its picker, and the "Order history" popup.
        // The address route moved off orders.manage onto orders.edit, which
        // holds the same three roles today and can be narrowed on its own.
        ['PUT', 'admin-api/orders/*/address', 'orders.edit'],
        ['POST', 'admin-api/orders/*/mark-paid', 'orders.payment'],
        ['PUT', 'admin-api/orders/*/customer', 'orders.customer'],
        ['GET', 'admin-api/order-customer-search', 'orders.customer'],
        ['GET', 'admin-api/orders/*/customer-orders', 'orders.view'],
        ['PUT', 'admin-api/orders/*/status', 'orders.manage'],
        ['GET', 'admin-api/orders', 'orders.view'],
        ['GET', 'admin-api/orders-list', 'orders.view'],
        ['GET', 'admin-api/orders/*/detail', 'orders.view'],
        ['GET', 'admin-api/orders/*', 'orders.view'],

        // --------------------------------------------------------------- customers
        // Above the '{id}' read below it, and the pair is pinned by a test:
        // /customers/export also matches 'admin-api/customers/*', and reading
        // one customer is support's job while downloading all of them is not.
        ['GET', 'admin-api/customers/export', 'customers.export'],
        // Send account invite (Lane PQ). Above every customer rule it could
        // overlap: every verb, every path under /invites, one capability. Nothing below can
        // match these paths today (`*` stops at a slash), but a later
        // `admin-api/customers/**` read rule would, and must not hand a
        // support account the send.
        ['*', 'admin-api/customers/invites/**', 'customers.invite'],
        ['POST', 'admin-api/customers/bulk-delete', 'customers.manage'],
        ['POST', 'admin-api/customers/*/note', 'customers.manage'],
        ['POST', 'admin-api/customers/*/restore', 'customers.manage'],
        ['DELETE', 'admin-api/customers/*', 'customers.manage'],
        ['GET', 'admin-api/customers', 'customers.view'],
        ['GET', 'admin-api/customers/list', 'customers.view'],
        ['GET', 'admin-api/customers/*', 'customers.view'],
        // Write rule before the read rule, or the wildcard GET below would be
        // reached first for the same path and a `support` account could move a
        // lead's status on a customers.view capability.
        ['PUT', 'admin-api/quiz-leads/*', 'customers.manage'],
        ['GET', 'admin-api/quiz-leads', 'customers.view'],

        // --------------------------------------------------------------- catalogue
        ['GET', 'admin-api/catalog-products-export', 'catalog.export'],
        ['GET', 'admin-api/catalog-products-list', 'catalog.view'],
        ['GET', 'admin-api/catalog-products-facets', 'catalog.view'],
        ['GET', 'admin-api/catalog-products-detail/*', 'catalog.view'],
        ['*', 'admin-api/catalog-products-*', 'catalog.manage'],
        ['*', 'admin-api/catalog-products-*/*', 'catalog.manage'],
        /*
         * Phase 10 — Build my routine (Lane FM). WRITES ABOVE READS, which is
         * the rule this block is a fresh instance of rather than a repetition
         * of: `admin-api/routines` and `admin-api/routines/{concern}` are
         * different patterns, but `admin-api/routine-products` and
         * `admin-api/routine-products/{id}` differ only by a segment, and a
         * GET rule written first would be reached for a POST to the second and
         * let an `editor` on catalog.view retag the whole catalogue.
         *
         * catalog.*, not store.settings, and the line is worth stating: what
         * these endpoints change is WHICH PRODUCT FILLS WHICH STEP and which
         * coupon the offer strip names — merchandising, the same job as the
         * category tree and the product editor beside them. The one control
         * that can put two new pages on the storefront is the module toggle on
         * Store → Modules, which is `admin-api/modules` above, store.settings,
         * and is not touched here.
         */
        /*
         * Lane Q, round 3 — and it is the ONE endpoint in this block that is
         * not catalog.*. It flips `build_my_routine`, which is what puts
         * /routines and /routines/{concern} on the storefront, so it sits with
         * the Modules screen's permission rather than with the tagging screen's.
         * First in the block so no broader `routines*` pattern below can be
         * reached for it.
         */
        ['POST', 'admin-api/routines-module', 'store.settings'],
        ['POST', 'admin-api/routines-settings', 'catalog.manage'],
        /*
         * Lane Q4 — bulk tagging. ABOVE the reads below it, which is the whole
         * of this block's stated ordering rule: `admin-api/routine-products`
         * (GET, catalog.view) and `admin-api/routine-products-bulk` are
         * different patterns, but a lane tidying this list into alphabetical
         * order would put the read first and hand an `editor` on catalog.view
         * a twenty-five-row retag of the catalogue. It is a write, and it sits
         * with the writes.
         *
         * catalog.manage, the same as the single-product POST above it, because
         * it is that endpoint applied to a set: the same two columns, the same
         * vocabulary, no new reach. AdminCapabilityMapTest pins the ordering
         * and RoutineTaggingBulkTest pins the refusal.
         */
        ['POST', 'admin-api/routine-products-bulk', 'catalog.manage'],
        ['POST', 'admin-api/routine-products/*', 'catalog.manage'],
        ['POST', 'admin-api/routines/*', 'catalog.manage'],
        ['GET', 'admin-api/routine-products', 'catalog.view'],
        ['GET', 'admin-api/routines', 'catalog.view'],
        ['GET', 'admin-api/catalog/**', 'catalog.view'],
        ['*', 'admin-api/catalog/**', 'catalog.manage'],
        // Lane RPL: Undo of a picture a product save took off the server. The
        // product edit capability, named on its own line; a GET is not a route.
        ['POST', 'admin-api/product-editor-photo-undo/*', 'catalog.manage'],
        ['GET', 'admin-api/product-editor-load/*', 'catalog.view'],
        ['GET', 'admin-api/product-editor-*', 'catalog.view'],
        ['*', 'admin-api/product-editor-*', 'catalog.manage'],
        ['*', 'admin-api/product-editor-*/*', 'catalog.manage'],
        // The product editor's per-admin panel arrangement is a preference row
        // keyed to the signed-in admin, not a shared setting: anybody who can
        // open the editor can arrange their own copy of it.
        ['*', 'admin-api/editor-layout', 'catalog.view'],
        ['*', 'admin-api/editor-layout-reset', 'catalog.view'],
        ['GET', 'admin-api/products', 'catalog.view'],
        ['GET', 'admin-api/products/*', 'catalog.view'],
        ['PUT', 'admin-api/products/*', 'catalog.manage'],
        ['POST', 'admin-api/inventory', 'catalog.manage'],

        /*
         * Catalog -> Sets (Lane SET). THE WRITES ABOVE THE READS, because RULES
         * is first-match-wins and a `GET admin-api/sets/**` rule listed first
         * would resolve PUT /sets/7 -- which reprices and republishes a live
         * product -- to `sets.view`. That is the shape of the quiz-leads and
         * coupons/manage mistakes this file names further down, and
         * AdminCapabilityMapTest pins pairs like this one by name.
         *
         * The exact 'admin-api/sets' lines are SIBLINGS of the '/**' ones and
         * neither shadows the other: '**' matches one or more whole segments,
         * so 'admin-api/sets/**' does not match the bare 'admin-api/sets'.
         *
         * GET admin-api/sets/products is the member picker's search and falls
         * correctly to `sets.view` through the read wildcard below it -- it
         * reads the catalogue, it writes nothing.
         *
         * ABOVE the catalogue block that follows, not below it: nothing there
         * matches 'admin-api/sets' today, and keeping these together is what
         * makes the pair readable as one decision.
         */
        ['POST', 'admin-api/sets', 'sets.manage'],
        ['POST', 'admin-api/sets/**', 'sets.manage'],
        ['PUT', 'admin-api/sets/**', 'sets.manage'],
        ['PATCH', 'admin-api/sets/**', 'sets.manage'],
        ['DELETE', 'admin-api/sets/**', 'sets.manage'],
        ['GET', 'admin-api/sets', 'sets.view'],
        ['GET', 'admin-api/sets/**', 'sets.view'],

        /*
         * Catalog -> Sets -> Stock (Lane SP). A SEPARATE PATH, not a child of
         * 'admin-api/sets', deliberately: '**' matches one or more whole
         * segments, so neither of the two lines above can ever claim
         * 'admin-api/set-stock' and this pair cannot be widened by a change to
         * them. Both verbs carry the same capability because reading which rule
         * is live is not sensitive; it is written next to its sibling so the
         * three Sets capabilities read as one decision.
         */
        ['GET', 'admin-api/set-stock', 'sets.stock'],
        ['POST', 'admin-api/set-stock', 'sets.stock'],

        /*
         * Catalog -> Product tabs (Lane PT). THE WRITES ABOVE THE READS,
         * because RULES is first-match-wins: a `GET admin-api/product-tabs/**`
         * rule listed first would resolve POST /product-tabs/product/7/override
         * -- which rewrites what a live product page says -- to
         * `producttabs.view`. Same shape as the Sets pair above it, and the
         * same shape as the quiz-leads and coupons/manage mistakes this file
         * names further down.
         *
         * A SEPARATE PATH FROM 'admin-api/products', deliberately. '*' never
         * crosses a slash and '**' matches whole segments, so neither the
         * catalogue rules below nor the sets rules above can ever claim
         * 'admin-api/product-tabs' -- the hyphen makes it a different segment,
         * not a child of 'admin-api/product'.
         *
         * GET admin-api/product-tabs/search is the product picker and falls
         * correctly to `producttabs.view` through the read wildcard: it reads
         * the catalogue by name and writes nothing.
         */
        ['POST', 'admin-api/product-tabs', 'producttabs.manage'],
        ['POST', 'admin-api/product-tabs/**', 'producttabs.manage'],
        ['PUT', 'admin-api/product-tabs/**', 'producttabs.manage'],
        ['PATCH', 'admin-api/product-tabs/**', 'producttabs.manage'],
        ['DELETE', 'admin-api/product-tabs/**', 'producttabs.manage'],
        ['GET', 'admin-api/product-tabs', 'producttabs.view'],
        ['GET', 'admin-api/product-tabs/**', 'producttabs.view'],

        // The redirect ledger sits under /categories/ but is a map of the
        // store's old URLs, so it is content rather than catalogue. Above the
        // categories rules because those would otherwise claim it.
        ['*', 'admin-api/categories/redirects', 'content.manage'],
        ['*', 'admin-api/categories/redirects/*', 'content.manage'],
        ['GET', 'admin-api/categories', 'catalog.view'],
        ['GET', 'admin-api/brands', 'catalog.view'],
        ['GET', 'admin-api/brands-tree', 'catalog.view'],
        ['GET', 'admin-api/attributes', 'catalog.view'],
        ['*', 'admin-api/categories', 'catalog.manage'],
        ['*', 'admin-api/categories/**', 'catalog.manage'],
        ['*', 'admin-api/brands', 'catalog.manage'],
        ['*', 'admin-api/brands/**', 'catalog.manage'],
        ['*', 'admin-api/brands-tree', 'catalog.manage'],
        ['*', 'admin-api/brands-tree/**', 'catalog.manage'],
        ['*', 'admin-api/attributes', 'catalog.manage'],
        ['*', 'admin-api/attributes/**', 'catalog.manage'],
        ['*', 'admin-api/product-labels', 'catalog.manage'],
        ['*', 'admin-api/bundles', 'catalog.manage'],

        // ----------------------------------------------------- content & appearance
        /*
         * Content -> Shoppable video (routes/ugc-admin.php).
         *
         * WRITES FIRST, READS SECOND, and RULES is first-match-wins. The two
         * '**' lines below cover different verbs over the SAME paths, so the
         * order between them is the whole of the access control: put the GET
         * line above the write lines and POST /ugc-videos/{id}/media — a 64 MB
         * upload into the web root — resolves to `ugc.view`. That is exactly
         * the quiz-leads and coupons/manage shape this file names elsewhere,
         * and it is written out rather than relied on because both of those
         * were found by a test and not by a reader.
         *
         * The exact 'admin-api/ugc-videos' lines are SIBLINGS of the '/**'
         * ones and neither shadows the other: 'admin-api/ugc-videos' does not
         * match 'admin-api/ugc-videos/7', and '/**' does not match the bare
         * path.
         *
         * GET admin-api/ugc-videos/products is a READ and correctly falls to
         * the read rule: it returns a name and an id per product and nothing
         * else. It is listed by name below its wildcard only for a reader's
         * benefit — it would resolve the same way without the line, and
         * AdminCapabilityMapTest pins that it resolves to ugc.view.
         */
        ['POST', 'admin-api/ugc-videos', 'ugc.manage'],
        ['POST', 'admin-api/ugc-videos/**', 'ugc.manage'],
        ['PUT', 'admin-api/ugc-videos/**', 'ugc.manage'],
        ['PATCH', 'admin-api/ugc-videos/**', 'ugc.manage'],
        ['DELETE', 'admin-api/ugc-videos/**', 'ugc.manage'],
        ['GET', 'admin-api/ugc-videos', 'ugc.view'],
        ['GET', 'admin-api/ugc-videos/**', 'ugc.view'],

        /*
         * Content -> Shoppable video -> Sections, and Appearance -> Shoppable
         * video (Lane V3). The SAME two capabilities, and the same
         * writes-above-reads order, for the same first-match-wins reason spelt
         * out above — POST admin-api/ugc-sections/7/videos reorders a rail on the
         * live shop, and a GET rule listed first would resolve it to ugc.view.
         *
         * NO THIRD CAPABILITY FOR THE APPEARANCE SCREEN, and that is a decision:
         * the look of the rail and the contents of the rail are the same job done
         * by the same person, and a `ugc.appearance` nobody would ever grant
         * separately is a capability that exists to be ticked. The split that IS
         * real is view/manage — the person who checks whether a rail is live is
         * not always the person who may replace a clip's file.
         */
        ['POST', 'admin-api/ugc-sections', 'ugc.manage'],
        ['POST', 'admin-api/ugc-sections/**', 'ugc.manage'],
        ['PUT', 'admin-api/ugc-sections/**', 'ugc.manage'],
        ['PATCH', 'admin-api/ugc-sections/**', 'ugc.manage'],
        ['DELETE', 'admin-api/ugc-sections/**', 'ugc.manage'],
        ['GET', 'admin-api/ugc-sections', 'ugc.view'],
        ['GET', 'admin-api/ugc-sections/**', 'ugc.view'],
        ['POST', 'admin-api/ugc-appearance', 'ugc.manage'],
        ['GET', 'admin-api/ugc-appearance', 'ugc.view'],

        // (Lane IGR) The eight admin-api/instagram rules went with the routes.

        /*
         * ── Appearance → Banners → Cards banner (Lane BN) ───────────────────
         *
         * THE WRITES ABOVE THE READS, for the reason the Instagram block above
         * states and this file's header states before it: RULES is
         * first-match-wins, and a `GET admin-api/banners/**` rule listed first
         * would resolve nothing dangerous today — every GET here really is a read
         * — but it would resolve the NEXT read-shaped write somebody adds, which
         * is exactly how `admin-api/instagram/start` would have been mapped to a
         * view capability. The order is the guard, not the current path list.
         *
         * `**` after each literal prefix rather than a rule per path: the paths
         * beneath `admin-api/banners/` are sets, cards and one preview, and every
         * one of them is the same grant. A path added there tomorrow is covered
         * by the same capability its siblings carry rather than falling through
         * to the closed owner-only default and 403ing a manager on a screen that
         * otherwise works — which is the failure mode AdminCapabilityMapTest
         * names by route.
         */
        ['POST', 'admin-api/banners', 'banners.manage'],
        ['POST', 'admin-api/banners/**', 'banners.manage'],
        ['PUT', 'admin-api/banners/**', 'banners.manage'],
        ['PATCH', 'admin-api/banners/**', 'banners.manage'],
        ['DELETE', 'admin-api/banners/**', 'banners.manage'],
        ['GET', 'admin-api/banners', 'banners.view'],
        ['GET', 'admin-api/banners/**', 'banners.view'],

        /*
         * ── Appearance → Grid sections (Lane GS) ────────────────────────────
         *
         * THE WRITES ABOVE THE READS, which is this file's rule and not a
         * preference — RULES is first-match-wins, and listed the other way
         * round `GET admin-api/grid-sections/**` would resolve every path in
         * that file and a read capability would be enough to DELETE an
         * instance. That is the same sentence routes/banners-admin.php's header
         * writes about its own block, and it is repeated because the failure it
         * describes is silent.
         *
         * `POST admin-api/grid-sections/**` covers the draft preview as well as
         * the writes. A preview is a POST here deliberately: its payload is a
         * draft of what the instance is about to become, and a reader who may
         * not write the instance has no business composing one.
         */
        ['POST', 'admin-api/grid-sections', 'gridsections.manage'],
        ['POST', 'admin-api/grid-sections/**', 'gridsections.manage'],
        ['PUT', 'admin-api/grid-sections/**', 'gridsections.manage'],
        ['PATCH', 'admin-api/grid-sections/**', 'gridsections.manage'],
        ['DELETE', 'admin-api/grid-sections/**', 'gridsections.manage'],
        ['GET', 'admin-api/grid-sections', 'gridsections.view'],
        ['GET', 'admin-api/grid-sections/**', 'gridsections.view'],
        // Lane HC. Exact paths, no wildcard: three reads, and a fourth path
        // added under this prefix without a line here is owner-only.
        ['POST', 'admin-api/homepage-hub/type', 'homepagehub.type'],
        ['GET', 'admin-api/homepage-hub/products', 'homepagehub.search'],
        ['POST', 'admin-api/homepage-hub/preview', 'homepagehub.search'],
        ['GET', 'admin-api/homepage-hub', 'homepagehub.view'],

        // Lane WP. ABOVE the media/** line, or that line would claim them.
        ['*', 'admin-api/media/webp', 'media.optimize'],
        ['*', 'admin-api/media/webp/**', 'media.optimize'],
        // Lane IR. Every path under the prefix; one added without a line here
        // is still this capability, never wider.
        ['*', 'admin-api/image-seo', 'media.image_seo'],
        ['*', 'admin-api/image-seo/**', 'media.image_seo'],
        ['*', 'admin-api/media', 'content.manage'],
        ['*', 'admin-api/media/**', 'content.manage'],
        ['*', 'admin-api/blocks', 'content.manage'],
        ['*', 'admin-api/blocks/*', 'content.manage'],
        ['GET', 'admin-api/pages/*', 'content.manage'],
        ['GET', 'admin-api/posts', 'content.manage'],
        /*
         * The article editor (Lane J, routes/post-editor-admin.php).
         *
         * BEFORE the `admin-api/posts` line above would be pointless — that
         * pattern has no wildcard and matches nothing here — but these sit
         * beside it so the Journal's read half and write half are read
         * together, and so nobody adds `admin-api/posts/**` above them one day
         * without seeing that the write side is a different capability.
         *
         * Flat paths rather than /posts/{id}: see the header of
         * routes/post-editor-admin.php for why an existing wildcard must not be
         * able to decide which controller a new path reaches.
         */
        ['GET', 'admin-api/post-editor-bootstrap', 'posts.manage'],
        ['GET', 'admin-api/post-editor-load/*', 'posts.manage'],
        ['POST', 'admin-api/post-editor-slug', 'posts.manage'],
        ['POST', 'admin-api/post-editor-create', 'posts.manage'],
        ['POST', 'admin-api/post-editor-save/*', 'posts.manage'],
        /*
         * The content page editor (Lane S9, routes/page-editor-admin.php).
         *
         * Beside the `admin-api/pages/*` line above for the same reason the
         * article editor sits beside `admin-api/posts`: the read half and the
         * write half of one screen are read together, and nobody widens
         * `admin-api/pages/**` onto content.manage one day without seeing that
         * the write side is a different capability.
         *
         * Flat paths rather than /pages/{id}: the existing `admin-api/pages/*`
         * rule would otherwise decide which capability a write reached, and
         * first-match-wins means that is settled by list order rather than by
         * intent. See the header of routes/page-editor-admin.php.
         *
         * There is no create and no delete entry because there are no such
         * routes — a content page's address is a literal route in web.php, so a
         * created page would be unreachable and a deleted one would 404 an
         * address the footer links to. PageEditorApiController's header has the
         * measurement.
         */
        ['GET', 'admin-api/page-editor-bootstrap', 'pages.manage'],
        ['GET', 'admin-api/page-editor-list', 'pages.manage'],
        ['GET', 'admin-api/page-editor-load/*', 'pages.manage'],
        ['POST', 'admin-api/page-editor-save/*', 'pages.manage'],
        ['*', 'admin-api/redirects', 'content.manage'],
        ['*', 'admin-api/redirects/**', 'content.manage'],
        ['*', 'admin-api/mega-menu', 'content.manage'],
        ['*', 'admin-api/mega-menu/**', 'content.manage'],
        ['*', 'admin-api/header', 'content.manage'],
        ['*', 'admin-api/mobile-header', 'content.manage'],
        ['*', 'admin-api/mobile-menu', 'content.manage'],
        ['*', 'admin-api/site-search', 'content.manage'],
        ['*', 'admin-api/homepage', 'content.manage'],
        ['*', 'admin-api/homepage/**', 'content.manage'],
        ['*', 'admin-api/layout', 'content.manage'],
        ['*', 'admin-api/dividers', 'content.manage'],
        ['*', 'admin-api/product-styles', 'content.manage'],
        ['*', 'admin-api/product-page', 'content.manage'],
        ['POST', 'admin-api/share-images', 'shareimages.make'],
        ['*', 'admin-api/cart-panel', 'content.manage'],

        /*
         * Appearance -> Cart page. A capability of its own, and NOT the
         * content.manage the line above it uses, although the three roles that
         * hold them are the same three as this ships.
         *
         * The reason is the one cache.manage gives a few screens up: mapping a
         * new surface onto an existing capability means that the day somebody
         * narrows THAT capability -- and content.manage is a reasonable thing
         * to narrow to the people who write blog posts -- this narrows with it,
         * silently, in a different file, with nothing to notice. The cart page
         * is the page a shopper checks out from; who may rearrange it is a
         * decision worth being able to make on its own.
         *
         * Not store.settings either. Nothing on this screen can take the
         * storefront down or redirect its mail, and making an editor an owner
         * to move a slider is how a capability system stops being used.
         *
         * The '/**' line is a SIBLING of the exact one above it and neither can
         * shadow the other, but both are needed: 'admin-api/cart-page' does not
         * match 'admin-api/cart-page/products'.
         */
        ['*', 'admin-api/cart-page', 'cartpage.manage'],
        ['*', 'admin-api/cart-page/**', 'cartpage.manage'],
        // One line and no '/**' sibling: this screen has no sub-endpoint.
        ['*', 'admin-api/checkout-page', 'checkoutpage.manage'],
        /*
         * Appearance → Set. The '/**' sibling IS needed here: the preview is
         * 'admin-api/set-appearance/preview', which the exact line above it
         * does not match — and an endpoint that renders a Blade from a POST
         * body left outside the map would be reachable by any signed-in admin
         * whatever their role.                                       (Lane SA)
         */
        ['*', 'admin-api/set-appearance', 'setappearance.manage'],
        ['*', 'admin-api/set-appearance/**', 'setappearance.manage'],
        // The preview BEFORE the screen's own line, and as an exact path: the
        // line below it matches 'admin-api/slim-footer' and nothing under it,
        // so without this the preview would be off the map. (Lane FT)
        ['*', 'admin-api/slim-footer/preview', 'footer.preview'],
        ['*', 'admin-api/slim-footer', 'slimfooter.manage'],
        // Appearance → #KBeautyBliss Spotted (Lane HB). Both lines: the screen's
        // read is the bare path and every write is under it, and a write left
        // off the map would be owner-only rather than open -- but the editor
        // the owner gave this screen to would be refused for no reason.
        ['*', 'admin-api/spotted', 'spotted.manage'],
        ['*', 'admin-api/spotted/**', 'spotted.manage'],
        // Content → Instagram embeds (Lane IGE). Both lines: '/**' does not
        // match the bare path, and the screen's read IS the bare path.
        ['*', 'admin-api/ig-embeds', 'igembeds.manage'],
        ['*', 'admin-api/ig-embeds/**', 'igembeds.manage'],
        // One line and no '/**' sibling: this screen has no sub-endpoint.
        ['*', 'admin-api/site-layout', 'sitelayout.manage'],
        // One line and no '/**' sibling: this screen has no sub-endpoint either.
        ['*', 'admin-api/page-wash', 'pagewash.manage'],
        // One line and no '/**' sibling: no sub-endpoint here either. (Lane WA)
        ['*', 'admin-api/whatsapp-button', 'wabutton.manage'],
        // Appearance -> Coming Soon page (Lane CS). Both lines: `/**` does not match the bare prefix.
        ['*', 'admin-api/coming-soon', 'comingsoon.manage'],
        ['*', 'admin-api/coming-soon/**', 'comingsoon.manage'],
        // The screen's read and save (Lane PW), and its icon upload (Lane IC).
        ['*', 'admin-api/site-app', 'siteapp.manage'],
        ['*', 'admin-api/site-app/**', 'siteapp.manage'],
        // One line and no '/**' sibling: no sub-endpoint. (Lane SS)
        ['*', 'admin-api/page-banners', 'pagebanners.manage'],
        // 2.60.388: copy /super-sale/'s order from the old site (one fixed URL).
        ['POST', 'admin-api/page-banners/super-sale-order', 'pagebanners.manage'],
        // The screen's read and save, and the storefront panel's apply. (Lane PH)
        ['*', 'admin-api/page-header', 'pageheader.manage'],
        ['*', 'admin-api/page-header/**', 'pageheader.manage'],
        // The category page's "Edit header" panel: preview and save. (Lane CH)
        ['*', 'admin-api/category-header/**', 'categoryheader.manage'],
        // Catalog → Pagination: the read and the save. One line and no '/**'
        // sibling: the screen has no sub-endpoint. (Lane PG)
        ['*', 'admin-api/pagination', 'pagination.manage'],
        // Safety → 404 page: the read and the save. One line and no '/**'
        // sibling: the screen has no sub-endpoint. (Lane NF)
        ['*', 'admin-api/not-found-page', 'notfoundpage.manage'],
        // Store → Inquiries (Lane CT). The settings line first, so the
        // one-segment wildcard below can never be what answers for it.
        ['*', 'admin-api/inquiries/settings', 'inquiries.manage'],
        ['DELETE', 'admin-api/inquiries/*', 'inquiries.manage'],
        ['POST', 'admin-api/inquiries/*/read', 'inquiries.view'],
        ['GET', 'admin-api/inquiries', 'inquiries.view'],
        ['*', 'admin-api/account-panel', 'content.manage'],
        // Exact, so it cannot reach the /demo-content/ endpoints mapped to
        // data.import further up.
        ['*', 'admin-api/demo', 'content.manage'],

        // ----------------------------------------------------------------- reviews
        // Both exports carry author_email; both sit above the screen rules that
        // would otherwise hand them to an editor.
        ['GET', 'admin-api/reviews/export', 'reviews.export'],
        ['GET', 'admin-api/reviews-io/export', 'reviews.export'],
        ['*', 'admin-api/reviews-io/**', 'reviews.manage'],
        ['*', 'admin-api/review-settings', 'reviews.manage'],
        ['*', 'admin-api/review-bulk/**', 'reviews.manage'],
        ['*', 'admin-api/review-badges', 'reviews.manage'],
        ['*', 'admin-api/review-badges/**', 'reviews.manage'],
        ['*', 'admin-api/review-assign/**', 'reviews.manage'],
        ['POST', 'admin-api/reviews/bulk', 'reviews.moderate'],
        ['POST', 'admin-api/reviews/bulk-moderate', 'reviews.moderate'],
        ['PUT', 'admin-api/reviews/*/moderate', 'reviews.moderate'],
        ['PUT', 'admin-api/reviews/*', 'reviews.moderate'],
        ['GET', 'admin-api/reviews', 'reviews.view'],
        ['GET', 'admin-api/reviews/list', 'reviews.view'],
        ['GET', 'admin-api/reviews/*', 'reviews.view'],

        // ------------------------------------------------------------ translation
        /*
         * The Translation console. Split across two capabilities on purpose,
         * and the split is about money and about what a switch publishes — not
         * about who is trusted to type Arabic.
         *
         * OWNER ONLY (store.settings), because these two are levers rather than
         * content:
         *
         *   POST /translations/settings    writes the Google API key, and the
         *                                  master switch that makes /ar exist
         *                                  for every shopper at once. Same
         *                                  class as the mail credentials
         *                                  mapped below.
         *   POST /translations/machine/run a batch run billed per character to
         *                                  the owner's own Google Cloud
         *                                  account. Whoever can press it can
         *                                  spend his money.
         *
         * EDITOR AND UP (content.manage) for the rest, because translating a
         * product is an editor's job and a console they cannot use is a console
         * the owner ends up doing alone:
         *
         *   POST /translations             writes the text itself
         *   POST /translations/publish     the same text, made visible
         *   POST /translations/machine/field
         *                                  one field at a time, beside the box
         *                                  someone is typing in. It does spend
         *                                  money, which is why it is not simply
         *                                  lumped in with the reads — but it is
         *                                  one field, it stores nothing, and it
         *                                  is throttled at the route. An editor
         *                                  who cannot press it cannot use the
         *                                  Arabic boxes at all, which is the
         *                                  whole feature.
         *   GET  /translations/settings    safe for an editor to read: it
         *                                  answers `has_api_key` as a boolean
         *                                  and never returns the key.
         *
         * WRITES ARE LISTED FIRST, as this map requires. A single
         * `['*', 'admin-api/translations/**', 'content.manage']` placed above
         * these would match POST /translations/machine/run and hand an editor
         * the owner's Google bill.
         */
        ['POST', 'admin-api/translations/settings', 'store.settings'],
        ['POST', 'admin-api/translations/machine/run', 'store.settings'],
        ['POST', 'admin-api/translations/machine/field', 'content.manage'],
        ['POST', 'admin-api/translations/publish', 'content.manage'],
        ['POST', 'admin-api/translations', 'content.manage'],
        ['GET', 'admin-api/translations/**', 'content.manage'],
        ['GET', 'admin-api/translations', 'content.manage'],

        // --------------------------------------------------------------- marketing
        /*
         * Back-in-stock alerts and basket reminders.
         *
         * WRITE FIRST, as this map requires: `sweep` is the button that
         * actually sends mail to real customers, so it is marketing.manage and
         * it is listed above the reads. A `['*', 'admin-api/outbound/**']` read
         * rule placed first would match POST /outbound/sweep and hand the send
         * to anyone who could merely look at the list.
         *
         * The reads are marketing.view rather than a catalogue capability even
         * though `demand` looks like a stock report, because what it returns is
         * every address that has asked this shop for a product — a list of real
         * customers' email addresses, which is the same thing the newsletter
         * export beside it is separated out for. Whoever can read it can take
         * the shop's marketing list with them.
         */
        /*
         * Push Notifications (Lane PN), every route in routes/push-admin.php:
         * the GETs and the read-only live count are push.view, everything else
         * under the prefix push.send. Fails closed: a verb not listed is send.
         */
        ['GET', 'admin-api/push', 'push.view'],
        ['GET', 'admin-api/push/**', 'push.view'],
        ['POST', 'admin-api/push/count', 'push.view'],
        ['*', 'admin-api/push', 'push.send'],
        ['*', 'admin-api/push/**', 'push.send'],
        // Google Shopping feed (Lane SEO). Both lines: `/**` does not match the bare prefix.
        ['*', 'admin-api/merchant-feed', 'marketing.feed'],
        ['*', 'admin-api/merchant-feed/**', 'marketing.feed'],
        /*
         * Marketing Emails (Lane MK), every route in routes/marketing-emails-
         * admin.php. SEND FIRST, then the export, then the two POSTs that only
         * read (the live count and "See the N" carry the rules in the body),
         * then every GET as a read, then everything else as manage. First match
         * wins, so each narrower rule sits above the wildcard that would
         * otherwise claim it — the outbound/sweep lesson below.
         * MarketingEmailsCapabilityTest walks the routes and holds each one.
         */
        ['POST', 'admin-api/email-marketing/campaigns/*/send', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/schedule', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/unschedule', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/step', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/pause', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/resume', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/campaigns/*/cancel', 'marketing.email.send'],
        ['POST', 'admin-api/email-marketing/limits', 'marketing.email.send'],
        ['GET', 'admin-api/email-marketing/groups/*/export', 'marketing.export'],
        ['POST', 'admin-api/email-marketing/groups/count', 'marketing.email.view'],
        ['POST', 'admin-api/email-marketing/groups/people', 'marketing.email.view'],
        ['GET', 'admin-api/email-marketing/**', 'marketing.email.view'],
        ['*', 'admin-api/email-marketing/**', 'marketing.email.manage'],
        ['POST', 'admin-api/outbound/sweep', 'marketing.manage'],
        ['GET', 'admin-api/outbound/**', 'marketing.view'],
        ['GET', 'admin-api/newsletter/export', 'marketing.export'],
        ['*', 'admin-api/newsletter', 'marketing.manage'],
        // Marketing Pixels (Lane MP). Custom code FIRST: `/**` below would
        // otherwise match it and hand raw shop script to a manager.
        ['*', 'admin-api/marketing-pixels/custom-code', 'marketing.customcode'],
        ['*', 'admin-api/marketing-pixels/custom-code/**', 'marketing.customcode'],
        ['*', 'admin-api/marketing-pixels/**', 'marketing.pixels.connect'],
        ['*', 'admin-api/marketing-pixels', 'marketing.manage'],
        /*
         * Reading a coupon, its usage report and the product/category lookup
         * the editor searches with are all marketing.view. WRITING one is
         * marketing.manage, because a coupon is money: whoever can create
         * "100% off, no minimum" can empty the shop.
         *
         * The write rules come FIRST. Rule order decides among matches, and
         * `admin-api/coupons/*` would otherwise swallow
         * `POST admin-api/coupons/manage` — a read capability granted over a
         * create endpoint. The manage/* pair is likewise listed above the bare
         * coupons/* so the {coupon} routes under it cannot fall through to the
         * read rule.
         *
         * These arrived with the coupon editor, which was branched before the
         * capability layer existed. The coverage test caught them unmapped and
         * failing closed — owner-only — which is the default doing its job,
         * but owner-only is not the answer for a screen a manager runs
         * promotions from.
         */
        ['POST', 'admin-api/coupons/manage', 'marketing.manage'],
        ['PUT', 'admin-api/coupons/manage/*', 'marketing.manage'],
        ['DELETE', 'admin-api/coupons/manage/*', 'marketing.manage'],
        ['GET', 'admin-api/coupons/manage/lookup', 'marketing.view'],
        ['GET', 'admin-api/coupons/manage/*', 'marketing.view'],
        ['GET', 'admin-api/coupons/manage', 'marketing.view'],
        ['GET', 'admin-api/coupons', 'marketing.view'],
        ['GET', 'admin-api/coupons/*', 'marketing.view'],

        // --------------------------------------------------------------- dashboard
        /*
         * The storefront admin layer -- routes/storefront-admin.php (Lane RA).
         *
         * The CONTEXT read is mapped to `admin.access`, the capability every
         * back-office role holds, and that is deliberate rather than loose: it
         * is the one endpoint that answers "which of the two may this account
         * use here?", so it has to be reachable by an account that holds either.
         * The controller then answers 403 to an account that holds NEITHER,
         * and returns the bar only with storefront.adminbar and the editable
         * fields only with storefront.quick_edit. Today both are owner-only,
         * so every other role gets the 403.
         *
         * The two writes -- the preview and the save -- carry the pencil's own
         * capability. The save also changes what every shopper sees.
         */
        ['GET', 'admin-api/storefront/context', 'admin.access'],
        ['POST', 'admin-api/storefront/quick-edit/*/*/preview', 'storefront.quick_edit'],
        ['POST', 'admin-api/storefront/quick-edit/*/*', 'storefront.quick_edit'],

        ['GET', 'admin-api/stats', 'dashboard.view'],
        ['GET', 'admin-api/analytics', 'analytics.view'],
        // Analytics (Lane AN): the board, its live slice and its settings.
        // Exact paths: nothing else under site-analytics/ is reachable.
        ['GET', 'admin-api/site-analytics', 'analytics.view'],
        ['GET', 'admin-api/site-analytics/live', 'analytics.view'],
        ['GET', 'admin-api/site-analytics/settings', 'analytics.view'],
        ['POST', 'admin-api/site-analytics/settings', 'analytics.manage'],
        ['GET', 'admin-api/search-terms', 'search_terms.view'],

        // (Lane CT) Cart Tracking. Every GET reads; every other verb writes.
        // The '**' rule cannot reach anything outside cart-tracking/.
        ['GET', 'admin-api/cart-tracking', 'carttracking.view'],
        ['GET', 'admin-api/cart-tracking/**', 'carttracking.view'],
        ['*', 'admin-api/cart-tracking/**', 'carttracking.block'],
        /*
         * Store -> SEO Keywords -- routes/seo-keywords-admin.php, Lane KW.
         * Reads first, then every other verb on the same prefix: the order is
         * the rule, because the first match wins.
         */
        ['GET', 'admin-api/seo-keywords', 'seo_keywords.view'],
        ['GET', 'admin-api/seo-keywords/**', 'seo_keywords.view'],
        ['*', 'admin-api/seo-keywords/**', 'seo_keywords.sync'],
        // Store -> SEO Keywords -> Brand (Lane BR). One read, every other verb writes.
        ['GET', 'admin-api/seo-brand', 'seo_brand.view'],
        ['*', 'admin-api/seo-brand/**', 'seo_brand.manage'],
    ];

    /**
     * The capability a route requires, or null when the map does not know it.
     *
     * Null is not "allowed". EnforceAdminCapability reads a null as owner-only.
     */
    public static function for(Route $route): ?string
    {
        $name = $route->getName();

        if ($name !== null && isset(self::ROUTE_NAMES[$name])) {
            return self::ROUTE_NAMES[$name];
        }

        return self::forPath(self::primaryMethod($route), $route->uri());
    }

    /**
     * The same lookup against a bare method and URI, for tests and for callers
     * that hold no Route object.
     */
    public static function forPath(string $method, string $uri): ?string
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $uri = trim($uri, '/');

        foreach (self::RULES as [$ruleMethod, $pattern, $capability]) {
            if ($ruleMethod !== '*' && $ruleMethod !== $method) {
                continue;
            }

            if (self::matches($pattern, $uri)) {
                return $capability;
            }
        }

        return null;
    }

    /** Does this role hold this capability? Unknown role or capability: no. */
    public static function roleCan(?string $role, ?string $capability): bool
    {
        $role = self::canonicalRole($role);

        if ($role === null || $capability === null) {
            return false;
        }

        return in_array($role, self::CAPABILITIES[$capability] ?? [], true);
    }

    /** Every capability a role holds, in map order. For the console to render with. */
    public static function forRole(?string $role): array
    {
        $role = self::canonicalRole($role);

        if ($role === null) {
            return [];
        }

        return array_values(array_keys(array_filter(
            self::CAPABILITIES,
            fn (array $roles) => in_array($role, $roles, true)
        )));
    }

    /**
     * Resolve a stored role string to one this map knows, or null.
     *
     * Null is the closed answer, and the only accounts it can apply to are ones
     * carrying a role outside both ROLES and ROLE_ALIASES. The column is NOT
     * NULL DEFAULT 'owner' and AdminController validates every write against
     * ROLES, so reaching null takes a hand-edited database row.
     */
    public static function canonicalRole(?string $role): ?string
    {
        $role = strtolower(trim((string) $role));
        $role = self::ROLE_ALIASES[$role] ?? $role;

        return in_array($role, self::ROLES, true) ? $role : null;
    }

    /**
     * The verb to match a route on.
     *
     * Laravel registers GET routes as GET|HEAD and adds OPTIONS to everything,
     * so the raw list is never one value. HEAD and OPTIONS are dropped; the
     * first of what is left is the verb the route was declared with.
     */
    private static function primaryMethod(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        return $methods[0] ?? 'GET';
    }

    /**
     * Glob where '*' stops at a slash and '**' does not.
     *
     * preg_quote first, so every other character in a pattern is literal — the
     * '{id}' in a route URI contains regex metacharacters and must not be read
     * as a quantifier.
     */
    private static function matches(string $pattern, string $subject): bool
    {
        $regex = preg_quote($pattern, '#');
        // preg_quote turns '*' into '\*', so the placeholders are matched in
        // their escaped form — and the double must be replaced before the
        // single, or '**' is eaten as two singles and stops crossing slashes.
        $regex = str_replace('\*\*', '@@DOUBLE@@', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('@@DOUBLE@@', '.+', $regex);

        return (bool) preg_match('#^'.$regex.'$#', $subject);
    }
}
