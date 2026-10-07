<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeacherQuestionAnalyticsController extends Controller
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

        $questionConstraint = fn (Builder $query) => $query
            ->when($since, fn (Builder $questions, CarbonInterface $date) => $questions->where('created_at', '>=', $date));

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
            ->withCount(['authoredQuestions as question_count' => $questionConstraint])
            ->withCount(['authoredQuestions as draft_count' => fn (Builder $query) => $questionConstraint($query)
                ->where('status', QuestionStatus::Draft)])
            ->withCount(['authoredQuestions as review_count' => fn (Builder $query) => $questionConstraint($query)
                ->where('status', QuestionStatus::Review)])
            ->withCount(['authoredQuestions as published_count' => fn (Builder $query) => $questionConstraint($query)
                ->where('status', QuestionStatus::Published)])
            ->withCount(['authoredQuestions as archived_count' => fn (Builder $query) => $questionConstraint($query)
                ->where('status', QuestionStatus::Archived)])
            ->withMax(['authoredQuestions as last_question_created_at' => $questionConstraint], 'created_at')
            ->orderByDesc('question_count')
            ->orderByDesc('last_question_created_at')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $teacherQuery = User::query()
            ->where('role', UserRole::Teacher)
            ->when($subdistrict, fn (Builder $query, string $value) => $query
                ->whereHas('school', fn (Builder $school) => $school->where('subdistrict', $value)));
        $questionQuery = Question::query()
            ->whereHas('author', fn (Builder $author) => $author
                ->where('role', UserRole::Teacher)
                ->when($subdistrict, fn (Builder $query, string $value) => $query
                    ->whereHas('school', fn (Builder $school) => $school->where('subdistrict', $value))))
            ->when($since, fn (Builder $query, CarbonInterface $date) => $query->where('created_at', '>=', $date));
        $totalTeachers = (clone $teacherQuery)->count();
        $activeTeachers = (clone $questionQuery)->distinct()->count('author_id');
        $totalQuestions = (clone $questionQuery)->count();

        return Inertia::render('Admin/TeacherQuestions/Index', [
            'teachers' => $teachers,
            'stats' => [
                'totalQuestions' => $totalQuestions,
                'publishedQuestions' => (clone $questionQuery)->where('status', QuestionStatus::Published)->count(),
                'activeTeachers' => $activeTeachers,
                'totalTeachers' => $totalTeachers,
                'averageQuestions' => $activeTeachers > 0 ? round($totalQuestions / $activeTeachers, 1) : 0,
            ],
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
