<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\AssessmentSchedule;
use App\Models\Attempt;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use App\Services\TogetherRankingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TogetherRankingService $ranking): Response
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::Student)) {
            return Inertia::render('Dashboard', [
                'stats' => [
                    'availableAssessments' => Assessment::query()
                        ->where('grade_level', $user->grade_level)
                        ->where('status', AssessmentStatus::Published)
                        ->count(),
                    'completedAttempts' => Attempt::query()
                        ->where('user_id', $user->id)
                        ->whereNotNull('submitted_at')
                        ->count(),
                ],
                'mode' => 'student',
            ]);
        }

        if ($user->hasRole(UserRole::Operator)) {
            $school = $user->school()->firstOrFail();
            $attemptMetrics = $this->attemptMetricsBySchool([$school->id])->get($school->id);
            $studentCount = User::query()
                ->where('school_id', $school->id)
                ->where('role', UserRole::Student)
                ->count();
            $participantCount = (int) ($attemptMetrics?->participant_students ?? 0);

            return Inertia::render('Dashboard', [
                'stats' => [
                    'upcomingSchedules' => AssessmentSchedule::query()
                        ->where('school_npsn', $school->npsn)
                        ->where('ends_at', '>=', now())
                        ->count(),
                    'schoolUsers' => User::query()->where('school_id', $school->id)->count(),
                    'students' => $studentCount,
                    'completedAttempts' => (int) ($attemptMetrics?->completed_attempts ?? 0),
                    'participationRate' => $this->percentage($participantCount, $studentCount),
                    'averageScore' => $this->averageScore($attemptMetrics),
                ],
                'mode' => 'operator',
                'school' => [
                    'name' => $school->name,
                    'npsn' => $school->npsn,
                    'subdistrict' => $school->subdistrict,
                ],
            ]);
        }

        if ($user->hasRole(UserRole::Admin)) {
            return $this->adminDashboard($ranking->preview());
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'questions' => Question::query()->count(),
                'publishedQuestions' => Question::query()
                    ->where('status', 'published')
                    ->count(),
                'assessments' => Assessment::query()->count(),
                'completedAttempts' => Attempt::query()
                    ->whereHas('student', fn ($query) => $query->where('school_id', $user->school_id))
                    ->whereNotNull('submitted_at')
                    ->count(),
            ],
            'mode' => 'teacher',
            'rankingPreview' => $ranking->preview(),
        ]);
    }

    /** @param array<string, mixed>|null $rankingPreview */
    private function adminDashboard(?array $rankingPreview): Response
    {
        $studentCounts = User::query()
            ->where('role', UserRole::Student)
            ->whereNotNull('school_id')
            ->selectRaw('school_id, COUNT(*) as student_count')
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');
        $attemptMetrics = $this->attemptMetricsBySchool();

        $schools = School::query()
            ->orderByRaw('CASE WHEN subdistrict IS NULL THEN 1 ELSE 0 END')
            ->orderBy('subdistrict')
            ->orderBy('name')
            ->get(['id', 'name', 'npsn', 'subdistrict'])
            ->map(function (School $school) use ($studentCounts, $attemptMetrics): array {
                $studentCount = (int) ($studentCounts->get($school->id)?->student_count ?? 0);
                $metrics = $attemptMetrics->get($school->id);
                $participantCount = (int) ($metrics?->participant_students ?? 0);

                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'npsn' => $school->npsn,
                    'subdistrict' => $school->subdistrict,
                    'students' => $studentCount,
                    'participants' => $participantCount,
                    'completedAttempts' => (int) ($metrics?->completed_attempts ?? 0),
                    'participationRate' => $this->percentage($participantCount, $studentCount),
                    'averageScore' => $this->averageScore($metrics),
                    'scoredAttempts' => (int) ($metrics?->scored_attempts ?? 0),
                    'scorePercentageTotal' => (float) ($metrics?->score_percentage_total ?? 0),
                ];
            });

        $districts = collect(School::SUBDISTRICTS)
            ->map(fn (string $subdistrict): array => [
                'name' => $subdistrict,
                ...$this->summarizeSchools(
                    $schools->where('subdistrict', $subdistrict)->values(),
                ),
            ])
            ->values();
        $regionalSummary = $this->summarizeSchools($schools);

        return Inertia::render('Dashboard', [
            'mode' => 'admin',
            'stats' => [
                ...$regionalSummary,
                'unclassifiedSchools' => $schools->whereNull('subdistrict')->count(),
            ],
            'districts' => $districts,
            'schools' => $schools->map(function (array $school): array {
                unset($school['scorePercentageTotal']);

                return $school;
            })->values(),
            'rankingPreview' => $rankingPreview,
        ]);
    }

    /**
     * @param  array<int>|null  $schoolIds
     * @return Collection<int, object>
     */
    private function attemptMetricsBySchool(?array $schoolIds = null): Collection
    {
        return Attempt::query()
            ->join('users', 'users.id', '=', 'attempts.user_id')
            ->whereNotNull('attempts.submitted_at')
            ->whereNotNull('users.school_id')
            ->when($schoolIds !== null, fn ($query) => $query->whereIn('users.school_id', $schoolIds))
            ->selectRaw('users.school_id as school_id')
            ->selectRaw('COUNT(attempts.id) as completed_attempts')
            ->selectRaw('COUNT(DISTINCT attempts.user_id) as participant_students')
            ->selectRaw('SUM(CASE WHEN attempts.max_score > 0 THEN (attempts.score * 100.0 / attempts.max_score) ELSE 0 END) as score_percentage_total')
            ->selectRaw('SUM(CASE WHEN attempts.max_score > 0 THEN 1 ELSE 0 END) as scored_attempts')
            ->groupBy('users.school_id')
            ->get()
            ->keyBy('school_id');
    }

    /** @param Collection<int, array<string, mixed>> $schools */
    private function summarizeSchools(Collection $schools): array
    {
        $studentCount = (int) $schools->sum('students');
        $participantCount = (int) $schools->sum('participants');
        $scoredAttemptCount = (int) $schools->sum('scoredAttempts');

        return [
            'schools' => $schools->count(),
            'students' => $studentCount,
            'participants' => $participantCount,
            'completedAttempts' => (int) $schools->sum('completedAttempts'),
            'participationRate' => $this->percentage($participantCount, $studentCount),
            'averageScore' => $scoredAttemptCount > 0
                ? round((float) $schools->sum('scorePercentageTotal') / $scoredAttemptCount, 1)
                : 0,
        ];
    }

    private function averageScore(?object $metrics): float
    {
        $scoredAttemptCount = (int) ($metrics?->scored_attempts ?? 0);

        return $scoredAttemptCount > 0
            ? round((float) $metrics->score_percentage_total / $scoredAttemptCount, 1)
            : 0;
    }

    private function percentage(int $value, int $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 1) : 0;
    }
}
