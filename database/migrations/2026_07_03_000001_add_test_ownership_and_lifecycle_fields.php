<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('company_id')->constrained('users')->nullOnDelete();
            $table->string('visibility', 30)->default('global')->after('created_by_user_id');
            $table->unsignedInteger('version')->default(1)->after('visibility');
            $table->foreignId('parent_test_id')->nullable()->after('version')->constrained('tests')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('parent_test_id');

            $table->index(['company_id', 'is_active'], 'tests_company_active_idx');
            $table->index(['visibility', 'is_active'], 'tests_visibility_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tests', function (Blueprint $table) {
            $table->dropIndex('tests_company_active_idx');
            $table->dropIndex('tests_visibility_active_idx');
            $table->dropConstrainedForeignId('parent_test_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['visibility', 'version', 'locked_at']);
        });
    }
};
