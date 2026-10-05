<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.60.388: the owner saw "…banner.jpg" under a picture in the Media Library
 * and took the WebP conversion for broken. A converted upload kept the name it
 * was SENT with as its caption. New uploads now record the .webp name; this
 * gives the rows already converted the same, so every caption tells the truth.
 * Only `media.original_name` (a caption and search text) changes, only where
 * the file itself is a .webp. Then the compiled views, for the upload kit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renamed = 0;

        if (Schema::hasTable('media') && Schema::hasColumn('media', 'original_name')) {
            DB::table('media')
                ->where('path', 'like', '%.webp')
                ->where(function ($q): void {
                    $q->where('original_name', 'like', '%.jpg')
                        ->orWhere('original_name', 'like', '%.jpeg')
                        ->orWhere('original_name', 'like', '%.png')
                        ->orWhere('original_name', 'like', '%.JPG')
                        ->orWhere('original_name', 'like', '%.JPEG')
                        ->orWhere('original_name', 'like', '%.PNG');
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) use (&$renamed): void {
                    foreach ($rows as $row) {
                        $name = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', (string) $row->original_name);
                        DB::table('media')->where('id', $row->id)->update(['original_name' => $name]);
                        $renamed++;
                    }
                });
        }

        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "WebP: {$renamed} converted picture(s) now listed under their .webp name.\n";
        }
    }

    public function down(): void {}
};
