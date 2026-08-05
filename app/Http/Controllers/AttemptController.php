<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Question;
use App\Services\AI\AiManager;
use App\Services\AttemptSubmissionService;
use App\Services\QuestionSnapshotService;
use App\Services\StudentChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AttemptController extends Controller
{
    public function start(
        Request $request,
        Assessment $assessment,
        QuestionSnapshotService $snapshotService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless(
            $assessment->grade_level === $user->grade_level
            && $assessment->status === AssessmentStatus::Published,
            404,
        );

        abort_if($assessment->starts_at?->isFuture(), 403, 'Try out belum dimulai.');
        abort_if($assessment->ends_at?->isPast(), 403, 'Try out telah ditutup.');
        $snapshotService->snapshotAssessment($assessment);

        $attempt = Attempt::firstOrCreate(
            ['assessment_id' => $assessment->id, 'user_id' => $user->id],
            [
                'public_id' => (string) Str::uuid(),
                'status' => AttemptStatus::InProgress,
                'started_at' => now(),
            ],
        );

        return $attempt->status === AttemptStatus::Submitted
            ? to_route('attempts.result', $attempt->public_id)
            : to_route('attempts.show', $attempt->public_id);
    }

    public function show(
        Request $request,
        Attempt $attempt,
        AttemptSubmissionService $submissionService,
        QuestionSnapshotService $snapshotService,
    ): Response|RedirectResponse {
        $this->authorizeStudent($request, $attempt);

        if ($attempt->status === AttemptStatus::Submitted) {
            return to_route('attempts.result', $attempt->public_id);
        }

        if ($attempt->isExpired()) {
            $submissionService->submit($attempt);

            return to_route('attempts.result', $attempt->public_id);
        }

        $attempt->load(['assessment.questions.options', 'answers']);
        $answers = $attempt->answers->keyBy('question_id');
        $settings = $attempt->assessment->settings ?? [];
        $shuffleQuestions = (bool) data_get($settings, 'shuffle_questions', false);
        $shuffleOptions = (bool) data_get($settings, 'shuffle_options', false);
        $questions = $attempt->assessment->questions;

        if ($shuffleQuestions) {
            $questions = $questions->sortBy(
                fn (Question $question): string => hash('sha256', "{$attempt->public_id}:question:{$question->id}"),
            )->values();
        }

        return Inertia::render('Attempts/Show', [
            'attempt' => [
                'public_id' => $attempt->public_id,
                'started_at' => $attempt->started_at,
                'remaining_seconds' => max(
                    0,
                    ($attempt->assessment->duration_minutes * 60)
                    - $attempt->started_at->diffInSeconds(now()),
                ),
                'assessment' => [
                    'title' => $attempt->assessment->title,
                    'duration_minutes' => $attempt->assessment->duration_minutes,
                    'type_label' => data_get($settings, 'type_label', 'Try Out ANBK'),
                    'show_navigation' => (bool) data_get($settings, 'show_navigation', true),
                    'require_all_answers' => (bool) data_get($settings, 'require_all_answers', false),
                ],
                'questions' => $questions->values()->map(function (Question $question, int $questionIndex) use ($answers, $attempt, $shuffleOptions, $snapshotService): array {
                    $snapshot = $snapshotService->forQuestion($question);
                    $type = QuestionType::from($snapshot['type']);
                    $metadata = $snapshot['metadata'] ?? [];
                    $options = collect($snapshot['options'] ?? []);
                    if ($shuffleOptions) {
                        $options = $options->sortBy(
                            fn (array $option): string => hash('sha256', "{$attempt->public_id}:question:{$question->id}:option:{$option['id']}"),
                        )->values();
                    }

                    $matching = null;
                    if ($type === QuestionType::Matching) {
                        $pairs = collect($metadata['matching_pairs'] ?? []);
                        $rightItems = $pairs->map(fn (array $pair): array => [
                            'id' => $pair['right_id'],
                            'content' => $pair['right'],
                        ])->merge(collect($metadata['matching_distractors'] ?? [])->map(fn (array $distractor): array => [
                            'id' => $distractor['id'],
                            'content' => $distractor['content'],
                        ]));

                        if ($shuffleOptions) {
                            $rightItems = $rightItems->sortBy(
                                fn (array $item): string => hash('sha256', "{$attempt->public_id}:question:{$question->id}:match:{$item['id']}"),
                            );
                        }

                        $matching = [
                            'left_items' => $pairs->map(fn (array $pair): array => [
                                'id' => $pair['left_id'],
                                'content' => $pair['left'],
                            ])->values(),
                            'right_items' => $rightItems->values(),
                        ];
                    }

                    $matrix = null;
                    if ($type === QuestionType::CategoryMatrix) {
                        $columns = collect($metadata['matrix_columns'] ?? []);
                        if ($shuffleOptions) {
                            $columns = $columns->sortBy(
                                fn (array $column): string => hash('sha256', "{$attempt->public_id}:question:{$question->id}:matrix:{$column['id']}"),
                            );
                        }

                        $matrix = [
                            'columns' => $columns->map(fn (array $column): array => [
                                'id' => $column['id'],
                                'label' => $column['label'],
                            ])->values(),
                            'rows' => collect($metadata['matrix_rows'] ?? [])->map(fn (array $row): array => [
                                'id' => $row['id'],
                                'statement' => $row['statement'],
                            ])->values(),
                        ];
                    }

                    return [
                        'id' => $question->id,
                        'type' => $type->value,
                        'title' => $snapshot['title'],
                        'stimulus' => $snapshot['stimulus'],
                        'illustration_url' => $snapshotService->illustrationUrl($snapshot),
                        'prompt' => $snapshot['prompt'],
                        'position' => $questionIndex + 1,
                        'matching' => $matching,
                        'matrix' => $matrix,
                        'options' => $options->values()->map(fn (array $option, int $optionIndex): array => [
                            'id' => $option['id'],
                            'label' => chr(65 + $optionIndex),
                            'content' => $option['content'],
                        ]),
                        'response' => $answers->get($question->id)?->response,
                    ];
                }),
            ],
        ]);
    }

    public function saveAnswer(
        Request $request,
        Attempt $attempt,
        Question $question,
        QuestionSnapshotService $snapshotService,
    ): JsonResponse {
        $this->authorizeStudent($request, $attempt);
        abort_unless($attempt->status === AttemptStatus::InProgress, 409);
        abort_if($attempt->isExpired(), 409, 'Waktu pengerjaan telah habis.');

        $assessmentQuestion = $attempt->assessment
            ->questions()
            ->whereKey($question->id)
            ->first();
        abort_unless($assessmentQuestion, 404);
        $snapshot = $snapshotService->forQuestion($assessmentQuestion);
        $type = QuestionType::from($snapshot['type']);
        $metadata = $snapshot['metadata'] ?? [];

        $data = $request->validate([
            'option_ids' => ['sometimes', 'array', 'max:10'],
            'option_ids.*' => ['integer', 'distinct'],
            'text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'matches' => ['sometimes', 'array', 'max:8'],
            'matches.*' => ['string', 'max:64'],
            'matrix_answers' => ['sometimes', 'array', 'max:10'],
            'matrix_answers.*' => ['string', 'max:64'],
            'duration_seconds' => ['sometimes', 'integer', 'between:0,14400'],
        ]);

        if (isset($data['option_ids'])) {
            $validOptionCount = collect($snapshot['options'] ?? [])->whereIn('id', $data['option_ids'])->count();
            if ($validOptionCount !== count($data['option_ids'])) {
                throw ValidationException::withMessages(['option_ids' => 'Pilihan jawaban tidak valid.']);
            }
        }

        if (isset($data['matches'])) {
            if ($type !== QuestionType::Matching) {
                throw ValidationException::withMessages(['matches' => 'Jawaban pasangan hanya valid untuk soal menjodohkan.']);
            }

            $pairs = collect($metadata['matching_pairs'] ?? []);
            $validLeftIds = $pairs->pluck('left_id')->map(fn (mixed $id): string => (string) $id)->all();
            $validRightIds = $pairs->pluck('right_id')
                ->merge(collect($metadata['matching_distractors'] ?? [])->pluck('id'))
                ->map(fn (mixed $id): string => (string) $id)
                ->all();
            $leftIds = array_map('strval', array_keys($data['matches']));
            $rightIds = array_map('strval', array_values($data['matches']));

            if (array_diff($leftIds, $validLeftIds)
                || array_diff($rightIds, $validRightIds)
                || count($rightIds) !== count(array_unique($rightIds))) {
                throw ValidationException::withMessages(['matches' => 'Pasangan jawaban tidak valid.']);
            }
        }

        if (isset($data['matrix_answers'])) {
            if ($type !== QuestionType::CategoryMatrix) {
                throw ValidationException::withMessages(['matrix_answers' => 'Jawaban kategori hanya valid untuk soal matriks.']);
            }

            $validRowIds = collect($metadata['matrix_rows'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
            $validColumnIds = collect($metadata['matrix_columns'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

            if (array_diff(array_map('strval', array_keys($data['matrix_answers'])), $validRowIds)
                || array_diff(array_map('strval', array_values($data['matrix_answers'])), $validColumnIds)) {
                throw ValidationException::withMessages(['matrix_answers' => 'Jawaban kategori tidak valid.']);
            }
        }

        $response = array_filter([
            'option_ids' => $data['option_ids'] ?? null,
            'text' => $data['text'] ?? null,
            'matches' => $data['matches'] ?? null,
            'matrix_answers' => $data['matrix_answers'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        $attempt->answers()->updateOrCreate(
            ['question_id' => $question->id],
            [
                'response' => $response ?: null,
                'duration_seconds' => $data['duration_seconds'] ?? 0,
                'answered_at' => now(),
            ],
        );

        return response()->json(['saved_at' => now()->toIso8601String()]);
    }

    public function submit(
        Request $request,
        Attempt $attempt,
        AttemptSubmissionService $submissionService,
        QuestionSnapshotService $snapshotService,
    ): RedirectResponse {
        $this->authorizeStudent($request, $attempt);

        if ((bool) data_get($attempt->assessment->settings, 'require_all_answers', false) && ! $attempt->isExpired()) {
            $attempt->loadMissing(['assessment.questions', 'answers']);
            $answers = $attempt->answers->keyBy('question_id');
            $answeredCount = $attempt->assessment->questions->filter(
                fn (Question $question): bool => $this->hasCompleteResponse(
                    $snapshotService->forQuestion($question),
                    $answers->get($question->id)?->response,
                ),
            )->count();

            if ($answeredCount < $attempt->assessment->questions->count()) {
                throw ValidationException::withMessages([
                    'attempt' => 'Semua soal wajib dijawab sebelum try out dikirim.',
                ]);
            }
        }

        $submissionService->submit($attempt);

        return to_route('attempts.result', $attempt->public_id);
    }

    public function result(Request $request, Attempt $attempt): Response|RedirectResponse
    {
        $this->authorizeStudent($request, $attempt);
        if ($attempt->status !== AttemptStatus::Submitted) {
            return to_route('attempts.show', $attempt->public_id);
        }

        $attempt->load([
            'assessment:id,title',
            'competencyResults.competency:id,code,domain,name',
            'recommendations.competency:id,code,name',
            'recommendations.question.options',
        ]);

        return Inertia::render('Attempts/Result', ['attempt' => $attempt]);
    }

    public function practiceChat(
        Request $request,
        Attempt $attempt,
        StudentChatService $chatService,
        AiManager $manager,
    ): RedirectResponse {
        $this->authorizeStudent($request, $attempt);
        abort_unless($attempt->status === AttemptStatus::Submitted, 409);

        $chatService->requestPracticeFor($attempt, $manager);

        return to_route('student-chat.show');
    }

    private function authorizeStudent(Request $request, Attempt $attempt): void
    {
        abort_unless($attempt->user_id === $request->user()->id, 404);
    }

    private function hasCompleteResponse(array $snapshot, ?array $response): bool
    {
        $type = QuestionType::from($snapshot['type']);
        $metadata = $snapshot['metadata'] ?? [];

        if ($type === QuestionType::Matching) {
            $pairCount = count($metadata['matching_pairs'] ?? []);

            return $pairCount > 0 && count($response['matches'] ?? []) === $pairCount;
        }

        if ($type === QuestionType::CategoryMatrix) {
            $rowCount = count($metadata['matrix_rows'] ?? []);

            return $rowCount > 0 && count($response['matrix_answers'] ?? []) === $rowCount;
        }

        if ($type === QuestionType::ShortAnswer) {
            return trim((string) data_get($response, 'text', '')) !== '';
        }

        return data_get($response, 'option_ids', []) !== [];
    }
}
