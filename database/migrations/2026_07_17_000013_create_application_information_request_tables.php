<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_information_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_application_id');
            $table->foreignId('requested_by_user_id');
            $table->text('message');
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('previous_application_status', 50);
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('job_application_id', 'air_application_fk')->references('id')->on('job_applications')->cascadeOnDelete();
            $table->foreign('requested_by_user_id', 'air_requester_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by_user_id', 'air_canceller_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['job_application_id', 'status'], 'air_application_status_idx');
            $table->index('due_at', 'air_due_at_idx');
        });

        Schema::create('application_information_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_information_request_id');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('order_index');
            $table->timestamps();

            $table->foreign('application_information_request_id', 'airi_request_fk')->references('id')->on('application_information_requests')->cascadeOnDelete();
            $table->unique(['application_information_request_id', 'order_index'], 'airi_request_order_uq');
        });

        Schema::create('application_information_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_information_request_id');
            $table->foreignId('submitted_by_user_id');
            $table->text('message')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('application_information_request_id', 'aires_request_fk')->references('id')->on('application_information_requests')->cascadeOnDelete();
            $table->foreign('submitted_by_user_id', 'aires_submitter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->unique('application_information_request_id', 'aires_request_uq');
        });

        Schema::create('application_information_response_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_information_response_id');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('disk', 50);
            $table->string('mime_type', 255);
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->foreign('application_information_response_id', 'aira_response_fk')->references('id')->on('application_information_responses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_information_response_attachments');
        Schema::dropIfExists('application_information_responses');
        Schema::dropIfExists('application_information_request_items');
        Schema::dropIfExists('application_information_requests');
    }
};
