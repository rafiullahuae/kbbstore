<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-admin board layouts (Lane AN2). The owner: "the blocks should be
 * moveable to change the position as per my convenience." One row per admin
 * per board: the order of its block ids and the ones hidden, both allowlisted
 * by App\Services\Analytics\BoardLayout. Per USER, not per browser, so the
 * console and the owner app share it. `board` keeps the table reusable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_board_layouts')) {
            Schema::create('admin_board_layouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('admin_user_id');
                $t->string('board', 32);
                $t->text('layout');
                $t->timestamps();
                $t->unique(['admin_user_id', 'board']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_board_layouts');
    }
};
