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
            'role' => UserRole::Student->value,
            'student_identifier' => 'S-100',
            'grade_level' => 5,
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
                ->where('schoolNpsn', '10000006')
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
