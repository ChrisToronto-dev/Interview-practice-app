<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            // Nullable — only populated for TTS calls (tracks Google TTS character usage)
            $table->unsignedInteger('char_count')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropColumn('char_count');
        });
    }
};
