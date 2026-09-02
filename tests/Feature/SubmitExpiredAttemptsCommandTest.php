<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Jobs\GenerateAttemptSummary;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubmitExpiredAttemptsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_submits_only_expired_in_progress_attempts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-20 12:00:00'));
        Queue::fake();

        [$assessment, $question, $students] = $this->scenario();
        $expiredAttempt = $this->attemptFor($assessment, $question, $students[0], now()->subMinutes(31));
        $activeAttempt = $this->attemptFor($assessment, $question, $students[1], now()->subMinutes(29));
        $submittedAttempt = $this->attemptFor($assessment, $question, $students[2], now()->subMinutes(40));
        $submittedAttempt->update([
            'status' => AttemptStatus::Submitted,
            'submitted_at' => now()->subMinute(),
            'score' => 99,
            'max_score' => 100,
        ]);

        $this->artisan('attempts:submit-expired')
            ->expectsOutputToContain('1 attempt kedaluwarsa berhasil dikirim.')
            ->assertSuccessful();

        $expiredAttempt->refresh();
        $this->assertSame(AttemptStatus::Submitted, $expiredAttempt->status);
        $this->assertSame('1.00', $expiredAttempt->score);
        $this->assertSame('1.00', $expiredAttempt->max_score);
        $this->assertNotNull($expiredAttempt->submitted_at);
        $this->assertDatabaseHas('chat_messages', [
            'attempt_id' => $expiredAttempt->id,
            'type' => 'attempt_summary',
        ]);

        $this->assertSame(AttemptStatus::InProgress, $activeAttempt->fresh()->status);
        $this->assertSame(AttemptStatus::Submitted, $submittedAttempt->fresh()->status);
        $this->assertSame('99.00', $submittedAttempt->fresh()->score);
        Queue::assertPushed(GenerateAttemptSummary::class, 1);
    }

    public function test_expired_attempt_is_scheduled_to_run_every_minute(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('attempts:submit-expired')
            ->assertSuccessful();
    }

    private function scenario(): array
    {
        $school = School::create(['name' => 'Sekolah Uji Scheduler', 'npsn' => '10000009']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru Scheduler',
            'email' => 'guru-scheduler@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);
        $students = collect(range(1, 3))->map(fn (int $number): User => User::create([
            'school_id' => $school->id,
            'name' => "Murid Scheduler {$number}",
            'email' => "murid-scheduler-{$number}@example.com",
            'password' => 'password',
            'role' => UserRole::Student,
            'grade_level' => 6,
            'email_verified_at' => now(),
        ]));
        $competency = Competency::create([
            'school_id' => $school->id,
            'code' => 'SCH-01',
            'domain' => 'Literasi',
            'name' => 'Kompetensi scheduler',
            'grade_level' => 6,
        ]);
        $question = Question::create([
            'school_id' => $school->id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => 'single_choice',
            'status' => QuestionStatus::Published,
            'prompt' => 'Pilih jawaban yang benar.',
            'difficulty' => 1,
            'grade_level' => 6,
        ]);
        $question->options()->create([
            'label' => 'A',
            'content' => 'Jawaban benar',
            'is_correct' => true,
            'position' => 1,
        ]);
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out Scheduler',
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
            'settings' => ['require_all_answers' => true],
        ]);
        $assessment->questions()->attach($question->id, ['position' => 1, 'points' => 1]);

        return [$assessment, $question, $students];
    }

    private function attemptFor(
        Assessment $assessment,
        Question $question,
        User $student,
        CarbonInterface $startedAt,
    ): Attempt {
        $attempt = Attempt::create([
            'public_id' => fake()->uuid(),
            'assessment_id' => $assessment->id,
            'user_id' => $student->id,
            'status' => AttemptStatus::InProgress,
            'started_at' => $startedAt,
        ]);
        $attempt->questions()->attach($question->id, ['position' => 1, 'points' => 1]);
        $attempt->answers()->create([
            'question_id' => $question->id,
            'response' => ['option_ids' => [$question->options()->where('is_correct', true)->value('id')]],
            'duration_seconds' => 15,
            'answered_at' => now(),
        ]);

        return $attempt;
    }
}
