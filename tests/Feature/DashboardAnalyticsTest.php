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

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_regional_analytics_for_all_three_subdistricts(): void
    {
        $kelapaGading = School::create([
            'name' => 'SD Kelapa Gading',
            'npsn' => '10000001',
            'subdistrict' => 'Kelapa Gading',
        ]);
        $cilincing = School::create([
            'name' => 'SD Cilincing',
            'npsn' => '10000002',
            'subdistrict' => 'Cilincing',
        ]);
        $koja = School::create([
            'name' => 'SD Koja',
            'npsn' => '10000003',
            'subdistrict' => 'Koja',
        ]);
        $unclassified = School::create([
            'name' => 'SD Belum Lengkap',
            'npsn' => '10000004',
        ]);
        $admin = $this->user($unclassified, 'Admin Wilayah', UserRole::Admin, 'admin-wilayah@toa.local');

        $kelapaStudentOne = $this->student($kelapaGading, 'Siswa KG 1', 'KG-01');
        $this->student($kelapaGading, 'Siswa KG 2', 'KG-02');
        $cilincingStudent = $this->student($cilincing, 'Siswa Cilincing', 'CL-01');
        $this->student($koja, 'Siswa Koja', 'KJ-01');

        $this->completedAttempt($kelapaGading, $admin, $kelapaStudentOne, 8, 10);
        $this->completedAttempt($cilincing, $admin, $cilincingStudent, 5, 10);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('mode', 'admin')
                ->where('stats.schools', 4)
                ->where('stats.students', 4)
                ->where('stats.participants', 2)
                ->where('stats.completedAttempts', 2)
                ->where('stats.participationRate', 50)
                ->where('stats.averageScore', 65)
                ->where('stats.unclassifiedSchools', 1)
                ->has('districts', 3)
                ->where('districts.0.name', 'Kelapa Gading')
                ->where('districts.0.students', 2)
                ->where('districts.0.participationRate', 50)
                ->where('districts.0.averageScore', 80)
                ->where('districts.1.name', 'Cilincing')
                ->where('districts.1.participationRate', 100)
                ->where('districts.2.name', 'Koja')
                ->where('districts.2.completedAttempts', 0)
                ->has('schools', 4));
    }

    public function test_operator_dashboard_exposes_school_subdistrict_and_performance(): void
    {
        $school = School::create([
            'name' => 'SD Koja Utara',
            'npsn' => '20000001',
            'subdistrict' => 'Koja',
        ]);
        $operator = $this->user($school, 'Operator Koja', UserRole::Operator, 'operator-koja@toa.local');
        $student = $this->student($school, 'Siswa Koja', 'KJ-11');
        $this->completedAttempt($school, $operator, $student, 9, 10);

        $this->actingAs($operator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('mode', 'operator')
                ->where('school.subdistrict', 'Koja')
                ->where('stats.students', 1)
                ->where('stats.completedAttempts', 1)
                ->where('stats.participationRate', 100)
                ->where('stats.averageScore', 90));
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
        School $school,
        User $creator,
        User $student,
        int $score,
        int $maxScore,
    ): Attempt {
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $creator->id,
            'title' => "Try Out {$school->name}",
            'grade_level' => 6,
            'duration_minutes' => 60,
            'status' => AssessmentStatus::Published,
        ]);

        return Attempt::create([
            'public_id' => (string) Str::uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::Submitted,
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'duration_seconds' => 3600,
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }
}
