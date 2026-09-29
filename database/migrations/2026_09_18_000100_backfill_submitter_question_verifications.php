<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('questions')
            ->join('ai_generations', 'ai_generations.id', '=', 'questions.story_generation_id')
            ->whereColumn('questions.author_id', 'ai_generations.requested_by')
            ->whereIn('questions.status', ['review', 'published'])
            ->select([
                'questions.id as question_id',
                'ai_generations.requested_by as verifier_id',
                'ai_generations.request_payload',
                'ai_generations.updated_at as submitted_at',
            ])
            ->orderBy('questions.id')
            ->chunk(500, function ($questions): void {
                $rows = collect($questions)
                    ->filter(function (object $question): bool {
                        $payload = json_decode($question->request_payload ?? '{}', true);

                        return data_get($payload, 'submission_mode') === 'review';
                    })
                    ->map(fn (object $question): array => [
                        'question_id' => $question->question_id,
                        'verifier_id' => $question->verifier_id,
                        'verified_at' => $question->submitted_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($rows !== []) {
                    DB::table('question_verifications')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        // Riwayat verifikasi tidak dihapus agar data kontribusi guru tetap aman.
    }
};
