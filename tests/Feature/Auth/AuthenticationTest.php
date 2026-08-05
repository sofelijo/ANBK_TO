<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_users_cannot_authenticate(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_student_can_create_an_account_and_login_with_npsn_and_nisn(): void
    {
        $response = $this->post(route('student-login'), [
            'npsn' => '10001001',
            'nisn' => '0012345678',
            'name' => '  Budi   Santoso  ',
        ]);

        $school = School::query()->where('npsn', '10001001')->firstOrFail();
        $student = User::query()->where('student_identifier', '0012345678')->firstOrFail();

        $this->assertAuthenticatedAs($student);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame('Budi Santoso', $student->name);
        $this->assertSame(UserRole::Student, $student->role);
        $this->assertSame(5, $student->grade_level);
        $this->assertNotNull($student->email_verified_at);
        $this->assertNotNull($student->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $school->id,
            'actor_id' => $student->id,
            'action' => 'student.auto_registered',
            'auditable_type' => $student->getMorphClass(),
            'auditable_id' => $student->id,
        ]);
    }

    public function test_existing_student_can_login_without_entering_their_name(): void
    {
        $school = School::create([
            'name' => 'SD Negeri Uji',
            'npsn' => '10001002',
        ]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role' => UserRole::Student,
            'student_identifier' => '0012345679',
            'grade_level' => 5,
            'is_active' => true,
        ]);

        $response = $this->post(route('student-login'), [
            'npsn' => $school->npsn,
            'nisn' => $student->student_identifier,
        ]);

        $this->assertAuthenticatedAs($student);
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $student->id,
            'action' => 'student.logged_in_without_password',
        ]);
    }

    public function test_unknown_student_must_enter_a_name_on_first_login(): void
    {
        $school = School::create([
            'name' => 'SD Negeri Uji',
            'npsn' => '10001003',
        ]);

        $this->post(route('student-login'), [
            'npsn' => $school->npsn,
            'nisn' => '0012345680',
        ])->assertSessionHasErrors([
            'name' => 'Isi nama lengkap untuk membuat akun pertama kali.',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_inactive_student_cannot_login_with_nisn(): void
    {
        $school = School::create([
            'name' => 'SD Negeri Uji',
            'npsn' => '10001004',
        ]);
        User::factory()->create([
            'school_id' => $school->id,
            'role' => UserRole::Student,
            'student_identifier' => '0012345681',
            'is_active' => false,
        ]);

        $this->post(route('student-login'), [
            'npsn' => $school->npsn,
            'nisn' => '0012345681',
        ])->assertSessionHasErrors('nisn');

        $this->assertGuest();
    }

    public function test_student_login_validates_identifiers_and_is_rate_limited(): void
    {
        $this->post(route('student-login'), [
            'npsn' => '1234',
            'nisn' => '5678',
        ])->assertSessionHasErrors(['npsn', 'nisn']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('student-login'), [
                'npsn' => '10001999',
                'nisn' => '0012345699',
            ])->assertSessionHasErrors('name');
        }

        $this->post(route('student-login'), [
            'npsn' => '10001999',
            'nisn' => '0012345699',
        ])->assertSessionHasErrors('nisn');

        $this->assertGuest();
        $this->assertDatabaseMissing('schools', ['npsn' => '10001999']);
    }
}
