<?php

declare(strict_types=1);

use App\Services\Mail\MailConfigurator;
use App\Services\Mail\ServerMailTransport;
use Illuminate\Mail\MailManager;

/**
 * A fresh mail manager must always learn the server transport.
 *
 * MailConfigurator remembered which managers it had already taught, keyed on
 * spl_object_id($manager). PHP reuses an object id as soon as the object that
 * held it is freed — five objects created and released in sequence all report
 * id 1 — so a BRAND NEW MailManager could inherit the id of a retired one, be
 * skipped as "already registered", and never learn kbb-server. The next send
 * then threw "Unsupported mail transport [kbb-server]".
 *
 * That is non-deterministic: it depends on allocation history, which any change
 * to the surrounding test count perturbs. It surfaced as MailTransportOrdering-
 * Test failing intermittently, and as a MySQL run reporting failures that four
 * other runs of the same commit did not. Under PHP-FPM one manager per request
 * mostly hides it; a queue worker that rebuilds the manager is where it costs a
 * real order email.
 *
 * This test reproduces the hazard rather than the symptom: it churns managers so
 * ids WILL be recycled, then checks that every one of them can still build the
 * transport.
 */
it('teaches the transport to every manager, even when php recycles object ids', function () {
    $ids = [];

    /*
     * Each manager is registered, exercised and released before the next is
     * built, which is exactly the pattern that makes PHP hand out the same id
     * again. If any manager is skipped, resolving the transport throws.
     */
    for ($i = 0; $i < 6; $i++) {
        $manager = new MailManager(app());

        MailConfigurator::registerTransports($manager);

        $ids[] = spl_object_id($manager);

        config()->set('mail.mailers.kbb-probe', ['transport' => ServerMailTransport::NAME]);

        expect(fn () => $manager->mailer('kbb-probe'))
            ->not->toThrow(InvalidArgumentException::class);

        unset($manager);
    }

    /*
     * The guard on the guard. If PHP stopped recycling ids, every manager would
     * get a distinct key and the loop above would pass no matter how the
     * registry were keyed — the test would still be green and prove nothing.
     */
    expect(count(array_unique($ids)))
        ->toBeLessThan(6, 'php handed out six distinct object ids, so this run never exercised the reuse this test exists for');
});
