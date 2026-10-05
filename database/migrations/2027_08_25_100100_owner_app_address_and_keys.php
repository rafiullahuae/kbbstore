<?php

declare(strict_types=1);

use App\Services\OwnerApp\OwnerAppPath;
use App\Services\OwnerApp\VapidKeys;
use Illuminate\Database\Migrations\Migration;

/*
 * The owner app's secret address and the shop's VAPID key pair (Lane MAC).
 *
 * Both are generated HERE, on the server, once: a random address nobody chose
 * (so nobody can guess it from the shop's name) and a P-256 key pair made by
 * PHP's own openssl. Neither is in the package. An address already set, or a
 * key pair already stored, is left alone — re-running this changes nothing.
 *
 * The address is printed so whoever applies the package sees it once; it is
 * also shown, any time, under Platform → Users & Roles → Owner app.
 */
return new class extends Migration
{
    public function up(): void
    {
        OwnerAppPath::forgetMemo();
        $path = OwnerAppPath::ensure();

        VapidKeys::forget();
        $keys = VapidKeys::pair();

        if (app()->runningInConsole()) {
            echo "Owner app address: /{$path}/ — open Platform → Users & Roles → Owner app to give people access.\n";
            echo $keys === null
                ? "Push keys could not be made (openssl has no P-256 here); the app works, notifications stay off.\n"
                : "Push keys made.\n";
        }
    }

    public function down(): void {}
};
