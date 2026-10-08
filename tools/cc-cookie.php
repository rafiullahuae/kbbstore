<?php
// Prints the encrypted kbb_cart cookie value for the fixed token (same APP_KEY as spd-preview).
$name = 'kbb_cart'; $v = '0c0c0c0c-0c0c-4c0c-8c0c-0c0c0c0c0c0c';
echo encrypt(\Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$v, false), "\n";
