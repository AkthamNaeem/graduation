<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->timestamp('effective_deadline_at')->nullable()->after('started_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('test_attempts', function (Blueprint $table): void {
            $table->dropIndex(['effective_deadline_at']);
            $table->dropColumn('effective_deadline_at');
        });
    }
};
