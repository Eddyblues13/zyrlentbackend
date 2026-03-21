<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_key', 64)->nullable()->unique()->after('referral_code');
            $table->boolean('is_reseller')->default(false)->after('api_key');
            $table->timestamp('last_active_at')->nullable()->after('is_reseller');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'is_reseller', 'last_active_at']);
        });
    }
};
