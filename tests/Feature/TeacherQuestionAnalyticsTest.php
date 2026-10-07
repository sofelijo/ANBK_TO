<?php

namespace Tests\Feature;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherQuestionAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_question_counts_for_each_teacher(): void
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
        $admin = $this->user($kelapaGading, 'Admin Wilayah', 'admin-soal@toa.local', UserRole::Admin);
        $activeTeacher = $this->user($kelapaGading, 'Guru Produktif', 'produktif@toa.local', UserRole::Teacher);
        $inactiveTeacher = $this->user($kelapaGading, 'Guru Belum Menulis', 'belum-menulis@toa.local', UserRole::Teacher);
        $kojaTeacher = $this->user($koja, 'Guru Koja', 'guru-koja@toa.local', UserRole::Teacher);
        $competency = Competency::create([
            'school_id' => $kelapaGading->id,
            'code' => 'SOAL-01',
            'domain' => 'Literasi',
            'name' => 'Kompetensi soal',
            'grade_level' => 6,
        ]);

        $this->question($activeTeacher, $competency, 'Soal draf', QuestionStatus::Draft, now()->subDay());
        $this->question($activeTeacher, $competency, 'Soal terbit', QuestionStatus::Published, now()->subDays(2));
        $this->question($activeTeacher, $competency, 'Soal lama', QuestionStatus::Archived, now()->subDays(45));
        $this->question($kojaTeacher, $competency, 'Soal Koja', QuestionStatus::Review, now()->subDay());

        $this->actingAs($admin)
            ->get(route('admin.teacher-questions.index', ['period' => '30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TeacherQuestions/Index')
                ->where('stats.totalQuestions', 3)
                ->where('stats.publishedQuestions', 1)
                ->where('stats.activeTeachers', 2)
                ->where('stats.totalTeachers', 3)
                ->where('stats.averageQuestions', 1.5)
                ->where('teachers.data.0.name', 'Guru Produktif')
                ->where('teachers.data.0.question_count', 2)
                ->where('teachers.data.0.draft_count', 1)
                ->where('teachers.data.0.published_count', 1)
                ->where('teachers.data.1.name', 'Guru Koja')
                ->where('teachers.data.2.name', $inactiveTeacher->name)
                ->where('teachers.data.2.question_count', 0));

        $this->actingAs($admin)
            ->get(route('admin.teacher-questions.index', [
                'period' => 'all',
                'subdistrict' => 'Kelapa Gading',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalQuestions', 3)
                ->where('stats.totalTeachers', 2)
                ->where('stats.activeTeachers', 1)
                ->has('teachers.data', 2));

        $this->actingAs($activeTeacher)
            ->get(route('admin.teacher-questions.index'))
            ->assertForbidden();
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

    private function question(User $author, Competency $competency, string $title, QuestionStatus $status, $createdAt): Question
    {
        $question = Question::create([
            'author_id' => $author->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => $status,
            'title' => $title,
            'prompt' => 'Manakah jawaban yang benar?',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);
        $question->timestamps = false;
        $question->created_at = $createdAt;
        $question->updated_at = $createdAt;
        $question->save();

        return $question;
    }
}
