<?php

namespace Tests\Feature;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_chats_with_ai_and_teacher_can_monitor_the_private_room(): void
    {
        config()->set('ai.driver', 'fake');
        config()->set('queue.default', 'sync');
        [$teacher, $student] = $this->users();

        $this->actingAs($student)
            ->get(route('student-chat.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat/StudentShow')
                ->where('chatEnabled', true)
                ->has('messages', 0));

        $this->actingAs($student)
            ->post(route('student-chat.messages.store'), ['content' => 'Aku bingung memahami ide pokok.'])
            ->assertRedirect();

        $room = ChatRoom::where('student_id', $student->id)->firstOrFail();
        $this->assertDatabaseHas('chat_messages', [
            'chat_room_id' => $room->id,
            'sender_id' => $student->id,
            'sender_type' => 'student',
        ]);
        $assistantMessage = ChatMessage::where('chat_room_id', $room->id)->where('sender_type', 'assistant')->firstOrFail();
        $this->assertSame('completed', $assistantMessage->status);
        $this->assertStringContainsString('ide pokok', $assistantMessage->content);
        $this->assertSame(AiGenerationType::StudentChat, AiGeneration::firstOrFail()->type);
        $this->assertSame(AiGenerationStatus::Completed, AiGeneration::firstOrFail()->status);

        $this->actingAs($teacher)
            ->get(route('teacher-chat.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat/TeacherIndex')
                ->where('students.0.id', $student->id));
        $this->actingAs($teacher)
            ->get(route('teacher-chat.show', $student))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat/TeacherShow')
                ->where('student.id', $student->id)
                ->has('messages', 2));
    }

    public function test_chat_room_is_isolated_between_students_and_schools(): void
    {
        [, $student, $otherTeacher, $otherStudent] = $this->users();
        $room = ChatRoom::create(['school_id' => $student->school_id, 'student_id' => $student->id]);

        $this->actingAs($otherStudent)
            ->getJson(route('chat.messages.index', $room))
            ->assertNotFound();
        $this->actingAs($otherTeacher)
            ->get(route('teacher-chat.show', $student))
            ->assertNotFound();
        $this->actingAs($otherTeacher)
            ->getJson(route('chat.messages.index', $room))
            ->assertNotFound();
    }

    public function test_chat_is_disabled_during_active_tryout(): void
    {
        config()->set('ai.driver', 'fake');
        [$teacher, $student] = $this->users();
        $assessment = Assessment::create([
            'school_id' => $student->school_id,
            'created_by' => $teacher->id,
            'title' => 'Ujian Aktif',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        Attempt::create([
            'public_id' => (string) Str::uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('student-chat.show'))
            ->assertInertia(fn (Assert $page) => $page->where('chatEnabled', false));
        $this->actingAs($student)
            ->post(route('student-chat.messages.store'), ['content' => 'Berikan jawaban ujian.'])
            ->assertSessionHasErrors('content');
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_sensitive_message_uses_fixed_safety_response_without_calling_ai(): void
    {
        config()->set('ai.driver', 'fake');
        [, $student] = $this->users();

        $this->actingAs($student)
            ->post(route('student-chat.messages.store'), ['content' => 'Aku berpikir untuk menyakiti diri.'])
            ->assertRedirect();

        $message = ChatMessage::where('sender_type', 'assistant')->firstOrFail();
        $this->assertSame('safety', $message->type);
        $this->assertTrue($message->metadata['needs_teacher_attention']);
        $this->assertStringContainsString('orang dewasa yang kamu percaya', $message->content);
        $this->assertDatabaseCount('ai_generations', 0);
    }

    private function users(): array
    {
        $school = School::create(['name' => 'Sekolah Chat', 'npsn' => '10000005']);
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '10000006']);
        $teacher = $this->user($school, 'Guru Chat', 'guru-chat@example.com', UserRole::Teacher);
        $student = $this->user($school, 'Murid Chat', 'murid-chat@example.com', UserRole::Student);
        $otherTeacher = $this->user($otherSchool, 'Guru Lain', 'guru-lain@example.com', UserRole::Teacher);
        $otherStudent = $this->user($otherSchool, 'Murid Lain', 'murid-lain@example.com', UserRole::Student);

        return [$teacher, $student, $otherTeacher, $otherStudent];
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'student_identifier' => $role === UserRole::Student ? Str::upper(Str::random(8)) : null,
            'grade_level' => $role === UserRole::Student ? 5 : null,
            'email_verified_at' => now(),
        ]);
    }
}
