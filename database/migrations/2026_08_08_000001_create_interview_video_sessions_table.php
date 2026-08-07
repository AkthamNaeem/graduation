<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interview_video_sessions')) {
            Schema::create('interview_video_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('interview_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('provider', 32);
                $table->string('room_name', 191)->unique();
                $table->boolean('enabled')->default(true);
                $table->timestamp('first_joined_at')->nullable();
                $table->timestamp('last_left_at')->nullable();
                $table->timestamp('room_started_at')->nullable();
                $table->timestamp('room_ended_at')->nullable();
                $table->timestamps();

                $table->index(['provider', 'enabled'], 'ivs_provider_enabled_idx');
            });
        }

        if (! Schema::hasTable('livekit_webhook_events')) {
            Schema::create('livekit_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('interview_video_session_id')->constrained()->cascadeOnDelete();
                $table->string('event_id', 191)->unique();
                $table->string('event_type', 64);
                $table->timestamp('processed_at');
            });
        }

        if (! Schema::hasIndex('livekit_webhook_events', ['interview_video_session_id', 'event_type'])) {
            Schema::table('livekit_webhook_events', function (Blueprint $table): void {
                $table->index(
                    ['interview_video_session_id', 'event_type'],
                    'lk_webhook_session_type_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('livekit_webhook_events');
        Schema::dropIfExists('interview_video_sessions');
    }
};
