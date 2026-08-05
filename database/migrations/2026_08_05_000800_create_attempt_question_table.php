<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempt_question', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->decimal('points', 6, 2)->default(1);
            $table->json('snapshot')->nullable();
            $table->unique(['attempt_id', 'question_id']);
            $table->unique(['attempt_id', 'position']);
        });

        DB::table('attempt_question')->insertUsing(
            ['attempt_id', 'question_id', 'position', 'points', 'snapshot'],
            DB::table('attempts')
                ->join('assessment_question', 'assessment_question.assessment_id', '=', 'attempts.assessment_id')
                ->select([
                    'attempts.id',
                    'assessment_question.question_id',
                    'assessment_question.position',
                    'assessment_question.points',
                    'assessment_question.snapshot',
                ]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_question');
    }
};
