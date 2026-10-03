<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Mail\Kit\WebCopy;
use Illuminate\Mail\Events\MessageSending;

/**
 * Keeps the browser copy of an email that is about to be sent — Lane RM.
 *
 * Found by Laravel's listener discovery (app/Listeners, the framework default
 * in bootstrap/app.php's Application::configure()), so no provider is edited.
 * The package's clear_caches migration drops bootstrap/cache/events.php for an
 * install that has cached its events.
 *
 * Does nothing at all for a message the kit footer did not mint a link into,
 * and never throws: WebCopy::capture() logs and swallows.
 */
final class StoreMailWebCopy
{
    public function handle(MessageSending $event): void
    {
        try {
            $html = $event->message->getHtmlBody();
        } catch (\Throwable) {
            return;
        }

        if (is_resource($html)) {
            $html = stream_get_contents($html);
        }

        WebCopy::capture(is_string($html) ? $html : null);
    }
}
