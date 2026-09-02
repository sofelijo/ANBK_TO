<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Competency;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuestionTypeSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_choose_active_question_types_for_the_school(): void
    {
        [$school, $admin] = $this->schoolAndUser(UserRole::Admin, 'admin-types@example.com');

        $this->actingAs($admin)
            ->get(route('admin.question-types.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuestionTypes/Edit')
                ->has('allQuestionTypes', 5)
                ->has('questionTypes', 5));

        $this->actingAs($admin)
            ->patch(route('admin.question-types.update'), [
                'enabled_question_types' => ['single_choice', 'short_answer'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            ['single_choice', 'short_answer'],
            data_get($school->fresh()->settings, 'enabled_question_types'),
        );
    }

    public function test_at_least_one_question_type_must_remain_active(): void
    {
        [, $admin] = $this->schoolAndUser(UserRole::Admin, 'admin-empty-types@example.com');

        $this->actingAs($admin)
            ->patch(route('admin.question-types.update'), ['enabled_question_types' => []])
            ->assertSessionHasErrors('enabled_question_types');
    }

    public function test_question_form_and_store_only_allow_active_types_for_new_questions(): void
    {
        [$school, $admin] = $this->schoolAndUser(UserRole::Admin, 'admin-question-types@example.com');
        [, $teacher] = $this->schoolAndUser(UserRole::Teacher, 'teacher-question-types@example.com', $school);
        $subject = Subject::create([
            'school_id' => $school->id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'ai_question_format' => 'direct',
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'MAT6-BIL',
            'domain' => 'Bilangan',
            'name' => 'Operasi bilangan',
            'grade_level' => 6,
        ]);

        $this->actingAs($admin)->patch(route('admin.question-types.update'), [
            'enabled_question_types' => ['single_choice', 'short_answer'],
        ]);

        $this->actingAs($teacher)
            ->get(route('questions.create', ['subject_id' => $subject->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Questions/Create')
                ->has('questionTypes', 2)
                ->where('questionTypes.0.value', 'single_choice')
                ->where('questionTypes.1.value', 'short_answer'));

        $this->actingAs($teacher)
            ->post(route('questions.store'), [
                'subject_id' => $subject->id,
                'competency_id' => $competency->id,
                'type' => 'matching',
                'prompt' => 'Pasangkan setiap bilangan.',
                'difficulty' => 1,
                'grade_level' => 6,
                'options' => [],
                'accepted_answers' => [],
                'matching_pairs' => [
                    ['left' => 'Satu', 'right' => '1'],
                    ['left' => 'Dua', 'right' => '2'],
                ],
                'matching_distractors' => [],
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('questions', 0);
    }

    /** @return array{School, User} */
    private function schoolAndUser(UserRole $role, string $email, ?School $school = null): array
    {
        $school ??= School::create(['name' => 'Sekolah Bentuk Soal', 'npsn' => '10999999']);
        $user = User::create([
            'school_id' => $school->id,
            'name' => $role === UserRole::Admin ? 'Admin' : 'Guru',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return [$school, $user];
    }
}
