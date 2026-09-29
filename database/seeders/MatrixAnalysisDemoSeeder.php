<?php

namespace Database\Seeders;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionVerification;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Services\QuestionSnapshotService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class MatrixAnalysisDemoSeeder extends Seeder
{
    private const SCHOOL_NPSN = '31999991';

    private const ASSESSMENT_TITLE = 'Paket Demo Analisis Matriks Matematika';

    public function run(): void
    {
        $operatorEmail = trim((string) config('demo.matrix.operator_email'));
        $operatorPassword = (string) config('demo.matrix.operator_password');

        if ($operatorEmail === '' || $operatorPassword === '') {
            throw new RuntimeException(
                'Isi TOA_MATRIX_DEMO_OPERATOR_EMAIL dan TOA_MATRIX_DEMO_OPERATOR_PASSWORD pada .env sebelum menjalankan seeder.',
            );
        }

        DB::transaction(function () use ($operatorEmail, $operatorPassword): void {
            $school = School::query()->updateOrCreate(
                ['npsn' => self::SCHOOL_NPSN],
                [
                    'name' => 'SDN TOA Matrix Wilayah II',
                    'subdistrict' => 'Koja',
                    'timezone' => 'Asia/Jakarta',
                    'settings' => [
                        'address' => 'Jalan Pendidikan Demo, Koja',
                        'province' => 'DKI Jakarta',
                        'city' => 'Jakarta Utara',
                        'principal_name' => 'Kepala Sekolah Demo Matrix',
                        'phone' => '02100000000',
                    ],
                ],
            );

            $operator = User::query()->updateOrCreate(
                ['email' => $operatorEmail],
                [
                    'school_id' => $school->id,
                    'name' => 'Operator Demo Matrix',
                    'password' => Hash::make($operatorPassword),
                    'role' => UserRole::Operator,
                    'student_identifier' => null,
                    'grade_level' => null,
                    'is_active' => true,
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ],
            );

            $verifiers = collect(range(1, 3))->map(
                fn (int $number): User => $this->verifier($school, $number),
            );
            $students = collect(range(1, 30))->map(
                fn (int $number): User => $this->student($school, $number),
            );
            $subject = Subject::query()->updateOrCreate(
                ['school_id' => $school->id, 'code' => 'MAT-MATRIX'],
                [
                    'name' => 'Matematika',
                    'description' => 'Mata pelajaran demo untuk memeriksa matriks monitoring dan analisis butir.',
                    'ai_question_format' => 'direct',
                ],
            );
            $competencies = collect([
                [
                    'code' => 'MAT6-BIL-DEMO',
                    'domain' => 'Bilangan',
                    'name' => 'Operasi bilangan dan pengukuran',
                    'description' => 'Menggunakan operasi hitung, FPB, KPK, pecahan, dan konversi satuan.',
                ],
                [
                    'code' => 'MAT6-DAT-DEMO',
                    'domain' => 'Data dan Geometri',
                    'name' => 'Data, geometri, dan klasifikasi matematika',
                    'description' => 'Menganalisis data sederhana, bangun ruang, serta pernyataan dalam bentuk matriks.',
                ],
            ])->map(fn (array $data): Competency => Competency::query()->updateOrCreate(
                ['school_id' => $school->id, 'code' => $data['code']],
                [
                    ...$data,
                    'subject_id' => $subject->id,
                    'grade_level' => 6,
                ],
            ));
            $questions = $this->questions($school, $verifiers, $competencies);
            $assessment = Assessment::query()->updateOrCreate(
                ['title' => self::ASSESSMENT_TITLE],
                [
                    'subject_id' => $subject->id,
                    'created_by' => $verifiers->first()->id,
                    'description' => 'Paket demo 10 soal untuk melihat matriks jawaban 30 siswa dan analisis kualitas butir.',
                    'grade_level' => 6,
                    'duration_minutes' => 60,
                    'status' => AssessmentStatus::Published,
                    'settings' => [
                        'type' => Assessment::TYPE_REGULAR,
                        'question_count' => $questions->count(),
                        'shuffle_questions' => false,
                    ],
                    'competency_slots' => $competencies->pluck('id')->all(),
                ],
            );

            $assessment->questions()->sync($questions->mapWithKeys(
                fn (Question $question, int $index): array => [
                    $question->id => ['position' => $index + 1, 'points' => 1],
                ],
            )->all());
            app(QuestionSnapshotService::class)->snapshotAssessment($assessment, true);
            $assessment->refresh()->load('questions.options');

            foreach ($students as $index => $student) {
                $this->completedAttempt($assessment, $student, $index + 1, $competencies);
            }

            $this->command?->info(
                "Data demo matrix siap: {$school->name}, {$students->count()} siswa, operator {$operator->email}.",
            );
        });
    }

    private function verifier(School $school, int $number): User
    {
        $user = User::query()->firstOrNew([
            'email' => "guru.matrix.{$number}@toa.local",
        ]);
        $user->fill([
            'school_id' => $school->id,
            'name' => "Guru Verifikator Matrix {$number}",
            'role' => UserRole::Teacher,
            'student_identifier' => null,
            'grade_level' => null,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->password ??= Hash::make(Str::random(40));
        $user->save();

        return $user;
    }

    private function student(School $school, int $number): User
    {
        $identifier = sprintf('MATRIX-%03d', $number);
        $student = User::query()->firstOrNew([
            'email' => strtolower($identifier).'@student.toa.local',
        ]);
        $student->fill([
            'school_id' => $school->id,
            'name' => sprintf('Siswa Demo Matrix %02d', $number),
            'role' => UserRole::Student,
            'student_identifier' => $identifier,
            'grade_level' => 6,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $student->password ??= Hash::make(Str::random(40));
        $student->save();

        return $student;
    }

    /**
     * @param  Collection<int, User>  $verifiers
     * @param  Collection<int, Competency>  $competencies
     * @return Collection<int, Question>
     */
    private function questions(School $school, Collection $verifiers, Collection $competencies): Collection
    {
        $singleChoice = [
            ['Penjumlahan Bilangan', 'Hasil dari 125 + 375 adalah ...', ['400', '450', '500', '550'], 2],
            ['Pecahan dari Kuantitas', 'Nilai 3/4 dari 120 adalah ...', ['30', '60', '90', '100'], 2],
            ['Faktor Persekutuan Terbesar', 'FPB dari 36 dan 48 adalah ...', ['6', '8', '12', '18'], 2],
            ['Kelipatan Persekutuan Terkecil', 'KPK dari 8 dan 12 adalah ...', ['16', '20', '24', '32'], 2],
            ['Konversi Panjang', 'Jarak 2,5 kilometer sama dengan ... meter.', ['250', '2.050', '2.500', '25.000'], 2],
            ['Rata-rata Data', 'Rata-rata dari 6, 7, 8, 9, dan 10 adalah ...', ['7', '8', '9', '10'], 1],
            ['Luas Persegi Panjang', 'Luas persegi panjang berukuran 12 cm × 8 cm adalah ...', ['20 cm²', '40 cm²', '88 cm²', '96 cm²'], 3],
            ['Volume Kubus', 'Volume kubus dengan panjang rusuk 5 cm adalah ...', ['25 cm³', '75 cm³', '100 cm³', '125 cm³'], 3],
        ];
        $questions = collect($singleChoice)->map(function (array $specification, int $index) use ($verifiers, $competencies): Question {
            [$title, $prompt, $options, $correctPosition] = $specification;
            $number = $index + 1;
            $question = Question::query()->updateOrCreate(
                ['title' => "Demo Matrix {$number} · {$title}"],
                [
                    'author_id' => $verifiers->first()->id,
                    'competency_id' => $index < 5 ? $competencies[0]->id : $competencies[1]->id,
                    'type' => QuestionType::SingleChoice,
                    'status' => QuestionStatus::Published,
                    'stimulus' => null,
                    'prompt' => $prompt,
                    'explanation' => 'Gunakan konsep dan operasi matematika yang sesuai untuk memperoleh jawaban.',
                    'difficulty' => $index < 3 ? 1 : ($index < 6 ? 2 : 3),
                    'grade_level' => 6,
                    'cognitive_level' => $index < 5 ? 'penerapan' : 'penalaran',
                    'metadata' => ['demo_key' => "matrix-question-{$index}"],
                    'approved_by' => $verifiers->last()->id,
                    'approved_at' => now(),
                ],
            );
            $question->options()->delete();
            foreach ($options as $position => $content) {
                $question->options()->create([
                    'label' => chr(65 + $position),
                    'content' => $content,
                    'is_correct' => $position === $correctPosition,
                    'position' => $position + 1,
                ]);
            }
            $this->syncVerifications($question, $verifiers);

            return $question->load('options');
        });

        return $questions
            ->push($this->matrixQuestion($school, $verifiers, $competencies[1], 9, [
                'title' => 'Klasifikasi Kelipatan Tiga',
                'prompt' => 'Tentukan apakah setiap bilangan merupakan kelipatan 3.',
                'columns' => ['Kelipatan 3', 'Bukan kelipatan 3'],
                'rows' => [
                    ['12', 0],
                    ['14', 1],
                    ['21', 0],
                    ['25', 1],
                ],
            ]))
            ->push($this->matrixQuestion($school, $verifiers, $competencies[1], 10, [
                'title' => 'Sifat Bangun Datar',
                'prompt' => 'Tentukan kesesuaian setiap pernyataan tentang bangun datar.',
                'columns' => ['Sesuai', 'Tidak sesuai'],
                'rows' => [
                    ['Persegi memiliki empat sisi sama panjang.', 0],
                    ['Segitiga memiliki empat titik sudut.', 1],
                    ['Persegi panjang memiliki dua pasang sisi sejajar.', 0],
                    ['Lingkaran memiliki tepat dua sisi lurus.', 1],
                ],
            ]))
            ->values();
    }

    /** @param Collection<int, User> $verifiers */
    private function matrixQuestion(
        School $school,
        Collection $verifiers,
        Competency $competency,
        int $number,
        array $specification,
    ): Question {
        $prefix = sprintf('%02d', $number);
        $columns = collect($specification['columns'])->map(fn (string $label, int $index): array => [
            'id' => "00000000-0000-4000-8{$prefix}0-00000000000{$index}",
            'label' => $label,
        ]);
        $rows = collect($specification['rows'])->map(fn (array $row, int $index): array => [
            'id' => "10000000-0000-4000-8{$prefix}0-00000000000{$index}",
            'statement' => $row[0],
            'correct_column_id' => $columns[$row[1]]['id'],
        ]);
        $question = Question::query()->updateOrCreate(
            ['title' => "Demo Matrix {$number} · {$specification['title']}"],
            [
                'author_id' => $verifiers->first()->id,
                'competency_id' => $competency->id,
                'type' => QuestionType::CategoryMatrix,
                'status' => QuestionStatus::Published,
                'stimulus' => 'Baca setiap pernyataan, kemudian pilih kategori yang tepat pada setiap baris.',
                'prompt' => $specification['prompt'],
                'explanation' => 'Periksa setiap pernyataan secara terpisah dan cocokkan dengan definisi matematikanya.',
                'difficulty' => 2,
                'grade_level' => 6,
                'cognitive_level' => 'penalaran',
                'metadata' => [
                    'demo_key' => "matrix-question-{$number}",
                    'matrix_columns' => $columns->all(),
                    'matrix_rows' => $rows->all(),
                ],
                'approved_by' => $verifiers->last()->id,
                'approved_at' => now(),
            ],
        );
        $question->options()->delete();
        $this->syncVerifications($question, $verifiers);

        return $question->load('options');
    }

    /** @param Collection<int, User> $verifiers */
    private function syncVerifications(Question $question, Collection $verifiers): void
    {
        foreach ($verifiers as $index => $verifier) {
            QuestionVerification::query()->updateOrCreate(
                ['question_id' => $question->id, 'verifier_id' => $verifier->id],
                ['verified_at' => now()->subDays(5 - $index)],
            );
        }
    }

    /** @param Collection<int, Competency> $competencies */
    private function completedAttempt(
        Assessment $assessment,
        User $student,
        int $studentNumber,
        Collection $competencies,
    ): void {
        $duration = 1500 + ($studentNumber * 37) % 1200;
        $submittedAt = now()->subDays(2)->addMinutes($studentNumber * 3);
        $attempt = Attempt::query()->firstOrNew([
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
        ]);
        $attempt->public_id ??= (string) Str::uuid();
        $attempt->fill([
            'status' => AttemptStatus::Submitted,
            'started_at' => $submittedAt->copy()->subSeconds($duration),
            'submitted_at' => $submittedAt,
            'duration_seconds' => $duration,
            'max_score' => $assessment->questions->count(),
        ]);
        $attempt->save();

        $attempt->questions()->sync($assessment->questions->mapWithKeys(fn (Question $question): array => [
            $question->id => [
                'position' => $question->pivot->position,
                'points' => $question->pivot->points,
                'snapshot' => $question->pivot->snapshot,
            ],
        ])->all());
        $questionIds = $assessment->questions->pluck('id');
        $attempt->answers()->whereNotIn('question_id', $questionIds)->delete();
        $attempt->competencyResults()->whereNotIn('competency_id', $competencies->pluck('id'))->delete();
        $thresholds = [5, 8, 11, 14, 17, 20, 23, 10, 16, 21];
        $correctByCompetency = $competencies->mapWithKeys(
            fn (Competency $competency): array => [$competency->id => 0],
        )->all();
        $questionCountByCompetency = $competencies->mapWithKeys(
            fn (Competency $competency): array => [$competency->id => 0],
        )->all();
        $totalScore = 0;

        foreach ($assessment->questions->values() as $index => $question) {
            $isCorrect = $studentNumber >= $thresholds[$index];
            if (($studentNumber + ($index * 7)) % 23 === 0) {
                $isCorrect = ! $isCorrect;
            }
            $response = $question->type === QuestionType::CategoryMatrix
                ? $this->matrixResponse($question, $isCorrect)
                : $this->optionResponse($question, $isCorrect, $studentNumber + $index);
            $totalScore += $isCorrect ? 1 : 0;
            $correctByCompetency[$question->competency_id] += $isCorrect ? 1 : 0;
            $questionCountByCompetency[$question->competency_id]++;

            $attempt->answers()->updateOrCreate(
                ['question_id' => $question->id],
                [
                    'response' => $response,
                    'is_correct' => $isCorrect,
                    'points_awarded' => $isCorrect ? 1 : 0,
                    'duration_seconds' => 90 + (($studentNumber * 11 + $index * 17) % 150),
                    'answered_at' => $submittedAt->copy()->subMinutes(2)->addSeconds($index * 10),
                ],
            );
        }

        $attempt->update([
            'score' => $totalScore,
            'summary' => "Siswa menyelesaikan {$assessment->questions->count()} soal Matematika dengan {$totalScore} jawaban benar.",
        ]);

        foreach ($competencies as $competency) {
            $questionCount = $questionCountByCompetency[$competency->id];
            $correctCount = $correctByCompetency[$competency->id];
            $attempt->competencyResults()->updateOrCreate(
                ['competency_id' => $competency->id],
                [
                    'correct_count' => $correctCount,
                    'question_count' => $questionCount,
                    'percentage' => $questionCount > 0 ? round(($correctCount / $questionCount) * 100, 2) : 0,
                ],
            );
        }
    }

    private function optionResponse(Question $question, bool $isCorrect, int $seed): array
    {
        $options = $question->options->values();
        $selected = $isCorrect
            ? $options->firstWhere('is_correct', true)
            : $options->where('is_correct', false)->values()[$seed % $options->where('is_correct', false)->count()];

        return ['option_ids' => [$selected->id]];
    }

    private function matrixResponse(Question $question, bool $isCorrect): array
    {
        $columns = collect(data_get($question->metadata, 'matrix_columns', []));
        $answers = collect(data_get($question->metadata, 'matrix_rows', []))->mapWithKeys(
            fn (array $row): array => [$row['id'] => $row['correct_column_id']],
        );

        if (! $isCorrect && $answers->isNotEmpty()) {
            $firstRowId = $answers->keys()->first();
            $answers[$firstRowId] = $columns
                ->first(fn (array $column): bool => $column['id'] !== $answers[$firstRowId])['id'];
        }

        return ['matrix_answers' => $answers->all()];
    }
}
