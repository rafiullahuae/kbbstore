<?php

declare(strict_types=1);

use App\Services\Mail\ServerMailTransport;

/**
 * The suite must not hand a message to a real mail program.
 *
 * phpunit.xml sets MAIL_MAILER=array, which reads like it settles this. It does
 * not: nothing in this application sends through the DEFAULT mailer.
 * MailConfigurator writes a `kbb` mailer into the config at runtime and
 * OrderMailer sends through it by name, so the array transport is never
 * consulted and ServerMailTransport::deliver() runs instead. Eighteen checkout
 * tests were reaching PHP's mail() for real. On CI there is no MTA and the call
 * fails silently; on a developer machine with one, `vendor/bin/pest` sends real
 * email to whatever addresses the fixtures carry.
 *
 * These tests pin the guard that stops it, and — the part that matters — pin
 * that the guard is reached through the ordinary send path rather than only in
 * the abstract.
 */
it('routes a real send through the guard instead of PHP mail()', function () {
    ServerMailTransport::$lastTestDelivery = null;

    $transport = new ServerMailTransport;

    $email = (new \Symfony\Component\Mime\Email)
        ->from('shop@kbeautybliss.test')
        ->to('customer@example.com')
        ->subject('Your order is confirmed')
        ->text('Thank you.')
        ->html('<p>Thank you.</p>');

    $transport->send($email);

    // The guard fired, so mail() was never called...
    expect(ServerMailTransport::$lastTestDelivery)->not->toBeNull();

    // ...and everything above it really ran: the recipient, the subject and the
    // envelope sender were derived from the message, not stubbed.
    expect(ServerMailTransport::$lastTestDelivery['to'])->toContain('customer@example.com')
        ->and(ServerMailTransport::$lastTestDelivery['subject'])->toBe('Your order is confirmed')
        ->and(ServerMailTransport::$lastTestDelivery['params'])->toBe('-fshop@kbeautybliss.test')
        ->and(ServerMailTransport::$lastTestDelivery['body_bytes'])->toBeGreaterThan(0);

    // The two headers PHP writes itself are gone, or every message arrives with
    // both of them twice.
    $headers = ServerMailTransport::$lastTestDelivery['headers'];

    expect($headers)->not->toContain('To: ')
        ->and($headers)->not->toContain('Subject: ')
        ->and($headers)->toContain('From:');
});

it('keeps the guard out of the way of the argument spies', function () {
    // ServerMailDefaultTest asserts what reaches mail() by subclassing
    // deliver(). If the guard had been placed any higher — in send(), or in the
    // header surgery — those spies would be asserting against the guard's own
    // return value rather than against real header output, and would pass no
    // matter what the class did. This asserts the seam is still the seam.
    $spy = new class extends ServerMailTransport
    {
        public array $seen = [];

        protected function deliver(string $to, string $subject, string $body, string $headers, string $params): bool
        {
            $this->seen = compact('to', 'subject', 'body', 'headers', 'params');

            return true;
        }
    };

    ServerMailTransport::$lastTestDelivery = null;

    $spy->send(
        (new \Symfony\Component\Mime\Email)
            ->from('shop@kbeautybliss.test')
            ->to('someone@example.com')
            ->subject('Spied')
            ->text('body')
    );

    expect($spy->seen['subject'])->toBe('Spied')
        ->and($spy->seen['body'])->toContain('body')
        // The base guard did NOT run — the override took the call.
        ->and(ServerMailTransport::$lastTestDelivery)->toBeNull();
});

it('leaves no path from a checkout to PHP mail()', function () {
    // The end-to-end version. An order confirmation sent the way the
    // application sends it, through the named kbb mailer, must land in the
    // guard and not in mail().
    ServerMailTransport::$lastTestDelivery = null;

    app(\App\Services\Mail\MailConfigurator::class)->refresh();

    \Illuminate\Support\Facades\Mail::mailer(\App\Services\Mail\MailConfigurator::MAILER)
        ->raw('A receipt.', function ($m) {
            $m->to('shopper@example.com')->subject('Receipt');
        });

    expect(ServerMailTransport::$lastTestDelivery)->not->toBeNull()
        ->and(ServerMailTransport::$lastTestDelivery['to'])->toContain('shopper@example.com');
});
