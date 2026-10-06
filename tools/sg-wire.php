<?php
// Lane SG wiring CHECK (changes nothing). docs/sg-wiring.json is an EMPTY list
// (the format every docs/*-wiring.json shares, read by NotFoundPageWiringTest):
// no edit is needed, because the routes are in routes/spotted-admin.php and the
// picker in admin/partials/spotted-screen.blade.php, both already mounted once,
// and the schedule is in routes/console.php. This proves those mounts exist
// exactly once. The scheduler needs the ONE existing cron line:
//   * * * * * cd /home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app && php artisan schedule:run >> /dev/null 2>&1
$root = dirname(__DIR__);
$checks = [
    ['routes/web.php', "require __DIR__.'/spotted-admin.php';"],
    ['resources/views/admin/app.blade.php', "@include('admin.partials.spotted-screen')"],
    ['bootstrap/app.php', "commands: __DIR__.'/../routes/console.php'"],
    ['routes/console.php', "Schedule::command('kbb:instagram-sync --unattended')"],
    ['routes/spotted-admin.php', "Route::get('/spotted/instagram'"],
];
$bad = 0;
foreach ($checks as [$file, $needle]) {
    $n = substr_count((string) @file_get_contents($root.'/'.$file), $needle);
    echo ($n === 1 ? 'ok   ' : 'FAIL ').$file.' :: '.$needle.' ('.$n.")\n";
    $bad += $n === 1 ? 0 : 1;
}
exit($bad === 0 ? 0 : 1);
