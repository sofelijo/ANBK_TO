<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role')->default('student')->after('email');
            $table->string('student_identifier')->nullable()->after('role');
            $table->unsignedTinyInteger('grade_level')->nullable()->after('student_identifier');
            $table->index(['school_id', 'role']);
            $table->unique(['school_id', 'student_identifier']);
        });

        Schema::create('competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('competencies')->nullOnDelete();
            $table->string('code');
            $table->string('domain');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('grade_level');
            $table->timestamps();
            $table->unique(['school_id', 'code']);
            $table->index(['domain', 'grade_level']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->foreignId('competency_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('status')->default('draft');
            $table->string('title')->nullable();
            $table->text('stimulus')->nullable();
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->unsignedTinyInteger('difficulty')->default(1);
            $table->unsignedTinyInteger('grade_level');
            $table->string('cognitive_level')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'grade_level']);
            $table->index(['competency_id', 'difficulty']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 8);
            $table->text('content');
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['question_id', 'label']);
            $table->unique(['question_id', 'position']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('status')->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'grade_level']);
        });

        Schema::create('assessment_question', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->decimal('points', 6, 2)->default(1);
            $table->unique(['assessment_id', 'question_id']);
            $table->unique(['assessment_id', 'position']);
        });

        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(0);
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->json('response')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('points_awarded', 6, 2)->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('competency_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['attempt_id', 'competency_id']);
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('reason');
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
            $table->unique(['attempt_id', 'position']);
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('provider');
            $table->string('model');
            $table->string('input_hash', 64)->index();
            $table->json('request_payload');
            $table->json('result_payload')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_microusd')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('competency_results');
        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('attempts');
        Schema::dropIfExists('assessment_question');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('competencies');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn(['school_id', 'role', 'student_identifier', 'grade_level']);
        });

        Schema::dropIfExists('schools');
    }
};
