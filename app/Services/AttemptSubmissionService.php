<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Jobs\GenerateAttemptSummary;
use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

class AttemptSubmissionService
{
    public function __construct(
        private readonly QuestionScorer $scorer,
        private readonly QuestionSnapshotService $snapshotService,
        private readonly StudentChatService $chatService,
    ) {}

    public function submit(Attempt $attempt): Attempt
    {
        return DB::transaction(function () use ($attempt): Attempt {
            $attempt = Attempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($attempt->status === AttemptStatus::Submitted) {
                return $attempt;
            }

            $attempt->load(['assessment.questions.options', 'answers']);
            $answers = $attempt->answers->keyBy('question_id');
            $score = 0.0;
            $maxScore = 0.0;
            $competencies = [];

            foreach ($attempt->assessment->questions as $question) {
                $snapshot = $this->snapshotService->forQuestion($question);
                $points = (float) $question->pivot->points;
                $answer = $answers->get($question->id);
                $isCorrect = $this->scorer->isCorrect($question, $answer?->response, $snapshot);
                $awarded = $isCorrect ? $points : 0.0;

                $attempt->answers()->updateOrCreate(
                    ['question_id' => $question->id],
                    [
                        'response' => $answer?->response,
                        'is_correct' => $isCorrect,
                        'points_awarded' => $awarded,
                    ],
                );

                $score += $awarded;
                $maxScore += $points;
                $competencyId = (int) $snapshot['competency_id'];
                $competencies[$competencyId] ??= ['correct' => 0, 'total' => 0];
                $competencies[$competencyId]['correct'] += $isCorrect ? 1 : 0;
                $competencies[$competencyId]['total']++;
            }

            $attempt->competencyResults()->delete();
            foreach ($competencies as $competencyId => $result) {
                $percentage = $result['total'] > 0
                    ? round(($result['correct'] / $result['total']) * 100, 2)
                    : 0;

                $attempt->competencyResults()->create([
                    'competency_id' => $competencyId,
                    'correct_count' => $result['correct'],
                    'question_count' => $result['total'],
                    'percentage' => $percentage,
                ]);
            }

            $attempt->update([
                'status' => AttemptStatus::Submitted,
                'submitted_at' => now(),
                'duration_seconds' => min(
                    $attempt->started_at->diffInSeconds(now()),
                    $attempt->assessment->duration_minutes * 60,
                ),
                'score' => $score,
                'max_score' => $maxScore,
                'summary' => $this->fallbackSummary($score, $maxScore),
            ]);

            $this->buildRecommendations($attempt);
            $this->chatService->postAttemptSummary($attempt->fresh());
            GenerateAttemptSummary::dispatch($attempt->id)->afterCommit();

            return $attempt->fresh(['competencyResults.competency', 'recommendations.question']);
        });
    }

    private function buildRecommendations(Attempt $attempt): void
    {
        $attempt->recommendations()->delete();
        $results = $attempt->competencyResults()->orderBy('percentage')->get();
        $usedQuestionIds = $attempt->assessment->questions->pluck('id');
        $position = 1;

        foreach ($results as $result) {
            $question = Question::query()
                ->where('school_id', $attempt->assessment->school_id)
                ->where('competency_id', $result->competency_id)
                ->where('grade_level', $attempt->assessment->grade_level)
                ->where('status', QuestionStatus::Published)
                ->whereNotIn('id', $usedQuestionIds)
                ->orderByRaw('ABS(difficulty - ?)', [$this->recommendedDifficulty((float) $result->percentage)])
                ->first();

            if (! $question) {
                $question = $attempt->assessment->questions
                    ->first(fn (Question $candidate): bool => $candidate->competency_id === $result->competency_id
                        && ! $attempt->answers->firstWhere('question_id', $candidate->id)?->is_correct
                    );
            }

            if (! $question || $attempt->recommendations()->where('question_id', $question->id)->exists()) {
                continue;
            }

            $attempt->recommendations()->create([
                'competency_id' => $result->competency_id,
                'question_id' => $question->id,
                'position' => $position,
                'reason' => "Latihan untuk kompetensi dengan capaian {$result->percentage}%",
            ]);

            if (++$position > 3) {
                break;
            }
        }
    }

    private function recommendedDifficulty(float $percentage): int
    {
        return match (true) {
            $percentage < 40 => 1,
            $percentage < 75 => 2,
            default => 3,
        };
    }

    private function fallbackSummary(float $score, float $maxScore): string
    {
        $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;

        return "Kamu menyelesaikan try out dengan capaian {$percentage}%. Lihat peta kompetensi dan kerjakan latihan yang direkomendasikan untuk memperkuat bagian yang masih lemah.";
    }
}
