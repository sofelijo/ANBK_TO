<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Models\Attempt;
use App\Services\AI\AiManager;
use App\Services\StudentChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Throwable;

class GenerateAttemptSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $attemptId) {}

    public function handle(AiManager $manager, StudentChatService $chatService): void
    {
        $attempt = Attempt::with([
            'assessment',
            'student',
            'competencyResults.competency',
            'recommendations.competency',
        ])->findOrFail($this->attemptId);

        $payload = [
            'score' => (float) $attempt->score,
            'max_score' => (float) $attempt->max_score,
            'grade_level' => $attempt->assessment->grade_level,
            'results' => $attempt->competencyResults->map(fn ($result): array => [
                'code' => $result->competency->code,
                'name' => $result->competency->name,
                'percentage' => (float) $result->percentage,
            ])->values()->all(),
            'recommendations' => $attempt->recommendations->map(fn ($recommendation): array => [
                'competency' => $recommendation->competency->name,
                'reason' => $recommendation->reason,
            ])->values()->all(),
        ];

        $provider = $manager->provider();
        $generation = AiGeneration::create([
            'school_id' => $attempt->assessment->school_id,
            'requested_by' => $attempt->user_id,
            'attempt_id' => $attempt->id,
            'type' => AiGenerationType::AttemptSummary,
            'status' => AiGenerationStatus::Processing,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => hash('sha256', json_encode($payload)),
            'request_payload' => $payload,
        ]);

        try {
            $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $provider->generateJson(
                <<<PROMPT
Tuliskan ringkasan hasil try out dalam bahasa Indonesia untuk seorang pelajar kelas {$attempt->assessment->grade_level}. Maksimal 90 kata, hangat tetapi tidak berlebihan, sebutkan satu kekuatan, satu indikasi kelemahan, dan tindakan konkret. Jangan membuat diagnosis permanen dan jangan menyebut data pribadi. Kembalikan JSON saja: {"summary":"..."}.

Data hasil anonim:
{$payloadJson}
PROMPT,
                ['task' => 'attempt_summary', ...$payload],
            );

            $summary = Validator::make($response->data, [
                'summary' => ['required', 'string', 'max:1000'],
            ])->validate()['summary'];

            $attempt->update(['summary' => $summary]);
            $chatService->postAttemptSummary($attempt->fresh());
            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => ['summary' => $summary],
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
