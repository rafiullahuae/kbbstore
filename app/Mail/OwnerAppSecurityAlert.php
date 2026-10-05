<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Somebody is guessing a PIN in the owner app" — to every Full Admin
 * (Lane SEC).
 *
 * NO ADDRESS IN IT, ON PURPOSE. Not the app's secret path, not the admin's,
 * not a link of any kind: mail is the least controlled channel this shop has
 * (NewOrderAlert says why at length), and the one thing an attacker guessing
 * PINs does not have is the address. The body names the member, the device,
 * the time, the connection, and where in the admin to act — words, not a URL.
 *
 * Every line is escaped: the member's name and the device's name were typed
 * by people, and the device name by whoever enrolled the phone.
 */
final class OwnerAppSecurityAlert extends Mailable
{
    use Queueable;

    /** @param list<string> $lines plain text, escaped here */
    public function __construct(public readonly string $subjectLine, public readonly array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222">';
        foreach ($this->lines as $line) {
            $html .= '<p style="margin:0 0 10px">'.e($line).'</p>';
        }

        return new Content(htmlString: $html.'</div>');
    }
}
