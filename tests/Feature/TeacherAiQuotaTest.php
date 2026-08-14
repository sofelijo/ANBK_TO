<?php

namespace Tests\Feature;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\School;
use App\Models\User;
use App\Services\TeacherAiQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeacherAiQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_teacher_ai_quotas_for_the_school(): void
    {
        [$school, $admin] = $this->users();

        $this->actingAs($admin)
            ->get(route('admin.ai-quotas.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AiQuotas/Edit')
                ->where('quotas.story_questions', (int) config('ai.daily_story_limit')));

        $this->actingAs($admin)
            ->patch(route('admin.ai-quotas.update'), [
                'question_variants' => 7,
                'story_questions' => 12,
                'story_illustrations' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'question_variants' => 7,
            'story_questions' => 12,
            'story_illustrations' => 4,
        ], data_get($school->fresh()->settings, 'ai_teacher_quotas'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'teacher_ai_quotas.updated',
        ]);
    }

    public function test_teacher_cannot_manage_ai_quotas(): void
    {
        [, , $teacher] = $this->users();

        $this->actingAs($teacher)->get(route('admin.ai-quotas.edit'))->assertForbidden();
        $this->actingAs($teacher)->patch(route('admin.ai-quotas.update'), [
            'question_variants' => 1000,
            'story_questions' => 1000,
            'story_illustrations' => 1000,
        ])->assertForbidden();
    }

    public function test_teacher_is_limited_but_admin_is_not_and_failed_requests_are_not_counted(): void
    {
        [$school, $admin, $teacher] = $this->users();
        $school->update(['settings' => [
            'ai_teacher_quotas' => [
                'question_variants' => 1,
                'story_questions' => 1,
                'story_illustrations' => 1,
            ],
        ]]);

        $this->generation($teacher, AiGenerationStatus::Failed, 'teacher-failed');
        app(TeacherAiQuota::class)->ensureAvailable($teacher->fresh(), AiGenerationType::StoryQuestions, 'theme');

        $this->generation($teacher, AiGenerationStatus::Completed, 'teacher-completed');

        try {
            app(TeacherAiQuota::class)->ensureAvailable($teacher->fresh(), AiGenerationType::StoryQuestions, 'theme');
            $this->fail('Guru seharusnya terkena kuota.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('theme', $exception->errors());
        }

        $this->generation($admin, AiGenerationStatus::Completed, 'admin-completed');
        app(TeacherAiQuota::class)->ensureAvailable($admin->fresh(), AiGenerationType::StoryQuestions, 'theme');
        $this->assertTrue(true);
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Kuota', 'npsn' => '10000077']);
        $admin = User::create([
            'school_id' => $school->id,
            'name' => 'Admin Kuota',
            'email' => 'admin-kuota@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru Kuota',
            'email' => 'guru-kuota@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);

        return [$school, $admin, $teacher];
    }

    private function generation(User $user, AiGenerationStatus $status, string $hash): void
    {
        AiGeneration::create([
            'school_id' => $user->school_id,
            'requested_by' => $user->id,
            'type' => AiGenerationType::StoryQuestions,
            'status' => $status,
            'provider' => 'fake',
            'model' => 'test',
            'input_hash' => hash('sha256', $hash),
            'request_payload' => [],
        ]);
    }
}
