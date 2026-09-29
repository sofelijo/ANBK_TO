<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use App\Services\AuditLogger;
use App\Services\IndonesianBundleConfiguration;
use App\Services\StimulusImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ManualIndonesianBundleController extends Controller
{
    public function create(Request $request, IndonesianBundleConfiguration $configuration): Response|RedirectResponse
    {
        $subject = $this->subject($request, $request->integer('subject_id'));

        if (! $subject) {
            return to_route('questions.index')->with('error', 'Mata pelajaran Bahasa Indonesia tidak tersedia.');
        }

        $competencies = Competency::query()
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->with(['questionBlueprints' => fn ($query) => $query
                ->where('subject_id', $subject->id)
                ->select(['question_blueprints.id', 'subject_id', 'code', 'name', 'description'])])
            ->orderBy('grade_level')
            ->orderBy('code')
            ->get(['id', 'subject_id', 'code', 'name', 'grade_level'])
            ->map(fn (Competency $competency): array => [
                ...$competency->only(['id', 'subject_id', 'code', 'name', 'grade_level']),
                'question_blueprints' => $competency->questionBlueprints
                    ->sortBy(fn (QuestionBlueprint $blueprint) => $blueprint->pivot->position)
                    ->values()
                    ->map(fn (QuestionBlueprint $blueprint): array => $blueprint->only(['id', 'code', 'name', 'description']))
                    ->all(),
            ]);

        $draft = null;
        if ($request->integer('draft_id')) {
            $generation = AiGeneration::query()
                ->whereKey($request->integer('draft_id'))
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('type', AiGenerationType::StoryQuestions)
                ->where('request_payload->source', 'manual')
                ->where('request_payload->submission_mode', 'draft')
                ->firstOrFail();
            $questionIds = data_get($generation->result_payload, 'question_ids', []);
            $draftQuestions = Question::query()->whereIn('id', $questionIds)->with('options')->get()->keyBy('id');
            $draft = [
                'generation_id' => $generation->id,
                'competency_id' => (int) data_get($generation->request_payload, 'competency_id'),
                'title' => (string) data_get($generation->result_payload, 'title', ''),
                'story' => (string) data_get($generation->result_payload, 'story', ''),
                'max_words' => (int) data_get($generation->request_payload, 'max_words', 200),
                'stimulus_image_url' => $draftQuestions->first()?->illustration_url,
                'stimulus_image_alt' => (string) data_get($draftQuestions->first()?->metadata, 'illustration.alt', ''),
                'bundle_slots' => data_get($generation->request_payload, 'bundle_slots', []),
                'questions' => collect($questionIds)->map(function (int $questionId) use ($draftQuestions): array {
                    $question = $draftQuestions->get($questionId);
                    $columns = collect(data_get($question?->metadata, 'matrix_columns', []));

                    return [
                        'prompt' => $question?->prompt ?? '',
                        'explanation' => $question?->explanation ?? '',
                        'matrix_labels' => [
                            'true_label' => (string) data_get($columns->get(0), 'label', 'Benar'),
                            'false_label' => (string) data_get($columns->get(1), 'label', 'Salah'),
                        ],
                        'options' => $question?->options->map(fn ($option): array => [
                            'content' => $option->content,
                            'is_correct' => $option->is_correct,
                        ])->values()->all() ?? [],
                        'statements' => collect(data_get($question?->metadata, 'matrix_rows', []))->map(fn (array $row): array => [
                            'content' => $row['statement'],
                            'is_true' => $row['correct_column_id'] === data_get($columns->get(0), 'id'),
                        ])->all(),
                    ];
                })->all(),
            ];
        }

        return Inertia::render('Questions/ManualBundleCreate', [
            'subject' => $subject->only(['id', 'code', 'name']),
            'competencies' => $competencies,
            'bundleDefaults' => $configuration->forUser($request->user()),
            'draft' => $draft,
        ]);
    }

    public function store(
        Request $request,
        IndonesianBundleConfiguration $configuration,
        AuditLogger $auditLogger,
        StimulusImageService $imageService,
    ): RedirectResponse {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'competency_id' => ['required', 'integer'],
            'draft_generation_id' => ['nullable', 'integer'],
            'title' => ['required_if:submission_mode,review', 'nullable', 'string', 'max:255'],
            'story' => ['required_if:submission_mode,review', 'nullable', 'string', 'max:20000'],
            'stimulus_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:max_width=5000,max_height=5000'],
            'stimulus_image_alt' => ['nullable', 'string', 'max:255'],
            'max_words' => ['nullable', 'integer', 'between:50,1000'],
            'submission_mode' => ['nullable', Rule::in(['draft', 'review'])],
            'bundle_slots' => ['required', 'array', 'size:3'],
            'bundle_slots.*.question_blueprint_id' => ['required', 'integer', 'distinct'],
            'bundle_slots.*.answer_format' => ['required', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'bundle_slots.*.cognitive_level' => ['required', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
            'questions' => ['required', 'array', 'size:3'],
            'questions.*.prompt' => ['required_if:submission_mode,review', 'nullable', 'string', 'max:10000'],
            'questions.*.explanation' => ['nullable', 'string', 'max:10000'],
            'questions.*.matrix_labels' => ['nullable', 'array'],
            'questions.*.matrix_labels.true_label' => ['nullable', 'string', 'max:60'],
            'questions.*.matrix_labels.false_label' => ['nullable', 'string', 'max:60'],
            'questions.*.options' => ['array', 'max:10'],
            'questions.*.options.*.content' => ['nullable', 'string', 'max:3000'],
            'questions.*.options.*.is_correct' => ['boolean'],
            'questions.*.statements' => ['array'],
            'questions.*.statements.*.content' => ['nullable', 'string', 'max:1000'],
            'questions.*.statements.*.is_true' => ['boolean'],
        ]);
        $data['max_words'] = (int) ($data['max_words'] ?? 200);
        $data['submission_mode'] = $data['submission_mode'] ?? 'review';
        $data['title'] = trim((string) ($data['title'] ?? ''));
        $data['story'] = trim((string) ($data['story'] ?? ''));
        if ($data['submission_mode'] === 'draft' && ! $this->hasDraftContent($data)) {
            throw ValidationException::withMessages([
                'title' => 'Isi minimal satu kolom teks sebelum menyimpan draft.',
            ]);
        }
        if ($this->wordCount($data['story']) > $data['max_words']) {
            throw ValidationException::withMessages([
                'story' => "Isi bacaan melebihi batas {$data['max_words']} kata.",
            ]);
        }

        $subject = $this->subject($request, (int) $data['subject_id']);
        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => 'Mata pelajaran Bahasa Indonesia tidak tersedia.']);
        }

        $competency = Competency::query()
            ->whereKey($data['competency_id'])
            ->where('subject_id', $subject->id)
            ->whereNull('parent_id')
            ->first();
        if (! $competency) {
            throw ValidationException::withMessages(['competency_id' => 'Kompetensi Bahasa Indonesia tidak tersedia.']);
        }

        $blueprints = $competency->questionBlueprints()
            ->where('subject_id', $subject->id)
            ->get(['question_blueprints.id', 'code', 'name'])
            ->keyBy('id');
        $submittedIds = collect($data['bundle_slots'])->pluck('question_blueprint_id')->map(fn ($id): int => (int) $id);
        if ($blueprints->count() !== 3 || $submittedIds->sort()->values()->all() !== $blueprints->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'bundle_slots' => 'Bundle wajib menggunakan ketiga tipe soal milik kompetensi masing-masing satu kali.',
            ]);
        }

        $configuration->ensureComplete(
            collect($data['bundle_slots'])->map(fn (array $slot): array => collect($slot)->only(['answer_format', 'cognitive_level'])->all())->all(),
            'bundle_slots',
        );
        if ($data['submission_mode'] === 'review') {
            $this->validateAnswers($data['bundle_slots'], $data['questions']);
        }
        $draftComplete = $this->isDraftComplete($data);

        $replacedDraft = null;
        if (isset($data['draft_generation_id'])) {
            $replacedDraft = AiGeneration::query()
                ->whereKey($data['draft_generation_id'])
                ->where('school_id', $request->user()->school_id)
                ->where('requested_by', $request->user()->id)
                ->where('request_payload->source', 'manual')
                ->where('request_payload->submission_mode', 'draft')
                ->firstOrFail();
        }

        $replacedIllustration = $replacedDraft === null
            ? null
            : data_get(Question::query()->where('story_generation_id', $replacedDraft->id)->first()?->metadata, 'illustration');
        $illustration = $replacedIllustration;
        $storedNewIllustration = false;

        try {
            if ($request->hasFile('stimulus_image')) {
                $storedNewIllustration = true;
                $illustration = [
                    ...$imageService->store(
                        $request->file('stimulus_image'),
                        $request->user()->school_id,
                        trim($data['stimulus_image_alt'] ?? '') ?: 'Gambar pendukung stimulus '.($data['title'] ?: 'draft bundle'),
                    ),
                    'display_width' => 800,
                    'display_height' => 450,
                    'display_zoom' => 1,
                    'display_offset_x' => 0,
                    'display_offset_y' => 0,
                ];
            }

            $generation = DB::transaction(function () use ($data, $request, $subject, $competency, $configuration, $illustration, $draftComplete): AiGeneration {
                $paragraphCount = collect(preg_split('/\R{2,}/u', trim($data['story'])))->filter()->count();
                $payload = [
                    'source' => 'manual',
                    'format' => 'story',
                    'submission_mode' => $data['submission_mode'],
                    'draft_complete' => $draftComplete,
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'competency_id' => $competency->id,
                    'competency_code' => $competency->code,
                    'competency_name' => $competency->name,
                    'theme' => $data['title'] ?: 'Draft belum diberi judul',
                    'paragraph_count' => max(1, $paragraphCount),
                    'max_words' => $data['max_words'],
                    'question_count' => 3,
                    'use_illustration' => false,
                    'has_stimulus_image' => $illustration !== null,
                    'bundle_slots' => collect($data['bundle_slots'])->values()->map(fn (array $slot, int $index): array => [
                        'position' => $index + 1,
                        'question_blueprint_id' => (int) $slot['question_blueprint_id'],
                        'answer_format' => $slot['answer_format'],
                        'cognitive_level' => $slot['cognitive_level'],
                        'cognitive_level_label' => $configuration->levelLabel($slot['cognitive_level']),
                    ])->all(),
                ];
                $generation = AiGeneration::create([
                    'school_id' => $request->user()->school_id,
                    'requested_by' => $request->user()->id,
                    'type' => AiGenerationType::StoryQuestions,
                    'status' => AiGenerationStatus::Completed,
                    'provider' => 'manual',
                    'model' => 'manual',
                    'input_hash' => hash('sha256', Str::uuid()->toString()),
                    'request_payload' => $payload,
                ]);

                $questionIds = collect($data['bundle_slots'])->values()->map(function (array $slot, int $index) use ($data, $request, $competency, $configuration, $generation, $illustration): int {
                    $answerFormat = $slot['answer_format'];
                    $questionData = $data['questions'][$index];
                    $metadata = [
                        'created_manually' => true,
                        'story_generation_id' => $generation->id,
                        'generation_format' => 'story',
                        'bundle_answer_format' => $answerFormat,
                        'bundle_cognitive_level' => $slot['cognitive_level'],
                        'verification_locked' => $data['submission_mode'] === 'draft',
                    ];

                    if ($illustration !== null) {
                        $metadata['illustration'] = $illustration;
                    }

                    if ($answerFormat === 'true_false') {
                        $columns = collect([
                            trim($questionData['matrix_labels']['true_label'] ?? '') ?: 'Benar',
                            trim($questionData['matrix_labels']['false_label'] ?? '') ?: 'Salah',
                        ])->map(fn (string $label): array => [
                            'id' => (string) Str::uuid(),
                            'label' => $label,
                        ])->values();
                        $metadata['matrix_columns'] = $columns->all();
                        $metadata['matrix_rows'] = collect($questionData['statements'])->map(fn (array $statement): array => [
                            'id' => (string) Str::uuid(),
                            'statement' => trim($statement['content']),
                            'correct_column_id' => $columns[$statement['is_true'] ? 0 : 1]['id'],
                        ])->all();
                    }

                    $question = Question::create([
                        'author_id' => $request->user()->id,
                        'story_generation_id' => $generation->id,
                        'competency_id' => $competency->id,
                        'question_blueprint_id' => (int) $slot['question_blueprint_id'],
                        'type' => match ($answerFormat) {
                            'single_choice' => QuestionType::SingleChoice,
                            'multiple_choice' => QuestionType::MultipleChoice,
                            'true_false' => QuestionType::CategoryMatrix,
                        },
                        'status' => $data['submission_mode'] === 'draft' ? QuestionStatus::Draft : QuestionStatus::Review,
                        'title' => ($data['title'] ?: 'Draft bundle').' — Soal '.($index + 1),
                        'stimulus' => $data['story'],
                        'prompt' => trim((string) ($questionData['prompt'] ?? '')),
                        'explanation' => filled($questionData['explanation'] ?? null) ? trim($questionData['explanation']) : null,
                        'difficulty' => match ($slot['cognitive_level']) {
                            'textual' => 1,
                            'inferential' => 2,
                            'evaluation' => 3,
                        },
                        'grade_level' => $competency->grade_level,
                        'cognitive_level' => $configuration->levelLabel($slot['cognitive_level']),
                        'metadata' => $metadata,
                    ]);

                    if ($answerFormat !== 'true_false') {
                        foreach ($questionData['options'] ?? [] as $optionIndex => $option) {
                            $question->options()->create([
                                'label' => chr(65 + $optionIndex),
                                'content' => trim((string) ($option['content'] ?? '')),
                                'is_correct' => (bool) $option['is_correct'],
                                'position' => $optionIndex + 1,
                            ]);
                        }
                    }

                    return $question->id;
                })->all();

                $generation->update(['result_payload' => [
                    'source' => 'manual',
                    'format' => 'story',
                    'title' => $data['title'],
                    'story' => $data['story'],
                    'paragraph_count' => $payload['paragraph_count'],
                    'question_count' => 3,
                    'question_ids' => $questionIds,
                ]]);

                return $generation;
            });
        } catch (Throwable $exception) {
            if ($storedNewIllustration && $illustration !== null) {
                Storage::disk($illustration['disk'])->delete($illustration['path']);
            }

            throw $exception;
        }

        if ($replacedDraft !== null) {
            Question::query()->where('story_generation_id', $replacedDraft->id)->delete();
            $replacedDraft->delete();
            if ($storedNewIllustration && $replacedIllustration !== null) {
                Storage::disk($replacedIllustration['disk'])->delete($replacedIllustration['path']);
            }
        }

        $auditLogger->log($request, 'manual_story_bundle.created', $generation, [
            'question_ids' => data_get($generation->result_payload, 'question_ids', []),
            'competency_id' => $competency->id,
            'submission_mode' => $data['submission_mode'],
        ]);

        return to_route('story-questions.show', $generation)
            ->with('success', $data['submission_mode'] === 'draft'
                ? 'Bundle disimpan sebagai draft pribadi dan belum dapat diverifikasi guru lain.'
                : 'Bundle berhasil disimpan dan diajukan untuk verifikasi.');
    }

    private function subject(Request $request, int $subjectId): ?Subject
    {
        return Subject::query()
            ->whereKey($subjectId)
            ->where('code', 'BIND')
            ->whereHas('competencies')
            ->first(['id', 'code', 'name']);
    }

    private function validateAnswers(array $slots, array $questions): void
    {
        foreach ($slots as $index => $slot) {
            $question = $questions[$index];
            if ($slot['answer_format'] === 'true_false') {
                $statements = collect($question['statements'] ?? []);
                $labels = collect([
                    trim((string) data_get($question, 'matrix_labels.true_label', 'Benar')),
                    trim((string) data_get($question, 'matrix_labels.false_label', 'Salah')),
                ]);
                if ($labels->contains('') || $labels->map(fn (string $label): string => mb_strtolower($label))->unique()->count() !== 2) {
                    throw ValidationException::withMessages(["questions.{$index}.matrix_labels" => 'Nama kedua kolom wajib diisi dan harus berbeda.']);
                }
                if ($statements->count() !== 3 || $statements->contains(fn (array $row): bool => trim((string) ($row['content'] ?? '')) === '')) {
                    throw ValidationException::withMessages(["questions.{$index}.statements" => 'Benar/Salah wajib memiliki tepat tiga pernyataan lengkap.']);
                }

                continue;
            }

            $options = collect($question['options'] ?? []);
            $correctCount = $options->where('is_correct', true)->count();
            if ($options->contains(fn (array $option): bool => trim((string) ($option['content'] ?? '')) === '')) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'Semua pilihan jawaban wajib diisi lengkap.']);
            }
            if ($slot['answer_format'] === 'single_choice' && $options->count() !== 4) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'Pilihan Ganda wajib memiliki tepat empat pilihan jawaban.']);
            }
            if ($slot['answer_format'] === 'multiple_choice' && $options->count() < 2) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'MCMA membutuhkan minimal dua pilihan jawaban.']);
            }
            if ($slot['answer_format'] === 'single_choice' && $correctCount !== 1) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'Pilihan Ganda wajib memiliki tepat satu jawaban benar.']);
            }
            if ($slot['answer_format'] === 'multiple_choice' && $correctCount < 2) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'MCMA wajib memiliki minimal dua jawaban benar.']);
            }
        }
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[-’\'][\p{L}\p{N}]+)*/u', $text, $matches);

        return count($matches[0]);
    }

    private function hasDraftContent(array $data): bool
    {
        $values = collect([$data['title'] ?? '', $data['story'] ?? '']);
        foreach ($data['questions'] ?? [] as $question) {
            $values->push($question['prompt'] ?? '', $question['explanation'] ?? '');
            $values->push(...collect($question['options'] ?? [])->pluck('content')->all());
            $values->push(...collect($question['statements'] ?? [])->pluck('content')->all());
        }

        return $values->contains(fn (mixed $value): bool => trim((string) $value) !== '');
    }

    private function isDraftComplete(array $data): bool
    {
        if ($data['title'] === '' || $data['story'] === '' || collect($data['questions'])->contains(fn (array $question): bool => trim((string) ($question['prompt'] ?? '')) === '')) {
            return false;
        }

        try {
            $this->validateAnswers($data['bundle_slots'], $data['questions']);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }
}
