<?php

namespace App\Services;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssessmentAiAnalysisData
{
    public function __construct(private readonly QuestionSnapshotService $snapshotService) {}

    public function build(Assessment $assessment, int $schoolId): array
    {
        $assessment->loadMissing('subject:id,name');
        $attempts = Attempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::Submitted)
            ->whereHas('student', fn ($students) => $students->where('school_id', $schoolId))
            ->with([
                'answers:id,attempt_id,question_id,response,is_correct',
                'questions.options',
                'questions.competency:id,code,domain,name',
            ])
            ->orderBy('id')
            ->get();
        $questions = $attempts->flatMap->questions->unique('id')->values();
        $questionStats = $questions->map(
            fn (Question $question): array => $this->questionStats($question, $attempts),
        );
        $weakCompetencies = $this->weakCompetencies($questionStats);
        $difficultQuestions = $questionStats
            ->sortByDesc(fn (array $question): array => [
                $question['incorrect_percentage'],
                $question['incorrect_count'],
                $question['response_count'],
            ])
            ->take(5)
            ->values();

        return [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'subject' => $assessment->subject?->name,
                'grade_level' => $assessment->grade_level,
            ],
            'sample_size' => $attempts->count(),
            'weak_competencies' => $weakCompetencies->all(),
            'difficult_questions' => $difficultQuestions->all(),
        ];
    }

    public function hash(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function questionStats(Question $question, Collection $attempts): array
    {
        $snapshot = $this->snapshotService->forQuestion($question);
        $questionAttempts = $attempts->filter(
            fn (Attempt $attempt): bool => $attempt->questions->contains('id', $question->id),
        );
        $answers = $questionAttempts->map(
            fn (Attempt $attempt) => $attempt->answers->firstWhere('question_id', $question->id),
        );
        $responseCount = $questionAttempts->count();
        $correctCount = $answers->where('is_correct', true)->count();
        $incorrectCount = $responseCount - $correctCount;
        $competency = $question->competency;
        $metadata = $snapshot['metadata'] ?? [];

        return [
            'question_id' => $question->id,
            'title' => $snapshot['title'] ?: 'Soal '.$question->id,
            'type' => $snapshot['type'],
            'prompt' => Str::limit((string) $snapshot['prompt'], 2500, '…'),
            'stimulus' => Str::limit((string) ($snapshot['stimulus'] ?? ''), 2500, '…'),
            'difficulty' => (int) $snapshot['difficulty'],
            'competency_code' => $competency->code,
            'competency_name' => $competency->name,
            'competency_domain' => $competency->domain,
            'response_count' => $responseCount,
            'correct_count' => $correctCount,
            'incorrect_count' => $incorrectCount,
            'incorrect_percentage' => $responseCount > 0
                ? round(($incorrectCount / $responseCount) * 100, 2)
                : 0,
            'options' => collect($snapshot['options'] ?? [])->map(fn (array $option): array => [
                'content' => Str::limit((string) $option['content'], 1000, '…'),
                'is_correct' => (bool) $option['is_correct'],
            ])->values()->all(),
            'accepted_answers' => array_values($metadata['accepted_answers'] ?? []),
            'matrix' => $this->matrixAnswerGuide($metadata),
        ];
    }

    private function weakCompetencies(Collection $questions): Collection
    {
        return $questions
            ->groupBy('competency_code')
            ->map(function (Collection $group): array {
                $responseCount = (int) $group->sum('response_count');
                $incorrectCount = (int) $group->sum('incorrect_count');
                $first = $group->first();

                return [
                    'code' => $first['competency_code'],
                    'name' => $first['competency_name'],
                    'domain' => $first['competency_domain'],
                    'question_count' => $group->count(),
                    'response_count' => $responseCount,
                    'incorrect_count' => $incorrectCount,
                    'incorrect_percentage' => $responseCount > 0
                        ? round(($incorrectCount / $responseCount) * 100, 2)
                        : 0,
                ];
            })
            ->sortByDesc(fn (array $competency): array => [
                $competency['incorrect_percentage'],
                $competency['incorrect_count'],
            ])
            ->take(3)
            ->values();
    }

    private function matrixAnswerGuide(array $metadata): array
    {
        $columns = collect($metadata['matrix_columns'] ?? [])->keyBy('id');

        return collect($metadata['matrix_rows'] ?? [])->map(fn (array $row): array => [
            'statement' => Str::limit((string) ($row['statement'] ?? ''), 1000, '…'),
            'correct_category' => (string) data_get($columns, ($row['correct_column_id'] ?? '').'.label', ''),
        ])->values()->all();
    }
}
