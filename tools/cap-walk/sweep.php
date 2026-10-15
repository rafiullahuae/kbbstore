<?php
/* Lane CAP preview: the left-cart reminder sweep, run as the cron would, with
   every live typed reminder's clock moved an hour back. Never ships (tools/). */
use Illuminate\Support\Facades\DB;
DB::table('cart_recoveries')->whereNull('cancelled_at')->update(['consented_at' => now()->subHour()]);
\Illuminate\Support\Facades\Cache::flush();
$sent1 = app(\App\Services\OutboundSender::class)->sendCartReminders(20);
$sent2 = app(\App\Services\OutboundSender::class)->sendCartReminders(20);
echo json_encode([
    'first_sweep_sent' => $sent1,
    'second_sweep_sent' => $sent2,
    'recoveries' => DB::table('cart_recoveries')->orderBy('cart_id')->get(['cart_id', 'email', 'source', 'stage', 'last_sent_at', 'cancel_reason'])->all(),
    'mail_log' => DB::table('mail_deliveries')->orderBy('id')->get()->map(fn ($r) => array_intersect_key((array) $r, array_flip(['id', 'kind', 'status', 'recipient', 'to', 'subject'])))->all(),
], JSON_PRETTY_PRINT)."\n";
