<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Support\Collection;

class ItemAnalysisService
{
    public function __construct(private readonly QuestionSnapshotService $snapshotService) {}

    public function analyze(Assessment $assessment): array
    {
        $assessment->loadMissing('questions.options');
        $attempts = Attempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::Submitted)
            ->with(['answers', 'questions.options'])
            ->get();
        $usesAttemptQuestions = $attempts->contains(fn (Attempt $attempt): bool => $attempt->questions->isNotEmpty());
        $questions = $usesAttemptQuestions
            ? $attempts->flatMap->questions->unique('id')->values()
            : $assessment->questions;
        $minimumResponses = max(1, (int) config('assessment.item_analysis.minimum_responses', 30));
        $rankedAttempts = $attempts->sortByDesc(fn (Attempt $attempt): float => $this->percentage($attempt))->values();
        $groupSize = $attempts->count() >= $minimumResponses
            ? max(1, (int) floor($attempts->count() * (float) config('assessment.item_analysis.upper_lower_group_ratio', 0.27)))
            : 0;
        $upperIds = $groupSize > 0 ? $rankedAttempts->take($groupSize)->pluck('id') : collect();
        $lowerIds = $groupSize > 0 ? $rankedAttempts->take(-$groupSize)->pluck('id') : collect();

        $items = $questions->map(function (Question $question) use ($attempts, $usesAttemptQuestions, $minimumResponses, $upperIds, $lowerIds): array {
            $snapshot = $this->snapshotService->forQuestion($question);
            $itemAttempts = $usesAttemptQuestions
                ? $attempts->filter(fn (Attempt $attempt): bool => $attempt->questions->contains('id', $question->id))
                : $attempts;
            $answers = $itemAttempts->map(fn (Attempt $attempt) => $attempt->answers->firstWhere('question_id', $question->id));
            $responseCount = $itemAttempts->count();
            $answeredCount = $answers->filter(fn ($answer): bool => $answer?->response !== null)->count();
            $correctCount = $answers->where('is_correct', true)->count();
            $difficultyIndex = $responseCount > 0 ? $correctCount / $responseCount : null;
            $discriminationIndex = $responseCount >= $minimumResponses
                ? $this->discrimination($itemAttempts, $question->id, $upperIds, $lowerIds)
                : null;
            $distractors = $this->distractorStats($snapshot, $answers, $responseCount);
            $flags = $this->flags(
                $responseCount,
                $minimumResponses,
                $difficultyIndex,
                $discriminationIndex,
                $distractors,
            );

            return [
                'question' => [
                    'id' => $question->id,
                    'version' => $snapshot['question_version'] ?? $question->version,
                    'title' => $snapshot['title'] ?? $question->title,
                    'prompt' => $snapshot['prompt'] ?? $question->prompt,
                    'type' => $snapshot['type'],
                    'difficulty' => $snapshot['difficulty'],
                ],
                'response_count' => $responseCount,
                'answered_count' => $answeredCount,
                'omitted_count' => $responseCount - $answeredCount,
                'correct_count' => $correctCount,
                'correct_percentage' => $difficultyIndex !== null ? round($difficultyIndex * 100, 2) : 0,
                'difficulty_classification' => $this->difficultyClassification($difficultyIndex),
                'discrimination_index' => $discriminationIndex !== null ? round($discriminationIndex, 3) : null,
                'discrimination_percentage' => $discriminationIndex !== null ? round($discriminationIndex * 100, 2) : null,
                'discrimination_classification' => $this->discriminationClassification($discriminationIndex),
                'average_duration' => (int) round((float) ($answers->filter()->avg('duration_seconds') ?? 0)),
                'distractors' => $distractors,
                'flags' => $flags,
                'status' => $responseCount < $minimumResponses
                    ? 'insufficient_data'
                    : ($flags === [] ? 'healthy' : 'review'),
            ];
        })->values();

        return [
            'minimum_responses' => $minimumResponses,
            'sample_size' => $attempts->count(),
            'reliability' => $this->reliability($questions, $attempts, $minimumResponses),
            'items' => $items,
            'flagged_count' => $items->where('status', 'review')->count(),
        ];
    }

    private function discrimination(Collection $attempts, int $questionId, Collection $upperIds, Collection $lowerIds): ?float
    {
        if ($upperIds->isEmpty() || $lowerIds->isEmpty()) {
            return null;
        }

        $upperCorrect = $attempts->whereIn('id', $upperIds)
            ->filter(fn (Attempt $attempt): bool => (bool) $attempt->answers->firstWhere('question_id', $questionId)?->is_correct)
            ->count() / $upperIds->count();
        $lowerCorrect = $attempts->whereIn('id', $lowerIds)
            ->filter(fn (Attempt $attempt): bool => (bool) $attempt->answers->firstWhere('question_id', $questionId)?->is_correct)
            ->count() / $lowerIds->count();

        return $upperCorrect - $lowerCorrect;
    }

    private function distractorStats(array $snapshot, Collection $answers, int $responseCount): array
    {
        $type = QuestionType::from($snapshot['type']);
        if (! in_array($type, [QuestionType::SingleChoice, QuestionType::MultipleChoice], true)) {
            return ['applicable' => false, 'total' => 0, 'functioning' => 0, 'ineffective' => []];
        }

        $minimumRate = (float) config('assessment.item_analysis.distractor_minimum_rate', 0.05);
        $selectedIds = $answers->flatMap(fn ($answer): array => array_map('intval', $answer?->response['option_ids'] ?? []));
        $distractors = collect($snapshot['options'] ?? [])->where('is_correct', false)->map(function (array $option) use ($selectedIds, $responseCount, $minimumRate): array {
            $selectionCount = $selectedIds->filter(fn (int $id): bool => $id === (int) $option['id'])->count();
            $selectionRate = $responseCount > 0 ? $selectionCount / $responseCount : 0;

            return [
                'id' => (int) $option['id'],
                'label' => $option['label'],
                'content' => $option['content'],
                'selection_count' => $selectionCount,
                'selection_percentage' => round($selectionRate * 100, 2),
                'functioning' => $selectionRate >= $minimumRate,
            ];
        })->values();

        return [
            'applicable' => true,
            'total' => $distractors->count(),
            'functioning' => $distractors->where('functioning', true)->count(),
            'ineffective' => $distractors->where('functioning', false)->values()->all(),
            'options' => $distractors->all(),
        ];
    }

    private function reliability(Collection $questions, Collection $attempts, int $minimumResponses): array
    {
        $itemCount = $questions->count();
        $sampleSize = $attempts->count();
        if ($sampleSize < $minimumResponses || $itemCount < 2) {
            return [
                'coefficient' => null,
                'classification' => 'Data belum cukup',
                'status' => 'insufficient_data',
                'item_count' => $itemCount,
                'sample_size' => $sampleSize,
            ];
        }

        $itemVariances = $questions->sum(function (Question $question) use ($attempts, $sampleSize): float {
            $correct = $attempts->filter(
                fn (Attempt $attempt): bool => (bool) $attempt->answers->firstWhere('question_id', $question->id)?->is_correct,
            )->count();
            $proportion = $correct / $sampleSize;

            return $proportion * (1 - $proportion);
        });
        $totalScores = $attempts->map(fn (Attempt $attempt): int => $questions->filter(
            fn (Question $question): bool => (bool) $attempt->answers->firstWhere('question_id', $question->id)?->is_correct,
        )->count());
        $mean = (float) $totalScores->avg();
        $totalVariance = $totalScores->sum(fn (int $score): float => ($score - $mean) ** 2) / $sampleSize;

        if ($totalVariance <= 0) {
            return [
                'coefficient' => null,
                'classification' => 'Skor tidak bervariasi',
                'status' => 'no_variance',
                'item_count' => $itemCount,
                'sample_size' => $sampleSize,
            ];
        }

        $coefficient = ($itemCount / ($itemCount - 1)) * (1 - ($itemVariances / $totalVariance));

        return [
            'coefficient' => round($coefficient, 3),
            'classification' => $this->reliabilityClassification($coefficient),
            'status' => $coefficient >= 0.7 ? 'good' : 'review',
            'item_count' => $itemCount,
            'sample_size' => $sampleSize,
        ];
    }

    private function flags(int $responseCount, int $minimumResponses, ?float $difficulty, ?float $discrimination, array $distractors): array
    {
        if ($responseCount < $minimumResponses) {
            return [];
        }

        $flags = [];
        if ($difficulty !== null && $difficulty >= 0.9) {
            $flags[] = ['code' => 'too_easy', 'label' => 'Terlalu mudah', 'message' => 'Minimal 90% peserta menjawab benar.'];
        } elseif ($difficulty !== null && $difficulty <= 0.2) {
            $flags[] = ['code' => 'too_hard', 'label' => 'Terlalu sulit', 'message' => 'Maksimal 20% peserta menjawab benar.'];
        }

        if ($discrimination !== null && $discrimination < 0.2) {
            $flags[] = [
                'code' => $discrimination < 0 ? 'negative_discrimination' : 'low_discrimination',
                'label' => $discrimination < 0 ? 'Daya pembeda negatif' : 'Daya pembeda rendah',
                'message' => $discrimination < 0
                    ? 'Kelompok nilai rendah lebih banyak menjawab benar daripada kelompok tinggi.'
                    : 'Soal belum mampu membedakan penguasaan peserta dengan baik.',
            ];
        }

        if (($distractors['applicable'] ?? false) && ($distractors['ineffective'] ?? []) !== []) {
            $labels = collect($distractors['ineffective'])->pluck('label')->join(', ');
            $flags[] = [
                'code' => 'ineffective_distractor',
                'label' => 'Pengecoh tidak efektif',
                'message' => "Pilihan {$labels} dipilih kurang dari batas minimal.",
            ];
        }

        return $flags;
    }

    private function difficultyClassification(?float $index): string
    {
        return match (true) {
            $index === null => 'Belum ada data',
            $index < 0.3 => 'Sulit',
            $index <= 0.7 => 'Sedang',
            default => 'Mudah',
        };
    }

    private function discriminationClassification(?float $index): string
    {
        return match (true) {
            $index === null => 'Menunggu sampel',
            $index < 0 => 'Negatif',
            $index < 0.2 => 'Rendah',
            $index < 0.3 => 'Cukup',
            $index < 0.4 => 'Baik',
            default => 'Sangat baik',
        };
    }

    private function reliabilityClassification(float $coefficient): string
    {
        return match (true) {
            $coefficient >= 0.9 => 'Sangat tinggi',
            $coefficient >= 0.8 => 'Tinggi',
            $coefficient >= 0.7 => 'Memadai',
            $coefficient >= 0.6 => 'Perlu perhatian',
            default => 'Rendah',
        };
    }

    private function percentage(Attempt $attempt): float
    {
        return (float) $attempt->max_score > 0
            ? ((float) $attempt->score / (float) $attempt->max_score) * 100
            : 0;
    }
}
