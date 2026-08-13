<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentMonitoringWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_sees_live_answer_matrix_only_for_students_at_their_school(): void
    {
        [$operator, $studentCorrect, $studentIncorrect, $otherStudent, $assessment, $question] = $this->scenario();
        $correctOption = $question->options()->where('is_correct', true)->firstOrFail();
        $incorrectOption = $question->options()->where('is_correct', false)->firstOrFail();

        $correctAttempt = $this->attempt($assessment, $studentCorrect, $question);
        $incorrectAttempt = $this->attempt($assessment, $studentIncorrect, $question);
        $this->attempt($assessment, $otherStudent, $question);

        $this->actingAs($studentCorrect)->putJson(
            route('attempts.answers.update', [$correctAttempt->public_id, $question]),
            ['option_ids' => [$correctOption->id]],
        )->assertOk()->assertJsonMissing(['is_correct']);

        $this->actingAs($studentIncorrect)->putJson(
            route('attempts.answers.update', [$incorrectAttempt->public_id, $question]),
            ['option_ids' => [$incorrectOption->id]],
        )->assertOk()->assertJsonMissing(['is_correct']);

        $this->assertDatabaseHas('attempt_answers', [
            'attempt_id' => $correctAttempt->id,
            'question_id' => $question->id,
            'is_correct' => true,
        ]);
        $this->assertDatabaseHas('attempt_answers', [
            'attempt_id' => $incorrectAttempt->id,
            'question_id' => $question->id,
            'is_correct' => false,
        ]);

        $this->actingAs($operator)
            ->get(route('monitoring.index', ['assessment_id' => $assessment->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Monitoring/Index')
                ->where('selectedAssessmentId', $assessment->id)
                ->where('monitor.participant_count', 2)
                ->where('monitor.in_progress_count', 2)
                ->has('monitor.rows', 2)
                ->where('monitor.rows.0.student.name', 'Siswa Benar')
                ->where('monitor.rows.0.cells.0.status', 'correct')
                ->where('monitor.rows.1.student.name', 'Siswa Salah')
                ->where('monitor.rows.1.cells.0.status', 'incorrect'));
    }

    public function test_monitoring_is_not_available_to_teacher_or_student(): void
    {
        [$operator, $student] = $this->scenario();
        $teacher = $this->user($operator->school, 'Guru Sekolah', 'guru-monitor@example.com', UserRole::Teacher);

        $this->actingAs($teacher)->get(route('monitoring.index'))->assertForbidden();
        $this->actingAs($student)->get(route('monitoring.index'))->assertForbidden();
    }

    private function scenario(): array
    {
        $ownerSchool = School::create(['name' => 'Sekolah Pembuat', 'npsn' => '10000001']);
        $targetSchool = School::create(['name' => 'Sekolah Peserta', 'npsn' => '10000002']);
        $otherSchool = School::create(['name' => 'Sekolah Lain', 'npsn' => '10000003']);
        $owner = $this->user($ownerSchool, 'Admin Pembuat', 'admin-monitor@example.com', UserRole::Admin);
        $operator = $this->user($targetSchool, 'Operator Sekolah', 'operator-monitor@example.com', UserRole::Operator);
        $studentCorrect = $this->user($targetSchool, 'Siswa Benar', 'benar-monitor@example.com', UserRole::Student, '0010000001');
        $studentIncorrect = $this->user($targetSchool, 'Siswa Salah', 'salah-monitor@example.com', UserRole::Student, '0010000002');
        $otherStudent = $this->user($otherSchool, 'Siswa Sekolah Lain', 'lain-monitor@example.com', UserRole::Student, '0010000003');
        $competency = Competency::create([
            'school_id' => $ownerSchool->id,
            'code' => 'TKA5-MONITOR',
            'domain' => 'Literasi',
            'name' => 'Monitoring pemahaman',
            'grade_level' => 6,
        ]);
        $question = Question::create([
            'school_id' => $ownerSchool->id,
            'author_id' => $owner->id,
            'competency_id' => $competency->id,
            'type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Published,
            'title' => 'Soal monitoring',
            'prompt' => 'Pilih jawaban yang benar.',
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
            'title' => 'Try Out TKA Serentak',
            'grade_level' => 6,
            'duration_minutes' => 150,
            'status' => AssessmentStatus::Published,
            'settings' => ['shuffle_questions' => true],
        ]);

        return [$operator, $studentCorrect, $studentIncorrect, $otherStudent, $assessment, $question];
    }

    private function attempt(Assessment $assessment, User $student, Question $question): Attempt
    {
        $attempt = Attempt::create([
            'public_id' => (string) Str::uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
        ]);
        $attempt->questions()->attach($question->id, [
            'position' => 1,
            'points' => 1,
        ]);

        return $attempt;
    }

    private function user(
        School $school,
        string $name,
        string $email,
        UserRole $role,
        ?string $nisn = null,
    ): User {
        return User::create([
            'school_id' => $school->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'student_identifier' => $nisn,
            'grade_level' => $role === UserRole::Student ? 6 : null,
            'email_verified_at' => now(),
        ]);
    }
}
