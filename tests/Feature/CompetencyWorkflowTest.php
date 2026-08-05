<?php

namespace Tests\Feature;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompetencyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_create_update_list_and_delete_school_competency(): void
    {
        [$teacher] = $this->users();

        $this->actingAs($teacher)
            ->post(route('competencies.store'), [
                'code' => ' lit5-main ',
                'domain' => '  Literasi   Membaca ',
                'name' => '  Memahami   isi teks ',
                'description' => 'Kemampuan memahami isi bacaan.',
                'grade_level' => 5,
                'parent_id' => null,
            ])
            ->assertRedirect(route('competencies.index'));

        $competency = Competency::query()->where('code', 'LIT5-MAIN')->firstOrFail();
        $this->assertSame($teacher->school_id, $competency->school_id);
        $this->assertSame('Literasi Membaca', $competency->domain);
        $this->assertSame('Memahami isi teks', $competency->name);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'competency.created',
            'auditable_id' => $competency->id,
        ]);

        $this->actingAs($teacher)
            ->get(route('competencies.index', ['search' => 'LIT5']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Competencies/Index')
                ->has('competencies', 1)
                ->where('competencies.0.can_manage', true));

        $this->actingAs($teacher)
            ->put(route('competencies.update', $competency), [
                'code' => 'LIT5-MAIN',
                'domain' => 'Literasi',
                'name' => 'Memahami informasi utama',
                'description' => null,
                'grade_level' => 5,
                'parent_id' => null,
            ])
            ->assertRedirect(route('competencies.index'));

        $this->assertDatabaseHas('competencies', [
            'id' => $competency->id,
            'name' => 'Memahami informasi utama',
        ]);

        $this->actingAs($teacher)
            ->delete(route('competencies.destroy', $competency))
            ->assertRedirect(route('competencies.index'));

        $this->assertDatabaseMissing('competencies', ['id' => $competency->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $teacher->id,
            'action' => 'competency.deleted',
            'auditable_id' => $competency->id,
        ]);
    }

    public function test_used_competency_cannot_be_deleted(): void
    {
        [$teacher] = $this->users();
        $competency = $this->competency($teacher, 'LIT5-USED');
        Question::create([
            'school_id' => $teacher->school_id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Draft,
            'prompt' => 'Pertanyaan yang menggunakan kompetensi ini?',
            'difficulty' => 1,
            'grade_level' => 5,
        ]);

        $this->actingAs($teacher)
            ->delete(route('competencies.destroy', $competency))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('competencies', ['id' => $competency->id]);
    }

    public function test_teacher_cannot_manage_global_or_other_school_competency(): void
    {
        [$teacher, , $otherTeacher] = $this->users();
        $global = Competency::create([
            'school_id' => null,
            'code' => 'GLOBAL-5',
            'domain' => 'Literasi',
            'name' => 'Kompetensi Global',
            'grade_level' => 5,
        ]);
        $other = $this->competency($otherTeacher, 'OTHER-5');

        $this->actingAs($teacher)->get(route('competencies.edit', $global))->assertNotFound();
        $this->actingAs($teacher)->get(route('competencies.edit', $other))->assertNotFound();
        $this->actingAs($teacher)->delete(route('competencies.destroy', $global))->assertNotFound();
    }

    public function test_student_cannot_access_competency_management(): void
    {
        [, $student] = $this->users();

        $this->actingAs($student)
            ->get(route('competencies.index'))
            ->assertForbidden();
    }

    public function test_competency_parent_must_share_grade_and_cannot_create_cycle(): void
    {
        [$teacher] = $this->users();
        $parent = $this->competency($teacher, 'PARENT-5');
        $gradeEight = Competency::create([
            'school_id' => $teacher->school_id,
            'code' => 'PARENT-8',
            'domain' => 'Literasi',
            'name' => 'Induk Kelas 8',
            'grade_level' => 8,
        ]);

        $this->actingAs($teacher)
            ->post(route('competencies.store'), [
                'code' => 'CHILD-5',
                'domain' => 'Literasi',
                'name' => 'Turunan Kelas 5',
                'grade_level' => 5,
                'parent_id' => $gradeEight->id,
            ])
            ->assertSessionHasErrors('parent_id');

        $child = Competency::create([
            'school_id' => $teacher->school_id,
            'parent_id' => $parent->id,
            'code' => 'CHILD-OK-5',
            'domain' => 'Literasi',
            'name' => 'Turunan Valid',
            'grade_level' => 5,
        ]);

        $this->actingAs($teacher)
            ->put(route('competencies.update', $parent), [
                'code' => $parent->code,
                'domain' => $parent->domain,
                'name' => $parent->name,
                'grade_level' => 5,
                'parent_id' => $child->id,
            ])
            ->assertSessionHasErrors('parent_id');
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Kompetensi', 'npsn' => '10000011']);
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '10000012']);
        $teacher = $this->user($school, 'Guru Kompetensi', 'guru-kompetensi@example.com', UserRole::Teacher);
        $student = $this->user($school, 'Siswa Kompetensi', 'siswa-kompetensi@example.com', UserRole::Student);
        $otherTeacher = $this->user($otherSchool, 'Guru Lain', 'guru-lain-kompetensi@example.com', UserRole::Teacher);

        return [$teacher, $student, $otherTeacher];
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'student_identifier' => $role === UserRole::Student ? '0012345000' : null,
            'grade_level' => $role === UserRole::Student ? 5 : null,
            'email_verified_at' => now(),
        ]);
    }

    private function competency(User $teacher, string $code): Competency
    {
        return Competency::create([
            'school_id' => $teacher->school_id,
            'code' => $code,
            'domain' => 'Literasi',
            'name' => "Kompetensi {$code}",
            'grade_level' => 5,
        ]);
    }
}
