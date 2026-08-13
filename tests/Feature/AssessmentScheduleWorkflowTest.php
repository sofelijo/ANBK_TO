<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentScheduleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_schedule_one_npsn_per_weekday_session(): void
    {
        [$manager, $assessment] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 4,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $schedule = AssessmentSchedule::firstOrFail();
        $this->assertSame('22222222', $schedule->school_npsn);
        $this->assertSame('2026-08-11 14:00', $schedule->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-11 16:30', $schedule->ends_at->format('Y-m-d H:i'));

        $otherAssessment = $assessment->replicate();
        $otherAssessment->title = 'Paket Kedua';
        $otherAssessment->save();

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $otherAssessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 4,
        ])->assertSessionHasErrors('session_number');

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $otherAssessment->id,
            'scheduled_date' => '2026-08-15',
            'session_number' => 2,
        ])->assertSessionHasErrors('scheduled_date');
    }

    public function test_only_scheduled_school_can_see_and_start_during_its_session(): void
    {
        [$manager, $assessment, $student, $otherStudent] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
        ]);

        $this->actingAs($student)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->where('bookingRequired', false));

        $this->actingAs($otherStudent)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page->has('assessments', 1));

        $this->travelTo(CarbonImmutable::parse('2026-08-11 07:00'));
        $this->actingAs($student)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bookingRequired', true)
                ->where('assessments.0.schedules.0.school_npsn', '22222222'));
        $this->actingAs($otherStudent)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page->has('assessments', 0));

        $this->actingAs($student)
            ->post(route('attempts.start', $assessment))
            ->assertRedirect();

        $this->assertSame(
            '2026-08-11 06:30',
            Attempt::query()->where('user_id', $student->id)->firstOrFail()->started_at->format('Y-m-d H:i'),
        );

        $this->actingAs($otherStudent)
            ->post(route('attempts.start', $assessment))
            ->assertForbidden();
    }

    public function test_students_can_start_without_booking_outside_operational_hours(): void
    {
        [, $assessment, $student, $otherStudent] = $this->scenario();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:30'));
        $this->assertFalse(AssessmentSchedule::bookingRequired());
        $this->actingAs($student)->post(route('attempts.start', $assessment))->assertRedirect();
        $this->actingAs($otherStudent)->post(route('attempts.start', $assessment))->assertRedirect();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 16:29'));
        $this->assertTrue(AssessmentSchedule::bookingRequired());
        $this->travelTo(CarbonImmutable::parse('2026-08-10 16:30'));
        $this->assertFalse(AssessmentSchedule::bookingRequired());
        $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00'));
        $this->assertFalse(AssessmentSchedule::bookingRequired());
    }

    public function test_teacher_cannot_manage_school_schedule(): void
    {
        [$operator, $assessment] = $this->scenario();
        $teacher = $this->user($operator->school, 'Guru Peserta', 'teacher-schedule@example.com', UserRole::Teacher);

        $this->actingAs($teacher)->get(route('schedules.index'))->assertForbidden();
        $this->actingAs($teacher)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
        ])->assertForbidden();
    }

    private function scenario(): array
    {
        $ownerSchool = School::create(['name' => 'Sekolah Pengelola', 'npsn' => '11111111']);
        $targetSchool = School::create(['name' => 'Sekolah Peserta', 'npsn' => '22222222']);
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '33333333']);
        $owner = $this->user($ownerSchool, 'Pemilik Paket', 'owner@example.com', UserRole::Admin);
        $manager = $this->user($targetSchool, 'Operator Peserta', 'manager@example.com', UserRole::Operator);
        $student = $this->user($targetSchool, 'Siswa Target', 'target@example.com', UserRole::Student);
        $otherStudent = $this->user($otherSchool, 'Siswa Lain', 'other@example.com', UserRole::Student);
        $competency = Competency::create([
            'school_id' => $ownerSchool->id,
            'code' => 'LIT6-JADWAL',
            'domain' => 'Literasi',
            'name' => 'Memahami jadwal',
            'grade_level' => 6,
        ]);
        $question = Question::create([
            'school_id' => $ownerSchool->id,
            'author_id' => $owner->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => QuestionStatus::Published,
            'prompt' => 'Pilih jawaban benar.',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);
        $question->options()->createMany([
            ['label' => 'A', 'content' => 'Benar', 'is_correct' => true, 'position' => 1],
            ['label' => 'B', 'content' => 'Salah', 'is_correct' => false, 'position' => 2],
        ]);
        $assessment = Assessment::create([
            'school_id' => $ownerSchool->id,
            'created_by' => $owner->id,
            'title' => 'Try Out Terjadwal',
            'grade_level' => 6,
            'duration_minutes' => 150,
            'status' => AssessmentStatus::Published,
        ]);
        $assessment->questions()->attach($question->id, ['position' => 1, 'points' => 1]);

        return [$manager, $assessment, $student, $otherStudent];
    }

    private function user(School $school, string $name, string $email, UserRole $role): User
    {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'grade_level' => $role === UserRole::Student ? 6 : null,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
    }
}
