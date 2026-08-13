<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Question;
use App\Services\QuestionScorer;
use App\Services\QuestionSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentMonitoringController extends Controller
{
    public function __invoke(
        Request $request,
        QuestionScorer $scorer,
        QuestionSnapshotService $snapshotService,
    ): Response {
        $schoolId = $request->user()->school_id;
        $schoolNpsn = (string) $request->user()->school()->value('npsn');

        $assessments = Assessment::query()
            ->where(function ($query) use ($schoolId, $schoolNpsn): void {
                $query->whereHas('schedules', fn ($schedules) => $schedules->where('school_npsn', $schoolNpsn))
                    ->orWhereHas('attempts.student', fn ($students) => $students->where('school_id', $schoolId));
            })
            ->withCount([
                'attempts as school_attempts_count' => fn ($attempts) => $attempts
                    ->whereHas('student', fn ($students) => $students->where('school_id', $schoolId)),
                'attempts as school_in_progress_count' => fn ($attempts) => $attempts
                    ->where('status', AttemptStatus::InProgress)
                    ->whereHas('student', fn ($students) => $students->where('school_id', $schoolId)),
            ])
            ->with(['schedules' => fn ($schedules) => $schedules
                ->where('school_npsn', $schoolNpsn)
                ->latest('starts_at')])
            ->latest('updated_at')
            ->get(['id', 'title', 'grade_level', 'duration_minutes', 'settings']);

        $selectedAssessmentId = $request->integer('assessment_id');
        if (! $assessments->contains('id', $selectedAssessmentId)) {
            $selectedAssessmentId = $assessments
                ->first(fn (Assessment $assessment): bool => $assessment->schedules->contains(
                    fn ($schedule): bool => $schedule->starts_at->lte(now()) && $schedule->ends_at->gt(now()),
                ))?->id
                ?? $assessments->firstWhere('school_in_progress_count', '>', 0)?->id
                ?? $assessments->first()?->id;
        }

        return Inertia::render('Monitoring/Index', [
            'assessments' => $assessments->map(fn (Assessment $assessment): array => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'grade_level' => $assessment->grade_level,
                'duration_minutes' => $assessment->duration_minutes,
                'attempts_count' => $assessment->school_attempts_count,
                'in_progress_count' => $assessment->school_in_progress_count,
                'schedule' => $assessment->schedules->first()?->only(['starts_at', 'ends_at', 'session_number']),
            ]),
            'selectedAssessmentId' => $selectedAssessmentId,
            'monitor' => $selectedAssessmentId
                ? $this->monitor($selectedAssessmentId, $schoolId, $scorer, $snapshotService)
                : null,
        ]);
    }

    private function monitor(
        int $assessmentId,
        int $schoolId,
        QuestionScorer $scorer,
        QuestionSnapshotService $snapshotService,
    ): array {
        $assessment = Assessment::query()->findOrFail($assessmentId);
        $attempts = Attempt::query()
            ->where('assessment_id', $assessmentId)
            ->whereHas('student', fn ($students) => $students->where('school_id', $schoolId))
            ->with([
                'student:id,name,student_identifier,grade_level',
                'questions.options',
                'answers:id,attempt_id,question_id,response,is_correct,answered_at',
            ])
            ->orderBy('started_at')
            ->get();
        $shuffleQuestions = (bool) data_get($assessment->settings, 'shuffle_questions', false);
        $questionCount = max(
            (int) data_get($assessment->settings, 'question_count', 0),
            $attempts->max(fn (Attempt $attempt): int => $attempt->questions->count()) ?? 0,
        );

        $rows = $attempts->map(function (Attempt $attempt) use ($scorer, $snapshotService, $shuffleQuestions): array {
            $answers = $attempt->answers->keyBy('question_id');
            $questions = $this->orderedQuestions($attempt, $shuffleQuestions);
            $cells = $questions->values()->map(function (Question $question, int $index) use ($answers, $attempt, $scorer, $snapshotService): array {
                $answer = $answers->get($question->id);
                $answered = $this->hasResponse($answer?->response);
                $isCorrect = $answer?->is_correct;

                if ($answered && $isCorrect === null) {
                    $isCorrect = $scorer->isCorrect($question, $answer->response, $snapshotService->forQuestion($question));
                }

                $status = match (true) {
                    $answered && $isCorrect === true => 'correct',
                    $answered || $attempt->status === AttemptStatus::Submitted => 'incorrect',
                    default => 'unanswered',
                };

                return [
                    'position' => $index + 1,
                    'question_id' => $question->id,
                    'label' => $question->title ?: $question->prompt,
                    'answered' => $answered,
                    'status' => $status,
                ];
            });

            return [
                'attempt_id' => $attempt->id,
                'student' => [
                    'name' => $attempt->student->name,
                    'nisn' => $attempt->student->student_identifier,
                    'grade_level' => $attempt->student->grade_level,
                ],
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
                'answered_count' => $cells->where('answered', true)->count(),
                'correct_count' => $cells->where('status', 'correct')->count(),
                'incorrect_count' => $cells->where('status', 'incorrect')->count(),
                'cells' => $cells,
            ];
        });

        return [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'grade_level' => $assessment->grade_level,
                'duration_minutes' => $assessment->duration_minutes,
            ],
            'question_count' => $questionCount,
            'participant_count' => $rows->count(),
            'in_progress_count' => $rows->where('status', AttemptStatus::InProgress->value)->count(),
            'submitted_count' => $rows->where('status', AttemptStatus::Submitted->value)->count(),
            'rows' => $rows,
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function orderedQuestions(Attempt $attempt, bool $shuffleQuestions): Collection
    {
        if (! $shuffleQuestions) {
            return $attempt->questions;
        }

        return $attempt->questions->sortBy(
            fn (Question $question): string => hash('sha256', "{$attempt->public_id}:question:{$question->id}"),
        )->values();
    }

    private function hasResponse(?array $response): bool
    {
        return collect($response ?? [])->contains(function (mixed $value): bool {
            if (is_array($value)) {
                return $value !== [];
            }

            return trim((string) $value) !== '';
        });
    }
}
