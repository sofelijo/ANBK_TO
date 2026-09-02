<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Services\AI\AiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateSchoolAssessmentAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $generationId) {}

    public function handle(AiManager $manager): void
    {
        $generation = AiGeneration::query()->findOrFail($this->generationId);
        abort_unless($generation->type === AiGenerationType::SchoolAssessmentAnalysis, 409);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);

        try {
            $context = $generation->request_payload;
            $response = $manager->provider()->generateJson(
                $this->prompt($context),
                ['task' => 'school_assessment_analysis', ...$context],
            );
            $result = $this->validateResult(
                $this->normalizeResult($response->data, $context),
                $context,
            );

            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => $result,
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

    private function validateResult(array $result, array $context): array
    {
        $competencyCodes = collect($context['weak_competencies'])->pluck('code')->all();
        $difficultQuestions = collect($context['difficult_questions']);
        $questionIds = $difficultQuestions->pluck('question_id')->all();
        $practiceCompetencyCodes = $difficultQuestions->pluck('competency_code')->unique()->all();
        $validated = Validator::make($result, [
            'summary' => ['required', 'string', 'max:2000'],
            'teacher_recommendations' => ['required', 'array', 'min:1', 'max:3'],
            'teacher_recommendations.*.competency_code' => ['required', 'string', 'distinct', Rule::in($competencyCodes)],
            'teacher_recommendations.*.finding' => ['required', 'string', 'max:1500'],
            'teacher_recommendations.*.action' => ['required', 'string', 'max:1500'],
            'teacher_recommendations.*.suggested_activity' => ['required', 'string', 'max:1500'],
            'practice_questions' => ['required', 'array', 'min:3', 'max:5'],
            'practice_questions.*.source_question_id' => ['required', 'integer', Rule::in($questionIds)],
            'practice_questions.*.competency_code' => ['required', 'string', Rule::in($practiceCompetencyCodes)],
            'practice_questions.*.prompt' => ['required', 'string', 'max:5000'],
            'practice_questions.*.difficulty' => ['required', 'integer', 'between:1,3'],
            'practice_questions.*.options' => ['required', 'array', 'size:4'],
            'practice_questions.*.options.*.content' => ['required', 'string', 'max:2000'],
            'practice_questions.*.options.*.is_correct' => ['required', 'boolean'],
            'practice_questions.*.explanation' => ['required', 'string', 'max:3000'],
        ])->validate();

        foreach ($validated['practice_questions'] as $question) {
            if (collect($question['options'])->where('is_correct', true)->count() !== 1) {
                throw ValidationException::withMessages([
                    'practice_questions' => 'Setiap soal latihan AI harus memiliki tepat satu jawaban benar.',
                ]);
            }

            $source = $difficultQuestions->firstWhere('question_id', $question['source_question_id']);
            if ($source['competency_code'] !== $question['competency_code']) {
                throw ValidationException::withMessages([
                    'practice_questions' => 'Kompetensi soal latihan AI harus sesuai dengan soal sumbernya.',
                ]);
            }
        }

        return $validated;
    }

    private function normalizeResult(array $result, array $context): array
    {
        $providedRecommendations = collect($result['teacher_recommendations'] ?? [])
            ->filter(fn (mixed $recommendation): bool => is_array($recommendation))
            ->filter(fn (array $recommendation): bool => is_string($recommendation['competency_code'] ?? null))
            ->unique('competency_code')
            ->keyBy('competency_code');

        $result['teacher_recommendations'] = collect($context['weak_competencies'])
            ->map(function (array $competency) use ($providedRecommendations): array {
                $recommendation = $providedRecommendations->get($competency['code'], []);

                return [
                    'competency_code' => $competency['code'],
                    'finding' => $this->textOrFallback(
                        $recommendation['finding'] ?? null,
                        "Sebanyak {$competency['incorrect_percentage']}% dari {$competency['response_count']} respons pada {$competency['name']} belum tepat.",
                    ),
                    'action' => $this->textOrFallback(
                        $recommendation['action'] ?? null,
                        "Ulangi konsep inti {$competency['name']} melalui contoh bertahap dan diskusi alasan pada setiap langkah.",
                    ),
                    'suggested_activity' => $this->textOrFallback(
                        $recommendation['suggested_activity'] ?? null,
                        'Gunakan latihan berpasangan: satu siswa menyelesaikan soal dan pasangannya memeriksa serta menjelaskan letak kekeliruan.',
                    ),
                ];
            })
            ->values()
            ->all();

        return $result;
    }

    private function textOrFallback(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private function prompt(array $context): string
    {
        $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Anda adalah analis pembelajaran untuk TOA (Try Out Adaptif). Analisis hasil agregat satu sekolah di bawah ini dan bantu guru menentukan tindak lanjut.

Aturan penting:
1. Gunakan hanya bukti pada statistik. Nyatakan kelemahan sebagai indikasi kelompok, bukan label atau diagnosis permanen terhadap siswa.
2. Jangan menyebut atau menebak identitas siswa.
3. Buat tepat 1 rekomendasi untuk setiap kompetensi lemah yang tersedia, maksimal 3. Setiap competency_code hanya boleh muncul satu kali dan semua kode kompetensi lemah harus terwakili. Finding harus menyebut bukti persentase kesalahan. Action dan suggested_activity harus konkret dan dapat dilakukan guru di kelas.
4. Buat 3 sampai 5 soal latihan pilihan tunggal baru berdasarkan konsep soal dengan kesalahan tertinggi. Jangan menyalin kalimat atau angka soal sumber secara identik. Sesuaikan kelas dan mata pelajaran.
5. Setiap soal latihan harus memiliki tepat 4 opsi dan tepat 1 opsi benar. Sertakan pembahasan ringkas. source_question_id dan competency_code wajib berasal dari data input.
6. Semua teks menggunakan bahasa Indonesia yang jelas dan siap dibaca guru.

Kembalikan JSON saja dengan struktur berikut:
{
  "summary": "ringkasan temuan utama dan prioritas",
  "teacher_recommendations": [
    {
      "competency_code": "kode dari input",
      "finding": "temuan berbasis data",
      "action": "tindakan mengajar",
      "suggested_activity": "aktivitas kelas"
    }
  ],
  "practice_questions": [
    {
      "source_question_id": 1,
      "competency_code": "kode dari input",
      "prompt": "soal baru",
      "difficulty": 1,
      "options": [
        {"content": "opsi", "is_correct": true},
        {"content": "opsi", "is_correct": false},
        {"content": "opsi", "is_correct": false},
        {"content": "opsi", "is_correct": false}
      ],
      "explanation": "pembahasan"
    }
  ]
}

Data agregat tanpa identitas siswa:
{$contextJson}
PROMPT;
    }
}
