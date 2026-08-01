<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_seeker_profiles', function (Blueprint $table): void {
            $table->string('availability_status')->nullable()->after('city_id');
            $table->date('available_from')->nullable()->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('job_seeker_profiles', function (Blueprint $table): void {
            $table->dropColumn(['availability_status', 'available_from']);
        });
    }
};
