<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('dial_code')->nullable()->after('flag');
            $table->string('twilio_code', 10)->nullable()->after('dial_code'); // ISO-3166 alpha-2
            $table->decimal('price_usd', 8, 4)->default(1.00)->after('twilio_code');
            $table->boolean('is_active')->default(true)->after('price_usd');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['dial_code', 'twilio_code', 'price_usd', 'is_active']);
        });
    }
};
