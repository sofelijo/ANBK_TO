<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_view_and_export_school_report(): void
    {
        [$teacher, $assessment] = $this->scenario();

        $this->actingAs($teacher)
            ->get(route('reports.index', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Index')
                ->where('summary.completed', 1)
                ->where('summary.average', 50)
                ->where('itemAnalysis.sample_size', 1)
                ->where('itemAnalysis.reliability.status', 'insufficient_data'));

        $this->actingAs($teacher)
            ->get(route('reports.export', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_advanced_item_analysis_flags_problematic_questions_and_calculates_reliability(): void
    {
        [$teacher, $assessment] = $this->analysisScenario();

        $this->actingAs($teacher)
            ->get(route('reports.index', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Index')
                ->where('itemAnalysis.sample_size', 30)
                ->where('itemAnalysis.flagged_count', 2)
                ->where('itemAnalysis.reliability.coefficient', 0)
                ->where('itemAnalysis.reliability.status', 'review')
                ->where('items.0.correct_percentage', 100)
                ->where('items.0.discrimination_percentage', 0)
                ->where('items.0.distractors.functioning', 0)
                ->where('items.0.status', 'review')
                ->where('items.0.flags', fn ($flags): bool => collect($flags)->contains('code', 'too_easy')
                    && collect($flags)->contains('code', 'low_discrimination')
                    && collect($flags)->contains('code', 'ineffective_distractor'))
                ->where('items.1.correct_percentage', 50)
                ->where('items.1.discrimination_percentage', 100)
                ->where('items.1.status', 'healthy')
                ->where('items.2.correct_percentage', 0)
                ->where('items.2.status', 'review'));
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Sekolah Laporan', 'npsn' => '10000002']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'email' => 'guru-report@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $student = User::create([
            'school_id' => $school->id,
            'name' => 'Murid',
            'email' => 'murid-report@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => 'R-001',
            'grade_level' => 5,
            'email_verified_at' => now(),
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT5-REPORT',
            'domain' => 'Literasi',
            'name' => 'Kompetensi laporan',
            'grade_level' => 5,
        ]);
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Paket Laporan',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $attempt = Attempt::create([
            'public_id' => (string) Str::uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::Submitted,
            'started_at' => now()->subMinutes(20),
            'submitted_at' => now(),
            'duration_seconds' => 1200,
            'score' => 1,
            'max_score' => 2,
            'summary' => 'Ringkasan.',
        ]);
        $attempt->competencyResults()->create([
            'competency_id' => $competency->id,
            'correct_count' => 1,
            'question_count' => 2,
            'percentage' => 50,
        ]);

        return [$teacher, $assessment];
    }

    private function analysisScenario(): array
    {
        $school = School::create(['name' => 'Sekolah Analisis', 'npsn' => '10000004']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru Analisis',
            'email' => 'guru-analysis@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'code' => 'LIT5-ANALYSIS',
            'domain' => 'Literasi',
            'name' => 'Analisis kualitas soal',
            'grade_level' => 5,
        ]);
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Paket Analisis Butir',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $questions = collect([
            ['title' => 'Soal terlalu mudah', 'difficulty' => 1],
            ['title' => 'Soal pembeda baik', 'difficulty' => 2],
            ['title' => 'Soal terlalu sulit', 'difficulty' => 3],
        ])->map(function (array $data) use ($school, $teacher, $competency): Question {
            $question = Question::create([
                'school_id' => $school->id,
                'author_id' => $teacher->id,
                'competency_id' => $competency->id,
                'type' => 'single_choice',
                'status' => QuestionStatus::Published,
                'title' => $data['title'],
                'prompt' => 'Pilih jawaban yang tepat.',
                'difficulty' => $data['difficulty'],
                'grade_level' => 5,
            ]);
            $question->options()->createMany([
                ['label' => 'A', 'content' => 'Jawaban benar', 'is_correct' => true, 'position' => 1],
                ['label' => 'B', 'content' => 'Jawaban pengecoh', 'is_correct' => false, 'position' => 2],
            ]);

            return $question->load('options');
        });
        $assessment->questions()->attach($questions->values()->mapWithKeys(
            fn (Question $question, int $index): array => [$question->id => ['position' => $index + 1, 'points' => 1]],
        )->all());

        foreach (range(1, 30) as $index) {
            $topGroup = $index <= 15;
            $student = User::create([
                'school_id' => $school->id,
                'name' => "Murid {$index}",
                'email' => "murid-analysis-{$index}@example.com",
                'password' => 'password',
                'role' => UserRole::Student,
                'student_identifier' => sprintf('A-%03d', $index),
                'grade_level' => 5,
                'email_verified_at' => now(),
            ]);
            $attempt = Attempt::create([
                'public_id' => (string) Str::uuid(),
                'assessment_id' => $assessment->id,
                'user_id' => $student->id,
                'status' => AttemptStatus::Submitted,
                'started_at' => now()->subMinutes(20),
                'submitted_at' => now(),
                'duration_seconds' => 1200,
                'score' => $topGroup ? 2 : 1,
                'max_score' => 3,
            ]);

            foreach ($questions as $questionIndex => $question) {
                $isCorrect = match ($questionIndex) {
                    0 => true,
                    1 => $topGroup,
                    default => false,
                };
                $selectedOption = $question->options->firstWhere('is_correct', $isCorrect);
                $attempt->answers()->create([
                    'question_id' => $question->id,
                    'response' => ['option_ids' => [$selectedOption->id]],
                    'is_correct' => $isCorrect,
                    'points_awarded' => $isCorrect ? 1 : 0,
                    'duration_seconds' => 30 + $questionIndex,
                    'answered_at' => now(),
                ]);
            }
        }

        return [$teacher, $assessment];
    }
}
