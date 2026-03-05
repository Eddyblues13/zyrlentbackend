<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->string('sms_from')->nullable()->after('otp_code');
            $table->string('ip_address', 45)->nullable()->after('expires_at');
            $table->string('user_agent')->nullable()->after('ip_address');
            $table->timestamp('completed_at')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->dropColumn(['sms_from', 'ip_address', 'user_agent', 'completed_at']);
        });
    }
};
