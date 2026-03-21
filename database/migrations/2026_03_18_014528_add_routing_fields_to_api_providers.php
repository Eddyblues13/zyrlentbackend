<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->unsignedInteger('priority')->default(10)->after('is_active');
            $table->decimal('success_rate', 5, 2)->default(100.00)->after('priority');
            $table->unsignedInteger('avg_response_ms')->default(0)->after('success_rate');
            $table->unsignedBigInteger('total_requests')->default(0)->after('avg_response_ms');
            $table->unsignedBigInteger('total_successes')->default(0)->after('total_requests');
            $table->unsignedBigInteger('total_failures')->default(0)->after('total_successes');
            $table->decimal('cost_multiplier', 5, 2)->default(1.00)->after('total_failures');
            $table->string('routing_mode')->default('priority')->after('cost_multiplier'); // priority | cheapest | smart
        });
    }

    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->dropColumn([
                'priority', 'success_rate', 'avg_response_ms',
                'total_requests', 'total_successes', 'total_failures',
                'cost_multiplier', 'routing_mode',
            ]);
        });
    }
};
