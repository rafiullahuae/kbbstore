<?php
// Lane SG wiring CHECK (changes nothing): docs/sg-wiring.json says no edit is
// needed; this proves the three mounts it relies on are each present exactly once.
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
