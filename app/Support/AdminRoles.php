<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable roles, on top of the capability map. (Lane RL, plan row 53.)
 *
 * AdminCapabilities' own docblock said "when roles genuinely need to be
 * editable, this file is the thing that grows a database backing". This is that
 * backing, kept beside the map rather than inside it, so the map stays what it
 * was: the list of capabilities, the route rules, and the CODE DEFAULT of the
 * four roles that existed before this file.
 *
 * ---------------------------------------------------------------------------
 * HOW AN ACCOUNT'S ACCESS IS WORKED OUT
 * ---------------------------------------------------------------------------
 *
 *   1. `admin_users.role` = 'owner'  ->  Full Admin: every capability, always.
 *      Read off the column alone, before anything else, so no row in
 *      `admin_roles`, no cache and no typo here can lock the owner out.
 *   2. Otherwise the account's role: `admin_users.role_id`, or -- when that is
 *      empty, as it is for every account that predates this package -- the
 *      preset its legacy `role` maps to (LEGACY below). The role row's
 *      `capabilities`, or, while a PRESET has never been edited (NULL), the
 *      preset's code default.
 *   3. Plus that person's own `grants`, minus their own `revokes`.
 *   4. Intersected with the capabilities the map knows. Nothing else survives.
 *
 * A preset that has never been edited FOLLOWS THE CODE. That is how "every
 * existing account keeps exactly the access it has today" is true on the day
 * the package applies and stays true afterwards: a lane that adds
 * 'foo.manage' => ['owner', 'manager'] to the map tomorrow reaches every Store
 * Manager without anybody re-saving a role. Editing a preset freezes its list;
 * "Restore default" sets it back to NULL and it follows the code again.
 *
 * A custom role never follows anything: its list is what was ticked. A
 * capability added to the map later is therefore Full-Admin-only for a custom
 * role until somebody ticks it -- closed, the same default the map has.
 *
 * ---------------------------------------------------------------------------
 * COST
 * ---------------------------------------------------------------------------
 *
 * `admin_roles` is a handful of rows. It is read once into the cache, and an
 * account's effective set is worked out once per request and kept on the
 * request. A permission check is an array lookup. Every write to a role or to
 * an account flushes both (AdminRole / AdminUser model events).
 */
final class AdminRoles
{
    /** The cache key the role table lives under. */
    public const CACHE_KEY = 'kbb.admin_roles.v1';

    /** The role every legacy `admin_users.role` value stands for. */
    public const LEGACY = [
        'owner' => 'full-admin',
        'manager' => 'store-manager',
        'support' => 'customer-support',
        'editor' => 'content-editor',
    ];

    /**
     * The predefined roles: slug => [name, tier, what it is for].
     *
     * TIER is the legacy value written to `admin_users.role` for an account on
     * this role. It is what the audit trail prints as the actor's role, and it
     * is what the account falls back to if this package is ever rolled back.
     * Only Full Admin is ever 'owner'.
     */
    public const PRESETS = [
        'full-admin' => ['Full Admin', 'owner', 'Everything, always — including staff accounts, roles, payment keys, site settings and core updates. Cannot be narrowed.'],
        'sub-admin' => ['Sub Admin', 'manager', 'Runs the shop and most of its settings: everything a Store Manager does plus store settings, emails, cache, security log, imports and taking over a record someone else is editing. Not staff accounts, roles, payment keys or updates.'],
        'store-manager' => ['Store Manager', 'manager', 'Runs the shop: orders, refunds, customers, the catalogue, content, marketing and shipping. Not site settings, payment keys, staff accounts or updates.'],
        'seo-manager' => ['SEO Manager', 'editor', 'Search: product and category copy, pages, articles, redirects, the SEO audit, search terms and analytics. No orders, customers or money.'],
        'inventory-manager' => ['Inventory Manager', 'editor', 'Stock: the catalogue, stock levels, sets and the set stock rule, and reading orders to see what sold. No customers, money or settings.'],
        'marketing-manager' => ['Marketing Manager', 'manager', 'Growth: newsletter, coupons, marketing emails (build and test, not send), banners, the homepage grid, Instagram and UGC. No orders or money.'],
        'customer-support' => ['Customer Support', 'support', 'Answers customers: reads orders and customers, adds notes, moves an order along and moderates reviews. Touches no money, deletes nothing, exports nothing.'],
        'content-editor' => ['Content Editor', 'editor', 'Works on the storefront: the catalogue, pages, blog, media, appearance and the review furniture. Sees no customer, no order and no money.'],
    ];

    /** Sub Admin = today's manager PLUS these. Never the owner's own seven. */
    private const SUB_ADMIN_EXTRA = [
        'store.settings', 'cache.manage', 'emails.manage', 'security.view',
        'system.diagnostics', 'seo.audit', 'data.import', 'presence.takeover',
    ];

    /** The three new presets, spelled out. Filtered against the map on read. */
    private const FIXED = [
        'seo-manager' => [
            'admin.access', 'dashboard.view', 'presence.view', 'analytics.view', 'search_terms.view', 'seo.audit',
            'catalog.view', 'catalog.manage', 'catalog.export', 'producttabs.view', 'producttabs.manage',
            'shareimages.make', 'content.manage', 'posts.manage', 'pages.manage', 'reviews.view',
            'seo_keywords.view', 'seo_keywords.sync',
        ],
        'inventory-manager' => [
            'admin.access', 'dashboard.view', 'presence.view', 'catalog.view', 'catalog.manage', 'catalog.export',
            'sets.view', 'sets.manage', 'sets.stock', 'orders.view', 'invoices.view',
        ],
        'marketing-manager' => [
            'admin.access', 'dashboard.view', 'presence.view', 'analytics.view', 'search_terms.view', 'catalog.view',
            'marketing.view', 'marketing.manage', 'marketing.export',
            'marketing.email.view', 'marketing.email.manage', 'marketing.bounces.view', 'emails.view', 'emails.test',
            'banners.view', 'banners.manage', 'gridsections.view', 'gridsections.manage',
            'spotted.manage', 'ugc.view', 'ugc.manage', 'wabutton.manage',
        ],
    ];

    /**
     * Every capability, in a section, in plain English. The screen draws its
     * ticks from this and nothing else; AdminRolesTest proves it names every
     * key in AdminCapabilities::CAPABILITIES exactly once and nothing more.
     */
    public const SECTIONS = [
        ['dashboard', 'Dashboard & reports', [
            'admin.access' => 'Sign in to the admin console',
            'dashboard.view' => 'See the dashboard',
            'analytics.view' => 'See revenue and analytics',
            'analytics.manage' => 'Switch visitor tracking on or off and set the addresses it leaves out (Analytics)',
            'search_terms.view' => 'See what shoppers searched for',
        ]],
        ['orders', 'Orders', [
            'orders.view' => 'See orders',
            'orders.manage' => 'Change an order’s status and add notes',
            'orders.edit' => 'Correct an order’s address, email and phone',
            'orders.customer' => 'Move an order to another customer account',
            'orders.money' => 'Refund and void payments',
            'orders.payment' => 'Mark an order as paid',
            'orders.paylink' => 'Send a failed or unpaid order\'s payment link (WhatsApp, email, copy)',
            'orders.paylink.settings' => 'Change the order link\'s expiry and wording',
            'orders.delete' => 'Delete orders',
            'orders.export' => 'Export orders',
            'orders.sample' => 'Create a sample order (Demo content)',
            'invoices.view' => 'Print invoices and packing slips',
        ]],
        ['customers', 'Customers', [
            'customers.view' => 'See customers',
            'customers.manage' => 'Edit customers',
            'customers.export' => 'Export the customer list',
            'customers.invite' => 'Email guests an account invite',
            'inquiries.view' => 'Read the contact page’s inquiries and mark them read (Store → Inquiries)',
            'inquiries.manage' => 'Delete inquiries and change the contact page’s cards, form and recipient',
        ]],
        ['products', 'Products & catalogue', [
            'catalog.view' => 'See products, categories and brands',
            'catalog.manage' => 'Edit products, categories, brands and stock levels',
            'catalog.export' => 'Export the catalogue',
            'sets.view' => 'See sets',
            'sets.manage' => 'Create and edit sets',
            'producttabs.view' => 'See product tabs',
            'producttabs.manage' => 'Write product tabs',
            'pagination.manage' => 'Turn pagination on or off for the shop, a category, a brand or a listing page',
            'notfoundpage.manage' => 'Choose and edit the shop’s 404 page (Safety → 404 page)',
            'shareimages.make' => 'Make link-preview pictures',
        ]],
        ['inventory', 'Inventory', [
            'sets.stock' => 'Decide whether selling a set takes stock from its items',
        ]],
        ['reviews', 'Reviews', [
            'reviews.view' => 'See reviews',
            'reviews.moderate' => 'Approve, hide and reply to reviews',
            'reviews.manage' => 'Review settings, badges and bulk tools',
            'reviews.export' => 'Export reviews',
        ]],
        ['content', 'Content & media', [
            'content.manage' => 'Media, menus, categories copy, redirects and translations',
            'media.optimize' => 'Convert images to WebP across the shop, undo it and remove originals',
            'media.image_seo' => 'Rename product pictures for SEO and bulk-write their alt text (Catalog → Image SEO), and undo it',
            'posts.manage' => 'Write Journal articles',
            'pages.manage' => 'Edit content pages (terms, privacy, FAQ…)',
            'ugc.view' => 'See the shoppable-video library',
            'ugc.manage' => 'Upload and edit shoppable videos',
        ]],
        ['seo', 'SEO', [
            'seo.audit' => 'SEO overview and audit',
            'seo_keywords.view' => 'See SEO keywords',
            'seo_keywords.sync' => 'Sync, edit and undo SEO keywords',
            'seo_brand.view' => 'See the brand name check',
            'seo_brand.manage' => 'Replace the old shop name and change the brand switches',
        ]],
        ['appearance', 'Appearance', [
            'banners.view' => 'See banners',
            'banners.manage' => 'Edit banners',
            'gridsections.view' => 'See grid sections',
            'gridsections.manage' => 'Edit grid sections',
            'homepagehub.view' => 'See the homepage section list (Homepage content → All sections)',
            'homepagehub.search' => 'Search products for a homepage section',
            'homepagehub.type' => 'Change a homepage section’s fonts, sizes and spacing (Fonts & size tab)',
            'cartpage.manage' => 'Cart page appearance',
            'checkoutpage.manage' => 'Checkout page appearance',
            'setappearance.manage' => 'Set box appearance',
            'slimfooter.manage' => 'Footer bar',
            'footer.preview' => 'Footer previews',
            'spotted.manage' => '#KBeautyBliss Spotted',
            'igembeds.manage' => 'Instagram embeds — paste post and reel addresses',
            'sitelayout.manage' => 'Site width and columns',
            'pagewash.manage' => 'Page background',
            'wabutton.manage' => 'WhatsApp button',
            'comingsoon.manage' => 'Coming Soon page: hide the shop on an address, and its preview link (Full Admin by default)',
            'pagebanners.manage' => 'Page banners and the Super Sale products',
            'pageheader.manage' => 'Page header of the custom pages, and its “Edit header” panel on the shop',
            'categoryheader.manage' => 'A category page’s “Edit header” panel on the shop, and its custom header area',
        ]],
        ['storefront', 'Storefront tools', [
            'storefront.adminbar' => 'See the admin bar on the shop',
            'storefront.quick_edit' => 'Quick-edit category and brand headers on the shop',
            'siteapp.manage' => 'Site App: make the shop an installable Home Screen app, and its name',
        ]],
        ['marketing', 'Marketing', [
            'marketing.view' => 'See newsletter, coupons and labels',
            'marketing.manage' => 'Edit newsletter, coupons and labels',
            'marketing.export' => 'Export subscriber and group lists',
            'marketing.email.view' => 'See marketing emails and reports',
            'marketing.email.manage' => 'Build marketing emails and send tests',
            'marketing.email.send' => 'Send and schedule campaigns',
            'marketing.bounces.view' => 'See bounced and unsubscribed addresses',
            'marketing.bounces.restore' => 'Restore a bounced address',
            'marketing.bounces.mailbox' => 'Set up the bounce mailbox',
            'push.view' => 'See push notification campaigns, reports and subscriber analytics',
            'push.send' => 'Send, schedule and test push notifications, and change their automations and rules',
            'marketing.feed' => 'Google Shopping feed: see its address and switch it on or off',
            'marketing.pixels.connect' => 'Marketing Pixels: connect Meta, Google and TikTok, paste their tokens and run the checks',
            'marketing.customcode' => 'Marketing Pixels: custom head, body and footer code on the shop (Full Admin only by default)',
            'carttracking.view' => 'See Cart Tracking',
            'carttracking.block' => 'Block and unblock IP addresses',
        ]],
        ['emails', 'Emails', [
            'emails.view' => 'See email settings and sent mail',
            'emails.test' => 'Send a test email to yourself',
            'emails.manage' => 'Change sending, branding and email templates',
        ]],
        ['shipping', 'Shipping & payments', [
            'store.shipping' => 'Shipping rates and rules',
            'payments.manage' => 'Payment gateways and their keys',
            'payments.stripe_webhook' => 'Stripe: see webhook status and set the webhook up automatically',
            'payments.log' => 'Read the payment log',
        ]],
        ['settings', 'Settings & platform', [
            'store.settings' => 'Store settings, modules, mail and the admin address',
            'platform.site_url' => 'Change the site address',
            'platform.domain_switch' => 'Move the shop to a new domain (Platform → Domain switch; Full Admin only)',
            'payments.check' => 'Run the read-only payments check (Platform → Domain switch → Payments ready?)',
            'cache.manage' => 'Clear caches and browser caching',
            'updates.manage' => 'Install and roll back core updates',
            'system.diagnostics' => 'Error log, health and diagnostics',
        ]],
        ['security', 'Security', [
            'security.view' => 'See the security log',
            'security.integrity' => 'Run the file integrity check',
            'firewall.view' => 'See the firewall and what it refused',
            'firewall.manage' => 'Change the firewall: mode, limits, countries, bots, always-allow, bans',
        ]],
        ['data', 'Data & import', [
            'data.import' => 'Import customers, orders and products',
            'data.cleanup' => 'Delete data before a migration',
            'data.old_links' => 'Rewrite links to the old site',
        ]],
        ['users', 'Users & Roles', [
            'users.manage' => 'Add, edit and remove staff accounts',
            'roles.manage' => 'Create and edit roles',
            'ownerapp.manage' => 'Owner app: give access, set PINs, sign phones out',
            'presence.view' => 'See who else is editing a record',
            'presence.takeover' => 'Take over a record someone else is editing',
        ]],
    ];

    /* ------------------------------------------------------------ catalogue */

    /** @return list<string> every capability the map knows, in map order */
    public static function known(): array
    {
        return array_keys(AdminCapabilities::CAPABILITIES);
    }

    /** @return array<string, string> capability => label */
    public static function labels(): array
    {
        $out = [];
        foreach (self::SECTIONS as [, , $caps]) {
            $out += $caps;
        }

        return $out;
    }

    /** The sections in the shape the screen reads. */
    public static function sections(): array
    {
        return array_map(fn (array $s) => [
            'key' => $s[0],
            'label' => $s[1],
            'caps' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($s[2]), $s[2]),
        ], self::SECTIONS);
    }

    /** @return list<string> only the keys the map knows, de-duplicated, in map order */
    public static function clean(mixed $caps): array
    {
        if (! is_array($caps)) {
            return [];
        }

        return array_values(array_intersect(self::known(), $caps));
    }

    /** @return list<string> a preset's code default */
    public static function presetDefault(string $slug): array
    {
        return match (true) {
            $slug === 'full-admin' => self::known(),
            $slug === 'sub-admin' => self::clean(array_merge(AdminCapabilities::forRole('manager'), self::SUB_ADMIN_EXTRA)),
            isset(self::FIXED[$slug]) => self::clean(self::FIXED[$slug]),
            ($legacy = array_search($slug, self::LEGACY, true)) !== false => AdminCapabilities::forRole($legacy),
            default => [],
        };
    }

    /* --------------------------------------------------------------- roles */

    /**
     * Every role row, keyed by id, as plain arrays. Cached; empty (and NOT
     * cached) while the table does not exist yet, so a package whose code lands
     * before its migration runs answers from the code defaults rather than
     * remembering an empty table.
     *
     * @return array<int, array{id:int, slug:string, name:string, description:?string, tier:string, is_preset:bool, capabilities:?array}>
     */
    public static function roles(): array
    {
        $memo = self::memo('roles');
        if ($memo !== null) {
            return $memo;
        }

        $rows = Cache::get(self::CACHE_KEY);

        if (! is_array($rows)) {
            if (! Schema::hasTable('admin_roles')) {
                return [];
            }
            $rows = [];
            foreach (DB::table('admin_roles')->orderBy('id')->get() as $r) {
                $caps = $r->capabilities === null ? null : json_decode((string) $r->capabilities, true);
                $rows[(int) $r->id] = [
                    'id' => (int) $r->id,
                    'slug' => (string) $r->slug,
                    'name' => (string) $r->name,
                    'description' => $r->description,
                    'tier' => (string) $r->tier,
                    'is_preset' => (bool) $r->is_preset,
                    'capabilities' => is_array($caps) ? $caps : null,
                ];
            }
            Cache::forever(self::CACHE_KEY, $rows);
        }

        return self::remember('roles', $rows);
    }

    /** @return list<string> a role row's capabilities (code default while a preset is unedited) */
    public static function roleCapabilities(array $role): array
    {
        if ($role['slug'] === 'full-admin') {
            return self::known();
        }
        if ($role['capabilities'] === null) {
            return $role['is_preset'] ? self::presetDefault($role['slug']) : [];
        }

        return self::clean($role['capabilities']);
    }

    public static function bySlug(string $slug): ?array
    {
        foreach (self::roles() as $r) {
            if ($r['slug'] === $slug) {
                return $r;
            }
        }

        return null;
    }

    /**
     * The role an account is on: its role_id, or the preset its legacy role
     * maps to. Null for a role_id whose row is gone or a role nobody knows.
     */
    public static function roleOf(AdminUser $user): ?array
    {
        $id = $user->getAttribute('role_id');
        if ($id !== null) {
            return self::roles()[(int) $id] ?? null;
        }

        $slug = self::LEGACY[AdminCapabilities::canonicalRole($user->role ?? null) ?? ''] ?? null;
        if ($slug === null) {
            return null;
        }

        // The table may not exist yet: the preset still answers from code.
        return self::bySlug($slug) ?? [
            'id' => 0, 'slug' => $slug, 'name' => self::PRESETS[$slug][0], 'description' => self::PRESETS[$slug][2],
            'tier' => self::PRESETS[$slug][1], 'is_preset' => true, 'capabilities' => null,
        ];
    }

    /* ------------------------------------------------------------- answers */

    /** Full Admin: the column alone decides, ahead of any table. */
    public static function isFull(?AdminUser $user): bool
    {
        return $user !== null && AdminCapabilities::canonicalRole($user->role ?? null) === 'owner';
    }

    /**
     * What this account may do, worked out fresh. Callers want for(), which
     * memoises; this is for an account that is about to be saved.
     *
     * @return list<string>
     */
    public static function resolve(AdminUser $user): array
    {
        if (self::isFull($user)) {
            return self::known();
        }

        $role = self::roleOf($user);
        $base = $role === null ? [] : self::roleCapabilities($role);
        $plus = self::clean(self::list($user->getAttribute('grants')));
        $minus = self::list($user->getAttribute('revokes'));

        return array_values(array_diff(self::clean(array_merge($base, $plus)), $minus));
    }

    /** @return list<string> resolve(), once per account per request */
    public static function for(?AdminUser $user): array
    {
        if ($user === null) {
            return [];
        }
        $key = 'user.'.(int) $user->getKey();

        return self::memo($key) ?? self::remember($key, self::resolve($user));
    }

    /** The one question every check in the console asks. Unknown or null: no. */
    public static function can(?AdminUser $user, ?string $capability): bool
    {
        if (self::isFull($user)) {
            return true;
        }
        if ($user === null || $capability === null) {
            return false;
        }

        return in_array($capability, self::for($user), true);
    }

    /* --------------------------------------------------------- escalation */

    /**
     * Would this change let $actor reach past their own access? Null when it
     * is allowed; otherwise [status, error code, sentence].
     *
     * $after is the target's access AFTER the change — ['full' => bool,
     * 'caps' => list] — or null when the target is being deleted. $target is
     * null when the account is being created.
     *
     * The rules, every one closed:
     *   - nobody but a Full Admin changes their OWN access;
     *   - nobody touches an account that holds anything they do not;
     *   - nobody hands out anything they do not hold (so only a Full Admin
     *     makes a Full Admin);
     *   - the last Full Admin cannot be demoted or deleted, by anyone.
     */
    public static function refusal(AdminUser $actor, ?AdminUser $target, ?array $after, bool $accessChanges = true): ?array
    {
        $actorFull = self::isFull($actor);
        $mine = self::for($actor);

        if ($target !== null && $accessChanges && ! $actorFull && (int) $target->getKey() === (int) $actor->getKey()) {
            return [403, 'own_access', 'You cannot change your own role or permissions. Ask a Full Admin.'];
        }

        if ($target !== null && ! $actorFull && (self::isFull($target) || array_diff(self::resolve($target), $mine) !== [])) {
            return [403, 'outranks', 'This account can do things your role cannot, so only someone with at least its access can change it.'];
        }

        if ($accessChanges && $after !== null && ! $actorFull) {
            if ($after['full']) {
                return [403, 'beyond_your_access', 'Only a Full Admin can make someone a Full Admin.'];
            }
            $beyond = array_values(array_diff($after['caps'], $mine));
            if ($beyond !== []) {
                return [403, 'beyond_your_access', 'You cannot give permissions you do not have yourself: '.self::describe($beyond).'.'];
            }
        }

        if ($target !== null && self::isFull($target) && ($after === null || ! $after['full'])
            && AdminUser::query()->where('role', 'owner')->count() <= 1) {
            return [422, 'last_full_admin', 'This is the only Full Admin. Make someone else a Full Admin first, or the shop would have nobody who can manage accounts.'];
        }

        return null;
    }

    /** "See orders, Export orders" — labels, never keys, for a sentence. */
    public static function describe(array $caps): string
    {
        $labels = self::labels();

        return implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, array_slice($caps, 0, 6)))
            .(count($caps) > 6 ? ' and '.(count($caps) - 6).' more' : '');
    }

    /* ------------------------------------------------------------- caching */

    /** After any write to a role or an account. */
    public static function flush(?int $userId = null): void
    {
        if ($userId === null) {
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (\Throwable) {
                // A cache store that cannot be reached holds nothing stale.
            }
        }

        $attrs = self::attributes();
        if ($attrs === null) {
            return;
        }
        foreach (array_keys($attrs->all()) as $k) {
            if (str_starts_with($k, 'kbb.roles.') && ($userId === null || $k === 'kbb.roles.user.'.$userId)) {
                $attrs->remove($k);
            }
        }
    }

    /** JSON column, array cast, or nothing: always a list. */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function attributes(): ?\Symfony\Component\HttpFoundation\ParameterBag
    {
        return app()->bound('request') ? app('request')->attributes : null;
    }

    private static function memo(string $key): ?array
    {
        $v = self::attributes()?->get('kbb.roles.'.$key);

        return is_array($v) ? $v : null;
    }

    private static function remember(string $key, array $value): array
    {
        self::attributes()?->set('kbb.roles.'.$key, $value);

        return $value;
    }
}
