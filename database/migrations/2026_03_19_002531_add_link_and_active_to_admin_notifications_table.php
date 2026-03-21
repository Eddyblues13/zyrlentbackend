<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->string('link_url')->nullable()->after('message');
            $table->string('link_label')->nullable()->after('link_url');
            $table->boolean('is_active')->default(true)->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropColumn(['link_url', 'link_label', 'is_active']);
        });
    }
};
