<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\Import\Checkpoint;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\ServerMailTransport;
use App\Services\ModuleSchema;
use App\Services\Seo\IndexNow;
use App\Services\SetAppearanceLiveMap;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Services\Update\InstalledVersion;
use App\Support\Facets;
use App\Support\SiteHost;
use App\Support\Money;
use App\Support\ProductTabs;
use App\Support\Shortcodes;
use App\Support\SetPricing;
use App\Support\Url;

/**
 * Every process-level static in app/, and the call that clears it.
 *
 * WHY. A static outlives the container, so it outlives the test. RefreshDatabase
 * rolls the database back and tests/Pest.php puts the container back, but
 * neither of those touches a `private static ?array $memo`. Whatever the first
 * test to call Setting::map(), Url::base() or IndexNow::key() resolved is what
 * every later test in the process gets, whatever their own seed data says. That
 * is an order dependency by construction: the suite's answer depends on which
 * test ran first, which changes with --order-by=random, with
 * .phpunit.result.cache putting previous failures first, and with running one
 * file instead of all of them.
 *
 * It is not hypothetical here. CLAUDE.md records Setting::map() as a landmine.
 * SettingsService's own docblock records a memo leaking a `false` between test
 * files. About thirty test files call SettingsService::forgetMemo() in their
 * own beforeEach, which is the same fix written thirty times by the lanes that
 * were bitten — and absent from every file written by a lane that was not.
 * StorefrontRouteWalkTest carries a comment explaining that it must ask the
 * application for the IndexNow key rather than the key it just seeded, because
 * IndexNow::key() memoised in a function-local static nothing could reach.
 *
 * So the reset belongs here, once, run from tests/Pest.php before every test,
 * and the per-file calls become redundant rather than load-bearing.
 *
 * ADDING A MEMO. Register it below. StaticMemoIsolationTest scans app/ for
 * static properties and function-local statics and fails on any class that is
 * neither reset here nor exempt with a reason — so a new memo cannot be added
 * without this file being updated, which is the point.
 */
final class StaticMemos
{
    /**
     * class => the call that returns its statics to their declared defaults.
     *
     * A closure per entry rather than a method name, because the clearing call
     * is not uniformly named (flushMap, forgetMemo, forgetConfig, reset, forget)
     * and because ServerMailTransport's is a plain assignment.
     *
     * @return array<class-string, \Closure(): void>
     */
    public static function resets(): array
    {
        return [
            Setting::class => static fn () => Setting::flushMap(),
            SettingsService::class => static fn () => SettingsService::forgetMemo(),
            AdminPathService::class => static fn () => AdminPathService::forgetMemo(),
            \App\Services\OwnerApp\OwnerAppPath::class => static fn () => \App\Services\OwnerApp\OwnerAppPath::forgetMemo(),
            \App\Services\OwnerApp\OwnerAppSettings::class => static fn () => \App\Services\OwnerApp\OwnerAppSettings::forget(),
            \App\Services\OwnerApp\OwnerAppUi::class => static fn () => \App\Services\OwnerApp\OwnerAppUi::forget(),
            \App\Services\OwnerApp\VapidKeys::class => static fn () => \App\Services\OwnerApp\VapidKeys::forget(),
            \App\Services\OwnerApp\OwnerAppEvents::class => static fn () => \App\Services\OwnerApp\OwnerAppEvents::forget(),
            \App\Services\OwnerApp\OwnerAppAlerts::class => static fn () => \App\Services\OwnerApp\OwnerAppAlerts::forget(),
            // Lane PO: order mail held for after the response.
            \App\Services\Mail\OrderMailer::class => static fn () => \App\Services\Mail\OrderMailer::forget(),
            // Lane MP: server events buffered for after the response (integrator, 2.60.449).
            \App\Services\Pixels\ServerEvents::class => static fn () => \App\Services\Pixels\ServerEvents::forget(),
            // Lane PW: the hashes in the app's versioned URLs, per process.
            \App\Services\SiteApp::class => static fn () => \App\Services\SiteApp::forgetHashes(),
            IndexNow::class => static fn () => IndexNow::forgetKey(),
            InstalledVersion::class => static fn () => InstalledVersion::forget(),
            Facets::class => static fn () => Facets::reset(),
            // Lane BH: whether brands.logo_color / ring_color exist yet.
            \App\Support\BrandLogo::class => static fn () => \App\Support\BrandLogo::forgetColumns(),
            // Lane BR2: whether brands.header_layout exists yet.
            \App\Support\BrandPanel::class => static fn () => \App\Support\BrandPanel::forgetColumn(),
            // Lane BR: the test-only target override, so a case that narrows
            // the brand rename can never leave it narrowed for the next one.
            \App\Services\Seo\BrandRename::class => static function (): void {
                \App\Services\Seo\BrandRename::$targets = null;
            },
            /*
             * The authored product tabs, BOTH layers (Lane PT).
             *
             * Same shape and the same reason as Setting::map() above: a
             * per-process memo over a cache entry, so a test that writes a tab
             * and then renders a product page would otherwise be served the
             * answer whichever test ran first got.
             *
             * flush() and NOT forgetMemo(), which was the first shape and was
             * half a reset. RefreshDatabase rolls the ROWS back and touches no
             * cache at all, so a test that creates a global tab leaves that tab
             * in the cache entry after its row has gone -- and the next test to
             * render a product page is served a tab that no longer exists,
             * which is the same order dependency one layer further out. The
             * model hooks call the same function.
             */
            ProductTabs::class => static fn () => ProductTabs::flush(),
            Money::class => static fn () => Money::forgetConfig(),
            Url::class => static fn () => Url::forgetBase(),
            /*
             * The translations map, both layers (Lane EP).
             *
             * Same shape and the same reason as Setting::map() above it: a
             * per-process memo over a cache entry. Without this reset, the
             * first test to render a translated page would fix the answer for
             * every test after it in the process — a test that seeds Arabic and
             * a test that asserts the English fallback would each pass alone
             * and one of them would fail in a full run, depending on order.
             */
            TranslationStore::class => static fn () => TranslationStore::flush(),
            /*
             * SiteHost memoises its verdict for the host it last answered
             * about, so Seo::render() and the middleware do not each pay for
             * the settings map on one page load.
             *
             * Without this reset it is the classic ordering bug and it bites in
             * the worst direction: a test that switches an install to private
             * leaves the memo saying "private", and the next test in the same
             * process that happens to run on the same host renders every page
             * noindex. That is how it was found -- TaxonomySeoOverridesTest
             * started asserting 'index, follow' against 'noindex, nofollow'
             * from a file it has nothing to do with.
             */
            SiteHost::class => static fn () => SiteHost::forget(),
            // The built grid stylesheet's bytes, keyed by Vite entry (Lane CC).
            \App\Support\LandingCss::class => static fn () => \App\Support\LandingCss::forget(),
            /*
             * The per-module normalised schemas (Lane M2).
             *
             * Built from class CONSTANTS, so unlike every entry above it this
             * one cannot carry a seeded value across a test — which is exactly
             * the argument for an exemption, and the reason it is a reset
             * instead. An exemption is a claim somebody has to believe; a reset
             * costs one array assignment and is a fact. Three modules memoised
             * this in a `static` of their own until this test refused them.
             */
            ModuleSchema::class => static fn () => ModuleSchema::forgetNormalised(),
            /*
             * Whether `import_checkpoints` has the `dropped_fields` column
             * (Lane PX).
             *
             * The memo only ever caches a YES, which is safe in production --
             * nothing removes a column under a running process -- and is an
             * ordering bug in a suite that runs every test in one process. The
             * first test to commit an import batch fixes the answer for every
             * test after it, including one that builds a checkpoints table
             * WITHOUT the column to prove an import still works on a shop whose
             * update package has not been applied yet. That test would then
             * write a column that is not there and fail pointing at the import.
             */
            Checkpoint::class => static fn () => Checkpoint::forgetColumnMemo(),
            /*
             * A set's parts total, memoised per request so one page render does
             * not ask the same question three times -- the price, the schema
             * offer and the saving all read it. (Lane SP)
             *
             * The memo is keyed by PRODUCT ID, and RefreshDatabase hands the
             * next test the same ids over a different catalogue: without this
             * reset, the first test to price a set fixes what set #1 costs for
             * every test after it, including one that builds set #1 out of
             * entirely different products. Exactly the shape this file exists
             * for.
             */
            SetPricing::class => static fn () => SetPricing::forget(),
            /*
             * Which of Appearance → Set's controls the screen may preview
             * without asking the server, derived from SetAppearance::css()
             * itself. (Lane SA3)
             *
             * It is a memo over a CACHE entry, the same two-layer shape as
             * Setting::map() and ProductTabs above, and it is here for the
             * reason those are: the cache store is `array` under test, so the
             * first test to build the map decides what every later test in the
             * process is handed — and SetAppearanceLiveMapTest builds it
             * deliberately against a flushed cache to prove the caching works
             * at all. Without this reset that test's own timing measurement
             * would depend on whether some earlier file had opened the screen.
             */
            SetAppearanceLiveMap::class => static fn () => SetAppearanceLiveMap::forget(),
            /*
             * BannerCard::naturalSize() memoises the header it reads off a
             * picture with no stored size, per path, per process. (Lane RC)
             */
            \App\Models\BannerCard::class => static fn () => \App\Models\BannerCard::forgetSizes(),
            /*
             * Lane EM: the browser-copy tokens minted while rendering and the
             * "does mail_web_copies exist" answer. Without the reset a token
             * minted by one test's render is still pending in the next, and
             * a test that migrates the table away keeps being told it exists.
             */
            \App\Services\Mail\Kit\WebCopy::class => static fn () => \App\Services\Mail\Kit\WebCopy::reset(),
            /*
             * Lane EK: the template builder's stored section orders and words,
             * read once per process. Without the reset a test that reorders
             * "Order shipped" leaves the next test's shipped email reordered.
             */
            \App\Services\Mail\Kit\KitSections::class => static fn () => \App\Services\Mail\Kit\KitSections::forget(),
            // Lane EM: the per-minute cap on new email copies counts across
            // renders; a test that made copies must not spend the next one's.
            \App\Services\Mail\Kit\MailImage::class => static fn () => \App\Services\Mail\Kit\MailImage::resetBudget(),
            \App\Services\Mail\Kit\EmailWording::class => static fn () => \App\Services\Mail\Kit\EmailWording::forget(),
            // (Lane CT) The compiled block list: a per-process FILE as well as a
            // memo, so a block written by one test would otherwise be enforced
            // in the next. Reset to "empty, ready, default settings" -- which is
            // exactly what a freshly migrated test database holds -- without a
            // query, so no test's first request pays a rebuild.
            \App\Services\Security\IpBlockList::class => static fn () => \App\Services\Security\IpBlockList::seedEmpty(),
            // (Lane FW) The firewall's per-request state, its counter store,
            // the open country file, the bot ranges and the flush flag.
            \App\Services\Security\Firewall::class => static fn () => \App\Services\Security\Firewall::reset(),
            \App\Services\Security\FirewallStore::class => static fn () => \App\Services\Security\FirewallStore::reset(),
            \App\Services\Security\FirewallCounters::class => static fn () => \App\Services\Security\FirewallCounters::wipeTestTable(),
            \App\Services\Security\FirewallLog::class => static fn () => \App\Services\Security\FirewallLog::reset(),
            \App\Services\Security\CountryDb::class => static fn () => \App\Services\Security\CountryDb::forget(),
            \App\Services\Security\GoodBots::class => static fn () => \App\Services\Security\GoodBots::forget(),
            // Public and written from the transport itself; there is no forget()
            // to call, so this is the assignment.
            ServerMailTransport::class => static function (): void {
                ServerMailTransport::$lastTestDelivery = null;
            },
            // Lane KW: the sync's step budget and chunk size, which a test
            // shrinks to force a run across many steps.
            \App\Services\Seo\Keywords\KeywordSync::class => static fn () => \App\Services\Seo\Keywords\KeywordSync::resetTuning(),
            // Lane AN: the crawler pattern and the day's visitor salt.
            \App\Services\Analytics\Tracker::class => static fn () => \App\Services\Analytics\Tracker::forget(),
        ];
    }

    /**
     * Statics that are deliberately NOT reset, with the reason.
     *
     * An exemption is a claim that the value cannot cross a test boundary, and
     * each one has to survive being read out loud.
     *
     * @var array<class-string, string>
     */
    public const EXEMPT = [
        \App\Support\AddressRegions::class => 'index is the alias lookup built from the class\'s own LISTS '
            .'constant -- no database, no settings, no request -- so it holds the same bytes in every test; a '
            .'cache of a constant, not state.',
        \App\Support\AdminConsoleAssets::class => 'manifest and buildId are keyed on public/build/manifest.json\'s '
            .'mtime and size, so a new build is a new key and nothing a test does can make it answer stale; '
            .'they are a read cache of a shipped file, not state.',
        \App\Services\OwnerApp\OwnerAppAuth::class => 'dummy is a hash of random bytes, made once so an unknown email '
            .'costs a sign-in what a known one does. No test reads it and no value of it can change what any other '
            .'test sees: it only ever answers false.',
        \App\Services\CartTracking\HostingNetworks::class => 'packed is the decoded copy of a shipped, read-only '
            .'data file (resources/data/hosting-networks.php). Nothing writes it but data(), and it holds the same '
            .'bytes in every test, so keeping it across tests is a cache of a constant, not state.',
        Shortcodes::class => 'stack is a re-entrancy guard, not a memo: block() pushes a slug and pops '
            .'it in a finally, so it is empty again however the render ends, exception included.',
        MailConfigurator::class => 'registered is a WeakMap keyed on the mailer instance. Entries '
            .'disappear with the mailer they describe, which is what it was changed to a WeakMap FOR '
            .'-- keyed on spl_object_id it leaked between tests, because PHP reuses object ids.',
        \App\Support\AdminSearchIndex::class => 'built is derived from class constants only (CURATED and '
            .'the SCHEMA/TABS of the SCHEMA_SCREENS classes) -- no database, no settings, no request -- so '
            .'no test can make it differ from what a fresh process would build.',
    ];

    /** Clear every registered memo. Called from tests/Pest.php before each test. */
    public static function forgetAll(): void
    {
        foreach (self::resets() as $reset) {
            $reset();
        }
    }

    /**
     * Registered statics that are not at their declared default right now.
     *
     * "Declared default" rather than a list of expected values, so this cannot
     * drift from the classes it describes: it reads what the property
     * declaration says and compares the live value with that.
     *
     * @return array<string, mixed> "Class::$property" => the value found
     */
    public static function leftOver(): array
    {
        $dirty = [];

        foreach (array_keys(self::resets()) as $class) {
            $reflection = new \ReflectionClass($class);
            $defaults = $reflection->getDefaultProperties();

            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                $name = $property->getName();

                if (! $property->isInitialized()) {
                    continue;
                }

                $value = $property->getValue();

                if ($value !== ($defaults[$name] ?? null)) {
                    $dirty[$class.'::$'.$name] = $value;
                }
            }
        }

        return $dirty;
    }
}
