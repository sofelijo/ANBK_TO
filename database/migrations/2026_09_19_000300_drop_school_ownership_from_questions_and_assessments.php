<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'status', 'grade_level']);
            $table->dropIndex(['school_id', 'superseded_by_id']);
            $table->dropConstrainedForeignId('school_id');
        });

        Schema::table('assessments', function (Blueprint $table): void {
            $table->dropIndex(['school_id', 'status', 'grade_level']);
            $table->dropConstrainedForeignId('school_id');
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['school_id', 'status', 'grade_level']);
            $table->index(['school_id', 'superseded_by_id']);
        });

        Schema::table('assessments', function (Blueprint $table): void {
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['school_id', 'status', 'grade_level']);
        });
    }
};
