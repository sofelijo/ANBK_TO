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
            'school_assessment_analysis' => new AiResponse($this->schoolAssessmentAnalysis($context), 0, 0),
            default => new AiResponse([]),
        };
    }

    private function schoolAssessmentAnalysis(array $context): array
    {
        $competencies = collect($context['weak_competencies'] ?? [])->values();
        $questions = collect($context['difficult_questions'] ?? [])->values();
        $weakest = $competencies->first();
        $subject = mb_strtolower((string) data_get($context, 'assessment.subject', ''));
        $mathExercises = [
            ['prompt' => 'Hasil dari 240 + 360 adalah …', 'correct' => '600', 'wrong' => ['500', '580', '620'], 'explanation' => 'Jumlahkan nilai ratusan dan puluhan: 240 + 360 = 600.'],
            ['prompt' => 'Nilai 2/5 dari 150 adalah …', 'correct' => '60', 'wrong' => ['30', '50', '75'], 'explanation' => 'Hitung 150 ÷ 5 = 30, kemudian 30 × 2 = 60.'],
            ['prompt' => 'FPB dari 42 dan 56 adalah …', 'correct' => '14', 'wrong' => ['7', '12', '21'], 'explanation' => 'Faktor terbesar yang membagi 42 dan 56 adalah 14.'],
            ['prompt' => 'KPK dari 9 dan 12 adalah …', 'correct' => '36', 'wrong' => ['18', '24', '48'], 'explanation' => 'Kelipatan pertama yang sama dari 9 dan 12 adalah 36.'],
            ['prompt' => 'Jarak 3,2 kilometer sama dengan … meter.', 'correct' => '3.200', 'wrong' => ['320', '3.020', '32.000'], 'explanation' => 'Satu kilometer adalah 1.000 meter, sehingga 3,2 km = 3.200 m.'],
        ];

        return [
            'summary' => $weakest
                ? "Dari {$context['sample_size']} pengerjaan selesai, prioritas penguatan berada pada {$weakest['name']} dengan {$weakest['incorrect_percentage']}% respons belum tepat. Guru disarankan memulai dari konsep dasar, memodelkan strategi penyelesaian, lalu memeriksa pemahaman melalui latihan bertahap."
                : 'Data pengerjaan telah dianalisis. Gunakan latihan bertahap dan pemeriksaan pemahaman untuk menentukan tindak lanjut.',
            'teacher_recommendations' => $competencies->map(fn (array $competency): array => [
                'competency_code' => $competency['code'],
                'finding' => "Sebanyak {$competency['incorrect_percentage']}% dari {$competency['response_count']} respons pada {$competency['name']} belum tepat.",
                'action' => "Ulangi konsep inti {$competency['name']} dengan contoh konkret, lalu minta siswa menjelaskan alasan pada setiap langkah.",
                'suggested_activity' => 'Gunakan latihan berpasangan: satu siswa menyelesaikan soal dan pasangannya memeriksa serta menjelaskan letak kekeliruan.',
            ])->all(),
            'practice_questions' => collect(range(0, 4))->map(function (int $index) use ($questions, $mathExercises, $subject): array {
                $source = $questions[$index % $questions->count()];
                $exercise = str_contains($subject, 'matematika')
                    ? $mathExercises[$index]
                    : $this->genericPracticeExercise($source, $index);

                return [
                    'source_question_id' => $source['question_id'],
                    'competency_code' => $source['competency_code'],
                    'prompt' => $exercise['prompt'],
                    'difficulty' => min(3, max(1, (int) $source['difficulty'])),
                    'options' => collect([$exercise['correct'], ...$exercise['wrong']])->map(
                        fn (string $content, int $optionIndex): array => [
                            'content' => $content,
                            'is_correct' => $optionIndex === 0,
                        ],
                    )->all(),
                    'explanation' => $exercise['explanation'],
                ];
            })->all(),
        ];
    }

    private function genericPracticeExercise(array $source, int $index): array
    {
        $options = collect($source['options'] ?? []);
        $correct = (string) data_get($options->firstWhere('is_correct', true), 'content', 'Jawaban paling tepat');
        $wrong = $options->where('is_correct', false)->pluck('content')->map(fn ($content): string => (string) $content)->take(3);

        while ($wrong->count() < 3) {
            $wrong->push('Pilihan pengecoh '.($wrong->count() + 1));
        }

        return [
            'prompt' => 'Latihan penguatan '.($index + 1).': '.(string) $source['prompt'],
            'correct' => $correct,
            'wrong' => $wrong->all(),
            'explanation' => 'Tinjau kembali konsep pada kompetensi terkait dan cocokkan setiap informasi dengan pilihan jawaban.',
        ];
    }

    private function storyQuestions(array $context): array
    {
        if (($context['format'] ?? 'story') === 'direct') {
            return $this->directQuestions($context);
        }

        $theme = trim((string) ($context['theme'] ?? '')) ?: 'kegiatan sekolah yang menarik';
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
            ['prompt' => 'Kapan kegiatan tersebut dilaksanakan?', 'correct' => 'Hari Senin', 'also_correct' => 'Pada awal pekan', 'wrong' => ['Hari Selasa', 'Hari Rabu', 'Hari Jumat'], 'difficulty' => 1],
            ['prompt' => 'Berapa kelompok yang mengikuti kegiatan?', 'correct' => 'Tiga kelompok', 'also_correct' => 'Lebih dari dua kelompok', 'wrong' => ['Dua kelompok', 'Empat kelompok', 'Lima kelompok'], 'difficulty' => 1],
            ['prompt' => 'Apa yang dilakukan setiap kelompok setelah kegiatan selesai?', 'correct' => 'Mempresentasikan temuan', 'also_correct' => 'Menyampaikan hasil di depan kelas', 'wrong' => ['Langsung pulang', 'Menghapus catatan', 'Mengganti tema'], 'difficulty' => 2],
            ['prompt' => 'Mengapa guru memberikan apresiasi kepada siswa?', 'correct' => 'Karena siswa menyelesaikan kegiatan', 'also_correct' => 'Karena kegiatan berhasil diselesaikan', 'wrong' => ['Karena kegiatan dibatalkan', 'Karena siswa datang terlambat', 'Karena kelas belum dirapikan'], 'difficulty' => 2],
        ];
        $blueprints = collect($context['question_blueprints'] ?? [])->values();

        return [
            'title' => $title,
            'story_paragraphs' => array_slice($paragraphs, 0, $paragraphCount),
            'questions' => collect(array_slice($questions, 0, $questionCount))->map(fn (array $question, int $index): array => $this->storyQuestionPayload(
                $question,
                $index,
                $title,
                $competencyCode,
                (string) data_get($blueprints, "{$index}.answer_format", 'single_choice'),
                (string) data_get($blueprints, "{$index}.cognitive_level_label", 'Menemukan informasi'),
            ))->all(),
        ];
    }

    private function storyQuestionPayload(array $question, int $index, string $title, string $competencyCode, string $answerFormat, string $cognitiveLevel): array
    {
        $base = [
            'competency_code' => $competencyCode,
            'title' => "{$title} - Soal ".($index + 1),
            'explanation' => 'Jawaban ditemukan dengan membaca dan menafsirkan informasi pada cerita.',
            'difficulty' => min(3, $index + 1),
            'cognitive_level' => $cognitiveLevel,
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
                'prompt' => 'Tentukan Benar atau Salah untuk setiap pernyataan berdasarkan cerita.',
                'options' => [],
                'matrix_columns' => ['Benar', 'Salah'],
                'matrix_rows' => [
                    ['statement' => $question['correct'].'.', 'correct_column_index' => 0],
                    ['statement' => $question['wrong'][0].'.', 'correct_column_index' => 1],
                    ['statement' => $question['also_correct'].'.', 'correct_column_index' => 0],
                ],
            ];
        }

        $multipleChoice = $answerFormat === 'multiple_choice';
        $options = $multipleChoice
            ? [[$question['correct'], true], [$question['also_correct'], true], [$question['wrong'][0], false], [$question['wrong'][1], false]]
            : [[$question['correct'], true], [$question['wrong'][0], false], [$question['wrong'][1], false], [$question['wrong'][2], false]];

        return [
            ...$base,
            'type' => $multipleChoice ? 'multiple_choice' : 'single_choice',
            'prompt' => $multipleChoice ? 'Pilih semua jawaban yang benar. '.$question['prompt'] : $question['prompt'],
            'options' => collect($options)->map(fn (array $option): array => ['content' => $option[0], 'is_correct' => $option[1]])->all(),
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
                    ['statement' => "Hasil perhitungan bukan {$question['wrong'][1]}.", 'correct_column_index' => 0],
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
