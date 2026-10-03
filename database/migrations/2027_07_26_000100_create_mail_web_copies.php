<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "View this email in your browser" (Lane RM): one row per sent message that
 * carries the link. See App\Services\Mail\Kit\WebCopy.
 *
 * token_hash  SHA-256 of the 256-bit token in the link; the token itself is
 *             never stored, so this table cannot be read back into links.
 * body        the sent HTML, deflated and base64'd (about a fifth the size).
 * expires_at  WebCopy::DAYS after sending; expired rows answer 404 and are
 *             pruned as new rows arrive.
 *
 * Idempotent: packages are sometimes applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_web_copies')) {
            return;
        }

        Schema::create('mail_web_copies', function (Blueprint $t) {
            $t->id();
            $t->char('token_hash', 64)->unique();
            $t->longText('body');
            $t->timestamp('expires_at')->index();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_web_copies');
    }
};
