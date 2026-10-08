<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\ActionNotification;
use App\Services\NotificationAudience;
use App\Services\QuestionSnapshotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::Operator)) {
            return to_route('schedules.index');
        }

        $subjects = Subject::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $subjectOptions = $subjects
            ->groupBy(fn (Subject $subject) => mb_strtolower(trim($subject->name)))
            ->map(function (Collection $matchingSubjects, string $normalizedName): array {
                $canonicalSubject = $matchingSubjects
                    ->sortBy(fn (Subject $subject) => [mb_strlen($subject->code), $subject->code])
                    ->first();

                return [
                    'value' => "subject:{$normalizedName}",
                    'code' => $canonicalSubject->code,
                    'name' => $canonicalSubject->name,
                ];
            })
            ->values();
        $subjectFilter = $request->string('subject')->toString();
        $typeFilter = $request->string('type')->toString();
        $questionSubjectCount = fn () => DB::table('assessment_question')
            ->join('questions', 'questions.id', '=', 'assessment_question.question_id')
            ->join('competencies', 'competencies.id', '=', 'questions.competency_id')
            ->join('subjects', 'subjects.id', '=', 'competencies.subject_id')
            ->whereColumn('assessment_question.assessment_id', 'assessments.id')
            ->selectRaw('COUNT(DISTINCT LOWER(TRIM(subjects.name)))');
        $questionSubjectId = fn () => DB::table('assessment_question')
            ->join('questions', 'questions.id', '=', 'assessment_question.question_id')
            ->join('competencies', 'competencies.id', '=', 'questions.competency_id')
            ->whereColumn('assessment_question.assessment_id', 'assessments.id')
            ->selectRaw('MIN(competencies.subject_id)');
        $questionSubjectName = fn () => DB::table('assessment_question')
            ->join('questions', 'questions.id', '=', 'assessment_question.question_id')
            ->join('competencies', 'competencies.id', '=', 'questions.competency_id')
            ->join('subjects', 'subjects.id', '=', 'competencies.subject_id')
            ->whereColumn('assessment_question.assessment_id', 'assessments.id')
            ->selectRaw('MIN(subjects.name)');

        $query = Assessment::query()
            ->select('assessments.*')
            ->addSelect([
                'question_subject_count' => $questionSubjectCount(),
                'question_subject_id' => $questionSubjectId(),
                'question_subject_name' => $questionSubjectName(),
            ])
            ->withCount(['questions', 'attempts'])
            ->withAvg('questions as average_difficulty', 'difficulty')
            ->latest();

        if ($user->hasRole(UserRole::Student)) {
            $schoolNpsn = $user->school()->value('npsn');
            $query->where('status', AssessmentStatus::Published)
                ->where('grade_level', $user->grade_level)
                ->with(['schedules' => fn ($schedules) => $schedules->where('school_npsn', $schoolNpsn)])
                ->with(['attempts' => fn ($attempts) => $attempts->where('user_id', $user->id)]);
        } else {
            $query->withCount('schedules')
                ->with(['questions:id,competency_id', 'questions.competency:id,parent_id,code,name']);
        }

        if ($subjectFilter === 'mixed') {
            $query->where($questionSubjectCount(), '>', 1);
        } elseif (str_starts_with($subjectFilter, 'subject:') && $subjectOptions->contains('value', $subjectFilter)) {
            $normalizedSubjectName = mb_substr($subjectFilter, mb_strlen('subject:'));
            $query->where(function ($filtered) use ($questionSubjectCount, $normalizedSubjectName) {
                $filtered->where(function ($withQuestions) use ($questionSubjectCount, $normalizedSubjectName) {
                    $withQuestions->where($questionSubjectCount(), '=', 1)
                        ->whereExists(fn ($questionSubjects) => $questionSubjects
                            ->selectRaw('1')
                            ->from('assessment_question')
                            ->join('questions', 'questions.id', '=', 'assessment_question.question_id')
                            ->join('competencies', 'competencies.id', '=', 'questions.competency_id')
                            ->join('subjects', 'subjects.id', '=', 'competencies.subject_id')
                            ->whereColumn('assessment_question.assessment_id', 'assessments.id')
                            ->whereRaw('LOWER(TRIM(subjects.name)) = ?', [$normalizedSubjectName]));
                })->orWhere(function ($withoutQuestions) use ($questionSubjectCount, $normalizedSubjectName) {
                    $withoutQuestions->where($questionSubjectCount(), '=', 0)
                        ->whereHas('subject', fn ($subject) => $subject
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedSubjectName]));
                });
            });
        } elseif (ctype_digit($subjectFilter) && $subjects->contains('id', (int) $subjectFilter)) {
            $subjectId = (int) $subjectFilter;
            $query->where(function ($filtered) use ($questionSubjectCount, $questionSubjectId, $subjectId) {
                $filtered->where(function ($withQuestions) use ($questionSubjectCount, $questionSubjectId, $subjectId) {
                    $withQuestions->where($questionSubjectCount(), '=', 1)
                        ->where($questionSubjectId(), '=', $subjectId);
                })->orWhere(function ($withoutQuestions) use ($questionSubjectCount, $subjectId) {
                    $withoutQuestions->where($questionSubjectCount(), '=', 0)
                        ->where('subject_id', $subjectId);
                });
            });
        }

        if ($typeFilter === Assessment::TYPE_TOGETHER) {
            $query->where('settings->type', Assessment::TYPE_TOGETHER);
        } elseif ($typeFilter === Assessment::TYPE_REGULAR) {
            $query->where(fn ($regular) => $regular
                ->whereNull('settings->type')
                ->orWhere('settings->type', Assessment::TYPE_REGULAR));
        }

        $assessments = $query->get();
        $subjectsById = $subjects->keyBy('id');

        // Build sub-competency coverage per assessment for the manage view
        $subCompetencies = [];
        if ($user->hasRole(UserRole::Admin, UserRole::Teacher)) {
            $subCompetencies = Competency::query()
                ->whereNotNull('parent_id')
                ->get(['id', 'parent_id', 'code', 'name', 'grade_level', 'subject_id'])
                ->keyBy('id');
        }

        return Inertia::render('Assessments/Index', [
            'assessments' => $assessments->map(fn (Assessment $a) => [
                ...$a->toArray(),
                'settings' => [
                    ...($a->settings ?? []),
                    'type' => $a->assessmentType(),
                    'type_label' => config("assessment.types.{$a->assessmentType()}"),
                ],
                'average_difficulty' => $a->average_difficulty !== null
                    ? round((float) $a->average_difficulty, 2)
                    : null,
                'subject_label' => (int) $a->question_subject_count > 1
                    ? 'Campuran'
                    : ($a->question_subject_name
                        ?: $subjectsById->get((int) $a->subject_id)?->name
                        ?: 'Belum ditentukan'),
                'subject_kind' => (int) $a->question_subject_count > 1
                    ? 'mixed'
                    : ($a->question_subject_name
                        ? 'subject:'.mb_strtolower(trim($a->question_subject_name))
                        : ($subjectsById->get((int) $a->subject_id)
                            ? 'subject:'.mb_strtolower(trim($subjectsById->get((int) $a->subject_id)->name))
                            : 'unassigned')),
                'competency_coverage' => $user->hasRole(UserRole::Admin, UserRole::Teacher)
                    ? $a->questions
                        ->pluck('competency')
                        ->filter()
                        ->filter(fn ($c) => $c->parent_id !== null) // only sub-competencies
                        ->groupBy('id')
                        ->map->count()
                    : null,
                'can_manage' => $user->hasRole(UserRole::Admin, UserRole::Teacher),
            ]),
            'canManage' => $user->hasRole(UserRole::Admin, UserRole::Teacher),
            'subCompetencies' => $user->hasRole(UserRole::Admin, UserRole::Teacher)
                ? $subCompetencies->values()
                : [],
            'subjects' => $subjectOptions,
            'filters' => [
                'subject' => $subjectFilter,
                'type' => in_array($typeFilter, [Assessment::TYPE_REGULAR, Assessment::TYPE_TOGETHER], true)
                    ? $typeFilter
                    : '',
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Assessments/Create', $this->formProps($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $questions = $this->resolveQuestions($request, $data);
        $candidateQuestionIds = $this->candidateQuestionIds($request, $data);

        $assessment = DB::transaction(function () use ($data, $questions, $candidateQuestionIds, $request): Assessment {
            $assessment = Assessment::create([
                'created_by' => $request->user()->id,
                ...$this->attributes($data, candidateQuestionIds: $candidateQuestionIds),
                'status' => AssessmentStatus::Draft,
            ]);
            $this->syncQuestions($assessment, $questions);

            return $assessment;
        });

        return to_route('assessments.index')->with('success', "Paket {$assessment->title} berhasil dibuat.");
    }

    public function show(Request $request, Assessment $assessment): Response
    {
        $canManage = $request->user()->hasRole(UserRole::Admin, UserRole::Teacher);

        $assessment->load([
            'subject:id,code,name',
            'questions' => function ($q) {
                $q->with(['competency:id,parent_id,code,name,grade_level', 'competency.parent:id,name']);
            },
        ]);

        $slots = $assessment->competency_slots ?? [];
        $questions = $assessment->questions;

        // Sub-competencies coverage count
        $coverageMap = $questions
            ->pluck('competency')
            ->filter()
            ->filter(fn ($c) => $c->parent_id !== null)
            ->groupBy('id')
            ->map->count();

        // Relevant sub-competencies for this subject and grade level
        $subCompetencies = Competency::query()
            ->whereNotNull('parent_id')
            ->where('grade_level', $assessment->grade_level)
            ->when($assessment->subject_id, fn ($q) => $q->where('subject_id', $assessment->subject_id))
            ->with('parent:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn ($comp) => [
                'id' => $comp->id,
                'name' => $comp->name,
                'code' => $comp->code,
                'parent_name' => $comp->parent?->name ?? '',
                'is_selected_slot' => in_array($comp->id, $slots),
                'question_count' => $coverageMap[$comp->id] ?? 0,
            ]);

        $attachedQuestionIds = $questions->pluck('id')->all();
        $seenBundleIds = [];
        $availableBankQuestions = collect();

        if ($canManage) {
            $availableBankQuestions = Question::query()
                ->where('grade_level', $assessment->grade_level)
                ->where('status', QuestionStatus::Published)
                ->when($assessment->subject_id, function ($q) use ($assessment) {
                    $q->whereHas('competency', fn ($c) => $c->where('subject_id', $assessment->subject_id));
                })
                ->whereNotIn('id', $attachedQuestionIds)
                ->with([
                    'competency:id,parent_id,code,name',
                    'competency.parent:id,name',
                    'options',
                    'storyGeneration:id,request_payload,result_payload',
                    'bundleQuestions' => fn ($bundleQuestions) => $bundleQuestions
                        ->with([
                            'competency:id,parent_id,code,name',
                            'competency.parent:id,name',
                            'options',
                        ])
                        ->orderBy('id'),
                ])
                ->withCount('bundleQuestions as bundle_question_count')
                ->latest()
                ->limit(100)
                ->get()
                ->map(function ($q) use (&$seenBundleIds): array {
                    $isBundle = $q->story_generation_id !== null
                        && data_get($q->storyGeneration?->request_payload, 'format') !== 'direct';
                    $includeBundleQuestions = $isBundle && ! in_array($q->story_generation_id, $seenBundleIds, true);

                    if ($includeBundleQuestions) {
                        $seenBundleIds[] = $q->story_generation_id;
                    }

                    return [
                        'id' => $q->id,
                        'prompt' => $q->prompt,
                        'stimulus' => $q->stimulus,
                        'explanation' => $q->explanation,
                        'type' => $q->type,
                        'difficulty' => $q->difficulty,
                        'competency_id' => $q->competency?->id,
                        'competency_name' => $q->competency?->name ?? '-',
                        'competency_code' => $q->competency?->code ?? '-',
                        'parent_competency_name' => $q->competency?->parent?->name ?? '',
                        'is_bundle' => $isBundle,
                        'story_generation_id' => $isBundle ? $q->story_generation_id : null,
                        'bundle_title' => $isBundle
                            ? data_get($q->storyGeneration?->result_payload, 'title')
                            : null,
                        'bundle_question_count' => $isBundle ? $q->bundle_question_count : 1,
                        'bundle_questions' => $includeBundleQuestions
                            ? $q->bundleQuestions->map(fn (Question $bundleQuestion): array => [
                                'id' => $bundleQuestion->id,
                                'prompt' => $bundleQuestion->prompt,
                                'explanation' => $bundleQuestion->explanation,
                                'type' => $bundleQuestion->type,
                                'difficulty' => $bundleQuestion->difficulty,
                                'competency_name' => $bundleQuestion->competency?->name ?? '-',
                                'options' => $bundleQuestion->options->map(fn ($option): array => [
                                    'id' => $option->id,
                                    'label' => $option->label,
                                    'content' => $option->content,
                                    'option_text' => $option->content ?? $option->label ?? '',
                                    'is_correct' => (bool) $option->is_correct,
                                ]),
                            ])->values()
                            : [],
                        'options' => $q->options->map(fn ($opt) => [
                            'id' => $opt->id,
                            'label' => $opt->label,
                            'content' => $opt->content,
                            'option_text' => $opt->content ?? $opt->label ?? '',
                            'is_correct' => (bool) $opt->is_correct,
                        ]),
                    ];
                });
        }

        return Inertia::render('Assessments/Show', [
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description,
                'grade_level' => $assessment->grade_level,
                'duration_minutes' => $assessment->duration_minutes,
                'status' => $assessment->status,
                'starts_at' => $assessment->starts_at?->translatedFormat('d M Y H:i'),
                'ends_at' => $assessment->ends_at?->translatedFormat('d M Y H:i'),
                'subject' => $assessment->subject ? [
                    'id' => $assessment->subject->id,
                    'name' => $assessment->subject->name,
                    'code' => $assessment->subject->code,
                ] : null,
                'questions_count' => $questions->count(),
                'attempts_count' => $assessment->attempts()->count(),
                'competency_slots' => $slots,
            ],
            'questions' => $questions->map(fn ($q) => [
                'id' => $q->id,
                'title' => $q->title,
                'prompt' => $q->prompt,
                'stimulus' => $q->stimulus,
                'explanation' => $q->explanation,
                'type' => $q->type,
                'difficulty' => $q->difficulty,
                'competency' => $q->competency ? [
                    'id' => $q->competency->id,
                    'name' => $q->competency->name,
                    'code' => $q->competency->code,
                    'parent_name' => $q->competency->parent?->name ?? '',
                ] : null,
                'options' => $q->options ? $q->options->map(fn ($opt) => [
                    'id' => $opt->id,
                    'label' => $opt->label,
                    'content' => $opt->content,
                    'option_text' => $opt->content ?? $opt->label ?? '',
                    'is_correct' => (bool) $opt->is_correct,
                ]) : [],
            ]),
            'subCompetencies' => $subCompetencies,
            'availableBankQuestions' => $availableBankQuestions,
            'canManage' => $canManage,
            'canPreview' => $questions->isNotEmpty(),
        ]);
    }

    public function preview(
        Request $request,
        Assessment $assessment,
        QuestionSnapshotService $snapshotService,
    ): Response {
        $assessment->load(['questions.options']);
        abort_if($assessment->questions->isEmpty(), 409, 'Tambahkan soal sebelum membuka pratinjau siswa.');

        $settings = $assessment->settings ?? [];
        $previewKey = "assessment-preview:{$assessment->id}";
        $shuffleQuestions = (bool) data_get($settings, 'shuffle_questions', false);
        $shuffleOptions = (bool) data_get($settings, 'shuffle_options', false);
        $questions = $assessment->questions;

        if ($shuffleQuestions) {
            $questions = $questions->sortBy(
                fn (Question $question): string => hash('sha256', "{$previewKey}:question:{$question->id}"),
            )->values();
        }

        return Inertia::render('Attempts/Show', [
            'preview' => true,
            'attempt' => [
                'public_id' => $previewKey,
                'remaining_seconds' => $assessment->duration_minutes * 60,
                'assessment' => [
                    'title' => $assessment->title,
                    'duration_minutes' => $assessment->duration_minutes,
                    'type_label' => config("assessment.types.{$assessment->assessmentType()}", 'Try Out Adaptif'),
                    'show_navigation' => (bool) data_get($settings, 'show_navigation', true),
                    'require_all_answers' => (bool) data_get($settings, 'require_all_answers', false),
                ],
                'questions' => $questions->values()->map(function (Question $question, int $questionIndex) use ($previewKey, $shuffleOptions, $snapshotService): array {
                    $snapshot = $snapshotService->forQuestion($question);
                    $type = QuestionType::from($snapshot['type']);
                    $metadata = $snapshot['metadata'] ?? [];
                    $options = collect($snapshot['options'] ?? []);

                    if ($shuffleOptions) {
                        $options = $options->sortBy(
                            fn (array $option): string => hash('sha256', "{$previewKey}:question:{$question->id}:option:{$option['id']}"),
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
                                fn (array $item): string => hash('sha256', "{$previewKey}:question:{$question->id}:match:{$item['id']}"),
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
                                fn (array $column): string => hash('sha256', "{$previewKey}:question:{$question->id}:matrix:{$column['id']}"),
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
                        'stimulus' => $snapshot['stimulus'],
                        'stimulus_text_style' => $metadata['stimulus_text_style'] ?? null,
                        'stimulus_visual' => $metadata['stimulus_visual'] ?? null,
                        'illustration_url' => $snapshotService->illustrationUrl($snapshot),
                        'secondary_illustration_url' => $snapshotService->secondaryIllustrationUrl($snapshot),
                        'additional_illustration_urls' => $snapshotService->additionalIllustrationUrls($snapshot),
                        'illustration_display' => [
                            'source' => data_get($metadata, 'illustration.source'),
                            'width' => (int) data_get($metadata, 'illustration.display_width', 800),
                            'height' => (int) data_get($metadata, 'illustration.display_height', 450),
                            'text_position' => (int) data_get($metadata, 'illustration.text_position', 0),
                            'document_x' => (float) data_get($metadata, 'illustration.document_x', 8),
                            'document_y' => (float) data_get($metadata, 'illustration.document_y', 18),
                            'zoom' => (float) data_get($metadata, 'illustration.display_zoom', 1),
                            'offset_x' => (float) data_get($metadata, 'illustration.display_offset_x', 0),
                            'offset_y' => (float) data_get($metadata, 'illustration.display_offset_y', 0),
                        ],
                        'secondary_illustration_display' => [
                            'width' => (int) data_get($metadata, 'secondary_illustration.display_width', 320),
                            'document_x' => (float) data_get($metadata, 'secondary_illustration.document_x', 48),
                            'document_y' => (float) data_get($metadata, 'secondary_illustration.document_y', 48),
                            'alt' => data_get($metadata, 'secondary_illustration.alt', 'Gambar stimulus kedua'),
                        ],
                        'additional_illustration_displays' => collect(data_get($metadata, 'additional_illustrations', []))->map(fn (array $illustration): array => [
                            'width' => (int) ($illustration['display_width'] ?? 320),
                            'document_x' => (float) ($illustration['document_x'] ?? 20),
                            'document_y' => (float) ($illustration['document_y'] ?? 20),
                            'alt' => $illustration['alt'] ?? 'Gambar stimulus tambahan',
                        ])->values(),
                        'prompt' => $snapshot['prompt'],
                        'position' => $questionIndex + 1,
                        'matching' => $matching,
                        'matrix' => $matrix,
                        'options' => $options->values()->map(fn (array $option, int $optionIndex): array => [
                            'id' => $option['id'],
                            'label' => chr(65 + $optionIndex),
                            'content' => $option['content'],
                        ]),
                        'response' => null,
                    ];
                }),
            ],
        ]);
    }

    public function removeQuestion(Request $request, Assessment $assessment, Question $question): RedirectResponse
    {
        $this->ensureManageable($request);

        $assessment->questions()->detach($question->id);

        $remaining = $assessment->questions()->get();
        $assessment->questions()->sync(
            $remaining->values()->mapWithKeys(fn ($q, $index) => [
                $q->id => ['position' => $index + 1, 'points' => 1],
            ])->all()
        );

        return back()->with('success', 'Soal berhasil dihapus dari paket ini.');
    }

    public function swapQuestion(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->ensureManageable($request);

        $data = $request->validate([
            'old_question_id' => ['required', 'integer'],
            'new_question_id' => ['required', 'integer', 'exists:questions,id'],
        ]);

        $pivot = DB::table('assessment_question')
            ->where('assessment_id', $assessment->id)
            ->where('question_id', $data['old_question_id'])
            ->first();

        $position = $pivot?->position ?? 1;

        $assessment->questions()->detach($data['old_question_id']);
        $assessment->questions()->attach($data['new_question_id'], [
            'position' => $position,
            'points' => 1,
        ]);

        return back()->with('success', 'Soal berhasil diganti.');
    }

    public function attachQuestion(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->ensureManageable($request);

        $data = $request->validate([
            'question_id' => ['nullable', 'required_without:story_generation_id', 'integer', 'exists:questions,id'],
            'story_generation_id' => ['nullable', 'required_without:question_id', 'integer', 'exists:ai_generations,id'],
        ]);

        if (filled($data['story_generation_id'] ?? null)) {
            $generation = AiGeneration::findOrFail($data['story_generation_id']);
            $questions = Question::query()
                ->where('story_generation_id', $generation->id)
                ->whereNull('superseded_by_id')
                ->orderBy('id')
                ->get();

            abort_unless(
                data_get($generation->request_payload, 'format') !== 'direct'
                && $questions->isNotEmpty()
                && $questions->every(fn (Question $question): bool => $this->questionCanBeAttached($assessment, $question)),
                422,
                'Seluruh soal dalam bundel harus sudah terbit dan sesuai dengan paket.',
            );

            $attachedIds = $assessment->questions()->pluck('questions.id');
            $questions = $questions->whereNotIn('id', $attachedIds);

            DB::transaction(function () use ($assessment, $questions): void {
                $nextPosition = ((int) $assessment->questions()->max('assessment_question.position')) + 1;

                foreach ($questions as $question) {
                    $assessment->questions()->attach($question->id, [
                        'position' => $nextPosition++,
                        'points' => 1,
                    ]);
                }
            });

            return back()->with('success', $questions->count().' soal dalam bundel berhasil ditambahkan ke paket.');
        }

        $question = Question::findOrFail($data['question_id']);
        abort_unless($this->questionCanBeAttached($assessment, $question), 422, 'Soal tidak sesuai dengan paket.');

        if (! $assessment->questions()->where('question_id', $question->id)->exists()) {
            $nextPosition = ((int) $assessment->questions()->max('assessment_question.position')) + 1;
            $assessment->questions()->attach($question->id, [
                'position' => $nextPosition,
                'points' => 1,
            ]);
        }

        return back()->with('success', 'Soal berhasil ditambahkan ke paket.');
    }

    private function questionCanBeAttached(Assessment $assessment, Question $question): bool
    {
        return $question->grade_level === $assessment->grade_level
            && $question->status === QuestionStatus::Published
            && ($assessment->subject_id === null || $question->competency()->where('subject_id', $assessment->subject_id)->exists());
    }

    public function edit(Request $request, Assessment $assessment): Response
    {
        $this->ensureEditable($request, $assessment);
        $assessment->load(['questions:id,competency_id', 'questions.competency:id,parent_id']);
        $settings = $assessment->settings ?? [];

        return Inertia::render('Assessments/Create', [
            ...$this->formProps($request, $assessment->questions->pluck('id')->all()),
            'assessment' => [
                'id' => $assessment->id,
                'subject_id' => $assessment->subject_id,
                'title' => $assessment->title,
                'description' => $assessment->description ?? '',
                'grade_level' => $assessment->grade_level,
                'duration_minutes' => $assessment->duration_minutes,
                'assessment_type' => $assessment->assessmentType(),
                'custom_type_name' => '',
                'selection_mode' => data_get($settings, 'selection_mode', 'manual'),
                'question_count' => $assessment->questions->count(),
                'question_ids' => $assessment->questions->pluck('id')->all(),
                'competency_rows' => data_get($settings, 'competency_rows', []),
                'blueprint_rows' => data_get($settings, 'blueprint_rows', []),
                'competency_slots' => $assessment->competency_slots ?? [],
                'starts_at' => $assessment->starts_at?->format('Y-m-d\TH:i') ?? '',
                'ends_at' => $assessment->ends_at?->format('Y-m-d\TH:i') ?? '',
                'shuffle_questions' => (bool) data_get($settings, 'shuffle_questions', false),
                'shuffle_options' => (bool) data_get($settings, 'shuffle_options', false),
                'show_navigation' => (bool) data_get($settings, 'show_navigation', true),
                'require_all_answers' => (bool) data_get($settings, 'require_all_answers', false),
                // coverage: sub-competency_id => count of questions in this assessment
                'competency_coverage' => $assessment->questions
                    ->pluck('competency')
                    ->filter()
                    ->filter(fn ($c) => $c->parent_id !== null)
                    ->groupBy('id')
                    ->map->count(),
            ],
        ]);
    }

    public function update(Request $request, Assessment $assessment, NotificationAudience $audience): RedirectResponse
    {
        $this->ensureEditable($request, $assessment);
        $wasPublished = $assessment->status === AssessmentStatus::Published;
        $data = $this->validatedData($request);
        $questions = $this->resolveQuestions($request, $data, $assessment);
        $candidateQuestionIds = $this->candidateQuestionIds($request, $data, $assessment);

        DB::transaction(function () use ($assessment, $data, $questions, $candidateQuestionIds): void {
            $assessment->update([
                ...$this->attributes($data, $assessment->settings ?? [], $candidateQuestionIds),
                'status' => AssessmentStatus::Draft,
            ]);
            $this->syncQuestions($assessment, $questions);
        });

        if ($wasPublished) {
            $audience->send(
                $audience->studentsForAssessment($assessment),
                new ActionNotification(
                    'Paket try out sedang diperbarui',
                    "Paket {$assessment->title} diperbarui dan sementara kembali menjadi draft sampai diterbitkan ulang.",
                    route('assessments.index', absolute: false),
                    'warning',
                    "assessment-updated:{$assessment->id}:{$assessment->updated_at?->timestamp}",
                ),
            );
        }

        return to_route('assessments.index')
            ->with('success', 'Perubahan paket disimpan sebagai draft dan perlu diterbitkan ulang.');
    }

    public function publish(
        Request $request,
        Assessment $assessment,
        QuestionSnapshotService $snapshotService,
    ): RedirectResponse {
        $this->ensureManageable($request);
        abort_if($assessment->questions()->count() === 0, 422, 'Paket belum memiliki soal.');
        $assessment->load('questions.options');
        abort_if(
            $assessment->questions->contains(
                fn (Question $question): bool => $question->status !== QuestionStatus::Published
                    && ! $snapshotService->hasSnapshot($question),
            ),
            422,
            'Soal baru dalam paket harus berstatus terbit.',
        );

        $snapshotService->snapshotAssessment($assessment);
        $assessment->update(['status' => AssessmentStatus::Published]);

        if (! $assessment->requiresSchoolSchedule()) {
            User::query()
                ->where('role', UserRole::Student)
                ->where('grade_level', $assessment->grade_level)
                ->where('is_active', true)
                ->chunkById(200, function ($students) use ($assessment): void {
                    foreach ($students as $student) {
                        $student->notify(new ActionNotification(
                            'Try out baru tersedia',
                            "Paket {$assessment->title} sudah diterbitkan dan dapat dikerjakan.",
                            route('assessments.show', $assessment, absolute: false),
                            'info',
                        ));
                    }
                });
        }

        return back()->with('success', 'Paket try out telah diterbitkan.');
    }

    private function validatedData(Request $request): array
    {
        $request->merge([
            'subject_id' => $request->input('subject_id') ? (int) $request->input('subject_id') : null,
            'starts_at' => $request->input('starts_at') ?: null,
            'ends_at' => $request->input('ends_at') ?: null,
            'description' => $request->input('description') ?: null,
        ]);

        $data = $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'competency_slots' => ['nullable', 'array'],
            'competency_slots.*' => ['integer', 'exists:competencies,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'grade_level' => ['required', 'integer', Rule::in([6, 9, 12])],
            'duration_minutes' => ['required', 'integer', 'between:5,480'],
            'assessment_type' => ['required', 'string', Rule::in(array_keys(config('assessment.types')))],
            'selection_mode' => ['required', Rule::in(['manual', 'automatic', 'competency', 'blueprint'])],
            'question_count' => ['required', 'integer', 'between:1,100'],
            'question_ids' => ['nullable', 'required_if:selection_mode,manual', 'array', 'max:100'],
            'question_ids.*' => ['integer', 'distinct'],
            'competency_rows' => ['nullable', 'array', 'max:30'],
            'competency_rows.*.competency_id' => ['required_if:selection_mode,competency', 'integer'],
            'competency_rows.*.count' => ['required_if:selection_mode,competency', 'integer', 'between:1,100'],
            'blueprint_rows' => ['nullable', 'array', 'max:30'],
            'blueprint_rows.*.competency_id' => ['required_if:selection_mode,blueprint', 'integer'],
            'blueprint_rows.*.type' => ['required_if:selection_mode,blueprint', Rule::enum(QuestionType::class)],
            'blueprint_rows.*.difficulty' => ['required_if:selection_mode,blueprint', 'integer', 'between:1,3'],
            'blueprint_rows.*.count' => ['required_if:selection_mode,blueprint', 'integer', 'between:1,100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'shuffle_questions' => ['required', 'boolean'],
            'shuffle_options' => ['required', 'boolean'],
            'show_navigation' => ['required', 'boolean'],
            'require_all_answers' => ['required', 'boolean'],
        ], [
            'title.required' => 'Judul paket ujian wajib diisi.',
            'title.max' => 'Judul paket tidak boleh lebih dari 255 karakter.',
            'grade_level.required' => 'Jenjang kelas wajib dipilih.',
            'grade_level.in' => 'Jenjang kelas harus 6, 9, atau 12.',
            'duration_minutes.required' => 'Durasi pengerjaan wajib diisi.',
            'duration_minutes.between' => 'Durasi pengerjaan harus antara 5 sampai 480 menit.',
            'question_count.required' => 'Jumlah soal wajib diisi.',
            'question_count.between' => 'Jumlah soal harus antara 1 sampai 100.',
            'ends_at.after' => 'Waktu ditutup harus setelah waktu mulai tersedia.',
        ]);

        if ($data['selection_mode'] === 'competency') {
            $rows = collect($data['competency_rows']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'competency_rows' => 'Tabel aturan kompetensi wajib diisi minimal 1 baris.',
                ]);
            }

            if ($rows->sum('count') !== (int) $data['question_count']) {
                throw ValidationException::withMessages([
                    'competency_rows' => 'Total kuota kompetensi harus sama dengan target jumlah soal.',
                ]);
            }

            if ($rows->pluck('competency_id')->unique()->count() !== $rows->count()) {
                throw ValidationException::withMessages([
                    'competency_rows' => 'Setiap kompetensi hanya boleh ditambahkan satu kali.',
                ]);
            }
        } elseif ($data['selection_mode'] === 'blueprint') {
            $rows = collect($data['blueprint_rows']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'blueprint_rows' => 'Tabel aturan blueprint wajib diisi minimal 1 baris.',
                ]);
            }
            $signatures = $rows->map(fn (array $row): string => implode(':', [
                $row['competency_id'],
                $row['type'],
                $row['difficulty'],
            ]));

            if ($rows->sum('count') !== (int) $data['question_count']) {
                throw ValidationException::withMessages([
                    'blueprint_rows' => 'Total kuota blueprint harus sama dengan target jumlah soal.',
                ]);
            }

            if ($signatures->unique()->count() !== $signatures->count()) {
                throw ValidationException::withMessages([
                    'blueprint_rows' => 'Kombinasi kompetensi, bentuk soal, dan kesulitan tidak boleh duplikat.',
                ]);
            }
        }

        return $data;
    }

    private function resolveQuestions(Request $request, array $data, ?Assessment $assessment = null): Collection
    {
        $questionQuery = Question::query()
            ->where('grade_level', $data['grade_level'])
            ->when(! empty($data['subject_id']), function ($q) use ($data) {
                $q->whereHas('competency', fn ($comp) => $comp->where('subject_id', $data['subject_id']));
            });

        if ($data['selection_mode'] === 'manual') {
            if (count($data['question_ids']) !== (int) $data['question_count']) {
                throw ValidationException::withMessages([
                    'question_ids' => 'Jumlah soal yang dipilih harus sama dengan target jumlah soal.',
                ]);
            }

            $existingQuestionIds = $assessment?->questions()->pluck('questions.id') ?? collect();
            $questions = $questionQuery
                ->where(fn ($query) => $query
                    ->where('status', QuestionStatus::Published)
                    ->when($existingQuestionIds->isNotEmpty(), fn ($nested) => $nested->orWhereIn('questions.id', $existingQuestionIds)))
                ->whereIn('questions.id', $data['question_ids'])
                ->get();
        } elseif ($data['selection_mode'] === 'competency') {
            $existingComposition = data_get($assessment?->settings, 'competency_rows', []);
            if ($assessment
                && $assessment->grade_level === (int) $data['grade_level']
                && $assessment->questions()->count() === (int) $data['question_count']
                && $existingComposition === $data['competency_rows']) {
                return $assessment->questions()->get();
            }

            $questions = new Collection;
            foreach ($data['competency_rows'] as $index => $row) {
                $selected = $this->prioritizeLeastUsed(
                    (clone $questionQuery)
                        ->where('status', QuestionStatus::Published)
                        ->where('competency_id', $row['competency_id'])
                        ->whereNotIn('id', $questions->pluck('id')),
                    $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id,
                )
                    ->limit($row['count'])
                    ->get();

                if ($selected->count() !== (int) $row['count']) {
                    throw ValidationException::withMessages([
                        "competency_rows.{$index}.count" => 'Bank soal terbit untuk kompetensi ini belum mencukupi kuota.',
                    ]);
                }

                $questions->push(...$selected);
            }
        } elseif ($data['selection_mode'] === 'blueprint') {
            $existingBlueprint = data_get($assessment?->settings, 'blueprint_rows', []);
            if ($assessment
                && $assessment->grade_level === (int) $data['grade_level']
                && $assessment->questions()->count() === (int) $data['question_count']
                && $existingBlueprint === $data['blueprint_rows']) {
                return $assessment->questions()->get();
            }

            $questions = new Collection;
            foreach ($data['blueprint_rows'] as $index => $row) {
                $selected = $this->prioritizeLeastUsed(
                    (clone $questionQuery)
                        ->where('status', QuestionStatus::Published)
                        ->where('competency_id', $row['competency_id'])
                        ->where('type', $row['type'])
                        ->where('difficulty', $row['difficulty'])
                        ->whereNotIn('id', $questions->pluck('id')),
                    $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id,
                )
                    ->limit($row['count'])
                    ->get();

                if ($selected->count() !== (int) $row['count']) {
                    throw ValidationException::withMessages([
                        "blueprint_rows.{$index}.count" => 'Bank soal belum mencukupi kuota pada kombinasi ini.',
                    ]);
                }

                $questions->push(...$selected);
            }
        } elseif ($assessment
            && $assessment->grade_level === (int) $data['grade_level']
            && $assessment->questions()->count() === (int) $data['question_count']) {
            $questions = $assessment->questions()->get();
        } else {
            $questions = $this->prioritizeLeastUsed(
                $questionQuery->where('status', QuestionStatus::Published),
                $request->user()->hasRole(UserRole::Admin) ? null : $request->user()->school_id,
            )
                ->limit($data['question_count'])
                ->get();
        }

        // Enforce full target question count check only when publishing or published.
        // Draft assessments can be created and updated freely while building the question bank.
        if ($assessment?->status === AssessmentStatus::Published && $questions->count() !== (int) $data['question_count']) {
            throw ValidationException::withMessages([
                'question_ids' => $data['selection_mode'] === 'manual'
                    ? 'Ada soal yang tidak tersedia, belum diterbitkan, atau berbeda jenjang.'
                    : 'Jumlah soal terbit pada jenjang ini belum mencukupi target.',
            ]);
        }

        return $questions;
    }

    private function prioritizeLeastUsed(Builder $query, ?int $schoolId): Builder
    {
        $schoolUsage = DB::table('assessment_question')
            ->join('attempts', 'attempts.assessment_id', '=', 'assessment_question.assessment_id')
            ->join('users', 'users.id', '=', 'attempts.user_id')
            ->whereColumn('assessment_question.question_id', 'questions.id')
            ->when($schoolId !== null, fn ($attempts) => $attempts->where('users.school_id', $schoolId))
            ->selectRaw('COUNT(attempts.id)');

        return $query
            ->orderBy($schoolUsage)
            ->inRandomOrder();
    }

    private function attributes(array $data, array $existingSettings = [], array $candidateQuestionIds = []): array
    {
        $typeLabel = config("assessment.types.{$data['assessment_type']}");

        return [
            'subject_id' => $data['subject_id'] ?? null,
            'competency_slots' => array_values(array_map('intval', $data['competency_slots'] ?? [])) ?: null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'grade_level' => $data['grade_level'],
            'duration_minutes' => $data['duration_minutes'],
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'settings' => [
                ...$existingSettings,
                'type' => $data['assessment_type'],
                'type_label' => $typeLabel,
                'selection_mode' => $data['selection_mode'],
                'question_count' => (int) $data['question_count'],
                'candidate_question_ids' => $candidateQuestionIds,
                'competency_rows' => $data['selection_mode'] === 'competency'
                    ? array_values($data['competency_rows'])
                    : [],
                'blueprint_rows' => $data['selection_mode'] === 'blueprint'
                    ? array_values($data['blueprint_rows'])
                    : [],
                'shuffle_questions' => (bool) $data['shuffle_questions'],
                'shuffle_options' => (bool) $data['shuffle_options'],
                'show_navigation' => (bool) $data['show_navigation'],
                'require_all_answers' => (bool) $data['require_all_answers'],
            ],
        ];
    }

    private function candidateQuestionIds(Request $request, array $data, ?Assessment $assessment = null): array
    {
        if ($data['selection_mode'] === 'manual') {
            return array_values(array_map('intval', $data['question_ids']));
        }

        $query = Question::query()
            ->where('grade_level', $data['grade_level'])
            ->when(! empty($data['subject_id']), function ($q) use ($data) {
                $q->whereHas('competency', fn ($comp) => $comp->where('subject_id', $data['subject_id']));
            })
            ->where(fn ($questions) => $questions
                ->where('status', QuestionStatus::Published)
                ->when($assessment, fn ($existing) => $existing->orWhereIn('id',
                    $assessment->questions()->pluck('questions.id')
                )));

        if ($data['selection_mode'] === 'competency') {
            $query->whereIn('competency_id', collect($data['competency_rows'])->pluck('competency_id'));
        } elseif ($data['selection_mode'] === 'blueprint') {
            $query->where(function ($combinations) use ($data): void {
                foreach ($data['blueprint_rows'] as $row) {
                    $combinations->orWhere(fn ($combination) => $combination
                        ->where('competency_id', $row['competency_id'])
                        ->where('type', $row['type'])
                        ->where('difficulty', $row['difficulty']));
                }
            });
        }

        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function syncQuestions(Assessment $assessment, Collection $questions): void
    {
        $assessment->questions()->sync(
            $questions->values()->mapWithKeys(fn (Question $question, int $index): array => [
                $question->id => ['position' => $index + 1, 'points' => 1, 'snapshot' => null],
            ])->all(),
        );
    }

    private function formProps(Request $request, array $includedQuestionIds = []): array
    {
        return [
            'assessmentTypes' => config('assessment.types'),
            'questionTypes' => [
                QuestionType::SingleChoice->value => 'Pilihan tunggal',
                QuestionType::MultipleChoice->value => 'Pilihan kompleks',
                QuestionType::ShortAnswer->value => 'Isian singkat',
                QuestionType::Matching->value => 'Menjodohkan',
                QuestionType::CategoryMatrix->value => 'Pilihan kategori (tabel)',
            ],
            'subjects' => Subject::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'competencies' => Competency::query()
                ->orderBy('grade_level')
                ->orderBy('code')
                ->get(['id', 'parent_id', 'code', 'name', 'grade_level', 'subject_id']),
            'questions' => Question::query()
                ->where(fn ($query) => $query
                    ->where('status', QuestionStatus::Published)
                    ->when($includedQuestionIds !== [], fn ($nested) => $nested->orWhereIn('id', $includedQuestionIds)))
                ->with('competency:id,code,name,subject_id,parent_id')
                ->orderBy('grade_level')
                ->latest()
                ->get(['id', 'competency_id', 'type', 'title', 'prompt', 'grade_level', 'difficulty']),
        ];
    }

    private function ensureManageable(Request $request): void
    {
        abort_unless($request->user()->hasRole(UserRole::Admin, UserRole::Teacher), 403);
    }

    private function ensureEditable(Request $request, Assessment $assessment): void
    {
        $this->ensureManageable($request);
    }
}
