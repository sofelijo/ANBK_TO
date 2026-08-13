<?php

namespace App\Services\AI;

class FakeAiProvider implements AiProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'deterministic-local';
    }

    public function generateJson(string $prompt, array $context = []): AiResponse
    {
        return match ($context['task'] ?? null) {
            'question_variants' => new AiResponse($this->questionVariants($context), 0, 0),
            'story_questions' => new AiResponse($this->storyQuestions($context), 0, 0),
            'question_validation' => new AiResponse($this->questionValidation($context), 0, 0),
            'attempt_summary' => new AiResponse($this->attemptSummary($context), 0, 0),
            'student_chat' => new AiResponse($this->studentChat($context), 0, 0),
            default => new AiResponse([]),
        };
    }

    private function storyQuestions(array $context): array
    {
        if (($context['format'] ?? 'story') === 'direct') {
            return $this->directQuestions($context);
        }

        $theme = trim((string) ($context['theme'] ?? 'kegiatan sekolah'));
        $paragraphCount = (int) ($context['paragraph_count'] ?? 3);
        $questionCount = (int) ($context['question_count'] ?? 3);
        $competencyCode = $context['competencies'][0]['code'];
        $title = 'Cerita '.mb_convert_case($theme, MB_CASE_TITLE, 'UTF-8');
        $paragraphs = [
            "Pada hari Senin, siswa mengikuti kegiatan bertema {$theme}. Mereka bekerja dalam tiga kelompok, mempresentasikan temuan setelah selesai, lalu menerima apresiasi guru karena berhasil menyelesaikan kegiatan.",
            'Para siswa bekerja dalam tiga kelompok dan mencatat hasil kegiatan bersama dengan tertib.',
            'Setelah selesai, setiap kelompok mempresentasikan temuannya di depan kelas.',
            'Guru memberikan apresiasi dan mengajak siswa menyimpulkan manfaat kegiatan tersebut.',
            'Sebelum pulang, para siswa merapikan alat dan memastikan ruang kelas kembali bersih.',
        ];

        $questions = [
            ['prompt' => 'Kapan kegiatan tersebut dilaksanakan?', 'correct' => 'Hari Senin', 'wrong' => ['Hari Selasa', 'Hari Rabu', 'Hari Jumat'], 'difficulty' => 1],
            ['prompt' => 'Berapa kelompok yang mengikuti kegiatan?', 'correct' => 'Tiga kelompok', 'wrong' => ['Dua kelompok', 'Empat kelompok', 'Lima kelompok'], 'difficulty' => 1],
            ['prompt' => 'Apa yang dilakukan setiap kelompok setelah kegiatan selesai?', 'correct' => 'Mempresentasikan temuan', 'wrong' => ['Langsung pulang', 'Menghapus catatan', 'Mengganti tema'], 'difficulty' => 2],
            ['prompt' => 'Mengapa guru memberikan apresiasi kepada siswa?', 'correct' => 'Karena siswa menyelesaikan kegiatan', 'wrong' => ['Karena kegiatan dibatalkan', 'Karena siswa datang terlambat', 'Karena kelas belum dirapikan'], 'difficulty' => 2],
        ];

        return [
            'title' => $title,
            'story_paragraphs' => array_slice($paragraphs, 0, $paragraphCount),
            'questions' => collect(array_slice($questions, 0, $questionCount))->map(fn (array $question, int $index): array => [
                'competency_code' => $competencyCode,
                'type' => 'single_choice',
                'title' => "{$title} - Soal ".($index + 1),
                'prompt' => $question['prompt'],
                'explanation' => 'Jawaban ditemukan dengan membaca informasi pada cerita.',
                'difficulty' => $question['difficulty'],
                'cognitive_level' => 'menemukan informasi',
                'options' => collect([$question['correct'], ...$question['wrong']])->map(
                    fn (string $content, int $optionIndex): array => [
                        'content' => $content,
                        'is_correct' => $optionIndex === 0,
                    ],
                )->all(),
                'accepted_answers' => [],
            ])->all(),
        ];
    }

    private function directQuestions(array $context): array
    {
        $topic = trim((string) ($context['theme'] ?? 'operasi hitung'));
        $questionCount = (int) ($context['question_count'] ?? 3);
        $answerFormat = (string) ($context['answer_format'] ?? 'single_choice');
        $competencyCode = $context['competencies'][0]['code'];
        $questions = [
            ['prompt' => 'Hasil dari 24 + 18 adalah …', 'correct' => '42', 'wrong' => ['32', '40', '44']],
            ['prompt' => 'Hasil dari 7 × 6 adalah …', 'correct' => '42', 'wrong' => ['36', '40', '48']],
            ['prompt' => 'Hasil dari 56 ÷ 8 adalah …', 'correct' => '7', 'wrong' => ['6', '8', '9']],
            ['prompt' => 'Bilangan yang nilainya paling besar adalah …', 'correct' => '0,75', 'wrong' => ['0,5', '0,25', '0,1']],
            ['prompt' => 'Hasil dari 125 − 48 adalah …', 'correct' => '77', 'wrong' => ['67', '73', '83']],
            ['prompt' => 'Nilai dari 3 × (8 + 2) adalah …', 'correct' => '30', 'wrong' => ['26', '28', '32']],
            ['prompt' => 'Pecahan yang senilai dengan 1/2 adalah …', 'correct' => '2/4', 'wrong' => ['1/3', '2/3', '3/4']],
            ['prompt' => 'Keliling persegi dengan sisi 6 cm adalah …', 'correct' => '24 cm', 'wrong' => ['12 cm', '18 cm', '36 cm']],
            ['prompt' => 'Rata-rata dari 6, 8, dan 10 adalah …', 'correct' => '8', 'wrong' => ['7', '9', '10']],
        ];

        return [
            'title' => 'Latihan '.mb_convert_case($topic, MB_CASE_TITLE, 'UTF-8'),
            'visual_description' => ($context['use_illustration'] ?? false)
                ? 'Tiga kelompok apel tersusun rapi di atas meja, masing-masing kelompok berisi empat apel merah.'
                : '',
            'visual_spec' => ($context['use_illustration'] ?? false) ? [
                'type' => 'object_groups',
                'groups' => 3,
                'objects_per_group' => 4,
            ] : null,
            'story_paragraphs' => [],
            'questions' => collect(array_slice($questions, 0, $questionCount))->map(
                fn (array $question, int $index): array => $this->directQuestionPayload(
                    $question,
                    $index,
                    $competencyCode,
                    $answerFormat === 'mixed'
                        ? ['single_choice', 'multiple_choice', 'true_false'][$index % 3]
                        : $answerFormat,
                ),
            )->all(),
        ];
    }

    private function directQuestionPayload(array $question, int $index, string $competencyCode, string $answerFormat): array
    {
        $base = [
            'competency_code' => $competencyCode,
            'title' => 'Soal AI '.($index + 1),
            'stimulus' => '',
            'explanation' => 'Hitung secara bertahap untuk memperoleh jawaban yang benar.',
            'difficulty' => min(3, $index + 1),
            'cognitive_level' => 'penerapan',
            'accepted_answers' => [],
            'matching_pairs' => [],
            'matching_distractors' => [],
            'matrix_columns' => [],
            'matrix_rows' => [],
        ];

        if ($answerFormat === 'true_false') {
            return [
                ...$base,
                'type' => 'category_matrix',
                'prompt' => 'Tentukan Benar atau Salah untuk setiap pernyataan berikut.',
                'options' => [],
                'matrix_columns' => ['Benar', 'Salah'],
                'matrix_rows' => [
                    ['statement' => "Hasil perhitungan adalah {$question['correct']}.", 'correct_column_index' => 0],
                    ['statement' => "Hasil perhitungan adalah {$question['wrong'][0]}.", 'correct_column_index' => 1],
                ],
            ];
        }

        $multipleChoice = $answerFormat === 'multiple_choice';

        return [
            ...$base,
            'type' => $multipleChoice ? 'multiple_choice' : 'single_choice',
            'prompt' => $multipleChoice
                ? 'Pilih semua jawaban yang dianggap benar untuk perhitungan berikut: '.$question['prompt']
                : $question['prompt'],
            'options' => collect([$question['correct'], ...$question['wrong']])->map(
                fn (string $content, int $optionIndex): array => [
                    'content' => $content,
                    'is_correct' => $optionIndex === 0 || ($multipleChoice && $optionIndex === 1),
                ],
            )->all(),
        ];
    }

    private function questionVariants(array $context): array
    {
        $source = $context['source'];

        return [
            'variants' => collect(range(1, 3))->map(function (int $number) use ($source): array {
                $options = collect($source['options'] ?? [])->map(fn (array $option): array => [
                    'content' => $option['content'],
                    'is_correct' => $option['is_correct'],
                ])->all();

                return [
                    'title' => trim(($source['title'] ?: 'Variasi soal').' '.$number),
                    'stimulus' => $source['stimulus'],
                    'prompt' => $source['prompt']." (variasi {$number})",
                    'explanation' => $source['explanation'],
                    'difficulty' => $source['difficulty'],
                    'cognitive_level' => $source['cognitive_level'],
                    'options' => $options,
                    'accepted_answers' => $source['accepted_answers'] ?? [],
                ];
            })->all(),
        ];
    }

    private function attemptSummary(array $context): array
    {
        $results = collect($context['results']);
        $strongest = $results->sortByDesc('percentage')->first();
        $weakest = $results->sortBy('percentage')->first();

        $summary = 'Hasilmu sudah tercatat.';
        if ($strongest && $weakest) {
            $summary = "Kekuatanmu terlihat pada {$strongest['name']} ({$strongest['percentage']}%). Fokus latihan berikutnya adalah {$weakest['name']} ({$weakest['percentage']}%). Kerjakan soal rekomendasi secara bertahap dan periksa kembali alasan setiap jawaban.";
        }

        return ['summary' => $summary];
    }

    private function studentChat(array $context): array
    {
        $latestMessage = collect($context['recent_messages'] ?? [])->last();
        $question = trim((string) data_get($latestMessage, 'content', ''));

        if (str_contains(mb_strtolower($question), 'contoh soal latihan baru')) {
            return [
                'reply' => 'Baik, kita berlatih tanpa melihat jawabannya dulu. Contoh soal: Rani membaca sebuah paragraf tentang warga yang bekerja sama membersihkan selokan sebelum musim hujan. Apa alasan utama warga melakukan kegiatan tersebut? Tuliskan jawabanmu beserta alasannya.',
            ];
        }

        return [
            'reply' => $question === ''
                ? 'Apa yang ingin kamu pelajari hari ini? Kita bisa membuat langkah kecil yang mudah dilakukan.'
                : "Aku memahami pertanyaanmu tentang: {$question}. Coba jelaskan bagian yang paling membingungkan, lalu kita pecah menjadi langkah-langkah kecil dan berlatih secara bertahap.",
        ];
    }

    private function questionValidation(array $context): array
    {
        $question = $context['question'];
        $issues = [];

        if (mb_strlen(trim((string) $question['prompt'])) < 10) {
            $issues[] = [
                'severity' => 'error',
                'field' => 'prompt',
                'message' => 'Pertanyaan terlalu singkat untuk dinilai dengan jelas.',
            ];
        }

        $optionContents = collect($question['options'])->pluck('content')->map(
            fn (string $content): string => mb_strtolower(trim($content)),
        );
        if ($optionContents->count() !== $optionContents->unique()->count()) {
            $issues[] = [
                'severity' => 'error',
                'field' => 'options',
                'message' => 'Terdapat pilihan jawaban yang sama.',
            ];
        }

        if (empty($question['explanation'])) {
            $issues[] = [
                'severity' => 'warning',
                'field' => 'explanation',
                'message' => 'Pembahasan belum tersedia.',
            ];
        }

        $hasError = collect($issues)->contains('severity', 'error');

        return [
            'passed' => ! $hasError,
            'score' => max(0, 100 - (collect($issues)->where('severity', 'error')->count() * 30) - (collect($issues)->where('severity', 'warning')->count() * 10)),
            'issues' => $issues,
            'suggestions' => $issues ? ['Perbaiki poin yang ditandai lalu jalankan validasi ulang.'] : ['Soal siap ditinjau akhir oleh guru.'],
        ];
    }
}
