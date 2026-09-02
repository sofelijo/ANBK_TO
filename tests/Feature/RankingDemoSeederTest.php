<?php

namespace Tests\Feature;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RankingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_varied_idempotent_ranking_demo_data(): void
    {
        $this->seed(RankingDemoSeeder::class);

        $counts = $this->demoCounts();

        $this->assertSame(16, $counts['schools']);
        $this->assertSame(288, $counts['students']);
        $this->assertSame(7, $counts['assessments']);
        $this->assertGreaterThan(700, $counts['submittedAttempts']);
        $this->assertGreaterThan(0, $counts['inProgressAttempts']);
        $this->assertDatabaseHas('users', ['email' => 'ranking.admin@toa.local']);
        $this->assertDatabaseHas('schools', ['npsn' => '32809999', 'subdistrict' => null]);

        $this->seed(RankingDemoSeeder::class);

        $this->assertSame($counts, $this->demoCounts());
    }

    /** @return array<string, int> */
    private function demoCounts(): array
    {
        $assessmentIds = Assessment::query()
            ->where('title', 'like', '[DEMO RANKING]%')
            ->pluck('id');

        return [
            'schools' => School::query()->where('name', 'like', '[DEMO RANKING]%')->count(),
            'students' => User::query()->where('email', 'like', 'rank-%@student.toa.local')->count(),
            'assessments' => $assessmentIds->count(),
            'submittedAttempts' => Attempt::query()->whereIn('assessment_id', $assessmentIds)->where('status', AttemptStatus::Submitted)->count(),
            'inProgressAttempts' => Attempt::query()->whereIn('assessment_id', $assessmentIds)->where('status', AttemptStatus::InProgress)->count(),
        ];
    }
}
