<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function index(Request $request): Response
    {
        $questions = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->whereNull('superseded_by_id')
            ->where(function ($query) {
                $query->whereNull('questions.story_generation_id')
                    ->orWhereNotExists(function ($subquery) {
                        $subquery->selectRaw('1')
                            ->from('questions as earlier_bundle_questions')
                            ->whereColumn('earlier_bundle_questions.story_generation_id', 'questions.story_generation_id')
                            ->whereNull('earlier_bundle_questions.superseded_by_id')
                            ->whereColumn('earlier_bundle_questions.id', '<', 'questions.id');
                    });
            })
            ->with([
                'competency:id,code,name',
                'author:id,name',
                'storyGeneration:id,request_payload,result_payload',
            ])
            ->withCount([
                'variants',
                'bundleQuestions as bundle_question_count',
                'bundleQuestions as bundle_draft_count' => fn ($query) => $query->where('status', QuestionStatus::Draft),
                'bundleQuestions as bundle_published_count' => fn ($query) => $query->where('status', QuestionStatus::Published),
                'bundleQuestions as bundle_archived_count' => fn ($query) => $query->where('status', QuestionStatus::Archived),
            ])
            ->when($request->string('search')->toString(), function ($query, string $search) {
                $query->where(fn ($nested) => $nested
                    ->where('questions.title', 'like', "%{$search}%")
                    ->orWhere('questions.prompt', 'like', "%{$search}%")
                    ->orWhereHas('bundleQuestions', fn ($bundleQuestion) => $bundleQuestion
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('prompt', 'like', "%{$search}%")));
            })
            ->when($request->string('status')->toString(), function ($query, string $status) {
                $query->where(fn ($filtered) => $filtered
                    ->where(fn ($standalone) => $standalone
                        ->whereNull('questions.story_generation_id')
                        ->where('questions.status', $status))
                    ->orWhere(fn ($bundle) => $bundle
                        ->whereNotNull('questions.story_generation_id')
                        ->whereHas('bundleQuestions', fn ($bundleQuestion) => $bundleQuestion->where('status', $status))));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Questions/Index', [
            'questions' => $questions,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Questions/Create', [
            'competencies' => $this->competencies($request),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $this->validatedData($request);
        $question = DB::transaction(function () use ($data, $request): Question {
            $question = Question::create([
                ...$this->attributes($data),
                'school_id' => $request->user()->school_id,
                'author_id' => $request->user()->id,
                'status' => QuestionStatus::Draft,
            ]);
            $this->syncOptions($question, $data['options'] ?? []);

            return $question;
        });
        $auditLogger->log($request, 'question.created', $question);

        return to_route('questions.show', $question)->with('success', 'Soal berhasil disimpan sebagai draft.');
    }

    public function show(Request $request, Question $question): Response
    {
        $this->ensureSameSchool($request, $question);
        $question->load([
            'competency:id,code,domain,name',
            'author:id,name',
            'options',
            'reviews.reviewer:id,name',
            'variants' => fn ($query) => $query->with('competency:id,code,name')->latest(),
            'revisionOf:id,title,version,status',
            'supersededBy:id,title,version,status',
        ]);

        return Inertia::render('Questions/Show', [
            'question' => $question,
            'latestGeneration' => AiGeneration::query()
                ->where('source_question_id', $question->id)
                ->where('type', AiGenerationType::QuestionVariants)
                ->latest()
                ->first(),
        ]);
    }

    public function edit(Request $request, Question $question): Response
    {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan oleh revisi yang lebih baru.');
        $question->load('options');

        return Inertia::render('Questions/Create', [
            'competencies' => $this->competencies($request),
            'question' => $question,
        ]);
    }

    public function update(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan oleh revisi yang lebih baru.');
        $data = $this->validatedData($request);
        $createRevision = $question->status === QuestionStatus::Published
            || $question->assessments()->exists();

        $savedQuestion = DB::transaction(function () use ($question, $data, $request, $createRevision): Question {
            if ($createRevision) {
                $revision = Question::create([
                    ...$this->attributes($data, $question->metadata ?? []),
                    'school_id' => $question->school_id,
                    'author_id' => $request->user()->id,
                    'parent_id' => $question->parent_id,
                    'revision_of_id' => $question->id,
                    'version' => $question->version + 1,
                    'story_generation_id' => $question->story_generation_id,
                    'status' => QuestionStatus::Draft,
                ]);
                $this->syncOptions($revision, $data['options'] ?? []);

                return $revision;
            }

            $question->update([
                ...$this->attributes($data, $question->metadata ?? []),
                'status' => QuestionStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $this->syncOptions($question, $data['options'] ?? []);

            return $question;
        });
        $auditLogger->log($request, $createRevision ? 'question.revision_created' : 'question.updated', $savedQuestion, [
            'revision_of_id' => $createRevision ? $question->id : null,
        ]);

        return to_route('questions.show', $savedQuestion)->with(
            'success',
            $createRevision
                ? "Revisi versi {$savedQuestion->version} disimpan sebagai draft. Versi lama tetap aman untuk paket yang sudah terbit."
                : 'Perubahan disimpan sebagai draft dan perlu diterbitkan ulang.',
        );
    }

    public function duplicate(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        $question->load('options');

        $duplicate = DB::transaction(function () use ($question, $request): Question {
            $duplicate = $question->replicate([
                'revision_of_id', 'version', 'superseded_by_id', 'status', 'approved_by', 'approved_at', 'created_at', 'updated_at',
            ]);
            $duplicate->parent_id = $question->id;
            $duplicate->story_generation_id = null;
            $duplicate->author_id = $request->user()->id;
            $duplicate->revision_of_id = null;
            $duplicate->version = 1;
            $duplicate->superseded_by_id = null;
            $duplicate->status = QuestionStatus::Draft;
            $duplicate->title = trim(($question->title ?: 'Salinan soal').' - salinan');
            $duplicate->metadata = [
                ...($question->metadata ?? []),
                'duplicated_from' => $question->id,
            ];
            $duplicate->save();

            foreach ($question->options as $option) {
                $duplicate->options()->create($option->only(['label', 'content', 'is_correct', 'position']));
            }

            return $duplicate;
        });
        $auditLogger->log($request, 'question.duplicated', $duplicate, ['source_question_id' => $question->id]);

        return to_route('questions.edit', $duplicate)->with('success', 'Salinan soal dibuat sebagai draft.');
    }

    public function archive(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        $question->update(['status' => QuestionStatus::Archived]);
        $auditLogger->log($request, 'question.archived', $question);

        return to_route('questions.index')->with('success', 'Soal dipindahkan ke arsip.');
    }

    public function approve(Request $request, Question $question, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureSameSchool($request, $question);
        abort_if($question->superseded_by_id !== null, 409, 'Versi soal ini sudah digantikan.');

        DB::transaction(function () use ($question, $request): void {
            if ($question->revision_of_id) {
                $source = Question::query()->lockForUpdate()->findOrFail($question->revision_of_id);
                abort_if(
                    $source->superseded_by_id !== null && $source->superseded_by_id !== $question->id,
                    409,
                    'Sudah ada revisi lain yang diterbitkan untuk soal ini.',
                );
                $source->update([
                    'status' => QuestionStatus::Archived,
                    'superseded_by_id' => $question->id,
                ]);
            }

            $question->update([
                'status' => QuestionStatus::Published,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
        });
        $auditLogger->log($request, 'question.published', $question);

        return back()->with('success', 'Soal sudah diterbitkan dan siap masuk paket ujian.');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'competency_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'title' => ['nullable', 'string', 'max:255'],
            'stimulus' => ['nullable', 'string', 'max:20000'],
            'prompt' => ['required', 'string', 'max:10000'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'difficulty' => ['required', 'integer', 'between:1,3'],
            'grade_level' => ['required', 'integer', Rule::in([5, 8, 11])],
            'cognitive_level' => ['nullable', 'string', 'max:100'],
            'options' => ['array'],
            'options.*.content' => ['required_with:options', 'string', 'max:3000'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
            'accepted_answers' => ['array'],
            'accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'matching_pairs' => ['required_if:type,matching', 'array', 'between:2,8'],
            'matching_pairs.*.left_id' => ['nullable', 'uuid', 'distinct'],
            'matching_pairs.*.left' => ['required_if:type,matching', 'string', 'max:1000'],
            'matching_pairs.*.right_id' => ['nullable', 'uuid', 'distinct'],
            'matching_pairs.*.right' => ['required_if:type,matching', 'string', 'max:1000'],
            'matching_distractors' => ['array', 'max:4'],
            'matching_distractors.*.id' => ['nullable', 'uuid', 'distinct'],
            'matching_distractors.*.content' => ['required_with:matching_distractors', 'string', 'max:1000'],
            'matrix_columns' => ['required_if:type,category_matrix', 'array', 'between:2,4'],
            'matrix_columns.*.id' => ['nullable', 'uuid', 'distinct'],
            'matrix_columns.*.label' => ['required_if:type,category_matrix', 'string', 'max:255'],
            'matrix_rows' => ['required_if:type,category_matrix', 'array', 'between:2,10'],
            'matrix_rows.*.id' => ['nullable', 'uuid', 'distinct'],
            'matrix_rows.*.statement' => ['required_if:type,category_matrix', 'string', 'max:1000'],
            'matrix_rows.*.correct_column_index' => ['required_if:type,category_matrix', 'integer', 'between:0,3'],
        ]);

        $competency = Competency::query()
            ->whereKey($data['competency_id'])
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->firstOrFail();

        if ($competency->grade_level !== (int) $data['grade_level']) {
            throw ValidationException::withMessages([
                'grade_level' => 'Jenjang soal harus sama dengan jenjang kompetensi.',
            ]);
        }

        $type = QuestionType::from($data['type']);
        $data['accepted_answers'] = array_values(array_filter($data['accepted_answers'] ?? []));
        $correctCount = collect($data['options'] ?? [])->where('is_correct', true)->count();

        if ($type === QuestionType::SingleChoice && (count($data['options'] ?? []) < 2 || $correctCount !== 1)) {
            throw ValidationException::withMessages([
                'options' => 'Pilihan tunggal membutuhkan minimal dua opsi dan tepat satu jawaban benar.',
            ]);
        }

        if ($type === QuestionType::MultipleChoice && (count($data['options'] ?? []) < 2 || $correctCount < 1)) {
            throw ValidationException::withMessages([
                'options' => 'Pilihan kompleks membutuhkan minimal dua opsi dan satu jawaban benar.',
            ]);
        }

        if ($type === QuestionType::ShortAnswer && empty($data['accepted_answers'])) {
            throw ValidationException::withMessages([
                'accepted_answers' => 'Isian singkat membutuhkan minimal satu jawaban yang diterima.',
            ]);
        }

        if ($type === QuestionType::Matching) {
            $leftItems = collect($data['matching_pairs'])->pluck('left')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $rightItems = collect($data['matching_pairs'])->pluck('right')
                ->merge(collect($data['matching_distractors'] ?? [])->pluck('content'))
                ->map(fn (string $value): string => mb_strtolower(trim($value)));

            if ($leftItems->unique()->count() !== $leftItems->count() || $rightItems->unique()->count() !== $rightItems->count()) {
                throw ValidationException::withMessages([
                    'matching_pairs' => 'Isi pada setiap lajur harus unik agar pasangan tidak ambigu.',
                ]);
            }
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columnLabels = collect($data['matrix_columns'])->pluck('label')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $statements = collect($data['matrix_rows'])->pluck('statement')->map(fn (string $value): string => mb_strtolower(trim($value)));
            $invalidColumn = collect($data['matrix_rows'])->contains(
                fn (array $row): bool => $row['correct_column_index'] >= count($data['matrix_columns']),
            );

            if ($columnLabels->unique()->count() !== $columnLabels->count()
                || $statements->unique()->count() !== $statements->count()
                || $invalidColumn) {
                throw ValidationException::withMessages([
                    'matrix_rows' => 'Kategori dan pernyataan harus unik serta setiap kunci harus memilih kategori yang tersedia.',
                ]);
            }
        }

        if (! in_array($type, [QuestionType::SingleChoice, QuestionType::MultipleChoice], true)) {
            $data['options'] = [];
        }

        return $data;
    }

    private function attributes(array $data, array $existingMetadata = []): array
    {
        $type = QuestionType::from($data['type']);
        $metadata = $existingMetadata;

        if ($type === QuestionType::ShortAnswer) {
            $metadata['accepted_answers'] = $data['accepted_answers'];
        } else {
            unset($metadata['accepted_answers']);
        }

        if ($type === QuestionType::Matching) {
            $metadata['matching_pairs'] = collect($data['matching_pairs'])->map(fn (array $pair): array => [
                'left_id' => $pair['left_id'] ?? (string) Str::uuid(),
                'left' => trim($pair['left']),
                'right_id' => $pair['right_id'] ?? (string) Str::uuid(),
                'right' => trim($pair['right']),
            ])->all();
            $metadata['matching_distractors'] = collect($data['matching_distractors'] ?? [])->map(fn (array $distractor): array => [
                'id' => $distractor['id'] ?? (string) Str::uuid(),
                'content' => trim($distractor['content']),
            ])->all();
        } else {
            unset($metadata['matching_pairs'], $metadata['matching_distractors']);
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columns = collect($data['matrix_columns'])->map(fn (array $column): array => [
                'id' => $column['id'] ?? (string) Str::uuid(),
                'label' => trim($column['label']),
            ])->values();
            $metadata['matrix_columns'] = $columns->all();
            $metadata['matrix_rows'] = collect($data['matrix_rows'])->map(fn (array $row): array => [
                'id' => $row['id'] ?? (string) Str::uuid(),
                'statement' => trim($row['statement']),
                'correct_column_id' => $columns[$row['correct_column_index']]['id'],
            ])->all();
        } else {
            unset($metadata['matrix_columns'], $metadata['matrix_rows']);
        }

        return [
            'competency_id' => $data['competency_id'],
            'type' => $type,
            'title' => $data['title'] ?? null,
            'stimulus' => $data['stimulus'] ?? null,
            'prompt' => $data['prompt'],
            'explanation' => $data['explanation'] ?? null,
            'difficulty' => $data['difficulty'],
            'grade_level' => $data['grade_level'],
            'cognitive_level' => $data['cognitive_level'] ?? null,
            'metadata' => $metadata ?: null,
        ];
    }

    private function syncOptions(Question $question, array $options): void
    {
        $question->options()->delete();
        foreach ($options as $index => $option) {
            $question->options()->create([
                'label' => chr(65 + $index),
                'content' => $option['content'],
                'is_correct' => $option['is_correct'],
                'position' => $index + 1,
            ]);
        }
    }

    private function competencies(Request $request)
    {
        return Competency::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $request->user()->school_id))
            ->orderBy('grade_level')
            ->orderBy('domain')
            ->orderBy('name')
            ->get(['id', 'code', 'domain', 'name', 'grade_level']);
    }

    private function ensureSameSchool(Request $request, Question $question): void
    {
        abort_unless($question->school_id === $request->user()->school_id, 404);
    }
}
