<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AiGeneration;
use App\Models\Question;
use App\Services\AI\AiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class GenerateQuestionVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $generationId) {}

    public function handle(AiManager $manager): void
    {
        $generation = AiGeneration::with('sourceQuestion.options')->findOrFail($this->generationId);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);

        try {
            $question = $generation->sourceQuestion;
            $source = [
                'title' => $question->title,
                'stimulus' => $question->stimulus,
                'prompt' => $question->prompt,
                'explanation' => $question->explanation,
                'difficulty' => $question->difficulty,
                'cognitive_level' => $question->cognitive_level,
                'accepted_answers' => $question->metadata['accepted_answers'] ?? [],
                'options' => $question->options->map(fn ($option): array => [
                    'content' => $option->content,
                    'is_correct' => $option->is_correct,
                ])->all(),
            ];

            $response = $manager->provider()->generateJson(
                $this->prompt($question, $source),
                ['task' => 'question_variants', 'source' => $source],
            );

            $variants = Validator::make($response->data, [
                'variants' => ['required', 'array', 'size:3'],
                'variants.*.title' => ['nullable', 'string', 'max:255'],
                'variants.*.stimulus' => ['nullable', 'string', 'max:20000'],
                'variants.*.prompt' => ['required', 'string', 'max:10000'],
                'variants.*.explanation' => ['nullable', 'string', 'max:10000'],
                'variants.*.difficulty' => ['required', 'integer', 'between:1,3'],
                'variants.*.cognitive_level' => ['nullable', 'string', 'max:100'],
                'variants.*.options' => ['array'],
                'variants.*.options.*.content' => ['required', 'string', 'max:3000'],
                'variants.*.options.*.is_correct' => ['required', 'boolean'],
                'variants.*.accepted_answers' => ['array'],
                'variants.*.accepted_answers.*' => ['string', 'max:500'],
            ])->validate()['variants'];

            $questionIds = DB::transaction(function () use ($variants, $question): array {
                $questionIds = [];

                foreach ($variants as $variant) {
                    $this->validateCorrectAnswers($question, $variant);
                    $created = Question::create([
                        'school_id' => $question->school_id,
                        'author_id' => $question->author_id,
                        'parent_id' => $question->id,
                        'competency_id' => $question->competency_id,
                        'type' => $question->type,
                        'status' => QuestionStatus::Draft,
                        'title' => $variant['title'] ?? null,
                        'stimulus' => $variant['stimulus'] ?? null,
                        'prompt' => $variant['prompt'],
                        'explanation' => $variant['explanation'] ?? null,
                        'difficulty' => $variant['difficulty'],
                        'grade_level' => $question->grade_level,
                        'cognitive_level' => $variant['cognitive_level'] ?? null,
                        'metadata' => $question->type === QuestionType::ShortAnswer
                            ? ['accepted_answers' => $variant['accepted_answers']]
                            : ['generated_by_ai' => true],
                    ]);

                    foreach ($variant['options'] ?? [] as $index => $option) {
                        $created->options()->create([
                            'label' => chr(65 + $index),
                            'content' => $option['content'],
                            'is_correct' => $option['is_correct'],
                            'position' => $index + 1,
                        ]);
                    }

                    $questionIds[] = $created->id;
                }

                return $questionIds;
            });

            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => ['question_ids' => $questionIds],
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_microusd' => $manager->costMicrousd($response),
            ]);
        } catch (Throwable $exception) {
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            throw $exception;
        }
    }

    private function validateCorrectAnswers(Question $source, array $variant): void
    {
        if ($source->type === QuestionType::ShortAnswer) {
            Validator::make($variant, ['accepted_answers' => ['required', 'array', 'min:1']])->validate();

            return;
        }

        $correctCount = collect($variant['options'] ?? [])->where('is_correct', true)->count();
        $requiredCount = $source->type === QuestionType::SingleChoice ? 1 : null;

        Validator::make(['correct_count' => $correctCount, 'option_count' => count($variant['options'] ?? [])], [
            'option_count' => ['integer', 'min:2'],
            'correct_count' => $requiredCount === 1 ? ['integer', 'in:1'] : ['integer', 'min:1'],
        ])->validate();
    }

    private function prompt(Question $question, array $source): string
    {
        $sourceJson = json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Anda membantu guru membuat bank soal try out ANBK. Buat tepat 3 variasi dari soal sumber di bawah ini.

Pertahankan kompetensi, bentuk soal, jenjang kelas {$question->grade_level}, dan tingkat kognitif. Ubah konteks, angka, tokoh, atau distraktor secara bermakna; jangan sekadar mengganti beberapa kata. Pastikan soal tidak ambigu, kunci benar, semua informasi yang diperlukan tersedia, dan bahasa sesuai pelajar Indonesia.

Kembalikan JSON saja dengan struktur:
{"variants":[{"title":null,"stimulus":null,"prompt":"...","explanation":"...","difficulty":1,"cognitive_level":null,"options":[{"content":"...","is_correct":true}],"accepted_answers":[]}]}

Untuk isian singkat isi accepted_answers dan kosongkan options. Untuk pilihan tunggal harus tepat satu opsi benar. Untuk pilihan kompleks boleh lebih dari satu opsi benar.

Soal sumber:
{$sourceJson}
PROMPT;
    }
}
