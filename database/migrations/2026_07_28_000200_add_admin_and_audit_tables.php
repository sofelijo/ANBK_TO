<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('timezone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('grade_level');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        Schema::create('question_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('status');
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('issues')->nullable();
            $table->json('suggestions')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['question_id', 'source', 'status']);
        });

        Schema::create('attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['attempt_id', 'event_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('auditable');
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attempt_events');
        Schema::dropIfExists('question_reviews');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
