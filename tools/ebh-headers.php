<?php
/*
 * Lane EB: print the headers of one rendered marketing email, exactly as
 * CampaignSender builds it (signed Message-ID, both List-Unsubscribe entries,
 * Feedback-ID), through Laravel's array mailer. Preview database only.
 *   php artisan tinker --execute="require 'tools/ebh-headers.php';"
 */
use App\Mail\CampaignMail;
use App\Services\Marketing\Bounces\BounceMailbox;
use App\Services\Marketing\Bounces\BounceRef;
use App\Services\Marketing\UnsubscribeToken;
use App\Support\Url;

app(App\Services\Mail\MailConfigurator::class)->apply();
$sendId = 1234; $to = 'sara@example.com'; $campaign = 7;
$token = UnsubscribeToken::for($sendId, $to);
$mailto = app(BounceMailbox::class)->unsubscribeAddress();
$mail = new CampaignMail('New arrivals this week, Sara', '<p>Hello Sara</p><p><a href="'.Url::external('/email/u/'.$token).'">Unsubscribe</a></p>',
    "Hello Sara\n\nUnsubscribe: ".Url::external('/email/u/'.$token), Url::external('/email/u/'.$token), 'K Beauty Bliss',
    BounceRef::messageId($sendId, $to, 'kbeautybliss.com'),
    $mailto !== '' ? 'mailto:'.$mailto.'?subject='.rawurlencode('unsubscribe '.$token) : null, $campaign);
$m = Illuminate\Support\Facades\Mail::mailer('array');
$m->to($to)->send($mail);
$msg = $m->getSymfonyTransport()->messages()[0]->getOriginalMessage();
echo $msg->getHeaders()->toString();
echo "\n[parts] ".implode(', ', array_map(fn ($p) => $p->getMediaType().'/'.$p->getMediaSubtype(), $msg->getBody()->getParts()))."\n";
