<?php

declare(strict_types=1);

/*
 * One "phone" in OwnerAppRaceTest (Lane SEC): boots the app against the MySQL
 * database named in its environment, waits for a shared start instant so that
 * every child is inside OwnerAppAuth at the same moment, sends ONE PIN and
 * prints the status it got.
 *
 *   php tests/Support/oa-race-child.php <kind:unlock|enrol> <deviceId|email> <pin> <startAtMicrotime>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$self, $kind, $who, $pin, $startAt] = $argv + [null, '', '', '', '0'];

$request = Illuminate\Http\Request::create('/oa-race', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);

$late = microtime(true) > (float) $startAt;
while (microtime(true) < (float) $startAt) {
    usleep(200);
}

if ($kind === 'unlock') {
    $device = App\Models\OwnerAppDevice::query()->with('member.admin')->findOrFail((int) $who);
    $r = App\Services\OwnerApp\OwnerAppAuth::unlock($request, $device, $pin);
} else {
    $r = App\Services\OwnerApp\OwnerAppAuth::enrol($request, $who, $pin, 'race');
}

echo json_encode(['status' => $r['status'], 'late' => $late]), "\n";
