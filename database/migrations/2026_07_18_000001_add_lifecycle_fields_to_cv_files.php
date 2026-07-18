<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cv_files', function (Blueprint $table) {
            $table->string('version_label', 150)->nullable()->after('original_name');
            $table->timestamp('archived_at')->nullable()->after('confirmed_at');
            $table->index(['user_id', 'archived_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('cv_files', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'archived_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['version_label', 'archived_at']);
        });
    }
};
