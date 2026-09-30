<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_deactivate_school_user(): void
    {
        [$admin] = $this->users();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Murid Baru',
            'email' => 'murid-baru@example.com',
            'password' => 'password123',
            'school_id' => $admin->school_id,
            'role' => UserRole::Student->value,
            'student_identifier' => 'S-100',
            'grade_level' => 6,
        ])->assertRedirect();

        $student = User::query()->where('email', 'murid-baru@example.com')->firstOrFail();
        $this->assertTrue($student->is_active);
        $this->assertNotNull($student->approved_at);
        $this->assertSame($admin->id, $student->approved_by);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $student))
            ->assertRedirect();

        $this->assertFalse($student->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.status_changed',
        ]);
    }

    public function test_admin_can_approve_pending_teacher_registration(): void
    {
        [$admin] = $this->users();
        $teacher = User::create([
            'school_id' => $admin->school_id,
            'name' => 'Guru Menunggu',
            'email' => 'guru-menunggu@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingCount', 1)
                ->where('users.data.0.id', $teacher->id));

        $this->actingAs($admin)
            ->patch(route('admin.users.approve', $teacher))
            ->assertRedirect()
            ->assertSessionHas('success');

        $teacher->refresh();
        $this->assertTrue($teacher->is_active);
        $this->assertNotNull($teacher->approved_at);
        $this->assertSame($admin->id, $teacher->approved_by);
        $this->assertNotNull($teacher->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'user.approved',
            'auditable_type' => User::class,
            'auditable_id' => $teacher->id,
        ]);

        Auth::logout();
        $this->post('/login', [
            'email' => 'guru-menunggu@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($teacher);
    }

    public function test_admin_can_see_and_approve_pending_teacher_from_another_school(): void
    {
        [$admin] = $this->users();
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '10000007']);
        $teacher = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Guru Lintas Sekolah',
            'email' => 'guru-lintas-sekolah@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingCount', 1)
                ->where('users.data.0.id', $teacher->id)
                ->where('users.data.0.school.npsn', '10000007'));

        $this->actingAs($admin)
            ->patch(route('admin.users.approve', $teacher))
            ->assertRedirect()
            ->assertSessionHas('success');

        $teacher->refresh();
        $this->assertTrue($teacher->is_active);
        $this->assertSame($admin->id, $teacher->approved_by);
    }

    public function test_approved_teacher_from_another_school_remains_visible_in_global_teacher_list(): void
    {
        [$admin] = $this->users();
        $otherSchool = School::create(['name' => 'Sekolah Lain Aktif', 'npsn' => '10000027']);
        $teacher = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Guru Lintas Aktif',
            'email' => 'guru-lintas-aktif@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get('/admin/users?role=teacher&search=&status=')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($users) => collect($users)->contains('id', $teacher->id))
                ->where('users.data', fn ($users) => collect($users)->contains(
                    fn (array $user): bool => data_get($user, 'school.npsn') === '10000027',
                )));
    }

    public function test_admin_can_toggle_user_from_another_school(): void
    {
        [$admin] = $this->users();
        $otherSchool = School::create(['name' => 'Sekolah Lain Kelola', 'npsn' => '10000037']);
        $teacher = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Guru Lintas Kelola',
            'email' => 'guru-lintas-kelola@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => true,
            'approved_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $teacher))
            ->assertRedirect();

        $this->assertFalse($teacher->fresh()->is_active);
    }

    public function test_admin_can_approve_operator_from_another_school(): void
    {
        [$admin] = $this->users();
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '10000017']);
        $operator = User::create([
            'school_id' => $otherSchool->id,
            'name' => 'Operator Sekolah Lain',
            'email' => 'operator-sekolah-lain@example.com',
            'password' => 'password',
            'role' => UserRole::Operator,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.approve', $operator))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($operator->fresh()->is_active);
        $this->assertSame($admin->id, $operator->fresh()->approved_by);
    }

    public function test_admin_can_create_school_operator(): void
    {
        [$admin] = $this->users();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Operator Baru',
            'email' => 'operator-baru@example.com',
            'password' => 'password123',
            'school_id' => $admin->school_id,
            'role' => UserRole::Operator->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'school_id' => $admin->school_id,
            'email' => 'operator-baru@example.com',
            'role' => UserRole::Operator->value,
            'is_active' => true,
        ]);
    }

    public function test_teacher_cannot_open_user_administration(): void
    {
        [, $teacher] = $this->users();

        $this->actingAs($teacher)->get(route('admin.users.index'))->assertForbidden();
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Admin', 'npsn' => '10000006']);
        $admin = User::create([
            'school_id' => $school->id,
            'name' => 'Admin',
            'email' => 'admin-test@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'email' => 'teacher-admin-test@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);

        return [$admin, $teacher];
    }
}
