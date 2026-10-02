<?php
/*
 * Seed for the Lane PQ preview: Store -> Customers -> Send account invite.
 *
 *   1,040 guest customers (no password) -- the shape a WooCommerce import of
 *   guest checkouts leaves -- plus 12 with a password and 6 with only a
 *   WordPress hash, so "already have a password — skipped" has something to
 *   count, and two guests with addresses no email can be sent to.
 *
 *   Three guests invited days ago (one of them activated), so the list shows
 *   both pills before anything is pressed.
 *
 *   One REAL invite sent through the real sender, to "Mariam Al Haddad", on the
 *   in-memory transport: its HTML is written to the webroot as
 *   pq-sent-email.html (the email as sent) and its link to pq-link.txt, which
 *   the shots follow to the set-password page.
 *
 * Mail is set to the `log` transport so the bulk send in the shots really runs
 * every batch without anything leaving the machine.
 */
use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\CustomerInvites\CustomerInviter;
use App\Services\Mail\MailConfigurator;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);
AdminUser::updateOrCreate(['email' => 'support@preview.test'], [
    'name' => 'Preview Support', 'password' => 'preview-secret-1', 'role' => 'support',
]);

app(SettingsService::class)->set('store_name', 'K Beauty Bliss');
app(SettingsService::class)->set('mail_transport', 'log');

$first = ['Mariam', 'Aisha', 'Fatima', 'Noor', 'Layla', 'Sara', 'Hessa', 'Reem', 'Amna', 'Shamma', 'Priya', 'Anna', 'Grace', 'Jessica', 'Huda', 'Dana', 'Lina', 'Rania', 'Mona', 'Yasmin'];
$last = ['Al Haddad', 'Khan', 'Al Mansoori', 'Rahman', 'Haddad', 'Nair', 'Smith', 'Ali', 'Al Suwaidi', 'Fernandes', 'Hussain', 'Kaur', 'Al Ketbi', 'Joseph', 'Saleh'];

$rows = [];
$now = now();
for ($i = 1; $i <= 1040; $i++) {
    $f = $first[$i % count($first)];
    $l = $last[intdiv($i, count($first)) % count($last)];
    $rows[] = [
        'name' => $f . ' ' . $l,
        'first_name' => $f,
        'last_name' => $l,
        'email' => strtolower($f . '.' . str_replace(' ', '', $l)) . $i . '@example.com',
        'created_at' => $now->copy()->subDays(400 - ($i % 400)),
        'updated_at' => $now,
    ];
}
foreach (array_chunk($rows, 200) as $chunk) {
    DB::table('customers')->insert($chunk);
}

for ($i = 1; $i <= 12; $i++) {
    Customer::create(['name' => 'Account Holder ' . $i, 'email' => 'account' . $i . '@example.com', 'password' => 'has-a-password-' . $i]);
}
for ($i = 1; $i <= 6; $i++) {
    Customer::create(['name' => 'WordPress Customer ' . $i, 'email' => 'wp' . $i . '@example.com', 'legacy_password' => '$P$Bexamplephpasshash' . $i . 'XXXXXXXXXX.']);
}
DB::table('customers')->insert([
    ['name' => 'No Email On Record', 'email' => 'not-an-address', 'created_at' => $now, 'updated_at' => $now],
    ['name' => 'Broken Address', 'email' => 'broken@@example.com', 'created_at' => $now, 'updated_at' => $now],
]);

// Invited days ago; one of them has since set a password from it.
$a = Customer::create(['name' => 'Huda Saleh', 'first_name' => 'Huda', 'email' => 'huda.saleh@example.com']);
$a->forceFill(['invited_at' => $now->copy()->subDays(3), 'invite_count' => 1])->save();
$b = Customer::create(['name' => 'Grace Joseph', 'first_name' => 'Grace', 'email' => 'grace.joseph@example.com']);
$b->forceFill(['invited_at' => $now->copy()->subDays(5), 'invite_count' => 2])->save();
$c = Customer::create(['name' => 'Rania Ali', 'first_name' => 'Rania', 'email' => 'rania.ali@example.com', 'password' => 'activated-from-invite']);
$c->forceFill(['invited_at' => $now->copy()->subDays(6), 'invite_count' => 1, 'invite_accepted_at' => $now->copy()->subDays(5), 'email_verified_at' => $now->copy()->subDays(5)])->save();

// One real invite, through the real sender, captured off the array transport.
$mariam = Customer::create(['name' => 'Mariam Al Haddad', 'first_name' => 'Mariam', 'last_name' => 'Al Haddad', 'email' => 'mariam.alhaddad@example.com']);

app('mail.manager');
config()->set('mail.mailers.' . MailConfigurator::MAILER, ['transport' => 'array']);
Mail::purge(MailConfigurator::MAILER);

$inviter = app(CustomerInviter::class);
$template = app(\App\Services\CustomerInvites\InviteTemplate::class)->current();
$run = $inviter->start([$mariam->id], $template['subject'], $template['body'], 7, false, null);
$inviter->step($run);

$sent = Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport()->messages()->all();
$msg = $sent[0]->getOriginalMessage();
$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
file_put_contents($root . '/pq-sent-email.html',
    '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Sent: ' . e($msg->getSubject()) . '</title></head><body style="margin:0;background:#f4f4f5">'
    . '<div style="max-width:640px;margin:0 auto;background:#fff;padding:16px 18px;font:13px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#555;border-bottom:1px solid #eee">'
    . '<div><b>From:</b> ' . e(implode(', ', array_map(fn ($a) => $a->toString(), $msg->getFrom()))) . '</div>'
    . '<div><b>To:</b> ' . e($sent[0]->getEnvelope()->getRecipients()[0]->getAddress()) . '</div>'
    . '<div><b>Subject:</b> ' . e($msg->getSubject()) . '</div></div>'
    . '<div style="max-width:640px;margin:0 auto;background:#fff;padding:18px">' . $msg->getHtmlBody() . '</div></body></html>');
preg_match('#https?://[^"\s<]*/my-account/welcome/[a-f0-9]{64}/#', (string) $msg->getHtmlBody(), $m);
file_put_contents($root . '/pq-link.txt', $m[0] ?? '');
file_put_contents($root . '/pq-sent-email.txt', (string) $msg->getTextBody());

// A second live link, for the 390px picture of the form (the 1280 run spends
// Mariam's by using it).
$layla = Customer::create(['name' => 'Layla Nair', 'first_name' => 'Layla', 'email' => 'layla.nair@example.com']);
$run2 = $inviter->start([$layla->id], $template['subject'], $template['body'], 7, false, null);
$inviter->step($run2);
$sent2 = Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport()->messages()->all();
preg_match('#https?://[^"\s<]*/my-account/welcome/[a-f0-9]{64}/#', (string) end($sent2)->getOriginalMessage()->getHtmlBody(), $m2);
file_put_contents($root . '/pq-link-2.txt', $m2[0] ?? '');

echo 'customers: ' . Customer::count() . ', invite link: ' . ($m[0] ?? 'NONE') . "\n";
