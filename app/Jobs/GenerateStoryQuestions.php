<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Services\AI\AiManager;
use App\Services\AI\EducationalMathSvgRenderer;
use App\Services\AI\GeometrySvgRenderer;
use App\Services\AI\MathIllustrationProfile;
use App\Services\QuestionDuplicateDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GenerateStoryQuestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $generationId) {}

    public function handle(
        AiManager $manager,
        QuestionDuplicateDetector $duplicateDetector,
        GeometrySvgRenderer $geometryRenderer,
        EducationalMathSvgRenderer $educationalMathRenderer,
        MathIllustrationProfile $illustrationProfile,
    ): void {
        $generation = AiGeneration::with('requester')->findOrFail($this->generationId);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);

        try {
            $competencies = $this->competencies($generation);
            $format = data_get($generation->request_payload, 'format') === 'direct' ? 'direct' : 'story';
            $theme = trim((string) data_get($generation->request_payload, 'theme'));
            $paragraphCount = (int) data_get($generation->request_payload, 'paragraph_count', 3);
            $maxWords = (int) data_get($generation->request_payload, 'max_words', 200);
            $questionCount = (int) data_get($generation->request_payload, 'question_count', 3);
            $questionStyle = (string) data_get($generation->request_payload, 'question_style', 'direct');
            $answerFormat = (string) data_get($generation->request_payload, 'answer_format', 'single_choice');
            $requestedDifficulty = data_get($generation->request_payload, 'difficulty');
            $targetDifficulty = $requestedDifficulty !== null
                ? max(1, min(3, (int) $requestedDifficulty))
                : null;
            $useIllustration = (bool) data_get($generation->request_payload, 'use_illustration', false);
            $illustrationMode = (string) data_get($generation->request_payload, 'illustration_mode', 'lite');
            $questionBlueprints = collect(data_get($generation->request_payload, 'question_blueprints', []))->values();
            $competencyContext = $competencies->map(fn (Competency $competency): array => [
                'code' => $competency->code,
                'domain' => $competency->domain,
                'name' => $competency->name,
                'grade_level' => $competency->grade_level,
            ])->values()->all();
            $recentQuestions = $this->recentQuestionExamples($generation, $competencies);
            $variationStrategy = $this->variationStrategy($generation->id);
            $prompt = $format === 'story'
                ? $this->prompt($theme, $paragraphCount, $maxWords, $questionCount, $competencyContext, $questionBlueprints->all(), $recentQuestions, $variationStrategy)
                : $this->directPrompt(
                    (string) data_get($generation->request_payload, 'example_question', ''),
                    $questionCount,
                    $competencyContext,
                    $questionStyle,
                    $answerFormat,
                    $useIllustration,
                    $questionBlueprints->all(),
                    $recentQuestions,
                    $variationStrategy,
                    $illustrationProfile->promptDirection(array_column($competencyContext, 'code')),
                    $illustrationMode,
                );
            $usesConfiguredBundle = $questionBlueprints->isNotEmpty()
                && $questionBlueprints->every(fn (array $blueprint): bool => isset($blueprint['cognitive_level']));
            if (! $usesConfiguredBundle && $targetDifficulty !== null) {
                $prompt .= "\n\nLEVEL SOAL WAJIB: semua soal harus memiliki difficulty={$targetDifficulty} pada skala 1=mudah, 2=sedang, 3=sulit.";
            }
            $provider = $manager->provider();

            $context = [
                'task' => 'story_questions',
                'format' => $format,
                'theme' => $theme,
                'paragraph_count' => $paragraphCount,
                'max_words' => $format === 'story' ? $maxWords : 0,
                'question_count' => $questionCount,
                'question_style' => $questionStyle,
                'answer_format' => $answerFormat,
                'difficulty' => $targetDifficulty,
                'use_illustration' => $useIllustration,
                'illustration_mode' => $illustrationMode,
                'competencies' => $competencyContext,
                'question_blueprints' => $questionBlueprints->all(),
                'variation_strategy' => $variationStrategy,
            ];
            $response = $provider->generateJson($prompt, $context);
            if ($provider->name() !== 'fake'
                && $this->hasDuplicatePrompt(data_get($response->data, 'questions', []), $recentQuestions, $duplicateDetector)) {
                $response = $provider->generateJson(
                    $prompt."\n\nOUTPUT SEBELUMNYA DITOLAK KARENA TERLALU MIRIP. Buat ulang dari nol dengan objek, angka, informasi yang ditanyakan, dan cara penyelesaian yang berbeda.",
                    $context,
                );
            }

            $data = Validator::make($response->data, [
                'title' => ['required', 'string', 'max:255'],
                'visual_description' => ['nullable', 'string', 'max:5000'],
                'visual_spec' => ['nullable', 'array'],
                'visual_spec.type' => ['required_with:visual_spec', Rule::in(['fraction_models', 'object_groups', 'geometry_2d', ...EducationalMathSvgRenderer::TYPES])],
                'visual_spec.shape' => ['required_if:visual_spec.type,geometry_2d', Rule::in([...GeometrySvgRenderer::SHAPES, 'cube', 'rectangular_prism'])],
                'visual_spec.unit' => ['nullable', 'string', 'max:20'],
                'visual_spec.title' => ['nullable', 'string', 'max:100'],
                'visual_spec.dimensions' => ['required_if:visual_spec.type,geometry_2d', 'array'],
                'visual_spec.dimensions.*' => ['numeric', 'gt:0'],
                'visual_spec.items' => ['required_if:visual_spec.type,fraction_models', 'array', 'between:1,8'],
                'visual_spec.items.*.shape' => ['nullable', Rule::in(['circle', 'rectangle'])],
                'visual_spec.items.*.total_parts' => ['nullable', 'integer', 'between:1,20'],
                'visual_spec.items.*.shaded_parts' => ['nullable', 'integer', 'between:0,20'],
                'visual_spec.items.*.label' => ['nullable', 'string', 'max:30'],
                'visual_spec.items.*.value' => ['nullable', 'numeric', 'gte:0'],
                'visual_spec.groups' => ['required_if:visual_spec.type,object_groups', 'integer', 'between:1,10'],
                'visual_spec.objects_per_group' => ['required_if:visual_spec.type,object_groups', 'integer', 'between:1,20'],
                'visual_spec.kind' => ['nullable', Rule::in(['ruler', 'liquid', 'mass'])],
                'visual_spec.style' => ['nullable', Rule::in(['bar', 'pictogram', 'table'])],
                'visual_spec.value' => ['nullable', 'numeric', 'gte:0'],
                'visual_spec.maximum' => ['nullable', 'numeric', 'gt:0'],
                'visual_spec.legend_value' => ['nullable', 'numeric', 'gt:0'],
                'visual_spec.hour' => ['nullable', 'integer', 'between:0,23'],
                'visual_spec.minute' => ['nullable', 'integer', 'between:0,59'],
                'visual_spec.degrees' => ['nullable', 'numeric', 'gt:0', 'lt:360'],
                'visual_spec.cubes' => ['nullable', 'array', 'between:1,40'],
                'visual_spec.cubes.*.x' => ['required_with:visual_spec.cubes', 'integer', 'between:-8,8'],
                'visual_spec.cubes.*.y' => ['required_with:visual_spec.cubes', 'integer', 'between:-8,8'],
                'visual_spec.cubes.*.z' => ['required_with:visual_spec.cubes', 'integer', 'between:-8,8'],
                'visual_spec.points' => ['nullable', 'array', 'between:2,6'],
                'visual_spec.points.*.label' => ['required_with:visual_spec.points', 'string', 'max:30'],
                'visual_spec.points.*.distance_from_previous' => ['nullable', 'numeric', 'gt:0'],
                'story_paragraphs' => [$format === 'story' ? 'required' : 'present', 'array', $format === 'story' ? "size:{$paragraphCount}" : 'size:0'],
                'story_paragraphs.*' => ['required', 'string', 'max:5000'],
                'questions' => ['required', 'array', "size:{$questionCount}"],
                'questions.*.competency_code' => ['required', 'string', Rule::in($competencies->keys()->all())],
                'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
                'questions.*.title' => ['nullable', 'string', 'max:255'],
                'questions.*.stimulus' => ['nullable', 'string', 'max:10000'],
                'questions.*.prompt' => ['required', 'string', 'max:10000'],
                'questions.*.explanation' => ['required', 'string', 'max:10000'],
                'questions.*.difficulty' => ['required', 'integer', 'between:1,3'],
                'questions.*.cognitive_level' => ['nullable', 'string', 'max:100'],
                'questions.*.options' => ['array', 'max:6'],
                'questions.*.options.*.content' => ['required', 'string', 'max:3000'],
                'questions.*.options.*.is_correct' => ['required', 'boolean'],
                'questions.*.accepted_answers' => ['array'],
                'questions.*.accepted_answers.*' => ['string', 'max:500'],
                'questions.*.matching_pairs' => ['array', 'max:8'],
                'questions.*.matching_pairs.*.left' => ['required', 'string', 'max:1000'],
                'questions.*.matching_pairs.*.right' => ['required', 'string', 'max:1000'],
                'questions.*.matching_distractors' => ['array', 'max:4'],
                'questions.*.matching_distractors.*' => ['string', 'max:1000'],
                'questions.*.matrix_columns' => ['array', 'max:4'],
                'questions.*.matrix_columns.*' => ['string', 'max:255'],
                'questions.*.matrix_rows' => ['array', 'max:10'],
                'questions.*.matrix_rows.*.statement' => ['required', 'string', 'max:1000'],
                'questions.*.matrix_rows.*.correct_column_index' => ['required', 'integer', 'between:0,3'],
            ])->validate();

            if (data_get($data, 'visual_spec.type') === 'geometry_2d'
                && ($geometryError = $geometryRenderer->validationError($data['visual_spec'])) !== null) {
                throw ValidationException::withMessages(['visual_spec' => $geometryError]);
            }
            if ($educationalMathRenderer->supports($data['visual_spec'] ?? null)
                && ($visualError = $educationalMathRenderer->validationError($data['visual_spec'])) !== null) {
                throw ValidationException::withMessages(['visual_spec' => $visualError]);
            }

            if (data_get($data, 'visual_spec.type') === 'fraction_models') {
                foreach ($data['visual_spec']['items'] ?? [] as $index => $item) {
                    if (! isset($item['shape'], $item['total_parts'], $item['shaded_parts'])) {
                        throw ValidationException::withMessages(["visual_spec.items.{$index}" => 'Model pecahan membutuhkan shape, total_parts, dan shaded_parts.']);
                    }
                }
            }

            if ($format === 'direct' && $useIllustration && trim((string) ($data['visual_description'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'visual_description' => 'AI tidak memberikan deskripsi visual untuk ilustrasi yang diminta.',
                ]);
            }

            $precisionContext = mb_strtolower(
                (string) data_get($generation->request_payload, 'subject_name').' '.
                (string) data_get($generation->request_payload, 'competency_name'),
            );
            if ($format === 'direct'
                && $useIllustration
                && $illustrationMode === 'lite'
                && str_contains($precisionContext, 'matematika')
                && $illustrationProfile->requiresVisual(array_column($competencyContext, 'code'))
                && ! $this->supportsPrecisionVisualSpec($data['visual_spec'] ?? null)) {
                throw ValidationException::withMessages([
                    'visual_spec' => 'Soal Matematika ini membutuhkan spesifikasi diagram presisi.',
                ]);
            }
            if ($format === 'direct'
                && $useIllustration
                && $illustrationMode === 'lite'
                && str_contains($precisionContext, 'matematika')
                && $illustrationProfile->requiresVisual(array_column($competencyContext, 'code'))
                && ! $illustrationProfile->acceptsType(array_column($competencyContext, 'code'), data_get($data, 'visual_spec.type'))) {
                throw ValidationException::withMessages([
                    'visual_spec' => 'Jenis SVG tidak sesuai dengan kebutuhan subkompetensi Matematika yang dipilih.',
                ]);
            }

            foreach (data_get($data, 'visual_spec.type') === 'fraction_models' ? data_get($data, 'visual_spec.items', []) : [] as $index => $item) {
                if ($item['shaded_parts'] > $item['total_parts']) {
                    throw ValidationException::withMessages([
                        "visual_spec.items.{$index}.shaded_parts" => 'Jumlah bagian diarsir tidak boleh melebihi jumlah seluruh bagian.',
                    ]);
                }
            }

            $this->validateQuestionSet($data['questions'], $competencies);
            if ($format === 'story' && $questionBlueprints->isNotEmpty() && $questionBlueprints->every(fn (array $blueprint): bool => isset($blueprint['answer_format'], $blueprint['cognitive_level']))) {
                $this->validateIndonesianBundle($data['questions'], $questionBlueprints);
            }
            if ($provider->name() !== 'fake'
                && $this->hasDuplicatePrompt($data['questions'], $recentQuestions, $duplicateDetector)) {
                throw ValidationException::withMessages([
                    'questions' => 'AI masih menghasilkan soal yang terlalu mirip dengan soal lain. Silakan proses ulang.',
                ]);
            }
            if ($format === 'direct') {
                $this->validateAnswerFormat($data['questions'], $answerFormat);
            }
            $story = $format === 'story' ? implode("\n\n", $data['story_paragraphs']) : null;

            if ($story !== null && mb_strlen($story) > 20000) {
                throw ValidationException::withMessages([
                    'story_paragraphs' => 'Cerita yang dihasilkan terlalu panjang.',
                ]);
            }
            if ($story !== null && $this->wordCount($story) > $maxWords) {
                throw ValidationException::withMessages([
                    'story_paragraphs' => "Cerita yang dihasilkan melebihi batas {$maxWords} kata.",
                ]);
            }

            $questionIds = DB::transaction(function () use ($data, $generation, $manager, $response, $theme, $story, $competencies, $format, $questionBlueprints, $targetDifficulty): array {
                $questionIds = [];

                foreach ($data['questions'] as $index => $questionData) {
                    $type = QuestionType::from($questionData['type']);
                    $competency = $competencies[$questionData['competency_code']];
                    $metadata = [
                        'generated_by_ai' => true,
                        'story_generation_id' => $generation->id,
                        'generation_format' => $format,
                        'story_theme' => $format === 'story' ? $theme : null,
                        'bundle_answer_format' => data_get($questionBlueprints, "{$index}.answer_format"),
                        'bundle_cognitive_level' => data_get($questionBlueprints, "{$index}.cognitive_level"),
                    ];

                    if ($type === QuestionType::ShortAnswer) {
                        $metadata['accepted_answers'] = array_values($questionData['accepted_answers']);
                    }

                    if ($type === QuestionType::Matching) {
                        $metadata['matching_pairs'] = collect($questionData['matching_pairs'])->map(fn (array $pair): array => [
                            'left_id' => (string) Str::uuid(),
                            'left' => trim($pair['left']),
                            'right_id' => (string) Str::uuid(),
                            'right' => trim($pair['right']),
                        ])->all();
                        $metadata['matching_distractors'] = collect($questionData['matching_distractors'] ?? [])->map(fn (string $content): array => [
                            'id' => (string) Str::uuid(),
                            'content' => trim($content),
                        ])->all();
                    }

                    if ($type === QuestionType::CategoryMatrix) {
                        $columns = collect($questionData['matrix_columns'])->map(fn (string $label): array => [
                            'id' => (string) Str::uuid(),
                            'label' => trim($label),
                        ])->values();
                        $metadata['matrix_columns'] = $columns->all();
                        $metadata['matrix_rows'] = collect($questionData['matrix_rows'])->map(fn (array $row): array => [
                            'id' => (string) Str::uuid(),
                            'statement' => trim($row['statement']),
                            'correct_column_id' => $columns[$row['correct_column_index']]['id'],
                        ])->all();
                    }

                    $submissionMode = data_get($generation->request_payload, 'submission_mode', 'draft');
                    $initialStatus = $submissionMode === 'review' ? QuestionStatus::Review : QuestionStatus::Draft;
                    $metadata['verification_locked'] = $submissionMode === 'draft';

                    $question = Question::create([
                        'author_id' => $generation->requested_by,
                        'story_generation_id' => $generation->id,
                        'competency_id' => $competency->id,
                        'question_blueprint_id' => $questionBlueprints->isNotEmpty()
                            ? $questionBlueprints[$index % $questionBlueprints->count()]['id']
                            : null,
                        'type' => $type,
                        'status' => $initialStatus,
                        'title' => ($questionData['title'] ?? null) ?: $data['title'].' - Soal '.($index + 1),
                        'stimulus' => $format === 'story' ? $story : ($questionData['stimulus'] ?? null),
                        'prompt' => $questionData['prompt'],
                        'explanation' => $questionData['explanation'],
                        'difficulty' => match (data_get($questionBlueprints, "{$index}.cognitive_level")) {
                            'textual' => 1,
                            'inferential' => 2,
                            'evaluation' => 3,
                            default => $targetDifficulty ?? $questionData['difficulty'],
                        },
                        'grade_level' => $competency->grade_level,
                        'cognitive_level' => data_get($questionBlueprints, "{$index}.cognitive_level_label", $questionData['cognitive_level'] ?? null),
                        'metadata' => $metadata,
                    ]);

                    foreach ($questionData['options'] ?? [] as $optionIndex => $option) {
                        $question->options()->create([
                            'label' => chr(65 + $optionIndex),
                            'content' => $option['content'],
                            'is_correct' => $option['is_correct'],
                            'position' => $optionIndex + 1,
                        ]);
                    }

                    $questionIds[] = $question->id;
                }

                $generation->update([
                    'status' => AiGenerationStatus::Completed,
                    'result_payload' => [
                        'title' => $data['title'],
                        'format' => $format,
                        'story' => $story,
                        'visual_description' => $data['visual_description'] ?? null,
                        'visual_spec' => $data['visual_spec'] ?? null,
                        'paragraph_count' => count($data['story_paragraphs']),
                        'question_ids' => $questionIds,
                        'question_count' => count($questionIds),
                    ],
                    'input_tokens' => $response->inputTokens,
                    'output_tokens' => $response->outputTokens,
                    'cost_microusd' => $manager->costMicrousd($response),
                ]);

                return $questionIds;
            });

            $minimumQuestionCount = $format === 'story' ? 2 : 1;
            $maximumQuestionCount = $format === 'story' ? 4 : 9;
            if (count($questionIds) < $minimumQuestionCount || count($questionIds) > $maximumQuestionCount) {
                throw new RuntimeException('Jumlah soal AI di luar batas yang diizinkan.');
            }
        } catch (Throwable $exception) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            throw $exception;
        }
    }

    private function competencies(AiGeneration $generation): Collection
    {
        $subjectId = (int) data_get($generation->request_payload, 'subject_id');
        $competencyId = (int) data_get($generation->request_payload, 'competency_id');
        $competencies = Competency::query()
            ->when($subjectId > 0, fn ($query) => $query->where('subject_id', $subjectId))
            ->when($competencyId > 0, fn ($query) => $query->whereKey($competencyId))
            ->when($competencyId === 0, fn ($query) => $query->whereDoesntHave('children'))
            ->get()
            ->unique('code')
            ->keyBy('code');

        if ($competencies->isEmpty()) {
            throw new RuntimeException('Belum ada kompetensi yang dapat dipakai untuk membuat soal.');
        }

        return $competencies;
    }

    private function validateQuestionSet(array $questions, Collection $competencies): void
    {
        $gradeLevels = collect($questions)
            ->map(fn (array $question): int => $competencies[$question['competency_code']]->grade_level)
            ->unique();

        if ($gradeLevels->count() !== 1) {
            throw ValidationException::withMessages([
                'questions' => 'Semua soal dalam satu cerita harus menggunakan jenjang kelas yang sama.',
            ]);
        }

        foreach ($questions as $index => $question) {
            $type = QuestionType::from($question['type']);
            $options = $question['options'] ?? [];
            $acceptedAnswers = array_values(array_filter($question['accepted_answers'] ?? []));
            $correctCount = collect($options)->where('is_correct', true)->count();

            if ($type === QuestionType::ShortAnswer && $acceptedAnswers === []) {
                throw ValidationException::withMessages([
                    "questions.{$index}.accepted_answers" => 'Isian singkat membutuhkan minimal satu jawaban.',
                ]);
            }

            if ($type === QuestionType::SingleChoice && (count($options) < 2 || $correctCount !== 1)) {
                throw ValidationException::withMessages([
                    "questions.{$index}.options" => 'Pilihan tunggal membutuhkan minimal dua opsi dan tepat satu jawaban benar.',
                ]);
            }

            if ($type === QuestionType::MultipleChoice && (count($options) < 2 || $correctCount < 1)) {
                throw ValidationException::withMessages([
                    "questions.{$index}.options" => 'Pilihan kompleks membutuhkan minimal dua opsi dan satu jawaban benar.',
                ]);
            }

            if ($type === QuestionType::Matching) {
                $pairs = collect($question['matching_pairs'] ?? []);
                $leftItems = $pairs->pluck('left')->map(fn (string $value): string => mb_strtolower(trim($value)));
                $rightItems = $pairs->pluck('right')
                    ->merge($question['matching_distractors'] ?? [])
                    ->map(fn (string $value): string => mb_strtolower(trim($value)));

                if ($pairs->count() < 2
                    || $leftItems->unique()->count() !== $leftItems->count()
                    || $rightItems->unique()->count() !== $rightItems->count()) {
                    throw ValidationException::withMessages([
                        "questions.{$index}.matching_pairs" => 'Soal menjodohkan membutuhkan minimal dua pasangan unik.',
                    ]);
                }
            }

            if ($type === QuestionType::CategoryMatrix) {
                $columns = collect($question['matrix_columns'] ?? [])->map(fn (string $value): string => mb_strtolower(trim($value)));
                $rows = collect($question['matrix_rows'] ?? []);
                $statements = $rows->pluck('statement')->map(fn (string $value): string => mb_strtolower(trim($value)));
                $invalidColumn = $rows->contains(fn (array $row): bool => $row['correct_column_index'] >= $columns->count());

                if ($columns->count() < 2
                    || $rows->count() < 2
                    || $columns->unique()->count() !== $columns->count()
                    || $statements->unique()->count() !== $statements->count()
                    || $invalidColumn) {
                    throw ValidationException::withMessages([
                        "questions.{$index}.matrix_rows" => 'Soal pilihan kategori membutuhkan minimal dua kolom dan dua pernyataan unik.',
                    ]);
                }
            }
        }
    }

    private function validateAnswerFormat(array $questions, string $answerFormat): void
    {
        $types = collect($questions)->pluck('type');
        $invalid = match ($answerFormat) {
            'single_choice' => $types->contains(fn (string $type): bool => $type !== QuestionType::SingleChoice->value),
            'multiple_choice' => collect($questions)->contains(fn (array $question): bool => $question['type'] !== QuestionType::MultipleChoice->value
                || collect($question['options'] ?? [])->where('is_correct', true)->count() < 2),
            'true_false' => collect($questions)->contains(function (array $question): bool {
                $columns = collect($question['matrix_columns'] ?? [])->map(
                    fn (string $column): string => mb_strtolower(trim($column)),
                )->values()->all();

                return $question['type'] !== QuestionType::CategoryMatrix->value
                    || $columns !== ['benar', 'salah']
                    || count($question['matrix_rows'] ?? []) !== 3;
            }),
            'mixed' => collect($questions)->contains(function (array $question): bool {
                $type = $question['type'];
                if (! in_array($type, [
                    QuestionType::SingleChoice->value,
                    QuestionType::MultipleChoice->value,
                    QuestionType::CategoryMatrix->value,
                ], true)) {
                    return true;
                }
                if ($type === QuestionType::MultipleChoice->value) {
                    return collect($question['options'] ?? [])->where('is_correct', true)->count() < 2;
                }
                if ($type === QuestionType::CategoryMatrix->value) {
                    return collect($question['matrix_columns'] ?? [])
                        ->map(fn (string $column): string => mb_strtolower(trim($column)))
                        ->values()
                        ->all() !== ['benar', 'salah']
                        || count($question['matrix_rows'] ?? []) !== 3;
                }

                return false;
            }) || $types->unique()->count() < 2,
            default => true,
        };

        if ($invalid) {
            throw ValidationException::withMessages([
                'answer_format' => 'AI tidak menghasilkan format jawaban sesuai pilihan guru.',
            ]);
        }
    }

    private function validateIndonesianBundle(array $questions, Collection $blueprints): void
    {
        foreach ($questions as $index => $question) {
            $format = data_get($blueprints, "{$index}.answer_format");
            $valid = match ($format) {
                'single_choice' => $question['type'] === QuestionType::SingleChoice->value,
                'multiple_choice' => $question['type'] === QuestionType::MultipleChoice->value
                    && collect($question['options'] ?? [])->where('is_correct', true)->count() >= 2,
                'true_false' => $question['type'] === QuestionType::CategoryMatrix->value
                    && collect($question['matrix_columns'] ?? [])->map(fn (string $column): string => mb_strtolower(trim($column)))->values()->all() === ['benar', 'salah']
                    && count($question['matrix_rows'] ?? []) === 3,
                default => false,
            };

            if (! $valid) {
                throw ValidationException::withMessages([
                    "questions.{$index}.type" => 'AI tidak mengikuti format jawaban pada komposisi bundle Bahasa Indonesia.',
                ]);
            }
        }
    }

    private function supportsPrecisionVisualSpec(mixed $spec): bool
    {
        return is_array($spec)
            && in_array(data_get($spec, 'type'), ['fraction_models', 'object_groups', 'geometry_2d', ...EducationalMathSvgRenderer::TYPES], true);
    }

    private function prompt(string $theme, int $paragraphCount, int $maxWords, int $questionCount, array $competencies, array $questionBlueprints, array $recentQuestions, string $variationStrategy): string
    {
        $competencyJson = json_encode($competencies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $blueprintDirection = $this->questionBlueprintDirection($questionBlueprints);
        $duplicateDirection = $this->duplicateDirection($recentQuestions, $variationStrategy);
        $themeDirection = $theme !== ''
            ? "Gunakan tema yang diberikan guru: \"{$theme}\"."
            : 'Guru tidak menentukan tema. Pilih sendiri satu tema yang konkret, menarik, sesuai usia siswa dan kompetensi, serta berbeda dari contoh soal sebelumnya.';

        return <<<PROMPT
Anda membantu guru membuat paket soal cerita Try Out Adaptif berbahasa Indonesia.

{$themeDirection}
Buat satu cerita dengan tepat {$paragraphCount} paragraf dan maksimal {$maxWords} kata untuk keseluruhan cerita, lalu buat tepat {$questionCount} soal yang semuanya hanya menggunakan cerita tersebut sebagai stimulus. Kembalikan setiap paragraf sebagai satu elemen story_paragraphs tanpa nomor paragraf. Gunakan hanya kompetensi atau subkompetensi yang diberikan untuk seluruh soal dan jangan menggantinya dengan klasifikasi lain. Semua soal harus berada pada satu jenjang kelas yang sama. Cerita harus sesuai usia jenjang tersebut, faktual, aman untuk anak, tidak bias, dan memuat seluruh informasi yang diperlukan untuk menjawab soal.

Gunakan variasi tingkat kesulitan dan proses kognitif. Soal boleh berbentuk single_choice, multiple_choice, short_answer, matching, atau category_matrix. Untuk single_choice berikan 4 opsi dengan tepat satu jawaban benar. Untuk multiple_choice berikan 4 opsi dan minimal satu jawaban benar. Untuk short_answer kosongkan options dan isi accepted_answers. Untuk matching kosongkan options dan accepted_answers, lalu isi matching_pairs dengan 2–5 objek left/right serta matching_distractors dengan 0–2 pilihan kanan pengecoh. Untuk category_matrix kosongkan options, isi matrix_columns dengan 2–4 label kategori, lalu isi matrix_rows dengan 2–6 pernyataan dan correct_column_index berbasis indeks mulai dari 0. Sertakan pembahasan yang merujuk isi cerita. Jangan membuat pertanyaan yang membutuhkan pengetahuan di luar cerita.

{$blueprintDirection}

{$duplicateDirection}

Kembalikan JSON saja dengan struktur:
{"title":"Judul cerita","story_paragraphs":["Paragraf pertama","Paragraf berikutnya"],"questions":[{"competency_code":"KODE_DARI_DAFTAR","type":"single_choice","title":"Judul internal soal","prompt":"Pertanyaan","explanation":"Pembahasan","difficulty":1,"cognitive_level":"menemukan informasi","options":[{"content":"Pilihan","is_correct":true}],"accepted_answers":[],"matching_pairs":[],"matching_distractors":[],"matrix_columns":[],"matrix_rows":[]}]}

Daftar kompetensi yang boleh dipilih:
{$competencyJson}
PROMPT;
    }

    private function directPrompt(string $exampleQuestion, int $questionCount, array $competencies, string $questionStyle, string $answerFormat, bool $useIllustration, array $questionBlueprints, array $recentQuestions, string $variationStrategy, string $illustrationProfileDirection, string $illustrationMode): string
    {
        $competencyJson = json_encode($competencies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $exampleDirection = trim($exampleQuestion) !== ''
            ? "Gunakan contoh soal berikut sebagai pola, lalu buat variasi baru dengan angka, objek, atau konteks berbeda tanpa menyalin persis:\n{$exampleQuestion}"
            : 'Tidak ada contoh soal. Susun soal baru langsung dari kompetensi yang diberikan.';
        $styleDirection = $questionStyle === 'reasoning'
            ? 'Jenis soal: PENALARAN. Buat soal yang menuntut analisis, hubungan antar informasi, strategi, atau perhitungan bertahap; hindari sekadar hafalan dan hitung satu langkah.'
            : 'Jenis soal: LANGSUNG. Buat pertanyaan ringkas dan fokus untuk mengukur penguasaan konsep atau prosedur secara langsung.';
        $illustrationDirection = $useIllustration
            ? 'Gunakan satu ilustrasi visual bersama untuk seluruh soal. Isi visual_description dengan deskripsi gambar yang presisi, termasuk jumlah objek, posisi, bentuk, ukuran, atau data visual yang dibutuhkan. Semua soal harus konsisten dengan ilustrasi tersebut dan tidak boleh membocorkan jawaban.'
            : 'Soal tidak memakai ilustrasi. Isi visual_description dengan string kosong.';
        $visualSpecDirection = ! $useIllustration
            ? 'Isi visual_spec dengan null. Semua informasi yang dibutuhkan untuk menjawab wajib tertulis langsung pada stimulus atau pertanyaan; jangan membuat soal yang bergantung pada gambar, diagram, grafik, atau tabel yang tidak ditampilkan.'
            : ($illustrationMode === 'pro'
                ? 'Mode ilustrasi PRO memakai API gambar. Isi visual_spec dengan null. Buat visual_description yang lengkap dan hindari tulisan, rumus, proses hitung, hasil turunan, kunci, atau penanda opsi benar pada gambar.'
                : <<<DIRECTION
{$illustrationProfileDirection}

Mode ilustrasi LITE memakai SVG lokal. Untuk diagram Matematika, visual_spec WAJIB terstruktur agar sistem menggambar secara presisi. Pilih tepat satu format berikut:
- geometry_2d: shape square/rectangle/triangle/circle/trapezoid/parallelogram/rhombus/kite/regular_polygon, unit, dimensions.
- fraction_models: items berisi shape circle/rectangle, total_parts, shaded_parts.
- object_groups: groups dan objects_per_group.
- spatial_cubes: cubes berisi koordinat integer x,y,z untuk setiap kubus.
- solid_3d: shape cube dengan dimensions.side, atau rectangular_prism dengan length,width,height, serta unit.
- measurement: kind ruler/liquid/mass, value, maximum, unit. Value hanya mengatur posisi objek/jarum/cairan; jangan tuliskan hasil bacaan sebagai jawaban.
- clock: hour dan minute; gambar tidak menuliskan waktu digital.
- angle: degrees untuk menentukan arah garis; gambar tidak menuliskan besar sudut.
- data_chart: style bar/pictogram/table, title, items berisi label dan value; piktogram juga wajib legend_value dan unit.
- route: points berisi label dan distance_from_previous (mulai titik kedua), serta unit.
Masukkan hanya data mentah yang disebut dalam soal. Jangan menaruh rumus, proses hitung, hasil turunan, kunci, atau penanda opsi benar di visual. Untuk visual nonmatematika isi visual_spec dengan null.
DIRECTION);
        $answerFormatDirection = match ($answerFormat) {
            'single_choice' => 'FORMAT JAWABAN WAJIB: Semua soal harus bertipe single_choice dengan 4 opsi dan tepat 1 jawaban benar.',
            'multiple_choice' => 'FORMAT JAWABAN WAJIB: Semua soal harus bertipe multiple_choice (MCMA) dengan 4 opsi dan minimal 2 jawaban benar. Pertanyaan harus memerintahkan siswa memilih semua jawaban yang benar.',
            'true_false' => 'FORMAT JAWABAN WAJIB: Semua soal harus bertipe category_matrix. Gunakan tepat dua matrix_columns dalam urutan ["Benar", "Salah"] dan tepat 3 pernyataan pada matrix_rows.',
            'mixed' => 'FORMAT JAWABAN WAJIB: Campurkan hanya single_choice, multiple_choice, dan category_matrix. Gunakan minimal 2 tipe berbeda dalam paket. Untuk category_matrix gunakan tepat dua kolom ["Benar", "Salah"] dan tepat 3 pernyataan. Untuk multiple_choice gunakan minimal 2 jawaban benar.',
            default => 'FORMAT JAWABAN WAJIB: Semua soal harus bertipe single_choice.',
        };
        $blueprintDirection = $this->questionBlueprintDirection($questionBlueprints);
        $duplicateDirection = $this->duplicateDirection($recentQuestions, $variationStrategy);

        return <<<PROMPT
Anda membantu guru membuat soal Try Out Adaptif berbahasa Indonesia.

Buat tepat {$questionCount} variasi soal. Soal harus mengukur kompetensi yang diberikan. Jangan memaksakan cerita panjang. Gunakan stimulus singkat hanya jika memang dibutuhkan; jika tidak, isi stimulus dengan string kosong. Untuk Matematika, pastikan angka, operasi, satuan, kunci, dan pembahasan konsisten serta dapat dihitung dengan jelas.

{$exampleDirection}

{$styleDirection}

{$answerFormatDirection}

{$illustrationDirection}

{$visualSpecDirection}

{$blueprintDirection}

{$duplicateDirection}

Gunakan variasi tingkat kesulitan dan proses kognitif. Soal boleh berbentuk single_choice, multiple_choice, short_answer, matching, atau category_matrix. Untuk single_choice berikan 4 opsi dengan tepat satu jawaban benar. Untuk multiple_choice berikan 4 opsi dan minimal satu jawaban benar. Untuk short_answer kosongkan options dan isi accepted_answers. Untuk matching kosongkan options dan accepted_answers, lalu isi matching_pairs dengan 2–5 objek left/right serta matching_distractors dengan 0–2 pilihan kanan pengecoh. Untuk category_matrix kosongkan options, isi matrix_columns dengan 2–4 label kategori, lalu isi matrix_rows dengan 2–6 pernyataan dan correct_column_index berbasis indeks mulai dari 0.

Kembalikan JSON saja dengan struktur:
{"title":"Judul paket","visual_description":"Deskripsi ilustrasi atau string kosong","visual_spec":{"type":"geometry_2d","shape":"trapezoid","unit":"cm","dimensions":{"top_base":10,"bottom_base":20,"height":8}},"story_paragraphs":[],"questions":[{"competency_code":"KODE_DARI_DAFTAR","type":"single_choice","title":"Judul internal soal","stimulus":"Stimulus singkat atau kosong","prompt":"Pertanyaan","explanation":"Pembahasan langkah demi langkah","difficulty":1,"cognitive_level":"penerapan","options":[{"content":"Pilihan","is_correct":true}],"accepted_answers":[],"matching_pairs":[],"matching_distractors":[],"matrix_columns":[],"matrix_rows":[]}]}

Kompetensi atau subkompetensi yang wajib digunakan:
{$competencyJson}
PROMPT;
    }

    private function questionBlueprintDirection(array $questionBlueprints): string
    {
        if ($questionBlueprints === []) {
            return 'Tidak ada tipe soal khusus yang dipilih. Ikuti kompetensi secara umum.';
        }

        $blueprintJson = json_encode($questionBlueprints, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $isBundle = collect($questionBlueprints)->every(fn (array $blueprint): bool => isset($blueprint['answer_format'], $blueprint['cognitive_level_label']));
        if ($isBundle) {
            return <<<DIRECTION
Buat setiap soal tepat sesuai slot bundle berikut dan urutannya tidak boleh diubah:
{$blueprintJson}

Aturan format slot:
- answer_format=single_choice: type=single_choice, 4 opsi, tepat 1 jawaban benar.
- answer_format=true_false: type=category_matrix, matrix_columns tepat ["Benar","Salah"], dan tepat 3 pernyataan yang semuanya wajib dijawab.
- answer_format=multiple_choice: type=multiple_choice, 4 opsi, minimal 2 jawaban benar, dan perintah memilih semua jawaban benar.
- cognitive_level harus mengukur tingkat yang tertulis pada cognitive_level_label, bukan hanya menyalin labelnya.
- Ketiga soal wajib menggunakan cerita yang sama, tetapi mengukur fokus tipe soal yang berbeda sesuai name dan description setiap slot.
DIRECTION;
        }

        return "Gunakan tipe soal berikut secara bergiliran sesuai urutan untuk setiap soal. Isi pertanyaan harus benar-benar mengukur fokus tipe soal tersebut:\n{$blueprintJson}";
    }

    private function recentQuestionExamples(AiGeneration $generation, Collection $competencies): array
    {
        return Question::query()
            ->whereIn('competency_id', $competencies->pluck('id'))
            ->where('status', '!=', QuestionStatus::Archived)
            ->latest('id')
            ->limit(20)
            ->pluck('prompt')
            ->filter(fn (?string $prompt): bool => filled($prompt))
            ->values()
            ->all();
    }

    private function variationStrategy(int $generationId): string
    {
        $strategies = [
            'Gunakan objek atau konteks yang belum dipakai dan angka yang berbeda dari soal terdahulu.',
            'Ukur aspek lain dalam kompetensi yang sama; jangan hanya mengganti susunan kata.',
            'Gunakan pertanyaan balik: berikan hasil dan minta siswa menemukan salah satu informasi awal.',
            'Gunakan perbandingan dua objek atau dua kondisi yang berbeda.',
            'Gunakan penerapan sehari-hari dengan data baru dan target pertanyaan yang berbeda.',
            'Gunakan representasi atau bentuk lain yang masih tepat untuk kompetensi tersebut.',
        ];

        return $strategies[$generationId % count($strategies)];
    }

    private function duplicateDirection(array $recentQuestions, string $variationStrategy): string
    {
        $examples = json_encode($recentQuestions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
ATURAN ANTI-DUPLIKASI WAJIB:
- {$variationStrategy}
- Setiap soal dalam output harus memiliki informasi yang ditanyakan dan cara penyelesaian yang berbeda.
- Jangan membuat ulang soal lama hanya dengan mengganti beberapa kata.
- Untuk Matematika, ubah kombinasi bentuk/objek, angka, operasi, atau informasi yang dicari secara bermakna.
- Jika memakai satu ilustrasi bersama, tiap soal tetap harus menanyakan aspek yang berbeda dari ilustrasi itu.

Soal yang sudah ada dan DILARANG dibuat ulang atau diparafrasekan:
{$examples}
PROMPT;
    }

    private function hasDuplicatePrompt(array $questions, array $recentQuestions, QuestionDuplicateDetector $detector): bool
    {
        $prompts = collect($questions)
            ->pluck('prompt')
            ->filter(fn (mixed $prompt): bool => is_string($prompt) && trim($prompt) !== '')
            ->values();

        foreach ($prompts as $index => $prompt) {
            foreach ($recentQuestions as $recentPrompt) {
                if ($detector->similarity($prompt, $recentPrompt) >= 0.82) {
                    return true;
                }
            }

            foreach ($prompts->slice($index + 1) as $otherPrompt) {
                if ($detector->similarity($prompt, $otherPrompt) >= 0.82) {
                    return true;
                }
            }
        }

        return false;
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[-’\'][\p{L}\p{N}]+)*/u', $text, $matches);

        return count($matches[0]);
    }
}
