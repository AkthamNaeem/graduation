<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_test_assignment_deadline_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_test_assignment_id')
                ->constrained(indexName: 'atadc_assignment_fk')
                ->cascadeOnDelete();
            $table->timestamp('previous_deadline_at')->nullable();
            $table->timestamp('new_deadline_at');
            $table->foreignId('changed_by_user_id')
                ->constrained('users', indexName: 'atadc_changed_by_fk')
                ->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['application_test_assignment_id', 'created_at'], 'assignment_deadline_changes_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_test_assignment_deadline_changes');
    }
};
