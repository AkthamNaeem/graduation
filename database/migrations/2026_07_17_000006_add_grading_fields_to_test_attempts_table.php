<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->decimal('objective_score', 10, 2)->nullable()->after('submitted_at');
            $table->decimal('objective_max_score', 10, 2)->nullable()->after('objective_score');
            $table->decimal('manual_score', 10, 2)->nullable()->after('objective_max_score');
            $table->decimal('manual_max_score', 10, 2)->nullable()->after('manual_score');
            $table->decimal('total_score', 10, 2)->nullable()->after('manual_max_score');
            $table->decimal('max_score', 10, 2)->nullable()->after('total_score');
            $table->decimal('percentage', 5, 2)->nullable()->after('max_score');
            $table->string('grading_status', 40)->default('pending')->after('percentage');
            $table->timestamp('auto_graded_at')->nullable()->after('grading_status');
            $table->timestamp('manually_graded_at')->nullable()->after('auto_graded_at');
        });
    }

    public function down(): void
    {
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'objective_score',
                'objective_max_score',
                'manual_score',
                'manual_max_score',
                'total_score',
                'max_score',
                'percentage',
                'grading_status',
                'auto_graded_at',
                'manually_graded_at',
            ]);
        });
    }
};
