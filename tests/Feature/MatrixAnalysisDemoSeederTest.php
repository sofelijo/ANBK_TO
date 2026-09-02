<?php

namespace Tests\Feature;

use App\Enums\AttemptStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\QuestionVerification;
use App\Models\School;
use App\Models\User;
use Database\Seeders\MatrixAnalysisDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MatrixAnalysisDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_idempotent_completed_math_matrix_scenario(): void
    {
        config()->set('demo.matrix.operator_email', 'operator.matrix.test@toa.local');
        config()->set('demo.matrix.operator_password', 'local-test-password');

        $this->seed(MatrixAnalysisDemoSeeder::class);
        $this->seed(MatrixAnalysisDemoSeeder::class);

        $school = School::query()->where('npsn', '31999991')->firstOrFail();
        $operator = User::query()
            ->where('school_id', $school->id)
            ->where('role', UserRole::Operator)
            ->where('email', 'operator.matrix.test@toa.local')
            ->firstOrFail();
        $assessment = Assessment::query()
            ->where('school_id', $school->id)
            ->where('title', 'Paket Demo Analisis Matriks Matematika')
            ->firstOrFail();
        $questionIds = $assessment->questions()->pluck('questions.id');
        $attempts = Attempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::Submitted)
            ->get();

        $this->assertSame('Koja', $school->subdistrict);
        $this->assertTrue(Hash::check('local-test-password', $operator->password));
        $this->assertSame(30, User::query()
            ->where('school_id', $school->id)
            ->where('role', UserRole::Student)
            ->count());
        $this->assertCount(10, $questionIds);
        $this->assertSame(2, $assessment->questions()
            ->where('type', QuestionType::CategoryMatrix)
            ->count());
        $this->assertSame(30, $attempts->count());
        $this->assertGreaterThan(5, $attempts->pluck('score')->unique()->count());
        $this->assertSame(300, DB::table('attempt_question')
            ->whereIn('question_id', $questionIds)
            ->count());
        $this->assertSame(300, DB::table('attempt_answers')
            ->whereIn('attempt_id', $attempts->pluck('id'))
            ->count());
        $this->assertSame(30, QuestionVerification::query()
            ->whereIn('question_id', $questionIds)
            ->count());

        $this->actingAs($operator)
            ->get(route('monitoring.index', ['assessment_id' => $assessment->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Monitoring/Index')
                ->where('monitor.participant_count', 30)
                ->where('monitor.submitted_count', 30)
                ->where('monitor.question_count', 10)
                ->has('monitor.rows', 30)
                ->has('monitor.rows.0.cells', 10));
    }
}
