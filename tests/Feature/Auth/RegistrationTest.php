<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        School::create(['name' => 'Sekolah Uji', 'npsn' => '10000004']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'npsn' => '10000004',
            'account_type' => UserRole::Student->value,
            'student_identifier' => 'S-001',
            'grade_level' => 5,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_teacher_registration_waits_for_admin_approval(): void
    {
        School::create(['name' => 'Sekolah Uji', 'npsn' => '10000005']);

        $response = $this->post('/register', [
            'name' => 'Guru Pendaftar',
            'email' => 'guru-pendaftar@example.com',
            'npsn' => '10000005',
            'account_type' => UserRole::Teacher->value,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $teacher = User::query()->where('email', 'guru-pendaftar@example.com')->firstOrFail();
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');
        $this->assertGuest();
        $this->assertSame(UserRole::Teacher, $teacher->role);
        $this->assertFalse($teacher->is_active);
        $this->assertNull($teacher->approved_at);
        $this->assertNull($teacher->student_identifier);

        $this->post('/login', [
            'email' => 'guru-pendaftar@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors([
            'email' => 'Akun guru Anda masih menunggu persetujuan admin sekolah.',
        ]);
        $this->assertGuest();
    }

    public function test_registration_requires_an_existing_eight_digit_npsn(): void
    {
        School::create(['name' => 'Sekolah Uji', 'npsn' => '10000007']);
        $payload = [
            'name' => 'Guru NPSN',
            'email' => 'guru-npsn@example.com',
            'account_type' => UserRole::Teacher->value,
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        $this->post('/register', [...$payload, 'npsn' => '1234'])
            ->assertSessionHasErrors('npsn');

        $this->post('/register', [...$payload, 'npsn' => '10000008'])
            ->assertSessionHasErrors([
                'npsn' => 'NPSN sekolah tidak ditemukan.',
            ]);
    }
}
