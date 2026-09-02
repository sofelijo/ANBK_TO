<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verifier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->unique(['question_id', 'verifier_id']);
            $table->index(['verifier_id', 'verified_at']);
        });

        DB::table('questions')
            ->whereNotNull('approved_by')
            ->orderBy('id')
            ->chunkById(500, function ($questions): void {
                $now = now();
                $rows = collect($questions)->map(fn (object $question): array => [
                    'question_id' => $question->id,
                    'verifier_id' => $question->approved_by,
                    'verified_at' => $question->approved_at ?? $question->updated_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('question_verifications')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_verifications');
    }
};
