<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tests', function (Blueprint $table): void {
            // Legacy catalog rows cannot be attributed safely. New writes require a company.
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
