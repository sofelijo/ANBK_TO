<?php

namespace Tests\Feature;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionVerification;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherVerificationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_analyze_teacher_verification_activity_by_period_and_subdistrict(): void
    {
        $kelapaGading = School::create([
            'name' => 'SD Kelapa Gading',
            'npsn' => '31000001',
            'subdistrict' => 'Kelapa Gading',
        ]);
        $koja = School::create([
            'name' => 'SD Koja',
            'npsn' => '31000002',
            'subdistrict' => 'Koja',
        ]);
        $admin = $this->user($kelapaGading, 'Admin Wilayah', 'admin-verifikasi@toa.local', UserRole::Admin);
        $activeTeacher = $this->user($kelapaGading, 'Guru Paling Aktif', 'aktif@toa.local', UserRole::Teacher);
        $secondTeacher = $this->user($kelapaGading, 'Guru Pendamping', 'pendamping@toa.local', UserRole::Teacher);
        $inactiveTeacher = $this->user($kelapaGading, 'Guru Belum Aktif', 'belum@toa.local', UserRole::Teacher);
        $kojaTeacher = $this->user($koja, 'Guru Koja', 'koja@toa.local', UserRole::Teacher);
        $competency = Competency::create([
            'school_id' => $kelapaGading->id,
            'code' => 'VER-01',
            'domain' => 'Literasi',
            'name' => 'Kompetensi verifikasi',
            'grade_level' => 6,
        ]);
        $publishedQuestion = $this->question($kelapaGading, $activeTeacher, $competency, 'Soal terbit', QuestionStatus::Published);
        $reviewQuestion = $this->question($kelapaGading, $activeTeacher, $competency, 'Soal direview', QuestionStatus::Review);

        $this->verification($publishedQuestion, $activeTeacher, now()->subDay());
        $this->verification($reviewQuestion, $activeTeacher, now()->subDays(2));
        $this->verification($publishedQuestion, $secondTeacher, now()->subDays(3));
        $oldQuestion = $this->question($kelapaGading, $activeTeacher, $competency, 'Soal lama', QuestionStatus::Published);
        $this->verification($oldQuestion, $activeTeacher, now()->subDays(45));

        $this->actingAs($admin)
            ->get(route('admin.teacher-verifications.index', ['period' => '30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TeacherVerifications/Index')
                ->where('stats.totalVerifications', 3)
                ->where('stats.activeTeachers', 2)
                ->where('stats.totalTeachers', 4)
                ->where('stats.teacherParticipationRate', 50)
                ->where('stats.questionsVerified', 2)
                ->where('stats.publishedQuestions', 1)
                ->where('teachers.data.0.name', 'Guru Paling Aktif')
                ->where('teachers.data.0.verification_count', 2)
                ->where('teachers.data.0.published_contribution_count', 1)
                ->has('trend', 14));

        $this->actingAs($admin)
            ->get(route('admin.teacher-verifications.index', [
                'period' => '30',
                'subdistrict' => 'Koja',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalTeachers', 1)
                ->where('stats.totalVerifications', 0)
                ->has('teachers.data', 1)
                ->where('teachers.data.0.name', $kojaTeacher->name));

        $this->actingAs($activeTeacher)
            ->get(route('admin.teacher-verifications.index'))
            ->assertForbidden();

        $this->assertSame(0, $inactiveTeacher->questionVerifications()->count());
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
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

    private function question(
        School $school,
        User $author,
        Competency $competency,
        string $title,
        QuestionStatus $status,
    ): Question {
        return Question::create([
            'school_id' => $school->id,
            'author_id' => $author->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => $status,
            'title' => $title,
            'prompt' => 'Manakah jawaban yang benar?',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);
    }

    private function verification(Question $question, User $teacher, $verifiedAt): void
    {
        QuestionVerification::create([
            'question_id' => $question->id,
            'verifier_id' => $teacher->id,
            'verified_at' => $verifiedAt,
        ]);
    }
}
