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
 * migrated onto a shared host with no shell, and would still answer the same
 * question this array answers. When roles genuinely need to be editable, this
 * file is the thing that grows a database backing — until then it is the whole
 * feature, readable in one screen, and diffable in a package.
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

        // Orders. Split four ways because reading an order, editing one,
        // moving money and destroying one are genuinely different acts.
        'orders.view' => ['owner', 'manager', 'support'],
        'orders.manage' => ['owner', 'manager', 'support'],
        'orders.money' => ['owner', 'manager'],
        'orders.delete' => ['owner', 'manager'],
        'orders.export' => ['owner', 'manager'],
        'invoices.view' => ['owner', 'manager', 'support'],

        // Customers. An invoice carries one person's address; the export
        // carries everybody's, which is why it is a capability of its own.
        'customers.view' => ['owner', 'manager', 'support'],
        'customers.manage' => ['owner', 'manager'],
        'customers.export' => ['owner', 'manager'],

        // Catalogue and storefront content.
        'catalog.view' => ['owner', 'manager', 'editor'],
        'catalog.manage' => ['owner', 'manager', 'editor'],
        'catalog.export' => ['owner', 'manager', 'editor'],
        'content.manage' => ['owner', 'manager', 'editor'],

        // The cart page's own appearance — row density, the recommended rail
        // and which products fill it, the summary wording and the two docked
        // bars. Storefront appearance, so the same three roles as
        // content.manage; its own capability so it can be moved on its own.
        // The RULES entry below carries the full argument.
        'cartpage.manage' => ['owner', 'manager', 'editor'],

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
        'store.settings' => ['owner'],
        'cache.manage' => ['owner'],
        'payments.manage' => ['owner'],
        'users.manage' => ['owner'],
        'updates.manage' => ['owner'],
        'system.diagnostics' => ['owner'],
        'data.import' => ['owner'],
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
        ['GET', 'admin-api/orders/*/settlement', 'orders.money'],
        ['POST', 'admin-api/orders/*/capture', 'orders.money'],
        ['POST', 'admin-api/orders/*/refund', 'orders.money'],

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
        ['*', 'admin-api/ecommerce', 'store.settings'],
        ['*', 'admin-api/modules', 'store.settings'],
        ['*', 'admin-api/mail', 'store.settings'],
        ['*', 'admin-api/mail/**', 'store.settings'],
        ['*', 'admin-api/shipping', 'store.shipping'],
        ['*', 'admin-api/extended-delivery', 'store.shipping'],
        ['*', 'admin-api/pay-ship-rules', 'store.shipping'],

        // ------------------------------------------------------------- diagnostics
        ['GET', 'admin-api/schema-inspect', 'system.diagnostics'],
        ['GET', 'admin-api/catalogue-audit', 'system.diagnostics'],
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
        ['*', 'admin-api/urls-media/**', 'data.import'],
        ['*', 'admin-api/demo-content', 'data.import'],
        ['*', 'admin-api/demo-content/**', 'data.import'],

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
        ['PUT', 'admin-api/orders/*/address', 'orders.manage'],
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
        ['POST', 'admin-api/routines-settings', 'catalog.manage'],
        ['POST', 'admin-api/routine-products/*', 'catalog.manage'],
        ['POST', 'admin-api/routines/*', 'catalog.manage'],
        ['GET', 'admin-api/routine-products', 'catalog.view'],
        ['GET', 'admin-api/routines', 'catalog.view'],
        ['GET', 'admin-api/catalog/**', 'catalog.view'],
        ['*', 'admin-api/catalog/**', 'catalog.manage'],
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
        ['*', 'admin-api/media', 'content.manage'],
        ['*', 'admin-api/media/**', 'content.manage'],
        ['*', 'admin-api/blocks', 'content.manage'],
        ['*', 'admin-api/blocks/*', 'content.manage'],
        ['GET', 'admin-api/pages/*', 'content.manage'],
        ['GET', 'admin-api/posts', 'content.manage'],
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
        ['POST', 'admin-api/outbound/sweep', 'marketing.manage'],
        ['GET', 'admin-api/outbound/**', 'marketing.view'],
        ['GET', 'admin-api/newsletter/export', 'marketing.export'],
        ['*', 'admin-api/newsletter', 'marketing.manage'],
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
        ['GET', 'admin-api/stats', 'dashboard.view'],
        ['GET', 'admin-api/analytics', 'analytics.view'],
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
