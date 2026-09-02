<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->foreignId('assessment_id')
                ->nullable()
                ->after('attempt_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index(
                ['school_id', 'assessment_id', 'type', 'created_at'],
                'ai_generations_school_assessment_type_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->dropIndex('ai_generations_school_assessment_type_created_index');
            $table->dropConstrainedForeignId('assessment_id');
        });
    }
};
