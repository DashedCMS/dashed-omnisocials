<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('dashed__social_channels', function (Blueprint $table) {
            $table->string('omnisocials_account_id')->nullable()->after('is_active');
            $table->string('omnisocials_platform')->nullable()->after('omnisocials_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('dashed__social_channels', function (Blueprint $table) {
            $table->dropColumn(['omnisocials_account_id', 'omnisocials_platform']);
        });
    }
};
