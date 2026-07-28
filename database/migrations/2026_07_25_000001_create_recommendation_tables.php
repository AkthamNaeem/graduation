<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_seeker_profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->uuid('request_id')->nullable();
            $table->char('context_hash', 64);
            $table->string('context_version', 64);
            $table->unsignedSmallInteger('requested_limit');
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('returned_count');
            $table->string('engine', 32);
            $table->boolean('fallback_used');
            $table->string('fallback_code', 64)->nullable();
            $table->string('model_version', 128)->nullable();
            $table->string('feature_schema_version', 128)->nullable();
            $table->string('explanation_contract_version', 128)->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('context_hash');
            $table->index('expires_at');
            $table->index(
                [
                    'job_seeker_profile_id',
                    'context_hash',
                    'requested_limit',
                    'expires_at',
                ],
                'recommendation_runs_lookup_idx',
            );
        });

        Schema::create('recommendation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recommendation_run_id')
                ->constrained('recommendation_runs')
                ->cascadeOnDelete();
            $table->foreignId('job_posting_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->decimal('score', 7, 4);
            $table->decimal('raw_score', 20, 10)->nullable();
            $table->string('matching_score_version', 160);
            $table->json('breakdown')->nullable();
            $table->json('reasons');
            $table->timestamp('created_at');

            $table->unique(
                ['recommendation_run_id', 'job_posting_id'],
                'recommendation_items_run_job_unique',
            );
            $table->unique(
                ['recommendation_run_id', 'rank'],
                'recommendation_items_run_rank_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_items');
        Schema::dropIfExists('recommendation_runs');
    }
};
