<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\QuestionVerification;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeacherVerificationAnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['7', '30', '90', 'all'])],
            'subdistrict' => ['nullable', Rule::in(School::SUBDISTRICTS)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $period = $filters['period'] ?? '30';
        $subdistrict = $filters['subdistrict'] ?? null;
        $search = trim($filters['search'] ?? '');
        $since = $this->periodStart($period);

        $teachers = User::query()
            ->where('role', UserRole::Teacher)
            ->with('school:id,name,npsn,subdistrict')
            ->when($subdistrict, fn (Builder $query, string $value) => $query
                ->whereHas('school', fn (Builder $school) => $school->where('subdistrict', $value)))
            ->when($search, fn (Builder $query, string $value) => $query->where(fn (Builder $matching) => $matching
                ->where('name', 'like', "%{$value}%")
                ->orWhere('email', 'like', "%{$value}%")
                ->orWhereHas('school', fn (Builder $school) => $school
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('npsn', 'like', "%{$value}%"))))
            ->withCount(['questionVerifications as verification_count' => fn (Builder $query) => $query
                ->when($since, fn (Builder $verification, CarbonInterface $date) => $verification->where('verified_at', '>=', $date))])
            ->withCount(['questionVerifications as published_contribution_count' => fn (Builder $query) => $query
                ->when($since, fn (Builder $verification, CarbonInterface $date) => $verification->where('verified_at', '>=', $date))
                ->whereHas('question', fn (Builder $question) => $question->where('status', QuestionStatus::Published))])
            ->withMax(['questionVerifications as last_verified_at' => fn (Builder $query) => $query
                ->when($since, fn (Builder $verification, CarbonInterface $date) => $verification->where('verified_at', '>=', $date))], 'verified_at')
            ->orderByDesc('verification_count')
            ->orderByDesc('last_verified_at')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $verificationQuery = QuestionVerification::query()
            ->when($since, fn (Builder $query, CarbonInterface $date) => $query->where('verified_at', '>=', $date))
            ->when($subdistrict, fn (Builder $query, string $value) => $query
                ->whereHas('verifier.school', fn (Builder $school) => $school->where('subdistrict', $value)));
        $totalTeachers = User::query()
            ->where('role', UserRole::Teacher)
            ->when($subdistrict, fn (Builder $query, string $value) => $query
                ->whereHas('school', fn (Builder $school) => $school->where('subdistrict', $value)))
            ->count();
        $activeTeachers = (clone $verificationQuery)->distinct()->count('verifier_id');
        $trendDays = $period === '7' ? 7 : 14;
        $trendStart = now()->startOfDay()->subDays($trendDays - 1);
        $trendCounts = (clone $verificationQuery)
            ->where('verified_at', '>=', $trendStart)
            ->get(['verified_at'])
            ->countBy(fn (QuestionVerification $verification): string => $verification->verified_at->format('Y-m-d'));

        return Inertia::render('Admin/TeacherVerifications/Index', [
            'teachers' => $teachers,
            'stats' => [
                'totalVerifications' => (clone $verificationQuery)->count(),
                'activeTeachers' => $activeTeachers,
                'totalTeachers' => $totalTeachers,
                'teacherParticipationRate' => $totalTeachers > 0
                    ? round(($activeTeachers / $totalTeachers) * 100, 1)
                    : 0,
                'questionsVerified' => (clone $verificationQuery)->distinct()->count('question_id'),
                'publishedQuestions' => (clone $verificationQuery)
                    ->whereHas('question', fn (Builder $question) => $question->where('status', QuestionStatus::Published))
                    ->distinct()
                    ->count('question_id'),
            ],
            'trend' => collect(range($trendDays - 1, 0))
                ->map(function (int $daysAgo) use ($trendCounts): array {
                    $date = now()->startOfDay()->subDays($daysAgo);

                    return [
                        'date' => $date->format('Y-m-d'),
                        'label' => $date->translatedFormat('d M'),
                        'count' => $trendCounts->get($date->format('Y-m-d'), 0),
                    ];
                })
                ->values(),
            'filters' => [
                'period' => $period,
                'subdistrict' => $subdistrict ?? '',
                'search' => $search,
            ],
            'subdistricts' => School::SUBDISTRICTS,
        ]);
    }

    private function periodStart(string $period): ?CarbonInterface
    {
        return match ($period) {
            '7' => now()->subDays(7),
            '30' => now()->subDays(30),
            '90' => now()->subDays(90),
            default => null,
        };
    }
}
