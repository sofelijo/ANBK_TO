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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
            ->where(fn ($query) => $query->whereNull('school_id')->orWhere('school_id', $request->user()->school_id))
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

        return Inertia::render('Questions/ManualBundleCreate', [
            'subject' => $subject->only(['id', 'code', 'name']),
            'competencies' => $competencies,
            'bundleDefaults' => $configuration->forUser($request->user()),
        ]);
    }

    public function store(
        Request $request,
        IndonesianBundleConfiguration $configuration,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'competency_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'story' => ['required', 'string', 'max:20000'],
            'max_words' => ['nullable', 'integer', 'between:50,1000'],
            'bundle_slots' => ['required', 'array', 'size:3'],
            'bundle_slots.*.question_blueprint_id' => ['required', 'integer', 'distinct'],
            'bundle_slots.*.answer_format' => ['required', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'bundle_slots.*.cognitive_level' => ['required', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
            'questions' => ['required', 'array', 'size:3'],
            'questions.*.prompt' => ['required', 'string', 'max:10000'],
            'questions.*.explanation' => ['nullable', 'string', 'max:10000'],
            'questions.*.options' => ['array'],
            'questions.*.options.*.content' => ['nullable', 'string', 'max:3000'],
            'questions.*.options.*.is_correct' => ['boolean'],
            'questions.*.statements' => ['array'],
            'questions.*.statements.*.content' => ['nullable', 'string', 'max:1000'],
            'questions.*.statements.*.is_true' => ['boolean'],
        ]);
        $data['max_words'] = (int) ($data['max_words'] ?? 200);
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
            ->where(fn ($query) => $query->whereNull('school_id')->orWhere('school_id', $request->user()->school_id))
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
        $this->validateAnswers($data['bundle_slots'], $data['questions']);

        $generation = DB::transaction(function () use ($data, $request, $subject, $competency, $configuration): AiGeneration {
            $paragraphCount = collect(preg_split('/\R{2,}/u', trim($data['story'])))->filter()->count();
            $payload = [
                'source' => 'manual',
                'format' => 'story',
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'competency_id' => $competency->id,
                'competency_code' => $competency->code,
                'competency_name' => $competency->name,
                'theme' => $data['title'],
                'paragraph_count' => max(1, $paragraphCount),
                'max_words' => $data['max_words'],
                'question_count' => 3,
                'use_illustration' => false,
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

            $questionIds = collect($data['bundle_slots'])->values()->map(function (array $slot, int $index) use ($data, $request, $competency, $configuration, $generation): int {
                $answerFormat = $slot['answer_format'];
                $questionData = $data['questions'][$index];
                $metadata = [
                    'created_manually' => true,
                    'story_generation_id' => $generation->id,
                    'generation_format' => 'story',
                    'bundle_answer_format' => $answerFormat,
                    'bundle_cognitive_level' => $slot['cognitive_level'],
                ];

                if ($answerFormat === 'true_false') {
                    $columns = collect(['Benar', 'Salah'])->map(fn (string $label): array => [
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
                    'school_id' => $request->user()->school_id,
                    'author_id' => $request->user()->id,
                    'story_generation_id' => $generation->id,
                    'competency_id' => $competency->id,
                    'question_blueprint_id' => (int) $slot['question_blueprint_id'],
                    'type' => match ($answerFormat) {
                        'single_choice' => QuestionType::SingleChoice,
                        'multiple_choice' => QuestionType::MultipleChoice,
                        'true_false' => QuestionType::CategoryMatrix,
                    },
                    'status' => QuestionStatus::Draft,
                    'title' => $data['title'].' — Soal '.($index + 1),
                    'stimulus' => trim($data['story']),
                    'prompt' => trim($questionData['prompt']),
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
                    foreach ($questionData['options'] as $optionIndex => $option) {
                        $question->options()->create([
                            'label' => chr(65 + $optionIndex),
                            'content' => trim($option['content']),
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
                'story' => trim($data['story']),
                'paragraph_count' => $payload['paragraph_count'],
                'question_count' => 3,
                'question_ids' => $questionIds,
            ]]);

            return $generation;
        });

        $auditLogger->log($request, 'manual_story_bundle.created', $generation, [
            'question_ids' => data_get($generation->result_payload, 'question_ids', []),
            'competency_id' => $competency->id,
        ]);

        return to_route('story-questions.show', $generation)
            ->with('success', 'Bundle manual berhasil disimpan sebagai draft.');
    }

    private function subject(Request $request, int $subjectId): ?Subject
    {
        return Subject::query()
            ->whereKey($subjectId)
            ->where('code', 'BIND')
            ->where(fn ($query) => $query->whereNull('school_id')->orWhere('school_id', $request->user()->school_id))
            ->whereHas('competencies')
            ->first(['id', 'code', 'name']);
    }

    private function validateAnswers(array $slots, array $questions): void
    {
        foreach ($slots as $index => $slot) {
            $question = $questions[$index];
            if ($slot['answer_format'] === 'true_false') {
                $statements = collect($question['statements'] ?? []);
                if ($statements->count() !== 3 || $statements->contains(fn (array $row): bool => trim((string) ($row['content'] ?? '')) === '')) {
                    throw ValidationException::withMessages(["questions.{$index}.statements" => 'Benar/Salah wajib memiliki tepat tiga pernyataan lengkap.']);
                }

                continue;
            }

            $options = collect($question['options'] ?? []);
            $correctCount = $options->where('is_correct', true)->count();
            if ($options->count() !== 4 || $options->contains(fn (array $option): bool => trim((string) ($option['content'] ?? '')) === '')) {
                throw ValidationException::withMessages(["questions.{$index}.options" => 'Empat pilihan jawaban wajib diisi lengkap.']);
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
}
