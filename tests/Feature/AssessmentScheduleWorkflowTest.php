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
use App\Services\AssessmentScheduleCapacityService;
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
            'school_npsn' => '99999999',
            'scheduled_date' => '2026-08-11',
            'session_number' => 4,
            'student_count' => 20,
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
            'student_count' => 20,
        ])->assertSessionHasErrors('session_number');

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $otherAssessment->id,
            'scheduled_date' => '2026-08-15',
            'session_number' => 2,
            'student_count' => 20,
        ])->assertSessionHasErrors('scheduled_date');
    }

    public function test_admin_without_a_school_can_manage_schedules_for_any_npsn(): void
    {
        [$operator, $assessment] = $this->scenario();
        $admin = User::create([
            'school_id' => null,
            'name' => 'Admin Global',
            'email' => 'admin-global-schedule@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($admin)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'school_npsn' => '87654321',
            'scheduled_date' => '2026-08-12',
            'session_number' => 2,
            'student_count' => 25,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $schedule = AssessmentSchedule::firstOrFail();
        $this->assertSame('87654321', $schedule->school_npsn);

        $this->actingAs($admin)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canChooseNpsn', true)
                ->where('schoolNpsn', '')
                ->has('schedules', 1)
                ->where('schedules.0.scheduled_date', '2026-08-12')
                ->where('schedules.0.school_npsn', '87654321'));

        $this->actingAs($operator)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canChooseNpsn', false)
                ->where('schoolNpsn', '22222222')
                ->has('schedules', 0));

        $this->actingAs($operator)->delete(route('schedules.destroy', $schedule))->assertNotFound();
        $this->actingAs($admin)->delete(route('schedules.destroy', $schedule))->assertRedirect();
        $this->assertDatabaseCount('assessment_schedules', 0);
    }

    public function test_admin_must_enter_an_eight_digit_npsn(): void
    {
        [, $assessment] = $this->scenario();
        $admin = User::create([
            'school_id' => null,
            'name' => 'Admin Global',
            'email' => 'admin-global-validation@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($admin)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'school_npsn' => '123',
            'scheduled_date' => '2026-08-12',
            'session_number' => 2,
            'student_count' => 25,
        ])->assertSessionHasErrors('school_npsn');

        $this->assertDatabaseCount('assessment_schedules', 0);
    }

    public function test_operator_sets_reserved_students_and_session_capacity_sums_the_reservations(): void
    {
        [$operator, $assessment, , $otherStudent] = $this->scenario();
        $admin = User::create([
            'school_id' => null,
            'name' => 'Admin Kapasitas',
            'email' => 'admin-capacity@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $otherOperator = $this->user($otherStudent->school, 'Operator Lain', 'other-operator@example.com', UserRole::Operator);
        $thirdSchool = School::create(['name' => 'Sekolah Ketiga', 'npsn' => '44444444']);
        $thirdOperator = $this->user($thirdSchool, 'Operator Ketiga', 'third-operator@example.com', UserRole::Operator);
        $this->user($thirdSchool, 'Siswa Ketiga', 'third-student@example.com', UserRole::Student);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($admin)->patch(route('schedules.capacity.update'), [
            'student_capacity' => 5,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('application_settings', [
            'key' => AssessmentScheduleCapacityService::SETTINGS_KEY,
            'value' => '5',
        ]);

        foreach ([[$operator, 3], [$otherOperator, 2]] as [$bookingOperator, $studentCount]) {
            $this->actingAs($bookingOperator)->post(route('schedules.store'), [
                'assessment_id' => $assessment->id,
                'scheduled_date' => '2026-08-11',
                'session_number' => 1,
                'student_count' => $studentCount,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->actingAs($thirdOperator)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
            'student_count' => 1,
        ])->assertSessionHasErrors('student_count');

        $this->assertDatabaseCount('assessment_schedules', 2);
        $this->actingAs($admin)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('capacity.studentsPerSession', 5)
                ->has('schedules', 2)
                ->where('schedules.0.student_count', 3)
                ->where('schedules.0.session_student_count', 5)
                ->where('schedules.0.session_student_remaining', 0)
                ->where('schedules.1.student_count', 2)
                ->where('schedules.1.session_student_count', 5));
    }

    public function test_operator_cannot_change_student_capacity(): void
    {
        [$operator] = $this->scenario();

        $this->actingAs($operator)->patch(route('schedules.capacity.update'), [
            'student_capacity' => 10,
        ])->assertForbidden();
        $this->actingAs($operator)->patch(route('schedules.duration.update'), [
            'session_duration_minutes' => 120,
        ])->assertForbidden();
        $this->actingAs($operator)->patch(route('schedules.thresholds.update'), [
            'green_threshold' => 60,
            'yellow_threshold' => 85,
        ])->assertForbidden();

        $this->assertDatabaseMissing('application_settings', [
            'key' => AssessmentScheduleCapacityService::SETTINGS_KEY,
        ]);
    }

    public function test_admin_can_configure_schedule_availability_color_thresholds(): void
    {
        [, $assessment] = $this->scenario();
        $admin = User::create([
            'school_id' => null,
            'name' => 'Admin Warna Jadwal',
            'email' => 'admin-schedule-colors@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('schedules.thresholds.update'), [
            'green_threshold' => 65,
            'yellow_threshold' => 88,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('application_settings', [
            'key' => AssessmentScheduleCapacityService::GREEN_THRESHOLD_SETTINGS_KEY,
            'value' => '65',
        ]);
        $this->assertDatabaseHas('application_settings', [
            'key' => AssessmentScheduleCapacityService::YELLOW_THRESHOLD_SETTINGS_KEY,
            'value' => '88',
        ]);

        $this->actingAs($admin)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('capacity.greenThreshold', 65)
                ->where('capacity.yellowThreshold', 88));

        $this->actingAs($admin)->patch(route('schedules.thresholds.update'), [
            'green_threshold' => 90,
            'yellow_threshold' => 80,
        ])->assertSessionHasErrors('yellow_threshold');
    }

    public function test_admin_can_change_session_duration_and_future_schedules_are_recalculated(): void
    {
        [$operator, $assessment] = $this->scenario();
        $admin = User::create([
            'school_id' => null,
            'name' => 'Admin Durasi',
            'email' => 'admin-duration@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'is_active' => true,
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($operator)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 4,
            'student_count' => 20,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->patch(route('schedules.duration.update'), [
            'session_duration_minutes' => 120,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('application_settings', [
            'key' => AssessmentScheduleCapacityService::DURATION_SETTINGS_KEY,
            'value' => '120',
        ]);
        $schedule = AssessmentSchedule::firstOrFail();
        $this->assertSame('2026-08-11 12:30', $schedule->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-11 14:30', $schedule->ends_at->format('Y-m-d H:i'));

        $this->actingAs($admin)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('capacity.sessionDurationMinutes', 120)
                ->has('slots', 5)
                ->where('slots.0.label', '06:30–08:30')
                ->where('slots.3.label', '12:30–14:30')
                ->where('slots.4.label', '14:30–16:30'));
    }

    public function test_operator_can_take_multiple_sessions_for_one_assessment_and_see_a_summary(): void
    {
        [$operator, $assessment] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        foreach ([1, 2] as $sessionNumber) {
            $this->actingAs($operator)->post(route('schedules.store'), [
                'assessment_id' => $assessment->id,
                'scheduled_date' => '2026-08-11',
                'session_number' => $sessionNumber,
                'student_count' => 20,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('assessment_schedules', 2);
        $this->actingAs($operator)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('scheduleSummaries', 1)
                ->where('scheduleSummaries.0.assessment_id', $assessment->id)
                ->where('scheduleSummaries.0.school_npsn', '22222222')
                ->where('scheduleSummaries.0.session_count', 2)
                ->where('scheduleSummaries.0.student_count', 40)
                ->where('sessionUsage.2026-08-11.1', 20)
                ->where('sessionUsage.2026-08-11.2', 20)
                ->where('defaultStudentCount', 20));
    }

    public function test_students_can_see_together_try_out_but_only_scheduled_school_can_start(): void
    {
        [$manager, $assessment, $student, $otherStudent] = $this->scenario();
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
            'student_count' => 1,
        ]);

        $this->actingAs($student)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->has('assessments.0.schedules', 1)
                ->where('assessments.0.average_difficulty', 1)
                ->where('assessments.0.settings.type', Assessment::TYPE_TOGETHER));

        $this->actingAs($otherStudent)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->has('assessments.0.schedules', 0));

        $this->travelTo(CarbonImmutable::parse('2026-08-11 07:00'));
        $this->actingAs($student)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assessments.0.schedules.0.school_npsn', '22222222'));
        $this->actingAs($otherStudent)
            ->get(route('assessments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('assessments', 1)
                ->has('assessments.0.schedules', 0));

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

    public function test_school_session_quota_is_enforced_and_operator_can_see_participants(): void
    {
        [$operator, $assessment, $firstStudent] = $this->scenario();
        $firstStudent->update(['student_identifier' => 'SESSION-001']);
        $secondStudent = $this->user($operator->school, 'Siswa Kedua', 'second-session@example.com', UserRole::Student);
        $secondStudent->update(['student_identifier' => 'SESSION-002']);
        $thirdStudent = $this->user($operator->school, 'Siswa Ketiga', 'third-session@example.com', UserRole::Student);
        $thirdStudent->update(['student_identifier' => 'SESSION-003']);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($operator)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
            'student_count' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $schedule = AssessmentSchedule::firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-08-11 07:00'));

        foreach ([$firstStudent, $secondStudent] as $student) {
            $this->actingAs($student)
                ->post(route('attempts.start', $assessment))
                ->assertRedirect();
        }

        $this->actingAs($firstStudent)
            ->post(route('attempts.start', $assessment))
            ->assertRedirect();
        $this->actingAs($thirdStudent)
            ->post(route('attempts.start', $assessment))
            ->assertForbidden();

        $this->assertSame(2, Attempt::query()->where('assessment_schedule_id', $schedule->id)->count());
        $this->assertDatabaseMissing('attempts', ['user_id' => $thirdStudent->id]);

        $this->actingAs($operator)->get(route('schedules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules.0.participants', 2)
                ->where('schedules.0.participants.0.name', 'Siswa Target')
                ->where('schedules.0.participants.0.student_identifier', 'SESSION-001')
                ->where('schedules.0.participants.1.name', 'Siswa Kedua')
                ->where('schedules.0.participants.1.student_identifier', 'SESSION-002'));
    }

    public function test_regular_try_out_can_start_without_booking_at_any_hour(): void
    {
        [, $assessment, $student, $otherStudent] = $this->scenario();
        $assessment->update(['settings' => [
            'type' => Assessment::TYPE_REGULAR,
            'type_label' => 'Try Out Reguler',
        ]]);

        $this->travelTo(CarbonImmutable::parse('2026-08-10 07:00'));
        $this->actingAs($student)->post(route('attempts.start', $assessment))->assertRedirect();
        $this->actingAs($otherStudent)->post(route('attempts.start', $assessment))->assertRedirect();
    }

    public function test_together_try_out_requires_an_active_schedule_at_any_hour(): void
    {
        [, $assessment, $student] = $this->scenario();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:30'));
        $this->actingAs($student)
            ->post(route('attempts.start', $assessment))
            ->assertForbidden();
    }

    public function test_regular_try_out_cannot_take_an_npsn_schedule(): void
    {
        [$manager, $assessment] = $this->scenario();
        $assessment->update(['settings' => [
            'type' => Assessment::TYPE_REGULAR,
            'type_label' => 'Try Out Reguler',
        ]]);
        $this->travelTo(CarbonImmutable::parse('2026-08-10 05:00'));

        $this->actingAs($manager)->post(route('schedules.store'), [
            'assessment_id' => $assessment->id,
            'scheduled_date' => '2026-08-11',
            'session_number' => 1,
            'student_count' => 1,
        ])->assertSessionHasErrors('assessment_id');

        $this->assertDatabaseCount('assessment_schedules', 0);
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
            'student_count' => 1,
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
            'settings' => [
                'type' => Assessment::TYPE_TOGETHER,
                'type_label' => 'Try Out Bersama',
            ],
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
