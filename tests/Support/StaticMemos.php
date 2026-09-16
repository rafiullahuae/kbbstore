<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\ServerMailTransport;
use App\Services\Seo\IndexNow;
use App\Services\SettingsService;
use App\Services\Update\InstalledVersion;
use App\Support\Facets;
use App\Support\Money;
use App\Support\Shortcodes;
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
            IndexNow::class => static fn () => IndexNow::forgetKey(),
            InstalledVersion::class => static fn () => InstalledVersion::forget(),
            Facets::class => static fn () => Facets::reset(),
            Money::class => static fn () => Money::forgetConfig(),
            Url::class => static fn () => Url::forgetBase(),
            // Public and written from the transport itself; there is no forget()
            // to call, so this is the assignment.
            ServerMailTransport::class => static function (): void {
                ServerMailTransport::$lastTestDelivery = null;
            },
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
        Shortcodes::class => 'stack is a re-entrancy guard, not a memo: block() pushes a slug and pops '
            .'it in a finally, so it is empty again however the render ends, exception included.',
        MailConfigurator::class => 'registered is a WeakMap keyed on the mailer instance. Entries '
            .'disappear with the mailer they describe, which is what it was changed to a WeakMap FOR '
            .'-- keyed on spl_object_id it leaked between tests, because PHP reuses object ids.',
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
