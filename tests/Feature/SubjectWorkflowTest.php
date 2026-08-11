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

class SubjectWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_create_update_list_and_delete_school_subject(): void
    {
        [$teacher] = $this->users();

        $this->actingAs($teacher)->post(route('subjects.store'), [
            'code' => ' bind ',
            'name' => ' Bahasa   Indonesia ',
            'description' => 'Mapel literasi TKA.',
        ])->assertRedirect(route('subjects.index'));

        $subject = Subject::query()->where('code', 'BIND')->firstOrFail();
        $this->assertSame($teacher->school_id, $subject->school_id);
        $this->assertSame('Bahasa Indonesia', $subject->name);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'subject.created',
            'auditable_id' => $subject->id,
        ]);

        $this->actingAs($teacher)
            ->get(route('subjects.index', ['search' => 'BIND']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Subjects/Index')
                ->has('subjects', 1)
                ->where('subjects.0.can_manage', true));

        $this->actingAs($teacher)->put(route('subjects.update', $subject), [
            'code' => 'BIND',
            'name' => 'Bahasa Indonesia TKA',
            'description' => null,
        ])->assertRedirect(route('subjects.index'));

        $this->assertSame('Bahasa Indonesia TKA', $subject->fresh()->name);

        $this->actingAs($teacher)
            ->delete(route('subjects.destroy', $subject))
            ->assertRedirect(route('subjects.index'));
        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
    }

    public function test_used_subject_cannot_be_deleted_and_other_roles_are_restricted(): void
    {
        [$teacher, $student, $operator] = $this->users();
        $subject = Subject::create([
            'school_id' => $teacher->school_id,
            'code' => 'MAT',
            'name' => 'Matematika',
        ]);
        Competency::create([
            'school_id' => $teacher->school_id,
            'subject_id' => $subject->id,
            'code' => 'MAT5-BIL',
            'domain' => 'Bilangan',
            'name' => 'Memahami bilangan',
            'grade_level' => 5,
        ]);

        $this->actingAs($teacher)
            ->get(route('subjects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjects.0.competencies_count', 1)
                ->where('subjects.0.questions_count', 0));

        $this->actingAs($teacher)
            ->delete(route('subjects.destroy', $subject))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);

        $this->actingAs($student)->get(route('subjects.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('subjects.index'))->assertForbidden();
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Mapel', 'npsn' => '10000021']);

        return [
            $this->user($school, 'Guru', 'guru-mapel@example.com', UserRole::Teacher),
            $this->user($school, 'Siswa', 'siswa-mapel@example.com', UserRole::Student),
            $this->user($school, 'Operator', 'operator-mapel@example.com', UserRole::Operator),
        ];
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'grade_level' => $role === UserRole::Student ? 5 : null,
            'email_verified_at' => now(),
        ]);
    }
}
