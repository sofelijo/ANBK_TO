<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TogetherRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_view_together_ranking_and_filter_it_by_subdistrict(): void
    {
        [$teacher, $assessment, $kelapaStudent, $cilincingStudent] = $this->scenario();

        $this->completedAttempt($assessment, $kelapaStudent, 9, 10, 1200);
        $this->completedAttempt($assessment, $cilincingStudent, 9, 10, 900);
        $secondAssessment = $assessment->replicate();
        $secondAssessment->title = 'Try Out Bersama Wilayah II Paket 2';
        $secondAssessment->starts_at = now();
        $secondAssessment->save();
        $this->completedAttempt($secondAssessment, $kelapaStudent, 7, 10, 1100);
        $this->completedAttempt($secondAssessment, $cilincingStudent, 5, 10, 800);
        $regularAssessment = $assessment->replicate();
        $regularAssessment->title = 'Try Out Reguler Sekolah';
        $regularAssessment->settings = [
            'type' => Assessment::TYPE_REGULAR,
            'type_label' => 'Try Out Reguler',
        ];
        $regularAssessment->starts_at = now()->addDay();
        $regularAssessment->save();
        $this->completedAttempt($regularAssessment, $kelapaStudent, 10, 10, 700);

        $this->actingAs($teacher)
            ->get(route('rankings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Rankings/Index')
                ->where('selectedAssessment', null)
                ->has('assessments', 3)
                ->where('cumulativeAssessmentCount', 2)
                ->has('rankings', 2)
                ->where('rankings.0.rank', 1)
                ->where('rankings.0.student.name', 'Siswa Kelapa Gading')
                ->where('rankings.0.packagesCompleted', 2)
                ->where('rankings.0.averageScore', 80)
                ->where('rankings.1.student.name', 'Siswa Cilincing')
                ->has('schoolRankings', 2)
                ->where('schoolRankings.0.school.name', 'SD Kelapa Gading')
                ->where('schoolRankings.0.packagesCompleted', 2)
                ->where('schoolRankings.0.averageScore', 80)
                ->where('districtRankings.0.name', 'Kelapa Gading')
                ->where('districtRankings.0.averageScore', 80));

        $this->actingAs($teacher)
            ->get(route('rankings.index', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedAssessment.id', $assessment->id)
                ->where('rankings.0.student.name', 'Siswa Cilincing')
                ->where('rankings.0.averageScore', 90));

        $this->actingAs($teacher)
            ->get(route('rankings.index', ['assessment_id' => $regularAssessment->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedAssessment.id', $regularAssessment->id)
                ->where('selectedAssessment.type', Assessment::TYPE_REGULAR)
                ->where('selectedAssessment.typeLabel', 'Try Out Reguler')
                ->has('rankings', 1)
                ->where('rankings.0.student.name', 'Siswa Kelapa Gading')
                ->where('rankings.0.averageScore', 100));

        $this->actingAs($teacher)
            ->get(route('rankings.index', [
                'subdistrict' => 'Kelapa Gading',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedSubdistrict', 'Kelapa Gading')
                ->has('rankings', 1)
                ->has('schoolRankings', 1)
                ->where('schoolRankings.0.rank', 1)
                ->where('schoolRankings.0.school.name', 'SD Kelapa Gading')
                ->where('schoolRankings.0.packagesCompleted', 2)
                ->where('schoolRankings.0.averageScore', 80)
                ->where('rankings.0.rank', 1)
                ->where('rankings.0.student.name', 'Siswa Kelapa Gading'));
    }

    public function test_teacher_dashboard_contains_latest_together_ranking_preview(): void
    {
        [$teacher, $assessment, $kelapaStudent] = $this->scenario();
        $this->completedAttempt($assessment, $kelapaStudent, 8, 10, 1000);
        $secondAssessment = $assessment->replicate();
        $secondAssessment->title = 'Try Out Bersama Dashboard Paket 2';
        $secondAssessment->starts_at = now();
        $secondAssessment->save();
        $this->completedAttempt($secondAssessment, $kelapaStudent, 6, 10, 900);

        $this->actingAs($teacher)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('mode', 'teacher')
                ->where('rankingPreview.assessmentCount', 2)
                ->where('rankingPreview.participantCount', 1)
                ->where('rankingPreview.schoolCount', 1)
                ->where('rankingPreview.schools.0.school.name', 'SD Kelapa Gading')
                ->where('rankingPreview.schools.0.packagesCompleted', 2)
                ->where('rankingPreview.schools.0.averageScore', 70));
    }

    public function test_student_and_operator_cannot_view_rankings(): void
    {
        [$teacher, , $student] = $this->scenario();
        $operator = $this->user($teacher->school, 'Operator', UserRole::Operator, 'operator-ranking@toa.local');

        $this->actingAs($student)->get(route('rankings.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('rankings.index'))->assertForbidden();
    }

    /** @return array{User, Assessment, User, User} */
    private function scenario(): array
    {
        $kelapaGading = School::create([
            'name' => 'SD Kelapa Gading',
            'npsn' => '31000001',
            'subdistrict' => 'Kelapa Gading',
        ]);
        $cilincing = School::create([
            'name' => 'SD Cilincing',
            'npsn' => '31000002',
            'subdistrict' => 'Cilincing',
        ]);
        School::create([
            'name' => 'SD Koja',
            'npsn' => '31000003',
            'subdistrict' => 'Koja',
        ]);

        $teacher = $this->user($kelapaGading, 'Guru Ranking', UserRole::Teacher, 'guru-ranking@toa.local');
        $kelapaStudent = $this->student($kelapaGading, 'Siswa Kelapa Gading', 'KG-RANK');
        $cilincingStudent = $this->student($cilincing, 'Siswa Cilincing', 'CL-RANK');

        $assessment = Assessment::create([
            'school_id' => $kelapaGading->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Bersama Wilayah II',
            'grade_level' => 6,
            'duration_minutes' => 60,
            'status' => AssessmentStatus::Published,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'settings' => [
                'type' => Assessment::TYPE_TOGETHER,
                'type_label' => 'Try Out Bersama',
            ],
        ]);

        return [$teacher, $assessment, $kelapaStudent, $cilincingStudent];
    }

    private function user(School $school, string $name, UserRole $role, string $email): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
    }

    private function student(School $school, string $name, string $identifier): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => strtolower($identifier).'@toa.local',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => $identifier,
            'grade_level' => 6,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function completedAttempt(
        Assessment $assessment,
        User $student,
        int $score,
        int $maxScore,
        int $durationSeconds,
    ): Attempt {
        return Attempt::create([
            'public_id' => (string) Str::uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::Submitted,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'duration_seconds' => $durationSeconds,
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }
}
