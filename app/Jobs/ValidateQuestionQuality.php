<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Services\AI\AiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ValidateQuestionQuality implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $generationId) {}

    public function handle(AiManager $manager): void
    {
        $generation = AiGeneration::with('sourceQuestion.options')->findOrFail($this->generationId);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);

        try {
            $question = $generation->sourceQuestion;
            $payload = [
                'type' => $question->type->value,
                'grade_level' => $question->grade_level,
                'difficulty' => $question->difficulty,
                'stimulus' => $question->stimulus,
                'prompt' => $question->prompt,
                'explanation' => $question->explanation,
                'accepted_answers' => $question->metadata['accepted_answers'] ?? [],
                'matching_pairs' => $question->metadata['matching_pairs'] ?? [],
                'matching_distractors' => $question->metadata['matching_distractors'] ?? [],
                'matrix_columns' => $question->metadata['matrix_columns'] ?? [],
                'matrix_rows' => $question->metadata['matrix_rows'] ?? [],
                'options' => $question->options->map(fn ($option): array => [
                    'content' => $option->content,
                    'is_correct' => $option->is_correct,
                ])->all(),
            ];
            $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $manager->provider()->generateJson(
                <<<PROMPT
Anda adalah reviewer kualitas soal try out ANBK. Evaluasi kejelasan stimulus, kesesuaian jenjang, ketepatan kunci, kualitas distraktor, potensi ambiguitas, dan kecukupan pembahasan. Jangan mengubah soal dan jangan menganggap keluaran ini sebagai keputusan final; guru tetap peninjau akhir.

Kembalikan JSON saja:
{"passed":true,"score":85,"issues":[{"severity":"warning","field":"prompt","message":"..."}],"suggestions":["..."]}

severity hanya error atau warning. score 0–100. passed bernilai false bila terdapat error yang dapat membuat kunci salah atau soal ambigu.

Soal:
{$payloadJson}
PROMPT,
                ['task' => 'question_validation', 'question' => $payload],
            );
            $result = Validator::make($response->data, [
                'passed' => ['required', 'boolean'],
                'score' => ['required', 'integer', 'between:0,100'],
                'issues' => ['array', 'max:20'],
                'issues.*.severity' => ['required', 'in:error,warning'],
                'issues.*.field' => ['required', 'string', 'max:100'],
                'issues.*.message' => ['required', 'string', 'max:1000'],
                'suggestions' => ['array', 'max:20'],
                'suggestions.*' => ['string', 'max:1000'],
            ])->validate();

            $review = $question->reviews()->create([
                'ai_generation_id' => $generation->id,
                'source' => 'ai',
                'status' => $result['passed'] ? 'passed' : 'failed',
                'score' => $result['score'],
                'issues' => $result['issues'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'reviewed_at' => now(),
            ]);
            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => ['review_id' => $review->id, ...$result],
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_microusd' => $manager->costMicrousd($response),
            ]);
        } catch (Throwable $exception) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            throw $exception;
        }
    }
}
