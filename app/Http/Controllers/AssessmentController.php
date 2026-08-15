<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\Subject;
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

        $query = Assessment::query()
            ->withCount(['questions', 'attempts'])
            ->latest();

        if ($user->hasRole(UserRole::Student)) {
            $schoolNpsn = $user->school()->value('npsn');
            $query->where('status', AssessmentStatus::Published)
                ->where('grade_level', $user->grade_level)
                ->with(['schedules' => fn ($schedules) => $schedules->where('school_npsn', $schoolNpsn)])
                ->with(['attempts' => fn ($attempts) => $attempts->where('user_id', $user->id)]);
        } else {
            $query->where('school_id', $user->school_id)
                ->withCount('schedules')
                ->with(['questions:id,competency_id', 'questions.competency:id,parent_id,code,name']);
        }

        $assessments = $query->get();

        // Build sub-competency coverage per assessment for the manage view
        $subCompetencies = [];
        if ($user->hasRole(UserRole::Admin, UserRole::Teacher)) {
            $subCompetencies = Competency::query()
                ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $user->school_id))
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
                'competency_coverage' => $user->hasRole(UserRole::Admin, UserRole::Teacher)
                    ? $a->questions
                        ->pluck('competency')
                        ->filter()
                        ->filter(fn ($c) => $c->parent_id !== null) // only sub-competencies
                        ->groupBy('id')
                        ->map->count()
                    : null,
            ]),
            'canManage' => $user->hasRole(UserRole::Admin, UserRole::Teacher),
            'subCompetencies' => $user->hasRole(UserRole::Admin, UserRole::Teacher)
                ? $subCompetencies->values()
                : [],
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
                'school_id' => $request->user()->school_id,
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
        $this->ensureSameSchool($request, $assessment);

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

        $availableBankQuestions = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->where('grade_level', $assessment->grade_level)
            ->where('status', QuestionStatus::Published)
            ->when($assessment->subject_id, function ($q) use ($assessment) {
                $q->whereHas('competency', fn ($c) => $c->where('subject_id', $assessment->subject_id));
            })
            ->whereNotIn('id', $attachedQuestionIds)
            ->with(['competency:id,parent_id,code,name', 'competency.parent:id,name', 'options'])
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn ($q) => [
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
                'options' => $q->options->map(fn ($opt) => [
                    'id' => $opt->id,
                    'label' => $opt->label,
                    'content' => $opt->content,
                    'option_text' => $opt->content ?? $opt->label ?? '',
                    'is_correct' => (bool) $opt->is_correct,
                ]),
            ]);

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
        ]);
    }

    public function removeQuestion(Request $request, Assessment $assessment, Question $question): RedirectResponse
    {
        $this->ensureSameSchool($request, $assessment);

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
        $this->ensureSameSchool($request, $assessment);

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
        $this->ensureSameSchool($request, $assessment);

        $data = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
        ]);

        if (! $assessment->questions()->where('question_id', $data['question_id'])->exists()) {
            $nextPos = $assessment->questions()->count() + 1;
            $assessment->questions()->attach($data['question_id'], [
                'position' => $nextPos,
                'points' => 1,
            ]);
        }

        return back()->with('success', 'Soal berhasil ditambahkan ke paket.');
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

    public function update(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->ensureEditable($request, $assessment);
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

        return to_route('assessments.index')
            ->with('success', 'Perubahan paket disimpan sebagai draft dan perlu diterbitkan ulang.');
    }

    public function publish(
        Request $request,
        Assessment $assessment,
        QuestionSnapshotService $snapshotService,
    ): RedirectResponse {
        $this->ensureSameSchool($request, $assessment);
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
            ->where('school_id', $request->user()->school_id)
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
                    $request->user()->school_id,
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
                    $request->user()->school_id,
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
                $request->user()->school_id,
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

    private function prioritizeLeastUsed(Builder $query, int $schoolId): Builder
    {
        $schoolUsage = DB::table('assessment_question')
            ->join('attempts', 'attempts.assessment_id', '=', 'assessment_question.assessment_id')
            ->join('users', 'users.id', '=', 'attempts.user_id')
            ->whereColumn('assessment_question.question_id', 'questions.id')
            ->where('users.school_id', $schoolId)
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
            ->where('school_id', $request->user()->school_id)
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
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'competencies' => Competency::query()
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->orderBy('grade_level')
                ->orderBy('code')
                ->get(['id', 'parent_id', 'code', 'name', 'grade_level', 'subject_id']),
            'questions' => Question::query()
                ->where('school_id', $request->user()->school_id)
                ->where(fn ($query) => $query
                    ->where('status', QuestionStatus::Published)
                    ->when($includedQuestionIds !== [], fn ($nested) => $nested->orWhereIn('id', $includedQuestionIds)))
                ->with('competency:id,code,name,subject_id,parent_id')
                ->orderBy('grade_level')
                ->latest()
                ->get(['id', 'competency_id', 'type', 'title', 'prompt', 'grade_level', 'difficulty']),
        ];
    }

    private function ensureSameSchool(Request $request, Assessment $assessment): void
    {
        abort_unless($assessment->school_id === $request->user()->school_id, 404);
    }

    private function ensureEditable(Request $request, Assessment $assessment): void
    {
        $this->ensureSameSchool($request, $assessment);
    }
}
