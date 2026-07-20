<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_screening_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('question_type', 32);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['job_posting_id', 'is_active', 'sort_order'],
                'job_screening_questions_active_order_idx',
            );
        });

        Schema::create('job_screening_question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_screening_question_id')->constrained()->cascadeOnDelete();
            $table->string('option_text', 1000);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['job_screening_question_id', 'sort_order'],
                'job_screening_options_order_idx',
            );
        });

        Schema::create('job_application_screening_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_question_id')->nullable();
            $table->text('question_text');
            $table->string('question_type', 32);
            $table->boolean('is_required');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->foreign('source_question_id', 'jas_questions_source_fk')
                ->references('id')
                ->on('job_screening_questions')
                ->nullOnDelete();
            $table->unique(
                ['job_application_id', 'source_question_id'],
                'jas_questions_application_source_uq',
            );
            $table->index(
                ['job_application_id', 'sort_order'],
                'jas_questions_application_order_idx',
            );
        });

        Schema::create('job_application_screening_question_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_question_id');
            $table->unsignedBigInteger('source_option_id')->nullable();
            $table->string('option_text', 1000);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->foreign('application_question_id', 'jasq_options_question_fk')
                ->references('id')
                ->on('job_application_screening_questions')
                ->cascadeOnDelete();
            $table->foreign('source_option_id', 'jasq_options_source_fk')
                ->references('id')
                ->on('job_screening_question_options')
                ->nullOnDelete();
            $table->unique(
                ['application_question_id', 'source_option_id'],
                'jasq_options_question_source_uq',
            );
            $table->index(
                ['application_question_id', 'sort_order'],
                'jasq_options_question_order_idx',
            );
        });

        Schema::create('job_application_screening_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('application_question_id');
            $table->longText('text_value')->nullable();
            $table->decimal('number_value', 20, 6)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->timestamps();

            $table->foreign('application_question_id', 'jas_answers_question_fk')
                ->references('id')
                ->on('job_application_screening_questions')
                ->cascadeOnDelete();
            $table->unique(
                ['job_application_id', 'application_question_id'],
                'jas_answers_application_question_uq',
            );
        });

        Schema::create('job_application_screening_answer_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_answer_id');
            $table->unsignedBigInteger('application_question_option_id');
            $table->timestamps();

            $table->foreign('application_answer_id', 'jasa_options_answer_fk')
                ->references('id')
                ->on('job_application_screening_answers')
                ->cascadeOnDelete();
            $table->foreign('application_question_option_id', 'jasa_options_option_fk')
                ->references('id')
                ->on('job_application_screening_question_options')
                ->cascadeOnDelete();
            $table->unique(
                ['application_answer_id', 'application_question_option_id'],
                'jasa_options_answer_option_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_screening_answer_options');
        Schema::dropIfExists('job_application_screening_answers');
        Schema::dropIfExists('job_application_screening_question_options');
        Schema::dropIfExists('job_application_screening_questions');
        Schema::dropIfExists('job_screening_question_options');
        Schema::dropIfExists('job_screening_questions');
    }
};
