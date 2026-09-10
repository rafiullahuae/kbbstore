<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admin accounts for the store back-office (separate from storefront customers). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('password');                 // bcrypt/argon2 hash
            $t->string('role')->default('owner');   // owner|manager|staff
            $t->rememberToken();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('admin_users'); }
};
