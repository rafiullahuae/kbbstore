<?php

declare(strict_types=1);

use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\Mail\ServerMailTransport;
use Illuminate\Support\Facades\Mail;

/**
 * "Unsupported mail transport [kbb-server]".
 *
 * That is what the owner got from Send test message on the live server, while
 * every test here passed. The transport was registered only from
 * MailServiceProvider, inside afterResolving('mail.manager') — which fires
 * only for resolutions that happen AFTER the provider registers its callback.
 * mail.manager is a singleton, so anything that resolved it earlier in the
 * request got an instance the callback never touched, and the first symptom
 * was an exception naming the driver rather than the ordering.
 *
 * It did not reproduce in the suite because the test bootstrap never resolves
 * the manager that early. So the test below forces the ordering instead of
 * hoping for it: it throws away the registration the provider made and then
 * asks the application to send, which is exactly the state a live request can
 * arrive in.
 */
beforeEach(function () {
    app(MailSettings::class)->save([
        'mail_transport' => 'server',
        'mail_from_address' => 'shop@kbeautybliss.test',
        'mail_from_name' => 'KBB',
    ]);
});

it('builds the mailer even when the provider callback never ran', function () {
    /*
     * A fresh MailManager has no custom creators at all — precisely the state
     * the singleton is in when it was resolved before the provider registered.
     * Swapping one in reproduces the live failure deterministically.
     */
    app()->forgetInstance('mail.manager');
    app()->instance('mail.manager', new Illuminate\Mail\MailManager(app()));

    // Nothing has registered kbb-server on this manager.
    app(MailConfigurator::class)->refresh();

    $mailer = Mail::mailer(MailConfigurator::MAILER);

    expect($mailer)->toBeInstanceOf(Illuminate\Mail\Mailer::class)
        ->and($mailer->getSymfonyTransport())->toBeInstanceOf(ServerMailTransport::class);
});

it('sends through the server transport after the same forgetting', function () {
    app()->forgetInstance('mail.manager');
    app()->instance('mail.manager', new Illuminate\Mail\MailManager(app()));

    ServerMailTransport::$lastTestDelivery = null;

    app(MailConfigurator::class)->refresh();

    // The end-to-end shape: what Send test message actually does.
    Mail::mailer(MailConfigurator::MAILER)->raw('A test message.', function ($m) {
        $m->to('owner@example.com')->subject('Test');
    });

    expect(ServerMailTransport::$lastTestDelivery)->not->toBeNull()
        ->and(ServerMailTransport::$lastTestDelivery['to'])->toContain('owner@example.com');
});

it('registers the transport from apply(), not only from the provider', function () {
    // The structural half: apply() must do the registration itself, so the
    // ordering cannot come back the next time a provider is reshuffled.
    $source = (string) file_get_contents(app_path('Services/Mail/MailConfigurator.php'));

    preg_match('/public function apply\(\): void\s*\{(.*?)\n    \}/s', $source, $m);
    $apply = $m[1] ?? '';

    expect($apply)->not->toBe('', 'apply() could not be found')
        ->and($apply)->toContain('registerTransports');
});
