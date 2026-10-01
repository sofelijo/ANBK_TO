<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Jobs\GenerateStoryQuestions;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Services\AI\AiManager;
use App\Services\AI\StoryIllustrationService;
use App\Services\AuditLogger;
use App\Services\IndonesianBundleConfiguration;
use App\Services\QuestionDuplicateDetector;
use App\Services\QuestionTypeConfiguration;
use App\Services\QuestionVerificationService;
use App\Services\TeacherAiQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiStoryQuestionController extends Controller
{
    public function create(Request $request, IndonesianBundleConfiguration $bundleConfiguration): Response|RedirectResponse
    {
        $subjects = Subject::query()
            ->whereHas('competencies')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'ai_question_format']);
        $selectedSubjectId = $subjects->contains('id', $request->integer('subject_id'))
            ? $request->integer('subject_id')
            : null;
        $selectedSubject = $subjects->firstWhere('id', $selectedSubjectId);

        if ($selectedSubject && $request->routeIs('story-questions.create') && $selectedSubject->ai_question_format === 'direct') {
            return to_route('ai-questions.create', ['subject_id' => $selectedSubject->id]);
        }

        return Inertia::render('Questions/StoryCreate', [
            'subjects' => $subjects,
            'selectedSubjectId' => $selectedSubjectId,
            'generationFormat' => $selectedSubject?->ai_question_format ?? 'direct',
            'creationMode' => $request->routeIs('json-questions.create') ? 'json' : 'ai',
            'competencies' => Competency::query()
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->orderBy('grade_level')
                ->orderByRaw('COALESCE(parent_id, id)')
                ->orderBy('parent_id')
                ->orderBy('code')
                ->get(['id', 'subject_id', 'parent_id', 'code', 'name', 'grade_level']),
            'questionBlueprints' => QuestionBlueprint::query()
                ->whereIn('subject_id', $subjects->pluck('id'))
                ->with('competencies:id')
                ->orderBy('name')
                ->get(['id', 'subject_id', 'code', 'name', 'description'])
                ->map(fn (QuestionBlueprint $blueprint): array => [
                    ...$blueprint->only(['id', 'subject_id', 'code', 'name', 'description']),
                    'competency_ids' => $blueprint->competencies->pluck('id'),
                    'competency_positions' => $blueprint->competencies->mapWithKeys(fn (Competency $competency): array => [
                        $competency->id => $competency->pivot->position,
                    ]),
                ]),
            'indonesianBundleDefaults' => $bundleConfiguration->forUser($request->user()),
            'recentGenerations' => AiGeneration::query()
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('type', AiGenerationType::StoryQuestions)
                ->latest()
                ->limit(10)
                ->get(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
        ]);
    }

    public function store(Request $request, AiManager $manager, AuditLogger $auditLogger, TeacherAiQuota $quota, IndonesianBundleConfiguration $bundleConfiguration): RedirectResponse
    {
        $requestedSubjectUsesIndonesianBundle = Subject::query()
            ->whereKey($request->integer('subject_id'))
            ->where('code', 'BIND')
            ->exists();

        if (! $requestedSubjectUsesIndonesianBundle) {
            $request->request->remove('bundle_slots');
        }

        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'root_competency_id' => ['nullable', 'integer'],
            'competency_id' => ['nullable', 'integer'],
            'question_blueprint_ids' => ['array'],
            'question_blueprint_ids.*' => ['integer', 'distinct'],
            'bundle_slots' => ['nullable', 'array', 'size:3'],
            'bundle_slots.*.question_blueprint_id' => ['required_with:bundle_slots', 'integer', 'distinct'],
            'bundle_slots.*.answer_format' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'bundle_slots.*.cognitive_level' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
            'theme' => ['nullable', 'string', 'max:5000'],
            'question_style' => ['nullable', Rule::in(['direct', 'reasoning'])],
            'answer_format' => ['nullable', Rule::in(['single_choice', 'true_false', 'multiple_choice', 'mixed'])],
            'difficulty' => ['nullable', 'integer', 'between:1,3'],
            'use_illustration' => ['nullable', 'boolean'],
            'illustration_mode' => ['nullable', Rule::in(['lite', 'pro'])],
            'paragraph_count' => ['nullable', 'integer', 'between:1,5'],
            'max_words' => ['nullable', 'integer', 'between:50,1000'],
            'question_count' => ['required', 'integer', 'between:1,9'],
        ]);
        $subject = Subject::query()
            ->whereKey($data['subject_id'])
            ->whereHas('competencies')
            ->first();

        if (! $subject) {
            throw ValidationException::withMessages([
                'subject_id' => 'Mata pelajaran belum memiliki kompetensi yang dapat dipakai.',
            ]);
        }
        $selectedCompetency = $this->selectedCompetency($request, $subject, $data);
        $availableBundleBlueprints = $selectedCompetency?->questionBlueprints()
            ->where('subject_id', $subject->id)
            ->get(['question_blueprints.id', 'code', 'name', 'description']) ?? collect();
        $usesIndonesianBundle = $subject->code === 'BIND' && $availableBundleBlueprints->count() === 3;
        $bundleSlots = [];
        if ($usesIndonesianBundle) {
            $submittedSlots = $data['bundle_slots'] ?? collect($bundleConfiguration->forUser($request->user()))
                ->values()
                ->map(fn (array $slot, int $index): array => [
                    'question_blueprint_id' => $availableBundleBlueprints[$index]->id,
                    ...$slot,
                ])->all();
            $bundleConfiguration->ensureComplete(
                collect($submittedSlots)->map(fn (array $slot): array => collect($slot)->only(['answer_format', 'cognitive_level'])->all())->all(),
                'bundle_slots',
            );
            $submittedBlueprintIds = collect($submittedSlots)->pluck('question_blueprint_id')->map(fn ($id): int => (int) $id);
            if ($submittedBlueprintIds->unique()->count() !== 3
                || $submittedBlueprintIds->sort()->values()->all() !== $availableBundleBlueprints->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'bundle_slots' => 'Bundle wajib menggunakan ketiga tipe soal milik kompetensi masing-masing satu kali.',
                ]);
            }
            $availableById = $availableBundleBlueprints->keyBy('id');
            $bundleSlots = collect($submittedSlots)->values()->map(fn (array $slot, int $index): array => [
                'position' => $index + 1,
                'question_blueprint_id' => (int) $slot['question_blueprint_id'],
                'answer_format' => $slot['answer_format'],
                'cognitive_level' => $slot['cognitive_level'],
                'cognitive_level_label' => $bundleConfiguration->levelLabel($slot['cognitive_level']),
            ])->all();
            $questionBlueprints = collect($bundleSlots)->map(function (array $slot) use ($availableById): array {
                $blueprint = $availableById[$slot['question_blueprint_id']];

                return [...$blueprint->only(['id', 'code', 'name', 'description']), ...$slot];
            });
        } else {
            $questionBlueprints = QuestionBlueprint::query()
                ->whereIn('id', $data['question_blueprint_ids'] ?? [])
                ->where('subject_id', $subject->id)
                ->get(['id', 'code', 'name', 'description']);
            if ($questionBlueprints->count() !== count($data['question_blueprint_ids'] ?? [])) {
                throw ValidationException::withMessages([
                    'question_blueprint_ids' => 'Ada tipe soal yang tidak tersedia.',
                ]);
            }
        }
        $generationFormat = $subject->ai_question_format === 'story' ? 'story' : 'direct';
        if ($generationFormat === 'story' && ! $usesIndonesianBundle && ! in_array((int) $data['question_count'], [2, 3, 4], true)) {
            throw ValidationException::withMessages([
                'question_count' => 'Paket cerita hanya mendukung 2–4 soal.',
            ]);
        }
        $theme = trim($data['theme'] ?? '');
        $questionStyle = $generationFormat === 'direct' ? ($data['question_style'] ?? 'direct') : 'reasoning';
        $answerFormat = $generationFormat === 'direct' ? ($data['answer_format'] ?? 'single_choice') : 'mixed';
        if ($answerFormat === 'mixed' && (int) $data['question_count'] < 2) {
            throw ValidationException::withMessages([
                'answer_format' => 'Format campuran membutuhkan minimal 2 soal.',
            ]);
        }
        $useIllustration = $generationFormat === 'story' || (bool) ($data['use_illustration'] ?? false);
        $illustrationMode = $generationFormat === 'story' ? 'pro' : ($data['illustration_mode'] ?? 'lite');

        $quota->ensureAvailable($request->user(), AiGenerationType::StoryQuestions, 'theme');

        $provider = $manager->provider();
        $payload = [
            'subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'format' => $generationFormat,
            'root_competency_id' => $selectedCompetency?->parent_id ?: $selectedCompetency?->id,
            'competency_id' => $selectedCompetency?->id,
            'competency_code' => $selectedCompetency?->code,
            'competency_name' => $selectedCompetency?->name,
            'question_blueprints' => $questionBlueprints->values()->all(),
            'bundle_slots' => $bundleSlots,
            'theme' => $generationFormat === 'story'
                ? $theme
                : ($theme !== '' ? $theme : ($selectedCompetency?->name ?? $subject->name)),
            'theme_source' => $generationFormat === 'story' && $theme === '' ? 'ai' : 'user',
            'example_question' => $generationFormat === 'direct' && $theme !== '' ? $theme : null,
            'question_style' => $questionStyle,
            'answer_format' => $answerFormat,
            'difficulty' => $usesIndonesianBundle ? null : (int) ($data['difficulty'] ?? 2),
            'use_illustration' => $useIllustration,
            'illustration_mode' => $useIllustration ? $illustrationMode : null,
            'paragraph_count' => $generationFormat === 'story' ? (int) ($data['paragraph_count'] ?? 3) : 0,
            'max_words' => $generationFormat === 'story' ? (int) ($data['max_words'] ?? 200) : 0,
            'question_count' => $usesIndonesianBundle ? 3 : (int) $data['question_count'],
            'submission_mode' => $request->input('submission_mode') === 'review'
                ? 'review'
                : ($request->input('submission_mode') === 'draft' ? 'draft' : ($request->header('X-Inertia') ? 'draft' : null)),
        ];
        $generation = AiGeneration::create([
            'school_id' => $request->user()->school_id,
            'requested_by' => $request->user()->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => AiGenerationStatus::Pending,
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'input_hash' => hash('sha256', json_encode($payload).$request->user()->school_id),
            'request_payload' => $payload,
        ]);

        $auditLogger->log($request, 'story_questions.requested', $generation, $payload);
        GenerateStoryQuestions::dispatch($generation->id);

        return to_route($generationFormat === 'story' ? 'story-questions.show' : 'ai-questions.show', $generation)
            ->with('success', $generationFormat === 'story'
                ? 'Tema diterima. AI sedang membuat cerita dan 2–4 soal draft.'
                : 'Topik diterima. AI sedang membuat soal draft tanpa mewajibkan cerita.');
    }

    public function storeJson(Request $request, AuditLogger $auditLogger, IndonesianBundleConfiguration $bundleConfiguration): RedirectResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'root_competency_id' => ['required', 'integer'],
            'competency_id' => ['required', 'integer'],
            'bundle_slots' => ['nullable', 'array'],
            'bundle_slots.*.question_blueprint_id' => ['required_with:bundle_slots', 'integer', 'distinct'],
            'bundle_slots.*.answer_format' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'bundle_slots.*.cognitive_level' => ['required_with:bundle_slots', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
            'theme' => ['nullable', 'string', 'max:5000'],
            'answer_format' => ['nullable', Rule::in(['single_choice', 'true_false', 'multiple_choice', 'mixed'])],
            'difficulty' => ['nullable', 'integer', 'between:1,3'],
            'paragraph_count' => ['nullable', 'integer', 'between:1,5'],
            'max_words' => ['nullable', 'integer', 'between:50,1000'],
            'question_count' => ['required', 'integer', 'between:1,9'],
            'json_payload' => ['required', 'string', 'max:250000'],
        ]);

        $subject = Subject::query()
            ->whereKey($data['subject_id'])
            ->whereHas('competencies')
            ->first();

        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => 'Mata pelajaran tidak tersedia.']);
        }

        $competency = $this->selectedCompetency($request, $subject, $data);
        $format = $subject->ai_question_format === 'story' ? 'story' : 'direct';
        $rawJson = trim($data['json_payload']);
        $rawJson = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $rawJson) ?? $rawJson;

        try {
            $payload = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ValidationException::withMessages([
                'json_payload' => 'JSON tidak valid: '.$exception->getMessage(),
            ]);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['json_payload' => 'Hasil yang ditempel harus berupa objek JSON.']);
        }

        $usesIndonesianBundle = $format === 'story' && $subject->code === 'BIND';
        $expectedQuestionCount = $usesIndonesianBundle ? 3 : (int) $data['question_count'];
        $paragraphCount = $format === 'story' ? (int) ($data['paragraph_count'] ?? 3) : 0;
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'story_paragraphs' => ['present', 'array', $format === 'story' ? "size:{$paragraphCount}" : 'size:0'],
            'story_paragraphs.*' => ['string', 'max:5000'],
            'questions' => ['required', 'array', "size:{$expectedQuestionCount}"],
            'questions.*.competency_code' => ['required', 'string'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.title' => ['nullable', 'string', 'max:255'],
            'questions.*.stimulus' => ['nullable', 'string', 'max:10000'],
            'questions.*.prompt' => ['required', 'string', 'max:10000'],
            'questions.*.explanation' => ['required', 'string', 'max:10000'],
            'questions.*.difficulty' => ['required', 'integer', 'between:1,3'],
            'questions.*.cognitive_level' => ['nullable', 'string', 'max:100'],
            'questions.*.options' => ['present', 'array', 'max:6'],
            'questions.*.options.*.content' => ['required', 'string', 'max:3000'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
            'questions.*.accepted_answers' => ['present', 'array'],
            'questions.*.accepted_answers.*' => ['string', 'max:500'],
            'questions.*.matching_pairs' => ['present', 'array', 'max:8'],
            'questions.*.matching_pairs.*.left' => ['required', 'string', 'max:1000'],
            'questions.*.matching_pairs.*.right' => ['required', 'string', 'max:1000'],
            'questions.*.matching_distractors' => ['present', 'array', 'max:4'],
            'questions.*.matching_distractors.*' => ['string', 'max:1000'],
            'questions.*.matrix_columns' => ['present', 'array', 'max:4'],
            'questions.*.matrix_columns.*' => ['string', 'max:255'],
            'questions.*.matrix_rows' => ['present', 'array', 'max:10'],
            'questions.*.matrix_rows.*.statement' => ['required', 'string', 'max:1000'],
            'questions.*.matrix_rows.*.correct_column_index' => ['required', 'integer', 'min:0'],
        ];
        $jsonValidator = validator($payload, $rules);
        if ($jsonValidator->fails()) {
            throw ValidationException::withMessages([
                'json_payload' => 'Struktur JSON belum sesuai: '.$jsonValidator->errors()->first(),
            ]);
        }
        $validatedJson = $jsonValidator->validated();

        $bundleSlots = collect();
        if ($usesIndonesianBundle) {
            $submittedSlots = $bundleConfiguration->ensureComplete($data['bundle_slots'] ?? [], 'bundle_slots');
            $blueprints = QuestionBlueprint::query()
                ->whereIn('id', collect($submittedSlots)->pluck('question_blueprint_id'))
                ->where('subject_id', $subject->id)
                ->whereHas('competencies', fn ($query) => $query->whereKey($competency->id))
                ->get()
                ->keyBy('id');

            if ($blueprints->count() !== 3) {
                throw ValidationException::withMessages(['bundle_slots' => 'Tiga tipe soal pada bundle tidak valid.']);
            }

            $bundleSlots = collect($submittedSlots)->values()->map(fn (array $slot): array => [
                ...$slot,
                'question_blueprint_id' => (int) $slot['question_blueprint_id'],
            ]);
        }

        foreach ($validatedJson['questions'] as $index => $questionData) {
            if ($questionData['competency_code'] !== $competency->code) {
                throw ValidationException::withMessages([
                    'json_payload' => 'Kode kompetensi pada soal '.($index + 1).' tidak sesuai dengan pilihan form.',
                ]);
            }

            $type = QuestionType::from($questionData['type']);
            $correctOptions = collect($questionData['options'])->where('is_correct', true)->count();
            $valid = match ($type) {
                QuestionType::SingleChoice => count($questionData['options']) >= 2 && $correctOptions === 1,
                QuestionType::MultipleChoice => count($questionData['options']) >= 2 && $correctOptions >= 2,
                QuestionType::ShortAnswer => count($questionData['accepted_answers']) >= 1,
                QuestionType::Matching => count($questionData['matching_pairs']) >= 2,
                QuestionType::CategoryMatrix => count($questionData['matrix_columns']) >= 2
                    && count($questionData['matrix_rows']) >= 2
                    && collect($questionData['matrix_rows'])->every(fn (array $row): bool => $row['correct_column_index'] < count($questionData['matrix_columns'])),
            };

            if (! $valid) {
                throw ValidationException::withMessages([
                    'json_payload' => 'Struktur jawaban soal '.($index + 1).' tidak sesuai dengan tipe soalnya.',
                ]);
            }

            if ($usesIndonesianBundle) {
                $expectedType = match ($bundleSlots[$index]['answer_format']) {
                    'single_choice' => QuestionType::SingleChoice,
                    'multiple_choice' => QuestionType::MultipleChoice,
                    'true_false' => QuestionType::CategoryMatrix,
                };
                if ($type !== $expectedType) {
                    throw ValidationException::withMessages([
                        'json_payload' => 'Tipe soal '.($index + 1).' tidak sesuai dengan komposisi bundle.',
                    ]);
                }
            } else {
                $expectedFormat = $data['answer_format'] ?? 'single_choice';
                $typeMatchesFormat = match ($expectedFormat) {
                    'single_choice' => $type === QuestionType::SingleChoice,
                    'multiple_choice' => $type === QuestionType::MultipleChoice,
                    'true_false' => $type === QuestionType::CategoryMatrix,
                    'mixed' => in_array($type, [QuestionType::SingleChoice, QuestionType::MultipleChoice, QuestionType::CategoryMatrix], true),
                };
                if (! $typeMatchesFormat || (int) $questionData['difficulty'] !== (int) ($data['difficulty'] ?? 2)) {
                    throw ValidationException::withMessages([
                        'json_payload' => 'Format jawaban atau level soal '.($index + 1).' tidak sesuai dengan pengaturan form.',
                    ]);
                }
            }
        }

        if (! $usesIndonesianBundle && ($data['answer_format'] ?? null) === 'mixed'
            && collect($validatedJson['questions'])->pluck('type')->unique()->count() < 2) {
            throw ValidationException::withMessages([
                'json_payload' => 'Format campuran harus menggunakan minimal dua tipe soal yang berbeda.',
            ]);
        }

        $story = $format === 'story' ? implode("\n\n", $validatedJson['story_paragraphs']) : null;
        $generation = DB::transaction(function () use ($request, $subject, $competency, $data, $validatedJson, $rawJson, $format, $story, $bundleSlots, $expectedQuestionCount): AiGeneration {
            $generation = AiGeneration::create([
                'school_id' => $request->user()->school_id,
                'requested_by' => $request->user()->id,
                'type' => AiGenerationType::StoryQuestions,
                'status' => AiGenerationStatus::Completed,
                'provider' => 'external-json',
                'model' => 'chatgpt',
                'input_hash' => hash('sha256', $rawJson.$request->user()->school_id),
                'request_payload' => [
                    'source' => 'json',
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'format' => $format,
                    'root_competency_id' => $competency->parent_id ?: $competency->id,
                    'competency_id' => $competency->id,
                    'competency_code' => $competency->code,
                    'competency_name' => $competency->name,
                    'theme' => trim((string) ($data['theme'] ?? '')),
                    'question_count' => $expectedQuestionCount,
                    'submission_mode' => 'draft',
                    'bundle_slots' => $bundleSlots->all(),
                ],
            ]);
            $questionIds = [];

            foreach ($validatedJson['questions'] as $index => $questionData) {
                $type = QuestionType::from($questionData['type']);
                $slot = $bundleSlots->get($index, []);
                $metadata = [
                    'generated_by_ai' => false,
                    'imported_from_external_json' => true,
                    'story_generation_id' => $generation->id,
                    'generation_format' => $format,
                    'story_theme' => trim((string) ($data['theme'] ?? '')),
                    'verification_locked' => true,
                    'bundle_answer_format' => $slot['answer_format'] ?? null,
                    'bundle_cognitive_level' => $slot['cognitive_level'] ?? null,
                ];

                if ($type === QuestionType::ShortAnswer) {
                    $metadata['accepted_answers'] = array_values($questionData['accepted_answers']);
                }
                if ($type === QuestionType::Matching) {
                    $metadata['matching_pairs'] = collect($questionData['matching_pairs'])->map(fn (array $pair): array => [
                        'left_id' => (string) Str::uuid(), 'left' => trim($pair['left']),
                        'right_id' => (string) Str::uuid(), 'right' => trim($pair['right']),
                    ])->all();
                    $metadata['matching_distractors'] = collect($questionData['matching_distractors'])->map(fn (string $content): array => [
                        'id' => (string) Str::uuid(), 'content' => trim($content),
                    ])->all();
                }
                if ($type === QuestionType::CategoryMatrix) {
                    $columns = collect($questionData['matrix_columns'])->map(fn (string $label): array => [
                        'id' => (string) Str::uuid(), 'label' => trim($label),
                    ])->values();
                    $metadata['matrix_columns'] = $columns->all();
                    $metadata['matrix_rows'] = collect($questionData['matrix_rows'])->map(fn (array $row): array => [
                        'id' => (string) Str::uuid(),
                        'statement' => trim($row['statement']),
                        'correct_column_id' => $columns[$row['correct_column_index']]['id'],
                    ])->all();
                }

                $difficulty = match ($slot['cognitive_level'] ?? null) {
                    'textual' => 1, 'inferential' => 2, 'evaluation' => 3,
                    default => (int) $questionData['difficulty'],
                };
                $question = Question::create([
                    'author_id' => $request->user()->id,
                    'story_generation_id' => $generation->id,
                    'competency_id' => $competency->id,
                    'question_blueprint_id' => $slot['question_blueprint_id'] ?? null,
                    'type' => $type,
                    'status' => QuestionStatus::Draft,
                    'title' => ($questionData['title'] ?? null) ?: $validatedJson['title'].' - Soal '.($index + 1),
                    'stimulus' => $format === 'story' ? $story : ($questionData['stimulus'] ?? null),
                    'prompt' => $questionData['prompt'],
                    'explanation' => $questionData['explanation'],
                    'difficulty' => $difficulty,
                    'grade_level' => $competency->grade_level,
                    'cognitive_level' => $questionData['cognitive_level'] ?? null,
                    'metadata' => $metadata,
                ]);
                foreach ($questionData['options'] as $optionIndex => $option) {
                    $question->options()->create([
                        'label' => chr(65 + $optionIndex),
                        'content' => $option['content'],
                        'is_correct' => (bool) $option['is_correct'],
                        'position' => $optionIndex + 1,
                    ]);
                }
                $questionIds[] = $question->id;
            }

            $generation->update(['result_payload' => [
                'title' => $validatedJson['title'],
                'story' => $story,
                'story_paragraphs' => $validatedJson['story_paragraphs'],
                'visual_description' => $validatedJson['visual_description'] ?? null,
                'visual_spec' => $validatedJson['visual_spec'] ?? null,
                'question_ids' => $questionIds,
                'question_count' => count($questionIds),
            ]]);

            return $generation;
        });

        $auditLogger->log($request, 'questions.imported_from_json', $generation, [
            'question_count' => $expectedQuestionCount,
        ]);

        return to_route($format === 'story' ? 'story-questions.show' : 'ai-questions.show', $generation)
            ->with('success', $expectedQuestionCount.' soal dari JSON berhasil diimpor sebagai draft.');
    }

    private function selectedCompetency(Request $request, Subject $subject, array $data): ?Competency
    {
        $rootId = (int) ($data['root_competency_id'] ?? 0);
        if ($rootId === 0) {
            return null;
        }

        $root = Competency::query()
            ->whereKey($rootId)
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->first();

        if (! $root) {
            throw ValidationException::withMessages([
                'root_competency_id' => 'Kompetensi tidak tersedia untuk mata pelajaran yang dipilih.',
            ]);
        }

        if ($subject->code === 'BIND') {
            return $root;
        }

        $selectedId = (int) ($data['competency_id'] ?? $root->id);
        $selected = Competency::query()
            ->whereKey($selectedId)
            ->where('subject_id', $subject->id)
            ->where(fn ($query) => $query
                ->whereKey($root->id)
                ->orWhere('parent_id', $root->id))
            ->first();

        if (! $selected) {
            throw ValidationException::withMessages([
                'competency_id' => 'Subkompetensi tidak berada di bawah kompetensi yang dipilih.',
            ]);
        }

        if ($selected->is($root) && $root->children()->exists()) {
            throw ValidationException::withMessages([
                'competency_id' => 'Pilih subkompetensi yang akan digunakan oleh AI.',
            ]);
        }

        return $selected;
    }

    public function show(
        Request $request,
        AiGeneration $generation,
        StoryIllustrationService $illustrationService,
        QuestionTypeConfiguration $questionTypeConfiguration,
    ): Response {
        $this->ensureAccessible($request, $generation);
        $this->markStaleGenerationAsFailed($generation);

        $illustration = $generation->storyIllustration;
        if ($illustration?->status === AiGenerationStatus::Processing
            && $illustration->updated_at->lt(now()->subMinutes(5))) {
            $illustration->update([
                'status' => AiGenerationStatus::Failed,
                'error' => 'Pengiriman batch ilustrasi terhenti. Silakan coba lagi.',
            ]);
        }

        if ($illustration?->status === AiGenerationStatus::Processing
            && $illustrationService->shouldRefresh($illustration)) {
            $illustrationService->refresh($illustration);
            $illustration->refresh();
        }

        $questionIds = data_get($generation->result_payload, 'question_ids', []);
        $questions = Question::query()
            ->whereIn('id', $questionIds)
            ->with([
                'competency:id,code,name,grade_level',
                'questionBlueprint:id,code,name',
                'author:id,name',
                'approver:id,name',
                'options',
                'verifications.verifier:id,name',
            ])
            ->get()
            ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
            ->values();
        $ownsBundle = $generation->requested_by === $request->user()->id;

        $hasDraftQuestions = $questions->contains(fn (Question $q): bool => $q->status === QuestionStatus::Draft);
        $hasReviewQuestions = $questions->contains(fn (Question $q): bool => $q->status === QuestionStatus::Review);
        $allPublished = $questions->isNotEmpty() && $questions->every(fn (Question $q): bool => $q->status === QuestionStatus::Published);

        $isExplicitDraftMode = data_get($generation->request_payload, 'submission_mode') === 'draft';
        $isExplicitReviewMode = data_get($generation->request_payload, 'submission_mode') === 'review';
        $isDraft = ! $allPublished && (
            $isExplicitDraftMode
            || (! $isExplicitReviewMode && ($hasDraftQuestions || $questions->contains(fn (Question $question): bool => (bool) data_get($question->metadata, 'verification_locked', false))))
        );

        $verificationLocked = $isDraft;

        $canSubmitForReview = $ownsBundle
            && ! $allPublished
            && $isDraft
            && $questions->isNotEmpty()
            && data_get($generation->request_payload, 'draft_complete', true) !== false;

        $canRevertToDraft = $ownsBundle
            && ! $allPublished
            && ! $isDraft;

        $subject = Subject::find(data_get($generation->request_payload, 'subject_id'));
        $availableCompetencies = $subject ? Competency::query()
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->orderBy('grade_level')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'grade_level']) : [];

        $availableBlueprints = $subject ? QuestionBlueprint::query()
            ->where('subject_id', $subject->id)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']) : [];

        $canDeleteBundle = $ownsBundle
            && ! $questions->contains(fn (Question $q): bool => $q->status === QuestionStatus::Published || $q->assessments()->exists());

        $school = $request->user()->school;
        $questionTypes = $request->user()->hasRole(UserRole::Admin)
            ? $questionTypeConfiguration->allOptions()
            : ($school
            ? $questionTypeConfiguration->options($school)
            : collect(QuestionType::cases())->map(fn (QuestionType $type): array => [
                'value' => $type->value,
                'label' => $questionTypeConfiguration->label($type),
                'description' => $questionTypeConfiguration->description($type),
                'active' => true,
            ])->all());

        return Inertia::render('Questions/StoryShow', [
            'generation' => [
                ...$generation->only(['id', 'status', 'request_payload', 'result_payload', 'created_at']),
                'error' => $generation->status === AiGenerationStatus::Failed
                    ? 'Soal belum berhasil dibuat. Silakan coba proses kembali.'
                    : null,
            ],
            'questions' => $questions->map(fn (Question $question): array => [
                ...$question->toArray(),
                'verification' => $this->verificationSummary($question, $request),
            ]),
            'canVerify' => $request->user()->hasRole(UserRole::Teacher) && ! $verificationLocked,
            'verificationLocked' => $verificationLocked,
            'canSubmitForReview' => $canSubmitForReview,
            'canRevertToDraft' => $canRevertToDraft,
            'isAuthor' => $ownsBundle,
            'canDeleteBundle' => $canDeleteBundle,
            'availableCompetencies' => $availableCompetencies,
            'availableBlueprints' => $availableBlueprints,
            'questionTypes' => $questionTypes,
            'illustration' => $illustration ? [
                ...$illustration->only(['id', 'status', 'created_at']),
                'error' => $illustration->status === AiGenerationStatus::Failed
                    ? 'Ilustrasi belum berhasil dibuat. Silakan coba kembali.'
                    : null,
            ] : null,
        ]);
    }

    public function retry(Request $request, AiGeneration $generation): RedirectResponse
    {
        $this->ensureAccessible($request, $generation);
        $this->markStaleGenerationAsFailed($generation);

        if ($generation->status !== AiGenerationStatus::Failed) {
            throw ValidationException::withMessages([
                'generation' => 'Permintaan ini masih diproses atau sudah selesai.',
            ]);
        }

        if (data_get($generation->result_payload, 'question_ids', []) !== []) {
            throw ValidationException::withMessages([
                'generation' => 'Draft soal sudah terbentuk dan tidak boleh dibuat ulang.',
            ]);
        }

        $generation->update([
            'status' => AiGenerationStatus::Pending,
            'result_payload' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_microusd' => 0,
            'error' => null,
        ]);

        GenerateStoryQuestions::dispatch($generation->id);

        return back()->with('success', 'Pembuatan soal cerita dijalankan kembali.');
    }

    public function submitForReview(
        Request $request,
        AiGeneration $generation,
        AuditLogger $auditLogger,
        QuestionVerificationService $verificationService,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);
        abort_unless(
            $generation->requested_by === $request->user()->id,
            403,
        );
        if (data_get($generation->request_payload, 'draft_complete', true) === false) {
            throw ValidationException::withMessages([
                'generation' => 'Lengkapi isi draft melalui formulir sebelum mengajukan verifikasi.',
            ]);
        }

        $questions = Question::query()
            ->where('story_generation_id', $generation->id)
            ->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle belum memiliki soal untuk diajukan.',
            ]);
        }

        $verificationResults = DB::transaction(function () use ($generation, $questions, $request, $verificationService): array {
            $results = [];

            foreach ($questions as $question) {
                if ($question->status !== QuestionStatus::Published) {
                    $metadata = $question->metadata ?? [];
                    $metadata['verification_locked'] = false;
                    $question->update([
                        'metadata' => $metadata,
                        'status' => QuestionStatus::Review,
                    ]);
                }

                if ($question->author_id === $request->user()->id) {
                    $results[$question->id] = $verificationService->verify($question, $request->user(), allowAuthor: true);
                }
            }

            $payload = $generation->request_payload ?? [];
            $payload['submission_mode'] = 'review';
            $generation->update(['request_payload' => $payload]);

            return $results;
        });

        $action = data_get($generation->request_payload, 'source') === 'manual'
            ? 'manual_story_bundle.submitted_for_review'
            : 'story_bundle.submitted_for_review';

        $auditLogger->log($request, $action, $generation, [
            'question_ids' => $questions->pluck('id')->all(),
        ]);

        foreach ($verificationResults as $questionId => $result) {
            if ($result['created']) {
                $auditLogger->log($request, 'question.verified', Question::findOrFail($questionId), [
                    'verification_count' => $result['count'],
                    'published' => $result['published'],
                    'source' => 'submission',
                ]);
            }
        }

        if ($verificationResults !== []) {
            $result = reset($verificationResults);
            $total = $result['count'] + $result['remaining'];

            return back()->with(
                'success',
                "Bundle diajukan dan verifikasi Anda tercatat ({$result['count']}/{$total}). Masih diperlukan {$result['remaining']} guru lagi.",
            );
        }

        return back()->with('success', 'Bundle diajukan ke status menunggu verifikasi guru lain.');
    }

    public function revertToDraft(Request $request, AiGeneration $generation, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureAccessible($request, $generation);
        abort_unless(
            $generation->requested_by === $request->user()->id,
            403,
        );

        $questions = Question::query()
            ->where('story_generation_id', $generation->id)
            ->get();

        if ($questions->isNotEmpty() && $questions->every(fn (Question $q): bool => $q->status === QuestionStatus::Published)) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle yang seluruh soalnya sudah terbit tidak dapat dikembalikan ke draft.',
            ]);
        }

        DB::transaction(function () use ($generation, $questions): void {
            foreach ($questions as $question) {
                if ($question->status !== QuestionStatus::Published) {
                    $metadata = $question->metadata ?? [];
                    $metadata['verification_locked'] = true;
                    $question->update([
                        'metadata' => $metadata,
                        'status' => QuestionStatus::Draft,
                    ]);
                    $question->verifications()->delete();
                }
            }

            $payload = $generation->request_payload ?? [];
            $payload['submission_mode'] = 'draft';
            $generation->update(['request_payload' => $payload]);
        });

        $auditLogger->log($request, 'story_bundle.reverted_to_draft', $generation, [
            'question_ids' => $questions->pluck('id')->all(),
        ]);

        return back()->with('success', 'Bundle dikembalikan ke status draft pribadi.');
    }

    public function updateStimulus(
        Request $request,
        AiGeneration $generation,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        abort_unless(
            $generation->requested_by === $request->user()->id,
            403,
            'Hanya pembuat bundle yang dapat mengubah stimulus.',
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'story' => ['required', 'string', 'max:20000'],
        ]);

        $payload = $generation->result_payload ?? [];
        $payload['title'] = $validated['title'];
        $payload['story'] = $validated['story'];

        if (isset($payload['story_paragraphs'])) {
            $payload['story_paragraphs'] = array_values(array_filter(
                preg_split("/\r\n|\n|\r/", $validated['story']),
                fn ($line) => trim($line) !== ''
            ));
        }

        DB::transaction(function () use ($generation, $payload, $validated): void {
            $generation->update(['result_payload' => $payload]);

            $questionIds = data_get($payload, 'question_ids', []);
            if (! empty($questionIds)) {
                Question::query()
                    ->whereIn('id', $questionIds)
                    ->update([
                        'stimulus' => $validated['story'],
                    ]);
            }
        });

        $auditLogger->log($request, 'story_generation.stimulus_updated', $generation, [
            'title' => $validated['title'],
        ]);

        return back()->with('success', 'Stimulus berhasil diperbarui untuk seluruh soal dalam bundel.');
    }

    public function destroy(Request $request, AiGeneration $generation, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureAccessible($request, $generation);
        abort_unless(
            $generation->requested_by === $request->user()->id,
            403,
            'Hanya pembuat bundle atau admin yang dapat menghapus bundle.',
        );

        $questions = Question::query()
            ->where('story_generation_id', $generation->id)
            ->get();

        if ($questions->contains(fn (Question $q): bool => $q->status === QuestionStatus::Published)) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle tidak dapat dihapus karena sudah memiliki soal yang terbit.',
            ]);
        }

        if ($questions->contains(fn (Question $q): bool => $q->assessments()->exists())) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle tidak dapat dihapus karena soal sudah digunakan dalam paket ujian.',
            ]);
        }

        DB::transaction(function () use ($generation, $questions): void {
            foreach ($questions as $question) {
                $question->options()->delete();
                $question->verifications()->delete();
                $question->delete();
            }
            $generation->delete();
        });

        $auditLogger->log($request, 'story_bundle.deleted', $generation, [
            'question_count' => $questions->count(),
        ]);

        return to_route('questions.index')->with('success', 'Paket bundle soal cerita beserta seluruh butir soalnya berhasil dihapus.');
    }

    public function storeQuestion(
        Request $request,
        AiGeneration $generation,
        AuditLogger $auditLogger,
        QuestionTypeConfiguration $questionTypeConfiguration,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        $school = $request->user()->school;
        $allowedQuestionTypes = $request->user()->hasRole(UserRole::Admin)
            ? array_column(QuestionType::cases(), 'value')
            : ($school
            ? $questionTypeConfiguration->enabledValues($school)
            : array_column(QuestionType::cases(), 'value'));

        if (! in_array($request->input('type'), $allowedQuestionTypes, true)) {
            throw ValidationException::withMessages([
                'type' => 'Bentuk soal ini sedang dinonaktifkan oleh administrator sekolah.',
            ]);
        }

        $validated = $request->validate([
            'competency_id' => ['required', 'integer', Rule::exists('competencies', 'id')],
            'question_blueprint_id' => ['nullable', 'integer', Rule::exists('question_blueprints', 'id')],
            'type' => ['required', Rule::enum(QuestionType::class)],
            'prompt' => ['required', 'string', 'max:10000'],
            'explanation' => ['required', 'string', 'max:10000'],
            'difficulty' => ['required', 'integer', 'between:1,3'],
            'cognitive_level' => ['nullable', 'string', 'max:100'],
            'options' => ['array'],
            'options.*.content' => ['required', 'string', 'max:3000'],
            'options.*.is_correct' => ['required', 'boolean'],
            'accepted_answers' => ['array'],
            'accepted_answers.*' => ['string', 'max:500'],
            'matching_pairs' => ['array'],
            'matching_pairs.*.left' => ['required', 'string', 'max:1000'],
            'matching_pairs.*.right' => ['required', 'string', 'max:1000'],
            'matching_distractors' => ['array'],
            'matching_distractors.*' => ['string', 'max:1000'],
            'matrix_columns' => ['array'],
            'matrix_columns.*' => ['string', 'max:255'],
            'matrix_rows' => ['array'],
            'matrix_rows.*.statement' => ['required', 'string', 'max:1000'],
            'matrix_rows.*.correct_column_index' => ['required', 'integer'],
        ]);

        $competency = Competency::findOrFail($validated['competency_id']);
        $type = QuestionType::from($validated['type']);
        $story = $generation->result_payload['story'] ?? null;

        $metadata = [
            'generated_by_ai' => false,
            'story_generation_id' => $generation->id,
            'generation_format' => data_get($generation->request_payload, 'format', 'story'),
            'story_theme' => data_get($generation->request_payload, 'theme', ''),
        ];

        if ($type === QuestionType::ShortAnswer) {
            $metadata['accepted_answers'] = array_values($validated['accepted_answers'] ?? []);
        }

        if ($type === QuestionType::Matching) {
            $metadata['matching_pairs'] = collect($validated['matching_pairs'] ?? [])->map(fn (array $pair): array => [
                'left_id' => (string) Str::uuid(),
                'left' => trim($pair['left']),
                'right_id' => (string) Str::uuid(),
                'right' => trim($pair['right']),
            ])->all();
            $metadata['matching_distractors'] = collect($validated['matching_distractors'] ?? [])->map(fn (string $content): array => [
                'id' => (string) Str::uuid(),
                'content' => trim($content),
            ])->all();
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columns = collect($validated['matrix_columns'] ?? [])->map(fn (string $label): array => [
                'id' => (string) Str::uuid(),
                'label' => trim($label),
            ])->values();
            $metadata['matrix_columns'] = $columns->all();
            $metadata['matrix_rows'] = collect($validated['matrix_rows'] ?? [])->map(fn (array $row): array => [
                'id' => (string) Str::uuid(),
                'statement' => trim($row['statement']),
                'correct_column_id' => $columns[$row['correct_column_index']]['id'] ?? null,
            ])->all();
        }

        $submissionMode = data_get($generation->request_payload, 'submission_mode', 'draft');
        $initialStatus = $submissionMode === 'review' ? QuestionStatus::Review : QuestionStatus::Draft;
        $metadata['verification_locked'] = $submissionMode === 'draft';

        $question = DB::transaction(function () use ($generation, $validated, $competency, $type, $story, $metadata, $initialStatus): Question {
            $question = Question::create([
                'author_id' => auth()->id(),
                'story_generation_id' => $generation->id,
                'competency_id' => $competency->id,
                'question_blueprint_id' => $validated['question_blueprint_id'] ?? null,
                'type' => $type,
                'status' => $initialStatus,
                'title' => ($generation->result_payload['title'] ?? 'Soal Cerita').' - Soal Tambahan',
                'stimulus' => $story,
                'prompt' => $validated['prompt'],
                'explanation' => $validated['explanation'],
                'difficulty' => $validated['difficulty'],
                'grade_level' => $competency->grade_level,
                'cognitive_level' => $validated['cognitive_level'] ?? null,
                'metadata' => $metadata,
            ]);

            foreach ($validated['options'] ?? [] as $index => $option) {
                $question->options()->create([
                    'label' => chr(65 + $index),
                    'content' => $option['content'],
                    'is_correct' => (bool) $option['is_correct'],
                    'position' => $index + 1,
                ]);
            }

            $resultPayload = $generation->result_payload ?? [];
            $questionIds = collect(data_get($resultPayload, 'question_ids', []))
                ->push($question->id)
                ->unique()
                ->values()
                ->all();
            $resultPayload['question_ids'] = $questionIds;
            $resultPayload['question_count'] = count($questionIds);
            $generation->update(['result_payload' => $resultPayload]);

            return $question;
        });

        $auditLogger->log($request, 'story_bundle.question_added_manually', $question, [
            'story_generation_id' => $generation->id,
        ]);

        return back()->with('success', 'Soal manual baru berhasil ditambahkan ke dalam bundle.');
    }

    public function generateQuestion(
        Request $request,
        AiGeneration $generation,
        AiManager $manager,
        TeacherAiQuota $quota,
        AuditLogger $auditLogger,
        QuestionTypeConfiguration $questionTypeConfiguration,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        $quota->ensureAvailable($request->user(), AiGenerationType::StoryQuestions, 'ai');

        $validated = $request->validate([
            'competency_id' => ['nullable', 'integer', Rule::exists('competencies', 'id')],
            'question_blueprint_id' => ['nullable', 'integer', Rule::exists('question_blueprints', 'id')],
            'answer_format' => ['nullable', 'string', Rule::in(['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'matching', 'category_matrix'])],
            'difficulty' => ['nullable', 'integer', 'between:1,3'],
            'cognitive_level' => ['nullable', 'string', 'max:100'],
            'instruction' => ['nullable', 'string', 'max:500'],
        ]);

        $school = $request->user()->school;
        $allowedQuestionTypes = $request->user()->hasRole(UserRole::Admin)
            ? array_column(QuestionType::cases(), 'value')
            : ($school
            ? $questionTypeConfiguration->enabledValues($school)
            : array_column(QuestionType::cases(), 'value'));

        $formatToCheck = ($validated['answer_format'] ?? 'single_choice') === 'true_false'
            ? 'category_matrix'
            : ($validated['answer_format'] ?? 'single_choice');

        if (! in_array($formatToCheck, $allowedQuestionTypes, true)) {
            throw ValidationException::withMessages([
                'answer_format' => 'Bentuk soal ini sedang dinonaktifkan oleh administrator sekolah.',
            ]);
        }

        $competencyId = $validated['competency_id'] ?? data_get($generation->request_payload, 'competency_id');
        $competency = Competency::findOrFail($competencyId);
        $blueprint = isset($validated['question_blueprint_id'])
            ? QuestionBlueprint::find($validated['question_blueprint_id'])
            : null;

        $story = $generation->result_payload['story'] ?? '';
        $answerFormat = $validated['answer_format'] ?? 'single_choice';
        $difficulty = (int) ($validated['difficulty'] ?? 2);
        $cognitiveLevel = $validated['cognitive_level'] ?? 'Pemahaman Inferensial (Level 2)';
        $instruction = trim((string) ($validated['instruction'] ?? ''));

        $prompt = $this->buildSingleQuestionAiPrompt($story, $competency, $blueprint, $answerFormat, $difficulty, $cognitiveLevel, $instruction);

        $provider = $manager->provider();
        $response = $provider->generateJson($prompt, [
            'task' => 'story_questions',
            'question_count' => 1,
            'format' => 'story',
            'theme' => data_get($generation->request_payload, 'theme', ''),
            'paragraph_count' => 3,
            'competencies' => [[
                'id' => $competency->id,
                'code' => $competency->code,
                'name' => $competency->name,
                'grade_level' => $competency->grade_level,
            ]],
            'question_blueprints' => $blueprint ? [[
                'id' => $blueprint->id,
                'code' => $blueprint->code,
                'name' => $blueprint->name,
                'answer_format' => $answerFormat,
                'cognitive_level_label' => $cognitiveLevel,
            ]] : [],
        ]);

        $rawQuestions = data_get($response->data, 'questions', []);
        $questionData = $rawQuestions[0] ?? null;

        if (! is_array($questionData) || empty($questionData['prompt'])) {
            throw ValidationException::withMessages([
                'ai' => 'AI tidak berhasil menghasilkan butir soal baru. Silakan coba kembali.',
            ]);
        }

        $submissionMode = data_get($generation->request_payload, 'submission_mode', 'draft');
        $initialStatus = $submissionMode === 'review' ? QuestionStatus::Review : QuestionStatus::Draft;

        $question = $this->saveSingleQuestionFromAi($generation, $questionData, $competency, $blueprint, $story, $difficulty, $cognitiveLevel, $initialStatus);

        $auditLogger->log($request, 'story_bundle.question_added_via_ai', $question, [
            'story_generation_id' => $generation->id,
        ]);

        return back()->with('success', '1 butir soal baru berhasil dibuat oleh AI dan ditambahkan ke bundle.');
    }

    public function regenerateQuestion(
        Request $request,
        AiGeneration $generation,
        Question $question,
        AiManager $manager,
        TeacherAiQuota $quota,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        abort_unless(
            $question->story_generation_id === $generation->id,
            404,
            'Soal tidak ditemukan dalam bundle ini.',
        );

        abort_if(
            $question->status === QuestionStatus::Published,
            409,
            'Soal yang sudah terbit tidak dapat di-generate ulang.',
        );

        $quota->ensureAvailable($request->user(), AiGenerationType::StoryQuestions, 'ai');

        $validated = $request->validate([
            'instruction' => ['nullable', 'string', 'max:500'],
        ]);

        $competency = $question->competency;
        $blueprint = $question->questionBlueprint;
        $story = $generation->result_payload['story'] ?? $question->stimulus ?? '';
        $answerFormat = match ($question->type) {
            QuestionType::SingleChoice => 'single_choice',
            QuestionType::MultipleChoice => 'multiple_choice',
            QuestionType::CategoryMatrix => 'category_matrix',
            QuestionType::Matching => 'matching',
            QuestionType::ShortAnswer => 'short_answer',
            default => 'single_choice',
        };
        $difficulty = $question->difficulty;
        $cognitiveLevel = $question->cognitive_level ?? 'Pemahaman Inferensial (Level 2)';
        $instruction = trim((string) ($validated['instruction'] ?? ''));
        $extraDirection = "PENTING: Jangan buat soal yang persis sama dengan pertanyaan sebelumnya (\"{$question->prompt}\"). Buat pertanyaan baru dari sudut pandang atau aspek lain dalam cerita.";
        if ($instruction !== '') {
            $extraDirection .= " Petunjuk tambahan dari guru: {$instruction}";
        }

        $prompt = $this->buildSingleQuestionAiPrompt($story, $competency, $blueprint, $answerFormat, $difficulty, $cognitiveLevel, $extraDirection);

        $provider = $manager->provider();
        $response = $provider->generateJson($prompt, [
            'task' => 'story_questions',
            'question_count' => 1,
            'format' => 'story',
            'theme' => data_get($generation->request_payload, 'theme', ''),
            'paragraph_count' => 3,
            'competencies' => [[
                'id' => $competency->id,
                'code' => $competency->code,
                'name' => $competency->name,
                'grade_level' => $competency->grade_level,
            ]],
            'question_blueprints' => $blueprint ? [[
                'id' => $blueprint->id,
                'code' => $blueprint->code,
                'name' => $blueprint->name,
                'answer_format' => $answerFormat,
                'cognitive_level_label' => $cognitiveLevel,
            ]] : [],
        ]);

        $rawQuestions = data_get($response->data, 'questions', []);
        $questionData = $rawQuestions[0] ?? null;

        if (! is_array($questionData) || empty($questionData['prompt'])) {
            throw ValidationException::withMessages([
                'ai' => 'AI tidak berhasil men-generate ulang soal. Silakan coba kembali.',
            ]);
        }

        $this->updateQuestionFromAi($question, $questionData, $difficulty, $cognitiveLevel);

        $auditLogger->log($request, 'story_bundle.question_regenerated_via_ai', $question, [
            'story_generation_id' => $generation->id,
        ]);

        return back()->with('success', 'Soal berhasil di-generate ulang oleh AI mengacu pada stimulus bundle.');
    }

    private function buildSingleQuestionAiPrompt(
        string $story,
        Competency $competency,
        ?QuestionBlueprint $blueprint,
        string $answerFormat,
        int $difficulty,
        string $cognitiveLevel,
        string $extraInstruction = '',
    ): string {
        $blueprintText = $blueprint ? "Tipe/Blueprint soal: {$blueprint->name} ({$blueprint->code}). {$blueprint->description}" : '';
        $instructionText = $extraInstruction !== '' ? "Petunjuk tambahan: {$extraInstruction}" : '';

        return <<<PROMPT
Anda membantu guru membuat tepat 1 (SATU) butir soal Try Out Adaptif berbahasa Indonesia yang HANYA menggunakan cerita berikut sebagai stimulus:

STIMULUS CERITA:
"{$story}"

KOMPETENSI:
Kode: {$competency->code}
Nama: {$competency->name}
Jenjang Kelas: {$competency->grade_level}

{$blueprintText}
Format Jawaban: {$answerFormat}
Tingkat Kesulitan: {$difficulty} (1: mudah/tekstual, 2: sedang/inferensial, 3: sukar/evaluasi)
Level Kognitif: {$cognitiveLevel}
{$instructionText}

Aturan pembuatan soal:
1. Pertanyaan HARUS bersumber langsung dari stimulus cerita di atas. Jangan menanyakan hal di luar isi cerita.
2. Untuk single_choice berikan 4 pilihan (A, B, C, D) dengan tepat 1 jawaban benar.
3. Untuk multiple_choice berikan 4 pilihan dengan minimal 2 jawaban benar.
4. Untuk true_false atau category_matrix isi matrix_columns: ["Benar", "Salah"] dan matrix_rows dengan 2–4 pernyataan dan correct_column_index (0 atau 1).
5. Untuk matching isi matching_pairs dengan 2–4 pasangan left dan right.
6. Untuk short_answer kosongkan options dan isi accepted_answers dengan kunci jawaban singkat.
7. Berikan penjelasan/pembahasan yang jelas yang mengutip bagian cerita.

Kembalikan respon dalam JSON saja dengan format:
{
  "questions": [
    {
      "competency_code": "{$competency->code}",
      "type": "{$answerFormat}",
      "title": "Soal Cerita",
      "prompt": "Pertanyaan...",
      "explanation": "Pembahasan...",
      "difficulty": {$difficulty},
      "cognitive_level": "{$cognitiveLevel}",
      "options": [
        {"content": "Pilihan A", "is_correct": true},
        {"content": "Pilihan B", "is_correct": false},
        {"content": "Pilihan C", "is_correct": false},
        {"content": "Pilihan D", "is_correct": false}
      ],
      "accepted_answers": [],
      "matching_pairs": [],
      "matching_distractors": [],
      "matrix_columns": [],
      "matrix_rows": []
    }
  ]
}
PROMPT;
    }

    private function saveSingleQuestionFromAi(
        AiGeneration $generation,
        array $questionData,
        Competency $competency,
        ?QuestionBlueprint $blueprint,
        string $story,
        int $difficulty,
        string $cognitiveLevel,
        QuestionStatus $status,
    ): Question {
        $type = QuestionType::tryFrom($questionData['type'] ?? '') ?? QuestionType::SingleChoice;
        $metadata = [
            'generated_by_ai' => true,
            'story_generation_id' => $generation->id,
            'generation_format' => 'story',
            'story_theme' => data_get($generation->request_payload, 'theme', ''),
            'verification_locked' => $status === QuestionStatus::Draft,
        ];

        if ($type === QuestionType::ShortAnswer) {
            $metadata['accepted_answers'] = array_values($questionData['accepted_answers'] ?? []);
        }

        if ($type === QuestionType::Matching) {
            $metadata['matching_pairs'] = collect($questionData['matching_pairs'] ?? [])->map(fn (array $pair): array => [
                'left_id' => (string) Str::uuid(),
                'left' => trim($pair['left'] ?? ''),
                'right_id' => (string) Str::uuid(),
                'right' => trim($pair['right'] ?? ''),
            ])->all();
            $metadata['matching_distractors'] = collect($questionData['matching_distractors'] ?? [])->map(fn (string $c): array => [
                'id' => (string) Str::uuid(),
                'content' => trim($c),
            ])->all();
        }

        if ($type === QuestionType::CategoryMatrix) {
            $columns = collect($questionData['matrix_columns'] ?? ['Benar', 'Salah'])->map(fn (string $l): array => [
                'id' => (string) Str::uuid(),
                'label' => trim($l),
            ])->values();
            $metadata['matrix_columns'] = $columns->all();
            $metadata['matrix_rows'] = collect($questionData['matrix_rows'] ?? [])->map(fn (array $r): array => [
                'id' => (string) Str::uuid(),
                'statement' => trim($r['statement'] ?? ''),
                'correct_column_id' => $columns[$r['correct_column_index'] ?? 0]['id'] ?? $columns[0]['id'],
            ])->all();
        }

        return DB::transaction(function () use ($generation, $questionData, $competency, $blueprint, $type, $story, $difficulty, $cognitiveLevel, $status, $metadata): Question {
            $question = Question::create([
                'author_id' => auth()->id() ?? $generation->requested_by,
                'story_generation_id' => $generation->id,
                'competency_id' => $competency->id,
                'question_blueprint_id' => $blueprint?->id,
                'type' => $type,
                'status' => $status,
                'title' => ($questionData['title'] ?? null) ?: ($generation->result_payload['title'] ?? 'Soal Cerita').' - Soal Tambahan AI',
                'stimulus' => $story,
                'prompt' => $questionData['prompt'],
                'explanation' => $questionData['explanation'] ?? '',
                'difficulty' => $questionData['difficulty'] ?? $difficulty,
                'grade_level' => $competency->grade_level,
                'cognitive_level' => $questionData['cognitive_level'] ?? $cognitiveLevel,
                'metadata' => $metadata,
            ]);

            foreach ($questionData['options'] ?? [] as $i => $opt) {
                $question->options()->create([
                    'label' => chr(65 + $i),
                    'content' => $opt['content'],
                    'is_correct' => (bool) $opt['is_correct'],
                    'position' => $i + 1,
                ]);
            }

            $resultPayload = $generation->result_payload ?? [];
            $questionIds = collect(data_get($resultPayload, 'question_ids', []))
                ->push($question->id)
                ->unique()
                ->values()
                ->all();
            $resultPayload['question_ids'] = $questionIds;
            $resultPayload['question_count'] = count($questionIds);
            $generation->update(['result_payload' => $resultPayload]);

            return $question;
        });
    }

    private function updateQuestionFromAi(
        Question $question,
        array $questionData,
        int $difficulty,
        string $cognitiveLevel,
    ): void {
        DB::transaction(function () use ($question, $questionData, $difficulty, $cognitiveLevel): void {
            $metadata = $question->metadata ?? [];
            $type = $question->type;

            if ($type === QuestionType::ShortAnswer) {
                $metadata['accepted_answers'] = array_values($questionData['accepted_answers'] ?? []);
            }

            if ($type === QuestionType::Matching) {
                $metadata['matching_pairs'] = collect($questionData['matching_pairs'] ?? [])->map(fn (array $pair): array => [
                    'left_id' => (string) Str::uuid(),
                    'left' => trim($pair['left'] ?? ''),
                    'right_id' => (string) Str::uuid(),
                    'right' => trim($pair['right'] ?? ''),
                ])->all();
                $metadata['matching_distractors'] = collect($questionData['matching_distractors'] ?? [])->map(fn (string $c): array => [
                    'id' => (string) Str::uuid(),
                    'content' => trim($c),
                ])->all();
            }

            if ($type === QuestionType::CategoryMatrix) {
                $columns = collect($questionData['matrix_columns'] ?? ['Benar', 'Salah'])->map(fn (string $l): array => [
                    'id' => (string) Str::uuid(),
                    'label' => trim($l),
                ])->values();
                $metadata['matrix_columns'] = $columns->all();
                $metadata['matrix_rows'] = collect($questionData['matrix_rows'] ?? [])->map(fn (array $r): array => [
                    'id' => (string) Str::uuid(),
                    'statement' => trim($r['statement'] ?? ''),
                    'correct_column_id' => $columns[$r['correct_column_index'] ?? 0]['id'] ?? $columns[0]['id'],
                ])->all();
            }

            $question->update([
                'prompt' => $questionData['prompt'],
                'explanation' => $questionData['explanation'] ?? $question->explanation,
                'difficulty' => $questionData['difficulty'] ?? $difficulty,
                'cognitive_level' => $questionData['cognitive_level'] ?? $cognitiveLevel,
                'metadata' => $metadata,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            $question->verifications()->delete();

            if (! empty($questionData['options'])) {
                $question->options()->delete();
                foreach ($questionData['options'] as $i => $opt) {
                    $question->options()->create([
                        'label' => chr(65 + $i),
                        'content' => $opt['content'],
                        'is_correct' => (bool) $opt['is_correct'],
                        'position' => $i + 1,
                    ]);
                }
            }
        });
    }

    public function publishBundle(
        Request $request,
        AiGeneration $generation,
        AuditLogger $auditLogger,
        QuestionDuplicateDetector $duplicateDetector,
        QuestionVerificationService $verificationService,
    ): RedirectResponse {
        $this->ensureAccessible($request, $generation);

        if (data_get($generation->request_payload, 'format') === 'direct') {
            throw ValidationException::withMessages([
                'generation' => 'Soal langsung tidak menggunakan bundle. Verifikasi setiap soal secara terpisah.',
            ]);
        }

        if ($generation->status !== AiGenerationStatus::Completed) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle hanya dapat diverifikasi setelah paket selesai dibuat.',
            ]);
        }

        if (data_get($generation->request_payload, 'submission_mode') === 'draft') {
            throw ValidationException::withMessages([
                'generation' => 'Bundle masih berupa draft pribadi. Pembuat bundle harus mengajukannya untuk verifikasi terlebih dahulu.',
            ]);
        }

        $questionIds = collect(data_get($generation->result_payload, 'question_ids', []))
            ->filter(fn ($id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($questionIds->isEmpty()) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle ini belum memiliki soal yang dapat diverifikasi.',
            ]);
        }

        if (! $request->user()->hasRole(UserRole::Teacher)) {
            throw ValidationException::withMessages([
                'generation' => 'Hanya akun guru yang dapat memverifikasi bundle soal.',
            ]);
        }

        $questions = Question::query()
            ->where('story_generation_id', $generation->id)
            ->whereIn('id', $questionIds)
            ->with('verifications:id,question_id,verifier_id')
            ->get();

        if ($questions->count() !== $questionIds->count()) {
            throw ValidationException::withMessages([
                'generation' => 'Sebagian soal bundle tidak ditemukan. Muat ulang halaman dan periksa kembali.',
            ]);
        }

        if ($questions->contains(fn (Question $question): bool => $question->status === QuestionStatus::Archived || $question->superseded_by_id !== null
        )) {
            throw ValidationException::withMessages([
                'generation' => 'Bundle memuat soal yang sudah diarsipkan atau digantikan. Periksa soal satu per satu.',
            ]);
        }

        $verifiable = $questions->reject(fn (Question $question): bool => $question
            ->verifications
            ->contains('verifier_id', $request->user()->id));

        if ($verifiable->isEmpty()) {
            return back()->with('success', 'Anda sudah memverifikasi seluruh soal dalam bundle ini.');
        }

        foreach ($verifiable as $index => $question) {
            if ($question->status !== QuestionStatus::Published && $duplicateDetector->hasBlockingDuplicate($question)) {
                throw ValidationException::withMessages([
                    'generation' => 'Soal nomor '.($index + 1).' terindikasi duplikat kuat. Periksa soal tersebut sebelum memverifikasi bundle.',
                ]);
            }
        }

        $results = $verifiable->map(fn (Question $question): array => $verificationService
            ->verify($question, $request->user()));
        $verifiedCount = $results->where('created', true)->count();
        $publishedCount = $results->where('published', true)->count();

        if ($verifiedCount === 0) {
            return back()->with('success', 'Anda sudah memverifikasi seluruh soal dalam bundle ini.');
        }

        $auditLogger->log($request, 'story_bundle.verified', $generation, [
            'question_ids' => $verifiable->pluck('id')->all(),
            'question_count' => $verifiedCount,
            'published_count' => $publishedCount,
        ]);

        if ($publishedCount > 0) {
            $auditLogger->log($request, 'story_bundle.published', $generation, [
                'question_ids' => $verifiable->pluck('id')->all(),
                'question_count' => $publishedCount,
            ]);
        }

        return back()->with(
            'success',
            $publishedCount > 0
                ? "Verifikasi Anda tercatat pada {$verifiedCount} soal; {$publishedCount} soal mencapai tiga verifikasi dan diterbitkan."
                : "Verifikasi Anda tercatat pada {$verifiedCount} soal. Tiga guru adalah batas minimal; verifikator tambahan tetap tercatat.",
        );
    }

    private function verificationSummary(Question $question, Request $request): array
    {
        $count = $question->verifications->count();
        $required = QuestionVerificationService::requiredFor($question);

        return [
            'required' => $required,
            'count' => $count,
            'remaining' => max(0, $required - $count),
            'currentUserVerified' => $question->verifications->contains('verifier_id', $request->user()->id),
            'verifiers' => $question->verifications->map(fn ($verification): array => [
                'id' => $verification->verifier_id,
                'name' => $verification->verifier?->name ?? 'Guru tidak aktif',
                'verifiedAt' => $verification->verified_at,
            ])->values(),
        ];
    }

    private function ensureAccessible(Request $request, AiGeneration $generation): void
    {
        $isPrivateDraft = data_get($generation->request_payload, 'submission_mode') === 'draft';

        abort_unless(
            $generation->type === AiGenerationType::StoryQuestions
            && (! $isPrivateDraft || $generation->requested_by === $request->user()->id || $request->user()->hasRole(UserRole::Admin)),
            404,
        );
    }

    private function markStaleGenerationAsFailed(AiGeneration $generation): void
    {
        $stalePending = $generation->status === AiGenerationStatus::Pending
            && $generation->updated_at->lt(now()->subMinute());
        $staleProcessing = $generation->status === AiGenerationStatus::Processing
            && $generation->updated_at->lt(now()->subMinutes(5));

        if ($stalePending || $staleProcessing) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => 'Proses antrean terhenti. Silakan jalankan ulang permintaan ini.',
            ]);
        }
    }
}
