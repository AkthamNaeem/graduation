<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_side_effect_executions', function (Blueprint $table): void {
            $table->id();
            $table->string('effect_key', 191)->unique();
            $table->string('event_name')->index();
            $table->string('listener_name');
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_side_effect_executions');
    }
};
