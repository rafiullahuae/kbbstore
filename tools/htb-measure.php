<?php
/*
 * Lane HTB -- one shop request through the HTTP kernel in a FRESH process (as
 * PHP-FPM serves it: no static memo survives), against the htb-preview
 * database and its FILE cache. Prints path, status, queries, HTML bytes, ms.
 * PREVIEW ONLY:
 *
 *   sh tools/htb-artisan.sh tinker --execute="\$p='/'; require 'tools/htb-measure.php';"
 */
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
\Illuminate\Support\Facades\DB::enableQueryLog();
$t = microtime(true);
$res = $kernel->handle(\Illuminate\Http\Request::create($p, 'GET', [], [], [], ['HTTP_HOST' => '127.0.0.1']));
$ms = (microtime(true) - $t) * 1000;
$q = count(\Illuminate\Support\Facades\DB::getQueryLog());
echo 'HTB '.$p.' status='.$res->getStatusCode().' queries='.$q.' bytes='.strlen((string) $res->getContent()).' ms='.round($ms, 1).PHP_EOL;
