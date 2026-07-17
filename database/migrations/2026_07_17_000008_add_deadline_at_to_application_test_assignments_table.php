<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table): void {
            $table->timestamp('deadline_at')->nullable()->index()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('application_test_assignments', function (Blueprint $table): void {
            $table->dropIndex(['deadline_at']);
            $table->dropColumn('deadline_at');
        });
    }
};
