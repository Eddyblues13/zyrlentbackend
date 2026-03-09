<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            // Naira price for display (admin sets this)
            $table->decimal('price', 12, 2)->nullable()->after('price_usd');
            // Number of available SMS numbers (admin-managed estimate)
            $table->unsignedInteger('available_numbers')->default(200)->after('price');
        });

        Schema::table('services', function (Blueprint $table) {
            // Sort order / boost for popular services (higher = shown first)
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['price', 'available_numbers']);
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
