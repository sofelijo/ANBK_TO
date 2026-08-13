<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'subject_id', 'code']);
            $table->index(['subject_id', 'name']);
        });

        Schema::create('competency_question_blueprint', function (Blueprint $table) {
            $table->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_blueprint_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);

            $table->primary(['competency_id', 'question_blueprint_id']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('question_blueprint_id')
                ->nullable()
                ->after('competency_id')
                ->constrained()
                ->nullOnDelete();
            $table->index(['competency_id', 'question_blueprint_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('question_blueprint_id');
        });

        Schema::dropIfExists('competency_question_blueprint');
        Schema::dropIfExists('question_blueprints');
    }
};
