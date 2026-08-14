<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CensoredResponse;
use App\Models\CensoredWord;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CensoredWordsTest extends TestCase
{
    use RefreshDatabase;

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'student_identifier' => $role === UserRole::Student ? 'STU12345' : null,
            'grade_level' => $role === UserRole::Student ? 6 : null,
            'email_verified_at' => now(),
        ]);
    }

    public function test_teacher_can_manage_words_and_responses_and_test_random_picker(): void
    {
        $school = School::create(['name' => 'Sekolah Sensor', 'npsn' => '10000088']);
        $teacher = $this->user($school, 'Guru Sensor', 'guru-sensor@example.com', UserRole::Teacher);

        // 1. Visit index page
        $this->actingAs($teacher)
            ->get(route('censored-words.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('CensoredWords/Index'));

        // 2. Add batch censored words (comma separated)
        $this->actingAs($teacher)
            ->post(route('censored-words.store'), [
                'words_input' => 'jancok, anjing, babi',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('censored_words', ['word' => 'jancok']);
        $this->assertDatabaseHas('censored_words', ['word' => 'anjing']);
        $this->assertDatabaseHas('censored_words', ['word' => 'babi']);

        // 3. Add custom response template
        $this->actingAs($teacher)
            ->post(route('censored-words.responses.store'), [
                'response_text' => 'Menurut ASKA, itu kata-kata yang kurang santun.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('censored_responses', ['school_id' => $school->id]);

        // 4. Test interactive tester endpoint (picks random active response from pool)
        $response = $this->actingAs($teacher)
            ->postJson(route('censored-words.test'), [
                'message' => 'apa itu jancok?',
            ]);

        $response->assertOk()
            ->assertJson([
                'triggered' => true,
            ]);

        $this->assertStringContainsString('ASKA', $response->json('response'));
    }

    public function test_student_sending_censored_word_receives_random_aska_response(): void
    {
        $school = School::create(['name' => 'Sekolah Siswa', 'npsn' => '10000089']);
        $student = $this->user($school, 'Siswa Test', 'siswa-test@example.com', UserRole::Student);

        CensoredWord::create([
            'school_id' => $school->id,
            'word' => 'jancok',
        ]);

        CensoredResponse::create([
            'school_id' => $school->id,
            'response_text' => 'Menurut ASKA, gunakan tutur kata yang baik ya!',
            'is_active' => true,
        ]);

        $this->actingAs($student)
            ->post(route('student-chat.messages.store'), [
                'content' => 'apa arti kata jancok?',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => 'assistant',
            'type' => 'safety',
        ]);

        $reply = \App\Models\ChatMessage::where('sender_type', 'assistant')->latest('id')->first();
        $this->assertNotNull($reply);
        $this->assertStringContainsString('ASKA', $reply->content);
    }
}
