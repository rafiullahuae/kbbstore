<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('products', function (Blueprint $t) {
            if (!Schema::hasColumn('products', 'seo_json')) $t->text('seo_json')->nullable();
        });
    }
    public function down(): void {
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'seo_json')) $t->dropColumn('seo_json');
        });
    }
};
