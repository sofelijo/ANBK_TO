<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Competency;
use App\Models\Question;
use App\Services\AI\AiManager;
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

    public function handle(AiManager $manager): void
    {
        $generation = AiGeneration::with('requester')->findOrFail($this->generationId);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);

        try {
            $competencies = $this->competencies($generation);
            $theme = trim((string) data_get($generation->request_payload, 'theme'));
            $paragraphCount = (int) data_get($generation->request_payload, 'paragraph_count', 3);
            $questionCount = (int) data_get($generation->request_payload, 'question_count', 3);
            $competencyContext = $competencies->map(fn (Competency $competency): array => [
                'code' => $competency->code,
                'domain' => $competency->domain,
                'name' => $competency->name,
                'grade_level' => $competency->grade_level,
            ])->values()->all();

            $response = $manager->provider()->generateJson(
                $this->prompt($theme, $paragraphCount, $questionCount, $competencyContext),
                [
                    'task' => 'story_questions',
                    'theme' => $theme,
                    'paragraph_count' => $paragraphCount,
                    'question_count' => $questionCount,
                    'competencies' => $competencyContext,
                ],
            );

            $data = Validator::make($response->data, [
                'title' => ['required', 'string', 'max:255'],
                'story_paragraphs' => ['required', 'array', "size:{$paragraphCount}"],
                'story_paragraphs.*' => ['required', 'string', 'max:5000'],
                'questions' => ['required', 'array', "size:{$questionCount}"],
                'questions.*.competency_code' => ['required', 'string', Rule::in($competencies->keys()->all())],
                'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
                'questions.*.title' => ['nullable', 'string', 'max:255'],
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

            $this->validateQuestionSet($data['questions'], $competencies);
            $story = implode("\n\n", $data['story_paragraphs']);

            if (mb_strlen($story) > 20000) {
                throw ValidationException::withMessages([
                    'story_paragraphs' => 'Cerita yang dihasilkan terlalu panjang.',
                ]);
            }

            $questionIds = DB::transaction(function () use ($data, $generation, $manager, $response, $theme, $story, $competencies): array {
                $questionIds = [];

                foreach ($data['questions'] as $index => $questionData) {
                    $type = QuestionType::from($questionData['type']);
                    $competency = $competencies[$questionData['competency_code']];
                    $metadata = [
                        'generated_by_ai' => true,
                        'story_generation_id' => $generation->id,
                        'story_theme' => $theme,
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

                    $question = Question::create([
                        'school_id' => $generation->school_id,
                        'author_id' => $generation->requested_by,
                        'story_generation_id' => $generation->id,
                        'competency_id' => $competency->id,
                        'type' => $type,
                        'status' => QuestionStatus::Draft,
                        'title' => ($questionData['title'] ?? null) ?: $data['title'].' - Soal '.($index + 1),
                        'stimulus' => $story,
                        'prompt' => $questionData['prompt'],
                        'explanation' => $questionData['explanation'],
                        'difficulty' => $questionData['difficulty'],
                        'grade_level' => $competency->grade_level,
                        'cognitive_level' => $questionData['cognitive_level'] ?? null,
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
                        'story' => $story,
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

            if (count($questionIds) < 2 || count($questionIds) > 4) {
                throw new RuntimeException('Jumlah soal cerita di luar batas yang diizinkan.');
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
        $competencies = Competency::query()
            ->where(fn ($query) => $query
                ->whereNull('school_id')
                ->orWhere('school_id', $generation->school_id))
            ->get()
            ->sortByDesc(fn (Competency $competency): bool => $competency->school_id === $generation->school_id)
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

    private function prompt(string $theme, int $paragraphCount, int $questionCount, array $competencies): string
    {
        $competencyJson = json_encode($competencies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Anda membantu guru membuat paket soal cerita try out ANBK berbahasa Indonesia.

Buat satu cerita berdasarkan tema "{$theme}" dengan tepat {$paragraphCount} paragraf, lalu buat tepat {$questionCount} soal yang semuanya hanya menggunakan cerita tersebut sebagai stimulus. Kembalikan setiap paragraf sebagai satu elemen story_paragraphs tanpa nomor paragraf. Pilih kompetensi paling relevan hanya dari daftar yang diberikan. Semua soal harus berada pada satu jenjang kelas yang sama. Cerita harus sesuai usia jenjang tersebut, faktual, aman untuk anak, tidak bias, dan memuat seluruh informasi yang diperlukan untuk menjawab soal.

Gunakan variasi tingkat kesulitan dan proses kognitif. Soal boleh berbentuk single_choice, multiple_choice, short_answer, matching, atau category_matrix. Untuk single_choice berikan 4 opsi dengan tepat satu jawaban benar. Untuk multiple_choice berikan 4 opsi dan minimal satu jawaban benar. Untuk short_answer kosongkan options dan isi accepted_answers. Untuk matching kosongkan options dan accepted_answers, lalu isi matching_pairs dengan 2–5 objek left/right serta matching_distractors dengan 0–2 pilihan kanan pengecoh. Untuk category_matrix kosongkan options, isi matrix_columns dengan 2–4 label kategori, lalu isi matrix_rows dengan 2–6 pernyataan dan correct_column_index berbasis indeks mulai dari 0. Sertakan pembahasan yang merujuk isi cerita. Jangan membuat pertanyaan yang membutuhkan pengetahuan di luar cerita.

Kembalikan JSON saja dengan struktur:
{"title":"Judul cerita","story_paragraphs":["Paragraf pertama","Paragraf berikutnya"],"questions":[{"competency_code":"KODE_DARI_DAFTAR","type":"single_choice","title":"Judul internal soal","prompt":"Pertanyaan","explanation":"Pembahasan","difficulty":1,"cognitive_level":"menemukan informasi","options":[{"content":"Pilihan","is_correct":true}],"accepted_answers":[],"matching_pairs":[],"matching_distractors":[],"matrix_columns":[],"matrix_rows":[]}]}

Daftar kompetensi yang boleh dipilih:
{$competencyJson}
PROMPT;
    }
}
