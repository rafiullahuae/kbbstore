<?php
/*
 * Lane EK — on top of tools/rj-seed.php (the previews' order KBB-10427 and the
 * owner account): a manager, the footer addresses typed, a few sent-mail rows
 * so "Last sent" reads like the mock, and product pictures served by the
 * preview. PREVIEW DATABASE ONLY.
 */
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;

AdminUser::updateOrCreate(['email' => 'manager@preview.test'], ['name' => 'Sara', 'password' => 'preview-secret-1', 'role' => 'manager']);

app(\App\Services\Mail\MailSettings::class)->save([
    'mail_support_email' => 'info@kbeautybliss.com',
    'mail_support_whatsapp' => '+971 58 505 2611',
]);
\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();

foreach ([['order.confirmation', 0], ['order.status_shipped', 0], ['order.status_cancelled', 1], ['order.refund', 6], ['order.merchant_alert', 0], ['account.password_reset', 2], ['account.verify_email', 3], ['customer.invite', 14], ['newsletter.confirm', 4], ['quiz.plan', 5]] as [$kind, $days]) {
    DB::table('mail_deliveries')->insert(['kind' => $kind, 'recipient' => 'aisha.khan@example.com', 'subject' => 'Preview', 'transport' => 'server', 'status' => 'sent',
        'created_at' => now()->subDays($days)->setTime(9, 41), 'updated_at' => now()->subDays($days)->setTime(9, 41)]);
}

// Real pictures for the order's products, so the preview's items have photos.
foreach (\App\Models\Product::query()->get() as $i => $p) {
    $rel = 'uploads/ek/p' . $p->id . '.jpg';
    @mkdir(public_path('uploads/ek'), 0755, true);
    $im = imagecreatetruecolor(400, 400);
    imagefilledrectangle($im, 0, 0, 400, 400, imagecolorallocate($im, 253, 236, 241));
    imagefilledrectangle($im, 150, 90, 250, 330, imagecolorallocate($im, 70 + ($i * 53) % 160, 140, 170));
    imagejpeg($im, public_path($rel), 85);
    imagedestroy($im);
    $p->image = '/' . $rel;
    $p->save();
}
echo "ek seed done\n";
