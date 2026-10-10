<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;

/**
 * The admin console's sidebar: every group and every row, in the order the
 * owner sees them. ONE definition, read by two consumers. Lane AP.
 *
 * ── WHAT THE OWNER REPORTED ───────────────────────────────────────────────
 *
 * "upon hard refresh some menu items from the left panel keeps missing and
 * page loading not complete and delayed ... must load everything instantly
 * with all menu items."
 *
 * The sidebar used to be BUILT BY JAVASCRIPT: an empty <nav id="nav"> in the
 * markup, and buildNav() filling it from a `const NAV` literal at the foot of
 * the console's first script block -- 1.36 s into a throttled cold load (4x
 * CPU, 2 Mbit/s), with nothing at all in the sidebar before it. One row,
 * Appearance -> #KBeautyBliss Spotted, was in neither NAV nor LATE_NAV and
 * arrived with its partial at 5.96 s, at DOMContentLoaded.
 *
 * So the sidebar is now SERVER-RENDERED from this class into the first bytes
 * of the document, complete: html() prints it in place of the empty <nav>, and
 * the browser paints it with the shell, before any script has been parsed.
 * buildNav() binds the markup that is already there instead of drawing it, and
 * window.kbbAddNavEntry() -- still what every screen partial calls -- finds its
 * row already present and returns it, adding nothing.
 *
 * ── THE ORDER HERE IS THE SIDEBAR'S ORDER ─────────────────────────────────
 *
 * Rows are listed in their SETTLED position: where the console placed them
 * once buildNav() and every kbbAddNavEntry() had run, captured from Chromium
 * before this change (storage/ap-logs/settled-before-owner.json, produced by
 * tools/ap-settled.cjs). The old `after:` anchors decided that position at run
 * time, which is why LATE_NAV could not be sorted; here the position is the
 * position, and AdminNavTest pins the whole list.
 *
 * Notes carried over from the NAV literal this replaces:
 *   - Catalog is a real group although it was declared with one row: the
 *     product editor and Categories & Brands open `.nav-group[data-sec=
 *     "Catalog"]`, which must exist.
 *   - 'blog' has no row: it and 'posts' opened the same screen. The id still
 *     routes (TITLES), so #blog reaches Blog Posts.
 *   - 'rev-capsule' has no row: it edited the same settings as 'rev-badge'.
 *   - Overview, Storefront and Core Updates are `flat`: a one-row section is a
 *     bare link with no group header. Core Updates is `pinned` to the foot of
 *     the sidebar, above the Console button.
 *   - `late` marks a row whose RENDERER ships in a screen partial near the end
 *     of the document. kbbNavClick() replays a click on one of those that
 *     lands before the partial has been parsed.
 *   - `pending` (a sentence) marks a row whose partial has not merged yet. The
 *     guards that pair every `late` row with its partial skip it, and a click
 *     on it says "not installed yet" instead of drawing the dashboard. Delete
 *     the key when the partial lands.
 *
 * ── HOW A LANE ADDS A ROW OR A GROUP FROM NOW ON ──────────────────────────
 *
 *   A row: one line in GROUPS, in the group and at the position the owner
 *   should see it, with `read` (the GET its screen makes first) or `cap`, its
 *   label and icon, and `late` => true when a partial draws it. The partial
 *   may still call kbbAddNavEntry() -- it returns this row -- and its label and
 *   group must match (AdminSidebarIsCompleteAtBuildTest compares them).
 *   A group: one ['sec' => ..., 'rows' => [...]] entry, where it should sit.
 *   Then update the settled-order pin in AdminSidebarIsCompleteAtBuildTest.
 *   Nothing in app.blade.php changes for either.
 *
 * ── WHO SEES WHICH ROW ────────────────────────────────────────────────────
 *
 * A role sees a row only when it may OPEN the screen. Every row names the
 * read its screen makes first when it opens (`read`, found by clicking every
 * row and logging its requests -- tools/ap-crawl.cjs), and the row is shown
 * when AdminCapabilities maps that GET to a capability the account holds. The
 * route map stays the one place that decides access; a rule moved there moves
 * the sidebar with it. The seven screens that read nothing when they open
 * name their capability directly (`cap`).
 *
 * It FAILS CLOSED: a row whose read the map does not know, or a row with
 * neither key, resolves to null, and AdminRoles::can() answers null with no
 * -- so only a Full Admin sees it, exactly as EnforceAdminCapability would
 * answer the request behind it. An owner short-circuits and sees every row,
 * which is what the console showed every account before this class existed.
 *
 * Everything printed from here is a code constant or passes through e(); no
 * setting and no request value reaches the markup. The `go` query value is
 * only ever COMPARED against these ids, never printed.
 */
final class AdminNav
{
    /** The icons NAV shared with the console's `I` map. */
    public const I = [
        'catalog' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'cust' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'dash' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'debug' => '<rect x="5" y="8" width="14" height="12" rx="6"/><path d="M9 8V6a3 3 0 0 1 6 0v2M3 13h2M19 13h2M4 18l2-1M20 18l-2-1M4 8l2 1M20 8l-2 1"/>',
        'orders' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
        'sandbox' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7.7 1.6 1.6 0 0 0-1.6 1.3H12a2 2 0 0 1-2-2 1.6 1.6 0 0 0-2.7-1.1 1.6 1.6 0 0 1-1.8.3 2 2 0 1 1-2.8-2.8 1.6 1.6 0 0 0 .7-2.7 1.6 1.6 0 0 0-1.3-1.6V12a2 2 0 0 1 2-2 1.6 1.6 0 0 0 1.1-2.7 1.6 1.6 0 0 1 .3-1.8 2 2 0 1 1 2.8-2.8 1.6 1.6 0 0 0 2.7.7H12a2 2 0 0 1 2 2 1.6 1.6 0 0 0 2.7 1.1 1.6 1.6 0 0 1 1.8-.3 2 2 0 1 1 2.8 2.8 1.6 1.6 0 0 0-.7 2.7 1.6 1.6 0 0 0 1.3 1.6V12a2 2 0 0 1-2 2 1.6 1.6 0 0 0-1.3.9z"/>',
        'theme' => '<circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><path d="M12 22a10 10 0 1 1 9-14c0 3-3 3-5 3s-3 2-2 4 1 3-2 3z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 0 0 0-6M19.5 20a5.5 5.5 0 0 0-3-5"/>',    ];

    /**
     * The sidebar, top to bottom. See the class docblock for the keys.
     *
     * @var list<array{sec: string, flat?: bool, pinned?: bool, rows: list<array<string, mixed>>}>
     */
    public const GROUPS = [
        ['sec' => 'Overview', 'flat' => true, 'rows' => [
            ['id' => 'dash', 'label' => 'Dashboard', 'read' => 'admin-api/stats', 'icon' => self::I['dash']],
            // Analytics (Lane AN): live visitors, pages, sources, orders by source.
            // Its own top-level row under Dashboard, as the owner asked; a
            // partial draws it (admin.partials.site-analytics-screen).
            ['id' => 'site-analytics', 'label' => 'Analytics', 'read' => 'admin-api/site-analytics', 'late' => true, 'icon' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>'],
            // Cart Tracking: moved here from Growth & Marketing (Lane QK10) -- the
            // owner, 9 October: "bring the Cart tracking page to the top third of
            // the left panel menu, so i can access it instantly". Third top-level
            // row, under Analytics. Same id, read and capability as before.
            ['id' => 'carttracking', 'label' => 'Cart Tracking', 'read' => 'admin-api/cart-tracking', 'late' => true, 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M6 6 5 3H2"/><path d="m11 10 2 2 3-3"/>'],
        ]],
        ['sec' => 'Platform', 'rows' => [
            ['id' => 'theme', 'label' => 'K-Beauty Bliss Theme', 'cap' => 'store.settings', 'icon' => self::I['theme']],
            ['id' => 'users', 'label' => 'Users & Roles', 'read' => 'admin-api/roles/members', 'icon' => self::I['users']],
            ['id' => 'settings', 'label' => 'Settings', 'cap' => 'store.settings', 'icon' => self::I['settings']],
            ['id' => 'siteaddr', 'label' => 'Site address', 'read' => 'admin-api/site-address', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/>'],
            // Platform -> Domain switch (Lane DW): the move to kbeautybliss.com, step by step.
            ['id' => 'domainswitch', 'label' => 'Domain switch', 'read' => 'admin-api/domain-switch', 'late' => true, 'icon' => '<path d="M4 7h13l-3-3"/><path d="M20 17H7l3 3"/><circle cx="19" cy="7" r="1.6"/><circle cx="5" cy="17" r="1.6"/>'],
            ['id' => 'cache', 'label' => 'Cache', 'read' => 'admin-api/cache', 'late' => true, 'icon' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>'],
        ]],
        // App (the owner, via the integrator, 5 October): the shop's own app and
        // the owner's app, after Platform. Both screens are drawn by partials
        // that arrive with their lanes (`pending`); until then a click shows
        // "not installed yet" rather than the dashboard (kbbNavClick).
        ['sec' => 'App', 'rows' => [
            ['id' => 'siteapp', 'label' => 'Site App', 'cap' => 'siteapp.manage', 'icon' => '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/><path d="M10 9l2 2 2-2"/><path d="M12 6v5"/>'],
            ['id' => 'ownerapp', 'label' => 'Owner App', 'cap' => 'ownerapp.manage', 'late' => true, 'icon' => '<rect x="6" y="2" width="12" height="20" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M8.5 16.5a3.5 3.5 0 0 1 7 0"/>'],
        ]],
        ['sec' => 'Safety', 'rows' => [
            ['id' => 'debug', 'label' => 'Debug & Monitor', 'cap' => 'system.diagnostics', 'icon' => self::I['debug']],
            ['id' => 'sandbox', 'label' => 'Sandbox & Deploy', 'cap' => 'updates.manage', 'icon' => self::I['sandbox']],
            ['id' => 'democontent', 'label' => 'Demo Content', 'read' => 'admin-api/demo-content', 'icon' => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.3"/>'],
            ['id' => 'notfoundpage', 'label' => '404 page', 'cap' => 'notfoundpage.manage', 'late' => true, 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M8.5 16c1.2-1 2.3-1 3.5 0s2.3 1 3.5 0"/><path d="M13 3l-2 4 2 2-1 3"/>'],
        ]],
        ['sec' => 'Catalog', 'rows' => [
            ['id' => 'catalog', 'label' => 'Catalog', 'read' => 'admin-api/catalog-products-list', 'icon' => self::I['catalog']],
            ['id' => 'product-tabs', 'label' => 'Product tabs', 'read' => 'admin-api/product-tabs', 'late' => true, 'icon' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M8 7V4"/><path d="M14 7V4"/>'],
            ['id' => 'sets', 'label' => 'Sets', 'read' => 'admin-api/sets', 'late' => true, 'icon' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18"/><path d="M12 7V4"/><path d="M8 4h8"/>'],
            ['id' => 'product-editor', 'label' => 'Product editor', 'read' => 'admin-api/editor-layout', 'late' => true, 'icon' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'],
            // Catalog -> Image SEO (Lane IR): rename product pictures and bulk alt text.
            ['id' => 'imageseo', 'label' => 'Image SEO', 'read' => 'admin-api/image-seo', 'late' => true, 'icon' => '<rect x="3" y="3" width="14" height="14" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m17 12-4-4-8 8"/><circle cx="17.5" cy="17.5" r="3"/><path d="m22 22-2.3-2.3"/>'],
            ['id' => 'routines', 'label' => 'Build my routine', 'read' => 'admin-api/routines', 'late' => true, 'icon' => '<path d="M4 6h10"/><path d="M4 12h16"/><path d="M4 18h7"/><circle cx="18" cy="6" r="2"/><circle cx="15" cy="18" r="2"/>'],
            ['id' => 'category-tree', 'label' => 'Categories', 'read' => 'admin-api/categories', 'late' => true, 'icon' => '<path d="M3 7h6l2 2h10v10a2 2 0 0 1-2 2H3z"/><path d="M3 7V5a2 2 0 0 1 2-2h4l2 2"/>'],
            ['id' => 'brands-manager', 'label' => 'Brands', 'read' => 'admin-api/brands', 'late' => true, 'icon' => '<path d="M20.6 13.4 12 22l-8.6-8.6a5 5 0 0 1 0-7.1 5 5 0 0 1 7.1 0L12 7.8l1.5-1.5a5 5 0 0 1 7.1 7.1Z"/>'],
            ['id' => 'pagination', 'label' => 'Pagination', 'read' => 'admin-api/pagination', 'late' => true, 'icon' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h2"/><path d="M11 20h2"/><path d="M15 20h2"/>'],
        ]],
        ['sec' => 'Store', 'rows' => [
            ['id' => 'modules', 'label' => 'Modules', 'read' => 'admin-api/modules', 'icon' => '<path d="M4 7h7v7H4z"/><path d="M13 4h7v7h-7z"/><path d="M13 13h7v7h-7z"/>'],
            ['id' => 'megamenu', 'label' => 'Mega Menu', 'read' => 'admin-api/mega-menu/menus', 'icon' => '<path d="M3 5h18M3 5v4h18V5M7 13h10M7 17h6"/>'],
            ['id' => 'ecommerce', 'label' => 'Ecommerce', 'read' => 'admin-api/ecommerce', 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/>'],
            ['id' => 'tax', 'label' => 'Tax', 'read' => 'admin-api/settings', 'icon' => '<path d="M4 4h12l4 4v12H4z"/><path d="M8 10h8M8 14h5"/>'],
            ['id' => 'payship', 'label' => 'Payment & Shipping Rules', 'read' => 'admin-api/pay-ship-rules', 'icon' => '<path d="M3 7h18v10H3z"/><path d="M3 11h18"/><circle cx="7.5" cy="14" r="1"/>'],
            ['id' => 'shipping', 'label' => 'Delivery & Shipping', 'read' => 'admin-api/shipping', 'icon' => '<path d="M2 6h11v9H2z"/><path d="M13 9h4.5l3.5 3.5V15h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>'],
            ['id' => 'import', 'label' => 'Store Import / Export', 'read' => 'admin-api/import/status', 'icon' => self::I['sandbox']],
            ['id' => 'orders', 'label' => 'Orders', 'read' => 'admin-api/orders-list', 'icon' => self::I['orders']],
            ['id' => 'order-new', 'label' => 'New Order', 'read' => 'admin-api/manual-orders/bootstrap', 'late' => true, 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M6 6 5 3H2"/>'],
            ['id' => 'coupon-editor', 'label' => 'Coupons', 'read' => 'admin-api/coupons/manage', 'late' => true, 'icon' => '<path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6z"/><path d="M12 9v6"/><path d="M9 12h6"/>'],
            ['id' => 'payments', 'label' => 'Payments', 'read' => 'admin-api/payments', 'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>'],
            ['id' => 'paygw', 'label' => 'Gateway webhooks', 'read' => 'admin-api/payments/tamara', 'late' => true, 'icon' => '<path d="M12 3a4 4 0 0 1 3.4 6.1l2.8 4.6"/><path d="M8.2 20a4 4 0 0 1-1.4-7.1L9.6 8"/><path d="M18 20a4 4 0 0 0 1-7.9H13"/>'],
            ['id' => 'security', 'label' => 'Security', 'read' => 'admin-api/security', 'late' => true, 'icon' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="m9 12 2 2 4-4"/>'],
            ['id' => 'firewall', 'label' => 'Firewall', 'read' => 'admin-api/security/firewall', 'late' => true, 'icon' => '<path d="M3 5h18v14H3z"/><path d="M3 10h18M3 14.5h18M9 5v5M15 10v4.5M9 14.5V19"/>'],
            // Lane AN: "Sales Report" (revenue, refunds, AOV, best sellers), so it
            // cannot be mistaken for the visitor Analytics row under Dashboard.
            ['id' => 'analytics', 'label' => 'Sales Report', 'read' => 'admin-api/analytics', 'icon' => '<path d="M3 3v18h18"/><path d="M7 14l3-4 4 3 5-7"/>'],
            ['id' => 'search', 'label' => 'Site Search', 'read' => 'admin-api/site-search', 'icon' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/>'],
            ['id' => 'seo', 'label' => 'SEO & Meta', 'read' => 'admin-api/settings', 'icon' => '<path d="M4 7h16M4 12h10M4 17h7"/><circle cx="18" cy="16" r="3"/><path d="m22 20-1.5-1.5"/>'],
            ['id' => 'seokeywords', 'label' => 'SEO Keywords', 'read' => 'admin-api/seo-keywords', 'late' => true, 'icon' => '<path d="M4 7h9M4 12h6M4 17h4"/><circle cx="16.5" cy="13.5" r="4.5"/><path d="m20 17 2 2"/>'],
            ['id' => 'store-settings', 'label' => 'Business Details', 'read' => 'admin-api/settings', 'icon' => self::I['settings']],
            ['id' => 'customers', 'label' => 'Customers', 'read' => 'admin-api/customers/list', 'icon' => self::I['cust']],
            ['id' => 'quiz-leads', 'label' => 'Quiz Leads', 'read' => 'admin-api/quiz-leads', 'icon' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>'],
            // Store → Inquiries (Lane CT): the contact page's messages and its settings.
            ['id' => 'inquiries', 'label' => 'Inquiries', 'read' => 'admin-api/inquiries', 'late' => true, 'icon' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1Z"/>'],
        ]],
        ['sec' => 'Emails', 'rows' => [
            ['id' => 'emails', 'label' => 'Overview', 'read' => 'admin-api/emails/overview', 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
            ['id' => 'emails-sending', 'label' => 'Sending & delivery', 'read' => 'admin-api/emails/sending', 'icon' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>'],
            ['id' => 'emails-customer', 'label' => 'Customer emails', 'read' => 'admin-api/emails/customer', 'icon' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'],
            ['id' => 'emails-branding', 'label' => 'Design & branding', 'read' => 'admin-api/emails/branding', 'icon' => '<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4A5.6 5.6 0 0 0 22 9.8C22 5.5 17.5 2 12 2z"/>'],
            ['id' => 'emails-sent', 'label' => 'Sent mail', 'read' => 'admin-api/mail/log', 'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
            ['id' => 'mail', 'label' => 'All mail settings', 'read' => 'admin-api/mail', 'icon' => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>'],
        ]],
        ['sec' => 'Content', 'rows' => [
            ['id' => 'posts', 'label' => 'Blog Posts', 'read' => 'admin-api/posts', 'icon' => '<path d="M4 4h11l5 5v11H4z"/><path d="M14 4v5h5"/><path d="M8 13h6"/>'],
            ['id' => 'htmlblocks', 'label' => 'HTML Blocks', 'read' => 'admin-api/blocks', 'icon' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4"/>'],
            ['id' => 'media', 'label' => 'Media Library', 'read' => 'admin-api/media', 'icon' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L6 21"/>'],
            ['id' => 'ugcsections', 'label' => 'Shoppable video', 'read' => 'admin-api/ugc-sections', 'late' => true, 'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18"/><path d="m9 14 4 2-4 2z"/>'],
            ['id' => 'igembeds', 'label' => 'Instagram embeds', 'read' => 'admin-api/ig-embeds', 'late' => true, 'icon' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M9.5 21h5"/>'],
        ]],
        ['sec' => 'Translation', 'rows' => [
            ['id' => 'tr-settings', 'label' => 'Language settings', 'read' => 'admin-api/translations/settings', 'icon' => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/><circle cx="9" cy="5" r="2.2"/><circle cx="15" cy="12" r="2.2"/><circle cx="8" cy="19" r="2.2"/>'],
            ['id' => 'tr-progress', 'label' => 'Progress', 'read' => 'admin-api/translations/progress', 'icon' => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="5" width="3" height="13" rx="1"/>'],
            ['id' => 'tr-strings', 'label' => 'Strings', 'read' => 'admin-api/translations', 'icon' => '<path d="M4 7V5h16v2"/><path d="M9 19h6"/><path d="M12 5v14"/>'],
            ['id' => 'tr-machine', 'label' => 'Machine translation', 'read' => 'admin-api/translations/estimate', 'icon' => '<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>'],
        ]],
        ['sec' => 'Appearance', 'rows' => [
            ['id' => 'homepage', 'label' => 'Homepage', 'read' => 'admin-api/homepage', 'icon' => '<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/>'],
            ['id' => 'hpcontent', 'label' => 'Homepage content', 'read' => 'admin-api/homepage-hub', 'late' => true, 'icon' => '<path d="M3 5h18v6H3z"/><path d="M3 15h9"/><path d="M3 19h6"/>'],
            ['id' => 'spotted', 'label' => '#KBeautyBliss Spotted', 'read' => 'admin-api/spotted', 'late' => true, 'icon' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>'],
            ['id' => 'banners', 'label' => 'Banners', 'read' => 'admin-api/banners', 'late' => true, 'icon' => '<rect x="3" y="4" width="7" height="16" rx="2"/><rect x="14" y="4" width="7" height="16" rx="2"/>'],
            ['id' => 'gridsections', 'label' => 'Grid sections', 'read' => 'admin-api/grid-sections', 'late' => true, 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
            ['id' => 'prodstyles', 'label' => 'Product styles', 'read' => 'admin-api/product-styles', 'icon' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="9" rx="1.5"/><rect x="3" y="15" width="7" height="6" rx="1.5"/>'],
            ['id' => 'mobilehdr', 'label' => 'Mobile Header', 'read' => 'admin-api/mobile-header', 'icon' => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M7 9h10"/>'],
            ['id' => 'dividers', 'label' => 'Section dividers', 'read' => 'admin-api/dividers', 'icon' => '<path d="M4 12h5"/><path d="M15 12h5"/><circle cx="12" cy="12" r="1.6"/>'],
            ['id' => 'pagewash', 'label' => 'Page background', 'read' => 'admin-api/page-wash', 'late' => true, 'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 14c4-3 7 1 10-1s5-2 8 0"/>'],
            ['id' => 'wabutton', 'label' => 'WhatsApp button', 'read' => 'admin-api/whatsapp-button', 'late' => true, 'icon' => '<path d="M4.5 19.5 6 15.6A8 8 0 1 1 9 18.6z"/><path d="M9.5 9.5c.4 2.2 2.3 4.4 5 5"/>'],
            ['id' => 'cartpanel', 'label' => 'Cart panel', 'read' => 'admin-api/cart-panel', 'late' => true, 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/>'],
            ['id' => 'cartpage', 'label' => 'Cart page', 'read' => 'admin-api/cart-page', 'late' => true, 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M9.5 9.5h7"/>'],
            ['id' => 'checkoutpage', 'label' => 'Checkout page', 'read' => 'admin-api/checkout-page', 'late' => true, 'icon' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/>'],
            ['id' => 'slimfooter', 'label' => 'Footer', 'read' => 'admin-api/slim-footer', 'late' => true, 'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 15h18"/><path d="M7 18h5"/>'],
            ['id' => 'acctpanel', 'label' => 'Login / Register panel', 'read' => 'admin-api/account-panel', 'icon' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 12h8M8 15h5"/>'],
            ['id' => 'header', 'label' => 'Header', 'read' => 'admin-api/header', 'icon' => '<path d="M3 5h18v5H3z"/><path d="M3 14h10"/>'],
            ['id' => 'mobilemenu', 'label' => 'Mobile menu', 'read' => 'admin-api/mobile-menu', 'icon' => '<path d="M7 2h10v20H7z"/><path d="M10 18h4"/>'],
            ['id' => 'productpage', 'label' => 'Product page', 'read' => 'admin-api/product-page', 'icon' => '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="7.5" r="1.2"/>'],
            ['id' => 'setap', 'label' => 'Set', 'read' => 'admin-api/set-appearance', 'late' => true, 'icon' => '<circle cx="8.5" cy="12" r="4.2"/><circle cx="14" cy="12" r="4.2"/><circle cx="19" cy="12" r="1.6"/>'],
            ['id' => 'bundles', 'label' => 'Quantity bundles', 'read' => 'admin-api/bundles', 'icon' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>'],
            ['id' => 'layout', 'label' => 'Product grid', 'read' => 'admin-api/layout', 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
            ['id' => 'sitelayout', 'label' => 'Site layout', 'read' => 'admin-api/site-layout', 'late' => true, 'icon' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path d="M15 4v16"/>'],
            // Appearance -> Coming Soon page (Lane CS): hide the shop on an address while the domain moves.
            ['id' => 'comingsoon', 'label' => 'Coming Soon page', 'read' => 'admin-api/coming-soon', 'late' => true, 'icon' => '<path d="M6 3h12M6 21h12"/><path d="M7 3c0 5 10 6 10 9s-10 4-10 9"/><path d="M17 3c0 5-10 6-10 9s10 4 10 9"/>'],
        ]],
        ['sec' => 'Pages', 'rows' => [
            ['id' => 'pages-store', 'label' => 'Store pages', 'read' => 'admin-api/pages/store', 'icon' => '<path d="M3 9h18M3 15h18M9 3v18"/><rect x="3" y="3" width="18" height="18" rx="2"/>'],
            ['id' => 'pages-user', 'label' => 'User pages', 'read' => 'admin-api/page-editor-list', 'icon' => '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h4"/>'],
            ['id' => 'pagebanners', 'label' => 'Page banners', 'read' => 'admin-api/page-banners', 'icon' => '<rect x="3" y="4" width="18" height="11" rx="2"/><path d="M3 19h18"/><path d="m7 9 2.5 2.5L14 7"/>'],
            ['id' => 'pageheader', 'label' => 'Page header', 'read' => 'admin-api/page-header', 'icon' => '<path d="M3 5h18"/><path d="M3 10h11"/><rect x="3" y="14" width="18" height="6" rx="1.5"/>'],
        ]],
        ['sec' => 'Growth & Marketing', 'rows' => [
            ['id' => 'mkt-email', 'label' => 'Marketing Emails', 'read' => 'admin-api/email-marketing/overview', 'tag' => 'new', 'icon' => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/><path d="M17 3.5l1 1.8 2 .4-1.4 1.4.3 2-1.9-.9-1.9.9.3-2L14 5.7l2-.4z"/>'],
            // Growth & Marketing -> Bounces & unsubscribes (Lane EB): the "Not sending to" list, the bounce mailbox, the pace.
            ['id' => 'mkt-health', 'label' => 'Bounces & unsubscribes', 'read' => 'admin-api/email-health/overview', 'late' => true, 'tag' => 'new', 'icon' => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/><path d="M15 15l6 6"/><path d="M21 15l-6 6"/>'],
            ['id' => 'newsletter', 'label' => 'Newsletter', 'read' => 'admin-api/newsletter', 'icon' => '<path d="M3 6h18v12H3z"/><path d="m3 7 9 6 9-6"/>'],
            ['id' => 'labels', 'label' => 'Product Labels', 'read' => 'admin-api/product-labels', 'icon' => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L3 11V3h8l9.59 9.59a2 2 0 0 1 0 2.82z"/><circle cx="7.5" cy="7.5" r="1.3"/>'],
            ['id' => 'meta', 'label' => 'Meta & Facebook', 'cap' => 'marketing.view', 'tag' => 'lock', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>'],
            // Growth & Marketing -> Google Shopping feed (Lane SEO): the product feed for Merchant Center and Meta.
            ['id' => 'merchantfeed', 'label' => 'Google Shopping feed', 'read' => 'admin-api/merchant-feed', 'late' => true, 'icon' => '<path d="M6 6h15l-1.5 9h-12z"/><circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M6 6 5 3H2"/><path d="M3 11h2M2 14h3"/>'],
            ['id' => 'pixels', 'label' => 'Marketing Pixels', 'read' => 'admin-api/marketing-pixels', 'icon' => '<path d="M13 2 3 14h7l-1 8 10-12h-7z"/>'],
            ['id' => 'searchterms', 'label' => 'Search Terms', 'read' => 'admin-api/search-terms', 'late' => true, 'icon' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/><path d="M8 11h6"/><path d="M11 8v6"/>'],
            ['id' => 'push', 'label' => 'Push Notifications', 'read' => 'admin-api/push', 'late' => true, 'tag' => 'new', 'icon' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'],
        ]],
        ['sec' => 'Reviews', 'rows' => [
            ['id' => 'rev-all', 'label' => 'All Reviews', 'read' => 'admin-api/reviews/list', 'icon' => '<path d="M12 3l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.8 6.2 21l1.1-6.5L2.6 9.8l6.5-.9z"/>'],
            ['id' => 'rev-add', 'label' => 'Bulk Tools', 'read' => 'admin-api/review-bulk/options', 'icon' => '<path d="M12 5v14M5 12h14"/>'],
            ['id' => 'rev-assign', 'label' => 'Assign / Duplicate', 'read' => 'admin-api/review-assign/reviews', 'icon' => self::I['copy']],
            ['id' => 'rev-io', 'label' => 'Review Import / Export', 'read' => 'admin-api/reviews-io/summary', 'icon' => '<path d="M8 7h11l-3-3M16 17H5l3 3"/>'],
            ['id' => 'rev-badge', 'label' => 'Rating Badge', 'read' => 'admin-api/review-badges', 'icon' => '<path d="M12 2l4 4-4 4-4-4z"/><path d="M4 12l8 8 8-8"/>'],
            ['id' => 'rev-settings', 'label' => 'Review Settings', 'read' => 'admin-api/review-settings', 'icon' => self::I['settings']],
        ]],
        ['sec' => 'Storefront', 'flat' => true, 'rows' => [
            ['id' => 'shopfilters', 'label' => 'Shop Filters', 'cap' => 'catalog.view', 'icon' => '<path d="M4 5h16l-6 7v5l-4 2v-7z"/>'],
        ]],
        ['sec' => 'Core Updates', 'flat' => true, 'pinned' => true, 'rows' => [
            ['id' => 'updates', 'label' => 'Core Updates', 'read' => 'admin-api/updates', 'icon' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/><path d="M12 8v5l3 2"/>'],
        ]],
    ];

    /** Drawn exactly as the console's ic() draws an icon, so a row is the same markup either way. */
    private const SVG_OPEN = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8">';

    private const CHEV = '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="m9 6 6 6-6 6"/></svg>';

    /** @return array<string, array<string, mixed>> every row by id, with its section */
    public static function rows(): array
    {
        $out = [];
        foreach (self::GROUPS as $g) {
            foreach ($g['rows'] as $r) {
                $out[$r['id']] = $r + ['sec' => $g['sec']];
            }
        }

        return $out;
    }

    /** The capability a row needs, or null when nothing maps it (Full Admin only). */
    public static function capability(array $row): ?string
    {
        if (isset($row['cap'])) {
            return $row['cap'];
        }

        return isset($row['read']) ? AdminCapabilities::forPath('GET', $row['read']) : null;
    }

    /**
     * The groups this account may see, rows filtered, empty groups dropped.
     * A Full Admin gets GROUPS unchanged without a single capability lookup.
     */
    public static function visibleFor(?AdminUser $user): array
    {
        if (AdminRoles::isFull($user)) {
            return self::GROUPS;
        }

        $out = [];
        foreach (self::GROUPS as $g) {
            $g['rows'] = array_values(array_filter(
                $g['rows'],
                fn (array $r) => AdminRoles::can($user, self::capability($r))
            ));
            if ($g['rows'] !== []) {
                $out[] = $g;
            }
        }

        return $out;
    }

    /** @return list<string> the row ids this account is NOT shown */
    public static function hiddenFor(?AdminUser $user): array
    {
        $shown = [];
        foreach (self::visibleFor($user) as $g) {
            foreach ($g['rows'] as $r) {
                $shown[$r['id']] = true;
            }
        }

        return array_values(array_filter(array_keys(self::rows()), fn (string $id) => ! isset($shown[$id])));
    }

    /**
     * What the console's script reads: the rows whose renderer ships late (for
     * kbbNavClick's replay) and the rows withheld from this account (so a
     * partial's own kbbAddNavEntry() cannot put one back). Ids only -- the
     * markup is html()'s job and is not sent twice.
     *
     * @return array{late: list<string>, hidden: list<string>}
     */
    public static function forScript(?AdminUser $user): array
    {
        $late = [];
        foreach (self::rows() as $id => $r) {
            if (! empty($r['late'])) {
                $late[] = $id;
            }
        }

        return ['late' => $late, 'hidden' => self::hiddenFor($user)];
    }

    /**
     * The row the console opens on, as far as the SERVER can know it: the
     * `?go=` deep link when it names a row this account sees, else Dashboard.
     * A `#hash` link never reaches the server; the inline script printed after
     * the sidebar moves the mark for that case before the first paint.
     */
    public static function active(array $groups, ?string $go): ?string
    {
        $want = explode('/', (string) $go)[0];
        $ids = [];
        foreach ($groups as $g) {
            foreach ($g['rows'] as $r) {
                $ids[$r['id']] = true;
            }
        }
        if ($want !== '' && isset($ids[$want])) {
            return $want;
        }

        return isset($ids['dash']) ? 'dash' : null;
    }

    /** The complete <nav id="nav"> for this account, ready to paint. */
    public static function html(?AdminUser $user, ?string $go = null): string
    {
        $groups = self::visibleFor($user);
        $active = self::active($groups, $go);
        $out = '';

        foreach ($groups as $g) {
            if (! empty($g['flat'])) {
                foreach ($g['rows'] as $r) {
                    $out .= self::item($r, ! empty($g['pinned']), $r['id'] === $active);
                }

                continue;
            }

            $open = false;
            $sum = 0;
            $items = '';
            foreach ($g['rows'] as $r) {
                $open = $open || $r['id'] === $active;
                $sum += preg_match('/^\d+$/', (string) ($r['tag'] ?? '')) ? (int) $r['tag'] : 0;
                $items .= self::item($r, false, $r['id'] === $active);
            }
            $badge = $sum > 0 ? '<span class="gh-badge">'.$sum.'</span>' : '';
            $sec = e($g['sec']);
            $out .= '<div class="nav-group'.($open ? ' open' : '').'" data-sec="'.$sec.'">'
                .'<button class="nav-gh"><span class="gh-name">'.$sec.'</span>'.$badge.self::CHEV.'</button>'
                .'<div class="nav-sub">'.$items.'</div></div>';
        }

        // data-wait: hidden until the script printed right after </nav> runs,
        // which is only once the whole <nav> has been parsed. A slow line can
        // otherwise deliver -- and the browser paint -- the first half of the
        // rows (measured: 57 of 93 for 217 ms on a throttled hard refresh).
        // All or nothing; see the console view.
        return '<nav class="nav" id="nav"'.($out === '' ? '' : ' data-wait').'>'.$out.'</nav>';
    }

    /** One row: navItemHTML()'s markup, byte for byte, plus `on` when active. */
    private static function item(array $r, bool $pinned, bool $on): string
    {
        $tag = $r['tag'] ?? null;
        $cls = 'nav-item'.($pinned ? ' nav-pinned' : '').($tag === 'lock' ? ' locked' : '').($on ? ' on' : '');
        $extra = $tag === 'lock' ? '<span class="tag">soon</span>' : ($tag ? '<span class="tag">'.e($tag).'</span>' : '');

        return '<button class="'.$cls.'" data-go="'.e($r['id']).'">'.self::SVG_OPEN.$r['icon'].'</svg><span>'.e($r['label']).'</span>'.$extra.'</button>';
    }
}
