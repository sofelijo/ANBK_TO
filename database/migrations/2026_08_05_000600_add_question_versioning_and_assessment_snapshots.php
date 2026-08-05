<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('revision_of_id')->nullable()->after('parent_id')->constrained('questions')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1)->after('revision_of_id');
            $table->foreignId('superseded_by_id')->nullable()->after('version')->constrained('questions')->nullOnDelete();
            $table->index(['school_id', 'superseded_by_id']);
        });

        Schema::table('assessment_question', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_question', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'superseded_by_id']);
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropColumn('version');
            $table->dropConstrainedForeignId('revision_of_id');
        });
    }
};
