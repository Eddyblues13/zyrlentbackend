<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number')->unique();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('operator')->nullable();       // e.g. "MTN", "Vodafone", "AT&T"
            $table->string('provider')->default('twilio'); // twilio, 5sim, smspva, manual
            $table->string('provider_sid')->nullable();    // external provider ID (e.g. Twilio SID)
            $table->enum('status', ['available', 'in_use', 'reserved', 'expired', 'retired'])->default('available');
            $table->decimal('cost_price', 10, 4)->default(0);  // what we pay for it
            $table->decimal('sell_price', 10, 4)->default(0);  // what we charge users
            $table->foreignId('assigned_order_id')->nullable()->constrained('number_orders')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('times_used')->default(0);
            $table->integer('max_uses')->default(1);       // how many times this number can be reused
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('provider');
            $table->index('operator');
            $table->index(['country_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
