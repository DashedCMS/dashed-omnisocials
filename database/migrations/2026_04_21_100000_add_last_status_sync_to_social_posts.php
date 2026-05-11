<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__social_posts', function (Blueprint $table) {
            $table->timestamp('last_status_sync_at')->nullable()->after('analytics_synced_at');
            $table->index(['status', 'last_status_sync_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dashed__social_posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_status_sync_at']);
            $table->dropColumn('last_status_sync_at');
        });
    }
};
