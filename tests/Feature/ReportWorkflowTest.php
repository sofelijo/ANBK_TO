<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
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
                ->where('summary.average', 50));

        $this->actingAs($teacher)
            ->get(route('reports.export', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
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
}
