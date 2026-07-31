<?php

namespace App\Services;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Competency;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class QuestionImportService
{
    public function import(UploadedFile $file, User $user): array
    {
        $rows = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray(null, true, true, false);
        $headers = array_map(
            fn (mixed $header): string => strtolower(trim((string) $header)),
            array_shift($rows) ?? [],
        );
        $imported = 0;
        $errors = [];

        foreach ($rows as $offset => $values) {
            $line = $offset + 2;
            if (collect($values)->filter(fn (mixed $value): bool => trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            try {
                $row = array_combine($headers, array_pad($values, count($headers), null));
                if (! is_array($row)) {
                    throw new \RuntimeException('Header file tidak sesuai template.');
                }

                $this->createQuestion($row, $user, $line);
                $imported++;
            } catch (Throwable $exception) {
                $errors[] = "Baris {$line}: {$exception->getMessage()}";
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    private function createQuestion(array $row, User $user, int $line): void
    {
        $requiredHeaders = ['competency_code', 'type', 'prompt', 'difficulty', 'grade_level'];
        foreach ($requiredHeaders as $header) {
            if (! array_key_exists($header, $row) || trim((string) $row[$header]) === '') {
                throw new \RuntimeException("kolom {$header} wajib diisi.");
            }
        }

        $type = QuestionType::tryFrom(trim((string) $row['type']));
        if (! $type) {
            throw new \RuntimeException('type harus single_choice, multiple_choice, short_answer, atau matching.');
        }
        if (in_array($type, [QuestionType::Matching, QuestionType::CategoryMatrix], true)) {
            throw new \RuntimeException('soal matching dan category_matrix dibuat melalui editor karena membutuhkan struktur khusus.');
        }

        $gradeLevel = (int) $row['grade_level'];
        $difficulty = (int) $row['difficulty'];
        if (! in_array($gradeLevel, [5, 8, 11], true) || ! in_array($difficulty, [1, 2, 3], true)) {
            throw new \RuntimeException('grade_level atau difficulty tidak valid.');
        }

        $competency = Competency::query()
            ->where('code', trim((string) $row['competency_code']))
            ->where('grade_level', $gradeLevel)
            ->where(fn ($query) => $query->whereNull('school_id')->orWhere('school_id', $user->school_id))
            ->first();
        if (! $competency) {
            throw new \RuntimeException('kode kompetensi tidak ditemukan.');
        }

        $correctLabels = $this->split((string) ($row['correct_answers'] ?? ''), '/[,;|]/');
        $options = collect(['A', 'B', 'C', 'D', 'E', 'F'])
            ->map(function (string $label) use ($row, $correctLabels): ?array {
                $content = trim((string) ($row['option_'.strtolower($label)] ?? ''));

                return $content === '' ? null : [
                    'label' => $label,
                    'content' => $content,
                    'is_correct' => in_array($label, $correctLabels, true),
                ];
            })
            ->filter()
            ->values();
        $acceptedAnswers = $this->split((string) ($row['accepted_answers'] ?? ''), '/[|;]/');

        if ($type === QuestionType::SingleChoice && ($options->count() < 2 || count($correctLabels) !== 1)) {
            throw new \RuntimeException('pilihan tunggal membutuhkan minimal dua opsi dan satu label jawaban benar.');
        }
        if ($type === QuestionType::MultipleChoice && ($options->count() < 2 || count($correctLabels) < 1)) {
            throw new \RuntimeException('pilihan kompleks membutuhkan minimal dua opsi dan jawaban benar.');
        }
        if ($type === QuestionType::ShortAnswer && count($acceptedAnswers) < 1) {
            throw new \RuntimeException('isian singkat membutuhkan accepted_answers.');
        }

        DB::transaction(function () use ($row, $user, $competency, $type, $gradeLevel, $difficulty, $options, $acceptedAnswers, $line): void {
            $question = Question::create([
                'school_id' => $user->school_id,
                'author_id' => $user->id,
                'competency_id' => $competency->id,
                'type' => $type,
                'status' => QuestionStatus::Draft,
                'title' => $this->nullable($row['title'] ?? null),
                'stimulus' => $this->nullable($row['stimulus'] ?? null),
                'prompt' => trim((string) $row['prompt']),
                'explanation' => $this->nullable($row['explanation'] ?? null),
                'difficulty' => $difficulty,
                'grade_level' => $gradeLevel,
                'cognitive_level' => $this->nullable($row['cognitive_level'] ?? null),
                'metadata' => $type === QuestionType::ShortAnswer
                    ? ['accepted_answers' => $acceptedAnswers, 'imported_line' => $line]
                    : ['imported_line' => $line],
            ]);

            foreach ($options as $index => $option) {
                $question->options()->create([
                    ...$option,
                    'position' => $index + 1,
                ]);
            }
        });
    }

    private function split(string $value, string $pattern): array
    {
        return array_values(array_filter(array_map(
            fn (string $part): string => strtoupper(trim($part)),
            preg_split($pattern, $value) ?: [],
        )));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
