<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SchoolProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_update_school_data_and_existing_schedule_npsn(): void
    {
        [$school, $admin, $operator] = $this->scenario();
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $admin->id,
            'title' => 'Paket TOA',
            'grade_level' => 6,
            'duration_minutes' => 150,
            'status' => AssessmentStatus::Published,
        ]);
        AssessmentSchedule::create([
            'assessment_id' => $assessment->id,
            'school_npsn' => $school->npsn,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
            'starts_at' => '2026-08-11 06:30:00',
            'ends_at' => '2026-08-11 09:00:00',
            'created_by' => $operator->id,
        ]);

        $this->actingAs($operator)
            ->get(route('school.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schools/Edit')
                ->where('school.npsn', '44444444'));

        $this->actingAs($operator)->patch(route('school.update'), [
            'name' => 'SD TOA Nusantara',
            'npsn' => '55555555',
            'subdistrict' => 'Koja',
            'timezone' => 'Asia/Makassar',
            'address' => 'Jalan Pendidikan 1',
            'province' => 'Sulawesi Selatan',
            'city' => 'Makassar',
            'principal_name' => 'Ibu Kepala Sekolah',
            'phone' => '08123456789',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $school->refresh();
        $this->assertSame('SD TOA Nusantara', $school->name);
        $this->assertSame('55555555', $school->npsn);
        $this->assertSame('Koja', $school->subdistrict);
        $this->assertSame('Asia/Makassar', $school->timezone);
        $this->assertSame('Makassar', $school->settings['city']);
        $this->assertDatabaseHas('assessment_schedules', ['school_npsn' => '55555555']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $operator->id,
            'action' => 'school.updated',
        ]);
    }

    public function test_operator_access_is_limited_to_school_data_and_schedules(): void
    {
        [, , $operator, $teacher] = $this->scenario();

        $this->actingAs($operator)->get(route('schedules.index'))->assertOk();
        $this->actingAs($operator)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('questions.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('assessments.index'))->assertRedirect(route('schedules.index'));
        $this->actingAs($teacher)->get(route('school.edit'))->assertForbidden();
    }

    public function test_operator_only_sees_students_from_registered_school_npsn(): void
    {
        [$school, $admin, $operator, $teacher] = $this->scenario();
        $student = $this->student($school, 'Siswa Sekolah Sendiri', '1234567890');
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '66666666']);
        $this->student($otherSchool, 'Siswa Sekolah Lain', '9876543210');

        $this->actingAs($operator)
            ->get(route('school.students.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schools/Students/Index')
                ->where('school.npsn', '44444444')
                ->where('summary.total', 1)
                ->has('students.data', 1)
                ->where('students.data.0.id', $student->id)
                ->where('students.data.0.nisn', '1234567890')
                ->where('students.data.0.parent_email', $student->parent_email));

        $this->actingAs($operator)
            ->get(route('school.students.index', ['search' => 'Sekolah Lain']))
            ->assertInertia(fn (Assert $page) => $page->has('students.data', 0));

        $this->actingAs($admin)->get(route('school.students.index'))->assertOk();
        $this->actingAs($teacher)->get(route('school.students.index'))->assertForbidden();
    }

    public function test_operator_can_approve_pending_student_from_same_school(): void
    {
        [$school, , $operator] = $this->scenario();
        $student = User::create([
            'school_id' => $school->id,
            'name' => 'Siswa Menunggu',
            'email' => 'student.'.$school->id.'.1234567891@toa.local',
            'parent_email' => 'orang-tua-menunggu@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => '1234567891',
            'grade_level' => 6,
            'is_active' => false,
            'approved_at' => null,
        ]);

        $this->actingAs($operator)
            ->patch(route('school.students.approve', $student))
            ->assertRedirect()
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertTrue($student->is_active);
        $this->assertNotNull($student->approved_at);
        $this->assertSame($operator->id, $student->approved_by);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $operator->id,
            'action' => 'student.approved',
            'auditable_id' => $student->id,
        ]);
    }

    public function test_operator_cannot_approve_student_from_another_school(): void
    {
        [, , $operator] = $this->scenario();
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '55555555']);
        $student = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Siswa Sekolah Lain',
            'email' => 'student.'.$otherSchool->id.'.1234567892@toa.local',
            'parent_email' => 'orang-tua-lain@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => '1234567892',
            'grade_level' => 6,
            'is_active' => false,
            'approved_at' => null,
        ]);

        $this->actingAs($operator)
            ->patch(route('school.students.approve', $student))
            ->assertNotFound();

        $student->refresh();
        $this->assertFalse($student->is_active);
        $this->assertNull($student->approved_at);
        $this->assertNull($student->approved_by);
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Sekolah Operator', 'npsn' => '44444444']);
        $admin = $this->user($school, 'Admin', 'admin-school@example.com', UserRole::Admin);
        $operator = $this->user($school, 'Operator', 'operator-school@example.com', UserRole::Operator);
        $teacher = $this->user($school, 'Guru', 'teacher-school@example.com', UserRole::Teacher);

        return [$school, $admin, $operator, $teacher];
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

    private function student(School $school, string $name, string $nisn): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => "student.{$school->id}.{$nisn}@toa.local",
            'parent_email' => "parent.{$school->id}.{$nisn}@example.com",
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => $nisn,
            'grade_level' => 6,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
