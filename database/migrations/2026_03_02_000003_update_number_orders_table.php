<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->string('order_ref')->unique()->nullable()->after('id');
            $table->string('phone_number')->nullable()->after('country_id');
            $table->string('twilio_sid')->nullable()->after('phone_number'); // Twilio IncomingPhoneNumber SID
            $table->text('otp_code')->nullable()->after('twilio_sid');     // Full SMS body received
            $table->timestamp('expires_at')->nullable()->after('otp_code');
        });
    }

    public function down(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->dropColumn(['order_ref', 'phone_number', 'twilio_sid', 'otp_code', 'expires_at']);
        });
    }
};
