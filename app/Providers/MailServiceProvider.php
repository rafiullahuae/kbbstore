<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\Mail\OrderMailObserver;
use App\Services\Mail\OrderMailer;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the database-backed mail configuration into the framework's mailer.
 *
 * The whole reason this provider exists rather than a call in the one
 * controller that sends a test message: the five features this unblocks
 * (password reset, email verification, newsletter double opt-in, abandoned
 * cart, back-in-stock) mostly send through Laravel's own plumbing --
 * `Password::sendResetLink()` builds its own notification and reaches for the
 * DEFAULT mailer. If the configuration were only applied by whoever remembered
 * to apply it, each of those would need its own call and the first one that
 * forgot would silently log instead of send. That is exactly the failure mode
 * this package was created to end.
 *
 * Cost when nothing sends mail: none. The hook is `afterResolving`, so the
 * lookup happens the first time something asks the container for the mail
 * manager and never on a storefront page that does not.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Scoped, not singleton. These memoise -- MailCredentials caches the
         * decrypted row -- and a singleton on a queue worker would keep serving
         * the configuration read during the first job after the owner changed
         * it. Same reasoning AppServiceProvider gives for CartService, and the
         * same trap CLAUDE.md records against Setting::map().
         */
        $this->app->scoped(MailCredentials::class);
        $this->app->scoped(MailSettings::class);
        $this->app->scoped(MailConfigurator::class);
        $this->app->scoped(MailTester::class);
        $this->app->scoped(OrderMailer::class);

        $this->app->afterResolving('mail.manager', function ($manager) {
            /*
             * First, because it must happen whatever the settings lookup below
             * does. `kbb-server` is not one of MailManager's built-in drivers,
             * so without this the manager would throw "Unsupported mail
             * transport [kbb-server]" the moment anything tried to send -- and
             * the swallow in OrderMailer would turn that into a logged line and
             * a customer who never heard from the store. Registering a creator
             * is a single array write and builds nothing.
             *
             * The manager arrives as the callback's argument rather than being
             * fetched from the container, because this runs during that
             * container resolution.
             */
            try {
                MailConfigurator::registerTransports($manager);
            } catch (\Throwable) {
                // An older framework build without extend(). Leave `log` in place.
            }

            /*
             * Guarded, and guarded loudly in the comment rather than quietly in
             * the code: this runs during a request that wants to send mail, and
             * `mail_credentials` does not exist until this package's migration
             * has run. On a server where the package landed but the migration
             * did not -- which has happened here before, see
             * PackageMigrationFlagTest -- the table read throws. Swallowing it
             * leaves the framework's own `log` fallback in place, which is the
             * behaviour the app already had.
             */
            try {
                $this->app->make(MailConfigurator::class)->apply();
            } catch (\Throwable) {
                // Leave config/mail.php's inert 'kbb' => log entry in place.
            }
        });
    }

    /**
     * Hook the order emails to the models that trigger them.
     *
     * Registered here rather than in AppServiceProvider because this is mail
     * wiring and this is the mail provider: the order emails, the transport they
     * go out on and the settings that configure them are one feature, and a
     * reader looking for "what sends mail in this app" should find all of it in
     * one place. OrderMailObserver's own header explains why the trigger is a
     * model event and not a call in each of the four controllers that change an
     * order's status — none of which this lane owns.
     *
     * Cheap: registering an observer attaches listeners and reads nothing. No
     * settings lookup, no database query, and no mail manager resolved, so a
     * storefront page that changes no order still pays nothing for this.
     */
    public function boot(): void
    {
        OrderMailObserver::register();
    }
}
