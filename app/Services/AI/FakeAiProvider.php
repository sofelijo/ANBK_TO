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
