<?php

use App\Enums\CompanyInvitationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('company_role');
            $table->string('token_hash', 64)->unique();
            $table->string('status')->default(CompanyInvitationStatus::PENDING->value);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'email', 'status'], 'company_invitations_pending_lookup');
            $table->index(['company_id', 'status'], 'company_invitations_company_status');
            $table->index('company_role');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
    }
};
