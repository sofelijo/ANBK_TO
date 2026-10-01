<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\School;
use App\Models\User;
use App\Notifications\ActionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_own_notification_and_mark_all_as_read(): void
    {
        [$user, $otherUser] = $this->users();
        $user->notify(new ActionNotification('Judul', 'Isi notifikasi', '/dashboard'));
        $user->notify(new ActionNotification('Judul kedua', 'Isi kedua', '/dashboard'));
        $notification = $user->notifications()->oldest()->firstOrFail();

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 2));

        $this->actingAs($otherUser)
            ->get(route('notifications.open', $notification))
            ->assertForbidden();
        $this->assertNull($notification->fresh()->read_at);

        $this->actingAs($user)
            ->get(route('notifications.open', $notification))
            ->assertRedirect('/dashboard');
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(1, $user->fresh()->unreadNotifications()->count());

        $this->actingAs($user)
            ->post(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Notifikasi', 'npsn' => '10000888']);

        return collect(['satu', 'dua'])->map(fn (string $suffix): User => User::create([
            'school_id' => $school->id,
            'name' => "Guru {$suffix}",
            'email' => "guru-{$suffix}@example.com",
            'password' => 'password',
            'role' => UserRole::Teacher,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]))->all();
    }
}
