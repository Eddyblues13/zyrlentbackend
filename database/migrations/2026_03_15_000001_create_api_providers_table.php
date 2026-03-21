<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // e.g. "Twilio", "5SIM", "SMSPVA"
            $table->string('slug')->unique();             // e.g. "twilio", "5sim", "smspva"
            $table->string('type');                        // provider type: "twilio", "5sim", "smspva"
            $table->json('credentials')->nullable();       // encrypted JSON: {"account_sid":"...","auth_token":"..."}
            $table->json('settings')->nullable();          // provider-specific config: {"phone_number":"+1..","webhook_secret":"..."}
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('capabilities')->nullable();      // ["countries","numbers","pricing"]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_providers');
    }
};
