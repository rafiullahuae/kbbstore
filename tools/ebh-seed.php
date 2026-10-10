<?php
/*
 * Seed the Lane EB preview (Bounces & unsubscribes): an owner and a manager,
 * Google Workspace as the transport, the bounce mailbox switched on, and a
 * realistic mix of bounced, unsubscribed, complained and soft-bouncing
 * addresses. Written into the PREVIEW's database only.
 */
use App\Services\Marketing\Bounces\BounceBook;
use Illuminate\Support\Facades\DB;

foreach (['owner', 'manager'] as $role) {
    \App\Models\AdminUser::updateOrCreate(['email' => $role.'@preview.test'],
        ['name' => 'Preview '.ucfirst($role), 'password' => 'preview-secret-1', 'role' => $role]);
}

$s = app(\App\Services\SettingsService::class);
$s->set('mail_transport', 'gmail');
$s->set('mail_gmail_username', 'info@kbeautybliss.com');
$s->set('mail_from_name', 'K Beauty Bliss');
app(\App\Services\Mail\MailCredentials::class)->put('gmail_password', 'abcdefghijklmnop');
$s->set('mkt_bounce_imap_enabled', '1', false);
$s->set('mail_last_dns_check', [
    'domain' => 'kbeautybliss.com', 'at' => now()->subMinutes(3)->toIso8601String(),
    'records' => [
        ['record' => 'SPF', 'host' => 'kbeautybliss.com', 'status' => 'ok', 'found' => 'v=spf1 include:_spf.google.com ~all', 'hint' => ''],
        ['record' => 'DKIM', 'host' => 'google._domainkey.kbeautybliss.com', 'status' => 'missing', 'found' => null, 'hint' => 'Google Admin → Apps → Google Workspace → Gmail → Authenticate email: generate the key, add the TXT record, then press Start authentication.'],
        ['record' => 'DMARC', 'host' => '_dmarc.kbeautybliss.com', 'status' => 'ok', 'found' => 'v=DMARC1; p=none; rua=mailto:info@kbeautybliss.com', 'hint' => ''],
    ],
], false);

$seg = DB::table('mkt_segments')->insertGetId(['name' => 'All customers', 'audience' => 'customers', 'match' => 'all', 'rules' => '[]', 'created_at' => now(), 'updated_at' => now()]);
$camp = [];
foreach (['October new arrivals', 'This week\'s special', 'Best sellers'] as $i => $name) {
    $camp[] = DB::table('mkt_campaigns')->insertGetId(['name' => $name, 'subject' => $name, 'status' => 'sent', 'segment_id' => $seg, 'blocks' => '[]', 'created_at' => now()->subDays(9 - $i * 3), 'updated_at' => now()]);
}

$book = app(BounceBook::class);
$hard = [
    ['noura.k@gmial.com', '5.1.1', '550-5.1.1 The email account that you tried to reach does not exist. Please try double-checking the recipient\'s email address for typos or unnecessary spaces.'],
    ['fatima.alzaabi@hotmial.com', '5.1.2', '550 5.1.2 Host unknown (Name server: hotmial.com: host not found)'],
    ['old.account@yahoo.com', '5.1.1', '554 delivery error: dd This user doesn\'t have a yahoo.com account (old.account@yahoo.com) [0] - mta1004.mail.ir2.yahoo.com'],
    ['reem@company-closed.ae', '5.4.4', 'Unable to route: no mail hosts for domain'],
    ['m.hassan@outlook.com', '5.1.10', '550 5.1.10 RESOLVER.ADR.RecipientNotFound; Recipient m.hassan@outlook.com not found by SMTP address lookup'],
];
foreach ($hard as $i => [$email, $code, $why]) {
    $book->record(['email' => $email, 'kind' => 'hard', 'code' => $code, 'detail' => $why, 'campaign_id' => $camp[$i % 3], 'source' => 'dsn', 'report_id' => 'seed-h'.$i]);
    DB::table('email_bounces')->where('email', $email)->update(['created_at' => now()->subHours(5 + $i * 17)]);
    DB::table('email_suppressions')->where('email', $email)->update(['created_at' => now()->subHours(5 + $i * 17)]);
}
foreach (['s1', 's2', 's3'] as $j => $r) {
    $book->record(['email' => 'aisha.full@gmail.com', 'kind' => 'soft', 'code' => '5.2.2', 'detail' => '552-5.2.2 The recipient\'s inbox is out of storage space.', 'campaign_id' => $camp[$j], 'source' => 'dsn', 'report_id' => 'seed-'.$r]);
}
$book->record(['email' => 'layla.m@icloud.com', 'kind' => 'soft', 'code' => '4.2.2', 'detail' => 'Mailbox full', 'source' => 'dsn', 'report_id' => 'seed-w1']);
$book->record(['email' => 'sara.design@gmail.com', 'kind' => 'soft', 'code' => '4.4.7', 'detail' => 'Message expired', 'source' => 'dsn', 'report_id' => 'seed-w2']);
$book->record(['email' => 'sara.design@gmail.com', 'kind' => 'soft', 'code' => '4.2.2', 'detail' => 'Mailbox full', 'source' => 'dsn', 'report_id' => 'seed-w3']);
$book->record(['email' => 'unhappy@yahoo.com', 'kind' => 'complaint', 'detail' => 'abuse', 'campaign_id' => $camp[0], 'source' => 'arf', 'report_id' => 'seed-c1']);

foreach (['hana@example.ae' => 'unsubscribe', 'dubai.mum@gmail.com' => 'unsubscribe'] as $e => $why) {
    DB::table('email_suppressions')->insert(['email' => $e, 'reason' => $why, 'source' => 'campaign:'.$camp[1], 'created_at' => now()->subDays(2)]);
}
DB::table('subscribers')->insert(['email' => 'news.reader@gmail.com', 'source' => 'homepage', 'status' => 'unsubscribed', 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(4)]);
DB::table('outbound_optouts')->insert(['email' => 'basket.person@gmail.com', 'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6)]);

@file_put_contents(storage_path('framework/kbb-bounces.json'), json_encode(['ran' => true, 'ok' => true, 'read' => 4, 'hard' => 1, 'soft' => 1, 'delay' => 1, 'complaint' => 0, 'unsubscribe' => 0, 'ignored' => 1, 'left' => 0, 'at' => now()->subMinutes(2)->toIso8601String()]));
echo "seeded\n";
