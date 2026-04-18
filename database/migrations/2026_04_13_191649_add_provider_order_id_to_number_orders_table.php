<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->string('provider_order_id')->nullable()->after('provider_slug')
                  ->comment('External order ID from provider (e.g. 5sim order id)');
        });
    }

    public function down(): void
    {
        Schema::table('number_orders', function (Blueprint $table) {
            $table->dropColumn('provider_order_id');
        });
    }
};
