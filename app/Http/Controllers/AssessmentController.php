<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Services\QuestionSnapshotService;
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
    public function index(Request $request): Response
    {
        $user = $request->user();
        $query = Assessment::query()
            ->withCount(['questions', 'attempts'])
            ->latest();

        if ($user->hasRole(UserRole::Student)) {
            $query->where('status', AssessmentStatus::Published)
                ->where('grade_level', $user->grade_level)
                ->with(['attempts' => fn ($attempts) => $attempts->where('user_id', $user->id)]);
        } else {
            $query->where('school_id', $user->school_id);
        }

        return Inertia::render('Assessments/Index', [
            'assessments' => $query->get(),
            'canManage' => $user->hasRole(UserRole::Admin, UserRole::Teacher),
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

        $assessment = DB::transaction(function () use ($data, $questions, $request): Assessment {
            $assessment = Assessment::create([
                'school_id' => $request->user()->school_id,
                'created_by' => $request->user()->id,
                ...$this->attributes($data),
                'status' => AssessmentStatus::Draft,
            ]);
            $this->syncQuestions($assessment, $questions);

            return $assessment;
        });

        return to_route('assessments.index')->with('success', "Paket {$assessment->title} berhasil dibuat.");
    }

    public function edit(Request $request, Assessment $assessment): Response
    {
        $this->ensureEditable($request, $assessment);
        $assessment->load('questions:id');
        $settings = $assessment->settings ?? [];

        return Inertia::render('Assessments/Create', [
            ...$this->formProps($request, $assessment->questions->pluck('id')->all()),
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'description' => $assessment->description ?? '',
                'grade_level' => $assessment->grade_level,
                'duration_minutes' => $assessment->duration_minutes,
                'assessment_type' => data_get($settings, 'type', 'tryout'),
                'custom_type_name' => data_get($settings, 'type') === 'custom'
                    ? data_get($settings, 'type_label', '')
                    : '',
                'selection_mode' => data_get($settings, 'selection_mode', 'manual'),
                'question_count' => $assessment->questions->count(),
                'question_ids' => $assessment->questions->pluck('id')->all(),
                'competency_rows' => data_get($settings, 'competency_rows', []),
                'blueprint_rows' => data_get($settings, 'blueprint_rows', []),
                'starts_at' => $assessment->starts_at?->format('Y-m-d\TH:i') ?? '',
                'ends_at' => $assessment->ends_at?->format('Y-m-d\TH:i') ?? '',
                'shuffle_questions' => (bool) data_get($settings, 'shuffle_questions', false),
                'shuffle_options' => (bool) data_get($settings, 'shuffle_options', false),
                'show_navigation' => (bool) data_get($settings, 'show_navigation', true),
                'require_all_answers' => (bool) data_get($settings, 'require_all_answers', false),
            ],
        ]);
    }

    public function update(Request $request, Assessment $assessment): RedirectResponse
    {
        $this->ensureEditable($request, $assessment);
        $data = $this->validatedData($request);
        $questions = $this->resolveQuestions($request, $data, $assessment);

        DB::transaction(function () use ($assessment, $data, $questions): void {
            $assessment->update([
                ...$this->attributes($data, $assessment->settings ?? []),
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
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'grade_level' => ['required', 'integer', Rule::in([5, 8, 11])],
            'duration_minutes' => ['required', 'integer', 'between:5,480'],
            'assessment_type' => ['required', 'string', Rule::in(array_keys(config('assessment.types')))],
            'custom_type_name' => ['nullable', 'required_if:assessment_type,custom', 'string', 'max:100'],
            'selection_mode' => ['required', Rule::in(['manual', 'automatic', 'competency', 'blueprint'])],
            'question_count' => ['required', 'integer', 'between:1,100'],
            'question_ids' => ['nullable', 'required_if:selection_mode,manual', 'array', 'max:100'],
            'question_ids.*' => ['integer', 'distinct'],
            'competency_rows' => ['nullable', 'required_if:selection_mode,competency', 'array', 'between:1,30'],
            'competency_rows.*.competency_id' => ['required_if:selection_mode,competency', 'integer'],
            'competency_rows.*.count' => ['required_if:selection_mode,competency', 'integer', 'between:1,100'],
            'blueprint_rows' => ['nullable', 'required_if:selection_mode,blueprint', 'array', 'between:1,30'],
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
        ]);

        if ($data['selection_mode'] === 'competency') {
            $rows = collect($data['competency_rows']);

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
            ->where('grade_level', $data['grade_level']);

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
                $selected = (clone $questionQuery)
                    ->where('status', QuestionStatus::Published)
                    ->where('competency_id', $row['competency_id'])
                    ->whereNotIn('id', $questions->pluck('id'))
                    ->inRandomOrder()
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
                $selected = (clone $questionQuery)
                    ->where('status', QuestionStatus::Published)
                    ->where('competency_id', $row['competency_id'])
                    ->where('type', $row['type'])
                    ->where('difficulty', $row['difficulty'])
                    ->whereNotIn('id', $questions->pluck('id'))
                    ->inRandomOrder()
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
            $questions = $questionQuery
                ->where('status', QuestionStatus::Published)
                ->inRandomOrder()
                ->limit($data['question_count'])
                ->get();
        }

        if ($questions->count() !== (int) $data['question_count']) {
            throw ValidationException::withMessages([
                'question_ids' => $data['selection_mode'] === 'manual'
                    ? 'Ada soal yang tidak tersedia, belum diterbitkan, atau berbeda jenjang.'
                    : 'Jumlah soal terbit pada jenjang ini belum mencukupi target.',
            ]);
        }

        return $questions;
    }

    private function attributes(array $data, array $existingSettings = []): array
    {
        $typeLabel = $data['assessment_type'] === 'custom'
            ? trim($data['custom_type_name'])
            : config("assessment.types.{$data['assessment_type']}");

        return [
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
            'competencies' => Competency::query()
                ->where(fn ($query) => $query
                    ->whereNull('school_id')
                    ->orWhere('school_id', $request->user()->school_id))
                ->orderBy('grade_level')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'grade_level']),
            'questions' => Question::query()
                ->where('school_id', $request->user()->school_id)
                ->where(fn ($query) => $query
                    ->where('status', QuestionStatus::Published)
                    ->when($includedQuestionIds !== [], fn ($nested) => $nested->orWhereIn('id', $includedQuestionIds)))
                ->with('competency:id,code,name')
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
        abort_if($assessment->attempts()->exists(), 409, 'Paket tidak dapat diedit karena sudah memiliki peserta.');
    }
}
