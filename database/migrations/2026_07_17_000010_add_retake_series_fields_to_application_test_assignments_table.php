<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'application_test_assignments_job_application_id_test_id_unique';

    public function up(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_UNIQUE);

            $table->foreignId('series_root_assignment_id')->nullable()->after('id');
            $table->foreignId('previous_assignment_id')->nullable()->after('series_root_assignment_id');
            $table->unsignedSmallInteger('attempt_number')->default(1)->after('previous_assignment_id');
            $table->unsignedSmallInteger('max_attempts')->default(1)->after('attempt_number');
            $table->foreignId('retake_granted_by_user_id')->nullable()->after('assigned_by_user_id');
            $table->text('retake_reason')->nullable()->after('note');

            $table->foreign('series_root_assignment_id', 'test_assignments_series_root_fk')
                ->references('id')->on('application_test_assignments')->restrictOnDelete();
            $table->foreign('previous_assignment_id', 'test_assignments_previous_fk')
                ->references('id')->on('application_test_assignments')->restrictOnDelete();
            $table->foreign('retake_granted_by_user_id', 'test_assignments_retake_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();

            $table->unique(
                ['job_application_id', 'test_id', 'attempt_number'],
                'test_assignments_application_test_attempt_unique',
            );
            $table->unique('previous_assignment_id', 'test_assignments_previous_unique');
            $table->index(['series_root_assignment_id', 'attempt_number'], 'test_assignments_series_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table): void {
            $table->dropIndex('test_assignments_series_attempt_index');
            $table->dropUnique('test_assignments_previous_unique');
            $table->dropUnique('test_assignments_application_test_attempt_unique');
            $table->dropForeign('test_assignments_series_root_fk');
            $table->dropForeign('test_assignments_previous_fk');
            $table->dropForeign('test_assignments_retake_actor_fk');
            $table->dropColumn([
                'series_root_assignment_id',
                'previous_assignment_id',
                'attempt_number',
                'max_attempts',
                'retake_granted_by_user_id',
                'retake_reason',
            ]);

            $table->unique(['job_application_id', 'test_id'], self::OLD_UNIQUE);
        });
    }
};
