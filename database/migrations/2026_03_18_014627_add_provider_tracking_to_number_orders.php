<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->after('country_id')
                  ->constrained('api_providers')->nullOnDelete();
            $table->string('provider_slug')->nullable()->after('provider_id');
            $table->unsignedInteger('provider_response_ms')->nullable()->after('provider_slug');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('provider_response_ms');
            $table->json('routing_log')->nullable()->after('retry_count'); // logs each provider attempt
        });
    }

    public function down(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn(['provider_id', 'provider_slug', 'provider_response_ms', 'retry_count', 'routing_log']);
        });
    }
};
